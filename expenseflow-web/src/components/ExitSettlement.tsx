import React, { useState, useEffect, useCallback, useMemo } from 'react';
import {
  Briefcase, Plus, Pencil, Trash2, X, RefreshCw, Check, CheckCircle2,
  AlertCircle, AlertTriangle, ShieldCheck, ChevronRight, Info, Save,
  Loader2, ArrowUpDown, Sliders, Eye, Lock, FileText, Calendar,
  User, DollarSign, Calculator, HelpCircle, FileCheck, Ban, Sparkles, Building2,
} from 'lucide-react';
import { payrollApi } from '../services/endpoints';
import type {
  SeveranceCase,
  SeverancePreview,
  SeveranceTerminationType,
} from '../types';
import { ConfirmationDialog } from './ConfirmationDialog';

interface Props {
  canManage: boolean;
  onNavigateToRuns?: () => void;
}

const inputCls =
  'w-full rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 px-3.5 py-2 text-xs text-slate-900 dark:text-white placeholder-slate-400 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 outline-none transition';
const btnPrimary =
  'inline-flex items-center justify-center gap-1.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold px-3.5 py-2 transition cursor-pointer disabled:opacity-50 shadow-sm shadow-indigo-500/20';
const btnGhost =
  'inline-flex items-center justify-center gap-1.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 hover:bg-slate-50 dark:hover:bg-slate-800 text-slate-700 dark:text-slate-200 text-xs font-semibold px-3.5 py-2 transition cursor-pointer disabled:opacity-50';

const formatCurrency = (val: unknown, currency = 'IDR') => {
  const num = Number(val) || 0;
  if (currency === 'IDR') {
    return new Intl.NumberFormat('id-ID', {
      style: 'currency',
      currency: 'IDR',
      maximumFractionDigits: 0,
    }).format(num);
  }
  return `${currency} ${new Intl.NumberFormat('id-ID', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(num)}`;
};

const formatDateIndo = (dateStr: string | null | undefined) => {
  if (!dateStr) return '-';
  try {
    const d = new Date(dateStr);
    return new Intl.DateTimeFormat('id-ID', {
      day: 'numeric',
      month: 'short',
      year: 'numeric',
    }).format(d);
  } catch {
    return dateStr;
  }
};

const errMsg = (e: any, fallback = 'Terjadi kesalahan.') =>
  (e?.message && typeof e.message === 'string' ? e.message : fallback);

const TERMINATION_CONFIG: Record<
  SeveranceTerminationType,
  { label: string; bg: string; text: string; border: string }
> = {
  phk: {
    label: 'PHK (Pemutusan Hubungan Kerja)',
    bg: 'bg-rose-50 dark:bg-rose-950/40',
    text: 'text-rose-700 dark:text-rose-300',
    border: 'border-rose-200 dark:border-rose-800',
  },
  pkwt_end: {
    label: 'Habis Kontrak PKWT',
    bg: 'bg-amber-50 dark:bg-amber-950/40',
    text: 'text-amber-700 dark:text-amber-300',
    border: 'border-amber-200 dark:border-amber-800',
  },
  resign: {
    label: 'Pengunduran Diri (Resign)',
    bg: 'bg-sky-50 dark:bg-sky-950/40',
    text: 'text-sky-700 dark:text-sky-300',
    border: 'border-sky-200 dark:border-sky-800',
  },
  retirement: {
    label: 'Pensiun / Usia Pensiun',
    bg: 'bg-purple-50 dark:bg-purple-950/40',
    text: 'text-purple-700 dark:text-purple-300',
    border: 'border-purple-200 dark:border-purple-800',
  },
  death: {
    label: 'Meninggal Dunia',
    bg: 'bg-slate-100 dark:bg-slate-800',
    text: 'text-slate-700 dark:text-slate-300',
    border: 'border-slate-300 dark:border-slate-700',
  },
};

const STATUS_CONFIG: Record<
  'draft' | 'calculated' | 'closed',
  { label: string; bg: string; text: string; border: string }
> = {
  draft: {
    label: 'Draf',
    bg: 'bg-yellow-50 dark:bg-yellow-950/40',
    text: 'text-yellow-700 dark:text-yellow-300',
    border: 'border-yellow-200 dark:border-yellow-800',
  },
  calculated: {
    label: 'Terkalkulasi',
    bg: 'bg-indigo-50 dark:bg-indigo-950/40',
    text: 'text-indigo-700 dark:text-indigo-300',
    border: 'border-indigo-200 dark:border-indigo-800',
  },
  closed: {
    label: 'Selesai / Ditutup',
    bg: 'bg-emerald-50 dark:bg-emerald-950/40',
    text: 'text-emerald-700 dark:text-emerald-300',
    border: 'border-emerald-200 dark:border-emerald-800',
  },
};

