import React, { useState, useEffect, useCallback } from 'react';
import {
  Smartphone, CheckCircle2, XCircle, AlertCircle, ChevronLeft,
  ChevronRight, RefreshCw, Filter, ShieldCheck, UserCheck,
  BadgeCheck, Hourglass, Search, ArrowRight,
} from 'lucide-react';
import { deviceChangeApi } from '../services/endpoints';
import { ApiError, invalidateCache } from '../services/api';
import { useDebounce } from '../hooks/useDebounce';

// ─── Types ───────────────────────────────────────────────────
interface DeviceUser {
  id: number;
  name: string;
  email: string;
  employee_code: string | null;
  department: string | null;
}

interface DeviceChangeRecord {
  id: number;
  user_id: number;
  company_id: number;
  old_device_id: string | null;
  old_device_name: string | null;
  new_device_id: string;
  new_device_name: string | null;
  status: 'pending' | 'approved' | 'rejected';
  reviewed_by: number | null;
  reviewed_at: string | null;
  notes: string | null;
  created_at: string;
  user?: DeviceUser | null;
  reviewer?: { id: number; name: string } | null;
}

interface PaginationMeta {
  current_page: number;
  last_page: number;
  total: number;
  per_page: number;
}

type FilterStatus = 'all' | 'pending' | 'approved' | 'rejected';

// ─── Helpers ─────────────────────────────────────────────────
const fmtDateTime = (iso: string | null) => {
  if (!iso) return '-';
  return new Date(iso).toLocaleString('id-ID', {
    day: '2-digit', month: 'short', year: 'numeric',
    hour: '2-digit', minute: '2-digit', hour12: false,
  });
};

const fmtDate = (iso: string | null) => {
  if (!iso) return '-';
  return new Date(iso).toLocaleDateString('id-ID', {
    day: '2-digit', month: 'short', year: 'numeric',
  });
};

const nameOf = (r: DeviceChangeRecord) => r.user?.name ?? `User #${r.user_id}`;

const initialsOf = (name: string) =>
  name.split(/\s+/).slice(0, 2).map((s) => s[0]).join('').toUpperCase();

const avatarColors = [
  { bg: 'bg-indigo-100', text: 'text-indigo-700' },
  { bg: 'bg-emerald-100', text: 'text-emerald-700' },
  { bg: 'bg-amber-100', text: 'text-amber-700' },
  { bg: 'bg-rose-100', text: 'text-rose-700' },
  { bg: 'bg-violet-100', text: 'text-violet-700' },
  { bg: 'bg-teal-100', text: 'text-teal-700' },
];
const avatarFor = (name: string) =>
  avatarColors[
  name.split('').reduce((a, c) => a + c.charCodeAt(0), 0) % avatarColors.length
  ];

const deviceLabel = (name: string | null, id: string | null) =>
  name || (id ? `${id.slice(0, 12)}…` : '—');

// ─── Modal konfirmasi approve / reject ───────────────────────
interface ActionModalProps {
  mode: 'approve' | 'reject';
  record: DeviceChangeRecord;
  onConfirm: (notes: string) => Promise<void>;
  onClose: () => void;
}

