import React, { useState, useEffect, useCallback } from 'react';
import {
  Clock, CheckCircle2, XCircle, AlertCircle, ChevronLeft,
  ChevronRight, RefreshCw, Filter, Timer, UserCheck, CalendarDays,
  BadgeCheck, Hourglass, Search,
} from 'lucide-react';
import { overtimeApi } from '../services/endpoints';
import { ApiError } from '../services/api';

// ─── Types ───────────────────────────────────────────────────
interface OvertimeRecord {
  id: number;
  attendance_id: number;
  user_id: number;
  user_name: string;
  department: string;
  attendance_date: string;
  check_in_time: string | null;
  check_out_time: string | null;
  overtime_minutes: number;
  overtime_formatted: string;
  status: 'pending' | 'approved' | 'rejected';
  is_auto_checkout: boolean;
  overtime_reason: string | null;
  reviewed_at: string | null;
  notes: string | null;
  created_at: string;
}

interface PaginationMeta {
  current_page: number;
  last_page: number;
  total: number;
  per_page: number;
}

type FilterStatus = 'all' | 'pending' | 'approved' | 'rejected';

// ─── Helpers ─────────────────────────────────────────────────
const fmtDate = (iso: string | null) => {
  if (!iso) return '-';
  return new Date(iso).toLocaleDateString('id-ID', {
    day: '2-digit', month: 'short', year: 'numeric',
  });
};

const fmtTime = (iso: string | null) => {
  if (!iso) return '-';
  return new Date(iso).toLocaleTimeString('id-ID', {
    hour: '2-digit', minute: '2-digit', hour12: false,
  });
};

const fmtMinutes = (m: number) => {
  if (m <= 0) return '0m';
  const j = Math.floor(m / 60);
  const mn = m % 60;
  if (j === 0) return `${mn}m`;
  if (mn === 0) return `${j}j`;
  return `${j}j ${mn}m`;
};

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

// ─── Modal konfirmasi approve / reject ───────────────────────
interface ActionModalProps {
  mode: 'approve' | 'reject';
  record: OvertimeRecord;
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
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-sm px-4"
      onClick={(e) => { if (e.target === e.currentTarget) onClose(); }}
    >
      <div className="bg-white rounded-2xl shadow-2xl w-full max-w-md p-6 animate-in fade-in slide-in-from-bottom-4 duration-200">
        {/* Header */}
        <div className={`flex items-center gap-3 mb-4 pb-4 border-b ${isReject ? 'border-rose-100' : 'border-emerald-100'}`}>
          <div className={`w-10 h-10 rounded-full flex items-center justify-center ${isReject ? 'bg-rose-100' : 'bg-emerald-100'}`}>
            {isReject
              ? <XCircle className="w-5 h-5 text-rose-600" />
              : <CheckCircle2 className="w-5 h-5 text-emerald-600" />}
          </div>
          <div>
            <p className={`font-bold text-sm ${isReject ? 'text-rose-700' : 'text-emerald-700'}`}>
              {isReject ? 'Tolak Lembur' : 'Setujui Lembur'}
            </p>
            <p className="text-xs text-slate-500">{record.user_name}</p>
          </div>
        </div>

        {/* Info lembur */}
        <div className="bg-slate-50 rounded-xl p-3 mb-4 space-y-1.5 text-xs">
          <div className="flex justify-between">
            <span className="text-slate-500">Tanggal</span>
            <span className="font-semibold text-slate-700">{fmtDate(record.attendance_date)}</span>
          </div>
          <div className="flex justify-between">
            <span className="text-slate-500">Durasi lembur</span>
            <span className="font-bold text-orange-600">{record.overtime_formatted || fmtMinutes(record.overtime_minutes)}</span>
          </div>
          <div className="flex justify-between">
            <span className="text-slate-500">Check-in / out</span>
            <span className="font-semibold text-slate-700">
              {fmtTime(record.check_in_time)} – {fmtTime(record.check_out_time)}
            </span>
          </div>
          {record.is_auto_checkout && (
            <div className="flex justify-between">
              <span className="text-slate-500">Tipe checkout</span>
              <span className="text-[10px] font-bold px-2 py-0.5 rounded bg-purple-100 text-purple-700">Auto-Checkout</span>
            </div>
          )}
          {record.overtime_reason && (
            <div className="flex justify-between">
              <span className="text-slate-500">Keterangan</span>
              <span className="font-semibold text-slate-700">{record.overtime_reason}</span>
            </div>
          )}
        </div>

