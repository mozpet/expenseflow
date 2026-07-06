import React, { useState, useMemo, useEffect } from 'react';
import {
  Users, 
  UserPlus, 
  Download, 
  Search, 
  Plus, 
  Check, 
  Clock, 
  Ban, 
  Lock, 
  Edit2, 
  X, 
  Info, 
  AlertTriangle, 
  Eye, 
  EyeOff, 
  ArrowLeft,
  ChevronRight,
  ShieldCheck,
  Building
} from 'lucide-react';
import { ConfirmationDialog } from './ConfirmationDialog';
import { userApi, attendanceApi } from '../services/endpoints';
import { ApiError } from '../services/api';

interface Employee {
  id: string; // employee_code / NIK (tampilan)
  backendId: number; // id numerik backend (untuk aksi API)
  nama: string;
  email: string;
  dept: string;
  jabatan: string; // dipetakan dari role
  role: string;
  hp: string;
  limit: number; // in IDR
  loginTerakhir: string;
  status: 'Aktif' | 'Nonaktif' | 'Belum login';
  initials: string;
  avatarBg: string; // Tailwind class
  avatarColor: string; // Tailwind class
  atasan?: string;
  tanggalMasuk?: string;
  officeId: number | null; // attendance_setting_id — kantor penempatan
  officeName: string; // nama kantor untuk tampilan
}

// Kantor perusahaan (dari attendance_settings) untuk dropdown penempatan.
interface Office {
  id: number;
  office_name: string;
}

const AVATAR_PALETTE = [
  'bg-indigo-50 dark:bg-indigo-950/40 text-indigo-600 dark:text-indigo-400',
  'bg-amber-50 dark:bg-amber-950/40 text-amber-600 dark:text-amber-400',
  'bg-emerald-50 dark:bg-emerald-950/40 text-emerald-600 dark:text-emerald-400',
  'bg-purple-50 dark:bg-purple-950/40 text-purple-600 dark:text-purple-400',
  'bg-sky-50 dark:bg-sky-950/40 text-sky-600 dark:text-sky-455',
];

// Petakan user backend → Employee lokal.
function mapEmployee(u: any): Employee {
  const nama = u.name ?? '';
  const initials = nama.split(/\s+/).map((w: string) => w[0]).slice(0, 2).join('').toUpperCase();
  const palette = AVATAR_PALETTE[(u.id ?? 0) % AVATAR_PALETTE.length].split(' ');
  return {
    id: u.employee_code ?? `EMP-${u.id}`,
    backendId: u.id,
    nama,
    email: u.email ?? '',
    dept: u.department ?? '—',
    jabatan: u.role ?? '—',
    role: u.role ?? 'employee',
    hp: '—',
    limit: Number(u.monthly_claim_limit ?? 0),
    loginTerakhir: '—',
    status: u.is_active === false ? 'Nonaktif' : 'Aktif',
    initials: initials || '?',
    avatarBg: palette.slice(0, 2).join(' '),
    avatarColor: palette.slice(2).join(' '),
    atasan: undefined,
    tanggalMasuk: u.created_at ? String(u.created_at).split('T')[0] : undefined,
    officeId: u.attendance_setting_id ?? null,
    officeName: u.office?.office_name ?? '—',
  };
}

interface ActivityLog {
  waktu: string;
  title: string;
  details: string;
  type: 'info' | 'success' | 'danger';
}

