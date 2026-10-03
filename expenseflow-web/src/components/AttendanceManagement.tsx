import React, { useState, useEffect, useCallback, useMemo, useRef } from 'react';
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
  Layers,
  Sparkles,
  Briefcase,
  Camera,
  Eye,
  Smartphone,
  Lock,
  SlidersHorizontal,
  ShieldCheck,
  BookOpen,
  Infinity,
} from 'lucide-react';
import { attendanceApi } from '../services/endpoints';
import { ApiError, getRetryAfterSeconds, invalidateCache, onDataVersionChange } from '../services/api';
import { useDebounce } from '../hooks/useDebounce';
import { useAuth } from '../auth/AuthContext';
import CustomDatePicker from './CustomDatePicker';
import { ConfirmationDialog } from './ConfirmationDialog';
import type { LeaveTypeSettingItem } from '../types';

export const LEAVE_TYPE_LABELS: Record<string, string> = {
  cuti: 'Cuti Tahunan',
  izin: 'Izin',
  sakit: 'Sakit',
  wfh: 'WFH',
  cuti_hamil: 'Cuti Hamil & Melahirkan',
  cuti_keguguran: 'Cuti Keguguran',
  cuti_ayah: 'Cuti Ayah (Istri Melahirkan / Keguguran)',
  cuti_haid: 'Cuti Haid',
  cuti_menikah: 'Cuti Menikah',
  cuti_menikahkan_anak: 'Menikahkan Anak',
  cuti_khitan_baptis_anak: 'Mengkhitankan / Membaptiskan Anak',
  cuti_duka_keluarga_inti: 'Duka Keluarga Inti',
  cuti_duka_serumah: 'Duka Anggota Serumah',
  cuti_ibadah_haji_umrah: 'Ibadah Keagamaan (Haji / Umrah)',
  cuti_setengah_hari: 'Cuti Setengah Hari',
};

export const LEAVE_TYPE_ELIGIBILITY_MAP: Record<string, { gender?: string; marital?: string; pregnant?: boolean; note: string }> = {
  cuti_hamil: {
    gender: 'Perempuan',
    marital: 'Sudah Menikah',
    pregnant: true,
    note: 'Khusus karyawati perempuan yang sudah menikah sah & berstatus hamil aktif di data karyawan.',
  },
  cuti_keguguran: {
    gender: 'Perempuan',
    marital: 'Sudah Menikah',
    note: 'Khusus karyawati perempuan yang berstatus menikah (wajib surat dokter kandungan).',
  },
  cuti_ayah: {
    gender: 'Laki-laki',
    marital: 'Sudah Menikah',
    note: 'Khusus karyawan laki-laki yang sudah berstatus menikah pada data karyawan.',
  },
  cuti_haid: {
    gender: 'Perempuan',
    note: 'Khusus pekerja perempuan yang merasakan sakit fisik hari pertama & kedua masa haid.',
  },
  cuti_menikah: {
    marital: 'Belum Menikah',
    note: 'Khusus karyawan lajang / belum menikah yang akan melangsungkan akad atau pemberkatan pernikahan.',
  },
  cuti_menikahkan_anak: {
    marital: 'Sudah Menikah',
    note: 'Khusus karyawan yang sudah menikah/berkeluarga untuk keperluan menikahkan anak.',
  },
  cuti_khitan_baptis_anak: {
    marital: 'Sudah Menikah',
    note: 'Khusus karyawan yang sudah menikah/berkeluarga untuk upacara khitan atau baptis anak.',
  },
};

export const checkLeaveEligibility = (
  leaveType: string,
  userProfile?: { gender?: string; maritalStatus?: string; isPregnant?: boolean }
): { isEligible: boolean; note?: string } => {
  const rule = LEAVE_TYPE_ELIGIBILITY_MAP[leaveType];
  if (!rule) return { isEligible: true };

  const gender = (userProfile?.gender || '').toLowerCase();
  const marital = (userProfile?.maritalStatus || '').toLowerCase();
  const isPregnant = Boolean(userProfile?.isPregnant);

  // Periksa gender
  if (rule.gender) {
    const isTargetFemale = rule.gender.toLowerCase().includes('perempuan');
    const isTargetMale = rule.gender.toLowerCase().includes('laki');
    if (isTargetFemale) {
      if (gender && !gender.includes('perempuan') && !gender.includes('female') && !gender.includes('wanita') && gender !== 'p' && gender !== 'f') {
        return { isEligible: false, note: 'Khusus pekerja perempuan (berdasarkan data profil jenis kelamin)' };
      }
    } else if (isTargetMale) {
      if (gender && !gender.includes('laki') && !gender.includes('pria') && !gender.includes('male') && gender !== 'l' && gender !== 'm') {
        return { isEligible: false, note: 'Khusus pekerja laki-laki (berdasarkan data profil jenis kelamin)' };
      }
    }
  }

  // Periksa status pernikahan
  if (rule.marital) {
    const isSingle = marital.includes('belum') || marital.includes('lajang') || marital.includes('single') || marital.includes('tidak');
    const isMarried = marital.includes('menikah') || marital.includes('kawin') || marital.includes('married');

    if (rule.marital === 'Sudah Menikah') {
      if (isSingle || (!isMarried && !marital)) {
        return { isEligible: false, note: 'Khusus pekerja yang sudah berstatus menikah' };
      }
    } else if (rule.marital === 'Belum Menikah') {
      if (isMarried && !isSingle) {
        return { isEligible: false, note: 'Khusus pekerja lajang / belum menikah (karyawan berstatus menikah tidak berhak mengambil cuti menikah)' };
      }
    }
  }

  // Periksa status kehamilan
  if (rule.pregnant) {
    if (!isPregnant) {
      return { isEligible: false, note: 'Khusus pekerja dengan status kehamilan aktif' };
    }
  }

  return { isEligible: true, note: rule.note };
};

export const LEAVE_REGULATIONS_GUIDE = [
  {
    type: 'cuti',
    label: 'Cuti Tahunan',
    quota: '12 hari/tahun',
    gender: 'Semua Gender',
    marital: 'Bebas',
    pregnant: '-',
    document: 'Tidak Wajib',
    legal: 'UU No. 13/2003 Ps. 79 (2)c',
    description: 'Hak istirahat tahunan bagi pekerja dengan masa kerja 12 bulan terus menerus. Kuota dasar dan tanggal reset tahunan dapat dikonfigurasi per kantor cabang.',
  },
  {
    type: 'izin',
    label: 'Izin',
    quota: 'Fleksibel / Akumulasi',
    gender: 'Semua Gender',
    marital: 'Bebas',
    pregnant: '-',
    document: 'Opsional (SOP Kantor)',
    legal: 'Kebijakan Perusahaan & PP/PKB',
    description: 'Izin tidak masuk kerja karena urusan keluarga, keperluan mendesak, atau keperluan pribadi di luar cuti tahunan.',
  },
  {
    type: 'wfh',
    label: 'Work From Home (WFH)',
    quota: 'Fleksibel / Sesuai Pengajuan',
    gender: 'Semua Gender',
    marital: 'Bebas',
    pregnant: '-',
    document: 'Opsional (Surat Tugas / Rencana Kerja)',
    legal: 'Kebijakan Fleksibilitas Kerja',
    description: 'Mode bekerja jarak jauh dari rumah atau luar kantor sesuai persetujuan atasan langsung dan SOP kantor cabang.',
  },
  {
    type: 'cuti_hamil',
    label: 'Cuti Hamil & Melahirkan',
    quota: '90 hari (3 s.d. 6 bulan)',
    gender: 'Khusus Perempuan',
    marital: 'Wajib Menikah',
    pregnant: 'Wajib Status Hamil Aktif',
    document: 'Wajib (Surat Dokter/Bidan)',
    legal: 'UU No. 13/2003 Ps. 82 (1) jo. UU KIA No. 4/2024',
    description: 'Istirahat 1,5 bulan sebelum melahirkan dan 1,5 bulan setelah melahirkan. Menurut UU KIA No. 4/2024, dapat diperpanjang hingga 6 bulan bila ada kondisi/indikasi medis khusus.',
  },
  {
    type: 'cuti_keguguran',
    label: 'Cuti Keguguran',
    quota: '45 hari (1,5 bulan)',
    gender: 'Khusus Perempuan',
    marital: 'Wajib Menikah',
    pregnant: 'Pasca-keguguran',
    document: 'Wajib (Surat Dokter Kandungan)',
    legal: 'UU No. 13/2003 Pasal 82 ayat (2)',
    description: 'Istirahat 1,5 bulan atau sesuai surat keterangan dokter spesialis kandungan/kebidanan setelah pekerja mengalami keguguran kandungan.',
  },
  {
    type: 'cuti_ayah',
    label: 'Cuti Ayah (Istri Melahirkan / Keguguran)',
    quota: '2 hari kerja',
    gender: 'Khusus Laki-laki',
    marital: 'Wajib Menikah',
    pregnant: '-',
    document: 'Opsional (SOP Kantor)',
    legal: 'UU No. 13/2003 Pasal 93 ayat (4) huruf e',
    description: 'Pendampingan suami saat istri sah melahirkan anak atau mengalami musibah keguguran kandungan.',
  },
  {
    type: 'cuti_haid',
    label: 'Cuti Haid',
    quota: '2 hari kerja',
    gender: 'Khusus Perempuan',
    marital: 'Bebas (Lajang/Menikah)',
    pregnant: '-',
    document: 'Opsional',
    legal: 'UU No. 13/2003 Pasal 81 ayat (1)',
    description: 'Pekerja perempuan yang merasakan sakit fisik pada hari pertama dan kedua masa haid sehingga tidak dapat melakukan pekerjaan.',
  },
  {
    type: 'cuti_menikah',
    label: 'Cuti Menikah Karyawan',
    quota: '3 hari kerja',
    gender: 'Semua Gender',
    marital: 'Belum Menikah / Lajang',
    pregnant: '-',
    document: 'Opsional (Undangan/Akad)',
    legal: 'UU No. 13/2003 Pasal 93 ayat (4) huruf a',
    description: 'Pekerja/buruh lajang yang melangsungkan akad atau pemberkatan pernikahan dirinya sendiri.',
  },
  {
    type: 'cuti_menikahkan_anak',
    label: 'Menikahkan Anak',
    quota: '2 hari kerja',
    gender: 'Semua Gender',
    marital: 'Wajib Menikah',
    pregnant: '-',
    document: 'Opsional',
    legal: 'UU No. 13/2003 Pasal 93 ayat (4) huruf b',
    description: 'Pekerja/buruh yang telah menikah/berkeluarga menikahkan anak kandungnya.',
  },
  {
    type: 'cuti_khitan_baptis_anak',
    label: 'Mengkhitankan / Membaptiskan Anak',
    quota: '2 hari kerja',
    gender: 'Semua Gender',
    marital: 'Wajib Menikah',
    pregnant: '-',
    document: 'Opsional',
    legal: 'UU No. 13/2003 Pasal 93 ayat (4) huruf c',
    description: 'Pekerja/buruh menyelenggarakan upacara khitanan atau pembaptisan anak kandungnya.',
  },
  {
    type: 'cuti_duka_keluarga_inti',
    label: 'Duka Cita Keluarga Inti',
    quota: '2 hari kerja',
    gender: 'Semua Gender',
    marital: 'Bebas',
    pregnant: '-',
    document: 'Opsional',
    legal: 'UU No. 13/2003 Pasal 93 ayat (4) huruf d',
    description: 'Suami/istri, orang tua/mertua, atau anak pekerja/buruh meninggal dunia.',
  },
  {
    type: 'cuti_duka_serumah',
    label: 'Duka Cita Anggota Serumah',
    quota: '1 hari kerja',
    gender: 'Semua Gender',
    marital: 'Bebas',
    pregnant: '-',
    document: 'Opsional',
    legal: 'UU No. 13/2003 Pasal 93 ayat (4) huruf f',
    description: 'Anggota keluarga yang bertempat tinggal dalam satu rumah dengan pekerja meninggal dunia.',
  },
  {
    type: 'cuti_ibadah_haji_umrah',
    label: 'Ibadah Keagamaan (Haji / Umrah)',
    quota: '40 hari kerja',
    gender: 'Semua Gender',
    marital: 'Bebas',
    pregnant: '-',
    document: 'Wajib (Bukti Keberangkatan)',
    legal: 'UU No. 13/2003 Ps. 80 & Ps. 93 (2)e',
    description: 'Menunaikan kewajiban ibadah keagamaan haji (atau umrah) yang diperintahkan oleh agamanya.',
  },
  {
    type: 'cuti_setengah_hari',
    label: 'Cuti Setengah Hari',
    quota: '10 unit/tahun',
    gender: 'Semua Gender',
    marital: 'Bebas',
    pregnant: '-',
    document: 'Opsional',
    legal: 'Kebijakan Internal Kantor',
    description: 'Cuti fleksibel setengah hari kerja mandiri (bersifat opsional/opt-in per regulasi cabang kantor).',
  },
  {
    type: 'sakit',
    label: 'Cuti Sakit',
    quota: 'Sesuai rujukan medis (Standar: 14 hari)',
    gender: 'Semua Gender',
    marital: 'Bebas',
    pregnant: '-',
    document: 'Wajib Surat Dokter (Dapat dikonfigurasi)',
    legal: 'UU No. 13/2003 Ps. 93 (2)a',
    description: 'Pekerja/buruh sakit dengan melampirkan surat keterangan dokter yang sah. Kuota estimasi, kewajiban surat dokter, dan SOP pengajuan dapat dikonfigurasi per kantor cabang.',
  },
  {
    type: 'izin',
    label: 'Izin',
    quota: 'SOP Perusahaan',
    gender: 'Semua Gender',
    marital: 'Bebas',
    pregnant: '-',
    document: 'Opsional',
    legal: 'Perjanjian Kerja / PP',
    description: 'Izin meninggalkan pekerjaan untuk keperluan mendesak dengan persetujuan atasan/HRD.',
  },
  {
    type: 'wfh',
    label: 'Work From Home (WFH)',
    quota: 'Sesuai Jadwal Shift',
    gender: 'Semua Gender',
    marital: 'Bebas',
    pregnant: '-',
    document: 'Presensi GPS & Swafoto',
    legal: 'Kebijakan Kerja Remote',
    description: 'Bekerja secara jarak jauh/WFH dengan pencatatan presensi GPS dan swafoto masuk/pulang.',
  },
];

export const getLeaveTypeLabel = (type: string) => LEAVE_TYPE_LABELS[type] ?? type;

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
    case 'absent':
    case 'alpha': return 'bg-rose-50 text-rose-700 dark:bg-rose-950/30 dark:text-rose-400';
    case 'early_leave': return 'bg-violet-50 text-violet-700 dark:bg-violet-950/30 dark:text-violet-400';
    case 'cuti': return 'bg-teal-50 text-teal-700 dark:bg-teal-950/30 dark:text-teal-400';
    case 'izin': return 'bg-purple-50 text-purple-700 dark:bg-purple-950/30 dark:text-purple-400';
    case 'sakit': return 'bg-orange-50 text-orange-700 dark:bg-orange-950/30 dark:text-orange-400';
    case 'wfh': return 'bg-indigo-50 text-indigo-700 dark:bg-indigo-950/30 dark:text-indigo-400';
    case 'libur': return 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400';
    case 'belum_hadir': return 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400';
    default: return 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300';
  }
};