        {/* Peringatan jika reject */}
        {isReject && (
          <div className="flex items-start gap-2 bg-rose-50 border border-rose-200 rounded-lg p-3 mb-4 text-xs text-rose-700">
            <AlertCircle className="w-3.5 h-3.5 mt-0.5 shrink-0" />
            <span>Jam lembur karyawan akan di-reset ke <strong>0 jam</strong> dan tidak masuk hitungan payroll.</span>
          </div>
        )}

        {/* Form */}
        <form onSubmit={handleSubmit} className="space-y-3">
          <div>
            <label className="text-xs font-semibold text-slate-600 block mb-1.5">
              {isReject ? 'Alasan penolakan *' : 'Catatan (opsional)'}
            </label>
            <textarea
              value={notes}
              onChange={(e) => setNotes(e.target.value)}
              placeholder={isReject ? 'Jelaskan alasan penolakan lembur...' : 'Tambahkan catatan approval (boleh kosong)...'}
              rows={3}
              className="w-full text-xs p-2.5 border border-slate-200 rounded-lg resize-none focus:ring-1 focus:ring-indigo-400 focus:outline-none focus:border-indigo-400 placeholder:text-slate-300"
            />
            {err && <p className="text-[11px] text-rose-600 mt-1">{err}</p>}
          </div>

          <div className="flex gap-2 pt-1">
            <button
              type="button"
              onClick={onClose}
              className="flex-1 py-2 text-xs font-semibold border border-slate-200 rounded-lg hover:bg-slate-50 text-slate-600 transition"
            >
              Batal
            </button>
            <button
              type="submit"
              disabled={busy}
              className={`flex-1 py-2 text-xs font-bold rounded-lg text-white transition flex items-center justify-center gap-1.5 ${
                isReject
                  ? 'bg-rose-500 hover:bg-rose-600 disabled:bg-rose-300'
                  : 'bg-emerald-500 hover:bg-emerald-600 disabled:bg-emerald-300'
              }`}
            >
              {busy && <div className="w-3 h-3 border-2 border-white/40 border-t-white rounded-full animate-spin" />}
              {isReject ? 'Tolak Lembur' : 'Setujui Lembur'}
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
    <div className="bg-white rounded-2xl border border-slate-100 p-4 flex items-center gap-3 shadow-sm">
      <div className={`w-10 h-10 rounded-xl flex items-center justify-center shrink-0 ${color}`}>
        {icon}
      </div>
      <div>
        <p className="text-[11px] text-slate-500 font-medium">{label}</p>
        <p className="text-xl font-bold text-slate-800 leading-tight">{value}</p>
        {sub && <p className="text-[10px] text-slate-400 mt-0.5">{sub}</p>}
      </div>
    </div>
  );
}

// ─── Status badge ─────────────────────────────────────────────
function StatusBadge({ status }: { status: string }) {
  switch (status) {
    case 'approved':
      return (
        <span className="inline-flex items-center gap-1 text-[10px] font-bold px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-700">
          <BadgeCheck className="w-3 h-3" /> Disetujui
        </span>
      );
    case 'rejected':
      return (
        <span className="inline-flex items-center gap-1 text-[10px] font-bold px-2 py-0.5 rounded-full bg-rose-100 text-rose-700">
          <XCircle className="w-3 h-3" /> Ditolak
        </span>
      );
    default:
      return (
        <span className="inline-flex items-center gap-1 text-[10px] font-bold px-2 py-0.5 rounded-full bg-amber-100 text-amber-700 animate-pulse">
          <Hourglass className="w-3 h-3" /> Menunggu
        </span>
      );
  }
}

