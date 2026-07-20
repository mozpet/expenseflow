import React, { useState, useEffect, useCallback } from 'react';
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
} from 'lucide-react';
import { attendanceApi } from '../services/endpoints';
import { ApiError } from '../services/api';

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

const TABS: { key: TabKey; label: string; icon: React.ElementType }[] = [
  { key: 'today', label: 'Hari Ini', icon: CalendarCheck },
  { key: 'leaves', label: 'Approval Izin & Cuti', icon: ClipboardList },
  { key: 'users', label: 'Karyawan & WFH', icon: Users },
  { key: 'balances', label: 'Saldo Cuti', icon: Wallet },
  { key: 'report', label: 'Laporan', icon: BarChart3 },
  { key: 'holidays', label: 'Libur Nasional', icon: CalendarDays },
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
    case 'absent': return 'Absen';
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
        <div className="grid grid-cols-2 lg:grid-cols-4 gap-3">
          {[1, 2, 3, 4].map(i => <div key={i} className="h-[84px] bg-slate-200 dark:bg-slate-800 rounded-2xl" />)}
        </div>
        <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
          {[1, 2, 3].map(i => <div key={i} className="h-64 bg-slate-200 dark:bg-slate-800 rounded-2xl" />)}
        </div>
      </div>
    );
  }
  if (tab === 'balances') {
    return (
      <div className="space-y-4 animate-pulse mt-2 w-full">
        <div className="flex justify-between items-center">
          <div className="h-4 w-48 bg-slate-200 dark:bg-slate-800 rounded" />
          <div className="h-8 w-48 bg-slate-200 dark:bg-slate-800 rounded-xl" />
        </div>
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          {[1, 2, 3, 4].map(i => <div key={i} className="h-40 bg-slate-200 dark:bg-slate-800 rounded-2xl" />)}
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
        <div className="flex justify-between items-center">
          <div className="space-y-2">
            <div className="h-5 w-40 bg-slate-200 dark:bg-slate-800 rounded" />
            <div className="h-3 w-64 bg-slate-200 dark:bg-slate-800 rounded" />
          </div>
          <div className="h-8 w-32 bg-slate-200 dark:bg-slate-800 rounded-lg" />
        </div>
        <div className="h-[300px] bg-slate-200 dark:bg-slate-800 rounded-2xl" />
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

  // Default for leaves
  return (
    <div className="h-[400px] bg-slate-200 dark:bg-slate-800 rounded-2xl animate-pulse w-full" />
  );
};

export const AttendanceManagement: React.FC<Props> = ({ onAddAuditLog, onAddNotification }) => {
  const [tab, setTab] = useState<TabKey>('today');
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  // Data per tab
  const [today, setToday] = useState<any | null>(null);
  const [leaves, setLeaves] = useState<any[]>([]);
  const [leaveStatus, setLeaveStatus] = useState<'pending' | 'approved' | 'rejected' | ''>('pending');
  const [leaveTypeFilter, setLeaveTypeFilter] = useState<'wfh' | 'izin' | 'sakit' | 'cuti' | ''>('');
  const [leaveSearch, setLeaveSearch] = useState('');
  const [showUpcoming, setShowUpcoming] = useState(false);
  const [docLoadingId, setDocLoadingId] = useState<number | null>(null);
  const [docModal, setDocModal] = useState<{ url: string; isPdf: boolean; userName: string } | null>(null);
  const [users, setUsers] = useState<any[]>([]);
  const [userSearch, setUserSearch] = useState('');
  const [balances, setBalances] = useState<any[]>([]);
  const [balanceSearch, setBalanceSearch] = useState('');
  const [togglingUserId, setTogglingUserId] = useState<number | null>(null);
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

  useEffect(() => {
    attendanceApi.settings.list().then(res => setOffices((res as any)?.settings ?? [])).catch(() => { });
  }, []);

  useEffect(() => {
    const handler = setTimeout(() => {
      if (reportFilter.search !== reportSearch) {
        setReportFilterAndReset({ ...reportFilter, search: reportSearch });
      }
    }, 500);
    return () => clearTimeout(handler);
  }, [reportSearch]);

  const [reportNameSort, setReportNameSort] = useState<'asc' | 'desc' | null>(null);
  const [holidays, setHolidays] = useState<any[]>([]);

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
      // Fetch SEMUA leaves tanpa filter status dari API — filter diterapkan lokal.
      // Ini memastikan data approved selalu tersedia untuk deteksi bentrok pada baris pending.
      const res: any = await attendanceApi.leaves({});
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
      const res: any = await attendanceApi.users();
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
      const res: any = await attendanceApi.holidays.list();
      setHolidays(res?.holidays ?? []);
    } catch (e) {
      reportApiError(e, 'Gagal memuat kalender libur.');
    } finally {
      setLoading(false);
    }
  }, []);

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
    else if (tab === 'balances') loadBalances();
    else if (tab === 'report') loadReport(reportPage);
    else if (tab === 'holidays') loadHolidays();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [tab, reportFilter, reportPage]);

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
      await loadUsers();
    } catch (e) {
      reportApiError(e, 'Gagal mengubah mode WFH.');
    }
  };

  const handleToggleRadius = async (id: number, name: string) => {
    try {
      const res: any = await attendanceApi.toggleRadius(id);
      const on = res?.user?.radius_enabled;
      onAddAuditLog('Radius Lapangan Diubah', `Radius ${name} ${on ? 'diaktifkan (lapangan)' : 'dinonaktifkan (WFH bebas)'}`, on ? 'bg-amber-600' : 'bg-slate-600');
      await loadUsers();
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
      <div className="flex items-center justify-between gap-3">
        <div className="flex gap-1.5 overflow-x-auto pb-1 flex-1">
          {TABS.map(({ key, label, icon: Icon }) => (
            <button
              key={key}
              onClick={() => setTab(key)}
              className={`flex items-center gap-1.5 px-3.5 py-2 rounded-xl text-xs font-semibold whitespace-nowrap transition ${tab === key
                ? 'bg-indigo-600 text-white shadow-sm shadow-indigo-500/20'
                : 'bg-white dark:bg-slate-900 text-slate-600 dark:text-slate-300 border border-slate-200 dark:border-slate-800 hover:bg-slate-50 dark:hover:bg-slate-800'
                }`}
            >
              <Icon className="w-3.5 h-3.5" />
              {label}
            </button>
          ))}
        </div>
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

      {error && (
        <div className="flex items-center justify-between gap-2 bg-rose-50 border border-rose-200 text-rose-700 rounded-lg px-4 py-3 text-xs">
          <span className="flex items-center gap-2"><AlertCircle className="w-4 h-4" />{error}</span>
        </div>
      )}

      {/* ─── TAB: Hari Ini ─── */}
      {tab === 'today' && (
        loading ? <TabSkeleton tab="today" /> : today && (
          <div className="space-y-5">
            <div className="flex items-center justify-between">
              <h3 className="text-sm font-bold text-slate-800 dark:text-slate-100 flex items-center gap-2">
                <CalendarDays className="w-4 h-4 text-indigo-500" />
                {new Intl.DateTimeFormat('id-ID', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }).format(new Date())}
              </h3>
            </div>
            <div className="grid grid-cols-2 lg:grid-cols-4 gap-3">
              <SummaryCard label="Total Karyawan" value={today.summary?.total_employees ?? 0} color="text-slate-800 dark:text-white" />
              <SummaryCard label="Sudah Check-in" value={today.summary?.checked_in ?? 0} color="text-emerald-600" />
              <SummaryCard label="Belum Check-in" value={today.summary?.not_checked_in ?? 0} color="text-rose-600" />
              <SummaryCard label="Izin / Cuti" value={today.summary?.on_leave ?? 0} color="text-amber-600" />
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
              {(() => {
                const checkedIn = today.checked_in ?? [];
                const notCheckedInRaw = today.not_checked_in ?? [];
                const onLeave = today.on_leave ?? [];

                const notCheckedIn = notCheckedInRaw.filter((p: any) => !p.is_off);
                const offToday = notCheckedInRaw.filter((p: any) => p.is_off);

                return (
                  <>
                    {/* Sudah check-in */}
                    <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl p-4 flex flex-col h-full">
                      <h4 className="text-xs font-bold text-emerald-700 dark:text-emerald-400 flex items-center gap-1.5 mb-3">
                        <CheckCircle2 className="w-4 h-4" /> Sudah Check-in ({checkedIn.length})
                      </h4>
                      <div className="space-y-2 flex-1 overflow-y-auto max-h-80 pr-1">
                        {checkedIn.length === 0 ? (
                          <p className="text-[11px] text-slate-400 py-3 text-center">Belum ada.</p>
                        ) : (
                          checkedIn.map((p: any) => (
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
                          ))
                        )}
                      </div>
                    </div>

                    {/* Belum check-in */}
                    <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl p-4 flex flex-col h-full">
                      <h4 className="text-xs font-bold text-rose-700 dark:text-rose-400 flex items-center gap-1.5 mb-3">
                        <Clock className="w-4 h-4" /> Belum Check-in ({notCheckedIn.length})
                      </h4>
                      <div className="space-y-2 flex-1 overflow-y-auto max-h-80 pr-1">
                        {notCheckedIn.length === 0 ? (
                          <p className="text-[11px] text-slate-400 py-3 text-center">Semua sudah hadir.</p>
                        ) : (
                          notCheckedIn.map((p: any) => (
                            <div key={p.user_id} className="flex items-center justify-between border-b border-slate-50 dark:border-slate-800/60 pb-2">
                              <div className="min-w-0">
                                <p className="text-xs font-semibold text-slate-800 dark:text-slate-200 truncate">{p.name}</p>
                                <p className="text-[10px] text-slate-400">{p.department ?? '—'}</p>
                              </div>
                            </div>
                          ))
                        )}
                      </div>
                    </div>

                    {/* Sedang Libur */}
                    <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl p-4 flex flex-col h-full">
                      <h4 className="text-xs font-bold text-slate-700 dark:text-slate-400 flex items-center gap-1.5 mb-3">
                        <CalendarDays className="w-4 h-4" /> Sedang Libur ({offToday.length})
                      </h4>
                      <div className="space-y-2 flex-1 overflow-y-auto max-h-80 pr-1">
                        {offToday.length === 0 ? (
                          <p className="text-[11px] text-slate-400 py-3 text-center">Tidak ada.</p>
                        ) : (
                          offToday.map((p: any) => (
                            <div key={p.user_id} className="flex items-center justify-between border-b border-slate-50 dark:border-slate-800/60 pb-2">
                              <div className="min-w-0">
                                <p className="text-xs font-semibold text-slate-800 dark:text-slate-200 truncate">{p.name}</p>
                                <p className="text-[10px] text-slate-400">{p.department ?? '—'}</p>
                              </div>
                              <span className="text-[9px] font-bold px-2 py-0.5 rounded bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400 shrink-0 border border-slate-200 dark:border-slate-700">Libur</span>
                            </div>
                          ))
                        )}
                      </div>
                    </div>

                    {/* Izin/cuti */}
                    <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl p-4 flex flex-col h-full">
                      <h4 className="text-xs font-bold text-amber-700 dark:text-amber-400 flex items-center gap-1.5 mb-3">
                        <ClipboardList className="w-4 h-4" /> Sedang Izin/Cuti ({onLeave.length})
                      </h4>
                      <div className="space-y-2 flex-1 overflow-y-auto max-h-80 pr-1">
                        {onLeave.length === 0 ? (
                          <p className="text-[11px] text-slate-400 py-3 text-center">Tidak ada.</p>
                        ) : (
                          onLeave.map((p: any) => (
                            <div key={p.user_id} className="flex items-center justify-between border-b border-slate-50 dark:border-slate-800/60 pb-2">
                              <div className="min-w-0">
                                <p className="text-xs font-semibold text-slate-800 dark:text-slate-200 truncate">{p.name}</p>
                                <p className="text-[10px] text-slate-400">{p.department ?? '—'}</p>
                              </div>
                              <span className="text-[9px] font-bold px-2 py-0.5 rounded bg-amber-50 text-amber-700 capitalize shrink-0">{p.leave_type}</span>
                            </div>
                          ))
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
              if (leaveStatus) result = result.filter((l: any) => l.status === l.status && l.status === leaveStatus);
              if (leaveTypeFilter) result = result.filter((l: any) => l.leave_type === leaveTypeFilter);
            }
            return result.filter((l: any) =>
              l.user_name.toLowerCase().includes(leaveSearch.toLowerCase())
            );
          })();

          // ── Deteksi bentrok: hanya untuk baris pending ────────
          // Cek apakah leave pending ini tumpang tindih tanggal dengan leave approved
          // dari karyawan lain (range overlap sederhana).
          const dateOverlaps = (s1: string, e1: string, s2: string, e2: string) =>
            s1.slice(0, 10) <= e2.slice(0, 10) && e1.slice(0, 10) >= s2.slice(0, 10);

          const getApprovedConflicts = (pendingLeave: any): string[] => {
            if (pendingLeave.status !== 'pending') return [];
            const found: string[] = [];
            leaves.forEach((other: any) => {
              if (other.id === pendingLeave.id) return;
              if (other.user_name === pendingLeave.user_name) return;
              if (other.status !== 'approved') return;
              if (dateOverlaps(
                pendingLeave.start_date, pendingLeave.end_date,
                other.start_date, other.end_date
              )) {
                if (!found.includes(other.user_name)) found.push(other.user_name);
              }
            });
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
                      <th className="py-2 px-2 font-semibold">Periode</th>
                      <th className="py-2 px-2 font-semibold text-center">Hari</th>
                      <th className="py-2 px-2 font-semibold">Alasan</th>
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
                      displayedLeaves.map((l: any) => {
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
                                  className="mt-1.5 flex items-center gap-1 text-[10px] font-semibold text-sky-600 dark:text-sky-400 hover:text-sky-800 hover:underline disabled:opacity-50"
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
                            <td className="py-2.5 px-2 text-slate-500 whitespace-nowrap">
                              {fmtDate(l.start_date)} – {fmtDate(l.end_date)}
                            </td>
                            <td className="py-2.5 px-2 text-center font-mono">{l.total_days}</td>
                            <td className="py-2.5 px-2 max-w-[180px] truncate text-slate-500" title={l.reason}>{l.reason}</td>
                            <td className="py-2.5 px-2 text-center">
                              <span className={`text-[9px] font-bold px-2 py-0.5 rounded ${leaveBadge(l.status)}`}>
                                {l.status === 'approved' ? 'Disetujui' : l.status === 'rejected' ? 'Ditolak' : 'Menunggu'}
                              </span>
                            </td>
                            <td className="py-2.5 px-2 text-right">
                              {l.status === 'pending' ? (
                                <div className="flex justify-end gap-1.5">
                                  <button
                                    onClick={() => handleApproveLeave(l.id, l.user_name)}
                                    className="p-1.5 rounded-lg bg-emerald-50 text-emerald-600 hover:bg-emerald-100"
                                    title="Setujui"
                                  >
                                    <Check className="w-3.5 h-3.5" />
                                  </button>
                                  <button
                                    onClick={() => handleRejectLeave(l.id, l.user_name)}
                                    className="p-1.5 rounded-lg bg-rose-50 text-rose-600 hover:bg-rose-100"
                                    title="Tolak"
                                  >
                                    <X className="w-3.5 h-3.5" />
                                  </button>
                                </div>
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
            <div className="relative mb-3">
              <Search className="absolute left-2.5 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-slate-400 pointer-events-none" />
              <input
                type="text"
                placeholder="Cari nama karyawan..."
                value={userSearch}
                onChange={(e) => setUserSearch(e.target.value)}
                className="w-full pl-8 pr-3 py-2 text-xs border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-800/20 text-slate-800 dark:text-slate-100 placeholder-slate-400 focus:outline-none focus:ring-1 focus:ring-indigo-400"
              />
            </div>
            <div className="overflow-x-auto">
              {(() => {
                const filtered = users.filter(u =>
                  u.name.toLowerCase().includes(userSearch.toLowerCase())
                );
                return (
                  <table className="w-full text-xs text-left">
                    <thead>
                      <tr className="border-b border-slate-100 dark:border-slate-800 text-slate-500">
                        <th className="py-2 px-2 font-semibold">Nama</th>
                        <th className="py-2 px-2 font-semibold">Departemen</th>
                        <th className="py-2 px-2 font-semibold">Role</th>
                        <th className="py-2 px-2 font-semibold text-center">Mode WFH</th>
                        <th className="py-2 px-2 font-semibold text-center">Radius Lapangan</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-50 dark:divide-slate-800/60">
                      {filtered.length === 0 ? (
                        <tr><td colSpan={5} className="text-center py-8 text-slate-400">{userSearch ? `Tidak ada karyawan dengan nama "${userSearch}".` : 'Tidak ada karyawan.'}</td></tr>
                      ) : (
                        filtered.map((u) => (
                          <tr key={u.id} className="hover:bg-slate-50/50 dark:hover:bg-slate-800/30">
                            <td className="py-2.5 px-2 font-semibold text-slate-800 dark:text-slate-200">{u.name}</td>
                            <td className="py-2.5 px-2 text-slate-500">{u.department ?? '—'}</td>
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
                );
              })()}
            </div>
          </div>
        )
      )}

      {/* ─── TAB: Saldo Cuti ─── */}
      {tab === 'balances' && (
        loading ? <TabSkeleton tab="balances" /> : (() => {
          // Group baris flat per nama karyawan → 1 card per orang
          type BalanceEntry = { cuti?: any; izin?: any };
          const grouped = balances.reduce<Record<string, BalanceEntry>>((acc, b) => {
            if (!acc[b.user_name]) acc[b.user_name] = {};
            if (b.leave_type === 'cuti') acc[b.user_name].cuti = b;
            else acc[b.user_name].izin = b;
            return acc;
          }, {});

          const entries = Object.entries(grouped).filter(([name]) =>
            name.toLowerCase().includes(balanceSearch.toLowerCase())
          );

          const progressColor = (remaining: number, quota: number) => {
            if (quota === 0) return 'bg-slate-300';
            const pct = remaining / quota;
            if (pct > 0.5) return 'bg-emerald-500';
            if (pct > 0.25) return 'bg-amber-400';
            return 'bg-rose-500';
          };

          const progressWidth = (remaining: number, quota: number) =>
            quota > 0 ? `${Math.min(100, Math.round((remaining / quota) * 100))}%` : '0%';

          return (
            <div className="space-y-4">
              <div className="flex items-center justify-between gap-3">
                <p className="text-[11px] text-slate-400">Saldo cuti karyawan tahun {new Date().getFullYear()}.</p>
                <div className="relative">
                  <Search className="absolute left-2.5 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-slate-400 pointer-events-none" />
                  <input
                    type="text"
                    placeholder="Cari karyawan..."
                    value={balanceSearch}
                    onChange={(e) => setBalanceSearch(e.target.value)}
                    className="pl-8 pr-3 py-2 text-xs border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-800/20 text-slate-800 dark:text-slate-100 placeholder-slate-400 focus:outline-none focus:ring-1 focus:ring-indigo-400 w-48"
                  />
                </div>
              </div>

              {entries.length === 0 ? (
                <p className="text-center py-10 text-xs text-slate-400">
                  {balanceSearch ? `Tidak ada karyawan dengan nama "${balanceSearch}".` : 'Belum ada data saldo.'}
                </p>
              ) : (
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                  {entries.map(([name, data]: [string, BalanceEntry]) => {
                    const cuti = data.cuti;
                    const izin = data.izin;
                    const userId = cuti?.user_id ?? izin?.user_id;
                    const isActive = (cuti?.quota ?? 0) > 0;
                    const isToggling = togglingUserId === userId;

                    return (
                      <div
                        key={name}
                        className={`bg-white dark:bg-slate-900 border rounded-2xl p-4 space-y-3 transition-opacity ${isActive
                          ? 'border-slate-100 dark:border-slate-800'
                          : 'border-slate-200 dark:border-slate-700 opacity-70'
                          }`}
                      >
                        {/* Header karyawan + toggle */}
                        <div className="flex items-center gap-2 pb-2 border-b border-slate-100 dark:border-slate-800">
                          <div className="w-7 h-7 rounded-full bg-indigo-100 dark:bg-indigo-950/50 flex items-center justify-center shrink-0">
                            <Users className="w-3.5 h-3.5 text-indigo-600 dark:text-indigo-400" />
                          </div>
                          <p className="text-xs font-bold text-slate-800 dark:text-slate-100 truncate flex-1">{name}</p>

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
                          <div className="space-y-1.5">
                            <p className="text-[9px] font-bold text-slate-400 uppercase tracking-wider">Cuti Tahunan</p>
                            {!isActive ? (
                              <div className="flex items-center gap-1.5 py-1">
                                <span className="text-[10px] text-slate-400 italic">Kuota nonaktif</span>
                              </div>
                            ) : cuti ? (
                              <>
                                <p className="text-lg font-bold text-slate-800 dark:text-slate-100 leading-none">
                                  {cuti.remaining}
                                  <span className="text-[10px] font-normal text-slate-400 ml-1">/ {cuti.quota} hari</span>
                                </p>
                                <div className="w-full h-1.5 bg-slate-100 dark:bg-slate-800 rounded-full overflow-hidden">
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
                          <div className="space-y-1.5">
                            <p className="text-[9px] font-bold text-slate-400 uppercase tracking-wider">Izin / Sakit</p>
                            {izin ? (
                              <>
                                <p className="text-lg font-bold text-slate-800 dark:text-slate-100 leading-none">
                                  {izin.used}
                                  <span className="text-[10px] font-normal text-slate-400 ml-1">hari terpakai</span>
                                </p>
                                <div className="w-full h-1.5 bg-slate-100 dark:bg-slate-800 rounded-full overflow-hidden">
                                  <div className="h-full w-0 rounded-full bg-slate-300" />
                                </div>
                                <p className="text-[10px] text-slate-400">Tidak terbatas</p>
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
              )}
            </div>
          );
        })()
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
                <input type="date" value={reportFilter.start_date} onChange={(e) => setReportFilterAndReset({ ...reportFilter, start_date: e.target.value })} className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-lg bg-slate-50 dark:bg-slate-800/20 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-1 focus:ring-indigo-400 transition-colors" />
              </div>
              <div className="space-y-1.5">
                <label className="text-[10px] font-bold text-slate-400 block uppercase tracking-wider">Sampai Tanggal</label>
                <input type="date" value={reportFilter.end_date} onChange={(e) => setReportFilterAndReset({ ...reportFilter, end_date: e.target.value })} className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-lg bg-slate-50 dark:bg-slate-800/20 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-1 focus:ring-indigo-400 transition-colors" />
              </div>
              <div className="space-y-1.5">
                <label className="text-[10px] font-bold text-slate-400 block uppercase tracking-wider">Status</label>
                <select value={reportFilter.status} onChange={(e) => setReportFilterAndReset({ ...reportFilter, status: e.target.value })} className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 focus:outline-none focus:ring-1 focus:ring-indigo-400 transition-colors">
                  <option value="">Semua Status</option>
                  <option value="present">Hadir</option>
                  <option value="late">Telat</option>
                  <option value="early_leave">Pulang Awal</option>
                  <option value="absent">Absen</option>
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
                  <option value="onsite">Kantor</option>
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
              <div className="pt-2">
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
                <SummaryCard label="Absen" value={report.summary?.absent ?? 0} color="text-rose-600" />
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
            reload={loadHolidays}
            onAddAuditLog={onAddAuditLog}
            onError={reportApiError}
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
    </div>
  );
};

// ─── Sub-komponen: Kalender libur nasional / cuti bersama ─────
const HolidaysTab: React.FC<{
  holidays: any[];
  reload: () => Promise<void>;
  onAddAuditLog: (t: string, d: string, b: string) => void;
  onError: (e: unknown, f: string) => void;
}> = ({ holidays, reload, onAddAuditLog, onError }) => {
  const [showForm, setShowForm] = useState(false);
  const [editingId, setEditingId] = useState<number | null>(null);
  const [form, setForm] = useState<{ date: string; name: string }>({ date: '', name: '' });
  const [saving, setSaving] = useState(false);

  const resetForm = () => {
    setForm({ date: '', name: '' });
    setEditingId(null);
    setShowForm(false);
  };

  const startCreate = () => {
    // Jika form sedang dalam mode edit, alihkan ke mode tambah; jika tidak, toggle.
    if (editingId !== null) {
      setEditingId(null);
      setForm({ date: '', name: '' });
      setShowForm(true);
      return;
    }
    setForm({ date: '', name: '' });
    setShowForm((v) => !v);
  };

  const startEdit = (h: any) => {
    setEditingId(h.id);
    setForm({ date: String(h.date).slice(0, 10), name: h.name });
    setShowForm(true);
  };

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!form.date || !form.name.trim()) return;
    setSaving(true);
    try {
      if (editingId !== null) {
        await attendanceApi.holidays.update(editingId, { date: form.date, name: form.name.trim() });
        onAddAuditLog('Hari libur diubah', `${form.name} (${form.date})`, 'bg-sky-500');
      } else {
        await attendanceApi.holidays.create({ date: form.date, name: form.name.trim() });
        onAddAuditLog('Hari libur ditambahkan', `${form.name} (${form.date})`, 'bg-amber-500');
      }
      resetForm();
      await reload();
    } catch (err) {
      onError(err, editingId !== null ? 'Gagal mengubah hari libur.' : 'Gagal menambah hari libur.');
    } finally {
      setSaving(false);
    }
  };

  const remove = async (h: any) => {
    if (!confirm(`Hapus libur "${h.name}" (${h.date})?`)) return;
    try {
      await attendanceApi.holidays.destroy(h.id);
      onAddAuditLog('Hari libur dihapus', `${h.name} (${h.date})`, 'bg-rose-500');
      await reload();
    } catch (err) {
      onError(err, 'Gagal menghapus hari libur.');
    }
  };

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <div>
          <h3 className="text-sm font-bold text-slate-800 dark:text-slate-100">Kalender Libur Nasional</h3>
          <p className="text-[11px] text-slate-400 mt-0.5">
            Tanggal libur tidak dihitung sebagai hari kerja (cuti) dan kerja di hari ini dihitung lembur penuh.
          </p>
        </div>
        <button
          onClick={startCreate}
          className="flex items-center gap-1.5 px-3 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-xs font-bold transition"
        >
          <Plus className="w-3.5 h-3.5" /> Tambah Libur
        </button>
      </div>

      {showForm && (
        <form onSubmit={submit} className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl p-5 grid grid-cols-1 sm:grid-cols-3 gap-4 items-end">
          <div className="space-y-1.5 sm:col-span-3 -mb-2">
            <p className="text-xs font-bold text-slate-700 dark:text-slate-200">
              {editingId !== null ? 'Ubah Hari Libur' : 'Tambah Hari Libur'}
            </p>
          </div>
          <div className="space-y-1.5">
            <label className="text-[10px] font-bold text-slate-400 block uppercase tracking-wider">Tanggal</label>
            <input
              type="date"
              value={form.date}
              onChange={(e) => setForm({ ...form, date: e.target.value })}
              className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-lg bg-slate-50 dark:bg-slate-800/20 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-1 focus:ring-indigo-400"
              required
            />
          </div>
          <div className="space-y-1.5 sm:col-span-1">
            <label className="text-[10px] font-bold text-slate-400 block uppercase tracking-wider">Nama Libur</label>
            <input
              type="text"
              value={form.name}
              placeholder="mis. Cuti Bersama"
              onChange={(e) => setForm({ ...form, name: e.target.value })}
              className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-lg bg-slate-50 dark:bg-slate-800/20 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-1 focus:ring-indigo-400"
              required
            />
          </div>
          <div className="flex items-center gap-2">
            <button
              type="submit"
              disabled={saving}
              className="px-3 py-2.5 bg-emerald-600 hover:bg-emerald-700 disabled:opacity-50 text-white rounded-lg text-xs font-bold transition"
            >
              {saving ? 'Menyimpan...' : editingId !== null ? 'Simpan Perubahan' : 'Simpan'}
            </button>
            <button
              type="button"
              onClick={resetForm}
              className="px-3 py-2.5 bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-600 dark:text-slate-300 rounded-lg text-xs font-bold transition"
            >
              Batal
            </button>
          </div>
        </form>
      )}

      <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl p-5 overflow-x-auto">
        <table className="w-full text-xs text-left">
          <thead>
            <tr className="border-b border-slate-100 dark:border-slate-800 text-slate-500">
              <th className="py-2 px-2 font-semibold">Tanggal</th>
              <th className="py-2 px-2 font-semibold">Nama</th>
              <th className="py-2 px-2 font-semibold text-center">Cakupan</th>
              <th className="py-2 px-2 font-semibold text-right">Aksi</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-50 dark:divide-slate-800/60">
            {holidays.length === 0 ? (
              <tr>
                <td colSpan={4} className="text-center py-12 text-slate-400 text-xs font-medium">
                  Belum ada data libur tahun ini.
                </td>
              </tr>
            ) : (
              holidays.map((h: any) => (
                <tr key={h.id} className="hover:bg-slate-50/70 dark:hover:bg-slate-800/40 transition-colors">
                  <td className="py-3 px-2 font-mono whitespace-nowrap text-slate-700 dark:text-slate-300">{fmtDate(h.date)}</td>
                  <td className="py-3 px-2 font-semibold text-slate-800 dark:text-slate-200">{h.name}</td>
                  <td className="py-3 px-2 text-center">
                    <span className={`inline-flex text-[10px] font-bold px-2 py-1 rounded-md uppercase tracking-wider ${h.scope === 'nasional' ? 'bg-rose-50 text-rose-700 dark:bg-rose-950/30 dark:text-rose-400' : 'bg-indigo-50 text-indigo-700 dark:bg-indigo-950/30 dark:text-indigo-400'}`}>
                      {h.scope}
                    </span>
                  </td>
                  <td className="py-3 px-2 text-right">
                    <div className="inline-flex items-center gap-1 justify-end">
                      <button
                        onClick={() => startEdit(h)}
                        className="inline-flex items-center gap-1 px-2 py-1 text-sky-600 hover:bg-sky-50 dark:hover:bg-sky-950/30 rounded-md text-[11px] font-medium transition"
                      >
                        <Pencil className="w-3.5 h-3.5" /> Ubah
                      </button>
                      <button
                        onClick={() => remove(h)}
                        className="inline-flex items-center gap-1 px-2 py-1 text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-950/30 rounded-md text-[11px] font-medium transition"
                      >
                        <Trash2 className="w-3.5 h-3.5" /> Hapus
                      </button>
                    </div>
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
};