function ActionModal({ mode, record, onConfirm, onClose }: ActionModalProps) {
  const [notes, setNotes] = useState('');
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState('');

  const isReject = mode === 'reject';

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (isReject && !notes.trim()) {
      setErr('Alasan penolakan wajib diisi.');
      return;
    }
    setBusy(true);
    setErr('');
    try {
      await onConfirm(notes.trim());
      onClose();
    } catch (ex: unknown) {
      setErr(ex instanceof ApiError ? ex.message : 'Terjadi kesalahan.');
      setBusy(false);
    }
  };

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm px-4"
      onClick={(e) => { if (e.target === e.currentTarget) onClose(); }}
    >
      <div className="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-md p-6 border border-slate-100 dark:border-slate-800 animate-in fade-in slide-in-from-bottom-4 duration-200">
        {/* Header */}
        <div className={`flex items-center gap-3 mb-4 pb-4 border-b ${isReject ? 'border-rose-100 dark:border-rose-900/40' : 'border-emerald-100 dark:border-emerald-900/40'}`}>
          <div className={`w-10 h-10 rounded-full flex items-center justify-center ${isReject ? 'bg-rose-100 dark:bg-rose-950/70' : 'bg-emerald-100 dark:bg-emerald-950/70'}`}>
            {isReject
              ? <XCircle className="w-5 h-5 text-rose-600 dark:text-rose-400" />
              : <CheckCircle2 className="w-5 h-5 text-emerald-600 dark:text-emerald-400" />}
          </div>
          <div>
            <p className={`font-bold text-sm ${isReject ? 'text-rose-700 dark:text-rose-400' : 'text-emerald-700 dark:text-emerald-400'}`}>
              {isReject ? 'Tolak Pindah Perangkat' : 'Setujui Pindah Perangkat'}
            </p>
            <p className="text-xs text-slate-500 dark:text-slate-400">{nameOf(record)}</p>
          </div>
        </div>

        {/* Info perangkat */}
        <div className="bg-slate-50 dark:bg-slate-800/60 rounded-xl p-3 mb-4 space-y-2 text-xs border border-slate-100 dark:border-slate-750">
          <div className="flex items-center justify-between gap-2">
            <div className="flex-1 min-w-0">
              <p className="text-[10px] text-slate-400 dark:text-slate-500 uppercase font-semibold">Perangkat lama</p>
              <p className="font-semibold text-slate-600 dark:text-slate-300 truncate" title={record.old_device_name ?? record.old_device_id ?? ''}>
                {deviceLabel(record.old_device_name, record.old_device_id)}
              </p>
            </div>
            <ArrowRight className="w-4 h-4 text-slate-400 dark:text-slate-500 shrink-0" />
            <div className="flex-1 min-w-0 text-right">
              <p className="text-[10px] text-slate-400 dark:text-slate-500 uppercase font-semibold">Perangkat baru</p>
              <p className="font-bold text-indigo-700 dark:text-indigo-400 truncate" title={record.new_device_name ?? record.new_device_id}>
                {deviceLabel(record.new_device_name, record.new_device_id)}
              </p>
            </div>
          </div>
          <div className="flex justify-between pt-1 border-t border-slate-100 dark:border-slate-750">
            <span className="text-slate-500 dark:text-slate-400">Diajukan</span>
            <span className="font-semibold text-slate-700 dark:text-slate-200">{fmtDateTime(record.created_at)}</span>
          </div>
        </div>

        {/* Peringatan */}
        {isReject ? (
          <div className="flex items-start gap-2 bg-rose-50 dark:bg-rose-950/40 border border-rose-200 dark:border-rose-900 rounded-lg p-3 mb-4 text-xs text-rose-700 dark:text-rose-400">
            <AlertCircle className="w-3.5 h-3.5 mt-0.5 shrink-0" />
            <span>Karyawan <strong>tetap tidak bisa login</strong> di perangkat baru. Perangkat lama tetap terikat.</span>
          </div>
        ) : (
          <div className="flex items-start gap-2 bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-900/40 rounded-lg p-3 mb-4 text-xs text-amber-700 dark:text-amber-400">
            <AlertCircle className="w-3.5 h-3.5 mt-0.5 shrink-0" />
            <span>Perangkat lama akan <strong>dilepas</strong> dan tak bisa dipakai lagi. Pastikan permintaan ini sah untuk mencegah titip absen.</span>
          </div>
        )}

        {/* Form */}
        <form onSubmit={handleSubmit} className="space-y-3">
          <div>
            <label className="text-xs font-semibold text-slate-600 dark:text-slate-300 block mb-1.5">
              {isReject ? 'Alasan penolakan *' : 'Catatan (opsional)'}
            </label>
            <textarea
              value={notes}
              onChange={(e) => setNotes(e.target.value)}
              placeholder={isReject ? 'Jelaskan alasan penolakan...' : 'Tambahkan catatan (boleh kosong)...'}
              rows={3}
              className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 rounded-lg resize-none focus:ring-1 focus:ring-indigo-400 focus:outline-none focus:border-indigo-400 placeholder:text-slate-300 dark:placeholder:text-slate-500"
            />
            {err && <p className="text-[11px] text-rose-600 dark:text-rose-400 mt-1">{err}</p>}
          </div>

          <div className="flex gap-2 pt-1">
            <button
              type="button"
              onClick={onClose}
              className="flex-1 py-2 text-xs font-semibold border border-slate-200 dark:border-slate-700 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-800 text-slate-600 dark:text-slate-300 transition cursor-pointer"
            >
              Batal
            </button>
            <button
              type="submit"
              disabled={busy}
              className={`flex-1 py-2 text-xs font-bold rounded-lg text-white transition flex items-center justify-center gap-1.5 cursor-pointer ${isReject
                  ? 'bg-rose-500 hover:bg-rose-600 disabled:bg-rose-300 dark:disabled:bg-rose-900/50'
                  : 'bg-emerald-500 hover:bg-emerald-600 disabled:bg-emerald-300 dark:disabled:bg-emerald-900/50'
                }`}
            >
              {busy && <div className="w-3 h-3 border-2 border-white/40 border-t-white rounded-full animate-spin" />}
              {isReject ? 'Tolak' : 'Setujui'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}