// ─── Main component ───────────────────────────────────────────
export function OvertimeApprovalView() {
  const [records, setRecords] = useState<OvertimeRecord[]>([]);
  const [meta, setMeta] = useState<PaginationMeta | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');

  const [filterStatus, setFilterStatus] = useState<FilterStatus>('pending');
  const [filterStart, setFilterStart] = useState('');
  const [filterEnd, setFilterEnd] = useState('');
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);

  // Summary counts (dihitung dari data pending dan all)
  const [countPending, setCountPending] = useState(0);
  const [countApproved, setCountApproved] = useState(0);
  const [countRejected, setCountRejected] = useState(0);

  // Modal state
  const [modal, setModal] = useState<{ mode: 'approve' | 'reject'; record: OvertimeRecord } | null>(null);

  const loadRecords = useCallback(async (pg = 1) => {
    setLoading(true);
    setError('');
    try {
      const params: Record<string, string | number> = { page: pg };
      if (filterStatus !== 'all') params.status = filterStatus;
      if (filterStart) params.start_date = filterStart;
      if (filterEnd) params.end_date = filterEnd;

      const res = await overtimeApi.list(params as Parameters<typeof overtimeApi.list>[0]);
      const data: OvertimeRecord[] = Array.isArray(res?.data) ? res.data : (Array.isArray(res) ? res : []);
      setRecords(data);
      if (res?.meta) setMeta(res.meta);
      else if (res?.current_page) setMeta({
        current_page: res.current_page,
        last_page: res.last_page,
        total: res.total,
        per_page: res.per_page,
      });
      setPage(pg);
    } catch (e: unknown) {
      setError(e instanceof ApiError ? e.message : 'Gagal memuat data lembur.');
    } finally {
      setLoading(false);
    }
  }, [filterStatus, filterStart, filterEnd]);

  // Load summary counts (3 calls singkat)
  const loadSummary = useCallback(async () => {
    try {
      const [rPending, rApproved, rRejected] = await Promise.all([
        overtimeApi.list({ status: 'pending', page: 1 }),
        overtimeApi.list({ status: 'approved', page: 1 }),
        overtimeApi.list({ status: 'rejected', page: 1 }),
      ]);
      setCountPending(rPending?.total ?? rPending?.meta?.total ?? (rPending?.data?.length ?? 0));
      setCountApproved(rApproved?.total ?? rApproved?.meta?.total ?? (rApproved?.data?.length ?? 0));
      setCountRejected(rRejected?.total ?? rRejected?.meta?.total ?? (rRejected?.data?.length ?? 0));
    } catch { /* diam — summary tidak kritis */ }
  }, []);

  useEffect(() => {
    loadRecords(1);
    loadSummary();
  }, [loadRecords, loadSummary]);

  const handleApply = () => loadRecords(1);

  const handleReset = () => {
    setFilterStatus('pending');
    setFilterStart('');
    setFilterEnd('');
    setSearch('');
  };

  const doApprove = async (notes: string) => {
    if (!modal) return;
    await overtimeApi.approve(modal.record.id, notes);
    await Promise.all([loadRecords(page), loadSummary()]);
  };

  const doReject = async (notes: string) => {
    if (!modal) return;
    await overtimeApi.reject(modal.record.id, notes);
    await Promise.all([loadRecords(page), loadSummary()]);
  };

  // Filter pencarian nama (client-side dari halaman saat ini)
  const displayed = search.trim()
    ? records.filter((r) => r.user_name.toLowerCase().includes(search.trim().toLowerCase())
        || r.department?.toLowerCase().includes(search.trim().toLowerCase()))
    : records;

  return (
    <div className="p-4 md:p-6 space-y-5 animate-in fade-in slide-in-from-bottom-2 duration-300">

      {/* ── Header ── */}
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div>
          <h2 className="text-lg font-bold text-slate-800 flex items-center gap-2">
            <Timer className="w-5 h-5 text-orange-500" />
            Approval Lembur Karyawan
          </h2>
          <p className="text-xs text-slate-500 mt-0.5">
            Setiap lembur wajib disetujui HRD sebelum masuk hitungan payroll.
          </p>
        </div>
        <button
          onClick={() => { loadRecords(page); loadSummary(); }}
          className="self-start sm:self-auto flex items-center gap-1.5 text-xs font-semibold text-indigo-600 border border-indigo-200 bg-white px-3 py-1.5 rounded-lg hover:bg-indigo-50 transition"
        >
          <RefreshCw className="w-3.5 h-3.5" /> Refresh
        </button>
      </div>

      {/* ── Summary cards ── */}
      <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
        <button
          onClick={() => { setFilterStatus('pending'); setPage(1); }}
          className="text-left transition hover:scale-[1.01]"
        >
          <SummaryCard
            label="Menunggu Persetujuan"
            value={countPending}
            sub="klik untuk filter"
            icon={<Hourglass className="w-5 h-5 text-amber-600" />}
            color="bg-amber-50"
          />
        </button>
        <button
          onClick={() => { setFilterStatus('approved'); setPage(1); }}
          className="text-left transition hover:scale-[1.01]"
        >
          <SummaryCard
            label="Disetujui"
            value={countApproved}
            sub="klik untuk filter"
            icon={<CheckCircle2 className="w-5 h-5 text-emerald-600" />}
            color="bg-emerald-50"
          />
        </button>
        <button
          onClick={() => { setFilterStatus('rejected'); setPage(1); }}
          className="text-left transition hover:scale-[1.01]"
        >
          <SummaryCard
            label="Ditolak"
            value={countRejected}
            sub="klik untuk filter"
            icon={<XCircle className="w-5 h-5 text-rose-600" />}
            color="bg-rose-50"
          />
        </button>
      </div>

      {/* ── Filter bar ── */}
      <div className="bg-white rounded-2xl border border-slate-100 p-4 shadow-sm space-y-3">
        <div className="flex items-center gap-2 text-xs font-semibold text-slate-600">
          <Filter className="w-3.5 h-3.5" /> Filter
        </div>
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-2.5">
          {/* Status */}
          <div>
            <label className="block text-[10px] font-semibold text-slate-500 uppercase mb-1">Status</label>
            <select
              value={filterStatus}
              onChange={(e) => setFilterStatus(e.target.value as FilterStatus)}
              className="w-full text-xs p-2.5 border border-slate-200 rounded-lg focus:ring-1 focus:ring-indigo-400 focus:outline-none bg-white"
            >
              <option value="all">Semua status</option>
              <option value="pending">Menunggu</option>
              <option value="approved">Disetujui</option>
              <option value="rejected">Ditolak</option>
            </select>
          </div>

          {/* Tanggal mulai */}
          <div>
            <label className="block text-[10px] font-semibold text-slate-500 uppercase mb-1">Dari Tanggal</label>
            <input
              type="date"
              value={filterStart}
              onChange={(e) => setFilterStart(e.target.value)}
              className="w-full text-xs p-2.5 border border-slate-200 rounded-lg focus:ring-1 focus:ring-indigo-400 focus:outline-none"
            />
          </div>

          {/* Tanggal akhir */}
          <div>
            <label className="block text-[10px] font-semibold text-slate-500 uppercase mb-1">Sampai Tanggal</label>
            <input
              type="date"
              value={filterEnd}
              onChange={(e) => setFilterEnd(e.target.value)}
              className="w-full text-xs p-2.5 border border-slate-200 rounded-lg focus:ring-1 focus:ring-indigo-400 focus:outline-none"
            />
          </div>

          {/* Search nama */}
          <div>
            <label className="block text-[10px] font-semibold text-slate-500 uppercase mb-1">Cari Karyawan</label>
            <div className="relative">
              <Search className="absolute left-2.5 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-slate-400 pointer-events-none" />
              <input
                type="text"
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                placeholder="Nama / departemen..."
                className="w-full pl-8 text-xs p-2.5 border border-slate-200 rounded-lg focus:ring-1 focus:ring-indigo-400 focus:outline-none"
              />
            </div>
          </div>
        </div>

        <div className="flex gap-2 pt-1">
          <button
            onClick={handleApply}
            className="px-4 py-2 text-xs font-bold bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg transition"
          >
            Terapkan Filter
          </button>
          <button
            onClick={handleReset}
            className="px-4 py-2 text-xs font-semibold border border-slate-200 bg-white hover:bg-slate-50 text-slate-600 rounded-lg transition"
          >
            Reset
          </button>
        </div>
      </div>

      {/* ── Error ── */}
      {error && (
        <div className="flex items-start gap-2 bg-rose-50 border border-rose-200 text-rose-700 rounded-xl px-4 py-3 text-xs">
          <AlertCircle className="w-4 h-4 shrink-0 mt-0.5" />
          <div className="flex-1">
            <p className="font-semibold">Gagal memuat data</p>
            <p>{error}</p>
          </div>
          <button onClick={() => loadRecords(page)} className="font-semibold underline shrink-0">
            Coba lagi
          </button>
        </div>
      )}

      {/* ── Tabel ── */}
      <div className="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden">
        {/* Table header info */}
        <div className="px-4 py-3 border-b border-slate-100 flex items-center justify-between">
          <p className="text-xs font-semibold text-slate-600 flex items-center gap-1.5">
            <UserCheck className="w-3.5 h-3.5 text-indigo-500" />
            {meta ? `${meta.total} pengajuan` : `${displayed.length} pengajuan`}
            {filterStatus !== 'all' && (
              <span className="ml-1 text-[10px] font-bold px-2 py-0.5 rounded-full bg-indigo-100 text-indigo-700">
                {filterStatus === 'pending' ? 'Menunggu' : filterStatus === 'approved' ? 'Disetujui' : 'Ditolak'}
              </span>
            )}
          </p>
          {loading && (
            <div className="w-4 h-4 border-2 border-indigo-300/40 border-t-indigo-500 rounded-full animate-spin" />
          )}
        </div>

        {/* Desktop table */}
        <div className="overflow-x-auto">
          <table className="w-full text-xs">
            <thead className="border-b border-slate-100 bg-slate-50/60">
              <tr>
                <th className="py-2.5 px-3 font-semibold text-left text-slate-500">Karyawan</th>
                <th className="py-2.5 px-3 font-semibold text-left text-slate-500">Tanggal</th>
                <th className="py-2.5 px-3 font-semibold text-center text-slate-500">Masuk</th>
                <th className="py-2.5 px-3 font-semibold text-center text-slate-500">Pulang</th>
                <th className="py-2.5 px-3 font-semibold text-center text-slate-500">Lembur</th>
                <th className="py-2.5 px-3 font-semibold text-center text-slate-500">Tipe</th>
                <th className="py-2.5 px-3 font-semibold text-left text-slate-500">Keterangan</th>
                <th className="py-2.5 px-3 font-semibold text-center text-slate-500">Status</th>
                <th className="py-2.5 px-3 font-semibold text-center text-slate-500">Aksi</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-50">
              {!loading && displayed.length === 0 && (
                <tr>
                  <td colSpan={9} className="py-16 text-center">
                    <div className="flex flex-col items-center gap-2 text-slate-400">
                      <Clock className="w-10 h-10 opacity-30" />
                      <p className="font-semibold text-sm">Tidak ada data lembur</p>
                      <p className="text-xs">
                        {filterStatus === 'pending'
                          ? 'Semua pengajuan lembur sudah diproses.'
                          : 'Coba ubah filter untuk melihat data lain.'}
                      </p>
                    </div>
                  </td>
                </tr>
              )}
              {displayed.map((r) => {
                const av = avatarFor(r.user_name);
                const isPending = r.status === 'pending';
                return (
                  <tr key={r.id} className="hover:bg-slate-50/60 transition-colors">
                    {/* Karyawan */}
                    <td className="py-3 px-3">
                      <div className="flex items-center gap-2.5">
                        <div className={`w-7 h-7 rounded-full flex items-center justify-center text-[10px] font-bold shrink-0 ${av.bg} ${av.text}`}>
                          {initialsOf(r.user_name)}
                        </div>
                        <div>
                          <p className="font-semibold text-slate-800">{r.user_name}</p>
                          {r.department && (
                            <p className="text-[10px] text-slate-400">{r.department}</p>
                          )}
                        </div>
                      </div>
                    </td>

                    {/* Tanggal */}
                    <td className="py-3 px-3">
                      <div className="flex items-center gap-1 text-slate-700">
                        <CalendarDays className="w-3 h-3 text-slate-400 shrink-0" />
                        {fmtDate(r.attendance_date)}
                      </div>
                    </td>

                    {/* Check-in */}
                    <td className="py-3 px-3 text-center font-mono text-slate-700">
                      {fmtTime(r.check_in_time)}
                    </td>

                    {/* Check-out */}
                    <td className="py-3 px-3 text-center font-mono text-slate-700">
                      {fmtTime(r.check_out_time)}
                    </td>

                    {/* Durasi lembur */}
                    <td className="py-3 px-3 text-center">
                      <span className="font-bold text-orange-600">
                        {r.overtime_formatted || fmtMinutes(r.overtime_minutes)}
                      </span>
                    </td>

                    {/* Auto-checkout badge */}
                    <td className="py-3 px-3 text-center">
                      {r.is_auto_checkout ? (
                        <span className="text-[10px] font-bold px-2 py-0.5 rounded-full bg-purple-100 text-purple-700">
                          Auto
                        </span>
                      ) : (
                        <span className="text-[10px] font-bold px-2 py-0.5 rounded-full bg-slate-100 text-slate-500">
                          Manual
                        </span>
                      )}
                    </td>

                    {/* Keterangan lembur */}
                    <td className="py-3 px-3">
                      {r.overtime_reason ? (
                        <span className="text-[11px] text-amber-700 bg-amber-50 px-2 py-1 rounded-lg">
                          {r.overtime_reason}
                        </span>
                      ) : (
                        <span className="text-[10px] text-slate-300">—</span>
                      )}
                    </td>

                    {/* Status */}
                    <td className="py-3 px-3 text-center">
                      <StatusBadge status={r.status} />
                      {r.reviewed_at && !isPending && (
                        <p className="text-[10px] text-slate-400 mt-0.5">{fmtDate(r.reviewed_at)}</p>
                      )}
                    </td>

                    {/* Aksi */}
                    <td className="py-3 px-3">
                      {isPending ? (
                        <div className="flex items-center justify-center gap-1.5">
                          <button
                            onClick={() => setModal({ mode: 'approve', record: r })}
                            className="flex items-center gap-1 px-2.5 py-1.5 text-[11px] font-bold bg-emerald-500 hover:bg-emerald-600 text-white rounded-lg transition"
                          >
                            <CheckCircle2 className="w-3 h-3" /> Setujui
                          </button>
                          <button
                            onClick={() => setModal({ mode: 'reject', record: r })}
                            className="flex items-center gap-1 px-2.5 py-1.5 text-[11px] font-bold bg-rose-500 hover:bg-rose-600 text-white rounded-lg transition"
                          >
                            <XCircle className="w-3 h-3" /> Tolak
                          </button>
                        </div>
                      ) : (
                        <div className="text-center">
                          {r.notes ? (
                            <p className="text-[10px] text-slate-500 italic max-w-[120px] truncate" title={r.notes}>
                              "{r.notes}"
                            </p>
                          ) : (
                            <span className="text-[10px] text-slate-300">—</span>
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
          <div className="px-4 py-3 border-t border-slate-100 flex items-center justify-between text-xs text-slate-500">
            <span>
              Halaman <span className="font-bold text-slate-700">{meta.current_page}</span> dari{' '}
              <span className="font-bold text-slate-700">{meta.last_page}</span>
              {' '}· Total {meta.total} data
            </span>
            <div className="flex items-center gap-1">
              <button
                disabled={meta.current_page <= 1}
                onClick={() => loadRecords(meta.current_page - 1)}
                className="p-1.5 rounded-lg border border-slate-200 hover:bg-slate-50 disabled:opacity-40 disabled:cursor-not-allowed transition"
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
                    className={`w-7 h-7 rounded-lg text-xs font-semibold transition ${
                      pg === meta.current_page
                        ? 'bg-indigo-600 text-white'
                        : 'border border-slate-200 hover:bg-slate-50 text-slate-600'
                    }`}
                  >
                    {pg}
                  </button>
                );
              })}
              <button
                disabled={meta.current_page >= meta.last_page}
                onClick={() => loadRecords(meta.current_page + 1)}
                className="p-1.5 rounded-lg border border-slate-200 hover:bg-slate-50 disabled:opacity-40 disabled:cursor-not-allowed transition"
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
