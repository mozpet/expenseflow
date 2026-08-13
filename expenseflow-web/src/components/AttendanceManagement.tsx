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
  const [leaveSearch, setLeaveSearch] = useState('');
  const [showUpcoming, setShowUpcoming] = useState(false);
  const [docLoadingId, setDocLoadingId] = useState<number | null>(null);
  const [docModal, setDocModal] = useState<{ url: string; isPdf: boolean; userName: string } | null>(null);
  const [users, setUsers] = useState<any[]>([]);
  const [userSearch, setUserSearch] = useState('');
  const [userOfficeFilter, setUserOfficeFilter] = useState('');
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
  const [todayOfficeFilter, setTodayOfficeFilter] = useState('');
  const [searchCheckedIn, setSearchCheckedIn] = useState('');
  const [searchNotCheckedIn, setSearchNotCheckedIn] = useState('');
  const [searchOffToday, setSearchOffToday] = useState('');
  const [searchOnLeave, setSearchOnLeave] = useState('');

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
    else if (tab === 'balances') loadBalances();
    else if (tab === 'report') loadReport(reportPage);
    else if (tab === 'holidays') loadHolidays();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [tab, reportFilter, reportPage, holidayYear]);

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
        <div className="flex items-center gap-2">
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

                const checkedIn = checkedInRaw.filter((p: any) => filterPerson(p, searchCheckedIn));
                const notCheckedIn = notCheckedInRaw.filter((p: any) => filterPerson(p, searchNotCheckedIn));
                const offToday = offTodayRaw.filter((p: any) => filterPerson(p, searchOffToday));
                const onLeave = onLeaveRaw.filter((p: any) => filterPerson(p, searchOnLeave));

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
                          ))
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
                          notCheckedIn.map((p: any) => (
                            <div key={p.user_id} className="flex items-center justify-between border-b border-slate-50 dark:border-slate-800/60 pb-2">
                              <div className="min-w-0">
                                <p className="text-xs font-semibold text-slate-800 dark:text-slate-200 truncate">{p.name}</p>
                                <p className="text-[10px] text-slate-400">
                                  {p.employee_code && <span className="font-mono">{p.employee_code} · </span>}
                                  {p.department ?? '—'}
                                </p>
                              </div>
                            </div>
                          ))
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
                          offToday.map((p: any) => (
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
                          ))
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
                          onLeave.map((p: any) => (
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
                  const q = userSearch.toLowerCase();
                  const matchSearch = u.name.toLowerCase().includes(q) ||
                    (u.employee_code && u.employee_code.toLowerCase().includes(q)) ||
                    (u.nik && u.nik.toLowerCase().includes(q));
                  const matchOffice = !userOfficeFilter ||
                    (userOfficeFilter === 'null'
                      ? !u.attendance_setting_id
                      : String(u.attendance_setting_id) === String(userOfficeFilter));
                  return matchSearch && matchOffice;
                });
                return (
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
                        filtered.map((u) => (
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
          type BalanceEntry = { cuti?: any; izin?: any; employeeCode?: string };
          const grouped = balances.reduce<Record<string, BalanceEntry>>((acc, b) => {
            if (!acc[b.user_name]) {
              acc[b.user_name] = { employeeCode: b.employee_code || b.nik || b.user?.employee_code || '' };
            }
            if (b.leave_type === 'cuti') acc[b.user_name].cuti = b;
            else acc[b.user_name].izin = b;
            return acc;
          }, {});

          const entries = Object.entries(grouped).filter(([name, data]: [string, any]) => {
            const q = balanceSearch.toLowerCase();
            return name.toLowerCase().includes(q) || (data.employeeCode && data.employeeCode.toLowerCase().includes(q));
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

          return (
            <div className="space-y-4">
              <div className="flex items-center justify-between gap-3">
                <p className="text-[11px] text-slate-400">Saldo cuti karyawan tahun {new Date().getFullYear()}.</p>
                <div className="relative">
                  <Search className="absolute left-2.5 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-slate-400 pointer-events-none" />
                  <input
                    type="text"
                    placeholder="Cari nama atau NIK..."
                    value={balanceSearch}
                    onChange={(e) => setBalanceSearch(e.target.value)}
                    className="pl-8 pr-3 py-2 text-xs border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-800/20 text-slate-800 dark:text-slate-100 placeholder-slate-400 focus:outline-none focus:ring-1 focus:ring-indigo-400 w-48"
                  />
                </div>
              </div>

              {entries.length === 0 ? (
                <p className="text-center py-10 text-xs text-slate-400">
                  {balanceSearch ? `Tidak ada karyawan yang cocok dengan "${balanceSearch}".` : 'Belum ada data saldo.'}
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
                          <p className="text-xs font-bold text-slate-800 dark:text-slate-100 truncate flex-1">
                            {name}
                            {data.employeeCode && (
                              <span className="ml-1.5 text-[10px] font-mono font-normal text-slate-400">({data.employeeCode})</span>
                            )}
                          </p>

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
  reload: () => Promise<void>;
  onAddAuditLog: (t: string, d: string, b: string) => void;
  onError: (e: unknown, f: string) => void;
  year: number;
  onYearChange: (y: number) => void;
}> = ({ holidays, reload, onAddAuditLog, onError, year, onYearChange }) => {
  const today = new Date();
  const [viewMonth, setViewMonth] = useState(today.getMonth());
  const [showForm, setShowForm] = useState(false);
  const [editingId, setEditingId] = useState<number | null>(null);
  const [form, setForm] = useState<{ date: string; name: string }>({ date: '', name: '' });
  const [saving, setSaving] = useState(false);
  const [detailDate, setDetailDate] = useState<string | null>(null);

  // Cegah bulan dari tahun lain saat navigasi tahun di header
  useEffect(() => {
    const cur = new Date();
    if (year === cur.getFullYear()) setViewMonth(cur.getMonth());
    else setViewMonth(0);
  }, [year]);

  // Kumpulkan libur per tanggal (map: 'YYYY-MM-DD' → holiday[])
  const byDate = useMemo(() => {
    const map: Record<string, any[]> = {};
    holidays.forEach(h => {
      const d = String(h.date).slice(0, 10);
      (map[d] = map[d] || []).push(h);
    });
    return map;
  }, [holidays]);

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
    let nasional = 0, perusahaan = 0;
    holidays.forEach(h => {
      if (String(h.date).slice(0, 10).startsWith(prefix)) {
        if (h.scope === 'nasional') nasional++;
        else perusahaan++;
      }
    });
    return { nasional, perusahaan };
  }, [holidays, year, viewMonth]);

  const resetForm = () => {
    setForm({ date: '', name: '' });
    setEditingId(null);
    setShowForm(false);
  };

  const startCreate = (date?: string) => {
    // Jika form sedang dalam mode edit, alihkan ke mode tambah; jika tidak, toggle.
    if (editingId !== null) {
      setEditingId(null);
      setForm({ date: date ?? '', name: '' });
      setShowForm(true);
      return;
    }
    setForm({ date: date ?? '', name: '' });
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
      setDetailDate(null);
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
      if (detailDate) setDetailDate(null);
      await reload();
    } catch (err) {
      onError(err, 'Gagal menghapus hari libur.');
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
        <button
          onClick={() => startCreate()}
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
                {summary.nasional} nasional · {summary.perusahaan} perusahaan
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
              const dayHolidays = byDate[dateStr] ?? [];
              const hasNational = dayHolidays.some(h => h.scope === 'nasional');
              const hasCompany = dayHolidays.some(h => h.scope === 'perusahaan');
              const isToday = dateStr === toDateStr(today);
              const isSelected = detailDate === dateStr;
              return (
                <button
                  key={dateStr}
                  onClick={() => {
                    setDetailDate(detailDate === dateStr ? null : dateStr);
                    setShowForm(false);
                  }}
                  className={`relative flex flex-col items-center justify-start h-16 sm:h-20 rounded-lg border text-xs transition-colors cursor-pointer
                    ${hasNational
                      ? 'bg-rose-50 dark:bg-rose-950/30 border-rose-200 dark:border-rose-900/40'
                      : hasCompany
                        ? 'bg-indigo-50 dark:bg-indigo-950/30 border-indigo-200 dark:border-indigo-900/40'
                        : 'bg-slate-50/60 dark:bg-slate-800/40 border-slate-100 dark:border-slate-800 hover:bg-slate-100 dark:hover:bg-slate-800'
                    }
                    ${isSelected ? 'ring-2 ring-indigo-500 border-indigo-400' : ''}`}
                  title={dayHolidays.length ? dayHolidays.map(h => h.name).join(', ') : 'Klik untuk menambah libur'}
                >
                  <span className={`text-[11px] font-bold mt-1 ${isToday ? 'text-indigo-600 dark:text-indigo-400' : 'text-slate-500 dark:text-slate-400'}`}>
                    {day}
                  </span>
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
                <button
                  onClick={() => { startCreate(detailDate); }}
                  className="flex items-center gap-1 text-[10px] font-bold text-indigo-600 dark:text-indigo-400 hover:text-indigo-800 hover:underline transition"
                >
                  <Plus className="w-3 h-3" /> Tambah
                </button>
              </div>
              {selectedHolidays.length === 0 ? (
                <p className="text-[11px] text-slate-400">
                  Tidak ada libur pada tanggal ini. Klik <span className="font-semibold text-indigo-600 dark:text-indigo-400">Tambah</span> untuk membuat libur khusus perusahaan.
                </p>
              ) : (
                <div className="space-y-2">
                  {selectedHolidays.map(h => (
                    <div key={h.id} className={`border rounded-lg p-2.5 ${h.scope === 'nasional' ? 'border-rose-200 dark:border-rose-900/40 bg-rose-50/40 dark:bg-rose-950/20' : 'border-indigo-200 dark:border-indigo-900/40 bg-indigo-50/40 dark:bg-indigo-950/20'}`}>
                      <div className="flex items-center justify-between gap-2">
                        <p className="text-xs font-semibold text-slate-800 dark:text-slate-200">{h.name}</p>
                        <span className={`inline-flex text-[9px] font-bold px-1.5 py-0.5 rounded uppercase tracking-wider shrink-0 ${h.scope === 'nasional' ? 'bg-rose-100 text-rose-700 dark:bg-rose-900/40 dark:text-rose-400' : 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-400'}`}>
                          {h.scope}
                        </span>
                      </div>
                      <div className="flex items-center gap-1 mt-1.5">
                        <button
                          onClick={() => startEdit(h)}
                          className="inline-flex items-center gap-1 px-1.5 py-1 text-sky-600 hover:bg-sky-50 dark:hover:bg-sky-950/30 rounded-md text-[10px] font-medium transition"
                        >
                          <Pencil className="w-3 h-3" /> Ubah
                        </button>
                        <button
                          onClick={() => remove(h)}
                          className="inline-flex items-center gap-1 px-1.5 py-1 text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-950/30 rounded-md text-[10px] font-medium transition"
                        >
                          <Trash2 className="w-3 h-3" /> Hapus
                        </button>
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
                  <span><span className="font-semibold text-rose-700 dark:text-rose-400">Merah</span> — libur nasional (diberlakukan semua perusahaan)</span>
                </li>
                <li className="flex items-center gap-2">
                  <span className="w-4 h-4 rounded-md bg-indigo-50 border border-indigo-200 dark:bg-indigo-950/30 dark:border-indigo-900/40 shrink-0" />
                  <span><span className="font-semibold text-indigo-700 dark:text-indigo-400">Biru</span> — libur khusus perusahaan (cuti bersama internal)</span>
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
    </div>
  );
};