// ─── Summary card ─────────────────────────────────────────────
function SummaryCard({
  label, value, sub, icon, color,
}: {
  label: string; value: number | string; sub?: string;
  icon: React.ReactNode; color: string;
}) {
  return (
    <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-100 dark:border-slate-800 p-4 flex items-center gap-3 shadow-sm">
      <div className={`w-10 h-10 rounded-xl flex items-center justify-center shrink-0 ${color}`}>
        {icon}
      </div>
      <div>
        <p className="text-[11px] text-slate-500 dark:text-slate-400 font-medium">{label}</p>
        <p className="text-xl font-bold text-slate-800 dark:text-slate-100 leading-tight">{value}</p>
        {sub && <p className="text-[10px] text-slate-400 dark:text-slate-500 mt-0.5">{sub}</p>}
      </div>
    </div>
  );
}

// ─── Status badge ─────────────────────────────────────────────
function StatusBadge({ status }: { status: string }) {
  switch (status) {
    case 'approved':
      return (
        <span className="inline-flex items-center gap-1 text-[10px] font-bold px-2 py-0.5 rounded-full bg-emerald-100 dark:bg-emerald-950/60 text-emerald-700 dark:text-emerald-400">
          <BadgeCheck className="w-3 h-3" /> Disetujui
        </span>
      );
    case 'rejected':
      return (
        <span className="inline-flex items-center gap-1 text-[10px] font-bold px-2 py-0.5 rounded-full bg-rose-100 dark:bg-rose-950/60 text-rose-700 dark:text-rose-400">
          <XCircle className="w-3 h-3" /> Ditolak
        </span>
      );
    default:
      return (
        <span className="inline-flex items-center gap-1 text-[10px] font-bold px-2 py-0.5 rounded-full bg-amber-100 dark:bg-amber-950/60 text-amber-700 dark:text-amber-400 animate-pulse">
          <Hourglass className="w-3 h-3" /> Menunggu
        </span>
      );
  }
}