export const ExitSettlement: React.FC<Props> = ({ canManage, onNavigateToRuns }) => {
  const [cases, setCases] = useState<SeveranceCase[]>([]);
  const [employees, setEmployees] = useState<any[]>([]);
  const [payrollRuns, setPayrollRuns] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [banner, setBanner] = useState<{ type: 'success' | 'error' | 'info'; text: string } | null>(null);

  // Filters
  const [statusFilter, setStatusFilter] = useState<string>('all');
  const [typeFilter, setTypeFilter] = useState<string>('all');
  const [searchQuery, setSearchQuery] = useState('');

  // Modals
  const [showFormModal, setShowFormModal] = useState(false);
  const [editingCase, setEditingCase] = useState<SeveranceCase | null>(null);
  const [caseForm, setCaseForm] = useState({
    user_id: '' as string | number,
    payroll_id: '' as string | number,
    termination_type: 'phk' as SeveranceTerminationType,
    termination_reason: '',
    termination_date: new Date().toISOString().slice(0, 10),
    last_working_date: new Date().toISOString().slice(0, 10),
    employment_type: 'pkwtt' as 'pkwt' | 'pkwtt',
    contract_start_date: '',
    contract_end_date: '',
    up_multiplier: '1.00',
    upmk_multiplier: '1.00',
    include_uph: true,
    annual_leave_balance_days: '0',
    relocation_cost: '0',
    other_compensation: '0',
    separation_pay: '0',
    asset_deduction: '0',
    settle_loans: true,
    notes: '',
  });
  const [savingCase, setSavingCase] = useState(false);

  // Preview Modal
  const [previewData, setPreviewData] = useState<SeverancePreview | null>(null);
  const [loadingPreview, setLoadingPreview] = useState(false);
  const [previewCaseId, setPreviewCaseId] = useState<number | null>(null);

  // Delete Dialog
  const [deletingCase, setDeletingCase] = useState<SeveranceCase | null>(null);
  const [deleting, setDeleting] = useState(false);

  const fetchCases = useCallback(async () => {
    setLoading(true);
    try {
      const res = await payrollApi.listSeveranceCases();
      setCases(res?.data ?? []);
    } catch (e: any) {
      setBanner({ type: 'error', text: errMsg(e, 'Gagal memuat berkas pesangon.') });
    } finally {
      setLoading(false);
    }
  }, []);

  const fetchSupportingData = useCallback(async () => {
    try {
      const [salariesRes, runsRes] = await Promise.all([
        payrollApi.listSalaries(),
        payrollApi.listRuns(),
      ]);
      setEmployees(salariesRes?.data ?? []);
      setPayrollRuns(runsRes?.data ?? []);
    } catch {
      // ignore
    }
  }, []);

  useEffect(() => {
    fetchCases();
    fetchSupportingData();
  }, [fetchCases, fetchSupportingData]);

  // Severance-type runs available for linking
  const severanceRuns = useMemo(() => {
    return payrollRuns.filter((r) => r.run_type === 'severance');
  }, [payrollRuns]);

  const filteredCases = useMemo(() => {
    return cases.filter((c) => {
      if (statusFilter !== 'all' && c.status !== statusFilter) return false;
      if (typeFilter !== 'all' && c.termination_type !== typeFilter) return false;
      if (searchQuery.trim()) {
        const q = searchQuery.toLowerCase();
        const empName = (c.user?.name || '').toLowerCase();
        const empCode = (c.user?.employee_code || '').toLowerCase();
        const reason = (c.termination_reason || '').toLowerCase();
        if (!empName.includes(q) && !empCode.includes(q) && !reason.includes(q)) return false;
      }
      return true;
    });
  }, [cases, statusFilter, typeFilter, searchQuery]);

  const handleOpenCreate = () => {
    setEditingCase(null);
    setCaseForm({
      user_id: '',
      payroll_id: '',
      termination_type: 'phk',
      termination_reason: '',
      termination_date: new Date().toISOString().slice(0, 10),
      last_working_date: new Date().toISOString().slice(0, 10),
      employment_type: 'pkwtt',
      contract_start_date: '',
      contract_end_date: '',
      up_multiplier: '1.00',
      upmk_multiplier: '1.00',
      include_uph: true,
      annual_leave_balance_days: '0',
      relocation_cost: '0',
      other_compensation: '0',
      separation_pay: '0',
      asset_deduction: '0',
      settle_loans: true,
      notes: '',
    });
    setShowFormModal(true);
  };

  const handleOpenEdit = (c: SeveranceCase) => {
    // Immutability check: if batch is approved or paid, disable edit
    const isLocked = c.payroll?.status === 'approved' || c.payroll?.status === 'paid';
    if (isLocked) {
      setBanner({
        type: 'error',
        text: 'Berkas pesangon terkunci karena batch payroll terkait sudah disetujui atau dibayarkan.',
      });
      return;
    }

    setEditingCase(c);
    setCaseForm({
      user_id: c.user_id,
      payroll_id: c.payroll_id || '',
      termination_type: c.termination_type,
      termination_reason: c.termination_reason || '',
      termination_date: c.termination_date,
      last_working_date: c.last_working_date || c.termination_date,
      employment_type: c.employment_type || 'pkwtt',
      contract_start_date: c.contract_start_date || '',
      contract_end_date: c.contract_end_date || '',
      up_multiplier: String(c.up_multiplier ?? '1.00'),
      upmk_multiplier: String(c.upmk_multiplier ?? '1.00'),
      include_uph: Boolean(c.include_uph),
      annual_leave_balance_days: String(c.annual_leave_balance_days ?? '0'),
      relocation_cost: String(c.relocation_cost ?? '0'),
      other_compensation: String(c.other_compensation ?? '0'),
      separation_pay: String(c.separation_pay ?? '0'),
      asset_deduction: String(c.asset_deduction ?? '0'),
      settle_loans: Boolean(c.settle_loans),
      notes: c.notes || '',
    });
    setShowFormModal(true);
  };

  const handleSaveCase = async (e: React.FormEvent) => {
    e.preventDefault();

    if (!caseForm.user_id) {
      setBanner({ type: 'error', text: 'Karyawan wajib dipilih.' });
      return;
    }
    if (!caseForm.termination_date) {
      setBanner({ type: 'error', text: 'Tanggal pengakhiran wajib diisi.' });
      return;
    }

    const upMul = parseFloat(caseForm.up_multiplier);
    const upmkMul = parseFloat(caseForm.upmk_multiplier);
    if (isNaN(upMul) || upMul < 0 || upMul > 2) {
      setBanner({ type: 'error', text: 'Faktor UP harus berada dalam rentang 0.00 hingga 2.00 (PP 35/2021).' });
      return;
    }
    if (isNaN(upmkMul) || upmkMul < 0 || upmkMul > 2) {
      setBanner({ type: 'error', text: 'Faktor UPMK harus berada dalam rentang 0.00 hingga 2.00 (PP 35/2021).' });
      return;
    }

    const payload: Partial<SeveranceCase> = {
      user_id: Number(caseForm.user_id),
      payroll_id: caseForm.payroll_id ? Number(caseForm.payroll_id) : null,
      termination_type: caseForm.termination_type,
      termination_reason: caseForm.termination_reason.trim() || null,
      termination_date: caseForm.termination_date,
      last_working_date: caseForm.last_working_date || caseForm.termination_date,
      employment_type: caseForm.employment_type,
      contract_start_date: caseForm.employment_type === 'pkwt' ? (caseForm.contract_start_date || null) : null,
      contract_end_date: caseForm.employment_type === 'pkwt' ? (caseForm.contract_end_date || null) : null,
      up_multiplier: upMul,
      upmk_multiplier: upmkMul,
      include_uph: caseForm.include_uph,
      annual_leave_balance_days: parseFloat(caseForm.annual_leave_balance_days) || 0,
      relocation_cost: parseFloat(caseForm.relocation_cost) || 0,
      other_compensation: parseFloat(caseForm.other_compensation) || 0,
      separation_pay: parseFloat(caseForm.separation_pay) || 0,
      asset_deduction: parseFloat(caseForm.asset_deduction) || 0,
      settle_loans: caseForm.settle_loans,
      notes: caseForm.notes.trim() || null,
    };

    setSavingCase(true);
    try {
      if (editingCase) {
        await payrollApi.updateSeveranceCase(editingCase.id, payload);
        setBanner({
          type: 'success',
          text: 'Berkas pesangon berhasil diperbarui. Status dikembalikan ke draf untuk kalkulasi ulang.',
        });
      } else {
        await payrollApi.createSeveranceCase(payload);
        setBanner({ type: 'success', text: 'Berkas pesangon berhasil dibuat.' });
      }
      setShowFormModal(false);
      fetchCases();
    } catch (e: any) {
      setBanner({ type: 'error', text: errMsg(e, 'Gagal menyimpan berkas pesangon.') });
    } finally {
      setSavingCase(false);
    }
  };

  const handleOpenPreview = async (c: SeveranceCase) => {
    setPreviewCaseId(c.id);
    setLoadingPreview(true);
    setPreviewData(null);
    try {
      const res = await payrollApi.previewSeveranceCase(c.id);
      setPreviewData(res?.data ?? null);
    } catch (e: any) {
      setBanner({ type: 'error', text: errMsg(e, 'Gagal memuat simulasi pesangon.') });
      setPreviewCaseId(null);
    } finally {
      setLoadingPreview(false);
    }
  };

  const handleDelete = async () => {
    if (!deletingCase) return;
    setDeleting(true);
    try {
      await payrollApi.deleteSeveranceCase(deletingCase.id);
      setBanner({ type: 'success', text: 'Berkas pesangon berhasil dihapus.' });
      setDeletingCase(null);
      fetchCases();
    } catch (e: any) {
      setBanner({ type: 'error', text: errMsg(e, 'Gagal menghapus berkas pesangon.') });
    } finally {
      setDeleting(false);
    }
  };

  return (
    <div className="space-y-6">
      {/* Alert Banner */}
      {banner && (
        <div
          className={`flex items-start gap-3 rounded-2xl p-4 text-xs font-medium transition ${
            banner.type === 'success'
              ? 'bg-emerald-50 dark:bg-emerald-950/40 text-emerald-800 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800'
              : banner.type === 'error'
              ? 'bg-rose-50 dark:bg-rose-950/40 text-rose-800 dark:text-rose-300 border border-rose-200 dark:border-rose-800'
              : 'bg-indigo-50 dark:bg-indigo-950/40 text-indigo-800 dark:text-indigo-300 border border-indigo-200 dark:border-indigo-800'
          }`}
        >
          {banner.type === 'success' ? (
            <CheckCircle2 className="w-4 h-4 mt-0.5 shrink-0 text-emerald-600 dark:text-emerald-400" />
          ) : banner.type === 'error' ? (
            <AlertCircle className="w-4 h-4 mt-0.5 shrink-0 text-rose-600 dark:text-rose-400" />
          ) : (
            <Info className="w-4 h-4 mt-0.5 shrink-0 text-indigo-600 dark:text-indigo-400" />
          )}
          <div className="flex-1">{banner.text}</div>
          <button
            onClick={() => setBanner(null)}
            className="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 cursor-pointer"
          >
            <X className="w-3.5 h-3.5" />
          </button>
        </div>
      )}

      {/* Header Info */}
      <div className="flex flex-col md:flex-row md:items-center justify-between gap-4 bg-gradient-to-br from-indigo-50/50 via-slate-50/50 to-white dark:from-slate-900/60 dark:via-slate-900/40 dark:to-slate-950 border border-slate-200/80 dark:border-slate-800/80 rounded-2xl p-5 shadow-sm">
        <div className="space-y-1">
          <div className="flex items-center gap-2">
            <span className="p-2 rounded-xl bg-indigo-100 dark:bg-indigo-950/60 text-indigo-600 dark:text-indigo-400">
              <Briefcase className="w-5 h-5" />
            </span>
            <div>
              <h3 className="text-sm font-bold text-slate-900 dark:text-white flex items-center gap-2">
                Exit Settlement / Berkas Pesangon Karyawan
              </h3>
              <p className="text-xs text-slate-500 dark:text-slate-400">
                Kalkulasi pesangon PHK (PP 35/2021) & kompensasi PKWT dengan pemisahan tegas PPh 21 Final (PP 68/2009) vs Non-Final.
              </p>
            </div>
          </div>
        </div>

        <div className="flex items-center gap-2">
          {onNavigateToRuns && (
            <button
              type="button"
              onClick={onNavigateToRuns}
              className={btnGhost}
            >
              <FileCheck className="w-3.5 h-3.5" />
              <span>Lihat Batch Payroll</span>
            </button>
          )}

          {canManage && (
            <button
              type="button"
              onClick={handleOpenCreate}
              className={btnPrimary}
            >
              <Plus className="w-4 h-4" />
              <span>Buat Berkas Pesangon</span>
            </button>
          )}
        </div>
      </div>

      {/* Guidance Callout */}
      <div className="grid grid-cols-1 md:grid-cols-2 gap-3 text-xs">
        <div className="flex items-start gap-2.5 rounded-xl bg-emerald-50/70 dark:bg-emerald-950/20 border border-emerald-200/70 dark:border-emerald-900/40 p-3 text-emerald-800 dark:text-emerald-300">
          <ShieldCheck className="w-4 h-4 mt-0.5 shrink-0 text-emerald-600 dark:text-emerald-400" />
          <div className="leading-relaxed">
            <strong className="font-semibold">PPh 21 FINAL (PP 68/2009):</strong> UP, UPMK, dan UPH dikenakan tarif progresif final (0%–25%), <em>tidak masuk bukti potong 1721-A1</em>, dan tidak ikut penyetahun Desember (dilaporkan via 1721-VII).
          </div>
        </div>

        <div className="flex items-start gap-2.5 rounded-xl bg-amber-50/70 dark:bg-amber-950/20 border border-amber-200/70 dark:border-amber-900/40 p-3 text-amber-800 dark:text-amber-300">
          <Sparkles className="w-4 h-4 mt-0.5 shrink-0 text-amber-600 dark:text-amber-400" />
          <div className="leading-relaxed">
            <strong className="font-semibold">Komponen NON-FINAL:</strong> Uang Kompensasi PKWT dan Uang Pisah (separation pay) bersifat tidak teratur non-final yang <em>ikut diperhitungkan</em> dalam rekonsiliasi tahunan 1721-A1.
          </div>
        </div>
      </div>

      {/* Filter Bar */}
      <div className="flex flex-wrap items-center justify-between gap-3 bg-white dark:bg-slate-900 p-3 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-sm">
        <div className="flex flex-wrap items-center gap-2 flex-1 min-w-[280px]">
          {/* Search */}
          <div className="relative flex-1 max-w-xs">
            <input
              type="text"
              placeholder="Cari nama karyawan, alasan..."
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              className={inputCls}
            />
            {searchQuery && (
              <button
                type="button"
                onClick={() => setSearchQuery('')}
                className="absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600"
              >
                <X className="w-3 h-3" />
              </button>
            )}
          </div>

          {/* Filter Status */}
          <select
            value={statusFilter}
            onChange={(e) => setStatusFilter(e.target.value)}
            className="rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 px-3 py-2 text-xs text-slate-900 dark:text-white focus:border-indigo-500 outline-none"
          >
            <option value="all">Semua Status</option>
            <option value="draft">Draf</option>
            <option value="calculated">Terkalkulasi</option>
            <option value="closed">Selesai / Ditutup</option>
          </select>

          {/* Filter Jenis Pengakhiran */}
          <select
            value={typeFilter}
            onChange={(e) => setTypeFilter(e.target.value)}
            className="rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 px-3 py-2 text-xs text-slate-900 dark:text-white focus:border-indigo-500 outline-none"
          >
            <option value="all">Semua Jenis Pengakhiran</option>
            <option value="phk">PHK</option>
            <option value="pkwt_end">Habis Kontrak PKWT</option>
            <option value="resign">Resign</option>
            <option value="retirement">Pensiun</option>
            <option value="death">Meninggal Dunia</option>
          </select>
        </div>

        <button
          type="button"
          onClick={fetchCases}
          disabled={loading}
          className={btnGhost}
        >
          <RefreshCw className={`w-3.5 h-3.5 ${loading ? 'animate-spin' : ''}`} />
          <span>Segarkan</span>
        </button>
      </div>

      {/* Cases Table */}
      <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-sm overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full text-left text-xs">
            <thead className="bg-slate-50 dark:bg-slate-800/70 border-b border-slate-200 dark:border-slate-800 text-slate-500 dark:text-slate-400 font-semibold uppercase tracking-wider">
              <tr>
                <th className="py-3 px-4">Karyawan</th>
                <th className="py-3 px-4">Jenis Pengakhiran</th>
                <th className="py-3 px-4">Tgl Pengakhiran</th>
                <th className="py-3 px-4">Faktor UP / UPMK</th>
                <th className="py-3 px-4">Batch Terkait</th>
                <th className="py-3 px-4">Status</th>
                <th className="py-3 px-4 text-right">Aksi</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100 dark:divide-slate-800 text-slate-700 dark:text-slate-300">
              {loading ? (
                <tr>
                  <td colSpan={7} className="py-12 text-center text-slate-400">
                    <Loader2 className="w-6 h-6 animate-spin mx-auto mb-2 text-indigo-500" />
                    <span>Memuat berkas pesangon...</span>
                  </td>
                </tr>
              ) : filteredCases.length === 0 ? (
                <tr>
                  <td colSpan={7} className="py-12 text-center text-slate-400">
                    <Briefcase className="w-8 h-8 mx-auto mb-2 text-slate-300 dark:text-slate-600" />
                    <p className="font-semibold text-slate-600 dark:text-slate-300">Belum ada berkas pesangon</p>
                    <p className="text-xs text-slate-400 mt-1">
                      {searchQuery || statusFilter !== 'all' || typeFilter !== 'all'
                        ? 'Tidak ada berkas yang cocok dengan filter.'
                        : 'Klik tombol Buat Berkas Pesangon untuk menambahkan berkas baru.'}
                    </p>
                  </td>
                </tr>
              ) : (
                filteredCases.map((c) => {
                  const typeConf = TERMINATION_CONFIG[c.termination_type] || TERMINATION_CONFIG.phk;
                  const statusConf = STATUS_CONFIG[c.status] || STATUS_CONFIG.draft;
                  const isLocked = c.payroll?.status === 'approved' || c.payroll?.status === 'paid';

                  return (
                    <tr
                      key={c.id}
                      className="hover:bg-slate-50/80 dark:hover:bg-slate-800/40 transition-colors"
                    >
                      {/* Karyawan */}
                      <td className="py-3 px-4">
                        <div className="font-bold text-slate-900 dark:text-white flex items-center gap-2">
                          <span className="w-7 h-7 rounded-full bg-indigo-50 dark:bg-indigo-950/60 text-indigo-600 dark:text-indigo-400 font-bold flex items-center justify-center text-xs">
                            {(c.user?.name || '?').charAt(0).toUpperCase()}
                          </span>
                          <div>
                            <div>{c.user?.name || `Karyawan #${c.user_id}`}</div>
                            {c.user?.employee_code && (
                              <div className="text-[10px] text-slate-400 font-mono">
                                {c.user.employee_code}
                              </div>
                            )}
                          </div>
                        </div>
                      </td>

                      {/* Jenis Pengakhiran */}
                      <td className="py-3 px-4">
                        <span
                          className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-[11px] font-medium border ${typeConf.bg} ${typeConf.text} ${typeConf.border}`}
                        >
                          {typeConf.label}
                        </span>
                        {c.termination_reason && (
                          <div className="text-[10px] text-slate-400 mt-0.5 max-w-xs truncate" title={c.termination_reason}>
                            {c.termination_reason}
                          </div>
                        )}
                      </td>

                      {/* Tgl Pengakhiran */}
                      <td className="py-3 px-4 text-slate-600 dark:text-slate-400">
                        <div>{formatDateIndo(c.termination_date)}</div>
                        <div className="text-[10px] text-slate-400 uppercase font-medium">
                          {c.employment_type}
                        </div>
                      </td>

                      {/* Faktor */}
                      <td className="py-3 px-4 font-mono text-slate-800 dark:text-slate-200">
                        <span className="text-[11px]">
                          UP: <strong>{c.up_multiplier}x</strong> | UPMK: <strong>{c.upmk_multiplier}x</strong>
                        </span>
                        {c.include_uph && (
                          <div className="text-[10px] text-emerald-600 dark:text-emerald-400 font-sans">
                            + Hak (UPH)
                          </div>
                        )}
                      </td>

                      {/* Batch Terkait */}
                      <td className="py-3 px-4">
                        {c.payroll ? (
                          <div className="space-y-0.5">
                            <span className="inline-flex items-center gap-1 font-medium text-slate-800 dark:text-slate-200">
                              <FileText className="w-3 h-3 text-indigo-500" />
                              Bulan {c.payroll.period_month}/{c.payroll.period_year}
                            </span>
                            <div>
                              <span
                                className={`inline-block px-1.5 py-0.2 rounded text-[10px] uppercase font-semibold ${
                                  isLocked
                                    ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300'
                                    : 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300'
                                }`}
                              >
                                {c.payroll.status}
                              </span>
                            </div>
                          </div>
                        ) : (
                          <span className="text-slate-400 italic text-[11px]">Belum terhubung</span>
                        )}
                      </td>

                      {/* Status */}
                      <td className="py-3 px-4">
                        <span
                          className={`inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-medium border ${statusConf.bg} ${statusConf.text} ${statusConf.border}`}
                        >
                          {statusConf.label}
                        </span>
                      </td>

                      {/* Aksi */}
                      <td className="py-3 px-4 text-right">
                        <div className="inline-flex items-center gap-1.5">
                          {/* Preview / Simulasi Button */}
                          <button
                            type="button"
                            onClick={() => handleOpenPreview(c)}
                            className="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg text-xs font-semibold bg-indigo-50 hover:bg-indigo-100 text-indigo-700 dark:bg-indigo-950/50 dark:hover:bg-indigo-900/60 dark:text-indigo-300 transition cursor-pointer"
                            title="Buka simulasi / preview rincian pesangon"
                          >
                            <Calculator className="w-3.5 h-3.5" />
                            <span>Simulasi</span>
                          </button>

                          {/* Edit Button */}
                          {canManage && (
                            <button
                              type="button"
                              onClick={() => handleOpenEdit(c)}
                              disabled={isLocked}
                              className={`p-1.5 rounded-lg transition ${
                                isLocked
                                  ? 'text-slate-300 dark:text-slate-600 cursor-not-allowed'
                                  : 'text-slate-500 hover:text-indigo-600 hover:bg-indigo-50 dark:hover:bg-indigo-950/60 cursor-pointer'
                              }`}
                              title={isLocked ? 'Terkunci: Batch sudah disetujui / dibayar' : 'Ubah berkas'}
                            >
                              {isLocked ? <Lock className="w-3.5 h-3.5" /> : <Pencil className="w-3.5 h-3.5" />}
                            </button>
                          )}

                          {/* Delete Button */}
                          {canManage && (
                            <button
                              type="button"
                              onClick={() => setDeletingCase(c)}
                              disabled={isLocked}
                              className={`p-1.5 rounded-lg transition ${
                                isLocked
                                  ? 'text-slate-300 dark:text-slate-600 cursor-not-allowed'
                                  : 'text-slate-500 hover:text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-950/60 cursor-pointer'
                              }`}
                              title={isLocked ? 'Terkunci: Batch sudah disetujui / dibayar' : 'Hapus berkas'}
                            >
                              <Trash2 className="w-3.5 h-3.5" />
                            </button>
                          )}
                        </div>
                      </td>
                    </tr>
                  );
                })
              )}
            </tbody>
          </table>
        </div>
      </div>

      {/* CREATE / EDIT MODAL */}
      {showFormModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-xs overflow-y-auto">
          <div className="w-full max-w-2xl bg-white dark:bg-slate-900 rounded-3xl border border-slate-200 dark:border-slate-800 shadow-2xl overflow-hidden animate-in fade-in zoom-in-95 duration-150 my-8">
            <div className="flex items-center justify-between px-6 py-4 border-b border-slate-100 dark:border-slate-800">
              <div className="flex items-center gap-2">
                <span className="p-2 rounded-xl bg-indigo-50 dark:bg-indigo-950/50 text-indigo-600 dark:text-indigo-400">
                  <Briefcase className="w-4 h-4" />
                </span>
                <h3 className="font-bold text-sm text-slate-900 dark:text-white">
                  {editingCase ? 'Ubah Berkas Pesangon' : 'Buat Berkas Pesangon Baru'}
                </h3>
              </div>
              <button
                type="button"
                onClick={() => setShowFormModal(false)}
                className="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 cursor-pointer"
              >
                <X className="w-4 h-4" />
              </button>
            </div>

            <form onSubmit={handleSaveCase} className="p-6 space-y-5 text-xs max-h-[80vh] overflow-y-auto">
              {/* Employee Selection */}
              <div>
                <label className="block font-semibold text-slate-700 dark:text-slate-300 mb-1">
                  Karyawan <span className="text-rose-500">*</span>
                </label>
                <select
                  value={caseForm.user_id}
                  onChange={(e) => setCaseForm({ ...caseForm, user_id: e.target.value })}
                  disabled={Boolean(editingCase)}
                  className={inputCls}
                  required
                >
                  <option value="">-- Pilih Karyawan --</option>
                  {employees.map((emp) => (
                    <option key={emp.id} value={emp.id}>
                      {emp.name} {emp.employee_code ? `(${emp.employee_code})` : ''} - Gaji: {formatCurrency(emp.basic_salary)}
                    </option>
                  ))}
                </select>
                {editingCase && (
                  <p className="text-[10px] text-slate-400 mt-1">
                    Karyawan tidak dapat diubah setelah berkas dibuat.
                  </p>
                )}
              </div>

              {/* Linked Payroll Run */}
              <div>
                <label className="block font-semibold text-slate-700 dark:text-slate-300 mb-1">
                  Hubungkan ke Batch Payroll Pesangon (Opsional)
                </label>
                <select
                  value={caseForm.payroll_id}
                  onChange={(e) => setCaseForm({ ...caseForm, payroll_id: e.target.value })}
                  className={inputCls}
                >
                  <option value="">-- Belum Dihubungkan (Draf Mandiri) --</option>
                  {severanceRuns.map((r) => (
                    <option
                      key={r.id}
                      value={r.id}
                      disabled={r.status === 'approved' || r.status === 'paid'}
                    >
                      Batch #{r.id} ({r.period_month}/{r.period_year}) - Status: {r.status}
                      {r.status === 'approved' || r.status === 'paid' ? ' (Terkunci)' : ''}
                    </option>
                  ))}
                </select>
                <p className="text-[10px] text-slate-400 mt-1">
                  Hanya batch payroll bertipe <strong>Pesangon (run_type: severance)</strong> yang dapat dipilih.
                </p>
              </div>

              {/* Grid: Jenis Pengakhiran & Alasan */}
              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                  <label className="block font-semibold text-slate-700 dark:text-slate-300 mb-1">
                    Jenis Pengakhiran Hubungan Kerja <span className="text-rose-500">*</span>
                  </label>
                  <select
                    value={caseForm.termination_type}
                    onChange={(e) =>
                      setCaseForm({
                        ...caseForm,
                        termination_type: e.target.value as SeveranceTerminationType,
                      })
                    }
                    className={inputCls}
                  >
                    <option value="phk">PHK (Pemutusan Hubungan Kerja)</option>
                    <option value="pkwt_end">Habis Kontrak PKWT</option>
                    <option value="resign">Pengunduran Diri (Resign)</option>
                    <option value="retirement">Pensiun / Usia Pensiun</option>
                    <option value="death">Meninggal Dunia</option>
                  </select>
                </div>

                <div>
                  <label className="block font-semibold text-slate-700 dark:text-slate-300 mb-1">
                    Alasan Pengakhiran (Opsional)
                  </label>
                  <input
                    type="text"
                    placeholder="misal: Efisiensi, Pelanggaran, dsb."
                    value={caseForm.termination_reason}
                    onChange={(e) =>
                      setCaseForm({ ...caseForm, termination_reason: e.target.value })
                    }
                    className={inputCls}
                  />
                </div>
              </div>

              {/* Grid: Dates & Contract */}
              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                  <label className="block font-semibold text-slate-700 dark:text-slate-300 mb-1">
                    Tanggal Pengakhiran <span className="text-rose-500">*</span>
                  </label>
                  <input
                    type="date"
                    value={caseForm.termination_date}
                    onChange={(e) =>
                      setCaseForm({ ...caseForm, termination_date: e.target.value })
                    }
                    className={inputCls}
                    required
                  />
                </div>

                <div>
                  <label className="block font-semibold text-slate-700 dark:text-slate-300 mb-1">
                    Hari Kerja Terakhir (Last Working Date)
                  </label>
                  <input
                    type="date"
                    value={caseForm.last_working_date}
                    onChange={(e) =>
                      setCaseForm({ ...caseForm, last_working_date: e.target.value })
                    }
                    className={inputCls}
                  />
                </div>
              </div>

              {/* Employment Type & Contract Dates */}
              <div className="p-4 rounded-2xl bg-slate-50 dark:bg-slate-800/40 border border-slate-200/80 dark:border-slate-800 space-y-3">
                <div className="flex items-center gap-6">
                  <span className="font-semibold text-slate-700 dark:text-slate-300">
                    Status Hubungan Kerja:
                  </span>
                  <label className="inline-flex items-center gap-2 cursor-pointer">
                    <input
                      type="radio"
                      name="employment_type"
                      value="pkwtt"
                      checked={caseForm.employment_type === 'pkwtt'}
                      onChange={() => setCaseForm({ ...caseForm, employment_type: 'pkwtt' })}
                      className="text-indigo-600 focus:ring-indigo-500"
                    />
                    <span>PKWTT (Tetap)</span>
                  </label>
                  <label className="inline-flex items-center gap-2 cursor-pointer">
                    <input
                      type="radio"
                      name="employment_type"
                      value="pkwt"
                      checked={caseForm.employment_type === 'pkwt'}
                      onChange={() => setCaseForm({ ...caseForm, employment_type: 'pkwt' })}
                      className="text-indigo-600 focus:ring-indigo-500"
                    />
                    <span>PKWT (Kontrak)</span>
                  </label>
                </div>

                {caseForm.employment_type === 'pkwt' && (
                  <div className="grid grid-cols-1 md:grid-cols-2 gap-3 pt-2">
                    <div>
                      <label className="block font-medium text-slate-600 dark:text-slate-400 mb-1">
                        Tanggal Mulai Kontrak
                      </label>
                      <input
                        type="date"
                        value={caseForm.contract_start_date}
                        onChange={(e) =>
                          setCaseForm({ ...caseForm, contract_start_date: e.target.value })
                        }
                        className={inputCls}
                      />
                    </div>
                    <div>
                      <label className="block font-medium text-slate-600 dark:text-slate-400 mb-1">
                        Tanggal Berakhir Kontrak
                      </label>
                      <input
                        type="date"
                        value={caseForm.contract_end_date}
                        onChange={(e) =>
                          setCaseForm({ ...caseForm, contract_end_date: e.target.value })
                        }
                        className={inputCls}
                      />
                    </div>
                  </div>
                )}
              </div>

              {/* Parameter PP 35/2021 Multipliers */}
              <div className="space-y-3">
                <h4 className="font-bold text-slate-900 dark:text-white flex items-center gap-1.5">
                  <Calculator className="w-4 h-4 text-indigo-500" />
                  Parameter Kompensasi Pesangon (PP 35/2021)
                </h4>

                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <div>
                    <label className="block font-semibold text-slate-700 dark:text-slate-300 mb-1">
                      Faktor Pengali UP (0.00 – 2.00) <span className="text-rose-500">*</span>
                    </label>
                    <input
                      type="number"
                      step="0.05"
                      min="0"
                      max="2"
                      value={caseForm.up_multiplier}
                      onChange={(e) =>
                        setCaseForm({ ...caseForm, up_multiplier: e.target.value })
                      }
                      className={`${inputCls} font-mono font-medium`}
                      required
                    />
                    <p className="text-[10px] text-slate-400 mt-1">
                      Standar: 1.00; Efisiensi kerugian: 0.50; Pensiun: 1.75–2.00.
                    </p>
                  </div>

                  <div>
                    <label className="block font-semibold text-slate-700 dark:text-slate-300 mb-1">
                      Faktor Pengali UPMK (0.00 – 2.00) <span className="text-rose-500">*</span>
                    </label>
                    <input
                      type="number"
                      step="0.05"
                      min="0"
                      max="2"
                      value={caseForm.upmk_multiplier}
                      onChange={(e) =>
                        setCaseForm({ ...caseForm, upmk_multiplier: e.target.value })
                      }
                      className={`${inputCls} font-mono font-medium`}
                      required
                    />
                    <p className="text-[10px] text-slate-400 mt-1">
                      Standar PP 35/2021 adalah 1.00x masa kerja penghargaan.
                    </p>
                  </div>
                </div>

                <div className="flex items-center gap-2 pt-1">
                  <input
                    type="checkbox"
                    id="include_uph"
                    checked={caseForm.include_uph}
                    onChange={(e) =>
                      setCaseForm({ ...caseForm, include_uph: e.target.checked })
                    }
                    className="rounded text-indigo-600 focus:ring-indigo-500 cursor-pointer"
                  />
                  <label htmlFor="include_uph" className="font-semibold text-slate-700 dark:text-slate-300 cursor-pointer">
                    Sertakan Uang Penggantian Hak (UPH) — Kompensasi Cuti & Perumahan 15%
                  </label>
                </div>
              </div>

              {/* Komponen Hak & Penyesuaian Lainnya */}
              <div className="grid grid-cols-1 md:grid-cols-3 gap-3">
                <div>
                  <label className="block font-medium text-slate-700 dark:text-slate-300 mb-1">
                    Sisa Hari Cuti Tahunan
                  </label>
                  <input
                    type="number"
                    step="0.5"
                    min="0"
                    max="365"
                    value={caseForm.annual_leave_balance_days}
                    onChange={(e) =>
                      setCaseForm({ ...caseForm, annual_leave_balance_days: e.target.value })
                    }
                    className={inputCls}
                  />
                  <p className="text-[10px] text-slate-400 mt-1">Dihitung upah / 25 hari.</p>
                </div>

                <div>
                  <label className="block font-medium text-slate-700 dark:text-slate-300 mb-1">
                    Biaya Relokasi (UPH)
                  </label>
                  <input
                    type="number"
                    step="1000"
                    min="0"
                    value={caseForm.relocation_cost}
                    onChange={(e) =>
                      setCaseForm({ ...caseForm, relocation_cost: e.target.value })
                    }
                    className={inputCls}
                  />
                </div>

                <div>
                  <label className="block font-medium text-slate-700 dark:text-slate-300 mb-1">
                    Kompensasi Hak Lain (UPH)
                  </label>
                  <input
                    type="number"
                    step="1000"
                    min="0"
                    value={caseForm.other_compensation}
                    onChange={(e) =>
                      setCaseForm({ ...caseForm, other_compensation: e.target.value })
                    }
                    className={inputCls}
                  />
                </div>
              </div>

              {/* Uang Pisah (Non-Final) & Potongan Aset */}
              <div className="grid grid-cols-1 md:grid-cols-2 gap-4 p-4 rounded-2xl bg-amber-50/50 dark:bg-amber-950/20 border border-amber-200/60 dark:border-amber-900/40">
                <div>
                  <label className="block font-semibold text-amber-900 dark:text-amber-300 mb-1">
                    Uang Pisah / Separation Pay (NON-FINAL)
                  </label>
                  <div className="relative">
                    <span className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 font-medium">
                      Rp
                    </span>
                    <input
                      type="number"
                      step="1000"
                      min="0"
                      value={caseForm.separation_pay}
                      onChange={(e) =>
                        setCaseForm({ ...caseForm, separation_pay: e.target.value })
                      }
                      className={`${inputCls} pl-9 font-medium`}
                    />
                  </div>
                  <p className="text-[10px] text-amber-700/80 dark:text-amber-400/80 mt-1">
                    Dikenakan pajak reguler non-final & ikut rekonsiliasi 1721-A1.
                  </p>
                </div>

                <div>
                  <label className="block font-semibold text-slate-700 dark:text-slate-300 mb-1">
                    Potongan Ganti Rugi / Aset Perusahaan
                  </label>
                  <div className="relative">
                    <span className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 font-medium">
                      Rp
                    </span>
                    <input
                      type="number"
                      step="1000"
                      min="0"
                      value={caseForm.asset_deduction}
                      onChange={(e) =>
                        setCaseForm({ ...caseForm, asset_deduction: e.target.value })
                      }
                      className={`${inputCls} pl-9 font-medium`}
                    />
                  </div>
                  <p className="text-[10px] text-slate-400 mt-1">
                    Dipangkas langsung dari total penerimaan bersih.
                  </p>
                </div>
              </div>

              {/* Toggle Lunasi Kasbon */}
              <div className="flex items-center gap-2">
                <input
                  type="checkbox"
                  id="settle_loans"
                  checked={caseForm.settle_loans}
                  onChange={(e) =>
                    setCaseForm({ ...caseForm, settle_loans: e.target.checked })
                  }
                  className="rounded text-indigo-600 focus:ring-indigo-500 cursor-pointer"
                />
                <label htmlFor="settle_loans" className="font-semibold text-slate-700 dark:text-slate-300 cursor-pointer">
                  Lunasi sisa pinjaman / kasbon karyawan secara otomatis dari uang pesangon
                </label>
              </div>

              {/* Notes */}
              <div>
                <label className="block font-semibold text-slate-700 dark:text-slate-300 mb-1">
                  Catatan Tambahan
                </label>
                <textarea
                  rows={2}
                  value={caseForm.notes}
                  onChange={(e) => setCaseForm({ ...caseForm, notes: e.target.value })}
                  className={inputCls}
                  placeholder="Catatan persetujuan direksi, dasar perjanjian bersama (PB), dsb."
                />
              </div>

              {/* Actions */}
              <div className="pt-2 flex items-center justify-end gap-2 border-t border-slate-100 dark:border-slate-800">
                <button
                  type="button"
                  onClick={() => setShowFormModal(false)}
                  disabled={savingCase}
                  className={btnGhost}
                >
                  Batal
                </button>
                <button
                  type="submit"
                  disabled={savingCase}
                  className={btnPrimary}
                >
                  {savingCase && <Loader2 className="w-3.5 h-3.5 animate-spin" />}
                  <span>{editingCase ? 'Simpan Perubahan' : 'Buat Berkas'}</span>
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* PREVIEW / SIMULATION MODAL */}
      {previewCaseId !== null && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-xs overflow-y-auto">
          <div className="w-full max-w-3xl bg-white dark:bg-slate-900 rounded-3xl border border-slate-200 dark:border-slate-800 shadow-2xl overflow-hidden animate-in fade-in zoom-in-95 duration-150 my-6">
            <div className="flex items-center justify-between px-6 py-4 border-b border-slate-100 dark:border-slate-800">
              <div className="flex items-center gap-2">
                <span className="p-2 rounded-xl bg-indigo-50 dark:bg-indigo-950/50 text-indigo-600 dark:text-indigo-400">
                  <Calculator className="w-5 h-5" />
                </span>
                <div>
                  <h3 className="font-bold text-sm text-slate-900 dark:text-white">
                    Simulasi & Rincian Pesangon (PP 35/2021 & PP 68/2009)
                  </h3>
                  <p className="text-[11px] text-slate-400">
                    Perhitungan otomatis berbasis upah bulanan, masa kerja, dan aturan pajak pesangon.
                  </p>
                </div>
              </div>
              <button
                type="button"
                onClick={() => setPreviewCaseId(null)}
                className="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 cursor-pointer"
              >
                <X className="w-4 h-4" />
              </button>
            </div>

            <div className="p-6 space-y-6 text-xs max-h-[80vh] overflow-y-auto">
              {loadingPreview ? (
                <div className="py-12 text-center text-slate-400">
                  <Loader2 className="w-6 h-6 animate-spin mx-auto mb-2 text-indigo-500" />
                  <span>Menghitung simulasi pesangon...</span>
                </div>
              ) : !previewData ? (
                <div className="py-8 text-center text-slate-400">
                  <AlertCircle className="w-6 h-6 mx-auto mb-2 text-rose-500" />
                  <p className="font-semibold text-slate-600 dark:text-slate-300">
                    Tidak dapat menampilkan simulasi.
                  </p>
                  <p className="text-xs text-slate-400 mt-1">
                    Pastikan data gaji pokok dan masa kerja karyawan tersedia.
                  </p>
                </div>
              ) : (
                <>
                  {/* Top Employee Info Card */}
                  <div className="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-2xl border border-slate-200/80 dark:border-slate-800 grid grid-cols-2 md:grid-cols-4 gap-3">
                    <div>
                      <span className="text-[10px] text-slate-400 block uppercase font-medium">Nama Karyawan</span>
                      <strong className="text-slate-900 dark:text-white font-bold">{previewData.employee_name}</strong>
                    </div>
                    <div>
                      <span className="text-[10px] text-slate-400 block uppercase font-medium">Upah Bulanan</span>
                      <strong className="text-slate-900 dark:text-white font-mono">{formatCurrency(previewData.monthly_wage)}</strong>
                    </div>
                    <div>
                      <span className="text-[10px] text-slate-400 block uppercase font-medium">Masa Kerja</span>
                      <strong className="text-slate-900 dark:text-white">
                        {previewData.tenure_years} Th ({previewData.tenure_months} Bulan)
                      </strong>
                    </div>
                    <div>
                      <span className="text-[10px] text-slate-400 block uppercase font-medium">Tgl Pengakhiran</span>
                      <strong className="text-slate-900 dark:text-white">{formatDateIndo(previewData.termination_date)}</strong>
                    </div>
                  </div>

                  {/* SECTION 1: PPh 21 FINAL (PP 68/2009) */}
                  <div className="rounded-2xl border-2 border-emerald-200 dark:border-emerald-800/70 bg-emerald-50/20 dark:bg-emerald-950/10 p-4 space-y-3">
                    <div className="flex flex-col md:flex-row md:items-center justify-between gap-2 border-b border-emerald-200/60 dark:border-emerald-800/40 pb-2">
                      <div className="flex items-center gap-2">
                        <span className="px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-600 text-white uppercase tracking-wider">
                          PPh 21 Final
                        </span>
                        <h4 className="font-bold text-slate-900 dark:text-white">
                          Komponen Pesangon Kena Pajak Final (PP 68/2009)
                        </h4>
                      </div>
                      <span className="text-[10px] text-emerald-700 dark:text-emerald-300 font-medium">
                        *Tidak dikreditkan di 1721-A1 (dilaporkan via 1721-VII)
                      </span>
                    </div>

                    <div className="space-y-2 font-mono">
                      {/* UP */}
                      <div className="flex justify-between items-center py-1 border-b border-dashed border-slate-200 dark:border-slate-800">
                        <div className="font-sans">
                          <span className="font-semibold text-slate-800 dark:text-slate-200">Uang Pesangon (UP)</span>
                          <span className="text-slate-400 text-[11px] ml-2">
                            ({previewData.up_months} bulan upah × faktor pengali)
                          </span>
                        </div>
                        <span className="font-bold text-slate-900 dark:text-white">
                          {formatCurrency(previewData.up)}
                        </span>
                      </div>

                      {/* UPMK */}
                      <div className="flex justify-between items-center py-1 border-b border-dashed border-slate-200 dark:border-slate-800">
                        <div className="font-sans">
                          <span className="font-semibold text-slate-800 dark:text-slate-200">Uang Penghargaan Masa Kerja (UPMK)</span>
                          <span className="text-slate-400 text-[11px] ml-2">
                            ({previewData.upmk_months} bulan upah)
                          </span>
                        </div>
                        <span className="font-bold text-slate-900 dark:text-white">
                          {formatCurrency(previewData.upmk)}
                        </span>
                      </div>

                      {/* UPH */}
                      <div className="space-y-1 py-1 border-b border-dashed border-slate-200 dark:border-slate-800">
                        <div className="flex justify-between items-center">
                          <span className="font-sans font-semibold text-slate-800 dark:text-slate-200">
                            Uang Penggantian Hak (UPH)
                          </span>
                          <span className="font-bold text-slate-900 dark:text-white">
                            {formatCurrency(previewData.uph)}
                          </span>
                        </div>
                        {/* Sub Breakdown */}
                        <div className="pl-4 text-[11px] font-sans text-slate-500 space-y-0.5">
                          <div className="flex justify-between">
                            <span>• Kompensasi Sisa Cuti:</span>
                            <span className="font-mono">{formatCurrency(previewData.uph_breakdown.leave_compensation)}</span>
                          </div>
                          <div className="flex justify-between">
                            <span>• Uang Perumahan & Pengobatan (15% UP+UPMK):</span>
                            <span className="font-mono">{formatCurrency(previewData.uph_breakdown.housing_medical)}</span>
                          </div>
                          {Number(previewData.uph_breakdown.relocation_cost) > 0 && (
                            <div className="flex justify-between">
                              <span>• Ongkos Relokasi / Pemulangan:</span>
                              <span className="font-mono">{formatCurrency(previewData.uph_breakdown.relocation_cost)}</span>
                            </div>
                          )}
                          {Number(previewData.uph_breakdown.other_compensation) > 0 && (
                            <div className="flex justify-between">
                              <span>• Kompensasi Hak Lainnya:</span>
                              <span className="font-mono">{formatCurrency(previewData.uph_breakdown.other_compensation)}</span>
                            </div>
                          )}
                        </div>
                      </div>

                      {/* Tax Base & Final Tax */}
                      <div className="pt-2 flex justify-between items-center text-xs font-sans">
                        <span className="font-semibold text-emerald-800 dark:text-emerald-300">
                          Dasar Pengenaan Pajak (DPP) PPh 21 Final:
                        </span>
                        <span className="font-bold font-mono text-emerald-700 dark:text-emerald-400">
                          {formatCurrency(previewData.final_tax_base)}
                        </span>
                      </div>

                      <div className="flex justify-between items-center p-2 rounded-xl bg-emerald-100/60 dark:bg-emerald-950/60 text-emerald-900 dark:text-emerald-200 text-xs font-sans">
                        <div>
                          <strong className="font-bold">Potongan PPh 21 Final (PP 68/2009):</strong>
                          <span className="text-[11px] text-emerald-700 dark:text-emerald-300 ml-2">
                            (Tarif Efektif: {(previewData.pph21_final_effective_rate * 100).toFixed(2)}%)
                          </span>
                        </div>
                        <span className="font-bold font-mono text-rose-600 dark:text-rose-400">
                          - {formatCurrency(previewData.pph21_final)}
                        </span>
                      </div>
                    </div>
                  </div>

                  {/* SECTION 2: KOMPONEN NON-FINAL */}
                  <div className="rounded-2xl border-2 border-amber-200 dark:border-amber-800/70 bg-amber-50/20 dark:bg-amber-950/10 p-4 space-y-3">
                    <div className="flex flex-col md:flex-row md:items-center justify-between gap-2 border-b border-amber-200/60 dark:border-amber-800/40 pb-2">
                      <div className="flex items-center gap-2">
                        <span className="px-2 py-0.5 rounded text-[10px] font-bold bg-amber-600 text-white uppercase tracking-wider">
                          Non-Final
                        </span>
                        <h4 className="font-bold text-slate-900 dark:text-white">
                          Komponen Penghasilan NON-FINAL (Ikut Rekonsiliasi Tahunan)
                        </h4>
                      </div>
                      <span className="text-[10px] text-amber-700 dark:text-amber-300 font-medium">
                        *Diperhitungkan pada Form 1721-A1
                      </span>
                    </div>

                    <div className="space-y-2 font-mono">
                      <div className="flex justify-between items-center py-1 border-b border-dashed border-slate-200 dark:border-slate-800">
                        <div className="font-sans">
                          <span className="font-semibold text-slate-800 dark:text-slate-200">Uang Kompensasi PKWT</span>
                          {previewData.pkwt_months > 0 && (
                            <span className="text-slate-400 text-[11px] ml-2">
                              ({previewData.pkwt_months} bulan masa kerja)
                            </span>
                          )}
                        </div>
                        <span className="font-bold text-slate-900 dark:text-white">
                          {formatCurrency(previewData.pkwt_compensation)}
                        </span>
                      </div>

                      <div className="flex justify-between items-center py-1">
                        <div className="font-sans">
                          <span className="font-semibold text-slate-800 dark:text-slate-200">Uang Pisah (Separation Pay)</span>
                        </div>
                        <span className="font-bold text-slate-900 dark:text-white">
                          {formatCurrency(previewData.separation_pay)}
                        </span>
                      </div>
                    </div>
                  </div>

                  {/* SECTION 3: POTONGAN LAINNYA */}
                  {(Number(previewData.loan_settlement) > 0 || Number(previewData.asset_deduction) > 0) && (
                    <div className="rounded-2xl border border-slate-200 dark:border-slate-800 p-4 space-y-2">
                      <h4 className="font-bold text-slate-900 dark:text-white flex items-center gap-1.5">
                        <Ban className="w-4 h-4 text-rose-500" />
                        Potongan Penyelesaian Kewajiban Karyawan
                      </h4>
                      <div className="space-y-1.5 font-mono text-xs">
                        {Number(previewData.loan_settlement) > 0 && (
                          <div className="flex justify-between items-center text-rose-600 dark:text-rose-400">
                            <span className="font-sans text-slate-700 dark:text-slate-300">
                              Pelunasan Sisa Pinjaman / Kasbon Perusahaan:
                            </span>
                            <span>- {formatCurrency(previewData.loan_settlement)}</span>
                          </div>
                        )}
                        {Number(previewData.asset_deduction) > 0 && (
                          <div className="flex justify-between items-center text-rose-600 dark:text-rose-400">
                            <span className="font-sans text-slate-700 dark:text-slate-300">
                              Ganti Rugi / Pengembalian Aset Inventaris:
                            </span>
                            <span>- {formatCurrency(previewData.asset_deduction)}</span>
                          </div>
                        )}
                      </div>
                    </div>
                  )}

                  {/* SECTION 4: GRAND TOTALS SUMMARY */}
                  <div className="rounded-2xl bg-gradient-to-br from-indigo-500 to-indigo-700 text-white p-5 space-y-3 shadow-lg shadow-indigo-500/20">
                    <div className="grid grid-cols-1 md:grid-cols-3 gap-4 text-center divide-y md:divide-y-0 md:divide-x divide-indigo-400/40">
                      <div className="pt-2 md:pt-0">
                        <span className="text-xs text-indigo-100 font-medium block">Total Bruto Pesangon</span>
                        <span className="text-lg font-bold font-mono tracking-tight">
                          {formatCurrency(previewData.total_gross)}
                        </span>
                      </div>
                      <div className="pt-2 md:pt-0">
                        <span className="text-xs text-indigo-100 font-medium block">Total Potongan (Pajak + Kasbon)</span>
                        <span className="text-lg font-bold font-mono tracking-tight text-rose-200">
                          - {formatCurrency(previewData.total_deduction)}
                        </span>
                      </div>
                      <div className="pt-2 md:pt-0">
                        <span className="text-xs text-indigo-100 font-medium block">Take-Home Pay / Total Bersih</span>
                        <span className="text-xl font-extrabold font-mono tracking-tight text-emerald-200">
                          {formatCurrency(previewData.total_net)}
                        </span>
                      </div>
                    </div>
                  </div>
                </>
              )}

              <div className="pt-2 flex items-center justify-end gap-2 border-t border-slate-100 dark:border-slate-800">
                <button
                  type="button"
                  onClick={() => setPreviewCaseId(null)}
                  className={btnGhost}
                >
                  Tutup Simulasi
                </button>
              </div>
            </div>
          </div>
        </div>
      )}

      {/* DELETE CONFIRMATION */}
      {deletingCase && (
        <ConfirmationDialog
          isOpen={true}
          title="Hapus Berkas Pesangon"
          message={`Apakah Anda yakin ingin menghapus berkas pesangon untuk karyawan ${deletingCase.user?.name || deletingCase.user_id}? Tindakan ini tidak dapat dibatalkan.`}
          confirmLabel={deleting ? 'Menghapus...' : 'Hapus Berkas'}
          confirmVariant="danger"
          isLoading={deleting}
          onConfirm={handleDelete}
          onCancel={() => setDeletingCase(null)}
        />
      )}
    </div>
  );
};
