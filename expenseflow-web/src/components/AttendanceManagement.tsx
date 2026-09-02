import React, { useState, useEffect, useCallback, useMemo } from 'react';
import {
  CalendarCheck,
  Users,
  ClipboardList,
  Wallet,
  BarChart3,
  CalendarDays,
  Check,
  X,
  Clock,
  Building2,
  Download,
  Plus,
  Trash2,
  Pencil,
  Home,
  MapPin,
  RefreshCw,
  AlertCircle,
  AlertTriangle,
  CheckCircle2,
  Search,
  CalendarClock,
  FileText,
  ExternalLink,
  Moon,
  Info,
  ChevronLeft,
  ChevronRight,
  ChevronDown,
  ChevronUp,
  UserCheck,
  UserX,
  Loader2,
  ShieldAlert,
  History,
  RotateCcw,
  Archive,
  Sparkles,
} from 'lucide-react';
import { attendanceApi } from '../services/endpoints';
import { ApiError } from '../services/api';
import { useDebounce } from '../hooks/useDebounce';
import { useAuth } from '../auth/AuthContext';
import CustomDatePicker from './CustomDatePicker';
import { ConfirmationDialog } from './ConfirmationDialog';


type TabKey = 'today' | 'leaves' | 'users' | 'balances' | 'report' | 'holidays';

interface Props {
  onAddAuditLog: (title: string, details: string, bg: string) => void;
  onAddNotification: (type: 'due' | 'flag' | 'new' | 'success', title: string, subtitle: string) => void;
}

// Util: ambil array dari respons (paginate {data:[]} atau array biasa).
const rows = (res: any): any[] => {
  if (Array.isArray(res)) return res;
  if (Array.isArray(res?.data)) return res.data;
  return [];
};

const fmtTime = (v?: string | null) => {
  if (!v) return '—';
  const d = new Date(v);
  if (isNaN(d.getTime())) return v;
  return d.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' });
};
const fmtDate = (v?: string | null) => {
  if (!v) return '—';
  const d = new Date(v);
  if (isNaN(d.getTime())) return String(v).slice(0, 10);
  return d.toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' });
};

// Untuk shift cross-day: tampilkan "12–13 Jun 2026"
const fmtDateRange = (start?: string | null, end?: string | null) => {
  if (!start) return '—';
  if (!end || start === end) return fmtDate(start);
  const s = new Date(start);
  const e = new Date(end);
  if (isNaN(s.getTime()) || isNaN(e.getTime())) return fmtDate(start);
  const sameMonth = s.getMonth() === e.getMonth() && s.getFullYear() === e.getFullYear();
  const dayS = s.toLocaleDateString('id-ID', { day: 'numeric' });
  const dayE = sameMonth
    ? e.toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' })
    : e.toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' });
  const monthYearS = s.toLocaleDateString('id-ID', { month: 'short', year: 'numeric' });
  return sameMonth ? `${dayS}–${dayE}` : `${dayS} ${monthYearS} – ${dayE}`;
};

const fmtMinutes = (mins?: number | null): string => {
  if (mins == null || mins < 0) return '—';
  const h = Math.floor(mins / 60);
  const m = mins % 60;
  return h > 0 ? `${h}j ${m}m` : `${m}m`;
};

// Utilitas tanggal untuk kalender libur
const pad2 = (n: number) => String(n).padStart(2, '0');
const toDateStr = (d: Date) => `${d.getFullYear()}-${pad2(d.getMonth() + 1)}-${pad2(d.getDate())}`;
const MONTHS = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
const WEEKDAYS = ['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'];

const TABS: { key: TabKey; label: string; icon: React.ElementType }[] = [
  { key: 'today', label: 'Hari Ini', icon: CalendarCheck },
  { key: 'leaves', label: 'Approval Izin & Cuti', icon: ClipboardList },
  { key: 'users', label: 'Karyawan & WFH', icon: Users },
  { key: 'balances', label: 'Saldo Cuti', icon: Wallet },
  { key: 'report', label: 'Laporan', icon: BarChart3 },
  { key: 'holidays', label: 'Kalender', icon: CalendarDays },
];

const statusBadge = (status: string) => {
  switch (status) {
    case 'present': return 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-400';
    case 'late': return 'bg-amber-50 text-amber-700 dark:bg-amber-950/30 dark:text-amber-400';
    case 'absent': return 'bg-rose-50 text-rose-700 dark:bg-rose-950/30 dark:text-rose-400';
    case 'early_leave': return 'bg-violet-50 text-violet-700 dark:bg-violet-950/30 dark:text-violet-400';
    case 'cuti': return 'bg-teal-50 text-teal-700 dark:bg-teal-950/30 dark:text-teal-400';
    case 'izin': return 'bg-purple-50 text-purple-700 dark:bg-purple-950/30 dark:text-purple-400';
    case 'sakit': return 'bg-orange-50 text-orange-700 dark:bg-orange-950/30 dark:text-orange-400';
    case 'wfh': return 'bg-indigo-50 text-indigo-700 dark:bg-indigo-950/30 dark:text-indigo-400';
    case 'libur': return 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400';
    default: return 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300';
  }
};

const statusLabel = (status: string) => {
  switch (status) {
    case 'present': return 'Hadir';
    case 'late': return 'Telat';
    case 'absent': return 'Alpha';
    case 'early_leave': return 'Pulang Awal';
    case 'cuti': return 'Cuti';
    case 'izin': return 'Izin';
    case 'sakit': return 'Sakit';
    case 'wfh': return 'WFH';
    case 'libur': return 'Libur';
    default: return status;
  }
};

const leaveBadge = (status: string) => {
  switch (status) {
    case 'approved':
      return 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-400';
    case 'rejected':
      return 'bg-rose-50 text-rose-700 dark:bg-rose-950/30 dark:text-rose-400';
    default:
      return 'bg-amber-50 text-amber-700 dark:bg-amber-950/30 dark:text-amber-400';
  }
};