// ─── Main component ───────────────────────────────────────────
export function DeviceChangeApprovalView() {
  const [records, setRecords] = useState<DeviceChangeRecord[]>([]);
  const [meta, setMeta] = useState<PaginationMeta | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');

  const [filterStatus, setFilterStatus] = useState<FilterStatus>('pending');
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);

  const [countPending, setCountPending] = useState(0);
  const [countApproved, setCountApproved] = useState(0);
  const [countRejected, setCountRejected] = useState(0);

  const [modal, setModal] = useState<{ mode: 'approve' | 'reject'; record: DeviceChangeRecord } | null>(null);

  const loadRecords = useCallback(async (pg = 1, forceRefresh = false) => {
    setLoading(true);
    setError('');
    try {
      if (forceRefresh) invalidateCache('/dashboard/attendance/device-changes');
      const params: Record<string, string | number> = { page: pg };
      if (filterStatus !== 'all') params.status = filterStatus;

      const res = await deviceChangeApi.list(params as Parameters<typeof deviceChangeApi.list>[0], forceRefresh);
      const data: DeviceChangeRecord[] = Array.isArray(res?.data) ? res.data : (Array.isArray(res) ? res : []);
      setRecords(data);
      if (res?.summary) {
        setCountPending(res.summary.pending ?? 0);
        setCountApproved(res.summary.approved ?? 0);
        setCountRejected(res.summary.rejected ?? 0);
      }
      if (res?.meta) setMeta(res.meta);
      else if (res?.current_page) setMeta({
        current_page: res.current_page,
        last_page: res.last_page,
        total: res.total,
        per_page: res.per_page,
      });
      setPage(pg);
    } catch (e: unknown) {
      setError(e instanceof ApiError ? e.message : 'Gagal memuat permintaan pindah perangkat.');
    } finally {
      setLoading(false);
    }
  }, [filterStatus]);

  useEffect(() => {
    loadRecords(1);
  }, [loadRecords]);

  const doApprove = async (notes: string) => {
    if (!modal) return;
    await deviceChangeApi.approve(modal.record.id, notes);
    await loadRecords(page);
  };

  const doReject = async (notes: string) => {
    if (!modal) return;
    await deviceChangeApi.reject(modal.record.id, notes);
    await loadRecords(page);
  };

  const debouncedSearch = useDebounce(search, 500);

  // Filter pencarian nama (client-side dari halaman saat ini)
  const displayed = debouncedSearch
    ? records.filter((r) => {
      const q = debouncedSearch.toLowerCase();
      return nameOf(r).toLowerCase().includes(q)
        || (r.user?.department?.toLowerCase().includes(q) ?? false)
        || (r.user?.employee_code?.toLowerCase().includes(q) ?? false);
    })
    : records;

  return (
    <div className="p-4 md:p-6 space-y-5 animate-in fade-in slide-in-from-bottom-2 duration-300">

      {/* ── Header ── */}
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <button
          onClick={() => loadRecords(page, true)}
          disabled={loading}
          className="ml-auto self-start sm:self-auto flex items-center gap-1.5 text-xs font-semibold text-indigo-600 dark:text-indigo-400 border border-indigo-200 dark:border-indigo-800 bg-white dark:bg-slate-800 px-3 py-1.5 rounded-lg hover:bg-indigo-50 dark:hover:bg-indigo-950/40 transition disabled:opacity-50 cursor-pointer"
        >
          <RefreshCw className={`w-3.5 h-3.5 ${loading ? 'animate-spin' : ''}`} /> Refresh
        </button>
      </div>

      {/* ── Summary cards ── */}
      <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
        <button onClick={() => { setFilterStatus('pending'); setPage(1); }} className="text-left transition hover:scale-[1.01] cursor-pointer">
          <SummaryCard
            label="Menunggu Persetujuan"
            value={countPending}
            sub="klik untuk filter"
            icon={<Hourglass className="w-5 h-5 text-amber-600 dark:text-amber-400" />}
            color="bg-amber-50 dark:bg-amber-950/40"
          />
        </button>
        <button onClick={() => { setFilterStatus('approved'); setPage(1); }} className="text-left transition hover:scale-[1.01] cursor-pointer">
          <SummaryCard
            label="Disetujui"
            value={countApproved}
            sub="klik untuk filter"
            icon={<CheckCircle2 className="w-5 h-5 text-emerald-600 dark:text-emerald-400" />}
            color="bg-emerald-50 dark:bg-emerald-950/40"
          />
        </button>
        <button onClick={() => { setFilterStatus('rejected'); setPage(1); }} className="text-left transition hover:scale-[1.01] cursor-pointer">
          <SummaryCard
            label="Ditolak"
            value={countRejected}
            sub="klik untuk filter"
            icon={<XCircle className="w-5 h-5 text-rose-600 dark:text-rose-400" />}
            color="bg-rose-50 dark:bg-rose-950/40"
          />
        </button>
      </div>

      {/* Tabs */}
      <div className="flex flex-wrap items-center gap-1 border-b border-slate-200 dark:border-slate-800">
        {[
          { key: 'all' as const, label: 'Semua Permintaan', count: countPending + countApproved + countRejected, icon: <Smartphone className="w-3.5 h-3.5" /> },
          { key: 'pending' as const, label: 'Menunggu', count: countPending, icon: <Hourglass className="w-3.5 h-3.5" /> },
          { key: 'approved' as const, label: 'Disetujui', count: countApproved, icon: <CheckCircle2 className="w-3.5 h-3.5" /> },
          { key: 'rejected' as const, label: 'Ditolak', count: countRejected, icon: <XCircle className="w-3.5 h-3.5" /> },
        ].map((t) => (
          <button
            key={t.key}
            onClick={() => { setFilterStatus(t.key); setPage(1); }}
            className={`flex items-center gap-1.5 px-3 sm:px-4 py-2.5 text-xs font-bold border-b-2 -mb-px transition cursor-pointer ${filterStatus === t.key
              ? 'border-indigo-500 text-indigo-600 dark:text-indigo-400'
              : 'border-transparent text-slate-400 hover:text-slate-600 dark:text-slate-500 dark:hover:text-slate-300'
              }`}
          >
            {t.icon}
            {t.label}
            <span className={`text-[10px] px-1.5 py-0.5 rounded-full font-mono ${filterStatus === t.key ? 'bg-indigo-50 dark:bg-indigo-900/40 text-indigo-600 dark:text-indigo-400' : 'bg-slate-100 dark:bg-slate-800 text-slate-500'}`}>
              {t.count}
            </span>
          </button>
        ))}
      </div>

      {/* ── Filter bar ── */}
      <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-100 dark:border-slate-800 p-4 shadow-sm space-y-3">
        <div className="flex items-center gap-2 text-xs font-semibold text-slate-600 dark:text-slate-300">
          <Filter className="w-3.5 h-3.5" /> Filter
        </div>
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
          {/* Status */}
          <div>
            <label className="block text-[10px] font-semibold text-slate-500 dark:text-slate-400 uppercase mb-1">Status</label>
            <select
              value={filterStatus}
              onChange={(e) => setFilterStatus(e.target.value as FilterStatus)}
              className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-lg focus:ring-1 focus:ring-indigo-400 focus:outline-none bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100"
            >
              <option value="all">Semua status</option>
              <option value="pending">Menunggu</option>
              <option value="approved">Disetujui</option>
              <option value="rejected">Ditolak</option>
            </select>
          </div>

          {/* Search nama */}
          <div>
            <label className="block text-[10px] font-semibold text-slate-500 dark:text-slate-400 uppercase mb-1">Cari Karyawan</label>
            <div className="relative">
              <Search className="absolute left-2.5 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-slate-400 pointer-events-none" />
              <input
                type="text"
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                placeholder="Nama / departemen / NIK..."
                className="w-full pl-8 text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 focus:ring-1 focus:ring-indigo-400 focus:outline-none"
              />
            </div>
          </div>
        </div>
      </div>

      {/* ── Error ── */}
      {error && (
        <div className="flex items-start gap-2 bg-rose-50 dark:bg-rose-950/40 border border-rose-200 dark:border-rose-900 text-rose-700 dark:text-rose-400 rounded-xl px-4 py-3 text-xs">
          <AlertCircle className="w-4 h-4 shrink-0 mt-0.5" />
          <div className="flex-1">
            <p className="font-semibold">Gagal memuat data</p>
            <p>{error}</p>
          </div>
          <button onClick={() => loadRecords(page)} className="font-semibold underline shrink-0 cursor-pointer">
            Coba lagi
          </button>
        </div>
      )}

      {/* ── Tabel ── */}
      <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-100 dark:border-slate-800 shadow-sm overflow-hidden">
        <div className="px-4 py-3 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
          <p className="text-xs font-semibold text-slate-600 dark:text-slate-300 flex items-center gap-1.5">
            <UserCheck className="w-3.5 h-3.5 text-indigo-500" />
            {meta ? `${meta.total} permintaan` : `${displayed.length} permintaan`}
            {filterStatus !== 'all' && (
              <span className="ml-1 text-[10px] font-bold px-2 py-0.5 rounded-full bg-indigo-100 dark:bg-indigo-950/60 text-indigo-700 dark:text-indigo-400">
                {filterStatus === 'pending' ? 'Menunggu' : filterStatus === 'approved' ? 'Disetujui' : 'Ditolak'}
              </span>
            )}
          </p>
          {loading && (
            <div className="w-4 h-4 border-2 border-indigo-300/40 border-t-indigo-500 rounded-full animate-spin" />
          )}
        </div>

        <div className="overflow-x-auto">
          <table className="w-full text-xs">
            <thead className="border-b border-slate-100 dark:border-slate-800 bg-slate-50/60 dark:bg-slate-800/50">
              <tr>
                <th className="py-2.5 px-3 font-semibold text-left text-slate-500 dark:text-slate-400">Karyawan</th>
                <th className="py-2.5 px-3 font-semibold text-left text-slate-500 dark:text-slate-400">Perangkat Lama</th>
                <th className="py-2.5 px-3 font-semibold text-left text-slate-500 dark:text-slate-400">Perangkat Baru</th>
                <th className="py-2.5 px-3 font-semibold text-left text-slate-500 dark:text-slate-400">Diajukan</th>
                <th className="py-2.5 px-3 font-semibold text-center text-slate-500 dark:text-slate-400">Status</th>
                <th className="py-2.5 px-3 font-semibold text-center text-slate-500 dark:text-slate-400">Aksi</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
              {!loading && displayed.length === 0 && (
                <tr>
                  <td colSpan={6} className="py-16 text-center">
                    <div className="flex flex-col items-center gap-2 text-slate-400 dark:text-slate-500">
                      <Smartphone className="w-10 h-10 opacity-30" />
                      <p className="font-semibold text-sm">Tidak ada permintaan pindah perangkat</p>
                      <p className="text-xs">
                        {filterStatus === 'pending'
                          ? 'Semua permintaan sudah diproses.'
                          : 'Coba ubah filter untuk melihat data lain.'}
                      </p>
                    </div>
                  </td>
                </tr>
              )}
              {displayed.map((r) => {
                const nm = nameOf(r);
                const av = avatarFor(nm);
                const isPending = r.status === 'pending';
                return (
                  <tr key={r.id} className="hover:bg-slate-50/60 dark:hover:bg-slate-800/40 transition-colors">
                    {/* Karyawan */}
                    <td className="py-3 px-3">
                      <div className="flex items-center gap-2.5">
                        <div className={`w-7 h-7 rounded-full flex items-center justify-center text-[10px] font-bold shrink-0 ${av.bg} ${av.text}`}>
                          {initialsOf(nm)}
                        </div>
                        <div>
                          <p className="font-semibold text-slate-800 dark:text-slate-100">{nm}</p>
                          <p className="text-[10px] text-slate-400 dark:text-slate-500">
                            {[r.user?.employee_code, r.user?.department].filter(Boolean).join(' · ') || '—'}
                          </p>
                        </div>
                      </div>
                    </td>

                    {/* Perangkat lama */}
                    <td className="py-3 px-3">
                      <div className="flex items-center gap-1.5 text-slate-500 dark:text-slate-400">
                        <Smartphone className="w-3 h-3 text-slate-300 dark:text-slate-600 shrink-0" />
                        <span className="truncate max-w-[140px]" title={r.old_device_name ?? r.old_device_id ?? ''}>
                          {deviceLabel(r.old_device_name, r.old_device_id)}
                        </span>
                      </div>
                    </td>

                    {/* Perangkat baru */}
                    <td className="py-3 px-3">
                      <div className="flex items-center gap-1.5 text-indigo-700 dark:text-indigo-400 font-semibold">
                        <Smartphone className="w-3 h-3 text-indigo-400 dark:text-indigo-500 shrink-0" />
                        <span className="truncate max-w-[140px]" title={r.new_device_name ?? r.new_device_id}>
                          {deviceLabel(r.new_device_name, r.new_device_id)}
                        </span>
                      </div>
                    </td>

                    {/* Diajukan */}
                    <td className="py-3 px-3 text-slate-600 dark:text-slate-300">
                      {fmtDateTime(r.created_at)}
                    </td>

                    {/* Status */}
                    <td className="py-3 px-3 text-center">
                      <StatusBadge status={r.status} />
                      {r.reviewed_at && !isPending && (
                        <p className="text-[10px] text-slate-400 dark:text-slate-500 mt-0.5">
                          {fmtDate(r.reviewed_at)}
                          {r.reviewer?.name ? ` · ${r.reviewer.name}` : ''}
                        </p>
                      )}
                    </td>

                    {/* Aksi */}
                    <td className="py-3 px-3">
                      {isPending ? (
                        <div className="flex items-center justify-center gap-1.5">
                          <button
                            onClick={() => setModal({ mode: 'approve', record: r })}
                            className="flex items-center gap-1 px-2.5 py-1.5 text-[11px] font-bold bg-emerald-500 hover:bg-emerald-600 text-white rounded-lg transition cursor-pointer"
                          >
                            <CheckCircle2 className="w-3 h-3" /> Setujui
                          </button>
                          <button
                            onClick={() => setModal({ mode: 'reject', record: r })}
                            className="flex items-center gap-1 px-2.5 py-1.5 text-[11px] font-bold bg-rose-500 hover:bg-rose-600 text-white rounded-lg transition cursor-pointer"
                          >
                            <XCircle className="w-3 h-3" /> Tolak
                          </button>
                        </div>
                      ) : (
                        <div className="text-center">
                          {r.notes ? (
                            <p className="text-[10px] text-slate-500 dark:text-slate-400 italic max-w-[120px] truncate mx-auto" title={r.notes}>
                              "{r.notes}"
                            </p>
                          ) : (
                            <span className="text-[10px] text-slate-300 dark:text-slate-600">—</span>
                          )}
                        </div>
                      )}
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>

        {/* ── Pagination ── */}
        {meta && meta.last_page > 1 && (
          <div className="px-4 py-3 border-t border-slate-100 dark:border-slate-800 flex items-center justify-between text-xs text-slate-500 dark:text-slate-400">
            <span>
              Halaman <span className="font-bold text-slate-700 dark:text-slate-200">{meta.current_page}</span> dari{' '}
              <span className="font-bold text-slate-700 dark:text-slate-200">{meta.last_page}</span>
              {' '}· Total {meta.total} data
            </span>
            <div className="flex items-center gap-1">
              <button
                disabled={meta.current_page <= 1}
                onClick={() => loadRecords(meta.current_page - 1)}
                className="p-1.5 rounded-lg border border-slate-200 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-800 disabled:opacity-40 disabled:cursor-not-allowed transition cursor-pointer"
              >
                <ChevronLeft className="w-3.5 h-3.5" />
              </button>
              {Array.from({ length: Math.min(meta.last_page, 5) }, (_, i) => {
                const pg = meta.last_page <= 5 ? i + 1
                  : meta.current_page <= 3 ? i + 1
                    : meta.current_page >= meta.last_page - 2 ? meta.last_page - 4 + i
                      : meta.current_page - 2 + i;
                return (
                  <button
                    key={pg}
                    onClick={() => loadRecords(pg)}
                    className={`w-7 h-7 rounded-lg text-xs font-semibold transition cursor-pointer ${pg === meta.current_page
                        ? 'bg-indigo-600 text-white'
                        : 'border border-slate-200 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-800 text-slate-600 dark:text-slate-300'
                      }`}
                  >
                    {pg}
                  </button>
                );
              })}
              <button
                disabled={meta.current_page >= meta.last_page}
                onClick={() => loadRecords(meta.current_page + 1)}
                className="p-1.5 rounded-lg border border-slate-200 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-800 disabled:opacity-40 disabled:cursor-not-allowed transition cursor-pointer"
              >
                <ChevronRight className="w-3.5 h-3.5" />
              </button>
            </div>
          </div>
        )}
      </div>

      {/* ── Modal approve / reject ── */}
      {modal && (
        <ActionModal
          mode={modal.mode}
          record={modal.record}
          onConfirm={modal.mode === 'approve' ? doApprove : doReject}
          onClose={() => setModal(null)}
        />
      )}
    </div>
  );
}
