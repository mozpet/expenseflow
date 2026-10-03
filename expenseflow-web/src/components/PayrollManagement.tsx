import React, { useState, useEffect, useCallback, useMemo } from 'react';
import {
  Calculator, Users, Layers, HandCoins, Plus, Pencil, Trash2, X, RefreshCw,
  Check, CheckCircle2, AlertCircle, AlertTriangle, Send, ThumbsUp, XCircle,
  Download, Eye, Building2, Banknote, ShieldCheck, ArrowLeft, Info, Save,
  Wallet, Coins, BadgeCheck, Loader2, FileText, Clock, ChevronRight, ChevronDown,
  ShieldAlert, KeyRound, Lock, Unlock, History, SlidersHorizontal, Sparkles, CreditCard, Shield,
  BookOpen, FileSpreadsheet, Settings, Landmark, Briefcase, Sigma,
} from 'lucide-react';
import { PayrollDisbursement } from './PayrollDisbursement';
import { PayrollGlJournal } from './PayrollGlJournal';
import { PayrollTax1721A1 } from './PayrollTax1721A1';
import { StrukturSkalaUpah } from './StrukturSkalaUpah';
import { MasterKursValas } from './MasterKursValas';
import { ExitSettlement } from './ExitSettlement';
import { FormulaDslEditor } from './FormulaDslEditor';
import { payrollApi, attendanceApi, userApi, PayrollStatus } from '../services/endpoints';
import type {
  BpjsProfile,
  CalculationStep,
  CalculationTraceStep,
  PayrollAdjustment,
  EmployeeBankAccount,
  PayrollLog,
  RunLogsResponse,
  PinStatus,
  PayrollGroup,
  CompanyTaxProfile,
  JobLevel,
  SalaryGrade,
} from '../types';
import { useAuth } from '../auth/AuthContext';
import CustomDatePicker from './CustomDatePicker';
import { ConfirmationDialog, ConfirmationType } from './ConfirmationDialog';

// ═══════════════════════════════════════════════════════════════
// Props
// ═══════════════════════════════════════════════════════════════
interface PayrollManagementProps {
  onAddAuditLog?: (title: string, details: string, badge: string) => void;
  onAddNotification?: (type: 'due' | 'flag' | 'new' | 'success', title: string, subtitle: string) => void;
}

// ═══════════════════════════════════════════════════════════════
// Helpers & konstanta bersama
// ═══════════════════════════════════════════════════════════════
const MONTHS_ID = [
  'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
  'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
];
const monthName = (m: number) => MONTHS_ID[m - 1] ?? String(m);

const PTKP_OPTIONS = ['TK/0', 'TK/1', 'TK/2', 'TK/3', 'K/0', 'K/1', 'K/2', 'K/3'];

const jkkRiskClassLabel = (c: number) => {
  switch (c) {
    case 1: return 'Kelas I — Sangat Rendah (0.24%)';
    case 2: return 'Kelas II — Rendah (0.54%)';
    case 3: return 'Kelas III — Sedang (0.89%)';
    case 4: return 'Kelas IV — Tinggi (1.27%)';
    case 5: return 'Kelas V — Sangat Tinggi (1.74%)';
    default: return `Kelas ${c}`;
  }
};

const calculationStepLabel = (code: string) => {
  const map: Record<string, string> = {
    GROSS: 'Penghasilan Bruto',
    OVERTIME: 'Tunjangan Lembur',
    ATTENDANCE: 'Potongan Absen',
    BPJS_KES: 'BPJS Kesehatan',
    BPJS_JKK: 'BPJS JKK (Kecelakaan Kerja)',
    BPJS_JKM: 'BPJS JKM (Kematian)',
    BPJS_JHT: 'BPJS JHT (Hari Tua)',
    BPJS_JP: 'BPJS JP (Pensiun)',
    PPH21_TER: 'PPh21 (TER Bulanan)',
    PPH21_PASAL17: 'PPh21 (Pasal 17 Tahunan)',
    NETT: 'Penghasilan Neto',
    SEVERANCE_PRORATE: 'Prorata Pesangon',
    SEVERANCE_UP: 'Uang Pesangon (UP)',
    SEVERANCE_UPMK: 'Uang Penghargaan Masa Kerja (UPMK)',
    SEVERANCE_UPH: 'Uang Penggantian Hak (UPH)',
    PKWT_COMPENSATION: 'Uang Kompensasi PKWT',
    PPH21_FINAL: 'PPh 21 Final (Pesangon PP 68/2009)',
    PPH26: 'PPh 26 (Subjek Pajak Luar Negeri)',
    CURRENCY: 'Konversi Valuta Asing',
  };
  return map[code] ?? code;
};

export const formatCurrency = (val: unknown) =>
  new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 })
    .format(Number(val) || 0);

export const formatDate = (iso?: string | null) => {
  if (!iso) return '-';
  const d = new Date(iso);
  if (isNaN(d.getTime())) return '-';
  return d.toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' });
};

const todayStr = () => new Date().toISOString().slice(0, 10);

// Ambil pesan error yang ramah dari objek error (ApiError memiliki .message).
export const errMsg = (e: any, fallback = 'Terjadi kesalahan. Coba lagi.') =>
  (e?.message && typeof e.message === 'string' ? e.message : fallback);