export const KaryawanManagement: React.FC<{
  onAddAuditLog: (title: string, details: string, bg: string) => void;
  onAddNotification: (type: 'due' | 'flag' | 'new' | 'success', title: string, subtitle: string) => void;
}> = ({ onAddAuditLog, onAddNotification }) => {
  // 1. Employee List State — dimuat dari backend.
  const [employees, setEmployees] = useState<Employee[]>([]);
  const [loadingEmployees, setLoadingEmployees] = useState<boolean>(true);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState<boolean>(false);

  // Daftar kantor perusahaan (untuk dropdown penempatan karyawan).
  const [offices, setOffices] = useState<Office[]>([]);

  const loadOffices = async () => {
    try {
      const res: any = await attendanceApi.settings.list();
      const list: Office[] = Array.isArray(res?.settings) ? res.settings
        : Array.isArray(res?.data) ? res.data
        : Array.isArray(res) ? res : [];
      setOffices(list.map((o: any) => ({ id: o.id, office_name: o.office_name })));
    } catch { /* diam — kantor opsional, tidak kritis */ }
  };

  const loadEmployees = async () => {
    setLoadingEmployees(true);
    setLoadError(null);
    try {
      const res: any = await userApi.list();
      const list = Array.isArray(res?.data) ? res.data : Array.isArray(res) ? res : [];
      setEmployees(list.map(mapEmployee));
    } catch (e: any) {
      setLoadError(e?.message ?? 'Gagal memuat karyawan.');
    } finally {
      setLoadingEmployees(false);
    }
  };

  useEffect(() => {
    loadEmployees();
    loadOffices();
  }, []);

  // Aktivitas per karyawan belum tersedia dari API — kosongkan.
  const employeeLogs: Record<string, ActivityLog[]> = {};

  // State Variables
  const [activeTab, setActiveTab] = useState<'all' | 'Aktif' | 'Nonaktif' | 'Belum login'>('all');
  const [selectedDept, setSelectedDept] = useState<string>('Semua dept');
  const [searchQuery, setSearchQuery] = useState<string>('');
  
  // Views/Forms controllers
  const [viewMode, setViewMode] = useState<'list' | 'add'>('list');

  // Modals controllers
  const [editEmployee, setEditEmployee] = useState<Employee | null>(null);
  const [editModalTab, setEditModalTab] = useState<'info' | 'limit' | 'log'>('info');
  const [editForm, setEditForm] = useState({
    nama: '',
    dept: '',
    jabatan: '',
    hp: '',
    limit: 2000000,
    officeId: '' as number | '' // '' = belum ditentukan
  });

  const [resetPwdEmployee, setResetPwdEmployee] = useState<Employee | null>(null);
  const [resetPwdForm, setResetPwdForm] = useState({
    password: 'Reset2026!',
    confirm: 'Reset2026!',
    alasan: 'Karyawan lupa password'
  });
  const [showProgressReset, setShowProgressReset] = useState(false);

  const [nonaktifEmployee, setNonaktifEmployee] = useState<Employee | null>(null);
  const [nonaktifForm, setNonaktifForm] = useState({
    alasan: '',
    catatan: ''
  });
  const [showProgressNonaktif, setShowProgressNonaktif] = useState(false);

  // Add Employee Form State
  const [addForm, setAddForm] = useState({
    nama: '',
    nik: '',
    email: '',
    hp: '',
    tanggalMasuk: new Date().toISOString().split('T')[0],
    dept: '',
    jabatan: '',
    role: 'employee',
    atasan: '',
    officeId: '' as number | '', // '' = belum ditentukan
    limit: 2000000,
    password: 'Maju2026!',
    confirmPassword: 'Maju2026!',
    showPassword: false
  });

  // Reusable General Confirmation Dialog state
  const [confirmDialog, setConfirmDialog] = useState<{
    isOpen: boolean;
    title: string;
    message: string | React.ReactNode;
    confirmText?: string;
    type: 'danger' | 'warning' | 'success' | 'info';
    onConfirm: () => void;
  } | null>(null);

  const handleOpenConfirm = (opts: typeof confirmDialog) => {
    setConfirmDialog(opts);
  };

  // 2. Computed KPI Totals
  const stats = useMemo(() => {
    const total = employees.length;
    const active = employees.filter(e => e.status === 'Aktif').length;
    const blocked = employees.filter(e => e.status === 'Nonaktif').length;
    const notLoggedIn = employees.filter(e => e.status === 'Belum login').length;
    return { total, active, blocked, notLoggedIn };
  }, [employees]);

  // Departemen lists for selector
  const departments = ['Marketing', 'Sales', 'Operations', 'Finance', 'HR', 'IT'];

  // 3. Filter and search logic
  const filteredEmployees = useMemo(() => {
    return employees.filter(e => {
      const matchSearch = e.nama.toLowerCase().includes(searchQuery.toLowerCase()) || 
                          e.id.toLowerCase().includes(searchQuery.toLowerCase()) ||
                          e.email.toLowerCase().includes(searchQuery.toLowerCase());
      const matchDept = selectedDept === 'Semua dept' || e.dept === selectedDept;
      const matchStatus = activeTab === 'all' || e.status === activeTab;
      return matchSearch && matchDept && matchStatus;
    });
  }, [employees, searchQuery, selectedDept, activeTab]);

  // Currency utility formatting helper
  const formatCurrency = (val: number) => {
    return new Intl.NumberFormat('id-ID', {
      style: 'currency',
      currency: 'IDR',
      maximumFractionDigits: 0
    }).format(val);
  };

  // Helper colors
  const getRandomBg = () => {
    const bgs = [
      'bg-indigo-50 dark:bg-indigo-950/40 text-indigo-600 dark:text-indigo-400',
      'bg-amber-50 dark:bg-amber-950/40 text-amber-600 dark:text-amber-400',
      'bg-emerald-50 dark:bg-emerald-950/40 text-emerald-600 dark:text-emerald-400',
      'bg-purple-50 dark:bg-purple-950/40 text-purple-600 dark:text-purple-400',
      'bg-sky-50 dark:bg-sky-950/40 text-sky-600 dark:text-sky-455',
    ];
    return bgs[Math.floor(Math.random() * bgs.length)];
  };

  // 4. Employee operations
  const triggerExport = () => {
    handleOpenConfirm({
      isOpen: true,
      title: 'Ekspor Data Karyawan',
      message: 'Apakah Anda yakin ingin mengekspor seluruh daftar karyawan (.xlsx) untuk laporan HR?',
      confirmText: 'Unduh Excel',
      type: 'info',
      onConfirm: () => {
        onAddAuditLog('Ekspor Excel Karyawan', `Berhasil mengekspor ${employees.length} data karyawan ke Excel oleh Sari Rahma`, 'bg-indigo-600');
        onAddNotification('success', 'Ekspor Excel Berhasil', `${employees.length} data karyawan telah diunduh dengan aman.`);
      }
    });
  };

  const reportApiError = (err: unknown, fallback: string) => {
    if (err instanceof ApiError) {
      const firstError = err.data?.errors && Object.values(err.data.errors)[0];
      alert(Array.isArray(firstError) ? firstError[0] : err.message);
    } else {
      alert(fallback);
    }
  };

  const handleAddNewEmployeeSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!addForm.nama || !addForm.email || !addForm.role) {
      alert('Harap isi nama, email, dan role (*)');
      return;
    }

    if (addForm.password !== addForm.confirmPassword) {
      alert('Password konfirmasi tidak cocok!');
      return;
    }

    setSubmitting(true);
    try {
      await userApi.create({
        name: addForm.nama,
        email: addForm.email,
        password: addForm.password,
        role: addForm.role,
        employee_code: addForm.nik || undefined,
        department: addForm.dept || undefined,
        attendance_setting_id: addForm.officeId === '' ? null : addForm.officeId,
        monthly_claim_limit: addForm.limit,
      });
      await loadEmployees();
      onAddAuditLog('Karyawan Baru Terdaftar', `Menambahkan karyawan baru: ${addForm.nama} - Role: ${addForm.role}`, 'bg-indigo-600');
      onAddNotification('new', 'Karyawan Baru Ditambahkan', `Akun untuk ${addForm.nama} berhasil didaftarkan.`);

      // Reset add state
      setAddForm({
        nama: '',
        nik: '',
        email: '',
        hp: '',
        tanggalMasuk: new Date().toISOString().split('T')[0],
        dept: '',
        jabatan: '',
        role: 'employee',
        atasan: '',
        officeId: '',
        limit: 2000000,
        password: 'Maju2026!',
        confirmPassword: 'Maju2026!',
        showPassword: false
      });
      setViewMode('list');
    } catch (err) {
      reportApiError(err, 'Gagal menambahkan karyawan.');
    } finally {
      setSubmitting(false);
    }
  };

  // Edit employee trigger & save
  const handleOpenEdit = (emp: Employee) => {
    setEditEmployee(emp);
    setEditModalTab('info');
    setEditForm({
      nama: emp.nama,
      dept: emp.dept,
      jabatan: emp.jabatan,
      hp: emp.hp,
      limit: emp.limit,
      officeId: emp.officeId ?? ''
    });
  };

  const handleSaveEditSubmit = async () => {
    if (!editEmployee) return;

    setSubmitting(true);
    try {
      await userApi.update(editEmployee.backendId, {
        name: editForm.nama,
        department: editForm.dept || undefined,
        attendance_setting_id: editForm.officeId === '' ? null : editForm.officeId,
        monthly_claim_limit: editForm.limit,
      });
      await loadEmployees();
      onAddAuditLog('Update Profil Karyawan', `Profil ${editForm.nama} (${editEmployee.id}) diperbarui`, 'bg-indigo-600');
      setEditEmployee(null);
    } catch (err) {
      reportApiError(err, 'Gagal memperbarui karyawan.');
    } finally {
      setSubmitting(false);
    }
  };

  // Reset password logic
  const handleOpenResetPwd = (emp: Employee) => {
    setResetPwdEmployee(emp);
    setResetPwdForm({
      password: 'Reset2026!',
      confirm: 'Reset2026!',
      alasan: 'Karyawan lupa password'
    });
  };

  const handleResetPwdSubmit = async () => {
    if (!resetPwdEmployee) return;
    if (resetPwdForm.password !== resetPwdForm.confirm) {
      alert('Password baru tidak cocok!');
      return;
    }

    setShowProgressReset(true);
    try {
      await userApi.resetPassword(resetPwdEmployee.backendId, resetPwdForm.password);
      onAddAuditLog('Reset Password Karyawan', `Password ${resetPwdEmployee.nama} direset. Alasan: ${resetPwdForm.alasan}`, 'bg-amber-600');
      onAddNotification('flag', 'Reset Password Berhasil', `Sistem mereset akses login untuk ${resetPwdEmployee.nama}.`);
      setResetPwdEmployee(null);
    } catch (err) {
      reportApiError(err, 'Gagal mereset password.');
    } finally {
      setShowProgressReset(false);
    }
  };

  // Deactivate or reactivation toggle
  const handleOpenToggleStatus = (emp: Employee) => {
    if (emp.status === 'Nonaktif') {
      // Simple activate directly (React native confirmation)
      handleOpenConfirm({
        isOpen: true,
        title: 'Aktifkan Akun Karyawan',
        message: `Apakah Anda yakin ingin mengaktifkan kembali akun ${emp.nama} (${emp.id})? Akses masuk ke aplikasi mobile akan langsung terbuka kembali.`,
        confirmText: 'Ya, Aktifkan Kembali',
        type: 'success',
        onConfirm: async () => {
          try {
            await userApi.activate(emp.backendId);
            await loadEmployees();
            onAddAuditLog('Akun Diaktifkan Kembali', `Akun ${emp.nama} (${emp.id}) diaktifkan kembali`, 'bg-emerald-600');
          } catch (err) {
            reportApiError(err, 'Gagal mengaktifkan akun.');
          }
        }
      });
    } else {
      setNonaktifEmployee(emp);
      setNonaktifForm({
        alasan: 'Resign / keluar dari perusahaan',
        catatan: ''
      });
    }
  };

  const handleNonaktifSubmit = async () => {
    if (!nonaktifEmployee) return;
    setShowProgressNonaktif(true);
    try {
      await userApi.deactivate(nonaktifEmployee.backendId);
      await loadEmployees();
      onAddAuditLog('Akun Dinonaktifkan', `Akun ${nonaktifEmployee.nama} (${nonaktifEmployee.id}) dinonaktifkan. Alasan: ${nonaktifForm.alasan}. Catatan: ${nonaktifForm.catatan}`, 'bg-rose-600');
      onAddNotification('flag', 'Akun Dinonaktifkan', `Akun ${nonaktifEmployee.nama} berhasil diblokir.`);
      setNonaktifEmployee(null);
    } catch (err) {
      reportApiError(err, 'Gagal menonaktifkan akun.');
    } finally {
      setShowProgressNonaktif(false);
    }
  };

  return (
    <div className="space-y-6 font-sans">
      
      {/* Dynamic Header top bar */}
      <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl p-5 shadow-xs">
        <div>
          <h3 className="text-sm font-bold text-slate-800 dark:text-slate-100 flex items-center gap-2">
            <Users className="w-5 h-5 text-indigo-650 text-indigo-600 shrink-0" />
            Manajemen Karyawan ExpenseFlow
          </h3>
          <p className="text-xs text-slate-400 dark:text-slate-500 mt-1">
            Persiapan dan monitoring profil limit klaim struk karyawan, reset password oleh HRD, serta deaktifasi akun.
          </p>
        </div>
        <div className="flex gap-2.5 w-full sm:w-auto shrink-0">
          <button 
            onClick={triggerExport}
            className="flex-1 sm:flex-initial flex items-center justify-center gap-1.5 py-2 px-4 rounded-xl text-xs font-semibold text-slate-600 dark:text-slate-350 hover:bg-slate-50 dark:hover:bg-slate-800 transition duration-150 border border-slate-200 dark:border-slate-750 cursor-pointer"
          >
            <Download className="w-3.5 h-3.5 text-indigo-600" />
            <span>Export Excel</span>
          </button>
          
          {viewMode === 'list' && (
            <button 
              onClick={() => setViewMode('add')}
              className="flex-1 sm:flex-initial flex items-center justify-center gap-1.5 py-2 px-4 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-bold shadow-sm shadow-indigo-500/15 transition duration-150 cursor-pointer"
            >
              <UserPlus className="w-3.5 h-3.5" />
              <span>Tambah Karyawan</span>
            </button>
          )}

          {viewMode === 'add' && (
            <button 
              onClick={() => setViewMode('list')}
              className="flex-1 sm:flex-initial flex items-center justify-center gap-1.5 py-2 px-4 bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-705 text-slate-700 dark:text-slate-300 rounded-xl text-xs font-semibold transition duration-150 cursor-pointer"
            >
              <X className="w-3.5 h-3.5" />
              <span>Kembali</span>
            </button>
          )}
        </div>
      </div>

      {viewMode === 'list' ? (
        <>
          {/* Bento-style Statistics Grid (Dynamic) */}
          <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
            <div className="p-4 rounded-2xl bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 shadow-xs flex flex-col justify-between">
              <span className="text-[11px] font-bold text-slate-400 uppercase tracking-widest block">Total Terdaftar</span>
              <div className="mt-2 flex items-baseline gap-1.5">
                <span className="text-2xl font-black text-slate-800 dark:text-slate-100 font-mono">{stats.total}</span>
                <span className="text-[10px] text-slate-400 font-medium">orang</span>
              </div>
              <span className="text-[9px] text-slate-400 dark:text-slate-500 mt-1 block">Telah didaftarkan</span>
            </div>

            <div className="p-4 rounded-2xl bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 shadow-xs flex flex-col justify-between">
              <span className="text-[11px] font-bold text-emerald-600 dark:text-emerald-400 uppercase tracking-widest block">Akun Aktif</span>
              <div className="mt-2 flex items-baseline gap-1.5">
                <span className="text-2xl font-black text-emerald-600 dark:text-emerald-400 font-mono">{stats.active}</span>
                <span className="text-[10px] text-slate-400 font-medium">aktif</span>
              </div>
              <span className="text-[9px] text-slate-400 dark:text-slate-500 mt-1 block">Bisa submit struk</span>
            </div>

            <div className="p-4 rounded-2xl bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 shadow-xs flex flex-col justify-between">
              <span className="text-[11px] font-bold text-rose-600 dark:text-rose-400 uppercase tracking-widest block">Akun Nonaktif</span>
              <div className="mt-2 flex items-baseline gap-1.5">
                <span className="text-2xl font-black text-rose-500 dark:text-rose-400 font-mono">{stats.blocked}</span>
                <span className="text-[10px] text-slate-400 font-medium">terblokir</span>
              </div>
              <span className="text-[9px] text-slate-400 dark:text-slate-500 mt-1 block">Akses login dibekukan</span>
            </div>

            <div className="p-4 rounded-2xl bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 shadow-xs flex flex-col justify-between">
              <span className="text-[11px] font-bold text-amber-600 dark:text-amber-400 uppercase tracking-widest block">Belum Login</span>
              <div className="mt-2 flex items-baseline gap-1.5">
                <span className="text-2xl font-black text-amber-600 dark:text-amber-400 font-mono">{stats.notLoggedIn}</span>
                <span className="text-[10px] text-slate-400 font-medium">antri</span>
              </div>
              <span className="text-[9px] text-slate-400 dark:text-slate-500 mt-1 block">Belum ganti default pwd</span>
            </div>
          </div>

          {/* Interactive Card Table with Filters */}
          <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-3xl p-5 shadow-xs space-y-4">
            
            {/* Filter Bar */}
            <div className="flex flex-col lg:flex-row justify-between items-stretch lg:items-center gap-3 pb-2">
              
              {/* Pill status filter */}
              <div className="flex items-center gap-1.5 overflow-x-auto scroller-hidden">
                <button 
                  onClick={() => setActiveTab('all')}
                  className={`px-3 py-1.5 rounded-full text-[11px] font-bold transition whitespace-nowrap cursor-pointer ${
                    activeTab === 'all' 
                      ? 'bg-indigo-600 text-white' 
                      : 'bg-slate-50 dark:bg-slate-800/40 text-slate-500 hover:text-slate-800 dark:hover:text-slate-250'
                  }`}
                >
                  Semua ({employees.length})
                </button>
                <button 
                  onClick={() => setActiveTab('Aktif')}
                  className={`px-3 py-1.5 rounded-full text-[11px] font-bold transition whitespace-nowrap cursor-pointer ${
                    activeTab === 'Aktif' 
                      ? 'bg-indigo-600 text-white' 
                      : 'bg-slate-50 dark:bg-slate-800/40 text-slate-500 hover:text-slate-800 dark:hover:text-slate-250'
                  }`}
                >
                  Aktif ({employees.filter(e => e.status === 'Aktif').length})
                </button>
                <button 
                  onClick={() => setActiveTab('Nonaktif')}
                  className={`px-3 py-1.5 rounded-full text-[11px] font-bold transition whitespace-nowrap cursor-pointer ${
                    activeTab === 'Nonaktif' 
                      ? 'bg-indigo-600 text-white' 
                      : 'bg-slate-50 dark:bg-slate-800/40 text-slate-500 hover:text-slate-800 dark:hover:text-slate-250'
                  }`}
                >
                  Nonaktif ({employees.filter(e => e.status === 'Nonaktif').length})
                </button>
                <button 
                  onClick={() => setActiveTab('Belum login')}
                  className={`px-3 py-1.5 rounded-full text-[11px] font-bold transition whitespace-nowrap cursor-pointer ${
                    activeTab === 'Belum login' 
                      ? 'bg-indigo-600 text-white' 
                      : 'bg-slate-50 dark:bg-slate-800/40 text-slate-500 hover:text-slate-800 dark:hover:text-slate-250'
                  }`}
                >
                  Belum Login ({employees.filter(e => e.status === 'Belum login').length})
                </button>
              </div>

              {/* Department and Search query inputs */}
              <div className="flex items-center gap-2">
                <select 
                  value={selectedDept}
                  onChange={(e) => setSelectedDept(e.target.value)}
                  className="p-2.5 rounded-xl border border-slate-200 dark:border-slate-800 text-[11px] font-semibold bg-slate-50/50 dark:bg-slate-800/20 text-slate-700 dark:text-slate-300 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                >
                  <option value="Semua dept">Semua dept</option>
                  {departments.map(d => (
                    <option key={d} value={d}>{d}</option>
                  ))}
                </select>

                <div className="relative flex-1 min-w-[160px]">
                  <Search className="absolute left-3 top-2.5 w-4 h-4 text-slate-400" />
                  <input 
                    type="text" 
                    placeholder="Cari nama / NIK..."
                    value={searchQuery}
                    onChange={(e) => setSearchQuery(e.target.value)}
                    className="w-full pl-9 pr-4 py-2 border border-slate-200 dark:border-slate-700 rounded-xl text-xs bg-slate-50 dark:bg-slate-800/50 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-1 focus:ring-indigo-500 transition"
                  />
                </div>
              </div>

            </div>

            {/* Table Area */}
            <div className="overflow-x-auto border border-slate-100 dark:border-slate-800 rounded-2xl">
              <table className="w-full text-left border-collapse min-w-[800px]">
                <thead>
                  <tr className="bg-slate-50/70 dark:bg-slate-800/20 border-b border-slate-100 dark:border-slate-800">
                    <th className="py-3 px-4 text-[11px] font-bold text-slate-400 uppercase tracking-widest" style={{ width: '180px' }}>Karyawan</th>
                    <th className="py-3 px-4 text-[11px] font-bold text-slate-400 uppercase tracking-widest" style={{ width: '100px' }}>NIK</th>
                    <th className="py-3 px-4 text-[11px] font-bold text-slate-400 uppercase tracking-widest" style={{ width: '110px' }}>Departemen</th>
                    <th className="py-3 px-4 text-[11px] font-bold text-slate-400 uppercase tracking-widest" style={{ width: '110px' }}>Jabatan</th>
                    <th className="py-3 px-4 text-[11px] font-bold text-slate-400 uppercase tracking-widest" style={{ width: '120px' }}>Batas Klaim</th>
                    <th className="py-3 px-4 text-[11px] font-bold text-slate-400 uppercase tracking-widest" style={{ width: '120px' }}>Login Terakhir</th>
                    <th className="py-3 px-4 text-[11px] font-bold text-slate-400 uppercase tracking-widest" style={{ width: '90px' }}>Status</th>
                    <th className="py-3 px-4 text-[11px] font-bold text-slate-400 uppercase tracking-widest" style={{ width: '220px' }}>Aksi</th>
                  </tr>
                </thead>
                <tbody>
                  {loadingEmployees ? (
                    [1, 2, 3, 4, 5].map((i) => (
                      <tr key={`skeleton-${i}`} className="border-b border-slate-100 dark:border-slate-805/40 animate-pulse">
                        <td className="py-3 px-4">
                          <div className="flex items-center gap-2.5">
                            <div className="w-[30px] h-[30px] rounded-full bg-slate-200 dark:bg-slate-700 shrink-0" />
                            <div className="min-w-0 space-y-1.5 w-full">
                              <div className="h-3 bg-slate-200 dark:bg-slate-700 rounded w-24" />
                              <div className="h-2 bg-slate-200 dark:bg-slate-700 rounded w-32" />
                            </div>
                          </div>
                        </td>
                        <td className="py-3 px-4">
                          <div className="h-3 bg-slate-200 dark:bg-slate-700 rounded w-16" />
                        </td>
                        <td className="py-3 px-4 space-y-1.5">
                          <div className="h-3 bg-slate-200 dark:bg-slate-700 rounded w-20" />
                          <div className="h-2 bg-slate-200 dark:bg-slate-700 rounded w-14" />
                        </td>
                        <td className="py-3 px-4">
                          <div className="h-3 bg-slate-200 dark:bg-slate-700 rounded w-16" />
                        </td>
                        <td className="py-3 px-4">
                          <div className="h-3 bg-slate-200 dark:bg-slate-700 rounded w-24" />
                        </td>
                        <td className="py-3 px-4">
                          <div className="h-3 bg-slate-200 dark:bg-slate-700 rounded w-20" />
                        </td>
                        <td className="py-3 px-4">
                          <div className="h-5 bg-slate-200 dark:bg-slate-700 rounded-full w-14" />
                        </td>
                        <td className="py-3 px-4">
                          <div className="flex gap-1.5">
                            <div className="h-6 bg-slate-200 dark:bg-slate-700 rounded-lg w-12" />
                            <div className="h-6 bg-slate-200 dark:bg-slate-700 rounded-lg w-16" />
                            <div className="h-6 bg-slate-200 dark:bg-slate-700 rounded-lg w-16" />
                          </div>
                        </td>
                      </tr>
                    ))
                  ) : filteredEmployees.length === 0 ? (
                    <tr>
                      <td colSpan={8} className="py-12 text-center text-xs text-slate-450 dark:text-slate-500">
                        Tidak ada data karyawan yang cocok dengan pencarian dan filter Anda.
                      </td>
                    </tr>
                  ) : (
                    filteredEmployees.map((emp) => (
                      <tr 
                        key={emp.id} 
                        className="hover:bg-slate-50/40 dark:hover:bg-slate-850/10 border-b border-slate-100 dark:border-slate-805/40 transition last:border-b-0"
                      >
                        {/* Karyawan Profile */}
                        <td className="py-3 px-4">
                          <div className="flex items-center gap-2.5">
                            <span className={`w-7.5 h-7.5 rounded-full flex items-center justify-center font-bold text-xs shrink-0 select-none ${emp.avatarBg} ${emp.avatarColor}`}>
                              {emp.initials}
                            </span>
                            <div className="min-w-0">
                              <p className="text-xs font-bold text-slate-800 dark:text-slate-100 truncate leading-tight">{emp.nama}</p>
                              <p className="text-[10px] text-slate-400 dark:text-slate-500 truncate mt-0.5">{emp.email}</p>
                            </div>
                          </div>
                        </td>

                        {/* NIK */}
                        <td className="py-3 px-4">
                          <span className="text-[11px] font-semibold text-slate-500 font-mono tracking-tight">{emp.id}</span>
                        </td>

                        {/* Dept + Kantor */}
                        <td className="py-3 px-4">
                          <span className="text-xs font-semibold text-slate-700 dark:text-slate-350 block">{emp.dept}</span>
                          {emp.officeId && (
                            <span className="text-[10px] text-indigo-500 dark:text-indigo-400 flex items-center gap-1 mt-0.5">
                              <Building className="w-3 h-3 shrink-0" />
                              {emp.officeName}
                            </span>
                          )}
                        </td>

                        {/* Jabatan */}
                        <td className="py-3 px-4">
                          <span className="text-[11px] text-slate-500 dark:text-slate-400">{emp.jabatan}</span>
                        </td>

                        {/* Limit */}
                        <td className="py-3 px-4">
                          <span className="text-xs font-bold text-slate-800 dark:text-slate-200 font-mono">{formatCurrency(emp.limit)}</span>
                        </td>

                        {/* Last Login */}
                        <td className="py-3 px-4">
                          {emp.loginTerakhir === 'Belum pernah' ? (
                            <span className="text-[10px] text-amber-600 dark:text-amber-500 font-semibold">{emp.loginTerakhir}</span>
                          ) : (
                            <span className="text-[10px] text-slate-500 dark:text-slate-400">{emp.loginTerakhir}</span>
                          )}
                        </td>

                        {/* Status badge */}
                        <td className="py-3 px-4">
                          {emp.status === 'Aktif' && (
                            <span className="inline-flex items-center gap-1.5 px-2 py-1 text-[10px] font-bold text-emerald-600 dark:text-emerald-450 bg-emerald-50 dark:bg-emerald-950/20 rounded-full">
                              <Check className="w-3 h-3 shrink-0" />
                              <span>Aktif</span>
                            </span>
                          )}
                          {emp.status === 'Nonaktif' && (
                            <span className="inline-flex items-center gap-1.5 px-2 py-0.5 text-[10px] font-bold text-rose-600 dark:text-rose-450 bg-rose-50 dark:bg-rose-950/20 rounded-full">
                              <Ban className="w-3 h-3 shrink-0" />
                              <span>Nonaktif</span>
                            </span>
                          )}
                          {emp.status === 'Belum login' && (
                            <span className="inline-flex items-center gap-1.5 px-2 py-0.5 text-[10px] font-bold text-amber-600 dark:text-amber-450 bg-amber-50 dark:bg-amber-950/20 rounded-full whitespace-nowrap">
                              <Clock className="w-3 h-3 shrink-0" />
                              <span>Belum login</span>
                            </span>
                          )}
                        </td>

                        {/* Action Buttons */}
                        <td className="py-3 px-4">
                          <div className="flex flex-wrap gap-1.5">
                            <button 
                              onClick={() => handleOpenEdit(emp)}
                              className="p-1 px-2.5 bg-slate-50 hover:bg-slate-100 dark:bg-slate-800 dark:hover:bg-slate-705 border border-slate-200 dark:border-slate-750 text-slate-600 dark:text-slate-300 rounded-lg text-[10px] font-semibold transition cursor-pointer flex items-center gap-1"
                            >
                              <Edit2 className="w-3 h-3" />
                              <span>Edit</span>
                            </button>
                            
                            {emp.status !== 'Nonaktif' ? (
                              <>
                                <button 
                                  onClick={() => handleOpenResetPwd(emp)}
                                  className="p-1 px-2 bg-amber-500 hover:bg-amber-600 dark:bg-amber-600 dark:hover:bg-amber-700 text-white rounded-lg text-[10px] font-semibold transition cursor-pointer flex items-center gap-1"
                                >
                                  <Lock className="w-3 h-3" />
                                  <span>Reset pwd</span>
                                </button>
                                
                                <button 
                                  onClick={() => handleOpenToggleStatus(emp)}
                                  className="p-1 px-2.5 bg-rose-600 hover:bg-rose-700 text-white rounded-lg text-[10px] font-semibold transition cursor-pointer flex items-center gap-1 font-sans"
                                >
                                  <Ban className="w-3 h-3" />
                                  <span>Nonaktif</span>
                                </button>
                              </>
                            ) : (
                              <button 
                                onClick={() => handleOpenToggleStatus(emp)}
                                className="p-1 px-2.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-[10px] font-bold transition cursor-pointer flex items-center gap-1"
                              >
                                <Check className="w-3 h-3" />
                                <span>Aktifkan</span>
                              </button>
                            )}
                          </div>
                        </td>

                      </tr>
                    ))
                  )}
                </tbody>
              </table>
            </div>

          </div>
        </>
      ) : (
        /* TAMBAH KARYAWAN WEB FORM (Matching the provided HTML Layout nicely but fully interactive!) */
        <form onSubmit={handleAddNewEmployeeSubmit} className="grid grid-cols-1 lg:grid-cols-2 gap-6 leading-relaxed">
          <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-3xl p-6 shadow-xs space-y-4">
            <div className="flex justify-between items-center pb-3 border-b border-slate-100 dark:border-slate-800">
              <span className="text-xs font-bold text-slate-850 dark:text-slate-100 flex items-center gap-2">
                <UserPlus className="w-4 h-4 text-indigo-600" />
                Data Karyawan Baru
              </span>
            </div>

            <div className="space-y-3.5">
              <span className="text-[10px] font-extrabold text-slate-400 dark:text-slate-500 uppercase tracking-widest block pb-1 border-b border-light-divider dark:border-slate-800/80 mb-3">Data Pribadi</span>
              
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                <div className="space-y-1">
                  <label className="text-[10px] font-bold text-slate-400 dark:text-slate-500 block">Nama Lengkap *</label>
                  <input 
                    type="text" 
                    value={addForm.nama}
                    onChange={(e) => setAddForm({ ...addForm, nama: e.target.value })}
                    required
                    placeholder="Nama sesuai KTP"
                    className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-800/10 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                  />
                </div>
                <div className="space-y-1">
                  <label className="text-[10px] font-bold text-slate-400 dark:text-slate-500 block">NIK Karyawan *</label>
                  <input 
                    type="text" 
                    value={addForm.nik}
                    onChange={(e) => setAddForm({ ...addForm, nik: e.target.value })}
                    required
                    placeholder="EMP-XXXX"
                    className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-800/10 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-1 focus:ring-indigo-500 font-mono"
                  />
                </div>
              </div>

              <div className="space-y-1">
                <label className="text-[10px] font-bold text-slate-400 dark:text-slate-500 block">
                  Email Kantor * <span className="text-[9px] text-slate-400 dark:text-slate-500 font-normal">(dipakai untuk login)</span>
                </label>
                <input 
                  type="email" 
                  value={addForm.email}
                  onChange={(e) => setAddForm({ ...addForm, email: e.target.value })}
                  required
                  placeholder="nama@perusahaan.co.id"
                  className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-808/10 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                />
              </div>

              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                <div className="space-y-1">
                  <label className="text-[10px] font-bold text-slate-400 dark:text-slate-500 block">Nomor HP</label>
                  <input 
                    type="text" 
                    value={addForm.hp}
                    onChange={(e) => setAddForm({ ...addForm, hp: e.target.value })}
                    placeholder="08xx-xxxx-xxxx"
                    className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-801/10 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                  />
                </div>
                <div className="space-y-1">
                  <label className="text-[10px] font-bold text-slate-400 dark:text-slate-500 block">Tanggal Masuk *</label>
                  <input 
                    type="date" 
                    value={addForm.tanggalMasuk}
                    onChange={(e) => setAddForm({ ...addForm, tanggalMasuk: e.target.value })}
                    required
                    className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-801/10 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                  />
                </div>
              </div>

              <span className="text-[10px] font-extrabold text-slate-400 dark:text-slate-500 uppercase tracking-widest block pb-1 border-b border-light-divider dark:border-slate-800/80 pt-4 mb-3">Jabatan & Departemen</span>
              
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                <div className="space-y-1">
                  <label className="text-[10px] font-bold text-slate-400 dark:text-slate-500 block">Departemen *</label>
                  <select 
                    value={addForm.dept}
                    onChange={(e) => setAddForm({ ...addForm, dept: e.target.value })}
                    required
                    className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-802/10 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-1 focus:ring-indigo-500 font-semibold"
                  >
                    <option value="">Pilih dept</option>
                    {departments.map(d => (
                      <option key={d} value={d}>{d}</option>
                    ))}
                  </select>
                </div>
                <div className="space-y-1">
                  <label className="text-[10px] font-bold text-slate-400 dark:text-slate-500 block">Jabatan</label>
                  <input
                    type="text"
                    value={addForm.jabatan}
                    onChange={(e) => setAddForm({ ...addForm, jabatan: e.target.value })}
                    placeholder="Staff / Supervisor / ..."
                    className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-803/10 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                  />
                </div>
              </div>

              <div className="space-y-1">
                <label className="text-[10px] font-bold text-slate-400 dark:text-slate-500 block">Kantor Penempatan</label>
                <select
                  value={addForm.officeId}
                  onChange={(e) => setAddForm({ ...addForm, officeId: e.target.value === '' ? '' : Number(e.target.value) })}
                  className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-802/10 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-1 focus:ring-indigo-500 font-semibold"
                >
                  <option value="">Belum ditentukan</option>
                  {offices.map(o => (
                    <option key={o.id} value={o.id}>{o.office_name}</option>
                  ))}
                </select>
                {offices.length === 0 && (
                  <p className="text-[10px] text-amber-500">Belum ada kantor. Tambahkan di menu Presensi & Cuti → Kantor.</p>
                )}
              </div>

              <div className="space-y-1">
                <label className="text-[10px] font-bold text-slate-400 dark:text-slate-500 block">Role Sistem *</label>
                <select
                  value={addForm.role}
                  onChange={(e) => setAddForm({ ...addForm, role: e.target.value })}
                  required
                  className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-802/10 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-1 focus:ring-indigo-500 font-semibold"
                >
                  <option value="employee">Employee (karyawan — mobile)</option>
                  <option value="finance">Finance</option>
                  <option value="hrd">HRD</option>
                  <option value="admin">Admin</option>
                  <option value="super_admin">Super Admin</option>
                </select>
              </div>

              <div className="space-y-1">
                <label className="text-[10px] font-bold text-slate-400 dark:text-slate-500 block">Atasan Langsung</label>
                <select 
                  value={addForm.atasan}
                  onChange={(e) => setAddForm({ ...addForm, atasan: e.target.value })}
                  className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-804/10 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                >
                  <option value="">Pilih atasan</option>
                  <option value="Budi Santoso — Direktur">Budi Santoso — Direktur</option>
                  <option value="Eko Prasetyo — Manajer Ops">Eko Prasetyo — Manajer Ops</option>
                  <option value="Diana Hartati — HRD Manager">Diana Hartati — HRD Manager</option>
                </select>
              </div>

              <div className="space-y-1">
                <label className="text-[10px] font-bold text-slate-400 dark:text-slate-500 block">Batas Klaim Bulanan *</label>
                <select 
                  value={addForm.limit}
                  onChange={(e) => setAddForm({ ...addForm, limit: Number(e.target.value) })}
                  className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-805/10 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-1 focus:ring-indigo-500 font-semibold"
                >
                  <option value={1000000}>Rp 1.000.000</option>
                  <option value={1500000}>Rp 1.500.000</option>
                  <option value={2000000}>Rp 2.000.000</option>
                  <option value={3000000}>Rp 3.000.000</option>
                  <option value={5000000}>Rp 5.000.000</option>
                </select>
              </div>

            </div>
          </div>

          <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-3xl p-6 shadow-xs space-y-4">
            <span className="text-[10px] font-extrabold text-slate-400 dark:text-slate-500 uppercase tracking-widest block pb-1 border-b border-light-divider dark:border-slate-800/80 mb-3">Role di Sistem</span>
            
            <div className="bg-indigo-50/40 dark:bg-indigo-950/10 border border-indigo-100 dark:border-indigo-900/40 p-3.5 rounded-2xl text-xs text-indigo-900 dark:text-indigo-400 space-y-3">
              <div className="flex items-center gap-2 font-bold leading-tight">
                <Lock className="w-4 h-4 text-indigo-650 text-indigo-600 shrink-0" />
                <span>Form ini hanya untuk karyawan baru. Role otomatis diset sebagai KARYAWAN.</span>
              </div>
              <div className="space-y-1.5 pl-6 text-[11px] leading-relaxed">
                <div className="flex items-center gap-2"><Check className="w-3.5 h-3.5 text-emerald-600" /> <span>Login di aplikasi mobile Flutter</span></div>
                <div className="flex items-center gap-2"><Check className="w-3.5 h-3.5 text-emerald-600" /> <span>Submit & foto struk pengeluaran</span></div>
                <div className="flex items-center gap-2"><Check className="w-3.5 h-3.5 text-emerald-600" /> <span>Pantau status pengajuan sendiri</span></div>
                <div className="flex items-center gap-2 text-rose-550/70"><X className="w-3.5 h-3.5 text-rose-500" /> <span>Login di website finance (Akses ditolak)</span></div>
                <div className="flex items-center gap-2 text-rose-550/70"><X className="w-3.5 h-3.5 text-rose-500" /> <span>Approve / lihat data orang lain (Akses ditolak)</span></div>
              </div>
            </div>

            <span className="text-[10px] font-extrabold text-slate-400 dark:text-slate-500 uppercase tracking-widest block pb-1 border-b border-light-divider dark:border-slate-800/80 pt-4 mb-3">Password Awal</span>
            <div className="bg-indigo-50/40 dark:bg-indigo-950/10 border border-indigo-100 dark:border-indigo-900/40 p-3.5 rounded-2xl text-[11px] text-indigo-900 dark:text-indigo-400 leading-relaxed">
              HRD menentukan sandi awal, lalu memberitahu karyawan secara langsung. Karyawan wajib mengubah sandinya pada saat login pertama kali di mobile app.
            </div>

            <div className="space-y-3.5">
              <div className="space-y-1 relative">
                <label className="text-[10px] font-bold text-slate-400 dark:text-slate-500 block">Password Awal *</label>
                <div className="relative">
                  <input 
                    type={addForm.showPassword ? 'text' : 'password'}
                    value={addForm.password}
                    onChange={(e) => setAddForm({ ...addForm, password: e.target.value })}
                    required
                    className="w-full text-xs p-2.5 pr-10 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-805/10 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-1 focus:ring-indigo-500 font-mono"
                  />
                  <button 
                    type="button"
                    onClick={() => setAddForm({ ...addForm, showPassword: !addForm.showPassword })}
                    className="absolute right-3 top-2.5 text-slate-400 dark:text-slate-500 hover:text-slate-650 transition"
                  >
                    {addForm.showPassword ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
                  </button>
                </div>
                <div className="flex gap-1 mt-1.5">
                  <span className="h-1 flex-1 rounded bg-emerald-500"></span>
                  <span className="h-1 flex-1 rounded bg-emerald-500"></span>
                  <span className="h-1 flex-1 rounded bg-emerald-500"></span>
                  <span className="h-1 flex-1 rounded bg-slate-200 dark:bg-slate-800"></span>
                </div>
                <span className="text-[10px] text-emerald-600 block leading-none pt-0.5">Cukup kuat</span>
              </div>

              <div className="space-y-1">
                <label className="text-[10px] font-bold text-slate-400 dark:text-slate-500 block">Konfirmasi Password *</label>
                <input 
                  type="password"
                  value={addForm.confirmPassword}
                  onChange={(e) => setAddForm({ ...addForm, confirmPassword: e.target.value })}
                  required
                  className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-805/10 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-1 focus:ring-indigo-500 font-mono"
                />
              </div>

              {addForm.password === addForm.confirmPassword ? (
                <div className="p-2.5 px-3 bg-emerald-50 dark:bg-emerald-950/20 border border-emerald-100 dark:border-emerald-900/30 rounded-xl text-emerald-700 dark:text-emerald-400 text-xs flex items-center gap-1.5">
                  <Check className="w-4 h-4 shrink-0" />
                  <span>Password terkonfirmasi cocok.</span>
                </div>
              ) : (
                <div className="p-2.5 px-3 bg-rose-50 dark:bg-rose-950/20 border border-rose-100 dark:border-rose-900/30 rounded-xl text-rose-600 dark:text-rose-450 text-xs flex items-center gap-1.5">
                  <X className="w-4 h-4 shrink-0" />
                  <span>Password tidak cocok.</span>
                </div>
              )}

              <div className="flex gap-2.5 pt-4">
                <button 
                  type="button"
                  onClick={() => setViewMode('list')}
                  className="flex-1 py-3 border border-slate-200 dark:border-slate-800 dark:hover:bg-slate-805 text-slate-600 dark:text-slate-350 rounded-xl text-xs font-semibold transition cursor-pointer"
                >
                  Batal
                </button>
                <button 
                  type="submit"
                  disabled={addForm.password !== addForm.confirmPassword}
                  className="flex-1 py-3 bg-indigo-650 bg-indigo-600 hover:bg-indigo-750 text-white font-bold text-xs rounded-xl shadow-md transition cursor-pointer disabled:opacity-50"
                >
                  Simpan Karyawan
                </button>
              </div>

            </div>
          </div>
        </form>
      )}

      {/* MODAL EDIT DATA KARYAWAN (Tabs: Data & jabatan, Batas klaim, Riwayat aktivitas) */}
      {editEmployee && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
          <div onClick={() => setEditEmployee(null)} className="fixed inset-0 bg-slate-900/60 dark:bg-slate-950/80 backdrop-blur-xs" />
          
          <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-100 dark:border-slate-800 w-full max-w-lg p-6 shadow-2xl relative z-10 overflow-hidden leading-relaxed max-h-[90vh] flex flex-col">
            
            {/* Modal Header */}
            <div className="flex justify-between items-center pb-3 border-b border-slate-100 dark:border-slate-800 shrink-0">
              <h3 className="text-sm font-bold text-slate-850 dark:text-slate-100 flex items-center gap-1.5">
                <Edit2 className="w-4.5 h-4.5 text-indigo-600" />
                Edit Data Karyawan
              </h3>
              <button onClick={() => setEditEmployee(null)} className="p-1 hover:bg-slate-50 dark:hover:bg-slate-800 rounded-full text-slate-400">
                <X className="w-4.5 h-4.5" />
              </button>
            </div>

            {/* Modal tab selectors */}
            <div className="flex items-center gap-1.5 py-3 border-b border-slate-100 dark:border-slate-800 shrink-0">
              <button 
                onClick={() => setEditModalTab('info')}
                className={`px-3 py-1.5 rounded-lg text-xs font-semibold transition cursor-pointer ${
                  editModalTab === 'info' 
                    ? 'bg-indigo-50 text-indigo-700 dark:bg-indigo-950/40 dark:text-indigo-400' 
                    : 'text-slate-500 hover:text-slate-800 dark:hover:text-slate-300'
                }`}
              >
                Data & Jabatan
              </button>
              <button 
                onClick={() => setEditModalTab('limit')}
                className={`px-3 py-1.5 rounded-lg text-xs font-semibold transition cursor-pointer ${
                  editModalTab === 'limit' 
                    ? 'bg-indigo-50 text-indigo-700 dark:bg-indigo-950/40 dark:text-indigo-400' 
                    : 'text-slate-500 hover:text-slate-800 dark:hover:text-slate-300'
                }`}
              >
                Batas Klaim
              </button>
              <button 
                onClick={() => setEditModalTab('log')}
                className={`px-3 py-1.5 rounded-lg text-xs font-semibold transition cursor-pointer ${
                  editModalTab === 'log' 
                    ? 'bg-indigo-50 text-indigo-700 dark:bg-indigo-950/40 dark:text-indigo-400' 
                    : 'text-slate-500 hover:text-slate-800 dark:hover:text-slate-300'
                }`}
              >
                Riwayat Aktivitas
              </button>
            </div>

            {/* Modal content body */}
            <div className="py-4 space-y-4 overflow-y-auto flex-1">
              
              {editModalTab === 'info' && (
                <div className="space-y-3.5">
                  <div className="grid grid-cols-2 gap-3.5">
                    <div className="space-y-1">
                      <label className="text-[10px] font-bold text-slate-400 dark:text-slate-500 block">Nama Lengkap *</label>
                      <input 
                        type="text" 
                        value={editForm.nama}
                        onChange={(e) => setEditForm({...editForm, nama: e.target.value})}
                        className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-805/10 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                      />
                    </div>
                    <div className="space-y-1">
                      <label className="text-[10px] font-bold text-slate-400 dark:text-slate-505 block">NIK (tidak bisa diubah)</label>
                      <input 
                        type="text" 
                        value={editEmployee.id}
                        disabled
                        className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-100/70 dark:bg-slate-800/40 text-slate-505 dark:text-slate-450 font-mono focus:outline-none"
                      />
                    </div>
                  </div>

                  <div className="space-y-1">
                    <label className="text-[10px] font-bold text-slate-400 dark:text-slate-505 block">Email Kantor (tidak bisa diubah)</label>
                    <input 
                      type="text" 
                      value={editEmployee.email}
                      disabled
                      className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-100/70 dark:bg-slate-800/40 text-slate-505 dark:text-slate-450 focus:outline-none"
                    />
                  </div>

                  <div className="grid grid-cols-2 gap-3.5">
                    <div className="space-y-1">
                      <label className="text-[10px] font-bold text-slate-400 dark:text-slate-500 block">Departemen</label>
                      <select 
                        value={editForm.dept}
                        onChange={(e) => setEditForm({...editForm, dept: e.target.value})}
                        className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-805/10 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                      >
                        {departments.map(d => (
                          <option key={d} value={d}>{d}</option>
                        ))}
                      </select>
                    </div>
                    <div className="space-y-1">
                      <label className="text-[10px] font-bold text-slate-400 dark:text-slate-500 block">Jabatan *</label>
                      <input 
                        type="text" 
                        value={editForm.jabatan}
                        onChange={(e) => setEditForm({...editForm, jabatan: e.target.value})}
                        className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-805/10 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                      />
                    </div>
                  </div>

                  <div className="space-y-1">
                    <label className="text-[10px] font-bold text-slate-400 dark:text-slate-500 block">Kantor Penempatan</label>
                    <select
                      value={editForm.officeId}
                      onChange={(e) => setEditForm({...editForm, officeId: e.target.value === '' ? '' : Number(e.target.value)})}
                      className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-805/10 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-1 focus:ring-indigo-500 font-semibold"
                    >
                      <option value="">Belum ditentukan</option>
                      {offices.map(o => (
                        <option key={o.id} value={o.id}>{o.office_name}</option>
                      ))}
                    </select>
                  </div>

                  <div className="space-y-1">
                    <label className="text-[10px] font-bold text-slate-400 dark:text-slate-500 block">Nomor HP</label>
                    <input
                      type="text"
                      value={editForm.hp}
                      onChange={(e) => setEditForm({...editForm, hp: e.target.value})}
                      className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-805/10 text-slate-800 dark:text-slate-100 focus:outline-none"
                    />
                  </div>

                  <div className="p-3 bg-indigo-50/40 dark:bg-indigo-950/15 border border-indigo-100 dark:border-indigo-900/30 rounded-xl text-[11px] text-indigo-900 dark:text-indigo-400 flex items-start gap-1.5 leading-relaxed">
                    <Info className="w-4.5 h-4.5 shrink-0 text-indigo-600" />
                    <span>Email dan NIK tidak bisa diubah karena digunakan sebagai identitas audit log dan otentikasi login mobile app. Jika ada kesalahan input, hubungi Super Admin.</span>
                  </div>
                </div>
              )}

              {editModalTab === 'limit' && (
                <div className="space-y-3.5">
                  <div className="space-y-1">
                    <label className="text-[10px] font-bold text-slate-400 dark:text-slate-500 block">Batas Klaim Bulanan Aktif saat ini</label>
                    <input 
                      type="text" 
                      value={formatCurrency(editEmployee.limit)}
                      disabled
                      className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-100/70 dark:bg-slate-800/40 text-slate-600 dark:text-slate-350 font-semibold font-mono"
                    />
                  </div>

                  <div className="space-y-1">
                    <label className="text-[10px] font-bold text-slate-400 dark:text-slate-500 block">Ubah Batas Klaim Baru</label>
                    <select 
                      value={editForm.limit}
                      onChange={(e) => setEditForm({...editForm, limit: Number(e.target.value)})}
                      className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-805/10 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-1 focus:ring-indigo-500 font-semibold font-mono"
                    >
                      <option value={1000000}>Rp 1.000.000</option>
                      <option value={1500000}>Rp 1.500.000</option>
                      <option value={2000000}>Rp 2.000.000</option>
                      <option value={3000000}>Rp 3.000.000</option>
                      <option value={5000000}>Rp 5.000.000</option>
                    </select>
                  </div>

                  <div className="p-3 bg-amber-50 dark:bg-amber-950/20 border border-amber-100 dark:border-amber-900/30 rounded-xl text-[11px] text-amber-800 dark:text-amber-400 flex items-start gap-1.5 leading-relaxed">
                    <AlertTriangle className="w-4.5 h-4.5 shrink-0 text-amber-600" />
                    <span>Perubahan batas klaim berlaku mulai dari bulan berikutnya, dan seluruh detail perubahan akan dicatat dalam Audit Log Keuangan sistem.</span>
                  </div>
                </div>
              )}

              {editModalTab === 'log' && (
                <div className="space-y-3 max-h-72 overflow-y-auto">
                  {(employeeLogs[editEmployee.id] || []).length === 0 ? (
                    <p className="text-center text-xs text-slate-400 py-6">Karyawan ini belum memiliki aktivitas yang dicatat di sistem.</p>
                  ) : (
                    employeeLogs[editEmployee.id].map((lg, idx) => (
                      <div key={idx} className="flex gap-3 items-start border-b border-slate-100 dark:border-slate-800/70 pb-3 last:border-b-0 last:pb-0">
                        <span className={`w-2.5 h-2.5 mt-1 rounded-full shrink-0 ${
                          lg.type === 'success' ? 'bg-emerald-500' : lg.type === 'danger' ? 'bg-rose-500' : 'bg-indigo-500'
                        }`} />
                        <div className="flex-1 text-[11px] leading-relaxed">
                          <p className="text-slate-800 dark:text-slate-250 font-bold">{lg.title}</p>
                          <p className="text-slate-400 mt-0.5">{lg.details}</p>
                          <span className="text-[10px] text-slate-400 font-mono block mt-1">{lg.waktu}</span>
                        </div>
                      </div>
                    ))
                  )}
                </div>
              )}

            </div>

            {/* Modal actions bar */}
            <div className="flex gap-2.5 pt-3 border-t border-slate-150 dark:border-slate-800/80 shrink-0">
              <button 
                type="button"
                onClick={() => setEditEmployee(null)}
                className="flex-1 py-2.5 border border-slate-200 dark:border-slate-800 dark:hover:bg-slate-805 text-slate-600 dark:text-slate-400 rounded-xl text-xs font-semibold hover:bg-slate-50 cursor-pointer"
              >
                Batal
              </button>
              <button 
                type="button"
                onClick={handleSaveEditSubmit}
                className="flex-1 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white font-bold rounded-xl text-xs shadow-sm shadow-indigo-500/10 cursor-pointer flex items-center justify-center gap-1.5"
              >
                <Check className="w-3.5 h-3.5" />
                <span>Simpan Perubahan</span>
              </button>
            </div>

          </div>
        </div>
      )}

      {/* MODAL RESET PASSWORD KARYAWAN */}
      {resetPwdEmployee && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
          <div onClick={() => setResetPwdEmployee(null)} className="fixed inset-0 bg-slate-900/60 dark:bg-slate-950/80 backdrop-blur-sm" />
          
          <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-100 dark:border-slate-800 w-full max-w-md p-6 shadow-2xl relative z-10 leading-relaxed overflow-hidden">
            <div className="flex justify-between items-center pb-3 border-b border-slate-100 dark:border-slate-800">
              <h3 className="text-sm font-bold text-slate-850 dark:text-slate-100 flex items-center gap-1.5">
                <Lock className="w-4.5 h-4.5 text-amber-500" />
                Reset Password Karyawan
              </h3>
              <button onClick={() => setResetPwdEmployee(null)} className="p-1 hover:bg-slate-50 dark:hover:bg-slate-850 rounded-full text-slate-400">
                <X className="w-4.5 h-4.5" />
              </button>
            </div>

            <div className="py-4 space-y-4">
              <div className="flex items-center gap-2.5 bg-slate-50 dark:bg-slate-800/40 p-3 rounded-xl border border-slate-100 dark:border-slate-800">
                <span className={`w-8 h-8 rounded-full flex items-center justify-center font-bold text-xs shrink-0 select-none ${resetPwdEmployee.avatarBg} ${resetPwdEmployee.avatarColor}`}>
                  {resetPwdEmployee.initials}
                </span>
                <div className="min-w-0">
                  <p className="text-xs font-bold text-slate-800 dark:text-slate-100 truncate">{resetPwdEmployee.nama}</p>
                  <p className="text-[10px] text-slate-400 dark:text-slate-505 truncate mt-0.5">{resetPwdEmployee.email} · {resetPwdEmployee.id}</p>
                </div>
              </div>

              <div className="p-3 bg-amber-50 dark:bg-amber-950/20 border border-amber-100 dark:border-amber-900/30 rounded-xl text-[11px] text-amber-800 dark:text-amber-400 flex items-start gap-1.5 leading-relaxed shrink-0">
                <AlertTriangle className="w-4.5 h-4.5 shrink-0 text-amber-600 mt-0.5 animate-pulse" />
                <span>Password lama akan langsung tidak bisa digunakan untuk masuk ke Flutter mobile app. HRD wajib memberitahu password baru ini ke karyawan secara langsung.</span>
              </div>

              <div className="space-y-3">
                <div className="space-y-1">
                  <label className="text-[10px] font-bold text-slate-400 dark:text-slate-500 block">Password Baru *</label>
                  <input 
                    type="password" 
                    value={resetPwdForm.password}
                    onChange={(e) => setResetPwdForm({...resetPwdForm, password: e.target.value})}
                    className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-805/10 text-slate-800 dark:text-slate-100 focus:outline-none"
                  />
                </div>

                <div className="space-y-1">
                  <label className="text-[10px] font-bold text-slate-400 dark:text-slate-500 block">Konfirmasi Password Baru *</label>
                  <input 
                    type="password" 
                    value={resetPwdForm.confirm}
                    onChange={(e) => setResetPwdForm({...resetPwdForm, confirm: e.target.value})}
                    className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-805/10 text-slate-800 dark:text-slate-100 focus:outline-none"
                  />
                </div>

                <div className="space-y-1 font-sans">
                  <label className="text-[10px] font-bold text-slate-400 dark:text-slate-500 block">Alasan Reset (untuk Audit Log)</label>
                  <select 
                    value={resetPwdForm.alasan}
                    onChange={(e) => setResetPwdForm({...resetPwdForm, alasan: e.target.value})}
                    className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-850 text-slate-800 dark:text-slate-100"
                  >
                    <option value="Karyawan lupa password">Karyawan lupa password</option>
                    <option value="Login pertama kali">Login pertama kali</option>
                    <option value="Keamanan akun">Keamanan akun/Indikasi compromised</option>
                    <option value="Permintaan langsung oleh karyawan">Permintaan langsung oleh karyawan</option>
                  </select>
                </div>
              </div>

            </div>

            {/* Reset buttons */}
            <div className="flex gap-2.5 pt-3 border-t border-slate-100 dark:border-slate-800">
              <button 
                type="button"
                onClick={() => setResetPwdEmployee(null)}
                disabled={showProgressReset}
                className="flex-1 py-2.5 border border-slate-200 dark:border-slate-800 dark:hover:bg-slate-805 text-slate-600 dark:text-slate-400 rounded-xl text-xs font-semibold hover:bg-slate-50 cursor-pointer disabled:opacity-50"
              >
                Batal
              </button>
              <button 
                type="button"
                onClick={handleResetPwdSubmit}
                disabled={showProgressReset}
                className="flex-1 py-2.5 bg-amber-500 hover:bg-amber-600 text-white font-bold rounded-xl text-xs shadow-sm cursor-pointer flex items-center justify-center gap-1.5 disabled:opacity-50"
              >
                {showProgressReset ? <span className="w-3.5 h-3.5 border-2 border-white/30 border-t-white rounded-full animate-spin shrink-0"></span> : <Lock className="w-3.5 h-3.5" />}
                <span>{showProgressReset ? 'Memproses...' : 'Reset Password'}</span>
              </button>
            </div>

          </div>
        </div>
      )}

      {/* MODAL NONAKTIFKAN AKUN KARYAWAN */}
      {nonaktifEmployee && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
          <div onClick={() => setNonaktifEmployee(null)} className="fixed inset-0 bg-slate-900/60 dark:bg-slate-950/80 backdrop-blur-sm" />
          
          <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-100 dark:border-slate-800 w-full max-w-md p-6 shadow-2xl relative z-10 leading-relaxed overflow-hidden">
            <div className="flex justify-between items-center pb-3 border-b border-slate-100 dark:border-slate-805">
              <h3 className="text-sm font-bold text-rose-600 dark:text-rose-450 flex items-center gap-1.5 font-sans">
                <Ban className="w-4.5 h-4.5 text-rose-600 shrink-0 animate-pulse" />
                Nonaktifkan Akun Karyawan
              </h3>
              <button onClick={() => setNonaktifEmployee(null)} className="p-1 hover:bg-slate-50 dark:hover:bg-slate-850 rounded-full text-slate-400">
                <X className="w-4.5 h-4.5" />
              </button>
            </div>

            <div className="py-4 space-y-4">
              <div className="flex items-center gap-2.5 bg-slate-50 dark:bg-slate-800/40 p-3 rounded-xl border border-slate-100 dark:border-slate-800">
                <span className={`w-8 h-8 rounded-full flex items-center justify-center font-bold text-xs shrink-0 select-none ${nonaktifEmployee.avatarBg} ${nonaktifEmployee.avatarColor}`}>
                  {nonaktifEmployee.initials}
                </span>
                <div className="min-w-0">
                  <p className="text-xs font-bold text-slate-850 dark:text-slate-100 truncate">{nonaktifEmployee.nama}</p>
                  <p className="text-[10px] text-slate-400 dark:text-slate-505 truncate mt-0.5">{nonaktifEmployee.email} · {nonaktifEmployee.id}</p>
                </div>
              </div>

              <div className="p-3 bg-rose-50 dark:bg-rose-950/20 border border-rose-100 dark:border-rose-900/30 rounded-xl text-[11px] text-rose-750 dark:text-rose-400 flex items-start gap-1.5 leading-relaxed">
                <AlertTriangle className="w-5 h-5 shrink-0 text-rose-600 mt-0.5" />
                <span>Karyawan ini tidak akan bisa login ke aplikasi mobile setelah akun dinonaktifkan. Pengajuan klaim struk yang sudah terunggah sebelumnya akan tetap tersimpan dan dapat diproses HR/Finance.</span>
              </div>

              <div className="bg-slate-50 dark:bg-slate-800/50 p-3 rounded-xl border border-slate-100 dark:border-slate-800 text-[10.5px] leading-relaxed space-y-1 text-slate-600 dark:text-slate-400">
                <p className="font-bold text-slate-700 dark:text-slate-350 mb-1">Dampak deaktifasi akun:</p>
                <div className="flex items-center gap-1.5 text-rose-550/70"><X className="w-3.5 h-3.5 text-rose-500 shrink-0" /> <span>Login aplikasi mobile diblokir total</span></div>
                <div className="flex items-center gap-1.5 text-rose-550/70"><X className="w-3.5 h-3.5 text-rose-500 shrink-0" /> <span>Semua token aktif otomatis dibatalkan</span></div>
                <div className="flex items-center gap-1.5"><Check className="w-3.5 h-3.5 text-emerald-600 shrink-0" /> <span>Data & histori reimbursement aman</span></div>
                <div className="flex items-center gap-1.5"><Check className="w-3.5 h-3.5 text-emerald-600 shrink-0" /> <span>Akun dapat diaktifkan kembali kapan saja</span></div>
              </div>

              <div className="space-y-3 font-sans">
                <div className="space-y-1">
                  <label className="text-[10px] font-bold text-slate-400 dark:text-slate-500 block">Alasan Penonaktifan * (wajib untuk Audit)</label>
                  <select 
                    value={nonaktifForm.alasan}
                    onChange={(e) => setNonaktifForm({...nonaktifForm, alasan: e.target.value})}
                    required
                    className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-850 text-slate-800 dark:text-slate-100"
                  >
                    <option value="">Pilih Alasan</option>
                    <option value="Resign / keluar dari perusahaan">Resign / keluar dari perusahaan</option>
                    <option value="Cuti panjang / tidak aktif bekerja">Cuti panjang / tidak aktif bekerja</option>
                    <option value="Penyalahgunaan sistem keuangan">Penyalahgunaan sistem keuangan</option>
                    <option value="Restrukturisasi departemen">Restrukturisasi departemen</option>
                  </select>
                </div>

                <div className="space-y-1">
                  <label className="text-[10px] font-bold text-slate-400 dark:text-slate-500 block">Catatan Tambahan</label>
                  <textarea 
                    rows={2}
                    value={nonaktifForm.catatan}
                    onChange={(e) => setNonaktifForm({...nonaktifForm, catatan: e.target.value})}
                    placeholder="Tulis informasi detail tambahan..."
                    className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-805/10 text-slate-800 dark:text-slate-100 focus:outline-none"
                  />
                </div>
              </div>

            </div>

            {/* Actions */}
            <div className="flex gap-2.5 pt-3 border-t border-slate-100 dark:border-slate-800/80">
              <button 
                type="button"
                onClick={() => setNonaktifEmployee(null)}
                className="flex-1 py-2.5 border border-slate-200 dark:border-slate-800 dark:hover:bg-slate-805 text-slate-600 dark:text-slate-400 rounded-xl text-xs font-semibold hover:bg-slate-50 cursor-pointer"
              >
                Batal
              </button>
              <button 
                type="button"
                onClick={handleNonaktifSubmit}
                disabled={!nonaktifForm.alasan || showProgressNonaktif}
                className="flex-1 py-2.5 bg-rose-600 hover:bg-rose-700 text-white font-bold rounded-xl text-xs shadow-sm cursor-pointer flex items-center justify-center gap-1.5 disabled:opacity-50"
              >
                {showProgressNonaktif ? <span className="w-3.5 h-3.5 border-2 border-white/30 border-t-white rounded-full animate-spin shrink-0"></span> : <Ban className="w-3.5 h-3.5" />}
                <span>{showProgressNonaktif ? 'Membekukan...' : 'Nonaktifkan Akun'}</span>
              </button>
            </div>

          </div>
        </div>
      )}

      {/* Confirmation Dialog helper */}
      {confirmDialog && (
        <ConfirmationDialog
          isOpen={confirmDialog.isOpen}
          onClose={() => setConfirmDialog(null)}
          onConfirm={() => {
            confirmDialog.onConfirm();
            setConfirmDialog(null);
          }}
          title={confirmDialog.title}
          message={confirmDialog.message}
          confirmText={confirmDialog.confirmText}
          type={confirmDialog.type}
        />
      )}

    </div>
  );
};