const statusLabel = (status: string) => {
  switch (status) {
    case 'present': return 'Hadir';
    case 'late': return 'Telat';
    case 'absent':
    case 'alpha': return 'Alpha';
    case 'early_leave': return 'Pulang Awal';
    case 'cuti': return 'Cuti';
    case 'izin': return 'Izin';
    case 'sakit': return 'Sakit';
    case 'wfh': return 'WFH';
    case 'libur': return 'Libur';
    case 'belum_hadir': return 'Belum Hadir';
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
  const [leaveStepFilter, setLeaveStepFilter] = useState<'all' | 'spv' | 'hrd'>('hrd');
  const [leaveTypeFilter, setLeaveTypeFilter] = useState<string>('');
  const [leaveSourceFilter, setLeaveSourceFilter] = useState<'all' | 'mandiri' | 'collective'>('all');
  const [leaveOfficeFilter, setLeaveOfficeFilter] = useState(''); // '' = semua cabang
  const [leaveSearch, setLeaveSearch] = useState('');
  const [showUpcoming, setShowUpcoming] = useState(false);
  const [docLoadingId, setDocLoadingId] = useState<number | null>(null);
  const [docModal, setDocModal] = useState<{ url: string; isPdf: boolean; userName: string } | null>(null);
  const [visitDetailModal, setVisitDetailModal] = useState<{
    attendanceId: number;
    userName: string;
    clientName: string;
    clientAddress?: string;
    visitNotes?: string;
    checkInLat?: number | string;
    checkInLng?: number | string;
    checkInTime?: string;
    photoUrl?: string | null;
    loadingPhoto: boolean;
  } | null>(null);
  const [users, setUsers] = useState<any[]>([]);
  const [userSearch, setUserSearch] = useState('');
  const [userOfficeFilter, setUserOfficeFilter] = useState('');
  const [balances, setBalances] = useState<any[]>([]);
  const [balanceSearch, setBalanceSearch] = useState('');
  const [balanceOfficeFilter, setBalanceOfficeFilter] = useState('');
  const [togglingUserId, setTogglingUserId] = useState<number | null>(null);


  // Sub-tab & history state untuk Saldo Cuti
  const [balanceSubTab, setBalanceSubTab] = useState<'active' | 'history' | 'leave_types'>('active');
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

  // State ekspansi jenis cuti tambahan per user & modal edit saldo
  const [expandedUserLeaves, setExpandedUserLeaves] = useState<Record<string, boolean>>({});
  const [editUserBalanceModal, setEditUserBalanceModal] = useState<{
    user_id: number;
    user_name: string;
    employee_code?: string;
    office_name?: string;
    leave_type: string;
    quota: number;
    used: number;
    remaining: number;
  } | null>(null);
  const [isSavingUserBalance, setIsSavingUserBalance] = useState(false);

  // Modal mini-dashboard 360° kelola saldo seluruh jenis cuti & laporan cuti terpakai per karyawan
  const [userLeaveDetailModal, setUserLeaveDetailModal] = useState<{
    userId: number;
    userName: string;
    employeeCode: string;
    department: string;
    officeName: string;
    gender?: string;
    maritalStatus?: string;
    isPregnant?: boolean;
    activeTab: 'matrix' | 'history';
    balancesMap: Record<string, {
      quota: number;
      used: number;
      remaining: number;
      officeDefault: number;
      isOfficeDisabled?: boolean;
      isUnlimited?: boolean;
    }>;
    originalBalancesMap: Record<string, number>;
    leavesHistory: any[];
    loadingHistory: boolean;
    historyFilterStatus: string;
    historyFilterType: string;
    historySearch: string;
    historySummary: { approved: number; pending: number; rejected: number; adjustments: number; totalDays: number };
  } | null>(null);
  const [isSavingUserDetailBalances, setIsSavingUserDetailBalances] = useState(false);

  // Modal rincian snapshot cuti tambahan periode lalu
  const [viewingSnapshotHistory, setViewingSnapshotHistory] = useState<any | null>(null);

  // State untuk sub-tab "Pengaturan Jenis Cuti Kantor"
  const [leaveTypeOfficeId, setLeaveTypeOfficeId] = useState<string>('');
  const leaveTypeOfficeIdRef = useRef<string>('');
  leaveTypeOfficeIdRef.current = leaveTypeOfficeId;
  const loadingLeaveTypeSettingsRef = useRef<boolean>(false);
  const officesRef = useRef<any[]>([]);
  const [leaveTypeSettingsList, setLeaveTypeSettingsList] = useState<LeaveTypeSettingItem[]>([]);
  const [leaveTypeSettingsOfficeName, setLeaveTypeSettingsOfficeName] = useState<string>('');
  const [leaveTypeOfficeQuota, setLeaveTypeOfficeQuota] = useState<number>(12);
  const [leaveTypeOfficeResetMonth, setLeaveTypeOfficeResetMonth] = useState<string>('12');
  const [leaveTypeOfficeResetDay, setLeaveTypeOfficeResetDay] = useState<string>('01');
  const [leaveTypeOfficeMultiApproval, setLeaveTypeOfficeMultiApproval] = useState<boolean>(true);
  const [leaveTypeSettingsLoading, setLeaveTypeSettingsLoading] = useState(false);
  const [leaveTypeSettingsSaving, setLeaveTypeSettingsSaving] = useState(false);
  const [leaveTypeSettingsEdited, setLeaveTypeSettingsEdited] = useState<Record<string, {
    is_enabled: boolean;
    quota_days: number;
    requires_document: boolean;
    notes: string;
  }>>({});
  const [showLeaveInfoModal, setShowLeaveInfoModal] = useState<boolean>(false);
  const [leaveInfoModalTab, setLeaveInfoModalTab] = useState<'overview' | 'catalog' | 'validation'>('overview');
  const [leaveInfoSearch, setLeaveInfoSearch] = useState<string>('');

  const debouncedBalanceHistorySearch = useDebounce(balanceHistorySearch, 500);
  const [report, setReport] = useState<any | null>(null);
  const [reportFilter, setReportFilter] = useState<{ start_date: string; end_date: string; status: string; type: string; search?: string; office_id?: string; shift_id?: string }>({
    start_date: '',
    end_date: '',
    status: '',
    type: '',
    search: '',
    office_id: '',
    shift_id: '',
  });
  const [reportSearch, setReportSearch] = useState('');
  const [reportPage, setReportPage] = useState(1);
  const [reportPageSize, setReportPageSize] = useState(25);
  const [reportAvailableShifts, setReportAvailableShifts] = useState<any[]>([]);
  const [reportSubTab, setReportSubTab] = useState<'log' | 'matrix'>('log');
  const [offices, setOffices] = useState<any[]>([]);
  officesRef.current = offices;
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
  }, [debouncedLeaveSearch, leaveStatus, leaveTypeFilter, leaveStepFilter, leaveSourceFilter, leaveOfficeFilter, showUpcoming]);

  useEffect(() => {
    setUserPage(1);
  }, [debouncedUserSearch, userOfficeFilter]);

  useEffect(() => {
    setBalancePage(1);
  }, [debouncedBalanceSearch, balanceOfficeFilter]);

  useEffect(() => {
    attendanceApi.settings.list().then(res => {
      const list = (res as any)?.settings ?? [];
      setOffices(list);
      if (list.length > 0) {
        setLeaveTypeOfficeId(prev => prev || String(list[0].id));
      }
      if (list.length === 1) {
        const singleId = String(list[0].id);
        setTodayOfficeFilter(singleId);
        setLeaveOfficeFilter(singleId);
        setUserOfficeFilter(singleId);
        setBalanceOfficeFilter(singleId);
        setBalanceHistoryOfficeFilter(singleId);
        setReportFilterAndReset((prev: any) => ({ ...prev, office_id: singleId }));
      }
    }).catch(() => { });
  }, []);

  useEffect(() => {
    if (reportFilter.search !== debouncedReportSearch) {
      setReportFilterAndReset({ ...reportFilter, search: debouncedReportSearch });
    }
  }, [debouncedReportSearch]);

  const [reportNameSort, setReportNameSort] = useState<'asc' | 'desc' | null>(null);
  const [holidays, setHolidays] = useState<any[]>([]);
  const [holidayYear, setHolidayYear] = useState<number>(new Date().getFullYear());

  // Countdown timer saat terkena HTTP 429 (Rate limit)
  const [rateLimitCountdown, setRateLimitCountdown] = useState(0);
  const rateLimitTimerRef = useRef<ReturnType<typeof setInterval> | null>(null);

  useEffect(() => {
    return () => {
      if (rateLimitTimerRef.current) {
        clearInterval(rateLimitTimerRef.current);
        rateLimitTimerRef.current = null;
      }
    };
  }, []);

  const startRateLimitCountdown = useCallback((initialSeconds: number) => {
    const secs = Math.max(1, Math.min(initialSeconds, 30));
    setRateLimitCountdown(secs);
    setError(`Terlalu banyak permintaan. Silakan tunggu ${secs} detik sebelum mencoba kembali.`);

    if (rateLimitTimerRef.current) {
      clearInterval(rateLimitTimerRef.current);
    }

    rateLimitTimerRef.current = setInterval(() => {
      setRateLimitCountdown((prev) => {
        if (prev <= 1) {
          if (rateLimitTimerRef.current) {
            clearInterval(rateLimitTimerRef.current);
            rateLimitTimerRef.current = null;
          }
          setError(null);
          return 0;
        }
        const next = prev - 1;
        setError(`Terlalu banyak permintaan. Silakan tunggu ${next} detik sebelum mencoba kembali.`);
        return next;
      });
    }, 1000);
  }, []);

  const reportApiError = (err: unknown, fallback: string) => {
    if (err instanceof ApiError) {
      if (err.status === 429) {
        const secs = getRetryAfterSeconds(err) ?? 30;
        startRateLimitCountdown(secs);
        return;
      }
      const firstError = err.data?.errors && Object.values(err.data.errors)[0];
      setError(Array.isArray(firstError) ? firstError[0] : err.message);
    } else {
      setError(fallback);
    }
  };

  // ─── Loaders ──────────────────────────────────────────────
  const loadToday = useCallback(async (forceRefresh = false) => {
    setLoading(true);
    setError(null);
    try {
      if (forceRefresh) invalidateCache('/dashboard/attendance/today');
      setToday(await attendanceApi.today(forceRefresh));
    } catch (e) {
      reportApiError(e, 'Gagal memuat data presensi hari ini.');
    } finally {
      setLoading(false);
    }
  }, []);

  const loadLeaves = useCallback(async (forceRefresh = false) => {
    setLoading(true);
    setError(null);
    try {
      // per_page: 500 — cukup untuk perusahaan UKM. Tidak bisa server-side pagination
      // karena conflict detection (deteksi bentrok cuti) butuh semua data leaves (semua status).
      if (forceRefresh) invalidateCache('/dashboard/attendance/leaves');
      const res: any = await attendanceApi.leaves({ per_page: 1000 }, forceRefresh);
      setLeaves(rows(res));
    } catch (e) {
      reportApiError(e, 'Gagal memuat pengajuan izin/cuti.');
    } finally {
      setLoading(false);
    }
  }, []);

  const loadUsers = useCallback(async (forceRefresh = false) => {
    setLoading(true);
    setError(null);
    try {
      // per_page: 300 — cukup untuk perusahaan UKM (≤ 300 karyawan aktif).
      // Filter office/search dilakukan client-side karena backend belum support filter tersebut.
      if (forceRefresh) invalidateCache('/dashboard/attendance/users');
      const res: any = await attendanceApi.users({ per_page: 300 }, forceRefresh);
      setUsers(rows(res));
    } catch (e) {
      reportApiError(e, 'Gagal memuat daftar karyawan.');
    } finally {
      setLoading(false);
    }
  }, []);

  const loadBalances = useCallback(async (forceRefresh = false) => {
    setLoading(true);
    setError(null);
    try {
      if (forceRefresh) invalidateCache('/dashboard/attendance/leave-balances');
      const res: any = await attendanceApi.leaveBalances(undefined, forceRefresh);
      setBalances(res?.balances ?? []);
    } catch (e) {
      reportApiError(e, 'Gagal memuat saldo cuti.');
    } finally {
      setLoading(false);
    }
  }, []);

  const loadBalanceHistories = useCallback(async (forceRefresh = false) => {
    setBalanceHistoryLoading(true);
    try {
      if (forceRefresh) invalidateCache('/dashboard/attendance/leave-balance-history');
      const params: any = {};
      if (balanceHistoryOfficeFilter) params.office_id = balanceHistoryOfficeFilter;
      if (balanceHistoryYearFilter) params.year = Number(balanceHistoryYearFilter);
      if (debouncedBalanceHistorySearch) params.search = debouncedBalanceHistorySearch;
      const res: any = await attendanceApi.leaveBalanceHistories(params, forceRefresh);
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

  const loadLeaveTypeSettings = useCallback(async (officeId?: string, forceRefresh = false) => {
    // Hindari race condition dan panggilan duplikat simultan yang memicu HTTP 429
    if (loadingLeaveTypeSettingsRef.current) return;
    loadingLeaveTypeSettingsRef.current = true;
    setLeaveTypeSettingsLoading(true);
    try {
      if (forceRefresh) invalidateCache('/dashboard/attendance/leave-types');
      const targetOffice = officeId || leaveTypeOfficeIdRef.current || (officesRef.current[0]?.id ? String(officesRef.current[0].id) : undefined);
      const res: any = await attendanceApi.leaveTypeSettings(targetOffice, forceRefresh);
      const list = res?.leave_types ?? [];
      setLeaveTypeSettingsList(list);
      setLeaveTypeSettingsOfficeName(res?.office_name ?? '');
      if (res?.attendance_setting_id) {
        const returnedId = String(res.attendance_setting_id);
        setLeaveTypeOfficeId(prev => (prev === returnedId ? prev : (prev || returnedId)));
      }
      if (res?.default_leave_quota !== undefined) {
        setLeaveTypeOfficeQuota(Number(res.default_leave_quota));
      }
      if (res?.leave_reset_date) {
        const [m, d] = res.leave_reset_date.split('-');
        setLeaveTypeOfficeResetMonth(m || '');
        setLeaveTypeOfficeResetDay(d || '');
      } else {
        setLeaveTypeOfficeResetMonth('');
        setLeaveTypeOfficeResetDay('');
      }
      if (res?.leave_multi_approval_enabled !== undefined) {
        setLeaveTypeOfficeMultiApproval(Boolean(res.leave_multi_approval_enabled));
      }

      const edited: Record<string, any> = {};
      list.forEach((item: any) => {
        const isAlwaysEnabled = item.leave_type === 'izin' || item.leave_type === 'wfh';
        edited[item.leave_type] = {
          is_enabled: isAlwaysEnabled ? true : Boolean(item.is_enabled),
          quota_days: Number(item.quota_days ?? 0),
          requires_document: Boolean(item.requires_document),
          notes: item.notes || '',
        };
      });
      setLeaveTypeSettingsEdited(edited);
    } catch (e) {
      reportApiError(e, 'Gagal memuat pengaturan jenis cuti kantor.');
    } finally {
      loadingLeaveTypeSettingsRef.current = false;
      setLeaveTypeSettingsLoading(false);
    }
  }, []);

  const handleSaveLeaveTypeSettings = async () => {
    const targetOfficeId = leaveTypeOfficeId ? Number(leaveTypeOfficeId) : offices[0]?.id;
    if (!targetOfficeId) {
      reportApiError(null, 'Pilih kantor terlebih dahulu.');
      return;
    }
    setLeaveTypeSettingsSaving(true);
    try {
      const resetDateStr = leaveTypeOfficeResetMonth && leaveTypeOfficeResetDay
        ? `${leaveTypeOfficeResetMonth}-${leaveTypeOfficeResetDay}`
        : null;

      const settings = Object.entries(leaveTypeSettingsEdited).map(([leave_type, val]: [string, any]) => ({
        leave_type,
        is_enabled: (leave_type === 'izin' || leave_type === 'wfh') ? true : val.is_enabled,
        quota_days: Number(val.quota_days),
        requires_document: val.requires_document,
        notes: val.notes || null,
      }));

      const res: any = await attendanceApi.updateLeaveTypeSettings({
        attendance_setting_id: targetOfficeId,
        default_leave_quota: Number(leaveTypeOfficeQuota),
        leave_reset_date: resetDateStr,
        leave_multi_approval_enabled: leaveTypeOfficeMultiApproval,
        settings,
      });

      // Invalidate cache settings kantor umum agar sinkron
      invalidateCache('/dashboard/attendance/settings');

      onAddAuditLog(
        'Pengaturan Jenis Cuti Kantor Diperbarui',
        `Kantor ${leaveTypeSettingsOfficeName || targetOfficeId}: Saldo default ${leaveTypeOfficeQuota} hari, reset ${resetDateStr || 'manual'}, ${settings.length} jenis cuti diperbarui`,
        'bg-teal-600'
      );
      onAddNotification(
        'success',
        'Pengaturan Cuti Tersimpan',
        res.message || 'Pengaturan saldo dan jenis cuti kantor berhasil diperbarui.'
      );
      await Promise.all([loadLeaveTypeSettings(String(targetOfficeId), true), loadBalances(true)]);
    } catch (e) {
      reportApiError(e, 'Gagal menyimpan pengaturan jenis cuti.');
    } finally {
      setLeaveTypeSettingsSaving(false);
    }
  };

  const handleResetLeaveTypeSettingsToDefault = () => {
    setLeaveTypeOfficeQuota(12);
    setLeaveTypeOfficeResetMonth('12');
    setLeaveTypeOfficeResetDay('01');
    setLeaveTypeOfficeMultiApproval(true);

    const resetMap: Record<string, any> = {};
    leaveTypeSettingsList.forEach((item) => {
      resetMap[item.leave_type] = {
        is_enabled: item.default_quota_days > 0 || item.leave_type !== 'cuti_setengah_hari',
        quota_days: item.default_quota_days,
        requires_document: item.requires_document,
        notes: item.notes || '',
      };
    });
    setLeaveTypeSettingsEdited(resetMap);
    onAddNotification('new', 'Form Direset', 'Nilai kuota dikembalikan ke standar regulasi (belum disimpan).');
  };

  const handleSaveUserBalance = async () => {
    if (!editUserBalanceModal) return;
    const currentBalance = balances.find(b => b.user_id === editUserBalanceModal.user_id && b.leave_type === editUserBalanceModal.leave_type);
    const officeLimit = currentBalance?.office_default_quota ?? 0;
    const requestedRemaining = Number(editUserBalanceModal.remaining ?? Math.max(0, editUserBalanceModal.quota - editUserBalanceModal.used));

    if (officeLimit > 0 && requestedRemaining > officeLimit) {
      onAddNotification('error', 'Validasi Gagal', `Sisa saldo (${requestedRemaining} hari) tidak boleh melebihi standar kantor (${officeLimit} hari).`);
      return;
    }

    const isOfficeDisabled = currentBalance ? (currentBalance.is_enabled_in_office === false || currentBalance.is_disabled === true) : false;
    if (isOfficeDisabled) {
      onAddNotification('error', 'Jenis Cuti Dinonaktifkan', `Jenis cuti ${getLeaveTypeLabel(editUserBalanceModal.leave_type)} sedang dinonaktifkan di pengaturan kantor ini.`);
      setEditUserBalanceModal(null);
      return;
    }

    const isIzinAccumulation = editUserBalanceModal.leave_type === 'izin' && 
      (currentBalance?.office_default_quota ?? 0) <= 0;
    if (isIzinAccumulation) {
      onAddNotification('warning', 'Tidak Dapat Diubah', 'Izin pribadi kantor ini diatur dalam mode akumulasi (tanpa batasan kuota).');
      setEditUserBalanceModal(null);
      return;
    }
    setIsSavingUserBalance(true);
    try {
      await attendanceApi.setLeaveBalance({
        user_id: editUserBalanceModal.user_id,
        leave_type: editUserBalanceModal.leave_type,
        remaining: requestedRemaining,
      });
      onAddAuditLog(
        'Saldo Cuti Karyawan Disesuaikan',
        `${editUserBalanceModal.user_name}: sisa saldo ${getLeaveTypeLabel(editUserBalanceModal.leave_type)} diatur menjadi ${requestedRemaining} hari`,
        'bg-indigo-600'
      );
      onAddNotification(
        'success',
        'Saldo Cuti Disimpan',
        `Sisa saldo ${getLeaveTypeLabel(editUserBalanceModal.leave_type)} untuk ${editUserBalanceModal.user_name} berhasil disimpan.`
      );
      setEditUserBalanceModal(null);
      await loadBalances(true);
    } catch (e) {
      reportApiError(e, 'Gagal menyimpan saldo cuti karyawan.');
    } finally {
      setIsSavingUserBalance(false);
    }
  };

  const handleOpenUserDetailModal = async (
    userId: number,
    userName: string,
    data: any,
    initialTab: 'matrix' | 'history' = 'matrix'
  ) => {
    const userBalances = balances.filter(b => b.user_id === userId);
    const balanceMap: Record<string, { quota: number; used: number; remaining: number; officeDefault: number; isOfficeDisabled: boolean; isUnlimited: boolean }> = {};
    const originalMap: Record<string, number> = {};

    const userProfileForEligibility = {
      gender: data.gender || '',
      maritalStatus: data.maritalStatus || '',
      isPregnant: data.isPregnant,
    };

    Object.keys(LEAVE_TYPE_LABELS).forEach(type => {
      if (type === 'wfh') return; // WFH adalah mode presensi, bukan jenis saldo hak cuti
      const isIzin = type === 'izin';
      const elig = isIzin ? { isEligible: true } : checkLeaveEligibility(type, userProfileForEligibility);
      const found = userBalances.find(b => b.leave_type === type);

      // Cek apakah tipe cuti ini di-disable di level kantor
      const isOfficeDisabled = found ? (found.is_enabled_in_office === false || found.is_disabled === true) : false;

      const officeDefault = isOfficeDisabled ? 0 : (found?.office_default_quota 
        ?? (type === 'cuti' ? 12 : type === 'cuti_setengah_hari' ? 10 : type === 'cuti_hamil' ? 90 : type === 'cuti_keguguran' ? 45 : type === 'cuti_menikah' ? 3 : type === 'cuti_ayah' || type === 'cuti_haid' || type === 'cuti_menikahkan_anak' || type === 'cuti_khitan_baptis_anak' || type === 'cuti_duka_keluarga_inti' ? 2 : type === 'cuti_duka_serumah' ? 1 : type === 'cuti_ibadah_haji_umrah' ? 40 : type === 'sakit' ? 14 : 0));
      
      // PENTING: isUnlimited HANYA berlaku kalau tipe cuti ENABLED di kantor.
      // Kalau disabled → bukan akumulasi, tapi OFF.
      const isUnlimited = !isOfficeDisabled && Boolean(found?.is_unlimited || (isIzin && officeDefault <= 0));
      let rawQuota = (isUnlimited || isOfficeDisabled) ? 0 : (found && Number(found.quota ?? 0) > 0 ? Number(found.quota) : officeDefault);
      // Alokasi kuota karyawan tidak boleh di atas standar kantor:
      if (!isUnlimited && !isOfficeDisabled && officeDefault > 0 && rawQuota > officeDefault) {
        rawQuota = officeDefault;
      }
      const currentQuota = (!elig.isEligible || isOfficeDisabled) ? 0 : rawQuota;
      const used = found ? Number(found.used ?? 0) : 0;
      const remaining = (isUnlimited || isOfficeDisabled) ? 0 : (!elig.isEligible ? 0 : (found && found.remaining !== null && found.remaining !== undefined ? Math.min(Number(found.remaining), Math.max(0, currentQuota - used)) : Math.max(0, currentQuota - used)));

      balanceMap[type] = {
        quota: currentQuota,
        used,
        remaining,
        officeDefault,
        isOfficeDisabled,
        isUnlimited,
      };
      originalMap[type] = remaining;
    });

    setUserLeaveDetailModal({
      userId,
      userName,
      employeeCode: data.employeeCode || '',
      department: data.department || '',
      officeName: data.officeName || '',
      gender: data.gender || '',
      maritalStatus: data.maritalStatus || '',
      isPregnant: data.isPregnant,
      activeTab: initialTab,
      balancesMap: balanceMap,
      originalBalancesMap: originalMap,
      leavesHistory: [],
      loadingHistory: true,
      historyFilterStatus: 'all',
      historyFilterType: 'all',
      historySearch: '',
      historySummary: { approved: 0, pending: 0, rejected: 0, adjustments: 0, totalDays: 0 },
    });

    try {
      const res: any = await attendanceApi.leaves({ user_id: userId, per_page: 500 }, true);
      const list = rows(res);
      const adjustments: any[] = Array.isArray(res?.adjustments)
        ? res.adjustments
        : (Array.isArray(res?.data?.adjustments) ? res.data.adjustments : []);
      const combinedHistory = [
        ...list.map((l: any) => ({ ...l, is_adjustment: false })),
        ...adjustments.map((a: any) => ({ ...a, is_adjustment: true, status: 'adjustment' })),
      ].sort((a: any, b: any) => new Date(b.created_at || 0).getTime() - new Date(a.created_at || 0).getTime());

      const approvedList = list.filter((l: any) => l.status === 'approved');
      const totalDaysApproved = approvedList.reduce((acc: number, l: any) => acc + (Number(l.total_days) || 0), 0);

      setUserLeaveDetailModal(prev => {
        if (!prev || prev.userId !== userId) return prev;
        return {
          ...prev,
          leavesHistory: combinedHistory,
          loadingHistory: false,
          historySummary: {
            approved: res?.summary?.approved ?? res?.data?.summary?.approved ?? approvedList.length,
            pending: res?.summary?.pending ?? res?.data?.summary?.pending ?? list.filter((l: any) => l.status === 'pending').length,
            rejected: res?.summary?.rejected ?? res?.data?.summary?.rejected ?? list.filter((l: any) => l.status === 'rejected').length,
            adjustments: adjustments.length,
            totalDays: totalDaysApproved,
          }
        };
      });
    } catch (err) {
      console.error('Failed to load leaves history for user', err);
      setUserLeaveDetailModal(prev => prev ? { ...prev, loadingHistory: false } : null);
    }
  };

  const handleResetUserDetailToOfficeDefaults = () => {
    if (!userLeaveDetailModal) return;
    const userProfile = {
      gender: userLeaveDetailModal.gender,
      maritalStatus: userLeaveDetailModal.maritalStatus,
      isPregnant: userLeaveDetailModal.isPregnant,
    };
    setUserLeaveDetailModal(prev => {
      if (!prev) return null;
      const updatedMap = { ...prev.balancesMap };
      Object.keys(updatedMap).forEach(key => {
        if (updatedMap[key].isOfficeDisabled) return; // Skip tipe yang disabled di kantor
        if (key === 'izin' && updatedMap[key].officeDefault <= 0) return; // Mode akumulasi tanpa limit kuota
        const elig = key === 'izin' ? { isEligible: true } : checkLeaveEligibility(key, userProfile);
        const def = elig.isEligible ? updatedMap[key].officeDefault : 0;
        const newRemaining = Math.max(0, def - updatedMap[key].used);
        updatedMap[key] = {
          ...updatedMap[key],
          quota: def,
          remaining: newRemaining,
        };
      });
      return {
        ...prev,
        balancesMap: updatedMap,
      };
    });
    onAddNotification('info', 'Diisi Sesuai Standar Kantor', 'Sisa saldo jenis cuti telah disesuaikan ke standar kantor cabang (dikurangi yang sudah terpakai).');
  };

  const handleSaveUserDetailBalances = async () => {
    if (!userLeaveDetailModal) return;
    setIsSavingUserDetailBalances(true);
    try {
      const balancesToUpdate: Array<{ leave_type: string; remaining: number }> = [];
      Object.entries(userLeaveDetailModal.balancesMap).forEach(([leaveType, data]: [string, any]) => {
        if (data.isOfficeDisabled) return; // Skip tipe yang disabled di kantor
        if (leaveType === 'izin' && data.officeDefault <= 0) return; // Mode akumulasi tanpa batasan kuota
        if (data.remaining !== userLeaveDetailModal.originalBalancesMap[leaveType]) {
          balancesToUpdate.push({
            leave_type: leaveType,
            remaining: Number(data.remaining),
          });
        }
      });

      if (balancesToUpdate.length === 0) {
        onAddNotification('info', 'Tidak Ada Perubahan', 'Tidak ada sisa saldo cuti yang diubah.');
        setIsSavingUserDetailBalances(false);
        return;
      }

      // Validasi: sisa saldo tidak boleh melebihi standar kantor
      for (const b of balancesToUpdate) {
        const itemData = userLeaveDetailModal.balancesMap[b.leave_type];
        const officeLimit = itemData?.officeDefault ?? 0;
        if (officeLimit > 0 && b.remaining > officeLimit) {
          onAddNotification('error', 'Validasi Gagal', `Sisa saldo ${getLeaveTypeLabel(b.leave_type)} (${b.remaining} hari) tidak boleh melebihi standar kantor (${officeLimit} hari).`);
          setIsSavingUserDetailBalances(false);
          return;
        }
      }

      await attendanceApi.setLeaveBalance({
        user_id: userLeaveDetailModal.userId,
        balances: balancesToUpdate,
      });

      onAddAuditLog(
        'Saldo Cuti Karyawan Diperbarui (Batch)',
        `${userLeaveDetailModal.userName}: sisa saldo ${balancesToUpdate.length} jenis cuti diperbarui`,
        'bg-indigo-600'
      );
      onAddNotification(
        'success',
        'Saldo Cuti Disimpan',
        `Berhasil menyimpan pembaruan sisa saldo cuti untuk ${userLeaveDetailModal.userName}.`
      );

      await loadBalances(true);

      // Muat ulang riwayat pengajuan & penyesuaian saldo agar penyesuaian yang baru tersimpan langsung muncul di tab riwayat
      try {
        const historyRes: any = await attendanceApi.leaves({ user_id: userLeaveDetailModal.userId, per_page: 500 }, true);
        const freshList = rows(historyRes);
        const freshAdjustments: any[] = Array.isArray(historyRes?.adjustments)
          ? historyRes.adjustments
          : (Array.isArray(historyRes?.data?.adjustments) ? historyRes.data.adjustments : []);
        const freshCombined = [
          ...freshList.map((l: any) => ({ ...l, is_adjustment: false })),
          ...freshAdjustments.map((a: any) => ({ ...a, is_adjustment: true, status: 'adjustment' })),
        ].sort((a: any, b: any) => new Date(b.created_at || 0).getTime() - new Date(a.created_at || 0).getTime());

        const freshApproved = freshList.filter((l: any) => l.status === 'approved');
        const freshTotalDays = freshApproved.reduce((acc: number, l: any) => acc + (Number(l.total_days) || 0), 0);

        setUserLeaveDetailModal(prev => {
          if (!prev) return null;
          const newOriginal = { ...prev.originalBalancesMap };
          balancesToUpdate.forEach(b => {
            newOriginal[b.leave_type] = b.remaining;
          });
          return {
            ...prev,
            originalBalancesMap: newOriginal,
            leavesHistory: freshCombined,
            historySummary: {
              approved: historyRes?.summary?.approved ?? historyRes?.data?.summary?.approved ?? freshApproved.length,
              pending: historyRes?.summary?.pending ?? historyRes?.data?.summary?.pending ?? freshList.filter((l: any) => l.status === 'pending').length,
              rejected: historyRes?.summary?.rejected ?? historyRes?.data?.summary?.rejected ?? freshList.filter((l: any) => l.status === 'rejected').length,
              adjustments: freshAdjustments.length,
              totalDays: freshTotalDays,
            }
          };
        });
      } catch {
        setUserLeaveDetailModal(prev => {
          if (!prev) return null;
          const newOriginal = { ...prev.originalBalancesMap };
          balancesToUpdate.forEach(b => {
            newOriginal[b.leave_type] = b.remaining;
          });
          return {
            ...prev,
            originalBalancesMap: newOriginal,
          };
        });
      }
    } catch (e) {
      reportApiError(e, 'Gagal menyimpan saldo cuti karyawan.');
    } finally {
      setIsSavingUserDetailBalances(false);
    }
  };

  const loadReport = useCallback(async (page = reportPage, forceRefresh = false) => {
    setLoading(true);
    setError(null);
    try {
      if (forceRefresh) invalidateCache('/dashboard/attendance/report');
      const f: any = { page, per_page: reportPageSize };
      if (reportFilter.start_date) f.start_date = reportFilter.start_date;
      if (reportFilter.end_date) f.end_date = reportFilter.end_date;
      if (reportFilter.status) f.status = reportFilter.status;
      if (reportFilter.type) f.type = reportFilter.type;
      if (reportFilter.search) f.search = reportFilter.search;
      if (reportFilter.office_id) f.office_id = reportFilter.office_id;
      if (reportFilter.shift_id) f.shift_id = reportFilter.shift_id;
      const res: any = await attendanceApi.report(f, forceRefresh);
      setReport(res);
      if (res?.available_shifts?.length) {
        setReportAvailableShifts(res.available_shifts);
      }
    } catch (e) {
      reportApiError(e, 'Gagal memuat laporan presensi.');
    } finally {
      setLoading(false);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [reportFilter, reportPage, reportPageSize]);

  const loadHolidays = useCallback(async (forceRefresh = false) => {
    setLoading(true);
    setError(null);
    try {
      if (forceRefresh) invalidateCache('/dashboard/attendance/holidays');
      const res: any = await attendanceApi.holidays.list(holidayYear, forceRefresh);
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
      } else if (balanceSubTab === 'history') {
        loadBalanceHistories();
      } else if (balanceSubTab === 'leave_types') {
        loadLeaveTypeSettings();
      }
    }
    else if (tab === 'report') loadReport(reportPage);
    else if (tab === 'holidays') {
      loadHolidays();
      loadLeaves();
      if (users.length === 0) {
        attendanceApi.users({ per_page: 300 }).then(res => setUsers(rows(res))).catch(() => {});
      }
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [tab, balanceSubTab, reportFilter, reportPage, reportPageSize, holidayYear, loadBalanceHistories, loadLeaveTypeSettings]);

  // Ketentuan 3: Auto-refresh data jika server mendeteksi data baru masuk dari mobile/backend
  useEffect(() => {
    const unsubscribe = onDataVersionChange((changedModules) => {
      if (changedModules.includes('leaves') && tab === 'leaves') {
        loadLeaves(true);
      } else if (changedModules.includes('attendance') && tab === 'today') {
        loadToday(true);
      }
    });
    return unsubscribe;
  }, [tab, loadLeaves, loadToday]);

  // ─── Aksi ─────────────────────────────────────────────────
  const handleApproveLeave = async (id: number, name: string) => {
    try {
      const res: any = await attendanceApi.approveLeave(id);
      invalidateCache('/dashboard/attendance/leaves');
      invalidateCache('/dashboard/attendance/today');
      invalidateCache('/dashboard/attendance/users');
      invalidateCache('/dashboard/notifications');
      const isSpvStep = res?.leave?.current_step === 'hrd' && res?.leave?.status === 'pending';
      const msg = isSpvStep
        ? `Pengajuan #${id} (${name}) disetujui Atasan (SPV) dan diteruskan ke HRD.`
        : `Pengajuan #${id} (${name}) telah disetujui sepenuhnya oleh HRD.`;
      onAddAuditLog('Izin/Cuti Disetujui', msg, 'bg-emerald-600');
      onAddNotification('success', 'Pengajuan Disetujui', msg);
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
      invalidateCache('/dashboard/attendance/leaves');
      invalidateCache('/dashboard/attendance/today');
      invalidateCache('/dashboard/attendance/users');
      invalidateCache('/dashboard/notifications');
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
    const targetUser = users.find(u => u.id === id);
    if (targetUser?.allow_attendance === false) {
      setError(`Mode WFH terkunci untuk ${name} karena Akses Presensi Mobile dinonaktifkan di Edit Profil Karyawan. Aktifkan kembali di Edit Profil Karyawan.`);
      return;
    }
    if (targetUser?.allow_wfh === false) {
      setError(`Mode WFH terkunci untuk ${name} karena Izinkan Presensi WFH dinonaktifkan di Edit Profil Karyawan. Aktifkan kembali di Edit Profil Karyawan.`);
      return;
    }
    if (!targetUser?.attendance_enabled) {
      setError(`Mode WFH tidak dapat diaktifkan untuk ${name} karena Presensi Mobile sedang nonaktif. Aktifkan Presensi Mobile terlebih dahulu.`);
      return;
    }
    if (targetUser?.dinas_luar_enabled) {
      setError(`Mode WFH tidak dapat dinonaktifkan untuk ${name} karena izin Dinas Luar sedang aktif. Nonaktifkan Dinas Luar terlebih dahulu.`);
      return;
    }
    try {
      const res: any = await attendanceApi.toggleWfh(id);
      invalidateCache('/dashboard/attendance/users');
      invalidateCache('/admin/users');
      const on = res?.user?.wfh_enabled;
      onAddAuditLog('Mode WFH Diubah', `Mode WFH ${name} ${on ? 'diaktifkan' : 'dinonaktifkan'}`, on ? 'bg-emerald-600' : 'bg-slate-600');
      setUsers(prev => prev.map(u => u.id === id ? {
        ...u,
        ...(res?.user || {}),
      } : u));
    } catch (e) {
      reportApiError(e, 'Gagal mengubah mode WFH.');
    }
  };

  const handleToggleRadius = async (id: number, name: string) => {
    const targetUser = users.find(u => u.id === id);
    if (targetUser?.allow_attendance === false) {
      setError(`Switch Lapangan terkunci untuk ${name} karena Akses Presensi Mobile dinonaktifkan di Edit Profil Karyawan. Aktifkan kembali di Edit Profil Karyawan.`);
      return;
    }
    if (targetUser?.allow_wfh === false) {
      setError(`Switch Lapangan terkunci untuk ${name} karena Izinkan Presensi WFH dinonaktifkan di Edit Profil Karyawan. Aktifkan kembali di Edit Profil Karyawan.`);
      return;
    }
    if (targetUser?.allow_radius === false) {
      setError(`Switch Lapangan terkunci untuk ${name} karena Validasi Radius Geofence dinonaktifkan di Edit Profil Karyawan. Aktifkan kembali di Edit Profil Karyawan.`);
      return;
    }
    if (!targetUser?.attendance_enabled) {
      setError(`Switch Lapangan tidak dapat diubah untuk ${name} karena Presensi Mobile sedang nonaktif.`);
      return;
    }
    if (!targetUser?.wfh_enabled) {
      setError(`Switch Lapangan tidak dapat diubah untuk ${name} karena Mode WFH sedang nonaktif.`);
      return;
    }
    if (targetUser?.dinas_luar_enabled) {
      setError(`Radius lapangan tidak dapat diaktifkan untuk ${name} karena karyawan dalam mode Dinas Luar (bebas radius).`);
      return;
    }
    try {
      const res: any = await attendanceApi.toggleRadius(id);
      invalidateCache('/dashboard/attendance/users');
      invalidateCache('/admin/users');
      const on = res?.user?.radius_enabled;
      onAddAuditLog('Radius Lapangan Diubah', `Radius ${name} ${on ? 'diaktifkan (lapangan)' : 'dinonaktifkan (WFH bebas)'}`, on ? 'bg-amber-600' : 'bg-slate-600');
      setUsers(prev => prev.map(u => u.id === id ? {
        ...u,
        ...(res?.user || {}),
      } : u));
    } catch (e) {
      reportApiError(e, 'Gagal mengubah radius lapangan.');
    }
  };

  const handleToggleDinasLuar = async (id: number, name: string) => {
    const targetUser = users.find(u => u.id === id);
    if (targetUser?.allow_attendance === false) {
      setError(`Izin Dinas Luar terkunci untuk ${name} karena Akses Presensi Mobile dinonaktifkan di Edit Profil Karyawan. Aktifkan kembali di Edit Profil Karyawan.`);
      return;
    }
    if (!targetUser?.attendance_enabled && !targetUser?.dinas_luar_enabled) {
      setError(`Izin Dinas Luar tidak dapat diaktifkan untuk ${name} karena Presensi Mobile sedang nonaktif. Aktifkan Presensi Mobile terlebih dahulu.`);
      return;
    }
    try {
      const res: any = await attendanceApi.toggleDinasLuar(id);
      invalidateCache('/dashboard/attendance/users');
      invalidateCache('/admin/users');
      const on = res?.user?.dinas_luar_enabled;
      onAddAuditLog('Izin Dinas Luar Diubah', `Dinas luar / kunjungan klien ${name} ${on ? 'diaktifkan' : 'dinonaktifkan'}`, on ? 'bg-indigo-600' : 'bg-slate-600');
      setUsers(prev => prev.map(u => u.id === id ? {
        ...u,
        ...(res?.user || {}),
      } : u));
    } catch (e) {
      reportApiError(e, 'Gagal mengubah izin dinas luar.');
    }
  };

  const handleToggleFlexitime = async (id: number, name: string) => {
    try {
      const res: any = await attendanceApi.toggleFlexitime(id);
      invalidateCache('/dashboard/attendance/users');
      invalidateCache('/admin/users');
      const on = res?.user?.flexitime_enabled;
      onAddAuditLog('Jam Fleksibel Diubah', `Jam fleksibel (flexitime) ${name} ${on ? 'diaktifkan' : 'dinonaktifkan'}`, on ? 'bg-teal-600' : 'bg-slate-600');
      setUsers(prev => prev.map(u => u.id === id ? {
        ...u,
        flexitime_enabled: res?.user?.flexitime_enabled ?? !u.flexitime_enabled,
      } : u));
    } catch (e) {
      reportApiError(e, 'Gagal mengubah jam kerja fleksibel.');
    }
  };

  const openVisitDetailModal = async (record: any) => {
    const attId = record.attendance_id || record.id;
    setVisitDetailModal({
      attendanceId: attId,
      userName: record.name || record.user_name || 'Karyawan',
      clientName: record.client_name || 'Kunjungan Klien',
      clientAddress: record.client_address,
      visitNotes: record.visit_notes,
      checkInLat: record.check_in_lat,
      checkInLng: record.check_in_lng,
      checkInTime: record.check_in_time,
      photoUrl: null,
      loadingPhoto: true,
    });

    if (attId) {
      const url = await attendanceApi.attendancePhotoUrl(attId);
      setVisitDetailModal(prev => prev && prev.attendanceId === attId ? {
        ...prev,
        photoUrl: url,
        loadingPhoto: false,
      } : prev);
    } else {
      setVisitDetailModal(prev => prev ? { ...prev, loadingPhoto: false } : null);
    }
  };

  const closeVisitDetailModal = () => {
    if (visitDetailModal?.photoUrl) {
      URL.revokeObjectURL(visitDetailModal.photoUrl);
    }
    setVisitDetailModal(null);
  };

  const handleToggleCutiQuota = async (
    userId: number,
    userName: string,
    currentQuota: number,
    refQuota = 12,
    isCurrentlyActive = true
  ) => {
    if (togglingUserId === userId) return;
    const willBeActive = !isCurrentlyActive;
    const targetQuota = currentQuota > 0 ? currentQuota : (refQuota > 0 ? refQuota : 12);
    setTogglingUserId(userId);

    // Simpan snapshot untuk rollback jika terjadi kesalahan
    const previousBalances = [...balances];

    // Optimistic update: langsung mutasi state balances secara halus di memori tanpa memicu loading skeleton / refresh
    setBalances(prev => {
      let hasCuti = false;
      let userRef: any = null;
      const updated = prev.map(b => {
        if (b.user_id === userId) {
          userRef = b;
          if (b.leave_type === 'cuti') {
            hasCuti = true;
            const newQuota = willBeActive ? (b.quota > 0 ? b.quota : targetQuota) : (b.quota > 0 ? b.quota : 0);
            return {
              ...b,
              allow_leave: willBeActive,
              quota: newQuota,
              remaining: Math.max(0, newQuota - (b.used ?? 0)),
              active: willBeActive,
            };
          }
          return {
            ...b,
            allow_leave: willBeActive,
          };
        }
        return b;
      });

      if (!hasCuti && willBeActive && userRef) {
        updated.push({
          ...userRef,
          id: -Date.now(),
          leave_type: 'cuti',
          quota: targetQuota,
          used: 0,
          remaining: targetQuota,
          active: true,
          allow_leave: true,
        });
      }

      return updated;
    });

    try {
      if (!willBeActive) {
        // Menonaktifkan hak cuti:
        // Set allow_leave: false. Kuota lama dipertahankan agar tidak terjadi saldo negatif/rusak.
        await attendanceApi.setLeaveBalance({
          user_id: userId,
          leave_type: 'cuti',
          quota: currentQuota > 0 ? currentQuota : 0,
          allow_leave: false,
        });
      } else {
        // Mengaktifkan kembali hak cuti:
        await attendanceApi.setLeaveBalance({
          user_id: userId,
          leave_type: 'cuti',
          quota: targetQuota,
          allow_leave: true,
        });
      }

      // Invalidate cache
      invalidateCache('/dashboard/attendance/leave-balances');
      invalidateCache('/admin/users');
      invalidateCache('/dashboard/attendance/users');

      onAddAuditLog(
        'Hak Cuti Diubah',
        `${userName}: seluruh hak cuti ${willBeActive ? 'diaktifkan kembali' : 'dinonaktifkan (karyawan hanya dapat mengajukan Izin & WFH)'}`,
        willBeActive ? 'bg-teal-600' : 'bg-slate-600'
      );

      // Sinkronisasi data di latar belakang tanpa memicu loading skeleton / flicker (smooth seperti switch WFH)
      attendanceApi.leaveBalances(undefined, true).then(res => {
        if (res?.balances) {
          setBalances(res.balances);
        }
      }).catch(() => {});

      // Perbarui juga data allUsers agar status leave_active userOptions langsung sinkron
      attendanceApi.allUsers(true).catch(() => {});
    } catch (e) {
      // Rollback ke state sebelumnya jika API gagal
      setBalances(previousBalances);
      reportApiError(e, 'Gagal mengubah status hak cuti.');
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
      if (reportFilter.shift_id) f.shift_id = reportFilter.shift_id;
      await attendanceApi.exportReport(f);
      onAddAuditLog('Export Laporan Presensi', 'Mengunduh laporan presensi (CSV)', 'bg-indigo-600');
    } catch (e) {
      reportApiError(e, 'Gagal mengekspor laporan.');
    }
  };

  // ─── Render helpers ───────────────────────────────────────
  const SummaryCard = ({ label, value, color, badge }: { label: string; value: number | string; color: string; badge?: React.ReactNode }) => (
    <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl p-4 shadow-xs">
      <div className="flex items-center justify-between">
        <p className="text-[11px] text-slate-400 font-semibold uppercase tracking-wider">{label}</p>
        {badge}
      </div>
      <p className={`text-2xl font-bold mt-1 ${color}`}>{value}</p>
    </div>
  );

  // Kalkulasi ringkasan presensi hari ini dinamis berdasarkan cabang terpilih (todayOfficeFilter)
  const todaySummaryCounts = useMemo(() => {
    if (!today) {
      return { total: 0, checkedIn: 0, notCheckedIn: 0, offToday: 0, onLeave: 0, alpha: 0 };
    }

    const matchOffice = (p: any) => {
      if (!todayOfficeFilter) return true;
      if (todayOfficeFilter === 'null') return !p.attendance_setting_id;
      return String(p.attendance_setting_id) === todayOfficeFilter;
    };

    const checkedInList = (today.checked_in ?? []).filter(matchOffice);
    const onLeaveList = (today.on_leave ?? []).filter(matchOffice);
    const notCheckedInAll = (today.not_checked_in ?? []).filter(matchOffice);

    const offTodayList = notCheckedInAll.filter((p: any) => Boolean(p.is_off));
    const workNotCheckedIn = notCheckedInAll.filter((p: any) => !p.is_off);
    const alphaList = workNotCheckedIn.filter((p: any) => Boolean(p.is_alpha || p.status === 'alpha'));

    const totalEmployees = checkedInList.length + onLeaveList.length + notCheckedInAll.length;

    return {
      total: totalEmployees,
      checkedIn: checkedInList.length,
      notCheckedIn: workNotCheckedIn.length,
      offToday: offTodayList.length,
      onLeave: onLeaveList.length,
      alpha: alphaList.length,
    };
  }, [today, todayOfficeFilter]);

  const pendingHrdCount = useMemo(() => {
    const todayStr = new Date().toLocaleDateString('en-CA');
    return leaves.filter((l: any) =>
      l.status === 'pending' &&
      l.current_step === 'hrd' &&
      l.holiday_id == null &&
      ((l.start_date ?? '').slice(0, 10) > todayStr)
    ).length;
  }, [leaves]);

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
              {key === 'leaves' && pendingHrdCount > 0 && (
                <span className="ml-1.5 px-1.5 py-0.5 text-[10px] font-bold rounded-full bg-amber-500 text-white leading-none">
                  {pendingHrdCount}
                </span>
              )}
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
              if (tab === 'today') loadToday(true);
              else if (tab === 'leaves') loadLeaves(true);
              else if (tab === 'users') loadUsers(true);
              else if (tab === 'balances') {
                if (balanceSubTab === 'active') loadBalances(true);
                else if (balanceSubTab === 'history') loadBalanceHistories(true);
                else if (balanceSubTab === 'leave_types') loadLeaveTypeSettings(leaveTypeOfficeId, true);
              }
              else if (tab === 'report') loadReport(reportPage, true);
              else if (tab === 'holidays') {
                loadHolidays(true);
                loadLeaves(true);
              }
            }}
            disabled={loading || balanceHistoryLoading || rateLimitCountdown > 0}
            className="flex items-center justify-center gap-1.5 px-3 py-2 bg-indigo-50 dark:bg-indigo-900/30 border border-indigo-100 dark:border-indigo-800 text-indigo-600 dark:text-indigo-400 hover:bg-indigo-100 dark:hover:bg-indigo-900/50 rounded-xl text-xs font-bold transition shrink-0 disabled:opacity-50"
            title={rateLimitCountdown > 0 ? `Tunggu ${rateLimitCountdown} detik` : "Refresh Data"}
          >
            {rateLimitCountdown > 0 ? (
              <>
                <Clock className="w-3.5 h-3.5 animate-spin text-rose-500" style={{ animationDuration: '3s' }} />
                <span>Tunggu ({rateLimitCountdown}s)</span>
              </>
            ) : (
              <>
                <RefreshCw className={`w-3.5 h-3.5 ${(loading || balanceHistoryLoading) ? 'animate-spin' : ''}`} />
                <span className="hidden sm:inline">Refresh</span>
              </>
            )}
          </button>
        </div>
      </div>

      {error && (
        <div className="flex items-center justify-between gap-3 bg-rose-50 dark:bg-rose-950/40 border border-rose-200 dark:border-rose-900/60 text-rose-700 dark:text-rose-300 rounded-xl px-4 py-3 text-xs shadow-sm transition-all duration-200">
          <span className="flex items-center gap-2 font-medium">
            <AlertCircle className="w-4 h-4 shrink-0 text-rose-500 dark:text-rose-400" />
            {error}
          </span>
          {rateLimitCountdown > 0 && (
            <span className="shrink-0 flex items-center gap-1.5 font-mono font-bold bg-rose-200/80 dark:bg-rose-900/80 text-rose-800 dark:text-rose-200 px-2.5 py-1 rounded-lg text-xs shadow-inner">
              <Clock className="w-3.5 h-3.5 text-rose-600 dark:text-rose-300 animate-spin" style={{ animationDuration: '3s' }} />
              {rateLimitCountdown} detik
            </span>
          )}
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
                  {offices.length !== 1 && (
                    <option value="" className="bg-white dark:bg-slate-800 text-slate-900 dark:text-slate-100">Semua Kantor</option>
                  )}
                  {offices.map(o => (
                    <option key={o.id} value={String(o.id)} className="bg-white dark:bg-slate-800 text-slate-900 dark:text-slate-100">{o.office_name}</option>
                  ))}
                  {offices.length !== 1 && (
                    <option value="null" className="bg-white dark:bg-slate-800 text-slate-900 dark:text-slate-100">Tanpa Kantor</option>
                  )}
                </select>
              </div>
            </div>

            <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
              <SummaryCard
                label="Total Karyawan"
                value={todaySummaryCounts.total}
                color="text-slate-800 dark:text-white"
                badge={<Users className="w-3.5 h-3.5 text-slate-400" />}
              />
              <SummaryCard
                label="Sudah Check-in"
                value={todaySummaryCounts.checkedIn}
                color="text-emerald-600 dark:text-emerald-400"
                badge={<CheckCircle2 className="w-3.5 h-3.5 text-emerald-500" />}
              />
              <SummaryCard
                label="Belum Check-in"
                value={todaySummaryCounts.notCheckedIn}
                color="text-rose-600 dark:text-rose-400"
                badge={<Clock className="w-3.5 h-3.5 text-rose-500" />}
              />
              <SummaryCard
                label="Sedang Libur"
                value={todaySummaryCounts.offToday}
                color="text-indigo-600 dark:text-indigo-400"
                badge={<CalendarDays className="w-3.5 h-3.5 text-indigo-500" />}
              />
              <SummaryCard
                label="Izin / Cuti"
                value={todaySummaryCounts.onLeave}
                color="text-amber-600 dark:text-amber-400"
                badge={<ClipboardList className="w-3.5 h-3.5 text-amber-500" />}
              />
              <SummaryCard
                label="Alpha"
                value={todaySummaryCounts.alpha}
                color="text-rose-600 dark:text-rose-500"
                badge={<AlertCircle className="w-3.5 h-3.5 text-rose-500" />}
              />
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
                                    {p.check_in_type === 'dinas_luar' && p.client_name && (
                                      <span className="ml-1 text-emerald-600 dark:text-emerald-400 font-semibold">· Klien: {p.client_name}</span>
                                    )}
                                  </p>
                                </div>
                                <div className="flex items-center gap-1.5 shrink-0">
                                  {p.check_in_type === 'wfh' && <Home className="w-3 h-3 text-indigo-500" />}
                                  {p.check_in_type === 'field' && <MapPin className="w-3 h-3 text-amber-500" />}
                                  {p.check_in_type === 'dinas_luar' && (
                                    <button
                                      type="button"
                                      onClick={() => openVisitDetailModal(p)}
                                      className="inline-flex items-center gap-1 text-[10px] font-bold px-1.5 py-0.5 rounded bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800 hover:bg-emerald-100 transition cursor-pointer"
                                      title="Lihat Detail Kunjungan Dinas Luar"
                                    >
                                      <Briefcase className="w-3 h-3 text-emerald-600 dark:text-emerald-400" />
                                      <span>Dinas Luar</span>
                                    </button>
                                  )}
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
                      <div className="flex items-center justify-between">
                        <h4 className="text-xs font-bold text-rose-700 dark:text-rose-400 flex items-center gap-1.5">
                          <Clock className="w-4 h-4" /> Belum Check-in ({notCheckedIn.length})
                        </h4>
                        {(() => {
                          const alphaCount = notCheckedIn.filter((p: any) => p.is_alpha || p.status === 'alpha').length;
                          return alphaCount > 0 ? (
                            <span className="text-[10px] font-bold px-1.5 py-0.5 rounded bg-rose-100 text-rose-700 dark:bg-rose-950/60 dark:text-rose-400 border border-rose-200 dark:border-rose-900/40">
                              {alphaCount} Alpha
                            </span>
                          ) : null;
                        })()}
                      </div>
                      <CardSearch value={searchNotCheckedIn} onChange={setSearchNotCheckedIn} />
                      <div className="space-y-2 flex-1 overflow-y-auto max-h-80 pr-1">
                        {notCheckedIn.length === 0 ? (
                          <p className="text-[11px] text-slate-400 py-3 text-center">{searchNotCheckedIn || todayOfficeFilter ? 'Tidak ditemukan.' : 'Semua sudah hadir.'}</p>
                        ) : (
                          <>
                            {notCheckedIn.slice(0, todayColumnLimit.notCheckedIn || 40).map((p: any) => {
                              const isAlpha = p.is_alpha || p.status === 'alpha';
                              return (
                                <div key={p.user_id} className="flex items-center justify-between border-b border-slate-50 dark:border-slate-800/60 pb-2">
                                  <div className="min-w-0">
                                    <p className="text-xs font-semibold text-slate-800 dark:text-slate-200 truncate flex items-center gap-1">
                                      {p.name}
                                    </p>
                                    <p className="text-[10px] text-slate-400">
                                      {p.employee_code && <span className="font-mono">{p.employee_code} · </span>}
                                      {p.department ?? '—'}
                                      {p.cutoff_time && (
                                        <span className="ml-1">· Batas {p.cutoff_time}</span>
                                      )}
                                    </p>
                                  </div>
                                  <div className="flex items-center gap-1.5 shrink-0 ml-2">
                                    {p.is_wfh && (
                                      <span
                                        className="text-[9px] font-bold px-1.5 py-0.5 rounded bg-blue-50 text-blue-700 dark:bg-blue-950/40 dark:text-blue-400 border border-blue-200 dark:border-blue-900/40 flex items-center gap-1"
                                        title={p.is_wfh_approved ? 'Pengajuan WFH disetujui HRD hari ini' : 'Mode WFH aktif'}
                                      >
                                        <Home className="w-2.5 h-2.5" />
                                        WFH
                                      </span>
                                    )}
                                    {isAlpha && (
                                      <span
                                        className="text-[9px] font-bold px-2 py-0.5 rounded bg-rose-50 text-rose-700 dark:bg-rose-950/40 dark:text-rose-400 border border-rose-200 dark:border-rose-900/40 shrink-0"
                                        title={p.cutoff_time ? `Melewati batas waktu presensi (${p.cutoff_time} WIB)` : 'Alpha'}
                                      >
                                        Alpha
                                      </span>
                                    )}
                                  </div>
                                </div>
                              );
                            })}
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
          const todayStr = new Date().toLocaleDateString('en-CA');

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
              if (leaveStatus === 'pending') {
                // Hanya pengajuan yang masih pending dan belum memasuki hari H
                result = result.filter((l: any) => l.status === 'pending' && ((l.start_date ?? '').slice(0, 10) > todayStr));
                if (leaveStepFilter !== 'all') {
                  result = result.filter((l: any) => (l.current_step || 'spv') === leaveStepFilter);
                }
              } else if (leaveStatus === 'rejected') {
                // Pengajuan yang ditolak atau pending yang sudah hari H
                result = result.filter((l: any) => l.status === 'rejected' || (l.status === 'pending' && (l.start_date ?? '').slice(0, 10) <= todayStr));
                if (leaveStepFilter !== 'all') {
                  result = result.filter((l: any) => (l.current_step || 'spv') === leaveStepFilter);
                }
              } else if (leaveStatus === 'approved') {
                result = result.filter((l: any) => l.status === 'approved');
              } else if (leaveStatus) {
                result = result.filter((l: any) => l.status === leaveStatus);
              } else {
                if (leaveStepFilter !== 'all') {
                  result = result.filter((l: any) => (l.current_step || 'spv') === leaveStepFilter);
                }
              }
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

          const leaveTypeLabel = (t: string) => getLeaveTypeLabel(t);

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
                    <option value="" className="bg-white dark:bg-slate-800 text-slate-900 dark:text-slate-100">Semua Status</option>
                    <option value="pending" className="bg-white dark:bg-slate-800 text-slate-900 dark:text-slate-100">Menunggu</option>
                    <option value="approved" className="bg-white dark:bg-slate-800 text-slate-900 dark:text-slate-100">Disetujui</option>
                    <option value="rejected" className="bg-white dark:bg-slate-800 text-slate-900 dark:text-slate-100">Ditolak</option>
                  </select>

                  {/* Dropdown tipe */}
                  <select
                    value={showUpcoming ? '' : leaveTypeFilter}
                    disabled={showUpcoming}
                    onChange={(e) => setLeaveTypeFilter(e.target.value)}
                    className="px-3 py-1.5 rounded-lg text-[11px] font-semibold border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 focus:outline-none focus:ring-1 focus:ring-indigo-400 disabled:opacity-50 disabled:cursor-not-allowed"
                  >
                    <option value="" className="bg-white dark:bg-slate-800 text-slate-900 dark:text-slate-100">Semua Tipe Cuti &amp; Izin</option>
                    <optgroup label="Tipe Standar" className="bg-white dark:bg-slate-800 text-slate-900 dark:text-slate-100">
                      <option value="cuti">Cuti Tahunan</option>
                      <option value="izin">Izin</option>
                      <option value="sakit">Sakit</option>
                      <option value="wfh">WFH</option>
                    </optgroup>
                    <optgroup label="Regulasi UU &amp; Khusus" className="bg-white dark:bg-slate-800 text-slate-900 dark:text-slate-100">
                      <option value="cuti_hamil">Cuti Hamil &amp; Melahirkan</option>
                      <option value="cuti_keguguran">Cuti Keguguran</option>
                      <option value="cuti_ayah">Cuti Ayah (Istri Melahirkan)</option>
                      <option value="cuti_haid">Cuti Haid</option>
                      <option value="cuti_menikah">Cuti Menikah</option>
                      <option value="cuti_menikahkan_anak">Menikahkan Anak</option>
                      <option value="cuti_khitan_baptis_anak">Mengkhitankan / Membaptiskan Anak</option>
                      <option value="cuti_duka_keluarga_inti">Duka Keluarga Inti</option>
                      <option value="cuti_duka_serumah">Duka Anggota Serumah</option>
                      <option value="cuti_ibadah_haji_umrah">Ibadah Keagamaan (Haji / Umrah)</option>
                      <option value="cuti_setengah_hari">Cuti Setengah Hari</option>
                    </optgroup>
                  </select>

                  {/* Dropdown tahap persetujuan */}
                  <select
                    value={showUpcoming || leaveStatus === 'approved' ? 'all' : leaveStepFilter}
                    disabled={showUpcoming || leaveStatus === 'approved'}
                    onChange={(e) => setLeaveStepFilter(e.target.value as any)}
                    className="px-3 py-1.5 rounded-lg text-[11px] font-semibold border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 focus:outline-none focus:ring-1 focus:ring-indigo-400 disabled:opacity-50 disabled:cursor-not-allowed"
                    title={leaveStatus === 'approved' ? 'Semua cuti disetujui telah selesai tahap persetujuan' : 'Filter tahap persetujuan cuti'}
                  >
                    <option value="hrd" className="bg-white dark:bg-slate-800 text-slate-900 dark:text-slate-100">Tahap 2: Menunggu HRD (Siap Diproses)</option>
                    <option value="spv" className="bg-white dark:bg-slate-800 text-slate-900 dark:text-slate-100">Tahap 1: Menunggu SPV (Pantau)</option>
                    <option value="all" className="bg-white dark:bg-slate-800 text-slate-900 dark:text-slate-100">Semua Tahap</option>
                  </select>

                  {/* Dropdown sumber cuti */}
                  <select
                    value={showUpcoming ? 'all' : leaveSourceFilter}
                    disabled={showUpcoming}
                    onChange={(e) => setLeaveSourceFilter(e.target.value as any)}
                    className="px-3 py-1.5 rounded-lg text-[11px] font-semibold border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 focus:outline-none focus:ring-1 focus:ring-indigo-400 disabled:opacity-50 disabled:cursor-not-allowed"
                  >
                    <option value="all" className="bg-white dark:bg-slate-800 text-slate-900 dark:text-slate-100">Semua Sumber</option>
                    <option value="mandiri" className="bg-white dark:bg-slate-800 text-slate-900 dark:text-slate-100">Mandiri (Mobile)</option>
                    <option value="collective" className="bg-white dark:bg-slate-800 text-slate-900 dark:text-slate-100">Cuti Bersama</option>
                  </select>

                  {/* Dropdown kantor cabang */}
                  {offices.length > 0 && (
                    <select
                      value={leaveOfficeFilter}
                      onChange={(e) => setLeaveOfficeFilter(e.target.value)}
                      className="px-3 py-1.5 rounded-lg text-[11px] font-semibold border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 focus:outline-none focus:ring-1 focus:ring-indigo-400"
                    >
                      {offices.length !== 1 && (
                        <option value="" className="bg-white dark:bg-slate-800 text-slate-900 dark:text-slate-100">Semua Kantor</option>
                      )}
                      {offices.map((o: any) => (
                        <option key={o.id} value={String(o.id)} className="bg-white dark:bg-slate-800 text-slate-900 dark:text-slate-100">{o.office_name}</option>
                      ))}
                      {offices.length !== 1 && (
                        <option value="null" className="bg-white dark:bg-slate-800 text-slate-900 dark:text-slate-100">Tanpa Kantor</option>
                      )}
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
                    className="pl-8 pr-3 py-1.5 text-xs border border-slate-200 dark:border-slate-700 rounded-lg bg-slate-50 dark:bg-slate-800 text-slate-800 dark:text-slate-100 placeholder-slate-400 focus:outline-none focus:ring-1 focus:ring-indigo-400 w-full sm:w-48"
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
                    <tr className="border-b border-slate-100 dark:border-slate-800 text-slate-500 dark:text-slate-400">
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
                              <div className="flex flex-wrap items-center gap-1.5 text-[10px] text-slate-400">
                                <span>{l.department ?? '—'}</span>
                                {l.manager_name && (
                                  <>
                                    <span>•</span>
                                    <span className="text-indigo-600 dark:text-indigo-400 font-medium">Atasan: {l.manager_name}</span>
                                  </>
                                )}
                              </div>
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
                              {l.holiday_id != null ? (
                                // Cuti Bersama: murni keputusan staf biasa (tidak ada approval berjenjang Lv 1 SPV maupun Lv 2 HRD)
                                l.collective_status === 'accepted' || l.status === 'approved' ? (
                                  <span className="text-[9px] font-bold px-2 py-0.5 rounded bg-emerald-50 dark:bg-emerald-950/30 text-emerald-700 dark:text-emerald-400 border border-emerald-200 dark:border-emerald-800">
                                    Ikut (Disetujui)
                                  </span>
                                ) : l.collective_status === 'declined' || l.status === 'rejected' ? (
                                  <span className="text-[9px] font-bold px-2 py-0.5 rounded bg-rose-50 dark:bg-rose-950/30 text-rose-600 dark:text-rose-400 border border-rose-200 dark:border-rose-800">
                                    Tidak Ikut
                                  </span>
                                ) : (
                                  <span className="text-[9px] font-bold px-2 py-0.5 rounded bg-blue-50 dark:bg-blue-950/30 text-blue-700 dark:text-blue-400 border border-blue-200 dark:border-blue-800" title="Menunggu staf menentukan pilihan ikut atau tidak via aplikasi mobile">
                                    Menunggu Pilihan Staf
                                  </span>
                                )
                              ) : l.status === 'pending' && ((l.start_date ?? '').slice(0, 10) <= todayStr) ? (
                                <span className="text-[9px] font-bold px-2 py-0.5 rounded bg-rose-50 dark:bg-rose-950/30 text-rose-600 dark:text-rose-400" title="Tidak ada aksi approval dari HRD hingga hari H — otomatis ditolak sistem">
                                  Ditolak (Hari H)
                                </span>
                              ) : l.status === 'pending' ? (
                                <div className="inline-flex flex-col items-center gap-0.5">
                                  {l.current_step === 'hrd' ? (
                                    <span className="text-[9px] font-bold px-2 py-0.5 rounded bg-sky-50 dark:bg-sky-950/40 text-sky-700 dark:text-sky-300 border border-sky-200 dark:border-sky-800" title="Telah disetujui SPV, menunggu persetujuan HRD">
                                      Tahap 2: HRD
                                    </span>
                                  ) : (
                                    <span className="text-[9px] font-bold px-2 py-0.5 rounded bg-amber-50 dark:bg-amber-950/40 text-amber-700 dark:text-amber-300 border border-amber-200 dark:border-amber-800" title="Menunggu persetujuan Atasan Langsung (SPV)">
                                      Tahap 1: SPV
                                    </span>
                                  )}
                                  {l.spv_name && (
                                    <span className="text-[9px] text-slate-400 max-w-[110px] truncate" title={`Disetujui SPV: ${l.spv_name}${l.spv_notes ? ` (${l.spv_notes})` : ''}`}>
                                      SPV: {l.spv_name}
                                    </span>
                                  )}
                                </div>
                              ) : (
                                <span className={`text-[9px] font-bold px-2 py-0.5 rounded ${leaveBadge(l.status)}`}>
                                  {l.status === 'approved' ? 'Disetujui' : 'Ditolak'}
                                </span>
                              )}
                            </td>
                            <td className="py-2.5 px-2 text-right">
                              {l.status === 'pending' && ((l.start_date ?? '').slice(0, 10) <= todayStr) ? (
                                <span className="text-[10px] text-rose-500 italic">
                                  Otomatis ditolak (Hari H)
                                </span>
                              ) : l.status === 'pending' && l.holiday_id == null ? (
                                l.current_step === 'hrd' ? (
                                  // Cuti mandiri pending Tahap 2: siap disetujui / ditolak oleh HRD
                                  <div className="flex justify-end gap-1.5">
                                    <button
                                      onClick={() => handleApproveLeave(l.id, l.user_name)}
                                      className="p-1.5 rounded-lg bg-emerald-50 text-emerald-600 hover:bg-emerald-100 cursor-pointer transition"
                                      title="Setujui Final (HRD)"
                                    >
                                      <Check className="w-3.5 h-3.5" />
                                    </button>
                                    <button
                                      onClick={() => handleRejectLeave(l.id, l.user_name)}
                                      className="p-1.5 rounded-lg bg-rose-50 text-rose-600 hover:bg-rose-100 cursor-pointer transition"
                                      title="Tolak (HRD)"
                                    >
                                      <X className="w-3.5 h-3.5" />
                                    </button>
                                  </div>
                                ) : (
                                  // Cuti mandiri pending Tahap 1: masih menunggu persetujuan SPV
                                  <span
                                    className="text-[10px] text-amber-600 dark:text-amber-400 font-medium italic"
                                    title="Pengajuan ini masih menunggu persetujuan Atasan Langsung (SPV). Setelah disetujui SPV, tombol persetujuan HRD akan aktif."
                                  >
                                    Menunggu SPV
                                  </span>
                                )
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
                        className="py-0.5 px-2 text-xs font-semibold border border-slate-200 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 focus:outline-none cursor-pointer"
                      >
                        <option value={25} className="bg-white dark:bg-slate-800 text-slate-900 dark:text-slate-100">25</option>
                        <option value={50} className="bg-white dark:bg-slate-800 text-slate-900 dark:text-slate-100">50</option>
                        <option value={100} className="bg-white dark:bg-slate-800 text-slate-900 dark:text-slate-100">100</option>
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
              <Smartphone className="w-4 h-4 shrink-0 mt-0.5 text-indigo-600" />
              <span><strong>Kontrol Presensi Karyawan:</strong> Hak akses Presensi Mobile dikontrol melalui checkbox di <strong>Edit Profil Karyawan</strong>. Jika Presensi Mobile dimatikan di profil, fitur mobile (Mode WFH, Radius Lapangan, Dinas Luar) otomatis <strong>terkunci (OFF)</strong>. Jam kerja fleksibel (Flexitime) dapat diatur mandiri karena berlaku untuk seluruh tipe presensi kantor.</span>
            </div>
            <div className="flex flex-col sm:flex-row items-stretch sm:items-center gap-2 mb-3">
              <div className="relative flex-1">
                <Search className="absolute left-2.5 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-slate-400 pointer-events-none" />
                <input
                  type="text"
                  placeholder="Cari nama atau NIK karyawan..."
                  value={userSearch}
                  onChange={(e) => setUserSearch(e.target.value)}
                  className="w-full pl-8 pr-3 py-2 text-xs border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-800 text-slate-800 dark:text-slate-100 placeholder-slate-400 focus:outline-none focus:ring-1 focus:ring-indigo-400"
                />
              </div>
              <select
                value={userOfficeFilter}
                onChange={(e) => setUserOfficeFilter(e.target.value)}
                className="py-2 px-3 text-xs font-semibold border border-slate-200 dark:border-slate-700 rounded-xl bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 focus:outline-none focus:ring-1 focus:ring-indigo-400 cursor-pointer"
              >
                {offices.length !== 1 && (
                  <option value="" className="bg-white dark:bg-slate-800 text-slate-900 dark:text-slate-100">Semua Kantor</option>
                )}
                {offices.map(o => (
                  <option key={o.id} value={o.id} className="bg-white dark:bg-slate-800 text-slate-900 dark:text-slate-100">{o.office_name}</option>
                ))}
                {offices.length !== 1 && (
                  <option value="null" className="bg-white dark:bg-slate-800 text-slate-900 dark:text-slate-100">Tanpa Kantor</option>
                )}
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
                        <tr className="border-b border-slate-100 dark:border-slate-800 text-slate-500 dark:text-slate-400">
                          <th className="py-2 px-2 font-semibold">Nama</th>
                          <th className="py-2 px-2 font-semibold">Departemen</th>
                          <th className="py-2 px-2 font-semibold">Kantor</th>
                          <th className="py-2 px-2 font-semibold">Role</th>
                          <th className="py-2 px-2 font-semibold text-center">Mode WFH</th>
                          <th className="py-2 px-2 font-semibold text-center">Radius Lapangan</th>
                          <th className="py-2 px-2 font-semibold text-center">Dinas Luar</th>
                          <th className="py-2 px-2 font-semibold text-center">Flexitime</th>
                        </tr>
                      </thead>
                      <tbody className="divide-y divide-slate-50 dark:divide-slate-800/60">
                        {filtered.length === 0 ? (
                          <tr><td colSpan={8} className="text-center py-8 text-slate-400">{userSearch || userOfficeFilter ? 'Tidak ada karyawan yang cocok dengan filter.' : 'Tidak ada karyawan.'}</td></tr>
                        ) : (
                          paginatedUsers.map((u) => {
                            // Status Izin Master dari Edit Profil Karyawan
                            const isMobileLocked = u.allow_attendance === false;
                            const isWfhLocked = isMobileLocked || u.allow_wfh === false;
                            const isRadiusLocked = isWfhLocked || u.allow_radius === false;
                            const isDinasLuarLocked = isMobileLocked;

                            return (
                              <tr key={u.id} className="hover:bg-slate-50/50 dark:hover:bg-slate-800/30">
                                <td className="py-2.5 px-2 font-semibold text-slate-800 dark:text-slate-200">
                                  {u.name}
                                  {(u.employee_code || u.nik) && (
                                    <span className="ml-1.5 text-[10px] font-mono font-normal text-slate-400">({u.employee_code || u.nik})</span>
                                  )}
                                </td>
                                <td className="py-2.5 px-2 text-slate-500 dark:text-slate-400">{u.department ?? '—'}</td>
                                <td className="py-2.5 px-2 text-slate-500 dark:text-slate-400">{u.office?.office_name ?? u.office_name ?? '—'}</td>
                                <td className="py-2.5 px-2 text-slate-500 dark:text-slate-400 capitalize">{u.role}</td>

                                {/* 1. Mode WFH */}
                                <td className="py-2.5 px-2 text-center">
                                  {isWfhLocked ? (
                                    <div className="inline-flex items-center justify-center gap-1.5" title={isMobileLocked ? "Akses Presensi Mobile dinonaktifkan di Edit Profil Karyawan. Switch WFH terkunci." : "Izinkan Presensi WFH dinonaktifkan di Edit Profil Karyawan. Switch terkunci off."}>
                                      <button
                                        disabled
                                        className="relative inline-flex h-5 w-9 items-center rounded-full bg-slate-200 dark:bg-slate-800 opacity-40 cursor-not-allowed"
                                      >
                                        <span className="inline-block h-3.5 w-3.5 transform rounded-full bg-slate-400 dark:bg-slate-600 translate-x-1" />
                                      </button>
                                      <Lock className="w-3 h-3 text-slate-400 dark:text-slate-500 shrink-0" />
                                    </div>
                                  ) : u.dinas_luar_enabled ? (
                                    <button
                                      disabled
                                      className="relative inline-flex h-5 w-9 items-center rounded-full bg-emerald-500 opacity-80 cursor-not-allowed"
                                      title="Mode WFH otomatis aktif dan terkunci karena izin Dinas Luar menyala. Nonaktifkan Dinas Luar jika ingin mengubah."
                                    >
                                      <span className="inline-block h-3.5 w-3.5 transform rounded-full bg-white translate-x-4 shadow-sm" />
                                    </button>
                                  ) : u.is_wfh_approved_today ? (
                                    <button
                                      disabled
                                      className="relative inline-flex h-5 w-9 items-center rounded-full bg-emerald-500 opacity-80 cursor-not-allowed"
                                      title="Mode WFH otomatis aktif karena pengajuan WFH telah disetujui HRD untuk hari ini."
                                    >
                                      <span className="inline-block h-3.5 w-3.5 transform rounded-full bg-white translate-x-4 shadow-sm" />
                                    </button>
                                  ) : (
                                    <button
                                      onClick={() => handleToggleWfh(u.id, u.name)}
                                      className={`relative inline-flex h-5 w-9 items-center rounded-full transition cursor-pointer ${u.wfh_enabled ? 'bg-emerald-500' : 'bg-slate-300 dark:bg-slate-700'}`}
                                      title={u.wfh_enabled ? 'WFH aktif — klik untuk nonaktifkan' : 'WFH nonaktif — klik untuk aktifkan'}
                                    >
                                      <span className={`inline-block h-3.5 w-3.5 transform rounded-full bg-white transition ${u.wfh_enabled ? 'translate-x-4' : 'translate-x-1'}`} />
                                    </button>
                                  )}
                                </td>

                                {/* 2. Radius Lapangan */}
                                <td className="py-2.5 px-2 text-center">
                                  {isRadiusLocked ? (
                                    <div className="inline-flex items-center justify-center gap-1.5" title={isMobileLocked ? "Akses Presensi Mobile dinonaktifkan di Edit Profil Karyawan. Switch Lapangan terkunci." : "Validasi Radius / WFH dinonaktifkan di Edit Profil Karyawan. Switch Lapangan terkunci off."}>
                                      <button
                                        disabled
                                        className="relative inline-flex h-5 w-9 items-center rounded-full bg-slate-200 dark:bg-slate-800 opacity-40 cursor-not-allowed"
                                      >
                                        <span className="inline-block h-3.5 w-3.5 transform rounded-full bg-slate-400 dark:bg-slate-600 translate-x-1" />
                                      </button>
                                      <Lock className="w-3 h-3 text-slate-400 dark:text-slate-500 shrink-0" />
                                    </div>
                                  ) : (u.dinas_luar_enabled || u.is_wfh_approved_today) ? (
                                    <span className="text-[10px] font-mono text-slate-400 dark:text-slate-600" title="Radius dinonaktifkan otomatis (WFH / Dinas Luar bebas radius)">—</span>
                                  ) : !u.wfh_enabled ? (
                                    <button
                                      disabled
                                      className="relative inline-flex h-5 w-9 items-center rounded-full bg-slate-200 dark:bg-slate-800 opacity-50 cursor-not-allowed"
                                      title="Mode WFH sedang nonaktif — aktifkan Mode WFH untuk mengatur radius lapangan"
                                    >
                                      <span className="inline-block h-3.5 w-3.5 transform rounded-full bg-slate-400 dark:bg-slate-600 translate-x-1" />
                                    </button>
                                  ) : (
                                    <button
                                      onClick={() => handleToggleRadius(u.id, u.name)}
                                      className={`relative inline-flex h-5 w-9 items-center rounded-full transition cursor-pointer ${u.radius_enabled ? 'bg-amber-500' : 'bg-slate-300 dark:bg-slate-700'}`}
                                      title={u.radius_enabled ? 'Radius aktif (lapangan) — klik untuk nonaktifkan' : 'Radius nonaktif (WFH bebas) — klik untuk aktifkan'}
                                    >
                                      <span className={`inline-block h-3.5 w-3.5 transform rounded-full bg-white transition ${u.radius_enabled ? 'translate-x-4' : 'translate-x-1'}`} />
                                    </button>
                                  )}
                                </td>

                                {/* 3. Dinas Luar */}
                                <td className="py-2.5 px-2 text-center">
                                  {isDinasLuarLocked ? (
                                    <div className="inline-flex items-center justify-center gap-1.5" title="Akses Presensi Mobile dinonaktifkan di Edit Profil Karyawan. Switch terkunci sampai diizinkan kembali di Edit Profil Karyawan.">
                                      <button
                                        disabled
                                        className="relative inline-flex h-5 w-9 items-center rounded-full bg-slate-200 dark:bg-slate-800 opacity-40 cursor-not-allowed"
                                      >
                                        <span className="inline-block h-3.5 w-3.5 transform rounded-full bg-slate-400 dark:bg-slate-600 translate-x-1" />
                                      </button>
                                      <Lock className="w-3 h-3 text-slate-400 dark:text-slate-500 shrink-0" />
                                    </div>
                                  ) : (
                                    <button
                                      onClick={() => handleToggleDinasLuar(u.id, u.name)}
                                      className={`relative inline-flex h-5 w-9 items-center rounded-full transition cursor-pointer ${u.dinas_luar_enabled ? 'bg-indigo-600' : 'bg-slate-300 dark:bg-slate-700'}`}
                                      title={u.dinas_luar_enabled ? 'Izin Dinas Luar aktif — klik untuk nonaktifkan' : 'Izin Dinas Luar nonaktif — klik untuk aktifkan'}
                                    >
                                      <span className={`inline-block h-3.5 w-3.5 transform rounded-full bg-white transition ${u.dinas_luar_enabled ? 'translate-x-4' : 'translate-x-1'}`} />
                                    </button>
                                  )}
                                </td>

                                {/* 4. Flexitime (Independen dari presensi mobile) */}
                                <td className="py-2.5 px-2 text-center">
                                  {u.has_active_shift ? (
                                    <button
                                      disabled
                                      className="relative inline-flex h-5 w-9 items-center rounded-full bg-slate-200 dark:bg-slate-800 opacity-50 cursor-not-allowed"
                                      title={`Flexitime dinonaktifkan otomatis karena karyawan memiliki penugasan shift (${u.active_shift_name || 'Shift'}).`}
                                    >
                                      <span className="inline-block h-3.5 w-3.5 transform rounded-full bg-slate-400 dark:bg-slate-600 translate-x-1" />
                                    </button>
                                  ) : (
                                    <button
                                      onClick={() => handleToggleFlexitime(u.id, u.name)}
                                      className={`relative inline-flex h-5 w-9 items-center rounded-full transition ${u.flexitime_enabled ? 'bg-teal-600' : 'bg-slate-300 dark:bg-slate-700'}`}
                                      title={u.flexitime_enabled ? 'Jam kerja fleksibel aktif — klik untuk nonaktifkan' : 'Jam kerja fleksibel nonaktif — klik untuk aktifkan'}
                                    >
                                      <span className={`inline-block h-3.5 w-3.5 transform rounded-full bg-white transition ${u.flexitime_enabled ? 'translate-x-4' : 'translate-x-1'}`} />
                                    </button>
                                  )}
                                </td>
                              </tr>
                            );
                          })
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
                              className="py-0.5 px-2 text-xs font-semibold border border-slate-200 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 focus:outline-none cursor-pointer"
                            >
                              <option value={25} className="bg-white dark:bg-slate-800 text-slate-900 dark:text-slate-100">25</option>
                              <option value={50} className="bg-white dark:bg-slate-800 text-slate-900 dark:text-slate-100">50</option>
                              <option value={100} className="bg-white dark:bg-slate-800 text-slate-900 dark:text-slate-100">100</option>
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
            <div className="inline-flex p-1 bg-slate-100 dark:bg-slate-800/80 rounded-xl border border-slate-200/60 dark:border-slate-700/60 flex-wrap">
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
              <button
                onClick={() => {
                  setBalanceSubTab('leave_types');
                }}
                className={`flex items-center gap-2 px-3.5 py-1.5 rounded-lg text-xs font-bold transition-all ${
                  balanceSubTab === 'leave_types'
                    ? 'bg-white dark:bg-slate-900 text-indigo-600 dark:text-indigo-400 shadow-sm'
                    : 'text-slate-500 hover:text-slate-800 dark:hover:text-slate-200'
                }`}
              >
                <SlidersHorizontal className="w-3.5 h-3.5" />
                Pengaturan Jenis Cuti Kantor
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
              type BalanceEntry = {
                userId: number;
                employeeCode: string;
                department: string;
                officeName: string;
                gender?: string;
                maritalStatus?: string;
                isPregnant?: boolean;
                allowLeave?: boolean;
                cuti?: any;
                izin?: any;
                otherBalances: any[];
                allBalances: any[];
              };
              const grouped = balances.reduce<Record<string, BalanceEntry>>((acc, b) => {
                if (!acc[b.user_name]) {
                  acc[b.user_name] = {
                    userId: b.user_id,
                    employeeCode: b.employee_code || b.nik || b.user?.employee_code || '',
                    department: b.user?.department || b.department || '',
                    officeName: b.office_name || b.office?.office_name || '',
                    gender: b.gender || b.user?.gender || '',
                    maritalStatus: b.marital_status || b.user?.marital_status || '',
                    isPregnant: b.is_pregnant !== undefined ? Boolean(b.is_pregnant) : Boolean(b.user?.is_pregnant),
                    allowLeave: b.allow_leave !== undefined ? Boolean(b.allow_leave) : true,
                    otherBalances: [],
                    allBalances: [],
                  };
                }
                if (b.gender && !acc[b.user_name].gender) acc[b.user_name].gender = b.gender;
                if (b.marital_status && !acc[b.user_name].maritalStatus) acc[b.user_name].maritalStatus = b.marital_status;
                if (b.is_pregnant !== undefined && acc[b.user_name].isPregnant === undefined) acc[b.user_name].isPregnant = Boolean(b.is_pregnant);
                if (b.allow_leave !== undefined) acc[b.user_name].allowLeave = Boolean(b.allow_leave);

                acc[b.user_name].allBalances.push(b);
                if (b.leave_type === 'cuti') acc[b.user_name].cuti = b;
                else if (b.leave_type === 'izin') acc[b.user_name].izin = b;
                else acc[b.user_name].otherBalances.push(b);
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
                        className="py-1.5 px-3 text-xs border border-slate-200 dark:border-slate-700 rounded-xl bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 focus:outline-none focus:ring-1 focus:ring-indigo-400 max-w-[180px]"
                      >
                        {offices.length !== 1 && (
                          <option value="" className="bg-white dark:bg-slate-800 text-slate-900 dark:text-slate-100">Semua Kantor</option>
                        )}
                        {offices.map(o => (
                          <option key={o.id} value={o.id} className="bg-white dark:bg-slate-800 text-slate-900 dark:text-slate-100">{o.office_name}</option>
                        ))}
                        {offices.length !== 1 && (
                          <option value="none" className="bg-white dark:bg-slate-800 text-slate-900 dark:text-slate-100">Tanpa Kantor</option>
                        )}
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
                        onClick={() => loadBalances(true)}
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
                          const isLeaveAllowed = data.allowLeave !== false && cuti?.allow_leave !== false;
                          const isActive = isLeaveAllowed && (cuti?.quota ?? 0) > 0;
                          const isIzinOfficeDisabled = izin ? (izin.is_enabled_in_office === false || izin.is_disabled === true) : false;
                          const isToggling = togglingUserId === userId;

                          return (
                            <div
                              key={name}
                              className={`bg-white dark:bg-slate-900 border rounded-2xl p-4 space-y-3 transition-all hover:shadow-sm ${isActive
                                ? 'border-slate-100 dark:border-slate-800'
                                : 'border-slate-200 dark:border-slate-700 opacity-80'
                                }`}
                            >
                              {/* Header karyawan + toggle & tombol kelola saldo */}
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

                                {/* Aksi kuota & edit individual 360° */}
                                <div className="flex items-center gap-2 shrink-0">
                                  <button
                                    type="button"
                                    onClick={() => handleOpenUserDetailModal(userId, name, data, 'matrix')}
                                    className="inline-flex items-center gap-1.5 px-2.5 py-1 text-xs font-bold rounded-xl bg-indigo-50 hover:bg-indigo-100 text-indigo-700 dark:bg-indigo-950/50 dark:hover:bg-indigo-900/60 dark:text-indigo-300 border border-indigo-200/80 dark:border-indigo-800 shadow-2xs transition-all cursor-pointer group"
                                    title="Kelola Saldo Seluruh Jenis Cuti & Laporan Pemakaian Karyawan"
                                  >
                                    <SlidersHorizontal className="w-3.5 h-3.5 text-indigo-600 dark:text-indigo-400 group-hover:rotate-45 transition-transform duration-200 shrink-0" />
                                    <span className="hidden sm:inline">Kelola Saldo &amp; Laporan</span>
                                    <span className="sm:hidden">Kelola</span>
                                  </button>

                                  <span className={`text-[9px] font-semibold hidden md:inline transition-colors duration-200 ${isActive ? 'text-teal-600 dark:text-teal-400' : 'text-slate-400'}`}>
                                    {isActive ? `Cuti ${cuti?.quota ?? 12}hr/thn` : 'Hak Cuti Nonaktif'}
                                  </span>
                                  <button
                                    type="button"
                                    onClick={() => {
                                      if (isToggling || !userId) return;
                                      handleToggleCutiQuota(userId, name, cuti?.quota ?? 0, cuti?.office_default_quota ?? 12, isActive);
                                    }}
                                    title={isActive ? 'Nonaktifkan seluruh jenis cuti karyawan (hanya izin dan WFH yang bisa diajukan)' : 'Aktifkan kembali seluruh jenis cuti karyawan'}
                                    className={`relative inline-flex h-5 w-9 items-center rounded-full transition-colors duration-200 cursor-pointer ${isActive ? 'bg-teal-500' : 'bg-slate-300 dark:bg-slate-700'
                                      }`}
                                  >
                                    <span className={`inline-block h-3.5 w-3.5 transform rounded-full bg-white shadow-xs transition-transform duration-200 ${isActive ? 'translate-x-4' : 'translate-x-1'
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
                                      <div className="flex items-center justify-between pt-0.5 text-[10px]">
                                        <p className="text-slate-400">
                                          Terpakai <span className="font-semibold text-slate-600 dark:text-slate-300">{cuti.used} hari</span>
                                        </p>
                                        <button
                                          type="button"
                                          onClick={() => handleOpenUserDetailModal(userId, name, data, 'history')}
                                          className="text-indigo-600 dark:text-indigo-400 hover:underline font-medium cursor-pointer"
                                        >
                                          Laporan &rarr;
                                        </button>
                                      </div>
                                    </>
                                  ) : (
                                    <p className="text-[10px] text-slate-400 italic">Belum ada data</p>
                                  )}
                                </div>

                                 {/* Blok Izin Pribadi (Jatah Per Tahun atau Akumulasi) */}
                                 <div className="space-y-1.5 bg-slate-50/50 dark:bg-slate-800/30 p-2.5 rounded-xl border border-slate-100 dark:border-slate-800/60">
                                   <div className="flex items-center justify-between">
                                     <p className="text-[9px] font-bold text-slate-400 uppercase tracking-wider">Izin (Pribadi)</p>
                                     <span className={`text-[9px] font-semibold px-1.5 py-0.5 rounded ${
                                       isIzinOfficeDisabled
                                         ? 'text-rose-600 bg-rose-50 dark:bg-rose-950/40 border border-rose-200/60 dark:border-rose-900/40'
                                         : (izin?.quota ?? 0) > 0 || (izin?.office_default_quota ?? 0) > 0
                                         ? 'text-teal-700 dark:text-teal-300 bg-teal-100/60 dark:bg-teal-950/60'
                                         : 'text-slate-500 dark:text-slate-400 bg-slate-200/60 dark:bg-slate-700/60'
                                     }`}>
                                       {isIzinOfficeDisabled
                                         ? 'Off Kantor'
                                         : (izin?.quota ?? 0) > 0 ? `${izin.quota} hr/thn` : ((izin?.office_default_quota ?? 0) > 0 ? `${izin.office_default_quota} hr/thn` : 'Tanpa Limit')}
                                     </span>
                                   </div>
                                   {isIzinOfficeDisabled ? (
                                     <div className="flex items-center justify-between pt-1 text-[10px]">
                                       <span className="text-slate-400 italic">Dinonaktifkan di kantor</span>
                                       {izin && (izin.used ?? 0) > 0 && (
                                         <span className="text-slate-500 font-mono font-medium">Terpakai: {izin.used} hr</span>
                                       )}
                                     </div>
                                   ) : (izin?.quota ?? 0) > 0 || (izin?.office_default_quota ?? 0) > 0 ? (
                                     <>
                                       <p className="text-base font-bold text-slate-800 dark:text-slate-100 leading-none">
                                         {izin ? izin.used : 0}
                                         <span className="text-[10px] font-normal text-slate-400 ml-1">/ {izin?.quota || izin?.office_default_quota} hr</span>
                                       </p>
                                       <div className="w-full h-1.5 bg-slate-200 dark:bg-slate-700 rounded-full overflow-hidden">
                                         <div
                                           className="h-full rounded-full bg-teal-500"
                                           style={{ width: `${Math.min(100, Math.round(((izin?.used ?? 0) / (izin?.quota || izin?.office_default_quota || 1)) * 100))}%` }}
                                         />
                                       </div>
                                       <div className="flex items-center justify-between pt-0.5 text-[10px]">
                                         <p className="text-teal-600 dark:text-teal-400 font-semibold">
                                           Sisa {Math.max(0, (izin?.quota || izin?.office_default_quota) - (izin?.used ?? 0))} hari
                                         </p>
                                         <button
                                           type="button"
                                           onClick={() => handleOpenUserDetailModal(userId, name, data, 'history')}
                                           className="text-indigo-600 dark:text-indigo-400 hover:underline font-medium cursor-pointer"
                                         >
                                           Laporan &rarr;
                                         </button>
                                       </div>
                                     </>
                                   ) : (
                                     <>
                                       <p className="text-base font-bold text-slate-800 dark:text-slate-100 leading-none">
                                         {izin ? izin.used : 0}
                                         <span className="text-[10px] font-normal text-slate-400 ml-1">hari terpakai</span>
                                       </p>
                                       <div className="w-full h-1.5 bg-slate-200 dark:bg-slate-700 rounded-full overflow-hidden">
                                         <div className="h-full w-full rounded-full bg-indigo-500/30" />
                                       </div>
                                       <div className="flex items-center justify-between pt-0.5 text-[10px]">
                                         <p className="text-slate-400">Akumulasi tanpa batas</p>
                                         <button
                                           type="button"
                                           onClick={() => handleOpenUserDetailModal(userId, name, data, 'history')}
                                           className="text-indigo-600 dark:text-indigo-400 hover:underline font-medium cursor-pointer"
                                         >
                                           Laporan &rarr;
                                         </button>
                                       </div>
                                     </>
                                   )}
                                 </div>
                              </div>

                              {/* Accordion / Bagian Jenis Cuti Tambahan */}
                              {data.otherBalances.length > 0 ? (
                                <div className="pt-2 border-t border-slate-100 dark:border-slate-800/80">
                                  <button
                                    type="button"
                                    onClick={() => setExpandedUserLeaves(prev => ({ ...prev, [name]: !prev[name] }))}
                                    className="w-full flex items-center justify-between text-[11px] font-semibold text-slate-500 hover:text-slate-800 dark:hover:text-slate-200 transition py-0.5 cursor-pointer"
                                  >
                                    <span className="flex items-center gap-1.5">
                                      <span>Cuti Khusus &amp; Tambahan</span>
                                      <span className="px-1.5 py-0.2 rounded-full text-[9px] font-mono bg-indigo-50 text-indigo-600 dark:bg-indigo-950/40 dark:text-indigo-400 font-bold">
                                        {data.otherBalances.length}
                                      </span>
                                      {!isActive && (
                                        <span className="px-1.5 py-0.5 rounded text-[9px] font-semibold bg-rose-50 text-rose-600 dark:bg-rose-950/40 dark:text-rose-400 border border-rose-200/60 dark:border-rose-900/40">
                                          Semua Nonaktif
                                        </span>
                                      )}
                                    </span>
                                    <ChevronDown className={`w-3.5 h-3.5 transition-transform ${expandedUserLeaves[name] ? 'rotate-180' : ''}`} />
                                  </button>

                                  {expandedUserLeaves[name] && (
                                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-2 mt-2 pt-1">
                                      {data.otherBalances.map((ob: any) => {
                                        const isObDisabled = !isActive || ob.is_disabled;
                                        return (
                                          <div
                                            key={ob.leave_type}
                                            className={`p-2 rounded-xl border text-[11px] space-y-1 transition-all ${
                                              isObDisabled
                                                ? 'bg-slate-100/60 dark:bg-slate-800/20 border-slate-200/60 dark:border-slate-800/40 opacity-60'
                                                : 'bg-slate-50/70 dark:bg-slate-800/40 border-slate-100 dark:border-slate-800/60'
                                            }`}
                                          >
                                            <div className="flex items-center justify-between gap-1">
                                              <div className="flex items-center gap-1 truncate min-w-0">
                                                <span className="font-semibold text-slate-700 dark:text-slate-200 truncate text-[10px]" title={getLeaveTypeLabel(ob.leave_type)}>
                                                  {getLeaveTypeLabel(ob.leave_type)}
                                                </span>
                                                {isObDisabled && (
                                                  <span className="text-[8px] font-semibold text-rose-500 bg-rose-50 dark:bg-rose-950/40 px-1 py-0.2 rounded shrink-0">
                                                    Off
                                                  </span>
                                                )}
                                              </div>
                                              <button
                                                type="button"
                                                onClick={() => handleOpenUserDetailModal(userId, name, data, 'matrix')}
                                                className="text-[10px] text-indigo-600 dark:text-indigo-400 hover:underline shrink-0 font-medium cursor-pointer"
                                              >
                                                Kelola
                                              </button>
                                            </div>
                                            <div className="flex items-center justify-between text-[10px] text-slate-500 dark:text-slate-400 font-mono">
                                              <span>Pakai: <strong className="text-slate-700 dark:text-slate-200">{ob.used}</strong></span>
                                              <span>Sisa: <strong className="text-slate-700 dark:text-slate-200">{!isObDisabled && ob.quota > 0 ? ob.remaining : '0'}</strong> / {isObDisabled ? '0' : ob.quota} hr</span>
                                            </div>
                                          </div>
                                        );
                                      })}
                                    </div>
                                  )}
                                </div>
                              ) : (
                                <div className="pt-1.5 flex items-center justify-between text-[10px] text-slate-400">
                                  <span>Belum ada kuota cuti khusus individu</span>
                                  <button
                                    type="button"
                                    onClick={() => handleOpenUserDetailModal(userId, name, data, 'matrix')}
                                    className="text-indigo-600 dark:text-indigo-400 hover:underline font-medium cursor-pointer"
                                  >
                                    + Kelola Saldo &amp; Laporan
                                  </button>
                                </div>
                              )}
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
                                className="py-0.5 px-2 text-xs font-semibold border border-slate-200 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 focus:outline-none cursor-pointer"
                              >
                                <option value={12} className="bg-white dark:bg-slate-800 text-slate-900 dark:text-slate-100">12</option>
                                <option value={24} className="bg-white dark:bg-slate-800 text-slate-900 dark:text-slate-100">24</option>
                                <option value={48} className="bg-white dark:bg-slate-800 text-slate-900 dark:text-slate-100">48</option>
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
                    className="py-1.5 px-3 text-xs font-semibold border border-slate-200 dark:border-slate-700 rounded-xl bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 focus:outline-none focus:ring-1 focus:ring-indigo-400"
                  >
                    <option value="" className="bg-white dark:bg-slate-800 text-slate-900 dark:text-slate-100">Semua Tahun Reset</option>
                    {[new Date().getFullYear(), new Date().getFullYear() - 1, new Date().getFullYear() - 2].map(y => (
                      <option key={y} value={y} className="bg-white dark:bg-slate-800 text-slate-900 dark:text-slate-100">{y}</option>
                    ))}
                  </select>

                  {/* Filter Kantor */}
                  <select
                    value={balanceHistoryOfficeFilter}
                    onChange={(e) => setBalanceHistoryOfficeFilter(e.target.value)}
                    className="py-1.5 px-3 text-xs font-semibold border border-slate-200 dark:border-slate-700 rounded-xl bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 focus:outline-none focus:ring-1 focus:ring-indigo-400"
                  >
                    {offices.length !== 1 && (
                      <option value="" className="bg-white dark:bg-slate-800 text-slate-900 dark:text-slate-100">Semua Kantor</option>
                    )}
                    {offices.map(o => (
                      <option key={o.id} value={o.id} className="bg-white dark:bg-slate-800 text-slate-900 dark:text-slate-100">{o.office_name}</option>
                    ))}
                    {offices.length !== 1 && (
                      <option value="none" className="bg-white dark:bg-slate-800 text-slate-900 dark:text-slate-100">Tanpa Kantor</option>
                    )}
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
                    onClick={() => loadBalanceHistories(true)}
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
                              {h.leave_types_snapshot && typeof h.leave_types_snapshot === 'object' && Object.keys(h.leave_types_snapshot).length > 0 && (
                                <div className="mt-1">
                                  <button
                                    type="button"
                                    onClick={() => setViewingSnapshotHistory(h)}
                                    className="inline-flex items-center gap-1 text-[10px] font-bold text-indigo-600 dark:text-indigo-400 hover:text-indigo-700 dark:hover:text-indigo-300 hover:underline cursor-pointer"
                                  >
                                    <FileText className="w-3 h-3" />
                                    Snapshot Cuti Khusus
                                  </button>
                                </div>
                              )}
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

          {/* ── SUB-TAB: Pengaturan Jenis Cuti Kantor (Regulasi UU & Kebijakan) ── */}
          {balanceSubTab === 'leave_types' && (
            <div className="space-y-4">
              {/* Header Filter & Action Bar */}
              <div className="flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3 bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl p-4">
                <div className="flex items-center gap-2 flex-wrap">
                  <span className="text-xs font-semibold text-slate-500 dark:text-slate-400">Kantor Cabang:</span>
                  <select
                    value={leaveTypeOfficeId || (offices[0]?.id ? String(offices[0].id) : '')}
                    onChange={(e) => {
                      const newId = e.target.value;
                      setLeaveTypeOfficeId(newId);
                      loadLeaveTypeSettings(newId, false);
                    }}
                    className="py-1.5 px-3 text-xs font-bold border border-slate-200 dark:border-slate-700 rounded-xl bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-1 focus:ring-indigo-400 cursor-pointer min-w-[200px]"
                  >
                    {offices.map(o => (
                      <option key={o.id} value={o.id} className="bg-white dark:bg-slate-800 text-slate-900 dark:text-slate-100">
                        {o.office_name} (ID: {o.id})
                      </option>
                    ))}
                  </select>

                  <button
                    type="button"
                    onClick={() => loadLeaveTypeSettings(leaveTypeOfficeId, true)}
                    disabled={leaveTypeSettingsLoading}
                    className="p-1.5 rounded-xl border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800 transition disabled:opacity-50 cursor-pointer"
                    title="Segarkan Pengaturan"
                  >
                    <RefreshCw className={`w-3.5 h-3.5 ${leaveTypeSettingsLoading ? 'animate-spin' : ''}`} />
                  </button>
                </div>

                <div className="flex items-center gap-2 flex-wrap">
                  <button
                    type="button"
                    onClick={() => setShowLeaveInfoModal(true)}
                    className="flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold text-indigo-700 dark:text-indigo-300 bg-indigo-50 hover:bg-indigo-100 dark:bg-indigo-950/40 dark:hover:bg-indigo-900/50 rounded-xl border border-indigo-200 dark:border-indigo-800/50 transition cursor-pointer shadow-2xs"
                    title="Buka panduan lengkap sistem, regulasi cuti, dan validasi kelayakan profil karyawan"
                  >
                    <BookOpen className="w-3.5 h-3.5 text-indigo-600 dark:text-indigo-400" />
                    <span>Panduan &amp; Regulasi Cuti</span>
                  </button>

                  <button
                    type="button"
                    onClick={handleSaveLeaveTypeSettings}
                    disabled={leaveTypeSettingsLoading || leaveTypeSettingsSaving}
                    className="flex items-center gap-1.5 px-4 py-1.5 text-xs font-bold text-white bg-teal-600 hover:bg-teal-700 rounded-xl shadow-sm transition disabled:opacity-50 cursor-pointer"
                  >
                    {leaveTypeSettingsSaving ? (
                      <>
                        <Loader2 className="w-3.5 h-3.5 animate-spin" />
                        <span>Menyimpan...</span>
                      </>
                    ) : (
                      <>
                        <Check className="w-3.5 h-3.5" />
                        <span>Simpan Pengaturan Kantor</span>
                      </>
                    )}
                  </button>
                </div>
              </div>

              {/* Card Saldo Cuti & Reset Tahunan Kantor (Dipindahkan dari Form Edit Kantor) */}
              <div className="p-4 rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 shadow-xs space-y-3.5">
                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2 pb-2.5 border-b border-slate-100 dark:border-slate-800">
                  <div className="flex items-center gap-2">
                    <div className="p-2 rounded-xl bg-teal-50 dark:bg-teal-950/40 text-teal-600 dark:text-teal-400">
                      <CalendarDays className="w-4 h-4" />
                    </div>
                    <div>
                      <h4 className="text-xs font-bold text-slate-800 dark:text-slate-100 flex items-center gap-1.5">
                        <span>Saldo Cuti &amp; Reset Tahunan</span>
                        <span className="text-[10px] font-normal text-teal-600 dark:text-teal-400 bg-teal-50 dark:bg-teal-950/40 px-2 py-0.5 rounded-full font-sans">
                          {leaveTypeSettingsOfficeName || 'Kantor Cabang'}
                        </span>
                      </h4>
                      <p className="text-[11px] text-slate-400">
                        Atur kuota default saat cuti diaktivasi &amp; jadwal arsip reset tahunan kantor
                      </p>
                    </div>
                  </div>

                  {/* Tombol Reset Kantor Manual */}
                  {leaveTypeOfficeId && (
                    <button
                      type="button"
                      onClick={() => {
                        const officeObj = offices.find(o => String(o.id) === String(leaveTypeOfficeId));
                        setResetOfficeModal({
                          id: Number(leaveTypeOfficeId),
                          name: leaveTypeSettingsOfficeName || officeObj?.office_name || 'Kantor',
                          quota: leaveTypeOfficeQuota,
                          resetDate: leaveTypeOfficeResetMonth && leaveTypeOfficeResetDay ? `${leaveTypeOfficeResetMonth}-${leaveTypeOfficeResetDay}` : '12-01',
                        });
                      }}
                      className="inline-flex items-center gap-1.5 px-3 py-1.5 text-[11px] font-semibold text-amber-700 bg-amber-50 hover:bg-amber-100 dark:bg-amber-950/40 dark:text-amber-300 dark:hover:bg-amber-900/50 rounded-xl border border-amber-200 dark:border-amber-900/50 transition cursor-pointer self-start sm:self-auto"
                      title="Reset dan arsipkan pemakaian cuti semua karyawan kantor ini sekarang"
                    >
                      <RotateCcw className="w-3.5 h-3.5 text-amber-600 dark:text-amber-400" />
                      <span>Reset Saldo Kantor Sekarang</span>
                    </button>
                  )}
                </div>

                <div className="grid grid-cols-1 md:grid-cols-2 gap-4 pt-1">
                  {/* Saldo Cuti Default (hari/tahun) */}
                  <div className="space-y-1.5">
                    <label className="text-[11px] font-bold text-slate-600 dark:text-slate-300 flex items-center justify-between">
                      <span>Saldo cuti default (hari/tahun) <span className="text-rose-500 font-bold">*</span></span>
                      <span className="text-[10px] text-slate-400 font-normal">Standar: 12 hari</span>
                    </label>
                    <input
                      type="number"
                      min={0}
                      max={365}
                      value={leaveTypeOfficeQuota}
                      onChange={(e) => {
                        const val = parseInt(e.target.value);
                        setLeaveTypeOfficeQuota(isNaN(val) ? 0 : Math.max(0, val));
                      }}
                      className="w-full p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-800 text-slate-800 dark:text-slate-100 font-mono text-xs font-bold focus:outline-none focus:ring-1 focus:ring-indigo-400"
                    />
                    <p className="text-[10px] text-slate-400">
                      Jumlah hari Cuti Tahunan yang diberikan saat HRD mengaktifkan kuota karyawan di kantor ini.
                    </p>
                  </div>

                  {/* Tanggal Reset Saldo Cuti */}
                  <div className="space-y-1.5">
                    <label className="text-[11px] font-bold text-slate-600 dark:text-slate-300 block">
                      Tanggal reset saldo cuti
                    </label>
                    <div className="grid grid-cols-2 gap-2">
                      <select
                        value={leaveTypeOfficeResetMonth}
                        onChange={(e) => {
                          const month = e.target.value;
                          setLeaveTypeOfficeResetMonth(month);
                          if (!month) {
                            setLeaveTypeOfficeResetDay('');
                            return;
                          }
                          const maxDays = month === '02' ? 29 : (['04', '06', '09', '11'].includes(month) ? 30 : 31);
                          if (parseInt(leaveTypeOfficeResetDay || '01') > maxDays) {
                            setLeaveTypeOfficeResetDay(String(maxDays).padStart(2, '0'));
                          } else if (!leaveTypeOfficeResetDay) {
                            setLeaveTypeOfficeResetDay('01');
                          }
                        }}
                        className="w-full p-2 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-800 text-slate-800 dark:text-slate-100 text-xs font-semibold focus:outline-none focus:ring-1 focus:ring-indigo-400 cursor-pointer"
                      >
                        <option value="">— Tanpa Reset Otomatis —</option>
                        {['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'].map((m, i) => (
                          <option key={m} value={String(i + 1).padStart(2, '0')}>{m}</option>
                        ))}
                      </select>

                      <select
                        value={leaveTypeOfficeResetDay}
                        onChange={(e) => setLeaveTypeOfficeResetDay(e.target.value)}
                        disabled={!leaveTypeOfficeResetMonth}
                        className="w-full p-2 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-800 text-slate-800 dark:text-slate-100 text-xs font-semibold focus:outline-none focus:ring-1 focus:ring-indigo-400 disabled:opacity-50 cursor-pointer font-mono"
                      >
                        <option value="">— Tanggal —</option>
                        {(() => {
                          const m = leaveTypeOfficeResetMonth;
                          const maxDays = m === '02' ? 29 : (['04', '06', '09', '11'].includes(m) ? 30 : 31);
                          return Array.from({ length: maxDays }, (_, i) => i + 1).map((d) => (
                            <option key={d} value={String(d).padStart(2, '0')}>{d}</option>
                          ));
                        })()}
                      </select>
                    </div>

                    {leaveTypeOfficeResetMonth && leaveTypeOfficeResetDay ? (
                      (() => {
                        const mm = Number(leaveTypeOfficeResetMonth);
                        const dd = Number(leaveTypeOfficeResetDay);
                        const now = new Date();
                        const sudahLewat = new Date(now.getFullYear(), mm - 1, dd).getTime() <= now.getTime();
                        return (
                          <p className={`text-[10px] font-semibold ${sudahLewat ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600 dark:text-emerald-400'}`}>
                            Reset tiap {dd} {['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'][mm - 1]}. Sisa cuti tahun berjalan akan diarsipkan ke Riwayat Saldo Sebelumnya.
                            {sudahLewat && ' (Siklus tahun ini telah berjalan).'}
                          </p>
                        );
                      })()
                    ) : (
                      <p className="text-[10px] text-slate-400">
                        Tanpa reset otomatis (saldo hanya di-reset secara manual oleh HRD).
                      </p>
                    )}
                  </div>
                </div>

                {/* Switch Multi-Approval (Atasan -> HRD) */}
                <div className="pt-2.5 border-t border-slate-100 dark:border-slate-800 flex items-center justify-between">
                  <div>
                    <p className="text-xs font-bold text-slate-700 dark:text-slate-200">
                      Persetujuan Berjenjang (Multi-Level Approval Cuti)
                    </p>
                    <p className="text-[10px] text-slate-400">
                      Pengajuan izin &amp; cuti harus disetujui Atasan Langsung (SPV) terlebih dahulu sebelum diproses final oleh HRD
                    </p>
                  </div>
                  <button
                    type="button"
                    onClick={() => setLeaveTypeOfficeMultiApproval(!leaveTypeOfficeMultiApproval)}
                    className={`relative inline-flex h-5 w-9 items-center rounded-full transition-colors cursor-pointer ${
                      leaveTypeOfficeMultiApproval ? 'bg-indigo-600' : 'bg-slate-300 dark:bg-slate-700'
                    }`}
                  >
                    <span
                      className={`inline-block h-3.5 w-3.5 transform rounded-full bg-white shadow transition-transform ${
                        leaveTypeOfficeMultiApproval ? 'translate-x-4' : 'translate-x-1'
                      }`}
                    />
                  </button>
                </div>
              </div>

              {/* Grid Pengaturan Jenis Cuti Kantor */}
              {leaveTypeSettingsLoading ? (
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                  {[...Array(6)].map((_, i) => (
                    <div key={i} className="h-44 bg-slate-100 dark:bg-slate-800/60 rounded-2xl animate-pulse" />
                  ))}
                </div>
              ) : leaveTypeSettingsList.length === 0 ? (
                <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl p-10 text-center space-y-2">
                  <SlidersHorizontal className="w-8 h-8 text-slate-300 dark:text-slate-600 mx-auto" />
                  <p className="text-xs font-semibold text-slate-600 dark:text-slate-300">Belum ada pengaturan jenis cuti untuk kantor ini.</p>
                  <p className="text-[11px] text-slate-400">Pilih kantor cabang di atas untuk memuat pengaturan.</p>
                </div>
              ) : (
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                  {leaveTypeSettingsList.map((item) => {
                    const isAlwaysEnabled = item.leave_type === 'izin' || item.leave_type === 'wfh';
                    const edited = leaveTypeSettingsEdited[item.leave_type] ?? {
                      is_enabled: isAlwaysEnabled ? true : Boolean(item.is_enabled),
                      quota_days: Number(item.quota_days ?? item.default_quota_days ?? 0),
                      requires_document: Boolean(item.requires_document),
                      notes: item.notes || '',
                    };
                    const isEnabled = isAlwaysEnabled ? true : edited.is_enabled;

                    return (
                      <div
                        key={item.leave_type}
                        className={`bg-white dark:bg-slate-900 border rounded-2xl p-4 space-y-3.5 transition-all ${
                          isEnabled
                            ? 'border-slate-200 dark:border-slate-800 shadow-xs'
                            : 'border-slate-100 dark:border-slate-800/60 opacity-60 bg-slate-50/30'
                        }`}
                      >
                        {/* Header Item Cuti */}
                        <div className="flex items-center justify-between gap-2 pb-2.5 border-b border-slate-100 dark:border-slate-800">
                          <div className="flex items-center gap-2.5 flex-wrap">
                            <h5 className="text-xs font-bold text-slate-800 dark:text-slate-100">{item.leave_type === 'izin' ? 'Izin' : item.label}</h5>

                            {/* Pilihan Model: Jatah Per Tahun vs Akumulasi (Pindah ke samping judul) */}
                            <div className="inline-flex p-0.5 rounded-lg bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 shrink-0">
                              <button
                                type="button"
                                disabled={!isEnabled}
                                onClick={() => {
                                  setLeaveTypeSettingsEdited(prev => ({
                                    ...prev,
                                    [item.leave_type]: {
                                      ...edited,
                                      quota_days: edited.quota_days > 0
                                        ? edited.quota_days
                                        : (item.default_quota_days > 0 ? item.default_quota_days : 1),
                                    },
                                  }));
                                }}
                                className={`px-2 py-0.5 text-[9px] font-bold rounded-md transition cursor-pointer disabled:opacity-50 ${
                                  edited.quota_days > 0
                                    ? 'bg-teal-600 text-white shadow-2xs'
                                    : 'text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200'
                                }`}
                                title="Ada batasan jatah hari per tahun"
                              >
                                Jatah Per Tahun
                              </button>
                              <button
                                type="button"
                                disabled={!isEnabled}
                                onClick={() => {
                                  setLeaveTypeSettingsEdited(prev => ({
                                    ...prev,
                                    [item.leave_type]: {
                                      ...edited,
                                      quota_days: 0,
                                    },
                                  }));
                                }}
                                className={`px-2 py-0.5 text-[9px] font-bold rounded-md transition cursor-pointer disabled:opacity-50 ${
                                  edited.quota_days <= 0
                                    ? 'bg-amber-500 text-white shadow-2xs'
                                    : 'text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200'
                                }`}
                                title="Tanpa batasan kuota (pemakaian diakumulasikan)"
                              >
                                Akumulasi
                              </button>
                            </div>
                          </div>

                          {/* Checkbox Aktif / Nonaktif per kantor (Izin & WFH adalah fitur standar sistem yang selalu aktif) */}
                          {isAlwaysEnabled ? (
                            <span
                              className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-xl text-[10px] font-bold bg-teal-50 dark:bg-teal-950/40 text-teal-700 dark:text-teal-300 border border-teal-200/80 dark:border-teal-800/60 shadow-2xs"
                              title="Fitur standar sistem yang selalu aktif dan dapat diatur kuota maupun mode akumulasinya"
                            >
                              <span className="w-1.5 h-1.5 rounded-full bg-teal-500" />
                              <span>Aktif</span>
                            </span>
                          ) : (
                            <label className={`flex items-center gap-2 cursor-pointer select-none shrink-0 py-1.5 px-3 rounded-xl border transition-all ${
                              isEnabled
                                ? 'border-teal-200/90 dark:border-teal-800/80 bg-teal-50/70 dark:bg-teal-950/40 hover:bg-teal-100/70 dark:hover:bg-teal-900/50'
                                : 'border-rose-300 dark:border-rose-800 bg-rose-50/90 dark:bg-rose-950/50 hover:bg-rose-100/80 dark:hover:bg-rose-900/60 shadow-2xs'
                            }`}>
                              <input
                                type="checkbox"
                                checked={isEnabled}
                                onChange={(e) => {
                                  setLeaveTypeSettingsEdited(prev => ({
                                    ...prev,
                                    [item.leave_type]: {
                                      ...edited,
                                      is_enabled: e.target.checked,
                                    },
                                  }));
                                }}
                                className={`w-4 h-4 rounded cursor-pointer transition-colors ${
                                  isEnabled
                                    ? 'border-teal-400 text-teal-600 focus:ring-teal-500 accent-teal-600'
                                    : 'border-rose-400 text-rose-600 focus:ring-rose-500 accent-rose-600'
                                }`}
                              />
                              <span className={`text-xs font-black tracking-wide ${
                                isEnabled
                                  ? 'text-teal-700 dark:text-teal-300'
                                  : 'text-rose-600 dark:text-rose-400 font-black'
                              }`}>
                                {isEnabled ? 'Diizinkan' : 'Dinonaktifkan'}
                              </span>
                            </label>
                          )}
                        </div>

                        {/* Pengaturan Input Form */}
                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 pt-1">
                          {/* Standar Kuota / Status Akumulasi */}
                          <div className="space-y-1">
                            <label className="text-[10px] font-semibold text-slate-600 dark:text-slate-300 flex items-center justify-between">
                              <span>Standar Kuota (Hari):</span>
                              {item.default_quota_days > 0 && (
                                <span className="text-[9px] text-slate-400 font-normal">UU: {item.default_quota_days} hari</span>
                              )}
                            </label>

                            {/* Tampilan Input Jatah Per Tahun ATAU Tampilan Akumulasi Tanpa Limit */}
                            {edited.quota_days > 0 ? (
                              <div className="relative">
                                <input
                                  type="number"
                                  min={1}
                                  max={365}
                                  disabled={!isEnabled}
                                  value={edited.quota_days}
                                  onChange={(e) => {
                                    const val = Number(e.target.value);
                                    setLeaveTypeSettingsEdited(prev => ({
                                      ...prev,
                                      [item.leave_type]: {
                                        ...edited,
                                        quota_days: isNaN(val) ? 1 : Math.max(1, val),
                                      },
                                    }));
                                  }}
                                  className="w-full px-2.5 py-1.5 text-xs font-mono font-bold border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-800 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-1 focus:ring-indigo-400 disabled:opacity-50 pr-18"
                                  placeholder="Jumlah hari"
                                />
                                <span className="absolute right-2.5 top-1/2 -translate-y-1/2 text-[10px] font-semibold text-slate-400 pointer-events-none">
                                  hari/tahun
                                </span>
                              </div>
                            ) : (
                              <div className="flex items-center justify-between px-2.5 py-1.5 bg-amber-50/70 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-900/40 rounded-xl text-amber-800 dark:text-amber-300">
                                <span className="text-[11px] font-bold flex items-center gap-1.5">
                                  <span className="text-sm font-black leading-none text-amber-600 dark:text-amber-400">∞</span>
                                  <span>Akumulasi (Tanpa Limit)</span>
                                </span>
                                <span className="text-[9px] font-semibold bg-amber-100 dark:bg-amber-900/60 px-1.5 py-0.5 rounded text-amber-700 dark:text-amber-300">
                                  Berjalan
                                </span>
                              </div>
                            )}

                            {/* Keterangan Referensi Regulasi UU */}
                            {item.default_quota_days > 0 && (
                              <div className="flex items-center justify-between text-[9px] text-slate-400">
                                <span>Rujukan UU: {item.default_quota_days} hari</span>
                                {edited.quota_days > 0 && edited.quota_days !== item.default_quota_days && (
                                  <button
                                    type="button"
                                    disabled={!isEnabled}
                                    onClick={() => {
                                      setLeaveTypeSettingsEdited(prev => ({
                                        ...prev,
                                        [item.leave_type]: {
                                          ...edited,
                                          quota_days: item.default_quota_days,
                                        },
                                      }));
                                    }}
                                    className="text-indigo-600 dark:text-indigo-400 hover:underline cursor-pointer"
                                  >
                                    Kembalikan ke standar UU
                                  </button>
                                )}
                              </div>
                            )}
                          </div>

                          {/* Toggle Dokumen Pendukung */}
                          <div className="space-y-1">
                            <label className="text-[10px] font-semibold text-slate-600 dark:text-slate-300 block">
                              Wajib Bukti / Dokumen:
                            </label>
                            <button
                              type="button"
                              disabled={!isEnabled}
                              onClick={() => {
                                setLeaveTypeSettingsEdited(prev => ({
                                  ...prev,
                                  [item.leave_type]: {
                                    ...edited,
                                    requires_document: !edited.requires_document,
                                  },
                                }));
                              }}
                              className={`w-full py-1.5 px-2.5 rounded-xl border text-xs font-semibold flex items-center justify-between transition cursor-pointer disabled:opacity-50 ${
                                edited.requires_document
                                  ? 'bg-amber-50 border-amber-200 text-amber-800 dark:bg-amber-950/30 dark:border-amber-900/40 dark:text-amber-300'
                                  : 'bg-slate-50 border-slate-200 text-slate-500 dark:bg-slate-800 dark:border-slate-700 dark:text-slate-400'
                              }`}
                            >
                              <span>
                                {edited.requires_document
                                  ? (item.leave_type === 'sakit'
                                      ? 'Wajib Surat Dokter'
                                      : item.leave_type === 'wfh'
                                        ? 'Wajib Surat Tugas / Rencana'
                                        : 'Wajib Lampirkan Dokumen')
                                  : 'Tidak Wajib'}
                              </span>
                              {edited.requires_document ? (
                                <FileText className="w-3.5 h-3.5 text-amber-600 dark:text-amber-400" />
                              ) : (
                                <span className="text-[10px] text-slate-400">Opsional</span>
                              )}
                            </button>
                          </div>
                        </div>

                        {/* Catatan SOP / Kebijakan */}
                        <div className="space-y-1">
                          <label className="text-[10px] font-semibold text-slate-500 dark:text-slate-400 block">
                            Catatan Kebijakan Kantor / Syarat Khusus:
                          </label>
                          <input
                            type="text"
                            disabled={!isEnabled}
                            placeholder={
                              item.leave_type === 'sakit'
                                ? 'Misal: Wajib lapor atasan & HRD via WA maks pkl 09.00, serahkan surat dokter fisik saat masuk...'
                                : 'Misal: Lampirkan surat keterangan dokter dari RS rujukan...'
                            }
                            value={edited.notes}
                            onChange={(e) => {
                              const val = e.target.value;
                              setLeaveTypeSettingsEdited(prev => ({
                                ...prev,
                                ...{
                                  [item.leave_type]: {
                                    ...edited,
                                    notes: val,
                                  },
                                },
                              }));
                            }}
                            className="w-full px-2.5 py-1.5 text-[11px] border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-800 text-slate-800 dark:text-slate-100 placeholder-slate-400 focus:outline-none focus:ring-1 focus:ring-indigo-400 disabled:opacity-50"
                          />
                        </div>
                      </div>
                    );
                  })}
                </div>
              )}
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
            <div className="flex flex-wrap justify-between items-center gap-3">
              <div className="flex items-center gap-3">
                <h3 className="text-sm font-bold text-slate-800 dark:text-slate-100">Filter Data</h3>
                {(reportFilter.start_date || reportFilter.end_date) && (
                  <button
                    onClick={() => setReportFilterAndReset({ ...reportFilter, start_date: '', end_date: '' })}
                    className="text-[10px] flex items-center gap-1 font-semibold text-rose-500 hover:text-rose-600 transition-colors bg-rose-50 hover:bg-rose-100 dark:bg-rose-950/30 dark:hover:bg-rose-900/50 px-2 py-1 rounded-md cursor-pointer"
                  >
                    <X className="w-3 h-3" />
                    Reset Tanggal
                  </button>
                )}
              </div>
              <div className="flex items-center gap-2.5 w-full sm:w-auto">
                <div className="relative flex-1 sm:w-64 shrink-0">
                  <Search className="absolute left-2.5 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-slate-400 pointer-events-none" />
                  <input
                    type="text"
                    placeholder="Cari nama karyawan..."
                    value={reportSearch}
                    onChange={(e) => setReportSearch(e.target.value)}
                    className="w-full pl-8 pr-3 py-2 text-xs border border-slate-200 dark:border-slate-700 rounded-lg bg-slate-50 dark:bg-slate-800 text-slate-800 dark:text-slate-100 placeholder-slate-400 focus:outline-none focus:ring-1 focus:ring-indigo-400 transition-colors"
                  />
                </div>
                <button
                  onClick={handleExport}
                  className="flex items-center justify-center gap-1.5 px-3.5 py-2 bg-indigo-50 dark:bg-indigo-900/30 border border-indigo-200 dark:border-indigo-800 text-indigo-600 dark:text-indigo-400 hover:bg-indigo-100 dark:hover:bg-indigo-900/50 rounded-lg text-xs font-bold transition shrink-0 cursor-pointer shadow-2xs"
                  title="Unduh data laporan dalam format CSV"
                >
                  <Download className="w-3.5 h-3.5" />
                  <span>Export CSV</span>
                </button>
              </div>
            </div>
            <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 items-end">
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
                <label className="text-[10px] font-bold text-slate-400 block uppercase tracking-wider">Kantor</label>
                <select value={reportFilter.office_id || ''} onChange={(e) => setReportFilterAndReset({ ...reportFilter, office_id: e.target.value })} className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 focus:outline-none focus:ring-1 focus:ring-indigo-400 transition-colors cursor-pointer">
                  {offices.length !== 1 && (
                    <option value="" className="bg-white dark:bg-slate-800 text-slate-900 dark:text-slate-100">Semua Kantor</option>
                  )}
                  {offices.map(o => (
                    <option key={o.id} value={o.id} className="bg-white dark:bg-slate-800 text-slate-900 dark:text-slate-100">{o.office_name}</option>
                  ))}
                </select>
              </div>

              <div className="space-y-1.5">
                <label className="text-[10px] font-bold text-slate-400 block uppercase tracking-wider">Shift</label>
                <select
                  value={reportFilter.shift_id || ''}
                  onChange={(e) => setReportFilterAndReset({ ...reportFilter, shift_id: e.target.value })}
                  className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 focus:outline-none focus:ring-1 focus:ring-indigo-400 transition-colors cursor-pointer"
                >
                  <option value="" className="bg-white dark:bg-slate-800 text-slate-900 dark:text-slate-100">Semua Shift</option>
                  <option value="office" className="bg-white dark:bg-slate-800 text-slate-900 dark:text-slate-100">🏢 Kantor (Default)</option>
                  {reportAvailableShifts.map((s) => {
                    const shiftStat = report?.by_shift?.find((bs: any) => bs.shift_id === s.id);
                    const lateNote = shiftStat?.late > 0 ? ` (⚠️ ${shiftStat.late} Telat)` : '';
                    return (
                      <option key={s.id} value={s.id} className="bg-white dark:bg-slate-800 text-slate-900 dark:text-slate-100">
                        {s.name}{lateNote}
                      </option>
                    );
                  })}
                </select>
              </div>

              <div className="space-y-1.5">
                <label className="text-[10px] font-bold text-slate-400 block uppercase tracking-wider">Status</label>
                <select value={reportFilter.status} onChange={(e) => setReportFilterAndReset({ ...reportFilter, status: e.target.value })} className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 focus:outline-none focus:ring-1 focus:ring-indigo-400 transition-colors cursor-pointer">
                  <option value="" className="bg-white dark:bg-slate-800 text-slate-900 dark:text-slate-100">Semua Status</option>
                  <option value="present" className="bg-white dark:bg-slate-800 text-slate-900 dark:text-slate-100">Hadir</option>
                  <option value="late" className="bg-white dark:bg-slate-800 text-slate-900 dark:text-slate-100">Telat</option>
                  <option value="early_leave" className="bg-white dark:bg-slate-800 text-slate-900 dark:text-slate-100">Pulang Awal</option>
                  <option value="absent" className="bg-white dark:bg-slate-800 text-slate-900 dark:text-slate-100">Alpha</option>
                  <option value="libur" className="bg-white dark:bg-slate-800 text-slate-900 dark:text-slate-100">Libur</option>
                  <option value="cuti" className="bg-white dark:bg-slate-800 text-slate-900 dark:text-slate-100">Cuti</option>
                  <option value="izin" className="bg-white dark:bg-slate-800 text-slate-900 dark:text-slate-100">Izin</option>
                  <option value="sakit" className="bg-white dark:bg-slate-800 text-slate-900 dark:text-slate-100">Sakit</option>
                </select>
              </div>

              <div className="space-y-1.5">
                <label className="text-[10px] font-bold text-slate-400 block uppercase tracking-wider">Lokasi</label>
                <select value={reportFilter.type} onChange={(e) => setReportFilterAndReset({ ...reportFilter, type: e.target.value })} className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 focus:outline-none focus:ring-1 focus:ring-indigo-400 transition-colors cursor-pointer">
                  <option value="" className="bg-white dark:bg-slate-800 text-slate-900 dark:text-slate-100">Semua Lokasi</option>
                  <option value="onsite" className="bg-white dark:bg-slate-800 text-slate-900 dark:text-slate-100">On Site</option>
                  <option value="wfh" className="bg-white dark:bg-slate-800 text-slate-900 dark:text-slate-100">WFH</option>
                  <option value="field" className="bg-white dark:bg-slate-800 text-slate-900 dark:text-slate-100">Lapangan</option>
                  <option value="dinas_luar" className="bg-white dark:bg-slate-800 text-slate-900 dark:text-slate-100">Dinas Luar</option>
                </select>
              </div>
            </div>
          </div>

          {loading ? (
            <TabSkeleton tab="report" />
          ) : report && (
            <>
              {/* Summary global */}
              <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 xl:grid-cols-12 gap-3">
                <SummaryCard label="Hadir" value={report.summary?.present ?? 0} color="text-emerald-600" />
                <SummaryCard label="Telat" value={report.summary?.late ?? 0} color="text-amber-600" />
                <SummaryCard label="Pulang Awal" value={report.summary?.early_leave ?? 0} color="text-violet-600" />
                <SummaryCard label="Alpha" value={report.summary?.absent ?? 0} color="text-rose-600" />
                <SummaryCard label="Cuti" value={report.summary?.cuti ?? 0} color="text-teal-600" />
                <SummaryCard label="Izin" value={report.summary?.izin ?? 0} color="text-purple-600" />
                <SummaryCard label="Sakit" value={report.summary?.sakit ?? 0} color="text-orange-500" />
                <SummaryCard label="On site" value={report.by_type?.onsite ?? 0} color="text-slate-700 dark:text-white" />
                <SummaryCard label="WFH" value={report.by_type?.wfh ?? 0} color="text-indigo-600" />
                <SummaryCard label="Dinas Luar" value={report.by_type?.dinas_luar ?? 0} color="text-emerald-500" />
                <SummaryCard label="Jam Kerja" value={fmtMinutes(report.summary?.total_working_minutes)} color="text-cyan-600" />
                <SummaryCard label="Lembur" value={fmtMinutes(report.summary?.total_overtime_minutes)} color="text-orange-600" />
              </div>

              {/* ── Sub-nav Mode Tampilan: Detail Log Presensi vs Rekapitulasi per Shift ── */}
              <div className="flex flex-wrap items-center justify-between gap-3 pt-1">
                <div className="flex items-center gap-1.5 p-1 bg-slate-100 dark:bg-slate-800/80 rounded-xl border border-slate-200/60 dark:border-slate-700/60">
                  <button
                    type="button"
                    onClick={() => setReportSubTab('log')}
                    className={`flex items-center gap-2 px-3.5 py-1.5 rounded-lg text-xs font-semibold transition-all cursor-pointer ${
                      reportSubTab === 'log'
                        ? 'bg-white dark:bg-slate-900 text-indigo-600 dark:text-indigo-400 shadow-xs'
                        : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-slate-200'
                    }`}
                  >
                    <ClipboardList className="w-3.5 h-3.5" />
                    <span>Detail Log Presensi</span>
                    {report?.report?.total !== undefined && (
                      <span className="text-[10px] px-1.5 py-0.2 rounded-full bg-slate-100 dark:bg-slate-800 font-mono">
                        {report.report.total}
                      </span>
                    )}
                  </button>

                  <button
                    type="button"
                    onClick={() => setReportSubTab('matrix')}
                    className={`flex items-center gap-2 px-3.5 py-1.5 rounded-lg text-xs font-semibold transition-all cursor-pointer ${
                      reportSubTab === 'matrix'
                        ? 'bg-white dark:bg-slate-900 text-indigo-600 dark:text-indigo-400 shadow-xs'
                        : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-slate-200'
                    }`}
                  >
                    <BarChart3 className="w-3.5 h-3.5" />
                    <span>Rekapitulasi per Shift</span>
                    {report?.by_shift && report.by_shift.length > 0 && (
                      <span className="text-[10px] px-1.5 py-0.2 rounded-full bg-indigo-50 dark:bg-indigo-950/60 text-indigo-600 dark:text-indigo-400 font-mono font-bold">
                        {report.by_shift.length} Shift
                      </span>
                    )}
                  </button>
                </div>

                {/* Indikator Filter Shift Aktif */}
                {reportFilter.shift_id && (
                  <div className="flex items-center gap-2 text-xs">
                    <span className="text-slate-500 text-[11px]">Filter Shift aktif:</span>
                    <span className="px-2.5 py-1 rounded-lg bg-indigo-50 dark:bg-indigo-950/50 text-indigo-600 dark:text-indigo-400 font-medium text-[11px] border border-indigo-200 dark:border-indigo-800 flex items-center gap-1.5">
                      <span className="w-2 h-2 rounded-full bg-indigo-500" />
                      {reportFilter.shift_id === 'office'
                        ? '🏢 Kantor (Default)'
                        : reportAvailableShifts.find(s => String(s.id) === String(reportFilter.shift_id))?.name || reportFilter.shift_id}
                    </span>
                    <button
                      type="button"
                      onClick={() => setReportFilterAndReset({ ...reportFilter, shift_id: '' })}
                      className="text-[11px] font-semibold text-rose-500 hover:underline cursor-pointer"
                    >
                      Reset Filter
                    </button>
                  </div>
                )}
              </div>

              {reportSubTab === 'log' ? (
                <>
                  <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl shadow-xs overflow-hidden">
                <div className="p-5 overflow-x-auto">
                  <table className="w-full text-xs text-left">
                    <thead>
                      <tr className="border-b border-slate-100 dark:border-slate-800 text-slate-500 dark:text-slate-400">
                        <th className="py-2 px-2 font-semibold">NIK</th>
                        <th className="py-2 px-2 font-semibold">
                          <button
                            onClick={() => setReportNameSort(s => s === 'asc' ? 'desc' : 'asc')}
                            className="flex items-center gap-1 hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors group cursor-pointer"
                            title="Urutkan berdasarkan nama"
                          >
                            Nama
                            <span className="text-[10px] font-bold">
                              {reportNameSort === 'asc' ? '↑' : reportNameSort === 'desc' ? '↓' : <span className="opacity-30 group-hover:opacity-70">↕</span>}
                            </span>
                          </button>
                        </th>
                        <th className="py-2 px-2 font-semibold">Departemen</th>
                        <th className="py-2 px-2 font-semibold">Shift</th>
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
                              <td colSpan={13} className="text-center py-12">
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
                              <td className="py-3 px-2 whitespace-nowrap">
                                <span
                                  className="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold"
                                  style={{
                                    backgroundColor: `${r.shift_color || '#64748b'}18`,
                                    color: r.shift_color || '#64748b',
                                  }}
                                >
                                  {r.shift_name || 'Kantor (Default)'}
                                </span>
                              </td>
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
                                  <div className="flex flex-col gap-1">
                                    <span className="flex items-center gap-1.5">
                                      {r.check_in_type === 'wfh' && <Home className="w-3.5 h-3.5 text-indigo-500" />}
                                      {r.check_in_type === 'field' && <MapPin className="w-3.5 h-3.5 text-amber-500" />}
                                      {r.check_in_type === 'onsite' && <Building2 className="w-3.5 h-3.5 text-slate-400" />}
                                      {r.check_in_type === 'dinas_luar' && <Briefcase className="w-3.5 h-3.5 text-emerald-500" />}
                                      <span className={r.check_in_type === 'dinas_luar' ? 'font-semibold text-emerald-700 dark:text-emerald-400' : ''}>
                                        {r.check_in_type === 'dinas_luar' ? 'Dinas Luar' : r.check_in_type === 'wfh' ? 'WFH' : r.check_in_type === 'field' ? 'Lapangan' : 'Kantor'}
                                      </span>
                                    </span>
                                    {r.check_in_type === 'dinas_luar' && (
                                      <div className="flex items-center gap-1.5">
                                        {r.client_name && (
                                          <span className="text-[10px] text-slate-500 max-w-[130px] truncate" title={r.client_name}>
                                            {r.client_name}
                                          </span>
                                        )}
                                        <button
                                          type="button"
                                          onClick={() => openVisitDetailModal(r)}
                                          className="inline-flex items-center gap-1 px-1.5 py-0.5 rounded bg-emerald-50 dark:bg-emerald-950/40 text-emerald-700 dark:text-emerald-300 hover:bg-emerald-100 dark:hover:bg-emerald-900/60 text-[9px] font-bold border border-emerald-200 dark:border-emerald-800 transition cursor-pointer"
                                          title="Lihat Detail Kunjungan & Foto Bukti"
                                        >
                                          <Eye className="w-2.5 h-2.5" /> Detail
                                        </button>
                                      </div>
                                    )}
                                  </div>
                                ) : (
                                  <span className="text-slate-300 dark:text-slate-600">—</span>
                                )}
                              </td>
                              <td className="py-3 px-2 whitespace-nowrap">
                                {(r.check_in_type === 'wfh' || r.check_in_type === 'field' || r.check_in_type === 'dinas_luar') && r.check_in_lat && r.check_in_lng ? (
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

                {/* Pagination footer (Server-side) - disembunyikan jika total data <= 25 */}
                {report?.report && report.report.total > 25 && (
                  <div className="flex flex-col sm:flex-row items-center justify-between gap-3 px-4 py-3 border-t border-slate-100 dark:border-slate-800 text-xs">
                    <div className="flex items-center gap-2 text-slate-500 dark:text-slate-400">
                      <span>
                        Menampilkan <strong className="text-slate-800 dark:text-slate-200 font-bold font-mono">
                          {report.report.from ?? (((report.report.current_page - 1) * report.report.per_page) + 1)} - {report.report.to ?? Math.min(report.report.current_page * report.report.per_page, report.report.total)}
                        </strong> dari <strong className="text-slate-800 dark:text-slate-200 font-bold font-mono">{report.report.total}</strong> data
                      </span>
                      <span className="hidden sm:inline">•</span>
                      <div className="flex items-center gap-1.5">
                        <span className="hidden sm:inline">Per hal:</span>
                        <select
                          value={reportPageSize}
                          onChange={(e) => {
                            setReportPageSize(Number(e.target.value));
                            setReportPage(1);
                          }}
                          className="py-0.5 px-2 text-xs font-semibold border border-slate-200 dark:border-slate-700 rounded-lg bg-slate-50 dark:bg-slate-800 text-slate-700 dark:text-slate-300 focus:outline-none cursor-pointer"
                        >
                          <option value={25} className="bg-white dark:bg-slate-800 text-slate-900 dark:text-slate-100">25</option>
                          <option value={50} className="bg-white dark:bg-slate-800 text-slate-900 dark:text-slate-100">50</option>
                          <option value={100} className="bg-white dark:bg-slate-800 text-slate-900 dark:text-slate-100">100</option>
                        </select>
                      </div>
                    </div>

                    <div className="flex items-center gap-1.5">
                      <button
                        onClick={() => setReportPage(1)}
                        disabled={reportPage <= 1}
                        className="p-1 rounded border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 disabled:opacity-30 disabled:cursor-not-allowed hover:bg-slate-50 dark:hover:bg-slate-800 transition cursor-pointer"
                        title="Halaman Pertama"
                      >
                        «
                      </button>
                      <button
                        onClick={() => setReportPage((p) => Math.max(1, p - 1))}
                        disabled={reportPage <= 1}
                        className="p-1 rounded border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 disabled:opacity-30 disabled:cursor-not-allowed hover:bg-slate-50 dark:hover:bg-slate-800 transition cursor-pointer"
                        title="Halaman Sebelumnya"
                      >
                        ‹
                      </button>
                      <span className="px-2 font-mono font-semibold text-slate-700 dark:text-slate-300">
                        Hal {report.report.current_page} / {Math.max(1, report.report.last_page)}
                      </span>
                      <button
                        onClick={() => setReportPage((p) => Math.min(report.report.last_page, p + 1))}
                        disabled={reportPage >= report.report.last_page}
                        className="p-1 rounded border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 disabled:opacity-30 disabled:cursor-not-allowed hover:bg-slate-50 dark:hover:bg-slate-800 transition cursor-pointer"
                        title="Halaman Berikutnya"
                      >
                        ›
                      </button>
                      <button
                        onClick={() => setReportPage(report.report.last_page)}
                        disabled={reportPage >= report.report.last_page}
                        className="p-1 rounded border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 disabled:opacity-30 disabled:cursor-not-allowed hover:bg-slate-50 dark:hover:bg-slate-800 transition cursor-pointer"
                        title="Halaman Terakhir"
                      >
                        »
                      </button>
                    </div>
                  </div>
                )}
              </div>
            </>
          ) : (
            /* ── Tampilan Sub-tab 2: Matriks & Rekapitulasi per Shift ── */
            <div className="space-y-4">
              {/* Header Info Banner */}
              <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl p-5 shadow-xs flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
                <div className="flex items-center gap-3">
                  <div className="p-2.5 rounded-xl bg-indigo-50 dark:bg-indigo-950/50 text-indigo-600 dark:text-indigo-400 shrink-0">
                    <Layers className="w-5 h-5" />
                  </div>
                  <div>
                    <h3 className="text-sm font-bold text-slate-800 dark:text-slate-100">
                      Matriks Rekapitulasi Performa Shift
                    </h3>
                    <p className="text-xs text-slate-500">
                      Evaluasi perbandingan tingkat kehadiran, keterlambatan, dan lembur antar shift
                    </p>
                  </div>
                </div>

                <div className="flex items-center gap-2">
                  <button
                    onClick={handleExport}
                    className="flex items-center gap-1.5 px-3.5 py-2 bg-indigo-50 dark:bg-indigo-900/30 border border-indigo-100 dark:border-indigo-800 text-indigo-600 dark:text-indigo-400 hover:bg-indigo-100 dark:hover:bg-indigo-900/50 rounded-xl text-xs font-bold transition cursor-pointer"
                  >
                    <Download className="w-3.5 h-3.5" />
                    Export CSV
                  </button>
                </div>
              </div>

              {/* Tabel Matriks Rekapitulasi */}
              <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl shadow-xs overflow-hidden">
                <div className="p-5 overflow-x-auto">
                  <table className="w-full text-xs text-left">
                    <thead>
                      <tr className="border-b border-slate-100 dark:border-slate-800 text-slate-500 uppercase text-[10px] tracking-wider">
                        <th className="py-3 px-3 font-semibold">Shift Kerja</th>
                        <th className="py-3 px-3 font-semibold text-center">Total Jadwal</th>
                        <th className="py-3 px-3 font-semibold text-center">Tingkat Hadir</th>
                        <th className="py-3 px-3 font-semibold text-center">Tepat Waktu</th>
                        <th className="py-3 px-3 font-semibold text-center">Telat Masuk</th>
                        <th className="py-3 px-3 font-semibold text-center">Pulang Awal</th>
                        <th className="py-3 px-3 font-semibold text-center">Alpha</th>
                        <th className="py-3 px-3 font-semibold text-right">Total Jam Kerja</th>
                        <th className="py-3 px-3 font-semibold text-right">Total Lembur</th>
                        <th className="py-3 px-3 font-semibold text-center">Aksi</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-50 dark:divide-slate-800/60">
                      {report.by_shift && report.by_shift.length > 0 ? (
                        report.by_shift.map((s: any) => {
                          const presentPct = s.total_records > 0 ? Math.round((s.present / s.total_records) * 100) : 0;
                          const onTime = Math.max(0, s.present - s.late);
                          return (
                            <tr key={s.shift_id ?? 'office'} className="hover:bg-slate-50/70 dark:hover:bg-slate-800/40 transition-colors">
                              <td className="py-3.5 px-3">
                                <div className="flex items-center gap-2.5">
                                  <span
                                    className="w-3 h-3 rounded-full shrink-0 shadow-xs"
                                    style={{ backgroundColor: s.color || '#6366f1' }}
                                  />
                                  <div>
                                    <span className="font-bold text-slate-800 dark:text-slate-100 text-xs">
                                      {s.shift_name}
                                    </span>
                                    <p className="text-[10px] text-slate-400">
                                      {s.shift_id === null ? 'Default Kantor' : 'Shift Khusus'}
                                    </p>
                                  </div>
                                </div>
                              </td>
                              <td className="py-3.5 px-3 text-center font-mono font-semibold text-slate-700 dark:text-slate-300">
                                {s.total_records}
                              </td>
                              <td className="py-3.5 px-3 text-center">
                                <div className="inline-flex flex-col items-center min-w-[70px]">
                                  <span className="font-mono font-bold text-xs text-slate-800 dark:text-slate-200">
                                    {presentPct}%
                                  </span>
                                  <div className="w-16 h-1.5 bg-slate-100 dark:bg-slate-800 rounded-full overflow-hidden mt-1">
                                    <div
                                      className={`h-full rounded-full ${
                                        presentPct >= 80
                                          ? 'bg-emerald-500'
                                          : presentPct >= 50
                                          ? 'bg-amber-500'
                                          : 'bg-rose-500'
                                      }`}
                                      style={{ width: `${Math.min(100, presentPct)}%` }}
                                    />
                                  </div>
                                </div>
                              </td>
                              <td className="py-3.5 px-3 text-center font-mono text-emerald-600 dark:text-emerald-400 font-semibold">
                                {onTime}
                              </td>
                              <td className="py-3.5 px-3 text-center">
                                {s.late > 0 ? (
                                  <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-amber-100 dark:bg-amber-950/60 text-amber-700 dark:text-amber-400 font-mono font-bold text-[11px]">
                                    ⚠️ {s.late}
                                  </span>
                                ) : (
                                  <span className="font-mono text-slate-400">0</span>
                                )}
                              </td>
                              <td className="py-3.5 px-3 text-center font-mono text-violet-600 dark:text-violet-400">
                                {s.early_leave || 0}
                              </td>
                              <td className="py-3.5 px-3 text-center">
                                {s.absent > 0 ? (
                                  <span className="inline-flex items-center px-2 py-0.5 rounded-md bg-rose-100 dark:bg-rose-950/60 text-rose-700 dark:text-rose-400 font-mono font-bold text-[11px]">
                                    {s.absent}
                                  </span>
                                ) : (
                                  <span className="font-mono text-slate-400">0</span>
                                )}
                              </td>
                              <td className="py-3.5 px-3 text-right font-mono text-cyan-600 dark:text-cyan-400">
                                {fmtMinutes(s.working_minutes)}
                              </td>
                              <td className="py-3.5 px-3 text-right font-mono text-orange-600 dark:text-orange-400 font-semibold">
                                {fmtMinutes(s.overtime_minutes)}
                              </td>
                              <td className="py-3.5 px-3 text-center">
                                <button
                                  type="button"
                                  onClick={() => {
                                    const targetVal = s.shift_id !== null ? String(s.shift_id) : 'office';
                                    setReportFilterAndReset({
                                      ...reportFilter,
                                      shift_id: targetVal,
                                    });
                                    setReportSubTab('log');
                                  }}
                                  className="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-indigo-50 dark:bg-indigo-950/50 text-indigo-600 dark:text-indigo-400 hover:bg-indigo-100 dark:hover:bg-indigo-900/50 font-semibold text-[11px] transition cursor-pointer"
                                  title="Lihat rincian log karyawan shift ini"
                                >
                                  <span>Lihat Log</span>
                                  <ExternalLink className="w-3 h-3" />
                                </button>
                              </td>
                            </tr>
                          );
                        })
                      ) : (
                        <tr>
                          <td colSpan={10} className="py-8 text-center text-slate-400">
                            Belum ada data shift pada periode yang dipilih.
                          </td>
                        </tr>
                      )}
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
          )}
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
            leaves={leaves}
            reload={() => Promise.all([loadHolidays(true), loadLeaves(true)])}
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

      {/* ─── Modal: Detail Kunjungan Dinas Luar & Foto Bukti ─── */}
      {visitDetailModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
          <div onClick={closeVisitDetailModal} className="fixed inset-0 bg-slate-900/70 backdrop-blur-sm" />
          <div className="relative z-10 bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-lg max-h-[90vh] flex flex-col overflow-hidden animate-in fade-in zoom-in-95 duration-200">
            {/* Header */}
            <div className="flex items-center justify-between px-5 py-3.5 border-b border-slate-100 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-800/40">
              <div className="flex items-center gap-2.5">
                <div className="w-8 h-8 rounded-xl bg-emerald-100 dark:bg-emerald-950/60 text-emerald-600 dark:text-emerald-400 flex items-center justify-center">
                  <Briefcase className="w-4 h-4" />
                </div>
                <div>
                  <p className="text-sm font-bold text-slate-800 dark:text-slate-100">Detail Kunjungan Klien</p>
                  <p className="text-[11px] text-slate-500 dark:text-slate-400">{visitDetailModal.userName}</p>
                </div>
              </div>
              <button
                onClick={closeVisitDetailModal}
                className="p-1.5 hover:bg-slate-200/60 dark:hover:bg-slate-800 rounded-lg text-slate-400 hover:text-slate-600 transition"
              >
                <X className="w-4 h-4" />
              </button>
            </div>

            {/* Body */}
            <div className="flex-1 overflow-y-auto p-5 space-y-4">
              {/* Info Klien */}
              <div className="bg-emerald-50/50 dark:bg-emerald-950/20 border border-emerald-100 dark:border-emerald-900/40 rounded-xl p-3.5 space-y-2.5">
                <div>
                  <span className="text-[10px] font-bold text-emerald-600 dark:text-emerald-400 uppercase tracking-wider block">Nama Klien / Instansi</span>
                  <p className="text-sm font-bold text-slate-800 dark:text-slate-100">{visitDetailModal.clientName}</p>
                </div>
                {visitDetailModal.clientAddress && (
                  <div>
                    <span className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">Alamat / Lokasi Klien</span>
                    <p className="text-xs text-slate-700 dark:text-slate-300 leading-relaxed flex items-start gap-1.5 mt-0.5">
                      <MapPin className="w-3.5 h-3.5 text-emerald-500 shrink-0 mt-0.5" />
                      <span>{visitDetailModal.clientAddress}</span>
                    </p>
                  </div>
                )}
                {visitDetailModal.visitNotes && (
                  <div>
                    <span className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">Catatan / Agenda Kunjungan</span>
                    <p className="text-xs text-slate-600 dark:text-slate-300 bg-white/60 dark:bg-slate-900/60 rounded-lg p-2 mt-0.5 border border-slate-100 dark:border-slate-800/80">
                      {visitDetailModal.visitNotes}
                    </p>
                  </div>
                )}
              </div>

              {/* GPS Coordinates & Google Maps Link */}
              {visitDetailModal.checkInLat && visitDetailModal.checkInLng && (
                <div className="flex items-center justify-between p-3 rounded-xl border border-slate-100 dark:border-slate-800 bg-slate-50/70 dark:bg-slate-800/30 text-xs">
                  <div>
                    <span className="text-[10px] font-semibold text-slate-400 block">Koordinat GPS Saat Check-in</span>
                    <span className="font-mono text-slate-700 dark:text-slate-300 text-xs">
                      {Number(visitDetailModal.checkInLat).toFixed(6)}, {Number(visitDetailModal.checkInLng).toFixed(6)}
                    </span>
                  </div>
                  <a
                    href={`https://www.google.com/maps?q=${visitDetailModal.checkInLat},${visitDetailModal.checkInLng}`}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-emerald-600 text-white hover:bg-emerald-700 text-xs font-semibold shadow-xs transition"
                  >
                    <ExternalLink className="w-3.5 h-3.5" /> Google Maps
                  </a>
                </div>
              )}

              {/* Foto Bukti Kunjungan (jika ada) */}
              {visitDetailModal.photoUrl && (
                <div>
                  <span className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block mb-2">Foto Bukti Terlampir</span>
                  <div className="relative rounded-xl overflow-hidden border border-slate-200 dark:border-slate-700 bg-slate-950 flex items-center justify-center group">
                    <img
                      src={visitDetailModal.photoUrl}
                      alt={`Foto kunjungan ${visitDetailModal.clientName}`}
                      className="max-h-72 w-full object-contain rounded-xl"
                    />
                    <a
                      href={visitDetailModal.photoUrl}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="absolute bottom-3 right-3 px-2.5 py-1.5 rounded-lg bg-slate-900/80 backdrop-blur-sm text-white text-xs font-semibold opacity-0 group-hover:opacity-100 transition flex items-center gap-1"
                    >
                      <ExternalLink className="w-3 h-3" /> Perbesar
                    </a>
                  </div>
                </div>
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

      {/* ── Modal 360°: Kelola Saldo Seluruh Jenis Cuti & Laporan Pemakaian Karyawan ── */}
      {userLeaveDetailModal && (() => {
        const userProfile = {
          gender: userLeaveDetailModal.gender,
          maritalStatus: userLeaveDetailModal.maritalStatus,
          isPregnant: userLeaveDetailModal.isPregnant,
        };

        const balancesEntries = Object.entries(userLeaveDetailModal.balancesMap) as [string, any][];
        const isEntryUnlimited = (d: any) => (d.officeDefault <= 0 && (Number(d.quota) || 0) <= 0);

        const totalDistributedQuota = balancesEntries
          .filter(([type, d]) => !isEntryUnlimited(d) && (type === 'izin' || checkLeaveEligibility(type, userProfile).isEligible))
          .reduce((sum, [, d]) => sum + ((Number(d.remaining) || 0) + (Number(d.used) || 0)), 0);
        const totalUsedQuota = balancesEntries.reduce((sum, [, d]) => sum + (Number(d.used) || 0), 0);
        const totalRemainingQuota = balancesEntries
          .filter(([type, d]) => !isEntryUnlimited(d) && (type === 'izin' || checkLeaveEligibility(type, userProfile).isEligible))
          .reduce((sum, [, d]) => sum + (Number(d.remaining) || 0), 0);
        const totalActiveLeaveTypes = balancesEntries.filter(([type, d]) => {
          if (isEntryUnlimited(d)) return true;
          const elig = type === 'izin' ? { isEligible: true } : checkLeaveEligibility(type, userProfile);
          return elig.isEligible && ((Number(d.remaining) || 0) + (Number(d.used) || 0)) > 0;
        }).length;
        const modifiedCount = balancesEntries.filter(([type, d]) => {
          if (isEntryUnlimited(d)) return false;
          const elig = type === 'izin' ? { isEligible: true } : checkLeaveEligibility(type, userProfile);
          if (!elig.isEligible) return false;
          return d.remaining !== userLeaveDetailModal.originalBalancesMap[type];
        }).length;

        const filteredLeavesHistory = userLeaveDetailModal.leavesHistory.filter((item) => {
          if (userLeaveDetailModal.historyFilterStatus !== 'all' && item.status !== userLeaveDetailModal.historyFilterStatus) {
            return false;
          }
          if (userLeaveDetailModal.historyFilterType !== 'all' && item.leave_type !== userLeaveDetailModal.historyFilterType) {
            return false;
          }
          if (userLeaveDetailModal.historySearch) {
            const q = userLeaveDetailModal.historySearch.toLowerCase();
            const reason = (item.reason || '').toLowerCase();
            const dateStr = `${item.start_date || ''} ${item.end_date || ''} ${item.created_at || ''}`.toLowerCase();
            const leaveTypeStr = (item.leave_type_label || getLeaveTypeLabel(item.leave_type)).toLowerCase();
            const actorStr = (item.adjusted_by_name || item.approved_by || '').toLowerCase();
            if (!reason.includes(q) && !dateStr.includes(q) && !leaveTypeStr.includes(q) && !actorStr.includes(q)) {
              return false;
            }
          }
          return true;
        });

        return (
          <div className="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-4 bg-slate-950/70 backdrop-blur-xs">
            <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-3xl shadow-2xl max-w-4xl w-full max-h-[92vh] flex flex-col overflow-hidden animate-in fade-in zoom-in-95 duration-150">
              
              {/* ── 1. HEADER MODAL & PROFIL KARYAWAN ── */}
              <div className="px-6 py-4 border-b border-slate-100 dark:border-slate-800 bg-gradient-to-r from-slate-50 to-indigo-50/30 dark:from-slate-900 dark:to-indigo-950/20 flex items-start justify-between gap-4">
                <div className="flex items-start gap-3.5">
                  <div className="w-11 h-11 rounded-2xl bg-indigo-600 dark:bg-indigo-500 text-white flex items-center justify-center font-bold text-base shadow-sm shrink-0 mt-0.5">
                    {userLeaveDetailModal.userName.charAt(0).toUpperCase()}
                  </div>
                  <div className="space-y-1">
                    <div className="flex items-center gap-2 flex-wrap">
                      <h3 className="text-base font-bold text-slate-800 dark:text-slate-100">
                        {userLeaveDetailModal.userName}
                      </h3>
                      {userLeaveDetailModal.employeeCode && (
                        <span className="font-mono text-xs px-2 py-0.5 rounded-md bg-white dark:bg-slate-800 text-slate-600 dark:text-slate-300 border border-slate-200 dark:border-slate-700">
                          {userLeaveDetailModal.employeeCode}
                        </span>
                      )}
                      {userLeaveDetailModal.officeName && (
                        <span className="text-xs px-2 py-0.5 rounded-md bg-indigo-50 dark:bg-indigo-950/50 text-indigo-700 dark:text-indigo-300 font-medium border border-indigo-100 dark:border-indigo-900/50 flex items-center gap-1">
                          <Building2 className="w-3 h-3" />
                          {userLeaveDetailModal.officeName}
                        </span>
                      )}
                    </div>

                    {/* Profil Indikator Kelayakan */}
                    <div className="flex items-center gap-2 flex-wrap text-[11px] text-slate-500 dark:text-slate-400 pt-0.5">
                      <span>Divisi: <strong className="text-slate-700 dark:text-slate-200">{userLeaveDetailModal.department || '—'}</strong></span>
                      <span>•</span>
                      <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 font-semibold">
                        {userLeaveDetailModal.gender ? (userLeaveDetailModal.gender.toLowerCase().includes('perempuan') || userLeaveDetailModal.gender.toLowerCase().includes('female') || userLeaveDetailModal.gender.toLowerCase() === 'p' ? '♀ Perempuan' : '♂ Laki-laki') : 'Gender: —'}
                      </span>
                      <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 font-semibold">
                        {userLeaveDetailModal.maritalStatus ? userLeaveDetailModal.maritalStatus : 'Status Nikah: —'}
                      </span>
                      {userLeaveDetailModal.isPregnant && (
                        <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-rose-50 text-rose-700 dark:bg-rose-950/40 dark:text-rose-300 font-semibold border border-rose-200 dark:border-rose-900/40">
                          <Sparkles className="w-3 h-3 text-rose-500" />
                          Status Hamil Aktif
                        </span>
                      )}
                    </div>
                  </div>
                </div>

                <button
                  type="button"
                  onClick={() => setUserLeaveDetailModal(null)}
                  className="p-1.5 rounded-xl text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 hover:bg-white dark:hover:bg-slate-800 transition cursor-pointer shrink-0"
                >
                  <X className="w-5 h-5" />
                </button>
              </div>

              {/* ── 2. SEGMENTED TAB SWITCHER ── */}
              <div className="px-6 pt-3 pb-2 border-b border-slate-100 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-900/60 flex items-center justify-between gap-3 flex-wrap">
                <div className="inline-flex p-1 bg-slate-100 dark:bg-slate-800 rounded-xl border border-slate-200/60 dark:border-slate-700/60">
                  <button
                    type="button"
                    onClick={() => setUserLeaveDetailModal(prev => prev ? { ...prev, activeTab: 'matrix' } : null)}
                    className={`flex items-center gap-2 px-3.5 py-1.5 rounded-lg text-xs font-bold transition-all cursor-pointer ${
                      userLeaveDetailModal.activeTab === 'matrix'
                        ? 'bg-white dark:bg-slate-900 text-indigo-600 dark:text-indigo-400 shadow-xs'
                        : 'text-slate-500 hover:text-slate-800 dark:hover:text-slate-200'
                    }`}
                  >
                    <SlidersHorizontal className="w-3.5 h-3.5" />
                    <span>Matriks Saldo Cuti</span>
                    <span className="px-1.5 py-0.2 rounded-full text-[10px] font-mono bg-indigo-50 dark:bg-indigo-950/50 text-indigo-600 dark:text-indigo-400 font-bold">
                      {totalActiveLeaveTypes} Aktif
                    </span>
                  </button>

                  <button
                    type="button"
                    onClick={() => setUserLeaveDetailModal(prev => prev ? { ...prev, activeTab: 'history' } : null)}
                    className={`flex items-center gap-2 px-3.5 py-1.5 rounded-lg text-xs font-bold transition-all cursor-pointer ${
                      userLeaveDetailModal.activeTab === 'history'
                        ? 'bg-white dark:bg-slate-900 text-indigo-600 dark:text-indigo-400 shadow-xs'
                        : 'text-slate-500 hover:text-slate-800 dark:hover:text-slate-200'
                    }`}
                  >
                    <History className="w-3.5 h-3.5" />
                    <span>Laporan Riwayat Cuti &amp; Penyesuaian</span>
                    <span className="px-1.5 py-0.2 rounded-full text-[10px] font-mono bg-indigo-50 dark:bg-indigo-950/50 text-indigo-600 dark:text-indigo-400 font-bold">
                      {userLeaveDetailModal.leavesHistory.length}
                    </span>
                  </button>
                </div>

                {userLeaveDetailModal.activeTab === 'matrix' && (
                  <button
                    type="button"
                    onClick={handleResetUserDetailToOfficeDefaults}
                    className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-xl bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-750 transition shadow-2xs cursor-pointer"
                    title="Isi semua kuota jenis cuti sesuai standar kantor cabang karyawan ini"
                  >
                    <RotateCcw className="w-3.5 h-3.5 text-slate-500" />
                    <span>Kembalikan ke Standar Kantor</span>
                  </button>
                )}
              </div>

              {/* ── 3. BODY TAB KONTEN ── */}
              <div className="flex-1 overflow-y-auto p-6 space-y-5">
                
                {/* ════ TAB 1: MATRIKS SALDO CUTI ════ */}
                {userLeaveDetailModal.activeTab === 'matrix' && (
                  <div className="space-y-4">
                    {/* Stat Cards Ringkasan */}
                    <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
                      <div className="p-3 bg-slate-50 dark:bg-slate-800/40 rounded-2xl border border-slate-100 dark:border-slate-800 space-y-1">
                        <p className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Total Kuota Diberikan</p>
                        <p className="text-xl font-bold font-mono text-slate-800 dark:text-slate-100 leading-tight">
                          {totalDistributedQuota} <span className="text-xs font-normal text-slate-400 font-sans">hari</span>
                        </p>
                        <p className="text-[10px] text-slate-400">Dari {totalActiveLeaveTypes} jenis cuti aktif</p>
                      </div>

                      <div className="p-3 bg-slate-50 dark:bg-slate-800/40 rounded-2xl border border-slate-100 dark:border-slate-800 space-y-1">
                        <p className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Cuti Telah Terpakai</p>
                        <p className="text-xl font-bold font-mono text-slate-800 dark:text-slate-100 leading-tight">
                          {totalUsedQuota} <span className="text-xs font-normal text-slate-400 font-sans">hari</span>
                        </p>
                        <p className="text-[10px] text-indigo-600 dark:text-indigo-400 font-medium">Periode berjalan aktif</p>
                      </div>

                      <div className="p-3 bg-slate-50 dark:bg-slate-800/40 rounded-2xl border border-slate-100 dark:border-slate-800 space-y-1">
                        <p className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Estimasi Sisa Saldo</p>
                        <p className="text-xl font-bold font-mono text-teal-600 dark:text-teal-400 leading-tight">
                          {totalRemainingQuota} <span className="text-xs font-normal text-slate-400 font-sans">hari</span>
                        </p>
                        <p className="text-[10px] text-slate-400">Tersedia untuk diajukan</p>
                      </div>

                      <div className="p-3 bg-slate-50 dark:bg-slate-800/40 rounded-2xl border border-slate-100 dark:border-slate-800 space-y-1">
                        <p className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Status Modifikasi</p>
                        <p className="text-xl font-bold font-mono text-indigo-600 dark:text-indigo-400 leading-tight">
                          {modifiedCount} <span className="text-xs font-normal text-slate-400 font-sans">jenis</span>
                        </p>
                        <p className="text-[10px] text-slate-400">
                          {modifiedCount > 0 ? 'Menunggu disimpan' : 'Semua kuota tersinkron'}
                        </p>
                      </div>
                    </div>

                    {/* Banner Info */}
                    <div className="flex items-start gap-2.5 p-3.5 bg-indigo-50/60 dark:bg-indigo-950/40 border border-indigo-100/90 dark:border-indigo-900/50 rounded-2xl text-xs text-indigo-800 dark:text-indigo-200">
                      <Info className="w-4 h-4 shrink-0 mt-0.5 text-indigo-600 dark:text-indigo-400" />
                      <div className="space-y-1 text-[11px] leading-relaxed">
                        <p><strong>Tips Pengelolaan Saldo:</strong> Anda dapat langsung mengatur <strong>sisa saldo</strong> cuti karyawan. Nilai sisa saldo 0 hari berarti hak jenis cuti tersebut sudah habis.</p>
                        <p className="text-indigo-600 dark:text-indigo-300">
                          Sisa saldo tidak boleh melebihi <strong>Standar Kantor</strong>. Jenis cuti dengan mode <strong>Akumulasi</strong> berjalan tanpa batasan (<em>unlimited</em>).
                        </p>
                      </div>
                    </div>

                    {/* Tabel Matriks Jenis Cuti */}
                    <div className="border border-slate-200 dark:border-slate-800 rounded-2xl overflow-hidden">
                      <div className="overflow-x-auto">
                        <table className="w-full text-left text-xs border-collapse">
                          <thead>
                            <tr className="bg-slate-50 dark:bg-slate-800/60 border-b border-slate-200 dark:border-slate-700/80 text-[10px] font-bold text-slate-500 uppercase tracking-wider">
                              <th className="py-3 px-3.5">Jenis Cuti</th>
                              <th className="py-3 px-3">Kelayakan Profil</th>
                              <th className="py-3 px-3 text-center">Standar Kantor</th>
                              <th className="py-3 px-3 text-center">Terpakai</th>
                              <th className="py-3 px-3 text-center">Sisa Saldo</th>
                              <th className="py-3 px-3.5 text-center">Status</th>
                            </tr>
                          </thead>
                          <tbody className="divide-y divide-slate-100 dark:divide-slate-800/70 font-sans">
                            {Object.entries(LEAVE_TYPE_LABELS).map(([leaveType, label]) => {
                              if (leaveType === 'wfh') return null; // WFH adalah mode presensi, bukan jenis hak cuti

                              const item = userLeaveDetailModal.balancesMap[leaveType] || {
                                quota: 0,
                                used: 0,
                                remaining: 0,
                                officeDefault: 0,
                              };
                              const isIzin = leaveType === 'izin';
                              const isOfficeDisabled = Boolean(item.isOfficeDisabled);
                              // isUnlimited dibaca langsung dari perhitungan modal (berdasarkan setting kantor yang aktif)
                              const isUnlimited = Boolean(item.isUnlimited);
                              const eligibility = isIzin
                                ? { isEligible: true, note: 'Berlaku untuk seluruh karyawan' }
                                : checkLeaveEligibility(leaveType, userProfile);
                              const isEligible = !isOfficeDisabled && eligibility.isEligible;
                              const isModified = !isUnlimited && !isOfficeDisabled && isEligible && item.remaining !== userLeaveDetailModal.originalBalancesMap[leaveType];
                              const isZero = !isUnlimited && !isOfficeDisabled && (item.remaining + item.used) === 0;

                              return (
                                <tr
                                  key={leaveType}
                                  className={`transition-colors ${
                                    isOfficeDisabled
                                      ? 'opacity-40 bg-rose-50/40 dark:bg-rose-950/20 select-none'
                                      : !isEligible
                                      ? 'opacity-40 bg-slate-50/80 dark:bg-slate-850/40 select-none'
                                      : isModified
                                      ? 'bg-indigo-50/30 dark:bg-indigo-950/20 hover:bg-slate-50/70 dark:hover:bg-slate-800/40'
                                      : 'hover:bg-slate-50/70 dark:hover:bg-slate-800/40'
                                  }`}
                                  title={isOfficeDisabled ? 'Dinonaktifkan di Pengaturan Kantor' : !isEligible ? `Tidak Aktif: ${eligibility.note}` : undefined}
                                >
                                  {/* Kolom Jenis Cuti */}
                                  <td className="py-3 px-3.5">
                                    <div className="flex items-center gap-2">
                                      <div className={`p-1.5 rounded-lg shrink-0 ${
                                        !isEligible
                                          ? 'bg-slate-100 dark:bg-slate-800 text-slate-400 dark:text-slate-500'
                                          : leaveType === 'cuti'
                                          ? 'bg-teal-50 dark:bg-teal-950/50 text-teal-600 dark:text-teal-400'
                                          : leaveType === 'sakit'
                                          ? 'bg-rose-50 dark:bg-rose-950/50 text-rose-600 dark:text-rose-400'
                                          : isIzin
                                          ? 'bg-amber-50 dark:bg-amber-950/50 text-amber-600 dark:text-amber-400'
                                          : 'bg-indigo-50 dark:bg-indigo-950/50 text-indigo-600 dark:text-indigo-400'
                                      }`}>
                                        <CalendarDays className="w-3.5 h-3.5" />
                                      </div>
                                      <div className="min-w-0">
                                        <div className="flex items-center gap-1.5">
                                          <p className={`font-bold text-xs ${!isEligible ? 'text-slate-400 dark:text-slate-500 line-through decoration-slate-300 dark:decoration-slate-600' : 'text-slate-800 dark:text-slate-100'}`}>
                                            {isIzin ? 'Izin' : label}
                                          </p>
                                          {isOfficeDisabled ? (
                                            <span className="text-[9px] font-semibold px-1.5 py-0.5 rounded bg-rose-100 text-rose-700 dark:bg-rose-950/60 dark:text-rose-300">
                                              Off Kantor
                                            </span>
                                          ) : isUnlimited ? (
                                            <span className="text-[9px] font-semibold px-1.5 py-0.5 rounded bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300">
                                              Tanpa Limit
                                            </span>
                                          ) : null}
                                        </div>
                                        <p className="text-[10px] text-slate-400 font-mono">
                                          {leaveType}
                                        </p>
                                      </div>
                                    </div>
                                  </td>

                                  {/* Kolom Kelayakan Profil */}
                                  <td className="py-3 px-3">
                                    {isEligible ? (
                                      <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-emerald-50 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-400 border border-emerald-200/80 dark:border-emerald-800">
                                        <CheckCircle2 className="w-3 h-3 text-emerald-600 dark:text-emerald-400" />
                                        <span>Eligible</span>
                                      </span>
                                    ) : (
                                      <span
                                        className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-amber-500/10 text-amber-600 dark:text-amber-400 border border-amber-500/20 cursor-help"
                                        title={eligibility.note}
                                      >
                                        <AlertCircle className="w-3 h-3 text-amber-600 dark:text-amber-400" />
                                        <span>Tidak Sesuai Profil</span>
                                      </span>
                                    )}
                                  </td>

                                  {/* Kolom Standar Kantor */}
                                  <td className="py-3 px-3 text-center text-xs font-mono text-slate-400 dark:text-slate-500">
                                    {isOfficeDisabled ? (
                                      <span className="text-rose-500 dark:text-rose-400 font-semibold italic text-[11px]" title="Dinonaktifkan di Pengaturan Kantor">Off</span>
                                    ) : isUnlimited ? (
                                      <span className="text-amber-600 dark:text-amber-400 font-semibold italic text-[11px]" title="Mode Akumulasi (tanpa limit standar kantor)">Akumulasi</span>
                                    ) : (
                                      `${item.officeDefault} hr`
                                    )}
                                  </td>

                                  {/* Kolom Terpakai */}
                                  <td className="py-3 px-3 text-center text-xs font-mono font-semibold text-slate-500 dark:text-slate-400">
                                    {item.used} hr
                                  </td>

                                  {/* Kolom Sisa Saldo (Editable) */}
                                  <td className="py-2 px-3 text-center">
                                    {isOfficeDisabled ? (
                                      <div className="inline-flex items-center gap-1" title="Dinonaktifkan di Pengaturan Kantor">
                                        <input
                                          type="text"
                                          disabled
                                          value="—"
                                          className="w-16 py-1 px-2 text-center text-xs font-bold font-mono rounded-xl border border-dashed border-rose-200 dark:border-rose-700 bg-rose-50 dark:bg-rose-950/30 text-rose-400 dark:text-rose-500 cursor-not-allowed select-none"
                                        />
                                      </div>
                                    ) : isUnlimited ? (
                                      <span
                                        className="inline-flex items-center gap-1.5 px-3 py-1 rounded-xl bg-amber-50 dark:bg-amber-950/40 text-amber-700 dark:text-amber-300 text-xs font-bold border border-amber-200/80 dark:border-amber-800/60 select-none cursor-default shadow-2xs"
                                        title="Mode akumulasi berjalan tanpa batasan (unlimited)."
                                      >
                                        <Infinity className="w-3.5 h-3.5 text-amber-600 dark:text-amber-400" />
                                        <span>Tanpa Limit</span>
                                      </span>
                                    ) : !isEligible ? (
                                      <div className="inline-flex items-center gap-1" title={`Dinonaktifkan: ${eligibility.note}`}>
                                        <input
                                          type="text"
                                          disabled
                                          value="—"
                                          className="w-16 py-1 px-2 text-center text-xs font-bold font-mono rounded-xl border border-dashed border-slate-200 dark:border-slate-700 bg-slate-100 dark:bg-slate-800 text-slate-400 dark:text-slate-500 cursor-not-allowed select-none"
                                        />
                                      </div>
                                    ) : (
                                      <div className="inline-flex flex-col items-center gap-0.5">
                                        <div className="inline-flex items-center gap-1">
                                          <input
                                            type="number"
                                            min={0}
                                            max={item.officeDefault > 0 ? item.officeDefault : 365}
                                            value={item.remaining}
                                            onChange={(e) => {
                                              const parsed = parseInt(e.target.value) || 0;
                                              const maxAllowed = item.officeDefault > 0 ? item.officeDefault : 365;
                                              const val = Math.min(maxAllowed, Math.max(0, parsed));
                                              setUserLeaveDetailModal(prev => {
                                                if (!prev) return null;
                                                return {
                                                  ...prev,
                                                  balancesMap: {
                                                    ...prev.balancesMap,
                                                    [leaveType]: {
                                                      ...prev.balancesMap[leaveType],
                                                      remaining: val,
                                                      quota: val + item.used,
                                                    }
                                                  }
                                                };
                                              });
                                            }}
                                            title={item.officeDefault > 0 ? `Maksimal sesuai standar kantor: ${item.officeDefault} hr` : undefined}
                                            className={`w-16 py-1 px-2 text-center text-xs font-bold font-mono rounded-xl border transition-all ${
                                              isModified
                                                ? 'border-teal-500 ring-2 ring-teal-400/20 bg-teal-50/50 dark:bg-teal-950/40 text-teal-700 dark:text-teal-300'
                                                : 'border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100'
                                            }`}
                                          />
                                          <span className="text-[10px] text-slate-400">hr</span>
                                        </div>
                                        {item.officeDefault > 0 && (
                                          <span className="text-[9px] text-slate-400">
                                            maks {item.officeDefault} hr
                                          </span>
                                        )}
                                      </div>
                                    )}
                                  </td>


                                  {/* Kolom Status */}
                                  <td className="py-3 px-3.5 text-center">
                                    {isOfficeDisabled ? (
                                      <span className="text-[10px] px-2.5 py-0.5 rounded-full bg-rose-50 text-rose-700 dark:bg-rose-950/50 dark:text-rose-300 font-semibold border border-rose-200/80 dark:border-rose-800/60">
                                        Off Kantor
                                      </span>
                                    ) : isUnlimited ? (
                                      <span className="text-[10px] px-2.5 py-0.5 rounded-full bg-indigo-50 text-indigo-700 dark:bg-indigo-950/50 dark:text-indigo-300 font-semibold border border-indigo-200/80 dark:border-indigo-800/60">
                                        Akumulasi Aktif
                                      </span>
                                    ) : !isEligible ? (
                                      <span
                                        className="text-[10px] px-2.5 py-0.5 rounded-full bg-slate-100 dark:bg-slate-800 text-slate-400 dark:text-slate-500 font-semibold border border-slate-200 dark:border-slate-700 select-none"
                                        title={eligibility.note}
                                      >
                                        Tidak Aktif
                                      </span>
                                    ) : isZero ? (
                                      <span className="text-[10px] px-2 py-0.5 rounded-full bg-slate-100 dark:bg-slate-800 text-slate-400 font-medium">
                                        Nonaktif
                                      </span>
                                    ) : item.remaining <= 0 ? (
                                      <span className="text-[10px] px-2 py-0.5 rounded-full bg-rose-50 text-rose-700 dark:bg-rose-950/40 dark:text-rose-400 font-semibold">
                                        Habis
                                      </span>
                                    ) : (
                                      <span className="text-[10px] px-2 py-0.5 rounded-full bg-teal-50 text-teal-700 dark:bg-teal-950/40 dark:text-teal-400 font-semibold">
                                        Aktif
                                      </span>
                                    )}
                                  </td>
                                </tr>
                              );
                            })}
                          </tbody>
                        </table>
                      </div>
                    </div>
                  </div>
                )}

                {/* ════ TAB 2: LAPORAN RIWAYAT CUTI TERPAKAI ════ */}
                {userLeaveDetailModal.activeTab === 'history' && (
                  <div className="space-y-4">
                    {/* Ringkasan Stat Cuti Karyawan */}
                    <div className="grid grid-cols-2 sm:grid-cols-5 gap-3">
                      <div className="p-3 bg-slate-50 dark:bg-slate-800/40 rounded-2xl border border-slate-100 dark:border-slate-800 space-y-1">
                        <p className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Total Pengajuan Cuti</p>
                        <p className="text-xl font-bold font-mono text-slate-800 dark:text-slate-100 leading-tight">
                          {userLeaveDetailModal.leavesHistory.filter((l: any) => !l.is_adjustment).length} <span className="text-xs font-normal text-slate-400 font-sans">kali</span>
                        </p>
                        <p className="text-[10px] text-slate-400">Seluruh riwayat pengajuan</p>
                      </div>

                      <div className="p-3 bg-slate-50 dark:bg-slate-800/40 rounded-2xl border border-slate-100 dark:border-slate-800 space-y-1">
                        <p className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Total Hari Disetujui</p>
                        <p className="text-xl font-bold font-mono text-teal-600 dark:text-teal-400 leading-tight">
                          {userLeaveDetailModal.historySummary.totalDays} <span className="text-xs font-normal text-slate-400 font-sans">hari</span>
                        </p>
                        <p className="text-[10px] text-teal-600 dark:text-teal-400 font-medium">Cuti sah terpakai</p>
                      </div>

                      <div className="p-3 bg-slate-50 dark:bg-slate-800/40 rounded-2xl border border-slate-100 dark:border-slate-800 space-y-1">
                        <p className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Menunggu Persetujuan</p>
                        <p className="text-xl font-bold font-mono text-amber-500 leading-tight">
                          {userLeaveDetailModal.historySummary.pending} <span className="text-xs font-normal text-slate-400 font-sans">pengajuan</span>
                        </p>
                        <p className="text-[10px] text-amber-500 font-medium">Perlu review SPV/HRD</p>
                      </div>

                      <div className="p-3 bg-slate-50 dark:bg-slate-800/40 rounded-2xl border border-slate-100 dark:border-slate-800 space-y-1">
                        <p className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Pengajuan Ditolak</p>
                        <p className="text-xl font-bold font-mono text-rose-500 leading-tight">
                          {userLeaveDetailModal.historySummary.rejected} <span className="text-xs font-normal text-slate-400 font-sans">pengajuan</span>
                        </p>
                        <p className="text-[10px] text-slate-400">Tidak memotong saldo</p>
                      </div>

                      <div className="p-3 bg-indigo-50/50 dark:bg-indigo-950/20 rounded-2xl border border-indigo-100 dark:border-indigo-900/40 space-y-1">
                        <p className="text-[10px] font-semibold text-indigo-500 uppercase tracking-wider">Penyesuaian HRD</p>
                        <p className="text-xl font-bold font-mono text-indigo-600 dark:text-indigo-400 leading-tight">
                          {userLeaveDetailModal.historySummary.adjustments || 0} <span className="text-xs font-normal text-slate-400 font-sans">kali</span>
                        </p>
                        <p className="text-[10px] text-indigo-500/80 font-medium">Penyesuaian sisa saldo</p>
                      </div>
                    </div>

                    {/* Filter & Search Bar */}
                    <div className="flex items-center justify-between gap-3 flex-wrap bg-slate-50 dark:bg-slate-800/50 p-3 rounded-2xl border border-slate-100 dark:border-slate-800">
                      <div className="flex items-center gap-2 flex-wrap">
                        {/* Filter Status */}
                        <select
                          value={userLeaveDetailModal.historyFilterStatus}
                          onChange={(e) => setUserLeaveDetailModal(prev => prev ? { ...prev, historyFilterStatus: e.target.value } : null)}
                          className="py-1.5 px-3 text-xs border border-slate-200 dark:border-slate-700 rounded-xl bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 focus:outline-none focus:ring-1 focus:ring-indigo-400"
                        >
                          <option value="all">Semua Status &amp; Riwayat</option>
                          <option value="approved">Disetujui</option>
                          <option value="pending">Menunggu Persetujuan</option>
                          <option value="rejected">Ditolak</option>
                          <option value="adjustment">Penyesuaian Sisa Saldo (HRD)</option>
                        </select>

                        {/* Filter Jenis Cuti */}
                        <select
                          value={userLeaveDetailModal.historyFilterType}
                          onChange={(e) => setUserLeaveDetailModal(prev => prev ? { ...prev, historyFilterType: e.target.value } : null)}
                          className="py-1.5 px-3 text-xs border border-slate-200 dark:border-slate-700 rounded-xl bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 focus:outline-none focus:ring-1 focus:ring-indigo-400 max-w-[200px]"
                        >
                          <option value="all">Semua Jenis Cuti</option>
                          {Object.entries(LEAVE_TYPE_LABELS).map(([k, label]) => (
                            <option key={k} value={k}>{label}</option>
                          ))}
                        </select>
                      </div>

                      <div className="relative">
                        <Search className="absolute left-2.5 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-slate-400 pointer-events-none" />
                        <input
                          type="text"
                          placeholder="Cari alasan / tanggal..."
                          value={userLeaveDetailModal.historySearch}
                          onChange={(e) => setUserLeaveDetailModal(prev => prev ? { ...prev, historySearch: e.target.value } : null)}
                          className="pl-8 pr-3 py-1.5 text-xs border border-slate-200 dark:border-slate-700 rounded-xl bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 placeholder-slate-400 focus:outline-none focus:ring-1 focus:ring-indigo-400 w-52"
                        />
                      </div>
                    </div>

                    {/* Konten Daftar Riwayat Cuti */}
                    {userLeaveDetailModal.loadingHistory ? (
                      <div className="p-12 text-center space-y-2">
                        <Loader2 className="w-8 h-8 text-indigo-500 animate-spin mx-auto" />
                        <p className="text-xs text-slate-500">Memuat riwayat pengajuan cuti karyawan...</p>
                      </div>
                    ) : filteredLeavesHistory.length === 0 ? (
                      <div className="p-10 border border-dashed border-slate-200 dark:border-slate-800 rounded-2xl text-center space-y-2">
                        <CalendarCheck className="w-8 h-8 text-slate-300 dark:text-slate-600 mx-auto" />
                        <p className="text-xs font-semibold text-slate-600 dark:text-slate-300">
                          {userLeaveDetailModal.leavesHistory.length === 0
                            ? 'Karyawan ini belum pernah mengajukan cuti, izin, atau sakit.'
                            : 'Tidak ada riwayat cuti yang cocok dengan filter yang dipilih.'}
                        </p>
                        <p className="text-[11px] text-slate-400">Semua permohonan cuti resmi akan tercatat di sini secara otomatis.</p>
                      </div>
                    ) : (
                      <div className="space-y-2.5">
                        {filteredLeavesHistory.map((item: any) => {
                          if (item.is_adjustment || item.status === 'adjustment') {
                            const diff = Number(item.difference) || 0;
                            const diffStr = diff > 0 ? `+${diff}` : `${diff}`;
                            return (
                              <div
                                key={item.id}
                                className="p-3.5 rounded-2xl border border-indigo-100 dark:border-indigo-900/50 bg-gradient-to-r from-indigo-50/40 via-white to-purple-50/20 dark:from-indigo-950/20 dark:via-slate-900 dark:to-purple-950/10 hover:shadow-xs transition space-y-2.5"
                              >
                                <div className="flex items-start justify-between gap-3">
                                  <div className="space-y-1">
                                    <div className="flex items-center gap-2 flex-wrap">
                                      <span className="px-2 py-0.5 rounded-md text-[11px] font-bold bg-indigo-100 text-indigo-700 dark:bg-indigo-900/60 dark:text-indigo-300 border border-indigo-200 dark:border-indigo-800">
                                        {item.leave_type_label || getLeaveTypeLabel(item.leave_type)}
                                      </span>
                                      <span className="text-xs font-bold text-slate-800 dark:text-slate-100 flex items-center gap-1.5">
                                        <SlidersHorizontal className="w-3.5 h-3.5 text-indigo-500" />
                                        <span>Penyesuaian Sisa Saldo oleh HRD</span>
                                      </span>
                                      <span className={`font-mono text-xs px-2 py-0.5 rounded-full font-bold ${
                                        diff > 0
                                          ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300'
                                          : diff < 0
                                          ? 'bg-amber-100 text-amber-700 dark:bg-amber-950/60 dark:text-amber-300'
                                          : 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300'
                                      }`}>
                                        {diffStr} hari
                                      </span>
                                    </div>

                                    {item.reason && (
                                      <p className="text-xs text-slate-600 dark:text-slate-300 bg-white/80 dark:bg-slate-800/60 p-2 rounded-xl border border-indigo-100/60 dark:border-indigo-900/40 leading-relaxed">
                                        "{item.reason}"
                                      </p>
                                    )}
                                  </div>

                                  <div className="shrink-0 text-right space-y-1">
                                    <span className="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[11px] font-bold bg-indigo-50 text-indigo-700 dark:bg-indigo-950/40 dark:text-indigo-300 border border-indigo-200/80 dark:border-indigo-800">
                                      <SlidersHorizontal className="w-3.5 h-3.5" />
                                      Penyesuaian HRD
                                    </span>
                                    {item.created_at && (
                                      <p className="text-[10px] text-slate-400">
                                        Waktu: {new Date(item.created_at).toLocaleString('id-ID', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })}
                                      </p>
                                    )}
                                  </div>
                                </div>

                                <div className="flex items-center justify-between gap-2 pt-2 border-t border-indigo-100/60 dark:border-indigo-900/40 text-[11px] text-slate-500 flex-wrap">
                                  <div className="flex items-center gap-2">
                                    <span>Disesuaikan oleh: <strong className="text-slate-700 dark:text-slate-200">{item.adjusted_by_name || item.approved_by || 'HRD'}</strong></span>
                                    <span>•</span>
                                    <span>Tahun: <strong className="font-mono text-slate-700 dark:text-slate-200">{item.year}</strong></span>
                                  </div>
                                  <div className="font-mono text-xs font-semibold text-slate-600 dark:text-slate-300">
                                    Perubahan: {item.old_quota} hr &rarr; {item.new_quota} hr ({diffStr} hr)
                                  </div>
                                </div>
                              </div>
                            );
                          }

                          const isApproved = item.status === 'approved';
                          const isPending = item.status === 'pending';
                          const isRejected = item.status === 'rejected';

                          return (
                            <div
                              key={item.id}
                              className="p-3.5 rounded-2xl border border-slate-100 dark:border-slate-800 bg-white dark:bg-slate-900 hover:shadow-xs transition space-y-2.5"
                            >
                              <div className="flex items-start justify-between gap-3">
                                <div className="space-y-1">
                                  <div className="flex items-center gap-2 flex-wrap">
                                    <span className="px-2 py-0.5 rounded-md text-[11px] font-bold bg-indigo-50 text-indigo-700 dark:bg-indigo-950/50 dark:text-indigo-300 border border-indigo-100 dark:border-indigo-900/40">
                                      {getLeaveTypeLabel(item.leave_type)}
                                    </span>
                                    <span className="text-xs font-bold text-slate-800 dark:text-slate-100">
                                      {item.start_date} {item.end_date && item.end_date !== item.start_date ? `s.d. ${item.end_date}` : ''}
                                    </span>
                                    <span className="font-mono text-xs px-2 py-0.5 rounded-full bg-slate-100 dark:bg-slate-800 font-bold text-slate-700 dark:text-slate-200">
                                      {item.total_days} hari
                                    </span>
                                  </div>

                                  {item.reason && (
                                    <p className="text-xs text-slate-600 dark:text-slate-300 bg-slate-50 dark:bg-slate-800/40 p-2 rounded-xl border border-slate-100 dark:border-slate-800/60 leading-relaxed">
                                      "{item.reason}"
                                    </p>
                                  )}
                                </div>

                                <div className="shrink-0 text-right space-y-1">
                                  {isApproved && (
                                    <span className="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[11px] font-bold bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-400 border border-emerald-200/80 dark:border-emerald-800">
                                      <Check className="w-3.5 h-3.5" />
                                      Disetujui
                                    </span>
                                  )}
                                  {isPending && (
                                    <span className="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[11px] font-bold bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-400 border border-amber-200/80 dark:border-amber-800">
                                      <Clock className="w-3.5 h-3.5" />
                                      Menunggu
                                    </span>
                                  )}
                                  {isRejected && (
                                    <span className="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[11px] font-bold bg-rose-50 text-rose-700 dark:bg-rose-950/40 dark:text-rose-400 border border-rose-200/80 dark:border-rose-800">
                                      <X className="w-3.5 h-3.5" />
                                      Ditolak
                                    </span>
                                  )}

                                  {item.created_at && (
                                    <p className="text-[10px] text-slate-400">
                                      Diajukan: {new Date(item.created_at).toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' })}
                                    </p>
                                  )}
                                </div>
                              </div>

                              {/* Footer detail approval & dokumen surat dokter */}
                              <div className="flex items-center justify-between gap-2 pt-2 border-t border-slate-100 dark:border-slate-800/80 text-[11px] text-slate-500 flex-wrap">
                                <div>
                                  {isApproved && item.approved_by && (
                                    <span>Disetujui oleh: <strong className="text-slate-700 dark:text-slate-200">{item.approved_by}</strong></span>
                                  )}
                                  {isRejected && item.rejection_reason && (
                                    <span className="text-rose-600 dark:text-rose-400">Alasan penolakan: <em>{item.rejection_reason}</em></span>
                                  )}
                                  {item.holiday_id && (
                                    <span className="ml-2 px-1.5 py-0.5 rounded bg-amber-50 dark:bg-amber-950/50 text-amber-700 dark:text-amber-400 font-semibold text-[10px]">
                                      Cuti Bersama (Kantor)
                                    </span>
                                  )}
                                </div>

                                {item.has_document && (
                                  <button
                                    type="button"
                                    onClick={() => openLeaveDocument(item.id, userLeaveDetailModal.userName)}
                                    disabled={docLoadingId === item.id}
                                    className="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-semibold rounded-lg bg-indigo-50 hover:bg-indigo-100 text-indigo-700 dark:bg-indigo-950/50 dark:hover:bg-indigo-900/60 dark:text-indigo-300 transition cursor-pointer"
                                  >
                                    {docLoadingId === item.id ? (
                                      <Loader2 className="w-3.5 h-3.5 animate-spin" />
                                    ) : (
                                      <FileText className="w-3.5 h-3.5" />
                                    )}
                                    <span>Lihat Bukti Surat Dokter</span>
                                  </button>
                                )}
                              </div>
                            </div>
                          );
                        })}
                      </div>
                    )}
                  </div>
                )}

              </div>

              {/* ── 4. STICKY MODAL FOOTER ── */}
              <div className="px-6 py-4 border-t border-slate-100 dark:border-slate-800 bg-slate-50/80 dark:bg-slate-900/80 flex items-center justify-between gap-3">
                <div className="text-xs text-slate-500 dark:text-slate-400">
                  {userLeaveDetailModal.activeTab === 'matrix' ? (
                    modifiedCount > 0 ? (
                      <span className="font-semibold text-indigo-600 dark:text-indigo-400">
                        {modifiedCount} sisa saldo jenis cuti telah dimodifikasi (belum disimpan).
                      </span>
                    ) : (
                      <span>Seluruh nilai kuota sesuai data tersimpan di sistem.</span>
                    )
                  ) : (
                    <span>Menampilkan {filteredLeavesHistory.length} dari {userLeaveDetailModal.leavesHistory.length} riwayat pengajuan &amp; penyesuaian saldo.</span>
                  )}
                </div>

                <div className="flex items-center gap-2">
                  <button
                    type="button"
                    onClick={() => setUserLeaveDetailModal(null)}
                    disabled={isSavingUserDetailBalances}
                    className="px-4 py-2 text-xs font-semibold text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-xl transition cursor-pointer"
                  >
                    Tutup
                  </button>

                  {userLeaveDetailModal.activeTab === 'matrix' && (
                    <button
                      type="button"
                      onClick={handleSaveUserDetailBalances}
                      disabled={isSavingUserDetailBalances || modifiedCount === 0}
                      className="flex items-center gap-1.5 px-5 py-2 text-xs font-bold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl shadow-md transition disabled:opacity-50 disabled:cursor-not-allowed cursor-pointer"
                    >
                      {isSavingUserDetailBalances ? (
                        <>
                          <Loader2 className="w-4 h-4 animate-spin" />
                          <span>Menyimpan Saldo...</span>
                        </>
                      ) : (
                        <>
                          <Check className="w-4 h-4" />
                          <span>Simpan Semua Perubahan ({modifiedCount})</span>
                        </>
                      )}
                    </button>
                  )}
                </div>
              </div>

            </div>
          </div>
        );
      })()}

      {/* ── Modal Penyesuaian Saldo Cuti Individu Karyawan ── */}
      {editUserBalanceModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/60 backdrop-blur-xs">
          <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-3xl shadow-2xl max-w-md w-full overflow-hidden animate-in fade-in zoom-in-95 duration-150">
            {/* Header */}
            <div className="px-6 py-4 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
              <div className="flex items-center gap-2.5">
                <div className="p-2 rounded-xl bg-indigo-50 dark:bg-indigo-950/50 text-indigo-600 dark:text-indigo-400">
                  <SlidersHorizontal className="w-4 h-4" />
                </div>
                <div>
                  <h3 className="text-sm font-bold text-slate-800 dark:text-slate-100">Sesuaikan Saldo Cuti</h3>
                  <p className="text-[11px] text-slate-400">Atur kuota khusus untuk karyawan ini</p>
                </div>
              </div>
              <button
                type="button"
                onClick={() => setEditUserBalanceModal(null)}
                className="p-1 rounded-lg text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 transition cursor-pointer"
              >
                <X className="w-4 h-4" />
              </button>
            </div>

            {/* Body */}
            <div className="p-6 space-y-4 text-xs">
              {/* Profil Karyawan Info */}
              <div className="bg-slate-50 dark:bg-slate-800/40 p-3 rounded-2xl border border-slate-100 dark:border-slate-800 space-y-1">
                <p className="font-bold text-slate-800 dark:text-slate-100 text-sm">
                  {editUserBalanceModal.user_name}
                  {editUserBalanceModal.employee_code && (
                    <span className="font-mono text-xs font-normal text-slate-400 ml-1.5">
                      ({editUserBalanceModal.employee_code})
                    </span>
                  )}
                </p>
                {editUserBalanceModal.office_name && (
                  <p className="text-[11px] text-slate-400 flex items-center gap-1">
                    <Building2 className="w-3 h-3 text-slate-400" />
                    {editUserBalanceModal.office_name}
                  </p>
                )}
              </div>

              {/* Pilihan Jenis Cuti */}
              <div className="space-y-1.5">
                <label className="font-semibold text-slate-700 dark:text-slate-300">
                  Pilih Jenis Cuti:
                </label>
                <select
                  value={editUserBalanceModal.leave_type}
                  onChange={(e) => {
                    const newType = e.target.value;
                    const foundUserBalances = balances.filter(b => b.user_id === editUserBalanceModal.user_id);
                    const matching = foundUserBalances.find(b => b.leave_type === newType);
                    setEditUserBalanceModal({
                      ...editUserBalanceModal,
                      leave_type: newType,
                      quota: matching?.quota ?? (newType === 'cuti' ? 12 : newType === 'cuti_setengah_hari' ? 10 : 0),
                      used: matching?.used ?? 0,
                      remaining: matching?.remaining ?? 0,
                    });
                  }}
                  className="w-full py-2 px-3 border border-slate-200 dark:border-slate-700 rounded-xl bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-1 focus:ring-indigo-400 font-medium cursor-pointer"
                >
                  {Object.entries(LEAVE_TYPE_LABELS)
                    .filter(([k]) => {
                      if (k === 'wfh') return false;
                      const foundUserBalance = balances.find(b => b.user_id === editUserBalanceModal.user_id && b.leave_type === k);
                      // Sembunyikan tipe cuti yang dinonaktifkan di pengaturan kantor
                      if (foundUserBalance && (foundUserBalance.is_enabled_in_office === false || foundUserBalance.is_disabled === true)) {
                        return false;
                      }
                      if (k === 'izin') {
                        return (foundUserBalance?.office_default_quota ?? 0) > 0;
                      }
                      return true;
                    })
                    .map(([k, label]) => (
                      <option key={k} value={k}>
                        {label} ({k})
                      </option>
                    ))}
                </select>

                {LEAVE_TYPE_ELIGIBILITY_MAP[editUserBalanceModal.leave_type] && (
                  <div className="flex items-start gap-1.5 p-2 rounded-lg bg-indigo-50/70 dark:bg-indigo-950/30 border border-indigo-100 dark:border-indigo-900/30 text-[10px] text-indigo-700 dark:text-indigo-300">
                    <Info className="w-3.5 h-3.5 shrink-0 mt-0.5 text-indigo-600 dark:text-indigo-400" />
                    <p>
                      <strong>Ketentuan Syarat Karyawan: </strong>
                      {LEAVE_TYPE_ELIGIBILITY_MAP[editUserBalanceModal.leave_type].note}
                    </p>
                  </div>
                )}
              </div>

              {/* Input Sisa Saldo */}
              <div className="space-y-1.5">
                <label className="font-semibold text-slate-700 dark:text-slate-300 flex items-center justify-between">
                  <span>Sisa Saldo (Hari):</span>
                  <span className="text-[10px] text-slate-400">
                    {(() => {
                      const found = balances.find(b => b.user_id === editUserBalanceModal.user_id && b.leave_type === editUserBalanceModal.leave_type);
                      const officeLimit = found?.office_default_quota ?? 0;
                      return officeLimit > 0 ? `Maks standar kantor: ${officeLimit} hr` : '0 = Habis';
                    })()}
                  </span>
                </label>
                <div className="relative">
                  <input
                    type="number"
                    min={0}
                    max={(() => {
                      const found = balances.find(b => b.user_id === editUserBalanceModal.user_id && b.leave_type === editUserBalanceModal.leave_type);
                      return found?.office_default_quota && found.office_default_quota > 0 ? found.office_default_quota : 365;
                    })()}
                    value={editUserBalanceModal.remaining ?? Math.max(0, editUserBalanceModal.quota - editUserBalanceModal.used)}
                    onChange={(e) => {
                      const val = Number(e.target.value);
                      const found = balances.find(b => b.user_id === editUserBalanceModal.user_id && b.leave_type === editUserBalanceModal.leave_type);
                      const officeLimit = found?.office_default_quota ?? 0;
                      const maxAllowed = officeLimit > 0 ? officeLimit : 365;
                      const parsed = isNaN(val) ? 0 : Math.min(maxAllowed, Math.max(0, val));
                      setEditUserBalanceModal({
                        ...editUserBalanceModal,
                        remaining: parsed,
                        quota: parsed + editUserBalanceModal.used,
                      });
                    }}
                    className="w-full py-2 px-3 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-800 font-mono font-bold text-sm text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-1 focus:ring-indigo-400"
                  />
                  <span className="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 font-medium text-xs">
                    hari
                  </span>
                </div>
              </div>

              {/* Status Pemakaian & Standar Kantor */}
              <div className="grid grid-cols-2 gap-2 text-center pt-1">
                <div className="p-2.5 rounded-xl bg-slate-50 dark:bg-slate-800/40 border border-slate-100 dark:border-slate-800">
                  <p className="text-[10px] text-slate-400 uppercase font-semibold">Telah Dipakai</p>
                  <p className="text-sm font-bold text-slate-700 dark:text-slate-200 mt-0.5">
                    {editUserBalanceModal.used} <span className="text-[10px] font-normal text-slate-400">hari</span>
                  </p>
                </div>
                <div className="p-2.5 rounded-xl bg-slate-50 dark:bg-slate-800/40 border border-slate-100 dark:border-slate-800">
                  <p className="text-[10px] text-slate-400 uppercase font-semibold">Standar Kantor</p>
                  <p className="text-sm font-bold text-teal-600 dark:text-teal-400 mt-0.5">
                    {(() => {
                      const found = balances.find(b => b.user_id === editUserBalanceModal.user_id && b.leave_type === editUserBalanceModal.leave_type);
                      return (found?.office_default_quota ?? 0) > 0 ? `${found?.office_default_quota} hari` : 'Akumulasi';
                    })()}
                  </p>
                </div>
              </div>
            </div>

            {/* Footer */}
            <div className="px-6 py-3.5 border-t border-slate-100 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-900/50 flex items-center justify-end gap-2">
              <button
                type="button"
                onClick={() => setEditUserBalanceModal(null)}
                disabled={isSavingUserBalance}
                className="px-4 py-2 text-xs font-semibold text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-xl transition cursor-pointer"
              >
                Batal
              </button>
              <button
                type="button"
                onClick={handleSaveUserBalance}
                disabled={isSavingUserBalance}
                className="flex items-center gap-1.5 px-4 py-2 text-xs font-bold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl shadow-sm transition disabled:opacity-50 cursor-pointer"
              >
                {isSavingUserBalance ? (
                  <>
                    <Loader2 className="w-3.5 h-3.5 animate-spin" />
                    <span>Menyimpan...</span>
                  </>
                ) : (
                  <>
                    <Check className="w-3.5 h-3.5" />
                    <span>Simpan Saldo</span>
                  </>
                )}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* ── Modal Rincian Snapshot Cuti Khusus Periode Lalu ── */}
      {viewingSnapshotHistory && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/60 backdrop-blur-xs">
          <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-3xl shadow-2xl max-w-xl w-full overflow-hidden animate-in fade-in zoom-in-95 duration-150">
            {/* Header */}
            <div className="px-6 py-4 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
              <div className="flex items-center gap-2.5">
                <div className="p-2 rounded-xl bg-purple-50 dark:bg-purple-950/50 text-purple-600 dark:text-purple-400">
                  <Archive className="w-4 h-4" />
                </div>
                <div>
                  <h3 className="text-sm font-bold text-slate-800 dark:text-slate-100">Snapshot Cuti Khusus</h3>
                  <p className="text-[11px] text-slate-400">Arsip rincian hak cuti periode lampau yang tersimpan saat reset</p>
                </div>
              </div>
              <button
                type="button"
                onClick={() => setViewingSnapshotHistory(null)}
                className="p-1 rounded-lg text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 transition cursor-pointer"
              >
                <X className="w-4 h-4" />
              </button>
            </div>

            {/* Info Karyawan & Periode */}
            <div className="p-6 space-y-4 text-xs">
              <div className="bg-slate-50 dark:bg-slate-800/40 p-3 rounded-2xl border border-slate-100 dark:border-slate-800 space-y-1">
                <div className="flex items-center justify-between">
                  <p className="font-bold text-slate-800 dark:text-slate-100 text-sm">
                    {viewingSnapshotHistory.user_name}
                  </p>
                  <span className="font-mono text-[10px] text-slate-400">
                    {viewingSnapshotHistory.employee_code}
                  </span>
                </div>
                <div className="flex items-center gap-2 text-[11px] text-slate-400 flex-wrap">
                  <span>{viewingSnapshotHistory.office_name}</span>
                  <span>•</span>
                  <span>Periode: <strong>{viewingSnapshotHistory.period_label}</strong></span>
                  <span>•</span>
                  <span>Reset: {viewingSnapshotHistory.reset_date_formatted || fmtDate(viewingSnapshotHistory.reset_date)}</span>
                </div>
              </div>

              {/* Tabel Snapshot Cuti Khusus */}
              <div className="border border-slate-100 dark:border-slate-800 rounded-2xl overflow-hidden">
                <table className="w-full text-xs text-left">
                  <thead>
                    <tr className="bg-slate-50/70 dark:bg-slate-800/50 border-b border-slate-100 dark:border-slate-800 text-slate-500">
                      <th className="py-2.5 px-3 font-semibold">Jenis Cuti</th>
                      <th className="py-2.5 px-3 font-semibold text-center">Kuota Awal</th>
                      <th className="py-2.5 px-3 font-semibold text-center">Terpakai</th>
                      <th className="py-2.5 px-3 font-semibold text-center">Sisa Hangus</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100 dark:divide-slate-800/60 font-mono">
                    {Object.entries(viewingSnapshotHistory.leave_types_snapshot || {}).map(([type, snap]: [string, any]) => (
                      <tr key={type} className="hover:bg-slate-50/40 dark:hover:bg-slate-800/20">
                        <td className="py-2.5 px-3 font-sans">
                          <p className="font-bold text-slate-700 dark:text-slate-200">{getLeaveTypeLabel(type)}</p>
                          <p className="text-[9px] text-slate-400 font-mono">{type}</p>
                        </td>
                        <td className="py-2.5 px-3 text-center text-slate-600 dark:text-slate-400">
                          {snap?.quota ?? snap?.quota_days ?? 0} hr
                        </td>
                        <td className="py-2.5 px-3 text-center text-indigo-600 dark:text-indigo-400 font-bold">
                          {snap?.used ?? 0} hr
                        </td>
                        <td className="py-2.5 px-3 text-center text-amber-600 dark:text-amber-400 font-bold">
                          {snap?.remaining ?? 0} hr
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>

            {/* Footer */}
            <div className="px-6 py-3 border-t border-slate-100 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-900/50 flex items-center justify-end">
              <button
                type="button"
                onClick={() => setViewingSnapshotHistory(null)}
                className="px-4 py-1.5 text-xs font-semibold text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-xl transition cursor-pointer"
              >
                Tutup
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Modal Dialog Panduan & Regulasi Pengaturan Cuti Kantor */}
      {showLeaveInfoModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-5 bg-slate-950/70 backdrop-blur-xs animate-in fade-in">
          <div className="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl w-full max-w-5xl max-h-[92vh] flex flex-col shadow-2xl overflow-hidden">
            {/* Header Modal */}
            <div className="px-6 py-4 border-b border-slate-100 dark:border-slate-800 flex items-start justify-between gap-4 bg-slate-50/50 dark:bg-slate-900/50">
              <div className="flex items-center gap-3">
                <div className="p-2.5 rounded-2xl bg-gradient-to-br from-indigo-500 to-purple-600 text-white shadow-md shadow-indigo-500/20">
                  <BookOpen className="w-5 h-5" />
                </div>
                <div>
                  <div className="flex items-center gap-2 flex-wrap">
                    <h3 className="text-base font-bold text-slate-900 dark:text-slate-100">
                      Panduan &amp; Regulasi Pengaturan Cuti Kantor
                    </h3>
                    <span className="px-2 py-0.5 rounded-full text-[10px] font-bold bg-indigo-100 text-indigo-800 dark:bg-indigo-950/60 dark:text-indigo-300 border border-indigo-200 dark:border-indigo-800/60">
                      Standar UU Ketenagakerjaan &amp; UU KIA
                    </span>
                  </div>
                  <p className="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                    Dokumentasi lengkap fungsi halaman, aturan saldo &amp; siklus tahunan, validasi otomatis profil karyawan, serta matriks 15 jenis cuti resmi.
                  </p>
                </div>
              </div>

              <button
                type="button"
                onClick={() => setShowLeaveInfoModal(false)}
                className="p-2 text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-xl transition cursor-pointer"
                title="Tutup Modal"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            {/* Navigasi Tab Segmented */}
            <div className="px-6 pt-3 pb-2 border-b border-slate-100 dark:border-slate-800 bg-white dark:bg-slate-900 flex items-center gap-2 overflow-x-auto">
              <button
                type="button"
                onClick={() => setLeaveInfoModalTab('overview')}
                className={`flex items-center gap-2 px-3.5 py-2 rounded-xl text-xs font-bold transition cursor-pointer shrink-0 ${
                  leaveInfoModalTab === 'overview'
                    ? 'bg-indigo-600 text-white shadow-sm shadow-indigo-600/20'
                    : 'text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800'
                }`}
              >
                <SlidersHorizontal className="w-4 h-4" />
                <span>1. Fungsi Halaman &amp; Siklus Kantor</span>
              </button>

              <button
                type="button"
                onClick={() => setLeaveInfoModalTab('validation')}
                className={`flex items-center gap-2 px-3.5 py-2 rounded-xl text-xs font-bold transition cursor-pointer shrink-0 ${
                  leaveInfoModalTab === 'validation'
                    ? 'bg-indigo-600 text-white shadow-sm shadow-indigo-600/20'
                    : 'text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800'
                }`}
              >
                <ShieldCheck className="w-4 h-4" />
                <span>2. Sistem Validasi Profil Karyawan</span>
              </button>

              <button
                type="button"
                onClick={() => setLeaveInfoModalTab('catalog')}
                className={`flex items-center gap-2 px-3.5 py-2 rounded-xl text-xs font-bold transition cursor-pointer shrink-0 ${
                  leaveInfoModalTab === 'catalog'
                    ? 'bg-indigo-600 text-white shadow-sm shadow-indigo-600/20'
                    : 'text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800'
                }`}
              >
                <FileText className="w-4 h-4" />
                <span>3. Matriks 15 Jenis Cuti Resmi UU</span>
              </button>
            </div>

            {/* Isi Konten Tab */}
            <div className="p-6 overflow-y-auto flex-1 min-h-0 space-y-6 text-slate-700 dark:text-slate-300">
              {/* TAB 1: OVERVIEW HALAMAN & KEBIJAKAN KANTOR */}
              {leaveInfoModalTab === 'overview' && (
                <div className="space-y-6">
                  {/* Hero Pengantar */}
                  <div className="bg-gradient-to-r from-indigo-50 to-purple-50 dark:from-indigo-950/30 dark:to-purple-950/20 border border-indigo-100 dark:border-indigo-900/40 rounded-2xl p-4.5">
                    <h4 className="text-sm font-bold text-indigo-950 dark:text-indigo-200 flex items-center gap-2">
                      <Sparkles className="w-4 h-4 text-indigo-600 dark:text-indigo-400" />
                      Otonomi Kebijakan Cuti &amp; Saldo Cabang Kantor
                    </h4>
                    <p className="text-xs text-indigo-900/80 dark:text-indigo-300/80 leading-relaxed mt-1.5">
                      Setiap kantor cabang dapat memiliki karakteristik operasional yang berbeda (misalnya kantor pusat korporat vs gudang logistik vs pabrik produksi). Tab ini memungkinkan HRD/Superadmin menetapkan parameter kebijakan cuti spesifik untuk kantor <strong>{leaveTypeSettingsOfficeName || 'cabang yang dipilih'}</strong> tanpa mengganggu kantor cabang lain.
                    </p>
                  </div>

                  {/* 4 Pilar Fitur Halaman */}
                  <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    {/* Pilar 1 */}
                    <div className="border border-slate-200 dark:border-slate-800 rounded-2xl p-4 bg-slate-50/50 dark:bg-slate-800/20 space-y-2">
                      <div className="flex items-center gap-2 text-teal-600 dark:text-teal-400 font-bold text-xs">
                        <CalendarDays className="w-4 h-4" />
                        <h5>Saldo Cuti Default (Hari/Tahun)</h5>
                      </div>
                      <p className="text-xs text-slate-600 dark:text-slate-300 leading-relaxed">
                        Jumlah kuota hak cuti tahunan awal yang dialokasikan ke profil karyawan saat akun mereka pertama kali diaktifkan atau dipindahkan ke cabang ini (standar umum ketenagakerjaan adalah <strong>12 hari kerja</strong>).
                      </p>
                    </div>

                    {/* Pilar 2 */}
                    <div className="border border-slate-200 dark:border-slate-800 rounded-2xl p-4 bg-slate-50/50 dark:bg-slate-800/20 space-y-2">
                      <div className="flex items-center gap-2 text-indigo-600 dark:text-indigo-400 font-bold text-xs">
                        <History className="w-4 h-4" />
                        <h5>Siklus Reset Tahunan (Anniversary Reset)</h5>
                      </div>
                      <p className="text-xs text-slate-600 dark:text-slate-300 leading-relaxed">
                        Tanggal otomatis di mana saldo cuti tahunan karyawan di-reset. Saat tanggal tersebut tiba, sisa cuti periode berjalan otomatis diarsipkan ke <em>Riwayat Saldo Sebelumnya</em> untuk menjaga akuntabilitas audit, dan kuota baru diisikan kembali secara otomatis.
                      </p>
                    </div>

                    {/* Pilar 3 */}
                    <div className="border border-slate-200 dark:border-slate-800 rounded-2xl p-4 bg-slate-50/50 dark:bg-slate-800/20 space-y-2">
                      <div className="flex items-center gap-2 text-amber-600 dark:text-amber-400 font-bold text-xs">
                        <Users className="w-4 h-4" />
                        <h5>Persetujuan Berjenjang (Multi-Level Approval)</h5>
                      </div>
                      <p className="text-xs text-slate-600 dark:text-slate-300 leading-relaxed">
                        Jika diaktifkan, pengajuan izin/cuti wajib diverifikasi dan disetujui oleh <strong>Atasan Langsung (SPV)</strong> karyawan terlebih dahulu sebelum diteruskan ke <strong>HRD</strong> untuk persetujuan akhir dan pemotongan saldo.
                      </p>
                    </div>

                    {/* Pilar 4 */}
                    <div className="border border-slate-200 dark:border-slate-800 rounded-2xl p-4 bg-slate-50/50 dark:bg-slate-800/20 space-y-2">
                      <div className="flex items-center gap-2 text-purple-600 dark:text-purple-400 font-bold text-xs">
                        <SlidersHorizontal className="w-4 h-4" />
                        <h5>Aktivasi Cuti, Kuota &amp; Dokumen</h5>
                      </div>
                      <p className="text-xs text-slate-600 dark:text-slate-300 leading-relaxed">
                        3 jenis dasar (<em>Cuti Tahunan, Izin, WFH</em>) selalu aktif. <strong>Cuti Sakit</strong> serta 11 jenis cuti khusus berbayar lainnya dapat dikonfigurasi per kantor cabang (menentukan standar kuota hari, kewajiban berkas surat dokter/dokumen pendukung, serta catatan SOP kebijakan kantor).
                      </p>
                    </div>
                  </div>
                </div>
              )}

              {/* TAB 2: SISTEM VALIDASI PROFIL KARYAWAN OTOMATIS */}
              {leaveInfoModalTab === 'validation' && (
                <div className="space-y-6">
                  <div className="bg-amber-50/70 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-900/40 rounded-2xl p-4 space-y-1.5">
                    <h4 className="text-xs font-bold text-amber-900 dark:text-amber-200 flex items-center gap-2">
                      <ShieldCheck className="w-4 h-4 text-amber-600 dark:text-amber-400" />
                      Proteksi &amp; Validasi Otomatis Data Master Karyawan
                    </h4>
                    <p className="text-xs text-amber-800/90 dark:text-amber-300/90 leading-relaxed">
                      Sistem ExpenseFlow mencegah kesalahan manusia (human error) dan penyalahgunaan hak cuti dengan memvalidasi data profil master karyawan secara real-time saat formulir pengajuan izin/cuti disubmit oleh karyawan.
                    </p>
                  </div>

                  {/* 3 Validasi Kunci */}
                  <div className="space-y-3.5">
                    {/* Validasi 1: Gender */}
                    <div className="border border-slate-200 dark:border-slate-800 rounded-2xl p-4.5 bg-white dark:bg-slate-900 space-y-2.5">
                      <div className="flex items-center justify-between gap-2">
                        <h5 className="text-xs font-bold text-slate-800 dark:text-slate-100 flex items-center gap-2">
                          <span className="w-6 h-6 rounded-full bg-rose-100 dark:bg-rose-950/60 text-rose-700 dark:text-rose-300 flex items-center justify-center text-xs font-bold">1</span>
                          Validasi Pembatasan Gender (Gender Restriction)
                        </h5>
                        <span className="px-2 py-0.5 rounded-full text-[10px] font-bold bg-rose-50 text-rose-700 dark:bg-rose-950/40 dark:text-rose-300 border border-rose-200">
                          Khusus Gender Tertentu
                        </span>
                      </div>
                      <p className="text-xs text-slate-600 dark:text-slate-300 leading-relaxed">
                        Sistem memeriksa atribut <code>gender</code> pada data master karyawan:
                      </p>
                      <ul className="text-xs text-slate-600 dark:text-slate-300 space-y-1.5 list-disc list-inside pl-1">
                        <li>
                          <strong>Khusus Perempuan:</strong> <em>Cuti Hamil &amp; Melahirkan (90 hari)</em>, <em>Cuti Keguguran (45 hari)</em>, dan <em>Cuti Haid (2 hari)</em>. Karyawan laki-laki yang mencoba mengajukan jenis ini otomatis ditolak oleh sistem dengan penjelasan ramah.
                        </li>
                        <li>
                          <strong>Khusus Laki-laki:</strong> <em>Cuti Ayah (Pendampingan Istri Melahirkan / Keguguran - 2 hari)</em>. Karyawan perempuan tidak dapat mengajukan Cuti Ayah.
                        </li>
                        <li>
                          <strong>Semua Gender:</strong> Cuti Tahunan, Menikah, Duka Cita Keluarga, Ibadah Keagamaan, Sakit, Izin, dan WFH terbuka untuk seluruh gender.
                        </li>
                      </ul>
                    </div>

                    {/* Validasi 2: Status Pernikahan */}
                    <div className="border border-slate-200 dark:border-slate-800 rounded-2xl p-4.5 bg-white dark:bg-slate-900 space-y-2.5">
                      <div className="flex items-center justify-between gap-2">
                        <h5 className="text-xs font-bold text-slate-800 dark:text-slate-100 flex items-center gap-2">
                          <span className="w-6 h-6 rounded-full bg-purple-100 dark:bg-purple-950/60 text-purple-700 dark:text-purple-300 flex items-center justify-center text-xs font-bold">2</span>
                          Validasi Status Pernikahan (Marital Status)
                        </h5>
                        <span className="px-2 py-0.5 rounded-full text-[10px] font-bold bg-purple-50 text-purple-700 dark:bg-purple-950/40 dark:text-purple-300 border border-purple-200">
                          Wajib Berstatus Menikah (Married)
                        </span>
                      </div>
                      <p className="text-xs text-slate-600 dark:text-slate-300 leading-relaxed">
                        Cuti yang hak dasarnya terkait langsung dengan pasangan sah dan anak wajib berstatus <code>married</code> (Sudah Menikah) pada profil karyawan:
                      </p>
                      <div className="grid grid-cols-1 sm:grid-cols-2 gap-2 text-xs">
                        <div className="p-2.5 rounded-xl bg-purple-50/50 dark:bg-purple-950/20 border border-purple-100 dark:border-purple-900/30">
                          <p className="font-semibold text-purple-900 dark:text-purple-300">💍 Cuti Keluarga Pasangan &amp; Anak:</p>
                          <p className="text-slate-600 dark:text-slate-400 text-[11px] mt-0.5">
                            • Cuti Hamil &amp; Melahirkan<br />
                            • Cuti Keguguran<br />
                            • Cuti Ayah (Istri Melahirkan/Keguguran)<br />
                            • Menikahkan Anak Kandung<br />
                            • Mengkhitankan / Membaptiskan Anak
                          </p>
                        </div>
                        <div className="p-2.5 rounded-xl bg-slate-50 dark:bg-slate-800/40 border border-slate-200 dark:border-slate-700">
                          <p className="font-semibold text-slate-800 dark:text-slate-200">ℹ️ Karyawan Belum Menikah (Single):</p>
                          <p className="text-slate-600 dark:text-slate-400 text-[11px] mt-0.5">
                            Jika mengajukan cuti-cuti di samping, pengajuan otomatis ditolak: <em>"Cuti ini hanya dapat diajukan oleh karyawan yang berstatus sudah menikah pada profil kepegawaian."</em>
                          </p>
                        </div>
                      </div>
                    </div>

                    {/* Validasi 3: Status Kehamilan */}
                    <div className="border border-slate-200 dark:border-slate-800 rounded-2xl p-4.5 bg-white dark:bg-slate-900 space-y-2.5">
                      <div className="flex items-center justify-between gap-2">
                        <h5 className="text-xs font-bold text-slate-800 dark:text-slate-100 flex items-center gap-2">
                          <span className="w-6 h-6 rounded-full bg-pink-100 dark:bg-pink-950/60 text-pink-700 dark:text-pink-300 flex items-center justify-center text-xs font-bold">3</span>
                          Validasi Status Kehamilan (Active Pregnancy)
                        </h5>
                        <span className="px-2 py-0.5 rounded-full text-[10px] font-bold bg-pink-50 text-pink-700 dark:bg-pink-950/40 dark:text-pink-300 border border-pink-200">
                          Khusus Cuti Hamil
                        </span>
                      </div>
                      <p className="text-xs text-slate-600 dark:text-slate-300 leading-relaxed">
                        Pengajuan <strong>Cuti Hamil &amp; Melahirkan</strong> mewajibkan penanda status hamil aktif (<code>is_pregnant === true</code>) pada master profil data karyawan. Jika belum aktif, HRD atau karyawan dapat memperbarui data di tab Master Karyawan terlebih dahulu.
                      </p>
                    </div>
                  </div>
                </div>
              )}

              {/* TAB 3: KATALOG MASTER 15 JENIS CUTI RESMI UU */}
              {leaveInfoModalTab === 'catalog' && (
                <div className="space-y-4">
                  {/* Search Filter Cuti */}
                  <div className="flex items-center justify-between gap-3 flex-wrap sm:flex-nowrap">
                    <div className="relative flex-1 min-w-[240px]">
                      <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400 pointer-events-none" />
                      <input
                        type="text"
                        placeholder="Cari jenis cuti, syarat, atau dasar hukum UU..."
                        value={leaveInfoSearch}
                        onChange={(e) => setLeaveInfoSearch(e.target.value)}
                        className="w-full pl-9 pr-3 py-2 text-xs border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-800 text-slate-800 dark:text-slate-100 placeholder-slate-400 focus:outline-none focus:ring-1 focus:ring-indigo-400"
                      />
                    </div>
                    <span className="text-[11px] text-slate-400 shrink-0">
                      Total 15 Jenis Cuti Resmi
                    </span>
                  </div>

                  {/* Grid Matriks Regulasi Cuti */}
                  <div className="space-y-3">
                    {LEAVE_REGULATIONS_GUIDE
                      .filter(item => {
                        if (!leaveInfoSearch.trim()) return true;
                        const q = leaveInfoSearch.toLowerCase();
                        return (
                          item.label.toLowerCase().includes(q) ||
                          item.type.toLowerCase().includes(q) ||
                          item.legal.toLowerCase().includes(q) ||
                          item.description.toLowerCase().includes(q) ||
                          item.gender.toLowerCase().includes(q)
                        );
                      })
                      .map((item) => (
                        <div
                          key={item.type}
                          className="border border-slate-200 dark:border-slate-800 rounded-2xl p-4 bg-white dark:bg-slate-900 shadow-2xs space-y-2.5"
                        >
                          <div className="flex items-start justify-between gap-2 flex-wrap">
                            <div>
                              <div className="flex items-center gap-2 flex-wrap">
                                <h5 className="text-xs font-bold text-slate-800 dark:text-slate-100">{item.label}</h5>
                                <span className="font-mono text-[9px] px-1.5 py-0.5 rounded bg-slate-100 dark:bg-slate-800 text-slate-500">
                                  {item.type}
                                </span>
                              </div>
                              <p className="text-[10px] text-emerald-600 dark:text-emerald-400 font-semibold mt-0.5">
                                ⚖️ Dasar Hukum: {item.legal}
                              </p>
                            </div>

                            <div className="flex items-center gap-1.5 flex-wrap">
                              {/* Kuota */}
                              <span className="px-2 py-0.5 rounded-full text-[10px] font-bold bg-teal-50 text-teal-700 dark:bg-teal-950/40 dark:text-teal-300 border border-teal-200">
                                Kuota: {item.quota}
                              </span>

                              {/* Gender */}
                              <span className={`px-2 py-0.5 rounded-full text-[10px] font-semibold ${
                                item.gender.includes('Perempuan')
                                  ? 'bg-rose-50 text-rose-700 dark:bg-rose-950/40 dark:text-rose-300 border border-rose-200'
                                  : item.gender.includes('Laki-laki')
                                  ? 'bg-sky-50 text-sky-700 dark:bg-sky-950/40 dark:text-sky-300 border border-sky-200'
                                  : 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-400'
                              }`}>
                                {item.gender}
                              </span>

                              {/* Nikah */}
                              {item.marital.includes('Wajib') && (
                                <span className="px-2 py-0.5 rounded-full text-[10px] font-bold bg-purple-50 text-purple-700 dark:bg-purple-950/40 dark:text-purple-300 border border-purple-200">
                                  💍 Wajib Menikah
                                </span>
                              )}

                              {/* Dokumen */}
                              <span className={`px-2 py-0.5 rounded-full text-[10px] font-semibold ${
                                item.document.includes('Wajib')
                                  ? 'bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300 border border-amber-200'
                                  : 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-400'
                              }`}>
                                {item.document}
                              </span>
                            </div>
                          </div>

                          <p className="text-xs text-slate-600 dark:text-slate-300 leading-relaxed bg-slate-50 dark:bg-slate-800/40 p-2.5 rounded-xl border border-slate-100 dark:border-slate-800">
                            {item.description}
                          </p>
                        </div>
                      ))}
                  </div>
                </div>
              )}
            </div>

            {/* Footer Modal */}
            <div className="px-6 py-3.5 border-t border-slate-100 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-900/50 flex items-center justify-between">
              <div className="text-xs text-slate-500 dark:text-slate-400 flex items-center gap-1.5">
                <Building2 className="w-3.5 h-3.5 text-teal-600 dark:text-teal-400" />
                <span>Kantor Aktif: <strong>{leaveTypeSettingsOfficeName || 'Semua Kantor Cabang'}</strong></span>
              </div>
              <button
                type="button"
                onClick={() => setShowLeaveInfoModal(false)}
                className="px-4 py-2 text-xs font-bold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl shadow-sm transition cursor-pointer"
              >
                Tutup Panduan
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
  leaves?: any[];
  reload: () => Promise<void>;
  onAddAuditLog: (t: string, d: string, b: string) => void;
  onError: (e: unknown, f: string) => void;
  year: number;
  onYearChange: (y: number) => void;
}> = ({ holidays, offices, users, leaves = [], reload, onAddAuditLog, onError, year, onYearChange }) => {
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

  // Tanggal terpilih di kalender — default ke hari ini
  const [detailDate, setDetailDate] = useState<string | null>(todayStr);
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
  // Filter kantor untuk kalender — jika role terbatas ke kantor tertentu, default otomatis ke kantor tersebut
  const [calOfficeFilter, setCalOfficeFilter] = useState<string>(() => {
    if (offices.length === 1) return String(offices[0].id);
    if (user?.attendance_setting_id && offices.some((o: any) => String(o.id) === String(user.attendance_setting_id))) {
      return String(user.attendance_setting_id);
    }
    if (offices.length > 0 && !isSuperAdmin) return String(offices[0].id);
    return '';
  });
  const [hasUserSelectedOffice, setHasUserSelectedOffice] = useState(false);

  // Sinkronkan filter kantor saat offices selesai dimuat
  useEffect(() => {
    if (offices.length === 0) return;
    // Jika user hanya punya akses ke 1 kantor (role branch_scope self/specific), selalu kunci ke kantor tsb
    if (offices.length === 1) {
      setCalOfficeFilter(String(offices[0].id));
      return;
    }
    // Jika belum dipilih manual oleh user dan filter saat ini kosong, arahkan ke kantor user atau kantor pertama
    if (!hasUserSelectedOffice && !calOfficeFilter) {
      const defaultId = (user?.attendance_setting_id && offices.some((o: any) => String(o.id) === String(user.attendance_setting_id)))
        ? String(user.attendance_setting_id)
        : String(offices[0].id);
      setCalOfficeFilter(defaultId);
    }
  }, [offices, user?.attendance_setting_id, hasUserSelectedOffice, calOfficeFilter]);

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

  // ─── Fitur Tarik Libur Otomatis (SKB 3 Menteri) ───────────────
  const [showAutoSyncModal, setShowAutoSyncModal] = useState(false);
  const [autoSyncYear, setAutoSyncYear] = useState<number>(year);
  const [loadingAutoSyncPreview, setLoadingAutoSyncPreview] = useState(false);
  const [autoSyncPreviewData, setAutoSyncPreviewData] = useState<any | null>(null);
  const [selectedAutoHolidays, setSelectedAutoHolidays] = useState<Record<string, boolean>>({});
  const [collectiveTreatment, setCollectiveTreatment] = useState<'nasional' | 'collective'>('nasional');
  const [syncingHolidays, setSyncingHolidays] = useState(false);
  const [autoSyncSuccessMsg, setAutoSyncSuccessMsg] = useState<string | null>(null);

  const loadAutoSyncPreview = useCallback(async (targetYear: number) => {
    setLoadingAutoSyncPreview(true);
    setAutoSyncSuccessMsg(null);
    try {
      const res: any = await attendanceApi.holidays.previewNational(targetYear);
      setAutoSyncPreviewData(res);
      const initialSelected: Record<string, boolean> = {};
      (res?.holidays || []).forEach((h: any) => {
        initialSelected[h.date] = !h.already_exists;
      });
      setSelectedAutoHolidays(initialSelected);
    } catch (err: any) {
      onError(err, 'Gagal memuat daftar hari libur nasional');
    } finally {
      setLoadingAutoSyncPreview(false);
    }
  }, [onError]);

  const handleOpenAutoSyncModal = () => {
    setAutoSyncYear(year);
    setAutoSyncSuccessMsg(null);
    setShowAutoSyncModal(true);
    loadAutoSyncPreview(year);
  };

  const handleYearChangeAutoSync = (newY: number) => {
    setAutoSyncYear(newY);
    loadAutoSyncPreview(newY);
  };

  const handleToggleSelectAll = () => {
    if (!autoSyncPreviewData?.holidays) return;
    const allSelected = autoSyncPreviewData.holidays.every((h: any) => selectedAutoHolidays[h.date]);
    const updated: Record<string, boolean> = {};
    autoSyncPreviewData.holidays.forEach((h: any) => {
      updated[h.date] = !allSelected;
    });
    setSelectedAutoHolidays(updated);
  };

  const selectedCount = useMemo(() => {
    return Object.values(selectedAutoHolidays).filter(Boolean).length;
  }, [selectedAutoHolidays]);

  const handleExecuteSync = async () => {
    if (!autoSyncPreviewData?.holidays) return;
    const toImport = autoSyncPreviewData.holidays.filter((h: any) => selectedAutoHolidays[h.date]);
    if (toImport.length === 0) {
      alert('Pilih setidaknya 1 hari libur untuk disinkronkan.');
      return;
    }

    setSyncingHolidays(true);
    try {
      const res: any = await attendanceApi.holidays.syncNational({
        year: autoSyncYear,
        holidays: toImport,
        collective_treatment: collectiveTreatment,
      });
      setAutoSyncSuccessMsg(res?.message || 'Sinkronisasi berhasil.');
      onAddAuditLog('Tarik Libur Otomatis', `Sinkronisasi ${res?.synced_count ?? 0} hari libur nasional tahun ${autoSyncYear}`, 'attendance');
      await reload();
    } catch (err: any) {
      onError(err, 'Gagal menyinkronkan hari libur');
    } finally {
      setSyncingHolidays(false);
    }
  };

  // Libur yang relevan dg filter kantor yang dipilih.
  // '' = Semua Kantor → tampilkan semua libur perusahaan + nasional.
  // kantor spesifik → tampilkan: nasional + company-wide (attendance_setting_id null)
  //   + libur/cuti bersama khusus cabang tsb saja. Libur cabang lain disembunyikan.
  const DAY_NAMES = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];

  // Kantor yang sedang aktif/dipilih di kalender
  const selectedOffice = useMemo(() => {
    if (!calOfficeFilter) {
      if (offices.length === 1) return offices[0];
      return null;
    }
    return offices.find((o: any) => String(o.id) === calOfficeFilter) ?? null;
  }, [offices, calOfficeFilter]);

  // Daftar nama hari libur rutin mingguan kantor terpilih
  const selectedOfficeOffDays = useMemo(() => {
    if (!selectedOffice) return [];
    const workDays: number[] = Array.isArray(selectedOffice.work_days)
      ? selectedOffice.work_days.map(Number)
      : [1, 2, 3, 4, 5];
    return [0, 1, 2, 3, 4, 5, 6].filter(d => !workDays.includes(d)).map(d => DAY_NAMES[d]);
  }, [selectedOffice]);

  const visibleHolidays = useMemo(() => {
    if (!calOfficeFilter) return holidays;
    return holidays.filter((h) => {
      // Libur nasional (company_id null atau scope nasional atau is_national) berlaku untuk semua kantor.
      if (!h.company_id || h.scope === 'nasional' || h.is_national) return true;
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
    const targetOffice = selectedOffice;
    if (!targetOffice) return new Set(); // jika Semua Kantor dipilih tanpa kantor tunggal, tidak sorot libur mingguan
    const workDays: number[] = Array.isArray(targetOffice.work_days)
      ? targetOffice.work_days.map(Number)
      : [1, 2, 3, 4, 5]; // default Senin-Jumat jika tidak ada
    const allDays = [0, 1, 2, 3, 4, 5, 6];
    return new Set(allDays.filter(d => !workDays.includes(d)));
  }, [selectedOffice]);

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

  // Filter hanya cuti & izin yang berstatus 'approved' (fix disetujui HRD)
  const approvedLeaves = useMemo(() => {
    return (leaves || []).filter((l: any) => {
      if (l.status !== 'approved') return false;
      if (calOfficeFilter) {
        if (l.attendance_setting_id !== undefined && l.attendance_setting_id !== null) {
          if (String(l.attendance_setting_id) !== calOfficeFilter) return false;
        }
      }
      return true;
    });
  }, [leaves, calOfficeFilter]);

  // Kumpulkan karyawan cuti per tanggal (map: 'YYYY-MM-DD' → leave[])
  const leavesByDate = useMemo(() => {
    const map: Record<string, any[]> = {};
    approvedLeaves.forEach((l: any) => {
      const startStr = (l.start_date ?? '').slice(0, 10);
      const endStr = (l.end_date ?? l.start_date ?? '').slice(0, 10);
      if (!startStr) return;

      const startDate = new Date(startStr + 'T00:00:00');
      const endDate = new Date(endStr + 'T00:00:00');
      if (isNaN(startDate.getTime()) || isNaN(endDate.getTime())) return;

      const cur = new Date(startDate.getTime());
      let safetyCounter = 0;
      while (cur <= endDate && safetyCounter < 366) {
        const dStr = toDateStr(cur);
        if (!map[dStr]) map[dStr] = [];
        if (!map[dStr].some((item: any) => item.id === l.id)) {
          map[dStr].push(l);
        }
        cur.setDate(cur.getDate() + 1);
        safetyCounter++;
      }
    });
    return map;
  }, [approvedLeaves]);

  // Total karyawan unik yang cuti/izin di bulan aktif
  const monthApprovedLeavesSummary = useMemo(() => {
    const prefix = `${year}-${pad2(viewMonth + 1)}-`;
    const userSet = new Set<string>();
    let totalDays = 0;
    Object.entries(leavesByDate).forEach(([dStr, list]) => {
      if (dStr.startsWith(prefix)) {
        ((list as any[]) || []).forEach((l: any) => {
          userSet.add(String(l.user_id || l.user_name));
          totalDays++;
        });
      }
    });
    return {
      uniqueUsers: userSet.size,
      totalDays,
    };
  }, [leavesByDate, year, viewMonth]);

  const getLeaveTypeBadge = (type: string) => {
    switch (type) {
      case 'cuti':
        return {
          bg: 'bg-teal-50 dark:bg-teal-950/40 text-teal-700 dark:text-teal-300 border-teal-200 dark:border-teal-800',
          dot: 'bg-teal-500',
          label: 'Cuti Tahunan',
        };
      case 'izin':
        return {
          bg: 'bg-amber-50 dark:bg-amber-950/40 text-amber-700 dark:text-amber-300 border-amber-200 dark:border-amber-800',
          dot: 'bg-amber-500',
          label: 'Izin',
        };
      case 'sakit':
        return {
          bg: 'bg-rose-50 dark:bg-rose-950/40 text-rose-700 dark:text-rose-300 border-rose-200 dark:border-rose-800',
          dot: 'bg-rose-500',
          label: 'Cuti Sakit',
        };
      case 'wfh':
        return {
          bg: 'bg-sky-50 dark:bg-sky-950/40 text-sky-700 dark:text-sky-300 border-sky-200 dark:border-sky-800',
          dot: 'bg-sky-500',
          label: 'WFH',
        };
      case 'cuti_hamil':
      case 'cuti_keguguran':
        return {
          bg: 'bg-pink-50 dark:bg-pink-950/40 text-pink-700 dark:text-pink-300 border-pink-200 dark:border-pink-800',
          dot: 'bg-pink-500',
          label: LEAVE_TYPE_LABELS[type] || 'Cuti Khusus',
        };
      default:
        return {
          bg: 'bg-indigo-50 dark:bg-indigo-950/40 text-indigo-700 dark:text-indigo-300 border-indigo-200 dark:border-indigo-800',
          dot: 'bg-indigo-500',
          label: LEAVE_TYPE_LABELS[type] || type,
        };
    }
  };

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

  // Libur & Karyawan cuti pada tanggal yang dipilih (dari sisi kiri)
  const selectedHolidays = detailDate ? (byDate[detailDate] ?? []) : [];
  const selectedLeaves = detailDate ? (leavesByDate[detailDate] ?? []) : [];

  // Tombol "Tambah" status libur hanya diperbolehkan untuk tanggal di masa depan.
  // Tanggal sudah lewat / hari ini → tombol disembunyikan (disabled).
  const isDetailLocked = detailDate ? detailDate <= todayStr : false;

  return (
    <div className="space-y-4">
      {/* Header */}
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <div className="flex items-center gap-2.5">
            <h3 className="text-sm font-bold text-slate-800 dark:text-slate-100">Kalender {year}</h3>
            {selectedOffice && (
              <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-indigo-50 dark:bg-indigo-950/50 text-indigo-700 dark:text-indigo-300 border border-indigo-200/70 dark:border-indigo-800/50 shadow-2xs">
                <Building2 className="w-3 h-3 text-indigo-500 shrink-0" />
                <span>{selectedOffice.office_name}</span>
              </span>
            )}
          </div>
          <p className="text-[11px] text-slate-400 mt-0.5">
            Tanggal libur tidak dihitung sebagai hari kerja (cuti) dan kerja di hari ini dihitung lembur penuh.
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          {/* Tombol Tarik Libur Otomatis */}
          <button
            type="button"
            onClick={handleOpenAutoSyncModal}
            className="flex items-center gap-1.5 py-1.5 px-3 text-[11px] font-semibold rounded-lg bg-rose-50 hover:bg-rose-100 text-rose-700 dark:bg-rose-950/40 dark:hover:bg-rose-900/50 dark:text-rose-300 border border-rose-200 dark:border-rose-800/60 transition-colors shadow-sm cursor-pointer"
            title="Tarik & Sinkronkan Hari Libur Nasional Indonesia (SKB 3 Menteri) Otomatis"
          >
            <Sparkles className="w-3.5 h-3.5 text-rose-500 shrink-0" />
            <span>Tarik Libur Otomatis</span>
          </button>

          {/* Indikator Kantor Tunggal atau Dropdown Pilihan Kantor */}
          {offices.length === 1 ? (
            <div className="flex items-center gap-1.5 py-1.5 px-3 text-[11px] font-semibold rounded-lg bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 border border-slate-200 dark:border-slate-700 shadow-xs">
              <Building2 className="w-3.5 h-3.5 text-indigo-500 shrink-0" />
              <span>Kantor: <strong className="font-bold text-slate-900 dark:text-slate-100">{offices[0].office_name}</strong></span>
            </div>
          ) : offices.length > 1 ? (
            <div className="flex items-center gap-1.5">
              <Building2 className="w-3.5 h-3.5 text-slate-400 shrink-0" />
              <select
                value={calOfficeFilter}
                onChange={(e) => {
                  setHasUserSelectedOffice(true);
                  setCalOfficeFilter(e.target.value);
                }}
                className="py-1.5 px-3 text-[11px] font-semibold border border-slate-200 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 focus:outline-none focus:ring-1 focus:ring-indigo-400 shadow-xs cursor-pointer"
                title="Filter kalender & libur mingguan per kantor"
              >
                <option value="" className="bg-white dark:bg-slate-800 text-slate-900 dark:text-slate-100">Semua Kantor (Semua Libur)</option>
                {offices.map((o: any) => (
                  <option key={o.id} value={String(o.id)} className="bg-white dark:bg-slate-800 text-slate-900 dark:text-slate-100">{o.office_name}</option>
                ))}
              </select>
            </div>
          ) : null}
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
            <div className="flex items-center gap-2 text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-lg bg-slate-50 dark:bg-slate-800 text-slate-800 dark:text-slate-100">
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
              className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-lg bg-slate-50 dark:bg-slate-800 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-1 focus:ring-indigo-400"
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
              {isSuperAdmin && <option value="nasional" className="bg-white dark:bg-slate-800 text-slate-900 dark:text-slate-100">Libur Nasional</option>}
              <option value="collective" className="bg-white dark:bg-slate-800 text-slate-900 dark:text-slate-100">Cuti Bersama</option>
              <option value="perusahaan" className="bg-white dark:bg-slate-800 text-slate-900 dark:text-slate-100">Libur Perusahaan</option>
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
                  offices.length !== 1 && <option value="" className="bg-white dark:bg-slate-800 text-slate-900 dark:text-slate-100">Semua Kantor (Semua Cabang)</option>
                ) : (
                  offices.length !== 1 && <option value="" disabled className="bg-white dark:bg-slate-800 text-slate-900 dark:text-slate-100">Pilih Kantor Cabang...</option>
                )}
                {offices.map((o: any) => (
                  <option key={o.id} value={String(o.id)} className="bg-white dark:bg-slate-800 text-slate-900 dark:text-slate-100">{o.office_name}</option>
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
              className="flex items-center justify-center p-2 text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg transition cursor-pointer"
              title="Bulan sebelumnya"
            >
              <ChevronLeft className="w-4 h-4" />
            </button>
            <div className="text-center">
              <p className="text-sm font-bold text-slate-800 dark:text-slate-100">{MONTHS[viewMonth]} {year}</p>
              <div className="flex items-center justify-center gap-1.5 text-[10px] text-slate-400 flex-wrap mt-0.5">
                <span>{summary.nasional} nasional</span>
                <span>·</span>
                <span>{summary.cutiBersama} cuti bersama</span>
                <span>·</span>
                <span>{summary.perusahaan} perusahaan</span>
                {monthApprovedLeavesSummary.uniqueUsers > 0 && (
                  <>
                    <span>·</span>
                    <span className="font-bold text-emerald-600 dark:text-emerald-400 flex items-center gap-1">
                      <Users className="w-3 h-3 inline" />
                      {monthApprovedLeavesSummary.uniqueUsers} karyawan cuti/izin disetujui
                    </span>
                  </>
                )}
              </div>
              {selectedOffice && (
                <p className="text-[10px] text-indigo-600 dark:text-indigo-400 font-medium mt-0.5">
                  Libur rutin mingguan ({selectedOffice.office_name}):{' '}
                  <span className="font-bold">{selectedOfficeOffDays.length > 0 ? selectedOfficeOffDays.join(', ') : 'Tidak ada (7 hari kerja)'}</span>
                </p>
              )}
            </div>
            <button
              onClick={() => changeMonth(1)}
              className="flex items-center justify-center p-2 text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg transition cursor-pointer"
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
              if (day === null) return <div key={`e-${idx}`} className="min-h-[4.75rem] sm:min-h-[5.5rem]" />;
              const dateStr = `${year}-${pad2(viewMonth + 1)}-${pad2(day)}`;
              const isPastOrToday = dateStr <= todayStr;
              const dayHolidays = byDate[dateStr] ?? [];
              const dayLeaves = leavesByDate[dateStr] ?? [];
              const hasNational = dayHolidays.some(h => h.scope === 'nasional');
              const hasCollective = dayHolidays.some(h => h.is_collective);
              const hasCompany = dayHolidays.some(h => (h.scope === 'perusahaan' || h.scope === 'cabang') && !h.is_collective);
              const isToday = dateStr === toDateStr(today);
              const isSelected = detailDate === dateStr;
              const jsDay = new Date(dateStr + 'T00:00:00').getDay();
              const isWeeklyOff = weeklyOffDays.has(jsDay);
              const hasLeaves = dayLeaves.length > 0;

              // Prioritas warna background
              const bgClass = hasNational
                ? 'bg-rose-50 dark:bg-rose-950/30 border-rose-200 dark:border-rose-900/40'
                : isWeeklyOff
                  ? 'bg-red-50 dark:bg-red-950/20 border-red-200 dark:border-red-900/30'
                  : hasCollective
                    ? 'bg-amber-50 dark:bg-amber-950/30 border-amber-200 dark:border-amber-900/40'
                    : hasCompany
                      ? 'bg-indigo-50 dark:bg-indigo-950/30 border-indigo-200 dark:border-indigo-900/40'
                      : hasLeaves
                        ? 'bg-emerald-50/40 dark:bg-emerald-950/20 border-emerald-200/80 dark:border-emerald-800/40 hover:bg-emerald-50 dark:hover:bg-emerald-950/40'
                        : 'bg-slate-50/60 dark:bg-slate-800/40 border-slate-100 dark:border-slate-800 hover:bg-slate-100 dark:hover:bg-slate-800';

              const selectedOfficeName = selectedOffice?.office_name ?? '';
              const holidayTooltip = dayHolidays.length ? dayHolidays.map(h => h.name).join(', ') : '';
              const leaveTooltip = dayLeaves.map(l => `${l.user_name} (${getLeaveTypeBadge(l.leave_type).label})`).join(', ');
              const tooltip = [
                holidayTooltip ? `Libur: ${holidayTooltip}` : '',
                isWeeklyOff ? `Hari libur mingguan ${selectedOfficeName}` : '',
                dayLeaves.length ? `Karyawan Cuti/Izin (${dayLeaves.length}): ${leaveTooltip}` : '',
                isPastOrToday ? '' : 'Klik untuk melihat detail / menambah libur',
              ].filter(Boolean).join(' | ');

              return (
                <button
                  key={dateStr}
                  onClick={() => {
                    setDetailDate(detailDate === dateStr ? null : dateStr);
                    setShowForm(false);
                  }}
                  className={`relative flex flex-col items-center justify-start min-h-[4.75rem] sm:min-h-[5.5rem] h-auto pb-1.5 rounded-xl border text-xs transition-all cursor-pointer text-left
                    ${bgClass}
                    ${isSelected ? 'ring-2 ring-indigo-500 border-indigo-400 shadow-sm z-10' : ''}`}
                  title={tooltip}
                >
                  {/* Baris Tanggal & Indikator Cuti */}
                  <div className="flex items-center justify-between w-full px-1.5 pt-1">
                    <span className={`text-[11px] font-bold ${
                      isToday
                        ? 'w-5 h-5 rounded-full bg-indigo-600 text-white flex items-center justify-center -ml-0.5 shadow-2xs'
                        : isWeeklyOff && !hasNational
                          ? 'text-red-500 dark:text-red-400'
                          : 'text-slate-700 dark:text-slate-300'
                    }`}>
                      {day}
                    </span>
                    {hasLeaves && (
                      <span
                        className="inline-flex items-center gap-0.5 text-[8px] font-bold text-emerald-700 dark:text-emerald-300 bg-emerald-100 dark:bg-emerald-900/60 px-1 py-0.2 rounded-full border border-emerald-300/60 dark:border-emerald-700/60"
                        title={`${dayLeaves.length} karyawan cuti/izin disetujui`}
                      >
                        <Users className="w-2.5 h-2.5" />
                        <span>{dayLeaves.length}</span>
                      </span>
                    )}
                  </div>

                  {/* Badge libur mingguan */}
                  {isWeeklyOff && !hasNational && !hasCompany && dayHolidays.length === 0 && !hasLeaves && (
                    <span className="text-[8px] font-semibold text-red-400 dark:text-red-500 mt-0.5 leading-tight">
                      Libur
                    </span>
                  )}

                  {/* Daftar Libur Resmi */}
                  {dayHolidays.length > 0 && (
                    <div className="flex flex-col items-center gap-0.5 w-full px-1 mt-0.5">
                      {dayHolidays.slice(0, 1).map(h => (
                        <span key={h.id} className="w-full truncate text-center text-[8px] leading-tight font-semibold text-slate-700 dark:text-slate-300 bg-white/70 dark:bg-slate-800/70 rounded px-0.5">
                          {h.name}
                        </span>
                      ))}
                      {dayHolidays.length > 1 && (
                        <span className="w-full text-center text-[7.5px] font-bold text-slate-400">
                          +{dayHolidays.length - 1} libur
                        </span>
                      )}
                    </div>
                  )}

                  {/* Daftar Karyawan Cuti & Izin (Approved) */}
                  {dayLeaves.length > 0 && (
                    <div className="flex flex-col gap-0.5 w-full px-1 mt-1">
                      {dayLeaves.slice(0, dayHolidays.length > 0 ? 1 : 2).map((l: any) => {
                        const badge = getLeaveTypeBadge(l.leave_type);
                        return (
                          <div
                            key={`cell-leave-${l.id}`}
                            className={`w-full truncate text-[8px] leading-tight font-semibold px-1 py-0.5 rounded border ${badge.bg} flex items-center gap-1`}
                            title={`${l.user_name} (${badge.label}) - Disetujui HRD`}
                          >
                            <span className={`w-1.5 h-1.5 rounded-full ${badge.dot} shrink-0`} />
                            <span className="truncate">{l.user_name}</span>
                          </div>
                        );
                      })}
                      {dayLeaves.length > (dayHolidays.length > 0 ? 1 : 2) && (
                        <span className="w-full text-center text-[7.5px] font-bold text-emerald-700 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-950/40 rounded px-1">
                          +{dayLeaves.length - (dayHolidays.length > 0 ? 1 : 2)} cuti lagi
                        </span>
                      )}
                    </div>
                  )}
                </button>
              );
            })}
          </div>
        </div>

        {/* Panel detail / tambah cepat */}
        <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl p-5 lg:sticky lg:top-4 space-y-4">
          {detailDate ? (
            <>
              {/* Header Tanggal Terpilih */}
              <div className="flex items-center justify-between pb-3 border-b border-slate-100 dark:border-slate-800">
                <div>
                  <h4 className="text-xs font-bold text-slate-800 dark:text-slate-100 flex items-center gap-1.5">
                    <span>{fmtDate(detailDate)}</span>
                    {detailDate === todayStr && (
                      <span className="px-1.5 py-0.2 rounded text-[9px] font-bold bg-indigo-100 text-indigo-700 dark:bg-indigo-950/60 dark:text-indigo-300">
                        Hari Ini
                      </span>
                    )}
                  </h4>
                  <p className="text-[10px] text-slate-400 mt-0.5">
                    {selectedLeaves.length} karyawan cuti/izin · {selectedHolidays.length} hari libur
                  </p>
                </div>
                {!isDetailLocked && selectedHolidays.length === 0 && (
                  <button
                    onClick={() => { startCreate(detailDate); }}
                    className="inline-flex items-center gap-1 px-2.5 py-1.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-[10px] font-bold shadow-xs transition cursor-pointer"
                  >
                    <Plus className="w-3 h-3" /> Tambah Libur
                  </button>
                )}
              </div>

              {/* ── BAGIAN 1: Karyawan Cuti & Izin (Disetujui HRD) ── */}
              <div className="space-y-2.5">
                <div className="flex items-center justify-between">
                  <h5 className="text-[11px] font-bold text-slate-700 dark:text-slate-200 uppercase tracking-wider flex items-center gap-1.5">
                    <Users className="w-3.5 h-3.5 text-emerald-600 dark:text-emerald-400" />
                    <span>Karyawan Cuti &amp; Izin</span>
                  </h5>
                  {selectedLeaves.length > 0 && (
                    <span className="px-2 py-0.5 text-[9px] font-bold rounded-full bg-emerald-50 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800">
                      {selectedLeaves.length} Disetujui HRD
                    </span>
                  )}
                </div>

                {selectedLeaves.length === 0 ? (
                  <div className="p-3.5 rounded-xl border border-dashed border-slate-200 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-850/30 text-center space-y-1">
                    <p className="text-xs font-semibold text-slate-600 dark:text-slate-300 flex items-center justify-center gap-1.5">
                      <UserCheck className="w-4 h-4 text-emerald-500" />
                      <span>Tidak Ada Karyawan Cuti</span>
                    </p>
                    <p className="text-[10px] text-slate-400">
                      Seluruh karyawan aktif terjadwal bertugas pada tanggal ini.
                    </p>
                  </div>
                ) : (
                  <div className="space-y-2 max-h-72 overflow-y-auto pr-1">
                    {selectedLeaves.map((l: any) => {
                      const badge = getLeaveTypeBadge(l.leave_type);
                      const officeName = offices.find((o: any) => o.id === l.attendance_setting_id)?.office_name;

                      return (
                        <div
                          key={`detail-leave-${l.id}`}
                          className="p-3 rounded-xl border border-slate-200/80 dark:border-slate-800 bg-white dark:bg-slate-850 shadow-2xs space-y-2"
                        >
                          <div className="flex items-start justify-between gap-2">
                            <div className="min-w-0">
                              <p className="text-xs font-bold text-slate-800 dark:text-slate-100 truncate">
                                {l.user_name}
                              </p>
                              <p className="text-[10px] text-slate-400 truncate">
                                {l.position_name || l.department || 'Karyawan'}
                                {officeName ? ` · ${officeName}` : ''}
                              </p>
                            </div>
                            <span className={`inline-flex items-center gap-1 text-[9px] font-bold px-2 py-0.5 rounded-full border ${badge.bg} shrink-0`}>
                              <span className={`w-1.5 h-1.5 rounded-full ${badge.dot}`} />
                              <span>{badge.label}</span>
                            </span>
                          </div>

                          <div className="flex items-center justify-between text-[10px] text-slate-500 dark:text-slate-400 pt-1.5 border-t border-slate-100 dark:border-slate-800">
                            <span className="font-mono font-medium">
                              {fmtDate(l.start_date)}
                              {l.end_date && l.end_date !== l.start_date ? ` – ${fmtDate(l.end_date)}` : ''}
                            </span>
                            <span className="font-bold text-slate-700 dark:text-slate-200">
                              {l.total_days} hari
                            </span>
                          </div>

                          {l.reason && (
                            <p className="text-[10.5px] text-slate-600 dark:text-slate-300 bg-slate-50 dark:bg-slate-800/60 p-2 rounded-lg border border-slate-100 dark:border-slate-800/80 italic leading-relaxed">
                              "{l.reason}"
                            </p>
                          )}

                          <div className="flex items-center justify-between pt-0.5 text-[9px] text-slate-400">
                            <span className="inline-flex items-center gap-1 font-semibold text-emerald-600 dark:text-emerald-400">
                              <CheckCircle2 className="w-3 h-3" />
                              Disetujui HRD
                              {l.balance_after !== null && l.balance_after !== undefined && (
                                <span className="font-normal text-slate-500 dark:text-slate-400 ml-1">
                                  · Sisa: {l.balance_after} hari
                                </span>
                              )}
                            </span>
                            {l.approved_at && (
                              <span>Disetujui: {fmtDate(l.approved_at)}</span>
                            )}
                          </div>
                        </div>
                      );
                    })}
                  </div>
                )}
              </div>

              {/* ── BAGIAN 2: Hari Libur & Kalender ── */}
              <div className="space-y-2 pt-3 border-t border-slate-100 dark:border-slate-800">
                <h5 className="text-[11px] font-bold text-slate-700 dark:text-slate-200 uppercase tracking-wider flex items-center gap-1.5">
                  <CalendarDays className="w-3.5 h-3.5 text-indigo-500" />
                  <span>Hari Libur Kantor</span>
                </h5>

                {selectedHolidays.length === 0 ? (
                  isDetailLocked ? (
                    <p className="text-[11px] text-slate-400 bg-slate-50/50 dark:bg-slate-800/30 p-2.5 rounded-xl border border-slate-100 dark:border-slate-800 text-center">
                      Tidak ada libur nasional / perusahaan pada tanggal ini.
                    </p>
                  ) : (
                    <p className="text-[11px] text-slate-400 bg-slate-50/50 dark:bg-slate-800/30 p-2.5 rounded-xl border border-slate-100 dark:border-slate-800 text-center">
                      Tidak ada libur pada tanggal ini. Klik <span className="font-semibold text-indigo-600 dark:text-indigo-400">Tambah</span> untuk membuat libur khusus.
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
                              className="inline-flex items-center gap-1 px-2 py-1 bg-amber-50 hover:bg-amber-100 text-amber-700 dark:bg-amber-950/30 dark:text-amber-400 dark:hover:bg-amber-900/50 rounded-md text-[10px] font-bold transition cursor-pointer"
                            >
                              <Users className="w-3 h-3" /> Rekap Opt-in
                            </button>
                          )}
                          <div className="flex items-center gap-1 ml-auto">
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
              </div>
            </>
          ) : (
            <>
              <h4 className="text-xs font-bold text-slate-800 dark:text-slate-100 mb-3">Panduan Kalender</h4>
              <ul className="space-y-2.5 text-[11px] text-slate-600 dark:text-slate-300">
                <li className="flex items-center gap-2">
                  <span className="w-4 h-4 rounded-md bg-teal-50 border border-teal-300 dark:bg-teal-950/40 dark:border-teal-700 shrink-0 flex items-center justify-center">
                    <span className="w-1.5 h-1.5 rounded-full bg-teal-500" />
                  </span>
                  <span><span className="font-semibold text-teal-700 dark:text-teal-400">Pill Karyawan</span> — Karyawan yang cuti/izin sudah di-approve HRD</span>
                </li>
                <li className="flex items-center gap-2">
                  <span className="w-4 h-4 rounded-md bg-rose-50 border border-rose-200 dark:bg-rose-950/30 dark:border-rose-900/40 shrink-0" />
                  <span><span className="font-semibold text-rose-700 dark:text-rose-400">Merah tua</span> — libur nasional (diberlakukan semua perusahaan)</span>
                </li>
                <li className="flex items-center gap-2">
                  <span className="w-4 h-4 rounded-md bg-red-50 border border-red-200 dark:bg-red-950/20 dark:border-red-900/30 shrink-0" />
                  <span>
                    <span className="font-semibold text-red-500 dark:text-red-400">Merah muda</span> — libur rutin mingguan kantor
                    {selectedOffice ? (
                      <span className="text-slate-500 dark:text-slate-400"> ({selectedOffice.office_name}: {selectedOfficeOffDays.length > 0 ? selectedOfficeOffDays.join(', ') : 'Tidak ada'})</span>
                    ) : (
                      <span className="text-slate-400"> (pilih kantor untuk melihat)</span>
                    )}
                  </span>
                </li>
                <li className="flex items-center gap-2">
                  <span className="w-4 h-4 rounded-md bg-amber-50 border border-amber-200 dark:bg-amber-950/30 dark:border-amber-900/40 shrink-0" />
                  <span><span className="font-semibold text-amber-700 dark:text-amber-400">Amber (kuning)</span> — cuti bersama (saldo terpotong jika karyawan ikut)</span>
                </li>
                <li className="flex items-center gap-2">
                  <span className="w-4 h-4 rounded-md bg-indigo-50 border border-indigo-200 dark:bg-indigo-950/30 dark:border-indigo-900/40 shrink-0" />
                  <span><span className="font-semibold text-indigo-700 dark:text-indigo-400">Biru</span> — libur khusus perusahaan (tanpa potong saldo)</span>
                </li>
                <li className="flex items-start gap-2 pt-1 border-t border-slate-100 dark:border-slate-800">
                  <span className="w-4 h-4 rounded-md bg-slate-50 border border-slate-200 dark:bg-slate-800 shrink-0 flex items-center justify-center text-[10px] font-bold text-slate-500">
                    i
                  </span>
                  <span>Klik pada tanggal berapa saja untuk melihat daftar karyawan cuti &amp; detail libur secara lengkap.</span>
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
                                <span
                                  title={e.rejection_reason || undefined}
                                  className={`inline-flex text-[9px] font-bold px-1.5 py-0.5 rounded uppercase tracking-wider ${
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

      {/* Modal Tarik Libur Nasional Otomatis (SKB 3 Menteri) */}
      {showAutoSyncModal && (
        <div
          className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm px-4 py-6"
          onClick={(e) => { if (e.target === e.currentTarget && !syncingHolidays) setShowAutoSyncModal(false); }}
        >
          <div className="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-2xl max-h-[90vh] overflow-y-auto animate-in fade-in zoom-in-95 duration-200 border border-slate-100 dark:border-slate-800 flex flex-col">
            {/* Header */}
            <div className="flex items-center justify-between px-6 py-4 border-b border-slate-100 dark:border-slate-800 sticky top-0 bg-white dark:bg-slate-900 z-10">
              <div className="flex items-center gap-2.5">
                <div className="w-9 h-9 rounded-xl bg-rose-100 dark:bg-rose-950/60 flex items-center justify-center text-rose-600 dark:text-rose-400 shrink-0 shadow-sm">
                  <Sparkles className="w-5 h-5" />
                </div>
                <div>
                  <h3 className="text-sm font-bold text-slate-800 dark:text-slate-100 flex items-center gap-1.5">
                    Tarik Libur Nasional Otomatis
                    <span className="text-[10px] px-2 py-0.5 rounded-full font-semibold bg-rose-100 text-rose-700 dark:bg-rose-950/60 dark:text-rose-300">
                      SKB 3 Menteri
                    </span>
                  </h3>
                  <p className="text-[11px] text-slate-500 mt-0.5">
                    Sinkronkan daftar hari libur nasional resmi & cuti bersama Indonesia langsung ke kalender.
                  </p>
                </div>
              </div>
              <button
                type="button"
                onClick={() => setShowAutoSyncModal(false)}
                disabled={syncingHolidays}
                className="p-1.5 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 transition disabled:opacity-50"
              >
                <X className="w-4 h-4" />
              </button>
            </div>

            {/* Content */}
            <div className="p-6 space-y-4 flex-1">
              {/* Controls bar: Pilih Tahun & Kebijakan Cuti Bersama */}
              <div className="grid grid-cols-1 md:grid-cols-2 gap-3 p-3.5 bg-slate-50 dark:bg-slate-800/60 rounded-xl border border-slate-200/70 dark:border-slate-700/60">
                <div>
                  <label className="block text-[11px] font-bold text-slate-700 dark:text-slate-300 mb-1">
                    Tahun Kalender
                  </label>
                  <select
                    value={autoSyncYear}
                    onChange={(e) => handleYearChangeAutoSync(Number(e.target.value))}
                    disabled={loadingAutoSyncPreview || syncingHolidays}
                    className="w-full py-1.5 px-3 text-xs font-semibold rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 focus:ring-2 focus:ring-rose-500/20 focus:border-rose-500 outline-none"
                  >
                    {[2024, 2025, 2026, 2027].map(y => (
                      <option key={y} value={y} className="bg-white dark:bg-slate-800 text-slate-900 dark:text-slate-100">Tahun {y}</option>
                    ))}
                  </select>
                </div>

                <div>
                  <label className="block text-[11px] font-bold text-slate-700 dark:text-slate-300 mb-1">
                    Kebijakan Cuti Bersama
                  </label>
                  <select
                    value={collectiveTreatment}
                    onChange={(e) => setCollectiveTreatment(e.target.value as any)}
                    disabled={syncingHolidays}
                    className="w-full py-1.5 px-3 text-xs font-semibold rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 focus:ring-2 focus:ring-rose-500/20 focus:border-rose-500 outline-none"
                  >
                    <option value="nasional" className="bg-white dark:bg-slate-800 text-slate-900 dark:text-slate-100">Libur Nasional (Bebas Cuti / Tanggal Merah)</option>
                    <option value="collective" className="bg-white dark:bg-slate-800 text-slate-900 dark:text-slate-100">Cuti Bersama Perusahaan (Potong Kuota Cuti)</option>
                  </select>
                </div>
              </div>

              {/* Status Alert jika baru saja sukses */}
              {autoSyncSuccessMsg && (
                <div className="p-3.5 bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-200 dark:border-emerald-800 rounded-xl flex items-center gap-2.5 text-emerald-800 dark:text-emerald-300 text-xs font-semibold">
                  <CheckCircle2 className="w-4 h-4 text-emerald-600 dark:text-emerald-400 shrink-0" />
                  <span>{autoSyncSuccessMsg}</span>
                </div>
              )}

              {/* Summary Badges */}
              {autoSyncPreviewData && (
                <div className="grid grid-cols-4 gap-2 text-center">
                  <div className="p-2.5 rounded-lg bg-slate-100 dark:bg-slate-800/80 border border-slate-200/60 dark:border-slate-700">
                    <p className="text-lg font-extrabold text-slate-800 dark:text-slate-100">{autoSyncPreviewData.total ?? 0}</p>
                    <p className="text-[10px] font-bold text-slate-500 uppercase tracking-wider">Total Hari</p>
                  </div>
                  <div className="p-2.5 rounded-lg bg-rose-50 dark:bg-rose-950/30 border border-rose-200/60 dark:border-rose-900/40">
                    <p className="text-lg font-extrabold text-rose-700 dark:text-rose-400">{autoSyncPreviewData.total_national ?? 0}</p>
                    <p className="text-[10px] font-bold text-rose-600 dark:text-rose-400 uppercase tracking-wider">Libur Nasional</p>
                  </div>
                  <div className="p-2.5 rounded-lg bg-amber-50 dark:bg-amber-950/30 border border-amber-200/60 dark:border-amber-900/40">
                    <p className="text-lg font-extrabold text-amber-700 dark:text-amber-400">{autoSyncPreviewData.total_collective ?? 0}</p>
                    <p className="text-[10px] font-bold text-amber-600 dark:text-amber-400 uppercase tracking-wider">Cuti Bersama</p>
                  </div>
                  <div className="p-2.5 rounded-lg bg-slate-50 dark:bg-slate-800/40 border border-slate-200/60 dark:border-slate-700">
                    <p className="text-lg font-extrabold text-slate-600 dark:text-slate-400">{autoSyncPreviewData.total_already_exists ?? 0}</p>
                    <p className="text-[10px] font-bold text-slate-500 uppercase tracking-wider">Sudah Ada</p>
                  </div>
                </div>
              )}

              {/* Table / List */}
              <div className="border border-slate-200 dark:border-slate-800 rounded-xl overflow-hidden">
                <div className="flex items-center justify-between px-3.5 py-2.5 bg-slate-50 dark:bg-slate-800/60 border-b border-slate-200 dark:border-slate-800 text-xs">
                  <button
                    type="button"
                    onClick={handleToggleSelectAll}
                    disabled={loadingAutoSyncPreview || syncingHolidays}
                    className="flex items-center gap-2 font-bold text-slate-700 dark:text-slate-200 hover:text-rose-600 transition"
                  >
                    <span className="w-4 h-4 rounded border border-slate-300 dark:border-slate-600 flex items-center justify-center bg-white dark:bg-slate-700">
                      {autoSyncPreviewData?.holidays?.length > 0 &&
                       autoSyncPreviewData.holidays.every((h: any) => selectedAutoHolidays[h.date]) ? (
                        <Check className="w-3 h-3 text-rose-600" />
                      ) : null}
                    </span>
                    <span>Pilih Semua Hari Libur</span>
                  </button>

                  <span className="text-[11px] font-semibold text-slate-500">
                    {selectedCount} dari {autoSyncPreviewData?.holidays?.length ?? 0} dipilih
                  </span>
                </div>

                <div className="max-h-[300px] overflow-y-auto divide-y divide-slate-100 dark:divide-slate-800">
                  {loadingAutoSyncPreview ? (
                    <div className="py-12 flex flex-col items-center justify-center gap-2 text-slate-400">
                      <Loader2 className="w-6 h-6 animate-spin text-rose-500" />
                      <p className="text-xs">Menghubungi API Hari Libur Indonesia...</p>
                    </div>
                  ) : (!autoSyncPreviewData?.holidays || autoSyncPreviewData.holidays.length === 0) ? (
                    <div className="py-8 text-center text-xs text-slate-400">
                      Tidak ada data hari libur ditemukan untuk tahun {autoSyncYear}.
                    </div>
                  ) : (
                    autoSyncPreviewData.holidays.map((h: any) => {
                      const isChecked = !!selectedAutoHolidays[h.date];
                      return (
                        <label
                          key={h.date}
                          className={`flex items-center justify-between px-3.5 py-2.5 text-xs hover:bg-slate-50/80 dark:hover:bg-slate-800/40 cursor-pointer transition ${
                            isChecked ? 'bg-rose-50/30 dark:bg-rose-950/10' : ''
                          }`}
                        >
                          <div className="flex items-center gap-3 min-w-0 pr-3">
                            <input
                              type="checkbox"
                              checked={isChecked}
                              onChange={(e) => {
                                setSelectedAutoHolidays(prev => ({
                                  ...prev,
                                  [h.date]: e.target.checked,
                                }));
                              }}
                              disabled={syncingHolidays}
                              className="rounded border-slate-300 text-rose-600 focus:ring-rose-500 w-4 h-4 cursor-pointer shrink-0"
                            />
                            <div className="min-w-0">
                              <p className="font-semibold text-slate-800 dark:text-slate-100 truncate">
                                {h.name}
                              </p>
                              <p className="text-[11px] text-slate-400 mt-0.5">
                                📅 {fmtDate(h.date)}
                              </p>
                            </div>
                          </div>

                          <div className="flex items-center gap-1.5 shrink-0">
                            <span className={`text-[10px] px-2 py-0.5 rounded-full font-bold uppercase ${
                              h.is_collective
                                ? 'bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300'
                                : 'bg-rose-100 text-rose-700 dark:bg-rose-950/60 dark:text-rose-300'
                            }`}>
                              {h.is_collective ? 'Cuti Bersama' : 'Nasional'}
                            </span>

                            {h.already_exists ? (
                              <span className="text-[10px] px-2 py-0.5 rounded-full font-semibold bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-400">
                                Sudah Ada
                              </span>
                            ) : (
                              <span className="text-[10px] px-2 py-0.5 rounded-full font-bold bg-emerald-100 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300">
                                Baru
                              </span>
                            )}
                          </div>
                        </label>
                      );
                    })
                  )}
                </div>
              </div>
            </div>

            {/* Footer */}
            <div className="px-6 py-3.5 border-t border-slate-100 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-900/50 flex items-center justify-between">
              <div className="text-xs text-slate-500">
                <span className="font-bold text-slate-700 dark:text-slate-300">{selectedCount}</span> hari libur akan disinkronkan
              </div>
              <div className="flex items-center gap-2">
                <button
                  type="button"
                  onClick={() => setShowAutoSyncModal(false)}
                  disabled={syncingHolidays}
                  className="px-4 py-2 text-xs font-semibold text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-xl transition"
                >
                  Batal
                </button>
                <button
                  type="button"
                  onClick={handleExecuteSync}
                  disabled={syncingHolidays || selectedCount === 0}
                  className="flex items-center gap-1.5 px-4 py-2 text-xs font-bold text-white bg-rose-600 hover:bg-rose-700 rounded-xl shadow-md shadow-rose-500/20 transition disabled:opacity-50 disabled:cursor-not-allowed cursor-pointer"
                >
                  {syncingHolidays ? (
                    <>
                      <Loader2 className="w-3.5 h-3.5 animate-spin" />
                      <span>Menyinkronkan...</span>
                    </>
                  ) : (
                    <>
                      <Sparkles className="w-3.5 h-3.5" />
                      <span>Impor ke Kalender</span>
                    </>
                  )}
                </button>
              </div>
            </div>
          </div>
        </div>
      )}

    </div>
  );
};