// Label & warna status batch payroll.
const RUN_STATUS: Record<PayrollStatus, { label: string; cls: string; dot: string }> = {
  draft:      { label: 'Draft',     cls: 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300', dot: 'bg-slate-400' },
  calculated: { label: 'Dihitung',  cls: 'bg-sky-50 text-sky-700 dark:bg-sky-950/50 dark:text-sky-300', dot: 'bg-sky-500' },
  submitted:  { label: 'Diajukan',  cls: 'bg-amber-50 text-amber-700 dark:bg-amber-950/50 dark:text-amber-300', dot: 'bg-amber-500' },
  approved:   { label: 'Disetujui', cls: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300', dot: 'bg-emerald-500' },
  paid:       { label: 'Dibayar',   cls: 'bg-indigo-50 text-indigo-700 dark:bg-indigo-950/50 dark:text-indigo-300', dot: 'bg-indigo-500' },
  rejected:   { label: 'Ditolak',   cls: 'bg-rose-50 text-rose-700 dark:bg-rose-950/50 dark:text-rose-300', dot: 'bg-rose-500' },
};

const LOAN_STATUS: Record<string, { label: string; cls: string }> = {
  pending:   { label: 'Menunggu',    cls: 'bg-amber-50 text-amber-700 dark:bg-amber-950/50 dark:text-amber-300' },
  active:    { label: 'Aktif',       cls: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300' },
  paid:      { label: 'Lunas',       cls: 'bg-indigo-50 text-indigo-700 dark:bg-indigo-950/50 dark:text-indigo-300' },
  cancelled: { label: 'Dibatalkan',  cls: 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400 line-through' },
};

const ADJUSTMENT_STATUS: Record<string, { label: string; cls: string; dot: string }> = {
  pending:  { label: 'Menunggu',   cls: 'bg-amber-50 text-amber-700 dark:bg-amber-950/50 dark:text-amber-300', dot: 'bg-amber-500' },
  approved: { label: 'Disetujui',  cls: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300', dot: 'bg-emerald-500' },
  applied:  { label: 'Diterapkan', cls: 'bg-indigo-50 text-indigo-700 dark:bg-indigo-950/50 dark:text-indigo-300', dot: 'bg-indigo-500' },
  voided:   { label: 'Dibatalkan', cls: 'bg-rose-50 text-rose-700 dark:bg-rose-950/50 dark:text-rose-300 line-through', dot: 'bg-rose-500' },
};

const BANK_ACCOUNT_STATUS: Record<string, { label: string; cls: string }> = {
  active:               { label: 'Aktif',               cls: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300' },
  pending_verification: { label: 'Menunggu Verifikasi', cls: 'bg-amber-50 text-amber-700 dark:bg-amber-950/50 dark:text-amber-300' },
  superseded:           { label: 'Digantikan',          cls: 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400' },
  rejected:             { label: 'Ditolak',             cls: 'bg-rose-50 text-rose-700 dark:bg-rose-950/50 dark:text-rose-300 line-through' },
};

// Kelas input & tombol yang dipakai ulang.
export const inputCls =
  'w-full rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 px-3.5 py-2.5 text-sm text-slate-900 dark:text-white placeholder-slate-400 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 outline-none transition';
export const btnPrimary =
  'inline-flex items-center justify-center gap-1.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold px-4 py-2.5 transition cursor-pointer disabled:opacity-50 shadow-sm shadow-indigo-500/20';
export const btnGhost =
  'inline-flex items-center justify-center gap-1.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 hover:bg-slate-50 dark:hover:bg-slate-800 text-slate-700 dark:text-slate-200 text-xs font-semibold px-4 py-2.5 transition cursor-pointer disabled:opacity-50';

// ═══════════════════════════════════════════════════════════════
// Komponen kecil bersama
// ═══════════════════════════════════════════════════════════════
export type BannerState = { type: 'success' | 'error' | 'info'; text: string } | null;

export function useBanner() {
  const [banner, setBanner] = useState<BannerState>(null);
  const show = useCallback((type: 'success' | 'error' | 'info', text: string) => setBanner({ type, text }), []);
  useEffect(() => {
    if (banner?.type === 'success') {
      const t = setTimeout(() => setBanner(null), 4000);
      return () => clearTimeout(t);
    }
  }, [banner]);
  return { banner, show, clear: () => setBanner(null) };
}

export const Banner: React.FC<{ banner: BannerState; onClose: () => void }> = ({ banner, onClose }) => {
  if (!banner) return null;
  const map = {
    success: { cls: 'bg-emerald-50 border-emerald-200 text-emerald-700 dark:bg-emerald-950/40 dark:border-emerald-900/40 dark:text-emerald-300', icon: <CheckCircle2 className="w-4 h-4" /> },
    error:   { cls: 'bg-rose-50 border-rose-200 text-rose-700 dark:bg-rose-950/40 dark:border-rose-900/40 dark:text-rose-300', icon: <AlertCircle className="w-4 h-4" /> },
    info:    { cls: 'bg-sky-50 border-sky-200 text-sky-700 dark:bg-sky-950/40 dark:border-sky-900/40 dark:text-sky-300', icon: <Info className="w-4 h-4" /> },
  }[banner.type];
  return (
    <div className={`flex items-start gap-2 rounded-xl border px-4 py-3 text-xs ${map.cls}`}>
      <span className="shrink-0 mt-0.5">{map.icon}</span>
      <p className="flex-1 font-medium leading-relaxed">{banner.text}</p>
      <button onClick={onClose} className="shrink-0 opacity-60 hover:opacity-100 transition cursor-pointer"><X className="w-3.5 h-3.5" /></button>
    </div>
  );
};

export const Field: React.FC<{ label: string; hint?: string; required?: boolean; children: React.ReactNode }> = ({ label, hint, required, children }) => (
  <div className="space-y-1.5">
    <label className="text-xs font-semibold text-slate-600 dark:text-slate-300 flex items-center gap-1">
      {label}{required && <span className="text-rose-500">*</span>}
    </label>
    {children}
    {hint && <p className="text-[11px] text-slate-400 leading-relaxed">{hint}</p>}
  </div>
);

// Modal generik (backdrop + kartu). ConfirmationDialog (z-55) selalu tampil di atasnya.
export const Modal: React.FC<{
  open: boolean; onClose: () => void; title: string; icon?: React.ReactNode;
  children: React.ReactNode; footer?: React.ReactNode; maxW?: string;
}> = ({ open, onClose, title, icon, children, footer, maxW = 'max-w-lg' }) => {
  useEffect(() => {
    if (open) document.body.style.overflow = 'hidden';
    return () => { document.body.style.overflow = ''; };
  }, [open]);
  if (!open) return null;
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
      <div className="fixed inset-0 bg-slate-900/60 dark:bg-slate-950/80 backdrop-blur-xs" onClick={onClose} />
      <div className={`relative z-10 w-full ${maxW} bg-white dark:bg-slate-900 rounded-2xl border border-slate-100 dark:border-slate-800 shadow-2xl max-h-[90vh] flex flex-col overflow-hidden`}>
        <div className="flex items-center justify-between px-5 py-4 border-b border-slate-100 dark:border-slate-800 shrink-0">
          <h3 className="flex items-center gap-2 text-sm font-bold text-slate-900 dark:text-white">{icon}{title}</h3>
          <button onClick={onClose} className="p-1.5 rounded-full text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800 transition cursor-pointer" aria-label="Tutup">
            <X className="w-4 h-4" />
          </button>
        </div>
        <div className="px-5 py-4 overflow-y-auto flex-1 space-y-4">{children}</div>
        {footer && (
          <div className="px-5 py-3.5 border-t border-slate-100 dark:border-slate-800 flex justify-end gap-2.5 shrink-0 bg-slate-50/60 dark:bg-slate-900/60">
            {footer}
          </div>
        )}
      </div>
    </div>
  );
};

export const EmptyState: React.FC<{ icon: React.ReactNode; title: string; subtitle?: string }> = ({ icon, title, subtitle }) => (
  <div className="flex flex-col items-center justify-center py-16 text-center">
    <div className="w-14 h-14 rounded-2xl bg-slate-100 dark:bg-slate-800 flex items-center justify-center text-slate-400 mb-3">{icon}</div>
    <p className="text-sm font-semibold text-slate-600 dark:text-slate-300">{title}</p>
    {subtitle && <p className="text-xs text-slate-400 mt-1 max-w-sm">{subtitle}</p>}
  </div>
);

export const Spinner: React.FC<{ label?: string }> = ({ label = 'Memuat data...' }) => (
  <div className="flex items-center justify-center py-16">
    <div className="flex flex-col items-center gap-3">
      <div className="w-7 h-7 border-2 border-indigo-200 border-t-indigo-600 rounded-full animate-spin" />
      <span className="text-xs font-medium text-slate-400">{label}</span>
    </div>
  </div>
);

const RunStatusBadge: React.FC<{ status: PayrollStatus }> = ({ status }) => {
  const s = RUN_STATUS[status] ?? RUN_STATUS.draft;
  return (
    <span className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-bold ${s.cls}`}>
      <span className={`w-1.5 h-1.5 rounded-full ${s.dot}`} />{s.label}
    </span>
  );
};

// ═══════════════════════════════════════════════════════════════
// TAB 1 — Proses Payroll (daftar run → detail run → aksi status)
// ═══════════════════════════════════════════════════════════════
const ProsesPayrollTab: React.FC<{ canManage: boolean }> = ({ canManage }) => {
  const { banner, show, clear } = useBanner();
  const [runs, setRuns] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [offices, setOffices] = useState<any[]>([]);
  const [groups, setGroups] = useState<PayrollGroup[]>([]);
  const [selectedRunId, setSelectedRunId] = useState<number | null>(null);
  const [showCreate, setShowCreate] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const res = await payrollApi.listRuns();
      setRuns(res?.data ?? []);
    } catch (e: any) {
      show('error', errMsg(e, 'Gagal memuat daftar batch payroll.'));
    } finally {
      setLoading(false);
    }
  }, [show]);

  useEffect(() => { load(); }, [load]);
  useEffect(() => {
    attendanceApi.settings.list().then((res: any) => setOffices(res?.settings ?? res?.data ?? [])).catch(() => {});
    payrollApi.listGroups().then((res: any) => setGroups(res?.data ?? [])).catch(() => {});
  }, []);

  if (selectedRunId != null) {
    return (
      <RunDetailView
        runId={selectedRunId}
        canManage={canManage}
        onBack={() => setSelectedRunId(null)}
        onChanged={load}
      />
    );
  }

  return (
    <div className="space-y-4">
      <Banner banner={banner} onClose={clear} />

      <div className="flex items-center justify-between gap-3 flex-wrap">
        <p className="text-xs text-slate-500 dark:text-slate-400">
          Kelola batch penggajian per periode: buat → kalkulasi → ajukan → setujui → tandai dibayar.
        </p>
        <div className="flex items-center gap-2">
          <button onClick={load} className={btnGhost} title="Muat ulang"><RefreshCw className="w-3.5 h-3.5" /> Muat ulang</button>
          {canManage && (
            <button onClick={() => setShowCreate(true)} className={btnPrimary}><Plus className="w-3.5 h-3.5" /> Buat Periode</button>
          )}
        </div>
      </div>

      {loading ? <Spinner /> : runs.length === 0 ? (
        <EmptyState
          icon={<Calculator className="w-6 h-6" />}
          title="Belum ada batch payroll"
          subtitle={canManage ? 'Klik "Buat Periode" untuk memulai penggajian periode pertama Anda.' : 'Belum ada periode penggajian yang dibuat.'}
        />
      ) : (
        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
          {runs.map((run) => (
            <button
              key={run.id}
              onClick={() => setSelectedRunId(run.id)}
              className="text-left rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-4 hover:border-indigo-300 dark:hover:border-indigo-700 hover:shadow-md transition group"
            >
              <div className="flex items-start justify-between gap-2">
                <div>
                  <p className="text-sm font-bold text-slate-900 dark:text-white">{run.period_label ?? `${monthName(run.period_month)} ${run.period_year}`}</p>
                  <p className="text-[11px] text-slate-400 flex items-center gap-1 mt-0.5">
                    <Building2 className="w-3 h-3 shrink-0" />
                    <span>
                      {run.branch?.office_name ?? 'Semua Cabang'}
                      {(run.payroll_group?.name || run.payrollGroup?.name) ? ` · ${run.payroll_group?.name ?? run.payrollGroup?.name}` : ''}
                    </span>
                  </p>
                </div>
                <div className="flex items-center gap-1.5 flex-wrap justify-end">
                  <RunStatusBadge status={run.status} />
                  {run.run_type === 'thr' && (
                    <span className="text-[10px] font-bold px-1.5 py-0.5 rounded-full bg-amber-50 text-amber-700 dark:bg-amber-950/50 dark:text-amber-300">
                      THR
                    </span>
                  )}
                  {run.run_type === 'severance' && (
                    <span className="text-[10px] font-bold px-1.5 py-0.5 rounded-full bg-purple-50 text-purple-700 dark:bg-purple-950/50 dark:text-purple-300">
                      Pesangon
                    </span>
                  )}
                </div>
              </div>
              <div className="mt-3 pt-3 border-t border-slate-100 dark:border-slate-800 flex items-end justify-between">
                <div>
                  <p className="text-[10px] uppercase tracking-wide text-slate-400 font-semibold">Neto</p>
                  <p className="text-sm font-bold text-slate-900 dark:text-white">{formatCurrency(run.total_net)}</p>
                </div>
                <div className="text-right">
                  <p className="text-[10px] text-slate-400 font-medium flex items-center gap-1 justify-end"><Users className="w-3 h-3" />{run.employee_count ?? 0} karyawan</p>
                  <span className="text-[11px] text-indigo-600 dark:text-indigo-400 font-semibold flex items-center gap-0.5 justify-end mt-1 group-hover:gap-1.5 transition-all">Detail <ChevronRight className="w-3 h-3" /></span>
                </div>
              </div>
            </button>
          ))}
        </div>
      )}

      {showCreate && (
        <CreateRunModal
          offices={offices}
          groups={groups}
          onClose={() => setShowCreate(false)}
          onCreated={(id) => { setShowCreate(false); load(); setSelectedRunId(id); }}
          onError={(m) => show('error', m)}
        />
      )}
    </div>
  );
};

// ── Modal buat periode ──
const CreateRunModal: React.FC<{
  offices: any[];
  groups: PayrollGroup[];
  onClose: () => void;
  onCreated: (id: number) => void;
  onError: (m: string) => void;
}> = ({ offices, groups, onClose, onCreated, onError }) => {
  const now = new Date();
  const [month, setMonth] = useState<number>(now.getMonth() + 1);
  const [year, setYear] = useState<number>(now.getFullYear());
  const [runType, setRunType] = useState<'regular' | 'thr' | 'severance'>('regular');
  const [branchId, setBranchId] = useState<string>(''); // '' = semua cabang
  const [payrollGroupId, setPayrollGroupId] = useState<string>(''); // '' = semua grup
  const [isYearEnd, setIsYearEnd] = useState<boolean>(now.getMonth() + 1 === 12);
  const [notes, setNotes] = useState('');
  const [saving, setSaving] = useState(false);

  // Sinkronkan default akhir-tahun saat bulan atau jenis batch berubah (THR & Pesangon melarang akhir tahun).
  useEffect(() => {
    if (runType === 'thr' || runType === 'severance') {
      setIsYearEnd(false);
    } else {
      setIsYearEnd(month === 12);
    }
  }, [month, runType]);

  const submit = async () => {
    setSaving(true);
    try {
      const res = await payrollApi.createRun({
        period_month: month,
        period_year: year,
        attendance_setting_id: branchId ? Number(branchId) : null,
        run_type: runType,
        payroll_group_id: payrollGroupId ? Number(payrollGroupId) : null,
        is_year_end: (runType === 'thr' || runType === 'severance') ? false : isYearEnd,
        notes: notes.trim() || undefined,
      });
      onCreated(res?.data?.id);
    } catch (e: any) {
      onError(errMsg(e, 'Gagal membuat batch payroll.'));
      setSaving(false);
    }
  };

  return (
    <Modal
      open onClose={onClose} title="Buat Periode Payroll" icon={<Plus className="w-4 h-4 text-indigo-500" />}
      footer={<>
        <button onClick={onClose} className={btnGhost} disabled={saving}>Batal</button>
        <button onClick={submit} className={btnPrimary} disabled={saving}>
          {saving ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <Check className="w-3.5 h-3.5" />} Buat Batch
        </button>
      </>}
    >
      {/* Pilihan Jenis Batch: Reguler vs THR vs Pesangon */}
      <Field label="Jenis Batch" required>
        <div className="grid grid-cols-3 gap-2 p-1 rounded-xl bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700">
          <button
            type="button"
            onClick={() => setRunType('regular')}
            className={`py-2 px-2 rounded-lg text-xs font-semibold transition cursor-pointer text-center ${
              runType === 'regular'
                ? 'bg-white dark:bg-slate-900 text-indigo-600 dark:text-indigo-400 shadow-sm'
                : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'
            }`}
          >
            Reguler
          </button>
          <button
            type="button"
            onClick={() => setRunType('thr')}
            className={`py-2 px-2 rounded-lg text-xs font-semibold transition cursor-pointer text-center ${
              runType === 'thr'
                ? 'bg-amber-500 text-white shadow-sm'
                : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'
            }`}
          >
            THR
          </button>
          <button
            type="button"
            onClick={() => setRunType('severance')}
            className={`py-2 px-2 rounded-lg text-xs font-semibold transition cursor-pointer text-center ${
              runType === 'severance'
                ? 'bg-purple-600 text-white shadow-sm'
                : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'
            }`}
          >
            Pesangon
          </button>
        </div>
      </Field>

      {runType === 'thr' && (
        <div className="flex items-start gap-2.5 rounded-xl bg-amber-50 dark:bg-amber-950/40 border border-amber-200 dark:border-amber-800 p-3 text-xs text-amber-800 dark:text-amber-200">
          <Info className="w-4 h-4 shrink-0 mt-0.5 text-amber-600 dark:text-amber-400" />
          <div className="space-y-0.5 text-[11px]">
            <p className="font-semibold">Aturan Batch THR (Permenaker No. 6/2016):</p>
            <p>• Dihitung terpisah tanpa gaji pokok, tunjangan, lembur, kasbon, dan BPJS.</p>
            <p>• Masa kerja ≥12 bulan = 1 bulan upah; 1–11 bulan = pro-rata; &lt;1 bulan = tidak berhak (slip nominal 0).</p>
            <p>• PPh 21 dihitung TER marginal; opsi akhir tahun (Pasal 17) otomatis dinonaktifkan.</p>
          </div>
        </div>
      )}

      {runType === 'severance' && (
        <div className="flex items-start gap-2.5 rounded-xl bg-purple-50 dark:bg-purple-950/40 border border-purple-200 dark:border-purple-800 p-3 text-xs text-purple-900 dark:text-purple-200">
          <Briefcase className="w-4 h-4 shrink-0 mt-0.5 text-purple-600 dark:text-purple-400" />
          <div className="space-y-0.5 text-[11px]">
            <p className="font-semibold">Aturan Batch Pesangon / Exit Settlement (PP 35/2021 & PP 68/2009):</p>
            <p>• Dihitung khusus pembayaran pesangon (UP), penghargaan masa kerja (UPMK), penggantian hak (UPH), uang pisah, dan kompensasi PKWT.</p>
            <p>• Dihitung tanpa gaji pokok bulanan, lembur, dan iuran BPJS.</p>
            <p>• UP, UPMK, dan UPH dikenakan PPh 21 Final dan tidak direkonsiliasi pada 1721-A1.</p>
            <p>• <strong>Petunjuk:</strong> Setelah batch ini dibuat, silakan buka menu <strong>Exit Settlement</strong> untuk melampirkan berkas karyawan sebelum menjalankan kalkulasi.</p>
          </div>
        </div>
      )}

      <div className="grid grid-cols-2 gap-4">
        <Field label="Bulan" required>
          <select value={month} onChange={(e) => setMonth(Number(e.target.value))} className={inputCls}>
            {MONTHS_ID.map((m, i) => <option key={i} value={i + 1}>{m}</option>)}
          </select>
        </Field>
        <Field label="Tahun" required>
          <input type="number" min={2020} max={2100} value={year} onChange={(e) => setYear(Number(e.target.value))} className={inputCls} />
        </Field>
      </div>

      <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <Field label="Cabang" hint="Kosongkan untuk seluruh cabang.">
          <select value={branchId} onChange={(e) => setBranchId(e.target.value)} className={inputCls}>
            <option value="">Semua Cabang</option>
            {offices.map((o) => <option key={o.id} value={o.id}>{o.office_name}</option>)}
          </select>
        </Field>
        <Field label="Grup Payroll" hint="Kosongkan untuk seluruh karyawan.">
          <select value={payrollGroupId} onChange={(e) => setPayrollGroupId(e.target.value)} className={inputCls}>
            <option value="">Semua Grup Karyawan</option>
            {groups.filter((g) => g.is_active).map((g) => (
              <option key={g.id} value={g.id}>
                {g.name} {g.code ? `(${g.code})` : ''} {g.users_count !== undefined ? `· ${g.users_count} orang` : ''}
              </option>
            ))}
          </select>
        </Field>
      </div>

      <label className={`flex items-start gap-2.5 rounded-xl border border-slate-200 dark:border-slate-700 p-3 transition ${
        runType === 'thr'
          ? 'opacity-50 cursor-not-allowed bg-slate-50 dark:bg-slate-800/30'
          : 'cursor-pointer hover:bg-slate-50 dark:hover:bg-slate-800/50'
      }`}>
        <input
          type="checkbox"
          checked={isYearEnd}
          disabled={runType === 'thr'}
          onChange={(e) => setIsYearEnd(e.target.checked)}
          className="mt-0.5 accent-indigo-600 w-4 h-4 disabled:cursor-not-allowed"
        />
        <span className="text-xs">
          <span className="font-semibold text-slate-700 dark:text-slate-200">Periode akhir tahun (Desember)</span>
          <span className="block text-[11px] text-slate-400 mt-0.5">
            {runType === 'thr'
              ? 'Khusus batch THR, rekonsiliasi Pasal 17 dinormalkan pada batch reguler Desember.'
              : 'PPh21 dihitung final dengan tarif Pasal 17 progresif, bukan TER bulanan.'}
          </span>
        </span>
      </label>

      <Field label="Catatan (opsional)">
        <textarea value={notes} onChange={(e) => setNotes(e.target.value)} rows={2} maxLength={1000} className={inputCls} placeholder={runType === 'thr' ? 'Contoh: Pembayaran THR Hari Raya Idul Fitri' : 'Contoh: Gaji reguler bulan berjalan'} />
      </Field>
    </Modal>
  );
};

// ── Modal konfirmasi aksi final dengan otorisasi Step-up PIN (Fase 3 Modul D) ──
export const ActionWithPinModal: React.FC<{
  open: boolean;
  onClose: () => void;
  title: string;
  icon?: React.ReactNode;
  message: string;
  confirmText: string;
  hasPin: boolean;
  onConfirm: (pin?: string) => Promise<void>;
}> = ({ open, onClose, title, icon, message, confirmText, hasPin, onConfirm }) => {
  const [pin, setPin] = useState('');
  const [loading, setLoading] = useState(false);
  const [err, setErr] = useState<string | null>(null);

  const submit = async () => {
    if (hasPin && (!pin || pin.trim().length < 4)) {
      setErr('Masukkan PIN keamanan otorisasi Anda (4–6 digit).');
      return;
    }
    setLoading(true);
    setErr(null);
    try {
      await onConfirm(hasPin ? pin.trim() : undefined);
      onClose();
    } catch (e: any) {
      setErr(errMsg(e, 'Otorisasi gagal. Silakan periksa PIN Anda.'));
    } finally {
      setLoading(false);
    }
  };

  if (!open) return null;
  return (
    <Modal
      open
      onClose={onClose}
      title={title}
      icon={icon}
      maxW="max-w-md"
      footer={
        <>
          <button onClick={onClose} className={btnGhost} disabled={loading}>Batal</button>
          <button onClick={submit} className={btnPrimary} disabled={loading}>
            {loading ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <Check className="w-3.5 h-3.5" />} {confirmText}
          </button>
        </>
      }
    >
      <div className="space-y-4">
        <p className="text-xs text-slate-600 dark:text-slate-300 leading-relaxed">{message}</p>
        {hasPin ? (
          <div className="rounded-xl border border-indigo-100 dark:border-indigo-900/40 bg-indigo-50/50 dark:bg-indigo-950/20 p-4 space-y-2">
            <Field label="PIN Keamanan Otorisasi (4–6 Digit)" required hint="Masukkan PIN otorisasi finansial Anda untuk mengesahkan transaksi ini.">
              <input
                type="password"
                maxLength={6}
                value={pin}
                onChange={(e) => setPin(e.target.value.replace(/\D/g, ''))}
                className={`${inputCls} font-mono text-center tracking-widest text-base bg-white dark:bg-slate-900`}
                placeholder="••••••"
                autoFocus
                inputMode="numeric"
              />
            </Field>
          </div>
        ) : (
          <div className="rounded-xl border border-amber-200 dark:border-amber-900/40 bg-amber-50/60 dark:bg-amber-950/20 p-3 text-[11px] text-amber-700 dark:text-amber-300 flex items-start gap-2">
            <Info className="w-4 h-4 shrink-0 mt-0.5" />
            <span>Anda belum mengatur PIN Keamanan akun. Disarankan untuk mengatur PIN via menu "PIN Keamanan" demi perlindungan ganda.</span>
          </div>
        )}
        {err && (
          <div className="flex items-center gap-1.5 text-[11px] text-rose-600 dark:text-rose-400 bg-rose-50 dark:bg-rose-950/40 border border-rose-200 dark:border-rose-900/40 rounded-xl p-2.5">
            <AlertCircle className="w-3.5 h-3.5 shrink-0" />
            <span>{err}</span>
          </div>
        )}
      </div>
    </Modal>
  );
};

// ── Detail run: ringkasan + aksi status + daftar payslip + jejak audit ──
const RunDetailView: React.FC<{
  runId: number; canManage: boolean; onBack: () => void; onChanged: () => void;
}> = ({ runId, canManage, onBack, onChanged }) => {
  const { user } = useAuth();
  const { banner, show, clear } = useBanner();
  const [run, setRun] = useState<any | null>(null);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [selectedPayslipId, setSelectedPayslipId] = useState<number | null>(null);
  const [showReject, setShowReject] = useState(false);
  const [showCalcOptions, setShowCalcOptions] = useState(false);
  const [viewMode, setViewMode] = useState<'payslips' | 'audit_logs'>('payslips');

  // Jejak audit hash-chain (Modul C)
  const [logsData, setLogsData] = useState<RunLogsResponse | null>(null);
  const [logsLoading, setLogsLoading] = useState(false);

  // Status PIN user (Modul D)
  const [pinStatus, setPinStatus] = useState<PinStatus | null>(null);
  const [pinAction, setPinAction] = useState<null | {
    action: 'approve' | 'mark_paid';
    title: string;
    message: string;
    confirmText: string;
    icon: React.ReactNode;
  }>(null);

  // Opsi kalkulasi (Fase 2: BPJS default ON).
  const [opts, setOpts] = useState({
    pph21: true,
    bpjs: true,
    overtime: true,
    attendance_deduction: true,
    receipt_reimbursement: false,
    loan_installment: true,
  });

  const [confirm, setConfirm] = useState<null | {
    title: string; message: React.ReactNode; type: ConfirmationType; confirmText: string; run: () => Promise<void>;
  }>(null);
  const [confirmLoading, setConfirmLoading] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const res = await payrollApi.getRun(runId);
      setRun(res?.data ?? null);
    } catch (e: any) {
      show('error', errMsg(e, 'Gagal memuat detail batch.'));
    } finally {
      setLoading(false);
    }
  }, [runId, show]);

  const loadLogs = useCallback(async () => {
    setLogsLoading(true);
    try {
      const res = await payrollApi.getRunLogs(runId);
      setLogsData(res?.data ?? null);
    } catch (e: any) {
      show('error', errMsg(e, 'Gagal memuat jejak audit kriptografis.'));
    } finally {
      setLogsLoading(false);
    }
  }, [runId, show]);

  useEffect(() => { load(); }, [load]);

  useEffect(() => {
    payrollApi.getPinStatus().then((res: any) => setPinStatus(res?.data ?? null)).catch(() => {});
  }, []);

  useEffect(() => {
    if (viewMode === 'audit_logs') {
      loadLogs();
    }
  }, [viewMode, loadLogs]);

  const status: PayrollStatus | undefined = run?.status;
  const isPreparer = run && user?.id != null && Number(run.prepared_by) === Number(user.id);
  const locked = status === 'approved' || status === 'paid';

  const runConfirmed = async () => {
    if (!confirm) return;
    setConfirmLoading(true);
    try {
      await confirm.run();
      setConfirm(null);
      await load();
      onChanged();
    } catch (e: any) {
      setConfirm(null);
      show('error', errMsg(e));
    } finally {
      setConfirmLoading(false);
    }
  };

  const doCalculate = async () => {
    setBusy(true);
    try {
      const res = await payrollApi.calculateRun(runId, opts);
      show('success', res?.message ?? 'Perhitungan selesai.');
      await load();
      onChanged();
    } catch (e: any) {
      const rawMsg = errMsg(e, 'Gagal menghitung payroll.');
      if (rawMsg.toLowerCase().includes('kurs')) {
        show(
          'error',
          `${rawMsg} Silakan lengkapi master kurs valas pada menu Pengaturan Payroll > Master Kurs Valas sebelum menghitung ulang.`
        );
      } else {
        show('error', rawMsg);
      }
    } finally {
      setBusy(false);
    }
  };

  const handleApproveClick = () => {
    if (pinStatus?.has_pin) {
      setPinAction({
        action: 'approve',
        title: 'Setujui Batch Payroll',
        message: 'Menyetujui akan mengunci batch dan slip gaji. Karyawan akan dapat melihat slip masing-masing di aplikasi mobile/web.',
        confirmText: 'Otorisasi & Setujui',
        icon: <ThumbsUp className="w-4 h-4 text-emerald-500" />,
      });
    } else {
      setConfirm({
        title: 'Setujui Payroll',
        type: 'success',
        confirmText: 'Ya, Setujui',
        message: 'Menyetujui akan mengunci batch dan slip gaji. Karyawan akan dapat melihat slip masing-masing.',
        run: async () => { await payrollApi.approveRun(runId); },
      });
    }
  };

  const handleMarkPaidClick = () => {
    if (pinStatus?.has_pin) {
      setPinAction({
        action: 'mark_paid',
        title: 'Tandai Payroll Sudah Dibayar',
        message: 'Menandai batch sudah dibayar akan mengunci final transaksi dan memotong cicilan kasbon aktif satu kali. Pastikan transfer bank telah benar-benar terlaksana.',
        confirmText: 'Otorisasi Pembayaran',
        icon: <Banknote className="w-4 h-4 text-indigo-500" />,
      });
    } else {
      setConfirm({
        title: 'Tandai Dibayar',
        type: 'info',
        confirmText: 'Ya, Sudah Dibayar',
        message: 'Menandai batch sudah dibayar akan mengunci final dan memotong cicilan kasbon aktif satu kali. Pastikan transfer gaji benar-benar telah dilakukan.',
        run: async () => { await payrollApi.markPaid(runId); },
      });
    }
  };

  const executePinAction = async (pin?: string) => {
    if (!pinAction) return;
    if (pinAction.action === 'approve') {
      await payrollApi.approveRun(runId, pin);
      show('success', 'Batch payroll berhasil disetujui.');
    } else {
      await payrollApi.markPaid(runId, pin);
      show('success', 'Payroll ditandai sudah dibayar.');
    }
    setPinAction(null);
    await load();
    onChanged();
  };

  const summary = useMemo(() => ([
    { label: 'Bruto', value: run?.total_gross, icon: <Wallet className="w-4 h-4" />, cls: 'text-slate-900 dark:text-white' },
    { label: 'Potongan', value: run?.total_deduction, icon: <Coins className="w-4 h-4" />, cls: 'text-rose-600 dark:text-rose-400' },
    { label: 'PPh21', value: run?.total_tax, icon: <FileText className="w-4 h-4" />, cls: 'text-amber-600 dark:text-amber-400' },
    { label: 'Neto (Take Home)', value: run?.total_net, icon: <Banknote className="w-4 h-4" />, cls: 'text-emerald-600 dark:text-emerald-400' },
    ...(run?.total_bpjs_company != null ? [{ label: 'BPJS Perusahaan (Beban PT)', value: run.total_bpjs_company, icon: <Building2 className="w-4 h-4" />, cls: 'text-sky-600 dark:text-sky-400' }] : []),
    ...(run?.total_bpjs_employee != null ? [{ label: 'BPJS Karyawan (Potongan)', value: run.total_bpjs_employee, icon: <Coins className="w-4 h-4" />, cls: 'text-amber-600 dark:text-amber-400' }] : []),
  ]), [run]);

  return (
    <div className="space-y-4">
      <button onClick={onBack} className="inline-flex items-center gap-1.5 text-xs font-semibold text-slate-500 hover:text-indigo-600 dark:hover:text-indigo-400 transition cursor-pointer">
        <ArrowLeft className="w-3.5 h-3.5" /> Kembali ke daftar
      </button>

      <Banner banner={banner} onClose={clear} />

      {loading || !run ? <Spinner label="Memuat detail batch..." /> : (
        <>
          {/* Header batch */}
          <div className="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-5">
            <div className="flex items-start justify-between gap-3 flex-wrap">
              <div>
                <div className="flex items-center gap-2.5 flex-wrap">
                  <h3 className="text-base font-bold text-slate-900 dark:text-white">{run.period_label ?? `${monthName(run.period_month)} ${run.period_year}`}</h3>
                  <RunStatusBadge status={run.status} />
                  {run.is_year_end && <span className="text-[10px] font-bold px-2 py-0.5 rounded-full bg-violet-50 text-violet-700 dark:bg-violet-950/50 dark:text-violet-300">Akhir Tahun · Pasal 17</span>}
                  {run.run_type === 'thr' && <span className="text-[10px] font-bold px-2 py-0.5 rounded-full bg-amber-50 text-amber-700 dark:bg-amber-950/50 dark:text-amber-300">Tunjangan Hari Raya (THR)</span>}
                  {run.run_type === 'severance' && <span className="text-[10px] font-bold px-2 py-0.5 rounded-full bg-purple-50 text-purple-700 dark:bg-purple-950/50 dark:text-purple-300">Pesangon (Exit Settlement)</span>}
                </div>
                <p className="text-[11px] text-slate-400 flex items-center gap-1 mt-1">
                  <Building2 className="w-3 h-3" />{run.branch?.office_name ?? 'Semua Cabang'}{(run.payroll_group?.name || run.payrollGroup?.name) ? ` · Grup: ${run.payroll_group?.name ?? run.payrollGroup?.name}` : ''} · {run.employee_count ?? 0} karyawan
                </p>
              </div>
            </div>

            {run.run_type === 'severance' && (
              <div className="mt-3 flex items-start gap-2.5 rounded-xl bg-purple-50/70 dark:bg-purple-950/30 border border-purple-200 dark:border-purple-800/60 p-3 text-xs text-purple-900 dark:text-purple-200">
                <Briefcase className="w-4 h-4 text-purple-600 dark:text-purple-400 shrink-0 mt-0.5" />
                <div className="space-y-0.5 text-[11px] leading-relaxed">
                  <p className="font-semibold">Batch Pesangon (Exit Settlement):</p>
                  <p>Batch ini memproses pembayaran pesangon karyawan. Pastikan berkas pesangon sudah dibuat dan ditautkan ke batch ini melalui menu <strong>Exit Settlement</strong> sebelum menjalankan kalkulasi.</p>
                </div>
              </div>
            )}

            {/* Jejak maker-checker */}
            <div className="mt-4 grid grid-cols-2 sm:grid-cols-4 gap-3 text-[11px]">
              <TrailItem label="Disiapkan" name={run.prepared_by_name ?? run.preparedBy?.name} />
              <TrailItem label="Diajukan" name={run.submitted_by_name ?? run.submittedBy?.name} date={run.submitted_at} />
              <TrailItem label="Disetujui" name={run.approved_by_name ?? run.approvedBy?.name} date={run.approved_at} />
              <TrailItem label="Dibayar" name={run.paid_by_name ?? run.paidBy?.name} date={run.paid_at} />
            </div>

            {status === 'rejected' && run.reject_reason && (
              <div className="mt-3 flex items-start gap-2 rounded-xl bg-rose-50 dark:bg-rose-950/40 border border-rose-200 dark:border-rose-900/40 px-3 py-2.5 text-xs text-rose-700 dark:text-rose-300">
                <XCircle className="w-4 h-4 shrink-0 mt-0.5" />
                <span><span className="font-bold">Ditolak:</span> {run.reject_reason}</span>
              </div>
            )}
          </div>

          {/* Ringkasan total */}
          <div className="grid grid-cols-2 lg:grid-cols-4 gap-3">
            {summary.map((s) => (
              <div key={s.label} className="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-4">
                <p className="text-[10px] uppercase tracking-wide text-slate-400 font-semibold flex items-center gap-1.5">{s.icon}{s.label}</p>
                <p className={`text-base font-bold mt-1.5 ${s.cls}`}>{formatCurrency(s.value)}</p>
              </div>
            ))}
          </div>

          {/* Panel aksi status */}
          {canManage && !locked && (
            <div className="rounded-2xl border border-slate-200 dark:border-slate-800 bg-slate-50/60 dark:bg-slate-900/60 p-4 space-y-3">
              <p className="text-xs font-bold text-slate-600 dark:text-slate-300 flex items-center gap-1.5"><ShieldCheck className="w-3.5 h-3.5 text-indigo-500" /> Tindakan</p>

              {/* Opsi kalkulasi */}
              {(status === 'draft' || status === 'calculated' || status === 'rejected') && (
                <div className="rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 p-3">
                  <button onClick={() => setShowCalcOptions((v) => !v)} className="w-full flex items-center justify-between text-xs font-semibold text-slate-600 dark:text-slate-300 cursor-pointer">
                    <span>Opsi kalkulasi</span>
                    <ChevronRight className={`w-4 h-4 transition-transform ${showCalcOptions ? 'rotate-90' : ''}`} />
                  </button>
                  {showCalcOptions && (
                    <div className="mt-3 grid sm:grid-cols-2 gap-2">
                      {([
                        ['pph21', 'Potong PPh21 (TER/Pasal 17)'],
                        ['bpjs', 'Hitung Iuran BPJS Kesehatan & Ketenagakerjaan (Karyawan & Perusahaan)'],
                        ['overtime', 'Tambah tunjangan lembur (approved)'],
                        ['attendance_deduction', 'Potong absen dari presensi'],
                        ['loan_installment', 'Potong cicilan kasbon aktif'],
                        ['receipt_reimbursement', 'Reimburse struk approved (non-pajak)'],
                      ] as const).map(([key, label]) => (
                        <label key={key} className="flex items-start gap-2 text-[11px] cursor-pointer">
                          <input type="checkbox" checked={(opts as any)[key]} onChange={(e) => setOpts((o) => ({ ...o, [key]: e.target.checked }))} className="mt-0.5 accent-indigo-600 w-3.5 h-3.5" />
                          <span className="text-slate-600 dark:text-slate-300">{label}
                            {key === 'receipt_reimbursement' && <span className="block text-[10px] text-amber-600 dark:text-amber-400">Aktifkan hanya bila reimburse struk belum dibayar terpisah.</span>}
                          </span>
                        </label>
                      ))}
                    </div>
                  )}
                </div>
              )}

              <div className="flex flex-wrap gap-2">
                {(status === 'draft' || status === 'calculated' || status === 'rejected') && (
                  <button onClick={doCalculate} className={btnPrimary} disabled={busy}>
                    {busy ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <Calculator className="w-3.5 h-3.5" />} {status === 'draft' || status === 'rejected' ? 'Kalkulasi' : 'Hitung Ulang'}
                  </button>
                )}

                {status === 'calculated' && (
                  <button
                    onClick={() => setConfirm({
                      title: 'Ajukan Payroll', type: 'info', confirmText: 'Ya, Ajukan',
                      message: 'Batch akan dikirim untuk persetujuan. Setelah diajukan, kalkulasi tidak bisa diubah kecuali ditolak penyetuju.',
                      run: async () => { await payrollApi.submitRun(runId); },
                    })}
                    className={btnPrimary}
                  ><Send className="w-3.5 h-3.5" /> Ajukan</button>
                )}

                {status === 'submitted' && (
                  <>
                    <button
                      disabled={isPreparer}
                      title={isPreparer ? 'Anda menyiapkan batch ini — persetujuan wajib oleh orang lain (maker-checker).' : undefined}
                      onClick={handleApproveClick}
                      className={`${btnPrimary} bg-emerald-600 hover:bg-emerald-700 shadow-emerald-500/20`}
                    ><ThumbsUp className="w-3.5 h-3.5" /> Setujui</button>
                    <button onClick={() => setShowReject(true)} className={`${btnGhost} text-rose-600 dark:text-rose-400 border-rose-200 dark:border-rose-900/50`}>
                      <XCircle className="w-3.5 h-3.5" /> Tolak
                    </button>
                  </>
                )}

                {(status === 'draft' || status === 'calculated' || status === 'rejected') && (
                  <button
                    onClick={() => setConfirm({
                      title: 'Hapus Batch', type: 'danger', confirmText: 'Ya, Hapus',
                      message: 'Batch beserta seluruh slip di dalamnya akan dihapus permanen. Tindakan ini tidak dapat dibatalkan.',
                      run: async () => { await payrollApi.deleteRun(runId); onBack(); },
                    })}
                    className={`${btnGhost} text-rose-600 dark:text-rose-400 border-rose-200 dark:border-rose-900/50 ml-auto`}
                  ><Trash2 className="w-3.5 h-3.5" /> Hapus</button>
                )}
              </div>

              {status === 'submitted' && isPreparer && (
                <p className="text-[11px] text-amber-600 dark:text-amber-400 flex items-center gap-1"><Info className="w-3 h-3" /> Anda menyiapkan batch ini. Persetujuan harus dilakukan oleh pengguna lain (maker-checker).</p>
              )}
            </div>
          )}

          {status === 'approved' && canManage && (
            <div className="rounded-2xl border border-emerald-200 dark:border-emerald-900/40 bg-emerald-50/60 dark:bg-emerald-950/30 p-4 flex items-center justify-between gap-3 flex-wrap">
              <p className="text-xs text-emerald-700 dark:text-emerald-300 flex items-center gap-1.5"><BadgeCheck className="w-4 h-4" /> Batch sudah disetujui. Tandai sebagai dibayar setelah transfer gaji dilakukan.</p>
              <button
                onClick={handleMarkPaidClick}
                className={btnPrimary}
              ><Banknote className="w-3.5 h-3.5" /> Tandai Dibayar</button>
            </div>
          )}

          {status === 'paid' && (
            <div className="rounded-2xl border border-indigo-200 dark:border-indigo-900/40 bg-indigo-50/60 dark:bg-indigo-950/30 p-4 text-xs text-indigo-700 dark:text-indigo-300 flex items-center gap-1.5">
              <CheckCircle2 className="w-4 h-4" /> Payroll selesai & terkunci. Slip gaji tersedia untuk karyawan.
            </div>
          )}

          {/* Tab Pengalih: Slip Gaji vs Jejak Audit Kriptografis (Modul C) */}
          <div className="flex border-b border-slate-200 dark:border-slate-800 gap-4 pt-2">
            <button
              onClick={() => setViewMode('payslips')}
              className={`pb-2.5 text-xs font-bold transition flex items-center gap-1.5 border-b-2 cursor-pointer ${
                viewMode === 'payslips'
                  ? 'border-indigo-600 text-indigo-600 dark:text-indigo-400'
                  : 'border-transparent text-slate-400 hover:text-slate-600 dark:hover:text-slate-200'
              }`}
            >
              <Users className="w-3.5 h-3.5" /> Slip Gaji ({run.payslips?.length ?? 0})
            </button>
            <button
              onClick={() => setViewMode('audit_logs')}
              className={`pb-2.5 text-xs font-bold transition flex items-center gap-1.5 border-b-2 cursor-pointer ${
                viewMode === 'audit_logs'
                  ? 'border-indigo-600 text-indigo-600 dark:text-indigo-400'
                  : 'border-transparent text-slate-400 hover:text-slate-600 dark:hover:text-slate-200'
              }`}
            >
              <ShieldCheck className="w-3.5 h-3.5" /> Jejak Audit & Integritas Hash-Chain
            </button>
          </div>

          {/* View Mode: DAFTAR SLIP GAJI */}
          {viewMode === 'payslips' && (
            <div className="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 overflow-hidden">
              {(run.payslips?.length ?? 0) === 0 ? (
                <EmptyState icon={<Users className="w-6 h-6" />} title="Belum ada slip" subtitle="Jalankan Kalkulasi untuk membangun slip gaji karyawan pada periode ini." />
              ) : (
                <div className="overflow-x-auto">
                  <table className="w-full text-xs">
                    <thead>
                      <tr className="text-left text-slate-400 border-b border-slate-100 dark:border-slate-800">
                        <th className="px-4 py-2.5 font-semibold">Karyawan</th>
                        <th className="px-4 py-2.5 font-semibold text-right">Bruto</th>
                        <th className="px-4 py-2.5 font-semibold text-right">Potongan</th>
                        <th className="px-4 py-2.5 font-semibold text-right">PPh21</th>
                        <th className="px-4 py-2.5 font-semibold text-right">Neto</th>
                        <th className="px-4 py-2.5 font-semibold text-right">Slip</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-50 dark:divide-slate-800/60">
                      {run.payslips.map((p: any) => (
                        <tr key={p.id} className="hover:bg-slate-50 dark:hover:bg-slate-800/40 transition">
                          <td className="px-4 py-2.5">
                            <p className="font-semibold text-slate-800 dark:text-slate-100">{p.employee_name}</p>
                            {p.employee_code && <p className="text-[10px] text-slate-400">{p.employee_code}</p>}
                          </td>
                          <td className="px-4 py-2.5 text-right text-slate-700 dark:text-slate-300">{formatCurrency(p.gross)}</td>
                          <td className="px-4 py-2.5 text-right text-rose-600 dark:text-rose-400">{formatCurrency(p.total_deduction)}</td>
                          <td className="px-4 py-2.5 text-right text-amber-600 dark:text-amber-400">{formatCurrency(p.pph21)}</td>
                          <td className="px-4 py-2.5 text-right font-bold text-emerald-600 dark:text-emerald-400">{formatCurrency(p.net)}</td>
                          <td className="px-4 py-2.5 text-right">
                            <button onClick={() => setSelectedPayslipId(p.id)} className="inline-flex items-center gap-1 text-indigo-600 dark:text-indigo-400 font-semibold hover:underline cursor-pointer">
                              <Eye className="w-3.5 h-3.5" /> Lihat
                            </button>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </div>
          )}

          {/* View Mode: JEJAK AUDIT & INTEGRITAS HASH-CHAIN (Modul C) */}
          {viewMode === 'audit_logs' && (
            <div className="space-y-3">
              {logsLoading ? (
                <Spinner label="Memverifikasi rantai hash kriptografis..." />
              ) : (
                <>
                  {/* Status Banner Integritas Kriptografis */}
                  {logsData?.integrity && (
                    <div
                      className={`rounded-2xl border p-4 flex items-start gap-3 text-xs ${
                        logsData.integrity.ok
                          ? 'bg-emerald-50 dark:bg-emerald-950/40 border-emerald-200 dark:border-emerald-900/40 text-emerald-800 dark:text-emerald-200'
                          : 'bg-rose-50 dark:bg-rose-950/40 border-rose-200 dark:border-rose-900/40 text-rose-800 dark:text-rose-200'
                      }`}
                    >
                      {logsData.integrity.ok ? (
                        <ShieldCheck className="w-5 h-5 text-emerald-600 dark:text-emerald-400 shrink-0 mt-0.5" />
                      ) : (
                        <ShieldAlert className="w-5 h-5 text-rose-600 dark:text-rose-400 shrink-0 mt-0.5" />
                      )}
                      <div>
                        <p className="font-bold">
                          {logsData.integrity.ok
                            ? 'Integritas Rantai Kriptografis Valid (Tamper-Evident SHA-256)'
                            : 'Peringatan Integritas Terputus (Tamper Detected)'}
                        </p>
                        <p className="mt-0.5 text-[11px] opacity-90 leading-relaxed">
                          {logsData.integrity.ok
                            ? 'Seluruh riwayat siklus perubahan batch payroll ini terverifikasi secara matematis menggunakan hash-chain SHA-256. Tidak ada data yang dimanipulasi di basis data.'
                            : `Terdeteksi kegagalan verifikasi pada urutan #${logsData.integrity.broken_at}. Rantai hash transaksi masa lalu tidak cocok.`}
                        </p>
                      </div>
                    </div>
                  )}

                  {/* Tabel Rantai Log */}
                  <div className="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 overflow-hidden">
                    {(!logsData?.logs || logsData.logs.length === 0) ? (
                      <EmptyState icon={<History className="w-6 h-6" />} title="Belum ada catatan log audit" subtitle="Aktivitas perhitungan dan persetujuan akan tercatat di sini." />
                    ) : (
                      <div className="overflow-x-auto">
                        <table className="w-full text-xs">
                          <thead>
                            <tr className="text-left text-slate-400 border-b border-slate-100 dark:border-slate-800">
                              <th className="px-4 py-2.5 font-semibold"># Seq</th>
                              <th className="px-4 py-2.5 font-semibold">Aksi</th>
                              <th className="px-4 py-2.5 font-semibold">Waktu & IP</th>
                              <th className="px-4 py-2.5 font-semibold">Keterangan / Status</th>
                              <th className="px-4 py-2.5 font-semibold font-mono text-right">Hash Rantai (SHA-256)</th>
                            </tr>
                          </thead>
                          <tbody className="divide-y divide-slate-50 dark:divide-slate-800/60">
                            {logsData.logs.map((lg) => (
                              <tr key={lg.id} className="hover:bg-slate-50 dark:hover:bg-slate-800/40 transition">
                                <td className="px-4 py-2.5 font-mono text-[11px] text-slate-400">#{lg.sequence}</td>
                                <td className="px-4 py-2.5">
                                  <span className="inline-flex items-center gap-1 rounded-md px-2 py-0.5 text-[10px] font-bold bg-indigo-50 dark:bg-indigo-950/50 text-indigo-700 dark:text-indigo-300">
                                    {lg.action}
                                  </span>
                                </td>
                                <td className="px-4 py-2.5 text-slate-600 dark:text-slate-400 whitespace-nowrap">
                                  <p>{formatDate(lg.created_at)}</p>
                                  {lg.ip_address && <p className="text-[10px] font-mono text-slate-400">{lg.ip_address}</p>}
                                </td>
                                <td className="px-4 py-2.5 text-slate-700 dark:text-slate-300 max-w-xs truncate">
                                  {lg.notes || '—'}
                                </td>
                                <td className="px-4 py-2.5 text-right font-mono text-[10px] text-slate-400">
                                  <span title={`Prev: ${lg.prev_hash}\nRecord: ${lg.record_hash}`}>
                                    {lg.record_hash.slice(0, 16)}...
                                  </span>
                                </td>
                              </tr>
                            ))}
                          </tbody>
                        </table>
                      </div>
                    )}
                  </div>
                </>
              )}
            </div>
          )}
        </>
      )}

      {selectedPayslipId != null && (
        <PayslipDetailModal payslipId={selectedPayslipId} onClose={() => setSelectedPayslipId(null)} onError={(m) => show('error', m)} />
      )}

      {showReject && (
        <RejectModal
          onClose={() => setShowReject(false)}
          onSubmit={async (reason) => {
            await payrollApi.rejectRun(runId, reason);
            setShowReject(false);
            show('success', 'Payroll ditolak & dikembalikan untuk perbaikan.');
            await load();
            onChanged();
          }}
        />
      )}

      {/* Modal Otorisasi dengan Step-up PIN (Fase 3 Modul D) */}
      {pinAction && (
        <ActionWithPinModal
          open
          onClose={() => setPinAction(null)}
          title={pinAction.title}
          message={pinAction.message}
          confirmText={pinAction.confirmText}
          icon={pinAction.icon}
          hasPin={Boolean(pinStatus?.has_pin)}
          onConfirm={executePinAction}
        />
      )}

      {confirm && (
        <ConfirmationDialog
          isOpen onClose={() => setConfirm(null)} onConfirm={runConfirmed}
          title={confirm.title} message={confirm.message} type={confirm.type}
          confirmText={confirm.confirmText} isLoading={confirmLoading}
        />
      )}
    </div>
  );
};