const TabSkeleton = ({ tab }: { tab: TabKey }) => {
  if (tab === 'today') {
    return (
      <div className="space-y-5 animate-pulse w-full">
        {/* Header bar */}
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div className="h-5 w-52 bg-slate-200 dark:bg-slate-800 rounded-lg" />
          <div className="h-8 w-36 bg-slate-200 dark:bg-slate-800 rounded-xl" />
        </div>

        {/* 4 Summary Cards */}
        <div className="grid grid-cols-2 lg:grid-cols-4 gap-3">
          {[1, 2, 3, 4].map(i => (
            <div key={i} className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl p-4 space-y-2">
              <div className="h-3 w-24 bg-slate-200 dark:bg-slate-800 rounded" />
              <div className="h-7 w-12 bg-slate-200 dark:bg-slate-800 rounded-md" />
            </div>
          ))}
        </div>

        {/* 4 Kolom Per-Data */}
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
          {/* Kolom 1: Sudah Check-in */}
          <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl p-4 flex flex-col h-full space-y-3">
            <div className="flex items-center justify-between">
              <div className="h-4 w-32 bg-slate-200 dark:bg-slate-800 rounded" />
              <div className="h-4 w-6 bg-slate-200 dark:bg-slate-800 rounded-full" />
            </div>
            <div className="h-8 bg-slate-100 dark:bg-slate-800/60 rounded-lg w-full" />
            <div className="space-y-3 pt-1">
              {[1, 2, 3, 4].map(i => (
                <div key={i} className="flex items-center justify-between border-b border-slate-50 dark:border-slate-800/60 pb-2.5">
                  <div className="space-y-1.5">
                    <div className="h-3.5 w-24 bg-slate-200 dark:bg-slate-800 rounded" />
                    <div className="h-2.5 w-32 bg-slate-200 dark:bg-slate-800 rounded" />
                  </div>
                  <div className="h-5 w-12 bg-slate-200 dark:bg-slate-800 rounded" />
                </div>
              ))}
            </div>
          </div>

          {/* Kolom 2: Belum Check-in */}
          <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl p-4 flex flex-col h-full space-y-3">
            <div className="flex items-center justify-between">
              <div className="h-4 w-32 bg-slate-200 dark:bg-slate-800 rounded" />
              <div className="h-4 w-6 bg-slate-200 dark:bg-slate-800 rounded-full" />
            </div>
            <div className="h-8 bg-slate-100 dark:bg-slate-800/60 rounded-lg w-full" />
            <div className="space-y-3 pt-1">
              {[1, 2, 3, 4].map(i => (
                <div key={i} className="flex items-center justify-between border-b border-slate-50 dark:border-slate-800/60 pb-2.5">
                  <div className="space-y-1.5">
                    <div className="h-3.5 w-24 bg-slate-200 dark:bg-slate-800 rounded" />
                    <div className="h-2.5 w-28 bg-slate-200 dark:bg-slate-800 rounded" />
                  </div>
                </div>
              ))}
            </div>
          </div>

          {/* Kolom 3: Sedang Libur */}
          <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl p-4 flex flex-col h-full space-y-3">
            <div className="flex items-center justify-between">
              <div className="h-4 w-28 bg-slate-200 dark:bg-slate-800 rounded" />
              <div className="h-4 w-6 bg-slate-200 dark:bg-slate-800 rounded-full" />
            </div>
            <div className="h-8 bg-slate-100 dark:bg-slate-800/60 rounded-lg w-full" />
            <div className="space-y-3 pt-1">
              {[1, 2, 3].map(i => (
                <div key={i} className="flex items-center justify-between border-b border-slate-50 dark:border-slate-800/60 pb-2.5">
                  <div className="space-y-1.5">
                    <div className="h-3.5 w-24 bg-slate-200 dark:bg-slate-800 rounded" />
                    <div className="h-2.5 w-28 bg-slate-200 dark:bg-slate-800 rounded" />
                  </div>
                  <div className="h-5 w-10 bg-slate-200 dark:bg-slate-800 rounded" />
                </div>
              ))}
            </div>
          </div>

          {/* Kolom 4: Sedang Izin/Cuti */}
          <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl p-4 flex flex-col h-full space-y-3">
            <div className="flex items-center justify-between">
              <div className="h-4 w-32 bg-slate-200 dark:bg-slate-800 rounded" />
              <div className="h-4 w-6 bg-slate-200 dark:bg-slate-800 rounded-full" />
            </div>
            <div className="h-8 bg-slate-100 dark:bg-slate-800/60 rounded-lg w-full" />
            <div className="space-y-3 pt-1">
              {[1, 2, 3].map(i => (
                <div key={i} className="flex items-center justify-between border-b border-slate-50 dark:border-slate-800/60 pb-2.5">
                  <div className="space-y-1.5">
                    <div className="h-3.5 w-24 bg-slate-200 dark:bg-slate-800 rounded" />
                    <div className="h-2.5 w-28 bg-slate-200 dark:bg-slate-800 rounded" />
                  </div>
                  <div className="h-5 w-10 bg-slate-200 dark:bg-slate-800 rounded" />
                </div>
              ))}
            </div>
          </div>
        </div>
      </div>
    );
  }

  if (tab === 'leaves') {
    return (
      <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl p-5 space-y-4 animate-pulse w-full">
        {/* Filter bar skeleton */}
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div className="flex flex-wrap items-center gap-2">
            <div className="h-8 w-28 bg-slate-200 dark:bg-slate-800 rounded-lg" />
            <div className="h-8 w-24 bg-slate-200 dark:bg-slate-800 rounded-lg" />
            <div className="h-8 w-28 bg-slate-200 dark:bg-slate-800 rounded-lg" />
            <div className="h-8 w-28 bg-slate-200 dark:bg-slate-800 rounded-lg" />
            <div className="h-8 w-24 bg-slate-200 dark:bg-slate-800 rounded-lg" />
          </div>
          <div className="h-8 w-48 bg-slate-200 dark:bg-slate-800 rounded-lg" />
        </div>

        {/* Table skeleton per-data */}
        <div className="overflow-x-auto">
          <table className="w-full text-xs text-left">
            <thead>
              <tr className="border-b border-slate-100 dark:border-slate-800 text-slate-400">
                <th className="py-2 px-2 font-semibold">Karyawan</th>
                <th className="py-2 px-2 font-semibold">Tipe</th>
                <th className="py-2 px-2 font-semibold">Sumber</th>
                <th className="py-2 px-2 font-semibold">Periode</th>
                <th className="py-2 px-2 font-semibold text-center">Hari</th>
                <th className="py-2 px-2 font-semibold">Alasan / Status Pilihan</th>
                <th className="py-2 px-2 font-semibold text-center">Status</th>
                <th className="py-2 px-2 font-semibold text-right">Aksi</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-50 dark:divide-slate-800/60">
              {[...Array(6)].map((_, i) => (
                <tr key={i}>
                  <td className="py-3 px-2">
                    <div className="space-y-1.5">
                      <div className="h-3.5 w-28 bg-slate-200 dark:bg-slate-800 rounded" />
                      <div className="h-2.5 w-16 bg-slate-200 dark:bg-slate-800 rounded" />
                    </div>
                  </td>
                  <td className="py-3 px-2">
                    <div className="h-5 w-12 bg-slate-200 dark:bg-slate-800 rounded" />
                  </td>
                  <td className="py-3 px-2">
                    <div className="h-3.5 w-16 bg-slate-200 dark:bg-slate-800 rounded" />
                  </td>
                  <td className="py-3 px-2">
                    <div className="h-3.5 w-24 bg-slate-200 dark:bg-slate-800 rounded" />
                  </td>
                  <td className="py-3 px-2 text-center">
                    <div className="h-3.5 w-6 bg-slate-200 dark:bg-slate-800 rounded mx-auto" />
                  </td>
                  <td className="py-3 px-2">
                    <div className="h-3.5 w-32 bg-slate-200 dark:bg-slate-800 rounded" />
                  </td>
                  <td className="py-3 px-2 text-center">
                    <div className="h-5 w-16 bg-slate-200 dark:bg-slate-800 rounded mx-auto" />
                  </td>
                  <td className="py-3 px-2">
                    <div className="flex items-center justify-end gap-1.5">
                      <div className="h-6 w-6 bg-slate-200 dark:bg-slate-800 rounded-lg" />
                      <div className="h-6 w-6 bg-slate-200 dark:bg-slate-800 rounded-lg" />
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    );
  }

  if (tab === 'balances') {
    return (
      <div className="space-y-4 animate-pulse w-full">
        {/* Header bar */}
        <div className="flex items-center justify-between gap-3 flex-wrap">
          <div className="h-4 w-48 bg-slate-200 dark:bg-slate-800 rounded" />
          <div className="flex items-center gap-2">
            <div className="h-8 w-32 bg-slate-200 dark:bg-slate-800 rounded-xl" />
            <div className="h-8 w-48 bg-slate-200 dark:bg-slate-800 rounded-xl" />
          </div>
        </div>

        {/* Grid Card Saldo Per Karyawan */}
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          {[1, 2, 3, 4, 5, 6].map(i => (
            <div
              key={i}
              className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl p-4 space-y-3"
            >
              {/* Header karyawan + toggle switch */}
              <div className="flex items-center gap-2 pb-2 border-b border-slate-100 dark:border-slate-800">
                <div className="w-7 h-7 rounded-full bg-slate-200 dark:bg-slate-800 shrink-0" />
                <div className="flex-1 space-y-1">
                  <div className="h-3.5 w-28 bg-slate-200 dark:bg-slate-800 rounded" />
                  <div className="h-2.5 w-16 bg-slate-200 dark:bg-slate-800 rounded" />
                </div>
                <div className="flex items-center gap-2 shrink-0">
                  <div className="h-3 w-16 bg-slate-200 dark:bg-slate-800 rounded" />
                  <div className="h-5 w-9 bg-slate-200 dark:bg-slate-800 rounded-full" />
                </div>
              </div>

              {/* 2 Kolom: Cuti & Izin */}
              <div className="grid grid-cols-2 gap-3">
                {/* Blok Cuti Tahunan */}
                <div className="space-y-2">
                  <div className="h-2.5 w-16 bg-slate-200 dark:bg-slate-800 rounded" />
                  <div className="h-5 w-20 bg-slate-200 dark:bg-slate-800 rounded" />
                  <div className="w-full h-1.5 bg-slate-200 dark:bg-slate-800 rounded-full" />
                  <div className="h-2.5 w-20 bg-slate-200 dark:bg-slate-800 rounded" />
                </div>

                {/* Blok Izin / Sakit */}
                <div className="space-y-2">
                  <div className="h-2.5 w-14 bg-slate-200 dark:bg-slate-800 rounded" />
                  <div className="h-5 w-20 bg-slate-200 dark:bg-slate-800 rounded" />
                  <div className="w-full h-1.5 bg-slate-200 dark:bg-slate-800 rounded-full" />
                  <div className="h-2.5 w-16 bg-slate-200 dark:bg-slate-800 rounded" />
                </div>
              </div>
            </div>
          ))}
        </div>
      </div>
    );
  }

  if (tab === 'report') {
    return (
      <div className="space-y-4 animate-pulse w-full">
        <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 xl:grid-cols-11 gap-3">
          {[...Array(11)].map((_, i) => <div key={i} className="h-[84px] bg-slate-200 dark:bg-slate-800 rounded-2xl" />)}
        </div>
        <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl p-5 overflow-x-auto">
          <table className="w-full text-xs text-left">
            <thead>
              <tr className="border-b border-slate-100 dark:border-slate-800 text-slate-400">
                <th className="py-2 px-2 font-semibold">Nama</th>
                <th className="py-2 px-2 font-semibold">Departemen</th>
                <th className="py-2 px-2 font-semibold">Tanggal</th>
                <th className="py-2 px-2 font-semibold">Masuk</th>
                <th className="py-2 px-2 font-semibold">Pulang</th>
                <th className="py-2 px-2 font-semibold">Jam Kerja</th>
                <th className="py-2 px-2 font-semibold">Lembur</th>
                <th className="py-2 px-2 font-semibold">Lokasi</th>
                <th className="py-2 px-2 font-semibold">GPS (WFH)</th>
                <th className="py-2 px-2 font-semibold text-center">Status</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-50 dark:divide-slate-800/60">
              {[...Array(10)].map((_, i) => (
                <tr key={i}>
                  <td className="py-3 px-2">
                    <div className="flex items-center gap-2">
                      <div className="w-6 h-6 rounded-full bg-slate-200 dark:bg-slate-800 shrink-0" />
                      <div className="space-y-1.5 w-full">
                        <div className="h-3 w-24 bg-slate-200 dark:bg-slate-800 rounded" />
                        <div className="h-2 w-32 bg-slate-200 dark:bg-slate-800 rounded" />
                      </div>
                    </div>
                  </td>
                  <td className="py-3 px-2"><div className="h-3 w-16 bg-slate-200 dark:bg-slate-800 rounded" /></td>
                  <td className="py-3 px-2"><div className="h-3 w-20 bg-slate-200 dark:bg-slate-800 rounded" /></td>
                  <td className="py-3 px-2"><div className="h-3 w-12 bg-slate-200 dark:bg-slate-800 rounded" /></td>
                  <td className="py-3 px-2"><div className="h-3 w-12 bg-slate-200 dark:bg-slate-800 rounded" /></td>
                  <td className="py-3 px-2"><div className="h-3 w-16 bg-slate-200 dark:bg-slate-800 rounded" /></td>
                  <td className="py-3 px-2"><div className="h-3 w-16 bg-slate-200 dark:bg-slate-800 rounded" /></td>
                  <td className="py-3 px-2"><div className="h-3 w-12 bg-slate-200 dark:bg-slate-800 rounded" /></td>
                  <td className="py-3 px-2"><div className="h-3 w-16 bg-slate-200 dark:bg-slate-800 rounded" /></td>
                  <td className="py-3 px-2 text-center"><div className="h-5 w-16 bg-slate-200 dark:bg-slate-800 rounded-full mx-auto" /></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    );
  }

  if (tab === 'holidays') {
    return (
      <div className="space-y-4 animate-pulse w-full">
        {/* Header */}
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div className="space-y-1">
            <div className="h-5 w-32 bg-slate-200 dark:bg-slate-800 rounded" />
            <div className="h-3 w-72 bg-slate-200 dark:bg-slate-800 rounded" />
          </div>
          <div className="h-8 w-32 bg-slate-200 dark:bg-slate-800 rounded-lg" />
        </div>

        {/* Grid kalender (2 cols) + detail (1 col) */}
        <div className="grid grid-cols-1 lg:grid-cols-3 gap-4 items-start">
          {/* Kalender Box */}
          <div className="lg:col-span-2 bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl p-5 space-y-4">
            {/* Navigasi bulan */}
            <div className="flex items-center justify-between mb-4">
              <div className="h-8 w-8 bg-slate-200 dark:bg-slate-800 rounded-lg" />
              <div className="space-y-1 flex flex-col items-center">
                <div className="h-4 w-28 bg-slate-200 dark:bg-slate-800 rounded" />
                <div className="h-2.5 w-44 bg-slate-200 dark:bg-slate-800 rounded" />
              </div>
              <div className="h-8 w-8 bg-slate-200 dark:bg-slate-800 rounded-lg" />
            </div>

            {/* Nama Hari */}
            <div className="grid grid-cols-7 gap-1 mb-2">
              {['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'].map(d => (
                <div key={d} className="h-3 bg-slate-200 dark:bg-slate-800 rounded mx-2" />
              ))}
            </div>

            {/* Sel Tanggal (35 cells) */}
            <div className="grid grid-cols-7 gap-1">
              {[...Array(35)].map((_, idx) => (
                <div key={idx} className="h-16 sm:h-20 rounded-lg border border-slate-100 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-800/30 p-1 flex flex-col items-center justify-between">
                  <div className="h-3 w-4 bg-slate-200 dark:bg-slate-700 rounded mt-1" />
                  {idx % 7 === 0 || idx === 12 || idx === 20 ? (
                    <div className="h-2.5 w-10 bg-slate-200 dark:bg-slate-700 rounded mb-1" />
                  ) : null}
                </div>
              ))}
            </div>
          </div>

          {/* Panel Detail */}
          <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl p-5 space-y-3">
            <div className="flex items-center justify-between mb-3">
              <div className="h-4 w-24 bg-slate-200 dark:bg-slate-800 rounded" />
              <div className="h-7 w-24 bg-slate-200 dark:bg-slate-800 rounded-lg" />
            </div>
            {[1, 2].map(i => (
              <div key={i} className="border border-slate-100 dark:border-slate-800 rounded-lg p-3 space-y-2 bg-slate-50/50 dark:bg-slate-800/20">
                <div className="flex items-center justify-between">
                  <div className="h-3.5 w-24 bg-slate-200 dark:bg-slate-800 rounded" />
                  <div className="h-4 w-14 bg-slate-200 dark:bg-slate-800 rounded" />
                </div>
                <div className="h-2.5 w-32 bg-slate-200 dark:bg-slate-800 rounded" />
              </div>
            ))}
          </div>
        </div>
      </div>
    );
  }

  if (tab === 'users') {
    return (
      <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl p-5 space-y-4 w-full animate-pulse">
        <div className="h-12 bg-slate-200 dark:bg-slate-800 rounded-xl" />
        <div className="h-9 bg-slate-200 dark:bg-slate-800 rounded-xl w-full" />
        <div className="w-full space-y-3 mt-4">
          <div className="flex border-b border-slate-100 dark:border-slate-800 pb-3 mb-2">
            <div className="w-1/4 h-4 bg-slate-200 dark:bg-slate-800 rounded" />
            <div className="w-1/4 h-4 bg-slate-200 dark:bg-slate-800 rounded mx-2" />
            <div className="w-1/6 h-4 bg-slate-200 dark:bg-slate-800 rounded mx-2" />
            <div className="w-1/6 h-4 bg-slate-200 dark:bg-slate-800 rounded mx-2" />
            <div className="w-1/6 h-4 bg-slate-200 dark:bg-slate-800 rounded" />
          </div>
          {[1, 2, 3, 4, 5].map(i => (
            <div key={i} className="flex items-center py-2.5 border-b border-slate-50 dark:border-slate-800/60">
              <div className="w-1/4">
                <div className="h-4 w-32 bg-slate-200 dark:bg-slate-800 rounded" />
              </div>
              <div className="w-1/4 px-2">
                <div className="h-4 w-20 bg-slate-200 dark:bg-slate-800 rounded" />
              </div>
              <div className="w-1/6 px-2">
                <div className="h-4 w-16 bg-slate-200 dark:bg-slate-800 rounded" />
              </div>
              <div className="w-1/6 px-2 flex justify-center">
                <div className="h-5 w-9 bg-slate-200 dark:bg-slate-800 rounded-full" />
              </div>
              <div className="w-1/6 flex justify-center">
                <div className="h-5 w-9 bg-slate-200 dark:bg-slate-800 rounded-full" />
              </div>
            </div>
          ))}
        </div>
      </div>
    );
  }

  return (
    <div className="h-[400px] bg-slate-200 dark:bg-slate-800 rounded-2xl animate-pulse w-full" />
  );
};

const CardSearch = ({ value, onChange }: { value: string; onChange: (v: string) => void }) => (
  <div className="relative mt-2 mb-1">
    <Search className="absolute left-2.5 top-1/2 -translate-y-1/2 w-3 h-3 text-slate-400 pointer-events-none" />
    <input
      type="text"
      placeholder="Cari nama / NIK..."
      value={value}
      onChange={(e) => onChange(e.target.value)}
      className="w-full pl-7 pr-3 py-1.5 text-[11px] border border-slate-200 dark:border-slate-700 rounded-lg bg-slate-50 dark:bg-slate-800/40 text-slate-800 dark:text-slate-100 placeholder-slate-400 focus:outline-none focus:ring-1 focus:ring-indigo-400"
    />
  </div>
);

export const AttendanceManagement: React.FC<Props> = ({ onAddAuditLog, onAddNotification }) => {
  const [tab, setTab] = useState<TabKey>('today');
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  // Panduan/Legend Modal
  const [showLegend, setShowLegend] = useState(false);

  // Data per tab
  const [today, setToday] = useState<any | null>(null);
  const [leaves, setLeaves] = useState<any[]>([]);
  const [leaveStatus, setLeaveStatus] = useState<'pending' | 'approved' | 'rejected' | ''>('pending');
  const [leaveTypeFilter, setLeaveTypeFilter] = useState<'wfh' | 'izin' | 'sakit' | 'cuti' | ''>('');
  const [leaveSourceFilter, setLeaveSourceFilter] = useState<'all' | 'mandiri' | 'collective'>('all');
  const [leaveOfficeFilter, setLeaveOfficeFilter] = useState(''); // '' = semua cabang
  const [leaveSearch, setLeaveSearch] = useState('');
  const [showUpcoming, setShowUpcoming] = useState(false);
  const [docLoadingId, setDocLoadingId] = useState<number | null>(null);
  const [docModal, setDocModal] = useState<{ url: string; isPdf: boolean; userName: string } | null>(null);
  const [users, setUsers] = useState<any[]>([]);
  const [userSearch, setUserSearch] = useState('');
  const [userOfficeFilter, setUserOfficeFilter] = useState('');
  const [balances, setBalances] = useState<any[]>([]);
  const [balanceSearch, setBalanceSearch] = useState('');
  const [balanceOfficeFilter, setBalanceOfficeFilter] = useState('');
  const [togglingUserId, setTogglingUserId] = useState<number | null>(null);

  // Sub-tab & history state untuk Saldo Cuti
  const [balanceSubTab, setBalanceSubTab] = useState<'active' | 'history'>('active');
  const [balanceHistories, setBalanceHistories] = useState<any[]>([]);
  const [balanceHistoryStats, setBalanceHistoryStats] = useState<{
    total_records: number;
    total_cuti_used: number;
    total_cuti_remaining: number;
    total_izin_sakit_used: number;
  } | null>(null);
  const [balanceHistoryOfficeFilter, setBalanceHistoryOfficeFilter] = useState('');
  const [balanceHistoryYearFilter, setBalanceHistoryYearFilter] = useState('');
  const [balanceHistorySearch, setBalanceHistorySearch] = useState('');
  const [balanceHistoryLoading, setBalanceHistoryLoading] = useState(false);
  const [resetOfficeModal, setResetOfficeModal] = useState<{ id: number; name: string; quota: number; resetDate: string } | null>(null);
  const [isResettingOffice, setIsResettingOffice] = useState(false);

  const debouncedBalanceHistorySearch = useDebounce(balanceHistorySearch, 500);
  const [report, setReport] = useState<any | null>(null);
  const [reportFilter, setReportFilter] = useState<{ start_date: string; end_date: string; status: string; type: string; search?: string; office_id?: string }>({
    start_date: '',
    end_date: '',
    status: '',
    type: '',
    search: '',
    office_id: '',
  });
  const [reportSearch, setReportSearch] = useState('');
  const [reportPage, setReportPage] = useState(1);
  const [offices, setOffices] = useState<any[]>([]);
  const [todayOfficeFilter, setTodayOfficeFilter] = useState('');
  const [searchCheckedIn, setSearchCheckedIn] = useState('');
  const [searchNotCheckedIn, setSearchNotCheckedIn] = useState('');
  const [searchOffToday, setSearchOffToday] = useState('');
  const [searchOnLeave, setSearchOnLeave] = useState('');

  const debouncedSearchCheckedIn = useDebounce(searchCheckedIn, 500);
  const debouncedSearchNotCheckedIn = useDebounce(searchNotCheckedIn, 500);
  const debouncedSearchOffToday = useDebounce(searchOffToday, 500);
  const debouncedSearchOnLeave = useDebounce(searchOnLeave, 500);
  const debouncedLeaveSearch = useDebounce(leaveSearch, 500);
  const debouncedUserSearch = useDebounce(userSearch, 500);
  const debouncedBalanceSearch = useDebounce(balanceSearch, 500);
  const debouncedReportSearch = useDebounce(reportSearch, 500);

  // Paginasi Client-side untuk Performa UI 60 FPS saat memproses ribuan data
  const [leavePage, setLeavePage] = useState<number>(1);
  const [leavePageSize, setLeavePageSize] = useState<number>(25);

  const [userPage, setUserPage] = useState<number>(1);
  const [userPageSize, setUserPageSize] = useState<number>(25);

  const [balancePage, setBalancePage] = useState<number>(1);
  const [balancePageSize, setBalancePageSize] = useState<number>(24);

  // Limit tampilan awal item per kolom di tab 'Hari Ini' agar DOM ringan (< 40 item)
  const [todayColumnLimit, setTodayColumnLimit] = useState<{ [key: string]: number }>({
    checkedIn: 40,
    notCheckedIn: 40,
    offToday: 40,
    onLeave: 40,
  });

  useEffect(() => {
    setLeavePage(1);
  }, [debouncedLeaveSearch, leaveStatus, leaveTypeFilter, leaveSourceFilter, leaveOfficeFilter, showUpcoming]);

  useEffect(() => {
    setUserPage(1);
  }, [debouncedUserSearch, userOfficeFilter]);

  useEffect(() => {
    setBalancePage(1);
  }, [debouncedBalanceSearch, balanceOfficeFilter]);

  useEffect(() => {
    attendanceApi.settings.list().then(res => setOffices((res as any)?.settings ?? [])).catch(() => { });
  }, []);

  useEffect(() => {
    if (reportFilter.search !== debouncedReportSearch) {
      setReportFilterAndReset({ ...reportFilter, search: debouncedReportSearch });
    }
  }, [debouncedReportSearch]);

  const [reportNameSort, setReportNameSort] = useState<'asc' | 'desc' | null>(null);
  const [holidays, setHolidays] = useState<any[]>([]);
  const [holidayYear, setHolidayYear] = useState<number>(new Date().getFullYear());

  const reportApiError = (err: unknown, fallback: string) => {
    if (err instanceof ApiError) {
      const firstError = err.data?.errors && Object.values(err.data.errors)[0];
      setError(Array.isArray(firstError) ? firstError[0] : err.message);
    } else {
      setError(fallback);
    }
  };

  // ─── Loaders ──────────────────────────────────────────────
  const loadToday = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      setToday(await attendanceApi.today());
    } catch (e) {
      reportApiError(e, 'Gagal memuat data presensi hari ini.');
    } finally {
      setLoading(false);
    }
  }, []);

  const loadLeaves = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      // per_page: 500 — cukup untuk perusahaan UKM. Tidak bisa server-side pagination
      // karena conflict detection (deteksi bentrok cuti) butuh semua data leaves (semua status).
      const res: any = await attendanceApi.leaves({ per_page: 500 });
      setLeaves(rows(res));
    } catch (e) {
      reportApiError(e, 'Gagal memuat pengajuan izin/cuti.');
    } finally {
      setLoading(false);
    }
  }, []);

  const loadUsers = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      // per_page: 300 — cukup untuk perusahaan UKM (≤ 300 karyawan aktif).
      // Filter office/search dilakukan client-side karena backend belum support filter tersebut.
      const res: any = await attendanceApi.users({ per_page: 300 });
      setUsers(rows(res));
    } catch (e) {
      reportApiError(e, 'Gagal memuat daftar karyawan.');
    } finally {
      setLoading(false);
    }
  }, []);

  const loadBalances = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const res: any = await attendanceApi.leaveBalances();
      setBalances(res?.balances ?? []);
    } catch (e) {
      reportApiError(e, 'Gagal memuat saldo cuti.');
    } finally {
      setLoading(false);
    }
  }, []);

  const loadBalanceHistories = useCallback(async () => {
    setBalanceHistoryLoading(true);
    try {
      const params: any = {};
      if (balanceHistoryOfficeFilter) params.office_id = balanceHistoryOfficeFilter;
      if (balanceHistoryYearFilter) params.year = Number(balanceHistoryYearFilter);
      if (debouncedBalanceHistorySearch) params.search = debouncedBalanceHistorySearch;
      const res: any = await attendanceApi.leaveBalanceHistories(params);
      setBalanceHistories(res?.histories ?? []);
      setBalanceHistoryStats(res?.stats ?? null);
    } catch (e) {
      reportApiError(e, 'Gagal memuat riwayat saldo cuti.');
    } finally {
      setBalanceHistoryLoading(false);
    }
  }, [balanceHistoryOfficeFilter, balanceHistoryYearFilter, debouncedBalanceHistorySearch]);

  const handleManualResetOffice = async (officeId: number, officeName: string) => {
    setIsResettingOffice(true);
    try {
      const res: any = await attendanceApi.resetOfficeLeaveBalances(officeId);
      onAddAuditLog('Reset Saldo Cuti', `Manual reset kantor ${officeName}: ${res.reset_count} karyawan`, 'bg-amber-500');
      onAddNotification('success', 'Reset Saldo Berhasil', res.message || `Saldo cuti kantor ${officeName} berhasil di-reset.`);
      setResetOfficeModal(null);
      await Promise.all([loadBalances(), loadBalanceHistories()]);
    } catch (e) {
      reportApiError(e, `Gagal me-reset saldo kantor ${officeName}.`);
    } finally {
      setIsResettingOffice(false);
    }
  };

  const loadReport = useCallback(async (page = reportPage) => {
    setLoading(true);
    setError(null);
    try {
      const f: any = { page };
      if (reportFilter.start_date) f.start_date = reportFilter.start_date;
      if (reportFilter.end_date) f.end_date = reportFilter.end_date;
      if (reportFilter.status) f.status = reportFilter.status;
      if (reportFilter.type) f.type = reportFilter.type;
      if (reportFilter.search) f.search = reportFilter.search;
      if (reportFilter.office_id) f.office_id = reportFilter.office_id;
      setReport(await attendanceApi.report(f));
    } catch (e) {
      reportApiError(e, 'Gagal memuat laporan presensi.');
    } finally {
      setLoading(false);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [reportFilter, reportPage]);

  const loadHolidays = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const res: any = await attendanceApi.holidays.list(holidayYear);
      setHolidays(res?.holidays ?? []);
    } catch (e) {
      reportApiError(e, 'Gagal memuat kalender libur.');
    } finally {
      setLoading(false);
    }
  }, [holidayYear]);

  // Reset halaman ke 1 setiap kali filter laporan berubah
  const setReportFilterAndReset = (next: typeof reportFilter) => {
    setReportPage(1);
    setReportNameSort(null);
    setReportFilter(next);
  };

  // Muat data sesuai tab aktif.
  useEffect(() => {
    if (tab === 'today') loadToday();
    else if (tab === 'leaves') loadLeaves();
    else if (tab === 'users') loadUsers();
    else if (tab === 'balances') {
      if (balanceSubTab === 'active') {
        loadBalances();
      } else {
        loadBalanceHistories();
      }
    }
    else if (tab === 'report') loadReport(reportPage);
    else if (tab === 'holidays') {
      loadHolidays();
      if (users.length === 0) {
        attendanceApi.users({ per_page: 300 }).then(res => setUsers(rows(res))).catch(() => {});
      }
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [tab, balanceSubTab, reportFilter, reportPage, holidayYear, loadBalanceHistories]);

  // ─── Aksi ─────────────────────────────────────────────────
  const handleApproveLeave = async (id: number, name: string) => {
    try {
      await attendanceApi.approveLeave(id);
      onAddAuditLog('Izin/Cuti Disetujui', `Pengajuan #${id} (${name}) disetujui`, 'bg-emerald-600');
      onAddNotification('success', 'Pengajuan Disetujui', `Pengajuan ${name} telah disetujui.`);
      await loadLeaves();
    } catch (e) {
      reportApiError(e, 'Gagal menyetujui pengajuan.');
    }
  };

  const handleRejectLeave = async (id: number, name: string) => {
    const reason = window.prompt(`Alasan menolak pengajuan ${name}:`, '');
    if (reason === null) return;
    if (!reason.trim()) {
      alert('Alasan penolakan wajib diisi.');
      return;
    }
    try {
      await attendanceApi.rejectLeave(id, reason.trim());
      onAddAuditLog('Izin/Cuti Ditolak', `Pengajuan #${id} (${name}) ditolak: ${reason}`, 'bg-rose-600');
      onAddNotification('flag', 'Pengajuan Ditolak', `Pengajuan ${name} ditolak.`);
      await loadLeaves();
    } catch (e) {
      reportApiError(e, 'Gagal menolak pengajuan.');
    }
  };

  const openLeaveDocument = async (id: number, userName: string) => {
    setDocLoadingId(id);
    try {
      const res = await attendanceApi.leaveDocumentUrl(id);
      if (!res) {
        setError('Gagal memuat surat dokter.');
        return;
      }
      setDocModal({ url: res.url, isPdf: res.isPdf, userName });
    } catch {
      setError('Gagal memuat surat dokter.');
    } finally {
      setDocLoadingId(null);
    }
  };

  const closeDocModal = () => {
    if (docModal) URL.revokeObjectURL(docModal.url);
    setDocModal(null);
  };

  const handleToggleWfh = async (id: number, name: string) => {
    try {
      const res: any = await attendanceApi.toggleWfh(id);
      const on = res?.user?.wfh_enabled;
      onAddAuditLog('Mode WFH Diubah', `WFH ${name} ${on ? 'diaktifkan' : 'dinonaktifkan'}`, on ? 'bg-emerald-600' : 'bg-slate-600');
      setUsers(prev => prev.map(u => u.id === id ? {
        ...u,
        wfh_enabled: res?.user?.wfh_enabled ?? !u.wfh_enabled,
        attendance_enabled: res?.user?.attendance_enabled ?? u.attendance_enabled,
      } : u));
    } catch (e) {
      reportApiError(e, 'Gagal mengubah mode WFH.');
    }
  };

  const handleToggleRadius = async (id: number, name: string) => {
    try {
      const res: any = await attendanceApi.toggleRadius(id);
      const on = res?.user?.radius_enabled;
      onAddAuditLog('Radius Lapangan Diubah', `Radius ${name} ${on ? 'diaktifkan (lapangan)' : 'dinonaktifkan (WFH bebas)'}`, on ? 'bg-amber-600' : 'bg-slate-600');
      setUsers(prev => prev.map(u => u.id === id ? {
        ...u,
        radius_enabled: res?.user?.radius_enabled ?? !u.radius_enabled,
      } : u));
    } catch (e) {
      reportApiError(e, 'Gagal mengubah radius lapangan.');
    }
  };

  const handleToggleCutiQuota = async (userId: number, userName: string, currentQuota: number) => {
    const newQuota = currentQuota > 0 ? 0 : 12;
    setTogglingUserId(userId);
    try {
      await attendanceApi.setLeaveBalance({ user_id: userId, leave_type: 'cuti', quota: newQuota });
      onAddAuditLog(
        'Kuota Cuti Tahunan Diubah',
        `${userName}: kuota cuti ${currentQuota > 0 ? 'dinonaktifkan (0 hari)' : 'diaktifkan (12 hari)'}`,
        currentQuota > 0 ? 'bg-slate-600' : 'bg-teal-600'
      );
      await loadBalances();
      // Perbarui juga data allUsers agar status leave_active userOptions langsung sinkron
      attendanceApi.allUsers(true).catch(() => {}); // forceRefresh=true agar cache tidak stale
    } catch (e) {
      reportApiError(e, 'Gagal mengubah kuota cuti.');
    } finally {
      setTogglingUserId(null);
    }
  };

  const handleExport = async () => {
    try {
      const f: any = {};
      if (reportFilter.start_date) f.start_date = reportFilter.start_date;
      if (reportFilter.end_date) f.end_date = reportFilter.end_date;
      if (reportFilter.status) f.status = reportFilter.status;
      if (reportFilter.type) f.type = reportFilter.type;
      if (reportFilter.search) f.search = reportFilter.search;
      if (reportFilter.office_id) f.office_id = reportFilter.office_id;
      await attendanceApi.exportReport(f);
      onAddAuditLog('Export Laporan Presensi', 'Mengunduh laporan presensi (CSV)', 'bg-indigo-600');
    } catch (e) {
      reportApiError(e, 'Gagal mengekspor laporan.');
    }
  };

  // ─── Render helpers ───────────────────────────────────────
  const SummaryCard = ({ label, value, color }: { label: string; value: number | string; color: string }) => (
    <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl p-4 shadow-xs">
      <p className="text-[11px] text-slate-400 font-semibold uppercase tracking-wider">{label}</p>
      <p className={`text-2xl font-bold mt-1 ${color}`}>{value}</p>
    </div>
  );


  return (
    <div className="space-y-5 font-sans">
      {/* Tabs & Refresh */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-slate-200 dark:border-slate-800">
        <div className="flex flex-wrap items-center gap-1">
          {TABS.map(({ key, label, icon: Icon }) => (
            <button
              key={key}
              onClick={() => setTab(key)}
              className={`flex items-center gap-1.5 px-3.5 sm:px-4 py-2.5 text-xs font-bold border-b-2 -mb-px transition cursor-pointer ${tab === key
                ? 'border-indigo-500 text-indigo-600 dark:text-indigo-400'
                : 'border-transparent text-slate-400 hover:text-slate-600 dark:text-slate-500 dark:hover:text-slate-300'
                }`}
            >
              <Icon className="w-3.5 h-3.5" />
              {label}
            </button>
          ))}
        </div>
        <div className="flex items-center gap-2 pb-1.5 self-end sm:self-center">
          {tab === 'report' && (
            <button
              onClick={() => setShowLegend(true)}
              className="flex items-center justify-center gap-1.5 px-3 py-2 bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700 rounded-xl text-xs font-bold transition shrink-0"
              title="Panduan Laporan"
            >
              <Info className="w-3.5 h-3.5" />
              <span className="hidden sm:inline">Panduan</span>
            </button>
          )}
          {tab === 'holidays' && (
            <div className="flex items-center gap-1.5">
              <button
                onClick={() => setHolidayYear(y => y - 1)}
                className="flex items-center justify-center px-2.5 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800 rounded-xl transition shrink-0"
                title="Tahun sebelumnya"
              >
                <ChevronLeft className="w-3.5 h-3.5" />
              </button>
              <span className="text-xs font-bold text-slate-700 dark:text-slate-200 w-12 text-center">{holidayYear}</span>
              <button
                onClick={() => setHolidayYear(y => y + 1)}
                className="flex items-center justify-center px-2.5 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800 rounded-xl transition shrink-0"
                title="Tahun berikutnya"
              >
                <ChevronRight className="w-3.5 h-3.5" />
              </button>
            </div>
          )}
          <button
            onClick={() => {
              if (tab === 'today') loadToday();
              else if (tab === 'leaves') loadLeaves();
              else if (tab === 'users') loadUsers();
              else if (tab === 'balances') loadBalances();
              else if (tab === 'report') loadReport(reportPage);
              else if (tab === 'holidays') loadHolidays();
            }}
            disabled={loading}
            className="flex items-center justify-center gap-1.5 px-3 py-2 bg-indigo-50 dark:bg-indigo-900/30 border border-indigo-100 dark:border-indigo-800 text-indigo-600 dark:text-indigo-400 hover:bg-indigo-100 dark:hover:bg-indigo-900/50 rounded-xl text-xs font-bold transition shrink-0 disabled:opacity-50"
            title="Refresh Data"
          >
            <RefreshCw className={`w-3.5 h-3.5 ${loading ? 'animate-spin' : ''}`} />
            <span className="hidden sm:inline">Refresh</span>
          </button>
        </div>
      </div>

      {error && (
        <div className="flex items-center justify-between gap-2 bg-rose-50 border border-rose-200 text-rose-700 rounded-lg px-4 py-3 text-xs">
          <span className="flex items-center gap-2"><AlertCircle className="w-4 h-4" />{error}</span>
        </div>
      )}

      {/* ─── TAB: Hari Ini ─── */}
      {tab === 'today' && (
        loading ? <TabSkeleton tab="today" /> : today && (
          <div className="space-y-5">
            <div className="flex flex-wrap items-center justify-between gap-3">
              <h3 className="text-sm font-bold text-slate-800 dark:text-slate-100 flex items-center gap-2">
                <CalendarDays className="w-4 h-4 text-indigo-500" />
                {new Intl.DateTimeFormat('id-ID', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }).format(new Date())}
              </h3>
              {/* Filter kantor */}
              <div className="flex items-center gap-2">
                <Building2 className="w-3.5 h-3.5 text-slate-400" />
                <select
                  value={todayOfficeFilter}
                  onChange={(e) => setTodayOfficeFilter(e.target.value)}
                  className="py-1.5 px-3 text-xs font-semibold border border-slate-200 dark:border-slate-700 rounded-xl bg-white dark:bg-slate-900 text-slate-700 dark:text-slate-300 focus:outline-none focus:ring-1 focus:ring-indigo-400 cursor-pointer"
                >
                  <option value="">Semua Kantor</option>
                  {offices.map(o => (
                    <option key={o.id} value={String(o.id)}>{o.office_name}</option>
                  ))}
                  <option value="null">Tanpa Kantor</option>
                </select>
              </div>
            </div>

            <div className="grid grid-cols-2 lg:grid-cols-4 gap-3">
              <SummaryCard label="Total Karyawan" value={today.summary?.total_employees ?? 0} color="text-slate-800 dark:text-white" />
              <SummaryCard label="Sudah Check-in" value={today.summary?.checked_in ?? 0} color="text-emerald-600" />
              <SummaryCard label="Belum Check-in" value={today.summary?.not_checked_in ?? 0} color="text-rose-600" />
              <SummaryCard label="Izin / Cuti" value={today.summary?.on_leave ?? 0} color="text-amber-600" />
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
              {(() => {
                // Helper: filter kantor + search
                const filterPerson = (p: any, search: string) => {
                  const matchOffice = !todayOfficeFilter ||
                    (todayOfficeFilter === 'null'
                      ? !p.attendance_setting_id
                      : String(p.attendance_setting_id) === todayOfficeFilter);
                  const q = search.toLowerCase();
                  const matchSearch = !q ||
                    p.name.toLowerCase().includes(q) ||
                    (p.employee_code && p.employee_code.toLowerCase().includes(q));
                  return matchOffice && matchSearch;
                };

                const checkedInRaw = today.checked_in ?? [];
                const notCheckedInRaw = (today.not_checked_in ?? []).filter((p: any) => !p.is_off);
                const offTodayRaw = (today.not_checked_in ?? []).filter((p: any) => p.is_off);
                const onLeaveRaw = today.on_leave ?? [];

                const checkedIn = checkedInRaw.filter((p: any) => filterPerson(p, debouncedSearchCheckedIn));
                const notCheckedIn = notCheckedInRaw.filter((p: any) => filterPerson(p, debouncedSearchNotCheckedIn));
                const offToday = offTodayRaw.filter((p: any) => filterPerson(p, debouncedSearchOffToday));
                const onLeave = onLeaveRaw.filter((p: any) => filterPerson(p, debouncedSearchOnLeave));

                return (
                  <>
                    {/* Sudah check-in */}
                    <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl p-4 flex flex-col h-full">
                      <h4 className="text-xs font-bold text-emerald-700 dark:text-emerald-400 flex items-center gap-1.5">
                        <CheckCircle2 className="w-4 h-4" /> Sudah Check-in ({checkedIn.length})
                      </h4>
                      <CardSearch value={searchCheckedIn} onChange={setSearchCheckedIn} />
                      <div className="space-y-2 flex-1 overflow-y-auto max-h-80 pr-1">
                        {checkedIn.length === 0 ? (
                          <p className="text-[11px] text-slate-400 py-3 text-center">{searchCheckedIn || todayOfficeFilter ? 'Tidak ditemukan.' : 'Belum ada.'}</p>
                        ) : (
                          <>
                            {checkedIn.slice(0, todayColumnLimit.checkedIn || 40).map((p: any) => (
                              <div key={p.user_id} className={`flex items-center justify-between border-b pb-2 ${p.is_cross_day ? 'border-indigo-100 dark:border-indigo-900/40 bg-indigo-50/40 dark:bg-indigo-950/20 rounded-lg px-1.5' : 'border-slate-50 dark:border-slate-800/60'}`}>
                                <div className="min-w-0">
                                  <p className="text-xs font-semibold text-slate-800 dark:text-slate-200 truncate flex items-center gap-1">
                                    {p.name}
                                    {p.is_cross_day && (
                                      <span title="Shift lintas tengah malam">
                                        <Moon className="w-3 h-3 text-indigo-400 shrink-0" />
                                      </span>
                                    )}
                                  </p>
                                  <p className="text-[10px] text-slate-400">
                                    {p.employee_code && <span className="font-mono mr-1">{p.employee_code} ·</span>}
                                    {p.department ?? '—'} ·{' '}
                                    {p.is_cross_day
                                      ? fmtDateRange(p.shift_date, p.checkout_date)
                                      : fmtTime(p.check_in_time)
                                    }
                                    {!p.is_cross_day && p.check_out_time ? ` – ${fmtTime(p.check_out_time)}` : ''}
                                    {p.is_cross_day && <span className="ml-1 text-indigo-400 font-medium">· shift malam</span>}
                                  </p>
                                </div>
                                <div className="flex items-center gap-1.5 shrink-0">
                                  {p.check_in_type === 'wfh' && <Home className="w-3 h-3 text-indigo-500" />}
                                  {p.check_in_type === 'field' && <MapPin className="w-3 h-3 text-amber-500" />}
                                  <span className={`text-[9px] font-bold px-2 py-0.5 rounded ${statusBadge(p.status)}`}>{statusLabel(p.status)}</span>
                                </div>
                              </div>
                            ))}
                            {checkedIn.length > (todayColumnLimit.checkedIn || 40) && (
                              <button
                                type="button"
                                onClick={() => setTodayColumnLimit(prev => ({ ...prev, checkedIn: (prev.checkedIn || 40) + 50 }))}
                                className="w-full py-1 text-[10px] font-semibold text-indigo-600 dark:text-indigo-400 bg-indigo-50/70 dark:bg-indigo-950/40 hover:bg-indigo-100 rounded-lg transition cursor-pointer"
                              >
                                + Tampilkan lebih banyak ({checkedIn.length - (todayColumnLimit.checkedIn || 40)} lainnya)
                              </button>
                            )}
                          </>
                        )}
                      </div>
                    </div>

                    {/* Belum check-in */}
                    <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl p-4 flex flex-col h-full">
                      <h4 className="text-xs font-bold text-rose-700 dark:text-rose-400 flex items-center gap-1.5">
                        <Clock className="w-4 h-4" /> Belum Check-in ({notCheckedIn.length})
                      </h4>
                      <CardSearch value={searchNotCheckedIn} onChange={setSearchNotCheckedIn} />
                      <div className="space-y-2 flex-1 overflow-y-auto max-h-80 pr-1">
                        {notCheckedIn.length === 0 ? (
                          <p className="text-[11px] text-slate-400 py-3 text-center">{searchNotCheckedIn || todayOfficeFilter ? 'Tidak ditemukan.' : 'Semua sudah hadir.'}</p>
                        ) : (
                          <>
                            {notCheckedIn.slice(0, todayColumnLimit.notCheckedIn || 40).map((p: any) => (
                              <div key={p.user_id} className="flex items-center justify-between border-b border-slate-50 dark:border-slate-800/60 pb-2">
                                <div className="min-w-0">
                                  <p className="text-xs font-semibold text-slate-800 dark:text-slate-200 truncate">{p.name}</p>
                                  <p className="text-[10px] text-slate-400">
                                    {p.employee_code && <span className="font-mono">{p.employee_code} · </span>}
                                    {p.department ?? '—'}
                                  </p>
                                </div>
                              </div>
                            ))}
                            {notCheckedIn.length > (todayColumnLimit.notCheckedIn || 40) && (
                              <button
                                type="button"
                                onClick={() => setTodayColumnLimit(prev => ({ ...prev, notCheckedIn: (prev.notCheckedIn || 40) + 50 }))}
                                className="w-full py-1 text-[10px] font-semibold text-rose-600 dark:text-rose-400 bg-rose-50/70 dark:bg-rose-950/40 hover:bg-rose-100 rounded-lg transition cursor-pointer"
                              >
                                + Tampilkan lebih banyak ({notCheckedIn.length - (todayColumnLimit.notCheckedIn || 40)} lainnya)
                              </button>
                            )}
                          </>
                        )}
                      </div>
                    </div>

                    {/* Sedang Libur */}
                    <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl p-4 flex flex-col h-full">
                      <h4 className="text-xs font-bold text-slate-700 dark:text-slate-400 flex items-center gap-1.5">
                        <CalendarDays className="w-4 h-4" /> Sedang Libur ({offToday.length})
                      </h4>
                      <CardSearch value={searchOffToday} onChange={setSearchOffToday} />
                      <div className="space-y-2 flex-1 overflow-y-auto max-h-80 pr-1">
                        {offToday.length === 0 ? (
                          <p className="text-[11px] text-slate-400 py-3 text-center">{searchOffToday || todayOfficeFilter ? 'Tidak ditemukan.' : 'Tidak ada.'}</p>
                        ) : (
                          <>
                            {offToday.slice(0, todayColumnLimit.offToday || 40).map((p: any) => (
                              <div key={p.user_id} className="flex items-center justify-between border-b border-slate-50 dark:border-slate-800/60 pb-2">
                                <div className="min-w-0">
                                  <p className="text-xs font-semibold text-slate-800 dark:text-slate-200 truncate">{p.name}</p>
                                  <p className="text-[10px] text-slate-400">
                                    {p.employee_code && <span className="font-mono">{p.employee_code} · </span>}
                                    {p.department ?? '—'}
                                  </p>
                                </div>
                                <span className="text-[9px] font-bold px-2 py-0.5 rounded bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400 shrink-0 border border-slate-200 dark:border-slate-700">Libur</span>
                              </div>
                            ))}
                            {offToday.length > (todayColumnLimit.offToday || 40) && (
                              <button
                                type="button"
                                onClick={() => setTodayColumnLimit(prev => ({ ...prev, offToday: (prev.offToday || 40) + 50 }))}
                                className="w-full py-1 text-[10px] font-semibold text-slate-600 dark:text-slate-400 bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 rounded-lg transition cursor-pointer"
                              >
                                + Tampilkan lebih banyak ({offToday.length - (todayColumnLimit.offToday || 40)} lainnya)
                              </button>
                            )}
                          </>
                        )}
                      </div>
                    </div>

                    {/* Izin/cuti */}
                    <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl p-4 flex flex-col h-full">
                      <h4 className="text-xs font-bold text-amber-700 dark:text-amber-400 flex items-center gap-1.5">
                        <ClipboardList className="w-4 h-4" /> Sedang Izin/Cuti ({onLeave.length})
                      </h4>
                      <CardSearch value={searchOnLeave} onChange={setSearchOnLeave} />
                      <div className="space-y-2 flex-1 overflow-y-auto max-h-80 pr-1">
                        {onLeave.length === 0 ? (
                          <p className="text-[11px] text-slate-400 py-3 text-center">{searchOnLeave || todayOfficeFilter ? 'Tidak ditemukan.' : 'Tidak ada.'}</p>
                        ) : (
                          <>
                            {onLeave.slice(0, todayColumnLimit.onLeave || 40).map((p: any) => (
                              <div key={p.user_id} className="flex items-center justify-between border-b border-slate-50 dark:border-slate-800/60 pb-2">
                                <div className="min-w-0">
                                  <p className="text-xs font-semibold text-slate-800 dark:text-slate-200 truncate">{p.name}</p>
                                  <p className="text-[10px] text-slate-400">
                                    {p.employee_code && <span className="font-mono">{p.employee_code} · </span>}
                                    {p.department ?? '—'}
                                  </p>
                                </div>
                                <span className="text-[9px] font-bold px-2 py-0.5 rounded bg-amber-50 text-amber-700 capitalize shrink-0">{p.leave_type}</span>
                              </div>
                            ))}
                            {onLeave.length > (todayColumnLimit.onLeave || 40) && (
                              <button
                                type="button"
                                onClick={() => setTodayColumnLimit(prev => ({ ...prev, onLeave: (prev.onLeave || 40) + 50 }))}
                                className="w-full py-1 text-[10px] font-semibold text-amber-700 dark:text-amber-400 bg-amber-50/70 dark:bg-amber-950/40 hover:bg-amber-100 rounded-lg transition cursor-pointer"
                              >
                                + Tampilkan lebih banyak ({onLeave.length - (todayColumnLimit.onLeave || 40)} lainnya)
                              </button>
                            )}
                          </>
                        )}
                      </div>
                    </div>
                  </>
                );
              })()}
            </div>
          </div>
        )
      )}

      {/* ─── TAB: Izin & Cuti ─── */}
      {tab === 'leaves' && (
        loading ? <TabSkeleton tab="leaves" /> : (() => {
          const todayStr = new Date().toISOString().slice(0, 10);

          // ── Filter lokal ──────────────────────────────────────
          const displayedLeaves = (() => {
            let result = leaves;
            if (showUpcoming) {
              // Mode mendatang: approved saja + belum selesai
              result = leaves.filter((l: any) =>
                l.status === 'approved' &&
                (l.end_date ?? '').slice(0, 10) >= todayStr
              );
            } else {
              if (leaveStatus) result = result.filter((l: any) => l.status === leaveStatus);
              if (leaveTypeFilter) result = result.filter((l: any) => l.leave_type === leaveTypeFilter);
            }
            // Filter sumber cuti — berlaku di semua mode (normal maupun mendatang)
            if (leaveSourceFilter === 'mandiri') result = result.filter((l: any) => l.holiday_id == null);
            else if (leaveSourceFilter === 'collective') result = result.filter((l: any) => l.holiday_id != null);
            // Filter cabang — berlaku di semua mode (normal maupun mendatang)
            if (leaveOfficeFilter === 'null') {
              result = result.filter((l: any) => l.attendance_setting_id == null);
            } else if (leaveOfficeFilter) {
              result = result.filter((l: any) => String(l.attendance_setting_id) === leaveOfficeFilter);
            }
            if (debouncedLeaveSearch) {
              result = result.filter((l: any) =>
                l.user_name.toLowerCase().includes(debouncedLeaveSearch.toLowerCase())
              );
            }
            return result;
          })();

          const totalLeavePages = Math.max(1, Math.ceil(displayedLeaves.length / leavePageSize));
          const paginatedLeaves = displayedLeaves.slice((leavePage - 1) * leavePageSize, leavePage * leavePageSize);

          // ── Deteksi bentrok: hanya untuk baris pending ────────
          // Pre-filter approved leave non-kolektif untuk kecepatan O(1) loop
          const relevantApprovedLeaves = leaves.filter((other: any) =>
            other.status === 'approved' &&
            other.holiday_id == null &&
            other.attendance_setting_id != null
          );

          const dateOverlaps = (s1: string, e1: string, s2: string, e2: string) =>
            s1.slice(0, 10) <= e2.slice(0, 10) && e1.slice(0, 10) >= s2.slice(0, 10);

          const getApprovedConflicts = (pendingLeave: any): string[] => {
            if (pendingLeave.status !== 'pending') return [];
            if (pendingLeave.holiday_id != null) return [];
            if (pendingLeave.attendance_setting_id == null) return [];
            const found: string[] = [];
            for (const other of relevantApprovedLeaves) {
              if (other.id === pendingLeave.id) continue;
              if (other.user_name === pendingLeave.user_name) continue;
              if (pendingLeave.attendance_setting_id !== other.attendance_setting_id) continue;
              if (dateOverlaps(
                pendingLeave.start_date, pendingLeave.end_date,
                other.start_date, other.end_date
              )) {
                if (!found.includes(other.user_name)) found.push(other.user_name);
              }
            }
            return found;
          };

          const leaveTypeLabel = (t: string) =>
            ({ cuti: 'Cuti', izin: 'Izin', sakit: 'Sakit', wfh: 'WFH' }[t] ?? t);

          return (
            <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl p-5 space-y-4">

              {/* Filter bar */}
              <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex flex-wrap items-center gap-2">

                  {/* Dropdown status */}
                  <select
                    value={showUpcoming ? 'approved' : leaveStatus}
                    disabled={showUpcoming}
                    onChange={(e) => {
                      setShowUpcoming(false);
                      setLeaveStatus(e.target.value as any);
                    }}
                    className="px-3 py-1.5 rounded-lg text-[11px] font-semibold border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 focus:outline-none focus:ring-1 focus:ring-indigo-400 disabled:opacity-50 disabled:cursor-not-allowed"
                  >
                    <option value="">Semua Status</option>
                    <option value="pending">Menunggu</option>
                    <option value="approved">Disetujui</option>
                    <option value="rejected">Ditolak</option>
                  </select>

                  {/* Dropdown tipe */}
                  <select
                    value={showUpcoming ? '' : leaveTypeFilter}
                    disabled={showUpcoming}
                    onChange={(e) => setLeaveTypeFilter(e.target.value as any)}
                    className="px-3 py-1.5 rounded-lg text-[11px] font-semibold border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 focus:outline-none focus:ring-1 focus:ring-indigo-400 disabled:opacity-50 disabled:cursor-not-allowed"
                  >
                    <option value="">Semua Tipe</option>
                    <option value="izin">Izin</option>
                    <option value="sakit">Sakit</option>
                    <option value="cuti">Cuti</option>
                    <option value="wfh">WFH</option>
                  </select>

                  {/* Dropdown sumber cuti */}
                  <select
                    value={showUpcoming ? 'all' : leaveSourceFilter}
                    disabled={showUpcoming}
                    onChange={(e) => setLeaveSourceFilter(e.target.value as any)}
                    className="px-3 py-1.5 rounded-lg text-[11px] font-semibold border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 focus:outline-none focus:ring-1 focus:ring-indigo-400 disabled:opacity-50 disabled:cursor-not-allowed"
                  >
                    <option value="all">Semua Sumber</option>
                    <option value="mandiri">Mandiri (Mobile)</option>
                    <option value="collective">Cuti Bersama</option>
                  </select>

                  {/* Dropdown kantor cabang */}
                  {offices.length > 1 && (
                    <select
                      value={leaveOfficeFilter}
                      onChange={(e) => setLeaveOfficeFilter(e.target.value)}
                      className="px-3 py-1.5 rounded-lg text-[11px] font-semibold border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 focus:outline-none focus:ring-1 focus:ring-indigo-400"
                    >
                      <option value="">Semua Kantor</option>
                      {offices.map((o: any) => (
                        <option key={o.id} value={String(o.id)}>{o.office_name}</option>
                      ))}
                      <option value="null">Tanpa Kantor</option>
                    </select>
                  )}

                  {/* Tombol Mendatang */}
                  <button
                    onClick={() => setShowUpcoming(v => !v)}
                    className={`flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-[11px] font-semibold border transition ${showUpcoming
                      ? 'bg-amber-500 text-white border-amber-500 shadow-sm'
                      : 'bg-white dark:bg-slate-800 text-slate-600 dark:text-slate-300 border-slate-200 dark:border-slate-700 hover:bg-amber-50 hover:border-amber-300 hover:text-amber-700'
                      }`}
                    title="Tampilkan cuti/izin sudah disetujui yang belum terlaksana"
                  >
                    <CalendarClock className="w-3.5 h-3.5" />
                    Mendatang
                    {showUpcoming && (
                      <span className="ml-1 bg-white/30 text-white text-[9px] font-bold px-1.5 py-0.5 rounded-full">
                        {displayedLeaves.length}
                      </span>
                    )}
                  </button>
                </div>

                {/* Search */}
                <div className="relative">
                  <Search className="absolute left-2.5 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-slate-400 pointer-events-none" />
                  <input
                    type="text"
                    placeholder="Cari nama karyawan..."
                    value={leaveSearch}
                    onChange={(e) => setLeaveSearch(e.target.value)}
                    className="pl-8 pr-3 py-1.5 text-xs border border-slate-200 dark:border-slate-700 rounded-lg bg-slate-50 dark:bg-slate-800/20 text-slate-800 dark:text-slate-100 placeholder-slate-400 focus:outline-none focus:ring-1 focus:ring-indigo-400 w-full sm:w-48"
                  />
                </div>
              </div>

              {/* Label mode mendatang */}
              {showUpcoming && (
                <div className="flex items-center gap-2 text-[11px] text-amber-700 dark:text-amber-400 bg-amber-50 dark:bg-amber-950/20 border border-amber-200 dark:border-amber-800 rounded-lg px-3 py-2">
                  <CalendarClock className="w-3.5 h-3.5 shrink-0" />
                  Menampilkan <span className="font-bold mx-0.5">{displayedLeaves.length}</span> izin/cuti yang sudah disetujui dan belum terlaksana.
                  <span className="ml-auto text-amber-500 italic">HRD tetap berhak approve/tolak pengajuan baru.</span>
                </div>
              )}

              {/* Tabel */}
              <div className="overflow-x-auto">
                <table className="w-full text-xs text-left">
                  <thead>
                    <tr className="border-b border-slate-100 dark:border-slate-800 text-slate-500">
                      <th className="py-2 px-2 font-semibold">Karyawan</th>
                      <th className="py-2 px-2 font-semibold">Tipe</th>
                      <th className="py-2 px-2 font-semibold">Sumber</th>
                      <th className="py-2 px-2 font-semibold">Periode</th>
                      <th className="py-2 px-2 font-semibold text-center">Hari</th>
                      <th className="py-2 px-2 font-semibold">Alasan / Status Pilihan</th>
                      <th className="py-2 px-2 font-semibold text-center">Status</th>
                      <th className="py-2 px-2 font-semibold text-right">Aksi</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-50 dark:divide-slate-800/60">
                    {displayedLeaves.length === 0 ? (
                      <tr>
                        <td colSpan={7} className="text-center py-8 text-slate-400">
                          {showUpcoming ? 'Tidak ada izin/cuti mendatang yang sudah disetujui.' : 'Tidak ada pengajuan.'}
                        </td>
                      </tr>
                    ) : (
                      paginatedLeaves.map((l: any) => {
                        // Alert hanya muncul pada baris PENDING yang bentrok dengan approved lain
                        const conflicts = getApprovedConflicts(l);
                        const hasConflict = conflicts.length > 0;

                        return (
                          <tr
                            key={l.id}
                            className={`transition-colors ${hasConflict
                              ? 'bg-amber-50/50 dark:bg-amber-950/10 hover:bg-amber-50/80'
                              : 'hover:bg-slate-50/50 dark:hover:bg-slate-800/30'
                              }`}
                          >
                            <td className="py-2.5 px-2">
                              <p className="font-semibold text-slate-800 dark:text-slate-200">{l.user_name}</p>
                              <p className="text-[10px] text-slate-400">{l.department ?? '—'}</p>
                              {/* Alert bentrok — hanya pada pending */}
                              {hasConflict && (
                                <div className="mt-1.5 flex items-start gap-1 bg-amber-100 dark:bg-amber-900/30 border border-amber-200 dark:border-amber-700 rounded-md px-2 py-1">
                                  <AlertTriangle className="w-3 h-3 text-amber-600 dark:text-amber-400 shrink-0 mt-0.5" />
                                  <span className="text-[10px] text-amber-800 dark:text-amber-300 leading-tight">
                                    <span className="font-bold">{conflicts.join(', ')}</span> sudah cuti di periode yang sama.
                                  </span>
                                </div>
                              )}
                            </td>
                            <td className="py-2.5 px-2">
                              <span className={`text-[9px] font-bold px-2 py-0.5 rounded ${l.leave_type === 'cuti' ? 'bg-teal-50 text-teal-700' :
                                l.leave_type === 'izin' ? 'bg-purple-50 text-purple-700' :
                                  l.leave_type === 'sakit' ? 'bg-orange-50 text-orange-700' :
                                    'bg-indigo-50 text-indigo-700'
                                }`}>
                                {leaveTypeLabel(l.leave_type)}
                              </span>
                              {/* Tombol surat dokter — muncul jika ada lampiran */}
                              {l.has_document && (
                                <button
                                  onClick={() => openLeaveDocument(l.id, l.user_name)}
                                  disabled={docLoadingId === l.id}
                                  className="mt-1.5 flex items-center gap-1 text-[10px] font-semibold text-sky-600 dark:text-sky-400 hover:text-sky-800 hover:underline disabled:opacity-50 cursor-pointer"
                                  title="Lihat surat dokter"
                                >
                                  {docLoadingId === l.id ? (
                                    <span className="w-3 h-3 border-2 border-sky-300 border-t-sky-600 rounded-full animate-spin" />
                                  ) : (
                                    <FileText className="w-3 h-3" />
                                  )}
                                  Surat Dokter
                                </button>
                              )}
                            </td>
                            {/* Kolom Sumber: Mandiri (mobile) atau Cuti Bersama (dari kalender HR) */}
                            <td className="py-2.5 px-2">
                              {l.holiday_id != null ? (
                                <span className="inline-flex items-center gap-1 text-[9px] font-bold px-2 py-0.5 rounded bg-blue-50 text-blue-700 dark:bg-blue-950/30 dark:text-blue-400 border border-blue-100 dark:border-blue-800">
                                  <CalendarDays className="w-3 h-3" />
                                  Cuti Bersama
                                </span>
                              ) : (
                                <span className="inline-flex items-center gap-1 text-[9px] font-bold px-2 py-0.5 rounded bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400">
                                  Mandiri
                                </span>
                              )}
                            </td>
                            <td className="py-2.5 px-2 text-slate-500 whitespace-nowrap">
                              {fmtDate(l.start_date)} – {fmtDate(l.end_date)}
                            </td>
                            <td className="py-2.5 px-2 text-center font-mono">{l.total_days}</td>
                            {/* Kolom Alasan / Status Pilihan */}
                            <td className="py-2.5 px-2 max-w-[180px] text-slate-500">
                              {l.holiday_id != null ? (
                                // Cuti bersama: tampilkan status pilihan karyawan
                                <div className="space-y-1">
                                  <span className={`inline-block text-[9px] font-bold px-2 py-0.5 rounded ${
                                    l.collective_status === 'accepted'
                                      ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-400'
                                      : l.collective_status === 'declined'
                                        ? 'bg-rose-50 text-rose-700 dark:bg-rose-950/30 dark:text-rose-400'
                                        : 'bg-amber-50 text-amber-700 dark:bg-amber-950/30 dark:text-amber-400'
                                  }`}>
                                    {l.collective_status === 'accepted' ? 'Ikut' : l.collective_status === 'declined' ? 'Tidak Ikut' : 'Belum Memilih'}
                                  </span>
                                  {l.reason && <p className="text-[10px] truncate" title={l.reason}>{l.reason}</p>}
                                </div>
                              ) : (
                                // Cuti mandiri: tampilkan alasan biasa
                                <span className="truncate block" title={l.reason}>{l.reason}</span>
                              )}
                            </td>
                            <td className="py-2.5 px-2 text-center">
                              <span className={`text-[9px] font-bold px-2 py-0.5 rounded ${leaveBadge(l.status)}`}>
                                {l.status === 'approved' ? 'Disetujui' : l.status === 'rejected' ? 'Ditolak' : 'Menunggu'}
                              </span>
                            </td>
                            <td className="py-2.5 px-2 text-right">
                              {l.status === 'pending' && l.holiday_id == null ? (
                                // Cuti mandiri pending: tampilkan tombol approve/tolak
                                <div className="flex justify-end gap-1.5">
                                  <button
                                    onClick={() => handleApproveLeave(l.id, l.user_name)}
                                    className="p-1.5 rounded-lg bg-emerald-50 text-emerald-600 hover:bg-emerald-100 cursor-pointer"
                                    title="Setujui"
                                  >
                                    <Check className="w-3.5 h-3.5" />
                                  </button>
                                  <button
                                    onClick={() => handleRejectLeave(l.id, l.user_name)}
                                    className="p-1.5 rounded-lg bg-rose-50 text-rose-600 hover:bg-rose-100 cursor-pointer"
                                    title="Tolak"
                                  >
                                    <X className="w-3.5 h-3.5" />
                                  </button>
                                </div>
                              ) : l.status === 'pending' && l.holiday_id != null ? (
                                // Cuti bersama pending: karyawan memilih sendiri via mobile
                                <span
                                  className="text-[10px] text-blue-500 dark:text-blue-400 italic cursor-help"
                                  title="Cuti bersama: karyawan memutuskan sendiri (accept/decline) via aplikasi mobile. HR tidak perlu melakukan approval manual."
                                >
                                  Karyawan memilih sendiri
                                </span>
                              ) : (
                                <span className="text-[10px] text-slate-400">
                                  {l.rejection_reason ? `Ditolak: ${l.rejection_reason}` : '—'}
                                </span>
                              )}
                            </td>
                          </tr>
                        );
                      })
                    )}
                  </tbody>
                </table>
              </div>

              {/* Pagination Bar */}
              {displayedLeaves.length >= 25 && (
                <div className="flex flex-col sm:flex-row items-center justify-between gap-3 pt-3 mt-2 border-t border-slate-100 dark:border-slate-800 text-xs">
                  <div className="flex items-center gap-2 text-slate-500 dark:text-slate-400">
                    <span>
                      Menampilkan <strong className="text-slate-800 dark:text-slate-200 font-bold font-mono">
                        {Math.min((leavePage - 1) * leavePageSize + 1, displayedLeaves.length)} - {Math.min(leavePage * leavePageSize, displayedLeaves.length)}
                      </strong> dari <strong className="text-slate-800 dark:text-slate-200 font-bold font-mono">{displayedLeaves.length}</strong> pengajuan
                    </span>
                    <span className="hidden sm:inline">•</span>
                    <div className="flex items-center gap-1.5">
                      <span className="hidden sm:inline">Per hal:</span>
                      <select
                        value={leavePageSize}
                        onChange={(e) => {
                          setLeavePageSize(Number(e.target.value));
                          setLeavePage(1);
                        }}
                        className="py-0.5 px-2 text-xs font-semibold border border-slate-200 dark:border-slate-700 rounded-lg bg-slate-50 dark:bg-slate-800/40 text-slate-700 dark:text-slate-300 focus:outline-none cursor-pointer"
                      >
                        <option value={25}>25</option>
                        <option value={50}>50</option>
                        <option value={100}>100</option>
                      </select>
                    </div>
                  </div>

                  <div className="flex items-center gap-1.5">
                    <button
                      type="button"
                      onClick={() => setLeavePage(1)}
                      disabled={leavePage === 1}
                      className="p-1 px-2 rounded-lg border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800 disabled:opacity-40 disabled:cursor-not-allowed transition font-medium cursor-pointer"
                      title="Halaman Pertama"
                    >
                      «
                    </button>
                    <button
                      type="button"
                      onClick={() => setLeavePage(p => Math.max(1, p - 1))}
                      disabled={leavePage === 1}
                      className="p-1 px-2 rounded-lg border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800 disabled:opacity-40 disabled:cursor-not-allowed transition font-medium cursor-pointer"
                      title="Halaman Sebelumnya"
                    >
                      ‹
                    </button>
                    <span className="px-2 font-semibold text-slate-700 dark:text-slate-300">
                      Hal <span className="font-mono font-bold text-indigo-600 dark:text-indigo-400">{leavePage}</span> / <span className="font-mono">{totalLeavePages}</span>
                    </span>
                    <button
                      type="button"
                      onClick={() => setLeavePage(p => Math.min(totalLeavePages, p + 1))}
                      disabled={leavePage === totalLeavePages}
                      className="p-1 px-2 rounded-lg border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800 disabled:opacity-40 disabled:cursor-not-allowed transition font-medium cursor-pointer"
                      title="Halaman Berikutnya"
                    >
                      ›
                    </button>
                    <button
                      type="button"
                      onClick={() => setLeavePage(totalLeavePages)}
                      disabled={leavePage === totalLeavePages}
                      className="p-1 px-2 rounded-lg border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800 disabled:opacity-40 disabled:cursor-not-allowed transition font-medium cursor-pointer"
                      title="Halaman Terakhir"
                    >
                      »
                    </button>
                  </div>
                </div>
              )}
            </div>
          );
        })()
      )}

      {/* ─── TAB: Karyawan & WFH ─── */}
      {tab === 'users' && (
        loading ? <TabSkeleton tab="users" /> : (
          <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl p-5">
            <div className="bg-indigo-50/40 dark:bg-indigo-950/20 border border-indigo-100 dark:border-indigo-900/30 p-3 rounded-xl text-[11px] text-indigo-900 dark:text-indigo-400 flex items-start gap-2 mb-4">
              <Home className="w-4 h-4 shrink-0 mt-0.5" />
              <span>Mode WFH ON → karyawan bisa presensi dari rumah via aplikasi mobile. OFF → presensi hanya di kantor (perangkat presensi). Radius ON → presensi mobile wajib dalam radius area kerja (mode lapangan).</span>
            </div>
            <div className="flex flex-col sm:flex-row items-stretch sm:items-center gap-2 mb-3">
              <div className="relative flex-1">
                <Search className="absolute left-2.5 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-slate-400 pointer-events-none" />
                <input
                  type="text"
                  placeholder="Cari nama atau NIK karyawan..."
                  value={userSearch}
                  onChange={(e) => setUserSearch(e.target.value)}
                  className="w-full pl-8 pr-3 py-2 text-xs border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-800/20 text-slate-800 dark:text-slate-100 placeholder-slate-400 focus:outline-none focus:ring-1 focus:ring-indigo-400"
                />
              </div>
              <select
                value={userOfficeFilter}
                onChange={(e) => setUserOfficeFilter(e.target.value)}
                className="py-2 px-3 text-xs font-semibold border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-800/20 text-slate-700 dark:text-slate-300 focus:outline-none focus:ring-1 focus:ring-indigo-400 cursor-pointer"
              >
                <option value="">Semua Kantor</option>
                {offices.map(o => (
                  <option key={o.id} value={o.id}>{o.office_name}</option>
                ))}
                <option value="null">Tanpa Kantor</option>
              </select>
            </div>
            <div className="overflow-x-auto">
              {(() => {
                const filtered = users.filter(u => {
                  const q = debouncedUserSearch.toLowerCase();
                  const matchSearch = !q ||
                    u.name.toLowerCase().includes(q) ||
                    (u.employee_code && u.employee_code.toLowerCase().includes(q)) ||
                    (u.nik && u.nik.toLowerCase().includes(q));
                  const matchOffice = !userOfficeFilter ||
                    (userOfficeFilter === 'null'
                      ? !u.attendance_setting_id
                      : String(u.attendance_setting_id) === String(userOfficeFilter));
                  return matchSearch && matchOffice;
                });
                const totalUserPages = Math.max(1, Math.ceil(filtered.length / userPageSize));
                const paginatedUsers = filtered.slice((userPage - 1) * userPageSize, userPage * userPageSize);

                return (
                  <>
                    <table className="w-full text-xs text-left">
                      <thead>
                        <tr className="border-b border-slate-100 dark:border-slate-800 text-slate-500">
                          <th className="py-2 px-2 font-semibold">Nama</th>
                          <th className="py-2 px-2 font-semibold">Departemen</th>
                          <th className="py-2 px-2 font-semibold">Kantor</th>
                          <th className="py-2 px-2 font-semibold">Role</th>
                          <th className="py-2 px-2 font-semibold text-center">Mode WFH</th>
                          <th className="py-2 px-2 font-semibold text-center">Radius Lapangan</th>
                        </tr>
                      </thead>
                      <tbody className="divide-y divide-slate-50 dark:divide-slate-800/60">
                        {filtered.length === 0 ? (
                          <tr><td colSpan={6} className="text-center py-8 text-slate-400">{userSearch || userOfficeFilter ? 'Tidak ada karyawan yang cocok dengan filter.' : 'Tidak ada karyawan.'}</td></tr>
                        ) : (
                          paginatedUsers.map((u) => (
                            <tr key={u.id} className="hover:bg-slate-50/50 dark:hover:bg-slate-800/30">
                              <td className="py-2.5 px-2 font-semibold text-slate-800 dark:text-slate-200">
                                {u.name}
                                {(u.employee_code || u.nik) && (
                                  <span className="ml-1.5 text-[10px] font-mono font-normal text-slate-400">({u.employee_code || u.nik})</span>
                                )}
                              </td>
                              <td className="py-2.5 px-2 text-slate-500">{u.department ?? '—'}</td>
                              <td className="py-2.5 px-2 text-slate-500">{u.office?.office_name ?? u.office_name ?? '—'}</td>
                              <td className="py-2.5 px-2 text-slate-500 capitalize">{u.role}</td>
                              <td className="py-2.5 px-2 text-center">
                                <button
                                  onClick={() => handleToggleWfh(u.id, u.name)}
                                  className={`relative inline-flex h-5 w-9 items-center rounded-full transition ${u.wfh_enabled ? 'bg-emerald-500' : 'bg-slate-300 dark:bg-slate-700'}`}
                                  title={u.wfh_enabled ? 'WFH aktif — klik untuk nonaktifkan' : 'WFH nonaktif — klik untuk aktifkan'}
                                >
                                  <span className={`inline-block h-3.5 w-3.5 transform rounded-full bg-white transition ${u.wfh_enabled ? 'translate-x-4' : 'translate-x-1'}`} />
                                </button>
                              </td>
                              <td className="py-2.5 px-2 text-center">
                                {u.wfh_enabled ? (
                                  <button
                                    onClick={() => handleToggleRadius(u.id, u.name)}
                                    className={`relative inline-flex h-5 w-9 items-center rounded-full transition ${u.radius_enabled ? 'bg-amber-500' : 'bg-slate-300 dark:bg-slate-700'}`}
                                    title={u.radius_enabled ? 'Radius aktif (lapangan) — klik untuk nonaktifkan' : 'Radius nonaktif (WFH bebas) — klik untuk aktifkan'}
                                  >
                                    <span className={`inline-block h-3.5 w-3.5 transform rounded-full bg-white transition ${u.radius_enabled ? 'translate-x-4' : 'translate-x-1'}`} />
                                  </button>
                                ) : (
                                  <span className="text-[10px] text-slate-300 dark:text-slate-700">—</span>
                                )}
                              </td>
                            </tr>
                          ))
                        )}
                      </tbody>
                    </table>

                    {/* Pagination Bar */}
                    {filtered.length >= 25 && (
                      <div className="flex flex-col sm:flex-row items-center justify-between gap-3 pt-3 mt-2 border-t border-slate-100 dark:border-slate-800 text-xs">
                        <div className="flex items-center gap-2 text-slate-500 dark:text-slate-400">
                          <span>
                            Menampilkan <strong className="text-slate-800 dark:text-slate-200 font-bold font-mono">
                              {Math.min((userPage - 1) * userPageSize + 1, filtered.length)} - {Math.min(userPage * userPageSize, filtered.length)}
                            </strong> dari <strong className="text-slate-800 dark:text-slate-200 font-bold font-mono">{filtered.length}</strong> karyawan
                          </span>
                          <span className="hidden sm:inline">•</span>
                          <div className="flex items-center gap-1.5">
                            <span className="hidden sm:inline">Per hal:</span>
                            <select
                              value={userPageSize}
                              onChange={(e) => {
                                setUserPageSize(Number(e.target.value));
                                setUserPage(1);
                              }}
                              className="py-0.5 px-2 text-xs font-semibold border border-slate-200 dark:border-slate-700 rounded-lg bg-slate-50 dark:bg-slate-800/40 text-slate-700 dark:text-slate-300 focus:outline-none cursor-pointer"
                            >
                              <option value={25}>25</option>
                              <option value={50}>50</option>
                              <option value={100}>100</option>
                            </select>
                          </div>
                        </div>

                        <div className="flex items-center gap-1.5">
                          <button
                            type="button"
                            onClick={() => setUserPage(1)}
                            disabled={userPage === 1}
                            className="p-1 px-2 rounded-lg border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800 disabled:opacity-40 disabled:cursor-not-allowed transition font-medium cursor-pointer"
                            title="Halaman Pertama"
                          >
                            «
                          </button>
                          <button
                            type="button"
                            onClick={() => setUserPage(p => Math.max(1, p - 1))}
                            disabled={userPage === 1}
                            className="p-1 px-2 rounded-lg border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800 disabled:opacity-40 disabled:cursor-not-allowed transition font-medium cursor-pointer"
                            title="Halaman Sebelumnya"
                          >
                            ‹
                          </button>
                          <span className="px-2 font-semibold text-slate-700 dark:text-slate-300">
                            Hal <span className="font-mono font-bold text-indigo-600 dark:text-indigo-400">{userPage}</span> / <span className="font-mono">{totalUserPages}</span>
                          </span>
                          <button
                            type="button"
                            onClick={() => setUserPage(p => Math.min(totalUserPages, p + 1))}
                            disabled={userPage === totalUserPages}
                            className="p-1 px-2 rounded-lg border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800 disabled:opacity-40 disabled:cursor-not-allowed transition font-medium cursor-pointer"
                            title="Halaman Berikutnya"
                          >
                            ›
                          </button>
                          <button
                            type="button"
                            onClick={() => setUserPage(totalUserPages)}
                            disabled={userPage === totalUserPages}
                            className="p-1 px-2 rounded-lg border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800 disabled:opacity-40 disabled:cursor-not-allowed transition font-medium cursor-pointer"
                            title="Halaman Terakhir"
                          >
                            »
                          </button>
                        </div>
                      </div>
                    )}
                  </>
                );
              })()}
            </div>
          </div>
        )
      )}

      {/* ─── TAB: Saldo Cuti ─── */}
      {tab === 'balances' && (
        <div className="space-y-5">
          {/* Sub-tab Navigation (Segmented Switch) */}
          <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 pb-2 border-b border-slate-100 dark:border-slate-800">
            <div className="inline-flex p-1 bg-slate-100 dark:bg-slate-800/80 rounded-xl border border-slate-200/60 dark:border-slate-700/60">
              <button
                onClick={() => setBalanceSubTab('active')}
                className={`flex items-center gap-2 px-3.5 py-1.5 rounded-lg text-xs font-bold transition-all ${
                  balanceSubTab === 'active'
                    ? 'bg-white dark:bg-slate-900 text-indigo-600 dark:text-indigo-400 shadow-sm'
                    : 'text-slate-500 hover:text-slate-800 dark:hover:text-slate-200'
                }`}
              >
                <Users className="w-3.5 h-3.5" />
                Saldo Berjalan (Periode Aktif)
              </button>
              <button
                onClick={() => {
                  setBalanceSubTab('history');
                  loadBalanceHistories();
                }}
                className={`flex items-center gap-2 px-3.5 py-1.5 rounded-lg text-xs font-bold transition-all ${
                  balanceSubTab === 'history'
                    ? 'bg-white dark:bg-slate-900 text-indigo-600 dark:text-indigo-400 shadow-sm'
                    : 'text-slate-500 hover:text-slate-800 dark:hover:text-slate-200'
                }`}
              >
                <History className="w-3.5 h-3.5" />
                Riwayat Saldo Sebelumnya
                {balanceHistories.length > 0 && (
                  <span className="ml-1 px-1.5 py-0.2 bg-indigo-50 dark:bg-indigo-950/60 text-indigo-600 dark:text-indigo-300 text-[10px] rounded-full font-bold">
                    {balanceHistories.length}
                  </span>
                )}
              </button>
            </div>

            {/* Info ringkas anniversary / reset */}
            <p className="text-[11px] text-slate-400 flex items-center gap-1.5">
              <Sparkles className="w-3.5 h-3.5 text-amber-500 shrink-0" />
              <span>Saat tanggal reset tiba (misal: 1 Des), pemakaian cuti &amp; izin/sakit otomatis tereset dan diarsipkan ke riwayat.</span>
            </p>
          </div>

          {/* ── SUB-TAB: Saldo Berjalan (Periode Aktif) ── */}
          {balanceSubTab === 'active' && (
            loading ? <TabSkeleton tab="balances" /> : (() => {
              type BalanceEntry = { cuti?: any; izin?: any; employeeCode?: string; department?: string; officeName?: string };
              const grouped = balances.reduce<Record<string, BalanceEntry>>((acc, b) => {
                if (!acc[b.user_name]) {
                  acc[b.user_name] = {
                    employeeCode: b.employee_code || b.nik || b.user?.employee_code || '',
                    department: b.user?.department || b.department || '',
                    officeName: b.office_name || b.office?.office_name || '',
                  };
                }
                if (b.leave_type === 'cuti') acc[b.user_name].cuti = b;
                else acc[b.user_name].izin = b;
                return acc;
              }, {});

              const entries = Object.entries(grouped)
                .filter(([, data]: [string, any]) => {
                  if (balanceOfficeFilter) {
                    const officeId = String(data.cuti?.office_id ?? data.izin?.office_id ?? '');
                    if (balanceOfficeFilter === 'none') {
                      if (officeId !== '') return false;
                    } else if (officeId !== balanceOfficeFilter) {
                      return false;
                    }
                  }
                  return true;
                })
                .filter(([name, data]: [string, any]) => {
                  const q = debouncedBalanceSearch.toLowerCase();
                  return !q || name.toLowerCase().includes(q) || (data.employeeCode && data.employeeCode.toLowerCase().includes(q));
                });

              const progressColor = (remaining: number, quota: number) => {
                if (quota === 0) return 'bg-slate-300';
                const pct = remaining / quota;
                if (pct > 0.5) return 'bg-emerald-500';
                if (pct > 0.25) return 'bg-amber-400';
                return 'bg-rose-500';
              };

              const progressWidth = (remaining: number, quota: number) =>
                quota > 0 ? `${Math.min(100, Math.round((remaining / quota) * 100))}%` : '0%';

              const totalBalancePages = Math.max(1, Math.ceil(entries.length / balancePageSize));
              const paginatedEntries = entries.slice((balancePage - 1) * balancePageSize, balancePage * balancePageSize);

              return (
                <div className="space-y-4">
                  {/* Filter bar */}
                  <div className="flex items-center justify-between gap-3 flex-wrap bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl p-3.5">
                    <div className="flex items-center gap-2 text-xs text-slate-500 dark:text-slate-400">
                      <span className="font-semibold text-slate-700 dark:text-slate-300">Tahun {new Date().getFullYear()}</span>
                      <span>•</span>
                      <span>{entries.length} Karyawan</span>
                    </div>

                    <div className="flex items-center gap-2">
                      <select
                        value={balanceOfficeFilter}
                        onChange={(e) => setBalanceOfficeFilter(e.target.value)}
                        className="py-1.5 px-3 text-xs border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-800/40 text-slate-700 dark:text-slate-200 focus:outline-none focus:ring-1 focus:ring-indigo-400 max-w-[180px]"
                      >
                        <option value="">Semua Kantor</option>
                        {offices.map(o => (
                          <option key={o.id} value={o.id}>{o.office_name}</option>
                        ))}
                        <option value="none">Tanpa Kantor</option>
                      </select>
                      <div className="relative">
                        <Search className="absolute left-2.5 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-slate-400 pointer-events-none" />
                        <input
                          type="text"
                          placeholder="Cari nama atau NIK..."
                          value={balanceSearch}
                          onChange={(e) => setBalanceSearch(e.target.value)}
                          className="pl-8 pr-3 py-1.5 text-xs border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-800/40 text-slate-800 dark:text-slate-100 placeholder-slate-400 focus:outline-none focus:ring-1 focus:ring-indigo-400 w-48"
                        />
                      </div>
                      <button
                        onClick={loadBalances}
                        className="p-1.5 rounded-xl border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800 transition cursor-pointer"
                        title="Segarkan Saldo"
                      >
                        <RefreshCw className="w-3.5 h-3.5" />
                      </button>
                    </div>
                  </div>

                  {/* Grid Cards */}
                  {entries.length === 0 ? (
                    <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl p-10 text-center space-y-2">
                      <Users className="w-8 h-8 text-slate-300 dark:text-slate-600 mx-auto" />
                      <p className="text-xs font-semibold text-slate-600 dark:text-slate-300">
                        {balanceSearch ? `Tidak ada karyawan yang cocok dengan "${balanceSearch}".` : 'Belum ada data saldo.'}
                      </p>
                      <p className="text-[11px] text-slate-400">Pastikan karyawan telah di-assign ke kantor dan memiliki akun aktif.</p>
                    </div>
                  ) : (
                    <>
                      <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                        {paginatedEntries.map(([name, data]: [string, BalanceEntry]) => {
                          const cuti = data.cuti;
                          const izin = data.izin;
                          const userId = cuti?.user_id ?? izin?.user_id;
                          const isActive = (cuti?.quota ?? 0) > 0;
                          const isToggling = togglingUserId === userId;

                          return (
                            <div
                              key={name}
                              className={`bg-white dark:bg-slate-900 border rounded-2xl p-4 space-y-3 transition-all hover:shadow-sm ${isActive
                                ? 'border-slate-100 dark:border-slate-800'
                                : 'border-slate-200 dark:border-slate-700 opacity-75'
                                }`}
                            >
                              {/* Header karyawan + toggle */}
                              <div className="flex items-center gap-2 pb-2.5 border-b border-slate-100 dark:border-slate-800">
                                <div className="w-8 h-8 rounded-full bg-indigo-50 dark:bg-indigo-950/50 flex items-center justify-center shrink-0 border border-indigo-100/50 dark:border-indigo-900/30">
                                  <Users className="w-4 h-4 text-indigo-600 dark:text-indigo-400" />
                                </div>
                                <div className="flex-1 min-w-0">
                                  <p className="text-xs font-bold text-slate-800 dark:text-slate-100 truncate">
                                    {name}
                                    {data.employeeCode && (
                                      <span className="ml-1.5 text-[10px] font-mono font-normal text-slate-400">({data.employeeCode})</span>
                                    )}
                                  </p>
                                  <p className="text-[10px] text-slate-400 truncate">
                                    {data.department || '—'} {data.officeName ? `• ${data.officeName}` : ''}
                                  </p>
                                </div>

                                {/* Toggle kuota cuti 12hr/thn */}
                                <div className="flex items-center gap-2 shrink-0">
                                  <span className={`text-[9px] font-semibold ${isActive ? 'text-teal-600 dark:text-teal-400' : 'text-slate-400'}`}>
                                    Cuti 12hr/thn
                                  </span>
                                  <button
                                    disabled={isToggling || !userId}
                                    onClick={() => handleToggleCutiQuota(userId, name, cuti?.quota ?? 0)}
                                    title={isActive ? 'Nonaktifkan kuota cuti tahunan' : 'Aktifkan kuota cuti 12 hari/tahun'}
                                    className={`relative inline-flex h-5 w-9 items-center rounded-full transition-colors disabled:opacity-50 disabled:cursor-wait ${isActive ? 'bg-teal-500' : 'bg-slate-300 dark:bg-slate-700'
                                      }`}
                                  >
                                    <span className={`inline-block h-3.5 w-3.5 transform rounded-full bg-white shadow transition-transform ${isActive ? 'translate-x-4' : 'translate-x-1'
                                      }`} />
                                  </button>
                                </div>
                              </div>

                              {/* Dua kolom: Cuti & Izin */}
                              <div className="grid grid-cols-2 gap-3">
                                {/* Blok Cuti Tahunan */}
                                <div className="space-y-1.5 bg-slate-50/50 dark:bg-slate-800/30 p-2.5 rounded-xl border border-slate-100 dark:border-slate-800/60">
                                  <div className="flex items-center justify-between">
                                    <p className="text-[9px] font-bold text-slate-400 uppercase tracking-wider">Cuti Tahunan</p>
                                    {isActive && (
                                      <span className="text-[9px] font-bold text-teal-600 dark:text-teal-400 bg-teal-50 dark:bg-teal-950/40 px-1.5 py-0.5 rounded">
                                        Aktif
                                      </span>
                                    )}
                                  </div>
                                  {!isActive ? (
                                    <div className="flex items-center gap-1.5 py-1">
                                      <span className="text-[10px] text-slate-400 italic">Kuota nonaktif</span>
                                    </div>
                                  ) : cuti ? (
                                    <>
                                      <p className="text-base font-bold text-slate-800 dark:text-slate-100 leading-none">
                                        {cuti.remaining}
                                        <span className="text-[10px] font-normal text-slate-400 ml-1">/ {cuti.quota} hari sisa</span>
                                      </p>
                                      <div className="w-full h-1.5 bg-slate-200 dark:bg-slate-700 rounded-full overflow-hidden">
                                        <div
                                          className={`h-full rounded-full transition-all ${progressColor(cuti.remaining, cuti.quota)}`}
                                          style={{ width: progressWidth(cuti.remaining, cuti.quota) }}
                                        />
                                      </div>
                                      <p className="text-[10px] text-slate-400">
                                        Terpakai <span className="font-semibold text-slate-600 dark:text-slate-300">{cuti.used} hari</span>
                                      </p>
                                    </>
                                  ) : (
                                    <p className="text-[10px] text-slate-400 italic">Belum ada data</p>
                                  )}
                                </div>

                                {/* Blok Izin / Sakit */}
                                <div className="space-y-1.5 bg-slate-50/50 dark:bg-slate-800/30 p-2.5 rounded-xl border border-slate-100 dark:border-slate-800/60">
                                  <div className="flex items-center justify-between">
                                    <p className="text-[9px] font-bold text-slate-400 uppercase tracking-wider">Izin &amp; Sakit</p>
                                    <span className="text-[9px] font-semibold text-indigo-500 bg-indigo-50 dark:bg-indigo-950/40 px-1.5 py-0.5 rounded">
                                      Periode Ini
                                    </span>
                                  </div>
                                  {izin ? (
                                    <>
                                      <p className="text-base font-bold text-slate-800 dark:text-slate-100 leading-none">
                                        {izin.used}
                                        <span className="text-[10px] font-normal text-slate-400 ml-1">hari terpakai</span>
                                      </p>
                                      <div className="w-full h-1.5 bg-slate-200 dark:bg-slate-700 rounded-full overflow-hidden">
                                        <div className="h-full w-0 rounded-full bg-slate-300" />
                                      </div>
                                      <p className="text-[10px] text-slate-400">Direset ke 0 saat anniversary</p>
                                    </>
                                  ) : (
                                    <p className="text-[10px] text-slate-400 italic">Belum ada data</p>
                                  )}
                                </div>
                              </div>
                            </div>
                          );
                        })}
                      </div>

                      {/* Pagination Bar */}
                      {entries.length >= 25 && (
                        <div className="flex flex-col sm:flex-row items-center justify-between gap-3 pt-3 border-t border-slate-100 dark:border-slate-800 text-xs">
                          <div className="flex items-center gap-2 text-slate-500 dark:text-slate-400">
                            <span>
                              Menampilkan <strong className="text-slate-800 dark:text-slate-200 font-bold font-mono">
                                {Math.min((balancePage - 1) * balancePageSize + 1, entries.length)} - {Math.min(balancePage * balancePageSize, entries.length)}
                              </strong> dari <strong className="text-slate-800 dark:text-slate-200 font-bold font-mono">{entries.length}</strong> karyawan
                            </span>
                            <span className="hidden sm:inline">•</span>
                            <div className="flex items-center gap-1.5">
                              <span className="hidden sm:inline">Per hal:</span>
                              <select
                                value={balancePageSize}
                                onChange={(e) => {
                                  setBalancePageSize(Number(e.target.value));
                                  setBalancePage(1);
                                }}
                                className="py-0.5 px-2 text-xs font-semibold border border-slate-200 dark:border-slate-700 rounded-lg bg-slate-50 dark:bg-slate-800/40 text-slate-700 dark:text-slate-300 focus:outline-none cursor-pointer"
                              >
                                <option value={12}>12</option>
                                <option value={24}>24</option>
                                <option value={48}>48</option>
                              </select>
                            </div>
                          </div>

                          <div className="flex items-center gap-1.5">
                            <button
                              type="button"
                              onClick={() => setBalancePage(1)}
                              disabled={balancePage === 1}
                              className="p-1 px-2 rounded-lg border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800 disabled:opacity-40 disabled:cursor-not-allowed transition font-medium cursor-pointer"
                              title="Halaman Pertama"
                            >
                              «
                            </button>
                            <button
                              type="button"
                              onClick={() => setBalancePage(p => Math.max(1, p - 1))}
                              disabled={balancePage === 1}
                              className="p-1 px-2 rounded-lg border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800 disabled:opacity-40 disabled:cursor-not-allowed transition font-medium cursor-pointer"
                              title="Halaman Sebelumnya"
                            >
                              ‹
                            </button>
                            <span className="px-2 font-semibold text-slate-700 dark:text-slate-300">
                              Hal <span className="font-mono font-bold text-indigo-600 dark:text-indigo-400">{balancePage}</span> / <span className="font-mono">{totalBalancePages}</span>
                            </span>
                            <button
                              type="button"
                              onClick={() => setBalancePage(p => Math.min(totalBalancePages, p + 1))}
                              disabled={balancePage === totalBalancePages}
                              className="p-1 px-2 rounded-lg border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800 disabled:opacity-40 disabled:cursor-not-allowed transition font-medium cursor-pointer"
                              title="Halaman Berikutnya"
                            >
                              ›
                            </button>
                            <button
                              type="button"
                              onClick={() => setBalancePage(totalBalancePages)}
                              disabled={balancePage === totalBalancePages}
                              className="p-1 px-2 rounded-lg border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800 disabled:opacity-40 disabled:cursor-not-allowed transition font-medium cursor-pointer"
                              title="Halaman Terakhir"
                            >
                              »
                            </button>
                          </div>
                        </div>
                      )}
                    </>
                  )}
                </div>
              );
            })()
          )}

          {/* ── SUB-TAB: Riwayat Saldo Sebelumnya (Arsip Periode Lalu) ── */}
          {balanceSubTab === 'history' && (
            <div className="space-y-4">
              {/* Top KPI Cards */}
              <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
                <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl p-4 space-y-1">
                  <div className="flex items-center justify-between">
                    <p className="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Total Catatan</p>
                    <Archive className="w-3.5 h-3.5 text-indigo-500" />
                  </div>
                  <p className="text-lg font-extrabold text-slate-800 dark:text-slate-100">
                    {balanceHistoryStats?.total_records ?? balanceHistories.length}
                    <span className="text-[10px] font-normal text-slate-400 ml-1">periode</span>
                  </p>
                </div>

                <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl p-4 space-y-1">
                  <div className="flex items-center justify-between">
                    <p className="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Cuti Terpakai Lalu</p>
                    <CalendarCheck className="w-3.5 h-3.5 text-teal-500" />
                  </div>
                  <p className="text-lg font-extrabold text-teal-600 dark:text-teal-400">
                    {balanceHistoryStats?.total_cuti_used ?? balanceHistories.reduce((s, h) => s + (h.cuti_used || 0), 0)}
                    <span className="text-[10px] font-normal text-slate-400 ml-1">hari</span>
                  </p>
                </div>

                <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl p-4 space-y-1">
                  <div className="flex items-center justify-between">
                    <p className="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Sisa Cuti Hangus</p>
                    <Clock className="w-3.5 h-3.5 text-amber-500" />
                  </div>
                  <p className="text-lg font-extrabold text-amber-600 dark:text-amber-400">
                    {balanceHistoryStats?.total_cuti_remaining ?? balanceHistories.reduce((s, h) => s + (h.cuti_remaining || 0), 0)}
                    <span className="text-[10px] font-normal text-slate-400 ml-1">hari</span>
                  </p>
                </div>

                <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl p-4 space-y-1">
                  <div className="flex items-center justify-between">
                    <p className="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Izin/Sakit Lalu</p>
                    <ClipboardList className="w-3.5 h-3.5 text-purple-500" />
                  </div>
                  <p className="text-lg font-extrabold text-purple-600 dark:text-purple-400">
                    {balanceHistoryStats?.total_izin_sakit_used ?? balanceHistories.reduce((s, h) => s + (h.izin_sakit_used || 0), 0)}
                    <span className="text-[10px] font-normal text-slate-400 ml-1">hari</span>
                  </p>
                </div>
              </div>

              {/* Filter bar */}
              <div className="flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3 bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl p-4">
                <div className="flex items-center gap-2 flex-wrap">
                  {/* Filter Tahun */}
                  <select
                    value={balanceHistoryYearFilter}
                    onChange={(e) => setBalanceHistoryYearFilter(e.target.value)}
                    className="py-1.5 px-3 text-xs font-semibold border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-800/40 text-slate-700 dark:text-slate-300 focus:outline-none focus:ring-1 focus:ring-indigo-400"
                  >
                    <option value="">Semua Tahun Reset</option>
                    {[new Date().getFullYear(), new Date().getFullYear() - 1, new Date().getFullYear() - 2].map(y => (
                      <option key={y} value={y}>{y}</option>
                    ))}
                  </select>

                  {/* Filter Kantor */}
                  <select
                    value={balanceHistoryOfficeFilter}
                    onChange={(e) => setBalanceHistoryOfficeFilter(e.target.value)}
                    className="py-1.5 px-3 text-xs font-semibold border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-800/40 text-slate-700 dark:text-slate-300 focus:outline-none focus:ring-1 focus:ring-indigo-400"
                  >
                    <option value="">Semua Kantor</option>
                    {offices.map(o => (
                      <option key={o.id} value={o.id}>{o.office_name}</option>
                    ))}
                    <option value="none">Tanpa Kantor</option>
                  </select>
                </div>

                <div className="flex items-center gap-2">
                  <div className="relative flex-1 sm:w-56">
                    <Search className="absolute left-2.5 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-slate-400 pointer-events-none" />
                    <input
                      type="text"
                      placeholder="Cari nama / NIK..."
                      value={balanceHistorySearch}
                      onChange={(e) => setBalanceHistorySearch(e.target.value)}
                      className="w-full pl-8 pr-3 py-1.5 text-xs border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-800/40 text-slate-800 dark:text-slate-100 placeholder-slate-400 focus:outline-none focus:ring-1 focus:ring-indigo-400"
                    />
                  </div>
                  <button
                    onClick={loadBalanceHistories}
                    disabled={balanceHistoryLoading}
                    className="p-2 rounded-xl border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800 transition disabled:opacity-50"
                    title="Segarkan Riwayat"
                  >
                    <RefreshCw className={`w-3.5 h-3.5 ${balanceHistoryLoading ? 'animate-spin' : ''}`} />
                  </button>
                </div>
              </div>

              {/* Table of Leave Balance Histories */}
              <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl overflow-hidden">
                <div className="overflow-x-auto">
                  <table className="w-full text-xs text-left">
                    <thead>
                      <tr className="border-b border-slate-100 dark:border-slate-800 text-slate-500 bg-slate-50/50 dark:bg-slate-800/30">
                        <th className="py-3 px-3.5 font-semibold">Karyawan</th>
                        <th className="py-3 px-3 font-semibold">Kantor Cabang</th>
                        <th className="py-3 px-3 font-semibold">Periode Siklus</th>
                        <th className="py-3 px-3 font-semibold text-center">Cuti Tahunan (Awal / Terpakai / Sisa)</th>
                        <th className="py-3 px-3 font-semibold text-center">Izin &amp; Sakit Terpakai</th>
                        <th className="py-3 px-3 font-semibold">Tanggal Reset</th>
                        <th className="py-3 px-3 font-semibold text-right">Status / Keterangan</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-50 dark:divide-slate-800/60">
                      {balanceHistoryLoading ? (
                        <tr>
                          <td colSpan={7} className="text-center py-12 text-slate-400">
                            <Loader2 className="w-5 h-5 animate-spin mx-auto mb-2 text-indigo-500" />
                            Memuat riwayat saldo cuti...
                          </td>
                        </tr>
                      ) : balanceHistories.length === 0 ? (
                        <tr>
                          <td colSpan={7} className="text-center py-12 text-slate-400 space-y-2">
                            <Archive className="w-8 h-8 mx-auto text-slate-300 dark:text-slate-600" />
                            <p className="font-semibold text-slate-600 dark:text-slate-300">Belum ada riwayat saldo periode sebelumnya.</p>
                            <p className="text-[11px] text-slate-400 max-w-md mx-auto">
                              Snapshot saldo cuti &amp; izin/sakit akan otomatis tersimpan di sini setiap kali jadwal reset tahunan kantor tiba (misal: 1 Desember) atau saat HRD melakukan reset manual.
                            </p>
                          </td>
                        </tr>
                      ) : (
                        balanceHistories.map((h: any) => (
                          <tr key={h.id} className="hover:bg-slate-50/50 dark:hover:bg-slate-800/30 transition-colors">
                            <td className="py-3 px-3.5">
                              <p className="font-semibold text-slate-800 dark:text-slate-100">{h.user_name}</p>
                              <div className="flex items-center gap-1.5 text-[10px] text-slate-400">
                                {h.employee_code && <span className="font-mono">{h.employee_code}</span>}
                                {h.employee_code && h.department && <span>•</span>}
                                <span>{h.department}</span>
                              </div>
                            </td>
                            <td className="py-3 px-3 text-slate-600 dark:text-slate-300">
                              <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-slate-100 dark:bg-slate-800 text-[11px] font-medium">
                                <Building2 className="w-3 h-3 text-slate-400" />
                                {h.office_name}
                              </span>
                            </td>
                            <td className="py-3 px-3">
                              <p className="font-semibold text-slate-700 dark:text-slate-200">{h.period_label}</p>
                              {h.period_start && h.period_end && (
                                <p className="text-[10px] text-slate-400">{fmtDate(h.period_start)} – {fmtDate(h.period_end)}</p>
                              )}
                            </td>
                            <td className="py-3 px-3 text-center">
                              <div className="inline-flex items-center gap-2 bg-slate-50 dark:bg-slate-800/40 px-2.5 py-1 rounded-lg border border-slate-100 dark:border-slate-800 font-mono text-[11px]">
                                <span className="text-slate-500" title="Kuota Awal">{h.cuti_quota}</span>
                                <span className="text-slate-300">/</span>
                                <span className="text-teal-600 dark:text-teal-400 font-bold" title="Cuti Terpakai">{h.cuti_used} terpakai</span>
                                <span className="text-slate-300">/</span>
                                <span className="text-amber-600 dark:text-amber-400 font-semibold" title="Sisa Cuti Hangus">{h.cuti_remaining} sisa</span>
                              </div>
                            </td>
                            <td className="py-3 px-3 text-center">
                              <span className="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-purple-50 dark:bg-purple-950/30 text-purple-700 dark:text-purple-300 text-[11px] font-bold">
                                {h.izin_sakit_used} hari
                              </span>
                            </td>
                            <td className="py-3 px-3 whitespace-nowrap">
                              <p className="font-semibold text-slate-700 dark:text-slate-300">{h.reset_date_formatted || fmtDate(h.reset_date)}</p>
                            </td>
                            <td className="py-3 px-3 text-right">
                              <span className="inline-flex items-center gap-1 text-[10px] font-bold px-2 py-0.5 rounded bg-emerald-50 text-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-400 border border-emerald-100 dark:border-emerald-900/40">
                                <CheckCircle2 className="w-3 h-3" />
                                Telah Di-reset
                              </span>
                              {h.notes && (
                                <p className="text-[10px] text-slate-400 mt-0.5 truncate max-w-[200px] ml-auto" title={h.notes}>
                                  {h.notes}
                                </p>
                              )}
                            </td>
                          </tr>
                        ))
                      )}
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
          )}
        </div>
      )}

      {/* ─── Modal: Konfirmasi Reset Manual Saldo Kantor ─── */}
      {resetOfficeModal && (
        <ConfirmationDialog
          isOpen={true}
          title="Konfirmasi Reset Saldo Cuti Kantor"
          message={`Apakah Anda yakin ingin me-reset saldo cuti dan pemakaian izin/sakit untuk seluruh karyawan di kantor "${resetOfficeModal.name}"? Pemakaian periode berjalan akan diarsipkan ke Riwayat Saldo Sebelumnya dan saldo periode baru akan diatur ulang.`}
          confirmLabel={isResettingOffice ? 'Memproses Reset...' : 'Ya, Reset & Arsipkan'}
          cancelLabel="Batal"
          isDanger={true}
          onConfirm={() => handleManualResetOffice(resetOfficeModal.id, resetOfficeModal.name)}
          onCancel={() => setResetOfficeModal(null)}
        />
      )}

      {/* ─── TAB: Laporan ─── */}
      {tab === 'report' && (
        <div className="space-y-4">
          <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl p-5 space-y-4">
            <div className="flex justify-between items-center">
              <div className="flex items-center gap-3">
                <h3 className="text-sm font-bold text-slate-800 dark:text-slate-100">Filter Data</h3>
                {(reportFilter.start_date || reportFilter.end_date) && (
                  <button
                    onClick={() => setReportFilterAndReset({ ...reportFilter, start_date: '', end_date: '' })}
                    className="text-[10px] flex items-center gap-1 font-semibold text-rose-500 hover:text-rose-600 transition-colors bg-rose-50 hover:bg-rose-100 dark:bg-rose-950/30 dark:hover:bg-rose-900/50 px-2 py-1 rounded-md"
                  >
                    <X className="w-3 h-3" />
                    Reset Tanggal
                  </button>
                )}
              </div>
              <div className="relative w-full sm:w-64 shrink-0">
                <Search className="absolute left-2.5 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-slate-400 pointer-events-none" />
                <input
                  type="text"
                  placeholder="Cari nama karyawan..."
                  value={reportSearch}
                  onChange={(e) => setReportSearch(e.target.value)}
                  className="w-full pl-8 pr-3 py-2 text-xs border border-slate-200 dark:border-slate-700 rounded-lg bg-slate-50 dark:bg-slate-800/20 text-slate-800 dark:text-slate-100 placeholder-slate-400 focus:outline-none focus:ring-1 focus:ring-indigo-400 transition-colors"
                />
              </div>
            </div>
            <div className="grid grid-cols-2 md:grid-cols-6 gap-4 items-end">
              <div className="space-y-1.5">
                <label className="text-[10px] font-bold text-slate-400 block uppercase tracking-wider">Dari Tanggal</label>
                <CustomDatePicker
                  value={reportFilter.start_date}
                  onChange={(val) => {
                    const next = { ...reportFilter, start_date: val };
                    if (reportFilter.end_date && val && reportFilter.end_date < val) {
                      next.end_date = '';
                    }
                    setReportFilterAndReset(next);
                  }}
                  placeholder="Pilih tanggal mulai"
                  size="sm"
                />
              </div>
              <div className="space-y-1.5">
                <label className="text-[10px] font-bold text-slate-400 block uppercase tracking-wider">Sampai Tanggal</label>
                <CustomDatePicker
                  value={reportFilter.end_date}
                  min={reportFilter.start_date || undefined}
                  onChange={(val) => setReportFilterAndReset({ ...reportFilter, end_date: val })}
                  placeholder="Pilih tanggal akhir"
                  size="sm"
                />
              </div>

              <div className="space-y-1.5">
                <label className="text-[10px] font-bold text-slate-400 block uppercase tracking-wider">Status</label>
                <select value={reportFilter.status} onChange={(e) => setReportFilterAndReset({ ...reportFilter, status: e.target.value })} className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 focus:outline-none focus:ring-1 focus:ring-indigo-400 transition-colors">
                  <option value="">Semua Status</option>
                  <option value="present">Hadir</option>
                  <option value="late">Telat</option>
                  <option value="early_leave">Pulang Awal</option>
                  <option value="absent">Alpha</option>
                  <option value="libur">Libur</option>
                  <option value="cuti">Cuti</option>
                  <option value="izin">Izin</option>
                  <option value="sakit">Sakit</option>
                </select>
              </div>
              <div className="space-y-1.5">
                <label className="text-[10px] font-bold text-slate-400 block uppercase tracking-wider">Lokasi</label>
                <select value={reportFilter.type} onChange={(e) => setReportFilterAndReset({ ...reportFilter, type: e.target.value })} className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 focus:outline-none focus:ring-1 focus:ring-indigo-400 transition-colors">
                  <option value="">Semua Lokasi</option>
                  <option value="onsite">On Site</option>
                  <option value="wfh">WFH</option>
                  <option value="field">Lapangan</option>
                </select>
              </div>
              <div className="space-y-1.5">
                <label className="text-[10px] font-bold text-slate-400 block uppercase tracking-wider">Kantor</label>
                <select value={reportFilter.office_id || ''} onChange={(e) => setReportFilterAndReset({ ...reportFilter, office_id: e.target.value })} className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 focus:outline-none focus:ring-1 focus:ring-indigo-400 transition-colors">
                  <option value="">Semua Kantor</option>
                  {offices.map(o => (
                    <option key={o.id} value={o.id}>{o.office_name}</option>
                  ))}
                </select>
              </div>
              <div className="pt-2 flex gap-2">
                <button onClick={handleExport} className="w-full flex items-center justify-center gap-1.5 px-3 py-2.5 bg-indigo-50 dark:bg-indigo-900/30 border border-indigo-100 dark:border-indigo-800 text-indigo-600 dark:text-indigo-400 hover:bg-indigo-100 dark:hover:bg-indigo-900/50 rounded-lg text-xs font-bold transition">
                  <Download className="w-3.5 h-3.5" /> Export CSV
                </button>
              </div>
            </div>
          </div>

          {loading ? (
            <TabSkeleton tab="report" />
          ) : report && (
            <>
              <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 xl:grid-cols-11 gap-3">
                <SummaryCard label="Hadir" value={report.summary?.present ?? 0} color="text-emerald-600" />
                <SummaryCard label="Telat" value={report.summary?.late ?? 0} color="text-amber-600" />
                <SummaryCard label="Pulang Awal" value={report.summary?.early_leave ?? 0} color="text-violet-600" />
                <SummaryCard label="Alpha" value={report.summary?.absent ?? 0} color="text-rose-600" />
                <SummaryCard label="Cuti" value={report.summary?.cuti ?? 0} color="text-teal-600" />
                <SummaryCard label="Izin" value={report.summary?.izin ?? 0} color="text-purple-600" />
                <SummaryCard label="Sakit" value={report.summary?.sakit ?? 0} color="text-orange-500" />
                <SummaryCard label="On site" value={report.by_type?.onsite ?? 0} color="text-slate-700 dark:text-white" />
                <SummaryCard label="WFH" value={report.by_type?.wfh ?? 0} color="text-indigo-600" />
                <SummaryCard label="Jam Kerja" value={fmtMinutes(report.summary?.total_working_minutes)} color="text-cyan-600" />
                <SummaryCard label="Lembur" value={fmtMinutes(report.summary?.total_overtime_minutes)} color="text-orange-600" />
              </div>

              <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl p-5 overflow-x-auto">
                <table className="w-full text-xs text-left">
                  <thead>
                    <tr className="border-b border-slate-100 dark:border-slate-800 text-slate-500">
                      <th className="py-2 px-2 font-semibold">NIK</th>
                      <th className="py-2 px-2 font-semibold">
                        <button
                          onClick={() => setReportNameSort(s => s === 'asc' ? 'desc' : 'asc')}
                          className="flex items-center gap-1 hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors group"
                          title="Urutkan berdasarkan nama"
                        >
                          Nama
                          <span className="text-[10px] font-bold">
                            {reportNameSort === 'asc' ? '↑' : reportNameSort === 'desc' ? '↓' : <span className="opacity-30 group-hover:opacity-70">↕</span>}
                          </span>
                        </button>
                      </th>
                      <th className="py-2 px-2 font-semibold">Departemen</th>
                      <th className="py-2 px-2 font-semibold">Tanggal</th>
                      <th className="py-2 px-2 font-semibold">Masuk</th>
                      <th className="py-2 px-2 font-semibold">Pulang</th>
                      <th className="py-2 px-2 font-semibold">Jam Kerja</th>
                      <th className="py-2 px-2 font-semibold">Telat</th>
                      <th className="py-2 px-2 font-semibold">Lembur</th>
                      <th className="py-2 px-2 font-semibold">Lokasi</th>
                      <th className="py-2 px-2 font-semibold">GPS (WFH)</th>
                      <th className="py-2 px-2 font-semibold text-center">Status</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-50 dark:divide-slate-800/60">
                    {(() => {
                      let filteredReport = rows(report.report);
                      if (reportNameSort) {
                        filteredReport = [...filteredReport].sort((a: any, b: any) => {
                          const cmp = (a.user_name ?? '').localeCompare(b.user_name ?? '', 'id');
                          return reportNameSort === 'asc' ? cmp : -cmp;
                        });
                      }

                      if (filteredReport.length === 0) {
                        return (
                          <tr>
                            <td colSpan={10} className="text-center py-12">
                              <div className="flex flex-col items-center justify-center text-slate-400 space-y-3">
                                <div className="w-12 h-12 rounded-full bg-slate-50 dark:bg-slate-800/50 flex items-center justify-center">
                                  <CalendarCheck className="w-6 h-6 opacity-40" />
                                </div>
                                <p className="text-xs font-medium">{reportSearch ? `Tidak ada karyawan bernama "${reportSearch}" di laporan ini.` : 'Tidak ada data presensi pada periode ini.'}</p>
                              </div>
                            </td>
                          </tr>
                        );
                      }

                      return filteredReport.map((r: any, idx: number) => {
                        const isVirtual = r.id === null; // baris virtual absent/leave
                        return (
                          <tr
                            key={r.id ?? `v-${r.user_id}-${r.date}-${idx}`}
                            className={`transition-colors ${isVirtual
                              ? 'bg-slate-50/60 dark:bg-slate-800/20 hover:bg-slate-100/60 dark:hover:bg-slate-800/40'
                              : 'hover:bg-slate-50/70 dark:hover:bg-slate-800/40'
                              }`}
                          >
                            <td className="py-3 px-2 text-slate-500 whitespace-nowrap font-mono text-[11px]">{r.employee_code ?? '—'}</td>
                            <td className="py-3 px-2 font-semibold text-slate-800 dark:text-slate-200 whitespace-nowrap">{r.user_name}</td>
                            <td className="py-3 px-2 text-slate-500 whitespace-nowrap">{r.department ?? '—'}</td>
                            <td className="py-3 px-2 text-slate-500 whitespace-nowrap">
                              <span className="inline-flex items-center gap-1">
                                {fmtDateRange(r.date, r.is_cross_day ? r.checkout_date : null)}
                                {r.is_cross_day && (
                                  <span title="Shift lintas tengah malam">
                                    <Moon className="w-3 h-3 text-indigo-400 shrink-0" />
                                  </span>
                                )}
                              </span>
                            </td>
                            <td className="py-3 px-2 font-mono whitespace-nowrap">{fmtTime(r.check_in_time)}</td>
                            <td className="py-3 px-2 font-mono whitespace-nowrap">{fmtTime(r.check_out_time)}</td>
                            <td className="py-3 px-2 font-mono text-violet-600 dark:text-violet-400 font-medium whitespace-nowrap">
                              {r.working_minutes != null ? fmtMinutes(r.working_minutes) : <span className="text-slate-300 dark:text-slate-600">—</span>}
                            </td>
                            <td className="py-3 px-2 font-mono whitespace-nowrap">
                              {r.late_minutes ? (
                                <span className="text-rose-600 dark:text-rose-400 font-medium">
                                  {fmtMinutes(r.late_minutes)}
                                </span>
                              ) : (
                                <span className="text-slate-300 dark:text-slate-600">—</span>
                              )}
                            </td>
                            <td className="py-3 px-2 font-mono whitespace-nowrap">
                              {r.overtime_minutes > 0 ? (
                                <span className="text-orange-600 dark:text-orange-400 font-medium">
                                  {fmtMinutes(r.overtime_minutes)}
                                  {r.is_holiday ? <span className="ml-1 text-[9px] font-bold text-rose-500">LIBUR</span> : null}
                                </span>
                              ) : (
                                <span className="text-slate-300 dark:text-slate-600">—</span>
                              )}
                            </td>
                            <td className="py-3 px-2 whitespace-nowrap">
                              {r.check_in_type ? (
                                <span className="flex items-center gap-1.5">
                                  {r.check_in_type === 'wfh' && <Home className="w-3.5 h-3.5 text-indigo-500" />}
                                  {r.check_in_type === 'field' && <MapPin className="w-3.5 h-3.5 text-amber-500" />}
                                  {r.check_in_type === 'onsite' && <Building2 className="w-3.5 h-3.5 text-slate-400" />}
                                  {r.check_in_type === 'wfh' ? 'WFH' : r.check_in_type === 'field' ? 'Lapangan' : 'Kantor'}
                                </span>
                              ) : (
                                <span className="text-slate-300 dark:text-slate-600">—</span>
                              )}
                            </td>
                            <td className="py-3 px-2 whitespace-nowrap">
                              {(r.check_in_type === 'wfh' || r.check_in_type === 'field') && r.check_in_lat && r.check_in_lng ? (
                                <a
                                  href={`https://www.google.com/maps?q=${r.check_in_lat},${r.check_in_lng}`}
                                  target="_blank"
                                  rel="noopener noreferrer"
                                  title={`Buka di Google Maps: ${Number(r.check_in_lat).toFixed(6)}, ${Number(r.check_in_lng).toFixed(6)}`}
                                  className="inline-flex items-center gap-1 text-indigo-600 dark:text-indigo-400 hover:text-indigo-800 dark:hover:text-indigo-300 hover:underline text-[11px] font-mono transition-colors"
                                >
                                  <MapPin className="w-3 h-3 shrink-0" />
                                  {Number(r.check_in_lat).toFixed(4)},
                                  {Number(r.check_in_lng).toFixed(4)}
                                </a>
                              ) : (
                                <span className="text-slate-300 dark:text-slate-600 text-[11px]">—</span>
                              )}
                            </td>
                            <td className="py-3 px-2 text-center whitespace-nowrap">
                              <span className={`inline-flex items-center justify-center text-[10px] font-bold px-2 py-1 rounded-md uppercase tracking-wider ${statusBadge(r.status)}`}>
                                {statusLabel(r.status)}
                              </span>
                            </td>
                          </tr>
                        );
                      });
                    })()}
                  </tbody>
                </table>
              </div>

              {/* Pagination */}
              {(() => {
                const meta = report?.report;
                if (!meta || meta.last_page <= 1) return null;
                return (
                  <div className="flex items-center justify-between gap-3 mt-2 px-1">
                    <p className="text-[11px] text-slate-400">
                      Menampilkan {((meta.current_page - 1) * meta.per_page) + 1}–{Math.min(meta.current_page * meta.per_page, meta.total)} dari <span className="font-semibold text-slate-600 dark:text-slate-300">{meta.total}</span> baris
                    </p>
                    <div className="flex items-center gap-1.5">
                      <button
                        disabled={reportPage <= 1}
                        onClick={() => setReportPage(p => p - 1)}
                        className="px-2.5 py-1.5 text-xs font-semibold border border-slate-200 dark:border-slate-700 rounded-lg disabled:opacity-40 hover:bg-slate-50 dark:hover:bg-slate-800 transition"
                      >
                        ‹ Prev
                      </button>
                      {Array.from({ length: Math.min(5, meta.last_page) }, (_, i) => {
                        const half = 2;
                        let start = Math.max(1, reportPage - half);
                        const end = Math.min(meta.last_page, start + 4);
                        start = Math.max(1, end - 4);
                        const pg = start + i;
                        if (pg > meta.last_page) return null;
                        return (
                          <button
                            key={pg}
                            onClick={() => setReportPage(pg)}
                            className={`w-7 h-7 text-xs font-semibold rounded-lg transition ${pg === reportPage
                              ? 'bg-indigo-600 text-white'
                              : 'border border-slate-200 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-800 text-slate-600 dark:text-slate-300'
                              }`}
                          >
                            {pg}
                          </button>
                        );
                      })}
                      <button
                        disabled={reportPage >= meta.last_page}
                        onClick={() => setReportPage(p => p + 1)}
                        className="px-2.5 py-1.5 text-xs font-semibold border border-slate-200 dark:border-slate-700 rounded-lg disabled:opacity-40 hover:bg-slate-50 dark:hover:bg-slate-800 transition"
                      >
                        Next ›
                      </button>
                    </div>
                  </div>
                );
              })()}
            </>
          )}
        </div>
      )}

      {/* ─── TAB: Libur Nasional ─── */}
      {tab === 'holidays' && (
        loading ? <TabSkeleton tab="holidays" /> : (
          <HolidaysTab
            holidays={holidays}
            offices={offices}
            users={users}
            reload={loadHolidays}
            onAddAuditLog={onAddAuditLog}
            onError={reportApiError}
            year={holidayYear}
            onYearChange={setHolidayYear}
          />
        )
      )}

      {/* ─── Modal: Surat Dokter ─── */}
      {docModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
          <div onClick={closeDocModal} className="fixed inset-0 bg-slate-900/70 backdrop-blur-sm" />
          <div className="relative z-10 bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-2xl max-h-[90vh] flex flex-col overflow-hidden">
            {/* Header */}
            <div className="flex items-center justify-between px-5 py-3 border-b border-slate-100 dark:border-slate-800">
              <div className="flex items-center gap-2">
                <FileText className="w-4 h-4 text-sky-600" />
                <div>
                  <p className="text-sm font-bold text-slate-800 dark:text-slate-100">Surat Dokter</p>
                  <p className="text-[11px] text-slate-400">{docModal.userName}</p>
                </div>
              </div>
              <div className="flex items-center gap-1.5">
                <a
                  href={docModal.url}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="flex items-center gap-1 px-2.5 py-1.5 text-[11px] font-semibold text-sky-600 hover:bg-sky-50 dark:hover:bg-sky-950/30 rounded-lg transition"
                  title="Buka di tab baru"
                >
                  <ExternalLink className="w-3.5 h-3.5" /> Tab Baru
                </a>
                <button
                  onClick={closeDocModal}
                  className="p-1.5 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-full"
                >
                  <X className="w-4 h-4" />
                </button>
              </div>
            </div>
            {/* Body */}
            <div className="flex-1 overflow-auto bg-slate-50 dark:bg-slate-950 flex items-center justify-center p-2">
              {docModal.isPdf ? (
                <iframe
                  src={docModal.url}
                  title="Surat Dokter"
                  className="w-full h-[70vh] rounded-lg bg-white"
                />
              ) : (
                <img
                  src={docModal.url}
                  alt="Surat Dokter"
                  className="max-w-full max-h-[70vh] object-contain rounded-lg"
                />
              )}
            </div>
          </div>
        </div>
      )}

      {/* ─── Modal: Panduan/Legend ─── */}
      {showLegend && (
        <div className="fixed inset-0 z-[60] flex items-center justify-center p-4">
          <div onClick={() => setShowLegend(false)} className="fixed inset-0 bg-slate-900/70 backdrop-blur-sm" />
          <div className="relative z-10 bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-lg flex flex-col overflow-hidden">
            <div className="flex items-center justify-between px-5 py-4 border-b border-slate-100 dark:border-slate-800">
              <div className="flex items-center gap-2">
                <Info className="w-5 h-5 text-indigo-500" />
                <h3 className="font-bold text-slate-800 dark:text-slate-100">Panduan Membaca Laporan</h3>
              </div>
              <button onClick={() => setShowLegend(false)} className="p-1.5 text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg transition-colors">
                <X className="w-4 h-4" />
              </button>
            </div>
            <div className="p-5 overflow-y-auto max-h-[70vh]">
              <div className="space-y-6">

                {/* Bagian Status */}
                <div>
                  <h4 className="text-xs font-bold text-slate-500 uppercase tracking-wider mb-3">Indikator Status</h4>
                  <ul className="space-y-3 text-sm text-slate-700 dark:text-slate-300">
                    <li className="flex gap-3 items-start">
                      <span className="inline-flex items-center justify-center text-[10px] font-bold px-2 py-1 rounded-md uppercase tracking-wider bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400 min-w-[70px]">HADIR</span>
                      <span className="flex-1">Karyawan masuk kerja (baik sesuai jam masuk maupun telat).</span>
                    </li>
                    <li className="flex gap-3 items-start">
                      <span className="inline-flex items-center justify-center text-[10px] font-bold px-2 py-1 rounded-md uppercase tracking-wider bg-rose-100 text-rose-700 dark:bg-rose-900/30 dark:text-rose-400 min-w-[70px]">ALPHA</span>
                      <span className="flex-1">Karyawan tidak masuk tanpa keterangan pada hari kerja.</span>
                    </li>
                    <li className="flex gap-3 items-start">
                      <span className="inline-flex items-center justify-center text-[10px] font-bold px-2 py-1 rounded-md uppercase tracking-wider bg-sky-100 text-sky-700 dark:bg-sky-900/30 dark:text-sky-400 min-w-[70px]">CUTI/IZIN</span>
                      <span className="flex-1">Karyawan sedang libur karena pengajuan cuti, izin, atau sakit yang disetujui.</span>
                    </li>
                    <li className="flex gap-3 items-start">
                      <span className="inline-flex items-center justify-center text-[10px] font-bold px-2 py-1 rounded-md uppercase tracking-wider bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300 min-w-[70px]">LIBUR</span>
                      <span className="flex-1">Hari tersebut adalah hari libur nasional atau weekend (akhir pekan) untuk kantor bersangkutan.</span>
                    </li>
                  </ul>
                </div>

                {/* Bagian Waktu */}
                <div>
                  <h4 className="text-xs font-bold text-slate-500 uppercase tracking-wider mb-3">Indikator Waktu & Shift</h4>
                  <ul className="space-y-3 text-sm text-slate-700 dark:text-slate-300">
                    <li className="flex gap-3 items-start">
                      <Moon className="w-4 h-4 text-indigo-400 shrink-0 mt-0.5" />
                      <span className="flex-1">
                        <strong>Shift Lintas Hari:</strong> Icon bulan menandakan karyawan masuk hari ini dan pulang keesokan harinya (Shift Malam).
                      </span>
                    </li>
                    <li className="flex gap-3 items-start">
                      <span className="text-rose-600 dark:text-rose-400 font-medium whitespace-nowrap min-w-[70px] mt-0.5">15m</span>
                      <span className="flex-1">
                        <strong>Kolom Telat:</strong> Menampilkan durasi telat warna merah (contoh: 15 menit) dari jam jadwal masuk aslinya.
                      </span>
                    </li>
                    <li className="flex gap-3 items-start">
                      <span className="text-[9px] font-bold text-rose-500 bg-rose-50/50 px-1 py-0.5 rounded mt-0.5">LIBUR</span>
                      <span className="flex-1">
                        Di dalam kolom <strong>Lembur</strong>, label merah menandakan bahwa lembur tersebut dilakukan pada saat hari libur / weekend (uang lembur biasanya berbeda).
                      </span>
                    </li>
                  </ul>
                </div>

                {/* Bagian Lokasi */}
                <div>
                  <h4 className="text-xs font-bold text-slate-500 uppercase tracking-wider mb-3">Lokasi (GPS)</h4>
                  <ul className="space-y-3 text-sm text-slate-700 dark:text-slate-300">
                    <li className="flex gap-3 items-start">
                      <Home className="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" />
                      <span className="flex-1">
                        <strong>WFH:</strong> Bekerja dari rumah (Work From Home). Koordinat GPS akan ditangkap otomatis.
                      </span>
                    </li>
                    <li className="flex gap-3 items-start">
                      <MapPin className="w-4 h-4 text-amber-500 shrink-0 mt-0.5" />
                      <span className="flex-1">
                        <strong>Lapangan:</strong> Bekerja di luar kantor (Dinas luar/lapangan).
                      </span>
                    </li>
                  </ul>
                </div>

              </div>
            </div>
            <div className="px-5 py-4 border-t border-slate-100 dark:border-slate-800 bg-slate-50 dark:bg-slate-900 flex justify-end">
              <button
                onClick={() => setShowLegend(false)}
                className="px-5 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-sm font-semibold transition"
              >
                Mengerti
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};

// ─── Sub-komponen: Kalender libur nasional / cuti bersama ─────
const HolidaysTab: React.FC<{
  holidays: any[];
  offices: any[];
  users: any[];
  reload: () => Promise<void>;
  onAddAuditLog: (t: string, d: string, b: string) => void;
  onError: (e: unknown, f: string) => void;
  year: number;
  onYearChange: (y: number) => void;
}> = ({ holidays, offices, users, reload, onAddAuditLog, onError, year, onYearChange }) => {
  const { user } = useAuth();
  const isSuperAdmin = user?.role === 'super_admin';

  const today = new Date();
  // Tanggal hari ini (YYYY-MM-DD) untuk menentukan sel kalender yang lewat/sedang berjalan
  const todayStr = toDateStr(today);
  const [viewMonth, setViewMonth] = useState(today.getMonth());
  const [showForm, setShowForm] = useState(false);
  const [editingId, setEditingId] = useState<number | null>(null);
  const [form, setForm] = useState<{ date: string; name: string; type: string; attendance_setting_id: string; excluded_users: any[] }>({
    date: '',
    name: '',
    type: 'perusahaan',
    attendance_setting_id: '',
    excluded_users: [],
  });
  const [excludeSearch, setExcludeSearch] = useState('');
  const [excludeDropdownOpen, setExcludeDropdownOpen] = useState(false);
  const [saving, setSaving] = useState(false);
  const [loadingPreview, setLoadingPreview] = useState(false);
  const [showCollectiveConfirm, setShowCollectiveConfirm] = useState(false);
  const [collectivePreviewData, setCollectivePreviewData] = useState<any | null>(null);
  const [showEligibleAccordion, setShowEligibleAccordion] = useState(false);
  const [holidayAutoExcluded, setHolidayAutoExcluded] = useState<any[]>([]);
  const [deleteConfirmHoliday, setDeleteConfirmHoliday] = useState<any | null>(null);
  const [deletingHoliday, setDeletingHoliday] = useState(false);
  // Daftar karyawan untuk dropdown pengecualian — selalu diambil mandiri via /users/all
  // (jangan pakai prop users: itu paginated 20 saja, tidak memuat semua karyawan)
  const [userOptions, setUserOptions] = useState<any[]>([]);

  // Muat semua karyawan aktif untuk dropdown pengecualian
  const ensureUserOptions = useCallback(() => {
    if (userOptions.length > 0) return;
    attendanceApi.allUsers()
      .then((res: any) => {
        const list = rows(res?.users ?? res);
        if (list.length > 0) setUserOptions(list);
      })
      .catch(() => {});
  }, [userOptions.length]);

  const handleTypeChange = (newType: string) => {
    setForm(f => ({ ...f, type: newType }));
  };

  const handleOfficeChange = (newOfficeId: string) => {
    setForm(f => ({ ...f, attendance_setting_id: newOfficeId }));
  };

  const [detailDate, setDetailDate] = useState<string | null>(null);
  // Modal rekap cuti bersama (HRD)
  const [collectiveDetailHoliday, setCollectiveDetailHoliday] = useState<any | null>(null);
  const [collectiveDetailData, setCollectiveDetailData] = useState<any | null>(null);
  const [loadingCollectiveDetail, setLoadingCollectiveDetail] = useState(false);
  // Modal peringatan hasil tambah libur:
  //  - autoExcluded    : karyawan yang otomatis dikecualikan dari cuti bersama (sudah punya cuti approved / cuti nonaktif)
  //  - balanceRestored : karyawan yang saldo cutinya dikembalikan karena libur nasional/cabang
  const [holidayWarning, setHolidayWarning] = useState<{
    title: string;
    autoExcluded: any[];
    balanceRestored: any[];
  } | null>(null);
  // Filter kantor untuk kalender — '' = semua kantor (tidak tampilkan libur mingguan)
  const [calOfficeFilter, setCalOfficeFilter] = useState<string>('');

  // Cegah bulan dari tahun lain saat navigasi tahun di header
  useEffect(() => {
    const cur = new Date();
    if (year === cur.getFullYear()) setViewMonth(cur.getMonth());
    else setViewMonth(0);
  }, [year]);

  // Muat daftar karyawan untuk dropdown pengecualian saat komponen pertama kali mount
  useEffect(() => {
    ensureUserOptions();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // Libur yang relevan dg filter kantor yang dipilih.
  // '' = Semua Kantor → tampilkan semua libur perusahaan + nasional.
  // kantor spesifik → tampilkan: nasional + company-wide (attendance_setting_id null)
  //   + libur/cuti bersama khusus cabang tsb saja. Libur cabang lain disembunyikan.
  const visibleHolidays = useMemo(() => {
    if (!calOfficeFilter) return holidays;
    return holidays.filter((h) => {
      // Libur nasional (company_id null) berlaku untuk semua kantor.
      if (!h.company_id || h.scope === 'nasional') return true;
      // Jika libur/cuti bersama punya cabang spesifik: hanya tampil jika ID cabang cocok persis dengan filter!
      if (h.attendance_setting_id !== null && h.attendance_setting_id !== undefined) {
        return String(h.attendance_setting_id) === String(calOfficeFilter);
      }
      // Libur company-wide (attendance_setting_id null) berlaku untuk semua cabang.
      return true;
    });
  }, [holidays, calOfficeFilter]);

  // Kumpulkan libur per tanggal (map: 'YYYY-MM-DD' → holiday[])
  const byDate = useMemo(() => {
    const map: Record<string, any[]> = {};
    visibleHolidays.forEach(h => {
      const d = String(h.date).slice(0, 10);
      (map[d] = map[d] || []).push(h);
    });
    return map;
  }, [visibleHolidays]);

  // Hitung hari libur mingguan dari kantor yang dipilih.
  // work_days adalah array integer 0=Minggu,1=Senin,...,6=Sabtu (JS getDay() convention).
  // weeklyOffDays = hari JS getDay() yang TIDAK ADA di work_days → hari libur mingguan.
  const weeklyOffDays = useMemo<Set<number>>(() => {
    if (!calOfficeFilter) return new Set(); // '' = semua kantor, tidak sorot libur mingguan
    const office = offices.find((o: any) => String(o.id) === calOfficeFilter);
    if (!office) return new Set();
    const workDays: number[] = Array.isArray(office.work_days)
      ? office.work_days.map(Number)
      : [1, 2, 3, 4, 5]; // default Senin-Jumat jika tidak ada
    const allDays = [0, 1, 2, 3, 4, 5, 6];
    return new Set(allDays.filter(d => !workDays.includes(d)));
  }, [calOfficeFilter, offices]);

  const firstDay = new Date(year, viewMonth, 1);
  const daysInMonth = new Date(year, viewMonth + 1, 0).getDate();
  // index Senin=0 … Minggu=6
  const offset = (firstDay.getDay() + 6) % 7;
  const cells: (number | null)[] = [
    ...Array.from({ length: offset }, () => null),
    ...Array.from({ length: daysInMonth }, (_, i) => i + 1),
  ];
  // Sisa sel kosong agar grid rapi (kelipatan 7)
  while (cells.length % 7 !== 0) cells.push(null);

  // Ringkasan per bulan: jumlah libur nasional & perusahaan di bulan yang sedang dilihat
  const summary = useMemo(() => {
    const prefix = `${year}-${pad2(viewMonth + 1)}-`;
    let nasional = 0, perusahaan = 0, cutiBersama = 0;
    visibleHolidays.forEach(h => {
      if (String(h.date).slice(0, 10).startsWith(prefix)) {
        if (h.scope === 'nasional') nasional++;
        else if (h.is_collective) cutiBersama++;
        else perusahaan++;
      }
    });
    return { nasional, perusahaan, cutiBersama };
  }, [visibleHolidays, year, viewMonth]);

  const resetForm = () => {
    setForm({ date: '', name: '', type: 'perusahaan', attendance_setting_id: calOfficeFilter || '', excluded_users: [] });
    setHolidayAutoExcluded([]);
    setEditingId(null);
    setShowForm(false);
    setShowCollectiveConfirm(false);
    setCollectivePreviewData(null);
    setShowEligibleAccordion(false);
  };

  const startCreate = (date?: string) => {
    // Jika ada filter cabang yang aktif, gunakan cabang tersebut sebagai default form
    // Jika bukan Super Admin, default ke cabang filter / cabang user / cabang pertama
    const defaultOffice = calOfficeFilter || (!isSuperAdmin ? (user?.attendance_setting_id ? String(user.attendance_setting_id) : (offices.length > 0 ? String(offices[0].id) : '')) : '');
    setHolidayAutoExcluded([]);
    if (editingId !== null) {
      setEditingId(null);
      setForm({ date: date ?? '', name: '', type: 'perusahaan', attendance_setting_id: defaultOffice, excluded_users: [] });
      setShowForm(true);
      return;
    }
    setForm({ date: date ?? '', name: '', type: 'perusahaan', attendance_setting_id: defaultOffice, excluded_users: [] });
    setShowForm((v) => !v);
    ensureUserOptions();
  };

  const startEdit = (h: any) => {
    if ((h.scope === 'nasional' || h.is_national) && !isSuperAdmin) {
      onError(null, 'Hanya Super Admin yang berwenang mengubah hari libur nasional.');
      return;
    }
    if (h.attendance_setting_id === null && !h.is_national && h.scope !== 'nasional' && !isSuperAdmin) {
      onError(null, 'Hanya Super Admin yang berwenang mengubah libur / cuti bersama untuk semua cabang.');
      return;
    }
    const officeId = h.attendance_setting_id ? String(h.attendance_setting_id) : '';
    setEditingId(h.id);

    const allExcluded = h.excluded_users ?? [];
    // Pisahkan: manual excluded (bisa dikembalikan HRD) vs auto excluded (permanen tidak ikut)
    const manualEx = allExcluded.filter((u: any) => u.is_manual !== false);
    const autoEx = allExcluded.filter((u: any) => u.is_manual === false);
    setHolidayAutoExcluded(autoEx);

    setForm({
      date: String(h.date).slice(0, 10),
      name: h.name,
      excluded_users: manualEx,
      // Tipe diturunkan dari data holiday:
      //  - scope 'nasional' / is_national=true → libur nasional
      //  - is_collective=true                  → cuti bersama
      //  - selain itu (scope 'perusahaan'/'cabang') → libur perusahaan
      type: h.scope === 'nasional' || h.is_national
        ? 'nasional'
        : h.is_collective
          ? 'collective'
          : 'perusahaan',
      attendance_setting_id: officeId,
    });
    setShowForm(true);
    ensureUserOptions();
  };

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!form.date || !form.name.trim()) return;
    // Larang membuat libur/cuti bersama baru untuk tanggal yang sudah lewat / hari ini.
    if (editingId === null && form.date <= todayStr) {
      onError(null, 'Tanggal sudah lewat / hari ini tidak bisa untuk menambah libur.');
      return;
    }

    // Guard hak akses: Hanya Super Admin yang boleh membuat libur/cuti bersama untuk Semua Cabang
    if (form.type !== 'nasional' && !form.attendance_setting_id && !isSuperAdmin) {
      onError(null, 'Hanya Super Admin yang berwenang mengatur libur / cuti bersama untuk semua cabang. Silakan pilih kantor cabang spesifik.');
      return;
    }

    // Jika Cuti Bersama: Panggil kalkulasi preview untuk memetakan karyawan yang tidak ikut beserta alasannya
    if (form.type === 'collective') {
      setLoadingPreview(true);
      try {
        const previewRes: any = await attendanceApi.holidays.previewCollective({
          holiday_id: editingId ?? undefined,
          date: form.date,
          name: form.name.trim(),
          attendance_setting_id: form.attendance_setting_id ? Number(form.attendance_setting_id) : null,
          excluded_user_ids: form.excluded_users.map(u => u.id),
        });
        setCollectivePreviewData(previewRes);
        setShowEligibleAccordion(false);
        setShowCollectiveConfirm(true);
      } catch (err) {
        onError(err, 'Gagal memuat pratinjau cuti bersama.');
      } finally {
        setLoadingPreview(false);
      }
      return;
    }

    await executeSave();
  };

  const executeSave = async () => {
    setSaving(true);
    try {
      const isCollective = form.type === 'collective';
      // Libur nasional tidak terikat kantor/cabang; kosongkan attendance_setting_id.
      const officeId = form.type === 'nasional'
        ? null
        : (form.attendance_setting_id ? Number(form.attendance_setting_id) : null);
      const payload = {
        date: form.date,
        name: form.name.trim(),
        type: form.type,
        attendance_setting_id: officeId,
        excluded_user_ids: form.excluded_users.map(u => u.id),
      };

      if (editingId !== null) {
        await attendanceApi.holidays.update(editingId, payload);
        onAddAuditLog('Hari libur diubah', `${form.name} (${form.date})`, 'bg-sky-500');
      } else {
        const res: any = await attendanceApi.holidays.create({ ...payload, is_collective: isCollective });
        onAddAuditLog(
          isCollective ? 'Cuti bersama ditambahkan' : 'Hari libur ditambahkan',
          `${form.name} (${form.date})`,
          isCollective ? 'bg-amber-500' : 'bg-sky-500',
        );
        // Tampilkan peringatan jika ada karyawan yang dikecualikan otomatis / saldo dikembalikan
        const autoExcluded = res?.warnings?.auto_excluded ?? [];
        const balanceRestored = res?.warnings?.balance_restored ?? [];
        if (autoExcluded.length > 0 || balanceRestored.length > 0) {
          setHolidayWarning({
            title: `${form.name} (${fmtDate(form.date)})`,
            autoExcluded,
            balanceRestored,
          });
        }
      }

      resetForm();
      setDetailDate(null);
      setShowCollectiveConfirm(false);
      await reload();
    } catch (err) {
      onError(err, editingId !== null ? 'Gagal mengubah hari libur.' : 'Gagal menambah hari libur.');
    } finally {
      setSaving(false);
    }
  };

  const remove = (h: any) => {
    if ((h.scope === 'nasional' || h.is_national) && !isSuperAdmin) {
      onError(null, 'Hanya Super Admin yang berwenang menghapus hari libur nasional.');
      return;
    }
    if (h.attendance_setting_id === null && !h.is_national && h.scope !== 'nasional' && !isSuperAdmin) {
      onError(null, 'Hanya Super Admin yang berwenang menghapus libur / cuti bersama untuk semua cabang.');
      return;
    }
    setDeleteConfirmHoliday(h);
  };

  const executeDelete = async () => {
    if (!deleteConfirmHoliday) return;
    const h = deleteConfirmHoliday;
    setDeletingHoliday(true);
    try {
      await attendanceApi.holidays.destroy(h.id);
      onAddAuditLog('Hari libur dihapus', `${h.name} (${h.date})`, 'bg-rose-500');
      if (detailDate) setDetailDate(null);
      setDeleteConfirmHoliday(null);
      await reload();
    } catch (err) {
      onError(err, 'Gagal menghapus hari libur.');
    } finally {
      setDeletingHoliday(false);
    }
  };

  const openCollectiveDetail = async (h: any) => {
    setCollectiveDetailHoliday(h);
    setLoadingCollectiveDetail(true);
    try {
      const res: any = await attendanceApi.collectiveLeaveDetail(h.id);
      setCollectiveDetailData(res);
    } catch (err) {
      onError(err, 'Gagal memuat rekap cuti bersama.');
      setCollectiveDetailHoliday(null);
    } finally {
      setLoadingCollectiveDetail(false);
    }
  };

  const changeMonth = (delta: number) => {
    const next = viewMonth + delta;
    if (next < 0) {
      onYearChange(year - 1);
      setViewMonth(11);
    } else if (next > 11) {
      onYearChange(year + 1);
      setViewMonth(0);
    } else {
      setViewMonth(next);
    }
    setDetailDate(null);
  };

  // Libur pada tanggal yang dipilih (dari sisi kiri)
  const selectedHolidays = detailDate ? (byDate[detailDate] ?? []) : [];

  // Tombol "Tambah" status libur hanya diperbolehkan untuk tanggal di masa depan.
  // Tanggal sudah lewat / hari ini → tombol disembunyikan (disabled).
  const isDetailLocked = detailDate ? detailDate <= todayStr : false;

  return (
    <div className="space-y-4">
      {/* Header */}
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h3 className="text-sm font-bold text-slate-800 dark:text-slate-100">Kalender {year}</h3>
          <p className="text-[11px] text-slate-400 mt-0.5">
            Tanggal libur tidak dihitung sebagai hari kerja (cuti) dan kerja di hari ini dihitung lembur penuh.
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          {/* Filter kantor — hanya tampil jika ada lebih dari 1 kantor */}
          {offices.length > 1 && (
            <div className="flex items-center gap-1.5">
              <Building2 className="w-3.5 h-3.5 text-slate-400 shrink-0" />
              <select
                value={calOfficeFilter}
                onChange={(e) => setCalOfficeFilter(e.target.value)}
                className="py-1.5 px-3 text-[11px] font-semibold border border-slate-200 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 focus:outline-none focus:ring-1 focus:ring-indigo-400"
                title="Filter libur mingguan per kantor"
              >
                <option value="">Semua Kantor</option>
                {offices.map((o: any) => (
                  <option key={o.id} value={String(o.id)}>{o.office_name}</option>
                ))}
              </select>
            </div>
          )}
        </div>
      </div>

      {showForm && (
        <form onSubmit={submit} className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl p-5 grid grid-cols-1 sm:grid-cols-4 gap-4 items-end">
          <div className="space-y-1.5 sm:col-span-4 -mb-2">
            <p className="text-xs font-bold text-slate-700 dark:text-slate-200">
              {editingId !== null ? 'Ubah Hari Libur' : 'Tambah Hari Libur'}
            </p>
          </div>
          <div className="space-y-1.5 sm:col-span-1">
            <label className="text-[10px] font-bold text-slate-400 block uppercase tracking-wider">Tanggal</label>
            {/* Tanggal tidak bisa diedit — berasal dari pilihan kalender (atau tanggal libur yang sedang diubah). */}
            <div className="flex items-center gap-2 text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-lg bg-slate-50 dark:bg-slate-800/20 text-slate-800 dark:text-slate-100">
              <CalendarDays className="w-3.5 h-3.5 text-indigo-500 shrink-0" />
              <span className="font-semibold">{form.date ? fmtDate(form.date) : '—'}</span>
            </div>
            <input type="hidden" value={form.date} />
          </div>
          <div className="space-y-1.5 sm:col-span-1">
            <label className="text-[10px] font-bold text-slate-400 block uppercase tracking-wider">Nama Libur</label>
            <input
              type="text"
              value={form.name}
              placeholder={form.type === 'nasional' ? 'mis. Hari Kenaikan Yesus Kristus' : form.type === 'collective' ? 'mis. Cuti Bersama Idul Fitri' : 'mis. Hari Jadi Perusahaan'}
              onChange={(e) => setForm({ ...form, name: e.target.value })}
              className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-lg bg-slate-50 dark:bg-slate-800/20 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-1 focus:ring-indigo-400"
              required
            />
          </div>
          <div className="space-y-1.5 sm:col-span-1">
            <label className="text-[10px] font-bold text-slate-400 block uppercase tracking-wider">Tipe Libur</label>
            <select
              value={form.type}
              onChange={(e) => handleTypeChange(e.target.value)}
              className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-1 focus:ring-indigo-400 cursor-pointer"
            >
              {isSuperAdmin && <option value="nasional">Libur Nasional</option>}
              <option value="collective">Cuti Bersama</option>
              <option value="perusahaan">Libur Perusahaan</option>
            </select>
          </div>

          {/* Kantor Cabang — langsung setelah Tipe Libur agar sebaris (kolom ke-4) */}
          {form.type !== 'nasional' && (
            <div className="space-y-1.5 sm:col-span-1">
              <label className="text-[10px] font-bold text-slate-400 block uppercase tracking-wider flex items-center justify-between">
                <span>Kantor Cabang</span>
                {!isSuperAdmin && (
                  <span className="text-[9px] font-normal text-amber-600 dark:text-amber-400 normal-case">
                    (Khusus Cabang)
                  </span>
                )}
              </label>
              <select
                value={form.attendance_setting_id}
                onChange={(e) => handleOfficeChange(e.target.value)}
                className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-1 focus:ring-indigo-400 cursor-pointer"
                required={!isSuperAdmin}
              >
                {isSuperAdmin ? (
                  <option value="">Semua Kantor (Semua Cabang)</option>
                ) : (
                  <option value="" disabled>Pilih Kantor Cabang...</option>
                )}
                {offices.map((o: any) => (
                  <option key={o.id} value={String(o.id)}>{o.office_name}</option>
                ))}
              </select>
            </div>
          )}

          {form.type === 'nasional' ? (
            <div className="sm:col-span-4 bg-rose-50 dark:bg-rose-950/20 border border-rose-200 dark:border-rose-900/30 rounded-lg p-3">
              <p className="text-[11px] text-rose-700 dark:text-rose-400">
                <span className="font-bold">Libur nasional</span> berlaku untuk semua perusahaan di aplikasi dan tampil <span className="font-bold">merah</span> di kalender.
              </p>
            </div>
          ) : form.type === 'collective' ? (
            <div className="sm:col-span-4 bg-amber-50 dark:bg-amber-950/20 border border-amber-200 dark:border-amber-900/30 rounded-lg p-3">
              <p className="text-xs font-bold text-amber-900 dark:text-amber-300">Cuti Bersama (Potong Saldo)</p>
              <p className="text-[11px] text-amber-700 dark:text-amber-400">
                Karyawan akan menerima notifikasi di aplikasi mobile H-7 dan dapat memilih untuk ikut atau tidak. <span className="font-bold">Saldo cuti karyawan yang mengikuti cuti ini akan terpotong otomatis.</span> Karyawan dengan cuti nonaktif otomatis masuk daftar pengecualian.
              </p>
            </div>
          ) : (
            <p className="text-[11px] text-slate-400 sm:col-span-4">
              Libur perusahaan hanya berlaku untuk perusahaan Anda dan tampil <span className="font-bold text-indigo-600 dark:text-indigo-400">biru</span> di kalender. Pilih kantor cabang untuk membatasi libur ke cabang tertentu.
            </p>
          )}
          {/* Input Pengecualian Karyawan (Manual HRD) — disesuaikan 1 kolom seperti kolom Tanggal */}
          <div className="sm:col-span-1 mt-1 space-y-1.5">
            <div className="flex items-center justify-between">
              <label className="text-[10px] font-bold text-slate-500 dark:text-slate-400 block uppercase tracking-wider truncate">
                {form.type === 'collective' ? 'Pengecualian Manual' : 'Pengecualian Karyawan'}
                <span className="lowercase normal-case font-normal text-slate-400 dark:text-slate-500 text-[9px] ml-1">
                  {form.type === 'collective' ? '(Manual HRD)' : '(Tidak ikut)'}
                </span>
              </label>
            </div>
            <div className="relative">
              <div
                className="w-full text-xs p-2 border border-slate-200 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-800 focus-within:ring-1 focus-within:ring-indigo-400 cursor-text min-h-[38px]"
                onClick={() => { ensureUserOptions(); setExcludeDropdownOpen(true); }}
              >
                <div className="flex flex-wrap gap-1 mb-0.5">
                  {form.excluded_users.length === 0 && !excludeDropdownOpen && (
                    <span className="text-slate-400 py-0.5 text-[11px]">Pilih karyawan...</span>
                  )}
                  {form.excluded_users.map(u => (
                    <span
                      key={u.id}
                      className="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-medium border bg-slate-50 dark:bg-slate-800 text-slate-800 dark:text-slate-200 border-slate-200 dark:border-slate-700"
                    >
                      <span>{u.name}</span>
                      <button
                        type="button"
                        title={form.type === 'collective' ? 'Kembalikan agar ikut cuti bersama' : 'Hapus dari pengecualian'}
                        onClick={(e) => {
                          e.stopPropagation();
                          setForm(f => ({ ...f, excluded_users: f.excluded_users.filter(x => x.id !== u.id) }));
                        }}
                        className="text-slate-400 hover:text-rose-500 hover:bg-rose-50 dark:hover:bg-rose-950/40 p-0.5 rounded transition"
                      >
                        <X className="w-3 h-3" />
                      </button>
                    </span>
                  ))}
                </div>
                {excludeDropdownOpen && (
                  <input
                    type="text"
                    autoFocus
                    placeholder="Cari karyawan..."
                    value={excludeSearch}
                    onChange={e => setExcludeSearch(e.target.value)}
                    onBlur={() => setTimeout(() => setExcludeDropdownOpen(false), 200)}
                    className="w-full bg-transparent outline-none text-slate-800 dark:text-slate-100 placeholder-slate-400 mt-0.5 text-xs"
                  />
                )}
              </div>

              {/* Dropdown List */}
              {excludeDropdownOpen && (
                <div className="absolute z-10 top-full left-0 w-80 mt-1 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg shadow-lg max-h-48 overflow-y-auto">
                  {(() => {
                    const filteredUsers = (userOptions || []).filter(u => {
                      // Filter by office if branch is selected
                      if (form.type !== 'nasional' && form.attendance_setting_id) {
                        if (String(u.attendance_setting_id) !== form.attendance_setting_id) return false;
                      }
                      // Filter by search
                      if (excludeSearch) {
                        const q = excludeSearch.toLowerCase();
                        const userName = String(u.name || '').toLowerCase();
                        const userCode = String(u.employee_code || '').toLowerCase();
                        if (!userName.includes(q) && !userCode.includes(q)) return false;
                      }
                      // Filter out already selected in manual
                      if (form.excluded_users.some(x => x.id === u.id)) return false;
                      // Filter out auto-excluded in edit mode
                      if (holidayAutoExcluded.some((x: any) => x.id === u.id)) return false;
                      return true;
                    });

                    if (filteredUsers.length === 0) {
                      return <div className="p-3 text-xs text-slate-500 text-center">Tidak ada karyawan yang cocok.</div>;
                    }

                    return filteredUsers.map(u => (
                      <div
                        key={u.id}
                        onMouseDown={(e) => {
                          e.preventDefault(); // Mencegah onBlur pada input
                          setForm(f => ({ ...f, excluded_users: [...f.excluded_users, u] }));
                          setExcludeSearch('');
                        }}
                        className="w-full flex items-center justify-between px-3 py-2 hover:bg-indigo-50/60 dark:hover:bg-indigo-950/40 border-b border-slate-50 dark:border-slate-800/60 last:border-0 cursor-pointer transition"
                      >
                        <div>
                          <p className="text-xs font-semibold text-slate-800 dark:text-slate-200">{u.name}</p>
                          <p className="text-[10px] text-slate-400">
                            {u.employee_code && <span className="font-mono">{u.employee_code} · </span>}
                            {u.office?.office_name ?? 'Kantor Pusat'}
                          </p>
                        </div>
                        <span
                          className="flex items-center gap-1 px-2 py-1 bg-indigo-50 text-indigo-600 dark:bg-indigo-950/60 dark:text-indigo-400 rounded text-[10px] font-bold shrink-0 pointer-events-none"
                        >
                          <Plus className="w-3 h-3" /> Tambah
                        </span>
                      </div>
                    ));
                  })()}
                </div>
              )}
            </div>
          </div>

          {/* Pengecualian Otomatis Sistem (Hanya Nama / Chips Sederhana) */}
          {form.type === 'collective' && holidayAutoExcluded.length > 0 && (
            <div className="sm:col-span-1 mt-1 space-y-1.5">
              <label className="text-[10px] font-bold text-slate-500 dark:text-slate-400 block uppercase tracking-wider truncate">
                Pengecualian Otomatis
                <span className="lowercase normal-case font-normal text-slate-400 dark:text-slate-500 text-[9px] ml-1">
                  ({holidayAutoExcluded.length} Auto)
                </span>
              </label>
              <div className="flex flex-wrap gap-1 p-2 border border-slate-200 dark:border-slate-700 rounded-lg bg-slate-50/60 dark:bg-slate-800/20 min-h-[38px] items-center">
                {holidayAutoExcluded.map((u: any) => (
                  <span
                    key={u.id}
                    className="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-medium bg-slate-100 dark:bg-slate-700 text-slate-700 dark:text-slate-300 border border-slate-200 dark:border-slate-600"
                    title={u.reason_detail || u.reason_label}
                  >
                    <span>{u.name}</span>
                    <span className="text-[9px] text-amber-600 dark:text-amber-400 font-semibold">
                      ({u.reason_label || 'Auto'})
                    </span>
                  </span>
                ))}
              </div>
            </div>
          )}

          <div className="flex items-center gap-2 sm:col-span-4 justify-end mt-2">
            <button
              type="submit"
              disabled={saving || loadingPreview}
              className="px-3 py-2.5 bg-emerald-600 hover:bg-emerald-700 disabled:opacity-50 text-white rounded-lg text-xs font-bold transition flex items-center gap-1.5"
            >
              {saving || loadingPreview ? (
                <>
                  <Loader2 className="w-3.5 h-3.5 animate-spin" />
                  {loadingPreview ? 'Menganalisis...' : 'Menyimpan...'}
                </>
              ) : (
                editingId !== null ? 'Simpan Perubahan' : 'Simpan'
              )}
            </button>
            <button
              type="button"
              disabled={saving || loadingPreview}
              onClick={resetForm}
              className="px-3 py-2.5 bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-600 dark:text-slate-300 rounded-lg text-xs font-bold transition disabled:opacity-50"
            >
              Batal
            </button>
          </div>
        </form>
      )}

      {/* Grid: kalender + detail */}
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-4 items-start">
        {/* Kalender */}
        <div className="lg:col-span-2 bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl p-5">
          {/* Navigasi bulan */}
          <div className="flex items-center justify-between mb-4">
            <button
              onClick={() => changeMonth(-1)}
              className="flex items-center justify-center p-2 text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg transition"
              title="Bulan sebelumnya"
            >
              <ChevronLeft className="w-4 h-4" />
            </button>
            <div className="text-center">
              <p className="text-sm font-bold text-slate-800 dark:text-slate-100">{MONTHS[viewMonth]} {year}</p>
              <p className="text-[10px] text-slate-400">
                {summary.nasional} nasional · {summary.cutiBersama} cuti bersama · {summary.perusahaan} perusahaan
              </p>
            </div>
            <button
              onClick={() => changeMonth(1)}
              className="flex items-center justify-center p-2 text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg transition"
              title="Bulan berikutnya"
            >
              <ChevronRight className="w-4 h-4" />
            </button>
          </div>

          {/* Hari */}
          <div className="grid grid-cols-7 gap-1 mb-2">
            {WEEKDAYS.map(d => (
              <div key={d} className="text-center text-[10px] font-bold text-slate-400 uppercase py-1">{d}</div>
            ))}
          </div>

          {/* Sel tanggal */}
          <div className="grid grid-cols-7 gap-1">
            {cells.map((day, idx) => {
              if (day === null) return <div key={`e-${idx}`} className="h-16 sm:h-20" />;
              const dateStr = `${year}-${pad2(viewMonth + 1)}-${pad2(day)}`;
              // Tanggal yang sudah lewat / hari ini TETAP menampilkan status liburnya
              // (tanggal merah, libur perusahaan, cuti bersama, libur mingguan).
              // Hanya tindakan "tambah status" yang disembunyikan untuk tanggal tsb.
              const isPastOrToday = dateStr <= todayStr;
              const dayHolidays = byDate[dateStr] ?? [];
              const hasNational = dayHolidays.some(h => h.scope === 'nasional');
              const hasCollective = dayHolidays.some(h => h.is_collective);
              const hasCompany = dayHolidays.some(h => (h.scope === 'perusahaan' || h.scope === 'cabang') && !h.is_collective);
              const isToday = dateStr === toDateStr(today);
              const isSelected = detailDate === dateStr;
              // Cek apakah hari ini adalah libur mingguan kantor yang dipilih
              // JS getDay(): 0=Minggu, 1=Senin, ..., 6=Sabtu — sama dengan konvensi work_days backend
              const jsDay = new Date(dateStr + 'T00:00:00').getDay();
              const isWeeklyOff = weeklyOffDays.has(jsDay);
              // Prioritas warna: nasional (merah tua) > libur mingguan kantor (merah muda) > cuti bersama (amber) > perusahaan (biru) > biasa
              // Tanggal sudah lewat / hari ini tetap menampilkan status libur; hanya tombol tambah yang disembunyikan darinya.
              const bgClass = hasNational
                ? 'bg-rose-50 dark:bg-rose-950/30 border-rose-200 dark:border-rose-900/40'
                : isWeeklyOff
                  ? 'bg-red-50 dark:bg-red-950/20 border-red-200 dark:border-red-900/30'
                  : hasCollective
                    ? 'bg-amber-50 dark:bg-amber-950/30 border-amber-200 dark:border-amber-900/40'
                    : hasCompany
                      ? 'bg-indigo-50 dark:bg-indigo-950/30 border-indigo-200 dark:border-indigo-900/40'
                      : 'bg-slate-50/60 dark:bg-slate-800/40 border-slate-100 dark:border-slate-800 hover:bg-slate-100 dark:hover:bg-slate-800';
              // Tooltip: libur mingguan kantor jika tidak ada event lain
              const selectedOfficeName = offices.find((o: any) => String(o.id) === calOfficeFilter)?.office_name ?? '';
              const tooltip = isPastOrToday
                ? ''
                : (dayHolidays.length
                  ? dayHolidays.map(h => h.name).join(', ')
                  : isWeeklyOff
                    ? `Hari libur mingguan ${selectedOfficeName}`
                    : 'Klik untuk menambah libur');
              return (
                <button
                  key={dateStr}
                  onClick={() => {
                    setDetailDate(detailDate === dateStr ? null : dateStr);
                    setShowForm(false);
                  }}
                  className={`relative flex flex-col items-center justify-start h-16 sm:h-20 rounded-lg border text-xs transition-colors cursor-pointer
                    ${bgClass}
                    ${isSelected ? 'ring-2 ring-indigo-500 border-indigo-400' : ''}`}
                  title={tooltip}
                >
                  <span className={`text-[11px] font-bold mt-1 ${
                    isToday
                      ? 'text-indigo-600 dark:text-indigo-400'
                      : isWeeklyOff && !hasNational
                        ? 'text-red-500 dark:text-red-400'
                        : 'text-slate-500 dark:text-slate-400'
                  }`}>
                    {day}
                  </span>
                  {/* Badge libur mingguan — hanya jika tidak ada event spesifik */}
                  {isWeeklyOff && !hasNational && !hasCompany && dayHolidays.length === 0 && (
                    <span className="text-[8px] font-semibold text-red-400 dark:text-red-500 mt-0.5 leading-tight">
                      Libur
                    </span>
                  )}
                  {dayHolidays.length > 0 && (
                    <span className="flex flex-col items-center gap-0.5 w-full px-1 mt-0.5">
                      {dayHolidays.slice(0, 2).map(h => (
                        <span key={h.id} className="w-full truncate text-center text-[8px] leading-tight font-semibold text-slate-700 dark:text-slate-300">
                          {h.name}
                        </span>
                      ))}
                      {dayHolidays.length > 2 && (
                        <span className="w-full text-center text-[8px] font-bold text-slate-400">
                          +{dayHolidays.length - 2} lagi
                        </span>
                      )}
                    </span>
                  )}
                </button>
              );
            })}
          </div>
        </div>

        {/* Panel detail / tambah cepat */}
        <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl p-5 lg:sticky lg:top-4">
          {detailDate ? (
            <>
              <div className="flex items-center justify-between mb-3">
                <h4 className="text-xs font-bold text-slate-800 dark:text-slate-100">
                  {fmtDate(detailDate)}
                </h4>
                {!isDetailLocked && selectedHolidays.length === 0 && (
                  <button
                    onClick={() => { startCreate(detailDate); }}
                    className="inline-flex items-center gap-1 px-3 py-1.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-[11px] font-bold shadow-sm shadow-indigo-500/20 transition"
                  >
                    <Plus className="w-3.5 h-3.5" /> Tambah Libur
                  </button>
                )}
              </div>
              {selectedHolidays.length === 0 ? (
                isDetailLocked ? (
                  <p className="text-[11px] text-slate-400">
                    Tidak ada libur pada tanggal ini.
                  </p>
                ) : (
                  <p className="text-[11px] text-slate-400">
                    Tidak ada libur pada tanggal ini. Klik <span className="font-semibold text-indigo-600 dark:text-indigo-400">Tambah</span> untuk membuat libur khusus perusahaan.
                  </p>
                )
              ) : (
                <div className="space-y-2">
                  {selectedHolidays.map(h => (
                    <div key={h.id} className={`border rounded-lg p-2.5 ${h.scope === 'nasional' ? 'border-rose-200 dark:border-rose-900/40 bg-rose-50/40 dark:bg-rose-950/20' : 'border-indigo-200 dark:border-indigo-900/40 bg-indigo-50/40 dark:bg-indigo-950/20'}`}>
                      <div className="flex items-center justify-between gap-2">
                        <div>
                          <p className="text-xs font-semibold text-slate-800 dark:text-slate-200">{h.name}</p>
                          {h.office_name && (
                            <p className="text-[10px] text-slate-400 font-medium flex items-center gap-1 mt-0.5">
                              <Building2 className="w-3 h-3 text-slate-400" /> {h.office_name}
                            </p>
                          )}
                        </div>
                        <span className={`inline-flex text-[9px] font-bold px-1.5 py-0.5 rounded uppercase tracking-wider shrink-0 ${
                          h.scope === 'nasional'
                            ? 'bg-rose-100 text-rose-700 dark:bg-rose-900/40 dark:text-rose-400'
                            : h.is_collective
                              ? 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300'
                              : 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-400'
                        }`}>
                          {h.is_collective
                            ? (h.office_name ? `Cuti Bersama (${h.office_name})` : 'Cuti Bersama (Semua Cabang)')
                            : h.scope}
                        </span>
                      </div>
                      {/* Ringkasan opt-in jika cuti bersama */}
                      {h.is_collective && h.collective_summary && (
                        <div className="mt-2 pt-2 border-t border-slate-100 dark:border-slate-800 grid grid-cols-3 gap-1 text-[10px] text-center">
                          <div className="bg-emerald-50 dark:bg-emerald-950/30 rounded p-1">
                            <span className="font-bold text-emerald-700 dark:text-emerald-400">{h.collective_summary.accepted}</span>
                            <span className="block text-[8px] text-emerald-600 dark:text-emerald-500 uppercase font-bold">Ikut</span>
                          </div>
                          <div className="bg-rose-50 dark:bg-rose-950/30 rounded p-1">
                            <span className="font-bold text-rose-700 dark:text-rose-400">{h.collective_summary.declined}</span>
                            <span className="block text-[8px] text-rose-600 dark:text-rose-500 uppercase font-bold">Tidak</span>
                          </div>
                          <div className="bg-slate-100 dark:bg-slate-800 rounded p-1">
                            <span className="font-bold text-slate-700 dark:text-slate-300">{h.collective_summary.pending}</span>
                            <span className="block text-[8px] text-slate-500 uppercase font-bold">Pending</span>
                          </div>
                        </div>
                      )}
                      {/* Pengecualian Karyawan */}
                      {h.excluded_users && h.excluded_users.length > 0 && (
                        <div className="mt-2 pt-2 border-t border-slate-100 dark:border-slate-800">
                          <p className="text-[9px] font-bold text-slate-500 dark:text-slate-400 uppercase mb-1 flex items-center justify-between">
                            <span>Dikecualikan (Kerja)</span>
                            <span className="text-[8px] font-normal normal-case text-slate-400">{h.excluded_users.length} karyawan</span>
                          </p>
                          <div className="flex flex-wrap gap-1">
                            {h.excluded_users.map((u: any) => {
                              const isAuto = u.is_manual === false;
                              return (
                                <span
                                  key={u.id}
                                  className={`text-[9px] px-1.5 py-0.5 rounded border inline-flex items-center gap-1 font-medium ${
                                    isAuto
                                      ? 'bg-amber-50 dark:bg-amber-950/30 text-amber-800 dark:text-amber-300 border-amber-200 dark:border-amber-800/60'
                                      : 'bg-slate-100 dark:bg-slate-700/50 text-slate-700 dark:text-slate-200 border-slate-200 dark:border-slate-600'
                                  }`}
                                  title={u.reason_detail || (isAuto ? 'Dikecualikan otomatis oleh sistem' : 'Pengecualian manual HRD')}
                                >
                                  <span>{u.name}</span>
                                  <span className={`text-[8px] font-bold ${isAuto ? 'text-amber-600 dark:text-amber-400' : 'text-slate-400'}`}>
                                    {isAuto ? `(${u.reason_label || 'Auto'})` : '(Manual)'}
                                  </span>
                                </span>
                              );
                            })}
                          </div>
                        </div>
                      )}

                      <div className="flex items-center justify-between gap-1 mt-2">
                        {h.is_collective && (
                          <button
                            onClick={() => openCollectiveDetail(h)}
                            className="inline-flex items-center gap-1 px-2 py-1 bg-amber-50 hover:bg-amber-100 text-amber-700 dark:bg-amber-950/30 dark:text-amber-400 dark:hover:bg-amber-900/50 rounded-md text-[10px] font-bold transition"
                          >
                            <Users className="w-3 h-3" /> Rekap Opt-in
                          </button>
                        )}
                        <div className="flex items-center gap-1 ml-auto">
                          {/* Tombol Ubah/Hapus hanya untuk tanggal masa depan — status historis tidak diubah/dihapus */}
                          {!isDetailLocked && (
                            (h.scope !== 'nasional' && !h.is_national) || isSuperAdmin ? (
                              <>
                                <button
                                  onClick={() => startEdit(h)}
                                  className="inline-flex items-center gap-1 px-1.5 py-1 text-sky-600 hover:bg-sky-50 dark:hover:bg-sky-950/30 rounded-md text-[10px] font-medium transition cursor-pointer"
                                  title="Ubah hari libur"
                                >
                                  <Pencil className="w-3 h-3" /> Ubah
                                </button>
                                <button
                                  onClick={() => remove(h)}
                                  className="inline-flex items-center gap-1 px-1.5 py-1 text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-950/30 rounded-md text-[10px] font-medium transition cursor-pointer"
                                  title="Hapus hari libur"
                                >
                                  <Trash2 className="w-3 h-3" /> Hapus
                                </button>
                              </>
                            ) : (
                              <span className="text-[10px] text-slate-400 dark:text-slate-500 italic px-1.5 py-0.5" title="Hanya Super Admin yang dapat mengubah atau menghapus libur nasional">
                                Libur Nasional (Terkunci)
                              </span>
                            )
                          )}
                        </div>
                      </div>
                    </div>
                  ))}
                </div>
              )}
            </>
          ) : (
            <>
              <h4 className="text-xs font-bold text-slate-800 dark:text-slate-100 mb-3">Panduan</h4>
              <ul className="space-y-3 text-[11px] text-slate-600 dark:text-slate-300">
                <li className="flex items-center gap-2">
                  <span className="w-4 h-4 rounded-md bg-rose-50 border border-rose-200 dark:bg-rose-950/30 dark:border-rose-900/40 shrink-0" />
                  <span><span className="font-semibold text-rose-700 dark:text-rose-400">Merah tua</span> — libur nasional (diberlakukan semua perusahaan)</span>
                </li>
                {calOfficeFilter && (
                  <li className="flex items-center gap-2">
                    <span className="w-4 h-4 rounded-md bg-red-50 border border-red-200 dark:bg-red-950/20 dark:border-red-900/30 shrink-0" />
                    <span><span className="font-semibold text-red-500 dark:text-red-400">Merah muda</span> — libur mingguan kantor yang dipilih</span>
                  </li>
                )}
                <li className="flex items-center gap-2">
                  <span className="w-4 h-4 rounded-md bg-amber-50 border border-amber-200 dark:bg-amber-950/30 dark:border-amber-900/40 shrink-0" />
                  <span><span className="font-semibold text-amber-700 dark:text-amber-400">Amber (kuning)</span> — cuti bersama (saldo terpotong jika karyawan ikut)</span>
                </li>
                <li className="flex items-center gap-2">
                  <span className="w-4 h-4 rounded-md bg-indigo-50 border border-indigo-200 dark:bg-indigo-950/30 dark:border-indigo-900/40 shrink-0" />
                  <span><span className="font-semibold text-indigo-700 dark:text-indigo-400">Biru</span> — libur khusus perusahaan (tanpa potong saldo)</span>
                </li>
                <li className="flex items-start gap-2">
                  <span className="w-4 h-4 rounded-md bg-slate-50 border border-slate-100 dark:bg-slate-800/40 shrink-0" />
                  <span>Klik tanggal untuk melihat detail libur di tanggal itu, atau tambah libur baru.</span>
                </li>
              </ul>
            </>
          )}
        </div>
      </div>

      {/* Modal Rekap Opt-in Cuti Bersama (HRD) */}
      {collectiveDetailHoliday && (
        <div
          className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-sm px-4 py-6"
          onClick={(e) => { if (e.target === e.currentTarget) { setCollectiveDetailHoliday(null); setCollectiveDetailData(null); } }}
        >
          <div className="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-2xl max-h-[90vh] overflow-y-auto animate-in fade-in slide-in-from-bottom-4 duration-200">
            <div className="flex items-center justify-between px-5 py-4 border-b border-slate-100 dark:border-slate-800 sticky top-0 bg-white dark:bg-slate-900">
              <div>
                <p className="font-bold text-sm text-slate-800 dark:text-slate-100 flex items-center gap-2">
                  <Users className="w-4 h-4 text-amber-500" />
                  Rekap Opt-in Cuti Bersama
                </p>
                <p className="text-[11px] text-slate-500 mt-0.5">
                  {collectiveDetailHoliday.name} · {fmtDate(String(collectiveDetailHoliday.date).slice(0, 10))}
                </p>
              </div>
              <button
                onClick={() => { setCollectiveDetailHoliday(null); setCollectiveDetailData(null); }}
                className="p-1.5 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg text-slate-400 transition"
              >
                <X className="w-4 h-4" />
              </button>
            </div>

            <div className="p-5 space-y-4">
              {loadingCollectiveDetail ? (
                <p className="text-center text-xs text-slate-400 py-8">Memuat data...</p>
              ) : collectiveDetailData ? (
                <>
                  {/* Summary cards */}
                  <div className="grid grid-cols-3 gap-3">
                    <div className="bg-emerald-50 dark:bg-emerald-950/30 border border-emerald-200 dark:border-emerald-900/40 rounded-lg p-3 text-center">
                      <p className="text-2xl font-bold text-emerald-700 dark:text-emerald-400">{collectiveDetailData.summary?.accepted ?? 0}</p>
                      <p className="text-[10px] font-bold text-emerald-600 dark:text-emerald-500 uppercase">Ikut</p>
                    </div>
                    <div className="bg-rose-50 dark:bg-rose-950/30 border border-rose-200 dark:border-rose-900/40 rounded-lg p-3 text-center">
                      <p className="text-2xl font-bold text-rose-700 dark:text-rose-400">{collectiveDetailData.summary?.declined ?? 0}</p>
                      <p className="text-[10px] font-bold text-rose-600 dark:text-rose-500 uppercase">Tidak</p>
                    </div>
                    <div className="bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg p-3 text-center">
                      <p className="text-2xl font-bold text-slate-700 dark:text-slate-300">{collectiveDetailData.summary?.pending ?? 0}</p>
                      <p className="text-[10px] font-bold text-slate-500 uppercase">Menunggu</p>
                    </div>
                  </div>

                  {/* Detail per karyawan */}
                  <div>
                    <p className="text-xs font-bold text-slate-700 dark:text-slate-200 mb-2">Daftar Karyawan</p>
                    <div className="border border-slate-100 dark:border-slate-800 rounded-lg overflow-hidden">
                      <table className="w-full text-xs">
                        <thead className="bg-slate-50 dark:bg-slate-800/50 border-b border-slate-100 dark:border-slate-800">
                          <tr>
                            <th className="py-2 px-3 text-left font-semibold text-slate-500">Karyawan</th>
                            <th className="py-2 px-3 text-left font-semibold text-slate-500">Dept</th>
                            <th className="py-2 px-3 text-center font-semibold text-slate-500">Saldo</th>
                            <th className="py-2 px-3 text-center font-semibold text-slate-500">Status</th>
                          </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-50 dark:divide-slate-800">
                          {(collectiveDetailData.employees ?? []).length === 0 ? (
                            <tr>
                              <td colSpan={4} className="text-center py-6 text-slate-400">Tidak ada data.</td>
                            </tr>
                          ) : (collectiveDetailData.employees ?? []).map((e: any) => (
                            <tr key={e.user_id} className="hover:bg-slate-50/50 dark:hover:bg-slate-800/30">
                              <td className="py-2 px-3 font-semibold text-slate-700 dark:text-slate-200">{e.user_name}</td>
                              <td className="py-2 px-3 text-slate-500">{e.department ?? '—'}</td>
                              <td className="py-2 px-3 text-center text-slate-600 dark:text-slate-300">
                                {e.remaining ?? e.quota - e.used} hari
                              </td>
                              <td className="py-2 px-3 text-center">
                                <span className={`inline-flex text-[9px] font-bold px-1.5 py-0.5 rounded uppercase tracking-wider ${
                                  e.collective_status === 'accepted'
                                    ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-400'
                                    : e.collective_status === 'declined'
                                      ? 'bg-rose-100 text-rose-700 dark:bg-rose-900/40 dark:text-rose-400'
                                      : 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-400'
                                }`}>
                                  {e.collective_status === 'accepted' ? 'Ikut' : e.collective_status === 'declined' ? 'Tidak' : 'Pending'}
                                </span>
                              </td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                  </div>
                </>
              ) : (
                <p className="text-center text-xs text-slate-400 py-8">Gagal memuat data.</p>
              )}
            </div>

            <div className="px-5 py-4 border-t border-slate-100 dark:border-slate-800 flex justify-end">
              <button
                onClick={() => { setCollectiveDetailHoliday(null); setCollectiveDetailData(null); }}
                className="px-4 py-2 bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-600 dark:text-slate-300 rounded-lg text-xs font-bold transition"
              >
                Tutup
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Modal Konfirmasi Simpan Cuti Bersama */}
      {showCollectiveConfirm && (
        <div
          className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm px-4 py-6"
          onClick={(e) => { if (e.target === e.currentTarget && !saving) setShowCollectiveConfirm(false); }}
        >
          <div className="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-xl max-h-[90vh] overflow-y-auto animate-in fade-in zoom-in-95 duration-200 border border-slate-100 dark:border-slate-800 flex flex-col">
            {/* Modal Header */}
            <div className="flex items-center justify-between px-5 py-4 border-b border-slate-100 dark:border-slate-800 sticky top-0 bg-white dark:bg-slate-900 z-10">
              <div className="flex items-center gap-2.5">
                <div className="w-9 h-9 rounded-xl bg-amber-100 dark:bg-amber-950/60 flex items-center justify-center text-amber-600 dark:text-amber-400 shrink-0">
                  <CalendarDays className="w-5 h-5" />
                </div>
                <div>
                  <h3 className="font-bold text-sm text-slate-800 dark:text-slate-100">
                    Konfirmasi Cuti Bersama
                  </h3>
                  <p className="text-[11px] text-slate-400">
                    Periksa ringkasan kepesertaan & alasan karyawan yang tidak dapat ikut
                  </p>
                </div>
              </div>
              <button
                disabled={saving}
                onClick={() => setShowCollectiveConfirm(false)}
                className="p-1.5 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg text-slate-400 transition"
              >
                <X className="w-4 h-4" />
              </button>
            </div>

            {/* Modal Content */}
            <div className="p-5 space-y-4 flex-1">
              {/* Ringkasan Cuti */}
              <div className="bg-slate-50 dark:bg-slate-800/40 rounded-xl p-3.5 border border-slate-100 dark:border-slate-800 space-y-2">
                <div className="flex items-center justify-between text-xs">
                  <span className="text-slate-400">Nama Acara / Libur</span>
                  <span className="font-bold text-slate-800 dark:text-slate-100">{collectivePreviewData?.holiday?.name || form.name}</span>
                </div>
                <div className="flex items-center justify-between text-xs">
                  <span className="text-slate-400">Tanggal Pelaksanaan</span>
                  <span className="font-bold text-indigo-600 dark:text-indigo-400">{fmtDate(collectivePreviewData?.holiday?.date || form.date)}</span>
                </div>
                <div className="flex items-center justify-between text-xs">
                  <span className="text-slate-400">Cakupan Kantor</span>
                  <span className="font-semibold text-slate-700 dark:text-slate-200">
                    {collectivePreviewData?.holiday?.office_name || (form.attendance_setting_id
                      ? (offices.find((o: any) => String(o.id) === form.attendance_setting_id)?.office_name ?? 'Cabang Terpilih')
                      : 'Semua Kantor (Semua Cabang)')}
                  </span>
                </div>
              </div>

              {/* 2 Stat Cards */}
              <div className="grid grid-cols-2 gap-3">
                <div className="bg-emerald-50/70 dark:bg-emerald-950/30 border border-emerald-200/80 dark:border-emerald-900/50 rounded-xl p-3 flex items-center gap-3">
                  <div className="w-8 h-8 rounded-lg bg-emerald-100 dark:bg-emerald-900/60 flex items-center justify-center text-emerald-600 dark:text-emerald-400 shrink-0">
                    <UserCheck className="w-4 h-4" />
                  </div>
                  <div>
                    <p className="text-[10px] uppercase font-bold text-emerald-700/80 dark:text-emerald-400">Diikutsertakan</p>
                    <p className="text-base font-extrabold text-emerald-800 dark:text-emerald-300">
                      {collectivePreviewData?.summary?.total_eligible ?? 0} <span className="text-xs font-normal text-emerald-600 dark:text-emerald-400">orang</span>
                    </p>
                  </div>
                </div>

                <div className="bg-amber-50/70 dark:bg-amber-950/30 border border-amber-200/80 dark:border-amber-900/50 rounded-xl p-3 flex items-center gap-3">
                  <div className="w-8 h-8 rounded-lg bg-amber-100 dark:bg-amber-900/60 flex items-center justify-center text-amber-600 dark:text-amber-400 shrink-0">
                    <UserX className="w-4 h-4" />
                  </div>
                  <div>
                    <p className="text-[10px] uppercase font-bold text-amber-700/80 dark:text-amber-400">Tidak Ikut / Dikecualikan</p>
                    <p className="text-base font-extrabold text-amber-800 dark:text-amber-300">
                      {collectivePreviewData?.summary?.total_excluded ?? 0} <span className="text-xs font-normal text-amber-600 dark:text-amber-400">orang</span>
                    </p>
                  </div>
                </div>
              </div>

              {/* Rincian Karyawan yang Dikecualikan */}
              <div className="space-y-2">
                <label className="text-xs font-bold text-slate-700 dark:text-slate-200 flex items-center justify-between">
                  <span className="flex items-center gap-1.5">
                    <Users className="w-3.5 h-3.5 text-amber-500" />
                    Karyawan yang Dikecualikan ({collectivePreviewData?.excluded_users?.length ?? 0})
                  </span>
                  <span className="text-[10px] font-normal text-slate-400">Beserta alasan pengecualian</span>
                </label>

                {(!collectivePreviewData?.excluded_users || collectivePreviewData.excluded_users.length === 0) ? (
                  <div className="rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50/50 dark:bg-slate-800/20 p-3 text-center">
                    <p className="text-xs text-slate-500 dark:text-slate-400">
                      Tidak ada karyawan yang dikecualikan. Seluruh karyawan aktif di cabang ini memenuhi syarat dan akan diikutsertakan.
                    </p>
                  </div>
                ) : (
                  <div className="rounded-xl border border-slate-200 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-800/30 p-2.5 max-h-56 overflow-y-auto space-y-2 divide-y divide-slate-100 dark:divide-slate-800/60">
                    {collectivePreviewData.excluded_users.map((u: any, idx: number) => {
                      let badgeClass = 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 border-slate-200 dark:border-slate-700';
                      if (u.reason_type === 'inactive_leave') {
                        badgeClass = 'bg-amber-100 dark:bg-amber-950/60 text-amber-800 dark:text-amber-300 border-amber-300 dark:border-amber-800/60';
                      } else if (u.reason_type === 'quota_exhausted') {
                        badgeClass = 'bg-rose-100 dark:bg-rose-950/60 text-rose-800 dark:text-rose-300 border-rose-300 dark:border-rose-800/60';
                      } else if (u.reason_type === 'existing_leave') {
                        badgeClass = 'bg-purple-100 dark:bg-purple-950/60 text-purple-800 dark:text-purple-300 border-purple-300 dark:border-purple-800/60';
                      } else if (u.reason_type === 'shift_off') {
                        badgeClass = 'bg-sky-100 dark:bg-sky-950/60 text-sky-800 dark:text-sky-300 border-sky-300 dark:border-sky-800/60';
                      }

                      return (
                        <div key={u.id || idx} className="pt-2 first:pt-0 flex items-start justify-between gap-2.5">
                          <div className="min-w-0 flex-1">
                            <div className="flex items-center gap-2">
                              <p className="text-xs font-bold text-slate-800 dark:text-slate-100 truncate">
                                {u.name}
                              </p>
                              {u.employee_code && (
                                <span className="text-[10px] font-mono text-slate-400 shrink-0">({u.employee_code})</span>
                              )}
                            </div>
                            <p className="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5 leading-tight">
                              {u.reason_detail || u.reason_label}
                            </p>
                          </div>
                          <span className={`inline-flex shrink-0 items-center px-2 py-0.5 rounded-md text-[10px] font-bold border shadow-2xs ${badgeClass}`}>
                            {u.reason_label}
                          </span>
                        </div>
                      );
                    })}
                  </div>
                )}
              </div>

              {/* Accordion Karyawan Diikutsertakan */}
              {collectivePreviewData?.eligible_users && collectivePreviewData.eligible_users.length > 0 && (
                <div className="border border-slate-100 dark:border-slate-800 rounded-xl overflow-hidden">
                  <button
                    type="button"
                    onClick={() => setShowEligibleAccordion(v => !v)}
                    className="w-full px-3.5 py-2.5 bg-slate-50/70 dark:bg-slate-800/40 hover:bg-slate-100 dark:hover:bg-slate-800 text-left flex items-center justify-between text-xs font-semibold text-slate-700 dark:text-slate-200 transition"
                  >
                    <span className="flex items-center gap-1.5">
                      <UserCheck className="w-3.5 h-3.5 text-emerald-500" />
                      Lihat Daftar Karyawan yang Diikutsertakan ({collectivePreviewData.eligible_users.length})
                    </span>
                    {showEligibleAccordion ? <ChevronUp className="w-3.5 h-3.5 text-slate-400" /> : <ChevronDown className="w-3.5 h-3.5 text-slate-400" />}
                  </button>

                  {showEligibleAccordion && (
                    <div className="p-3 bg-white dark:bg-slate-900 max-h-40 overflow-y-auto space-y-1.5 border-t border-slate-100 dark:border-slate-800">
                      <div className="flex flex-wrap gap-1.5">
                        {collectivePreviewData.eligible_users.map((u: any) => (
                          <span
                            key={u.id}
                            className="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg text-xs font-medium bg-emerald-50/80 dark:bg-emerald-950/40 text-emerald-900 dark:text-emerald-200 border border-emerald-200 dark:border-emerald-800/60"
                          >
                            <span>{u.name}</span>
                            {u.remaining_quota !== undefined && (
                              <span className="text-[9px] opacity-75 font-normal">
                                (sisa {u.remaining_quota} hari)
                              </span>
                            )}
                          </span>
                        ))}
                      </div>
                    </div>
                  )}
                </div>
              )}

              <p className="text-[11px] text-slate-400 leading-relaxed">
                💡 Karyawan dalam daftar pengecualian di atas otomatis dikecualikan secara permanen di sistem, tidak akan menerima notifikasi Cuti Bersama di aplikasi mobile, dan saldo cutinya tidak akan terpotong.
              </p>
            </div>

            {/* Modal Actions */}
            <div className="px-5 py-4 border-t border-slate-100 dark:border-slate-800 flex items-center justify-end gap-2 bg-slate-50/50 dark:bg-slate-900/50 rounded-b-2xl">
              <button
                type="button"
                disabled={saving}
                onClick={() => setShowCollectiveConfirm(false)}
                className="px-4 py-2 bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-600 dark:text-slate-300 rounded-xl text-xs font-bold transition disabled:opacity-50"
              >
                Batal
              </button>
              <button
                type="button"
                disabled={saving}
                onClick={executeSave}
                className="px-4 py-2 bg-amber-500 hover:bg-amber-600 text-white rounded-xl text-xs font-bold transition shadow-sm flex items-center gap-1.5 disabled:opacity-50"
              >
                {saving ? (
                  <>
                    <Loader2 className="w-3.5 h-3.5 animate-spin" />
                    Menyimpan...
                  </>
                ) : (
                  <>
                    <Check className="w-3.5 h-3.5" />
                    Ya, Simpan Cuti Bersama
                  </>
                )}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Modal Peringatan Hasil Tambah Libur / Cuti Bersama */}
      {holidayWarning && (
        <div
          className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-sm px-4 py-6"
          onClick={(e) => { if (e.target === e.currentTarget) setHolidayWarning(null); }}
        >
          <div className="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-lg max-h-[90vh] overflow-y-auto animate-in fade-in slide-in-from-bottom-4 duration-200">
            <div className="flex items-center justify-between px-5 py-4 border-b border-slate-100 dark:border-slate-800 sticky top-0 bg-white dark:bg-slate-900">
              <div>
                <p className="font-bold text-sm text-slate-800 dark:text-slate-100 flex items-center gap-2">
                  <AlertTriangle className="w-4 h-4 text-amber-500" />
                  Perhatian
                </p>
                <p className="text-[11px] text-slate-500 mt-0.5">{holidayWarning.title}</p>
              </div>
              <button
                onClick={() => setHolidayWarning(null)}
                className="p-1.5 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg text-slate-400 transition"
              >
                <X className="w-4 h-4" />
              </button>
            </div>

            <div className="p-5 space-y-4">
              {holidayWarning.autoExcluded.length > 0 && (
                <div className="rounded-lg border border-amber-200 dark:border-amber-900/40 bg-amber-50 dark:bg-amber-950/30 p-3">
                  <p className="text-xs font-bold text-amber-700 dark:text-amber-400 mb-2">
                    Dikecualikan otomatis dari cuti bersama ({holidayWarning.autoExcluded.length})
                  </p>
                  <p className="text-[11px] text-amber-600/80 dark:text-amber-500/80 mb-2">
                    Karyawan berikut sudah memiliki izin/cuti/sakit yang disetujui pada tanggal ini, sehingga tidak diikutsertakan dalam cuti bersama.
                  </p>
                  <ul className="space-y-1.5">
                    {holidayWarning.autoExcluded.map((u) => (
                      <li key={u.user_id} className="flex items-center justify-between text-[11px]">
                        <span className="font-semibold text-slate-700 dark:text-slate-200">{u.name}</span>
                        <span className="text-slate-500 dark:text-slate-400">
                          {u.leave_type} · {fmtDate(u.start_date)}{u.end_date && u.end_date !== u.start_date ? ` – ${fmtDate(u.end_date)}` : ''}
                        </span>
                      </li>
                    ))}
                  </ul>
                </div>
              )}

              {holidayWarning.balanceRestored.length > 0 && (
                <div className="rounded-lg border border-emerald-200 dark:border-emerald-900/40 bg-emerald-50 dark:bg-emerald-950/30 p-3">
                  <p className="text-xs font-bold text-emerald-700 dark:text-emerald-400 mb-2">
                    Saldo cuti dikembalikan ({holidayWarning.balanceRestored.length})
                  </p>
                  <p className="text-[11px] text-emerald-600/80 dark:text-emerald-500/80 mb-2">
                    Karyawan berikut memiliki cuti pribadi yang sudah disetujui pada tanggal ini. Karena kini menjadi hari libur, saldo cutinya dikembalikan.
                  </p>
                  <ul className="space-y-1.5">
                    {holidayWarning.balanceRestored.map((u) => (
                      <li key={u.user_id} className="flex items-center justify-between text-[11px]">
                        <span className="font-semibold text-slate-700 dark:text-slate-200">{u.name}</span>
                        <span className="text-emerald-600 dark:text-emerald-400 font-semibold">
                          +{u.restored_days} hari
                        </span>
                      </li>
                    ))}
                  </ul>
                </div>
              )}
            </div>

            <div className="px-5 py-4 border-t border-slate-100 dark:border-slate-800 flex justify-end">
              <button
                onClick={() => setHolidayWarning(null)}
                className="px-4 py-2 bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-600 dark:text-slate-300 rounded-lg text-xs font-bold transition"
              >
                Mengerti
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Confirmation Dialog Hapus Hari Libur / Cuti Bersama */}
      {deleteConfirmHoliday && (
        <ConfirmationDialog
          isOpen={!!deleteConfirmHoliday}
          onClose={() => setDeleteConfirmHoliday(null)}
          onConfirm={executeDelete}
          title="Hapus Hari Libur"
          message={
            <div className="space-y-2.5 text-xs text-slate-600 dark:text-slate-300 text-left">
              <p>Apakah Anda yakin ingin menghapus data hari libur ini?</p>
              <div className="p-3 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700/80 space-y-1.5">
                <div className="flex items-center justify-between">
                  <p className="font-bold text-slate-800 dark:text-slate-100 text-sm">{deleteConfirmHoliday.name}</p>
                  <span className={`text-[9px] px-1.5 py-0.5 rounded font-bold uppercase ${
                    deleteConfirmHoliday.is_collective
                      ? 'bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300'
                      : (deleteConfirmHoliday.scope === 'nasional' ? 'bg-rose-100 text-rose-700 dark:bg-rose-950/60 dark:text-rose-400' : 'bg-indigo-100 text-indigo-700 dark:bg-indigo-950/60 dark:text-indigo-400')
                  }`}>
                    {deleteConfirmHoliday.is_collective ? 'Cuti Bersama' : (deleteConfirmHoliday.scope === 'nasional' ? 'Libur Nasional' : 'Libur Perusahaan')}
                  </span>
                </div>
                <p className="text-slate-500 dark:text-slate-400 text-xs">
                  📅 {fmtDate(deleteConfirmHoliday.date)}
                  {deleteConfirmHoliday.office_name ? ` · 🏢 ${deleteConfirmHoliday.office_name}` : ' · 🌐 Semua Cabang'}
                </p>
                {deleteConfirmHoliday.is_collective && (
                  <p className="text-amber-600 dark:text-amber-400 text-[11px] font-medium pt-1.5 border-t border-slate-200/60 dark:border-slate-700/60">
                    ⚠️ Menghapus cuti bersama akan membatalkan seluruh jadwal cuti bersama karyawan dan mengembalikan saldo cuti yang telah terpotong.
                  </p>
                )}
              </div>
            </div>
          }
          confirmText="Ya, Hapus Libur"
          cancelText="Batal"
          type="danger"
          isLoading={deletingHoliday}
        />
      )}
    </div>
  );
};