const TrailItem: React.FC<{ label: string; name?: string | null; date?: string | null }> = ({ label, name, date }) => (
  <div className="rounded-xl bg-slate-50 dark:bg-slate-800/50 px-3 py-2">
    <p className="text-[10px] uppercase tracking-wide text-slate-400 font-semibold">{label}</p>
    <p className="text-slate-700 dark:text-slate-200 font-semibold truncate">{name || '—'}</p>
    {date && <p className="text-[10px] text-slate-400">{formatDate(date)}</p>}
  </div>
);

// ── Modal tolak (butuh alasan) ──
const RejectModal: React.FC<{ onClose: () => void; onSubmit: (reason: string) => Promise<void> }> = ({ onClose, onSubmit }) => {
  const [reason, setReason] = useState('');
  const [saving, setSaving] = useState(false);
  const [err, setErr] = useState<string | null>(null);

  const submit = async () => {
    if (reason.trim().length < 3) { setErr('Alasan penolakan wajib diisi (min. 3 karakter).'); return; }
    setSaving(true); setErr(null);
    try { await onSubmit(reason.trim()); }
    catch (e: any) { setErr(errMsg(e, 'Gagal menolak payroll.')); setSaving(false); }
  };

  return (
    <Modal
      open onClose={onClose} title="Tolak Payroll" icon={<XCircle className="w-4 h-4 text-rose-500" />} maxW="max-w-md"
      footer={<>
        <button onClick={onClose} className={btnGhost} disabled={saving}>Batal</button>
        <button onClick={submit} className={`${btnPrimary} bg-rose-600 hover:bg-rose-700 shadow-rose-500/20`} disabled={saving}>
          {saving ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <XCircle className="w-3.5 h-3.5" />} Tolak Batch
        </button>
      </>}
    >
      <Field label="Alasan penolakan" required hint="Alasan akan tercatat pada jejak audit & ditampilkan ke penyiap.">
        <textarea value={reason} onChange={(e) => setReason(e.target.value)} rows={4} maxLength={1000} className={inputCls} placeholder="Contoh: Data lembur Budi belum sesuai, mohon dihitung ulang." autoFocus />
      </Field>
      {err && <p className="text-[11px] text-rose-600 dark:text-rose-400 flex items-center gap-1"><AlertCircle className="w-3 h-3" /> {err}</p>}
    </Modal>
  );
};

// ── Modal detail slip + PDF ──
const PayslipDetailModal: React.FC<{ payslipId: number; onClose: () => void; onError: (m: string) => void }> = ({ payslipId, onClose, onError }) => {
  const [detail, setDetail] = useState<any | null>(null);
  const [loading, setLoading] = useState(true);
  const [pdfBusy, setPdfBusy] = useState(false);
  const [showSteps, setShowSteps] = useState(false);
  const [traceSteps, setTraceSteps] = useState<CalculationTraceStep[] | null>(null);
  const [loadingTrace, setLoadingTrace] = useState(false);
  const [expandedPayloadSeq, setExpandedPayloadSeq] = useState<number | null>(null);

  useEffect(() => {
    let active = true;
    payrollApi.getPayslip(payslipId)
      .then((res: any) => { if (active) setDetail(res?.data ?? null); })
      .catch((e: any) => onError(errMsg(e, 'Gagal memuat slip gaji.')))
      .finally(() => { if (active) setLoading(false); });
    return () => { active = false; };
  }, [payslipId, onError]);

  const p = detail?.payslip;

  const handleToggleSteps = () => {
    const next = !showSteps;
    setShowSteps(next);
    if (next && !traceSteps && !loadingTrace) {
      setLoadingTrace(true);
      payrollApi
        .getPayslipTrace(payslipId)
        .then((res: any) => {
          const steps = res?.data?.steps ?? res?.steps ?? null;
          if (Array.isArray(steps) && steps.length > 0) {
            setTraceSteps(steps);
          }
        })
        .catch(() => {
          // Graceful fallback ke calculation_steps bawaan
        })
        .finally(() => setLoadingTrace(false));
    }
  };

  const handleView = async () => {
    setPdfBusy(true);
    try { await payrollApi.viewPayslipPdf(payslipId, p?.employee_name ? `Slip Gaji ${p.employee_name}` : 'Slip Gaji'); }
    catch (e: any) { onError(errMsg(e, 'Gagal membuka PDF.')); }
    finally { setPdfBusy(false); }
  };
  const handleDownload = async () => {
    try {
      const fname = `slip-gaji-${p?.employee_code || payslipId}-${p?.period_year ?? ''}${String(p?.period_month ?? '').padStart(2, '0')}.pdf`;
      await payrollApi.downloadPayslipPdf(payslipId, fname);
    } catch (e: any) { onError(errMsg(e, 'Gagal mengunduh PDF.')); }
  };

  return (
    <Modal
      open onClose={onClose} title="Detail Slip Gaji" icon={<FileText className="w-4 h-4 text-indigo-500" />} maxW="max-w-2xl"
      footer={<>
        <button onClick={handleView} className={btnGhost} disabled={loading || pdfBusy}>
          {pdfBusy ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <Eye className="w-3.5 h-3.5" />} Lihat PDF
        </button>
        <button onClick={handleDownload} className={btnPrimary} disabled={loading}><Download className="w-3.5 h-3.5" /> Unduh PDF</button>
      </>}
    >
      {loading || !p ? <Spinner label="Memuat slip..." /> : (
        <div className="space-y-4">
          {/* Identitas */}
          <div className="rounded-xl bg-slate-50 dark:bg-slate-800/50 p-4">
            <div className="flex items-start justify-between gap-2">
              <div>
                <p className="text-sm font-bold text-slate-900 dark:text-white">{p.employee_name}</p>
                <p className="text-[11px] text-slate-400">{[p.employee_code, p.position_name, p.department_name].filter(Boolean).join(' · ') || '—'}</p>
              </div>
              <div className="text-right">
                <p className="text-[10px] uppercase tracking-wide text-slate-400 font-semibold">Periode</p>
                <p className="text-xs font-bold text-slate-700 dark:text-slate-200">{detail.period ?? `${monthName(p.period_month)} ${p.period_year}`}</p>
              </div>
            </div>
            <div className="mt-3 grid grid-cols-2 sm:grid-cols-4 gap-2 text-[11px]">
              <MiniStat label="PTKP" value={p.ptkp_status || '—'} />
              <MiniStat label="NPWP" value={p.npwp_masked || 'Tidak ada'} />
              <MiniStat label="Bank" value={p.bank_name || '—'} />
              <MiniStat label="No. Rekening" value={p.bank_account_no || '—'} />
            </div>
            {p.run_type !== 'severance' && (p.working_days != null || p.overtime_hours != null) && (
              <div className="mt-2 grid grid-cols-2 sm:grid-cols-4 gap-2 text-[11px]">
                <MiniStat label="Hari Kerja" value={String(p.working_days ?? '—')} />
                <MiniStat label="Hadir" value={String(p.present_days ?? '—')} />
                <MiniStat label="Absen" value={String(p.absent_days ?? '—')} />
                <MiniStat label="Jam Lembur" value={p.overtime_hours != null ? `${p.overtime_hours} jam` : '—'} />
              </div>
            )}
          </div>

          {/* Valuta Asing Rate Lock Banner (Fase 6) */}
          {p.currency && p.currency !== 'IDR' && (
            <div className="rounded-xl border border-indigo-200 dark:border-indigo-900/60 bg-indigo-50/50 dark:bg-indigo-950/30 p-3.5 text-xs text-indigo-950 dark:text-indigo-200 flex items-start gap-3">
              <Coins className="w-5 h-5 text-indigo-600 dark:text-indigo-400 shrink-0 mt-0.5" />
              <div className="space-y-1">
                <div className="flex items-center gap-2 flex-wrap">
                  <span className="font-bold text-sm">
                    {p.gross_currency != null ? `${p.currency} ${Number(p.gross_currency).toLocaleString('id-ID', { minimumFractionDigits: 2 })}` : p.currency}
                  </span>
                  <span className="text-slate-400">@ Kurs Terkunci</span>
                  <span className="font-mono font-bold text-indigo-600 dark:text-indigo-400">
                    {p.exchange_rate}
                  </span>
                  <span className="text-slate-400">=</span>
                  <span className="font-bold text-slate-900 dark:text-white">
                    {formatCurrency(p.basic_salary)}
                  </span>
                </div>
                <p className="text-[11px] text-indigo-700/80 dark:text-indigo-400/80 leading-relaxed">
                  Kurs valuta asing terkunci saat kalkulasi batch dijalankan. Seluruh nominal slip lain sudah dikonversi ke Rupiah (IDR).
                </p>
              </div>
            </div>
          )}

          {/* Earning & deduction */}
          <div className="grid sm:grid-cols-2 gap-4">
            <ItemList title="Pendapatan" items={detail.earnings ?? []} positive />
            <ItemList title="Potongan" items={detail.deductions ?? []} />
          </div>

          {/* Iuran BPJS Ditanggung Perusahaan (Beban PT, Bukan Pengurang Neto) */}
          {p.run_type !== 'severance' && p.bpjs_company_total != null && Number(p.bpjs_company_total) > 0 && (
            <div className="rounded-xl border border-sky-200 dark:border-sky-900/40 bg-sky-50/60 dark:bg-sky-950/30 p-3.5 text-xs text-sky-900 dark:text-sky-200">
              <div className="flex items-center justify-between">
                <span className="font-semibold flex items-center gap-1.5">
                  <Building2 className="w-4 h-4 text-sky-600 dark:text-sky-400" />
                  Iuran BPJS Ditanggung Perusahaan (Beban PT):
                </span>
                <span className="font-bold text-sky-700 dark:text-sky-300">{formatCurrency(p.bpjs_company_total)}</span>
              </div>
              <p className="text-[11px] text-sky-600/80 dark:text-sky-400/80 mt-1">
                Iuran ini dibayarkan langsung oleh perusahaan sesuai regulasi dan tidak memotong penghasilan bersih (Take Home Pay) Anda.
              </p>
            </div>
          )}

          {/* Total Ringkasan */}
          <div className="rounded-xl border border-slate-200 dark:border-slate-800 divide-y divide-slate-100 dark:divide-slate-800 text-xs">
            <Row label="Total Bruto" value={formatCurrency(p.gross)} />
            <Row label="Dasar Pengenaan Pajak" value={formatCurrency(p.taxable_income)} muted />
            <Row label={p.run_type === 'severance' ? 'PPh 21 Final' : 'PPh21'} value={`- ${formatCurrency(p.pph21)}`} cls="text-amber-600 dark:text-amber-400" />
            <Row label="Total Potongan" value={`- ${formatCurrency(p.total_deduction)}`} cls="text-rose-600 dark:text-rose-400" />
            <Row label="Gaji Bersih (Take Home Pay)" value={formatCurrency(p.net)} cls="text-emerald-600 dark:text-emerald-400 font-bold" bold />
          </div>

          {/* Jejak Perhitungan / Calculation Steps & Trace (Fase 2 & Fase 4) */}
          {(detail.calculation_steps || traceSteps) && (
            <div className="rounded-xl border border-slate-200 dark:border-slate-800 overflow-hidden">
              <button
                type="button"
                onClick={handleToggleSteps}
                className="w-full px-3.5 py-2.5 bg-slate-50 dark:bg-slate-800/50 flex items-center justify-between text-xs font-bold text-slate-700 dark:text-slate-300 cursor-pointer hover:bg-slate-100 dark:hover:bg-slate-800 transition"
              >
                <span className="flex items-center gap-1.5">
                  <Sparkles className="w-3.5 h-3.5 text-indigo-500" />
                  Rincian & Jejak Langkah Perhitungan ({(traceSteps ?? detail.calculation_steps ?? []).length} Langkah)
                  {loadingTrace && <Loader2 className="w-3 h-3 animate-spin text-indigo-500 ml-1" />}
                </span>
                <ChevronDown className={`w-4 h-4 text-slate-400 transition-transform ${showSteps ? 'rotate-180' : ''}`} />
              </button>
              {showSteps && (
                <div className="p-3 bg-white dark:bg-slate-900 overflow-x-auto space-y-2">
                  <table className="w-full text-xs">
                    <thead>
                      <tr className="text-left text-slate-400 border-b border-slate-100 dark:border-slate-800">
                        <th className="pb-2 font-semibold">#</th>
                        <th className="pb-2 font-semibold">Komponen & Rumus Acuan</th>
                        <th className="pb-2 font-semibold text-right">Hasil Mentah</th>
                        <th className="pb-2 font-semibold text-right">Hasil Akhir</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-50 dark:divide-slate-800/60">
                      {(traceSteps ?? detail.calculation_steps ?? []).map((st: any) => {
                        const hasDiff =
                          st.rounding_diff != null &&
                          Number(st.rounding_diff) !== 0;
                        const hasRaw =
                          st.raw_result != null &&
                          st.raw_result !== st.final_result;
                        const hasPayload =
                          st.input_payload &&
                          typeof st.input_payload === 'object' &&
                          Object.keys(st.input_payload).length > 0;
                        const isExpandedPayload =
                          expandedPayloadSeq === st.step_sequence;

                        return (
                          <React.Fragment key={st.step_sequence ?? st.step_code}>
                            <tr className="hover:bg-slate-50 dark:hover:bg-slate-800/30">
                              <td className="py-2.5 text-slate-400 font-mono text-[11px] align-top">
                                {st.step_sequence}
                              </td>
                              <td className="py-2.5 align-top">
                                <div className="font-medium text-slate-700 dark:text-slate-200">
                                  {calculationStepLabel(st.step_code)}
                                  <span className="ml-1.5 text-[10px] text-slate-400 font-mono">
                                    ({st.step_code})
                                  </span>
                                </div>
                                <div className="flex items-center gap-1.5 mt-1 flex-wrap">
                                  {st.formula_version && (
                                    <span className="px-1.5 py-0.5 rounded text-[10px] font-semibold bg-indigo-50 text-indigo-700 dark:bg-indigo-950/60 dark:text-indigo-300 border border-indigo-200 dark:border-indigo-800/40">
                                      Rumus: {st.formula_version}
                                    </span>
                                  )}
                                  {st.rule_reference && (
                                    <span className="px-1.5 py-0.5 rounded text-[10px] font-medium bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400">
                                      Ref: {st.rule_reference}
                                    </span>
                                  )}
                                  {hasPayload && (
                                    <button
                                      type="button"
                                      onClick={() =>
                                        setExpandedPayloadSeq(
                                          isExpandedPayload ? null : st.step_sequence
                                        )
                                      }
                                      className="text-[10px] text-indigo-600 hover:text-indigo-700 dark:text-indigo-400 underline font-medium cursor-pointer"
                                    >
                                      {isExpandedPayload ? 'Sembunyikan Variabel' : 'Lihat Variabel Input'}
                                    </button>
                                  )}
                                </div>
                              </td>
                              <td className="py-2.5 text-right font-mono text-slate-600 dark:text-slate-300 align-top">
                                {hasRaw ? (
                                  <div>
                                    <span className="block">{formatCurrency(st.raw_result)}</span>
                                    {hasDiff && (
                                      <span className="text-[10px] text-amber-600 dark:text-amber-400 font-medium block">
                                        Selisih:{' '}
                                        {Number(st.rounding_diff) > 0
                                          ? `+${st.rounding_diff}`
                                          : st.rounding_diff}
                                      </span>
                                    )}
                                  </div>
                                ) : (
                                  <span className="text-slate-400">-</span>
                                )}
                              </td>
                              <td className="py-2.5 text-right font-semibold text-slate-900 dark:text-white font-mono align-top">
                                {formatCurrency(st.final_result)}
                              </td>
                            </tr>
                            {isExpandedPayload && (
                              <tr>
                                <td colSpan={4} className="pb-3 pt-0 px-2">
                                  <div className="rounded-lg bg-slate-50 dark:bg-slate-800/80 p-2.5 text-[11px] font-mono border border-slate-200 dark:border-slate-700 space-y-1">
                                    <span className="text-[10px] font-bold uppercase text-slate-400 block font-sans">
                                      Parameter Input Langkah #{st.step_sequence} ({st.step_code})
                                    </span>
                                    <pre className="text-slate-700 dark:text-slate-200 overflow-x-auto whitespace-pre-wrap">
                                      {JSON.stringify(st.input_payload, null, 2)}
                                    </pre>
                                  </div>
                                </td>
                              </tr>
                            )}
                          </React.Fragment>
                        );
                      })}
                    </tbody>
                  </table>
                </div>
              )}
            </div>
          )}
        </div>
      )}
    </Modal>
  );
};

export const MiniStat: React.FC<{ label: string; value: string }> = ({ label, value }) => (
  <div>
    <p className="text-[10px] uppercase tracking-wide text-slate-400 font-semibold">{label}</p>
    <p className="text-slate-700 dark:text-slate-200 font-semibold truncate">{value}</p>
  </div>
);

export const ItemList: React.FC<{ title: string; items: any[]; positive?: boolean }> = ({ title, items, positive }) => (
  <div className="rounded-xl border border-slate-200 dark:border-slate-800 overflow-hidden">
    <p className="px-3 py-2 text-[11px] font-bold text-slate-500 dark:text-slate-400 bg-slate-50 dark:bg-slate-800/50 border-b border-slate-100 dark:border-slate-800">{title}</p>
    {items.length === 0 ? (
      <p className="px-3 py-4 text-[11px] text-slate-400 text-center">Tidak ada</p>
    ) : (
      <ul className="divide-y divide-slate-50 dark:divide-slate-800/60">
        {items.map((it, i) => {
          const isBpjs = it.source === 'bpjs' || it.is_statutory || it.code?.startsWith('BPJS');
          const isFinal = it.is_final || it.is_taxable === false || it.code === 'PPH21_FINAL' || it.tax_type === 'final' || it.code?.includes('FINAL');
          return (
            <li key={it.id ?? i} className="px-3 py-2 flex items-center justify-between gap-2 text-xs">
              <span className="text-slate-600 dark:text-slate-300 truncate flex items-center gap-1.5">
                {it.label}
                {isBpjs && (
                  <span className="px-1.5 py-0.5 rounded text-[10px] font-bold bg-sky-50 text-sky-700 dark:bg-sky-950/60 dark:text-sky-300 border border-sky-200 dark:border-sky-800/60">
                    BPJS
                  </span>
                )}
                {isFinal && (
                  <span className="px-1.5 py-0.5 rounded text-[10px] font-bold bg-emerald-50 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800/60">
                    Final
                  </span>
                )}
              </span>
              <span className={`font-semibold shrink-0 ${positive ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400'}`}>
                {positive ? '' : '- '}{formatCurrency(it.amount)}
              </span>
            </li>
          );
        })}
      </ul>
    )}
  </div>
);

export const Row: React.FC<{ label: string; value: string; cls?: string; muted?: boolean; bold?: boolean }> = ({ label, value, cls, muted, bold }) => (
  <div className={`flex items-center justify-between px-4 py-2.5 ${bold ? 'bg-slate-50/80 dark:bg-slate-800/40' : ''}`}>
    <span className={`${muted ? 'text-slate-400' : 'text-slate-600 dark:text-slate-300'} ${bold ? 'font-bold text-slate-800 dark:text-white' : ''}`}>{label}</span>
    <span className={`font-semibold ${cls ?? 'text-slate-800 dark:text-slate-100'}`}>{value}</span>
  </div>
);

// ═══════════════════════════════════════════════════════════════
// TAB 2 — Gaji Karyawan
// ═══════════════════════════════════════════════════════════════
const GajiKaryawanTab: React.FC<{ canManage: boolean }> = ({ canManage }) => {
  const { banner, show, clear } = useBanner();
  const [rows, setRows] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState('');
  const [selectedUserId, setSelectedUserId] = useState<number | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const res = await payrollApi.listSalaries();
      setRows(res?.data ?? []);
    } catch (e: any) {
      show('error', errMsg(e, 'Gagal memuat data gaji karyawan.'));
    } finally { setLoading(false); }
  }, [show]);

  useEffect(() => { load(); }, [load]);

  const filtered = useMemo(() => {
    const q = search.trim().toLowerCase();
    if (!q) return rows;
    return rows.filter((r) => `${r.name} ${r.employee_code ?? ''} ${r.department ?? ''}`.toLowerCase().includes(q));
  }, [rows, search]);

  return (
    <div className="space-y-4">
      <Banner banner={banner} onClose={clear} />
      <div className="flex items-center justify-between gap-3 flex-wrap">
        <div className="relative flex-1 max-w-xs">
          <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Cari nama / kode / departemen..." className={`${inputCls} pl-9`} />
          <Users className="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" />
        </div>
        <button onClick={load} className={btnGhost}><RefreshCw className="w-3.5 h-3.5" /> Muat ulang</button>
      </div>

      {loading ? <Spinner /> : filtered.length === 0 ? (
        <EmptyState icon={<Users className="w-6 h-6" />} title="Tidak ada karyawan" subtitle="Belum ada karyawan aktif, atau pencarian tidak cocok." />
      ) : (
        <div className="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 overflow-hidden overflow-x-auto">
          <table className="w-full text-xs">
            <thead>
              <tr className="text-left text-slate-400 border-b border-slate-100 dark:border-slate-800">
                <th className="px-4 py-2.5 font-semibold">Karyawan</th>
                <th className="px-4 py-2.5 font-semibold">Departemen</th>
                <th className="px-4 py-2.5 font-semibold text-right">Gaji Pokok</th>
                <th className="px-4 py-2.5 font-semibold text-center">PTKP</th>
                <th className="px-4 py-2.5 font-semibold text-center">NPWP</th>
                <th className="px-4 py-2.5 font-semibold text-right"></th>
              </tr>
            </thead>
            <tbody>
              {filtered.map((r) => {
                const isForeign = r.tax_profile?.tax_subject_type === 'foreign' || r.tax_subject_type === 'foreign';
                return (
                  <tr key={r.id} className="border-b border-slate-50 dark:border-slate-800/60 hover:bg-slate-50 dark:hover:bg-slate-800/40 transition">
                    <td className="px-4 py-2.5">
                      <div className="flex items-center gap-2">
                        <div>
                          <p className="font-semibold text-slate-800 dark:text-slate-100">{r.name}</p>
                          {r.employee_code && <p className="text-[10px] text-slate-400">{r.employee_code}</p>}
                        </div>
                        {isForeign && (
                          <span className="px-1.5 py-0.5 rounded text-[10px] font-bold bg-purple-50 text-purple-700 dark:bg-purple-950/60 dark:text-purple-300 border border-purple-200 dark:border-purple-800/60" title="Subjek Pajak Luar Negeri (PPh 26)">
                            PPh 26
                          </span>
                        )}
                      </div>
                    </td>
                    <td className="px-4 py-2.5 text-slate-600 dark:text-slate-400">{r.position || r.department || '—'}</td>
                    <td className="px-4 py-2.5 text-right">
                      {r.basic_salary != null ? (
                        <div className="inline-flex flex-col items-end">
                          <span className="font-semibold text-slate-800 dark:text-slate-100">
                            {r.currency && r.currency !== 'IDR' ? `${r.currency} ` : ''}{formatCurrency(r.basic_salary)}
                          </span>
                          {r.currency && r.currency !== 'IDR' && (
                            <span className="text-[10px] font-medium text-amber-600 dark:text-amber-400">Valas ({r.currency})</span>
                          )}
                        </div>
                      ) : (
                        <span className="text-[11px] text-amber-600 dark:text-amber-400">Belum diatur</span>
                      )}
                    </td>
                    <td className="px-4 py-2.5 text-center">
                      {r.ptkp_status ? <span className="text-[11px] font-semibold text-slate-600 dark:text-slate-300">{r.ptkp_status}</span> : <span className="text-slate-300">—</span>}
                    </td>
                    <td className="px-4 py-2.5 text-center">
                      {r.has_npwp ? <CheckCircle2 className="w-4 h-4 text-emerald-500 inline" /> : <span className="text-slate-300">—</span>}
                    </td>
                    <td className="px-4 py-2.5 text-right">
                      <button onClick={() => setSelectedUserId(r.id)} className="inline-flex items-center gap-1 text-indigo-600 dark:text-indigo-400 font-semibold hover:underline cursor-pointer">
                        {canManage ? <><Pencil className="w-3.5 h-3.5" /> Atur</> : <><Eye className="w-3.5 h-3.5" /> Lihat</>}
                      </button>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}

      {selectedUserId != null && (
        <EmployeeSalaryModal
          userId={selectedUserId}
          canManage={canManage}
          onClose={() => setSelectedUserId(null)}
          onSaved={() => { load(); }}
        />
      )}
    </div>
  );
};

// ── Modal atur gaji karyawan (gaji pokok + tunjangan tetap + profil pajak + BPJS + rekening bank) ──
const EmployeeSalaryModal: React.FC<{
  userId: number; canManage: boolean; onClose: () => void; onSaved: () => void;
}> = ({ userId, canManage, onClose, onSaved }) => {
  const { user } = useAuth();
  const { banner, show, clear } = useBanner();
  const [data, setData] = useState<any | null>(null);
  const [components, setComponents] = useState<any[]>([]);
  const [bankAccounts, setBankAccounts] = useState<EmployeeBankAccount[]>([]);
  const [loading, setLoading] = useState(true);
  const [tab, setTab] = useState<'gaji' | 'komponen' | 'pajak' | 'bpjs' | 'bank'>('gaji');

  // Struktur & Skala Upah & Valas (Fase 6)
  const [salaryGrades, setSalaryGrades] = useState<SalaryGrade[]>([]);
  const [jobLevels, setJobLevels] = useState<JobLevel[]>([]);
  const [selectedGradeId, setSelectedGradeId] = useState('');
  const [selectedLevelId, setSelectedLevelId] = useState('');
  const [currency, setCurrency] = useState('IDR');

  // Form gaji pokok
  const [basic, setBasic] = useState('');
  const [effDate, setEffDate] = useState(todayStr());
  const [salaryNotes, setSalaryNotes] = useState('');
  const [savingSalary, setSavingSalary] = useState(false);

  // Form komponen tetap
  const [compId, setCompId] = useState('');
  const [compAmount, setCompAmount] = useState('');
  const [compDate, setCompDate] = useState(todayStr());
  const [savingComp, setSavingComp] = useState(false);

  // Form pajak (Fase 6: Subjek Pajak WPDN PPh 21 vs SPLN PPh 26)
  const [ptkp, setPtkp] = useState('TK/0');
  const [taxMethod, setTaxMethod] = useState<'gross' | 'gross_up'>('gross');
  const [npwp, setNpwp] = useState('');
  const [npwpTouched, setNpwpTouched] = useState(false);
  const [taxSubjectType, setTaxSubjectType] = useState<'domestic' | 'foreign'>('domestic');
  const [treatyCountry, setTreatyCountry] = useState('');
  const [treatyRate, setTreatyRate] = useState('');
  const [foreignTaxId, setForeignTaxId] = useState('');
  const [savingTax, setSavingTax] = useState(false);

  // Form BPJS
  const [hasBpjsKes, setHasBpjsKes] = useState(true);
  const [hasBpjsTk, setHasBpjsTk] = useState(true);
  const [hasJkp, setHasJkp] = useState(true);
  const [jkkRiskClass, setJkkRiskClass] = useState<number>(2);
  const [bpjsKesNo, setBpjsKesNo] = useState('');
  const [bpjsTkNo, setBpjsTkNo] = useState('');
  const [savingBpjs, setSavingBpjs] = useState(false);

  // Form & aksi Rekening Bank
  const [showBankForm, setShowBankForm] = useState(false);
  const [bankName, setBankName] = useState('');
  const [bankAccountNo, setBankAccountNo] = useState('');
  const [bankAccountHolder, setBankAccountHolder] = useState('');
  const [bankBranch, setBankBranch] = useState('');
  const [swiftCode, setSwiftCode] = useState('');
  const [bankNotes, setBankNotes] = useState('');
  const [savingBank, setSavingBank] = useState(false);

  // Reject bank modal state
  const [rejectingAccount, setRejectingAccount] = useState<EmployeeBankAccount | null>(null);
  const [rejectReason, setRejectReason] = useState('');
  const [rejectingBank, setRejectingBank] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const [detailRes, compRes, bankRes, gradesRes, levelsRes] = await Promise.all([
        payrollApi.getSalary(userId),
        payrollApi.listComponents(),
        payrollApi.listBankAccounts(userId).catch(() => ({ data: [] })),
        payrollApi.listSalaryGrades().catch(() => ({ data: [] })),
        payrollApi.listJobLevels().catch(() => ({ data: [] })),
      ]);
      const d = detailRes?.data ?? null;
      setData(d);
      setComponents((compRes?.data ?? []).filter((c: any) => c.is_active));
      setBankAccounts(bankRes?.data ?? []);
      setSalaryGrades(gradesRes?.data ?? []);
      setJobLevels(levelsRes?.data ?? []);

      // Pre-fill active salary grade, level & currency
      const activeSalaryRecord = d?.salaries?.find((s: any) => s.is_active) ?? d?.salaries?.[0];
      if (activeSalaryRecord) {
        setSelectedGradeId(activeSalaryRecord.salary_grade_id ? String(activeSalaryRecord.salary_grade_id) : '');
        setSelectedLevelId(activeSalaryRecord.job_level_id ? String(activeSalaryRecord.job_level_id) : '');
        setCurrency(activeSalaryRecord.currency || 'IDR');
      }

      if (d?.tax_profile) {
        setPtkp(d.tax_profile.ptkp_status ?? 'TK/0');
        setTaxMethod(d.tax_profile.tax_method ?? 'gross');
        setTaxSubjectType(d.tax_profile.tax_subject_type ?? 'domestic');
        setTreatyCountry(d.tax_profile.treaty_country ?? '');
        setTreatyRate(d.tax_profile.treaty_rate != null ? String(Number(d.tax_profile.treaty_rate) * 100) : '');
        setForeignTaxId(d.tax_profile.foreign_tax_id ?? '');
      }

      if (d?.bpjs_profile) {
        setHasBpjsKes(Boolean(d.bpjs_profile.has_bpjs_kes));
        setHasBpjsTk(Boolean(d.bpjs_profile.has_bpjs_tk));
        setHasJkp(Boolean(d.bpjs_profile.has_jkp));
        setJkkRiskClass(Number(d.bpjs_profile.jkk_risk_class) || 2);
      }
    } catch (e: any) {
      show('error', errMsg(e, 'Gagal memuat detail gaji.'));
    } finally { setLoading(false); }
  }, [userId, show]);

  useEffect(() => { load(); }, [load]);

  const saveSalary = async () => {
    if (!basic || Number(basic) <= 0) { show('error', 'Gaji pokok harus lebih dari 0.'); return; }

    const selectedGrade = salaryGrades.find(g => String(g.id) === String(selectedGradeId));
    if (selectedGrade) {
      const basicNum = Number(basic);
      if (basicNum < selectedGrade.min_salary || basicNum > selectedGrade.max_salary) {
        show('error', `Gaji pokok di luar rentang golongan ${selectedGrade.name} (${formatCurrency(selectedGrade.min_salary)} – ${formatCurrency(selectedGrade.max_salary)}).`);
        return;
      }
    }

    setSavingSalary(true);
    try {
      await payrollApi.saveSalary(userId, {
        basic_salary: Number(basic),
        effective_date: effDate,
        notes: salaryNotes.trim() || undefined,
        salary_grade_id: selectedGradeId ? Number(selectedGradeId) : null,
        job_level_id: selectedLevelId ? Number(selectedLevelId) : null,
        currency: currency || 'IDR',
      });
      show('success', 'Gaji pokok berhasil disimpan.');
      setBasic(''); setSalaryNotes('');
      await load(); onSaved();
    } catch (e: any) { show('error', errMsg(e, 'Gagal menyimpan gaji pokok.')); }
    finally { setSavingSalary(false); }
  };

  const saveComponent = async () => {
    if (!compId) { show('error', 'Pilih komponen terlebih dahulu.'); return; }
    if (!compAmount || Number(compAmount) < 0) { show('error', 'Nominal komponen tidak valid.'); return; }
    setSavingComp(true);
    try {
      await payrollApi.saveSalaryComponent(userId, { salary_component_id: Number(compId), amount: Number(compAmount), effective_date: compDate });
      show('success', 'Komponen tetap ditambahkan.');
      setCompId(''); setCompAmount('');
      await load(); onSaved();
    } catch (e: any) { show('error', errMsg(e, 'Gagal menambah komponen.')); }
    finally { setSavingComp(false); }
  };

  const deleteComponent = async (id: number) => {
    try {
      await payrollApi.deleteSalaryComponent(id);
      show('success', 'Komponen dinonaktifkan.');
      await load(); onSaved();
    } catch (e: any) { show('error', errMsg(e, 'Gagal menghapus komponen.')); }
  };

  const saveTax = async () => {
    setSavingTax(true);
    try {
      const payload: any = {
        ptkp_status: ptkp,
        tax_method: taxMethod,
        tax_subject_type: taxSubjectType,
      };
      if (taxSubjectType === 'foreign') {
        const c = treatyCountry.trim().toUpperCase();
        payload.treaty_country = c || null;
        payload.treaty_rate = treatyRate ? Number(treatyRate) / 100 : null;
        payload.foreign_tax_id = foreignTaxId.trim() || null;
        if (payload.treaty_rate !== null && !payload.treaty_country) {
          show('error', 'Negara mitra P3B wajib diisi bila tarif P3B diisi.');
          setSavingTax(false);
          return;
        }
      } else {
        payload.treaty_country = null;
        payload.treaty_rate = null;
        payload.foreign_tax_id = null;
      }
      if (npwpTouched) payload.npwp = npwp; // kirim hanya jika diubah
      await payrollApi.saveTaxProfile(userId, payload);
      show('success', 'Profil pajak berhasil disimpan.');
      setNpwp(''); setNpwpTouched(false);
      await load(); onSaved();
    } catch (e: any) { show('error', errMsg(e, 'Gagal menyimpan profil pajak.')); }
    finally { setSavingTax(false); }
  };

  const saveBpjs = async () => {
    setSavingBpjs(true);
    try {
      const payload: any = {
        has_bpjs_kes: hasBpjsKes,
        has_bpjs_tk: hasBpjsTk,
        has_jkp: hasJkp,
        jkk_risk_class: Number(jkkRiskClass),
      };
      if (bpjsKesNo.trim()) payload.bpjs_kes_no = bpjsKesNo.trim();
      if (bpjsTkNo.trim()) payload.bpjs_tk_no = bpjsTkNo.trim();
      await payrollApi.saveBpjsProfile(userId, payload);
      show('success', 'Profil BPJS berhasil disimpan.');
      setBpjsKesNo(''); setBpjsTkNo('');
      await load(); onSaved();
    } catch (e: any) { show('error', errMsg(e, 'Gagal menyimpan profil BPJS.')); }
    finally { setSavingBpjs(false); }
  };

  const submitBank = async () => {
    if (!bankName.trim() || !bankAccountNo.trim() || !bankAccountHolder.trim()) {
      show('error', 'Nama bank, nomor rekening, dan nama pemilik rekening wajib diisi.');
      return;
    }
    setSavingBank(true);
    try {
      await payrollApi.submitBankAccount(userId, {
        bank_name: bankName.trim(),
        bank_account_no: bankAccountNo.trim(),
        bank_account_holder: bankAccountHolder.trim(),
        bank_branch: bankBranch.trim() || undefined,
        swift_code: swiftCode.trim() || undefined,
        notes: bankNotes.trim() || undefined,
      });
      show('success', 'Rekening bank berhasil diajukan (status: menunggu verifikasi).');
      setShowBankForm(false);
      setBankName(''); setBankAccountNo(''); setBankAccountHolder(''); setBankBranch(''); setSwiftCode(''); setBankNotes('');
      await load(); onSaved();
    } catch (e: any) { show('error', errMsg(e, 'Gagal mengajukan rekening bank.')); }
    finally { setSavingBank(false); }
  };

  const verifyBank = async (account: EmployeeBankAccount) => {
    try {
      await payrollApi.verifyBankAccount(account.id);
      show('success', `Rekening ${account.bank_name} berhasil diverifikasi & menjadi rekening aktif.`);
      await load(); onSaved();
    } catch (e: any) { show('error', errMsg(e, 'Gagal memverifikasi rekening.')); }
  };

  const rejectBank = async () => {
    if (!rejectingAccount) return;
    if (rejectReason.trim().length < 3) {
      show('error', 'Alasan penolakan minimal 3 karakter.');
      return;
    }
    setRejectingBank(true);
    try {
      await payrollApi.rejectBankAccount(rejectingAccount.id, rejectReason.trim());
      show('success', 'Pengajuan rekening berhasil ditolak.');
      setRejectingAccount(null);
      setRejectReason('');
      await load(); onSaved();
    } catch (e: any) { show('error', errMsg(e, 'Gagal menolak rekening.')); }
    finally { setRejectingBank(false); }
  };

  const emp = data?.employee;
  const activeComponents = components;

  return (
    <Modal
      open onClose={onClose} maxW="max-w-2xl"
      title={emp ? `Gaji & Profil · ${emp.name}` : 'Gaji Karyawan'}
      icon={<Wallet className="w-4 h-4 text-indigo-500" />}
    >
      <Banner banner={banner} onClose={clear} />
      {loading || !data ? <Spinner /> : (
        <>
          {/* Sub-tab bar */}
          <div className="flex gap-1 rounded-xl bg-slate-100 dark:bg-slate-800 p-1 overflow-x-auto">
            {([
              ['gaji', 'Gaji Pokok'],
              ['komponen', 'Tunjangan Tetap'],
              ['pajak', 'Profil Pajak'],
              ['bpjs', 'Profil BPJS'],
              ['bank', 'Rekening Bank'],
            ] as const).map(([k, l]) => (
              <button
                key={k}
                onClick={() => setTab(k)}
                className={`flex-1 rounded-lg px-2.5 py-1.5 text-xs font-semibold whitespace-nowrap transition cursor-pointer ${
                  tab === k
                    ? 'bg-white dark:bg-slate-900 text-indigo-600 dark:text-indigo-400 shadow-sm'
                    : 'text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200'
                }`}
              >
                {l}
              </button>
            ))}
          </div>

          {/* GAJI POKOK */}
          {tab === 'gaji' && (() => {
            const selectedGrade = salaryGrades.find((g) => String(g.id) === String(selectedGradeId));
            const isOutOfRange = Boolean(
              selectedGrade && basic && (Number(basic) < selectedGrade.min_salary || Number(basic) > selectedGrade.max_salary)
            );
            return (
              <div className="space-y-4">
                <div className="rounded-xl bg-slate-50 dark:bg-slate-800/50 p-3 flex items-center justify-between">
                  <span className="text-xs text-slate-500 dark:text-slate-400">Gaji pokok aktif saat ini</span>
                  <div className="text-right">
                    <span className="text-sm font-bold text-slate-900 dark:text-white">
                      {data.active_salary != null ? formatCurrency(data.active_salary) : 'Belum diatur'}
                    </span>
                    {data.salaries?.[0]?.currency && data.salaries[0].currency !== 'IDR' && (
                      <span className="ml-2 text-xs font-semibold px-2 py-0.5 rounded-full bg-amber-50 text-amber-700 dark:bg-amber-950/60 dark:text-amber-300 border border-amber-200 dark:border-amber-800/60">
                        {data.salaries[0].currency}
                      </span>
                    )}
                  </div>
                </div>

                {canManage && (
                  <div className="rounded-xl border border-slate-200 dark:border-slate-800 p-4 space-y-3">
                    <p className="text-xs font-bold text-slate-600 dark:text-slate-300">Tetapkan gaji pokok baru</p>

                    {/* Jenjang & Golongan Upah (Fase 6 Struktur & Skala Upah) */}
                    <div className="grid sm:grid-cols-2 gap-3">
                      <Field label="Jenjang Jabatan (opsional)" hint="Struktur organisasi / level karir">
                        <select
                          value={selectedLevelId}
                          onChange={(e) => setSelectedLevelId(e.target.value)}
                          className={inputCls}
                        >
                          <option value="">Tanpa Jenjang</option>
                          {jobLevels.map((lvl) => (
                            <option key={lvl.id} value={lvl.id}>{lvl.name} (Rank {lvl.rank})</option>
                          ))}
                        </select>
                      </Field>

                      <Field label="Golongan Upah (opsional)" hint="Skala upah min – maks">
                        <select
                          value={selectedGradeId}
                          onChange={(e) => {
                            const gid = e.target.value;
                            setSelectedGradeId(gid);
                            if (gid) {
                              const g = salaryGrades.find((x) => String(x.id) === String(gid));
                              if (g && g.job_level_id && !selectedLevelId) {
                                setSelectedLevelId(String(g.job_level_id));
                              }
                              if (g && g.currency && currency === 'IDR') {
                                setCurrency(g.currency);
                              }
                            }
                          }}
                          className={inputCls}
                        >
                          <option value="">Tanpa Golongan</option>
                          {salaryGrades
                            .filter((g) => !selectedLevelId || String(g.job_level_id) === String(selectedLevelId))
                            .map((g) => (
                              <option key={g.id} value={g.id}>
                                {g.name} ({formatCurrency(g.min_salary)} – {formatCurrency(g.max_salary)})
                              </option>
                            ))}
                        </select>
                      </Field>
                    </div>

                    <div className="grid sm:grid-cols-3 gap-3">
                      <Field label="Mata Uang Kontrak" required hint="Mata uang kontrak kerja">
                        <select value={currency} onChange={(e) => setCurrency(e.target.value)} className={inputCls}>
                          <option value="IDR">IDR – Rupiah (Indonesia)</option>
                          <option value="USD">USD – US Dollar</option>
                          <option value="SGD">SGD – Singapore Dollar</option>
                          <option value="EUR">EUR – Euro</option>
                          <option value="JPY">JPY – Japanese Yen</option>
                          <option value="GBP">GBP – British Pound</option>
                          <option value="AUD">AUD – Australian Dollar</option>
                          <option value="CNY">CNY – Chinese Yuan</option>
                        </select>
                      </Field>

                      <Field label={`Gaji Pokok (${currency})`} required>
                        <input
                          type="number"
                          min={0}
                          value={basic}
                          onChange={(e) => setBasic(e.target.value)}
                          className={inputCls}
                          placeholder={currency === 'IDR' ? 'Contoh: 8000000' : 'Contoh: 2500'}
                        />
                        {basic && <p className="text-[11px] text-indigo-500 font-medium">{currency} {formatCurrency(basic)}</p>}
                      </Field>

                      <Field label="Berlaku sejak" required>
                        <CustomDatePicker value={effDate} onChange={setEffDate} />
                      </Field>
                    </div>

                    {/* Warning jika nominal di luar rentang skala upah golongan */}
                    {isOutOfRange && selectedGrade && (
                      <div className="rounded-xl border border-amber-200 dark:border-amber-900/60 bg-amber-50 dark:bg-amber-950/30 p-3 text-xs text-amber-800 dark:text-amber-300 flex items-start gap-2">
                        <AlertTriangle className="w-4 h-4 text-amber-600 dark:text-amber-400 shrink-0 mt-0.5" />
                        <div className="space-y-0.5">
                          <p className="font-bold">Peringatan: Gaji Di Luar Rentang Skala Upah</p>
                          <p>
                            Nominal {currency} {formatCurrency(basic)} berada di luar skala golongan <strong>{selectedGrade.name}</strong> ({currency} {formatCurrency(selectedGrade.min_salary)} – {currency} {formatCurrency(selectedGrade.max_salary)}). Permenaker 1/2017 mewajibkan kepatuhan batas skala upah.
                          </p>
                        </div>
                      </div>
                    )}

                    {currency !== 'IDR' && (
                      <div className="rounded-xl border border-sky-200 dark:border-sky-900/50 bg-sky-50 dark:bg-sky-950/20 p-3 text-xs text-sky-800 dark:text-sky-300 flex items-start gap-2">
                        <Info className="w-4 h-4 text-sky-600 dark:text-sky-400 shrink-0 mt-0.5" />
                        <p>
                          Gaji dalam valuta asing ({currency}) akan dikonversi ke IDR pada saat proses kalkulasi payroll run menggunakan kurs transaksi efektif yang terkunci secara permanen.
                        </p>
                      </div>
                    )}

                    <Field label="Catatan (opsional)">
                      <input value={salaryNotes} onChange={(e) => setSalaryNotes(e.target.value)} className={inputCls} placeholder="Contoh: Kenaikan gaji tahunan" />
                    </Field>

                    <div className="flex justify-end">
                      <button onClick={saveSalary} className={btnPrimary} disabled={savingSalary || isOutOfRange}>
                        {savingSalary ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <Save className="w-3.5 h-3.5" />} Simpan Gaji
                      </button>
                    </div>
                  </div>
                )}

                {/* Riwayat */}
                {(data.salaries?.length ?? 0) > 0 && (
                  <div>
                    <p className="text-[11px] font-bold text-slate-500 dark:text-slate-400 mb-2">Riwayat gaji pokok</p>
                    <div className="rounded-xl border border-slate-200 dark:border-slate-800 divide-y divide-slate-100 dark:divide-slate-800">
                      {data.salaries.map((s: any) => (
                        <div key={s.id} className="flex items-center justify-between px-3 py-2 text-xs">
                          <div className="space-y-0.5">
                            <span className="text-slate-600 dark:text-slate-300">
                              {formatDate(s.effective_date)}{s.end_date ? ` – ${formatDate(s.end_date)}` : ''}
                              {s.is_active && <span className="ml-2 text-[10px] font-bold text-emerald-600 dark:text-emerald-400">Aktif</span>}
                            </span>
                            {(s.salary_grade || s.job_level) && (
                              <p className="text-[10px] text-slate-400">
                                {s.job_level?.name ? `${s.job_level.name} · ` : ''}
                                {s.salary_grade?.name ? `Golongan: ${s.salary_grade.name}` : ''}
                              </p>
                            )}
                          </div>
                          <span className="font-semibold text-slate-800 dark:text-slate-100">
                            {s.currency && s.currency !== 'IDR' ? `${s.currency} ` : ''}{formatCurrency(s.basic_salary)}
                          </span>
                        </div>
                      ))}
                    </div>
                  </div>
                )}
              </div>
            );
          })()}

          {/* KOMPONEN TETAP */}
          {tab === 'komponen' && (
            <div className="space-y-4">
              {canManage && (
                <div className="rounded-xl border border-slate-200 dark:border-slate-800 p-4 space-y-3">
                  <p className="text-xs font-bold text-slate-600 dark:text-slate-300">Tambah tunjangan / potongan tetap</p>
                  {activeComponents.length === 0 ? (
                    <p className="text-[11px] text-amber-600 dark:text-amber-400 flex items-center gap-1"><Info className="w-3 h-3" /> Belum ada komponen aktif. Buat dulu di tab "Komponen Gaji".</p>
                  ) : (
                    <>
                      <div className="grid sm:grid-cols-3 gap-3">
                        <Field label="Komponen" required>
                          <select value={compId} onChange={(e) => setCompId(e.target.value)} className={inputCls}>
                            <option value="">Pilih...</option>
                            {activeComponents.map((c) => <option key={c.id} value={c.id}>{c.name} ({c.type === 'earning' ? 'Pendapatan' : 'Potongan'})</option>)}
                          </select>
                        </Field>
                        <Field label="Nominal (Rp)" required>
                          <input type="number" min={0} value={compAmount} onChange={(e) => setCompAmount(e.target.value)} className={inputCls} placeholder="0" />
                        </Field>
                        <Field label="Berlaku sejak" required>
                          <CustomDatePicker value={compDate} onChange={setCompDate} />
                        </Field>
                      </div>
                      <div className="flex justify-end">
                        <button onClick={saveComponent} className={btnPrimary} disabled={savingComp}>
                          {savingComp ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <Plus className="w-3.5 h-3.5" />} Tambah
                        </button>
                      </div>
                    </>
                  )}
                </div>
              )}

              {(data.components?.length ?? 0) === 0 ? (
                <EmptyState icon={<Layers className="w-6 h-6" />} title="Belum ada tunjangan tetap" />
              ) : (
                <div className="rounded-xl border border-slate-200 dark:border-slate-800 divide-y divide-slate-100 dark:divide-slate-800">
                  {data.components.map((c: any) => (
                    <div key={c.id} className={`flex items-center justify-between px-3 py-2.5 text-xs ${!c.is_active ? 'opacity-50' : ''}`}>
                      <div>
                        <p className="font-semibold text-slate-800 dark:text-slate-100">{c.component?.name ?? 'Komponen'}
                          <span className={`ml-2 text-[10px] font-bold ${c.component?.type === 'earning' ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400'}`}>
                            {c.component?.type === 'earning' ? 'Pendapatan' : 'Potongan'}
                          </span>
                          {!c.is_active && <span className="ml-2 text-[10px] text-slate-400">(nonaktif)</span>}
                        </p>
                        <p className="text-[10px] text-slate-400">Berlaku {formatDate(c.effective_date)}</p>
                      </div>
                      <div className="flex items-center gap-3">
                        <span className="font-semibold text-slate-800 dark:text-slate-100">{formatCurrency(c.amount)}</span>
                        {canManage && c.is_active && (
                          <button onClick={() => deleteComponent(c.id)} className="text-rose-500 hover:text-rose-600 transition cursor-pointer" title="Nonaktifkan"><Trash2 className="w-3.5 h-3.5" /></button>
                        )}
                      </div>
                    </div>
                  ))}
                </div>
              )}
            </div>
          )}

          {/* PROFIL PAJAK */}
          {tab === 'pajak' && (
            <div className="space-y-4">
              {/* Pilihan Subjek Pajak: Domestik (PPh 21) vs Luar Negeri (PPh 26) */}
              <div className="rounded-xl border border-slate-200 dark:border-slate-800 p-3.5 space-y-3 bg-slate-50/50 dark:bg-slate-800/30">
                <label className="text-xs font-bold text-slate-700 dark:text-slate-200">Subjek Pajak Penghasilan</label>
                <div className="grid sm:grid-cols-2 gap-2">
                  <button
                    type="button"
                    onClick={() => canManage && setTaxSubjectType('domestic')}
                    className={`flex items-start gap-2.5 p-3 rounded-xl border text-left transition cursor-pointer ${
                      taxSubjectType === 'domestic'
                        ? 'border-indigo-600 bg-indigo-50/50 dark:bg-indigo-950/40 text-indigo-900 dark:text-indigo-200'
                        : 'border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 text-slate-600 dark:text-slate-400 hover:border-slate-300'
                    }`}
                  >
                    <input
                      type="radio"
                      checked={taxSubjectType === 'domestic'}
                      onChange={() => setTaxSubjectType('domestic')}
                      className="mt-0.5 text-indigo-600"
                      disabled={!canManage}
                    />
                    <div>
                      <p className="text-xs font-bold text-slate-800 dark:text-slate-100">Wajib Pajak Dalam Negeri (WPDN)</p>
                      <p className="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">Dipotong PPh 21 menggunakan tarif progresif TER bulanan & 1721-A1 tahunan.</p>
                    </div>
                  </button>

                  <button
                    type="button"
                    onClick={() => canManage && setTaxSubjectType('foreign')}
                    className={`flex items-start gap-2.5 p-3 rounded-xl border text-left transition cursor-pointer ${
                      taxSubjectType === 'foreign'
                        ? 'border-purple-600 bg-purple-50/50 dark:bg-purple-950/40 text-purple-900 dark:text-purple-200'
                        : 'border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 text-slate-600 dark:text-slate-400 hover:border-slate-300'
                    }`}
                  >
                    <input
                      type="radio"
                      checked={taxSubjectType === 'foreign'}
                      onChange={() => setTaxSubjectType('foreign')}
                      className="mt-0.5 text-purple-600"
                      disabled={!canManage}
                    />
                    <div>
                      <div className="flex items-center gap-1.5">
                        <p className="text-xs font-bold text-slate-800 dark:text-slate-100">Subjek Pajak Luar Negeri (SPLN / PPh 26)</p>
                        <span className="px-1.5 py-0.5 rounded text-[9px] font-bold bg-purple-100 text-purple-700 dark:bg-purple-900/60 dark:text-purple-300">PPh 26</span>
                      </div>
                      <p className="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">Ekspatriat / bukan residen pajak Indonesia. Tarif standar 20% bruto atau tarif tax treaty P3B.</p>
                    </div>
                  </button>
                </div>
              </div>

              {taxSubjectType === 'foreign' && (
                <div className="rounded-xl border border-purple-200 dark:border-purple-900/50 bg-purple-50/40 dark:bg-purple-950/20 p-3.5 space-y-3">
                  <div className="flex items-start gap-2 text-xs text-purple-800 dark:text-purple-300">
                    <Info className="w-4 h-4 shrink-0 mt-0.5 text-purple-600 dark:text-purple-400" />
                    <div>
                      <p className="font-bold">Ketentuan Pemotongan PPh Pasal 26:</p>
                      <p className="text-[11px] text-purple-700/90 dark:text-purple-300/90 mt-0.5 leading-relaxed">
                        Karyawan SPLN dikenakan tarif flat 20% dari penghasilan bruto tanpa pengurang PTKP. Jika negara residen memiliki Perjanjian Penghindaran Pajak Berganda (P3B) dengan Indonesia dan dilengkapi Formulir DGT / SKD yang valid, masukkan kode negara dan tarif istimewa P3B.
                      </p>
                    </div>
                  </div>

                  <div className="grid sm:grid-cols-3 gap-3">
                    <Field label="Kode Negara P3B (ISO-2)" hint="Contoh: SG, US, JP, AU, MY, GB, dll.">
                      <input
                        value={treatyCountry}
                        onChange={(e) => setTreatyCountry(e.target.value.toUpperCase())}
                        maxLength={2}
                        className={`${inputCls} uppercase font-mono`}
                        placeholder="SG"
                        disabled={!canManage}
                      />
                    </Field>

                    <Field label="Tarif P3B (%)" hint="Kosongkan untuk tarif standar 20%. Maks 40%.">
                      <input
                        type="number"
                        min={0}
                        max={40}
                        step="0.1"
                        value={treatyRate}
                        onChange={(e) => setTreatyRate(e.target.value)}
                        className={inputCls}
                        placeholder="Contoh: 10"
                        disabled={!canManage}
                      />
                    </Field>

                    <Field label="TIN / Tax ID Negara Asal" hint="Nomor identitas pajak di negara residen">
                      <input
                        value={foreignTaxId}
                        onChange={(e) => setForeignTaxId(e.target.value)}
                        className={inputCls}
                        placeholder="Contoh: S1234567A"
                        disabled={!canManage}
                      />
                    </Field>
                  </div>
                </div>
              )}

              <div className="grid sm:grid-cols-2 gap-3">
                <Field label="Status PTKP" required hint={taxSubjectType === 'foreign' ? 'SPLN tidak menggunakan PTKP dalam perhitungan PPh 26, namun tetap disimpan untuk rekam data.' : 'Penentu Penghasilan Tidak Kena Pajak untuk PPh21.'}>
                  <select value={ptkp} onChange={(e) => setPtkp(e.target.value)} className={inputCls} disabled={!canManage}>
                    {PTKP_OPTIONS.map((o) => <option key={o} value={o}>{o}</option>)}
                  </select>
                </Field>
                <Field label="Metode pajak" hint="Gross = pajak dipotong dari gaji. Gross-up = pajak ditanggung perusahaan.">
                  <select value={taxMethod} onChange={(e) => setTaxMethod(e.target.value as any)} className={inputCls} disabled={!canManage}>
                    <option value="gross">Gross (dipotong)</option>
                    <option value="gross_up">Gross-up (ditanggung)</option>
                  </select>
                </Field>
              </div>

              <Field label="NPWP" hint={data.tax_profile?.npwp_masked ? `Tersimpan: ${data.tax_profile.npwp_masked}. Kosongkan untuk menghapus. Biarkan tak tersentuh untuk tidak mengubah.` : 'Masukkan 15 digit (lama) atau 16 digit (baru). Disimpan terenkripsi.'}>
                <input
                  value={npwp}
                  onChange={(e) => { setNpwp(e.target.value); setNpwpTouched(true); }}
                  className={inputCls}
                  placeholder={data.tax_profile?.npwp_masked || 'Contoh: 09.254.294.3-407.000'}
                  disabled={!canManage}
                  inputMode="numeric"
                />
              </Field>

              {canManage && (
                <div className="flex justify-end">
                  <button onClick={saveTax} className={btnPrimary} disabled={savingTax}>
                    {savingTax ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <Save className="w-3.5 h-3.5" />} Simpan Profil Pajak
                  </button>
                </div>
              )}
            </div>
          )}

          {/* PROFIL BPJS (FASE 2) */}
          {tab === 'bpjs' && (
            <div className="space-y-4">
              <div className="rounded-xl border border-sky-200 dark:border-sky-900/40 bg-sky-50/50 dark:bg-sky-950/20 p-3.5 text-xs text-sky-800 dark:text-sky-300">
                <p className="font-semibold flex items-center gap-1.5">
                  <ShieldCheck className="w-4 h-4 text-sky-600 dark:text-sky-400" /> Profil Kepesertaan BPJS Karyawan
                </p>
                <p className="text-[11px] text-sky-700/80 dark:text-sky-400/80 mt-1 leading-relaxed">
                  Pengaturan iuran statutori BPJS Kesehatan dan BPJS Ketenagakerjaan (JKK, JKM, JHT, JP, JKP). Kalkulator payroll otomatis menghitung bagian perusahaan dan potongan karyawan sesuai peraturan ketenagakerjaan.
                </p>
              </div>

              {/* Toggles Kepesertaan */}
              <div className="rounded-xl border border-slate-200 dark:border-slate-800 divide-y divide-slate-100 dark:divide-slate-800 bg-white dark:bg-slate-900">
                <label className="flex items-center justify-between p-3.5 cursor-pointer hover:bg-slate-50 dark:hover:bg-slate-800/40 transition">
                  <div className="space-y-0.5">
                    <p className="text-xs font-bold text-slate-800 dark:text-slate-100">BPJS Kesehatan</p>
                    <p className="text-[11px] text-slate-400">Iuran 5% (4% ditanggung perusahaan, 1% dipotong dari gaji karyawan).</p>
                  </div>
                  <input
                    type="checkbox"
                    checked={hasBpjsKes}
                    onChange={(e) => setHasBpjsKes(e.target.checked)}
                    disabled={!canManage}
                    className="w-4 h-4 rounded text-indigo-600 focus:ring-indigo-500 border-slate-300"
                  />
                </label>

                <label className="flex items-center justify-between p-3.5 cursor-pointer hover:bg-slate-50 dark:hover:bg-slate-800/40 transition">
                  <div className="space-y-0.5">
                    <p className="text-xs font-bold text-slate-800 dark:text-slate-100">BPJS Ketenagakerjaan</p>
                    <p className="text-[11px] text-slate-400">Mencakup JHT (3.7% PT / 2% Pegawai), JKK, JKM (0.3% PT), dan JP (2% PT / 1% Pegawai).</p>
                  </div>
                  <input
                    type="checkbox"
                    checked={hasBpjsTk}
                    onChange={(e) => setHasBpjsTk(e.target.checked)}
                    disabled={!canManage}
                    className="w-4 h-4 rounded text-indigo-600 focus:ring-indigo-500 border-slate-300"
                  />
                </label>

                <label className="flex items-center justify-between p-3.5 cursor-pointer hover:bg-slate-50 dark:hover:bg-slate-800/40 transition">
                  <div className="space-y-0.5">
                    <p className="text-xs font-bold text-slate-800 dark:text-slate-100">Jaminan Kehilangan Pekerjaan (JKP)</p>
                    <p className="text-[11px] text-slate-400">Iuran 0.22% (direkomposisi dari JKK/JKM + subsidi APBN, tidak memotong gaji karyawan).</p>
                  </div>
                  <input
                    type="checkbox"
                    checked={hasJkp}
                    onChange={(e) => setHasJkp(e.target.checked)}
                    disabled={!canManage}
                    className="w-4 h-4 rounded text-indigo-600 focus:ring-indigo-500 border-slate-300"
                  />
                </label>
              </div>

              {/* Klasifikasi JKK */}
              <Field
                label="Klasifikasi Tingkat Risiko JKK"
                required
                hint="Menentukan tarif Jaminan Kecelakaan Kerja yang ditanggung perusahaan (0.24% – 1.74%)."
              >
                <select
                  value={jkkRiskClass}
                  onChange={(e) => setJkkRiskClass(Number(e.target.value))}
                  disabled={!canManage}
                  className={inputCls}
                >
                  <option value={1}>{jkkRiskClassLabel(1)}</option>
                  <option value={2}>{jkkRiskClassLabel(2)}</option>
                  <option value={3}>{jkkRiskClassLabel(3)}</option>
                  <option value={4}>{jkkRiskClassLabel(4)}</option>
                  <option value={5}>{jkkRiskClassLabel(5)}</option>
                </select>
              </Field>

              {/* Nomor Kartu BPJS */}
              <div className="grid sm:grid-cols-2 gap-3">
                <Field
                  label="Nomor BPJS Kesehatan"
                  hint={data.bpjs_profile?.bpjs_kes_no_masked ? `Tersimpan: ${data.bpjs_profile.bpjs_kes_no_masked}. Biarkan kosong bila tidak diubah.` : 'Nomor kartu BPJS Kesehatan (disimpan terenkripsi).'}
                >
                  <input
                    value={bpjsKesNo}
                    onChange={(e) => setBpjsKesNo(e.target.value)}
                    className={inputCls}
                    placeholder={data.bpjs_profile?.bpjs_kes_no_masked || 'Contoh: 0001234567890'}
                    disabled={!canManage}
                    inputMode="numeric"
                  />
                </Field>

                <Field
                  label="Nomor BPJS Ketenagakerjaan (KPJ)"
                  hint={data.bpjs_profile?.bpjs_tk_no_masked ? `Tersimpan: ${data.bpjs_profile.bpjs_tk_no_masked}. Biarkan kosong bila tidak diubah.` : 'Nomor KPJ BPJS TK (disimpan terenkripsi).'}
                >
                  <input
                    value={bpjsTkNo}
                    onChange={(e) => setBpjsTkNo(e.target.value)}
                    className={inputCls}
                    placeholder={data.bpjs_profile?.bpjs_tk_no_masked || 'Contoh: 12345678901'}
                    disabled={!canManage}
                    inputMode="numeric"
                  />
                </Field>
              </div>

              {canManage && (
                <div className="flex justify-end">
                  <button onClick={saveBpjs} className={btnPrimary} disabled={savingBpjs}>
                    {savingBpjs ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <Save className="w-3.5 h-3.5" />} Simpan Profil BPJS
                  </button>
                </div>
              )}
            </div>
          )}

          {/* REKENING BANK & MAKER-CHECKER (FASE 3) */}
          {tab === 'bank' && (
            <div className="space-y-4">
              <div className="rounded-xl border border-amber-200 dark:border-amber-900/40 bg-amber-50/50 dark:bg-amber-950/20 p-3.5 text-xs text-amber-800 dark:text-amber-300">
                <p className="font-semibold flex items-center gap-1.5">
                  <ShieldAlert className="w-4 h-4 text-amber-600 dark:text-amber-400" /> Proteksi Perubahan Rekening (Maker-Checker)
                </p>
                <p className="text-[11px] text-amber-700/80 dark:text-amber-400/80 mt-1 leading-relaxed">
                  Perubahan rekening bank wajib diajukan dan diverifikasi oleh petugas berbeda guna mencegah pembajakan rekening transfer gaji. Rekening yang disetujui otomatis disinkronkan ke profil pencairan gaji.
                </p>
              </div>

              <div className="flex items-center justify-between">
                <span className="text-xs font-bold text-slate-700 dark:text-slate-300">Daftar Rekening Bank Karyawan</span>
                {canManage && !showBankForm && (
                  <button onClick={() => setShowBankForm(true)} className={btnPrimary}>
                    <Plus className="w-3.5 h-3.5" /> Ajukan Rekening Baru
                  </button>
                )}
              </div>

              {/* Form Ajukan Rekening Baru */}
              {showBankForm && (
                <div className="rounded-2xl border border-indigo-200 dark:border-indigo-900/50 bg-indigo-50/30 dark:bg-indigo-950/20 p-4 space-y-3">
                  <div className="flex items-center justify-between">
                    <p className="text-xs font-bold text-indigo-900 dark:text-indigo-200 flex items-center gap-1.5">
                      <CreditCard className="w-4 h-4 text-indigo-600 dark:text-indigo-400" /> Form Pengajuan Rekening Bank
                    </p>
                    <button onClick={() => setShowBankForm(false)} className="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200"><X className="w-4 h-4" /></button>
                  </div>

                  <div className="grid sm:grid-cols-2 gap-3">
                    <Field label="Nama Bank" required>
                      <input value={bankName} onChange={(e) => setBankName(e.target.value)} className={inputCls} placeholder="Contoh: BCA, Mandiri, BRI, BNI" />
                    </Field>
                    <Field label="Nomor Rekening" required hint="Disimpan aman & terenkripsi">
                      <input value={bankAccountNo} onChange={(e) => setBankAccountNo(e.target.value)} className={inputCls} placeholder="Contoh: 1234567890" inputMode="numeric" />
                    </Field>
                  </div>

                  <div className="grid sm:grid-cols-2 gap-3">
                    <Field label="Nama Pemilik Rekening" required hint="Harus sesuai buku tabungan">
                      <input value={bankAccountHolder} onChange={(e) => setBankAccountHolder(e.target.value)} className={inputCls} placeholder="Nama sesuai rekening" />
                    </Field>
                    <Field label="Cabang Bank (opsional)">
                      <input value={bankBranch} onChange={(e) => setBankBranch(e.target.value)} className={inputCls} placeholder="Contoh: KCU Sudirman" />
                    </Field>
                  </div>

                  <div className="grid sm:grid-cols-2 gap-3">
                    <Field label="SWIFT / BIC Code (opsional)">
                      <input value={swiftCode} onChange={(e) => setSwiftCode(e.target.value)} className={inputCls} placeholder="Contoh: CENAIDJA" />
                    </Field>
                    <Field label="Catatan / Alasan Pengajuan">
                      <input value={bankNotes} onChange={(e) => setBankNotes(e.target.value)} className={inputCls} placeholder="Contoh: Penggantian rekening gaji utama" />
                    </Field>
                  </div>

                  <div className="flex justify-end gap-2 pt-1">
                    <button onClick={() => setShowBankForm(false)} className={btnGhost} disabled={savingBank}>Batal</button>
                    <button onClick={submitBank} className={btnPrimary} disabled={savingBank}>
                      {savingBank ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <Send className="w-3.5 h-3.5" />} Ajukan Rekening
                    </button>
                  </div>
                </div>
              )}

              {/* Daftar Rekening */}
              {bankAccounts.length === 0 ? (
                <EmptyState icon={<CreditCard className="w-6 h-6" />} title="Belum ada riwayat rekening" subtitle="Rekening yang digunakan saat ini tercatat di data karyawan atau belum ada pengajuan rekening baru." />
              ) : (
                <div className="space-y-2.5">
                  {bankAccounts.map((a) => {
                    const st = BANK_ACCOUNT_STATUS[a.status] ?? BANK_ACCOUNT_STATUS.pending_verification;
                    const isPending = a.status === 'pending_verification';
                    const isMaker = Number(a.requested_by) === Number(user?.id);

                    return (
                      <div
                        key={a.id}
                        className={`rounded-xl border p-3.5 text-xs transition ${
                          a.status === 'active'
                            ? 'border-emerald-200 dark:border-emerald-900/50 bg-emerald-50/20 dark:bg-emerald-950/10'
                            : isPending
                            ? 'border-amber-200 dark:border-amber-900/50 bg-amber-50/20 dark:bg-amber-950/10'
                            : 'border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 opacity-75'
                        }`}
                      >
                        <div className="flex items-start justify-between gap-3">
                          <div className="space-y-1">
                            <div className="flex items-center gap-2">
                              <span className="font-bold text-slate-800 dark:text-slate-100 text-sm">{a.bank_name}</span>
                              <span className={`text-[10px] font-bold px-2 py-0.5 rounded-full ${st.cls}`}>{st.label}</span>
                              {a.is_primary && (
                                <span className="text-[10px] font-bold px-2 py-0.5 rounded-full bg-indigo-50 text-indigo-700 dark:bg-indigo-950/50 dark:text-indigo-300">
                                  Utama
                                </span>
                              )}
                            </div>
                            <p className="font-mono text-xs text-slate-700 dark:text-slate-200">
                              {a.account_masked || a.bank_account_no_masked || '—'}
                              <span className="ml-2 font-sans font-normal text-slate-400">a.n. {a.bank_account_holder}</span>
                            </p>
                            {(a.bank_branch || a.swift_code) && (
                              <p className="text-[11px] text-slate-400">
                                {[a.bank_branch && `Cabang: ${a.bank_branch}`, a.swift_code && `SWIFT: ${a.swift_code}`].filter(Boolean).join(' · ')}
                              </p>
                            )}
                            {a.notes && <p className="text-[11px] text-slate-500 italic mt-0.5">Catatan: {a.notes}</p>}
                            {a.reject_reason && (
                              <p className="text-[11px] text-rose-600 dark:text-rose-400 font-medium flex items-center gap-1 mt-1">
                                <XCircle className="w-3.5 h-3.5" /> Alasan ditolak: {a.reject_reason}
                              </p>
                            )}
                          </div>

                          <div className="text-right space-y-1">
                            <p className="text-[10px] text-slate-400">
                              Diajukan: {a.requestedBy?.name || 'Sistem'} · {formatDate(a.created_at)}
                            </p>
                            {a.verifiedBy && (
                              <p className="text-[10px] text-emerald-600 dark:text-emerald-400">
                                Diverifikasi: {a.verifiedBy.name} · {formatDate(a.verified_at)}
                              </p>
                            )}
                          </div>
                        </div>

                        {/* Maker-checker buttons untuk pending verification */}
                        {isPending && canManage && (
                          <div className="mt-3 pt-2.5 border-t border-slate-100 dark:border-slate-800/80 flex items-center justify-between">
                            {isMaker ? (
                              <span className="text-[11px] text-amber-600 dark:text-amber-400 font-medium flex items-center gap-1">
                                <ShieldAlert className="w-3.5 h-3.5" /> Maker-checker: Anda yang mengajukan rekening ini. Verifikasi harus dilakukan oleh pengguna lain.
                              </span>
                            ) : (
                              <span className="text-[11px] text-slate-500">
                                Verifikasi rekening ini agar disinkronkan ke penggajian.
                              </span>
                            )}

                            <div className="flex items-center gap-2">
                              <button
                                onClick={() => { setRejectingAccount(a); setRejectReason(''); }}
                                disabled={isMaker}
                                className="inline-flex items-center gap-1 rounded-lg border border-rose-200 dark:border-rose-900/50 bg-rose-50/50 dark:bg-rose-950/20 text-rose-600 dark:text-rose-400 hover:bg-rose-100 dark:hover:bg-rose-950/40 text-xs font-semibold px-2.5 py-1.5 transition disabled:opacity-40 cursor-pointer"
                              >
                                <XCircle className="w-3 h-3" /> Tolak
                              </button>
                              <button
                                onClick={() => verifyBank(a)}
                                disabled={isMaker}
                                className="inline-flex items-center gap-1 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold px-3 py-1.5 transition disabled:opacity-40 shadow-sm shadow-emerald-500/20 cursor-pointer"
                              >
                                <Check className="w-3 h-3" /> Verifikasi
                              </button>
                            </div>
                          </div>
                        )}
                      </div>
                    );
                  })}
                </div>
              )}

              {/* Modal Alasan Tolak Rekening */}
              {rejectingAccount && (
                <Modal
                  open onClose={() => setRejectingAccount(null)}
                  title="Tolak Pengajuan Rekening Bank"
                  icon={<XCircle className="w-4 h-4 text-rose-500" />}
                  maxW="max-w-md"
                  footer={<>
                    <button onClick={() => setRejectingAccount(null)} className={btnGhost} disabled={rejectingBank}>Batal</button>
                    <button onClick={rejectBank} className={`${btnPrimary} bg-rose-600 hover:bg-rose-700 shadow-rose-500/20`} disabled={rejectingBank}>
                      {rejectingBank ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <XCircle className="w-3.5 h-3.5" />} Tolak Rekening
                    </button>
                  </>}
                >
                  <p className="text-xs text-slate-600 dark:text-slate-300 mb-3">
                    Anda akan menolak rekening <b>{rejectingAccount.bank_name} ({rejectingAccount.account_masked || rejectingAccount.bank_account_no_masked})</b> atas nama <b>{rejectingAccount.bank_account_holder}</b>.
                  </p>
                  <Field label="Alasan penolakan" required hint="Alasan akan dicatat pada log audit dan dikirimkan ke karyawan.">
                    <textarea
                      value={rejectReason}
                      onChange={(e) => setRejectReason(e.target.value)}
                      rows={3}
                      maxLength={1000}
                      className={inputCls}
                      placeholder="Contoh: Nama pemilik rekening tidak cocok dengan data KTP karyawan."
                      autoFocus
                    />
                  </Field>
                </Modal>
              )}
            </div>
          )}
        </>
      )}
    </Modal>
  );
};

// ═══════════════════════════════════════════════════════════════
// TAB 3 — Komponen Gaji (master)
// ═══════════════════════════════════════════════════════════════
const KomponenGajiTab: React.FC<{ canManage: boolean }> = ({ canManage }) => {
  const { banner, show, clear } = useBanner();
  const [rows, setRows] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [editing, setEditing] = useState<any | null>(null);
  const [showForm, setShowForm] = useState(false);
  const [confirm, setConfirm] = useState<any | null>(null);
  const [confirmLoading, setConfirmLoading] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const res = await payrollApi.listComponents();
      setRows(res?.data ?? []);
    } catch (e: any) { show('error', errMsg(e, 'Gagal memuat komponen gaji.')); }
    finally { setLoading(false); }
  }, [show]);

  useEffect(() => { load(); }, [load]);

  const remove = async (c: any) => {
    setConfirmLoading(true);
    try {
      await payrollApi.deleteComponent(c.id);
      setConfirm(null);
      show('success', 'Komponen dihapus.');
      await load();
    } catch (e: any) {
      setConfirm(null);
      show('error', errMsg(e, 'Komponen tidak dapat dihapus (mungkin sedang dipakai).'));
    } finally { setConfirmLoading(false); }
  };

  return (
    <div className="space-y-4">
      <Banner banner={banner} onClose={clear} />
      <div className="flex items-center justify-between gap-3 flex-wrap">
        <p className="text-xs text-slate-500 dark:text-slate-400">Master komponen pendapatan & potongan yang dipakai pada slip gaji.</p>
        <div className="flex gap-2">
          <button onClick={load} className={btnGhost}><RefreshCw className="w-3.5 h-3.5" /> Muat ulang</button>
          {canManage && <button onClick={() => { setEditing(null); setShowForm(true); }} className={btnPrimary}><Plus className="w-3.5 h-3.5" /> Komponen Baru</button>}
        </div>
      </div>

      {loading ? <Spinner /> : rows.length === 0 ? (
        <EmptyState icon={<Layers className="w-6 h-6" />} title="Belum ada komponen" subtitle="Buat komponen seperti Tunjangan Makan, Transportasi, atau Potongan BPJS." />
      ) : (
        <div className="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 overflow-hidden overflow-x-auto">
          <table className="w-full text-xs">
            <thead>
              <tr className="text-left text-slate-400 border-b border-slate-100 dark:border-slate-800">
                <th className="px-4 py-2.5 font-semibold">Kode</th>
                <th className="px-4 py-2.5 font-semibold">Nama</th>
                <th className="px-4 py-2.5 font-semibold text-center">Tipe</th>
                <th className="px-4 py-2.5 font-semibold text-center">Perhitungan</th>
                <th className="px-4 py-2.5 font-semibold text-center">Kena Pajak</th>
                <th className="px-4 py-2.5 font-semibold text-center">Status</th>
                {canManage && <th className="px-4 py-2.5 font-semibold text-right"></th>}
              </tr>
            </thead>
            <tbody>
              {rows.map((c) => (
                <tr key={c.id} className="border-b border-slate-50 dark:border-slate-800/60 hover:bg-slate-50 dark:hover:bg-slate-800/40 transition">
                  <td className="px-4 py-2.5 font-mono text-[11px] text-slate-500 dark:text-slate-400">{c.code}</td>
                  <td className="px-4 py-2.5 font-semibold text-slate-800 dark:text-slate-100">
                    <div className="flex items-center gap-1.5 flex-wrap">
                      <span>{c.name}</span>
                      {c.calc_type === 'formula' && (
                        <span className="inline-flex items-center gap-1 text-[9px] font-bold px-1.5 py-0.2 rounded-md bg-purple-50 text-purple-700 dark:bg-purple-950/60 dark:text-purple-300 border border-purple-200/50 dark:border-purple-800/50">
                          <Sigma className="w-2.5 h-2.5" /> DSL
                        </span>
                      )}
                    </div>
                    {c.category && <span className="block text-[10px] text-slate-400 font-normal">{c.category}</span>}
                    {c.calc_type === 'formula' && c.formula_dsl && (
                      <div className="mt-1 font-mono text-[10px] text-purple-700 dark:text-purple-300 bg-purple-50/70 dark:bg-purple-950/40 px-2 py-0.5 rounded border border-purple-200/50 dark:border-purple-800/40 max-w-xs sm:max-w-md truncate" title={c.formula_dsl}>
                        <span className="font-bold mr-1 opacity-70">fx:</span>
                        {c.formula_dsl}
                      </div>
                    )}
                  </td>
                  <td className="px-4 py-2.5 text-center">
                    <span className={`text-[10px] font-bold px-2 py-0.5 rounded-full ${c.type === 'earning' ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300' : 'bg-rose-50 text-rose-700 dark:bg-rose-950/50 dark:text-rose-300'}`}>
                      {c.type === 'earning' ? 'Pendapatan' : 'Potongan'}
                    </span>
                  </td>
                  <td className="px-4 py-2.5 text-center text-slate-500 dark:text-slate-400">
                    {c.calc_type === 'formula' ? (
                      <span className="inline-flex items-center gap-1 text-[10px] font-bold px-2 py-0.5 rounded-full bg-purple-50 text-purple-700 dark:bg-purple-950/50 dark:text-purple-300">
                        Formula
                      </span>
                    ) : c.calc_type === 'fixed' ? (
                      'Tetap'
                    ) : c.calc_type === 'manual' ? (
                      'Manual'
                    ) : (
                      'Otomatis'
                    )}
                  </td>
                  <td className="px-4 py-2.5 text-center">{c.is_taxable ? <Check className="w-4 h-4 text-emerald-500 inline" /> : <span className="text-slate-300">—</span>}</td>
                  <td className="px-4 py-2.5 text-center">
                    <span className={`text-[10px] font-bold ${c.is_active ? 'text-emerald-600 dark:text-emerald-400' : 'text-slate-400'}`}>{c.is_active ? 'Aktif' : 'Nonaktif'}</span>
                  </td>
                  {canManage && (
                    <td className="px-4 py-2.5 text-right whitespace-nowrap">
                      <button onClick={() => { setEditing(c); setShowForm(true); }} className="text-indigo-600 dark:text-indigo-400 hover:text-indigo-700 transition cursor-pointer mr-3" title="Ubah"><Pencil className="w-3.5 h-3.5" /></button>
                      <button onClick={() => setConfirm(c)} className="text-rose-500 hover:text-rose-600 transition cursor-pointer" title="Hapus"><Trash2 className="w-3.5 h-3.5" /></button>
                    </td>
                  )}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {showForm && (
        <ComponentFormModal
          editing={editing}
          availableComponents={rows}
          onClose={() => setShowForm(false)}
          onSaved={(msg) => { setShowForm(false); show('success', msg); load(); }}
          onError={(m) => show('error', m)}
        />
      )}

      {confirm && (
        <ConfirmationDialog
          isOpen onClose={() => setConfirm(null)} onConfirm={() => remove(confirm)}
          title="Hapus Komponen" type="danger" confirmText="Ya, Hapus" isLoading={confirmLoading}
          message={<>Hapus komponen <b>{confirm.name}</b>? Komponen yang sudah dipakai pada slip tidak dapat dihapus.</>}
        />
      )}
    </div>
  );
};

const ComponentFormModal: React.FC<{
  editing: any | null;
  availableComponents?: any[];
  onClose: () => void;
  onSaved: (msg: string) => void;
  onError: (m: string) => void;
}> = ({ editing, availableComponents = [], onClose, onSaved, onError }) => {
  const [code, setCode] = useState(editing?.code ?? '');
  const [name, setName] = useState(editing?.name ?? '');
  const [type, setType] = useState<'earning' | 'deduction'>(editing?.type ?? 'earning');
  const [calcType, setCalcType] = useState<'fixed' | 'manual' | 'auto' | 'formula'>(editing?.calc_type ?? 'fixed');
  const [formulaDsl, setFormulaDsl] = useState<string>(editing?.formula_dsl ?? '');
  const [category, setCategory] = useState(editing?.category ?? '');
  const [isTaxable, setIsTaxable] = useState<boolean>(editing?.is_taxable ?? true);
  const [isActive, setIsActive] = useState<boolean>(editing?.is_active ?? true);
  const [sortOrder, setSortOrder] = useState<number>(editing?.sort_order ?? 0);
  const [saving, setSaving] = useState(false);

  const submit = async () => {
    if (!code.trim() || !name.trim()) { onError('Kode dan nama wajib diisi.'); return; }
    if (calcType === 'formula' && !formulaDsl.trim()) {
      onError('Rumus formula DSL wajib diisi bila menggunakan metode perhitungan formula.');
      return;
    }
    setSaving(true);
    try {
      const payload: any = {
        code: code.trim(),
        name: name.trim(),
        type,
        calc_type: calcType,
        formula_dsl: calcType === 'formula' ? formulaDsl.trim() : null,
        category: category.trim() || undefined,
        is_taxable: isTaxable,
        is_active: isActive,
        sort_order: sortOrder,
      };
      if (editing) { await payrollApi.updateComponent(editing.id, payload); onSaved('Komponen diperbarui.'); }
      else { await payrollApi.createComponent(payload); onSaved('Komponen dibuat.'); }
    } catch (e: any) { onError(errMsg(e, 'Gagal menyimpan komponen.')); setSaving(false); }
  };

  return (
    <Modal
      open onClose={onClose} title={editing ? 'Ubah Komponen Gaji' : 'Komponen Gaji Baru'} icon={<Layers className="w-4 h-4 text-indigo-500" />}
      footer={<>
        <button onClick={onClose} className={btnGhost} disabled={saving}>Batal</button>
        <button onClick={submit} className={btnPrimary} disabled={saving}>{saving ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <Save className="w-3.5 h-3.5" />} Simpan</button>
      </>}
    >
      <div className="space-y-4">
        <div className="grid sm:grid-cols-2 gap-4">
          <Field label="Kode" required hint="Otomatis dijadikan huruf besar, unik per perusahaan.">
            <input value={code} onChange={(e) => setCode(e.target.value)} className={`${inputCls} font-mono`} placeholder="TUNJ_MAKAN" disabled={!!editing} />
          </Field>
          <Field label="Nama" required>
            <input value={name} onChange={(e) => setName(e.target.value)} className={inputCls} placeholder="Tunjangan Makan" />
          </Field>
          <Field label="Tipe" required>
            <select value={type} onChange={(e) => setType(e.target.value as any)} className={inputCls}>
              <option value="earning">Pendapatan (Earning)</option>
              <option value="deduction">Potongan (Deduction)</option>
            </select>
          </Field>
          <Field label="Metode perhitungan" required>
            <select value={calcType} onChange={(e) => setCalcType(e.target.value as any)} className={inputCls}>
              <option value="fixed">Tetap (nominal statis per karyawan)</option>
              <option value="formula">Rumus Dinamis / Formula DSL (Otomatis dievaluasi)</option>
              <option value="manual">Manual (diisi dinamis saat proses)</option>
              <option value="auto">Otomatis (dari sistem presensi/lembur)</option>
            </select>
          </Field>
          <Field label="Kategori (opsional)">
            <input value={category} onChange={(e) => setCategory(e.target.value)} className={inputCls} placeholder="Tunjangan" />
          </Field>
          <Field label="Urutan tampil">
            <input type="number" min={0} max={9999} value={sortOrder} onChange={(e) => setSortOrder(Number(e.target.value))} className={inputCls} />
          </Field>
        </div>

        {/* Editor Formula DSL khusus bila metode perhitungan = formula */}
        {calcType === 'formula' && (
          <div className="pt-1">
            <FormulaDslEditor
              value={formulaDsl}
              onChange={setFormulaDsl}
              componentCode={code}
              availableComponents={availableComponents}
            />
          </div>
        )}

        <div className="flex flex-wrap gap-4 pt-1 border-t border-slate-100 dark:border-slate-800">
          <label className="flex items-center gap-2 text-xs cursor-pointer">
            <input type="checkbox" checked={isTaxable} onChange={(e) => setIsTaxable(e.target.checked)} className="accent-indigo-600 w-4 h-4" />
            <span className="text-slate-600 dark:text-slate-300">Kena pajak (masuk dasar bruto PPh21)</span>
          </label>
          <label className="flex items-center gap-2 text-xs cursor-pointer">
            <input type="checkbox" checked={isActive} onChange={(e) => setIsActive(e.target.checked)} className="accent-indigo-600 w-4 h-4" />
            <span className="text-slate-600 dark:text-slate-300">Aktif</span>
          </label>
        </div>
      </div>
    </Modal>
  );
};

// ═══════════════════════════════════════════════════════════════
// TAB 4 — Kasbon / Pinjaman
// ═══════════════════════════════════════════════════════════════
const KasbonTab: React.FC<{ canManage: boolean }> = ({ canManage }) => {
  const { banner, show, clear } = useBanner();
  const [rows, setRows] = useState<any[]>([]);
  const [employees, setEmployees] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [showForm, setShowForm] = useState(false);
  const [confirm, setConfirm] = useState<any | null>(null);
  const [confirmLoading, setConfirmLoading] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const res = await payrollApi.listLoans();
      setRows(res?.data ?? []);
    } catch (e: any) { show('error', errMsg(e, 'Gagal memuat kasbon.')); }
    finally { setLoading(false); }
  }, [show]);

  useEffect(() => { load(); }, [load]);
  useEffect(() => {
    payrollApi.listSalaries().then((res: any) => setEmployees(res?.data ?? [])).catch(() => {});
  }, []);

  const runConfirmed = async () => {
    if (!confirm) return;
    setConfirmLoading(true);
    try {
      await confirm.run();
      setConfirm(null);
      show('success', confirm.success);
      await load();
    } catch (e: any) { setConfirm(null); show('error', errMsg(e)); }
    finally { setConfirmLoading(false); }
  };

  return (
    <div className="space-y-4">
      <Banner banner={banner} onClose={clear} />
      <div className="flex items-center justify-between gap-3 flex-wrap">
        <p className="text-xs text-slate-500 dark:text-slate-400">Kasbon karyawan dengan cicilan otomatis dipotong saat payroll ditandai dibayar.</p>
        <div className="flex gap-2">
          <button onClick={load} className={btnGhost}><RefreshCw className="w-3.5 h-3.5" /> Muat ulang</button>
          {canManage && <button onClick={() => setShowForm(true)} className={btnPrimary}><Plus className="w-3.5 h-3.5" /> Kasbon Baru</button>}
        </div>
      </div>

      {loading ? <Spinner /> : rows.length === 0 ? (
        <EmptyState icon={<HandCoins className="w-6 h-6" />} title="Belum ada kasbon" subtitle="Ajukan kasbon karyawan; cicilan akan otomatis terpotong tiap periode." />
      ) : (
        <div className="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 overflow-hidden overflow-x-auto">
          <table className="w-full text-xs">
            <thead>
              <tr className="text-left text-slate-400 border-b border-slate-100 dark:border-slate-800">
                <th className="px-4 py-2.5 font-semibold">Karyawan</th>
                <th className="px-4 py-2.5 font-semibold">Keperluan</th>
                <th className="px-4 py-2.5 font-semibold text-right">Pokok</th>
                <th className="px-4 py-2.5 font-semibold text-right">Cicilan</th>
                <th className="px-4 py-2.5 font-semibold text-right">Sisa</th>
                <th className="px-4 py-2.5 font-semibold text-center">Status</th>
                {canManage && <th className="px-4 py-2.5 font-semibold text-right"></th>}
              </tr>
            </thead>
            <tbody>
              {rows.map((l) => {
                const st = LOAN_STATUS[l.status] ?? LOAN_STATUS.pending;
                return (
                  <tr key={l.id} className="border-b border-slate-50 dark:border-slate-800/60 hover:bg-slate-50 dark:hover:bg-slate-800/40 transition">
                    <td className="px-4 py-2.5">
                      <p className="font-semibold text-slate-800 dark:text-slate-100">{l.user?.name ?? '—'}</p>
                      {l.user?.employee_code && <p className="text-[10px] text-slate-400">{l.user.employee_code}</p>}
                    </td>
                    <td className="px-4 py-2.5 text-slate-600 dark:text-slate-400">
                      {l.title || '—'}
                      <span className="block text-[10px] text-slate-400">Mulai {monthName(l.start_period_month)} {l.start_period_year} · {l.installments_paid}/{l.tenor_months} cicilan</span>
                    </td>
                    <td className="px-4 py-2.5 text-right text-slate-700 dark:text-slate-300">{formatCurrency(l.principal)}</td>
                    <td className="px-4 py-2.5 text-right text-slate-700 dark:text-slate-300">{formatCurrency(l.installment_amount)}</td>
                    <td className="px-4 py-2.5 text-right font-semibold text-slate-800 dark:text-slate-100">{formatCurrency(l.remaining_amount)}</td>
                    <td className="px-4 py-2.5 text-center"><span className={`text-[10px] font-bold px-2 py-0.5 rounded-full ${st.cls}`}>{st.label}</span></td>
                    {canManage && (
                      <td className="px-4 py-2.5 text-right whitespace-nowrap">
                        {l.status === 'pending' && (
                          <button
                            onClick={() => setConfirm({ title: 'Setujui Kasbon', type: 'success', confirmText: 'Ya, Setujui', success: 'Kasbon disetujui & aktif.', message: <>Setujui kasbon <b>{l.user?.name}</b> sebesar {formatCurrency(l.principal)}? Cicilan akan mulai dipotong pada periode yang ditentukan.</>, run: async () => { await payrollApi.approveLoan(l.id); } })}
                            className="text-emerald-600 dark:text-emerald-400 hover:underline font-semibold cursor-pointer mr-3">Setujui</button>
                        )}
                        {(l.status === 'pending' || l.status === 'active') && (
                          <button
                            onClick={() => setConfirm({ title: 'Batalkan Kasbon', type: 'danger', confirmText: 'Ya, Batalkan', success: 'Kasbon dibatalkan.', message: <>Batalkan kasbon <b>{l.user?.name}</b>? Cicilan tidak akan dipotong lagi.</>, run: async () => { await payrollApi.cancelLoan(l.id); } })}
                            className="text-rose-500 hover:underline font-semibold cursor-pointer">Batalkan</button>
                        )}
                      </td>
                    )}
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}

      {showForm && (
        <LoanFormModal
          employees={employees}
          onClose={() => setShowForm(false)}
          onSaved={() => { setShowForm(false); show('success', 'Kasbon dibuat (menunggu persetujuan).'); load(); }}
          onError={(m) => show('error', m)}
        />
      )}

      {confirm && (
        <ConfirmationDialog
          isOpen onClose={() => setConfirm(null)} onConfirm={runConfirmed}
          title={confirm.title} message={confirm.message} type={confirm.type} confirmText={confirm.confirmText} isLoading={confirmLoading}
        />
      )}
    </div>
  );
};

const LoanFormModal: React.FC<{
  employees: any[]; onClose: () => void; onSaved: () => void; onError: (m: string) => void;
}> = ({ employees, onClose, onSaved, onError }) => {
  const now = new Date();
  const [userId, setUserId] = useState('');
  const [title, setTitle] = useState('');
  const [principal, setPrincipal] = useState('');
  const [installment, setInstallment] = useState('');
  const [tenor, setTenor] = useState('');
  const [startMonth, setStartMonth] = useState<number>(now.getMonth() + 1);
  const [startYear, setStartYear] = useState<number>(now.getFullYear());
  const [notes, setNotes] = useState('');
  const [saving, setSaving] = useState(false);

  // Bantu isi cicilan otomatis dari pokok/tenor.
  const suggestInstallment = () => {
    const p = Number(principal), t = Number(tenor);
    if (p > 0 && t > 0) setInstallment(String(Math.ceil(p / t)));
  };

  const submit = async () => {
    if (!userId) { onError('Pilih karyawan.'); return; }
    if (Number(principal) < 1 || Number(installment) < 1 || Number(tenor) < 1) { onError('Pokok, cicilan, dan tenor wajib diisi.'); return; }
    if (Number(installment) > Number(principal)) { onError('Cicilan tidak boleh melebihi pokok pinjaman.'); return; }
    setSaving(true);
    try {
      await payrollApi.createLoan({
        user_id: Number(userId), title: title.trim() || 'Kasbon',
        principal: Number(principal), installment_amount: Number(installment), tenor_months: Number(tenor),
        start_period_month: startMonth, start_period_year: startYear, notes: notes.trim() || undefined,
      });
      onSaved();
    } catch (e: any) { onError(errMsg(e, 'Gagal membuat kasbon.')); setSaving(false); }
  };

  return (
    <Modal
      open onClose={onClose} title="Kasbon Baru" icon={<HandCoins className="w-4 h-4 text-indigo-500" />}
      footer={<>
        <button onClick={onClose} className={btnGhost} disabled={saving}>Batal</button>
        <button onClick={submit} className={btnPrimary} disabled={saving}>{saving ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <Check className="w-3.5 h-3.5" />} Buat Kasbon</button>
      </>}
    >
      <Field label="Karyawan" required>
        <select value={userId} onChange={(e) => setUserId(e.target.value)} className={inputCls}>
          <option value="">Pilih karyawan...</option>
          {employees.map((e) => <option key={e.id} value={e.id}>{e.name}{e.employee_code ? ` (${e.employee_code})` : ''}</option>)}
        </select>
      </Field>
      <Field label="Keperluan (opsional)">
        <input value={title} onChange={(e) => setTitle(e.target.value)} className={inputCls} placeholder="Contoh: Kasbon renovasi rumah" />
      </Field>
      <div className="grid sm:grid-cols-3 gap-3">
        <Field label="Pokok (Rp)" required>
          <input type="number" min={1} value={principal} onChange={(e) => setPrincipal(e.target.value)} onBlur={suggestInstallment} className={inputCls} placeholder="0" />
          {principal && <p className="text-[11px] text-indigo-500 font-medium">{formatCurrency(principal)}</p>}
        </Field>
        <Field label="Tenor (bulan)" required>
          <input type="number" min={1} max={120} value={tenor} onChange={(e) => setTenor(e.target.value)} onBlur={suggestInstallment} className={inputCls} placeholder="0" />
        </Field>
        <Field label="Cicilan / bulan (Rp)" required>
          <input type="number" min={1} value={installment} onChange={(e) => setInstallment(e.target.value)} className={inputCls} placeholder="0" />
          {installment && <p className="text-[11px] text-indigo-500 font-medium">{formatCurrency(installment)}</p>}
        </Field>
      </div>
      <div className="grid grid-cols-2 gap-3">
        <Field label="Mulai potong bulan" required>
          <select value={startMonth} onChange={(e) => setStartMonth(Number(e.target.value))} className={inputCls}>
            {MONTHS_ID.map((m, i) => <option key={i} value={i + 1}>{m}</option>)}
          </select>
        </Field>
        <Field label="Tahun" required>
          <input type="number" min={2020} max={2100} value={startYear} onChange={(e) => setStartYear(Number(e.target.value))} className={inputCls} />
        </Field>
      </div>
      <Field label="Catatan (opsional)">
        <textarea value={notes} onChange={(e) => setNotes(e.target.value)} rows={2} maxLength={1000} className={inputCls} />
      </Field>
    </Modal>
  );
};

// ═══════════════════════════════════════════════════════════════
// TAB — Penyesuaian Gaji & Koreksi Retroaktif (Maker-Checker)
// ═══════════════════════════════════════════════════════════════
const PenyesuaianTab: React.FC<{ canManage: boolean }> = ({ canManage }) => {
  const { user } = useAuth();
  const { banner, show, clear } = useBanner();
  const [rows, setRows] = useState<PayrollAdjustment[]>([]);
  const [loading, setLoading] = useState(true);
  const [statusFilter, setStatusFilter] = useState<string>('all');
  const [search, setSearch] = useState('');
  const [employees, setEmployees] = useState<any[]>([]);
  const [runs, setRuns] = useState<any[]>([]);

  // Modals
  const [showCreate, setShowCreate] = useState(false);
  const [voidTarget, setVoidTarget] = useState<PayrollAdjustment | null>(null);

  // Confirm dialog
  const [confirm, setConfirm] = useState<{
    title: string; message: React.ReactNode; type: ConfirmationType; confirmText: string; success: string; run: () => Promise<void>;
  } | null>(null);
  const [confirmLoading, setConfirmLoading] = useState(false);

  const runConfirmed = async () => {
    if (!confirm) return;
    setConfirmLoading(true);
    try {
      await confirm.run();
      show('success', confirm.success);
      setConfirm(null);
      await load();
    } catch (e: any) {
      show('error', errMsg(e, 'Aksi gagal diproses.'));
    } finally {
      setConfirmLoading(false);
    }
  };

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const [adjRes, salRes, runsRes] = await Promise.all([
        payrollApi.listAdjustments(statusFilter !== 'all' ? { status: statusFilter } : undefined),
        payrollApi.listSalaries().catch(() => ({ data: [] })),
        payrollApi.listRuns().catch(() => ({ data: [] })),
      ]);
      setRows(adjRes?.data ?? []);
      setEmployees(salRes?.data ?? []);
      setRuns(runsRes?.data ?? []);
    } catch (e: any) {
      show('error', errMsg(e, 'Gagal memuat daftar penyesuaian.'));
    } finally {
      setLoading(false);
    }
  }, [statusFilter, show]);

  useEffect(() => { load(); }, [load]);

  const filtered = useMemo(() => {
    const q = search.trim().toLowerCase();
    if (!q) return rows;
    return rows.filter((r) =>
      `${r.user?.name ?? ''} ${r.user?.employee_code ?? ''} ${r.name} ${r.reason ?? ''}`.toLowerCase().includes(q)
    );
  }, [rows, search]);

  const handleApprove = (adj: PayrollAdjustment) => {
    if (Number(adj.created_by) === Number(user?.id)) {
      show('error', 'Prinsip Maker-Checker: Anda tidak dapat menyetujui penyesuaian yang Anda buat sendiri.');
      return;
    }
    setConfirm({
      title: 'Setujui Penyesuaian',
      type: 'success',
      confirmText: 'Ya, Setujui',
      success: `Penyesuaian "${adj.name}" disetujui.`,
      message: (
        <>
          Setujui penyesuaian <b>{adj.name}</b> untuk <b>{adj.user?.name}</b> sebesar{' '}
          <b>{formatCurrency(adj.amount)}</b> ({adj.type === 'earning' ? 'Pendapatan' : 'Potongan'})?
          Penyesuaian ini akan otomatis diikutsertakan saat kalkulasi batch payroll.
        </>
      ),
      run: async () => {
        await payrollApi.approveAdjustment(adj.id);
      },
    });
  };

  return (
    <div className="space-y-4">
      <Banner banner={banner} onClose={clear} />

      {/* Info Maker-Checker Callout */}
      <div className="rounded-xl border border-indigo-100 dark:border-indigo-900/40 bg-indigo-50/40 dark:bg-indigo-950/20 p-3.5 text-xs text-indigo-900 dark:text-indigo-300">
        <p className="font-semibold flex items-center gap-1.5">
          <SlidersHorizontal className="w-4 h-4 text-indigo-600 dark:text-indigo-400" />
          Penyesuaian Gaji & Koreksi Retroaktif
        </p>
        <p className="text-[11px] text-indigo-700/80 dark:text-indigo-400/80 mt-1 leading-relaxed">
          Gunakan penyesuaian untuk penambahan bonus/rapel atau pemotongan khusus di luar komponen tetap. Seluruh pengajuan menerapkan alur maker-checker (persetujuan oleh pihak berbeda) dan jejak audit pembatalan (void).
        </p>
      </div>

      {/* Toolbar & Filter */}
      <div className="flex items-center justify-between gap-3 flex-wrap">
        <div className="flex items-center gap-2 flex-wrap">
          <div className="relative w-64">
            <input
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="Cari karyawan / nama penyesuaian..."
              className={`${inputCls} pl-9`}
            />
            <Users className="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" />
          </div>

          {/* Status Filter Tabs */}
          <div className="flex gap-1 rounded-xl bg-slate-100 dark:bg-slate-800 p-1 text-xs font-semibold">
            {[
              { key: 'all', label: 'Semua' },
              { key: 'pending', label: 'Menunggu' },
              { key: 'approved', label: 'Disetujui' },
              { key: 'applied', label: 'Diterapkan' },
              { key: 'voided', label: 'Dibatalkan' },
            ].map((f) => (
              <button
                key={f.key}
                onClick={() => setStatusFilter(f.key)}
                className={`rounded-lg px-2.5 py-1 transition cursor-pointer ${
                  statusFilter === f.key
                    ? 'bg-white dark:bg-slate-900 text-indigo-600 dark:text-indigo-400 shadow-sm'
                    : 'text-slate-500 hover:text-slate-700 dark:hover:text-slate-300'
                }`}
              >
                {f.label}
              </button>
            ))}
          </div>
        </div>

        <div className="flex items-center gap-2">
          <button onClick={load} className={btnGhost}><RefreshCw className="w-3.5 h-3.5" /> Muat Ulang</button>
          {canManage && (
            <button onClick={() => setShowCreate(true)} className={btnPrimary}>
              <Plus className="w-3.5 h-3.5" /> Penyesuaian Baru
            </button>
          )}
        </div>
      </div>

      {/* Tabel Penyesuaian */}
      {loading ? <Spinner /> : filtered.length === 0 ? (
        <EmptyState
          icon={<SlidersHorizontal className="w-6 h-6" />}
          title="Tidak ada penyesuaian"
          subtitle="Belum ada penyesuaian gaji pada kriteria filter ini."
        />
      ) : (
        <div className="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 overflow-hidden overflow-x-auto">
          <table className="w-full text-xs">
            <thead>
              <tr className="text-left text-slate-400 border-b border-slate-100 dark:border-slate-800">
                <th className="px-4 py-3 font-semibold">Karyawan</th>
                <th className="px-4 py-3 font-semibold">Penyesuaian</th>
                <th className="px-4 py-3 font-semibold text-right">Nominal</th>
                <th className="px-4 py-3 font-semibold">Batch / Retroaktif</th>
                <th className="px-4 py-3 font-semibold">Pembuat & Penyetuju</th>
                <th className="px-4 py-3 font-semibold text-center">Status</th>
                {canManage && <th className="px-4 py-3 font-semibold text-right">Aksi</th>}
              </tr>
            </thead>
            <tbody>
              {filtered.map((adj) => {
                const st = ADJUSTMENT_STATUS[adj.status] ?? ADJUSTMENT_STATUS.pending;
                const isMaker = Number(adj.created_by) === Number(user?.id);
                const isEarning = adj.type === 'earning';

                return (
                  <tr key={adj.id} className="border-b border-slate-50 dark:border-slate-800/60 hover:bg-slate-50 dark:hover:bg-slate-800/40 transition">
                    <td className="px-4 py-3">
                      <p className="font-semibold text-slate-800 dark:text-slate-100">{adj.user?.name ?? '—'}</p>
                      {adj.user?.employee_code && <p className="text-[10px] text-slate-400">{adj.user.employee_code}</p>}
                    </td>
                    <td className="px-4 py-3">
                      <div className="flex items-center gap-1.5 flex-wrap">
                        <span className="font-semibold text-slate-800 dark:text-slate-100">{adj.name}</span>
                        <span className={`text-[10px] font-bold px-1.5 py-0.5 rounded ${isEarning ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300' : 'bg-rose-50 text-rose-700 dark:bg-rose-950/50 dark:text-rose-300'}`}>
                          {isEarning ? 'Pendapatan' : 'Potongan'}
                        </span>
                        {isEarning && adj.is_taxable && (
                          <span className="text-[10px] font-medium px-1.5 py-0.5 rounded bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300">
                            PPh21
                          </span>
                        )}
                      </div>
                      {adj.reason && <p className="text-[10px] text-slate-400 mt-0.5 line-clamp-1">Alasan: {adj.reason}</p>}
                      {adj.void_reason && <p className="text-[10px] text-rose-500 font-medium mt-0.5">Alasan Batal: {adj.void_reason}</p>}
                    </td>
                    <td className="px-4 py-3 text-right">
                      <span className={`font-semibold ${isEarning ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400'}`}>
                        {isEarning ? '+' : '-'} {formatCurrency(adj.amount)}
                      </span>
                    </td>
                    <td className="px-4 py-3">
                      {adj.payroll_id ? (
                        <span className="inline-flex items-center gap-1 font-mono text-[11px] text-indigo-600 dark:text-indigo-400">
                          <CheckCircle2 className="w-3 h-3" /> Batch #{adj.payroll_id}
                        </span>
                      ) : adj.retroactive_payroll_id ? (
                        <span className="inline-flex items-center gap-1 font-mono text-[11px] text-amber-600 dark:text-amber-400">
                          <History className="w-3 h-3" /> Retroaktif Batch #{adj.retroactive_payroll_id}
                        </span>
                      ) : (
                        <span className="text-slate-400 text-[11px]">Batch Mendatang</span>
                      )}
                    </td>
                    <td className="px-4 py-3 space-y-0.5 text-[11px]">
                      <p className="text-slate-600 dark:text-slate-300">
                        Oleh: {adj.createdBy?.name || 'Sistem'} · {formatDate(adj.created_at)}
                      </p>
                      {adj.approvedBy && (
                        <p className="text-emerald-600 dark:text-emerald-400">
                          Disetujui: {adj.approvedBy.name} · {formatDate(adj.approved_at)}
                        </p>
                      )}
                      {adj.status === 'voided' && adj.voided_at && (
                        <p className="text-rose-500">
                          Dibatalkan: {formatDate(adj.voided_at)}
                        </p>
                      )}
                    </td>
                    <td className="px-4 py-3 text-center">
                      <span className={`inline-flex items-center gap-1.5 text-[10px] font-bold px-2 py-0.5 rounded-full ${st.cls}`}>
                        <span className={`w-1.5 h-1.5 rounded-full ${st.dot}`} />
                        {st.label}
                      </span>
                    </td>
                    {canManage && (
                      <td className="px-4 py-3 text-right whitespace-nowrap space-x-2">
                        {adj.status === 'pending' && (
                          <>
                            {isMaker ? (
                              <span className="text-[10px] font-medium text-amber-600 dark:text-amber-400 bg-amber-50 dark:bg-amber-950/40 px-2 py-1 rounded-md" title="Maker-checker: dibuat oleh akun Anda">
                                Menunggu Reviewer Lain
                              </span>
                            ) : (
                              <button
                                onClick={() => handleApprove(adj)}
                                className="inline-flex items-center gap-1 text-emerald-600 dark:text-emerald-400 hover:underline font-semibold cursor-pointer"
                              >
                                <Check className="w-3.5 h-3.5" /> Setujui
                              </button>
                            )}
                            <button
                              onClick={() => setVoidTarget(adj)}
                              className="text-rose-500 hover:underline font-semibold cursor-pointer"
                            >
                              Batalkan
                            </button>
                          </>
                        )}
                        {adj.status === 'approved' && (
                          <button
                            onClick={() => setVoidTarget(adj)}
                            className="text-rose-500 hover:underline font-semibold cursor-pointer"
                          >
                            Batalkan (Void)
                          </button>
                        )}
                        {(adj.status === 'applied' || adj.status === 'voided') && (
                          <span className="text-slate-300 dark:text-slate-600">—</span>
                        )}
                      </td>
                    )}
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}

      {/* Modal Tambah Penyesuaian */}
      {showCreate && (
        <CreateAdjustmentModal
          employees={employees}
          runs={runs}
          onClose={() => setShowCreate(false)}
          onSaved={() => {
            setShowCreate(false);
            show('success', 'Penyesuaian baru berhasil diajukan (status: menunggu persetujuan).');
            load();
          }}
          onError={(m) => show('error', m)}
        />
      )}

      {/* Modal Void Penyesuaian */}
      {voidTarget && (
        <VoidAdjustmentModal
          adjustment={voidTarget}
          onClose={() => setVoidTarget(null)}
          onSaved={() => {
            setVoidTarget(null);
            show('success', 'Penyesuaian berhasil dibatalkan (void).');
            load();
          }}
          onError={(m) => show('error', m)}
        />
      )}

      {/* Confirmation Dialog */}
      {confirm && (
        <ConfirmationDialog
          isOpen
          onClose={() => setConfirm(null)}
          onConfirm={runConfirmed}
          title={confirm.title}
          message={confirm.message}
          type={confirm.type}
          confirmText={confirm.confirmText}
          isLoading={confirmLoading}
        />
      )}
    </div>
  );
};

// ── Modal Buat Penyesuaian Gaji Baru ──
const CreateAdjustmentModal: React.FC<{
  employees: any[];
  runs: any[];
  onClose: () => void;
  onSaved: () => void;
  onError: (m: string) => void;
}> = ({ employees, runs, onClose, onSaved, onError }) => {
  const [userId, setUserId] = useState('');
  const [type, setType] = useState<'earning' | 'deduction'>('earning');
  const [name, setName] = useState('');
  const [amount, setAmount] = useState('');
  const [isTaxable, setIsTaxable] = useState(true);
  const [retroactiveRunId, setRetroactiveRunId] = useState('');
  const [reason, setReason] = useState('');
  const [saving, setSaving] = useState(false);

  const submit = async () => {
    if (!userId) { onError('Pilih karyawan terlebih dahulu.'); return; }
    if (!name.trim()) { onError('Nama penyesuaian wajib diisi.'); return; }
    if (!amount || Number(amount) <= 0) { onError('Nominal penyesuaian harus lebih dari 0.'); return; }
    if (!reason.trim()) { onError('Alasan / justifikasi penyesuaian wajib diisi.'); return; }

    setSaving(true);
    try {
      await payrollApi.createAdjustment({
        user_id: Number(userId),
        type,
        name: name.trim(),
        amount: Number(amount),
        is_taxable: type === 'earning' ? isTaxable : false,
        retroactive_payroll_id: retroactiveRunId ? Number(retroactiveRunId) : undefined,
        reason: reason.trim(),
      });
      onSaved();
    } catch (e: any) {
      onError(errMsg(e, 'Gagal membuat penyesuaian.'));
      setSaving(false);
    }
  };

  return (
    <Modal
      open onClose={onClose} title="Buat Penyesuaian Gaji" icon={<SlidersHorizontal className="w-4 h-4 text-indigo-500" />}
      footer={<>
        <button onClick={onClose} className={btnGhost} disabled={saving}>Batal</button>
        <button onClick={submit} className={btnPrimary} disabled={saving}>
          {saving ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <Send className="w-3.5 h-3.5" />} Ajukan Penyesuaian
        </button>
      </>}
    >
      <Field label="Karyawan" required>
        <select value={userId} onChange={(e) => setUserId(e.target.value)} className={inputCls}>
          <option value="">Pilih karyawan...</option>
          {employees.map((e) => (
            <option key={e.id} value={e.id}>{e.name}{e.employee_code ? ` (${e.employee_code})` : ''}</option>
          ))}
        </select>
      </Field>

      <Field label="Tipe Penyesuaian" required>
        <div className="grid grid-cols-2 gap-2">
          <button
            type="button"
            onClick={() => setType('earning')}
            className={`rounded-xl py-2 px-3 text-xs font-semibold border transition cursor-pointer ${
              type === 'earning'
                ? 'bg-emerald-50 dark:bg-emerald-950/40 border-emerald-500 text-emerald-700 dark:text-emerald-300 shadow-sm'
                : 'bg-white dark:bg-slate-900 border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-400'
            }`}
          >
            + Pendapatan (Earning)
          </button>
          <button
            type="button"
            onClick={() => setType('deduction')}
            className={`rounded-xl py-2 px-3 text-xs font-semibold border transition cursor-pointer ${
              type === 'deduction'
                ? 'bg-rose-50 dark:bg-rose-950/40 border-rose-500 text-rose-700 dark:text-rose-300 shadow-sm'
                : 'bg-white dark:bg-slate-900 border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-400'
            }`}
          >
            - Potongan (Deduction)
          </button>
        </div>
      </Field>

      <div className="grid sm:grid-cols-2 gap-3">
        <Field label="Nama Penyesuaian" required hint="Contoh: Bonus Q3, Rapel Kenaikan, Koreksi Lembur">
          <input value={name} onChange={(e) => setName(e.target.value)} className={inputCls} placeholder="Contoh: Bonus Kinerja" />
        </Field>

        <Field label="Nominal (Rp)" required>
          <input type="number" min={1} value={amount} onChange={(e) => setAmount(e.target.value)} className={inputCls} placeholder="0" />
          {amount && <p className="text-[11px] text-indigo-500 font-medium">{formatCurrency(amount)}</p>}
        </Field>
      </div>

      {type === 'earning' && (
        <div className="rounded-xl border border-slate-200 dark:border-slate-800 p-3 bg-slate-50/50 dark:bg-slate-800/30">
          <label className="flex items-center gap-2.5 cursor-pointer">
            <input
              type="checkbox"
              checked={isTaxable}
              onChange={(e) => setIsTaxable(e.target.checked)}
              className="w-4 h-4 rounded text-indigo-600 focus:ring-indigo-500 border-slate-300"
            />
            <div>
              <p className="text-xs font-bold text-slate-800 dark:text-slate-100">Kena Pajak PPh 21</p>
              <p className="text-[11px] text-slate-400">Tambahkan nominal penyesuaian ini ke dasar bruto pengenaan PPh 21.</p>
            </div>
          </label>
        </div>
      )}

      <Field label="Tautkan ke Batch Lalu (Koreksi Retroaktif - opsional)" hint="Kosongkan bila penyesuaian ini untuk batch payroll berikutnya.">
        <select value={retroactiveRunId} onChange={(e) => setRetroactiveRunId(e.target.value)} className={inputCls}>
          <option value="">Bukan koreksi retroaktif (Batch Mendatang)</option>
          {runs.map((r) => (
            <option key={r.id} value={r.id}>
              Batch #{r.id} · {monthName(r.period_month)} {r.period_year} ({r.title})
            </option>
          ))}
        </select>
      </Field>

      <Field label="Alasan / Justifikasi" required hint="Catatan audit wajib diisi untuk transparansi kepatuhan finansial.">
        <textarea
          value={reason}
          onChange={(e) => setReason(e.target.value)}
          rows={3}
          maxLength={1000}
          className={inputCls}
          placeholder="Jelaskan dasar penyesuaian atau nomor dokumen referensi..."
        />
      </Field>
    </Modal>
  );
};

// ── Modal Void Penyesuaian ──
const VoidAdjustmentModal: React.FC<{
  adjustment: PayrollAdjustment;
  onClose: () => void;
  onSaved: () => void;
  onError: (m: string) => void;
}> = ({ adjustment, onClose, onSaved, onError }) => {
  const [reason, setReason] = useState('');
  const [saving, setSaving] = useState(false);

  const submit = async () => {
    if (reason.trim().length < 3) {
      onError('Alasan pembatalan minimal 3 karakter.');
      return;
    }
    setSaving(true);
    try {
      await payrollApi.voidAdjustment(adjustment.id, reason.trim());
      onSaved();
    } catch (e: any) {
      onError(errMsg(e, 'Gagal membatalkan penyesuaian.'));
      setSaving(false);
    }
  };

  return (
    <Modal
      open onClose={onClose} title="Batalkan Penyesuaian (Void)" icon={<XCircle className="w-4 h-4 text-rose-500" />} maxW="max-w-md"
      footer={<>
        <button onClick={onClose} className={btnGhost} disabled={saving}>Kembali</button>
        <button onClick={submit} className={`${btnPrimary} bg-rose-600 hover:bg-rose-700 shadow-rose-500/20`} disabled={saving}>
          {saving ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <XCircle className="w-3.5 h-3.5" />} Batalkan Penyesuaian
        </button>
      </>}
    >
      <p className="text-xs text-slate-600 dark:text-slate-300 mb-3">
        Anda akan membatalkan penyesuaian <b>{adjustment.name}</b> untuk <b>{adjustment.user?.name}</b> sebesar <b>{formatCurrency(adjustment.amount)}</b>.
      </p>
      <Field label="Alasan Pembatalan (Void)" required hint="Alasan akan disimpan permanen pada rantai jejak audit.">
        <textarea
          value={reason}
          onChange={(e) => setReason(e.target.value)}
          rows={3}
          maxLength={1000}
          className={inputCls}
          placeholder="Contoh: Kesalahan input nominal, dibatalkan atas arahan manajer..."
          autoFocus
        />
      </Field>
    </Modal>
  );
};

// ── Modal PIN Keamanan Pengguna (Step-Up Rollout) ──
const SecurityPinModal: React.FC<{
  onClose: () => void;
  onSaved: (hasPin: boolean) => void;
  onError: (m: string) => void;
}> = ({ onClose, onSaved, onError }) => {
  const [status, setStatus] = useState<PinStatus | null>(null);
  const [loading, setLoading] = useState(true);
  const [currentPassword, setCurrentPassword] = useState('');
  const [currentPin, setCurrentPin] = useState('');
  const [pin, setPin] = useState('');
  const [pinConfirmation, setPinConfirmation] = useState('');
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    let active = true;
    payrollApi.getPinStatus()
      .then((res: any) => { if (active) setStatus(res?.data ?? null); })
      .catch((e: any) => onError(errMsg(e, 'Gagal memuat status PIN.')))
      .finally(() => { if (active) setLoading(false); });
    return () => { active = false; };
  }, [onError]);

  const submit = async () => {
    if (!currentPassword) { onError('Masukkan kata sandi login akun Anda.'); return; }
    if (status?.has_pin && !currentPin) { onError('Masukkan PIN keamanan saat ini.'); return; }
    if (!/^\d{4,6}$/.test(pin)) { onError('PIN baru harus berupa 4–6 digit angka.'); return; }
    if (pin !== pinConfirmation) { onError('Konfirmasi PIN baru tidak cocok.'); return; }

    setSaving(true);
    try {
      await payrollApi.setPin({
        current_password: currentPassword,
        current_pin: status?.has_pin ? currentPin : undefined,
        pin,
        pin_confirmation: pinConfirmation,
      });
      onSaved(true);
    } catch (e: any) {
      onError(errMsg(e, 'Gagal menyimpan PIN keamanan.'));
      setSaving(false);
    }
  };

  return (
    <Modal
      open onClose={onClose} title={status?.has_pin ? 'Ubah PIN Keamanan' : 'Atur PIN Keamanan'}
      icon={<KeyRound className="w-4 h-4 text-emerald-500" />} maxW="max-w-md"
      footer={<>
        <button onClick={onClose} className={btnGhost} disabled={saving}>Batal</button>
        <button onClick={submit} className={btnPrimary} disabled={saving || loading}>
          {saving ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <Save className="w-3.5 h-3.5" />}
          {status?.has_pin ? 'Simpan PIN Baru' : 'Aktifkan PIN'}
        </button>
      </>}
    >
      {loading ? <Spinner label="Memeriksa status PIN..." /> : (
        <div className="space-y-4">
          <div className="rounded-xl border border-emerald-200 dark:border-emerald-900/50 bg-emerald-50/40 dark:bg-emerald-950/20 p-3.5 text-xs text-emerald-900 dark:text-emerald-300">
            <p className="font-semibold flex items-center gap-1.5">
              <ShieldCheck className="w-4 h-4 text-emerald-600 dark:text-emerald-400" />
              Otorisasi Step-up Payroll
            </p>
            <p className="text-[11px] text-emerald-700/80 dark:text-emerald-400/80 mt-1 leading-relaxed">
              PIN Keamanan (4–6 digit) wajib dimasukkan saat melakukan tindakan finansial berisiko tinggi seperti persetujuan final (Approve) dan tanda pencairan gaji (Mark Paid).
            </p>
          </div>

          <Field label="Kata Sandi Akun" required hint="Konfirmasi identitas Anda menggunakan kata sandi login.">
            <input
              type="password"
              value={currentPassword}
              onChange={(e) => setCurrentPassword(e.target.value)}
              className={inputCls}
              placeholder="Kata sandi akun"
              autoFocus
            />
          </Field>

          {status?.has_pin && (
            <Field label="PIN Keamanan Saat Ini" required>
              <input
                type="password"
                maxLength={6}
                value={currentPin}
                onChange={(e) => setCurrentPin(e.target.value)}
                className={inputCls}
                placeholder="4–6 digit angka"
                inputMode="numeric"
              />
            </Field>
          )}

          <div className="grid grid-cols-2 gap-3">
            <Field label="PIN Baru" required hint="4–6 digit angka">
              <input
                type="password"
                maxLength={6}
                value={pin}
                onChange={(e) => setPin(e.target.value)}
                className={inputCls}
                placeholder="PIN Baru"
                inputMode="numeric"
              />
            </Field>
            <Field label="Konfirmasi PIN" required hint="Ulangi PIN baru">
              <input
                type="password"
                maxLength={6}
                value={pinConfirmation}
                onChange={(e) => setPinConfirmation(e.target.value)}
                className={inputCls}
                placeholder="Konfirmasi PIN"
                inputMode="numeric"
              />
            </Field>
          </div>
        </div>
      )}
    </Modal>
  );
};

// ═══════════════════════════════════════════════════════════════
// Fase 4+: Pengaturan Payroll (Grup Payroll & Profil Pajak NPWP)
// ═══════════════════════════════════════════════════════════════

const GroupFormModal: React.FC<{
  editing: PayrollGroup | null;
  onClose: () => void;
  onSaved: (msg: string) => void;
  onError: (m: string) => void;
}> = ({ editing, onClose, onSaved, onError }) => {
  const [name, setName] = useState(editing?.name ?? '');
  const [code, setCode] = useState(editing?.code ?? '');
  const [description, setDescription] = useState(editing?.description ?? '');
  const [isActive, setIsActive] = useState<boolean>(editing?.is_active ?? true);
  const [saving, setSaving] = useState(false);

  const submit = async () => {
    if (!name.trim()) {
      onError('Nama grup payroll wajib diisi.');
      return;
    }
    setSaving(true);
    try {
      const payload = {
        name: name.trim(),
        code: code.trim() ? code.trim().toUpperCase() : undefined,
        description: description.trim() || undefined,
        is_active: isActive,
      };
      if (editing) {
        await payrollApi.updateGroup(editing.id, payload);
        onSaved('Grup payroll berhasil diperbarui.');
      } else {
        await payrollApi.createGroup(payload);
        onSaved('Grup payroll berhasil dibuat.');
      }
    } catch (e: any) {
      onError(errMsg(e, 'Gagal menyimpan grup payroll.'));
      setSaving(false);
    }
  };

  return (
    <Modal
      open
      onClose={onClose}
      title={editing ? 'Ubah Grup Payroll' : 'Grup Payroll Baru'}
      icon={<Users className="w-4 h-4 text-indigo-500" />}
      footer={<>
        <button onClick={onClose} className={btnGhost} disabled={saving}>Batal</button>
        <button onClick={submit} className={btnPrimary} disabled={saving}>
          {saving ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <Save className="w-3.5 h-3.5" />} Simpan
        </button>
      </>}
    >
      <div className="space-y-4">
        <Field label="Nama Grup" required>
          <input
            value={name}
            onChange={(e) => setName(e.target.value)}
            className={inputCls}
            placeholder="Contoh: Staf Operasional / Divisi Pabrik"
            maxLength={120}
          />
        </Field>
        <Field label="Kode (opsional)" hint="Otomatis dijadikan huruf kapital, unik per perusahaan.">
          <input
            value={code}
            onChange={(e) => setCode(e.target.value)}
            className={`${inputCls} font-mono`}
            placeholder="STAF_OPS"
            maxLength={20}
          />
        </Field>
        <Field label="Deskripsi (opsional)">
          <textarea
            value={description}
            onChange={(e) => setDescription(e.target.value)}
            rows={2}
            maxLength={2000}
            className={inputCls}
            placeholder="Keterangan cakupan atau jadwal penggajian untuk grup ini..."
          />
        </Field>
        <label className="flex items-center gap-2 text-xs cursor-pointer">
          <input
            type="checkbox"
            checked={isActive}
            onChange={(e) => setIsActive(e.target.checked)}
            className="accent-indigo-600 w-4 h-4 cursor-pointer"
          />
          <span className="text-slate-700 dark:text-slate-300 font-medium">Aktif (dapat dipilih saat membuat batch payroll)</span>
        </label>
      </div>
    </Modal>
  );
};

const GrupPayrollPanel: React.FC<{ canManage: boolean }> = ({ canManage }) => {
  const { banner, show, clear } = useBanner();
  const [rows, setRows] = useState<PayrollGroup[]>([]);
  const [loading, setLoading] = useState(true);
  const [editing, setEditing] = useState<PayrollGroup | null>(null);
  const [showForm, setShowForm] = useState(false);
  const [confirm, setConfirm] = useState<PayrollGroup | null>(null);
  const [confirmLoading, setConfirmLoading] = useState(false);
  const [deactivateOffer, setDeactivateOffer] = useState<PayrollGroup | null>(null);
  const [deactivateLoading, setDeactivateLoading] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const res = await payrollApi.listGroups();
      setRows(res?.data ?? []);
    } catch (e: any) {
      show('error', errMsg(e, 'Gagal memuat grup payroll.'));
    } finally {
      setLoading(false);
    }
  }, [show]);

  useEffect(() => { load(); }, [load]);

  const remove = async (g: PayrollGroup) => {
    setConfirmLoading(true);
    try {
      await payrollApi.deleteGroup(g.id);
      setConfirm(null);
      show('success', `Grup '${g.name}' berhasil dihapus.`);
      await load();
    } catch (e: any) {
      setConfirm(null);
      const status = e?.response?.status;
      if (status === 422) {
        setDeactivateOffer(g);
        show('error', errMsg(e, 'Grup tidak dapat dihapus karena masih memiliki anggota atau pernah dipakai pada batch payroll.'));
      } else {
        show('error', errMsg(e, 'Gagal menghapus grup payroll.'));
      }
    } finally {
      setConfirmLoading(false);
    }
  };

  const deactivate = async (g: PayrollGroup) => {
    setDeactivateLoading(true);
    try {
      await payrollApi.updateGroup(g.id, {
        name: g.name,
        code: g.code ?? undefined,
        description: g.description ?? undefined,
        is_active: false,
      });
      setDeactivateOffer(null);
      show('success', `Grup '${g.name}' berhasil dinonaktifkan.`);
      await load();
    } catch (e: any) {
      show('error', errMsg(e, 'Gagal menonaktifkan grup payroll.'));
    } finally {
      setDeactivateLoading(false);
    }
  };

  return (
    <div className="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-5 space-y-4">
      <Banner banner={banner} onClose={clear} />

      <div className="flex items-start justify-between gap-3 flex-wrap">
        <div>
          <h3 className="text-sm font-bold text-slate-900 dark:text-white flex items-center gap-2">
            <Users className="w-4 h-4 text-indigo-500" />
            Grup Payroll
          </h3>
          <p className="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
            Kelompokkan karyawan untuk pemrosesan batch payroll bertahap atau terpisah per jadwal/divisi.
          </p>
        </div>
        <div className="flex items-center gap-2">
          <button onClick={load} className={btnGhost} title="Muat ulang"><RefreshCw className="w-3.5 h-3.5" /> Muat ulang</button>
          {canManage && (
            <button onClick={() => { setEditing(null); setShowForm(true); }} className={btnPrimary}>
              <Plus className="w-3.5 h-3.5" /> Grup Baru
            </button>
          )}
        </div>
      </div>

      {loading ? <Spinner /> : rows.length === 0 ? (
        <EmptyState
          icon={<Users className="w-6 h-6" />}
          title="Belum ada grup payroll"
          subtitle={canManage ? 'Klik "Grup Baru" untuk menambahkan pengelompokan karyawan.' : 'Belum ada grup payroll yang didaftarkan.'}
        />
      ) : (
        <div className="rounded-xl border border-slate-200 dark:border-slate-800 overflow-hidden overflow-x-auto">
          <table className="w-full text-xs">
            <thead>
              <tr className="text-left text-slate-400 border-b border-slate-100 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-800/40">
                <th className="px-4 py-2.5 font-semibold">Nama Grup</th>
                <th className="px-4 py-2.5 font-semibold">Kode</th>
                <th className="px-4 py-2.5 font-semibold">Deskripsi</th>
                <th className="px-4 py-2.5 font-semibold text-center">Anggota</th>
                <th className="px-4 py-2.5 font-semibold text-center">Status</th>
                {canManage && <th className="px-4 py-2.5 font-semibold text-right">Aksi</th>}
              </tr>
            </thead>
            <tbody>
              {rows.map((g) => (
                <tr key={g.id} className="border-b border-slate-50 dark:border-slate-800/60 hover:bg-slate-50 dark:hover:bg-slate-800/40 transition">
                  <td className="px-4 py-2.5 font-semibold text-slate-800 dark:text-slate-100">{g.name}</td>
                  <td className="px-4 py-2.5 font-mono text-[11px] text-slate-500 dark:text-slate-400">
                    {g.code ? <span className="px-1.5 py-0.5 rounded bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300">{g.code}</span> : '—'}
                  </td>
                  <td className="px-4 py-2.5 text-slate-500 dark:text-slate-400 max-w-xs truncate">{g.description || '—'}</td>
                  <td className="px-4 py-2.5 text-center">
                    <span className="inline-flex items-center gap-1 font-medium text-slate-700 dark:text-slate-300">
                      <Users className="w-3 h-3 text-slate-400" />
                      {g.users_count ?? 0} orang
                    </span>
                  </td>
                  <td className="px-4 py-2.5 text-center">
                    <span className={`text-[10px] font-bold px-2 py-0.5 rounded-full ${
                      g.is_active
                        ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300'
                        : 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400'
                    }`}>
                      {g.is_active ? 'Aktif' : 'Nonaktif'}
                    </span>
                  </td>
                  {canManage && (
                    <td className="px-4 py-2.5 text-right whitespace-nowrap">
                      <button
                        onClick={() => { setEditing(g); setShowForm(true); }}
                        className="text-indigo-600 dark:text-indigo-400 hover:text-indigo-700 transition cursor-pointer mr-3"
                        title="Ubah"
                      >
                        <Pencil className="w-3.5 h-3.5" />
                      </button>
                      <button
                        onClick={() => setConfirm(g)}
                        className="text-rose-500 hover:text-rose-600 transition cursor-pointer"
                        title="Hapus"
                      >
                        <Trash2 className="w-3.5 h-3.5" />
                      </button>
                    </td>
                  )}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {showForm && (
        <GroupFormModal
          editing={editing}
          onClose={() => setShowForm(false)}
          onSaved={(msg) => { setShowForm(false); show('success', msg); load(); }}
          onError={(m) => show('error', m)}
        />
      )}

      {confirm && (
        <ConfirmationDialog
          isOpen
          onClose={() => setConfirm(null)}
          onConfirm={() => remove(confirm)}
          title="Hapus Grup Payroll"
          type="danger"
          confirmText="Ya, Hapus"
          isLoading={confirmLoading}
          message={<>Hapus grup payroll <b>{confirm.name}</b>? Jika grup masih memiliki anggota atau pernah dipakai pada batch payroll, penghapusan akan dicegah oleh sistem.</>}
        />
      )}

      {deactivateOffer && (
        <ConfirmationDialog
          isOpen
          onClose={() => setDeactivateOffer(null)}
          onConfirm={() => deactivate(deactivateOffer)}
          title="Nonaktifkan Grup Payroll"
          type="warning"
          confirmText="Ya, Nonaktifkan"
          isLoading={deactivateLoading}
          message={<>Grup payroll <b>{deactivateOffer.name}</b> tidak dapat dihapus karena masih memiliki anggota atau pernah dipakai pada batch payroll. Nonaktifkan grup agar tidak dapat dipilih lagi pada batch baru?</>}
        />
      )}
    </div>
  );
};

const ProfilPajakPerusahaanPanel: React.FC<{ canManage: boolean }> = ({ canManage }) => {
  const { banner, show, clear } = useBanner();
  const [profile, setProfile] = useState<CompanyTaxProfile | null>(null);
  const [loading, setLoading] = useState(true);
  const [npwpInput, setNpwpInput] = useState('');
  const [saving, setSaving] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const res = await payrollApi.getCompanyTaxProfile();
      setProfile(res?.data ?? null);
      setNpwpInput('');
    } catch (e: any) {
      show('error', errMsg(e, 'Gagal memuat profil pajak perusahaan.'));
    } finally {
      setLoading(false);
    }
  }, [show]);

  useEffect(() => { load(); }, [load]);

  const handleSaveNpwp = async () => {
    const raw = npwpInput.trim();
    const digits = raw.replace(/\D/g, '');

    // Validasi klien: jika diisi, harus 15 atau 16 digit. Kosong = hapus.
    if (raw !== '' && digits.length !== 15 && digits.length !== 16) {
      show('error', 'NPWP tidak valid. Masukkan 15 digit (NPWP format lama) atau 16 digit (NPWP format baru/NIK), atau kosongkan untuk menghapus.');
      return;
    }

    setSaving(true);
    try {
      const res = await payrollApi.saveCompanyTaxProfile({ npwp: digits });
      setNpwpInput('');
      show('success', res?.message || 'Profil pajak perusahaan berhasil disimpan.');
      await load();
    } catch (e: any) {
      show('error', errMsg(e, 'Gagal menyimpan profil pajak perusahaan.'));
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-5 space-y-4">
      <Banner banner={banner} onClose={clear} />

      <div className="flex items-start justify-between gap-3 flex-wrap">
        <div className="flex items-center gap-2.5">
          <div className="p-2 rounded-xl bg-indigo-50 dark:bg-indigo-950/50 text-indigo-600 dark:text-indigo-400">
            <Landmark className="w-5 h-5" />
          </div>
          <div>
            <h3 className="text-sm font-bold text-slate-900 dark:text-white">Profil Pajak Perusahaan (NPWP Pemberi Kerja)</h3>
            <p className="text-xs text-slate-500 dark:text-slate-400">
              NPWP identitas pemotong pajak pada Bukti Potong 1721-A1 dan berkas ekspor e-Bupot 21/26 DJP.
            </p>
          </div>
        </div>
        <button onClick={load} className={btnGhost} title="Muat ulang"><RefreshCw className="w-3.5 h-3.5" /> Muat ulang</button>
      </div>

      {loading ? <Spinner /> : (
        <div className="space-y-4">
          <div className="rounded-xl border border-slate-100 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-800/30 p-4 space-y-3">
            <div className="flex items-center justify-between gap-2 flex-wrap">
              <div>
                <span className="text-[10px] font-semibold uppercase tracking-wider text-slate-400">Nama Perusahaan</span>
                <p className="text-sm font-bold text-slate-800 dark:text-slate-100">{profile?.name ?? '—'}</p>
                {profile?.address && <p className="text-xs text-slate-500 dark:text-slate-400 mt-0.5">{profile.address}</p>}
              </div>
              <div>
                {profile?.has_npwp ? (
                  <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300">
                    <ShieldCheck className="w-3.5 h-3.5" /> NPWP Terdaftar
                  </span>
                ) : (
                  <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-400">
                    <AlertCircle className="w-3.5 h-3.5" /> Belum Ada NPWP
                  </span>
                )}
              </div>
            </div>

            <div className="pt-2 border-t border-slate-200/60 dark:border-slate-800 flex items-center justify-between gap-2 flex-wrap">
              <div>
                <span className="text-[10px] font-semibold uppercase tracking-wider text-slate-400">NPWP Pemotong (Masked)</span>
                <p className="font-mono text-xs font-bold text-slate-800 dark:text-slate-200 mt-0.5">
                  {profile?.has_npwp && profile?.npwp_masked ? (
                    profile.npwp_masked
                  ) : (
                    <span className="text-slate-400 font-sans font-normal italic">Belum didaftarkan</span>
                  )}
                </p>
              </div>
              <span className="text-[11px] text-slate-400 flex items-center gap-1">
                <Lock className="w-3 h-3 text-slate-400" /> Tersimpan terenkripsi (AES-256)
              </span>
            </div>
          </div>

          {canManage && (
            <div className="pt-2 space-y-3">
              <Field
                label="Ubah / Daftarkan NPWP Perusahaan"
                hint="Masukkan 15 digit (NPWP lama) atau 16 digit (NPWP baru/NIK). Kosongkan lalu klik Simpan untuk menghapus NPWP. Nilai lengkap tersimpan terenkripsi dan tidak pernah ditampilkan kembali di layar."
              >
                <div className="flex gap-2">
                  <input
                    type="text"
                    value={npwpInput}
                    onChange={(e) => setNpwpInput(e.target.value)}
                    placeholder="Contoh: 01.234.567.8-901.000 (atau kosongkan untuk menghapus)"
                    className={`${inputCls} font-mono`}
                    disabled={saving}
                  />
                  <button
                    type="button"
                    onClick={handleSaveNpwp}
                    disabled={saving}
                    className={`${btnPrimary} shrink-0`}
                  >
                    {saving ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <Save className="w-3.5 h-3.5" />}
                    Simpan NPWP
                  </button>
                </div>
              </Field>

              <div className="flex items-start gap-2.5 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200 dark:border-slate-700/60 p-3 text-xs text-slate-600 dark:text-slate-400">
                <Info className="w-4 h-4 shrink-0 mt-0.5 text-indigo-500" />
                <div className="text-[11px] space-y-0.5">
                  <p className="font-semibold text-slate-700 dark:text-slate-300">Keamanan Data NPWP:</p>
                  <p>NPWP lengkap hanya didekripsi saat sistem membuat berkas PDF Bukti Potong 1721-A1 resmi dan berkas ekspor e-Bupot 21/26 XML DJP. Setiap pengunduhan berkas diaudit ke log audit keamanan.</p>
                </div>
              </div>
            </div>
          )}
        </div>
      )}
    </div>
  );
};

const PengaturanPayrollTab: React.FC<{ canManage: boolean }> = ({ canManage }) => {
  const [subTab, setSubTab] = useState<'groups' | 'company_tax' | 'grades' | 'currencies'>('groups');

  return (
    <div className="space-y-5">
      {/* Sub-tab pills */}
      <div className="flex gap-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 p-1 w-fit max-w-full overflow-x-auto">
        {[
          { key: 'groups', label: 'Grup Payroll', icon: Layers },
          { key: 'company_tax', label: 'Profil Pajak Perusahaan (NPWP)', icon: Landmark },
          { key: 'grades', label: 'Struktur & Skala Upah', icon: Briefcase },
          { key: 'currencies', label: 'Master Kurs Valas', icon: Coins },
        ].map((item) => {
          const Icon = item.icon;
          const active = subTab === item.key;
          return (
            <button
              key={item.key}
              onClick={() => setSubTab(item.key as any)}
              className={`inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold whitespace-nowrap transition cursor-pointer ${
                active
                  ? 'bg-white dark:bg-slate-900 text-indigo-600 dark:text-indigo-400 shadow-sm'
                  : 'text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200'
              }`}
            >
              <Icon className="w-3.5 h-3.5" />
              {item.label}
            </button>
          );
        })}
      </div>

      {subTab === 'groups' && <GrupPayrollPanel canManage={canManage} />}
      {subTab === 'company_tax' && <ProfilPajakPerusahaanPanel canManage={canManage} />}
      {subTab === 'grades' && <StrukturSkalaUpah canManage={canManage} />}
      {subTab === 'currencies' && <MasterKursValas canManage={canManage} />}
    </div>
  );
};

// ═══════════════════════════════════════════════════════════════
// Root — kerangka tab
// ═══════════════════════════════════════════════════════════════
const TABS = [
  { key: 'runs', label: 'Proses Payroll', icon: Calculator },
  { key: 'severance', label: 'Exit Settlement', icon: Briefcase },
  { key: 'disbursement', label: 'Pembayaran Bank', icon: Building2 },
  { key: 'gl', label: 'Jurnal Akuntansi', icon: BookOpen },
  { key: 'tax1721', label: 'PPh 21 (1721-A1)', icon: FileText },
  { key: 'adjustments', label: 'Penyesuaian', icon: SlidersHorizontal },
  { key: 'salaries', label: 'Gaji Karyawan', icon: Users },
  { key: 'components', label: 'Komponen Gaji', icon: Layers },
  { key: 'loans', label: 'Kasbon', icon: HandCoins },
  { key: 'settings', label: 'Pengaturan Payroll', icon: Settings },
] as const;
type TabKey = typeof TABS[number]['key'];

export const PayrollManagement: React.FC<PayrollManagementProps> = () => {
  const { user } = useAuth();
  const { banner, show, clear } = useBanner();
  const [tab, setTab] = useState<TabKey>('runs');
  const [pinStatus, setPinStatus] = useState<PinStatus | null>(null);
  const [showPinModal, setShowPinModal] = useState(false);

  // Manage = admin/super_admin/finance/hrd, atau flag can_manage_payroll. Selain itu read-only.
  const canManage = Boolean((user as any)?.can_manage_payroll)
    || ['admin', 'super_admin', 'finance', 'hrd'].includes(user?.role ?? '');

  const refreshPinStatus = useCallback(() => {
    payrollApi.getPinStatus()
      .then((res: any) => setPinStatus(res?.data ?? null))
      .catch(() => {});
  }, []);

  useEffect(() => {
    refreshPinStatus();
  }, [refreshPinStatus]);

  return (
    <div className="space-y-5">
      <Banner banner={banner} onClose={clear} />

      {/* Header bar: Tabs + PIN Button */}
      <div className="flex items-center justify-between gap-3 flex-wrap">
        {/* Tab bar */}
        <div className="flex gap-1.5 overflow-x-auto pb-1">
          {TABS.map((t) => {
            const Icon = t.icon;
            const active = tab === t.key;
            return (
              <button
                key={t.key}
                onClick={() => setTab(t.key)}
                className={`inline-flex items-center gap-2 rounded-xl px-4 py-2.5 text-xs font-semibold whitespace-nowrap transition cursor-pointer ${
                  active
                    ? 'bg-indigo-600 text-white shadow-sm shadow-indigo-500/20'
                    : 'bg-white dark:bg-slate-900 text-slate-500 dark:text-slate-400 border border-slate-200 dark:border-slate-800 hover:text-slate-700 dark:hover:text-slate-200'
                }`}
              >
                <Icon className="w-4 h-4" />{t.label}
              </button>
            );
          })}
        </div>

        {/* Tombol Pengaturan PIN Keamanan */}
        <button
          onClick={() => setShowPinModal(true)}
          className="inline-flex items-center gap-2 rounded-xl px-3.5 py-2 text-xs font-semibold bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 text-slate-700 dark:text-slate-300 hover:border-indigo-400 dark:hover:border-indigo-600 transition shadow-sm cursor-pointer"
        >
          <Shield className={`w-3.5 h-3.5 ${pinStatus?.has_pin ? 'text-emerald-500' : 'text-amber-500'}`} />
          <span>PIN Keamanan</span>
          {pinStatus?.has_pin ? (
            <span className="text-[10px] px-1.5 py-0.5 rounded-full bg-emerald-50 text-emerald-600 dark:bg-emerald-950/40 dark:text-emerald-400 font-bold">
              Aktif
            </span>
          ) : (
            <span className="text-[10px] px-1.5 py-0.5 rounded-full bg-amber-50 text-amber-600 dark:bg-amber-950/40 dark:text-amber-400 font-bold">
              Belum Diatur
            </span>
          )}
        </button>
      </div>

      {tab === 'runs' && <ProsesPayrollTab canManage={canManage} />}
      {tab === 'severance' && <ExitSettlement canManage={canManage} onNavigateToRuns={() => setTab('runs')} />}
      {tab === 'disbursement' && <PayrollDisbursement canManage={canManage} />}
      {tab === 'gl' && <PayrollGlJournal canManage={canManage} />}
      {tab === 'tax1721' && <PayrollTax1721A1 canManage={canManage} />}
      {tab === 'adjustments' && <PenyesuaianTab canManage={canManage} />}
      {tab === 'salaries' && <GajiKaryawanTab canManage={canManage} />}
      {tab === 'components' && <KomponenGajiTab canManage={canManage} />}
      {tab === 'loans' && <KasbonTab canManage={canManage} />}
      {tab === 'settings' && <PengaturanPayrollTab canManage={canManage} />}

      {/* Modal PIN Keamanan */}
      {showPinModal && (
        <SecurityPinModal
          onClose={() => setShowPinModal(false)}
          onSaved={(hasPin) => {
            setShowPinModal(false);
            setPinStatus({ has_pin: hasPin });
            show('success', hasPin ? 'PIN keamanan berhasil disimpan & aktif.' : 'PIN keamanan diperbarui.');
          }}
          onError={(m) => show('error', m)}
        />
      )}
    </div>
  );
};

export default PayrollManagement;
