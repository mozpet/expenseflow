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
  Building,
  RefreshCw,
  CreditCard,
  Receipt,
  Percent,
  Briefcase,
  Calendar,
  Wallet,
  Landmark,
  BadgePercent,
  FileText,
  UserCheck,
  Sparkles,
  Copy,
  Phone,
  Mail,
  Smartphone,
  MoreVertical,
  Trash2
} from 'lucide-react';
import { motion, AnimatePresence } from 'motion/react';
import { ConfirmationDialog } from './ConfirmationDialog';
import { userApi, attendanceApi } from '../services/endpoints';
import { ApiError } from '../services/api';
import { useDebounce } from '../hooks/useDebounce';

interface Employee {
  id: string; // employee_code / NIK (tampilan)
  backendId: number; // id numerik backend (untuk aksi API)
  nama: string;
  email: string;
  dept: string;
  jabatan: string; // dipetakan dari role
  role: string;
  hp: string;
  limit: number | null; // in IDR. null = tanpa batas
  loginTerakhir: string;
  status: 'Aktif' | 'Nonaktif' | 'Belum login';
  initials: string;
  avatarBg: string; // Tailwind class
  avatarColor: string; // Tailwind class
  atasan?: string;
  tanggalMasuk?: string;
  officeId: number | null; // attendance_setting_id — kantor penempatan
  officeName: string; // nama kantor untuk tampilan
  nikKtp: string | null; // NIK KTP (identity_number)
  // Tipe hubungan kerja & kontrak
  employmentType: string | null; // 'PKWTT' | 'PKWT' | 'Probation' | 'Internship' | null
  joinedDate: string | null;
  contractStartDate: string | null;
  contractEndDate: string | null;

  // Settings & Flags
  wfhEnabled?: boolean;
  radiusEnabled?: boolean;
  attendanceEnabled?: boolean;
  deviceName?: string | null;
  deviceId?: string | null;
  deviceBoundAt?: string | null;

  // Payroll & Tax
  bankName?: string | null;
  bankAccountNo?: string | null;
  bankAccountHolder?: string | null;
  salaryType?: 'monthly' | 'daily' | 'hourly' | null;
  basicSalary?: number | null;
  npwp?: string | null;
  ptkpStatus?: string | null;
  taxMethod?: 'gross' | 'gross_up' | 'nett' | null;
  bpjsKesehatanNo?: string | null;
  bpjsKetenagakerjaanNo?: string | null;
  bpjsKesehatanEnabled?: boolean;
  bpjsKetenagakerjaanEnabled?: boolean;
  hasJht?: boolean;
  hasJp?: boolean;
  overtimeEligible?: boolean;
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
    hp: u.phone ?? '—',
    limit: u.monthly_claim_limit !== null && u.monthly_claim_limit !== undefined ? Number(u.monthly_claim_limit) : null,
    loginTerakhir: '—',
    status: u.is_active === false ? 'Nonaktif' : 'Aktif',
    initials: initials || '?',
    avatarBg: palette.slice(0, 2).join(' '),
    avatarColor: palette.slice(2).join(' '),
    atasan: undefined,
    tanggalMasuk: u.joined_date ?? (u.created_at ? String(u.created_at).split('T')[0] : undefined),
    officeId: u.attendance_setting_id ?? null,
    officeName: u.office?.office_name ?? '—',
    nikKtp: u.identity_number ?? null,
    employmentType: u.employment_type ?? null,
    joinedDate: u.joined_date ?? null,
    contractStartDate: u.contract_start_date ?? null,
    contractEndDate: u.contract_end_date ?? null,
    wfhEnabled: u.wfh_enabled !== false,
    radiusEnabled: u.radius_enabled !== false,
    attendanceEnabled: u.attendance_enabled !== false,
    deviceName: u.device_name ?? null,
    deviceId: u.device_id ?? null,
    deviceBoundAt: u.device_bound_at ?? null,
    bankName: u.bank_name ?? null,
    bankAccountNo: u.bank_account_no ?? null,
    bankAccountHolder: u.bank_account_holder ?? null,
    salaryType: u.salary_type ?? null,
    basicSalary: u.basic_salary ? Number(u.basic_salary) : null,
    npwp: u.npwp ?? null,
    ptkpStatus: u.ptkp_status ?? null,
    taxMethod: u.tax_method ?? null,
    bpjsKesehatanNo: u.bpjs_kesehatan_no ?? null,
    bpjsKetenagakerjaanNo: u.bpjs_ketenagakerjaan_no ?? null,
    bpjsKesehatanEnabled: u.bpjs_kesehatan_enabled !== false,
    bpjsKetenagakerjaanEnabled: u.bpjs_ketenagakerjaan_enabled !== false,
    hasJht: u.has_jht !== false,
    hasJp: u.has_jp !== false,
    overtimeEligible: u.overtime_eligible !== false,
  };
}

// ─── Helper: format tanggal ISO menjadi "26 Juni 2029" (ramah baca) ────
function formatDateId(raw: string | null): string {
  if (!raw) return '';
  // Potong hanya bagian tanggalnya saja: "YYYY-MM-DD" (abaikan waktu)
  const dateStr = raw.slice(0, 10);
  const d = new Date(dateStr + 'T00:00:00');
  if (isNaN(d.getTime())) return raw; // fallback ke string asli
  return d.toLocaleDateString('id-ID', {
    day: '2-digit', month: 'long', year: 'numeric',
  });
}

// ─── Helper: hitung lama masa kerja (tenure) ───────────────────────────────
function calculateTenure(joinedDateStr: string | null | undefined): string {
  if (!joinedDateStr) return '—';
  const start = new Date(joinedDateStr.slice(0, 10) + 'T00:00:00');
  if (isNaN(start.getTime())) return '—';
  const now = new Date();
  let years = now.getFullYear() - start.getFullYear();
  let months = now.getMonth() - start.getMonth();
  if (months < 0) {
    years--;
    months += 12;
  }
  if (years <= 0 && months <= 0) return 'Baru';
  if (years <= 0) return `${months} bln`;
  if (months <= 0) return `${years} thn`;
  return `${years} th ${months} bl`;
}

// ─── Helper: status kontrak PKWT berdasarkan contract_end_date ─────────────
function contractStatus(endDate: string | null): 'active' | 'near' | 'expired' | null {
  if (!endDate) return null;
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  const end = new Date(endDate.slice(0, 10) + 'T00:00:00');
  end.setHours(0, 0, 0, 0);
  const diffDays = Math.floor((end.getTime() - today.getTime()) / 86400000);
  if (diffDays < 0) return 'expired';
  if (diffDays <= 30) return 'near';
  return 'active';
}

// ─── Helper: config badge employment type ──────────────────────────────────
const EMPLOYMENT_BADGE: Record<string, { label: string; cls: string }> = {
  PKWTT: { label: 'Tetap', cls: 'bg-teal-50 text-teal-700 dark:bg-teal-950/30 dark:text-teal-400' },
  PKWT: { label: 'Kontrak', cls: 'bg-blue-50 text-blue-700 dark:bg-blue-950/30 dark:text-blue-400' },
  Probation: { label: 'Probasi', cls: 'bg-amber-50 text-amber-700 dark:bg-amber-950/30 dark:text-amber-400' },
  Internship: { label: 'Magang', cls: 'bg-purple-50 text-purple-700 dark:bg-purple-950/30 dark:text-purple-400' },
};

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
  const [statusFilter, setStatusFilter] = useState<'active' | 'inactive'>('active');
  const [selectedDept, setSelectedDept] = useState<string>('Semua dept');
  const [selectedOffice, setSelectedOffice] = useState<string>('Semua kantor');
  const [selectedEmploymentType, setSelectedEmploymentType] = useState<string>('');
  const [searchQuery, setSearchQuery] = useState<string>('');

  // Views/Forms controllers: 'list' (tabel utama), 'add' (halaman tambah), 'edit' (halaman edit)
  const [viewMode, setViewMode] = useState<'list' | 'add' | 'edit'>('list');

  // Detail Modal / Slide-Over Drawer State
  const [detailEmployee, setDetailEmployee] = useState<Employee | null>(null);
  const [detailTab, setDetailTab] = useState<'profile' | 'payroll' | 'bpjs' | 'access'>('profile');
  const [showSensitiveData, setShowSensitiveData] = useState<boolean>(false);
  const [copiedField, setCopiedField] = useState<string | null>(null);

  const handleCopyText = (text: string, fieldKey: string) => {
    if (!text || text === '—') return;
    navigator.clipboard.writeText(text);
    setCopiedField(fieldKey);
    setTimeout(() => setCopiedField(null), 2000);
  };

  // Helper pengisi fallback dummy data cerdas untuk field payroll/identitas yang belum ada di backend
  const getEmployeeFullData = (emp: Employee) => {
    const seed = emp.backendId || 1;
    const dummyBanks = ['BCA', 'Bank Mandiri', 'BRI', 'BNI', 'CIMB Niaga'];
    const dummyBank = dummyBanks[seed % dummyBanks.length];
    const dummyAccountNo = `${1000000000 + (seed * 8374932) % 9000000000}`;
    const dummyNpwp = `${1000000000000000 + (seed * 739281729481) % 9000000000000000}`.replace(/(\d{2})(\d{3})(\d{3})(\d{1})(\d{3})(\d{3})/, '$1.$2.$3.$4-$5.$6');
    const dummyNikKtp = emp.nikKtp || `${3171000000000000 + (seed * 9472918) % 9000000000000}`;

    const baseSalaryMap: Record<string, number> = {
      super_admin: 22000000,
      admin: 15000000,
      finance: 10500000,
      hrd: 11000000,
      employee: 7500000,
    };
    const estimatedSalary = emp.basicSalary || baseSalaryMap[emp.role] || (emp.employmentType === 'Internship' ? 3500000 : 7500000);

    const ptkpOptions = ['TK/0', 'TK/1', 'K/0', 'K/1', 'K/2'];
    const dummyPtkp = emp.ptkpStatus || ptkpOptions[seed % ptkpOptions.length];

    const terCategoryMap: Record<string, 'A' | 'B' | 'C'> = {
      'TK/0': 'A', 'TK/1': 'A', 'K/0': 'A',
      'TK/2': 'B', 'TK/3': 'B', 'K/1': 'B', 'K/2': 'B',
      'K/3': 'C', 'K/I/0': 'C', 'K/I/1': 'C', 'K/I/2': 'C', 'K/I/3': 'C',
    };
    const terCategory = terCategoryMap[dummyPtkp] || 'A';

    const dummyBpjsKes = emp.bpjsKesehatanNo || `000${1234567890 + seed * 37}`;
    const dummyBpjsTk = emp.bpjsKetenagakerjaanNo || `220${12345678 + seed * 19}`;

    return {
      ...emp,
      nikKtp: dummyNikKtp,
      bankName: emp.bankName || dummyBank,
      bankAccountNo: emp.bankAccountNo || dummyAccountNo,
      bankAccountHolder: emp.bankAccountHolder || emp.nama,
      salaryType: emp.salaryType || (emp.employmentType === 'Internship' ? 'daily' : 'monthly'),
      basicSalary: estimatedSalary,
      npwp: emp.npwp || dummyNpwp,
      ptkpStatus: dummyPtkp,
      terCategory,
      taxMethod: emp.taxMethod || 'gross',
      bpjsKesehatanNo: dummyBpjsKes,
      bpjsKetenagakerjaanNo: dummyBpjsTk,
      bpjsKesehatanEnabled: emp.bpjsKesehatanEnabled ?? true,
      bpjsKetenagakerjaanEnabled: emp.bpjsKetenagakerjaanEnabled ?? true,
      hasJht: emp.hasJht ?? (emp.employmentType !== 'Internship'),
      hasJp: emp.hasJp ?? (emp.employmentType === 'PKWTT' || emp.employmentType === 'PKWT'),
      overtimeEligible: emp.overtimeEligible ?? (emp.role === 'employee'),
      wfhEnabled: emp.wfhEnabled ?? true,
      radiusEnabled: emp.radiusEnabled ?? true,
      attendanceEnabled: emp.attendanceEnabled ?? true,
      deviceName: emp.deviceName || (seed % 2 === 0 ? 'Samsung Galaxy A54' : 'iPhone 13 Pro'),
      deviceId: emp.deviceId || `DEV-${seed}84F9B${seed}`,
      deviceBoundAt: emp.deviceBoundAt || emp.tanggalMasuk || '2026-01-10',
    };
  };

  // Modals / active employee controllers
  const [editEmployee, setEditEmployee] = useState<Employee | null>(null);
  const [editForm, setEditForm] = useState({
    // Status Hubungan Kerja
    employmentType: 'PKWTT' as 'PKWTT' | 'PKWT' | 'Probation' | 'Internship',

    // Data Pribadi & Akun
    nama: '',
    nik: '',
    nikKtp: '',
    email: '',
    hp: '',
    tanggalMasuk: '',
    joinedDate: '',
    contractStartDate: '',
    contractEndDate: '',
    dept: '',
    jabatan: '',
    role: 'employee',
    atasan: '',
    officeId: '' as number | '', // '' = belum ditentukan
    limit: '' as number | '' | null,

    // Data Finansial & Perbankan (Payroll Roadmap)
    bankName: 'BCA',
    bankAccountNo: '',
    bankAccountHolder: '',
    salaryType: 'monthly' as 'monthly' | 'daily' | 'hourly',
    basicSalary: '' as number | '' | null,

    // Data Pajak & PTKP (PPh 21 TER 2024)
    npwp: '',
    ptkpStatus: 'TK/0',
    taxMethod: 'gross' as 'gross' | 'gross_up' | 'nett',

    // Data Kepesertaan BPJS & Fasilitas Lembur
    bpjsKesehatanNo: '',
    bpjsKetenagakerjaanNo: '',
    bpjsKesehatanEnabled: true,
    bpjsKetenagakerjaanEnabled: true,
    hasJht: true,
    hasJp: true,
    overtimeEligible: true,
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
    // Status Hubungan Kerja (Wajib dipilih di awal)
    employmentType: 'PKWTT' as 'PKWTT' | 'PKWT' | 'Probation' | 'Internship',

    // Data Pribadi & Akun
    nama: '',
    nik: '',
    nikKtp: '',
    email: '',
    hp: '',
    tanggalMasuk: new Date().toISOString().split('T')[0],
    joinedDate: new Date().toISOString().split('T')[0],
    contractStartDate: '',
    contractEndDate: '',
    dept: '',
    jabatan: '',
    role: 'employee',
    atasan: '',
    officeId: '' as number | '', // '' = belum ditentukan
    limit: '' as number | '' | null,
    password: 'Maju2026!',
    confirmPassword: 'Maju2026!',
    showPassword: false,

    // Data Finansial & Perbankan (Payroll Roadmap)
    bankName: 'BCA',
    bankAccountNo: '',
    bankAccountHolder: '',
    salaryType: 'monthly' as 'monthly' | 'daily' | 'hourly',
    basicSalary: '' as number | '' | null,

    // Data Pajak & PTKP (PPh 21 TER 2024)
    npwp: '',
    ptkpStatus: 'TK/0',
    taxMethod: 'gross' as 'gross' | 'gross_up' | 'nett',

    // Data Kepesertaan BPJS & Fasilitas Lembur
    bpjsKesehatanNo: '',
    bpjsKetenagakerjaanNo: '',
    bpjsKesehatanEnabled: true,
    bpjsKetenagakerjaanEnabled: true,
    hasJht: true,
    hasJp: true,
    overtimeEligible: true,
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
    const totalActive = employees.filter(e => e.status !== 'Nonaktif').length;
    const totalInactive = employees.filter(e => e.status === 'Nonaktif').length;
    const pkwtt = employees.filter(e => e.status !== 'Nonaktif' && e.employmentType === 'PKWTT').length;
    const pkwt = employees.filter(e => e.status !== 'Nonaktif' && e.employmentType === 'PKWT').length;
    const other = employees.filter(e => e.status !== 'Nonaktif' && (e.employmentType === 'Probation' || e.employmentType === 'Internship' || !e.employmentType)).length;
    return { total: employees.length, active: totalActive, inactive: totalInactive, pkwtt, pkwt, other };
  }, [employees]);

  // Departemen lists for selector
  const departments = ['Marketing', 'Sales', 'Operations', 'Finance', 'HR', 'IT'];

  const debouncedSearch = useDebounce(searchQuery, 500);

  // 3. Filter and search logic
  const filteredEmployees = useMemo(() => {
    return employees.filter(e => {
      // Filter status: active (default) vs inactive
      if (statusFilter === 'active' && e.status === 'Nonaktif') return false;
      if (statusFilter === 'inactive' && e.status !== 'Nonaktif') return false;

      const matchSearch = !debouncedSearch ||
        e.nama.toLowerCase().includes(debouncedSearch.toLowerCase()) ||
        e.id.toLowerCase().includes(debouncedSearch.toLowerCase()) ||
        e.email.toLowerCase().includes(debouncedSearch.toLowerCase());
      const matchDept = selectedDept === 'Semua dept' || e.dept === selectedDept;
      const matchOffice = selectedOffice === 'Semua kantor' ||
        (selectedOffice === 'tanpa_kantor' ? !e.officeId : e.officeId === Number(selectedOffice));
      const matchEmploymentType = !selectedEmploymentType || e.employmentType === selectedEmploymentType;
      return matchSearch && matchDept && matchOffice && matchEmploymentType;
    });
  }, [employees, debouncedSearch, statusFilter, selectedDept, selectedOffice, selectedEmploymentType]);

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

    handleOpenConfirm({
      isOpen: true,
      title: 'Konfirmasi Karyawan Baru',
      message: `Apakah Anda yakin ingin menambahkan karyawan baru bernama ${addForm.nama}? Pastikan data NIK dan email sudah benar.`,
      type: 'info',
      confirmText: 'Ya, Tambahkan',
      onConfirm: async () => {
        setSubmitting(true);
        try {
          await userApi.create({
            name: addForm.nama,
            email: addForm.email,
            password: addForm.password,
            role: addForm.role,
            employee_code: addForm.nik || undefined,
            identity_number: addForm.nikKtp || undefined,
            department: addForm.dept || undefined,
            attendance_setting_id: addForm.officeId === '' ? null : addForm.officeId,
            monthly_claim_limit: addForm.limit === '' || addForm.limit === null ? null : addForm.limit,
            employment_type: addForm.employmentType || null,
            joined_date: addForm.joinedDate || null,
            contract_start_date: addForm.employmentType === 'PKWT' ? (addForm.contractStartDate || null) : null,
            contract_end_date: addForm.employmentType === 'PKWT' ? (addForm.contractEndDate || null) : null,
          });
          await loadEmployees();
          onAddAuditLog('Karyawan Baru Terdaftar', `Menambahkan karyawan baru: ${addForm.nama} - Role: ${addForm.role}`, 'bg-indigo-600');
          onAddNotification('new', 'Karyawan Baru Ditambahkan', `Akun untuk ${addForm.nama} berhasil didaftarkan.`);

          // Reset add state
          setAddForm({
            employmentType: 'PKWTT',
            nama: '',
            nik: '',
            nikKtp: '',
            email: '',
            hp: '',
            tanggalMasuk: new Date().toISOString().split('T')[0],
            dept: '',
            jabatan: '',
            role: 'employee',
            atasan: '',
            officeId: '',
            limit: '' as number | '' | null,
            password: 'Maju2026!',
            confirmPassword: 'Maju2026!',
            showPassword: false,
            joinedDate: new Date().toISOString().split('T')[0],
            contractStartDate: '',
            contractEndDate: '',
            bankName: 'BCA',
            bankAccountNo: '',
            bankAccountHolder: '',
            salaryType: 'monthly',
            basicSalary: '',
            npwp: '',
            ptkpStatus: 'TK/0',
            taxMethod: 'gross',
            bpjsKesehatanNo: '',
            bpjsKetenagakerjaanNo: '',
            bpjsKesehatanEnabled: true,
            bpjsKetenagakerjaanEnabled: true,
            hasJht: true,
            hasJp: true,
            overtimeEligible: true,
          });
          setViewMode('list');
        } catch (err) {
          reportApiError(err, 'Gagal menambahkan karyawan.');
        } finally {
          setSubmitting(false);
        }
      }
    });
  };

  // Edit employee trigger & open full-page edit view
  const handleOpenEdit = (emp: Employee) => {
    setEditEmployee(emp);
    setEditForm({
      employmentType: (emp.employmentType as any) || 'PKWTT',
      nama: emp.nama,
      nik: emp.id.startsWith('EMP-') ? '' : emp.id,
      nikKtp: emp.nikKtp ?? '',
      email: emp.email,
      hp: emp.hp === '—' ? '' : emp.hp,
      tanggalMasuk: emp.tanggalMasuk ?? new Date().toISOString().split('T')[0],
      joinedDate: emp.joinedDate ?? '',
      contractStartDate: emp.contractStartDate ?? '',
      contractEndDate: emp.contractEndDate ?? '',
      dept: emp.dept === '—' ? '' : emp.dept,
      jabatan: emp.jabatan === '—' ? '' : emp.jabatan,
      role: emp.role || 'employee',
      atasan: '',
      officeId: emp.officeId ?? '',
      limit: emp.limit,

      // Data Payroll Default / Existing
      bankName: 'BCA',
      bankAccountNo: '',
      bankAccountHolder: emp.nama,
      salaryType: emp.employmentType === 'Internship' ? 'daily' : 'monthly',
      basicSalary: '',
      npwp: '',
      ptkpStatus: 'TK/0',
      taxMethod: 'gross',
      bpjsKesehatanNo: '',
      bpjsKetenagakerjaanNo: '',
      bpjsKesehatanEnabled: true,
      bpjsKetenagakerjaanEnabled: true,
      hasJht: emp.employmentType !== 'Internship',
      hasJp: emp.employmentType === 'PKWTT' || emp.employmentType === 'PKWT',
      overtimeEligible: emp.role === 'employee',
    });
    setViewMode('edit');
  };

  const handleSaveEditSubmit = async (e?: React.FormEvent) => {
    if (e) e.preventDefault();
    if (!editEmployee) return;

    handleOpenConfirm({
      isOpen: true,
      title: 'Konfirmasi Perubahan Data',
      message: `Terdapat perubahan pada profil ${editForm.nama}. Apakah Anda yakin ingin menyimpannya?`,
      type: 'info',
      confirmText: 'Ya, Simpan Perubahan',
      onConfirm: async () => {
        setSubmitting(true);
        try {
          await userApi.update(editEmployee.backendId, {
            name: editForm.nama,
            employee_code: editForm.nik || undefined,
            identity_number: editForm.nikKtp || undefined,
            phone: editForm.hp || undefined,
            role: editForm.role || undefined,
            department: editForm.dept || undefined,
            attendance_setting_id: editForm.officeId === '' ? null : editForm.officeId,
            monthly_claim_limit: editForm.limit === '' || editForm.limit === null ? null : editForm.limit,
            employment_type: editForm.employmentType || null,
            joined_date: editForm.joinedDate || null,
            contract_start_date: editForm.employmentType === 'PKWT' ? (editForm.contractStartDate || null) : null,
            contract_end_date: (editForm.employmentType === 'PKWT' || editForm.employmentType === 'Probation' || editForm.employmentType === 'Internship')
              ? (editForm.contractEndDate || null)
              : null,
          });
          await loadEmployees();
          onAddAuditLog('Update Profil Karyawan', `Profil ${editForm.nama} (${editEmployee.id}) diperbarui`, 'bg-indigo-600');
          onAddNotification('success', 'Profil Karyawan Diperbarui', `Perubahan data untuk ${editForm.nama} berhasil disimpan.`);
          setEditEmployee(null);
          setViewMode('list');
        } catch (err) {
          reportApiError(err, 'Gagal memperbarui karyawan.');
        } finally {
          setSubmitting(false);
        }
      }
    });
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

  const handleDeleteEmployee = (emp: Employee) => {
    handleOpenConfirm({
      isOpen: true,
      title: 'Hapus Karyawan (Soft Delete)',
      message: (
        <div className="space-y-2 text-xs">
          <p className="text-slate-600 dark:text-slate-300">
            Apakah Anda yakin ingin menghapus data karyawan <strong className="text-slate-900 dark:text-white font-bold">{emp.nama}</strong> ({emp.id})?
          </p>
          <p className="text-rose-700 dark:text-rose-300 bg-rose-50 dark:bg-rose-950/40 p-2.5 rounded-xl border border-rose-200 dark:border-rose-800/60 text-[11px] leading-relaxed">
            ⚠️ Akun akan diarsipkan (Soft Delete), seluruh sesi login dicabut, dan data tidak akan muncul di daftar operasional harian.
          </p>
        </div>
      ),
      confirmText: 'Ya, Hapus Data',
      type: 'danger',
      onConfirm: async () => {
        try {
          await userApi.destroy(emp.backendId);
          setEmployees(prev => prev.filter(e => e.backendId !== emp.backendId));
          onAddAuditLog('Hapus Karyawan', `Akun ${emp.nama} (${emp.id}) berhasil dihapus (soft delete).`, 'bg-rose-600');
          onAddNotification('success', 'Karyawan Dihapus', `Data ${emp.nama} telah berhasil diarsipkan.`);
        } catch (err) {
          reportApiError(err, 'Gagal menghapus data karyawan.');
        }
      },
    });
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
            onClick={() => { loadEmployees(); loadOffices(); }}
            disabled={loadingEmployees}
            className="flex-1 sm:flex-initial flex items-center justify-center gap-1.5 py-2 px-4 rounded-xl text-xs font-bold text-indigo-600 dark:text-indigo-400 bg-indigo-50 dark:bg-indigo-900/30 border border-indigo-100 dark:border-indigo-800 hover:bg-indigo-100 dark:hover:bg-indigo-900/50 transition duration-150 cursor-pointer disabled:opacity-50"
            title="Refresh Data"
          >
            <RefreshCw className={`w-3.5 h-3.5 ${loadingEmployees ? 'animate-spin' : ''}`} />
            <span>Refresh</span>
          </button>

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

          {viewMode !== 'list' && (
            <button
              onClick={() => {
                setViewMode('list');
                setEditEmployee(null);
              }}
              className="flex-1 sm:flex-initial flex items-center justify-center gap-1.5 py-2 px-4 bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-705 text-slate-700 dark:text-slate-300 rounded-xl text-xs font-semibold transition duration-150 cursor-pointer"
            >
              <ArrowLeft className="w-3.5 h-3.5" />
              <span>Kembali ke Daftar</span>
            </button>
          )}
        </div>
      </div>

      {viewMode === 'list' ? (
        <>
          {/* Bento-style Statistics Grid (Dynamic) */}
          <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
            <div
              onClick={() => setStatusFilter('active')}
              className={`p-4 rounded-2xl border transition cursor-pointer flex flex-col justify-between ${
                statusFilter === 'active'
                  ? 'bg-indigo-50/50 dark:bg-indigo-950/30 border-indigo-200 dark:border-indigo-800 ring-2 ring-indigo-500/20'
                  : 'bg-white dark:bg-slate-900 border-slate-100 dark:border-slate-800 hover:border-slate-300'
              }`}
            >
              <div className="flex items-center justify-between">
                <span className="text-[11px] font-bold text-slate-400 uppercase tracking-widest block">Total Karyawan Aktif</span>
                <span className="w-2 h-2 rounded-full bg-emerald-500 animate-pulse" />
              </div>
              <div className="mt-2 flex items-baseline gap-1.5">
                <span className="text-2xl font-black text-slate-800 dark:text-slate-100 font-mono">{stats.active}</span>
                <span className="text-[10px] text-slate-400 font-medium">orang</span>
              </div>
              <span className="text-[9px] text-slate-400 dark:text-slate-500 mt-1 block">Aktif dalam operasional</span>
            </div>

            <div className="p-4 rounded-2xl bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 shadow-xs flex flex-col justify-between">
              <span className="text-[11px] font-bold text-teal-600 dark:text-teal-400 uppercase tracking-widest block">Karyawan Tetap (PKWTT)</span>
              <div className="mt-2 flex items-baseline gap-1.5">
                <span className="text-2xl font-black text-teal-600 dark:text-teal-400 font-mono">{stats.pkwtt}</span>
                <span className="text-[10px] text-slate-400 font-medium">orang</span>
              </div>
              <span className="text-[9px] text-slate-400 dark:text-slate-500 mt-1 block">Perjanjian Kerja Tetap</span>
            </div>

            <div className="p-4 rounded-2xl bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 shadow-xs flex flex-col justify-between">
              <span className="text-[11px] font-bold text-blue-600 dark:text-blue-400 uppercase tracking-widest block">Karyawan Kontrak (PKWT)</span>
              <div className="mt-2 flex items-baseline gap-1.5">
                <span className="text-2xl font-black text-blue-600 dark:text-blue-400 font-mono">{stats.pkwt}</span>
                <span className="text-[10px] text-slate-400 font-medium">orang</span>
              </div>
              <span className="text-[9px] text-slate-400 dark:text-slate-500 mt-1 block">Periode berjangka</span>
            </div>

            <div
              onClick={() => setStatusFilter('inactive')}
              className={`p-4 rounded-2xl border transition cursor-pointer flex flex-col justify-between ${
                statusFilter === 'inactive'
                  ? 'bg-rose-50/50 dark:bg-rose-950/30 border-rose-200 dark:border-rose-800 ring-2 ring-rose-500/20'
                  : 'bg-white dark:bg-slate-900 border-slate-100 dark:border-slate-800 hover:border-rose-200'
              }`}
            >
              <div className="flex items-center justify-between">
                <span className="text-[11px] font-bold text-rose-600 dark:text-rose-400 uppercase tracking-widest block">Akun Nonaktif</span>
                <span className="w-2 h-2 rounded-full bg-rose-500" />
              </div>
              <div className="mt-2 flex items-baseline gap-1.5">
                <span className="text-2xl font-black text-rose-600 dark:text-rose-400 font-mono">{stats.inactive}</span>
                <span className="text-[10px] text-slate-400 font-medium">terblokir</span>
              </div>
              <span className="text-[9px] text-slate-400 dark:text-slate-500 mt-1 block">Bisa diaktifkan / dihapus</span>
            </div>
          </div>

          {/* Interactive Card Table with Filters */}
          <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-3xl p-5 shadow-xs space-y-4">

            {/* Filter Bar */}
            <div className="flex flex-col lg:flex-row justify-between items-stretch lg:items-center gap-3 pb-1">

              {/* Status Segmented Switch: Karyawan Aktif (Default) vs Karyawan Nonaktif */}
              <div className="inline-flex p-1 bg-slate-100 dark:bg-slate-800/80 rounded-2xl border border-slate-200/60 dark:border-slate-750 self-start sm:self-auto shrink-0">
                <button
                  type="button"
                  onClick={() => setStatusFilter('active')}
                  className={`flex items-center gap-2 px-3.5 py-1.5 rounded-xl text-xs font-bold transition cursor-pointer ${
                    statusFilter === 'active'
                      ? 'bg-white dark:bg-slate-900 text-indigo-600 dark:text-indigo-400 shadow-xs'
                      : 'text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200'
                  }`}
                >
                  <span className="w-2 h-2 rounded-full bg-emerald-500 animate-pulse" />
                  <span>Karyawan Aktif</span>
                  <span className={`text-[10px] px-1.5 py-0.2 rounded-full font-mono ${
                    statusFilter === 'active'
                      ? 'bg-indigo-50 dark:bg-indigo-950/60 text-indigo-600 dark:text-indigo-400 font-extrabold'
                      : 'bg-slate-200/80 dark:bg-slate-700 text-slate-500'
                  }`}>
                    {stats.active}
                  </span>
                </button>

                <button
                  type="button"
                  onClick={() => setStatusFilter('inactive')}
                  className={`flex items-center gap-2 px-3.5 py-1.5 rounded-xl text-xs font-bold transition cursor-pointer ${
                    statusFilter === 'inactive'
                      ? 'bg-white dark:bg-slate-900 text-rose-600 dark:text-rose-400 shadow-xs'
                      : 'text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200'
                  }`}
                >
                  <span className="w-2 h-2 rounded-full bg-rose-500" />
                  <span>Karyawan Nonaktif</span>
                  <span className={`text-[10px] px-1.5 py-0.2 rounded-full font-mono ${
                    statusFilter === 'inactive'
                      ? 'bg-rose-50 dark:bg-rose-950/60 text-rose-600 dark:text-rose-400 font-extrabold'
                      : 'bg-slate-200/80 dark:bg-slate-700 text-slate-500'
                  }`}>
                    {stats.inactive}
                  </span>
                </button>
              </div>

              {/* Department, Office, Tipe Kerja and Search query inputs */}
              <div className="flex flex-wrap items-center gap-2">
                <select
                  value={selectedOffice}
                  onChange={(e) => setSelectedOffice(e.target.value)}
                  className="p-2.5 rounded-xl border border-slate-200 dark:border-slate-800 text-[11px] font-semibold bg-slate-50/50 dark:bg-slate-800/20 text-slate-700 dark:text-slate-300 focus:outline-none focus:ring-1 focus:ring-indigo-500 cursor-pointer"
                >
                  <option value="Semua kantor">Semua kantor</option>
                  {offices.map(o => (
                    <option key={o.id} value={o.id}>{o.office_name}</option>
                  ))}
                  <option value="tanpa_kantor">Tanpa Kantor</option>
                </select>

                <select
                  value={selectedDept}
                  onChange={(e) => setSelectedDept(e.target.value)}
                  className="p-2.5 rounded-xl border border-slate-200 dark:border-slate-800 text-[11px] font-semibold bg-slate-50/50 dark:bg-slate-800/20 text-slate-700 dark:text-slate-300 focus:outline-none focus:ring-1 focus:ring-indigo-500 cursor-pointer"
                >
                  <option value="Semua dept">Semua dept</option>
                  {departments.map(d => (
                    <option key={d} value={d}>{d}</option>
                  ))}
                </select>

                <select
                  value={selectedEmploymentType}
                  onChange={(e) => setSelectedEmploymentType(e.target.value)}
                  className="p-2.5 rounded-xl border border-slate-200 dark:border-slate-800 text-[11px] font-semibold bg-slate-50/50 dark:bg-slate-800/20 text-slate-700 dark:text-slate-300 focus:outline-none focus:ring-1 focus:ring-indigo-500 cursor-pointer"
                >
                  <option value="">Semua Tipe Kerja</option>
                  <option value="PKWTT">PKWTT (Tetap)</option>
                  <option value="PKWT">PKWT (Kontrak)</option>
                  <option value="Probation">Probasi</option>
                  <option value="Internship">Magang</option>
                </select>

                <div className="relative flex-1 min-w-[180px]">
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
              <table className="w-full text-left border-collapse min-w-[900px]">
                <thead>
                  <tr className="bg-slate-50/70 dark:bg-slate-800/20 border-b border-slate-100 dark:border-slate-800">
                    <th className="py-3 px-4 text-[11px] font-bold text-slate-400 uppercase tracking-widest" style={{ width: '180px' }}>Karyawan</th>
                    <th className="py-3 px-4 text-[11px] font-bold text-slate-400 uppercase tracking-widest" style={{ width: '90px' }}>NIK</th>
                    <th className="py-3 px-4 text-[11px] font-bold text-slate-400 uppercase tracking-widest" style={{ width: '110px' }}>Departemen</th>
                    <th className="py-3 px-4 text-[11px] font-bold text-slate-400 uppercase tracking-widest" style={{ width: '100px' }}>Jabatan</th>
                    <th className="py-3 px-4 text-[11px] font-bold text-slate-400 uppercase tracking-widest" style={{ width: '130px' }}>Status & Kontrak</th>
                    <th className="py-3 px-4 text-[11px] font-bold text-slate-400 uppercase tracking-widest" style={{ width: '130px' }}>Tanggal Masuk</th>
                    <th className="py-3 px-4 text-[11px] font-bold text-slate-400 uppercase tracking-widest" style={{ width: '110px' }}>Batas Klaim</th>
                    <th className="py-3 px-4 text-[11px] font-bold text-slate-400 uppercase tracking-widest" style={{ width: '110px' }}>Nomor HP</th>
                    <th className="py-3 px-4 text-[11px] font-bold text-slate-400 uppercase tracking-widest text-right" style={{ width: '150px' }}>Aksi</th>
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
                          <div className="h-5 bg-slate-200 dark:bg-slate-700 rounded-full w-14" />
                        </td>
                        <td className="py-3 px-4">
                          <div className="h-3 bg-slate-200 dark:bg-slate-700 rounded w-20" />
                        </td>
                        <td className="py-3 px-4">
                          <div className="h-3 bg-slate-200 dark:bg-slate-700 rounded w-24" />
                        </td>
                        <td className="py-3 px-4">
                          <div className="h-3 bg-slate-200 dark:bg-slate-700 rounded w-20" />
                        </td>
                        <td className="py-3 px-4 text-right">
                          <div className="h-7 w-7 bg-slate-200 dark:bg-slate-700 rounded-lg ml-auto" />
                        </td>
                      </tr>
                    ))
                  ) : filteredEmployees.length === 0 ? (
                    <tr>
                      <td colSpan={9} className="py-12 text-center text-xs text-slate-450 dark:text-slate-500">
                        Tidak ada data karyawan yang cocok dengan pencarian dan filter Anda.
                      </td>
                    </tr>
                  ) : (
                    filteredEmployees.map((emp) => {
                      const cStat = contractStatus(emp.contractEndDate);
                      const badgeInfo = emp.employmentType ? EMPLOYMENT_BADGE[emp.employmentType] : null;

                      return (
                        <tr
                          key={emp.id}
                          className="hover:bg-slate-50/40 dark:hover:bg-slate-850/10 border-b border-slate-100 dark:border-slate-805/40 transition last:border-b-0"
                        >
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

                          <td className="py-3 px-4">
                            <span className="text-[11px] font-semibold text-slate-500 font-mono tracking-tight">{emp.id}</span>
                          </td>

                          <td className="py-3 px-4">
                            <span className="text-xs font-semibold text-slate-700 dark:text-slate-350 block">{emp.dept}</span>
                            {emp.officeId && (
                              <span className="text-[10px] text-indigo-500 dark:text-indigo-400 flex items-center gap-1 mt-0.5">
                                <Building className="w-3 h-3 shrink-0" />
                                {emp.officeName}
                              </span>
                            )}
                          </td>

                          <td className="py-3 px-4">
                            <span className="text-[11px] text-slate-500 dark:text-slate-400">{emp.jabatan}</span>
                          </td>

                          <td className="py-3 px-4 space-y-1">
                            <div className="flex flex-wrap items-center gap-1">
                              {emp.status === 'Aktif' && (
                                <span className="inline-flex items-center gap-1 px-1.5 py-0.5 text-[9px] font-bold text-emerald-600 dark:text-emerald-450 bg-emerald-50 dark:bg-emerald-950/20 rounded-full">
                                  <Check className="w-2.5 h-2.5 shrink-0" />
                                  <span>Aktif</span>
                                </span>
                              )}
                              {emp.status === 'Nonaktif' && (
                                <span className="inline-flex items-center gap-1 px-1.5 py-0.5 text-[9px] font-bold text-rose-600 dark:text-rose-450 bg-rose-50 dark:bg-rose-950/20 rounded-full">
                                  <Ban className="w-2.5 h-2.5 shrink-0" />
                                  <span>Nonaktif</span>
                                </span>
                              )}
                              {emp.status === 'Belum login' && (
                                <span className="inline-flex items-center gap-1 px-1.5 py-0.5 text-[9px] font-bold text-amber-600 dark:text-amber-450 bg-amber-50 dark:bg-amber-950/20 rounded-full whitespace-nowrap">
                                  <Clock className="w-2.5 h-2.5 shrink-0" />
                                  <span>Belum login</span>
                                </span>
                              )}

                              {badgeInfo && (
                                <span className={`inline-flex items-center px-1.5 py-0.5 text-[9px] font-bold rounded-full ${badgeInfo.cls}`}>
                                  {badgeInfo.label}
                                </span>
                              )}
                            </div>

                            {emp.employmentType === 'PKWT' && (
                              <div className="mt-0.5">
                                {cStat === 'active' && (
                                  <span className="inline-flex items-center gap-1 text-[9px] font-semibold text-emerald-600 dark:text-emerald-400 bg-emerald-50/80 dark:bg-emerald-950/30 px-1.5 py-0.5 rounded border border-emerald-200 dark:border-emerald-800/40">
                                    <span className="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse" />
                                    s.d {formatDateId(emp.contractEndDate)}
                                  </span>
                                )}
                                {cStat === 'near' && (
                                  <span className="inline-flex items-center gap-1 text-[9px] font-semibold text-amber-700 dark:text-amber-400 bg-amber-50 dark:bg-amber-950/40 px-1.5 py-0.5 rounded border border-amber-200 dark:border-amber-800/40" title="Kontrak berakhir <= 30 hari lagi">
                                    <AlertTriangle className="w-2.5 h-2.5 shrink-0 text-amber-600" />
                                    Hampir Expired
                                  </span>
                                )}
                                {cStat === 'expired' && (
                                  <span className="inline-flex items-center gap-1 text-[9px] font-semibold text-rose-700 dark:text-rose-400 bg-rose-50 dark:bg-rose-950/40 px-1.5 py-0.5 rounded border border-rose-200 dark:border-rose-800/40">
                                    <Ban className="w-2.5 h-2.5 shrink-0 text-rose-600" />
                                    Expired
                                  </span>
                                )}
                              </div>
                            )}
                          </td>

                          <td className="py-3 px-4 whitespace-nowrap">
                            <span className="text-xs font-semibold text-slate-700 dark:text-slate-200 block">
                              {formatDateId(emp.joinedDate || emp.tanggalMasuk || '') || '—'}
                            </span>
                            <span className="text-[10px] text-slate-400 dark:text-slate-500 font-medium flex items-center gap-1 mt-0.5">
                              <Clock className="w-2.5 h-2.5 shrink-0" />
                              Masa kerja: {calculateTenure(emp.joinedDate || emp.tanggalMasuk)}
                            </span>
                          </td>

                          <td className="py-3 px-4">
                            <span className="text-xs font-bold text-slate-800 dark:text-slate-200 font-mono">{formatCurrency(emp.limit)}</span>
                          </td>

                          <td className="py-3 px-4 whitespace-nowrap">
                            <span className="text-[11px] text-slate-500 dark:text-slate-400 font-medium">{emp.hp}</span>
                          </td>

                          <td className="py-3 px-4 text-right">
                            <div className="flex items-center justify-end gap-1">
                              {/* 1. Detail Lengkap (360°) */}
                              <button
                                onClick={() => {
                                  setDetailEmployee(emp);
                                  setDetailTab('profile');
                                }}
                                className="p-1.5 rounded-lg text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 dark:hover:bg-indigo-950/50 dark:hover:text-indigo-400 transition cursor-pointer"
                                title="Detail Lengkap (360°)"
                              >
                                <Eye className="w-4 h-4" />
                              </button>

                              {/* 2. Edit Data */}
                              <button
                                onClick={() => handleOpenEdit(emp)}
                                className="p-1.5 rounded-lg text-slate-400 hover:text-blue-600 hover:bg-blue-50 dark:hover:bg-blue-950/50 dark:hover:text-blue-400 transition cursor-pointer"
                                title="Edit Data Karyawan"
                              >
                                <Edit2 className="w-4 h-4" />
                              </button>

                              {/* 3. Aksi berdasarkan Status: Aktif vs Nonaktif */}
                              {emp.status !== 'Nonaktif' ? (
                                <>
                                  {/* Reset Password */}
                                  <button
                                    onClick={() => handleOpenResetPwd(emp)}
                                    className="p-1.5 rounded-lg text-slate-400 hover:text-amber-600 hover:bg-amber-50 dark:hover:bg-amber-950/50 dark:hover:text-amber-400 transition cursor-pointer"
                                    title="Reset Password Karyawan"
                                  >
                                    <Lock className="w-4 h-4" />
                                  </button>

                                  {/* Nonaktifkan Akun (Bekukan) */}
                                  <button
                                    onClick={() => handleOpenToggleStatus(emp)}
                                    className="p-1.5 rounded-lg text-slate-400 hover:text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-950/50 dark:hover:text-rose-400 transition cursor-pointer"
                                    title="Nonaktifkan Akun"
                                  >
                                    <Ban className="w-4 h-4" />
                                  </button>
                                </>
                              ) : (
                                <>
                                  {/* Aktifkan Kembali Akun */}
                                  <button
                                    onClick={() => handleOpenToggleStatus(emp)}
                                    className="p-1.5 rounded-lg text-slate-400 hover:text-emerald-600 hover:bg-emerald-50 dark:hover:bg-emerald-950/50 dark:hover:text-emerald-400 transition cursor-pointer"
                                    title="Aktifkan Kembali Akun"
                                  >
                                    <Check className="w-4 h-4" />
                                  </button>

                                  {/* Hapus Karyawan (Soft Delete) - HANYA MUNCUL DI AKUN NONAKTIF */}
                                  <button
                                    onClick={() => handleDeleteEmployee(emp)}
                                    className="p-1.5 rounded-lg text-slate-400 hover:text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-950/50 dark:hover:text-rose-400 transition cursor-pointer"
                                    title="Hapus Karyawan (Soft Delete)"
                                  >
                                    <Trash2 className="w-4 h-4" />
                                  </button>
                                </>
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
        </>
      ) : viewMode === 'add' ? (
        /* TAMBAH KARYAWAN WEB FORM (Form adaptif berdasarkan Status Kontrak & Kebutuhan Payroll Roadmap) */
        <form onSubmit={handleAddNewEmployeeSubmit} className="space-y-6 leading-relaxed">
          {/* 1. SELEKTOR UTAMA STATUS KONTRAK (Paling Atas) */}
          <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-3xl p-6 shadow-xs space-y-4">
            <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-2 pb-3 border-b border-slate-100 dark:border-slate-800">
              <div>
                <span className="text-xs font-bold text-slate-850 dark:text-slate-100 flex items-center gap-2">
                  <Briefcase className="w-4 h-4 text-indigo-600" />
                  Pilih Status Hubungan Kerja Karyawan *
                </span>
                <p className="text-[11px] text-slate-400 dark:text-slate-500 mt-0.5">
                  Formulir di bawah akan otomatis menyesuaikan kebutuhan data kontrak, hak benefit, dan skema payroll berdasarkan status yang Anda pilih.
                </p>
              </div>
              <span className="px-2.5 py-1 text-[10px] font-bold rounded-full bg-indigo-50 dark:bg-indigo-950/40 text-indigo-700 dark:text-indigo-400 border border-indigo-100 dark:border-indigo-800 shrink-0">
                Langkah 1 dari 2
              </span>
            </div>

            {/* 4 Card Pilihan Status Kontrak */}
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
              {/* PKWTT (Tetap) */}
              <button
                type="button"
                onClick={() => setAddForm({
                  ...addForm,
                  employmentType: 'PKWTT',
                  hasJht: true,
                  hasJp: true,
                  salaryType: 'monthly',
                  overtimeEligible: true
                })}
                className={`p-4 rounded-2xl border text-left transition duration-150 cursor-pointer flex flex-col justify-between relative overflow-hidden ${
                  addForm.employmentType === 'PKWTT'
                    ? 'bg-teal-50/60 dark:bg-teal-950/30 border-teal-500 dark:border-teal-500 ring-2 ring-teal-500/20 shadow-sm'
                    : 'bg-white dark:bg-slate-800/40 border-slate-200 dark:border-slate-700 hover:border-teal-300 dark:hover:border-teal-700'
                }`}
              >
                {addForm.employmentType === 'PKWTT' && (
                  <span className="absolute top-2 right-2 w-5 h-5 rounded-full bg-teal-500 text-white flex items-center justify-center">
                    <Check className="w-3 h-3" />
                  </span>
                )}
                <div>
                  <div className="w-8 h-8 rounded-xl bg-teal-100 dark:bg-teal-900/50 text-teal-700 dark:text-teal-400 flex items-center justify-center mb-2.5">
                    <ShieldCheck className="w-4.5 h-4.5" />
                  </div>
                  <h4 className="text-xs font-bold text-slate-800 dark:text-slate-100">PKWTT (Tetap)</h4>
                  <p className="text-[10px] text-slate-500 dark:text-slate-400 mt-1 leading-relaxed">
                    Karyawan tetap tanpa batas kontrak. Fasilitas BPJS lengkap (JHT + JP) & cuti tahunan penuh.
                  </p>
                </div>
                <div className="mt-3 pt-2.5 border-t border-teal-100 dark:border-teal-900/40 text-[9px] font-semibold text-teal-700 dark:text-teal-400">
                  • Gaji Bulanan • BPJS Lengkap
                </div>
              </button>

              {/* PKWT (Kontrak) */}
              <button
                type="button"
                onClick={() => setAddForm({
                  ...addForm,
                  employmentType: 'PKWT',
                  hasJht: true,
                  hasJp: true,
                  salaryType: 'monthly',
                  overtimeEligible: true
                })}
                className={`p-4 rounded-2xl border text-left transition duration-150 cursor-pointer flex flex-col justify-between relative overflow-hidden ${
                  addForm.employmentType === 'PKWT'
                    ? 'bg-blue-50/60 dark:bg-blue-950/30 border-blue-500 dark:border-blue-500 ring-2 ring-blue-500/20 shadow-sm'
                    : 'bg-white dark:bg-slate-800/40 border-slate-200 dark:border-slate-700 hover:border-blue-300 dark:hover:border-blue-700'
                }`}
              >
                {addForm.employmentType === 'PKWT' && (
                  <span className="absolute top-2 right-2 w-5 h-5 rounded-full bg-blue-500 text-white flex items-center justify-center">
                    <Check className="w-3 h-3" />
                  </span>
                )}
                <div>
                  <div className="w-8 h-8 rounded-xl bg-blue-100 dark:bg-blue-900/50 text-blue-700 dark:text-blue-400 flex items-center justify-center mb-2.5">
                    <Clock className="w-4.5 h-4.5" />
                  </div>
                  <h4 className="text-xs font-bold text-slate-800 dark:text-slate-100">PKWT (Kontrak)</h4>
                  <p className="text-[10px] text-slate-500 dark:text-slate-400 mt-1 leading-relaxed">
                    Karyawan kontrak bertempo. Membutuhkan tanggal mulai & selesai kontrak (reminder H-30).
                  </p>
                </div>
                <div className="mt-3 pt-2.5 border-t border-blue-100 dark:border-blue-900/40 text-[9px] font-semibold text-blue-700 dark:text-blue-400">
                  • Wajib Tgl Kontrak • Evaluasi
                </div>
              </button>

              {/* Probation (Masa Percobaan) */}
              <button
                type="button"
                onClick={() => setAddForm({
                  ...addForm,
                  employmentType: 'Probation',
                  hasJht: true,
                  hasJp: false,
                  salaryType: 'monthly',
                  overtimeEligible: true
                })}
                className={`p-4 rounded-2xl border text-left transition duration-150 cursor-pointer flex flex-col justify-between relative overflow-hidden ${
                  addForm.employmentType === 'Probation'
                    ? 'bg-amber-50/60 dark:bg-amber-950/30 border-amber-500 dark:border-amber-500 ring-2 ring-amber-500/20 shadow-sm'
                    : 'bg-white dark:bg-slate-800/40 border-slate-200 dark:border-slate-700 hover:border-amber-300 dark:hover:border-amber-700'
                }`}
              >
                {addForm.employmentType === 'Probation' && (
                  <span className="absolute top-2 right-2 w-5 h-5 rounded-full bg-amber-500 text-white flex items-center justify-center">
                    <Check className="w-3 h-3" />
                  </span>
                )}
                <div>
                  <div className="w-8 h-8 rounded-xl bg-amber-100 dark:bg-amber-900/50 text-amber-700 dark:text-amber-400 flex items-center justify-center mb-2.5">
                    <Sparkles className="w-4.5 h-4.5" />
                  </div>
                  <h4 className="text-xs font-bold text-slate-800 dark:text-slate-100">Probasi (Percobaan)</h4>
                  <p className="text-[10px] text-slate-500 dark:text-slate-400 mt-1 leading-relaxed">
                    Calon karyawan tetap dalam masa evaluasi kinerja (biasanya 3–6 bulan) sebelum diangkat PKWTT.
                  </p>
                </div>
                <div className="mt-3 pt-2.5 border-t border-amber-100 dark:border-amber-900/40 text-[9px] font-semibold text-amber-700 dark:text-amber-400">
                  • Evaluasi 3-6 Bulan • Calon Tetap
                </div>
              </button>

              {/* Internship (Magang / Freelance) */}
              <button
                type="button"
                onClick={() => setAddForm({
                  ...addForm,
                  employmentType: 'Internship',
                  hasJht: false,
                  hasJp: false,
                  salaryType: 'daily',
                  overtimeEligible: false
                })}
                className={`p-4 rounded-2xl border text-left transition duration-150 cursor-pointer flex flex-col justify-between relative overflow-hidden ${
                  addForm.employmentType === 'Internship'
                    ? 'bg-purple-50/60 dark:bg-purple-950/30 border-purple-500 dark:border-purple-500 ring-2 ring-purple-500/20 shadow-sm'
                    : 'bg-white dark:bg-slate-800/40 border-slate-200 dark:border-slate-700 hover:border-purple-300 dark:hover:border-purple-700'
                }`}
              >
                {addForm.employmentType === 'Internship' && (
                  <span className="absolute top-2 right-2 w-5 h-5 rounded-full bg-purple-500 text-white flex items-center justify-center">
                    <Check className="w-3 h-3" />
                  </span>
                )}
                <div>
                  <div className="w-8 h-8 rounded-xl bg-purple-100 dark:bg-purple-900/50 text-purple-700 dark:text-purple-400 flex items-center justify-center mb-2.5">
                    <Users className="w-4.5 h-4.5" />
                  </div>
                  <h4 className="text-xs font-bold text-slate-800 dark:text-slate-100">Magang / Internship</h4>
                  <p className="text-[10px] text-slate-500 dark:text-slate-400 mt-1 leading-relaxed">
                    Siswa/mahasiswa magang atau tenaga proyek khusus. Berbasis uang saku/honorarium.
                  </p>
                </div>
                <div className="mt-3 pt-2.5 border-t border-purple-100 dark:border-purple-900/40 text-[9px] font-semibold text-purple-700 dark:text-purple-400">
                  • Uang Saku / Harian • Tanpa JP/JHT
                </div>
              </button>
            </div>

            {/* Banner info penjelas status terpilih */}
            <div className={`p-3.5 rounded-2xl border text-xs flex items-start gap-2.5 ${
              addForm.employmentType === 'PKWTT'
                ? 'bg-teal-50/50 dark:bg-teal-950/20 border-teal-200 dark:border-teal-900/40 text-teal-900 dark:text-teal-300'
                : addForm.employmentType === 'PKWT'
                ? 'bg-blue-50/50 dark:bg-blue-950/20 border-blue-200 dark:border-blue-900/40 text-blue-900 dark:text-blue-300'
                : addForm.employmentType === 'Probation'
                ? 'bg-amber-50/50 dark:bg-amber-950/20 border-amber-200 dark:border-amber-900/40 text-amber-900 dark:text-amber-300'
                : 'bg-purple-50/50 dark:bg-purple-950/20 border-purple-200 dark:border-purple-900/40 text-purple-900 dark:text-purple-300'
            }`}>
              <Info className="w-4.5 h-4.5 shrink-0 mt-0.5" />
              <div className="space-y-0.5">
                <p className="font-bold text-[11px]">
                  Mode Formulir: {
                    addForm.employmentType === 'PKWTT' ? 'Karyawan Tetap (PKWTT)' :
                    addForm.employmentType === 'PKWT' ? 'Karyawan Kontrak (PKWT)' :
                    addForm.employmentType === 'Probation' ? 'Calon Karyawan Probasi' :
                    'Peserta Magang / Internship'
                  }
                </p>
                <p className="text-[10px] opacity-90 leading-relaxed">
                  {addForm.employmentType === 'PKWTT' && 'Semua data rekening bank, NPWP, PTKP, dan BPJS lengkap wajib disiapkan untuk penggajian bulanan resmi.'}
                  {addForm.employmentType === 'PKWT' && 'Wajib mengisi tanggal mulai dan selesai kontrak kerja agar sistem dapat memberikan peringatan perpanjangan kontrak H-30.'}
                  {addForm.employmentType === 'Probation' && 'Target masa percobaan dapat diatur 3 s.d 6 bulan. Karyawan akan dievaluasi sebelum diangkat ke status PKWTT.'}
                  {addForm.employmentType === 'Internship' && 'Dapat menggunakan basis uang saku harian atau bulanan. Tidak dikenakan iuran Jaminan Pensiun (JP) & JHT.'}
                </p>
              </div>
            </div>
          </div>

          {/* 2. GRID 2 KOLOM FORM DATA DETAIL */}
          <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
            {/* KOLOM KIRI: DATA PRIBADI & PEKERJAAN */}
            <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-3xl p-6 shadow-xs space-y-4">
              <div className="flex justify-between items-center pb-3 border-b border-slate-100 dark:border-slate-800">
                <span className="text-xs font-bold text-slate-850 dark:text-slate-100 flex items-center gap-2">
                  <UserPlus className="w-4 h-4 text-indigo-600" />
                  1. Data Pribadi & Profil Pekerjaan
                </span>
                <span className="text-[10px] text-slate-400 font-mono">* Wajib diisi</span>
              </div>

              <div className="space-y-3.5">
                <span className="text-[10px] font-extrabold text-slate-400 dark:text-slate-500 uppercase tracking-widest block pb-1 border-b border-light-divider dark:border-slate-800/80 mb-3">
                  Identitas Diri
                </span>

                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                  <div className="space-y-1">
                    <label className="text-[10px] font-bold text-slate-400 dark:text-slate-500 block">Nama Lengkap *</label>
                    <input
                      type="text"
                      value={addForm.nama}
                      onChange={(e) => setAddForm({ ...addForm, nama: e.target.value })}
                      required
                      placeholder="Sesuai KTP"
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

                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                  <div className="space-y-1">
                    <label className="text-[10px] font-bold text-slate-400 dark:text-slate-500 block">NIK KTP (16 Digit) *</label>
                    <input
                      type="text"
                      value={addForm.nikKtp}
                      onChange={(e) => setAddForm({ ...addForm, nikKtp: e.target.value.replace(/[^0-9]/g, '') })}
                      required
                      maxLength={16}
                      placeholder="16 digit NIK KTP"
                      className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-800/10 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-1 focus:ring-indigo-500 font-mono"
                    />
                  </div>
                  <div className="space-y-1">
                    <label className="text-[10px] font-bold text-slate-400 dark:text-slate-500 block">Nomor WhatsApp / HP</label>
                    <input
                      type="text"
                      value={addForm.hp}
                      onChange={(e) => setAddForm({ ...addForm, hp: e.target.value.replace(/[^0-9]/g, '') })}
                      maxLength={13}
                      placeholder="08xxxxxxxxxx"
                      className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-801/10 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                    />
                  </div>
                </div>

                <div className="space-y-1">
                  <label className="text-[10px] font-bold text-slate-400 dark:text-slate-500 block">
                    Email Kantor * <span className="text-[9px] text-slate-400 dark:text-slate-500 font-normal">(Digunakan untuk login aplikasi)</span>
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

                <span className="text-[10px] font-extrabold text-slate-400 dark:text-slate-500 uppercase tracking-widest block pb-1 border-b border-light-divider dark:border-slate-800/80 pt-4 mb-3">
                  Ketentuan Tanggal & Kontrak ({addForm.employmentType})
                </span>

                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                  <div className="space-y-1">
                    <label className="text-[10px] font-bold text-slate-400 dark:text-slate-500 block">Tanggal Bergabung (Joined Date) *</label>
                    <input
                      type="date"
                      value={addForm.joinedDate}
                      onChange={(e) => setAddForm({ ...addForm, joinedDate: e.target.value, tanggalMasuk: e.target.value })}
                      required
                      className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-801/10 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                    />
                  </div>

                  {/* Jika PKWT: Tampilkan input mulai & selesai kontrak */}
                  {addForm.employmentType === 'PKWT' && (
                    <div className="space-y-1">
                      <label className="text-[10px] font-bold text-blue-600 dark:text-blue-400 block">Selesai Kontrak (Expired Date) *</label>
                      <input
                        type="date"
                        value={addForm.contractEndDate}
                        onChange={(e) => setAddForm({ ...addForm, contractEndDate: e.target.value })}
                        required={addForm.employmentType === 'PKWT'}
                        className="w-full text-xs p-2.5 border border-blue-300 dark:border-blue-800 rounded-xl bg-blue-50/40 dark:bg-blue-950/20 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-1 focus:ring-blue-500"
                      />
                    </div>
                  )}

                  {/* Jika Probation: Target evaluasi probasi */}
                  {addForm.employmentType === 'Probation' && (
                    <div className="space-y-1">
                      <label className="text-[10px] font-bold text-amber-600 dark:text-amber-400 block">Target Selesai Probasi (3-6 Bln) *</label>
                      <input
                        type="date"
                        value={addForm.contractEndDate}
                        onChange={(e) => setAddForm({ ...addForm, contractEndDate: e.target.value })}
                        required={addForm.employmentType === 'Probation'}
                        className="w-full text-xs p-2.5 border border-amber-300 dark:border-amber-800 rounded-xl bg-amber-50/40 dark:bg-amber-950/20 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-1 focus:ring-amber-500"
                      />
                    </div>
                  )}

                  {/* Jika Internship: Selesai magang */}
                  {addForm.employmentType === 'Internship' && (
                    <div className="space-y-1">
                      <label className="text-[10px] font-bold text-purple-600 dark:text-purple-400 block">Target Selesai Magang</label>
                      <input
                        type="date"
                        value={addForm.contractEndDate}
                        onChange={(e) => setAddForm({ ...addForm, contractEndDate: e.target.value })}
                        className="w-full text-xs p-2.5 border border-purple-300 dark:border-purple-800 rounded-xl bg-purple-50/40 dark:bg-purple-950/20 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-1 focus:ring-purple-500"
                      />
                    </div>
                  )}
                </div>

                {/* Form Khusus Rentang Kontrak PKWT */}
                {addForm.employmentType === 'PKWT' && (
                  <div className="p-3 bg-blue-50/50 dark:bg-blue-950/20 border border-blue-100 dark:border-blue-900/30 rounded-xl space-y-2">
                    <p className="text-[11px] font-bold text-blue-800 dark:text-blue-300 flex items-center gap-1.5">
                      <Clock className="w-3.5 h-3.5" /> Tanggal Mulai Kontrak PKWT
                    </p>
                    <input
                      type="date"
                      value={addForm.contractStartDate || addForm.joinedDate}
                      onChange={(e) => setAddForm({ ...addForm, contractStartDate: e.target.value })}
                      className="w-full text-xs p-2 border border-slate-200 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-1 focus:ring-blue-500"
                    />
                  </div>
                )}

                <span className="text-[10px] font-extrabold text-slate-400 dark:text-slate-500 uppercase tracking-widest block pb-1 border-b border-light-divider dark:border-slate-800/80 pt-4 mb-3">
                  Penempatan & Akses Sistem
                </span>

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
                    <label className="text-[10px] font-bold text-slate-400 dark:text-slate-500 block">Jabatan / Posisi</label>
                    <input
                      type="text"
                      value={addForm.jabatan}
                      onChange={(e) => setAddForm({ ...addForm, jabatan: e.target.value })}
                      placeholder="Staff / Officer / Manager"
                      className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-803/10 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                    />
                  </div>
                </div>

                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                  <div className="space-y-1">
                    <label className="text-[10px] font-bold text-slate-400 dark:text-slate-500 block">Kantor Penempatan (Cabang)</label>
                    <select
                      value={addForm.officeId}
                      onChange={(e) => setAddForm({ ...addForm, officeId: e.target.value === '' ? '' : Number(e.target.value) })}
                      className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-802/10 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-1 focus:ring-indigo-500 font-semibold"
                    >
                      <option value="">Belum ditentukan (Kantor Pusat)</option>
                      {offices.map(o => (
                        <option key={o.id} value={o.id}>{o.office_name}</option>
                      ))}
                    </select>
                  </div>

                  <div className="space-y-1">
                    <label className="text-[10px] font-bold text-slate-400 dark:text-slate-500 block">Role Akun *</label>
                    <select
                      value={addForm.role}
                      onChange={(e) => setAddForm({ ...addForm, role: e.target.value })}
                      required
                      className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-802/10 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-1 focus:ring-indigo-500 font-semibold"
                    >
                      <option value="employee">Employee (Karyawan - Mobile App)</option>
                      <option value="finance">Finance (Web + Mobile)</option>
                      <option value="hrd">HRD (Web + Mobile)</option>
                      <option value="admin">Admin (Web + Mobile)</option>
                      <option value="super_admin">Super Admin (Full Akses)</option>
                    </select>
                  </div>
                </div>

                <div className="space-y-1">
                  <label className="text-[10px] font-bold text-slate-400 dark:text-slate-500 block">Batas Klaim Struk Bulanan (Reimbursement)</label>
                  <div className="relative">
                    <span className="absolute left-3 top-2.5 text-xs font-bold text-slate-400 font-mono">Rp</span>
                    <input
                      type="number"
                      value={addForm.limit === null ? '' : addForm.limit}
                      onChange={(e) => setAddForm({ ...addForm, limit: e.target.value === '' ? '' : Number(e.target.value) })}
                      placeholder="Kosongkan jika tanpa batas klaim"
                      className="w-full text-xs p-2.5 pl-9 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-800/10 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-1 focus:ring-indigo-500 font-mono"
                    />
                  </div>
                </div>
              </div>
            </div>

            {/* KOLOM KANAN: FINANSIAL PAYROLL, REKENING BANK, PAJAK, BPJS & KEAMANAN */}
            <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-3xl p-6 shadow-xs space-y-4">
              <div className="flex justify-between items-center pb-3 border-b border-slate-100 dark:border-slate-800">
                <span className="text-xs font-bold text-slate-850 dark:text-slate-100 flex items-center gap-2">
                  <Wallet className="w-4 h-4 text-emerald-600" />
                  2. Finansial Payroll, Pajak & BPJS
                </span>
                <span className="text-[10px] font-semibold text-emerald-600 bg-emerald-50 dark:bg-emerald-950/40 px-2 py-0.5 rounded-md">
                  Roadmap Gaji HRIS
                </span>
              </div>

              <div className="space-y-3.5">
                {/* A. REKENING BANK PAYROLL */}
                <span className="text-[10px] font-extrabold text-slate-400 dark:text-slate-500 uppercase tracking-widest block pb-1 border-b border-light-divider dark:border-slate-800/80 mb-3">
                  Informasi Rekening Payroll
                </span>

                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                  <div className="space-y-1">
                    <label className="text-[10px] font-bold text-slate-400 dark:text-slate-500 block">Bank Transfer</label>
                    <select
                      value={addForm.bankName}
                      onChange={(e) => setAddForm({ ...addForm, bankName: e.target.value })}
                      className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-802/10 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-1 focus:ring-indigo-500 font-semibold"
                    >
                      <option value="BCA">Bank BCA</option>
                      <option value="Mandiri">Bank Mandiri</option>
                      <option value="BRI">Bank BRI</option>
                      <option value="BNI">Bank BNI</option>
                      <option value="CIMB">CIMB Niaga</option>
                      <option value="Permata">Bank Permata</option>
                      <option value="BSI">Bank Syariah Indonesia (BSI)</option>
                      <option value="Danamon">Bank Danamon</option>
                      <option value="Other">Bank Lainnya</option>
                    </select>
                  </div>
                  <div className="space-y-1">
                    <label className="text-[10px] font-bold text-slate-400 dark:text-slate-500 block">Nomor Rekening</label>
                    <input
                      type="text"
                      value={addForm.bankAccountNo}
                      onChange={(e) => setAddForm({ ...addForm, bankAccountNo: e.target.value.replace(/[^0-9]/g, '') })}
                      placeholder="Nomor rekening transfer"
                      className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-800/10 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-1 focus:ring-indigo-500 font-mono"
                    />
                  </div>
                </div>

                <div className="space-y-1">
                  <label className="text-[10px] font-bold text-slate-400 dark:text-slate-500 block">Nama Pemilik Rekening (A.N. Rekening)</label>
                  <input
                    type="text"
                    value={addForm.bankAccountHolder || addForm.nama}
                    onChange={(e) => setAddForm({ ...addForm, bankAccountHolder: e.target.value })}
                    placeholder="Nama sesuai buku tabungan"
                    className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-800/10 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                  />
                </div>

                {/* B. PENGUPAHAN & GAJI DASAR */}
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3.5 pt-2">
                  <div className="space-y-1">
                    <label className="text-[10px] font-bold text-slate-400 dark:text-slate-500 block">Skema Pengupahan</label>
                    <select
                      value={addForm.salaryType}
                      onChange={(e) => setAddForm({ ...addForm, salaryType: e.target.value as any })}
                      className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-802/10 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-1 focus:ring-indigo-500 font-semibold"
                    >
                      <option value="monthly">Gaji Bulanan (Monthly)</option>
                      <option value="daily">Harian / Uang Saku (Daily)</option>
                      <option value="hourly">Per Jam (Hourly)</option>
                    </select>
                  </div>
                  <div className="space-y-1">
                    <label className="text-[10px] font-bold text-slate-400 dark:text-slate-500 block">
                      {addForm.employmentType === 'Internship' ? 'Uang Saku / Honor (IDR)' : 'Gaji Pokok Acuan (IDR)'}
                    </label>
                    <div className="relative">
                      <span className="absolute left-3 top-2.5 text-xs font-bold text-slate-400 font-mono">Rp</span>
                      <input
                        type="number"
                        value={addForm.basicSalary === null ? '' : addForm.basicSalary}
                        onChange={(e) => setAddForm({ ...addForm, basicSalary: e.target.value === '' ? '' : Number(e.target.value) })}
                        placeholder="Contoh: 5000000"
                        className="w-full text-xs p-2.5 pl-9 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-800/10 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-1 focus:ring-indigo-500 font-mono"
                      />
                    </div>
                  </div>
                </div>

                {/* C. DATA PERPAJAKAN PPH 21 TER 2024 */}
                <span className="text-[10px] font-extrabold text-slate-400 dark:text-slate-500 uppercase tracking-widest block pb-1 border-b border-light-divider dark:border-slate-800/80 pt-4 mb-3">
                  Pajak Penghasilan (PPh 21 TER 2024)
                </span>

                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                  <div className="space-y-1">
                    <label className="text-[10px] font-bold text-slate-400 dark:text-slate-500 block">
                      Nomor NPWP <span className="font-normal text-slate-400">(Opsional)</span>
                    </label>
                    <input
                      type="text"
                      value={addForm.npwp}
                      onChange={(e) => setAddForm({ ...addForm, npwp: e.target.value.replace(/[^0-9]/g, '') })}
                      maxLength={16}
                      placeholder="15 atau 16 digit NPWP"
                      className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-800/10 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-1 focus:ring-indigo-500 font-mono"
                    />
                  </div>

                  <div className="space-y-1">
                    <label className="text-[10px] font-bold text-slate-400 dark:text-slate-500 block">Status PTKP (Kategori TER) *</label>
                    <select
                      value={addForm.ptkpStatus}
                      onChange={(e) => setAddForm({ ...addForm, ptkpStatus: e.target.value })}
                      className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-802/10 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-1 focus:ring-indigo-500 font-semibold"
                    >
                      <optgroup label="TER Kategori A (PTKP Rp 54jt - 58.5jt)">
                        <option value="TK/0">TK/0 — Tidak Kawin, 0 Tanggungan (TER A)</option>
                        <option value="TK/1">TK/1 — Tidak Kawin, 1 Tanggungan (TER A)</option>
                        <option value="K/0">K/0 — Kawin, 0 Tanggungan (TER A)</option>
                      </optgroup>
                      <optgroup label="TER Kategori B (PTKP Rp 63jt - 67.5jt)">
                        <option value="TK/2">TK/2 — Tidak Kawin, 2 Tanggungan (TER B)</option>
                        <option value="TK/3">TK/3 — Tidak Kawin, 3 Tanggungan (TER B)</option>
                        <option value="K/1">K/1 — Kawin, 1 Tanggungan (TER B)</option>
                        <option value="K/2">K/2 — Kawin, 2 Tanggungan (TER B)</option>
                      </optgroup>
                      <optgroup label="TER Kategori C (PTKP Rp 72jt ke atas)">
                        <option value="K/3">K/3 — Kawin, 3 Tanggungan (TER C)</option>
                        <option value="K/I/0">K/I/0 — Kawin Istri Gabung, 0 Tanggungan (TER C)</option>
                        <option value="K/I/1">K/I/1 — Kawin Istri Gabung, 1 Tanggungan (TER C)</option>
                        <option value="K/I/2">K/I/2 — Kawin Istri Gabung, 2 Tanggungan (TER C)</option>
                        <option value="K/I/3">K/I/3 — Kawin Istri Gabung, 3 Tanggungan (TER C)</option>
                      </optgroup>
                    </select>
                  </div>
                </div>

                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                  <div className="space-y-1">
                    <label className="text-[10px] font-bold text-slate-400 dark:text-slate-500 block">Metode Perhitungan Pajak</label>
                    <select
                      value={addForm.taxMethod}
                      onChange={(e) => setAddForm({ ...addForm, taxMethod: e.target.value as any })}
                      className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-802/10 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-1 focus:ring-indigo-500 font-semibold"
                    >
                      <option value="gross">Gross (PPh 21 Potong Gaji Karyawan)</option>
                      <option value="gross_up">Gross-Up (Diberi Tunjangan Pajak)</option>
                      <option value="nett">Nett (Pajak Ditanggung Perusahaan)</option>
                    </select>
                  </div>

                  <div className="space-y-1">
                    <label className="text-[10px] font-bold text-slate-400 dark:text-slate-500 block">Hak Upah Lembur (Overtime)</label>
                    <div className="flex items-center gap-2 pt-1.5">
                      <label className="flex items-center gap-2 text-xs font-semibold text-slate-700 dark:text-slate-300 cursor-pointer">
                        <input
                          type="checkbox"
                          checked={addForm.overtimeEligible}
                          onChange={(e) => setAddForm({ ...addForm, overtimeEligible: e.target.checked })}
                          className="w-4 h-4 rounded text-indigo-600 focus:ring-indigo-500"
                        />
                        <span>Berhak Lembur Berbayar (PP 35/2021)</span>
                      </label>
                    </div>
                  </div>
                </div>

                {/* D. KEPESERTAAN BPJS */}
                <span className="text-[10px] font-extrabold text-slate-400 dark:text-slate-500 uppercase tracking-widest block pb-1 border-b border-light-divider dark:border-slate-800/80 pt-4 mb-3">
                  Keikutsertaan BPJS Kesehatan & Ketenagakerjaan
                </span>

                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                  <div className="space-y-1">
                    <label className="text-[10px] font-bold text-slate-400 dark:text-slate-500 block">No. BPJS Kesehatan (13 Digit)</label>
                    <input
                      type="text"
                      value={addForm.bpjsKesehatanNo}
                      onChange={(e) => setAddForm({ ...addForm, bpjsKesehatanNo: e.target.value.replace(/[^0-9]/g, '') })}
                      maxLength={13}
                      placeholder="000xxxxxxxxxx"
                      className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-800/10 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-1 focus:ring-indigo-500 font-mono"
                    />
                  </div>

                  <div className="space-y-1">
                    <label className="text-[10px] font-bold text-slate-400 dark:text-slate-500 block">No. BPJS Ketenagakerjaan (KPJ)</label>
                    <input
                      type="text"
                      value={addForm.bpjsKetenagakerjaanNo}
                      onChange={(e) => setAddForm({ ...addForm, bpjsKetenagakerjaanNo: e.target.value.replace(/[^0-9]/g, '') })}
                      maxLength={11}
                      placeholder="11 digit KPJ"
                      className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-800/10 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-1 focus:ring-indigo-500 font-mono"
                    />
                  </div>
                </div>

                {/* Checkbox Program BPJS */}
                <div className="p-3 bg-slate-50 dark:bg-slate-800/30 border border-slate-200 dark:border-slate-700/60 rounded-xl space-y-2 text-xs">
                  <p className="text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">
                    Program Jaminan Sosial yang Diikuti:
                  </p>
                  <div className="grid grid-cols-2 gap-2 text-[11px]">
                    <label className="flex items-center gap-2 cursor-pointer text-slate-700 dark:text-slate-300">
                      <input
                        type="checkbox"
                        checked={addForm.bpjsKesehatanEnabled}
                        onChange={(e) => setAddForm({ ...addForm, bpjsKesehatanEnabled: e.target.checked })}
                        className="w-3.5 h-3.5 rounded text-indigo-600 focus:ring-indigo-500"
                      />
                      <span>BPJS Kesehatan (4% + 1%)</span>
                    </label>
                    <label className="flex items-center gap-2 cursor-pointer text-slate-700 dark:text-slate-300">
                      <input
                        type="checkbox"
                        checked={addForm.bpjsKetenagakerjaanEnabled}
                        onChange={(e) => setAddForm({ ...addForm, bpjsKetenagakerjaanEnabled: e.target.checked })}
                        className="w-3.5 h-3.5 rounded text-indigo-600 focus:ring-indigo-500"
                      />
                      <span>JKK & JKM (Ditanggung Kantor)</span>
                    </label>
                    <label className="flex items-center gap-2 cursor-pointer text-slate-700 dark:text-slate-300">
                      <input
                        type="checkbox"
                        checked={addForm.hasJht}
                        onChange={(e) => setAddForm({ ...addForm, hasJht: e.target.checked })}
                        disabled={addForm.employmentType === 'Internship'}
                        className="w-3.5 h-3.5 rounded text-indigo-600 focus:ring-indigo-500 disabled:opacity-50"
                      />
                      <span>JHT - Hari Tua (3.7% + 2%)</span>
                    </label>
                    <label className="flex items-center gap-2 cursor-pointer text-slate-700 dark:text-slate-300">
                      <input
                        type="checkbox"
                        checked={addForm.hasJp}
                        onChange={(e) => setAddForm({ ...addForm, hasJp: e.target.checked })}
                        disabled={addForm.employmentType === 'Internship'}
                        className="w-3.5 h-3.5 rounded text-indigo-600 focus:ring-indigo-500 disabled:opacity-50"
                      />
                      <span>JP - Pensiun (2% + 1%)</span>
                    </label>
                  </div>
                </div>

                {/* E. PASSWORD AWAL AKUN */}
                <span className="text-[10px] font-extrabold text-slate-400 dark:text-slate-500 uppercase tracking-widest block pb-1 border-b border-light-divider dark:border-slate-800/80 pt-4 mb-3">
                  Sandi Awal Akun Mobile App
                </span>

                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
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

                {/* Tombol Simpan & Batal */}
                <div className="flex gap-2.5 pt-4 border-t border-slate-100 dark:border-slate-800">
                  <button
                    type="button"
                    onClick={() => setViewMode('list')}
                    className="flex-1 py-3 border border-slate-200 dark:border-slate-800 dark:hover:bg-slate-805 text-slate-600 dark:text-slate-350 rounded-xl text-xs font-semibold transition cursor-pointer"
                  >
                    Batal
                  </button>
                  <button
                    type="submit"
                    disabled={addForm.password !== addForm.confirmPassword || submitting}
                    className="flex-1 py-3 bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs rounded-xl shadow-md transition cursor-pointer disabled:opacity-50 flex items-center justify-center gap-2"
                  >
                    <Check className="w-4 h-4" />
                    <span>{submitting ? 'Menyimpan...' : 'Simpan Karyawan Baru'}</span>
                  </button>
                </div>

              </div>
            </div>
          </div>
        </form>
      ) : (
        /* EDIT KARYAWAN WEB FORM (Full-page View - Sesuai Status Kontrak & Kebutuhan Payroll Roadmap) */
        <form onSubmit={handleSaveEditSubmit} className="space-y-6 leading-relaxed">
          {/* 1. SELEKTOR UTAMA STATUS KONTRAK (Paling Atas) */}
          <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-3xl p-6 shadow-xs space-y-4">
            <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-2 pb-3 border-b border-slate-100 dark:border-slate-800">
              <div>
                <span className="text-xs font-bold text-slate-850 dark:text-slate-100 flex items-center gap-2">
                  <Edit2 className="w-4 h-4 text-indigo-600" />
                  Edit Data Karyawan: <span className="text-indigo-600 dark:text-indigo-400 font-bold">{editEmployee?.nama}</span> ({editEmployee?.id})
                </span>
                <p className="text-[11px] text-slate-400 dark:text-slate-500 mt-0.5">
                  Ubah status kontrak kerja, data penempatan, profil perbankan, atau skema perpajakan & BPJS karyawan.
                </p>
              </div>
              <span className="px-2.5 py-1 text-[10px] font-bold rounded-full bg-amber-50 dark:bg-amber-950/40 text-amber-700 dark:text-amber-400 border border-amber-100 dark:border-amber-800 shrink-0">
                Mode Edit Data
              </span>
            </div>

            {/* 4 Card Pilihan Status Kontrak */}
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
              {/* PKWTT (Tetap) */}
              <button
                type="button"
                onClick={() => setEditForm({
                  ...editForm,
                  employmentType: 'PKWTT',
                  hasJht: true,
                  hasJp: true,
                  salaryType: 'monthly',
                })}
                className={`p-4 rounded-2xl border text-left transition duration-150 cursor-pointer flex flex-col justify-between relative overflow-hidden ${
                  editForm.employmentType === 'PKWTT'
                    ? 'bg-teal-50/60 dark:bg-teal-950/30 border-teal-500 dark:border-teal-500 ring-2 ring-teal-500/20 shadow-sm'
                    : 'bg-white dark:bg-slate-800/40 border-slate-200 dark:border-slate-700 hover:border-teal-300 dark:hover:border-teal-700'
                }`}
              >
                {editForm.employmentType === 'PKWTT' && (
                  <span className="absolute top-2 right-2 w-5 h-5 rounded-full bg-teal-500 text-white flex items-center justify-center">
                    <Check className="w-3 h-3" />
                  </span>
                )}
                <div>
                  <div className="w-8 h-8 rounded-xl bg-teal-100 dark:bg-teal-900/50 text-teal-700 dark:text-teal-400 flex items-center justify-center mb-2.5">
                    <ShieldCheck className="w-4.5 h-4.5" />
                  </div>
                  <h4 className="text-xs font-bold text-slate-800 dark:text-slate-100">PKWTT (Tetap)</h4>
                  <p className="text-[10px] text-slate-500 dark:text-slate-400 mt-1 leading-relaxed">
                    Karyawan tetap tanpa batas kontrak. Fasilitas BPJS lengkap (JHT + JP) & cuti tahunan penuh.
                  </p>
                </div>
                <div className="mt-3 pt-2.5 border-t border-teal-100 dark:border-teal-900/40 text-[9px] font-semibold text-teal-700 dark:text-teal-400">
                  • Gaji Bulanan • BPJS Lengkap
                </div>
              </button>

              {/* PKWT (Kontrak) */}
              <button
                type="button"
                onClick={() => setEditForm({
                  ...editForm,
                  employmentType: 'PKWT',
                  hasJht: true,
                  hasJp: true,
                  salaryType: 'monthly',
                })}
                className={`p-4 rounded-2xl border text-left transition duration-150 cursor-pointer flex flex-col justify-between relative overflow-hidden ${
                  editForm.employmentType === 'PKWT'
                    ? 'bg-blue-50/60 dark:bg-blue-950/30 border-blue-500 dark:border-blue-500 ring-2 ring-blue-500/20 shadow-sm'
                    : 'bg-white dark:bg-slate-800/40 border-slate-200 dark:border-slate-700 hover:border-blue-300 dark:hover:border-blue-700'
                }`}
              >
                {editForm.employmentType === 'PKWT' && (
                  <span className="absolute top-2 right-2 w-5 h-5 rounded-full bg-blue-500 text-white flex items-center justify-center">
                    <Check className="w-3 h-3" />
                  </span>
                )}
                <div>
                  <div className="w-8 h-8 rounded-xl bg-blue-100 dark:bg-blue-900/50 text-blue-700 dark:text-blue-400 flex items-center justify-center mb-2.5">
                    <Clock className="w-4.5 h-4.5" />
                  </div>
                  <h4 className="text-xs font-bold text-slate-800 dark:text-slate-100">PKWT (Kontrak)</h4>
                  <p className="text-[10px] text-slate-500 dark:text-slate-400 mt-1 leading-relaxed">
                    Karyawan kontrak bertempo. Membutuhkan tanggal mulai & selesai kontrak (reminder H-30).
                  </p>
                </div>
                <div className="mt-3 pt-2.5 border-t border-blue-100 dark:border-blue-900/40 text-[9px] font-semibold text-blue-700 dark:text-blue-400">
                  • Wajib Tgl Kontrak • Evaluasi
                </div>
              </button>

              {/* Probation (Masa Percobaan) */}
              <button
                type="button"
                onClick={() => setEditForm({
                  ...editForm,
                  employmentType: 'Probation',
                  hasJht: true,
                  hasJp: false,
                  salaryType: 'monthly',
                })}
                className={`p-4 rounded-2xl border text-left transition duration-150 cursor-pointer flex flex-col justify-between relative overflow-hidden ${
                  editForm.employmentType === 'Probation'
                    ? 'bg-amber-50/60 dark:bg-amber-950/30 border-amber-500 dark:border-amber-500 ring-2 ring-amber-500/20 shadow-sm'
                    : 'bg-white dark:bg-slate-800/40 border-slate-200 dark:border-slate-700 hover:border-amber-300 dark:hover:border-amber-700'
                }`}
              >
                {editForm.employmentType === 'Probation' && (
                  <span className="absolute top-2 right-2 w-5 h-5 rounded-full bg-amber-500 text-white flex items-center justify-center">
                    <Check className="w-3 h-3" />
                  </span>
                )}
                <div>
                  <div className="w-8 h-8 rounded-xl bg-amber-100 dark:bg-amber-900/50 text-amber-700 dark:text-amber-400 flex items-center justify-center mb-2.5">
                    <Sparkles className="w-4.5 h-4.5" />
                  </div>
                  <h4 className="text-xs font-bold text-slate-800 dark:text-slate-100">Probasi (Percobaan)</h4>
                  <p className="text-[10px] text-slate-500 dark:text-slate-400 mt-1 leading-relaxed">
                    Calon karyawan tetap dalam masa evaluasi kinerja (biasanya 3–6 bulan) sebelum diangkat PKWTT.
                  </p>
                </div>
                <div className="mt-3 pt-2.5 border-t border-amber-100 dark:border-amber-900/40 text-[9px] font-semibold text-amber-700 dark:text-amber-400">
                  • Evaluasi 3-6 Bulan • Calon Tetap
                </div>
              </button>

              {/* Internship (Magang / Freelance) */}
              <button
                type="button"
                onClick={() => setEditForm({
                  ...editForm,
                  employmentType: 'Internship',
                  hasJht: false,
                  hasJp: false,
                  salaryType: 'daily',
                  overtimeEligible: false
                })}
                className={`p-4 rounded-2xl border text-left transition duration-150 cursor-pointer flex flex-col justify-between relative overflow-hidden ${
                  editForm.employmentType === 'Internship'
                    ? 'bg-purple-50/60 dark:bg-purple-950/30 border-purple-500 dark:border-purple-500 ring-2 ring-purple-500/20 shadow-sm'
                    : 'bg-white dark:bg-slate-800/40 border-slate-200 dark:border-slate-700 hover:border-purple-300 dark:hover:border-purple-700'
                }`}
              >
                {editForm.employmentType === 'Internship' && (
                  <span className="absolute top-2 right-2 w-5 h-5 rounded-full bg-purple-500 text-white flex items-center justify-center">
                    <Check className="w-3 h-3" />
                  </span>
                )}
                <div>
                  <div className="w-8 h-8 rounded-xl bg-purple-100 dark:bg-purple-900/50 text-purple-700 dark:text-purple-400 flex items-center justify-center mb-2.5">
                    <Users className="w-4.5 h-4.5" />
                  </div>
                  <h4 className="text-xs font-bold text-slate-800 dark:text-slate-100">Magang / Internship</h4>
                  <p className="text-[10px] text-slate-500 dark:text-slate-400 mt-1 leading-relaxed">
                    Siswa/mahasiswa magang atau tenaga proyek khusus. Berbasis uang saku/honorarium.
                  </p>
                </div>
                <div className="mt-3 pt-2.5 border-t border-purple-100 dark:border-purple-900/40 text-[9px] font-semibold text-purple-700 dark:text-purple-400">
                  • Uang Saku / Harian • Tanpa JP/JHT
                </div>
              </button>
            </div>
          </div>

          {/* 2. GRID 2 KOLOM FORM DATA DETAIL (EDIT) */}
          <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
            {/* KOLOM KIRI: DATA PRIBADI & PEKERJAAN */}
            <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-3xl p-6 shadow-xs space-y-4">
              <div className="flex justify-between items-center pb-3 border-b border-slate-100 dark:border-slate-800">
                <span className="text-xs font-bold text-slate-850 dark:text-slate-100 flex items-center gap-2">
                  <UserPlus className="w-4 h-4 text-indigo-600" />
                  1. Data Pribadi & Profil Pekerjaan
                </span>
                <span className="text-[10px] text-slate-400 font-mono">* Wajib diisi</span>
              </div>

              <div className="space-y-3.5">
                <span className="text-[10px] font-extrabold text-slate-400 dark:text-slate-500 uppercase tracking-widest block pb-1 border-b border-light-divider dark:border-slate-800/80 mb-3">
                  Identitas Diri
                </span>

                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                  <div className="space-y-1">
                    <label className="text-[10px] font-bold text-slate-400 dark:text-slate-500 block">Nama Lengkap *</label>
                    <input
                      type="text"
                      value={editForm.nama}
                      onChange={(e) => setEditForm({ ...editForm, nama: e.target.value })}
                      required
                      className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-800/10 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                    />
                  </div>
                  <div className="space-y-1">
                    <label className="text-[10px] font-bold text-slate-400 dark:text-slate-500 block">NIK Karyawan</label>
                    <input
                      type="text"
                      value={editForm.nik}
                      onChange={(e) => setEditForm({ ...editForm, nik: e.target.value })}
                      placeholder="EMP-XXXX"
                      className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-800/10 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-1 focus:ring-indigo-500 font-mono"
                    />
                  </div>
                </div>

                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                  <div className="space-y-1">
                    <label className="text-[10px] font-bold text-slate-400 dark:text-slate-500 block">NIK KTP (16 Digit) *</label>
                    <input
                      type="text"
                      value={editForm.nikKtp}
                      onChange={(e) => setEditForm({ ...editForm, nikKtp: e.target.value.replace(/[^0-9]/g, '') })}
                      required
                      maxLength={16}
                      placeholder="16 digit NIK KTP"
                      className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-800/10 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-1 focus:ring-indigo-500 font-mono"
                    />
                  </div>
                  <div className="space-y-1">
                    <label className="text-[10px] font-bold text-slate-400 dark:text-slate-500 block">Nomor WhatsApp / HP</label>
                    <input
                      type="text"
                      value={editForm.hp}
                      onChange={(e) => setEditForm({ ...editForm, hp: e.target.value.replace(/[^0-9]/g, '') })}
                      maxLength={13}
                      placeholder="08xxxxxxxxxx"
                      className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-801/10 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                    />
                  </div>
                </div>

                <div className="space-y-1">
                  <label className="text-[10px] font-bold text-slate-400 dark:text-slate-500 block">
                    Email Kantor <span className="text-[9px] text-slate-400 dark:text-slate-500 font-normal">(Tidak bisa diubah — ID login mobile app)</span>
                  </label>
                  <input
                    type="email"
                    value={editEmployee?.email || ''}
                    disabled
                    className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-100/70 dark:bg-slate-800/40 text-slate-500 dark:text-slate-400 focus:outline-none cursor-not-allowed"
                  />
                </div>

                <span className="text-[10px] font-extrabold text-slate-400 dark:text-slate-500 uppercase tracking-widest block pb-1 border-b border-light-divider dark:border-slate-800/80 pt-4 mb-3">
                  Ketentuan Tanggal & Kontrak ({editForm.employmentType})
                </span>

                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                  <div className="space-y-1">
                    <label className="text-[10px] font-bold text-slate-400 dark:text-slate-500 block">Tanggal Bergabung (Joined Date)</label>
                    <input
                      type="date"
                      value={editForm.joinedDate}
                      onChange={(e) => setEditForm({ ...editForm, joinedDate: e.target.value })}
                      className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-801/10 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                    />
                  </div>

                  {/* Jika PKWT: Tampilkan input selesai kontrak */}
                  {editForm.employmentType === 'PKWT' && (
                    <div className="space-y-1">
                      <label className="text-[10px] font-bold text-blue-600 dark:text-blue-400 block">Selesai Kontrak (Expired Date) *</label>
                      <input
                        type="date"
                        value={editForm.contractEndDate}
                        onChange={(e) => setEditForm({ ...editForm, contractEndDate: e.target.value })}
                        required={editForm.employmentType === 'PKWT'}
                        className="w-full text-xs p-2.5 border border-blue-300 dark:border-blue-800 rounded-xl bg-blue-50/40 dark:bg-blue-950/20 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-1 focus:ring-blue-500"
                      />
                    </div>
                  )}

                  {/* Jika Probation: Target evaluasi probasi */}
                  {editForm.employmentType === 'Probation' && (
                    <div className="space-y-1">
                      <label className="text-[10px] font-bold text-amber-600 dark:text-amber-400 block">Target Selesai Probasi (3-6 Bln) *</label>
                      <input
                        type="date"
                        value={editForm.contractEndDate}
                        onChange={(e) => setEditForm({ ...editForm, contractEndDate: e.target.value })}
                        required={editForm.employmentType === 'Probation'}
                        className="w-full text-xs p-2.5 border border-amber-300 dark:border-amber-800 rounded-xl bg-amber-50/40 dark:bg-amber-950/20 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-1 focus:ring-amber-500"
                      />
                    </div>
                  )}

                  {/* Jika Internship: Selesai magang */}
                  {editForm.employmentType === 'Internship' && (
                    <div className="space-y-1">
                      <label className="text-[10px] font-bold text-purple-600 dark:text-purple-400 block">Target Selesai Magang</label>
                      <input
                        type="date"
                        value={editForm.contractEndDate}
                        onChange={(e) => setEditForm({ ...editForm, contractEndDate: e.target.value })}
                        className="w-full text-xs p-2.5 border border-purple-300 dark:border-purple-800 rounded-xl bg-purple-50/40 dark:bg-purple-950/20 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-1 focus:ring-purple-500"
                      />
                    </div>
                  )}
                </div>

                {/* Form Khusus Rentang Kontrak PKWT */}
                {editForm.employmentType === 'PKWT' && (
                  <div className="p-3 bg-blue-50/50 dark:bg-blue-950/20 border border-blue-100 dark:border-blue-900/30 rounded-xl space-y-2">
                    <p className="text-[11px] font-bold text-blue-800 dark:text-blue-300 flex items-center gap-1.5">
                      <Clock className="w-3.5 h-3.5" /> Tanggal Mulai Kontrak PKWT
                    </p>
                    <input
                      type="date"
                      value={editForm.contractStartDate || editForm.joinedDate}
                      onChange={(e) => setEditForm({ ...editForm, contractStartDate: e.target.value })}
                      className="w-full text-xs p-2 border border-slate-200 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-1 focus:ring-blue-500"
                    />
                  </div>
                )}

                <span className="text-[10px] font-extrabold text-slate-400 dark:text-slate-500 uppercase tracking-widest block pb-1 border-b border-light-divider dark:border-slate-800/80 pt-4 mb-3">
                  Penempatan & Akses Sistem
                </span>

                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                  <div className="space-y-1">
                    <label className="text-[10px] font-bold text-slate-400 dark:text-slate-500 block">Departemen</label>
                    <select
                      value={editForm.dept}
                      onChange={(e) => setEditForm({ ...editForm, dept: e.target.value })}
                      className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-802/10 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-1 focus:ring-indigo-500 font-semibold"
                    >
                      <option value="">Pilih dept</option>
                      {departments.map(d => (
                        <option key={d} value={d}>{d}</option>
                      ))}
                    </select>
                  </div>
                  <div className="space-y-1">
                    <label className="text-[10px] font-bold text-slate-400 dark:text-slate-500 block">Jabatan / Posisi</label>
                    <input
                      type="text"
                      value={editForm.jabatan}
                      onChange={(e) => setEditForm({ ...editForm, jabatan: e.target.value })}
                      placeholder="Staff / Officer / Manager"
                      className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-803/10 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                    />
                  </div>
                </div>

                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                  <div className="space-y-1">
                    <label className="text-[10px] font-bold text-slate-400 dark:text-slate-500 block">Kantor Penempatan (Cabang)</label>
                    <select
                      value={editForm.officeId}
                      onChange={(e) => setEditForm({ ...editForm, officeId: e.target.value === '' ? '' : Number(e.target.value) })}
                      className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-802/10 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-1 focus:ring-indigo-500 font-semibold"
                    >
                      <option value="">Belum ditentukan (Kantor Pusat)</option>
                      {offices.map(o => (
                        <option key={o.id} value={o.id}>{o.office_name}</option>
                      ))}
                    </select>
                  </div>

                  <div className="space-y-1">
                    <label className="text-[10px] font-bold text-slate-400 dark:text-slate-500 block">Role Akun *</label>
                    <select
                      value={editForm.role}
                      onChange={(e) => setEditForm({ ...editForm, role: e.target.value })}
                      required
                      className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-802/10 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-1 focus:ring-indigo-500 font-semibold"
                    >
                      <option value="employee">Employee (Karyawan - Mobile App)</option>
                      <option value="finance">Finance (Web + Mobile)</option>
                      <option value="hrd">HRD (Web + Mobile)</option>
                      <option value="admin">Admin (Web + Mobile)</option>
                      <option value="super_admin">Super Admin (Full Akses)</option>
                    </select>
                  </div>
                </div>

                <div className="space-y-1">
                  <label className="text-[10px] font-bold text-slate-400 dark:text-slate-500 block">Batas Klaim Struk Bulanan (Reimbursement)</label>
                  <div className="relative">
                    <span className="absolute left-3 top-2.5 text-xs font-bold text-slate-400 font-mono">Rp</span>
                    <input
                      type="number"
                      value={editForm.limit === null ? '' : editForm.limit}
                      onChange={(e) => setEditForm({ ...editForm, limit: e.target.value === '' ? '' : Number(e.target.value) })}
                      placeholder="Kosongkan jika tanpa batas klaim"
                      className="w-full text-xs p-2.5 pl-9 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-800/10 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-1 focus:ring-indigo-500 font-mono"
                    />
                  </div>
                </div>
              </div>
            </div>

            {/* KOLOM KANAN: FINANSIAL PAYROLL, REKENING BANK, PAJAK, BPJS */}
            <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-3xl p-6 shadow-xs space-y-4">
              <div className="flex justify-between items-center pb-3 border-b border-slate-100 dark:border-slate-800">
                <span className="text-xs font-bold text-slate-850 dark:text-slate-100 flex items-center gap-2">
                  <Wallet className="w-4 h-4 text-emerald-600" />
                  2. Finansial Payroll, Pajak & BPJS
                </span>
                <span className="text-[10px] font-semibold text-emerald-600 bg-emerald-50 dark:bg-emerald-950/40 px-2 py-0.5 rounded-md">
                  Roadmap Gaji HRIS
                </span>
              </div>

              <div className="space-y-3.5">
                {/* A. REKENING BANK PAYROLL */}
                <span className="text-[10px] font-extrabold text-slate-400 dark:text-slate-500 uppercase tracking-widest block pb-1 border-b border-light-divider dark:border-slate-800/80 mb-3">
                  Informasi Rekening Payroll
                </span>

                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                  <div className="space-y-1">
                    <label className="text-[10px] font-bold text-slate-400 dark:text-slate-500 block">Bank Transfer</label>
                    <select
                      value={editForm.bankName}
                      onChange={(e) => setEditForm({ ...editForm, bankName: e.target.value })}
                      className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-802/10 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-1 focus:ring-indigo-500 font-semibold"
                    >
                      <option value="BCA">Bank BCA</option>
                      <option value="Mandiri">Bank Mandiri</option>
                      <option value="BRI">Bank BRI</option>
                      <option value="BNI">Bank BNI</option>
                      <option value="CIMB">CIMB Niaga</option>
                      <option value="Permata">Bank Permata</option>
                      <option value="BSI">Bank Syariah Indonesia (BSI)</option>
                      <option value="Danamon">Bank Danamon</option>
                      <option value="Other">Bank Lainnya</option>
                    </select>
                  </div>
                  <div className="space-y-1">
                    <label className="text-[10px] font-bold text-slate-400 dark:text-slate-500 block">Nomor Rekening</label>
                    <input
                      type="text"
                      value={editForm.bankAccountNo}
                      onChange={(e) => setEditForm({ ...editForm, bankAccountNo: e.target.value.replace(/[^0-9]/g, '') })}
                      placeholder="Nomor rekening transfer"
                      className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-800/10 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-1 focus:ring-indigo-500 font-mono"
                    />
                  </div>
                </div>

                <div className="space-y-1">
                  <label className="text-[10px] font-bold text-slate-400 dark:text-slate-500 block">Nama Pemilik Rekening (A.N. Rekening)</label>
                  <input
                    type="text"
                    value={editForm.bankAccountHolder || editForm.nama}
                    onChange={(e) => setEditForm({ ...editForm, bankAccountHolder: e.target.value })}
                    placeholder="Nama sesuai buku tabungan"
                    className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-800/10 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                  />
                </div>

                {/* B. PENGUPAHAN & GAJI DASAR */}
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3.5 pt-2">
                  <div className="space-y-1">
                    <label className="text-[10px] font-bold text-slate-400 dark:text-slate-500 block">Skema Pengupahan</label>
                    <select
                      value={editForm.salaryType}
                      onChange={(e) => setEditForm({ ...editForm, salaryType: e.target.value as any })}
                      className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-802/10 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-1 focus:ring-indigo-500 font-semibold"
                    >
                      <option value="monthly">Gaji Bulanan (Monthly)</option>
                      <option value="daily">Harian / Uang Saku (Daily)</option>
                      <option value="hourly">Per Jam (Hourly)</option>
                    </select>
                  </div>
                  <div className="space-y-1">
                    <label className="text-[10px] font-bold text-slate-400 dark:text-slate-500 block">
                      {editForm.employmentType === 'Internship' ? 'Uang Saku / Honor (IDR)' : 'Gaji Pokok Acuan (IDR)'}
                    </label>
                    <div className="relative">
                      <span className="absolute left-3 top-2.5 text-xs font-bold text-slate-400 font-mono">Rp</span>
                      <input
                        type="number"
                        value={editForm.basicSalary === null ? '' : editForm.basicSalary}
                        onChange={(e) => setEditForm({ ...editForm, basicSalary: e.target.value === '' ? '' : Number(e.target.value) })}
                        placeholder="Contoh: 5000000"
                        className="w-full text-xs p-2.5 pl-9 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-800/10 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-1 focus:ring-indigo-500 font-mono"
                      />
                    </div>
                  </div>
                </div>

                {/* C. DATA PERPAJAKAN PPH 21 TER 2024 */}
                <span className="text-[10px] font-extrabold text-slate-400 dark:text-slate-500 uppercase tracking-widest block pb-1 border-b border-light-divider dark:border-slate-800/80 pt-4 mb-3">
                  Pajak Penghasilan (PPh 21 TER 2024)
                </span>

                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                  <div className="space-y-1">
                    <label className="text-[10px] font-bold text-slate-400 dark:text-slate-500 block">
                      Nomor NPWP <span className="font-normal text-slate-400">(Opsional)</span>
                    </label>
                    <input
                      type="text"
                      value={editForm.npwp}
                      onChange={(e) => setEditForm({ ...editForm, npwp: e.target.value.replace(/[^0-9]/g, '') })}
                      maxLength={16}
                      placeholder="15 atau 16 digit NPWP"
                      className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-800/10 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-1 focus:ring-indigo-500 font-mono"
                    />
                  </div>

                  <div className="space-y-1">
                    <label className="text-[10px] font-bold text-slate-400 dark:text-slate-500 block">Status PTKP (Kategori TER) *</label>
                    <select
                      value={editForm.ptkpStatus}
                      onChange={(e) => setEditForm({ ...editForm, ptkpStatus: e.target.value })}
                      className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-802/10 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-1 focus:ring-indigo-500 font-semibold"
                    >
                      <optgroup label="TER Kategori A (PTKP Rp 54jt - 58.5jt)">
                        <option value="TK/0">TK/0 — Tidak Kawin, 0 Tanggungan (TER A)</option>
                        <option value="TK/1">TK/1 — Tidak Kawin, 1 Tanggungan (TER A)</option>
                        <option value="K/0">K/0 — Kawin, 0 Tanggungan (TER A)</option>
                      </optgroup>
                      <optgroup label="TER Kategori B (PTKP Rp 63jt - 67.5jt)">
                        <option value="TK/2">TK/2 — Tidak Kawin, 2 Tanggungan (TER B)</option>
                        <option value="TK/3">TK/3 — Tidak Kawin, 3 Tanggungan (TER B)</option>
                        <option value="K/1">K/1 — Kawin, 1 Tanggungan (TER B)</option>
                        <option value="K/2">K/2 — Kawin, 2 Tanggungan (TER B)</option>
                      </optgroup>
                      <optgroup label="TER Kategori C (PTKP Rp 72jt ke atas)">
                        <option value="K/3">K/3 — Kawin, 3 Tanggungan (TER C)</option>
                        <option value="K/I/0">K/I/0 — Kawin Istri Gabung, 0 Tanggungan (TER C)</option>
                        <option value="K/I/1">K/I/1 — Kawin Istri Gabung, 1 Tanggungan (TER C)</option>
                        <option value="K/I/2">K/I/2 — Kawin Istri Gabung, 2 Tanggungan (TER C)</option>
                        <option value="K/I/3">K/I/3 — Kawin Istri Gabung, 3 Tanggungan (TER C)</option>
                      </optgroup>
                    </select>
                  </div>
                </div>

                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                  <div className="space-y-1">
                    <label className="text-[10px] font-bold text-slate-400 dark:text-slate-500 block">Metode Perhitungan Pajak</label>
                    <select
                      value={editForm.taxMethod}
                      onChange={(e) => setEditForm({ ...editForm, taxMethod: e.target.value as any })}
                      className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-802/10 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-1 focus:ring-indigo-500 font-semibold"
                    >
                      <option value="gross">Gross (PPh 21 Potong Gaji Karyawan)</option>
                      <option value="gross_up">Gross-Up (Diberi Tunjangan Pajak)</option>
                      <option value="nett">Nett (Pajak Ditanggung Perusahaan)</option>
                    </select>
                  </div>

                  <div className="space-y-1">
                    <label className="text-[10px] font-bold text-slate-400 dark:text-slate-500 block">Hak Upah Lembur (Overtime)</label>
                    <div className="flex items-center gap-2 pt-1.5">
                      <label className="flex items-center gap-2 text-xs font-semibold text-slate-700 dark:text-slate-300 cursor-pointer">
                        <input
                          type="checkbox"
                          checked={editForm.overtimeEligible}
                          onChange={(e) => setEditForm({ ...editForm, overtimeEligible: e.target.checked })}
                          className="w-4 h-4 rounded text-indigo-600 focus:ring-indigo-500"
                        />
                        <span>Berhak Lembur Berbayar (PP 35/2021)</span>
                      </label>
                    </div>
                  </div>
                </div>

                {/* D. KEPESERTAAN BPJS */}
                <span className="text-[10px] font-extrabold text-slate-400 dark:text-slate-500 uppercase tracking-widest block pb-1 border-b border-light-divider dark:border-slate-800/80 pt-4 mb-3">
                  Keikutsertaan BPJS Kesehatan & Ketenagakerjaan
                </span>

                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                  <div className="space-y-1">
                    <label className="text-[10px] font-bold text-slate-400 dark:text-slate-500 block">No. BPJS Kesehatan (13 Digit)</label>
                    <input
                      type="text"
                      value={editForm.bpjsKesehatanNo}
                      onChange={(e) => setEditForm({ ...editForm, bpjsKesehatanNo: e.target.value.replace(/[^0-9]/g, '') })}
                      maxLength={13}
                      placeholder="000xxxxxxxxxx"
                      className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-800/10 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-1 focus:ring-indigo-500 font-mono"
                    />
                  </div>

                  <div className="space-y-1">
                    <label className="text-[10px] font-bold text-slate-400 dark:text-slate-500 block">No. BPJS Ketenagakerjaan (KPJ)</label>
                    <input
                      type="text"
                      value={editForm.bpjsKetenagakerjaanNo}
                      onChange={(e) => setEditForm({ ...editForm, bpjsKetenagakerjaanNo: e.target.value.replace(/[^0-9]/g, '') })}
                      maxLength={11}
                      placeholder="11 digit KPJ"
                      className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-800/10 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-1 focus:ring-indigo-500 font-mono"
                    />
                  </div>
                </div>

                {/* Checkbox Program BPJS */}
                <div className="p-3 bg-slate-50 dark:bg-slate-800/30 border border-slate-200 dark:border-slate-700/60 rounded-xl space-y-2 text-xs">
                  <p className="text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">
                    Program Jaminan Sosial yang Diikuti:
                  </p>
                  <div className="grid grid-cols-2 gap-2 text-[11px]">
                    <label className="flex items-center gap-2 cursor-pointer text-slate-700 dark:text-slate-300">
                      <input
                        type="checkbox"
                        checked={editForm.bpjsKesehatanEnabled}
                        onChange={(e) => setEditForm({ ...editForm, bpjsKesehatanEnabled: e.target.checked })}
                        className="w-3.5 h-3.5 rounded text-indigo-600 focus:ring-indigo-500"
                      />
                      <span>BPJS Kesehatan (4% + 1%)</span>
                    </label>
                    <label className="flex items-center gap-2 cursor-pointer text-slate-700 dark:text-slate-300">
                      <input
                        type="checkbox"
                        checked={editForm.bpjsKetenagakerjaanEnabled}
                        onChange={(e) => setEditForm({ ...editForm, bpjsKetenagakerjaanEnabled: e.target.checked })}
                        className="w-3.5 h-3.5 rounded text-indigo-600 focus:ring-indigo-500"
                      />
                      <span>JKK & JKM (Ditanggung Kantor)</span>
                    </label>
                    <label className="flex items-center gap-2 cursor-pointer text-slate-700 dark:text-slate-300">
                      <input
                        type="checkbox"
                        checked={editForm.hasJht}
                        onChange={(e) => setEditForm({ ...editForm, hasJht: e.target.checked })}
                        disabled={editForm.employmentType === 'Internship'}
                        className="w-3.5 h-3.5 rounded text-indigo-600 focus:ring-indigo-500 disabled:opacity-50"
                      />
                      <span>JHT - Hari Tua (3.7% + 2%)</span>
                    </label>
                    <label className="flex items-center gap-2 cursor-pointer text-slate-700 dark:text-slate-300">
                      <input
                        type="checkbox"
                        checked={editForm.hasJp}
                        onChange={(e) => setEditForm({ ...editForm, hasJp: e.target.checked })}
                        disabled={editForm.employmentType === 'Internship'}
                        className="w-3.5 h-3.5 rounded text-indigo-600 focus:ring-indigo-500 disabled:opacity-50"
                      />
                      <span>JP - Pensiun (2% + 1%)</span>
                    </label>
                  </div>
                </div>

                {/* Tombol Simpan & Batal (Edit) */}
                <div className="flex gap-2.5 pt-4 border-t border-slate-100 dark:border-slate-800">
                  <button
                    type="button"
                    onClick={() => {
                      setViewMode('list');
                      setEditEmployee(null);
                    }}
                    className="flex-1 py-3 border border-slate-200 dark:border-slate-800 dark:hover:bg-slate-805 text-slate-600 dark:text-slate-350 rounded-xl text-xs font-semibold transition cursor-pointer"
                  >
                    Batal
                  </button>
                  <button
                    type="submit"
                    disabled={submitting}
                    className="flex-1 py-3 bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs rounded-xl shadow-md transition cursor-pointer disabled:opacity-50 flex items-center justify-center gap-2"
                  >
                    <Check className="w-4 h-4" />
                    <span>{submitting ? 'Menyimpan...' : 'Simpan Perubahan Data'}</span>
                  </button>
                </div>

              </div>
            </div>
          </div>
        </form>
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
                    onChange={(e) => setResetPwdForm({ ...resetPwdForm, password: e.target.value })}
                    className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-805/10 text-slate-800 dark:text-slate-100 focus:outline-none"
                  />
                </div>

                <div className="space-y-1">
                  <label className="text-[10px] font-bold text-slate-400 dark:text-slate-500 block">Konfirmasi Password Baru *</label>
                  <input
                    type="password"
                    value={resetPwdForm.confirm}
                    onChange={(e) => setResetPwdForm({ ...resetPwdForm, confirm: e.target.value })}
                    className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-805/10 text-slate-800 dark:text-slate-100 focus:outline-none"
                  />
                </div>

                <div className="space-y-1 font-sans">
                  <label className="text-[10px] font-bold text-slate-400 dark:text-slate-500 block">Alasan Reset (untuk Audit Log)</label>
                  <select
                    value={resetPwdForm.alasan}
                    onChange={(e) => setResetPwdForm({ ...resetPwdForm, alasan: e.target.value })}
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
                    onChange={(e) => setNonaktifForm({ ...nonaktifForm, alasan: e.target.value })}
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
                    onChange={(e) => setNonaktifForm({ ...nonaktifForm, catatan: e.target.value })}
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

      {/* ========================================================================= */}
      {/* SLIDE-OVER DRAWER: DETAIL LENGKAP KARYAWAN 360° (PROFIL, PAYROLL, BPJS, AKSES) */}
      {/* ========================================================================= */}
      <AnimatePresence>
        {detailEmployee && (() => {
          const fullData = getEmployeeFullData(detailEmployee);
          const cStat = contractStatus(fullData.contractEndDate);
          const badgeInfo = fullData.employmentType ? EMPLOYMENT_BADGE[fullData.employmentType] : null;

          return (
            <div className="fixed inset-0 z-50 overflow-hidden flex justify-end">
              {/* Backdrop with fade animation */}
              <motion.div
                initial={{ opacity: 0 }}
                animate={{ opacity: 1 }}
                exit={{ opacity: 0 }}
                transition={{ duration: 0.22 }}
                onClick={() => setDetailEmployee(null)}
                className="fixed inset-0 bg-slate-900/60 dark:bg-slate-950/80 backdrop-blur-xs z-50 cursor-pointer"
              />

              {/* Slide-over Panel with spring slide animation */}
              <motion.div
                initial={{ x: '100%', opacity: 0.6 }}
                animate={{ x: 0, opacity: 1 }}
                exit={{ x: '100%', opacity: 0.6 }}
                transition={{ type: 'spring', damping: 28, stiffness: 260 }}
                className="relative z-55 w-screen max-w-2xl bg-white dark:bg-slate-900 shadow-2xl flex flex-col border-l border-slate-100 dark:border-slate-800 h-full overflow-hidden"
              >

                {/* 1. Header Profil Ringkas */}
                <div className="p-6 bg-gradient-to-r from-slate-50 to-indigo-50/40 dark:from-slate-850 dark:to-indigo-950/20 border-b border-slate-100 dark:border-slate-800 shrink-0">
                  <div className="flex items-start justify-between gap-4">
                    <div className="flex items-center gap-3.5 min-w-0">
                      <div className={`w-13 h-13 rounded-2xl flex items-center justify-center font-extrabold text-base select-none shadow-sm ${fullData.avatarBg} ${fullData.avatarColor}`}>
                        {fullData.initials}
                      </div>
                      <div className="min-w-0">
                        <div className="flex flex-wrap items-center gap-2">
                          <h3 className="text-base font-extrabold text-slate-850 dark:text-white truncate">
                            {fullData.nama}
                          </h3>
                          {fullData.status === 'Aktif' ? (
                            <span className="inline-flex items-center gap-1 px-2 py-0.5 text-[10px] font-bold text-emerald-700 dark:text-emerald-400 bg-emerald-100/70 dark:bg-emerald-950/50 rounded-full border border-emerald-200 dark:border-emerald-800/40">
                              <Check className="w-2.5 h-2.5" />
                              Aktif
                            </span>
                          ) : (
                            <span className="inline-flex items-center gap-1 px-2 py-0.5 text-[10px] font-bold text-rose-700 dark:text-rose-400 bg-rose-100/70 dark:bg-rose-950/50 rounded-full border border-rose-200 dark:border-rose-800/40">
                              <Ban className="w-2.5 h-2.5" />
                              Nonaktif
                            </span>
                          )}
                          {badgeInfo && (
                            <span className={`inline-flex items-center px-2 py-0.5 text-[10px] font-bold rounded-full border border-slate-200/60 dark:border-slate-700/60 ${badgeInfo.cls}`}>
                              {badgeInfo.label}
                            </span>
                          )}
                        </div>
                        <p className="text-xs text-slate-500 dark:text-slate-400 mt-1 flex flex-wrap items-center gap-2">
                          <span className="font-mono font-bold text-indigo-600 dark:text-indigo-400 bg-indigo-50 dark:bg-indigo-950/50 px-1.5 py-0.5 rounded border border-indigo-100 dark:border-indigo-900/40 text-[11px]">
                            {fullData.id}
                          </span>
                          <span>•</span>
                          <span className="font-semibold text-slate-700 dark:text-slate-300">{fullData.jabatan}</span>
                          <span>•</span>
                          <span>{fullData.dept}</span>
                          {fullData.officeName && fullData.officeName !== '—' && (
                            <>
                              <span>•</span>
                              <span className="text-indigo-600 dark:text-indigo-400 flex items-center gap-1 font-medium">
                                <Building className="w-3 h-3" />
                                {fullData.officeName}
                              </span>
                            </>
                          )}
                        </p>
                      </div>
                    </div>

                    <div className="flex items-center gap-1.5 shrink-0">
                      <button
                        onClick={() => {
                          const emp = detailEmployee;
                          setDetailEmployee(null);
                          handleOpenEdit(emp);
                        }}
                        className="px-3 py-1.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-bold transition flex items-center gap-1.5 shadow-xs cursor-pointer"
                        title="Buka form edit karyawan"
                      >
                        <Edit2 className="w-3.5 h-3.5" />
                        <span>Edit Data</span>
                      </button>
                      <button
                        onClick={() => setDetailEmployee(null)}
                        className="p-1.5 hover:bg-slate-200/70 dark:hover:bg-slate-800 text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 rounded-xl transition cursor-pointer"
                        title="Tutup detail"
                      >
                        <X className="w-5 h-5" />
                      </button>
                    </div>
                  </div>

                  {/* Quick contract alert if PKWT */}
                  {fullData.employmentType === 'PKWT' && (
                    <div className="mt-3 pt-3 border-t border-slate-200/60 dark:border-slate-800/80 flex items-center justify-between text-xs">
                      <span className="text-slate-500 dark:text-slate-400 text-[11px]">Periode Masa Kontrak:</span>
                      <div className="flex items-center gap-2">
                        <span className="font-medium text-slate-700 dark:text-slate-200 text-[11px]">
                          {formatDateId(fullData.contractStartDate) || '—'} s.d. {formatDateId(fullData.contractEndDate) || '—'}
                        </span>
                        {cStat === 'active' && (
                          <span className="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-400">
                            Aktif
                          </span>
                        )}
                        {cStat === 'near' && (
                          <span className="px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-100 text-amber-800 dark:bg-amber-950/50 dark:text-amber-400 flex items-center gap-1">
                            <AlertTriangle className="w-3 h-3" />
                            Segera Berakhir
                          </span>
                        )}
                        {cStat === 'expired' && (
                          <span className="px-2 py-0.5 rounded-full text-[10px] font-bold bg-rose-100 text-rose-800 dark:bg-rose-950/50 dark:text-rose-400">
                            Expired
                          </span>
                        )}
                      </div>
                    </div>
                  )}
                </div>

                {/* 2. Tabs Bar */}
                <div className="flex items-center px-6 border-b border-slate-100 dark:border-slate-800 bg-white dark:bg-slate-900 shrink-0 overflow-x-auto scrollbar-none">
                  {[
                    { id: 'profile', label: 'Profil & Kerja', icon: Briefcase },
                    { id: 'payroll', label: 'Payroll & Finansial', icon: Wallet },
                    { id: 'bpjs', label: 'BPJS & Jaminan', icon: ShieldCheck },
                    { id: 'access', label: 'Presensi & Akses', icon: Smartphone },
                  ].map((tab) => {
                    const Icon = tab.icon;
                    const isActive = detailTab === tab.id;
                    return (
                      <button
                        key={tab.id}
                        onClick={() => setDetailTab(tab.id as any)}
                        className={`py-3.5 px-4 text-xs font-bold transition-all border-b-2 flex items-center gap-2 shrink-0 cursor-pointer ${
                          isActive
                            ? 'border-indigo-600 text-indigo-600 dark:text-indigo-400 bg-indigo-50/30 dark:bg-indigo-950/20'
                            : 'border-transparent text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200'
                        }`}
                      >
                        <Icon className={`w-3.5 h-3.5 ${isActive ? 'text-indigo-600 dark:text-indigo-400' : 'text-slate-400'}`} />
                        <span>{tab.label}</span>
                      </button>
                    );
                  })}
                </div>

                {/* 3. Tab Body Container */}
                <div className="flex-1 overflow-y-auto p-6 space-y-6 bg-slate-50/50 dark:bg-slate-950/30">
                  <AnimatePresence mode="wait">
                    <motion.div
                      key={detailTab}
                      initial={{ opacity: 0, y: 8 }}
                      animate={{ opacity: 1, y: 0 }}
                      exit={{ opacity: 0, y: -6 }}
                      transition={{ duration: 0.16 }}
                      className="space-y-6"
                    >
                      {/* TAB 1: PROFIL & PEKERJAAN */}
                      {detailTab === 'profile' && (
                        <div className="space-y-4">
                      {/* Identitas Karyawan Card */}
                      <div className="bg-white dark:bg-slate-850 p-5 rounded-2xl border border-slate-100 dark:border-slate-800 shadow-xs space-y-4">
                        <div className="flex items-center justify-between pb-3 border-b border-slate-100 dark:border-slate-800">
                          <h4 className="text-xs font-extrabold text-slate-800 dark:text-slate-100 flex items-center gap-2">
                            <FileText className="w-4 h-4 text-indigo-600 dark:text-indigo-400" />
                            Data Identitas Pribadi
                          </h4>
                          <span className="text-[10px] text-slate-400 font-medium">Informasi KTP & Kontak</span>
                        </div>

                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                          <div className="space-y-1">
                            <span className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">NIK KTP (16 Digit)</span>
                            <div className="flex items-center justify-between bg-slate-50 dark:bg-slate-800/60 px-3 py-2 rounded-xl border border-slate-100 dark:border-slate-700/60">
                              <span className="text-xs font-mono font-bold text-slate-800 dark:text-slate-200">{fullData.nikKtp || '—'}</span>
                              {fullData.nikKtp && (
                                <button
                                  onClick={() => handleCopyText(fullData.nikKtp || '', 'nikKtp')}
                                  className="text-slate-400 hover:text-indigo-600 transition p-1"
                                  title="Salin NIK KTP"
                                >
                                  {copiedField === 'nikKtp' ? <Check className="w-3.5 h-3.5 text-emerald-600" /> : <Copy className="w-3.5 h-3.5" />}
                                </button>
                              )}
                            </div>
                          </div>

                          <div className="space-y-1">
                            <span className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">Nomor HP / WhatsApp</span>
                            <div className="flex items-center justify-between bg-slate-50 dark:bg-slate-800/60 px-3 py-2 rounded-xl border border-slate-100 dark:border-slate-700/60">
                              <span className="text-xs font-medium text-slate-800 dark:text-slate-200 flex items-center gap-1.5">
                                <Phone className="w-3 h-3 text-slate-400" />
                                {fullData.hp || '—'}
                              </span>
                              {fullData.hp && fullData.hp !== '—' && (
                                <button
                                  onClick={() => handleCopyText(fullData.hp, 'hp')}
                                  className="text-slate-400 hover:text-indigo-600 transition p-1"
                                  title="Salin Nomor HP"
                                >
                                  {copiedField === 'hp' ? <Check className="w-3.5 h-3.5 text-emerald-600" /> : <Copy className="w-3.5 h-3.5" />}
                                </button>
                              )}
                            </div>
                          </div>

                          <div className="space-y-1 sm:col-span-2">
                            <span className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">Email Perusahaan / Login</span>
                            <div className="flex items-center justify-between bg-slate-50 dark:bg-slate-800/60 px-3 py-2 rounded-xl border border-slate-100 dark:border-slate-700/60">
                              <span className="text-xs font-medium text-slate-800 dark:text-slate-200 flex items-center gap-1.5">
                                <Mail className="w-3.5 h-3.5 text-slate-400" />
                                {fullData.email}
                              </span>
                              <button
                                onClick={() => handleCopyText(fullData.email, 'email')}
                                className="text-slate-400 hover:text-indigo-600 transition p-1"
                                title="Salin Email"
                              >
                                {copiedField === 'email' ? <Check className="w-3.5 h-3.5 text-emerald-600" /> : <Copy className="w-3.5 h-3.5" />}
                              </button>
                            </div>
                          </div>
                        </div>
                      </div>

                      {/* Hubungan Kerja & Penempatan Card */}
                      <div className="bg-white dark:bg-slate-850 p-5 rounded-2xl border border-slate-100 dark:border-slate-800 shadow-xs space-y-4">
                        <div className="flex items-center justify-between pb-3 border-b border-slate-100 dark:border-slate-800">
                          <h4 className="text-xs font-extrabold text-slate-800 dark:text-slate-100 flex items-center gap-2">
                            <Briefcase className="w-4 h-4 text-indigo-600 dark:text-indigo-400" />
                            Data Hubungan Kerja & Kantor
                          </h4>
                          <span className="text-[10px] text-slate-400 font-medium">Struktur Organisasi</span>
                        </div>

                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                          <div className="space-y-1">
                            <span className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">Status Ketenagakerjaan</span>
                            <div className="bg-slate-50 dark:bg-slate-800/60 px-3 py-2 rounded-xl border border-slate-100 dark:border-slate-700/60">
                              <span className="text-xs font-bold text-slate-800 dark:text-slate-100">
                                {fullData.employmentType ? EMPLOYMENT_BADGE[fullData.employmentType]?.label : 'Belum Diatur'}
                              </span>
                              <p className="text-[10px] text-slate-400 mt-0.5">
                                {fullData.employmentType === 'PKWTT' ? 'Karyawan Tetap (PKWTT)' : fullData.employmentType === 'PKWT' ? 'Karyawan Kontrak (PKWT)' : fullData.employmentType === 'Probation' ? 'Masa Percobaan' : 'Magang / Internship'}
                              </p>
                            </div>
                          </div>

                          <div className="space-y-1">
                            <span className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">Kantor Penempatan</span>
                            <div className="bg-slate-50 dark:bg-slate-800/60 px-3 py-2 rounded-xl border border-slate-100 dark:border-slate-700/60">
                              <span className="text-xs font-bold text-indigo-600 dark:text-indigo-400 flex items-center gap-1">
                                <Building className="w-3.5 h-3.5" />
                                {fullData.officeName || 'Semua Kantor'}
                              </span>
                              <p className="text-[10px] text-slate-400 mt-0.5">Mengikuti aturan cut-off cabang</p>
                            </div>
                          </div>

                          <div className="space-y-1">
                            <span className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">Tanggal Mulai Bergabung</span>
                            <div className="bg-slate-50 dark:bg-slate-800/60 px-3 py-2 rounded-xl border border-slate-100 dark:border-slate-700/60">
                              <span className="text-xs font-medium text-slate-800 dark:text-slate-200 flex items-center gap-1.5">
                                <Calendar className="w-3.5 h-3.5 text-slate-400" />
                                {formatDateId(fullData.joinedDate || fullData.tanggalMasuk || '') || '—'}
                              </span>
                              <p className="text-[10px] text-slate-400 mt-0.5">Dasar hitungan masa kerja & THR</p>
                            </div>
                          </div>

                          <div className="space-y-1">
                            <span className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">Departemen & Role Sistem</span>
                            <div className="bg-slate-50 dark:bg-slate-800/60 px-3 py-2 rounded-xl border border-slate-100 dark:border-slate-700/60">
                              <span className="text-xs font-bold text-slate-800 dark:text-slate-100">{fullData.dept}</span>
                              <p className="text-[10px] text-slate-400 mt-0.5 capitalize">Otoritas role: {fullData.role}</p>
                            </div>
                          </div>
                        </div>
                      </div>
                    </div>
                  )}

                  {/* TAB 2: PAYROLL & FINANSIAL */}
                  {detailTab === 'payroll' && (
                    <div className="space-y-4 animate-in fade-in duration-150">
                      {/* Hero Gaji Pokok Card */}
                      <div className="bg-gradient-to-br from-indigo-600 to-indigo-800 text-white p-6 rounded-3xl shadow-lg relative overflow-hidden">
                        <div className="absolute right-0 top-0 w-48 h-48 bg-white/10 rounded-full blur-2xl -mr-10 -mt-10" />
                        <div className="relative z-10 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                          <div>
                            <span className="text-[11px] font-bold tracking-widest uppercase text-indigo-200 flex items-center gap-1.5">
                              <Wallet className="w-3.5 h-3.5" />
                              Gaji Pokok Karyawan ({fullData.salaryType === 'daily' ? 'Harian' : fullData.salaryType === 'hourly' ? 'Per Jam' : 'Bulanan'})
                            </span>
                            <div className="flex items-center gap-3 mt-1.5">
                              <h2 className="text-2xl sm:text-3xl font-black tracking-tight font-mono">
                                {showSensitiveData
                                  ? formatCurrency(fullData.basicSalary)
                                  : 'Rp •••••••••'}
                              </h2>
                              <button
                                onClick={() => setShowSensitiveData(!showSensitiveData)}
                                className="p-1.5 bg-white/15 hover:bg-white/25 rounded-xl transition cursor-pointer text-indigo-100 hover:text-white"
                                title={showSensitiveData ? 'Sembunyikan nominal' : 'Tampilkan nominal'}
                              >
                                {showSensitiveData ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
                              </button>
                            </div>
                            <p className="text-xs text-indigo-200 mt-1">
                              Dasar perhitungan lembur (1/173), THR, dan iuran BPJS ketenagakerjaan.
                            </p>
                          </div>

                          <div className="flex sm:flex-col items-end gap-2 shrink-0">
                            <span className={`px-3 py-1 rounded-full text-[10px] font-extrabold uppercase tracking-wide border ${
                              fullData.overtimeEligible
                                ? 'bg-emerald-500/20 text-emerald-200 border-emerald-300/30'
                                : 'bg-slate-500/20 text-slate-300 border-slate-400/30'
                            }`}>
                              {fullData.overtimeEligible ? '✓ Hak Lembur Aktif' : 'Non-Eligible Lembur'}
                            </span>
                            <span className="px-2.5 py-0.5 rounded-md text-[10px] font-mono bg-white/15 text-indigo-100">
                              Metode: {fullData.taxMethod.toUpperCase()}
                            </span>
                          </div>
                        </div>
                      </div>

                      {/* Rekening Bank Card */}
                      <div className="bg-white dark:bg-slate-850 p-5 rounded-2xl border border-slate-100 dark:border-slate-800 shadow-xs space-y-4">
                        <div className="flex items-center justify-between pb-3 border-b border-slate-100 dark:border-slate-800">
                          <h4 className="text-xs font-extrabold text-slate-800 dark:text-slate-100 flex items-center gap-2">
                            <Landmark className="w-4 h-4 text-indigo-600 dark:text-indigo-400" />
                            Rekening Bank Payroll
                          </h4>
                          <span className="text-[10px] text-emerald-600 dark:text-emerald-400 font-bold bg-emerald-50 dark:bg-emerald-950/40 px-2 py-0.5 rounded-full border border-emerald-200 dark:border-emerald-800/40">
                            ✓ Terenkripsi AES-256
                          </span>
                        </div>

                        <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                          <div className="space-y-1">
                            <span className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">Bank Tujuan</span>
                            <div className="bg-slate-50 dark:bg-slate-800/60 px-3 py-2 rounded-xl border border-slate-100 dark:border-slate-700/60 font-bold text-xs text-slate-800 dark:text-slate-100">
                              {fullData.bankName}
                            </div>
                          </div>

                          <div className="space-y-1">
                            <span className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">Nomor Rekening</span>
                            <div className="flex items-center justify-between bg-slate-50 dark:bg-slate-800/60 px-3 py-2 rounded-xl border border-slate-100 dark:border-slate-700/60">
                              <span className="text-xs font-mono font-bold text-slate-800 dark:text-slate-100">
                                {showSensitiveData
                                  ? fullData.bankAccountNo
                                  : fullData.bankAccountNo ? `${fullData.bankAccountNo.slice(0, 4)}••••${fullData.bankAccountNo.slice(-3)}` : '—'}
                              </span>
                              <button
                                onClick={() => handleCopyText(fullData.bankAccountNo || '', 'bankAccountNo')}
                                className="text-slate-400 hover:text-indigo-600 transition p-1"
                                title="Salin No Rekening"
                              >
                                {copiedField === 'bankAccountNo' ? <Check className="w-3.5 h-3.5 text-emerald-600" /> : <Copy className="w-3.5 h-3.5" />}
                              </button>
                            </div>
                          </div>

                          <div className="space-y-1">
                            <span className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">Nama Pemilik Rekening</span>
                            <div className="bg-slate-50 dark:bg-slate-800/60 px-3 py-2 rounded-xl border border-slate-100 dark:border-slate-700/60 text-xs font-bold text-slate-800 dark:text-slate-100 truncate">
                              {fullData.bankAccountHolder}
                            </div>
                          </div>
                        </div>
                      </div>

                      {/* Perpajakan PPh 21 TER 2024 Card */}
                      <div className="bg-white dark:bg-slate-850 p-5 rounded-2xl border border-slate-100 dark:border-slate-800 shadow-xs space-y-4">
                        <div className="flex items-center justify-between pb-3 border-b border-slate-100 dark:border-slate-800">
                          <h4 className="text-xs font-extrabold text-slate-800 dark:text-slate-100 flex items-center gap-2">
                            <BadgePercent className="w-4 h-4 text-indigo-600 dark:text-indigo-400" />
                            Konfigurasi Pajak PPh 21 (TER PMK 168/2023)
                          </h4>
                          <span className="text-[10px] text-slate-400 font-medium">Standar Kemenkeu 2024</span>
                        </div>

                        <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                          <div className="space-y-1">
                            <span className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">Nomor NPWP (16 Digit)</span>
                            <div className="flex items-center justify-between bg-slate-50 dark:bg-slate-800/60 px-3 py-2 rounded-xl border border-slate-100 dark:border-slate-700/60">
                              <span className="text-xs font-mono font-bold text-slate-800 dark:text-slate-100">{fullData.npwp || 'Tidak Ada (Tarif +20%)'}</span>
                              {fullData.npwp && (
                                <button
                                  onClick={() => handleCopyText(fullData.npwp || '', 'npwp')}
                                  className="text-slate-400 hover:text-indigo-600 transition p-1"
                                  title="Salin NPWP"
                                >
                                  {copiedField === 'npwp' ? <Check className="w-3.5 h-3.5 text-emerald-600" /> : <Copy className="w-3.5 h-3.5" />}
                                </button>
                              )}
                            </div>
                          </div>

                          <div className="space-y-1">
                            <span className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">Status PTKP & Kategori TER</span>
                            <div className="bg-slate-50 dark:bg-slate-800/60 px-3 py-2 rounded-xl border border-slate-100 dark:border-slate-700/60 flex items-center justify-between">
                              <span className="text-xs font-bold text-slate-800 dark:text-slate-100">{fullData.ptkpStatus}</span>
                              <span className="px-2 py-0.5 rounded text-[10px] font-extrabold bg-indigo-100 dark:bg-indigo-950/50 text-indigo-700 dark:text-indigo-400 border border-indigo-200 dark:border-indigo-800">
                                TER Kategori {fullData.terCategory}
                              </span>
                            </div>
                          </div>

                          <div className="space-y-1">
                            <span className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">Metode Pemotongan</span>
                            <div className="bg-slate-50 dark:bg-slate-800/60 px-3 py-2 rounded-xl border border-slate-100 dark:border-slate-700/60">
                              <span className="text-xs font-bold capitalize text-slate-800 dark:text-slate-100">
                                {fullData.taxMethod === 'gross_up' ? 'Gross-Up (Tunjangan Pajak)' : fullData.taxMethod === 'nett' ? 'Nett (Ditanggung Perusahaan)' : 'Gross (Potong Gaji)'}
                              </span>
                            </div>
                          </div>
                        </div>
                      </div>
                    </div>
                  )}

                  {/* TAB 3: BPJS & JAMINAN */}
                  {detailTab === 'bpjs' && (
                    <div className="space-y-4 animate-in fade-in duration-150">
                      {/* BPJS Kesehatan Card */}
                      <div className="bg-white dark:bg-slate-850 p-5 rounded-2xl border border-slate-100 dark:border-slate-800 shadow-xs space-y-4">
                        <div className="flex items-center justify-between pb-3 border-b border-slate-100 dark:border-slate-800">
                          <div className="flex items-center gap-2">
                            <div className="w-8 h-8 rounded-xl bg-teal-50 dark:bg-teal-950/50 text-teal-600 dark:text-teal-400 flex items-center justify-center font-bold">
                              🏥
                            </div>
                            <div>
                              <h4 className="text-xs font-extrabold text-slate-800 dark:text-slate-100">BPJS Kesehatan</h4>
                              <p className="text-[10px] text-slate-400">Jaminan Pemeliharaan Kesehatan</p>
                            </div>
                          </div>
                          <span className={`px-2.5 py-1 rounded-full text-[10px] font-extrabold border ${
                            fullData.bpjsKesehatanEnabled
                              ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-400 border-emerald-200 dark:border-emerald-800/40'
                              : 'bg-slate-100 text-slate-500 border-slate-200'
                          }`}>
                            {fullData.bpjsKesehatanEnabled ? '✓ Keanggotaan Aktif' : 'Non-Aktif'}
                          </span>
                        </div>

                        <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                          <div className="space-y-1">
                            <span className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">No. Kartu BPJS Kes</span>
                            <div className="flex items-center justify-between bg-slate-50 dark:bg-slate-800/60 px-3 py-2 rounded-xl border border-slate-100 dark:border-slate-700/60">
                              <span className="text-xs font-mono font-bold text-slate-800 dark:text-slate-100">{fullData.bpjsKesehatanNo || '—'}</span>
                              {fullData.bpjsKesehatanNo && (
                                <button
                                  onClick={() => handleCopyText(fullData.bpjsKesehatanNo || '', 'bpjsKes')}
                                  className="text-slate-400 hover:text-indigo-600 transition p-1"
                                  title="Salin No BPJS Kesehatan"
                                >
                                  {copiedField === 'bpjsKes' ? <Check className="w-3.5 h-3.5 text-emerald-600" /> : <Copy className="w-3.5 h-3.5" />}
                                </button>
                              )}
                            </div>
                          </div>

                          <div className="space-y-1">
                            <span className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">Iuran Perusahaan (4%)</span>
                            <div className="bg-slate-50 dark:bg-slate-800/60 px-3 py-2 rounded-xl border border-slate-100 dark:border-slate-700/60 text-xs font-semibold text-slate-700 dark:text-slate-300">
                              Ditanggung Kantor (Maks Cap 12jt)
                            </div>
                          </div>

                          <div className="space-y-1">
                            <span className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">Potongan Karyawan (1%)</span>
                            <div className="bg-slate-50 dark:bg-slate-800/60 px-3 py-2 rounded-xl border border-slate-100 dark:border-slate-700/60 text-xs font-semibold text-slate-700 dark:text-slate-300">
                              Dipotong dari Gaji Bulanan
                            </div>
                          </div>
                        </div>
                      </div>

                      {/* BPJS Ketenagakerjaan Card */}
                      <div className="bg-white dark:bg-slate-850 p-5 rounded-2xl border border-slate-100 dark:border-slate-800 shadow-xs space-y-4">
                        <div className="flex items-center justify-between pb-3 border-b border-slate-100 dark:border-slate-800">
                          <div className="flex items-center gap-2">
                            <div className="w-8 h-8 rounded-xl bg-blue-50 dark:bg-blue-950/50 text-blue-600 dark:text-blue-400 flex items-center justify-center font-bold">
                              🛡️
                            </div>
                            <div>
                              <h4 className="text-xs font-extrabold text-slate-800 dark:text-slate-100">BPJS Ketenagakerjaan</h4>
                              <p className="text-[10px] text-slate-400">JHT, JP, JKK, & JKM</p>
                            </div>
                          </div>
                          <span className={`px-2.5 py-1 rounded-full text-[10px] font-extrabold border ${
                            fullData.bpjsKetenagakerjaanEnabled
                              ? 'bg-blue-50 text-blue-700 dark:bg-blue-950/40 dark:text-blue-400 border-blue-200 dark:border-blue-800/40'
                              : 'bg-slate-100 text-slate-500 border-slate-200'
                          }`}>
                            {fullData.bpjsKetenagakerjaanEnabled ? '✓ Keanggotaan Aktif' : 'Non-Aktif'}
                          </span>
                        </div>

                        <div className="space-y-1">
                          <span className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">Nomor KPJ Ketenagakerjaan (11 Digit)</span>
                          <div className="flex items-center justify-between bg-slate-50 dark:bg-slate-800/60 px-3 py-2 rounded-xl border border-slate-100 dark:border-slate-700/60">
                            <span className="text-xs font-mono font-bold text-slate-800 dark:text-slate-100">{fullData.bpjsKetenagakerjaanNo || '—'}</span>
                            {fullData.bpjsKetenagakerjaanNo && (
                              <button
                                onClick={() => handleCopyText(fullData.bpjsKetenagakerjaanNo || '', 'bpjsTk')}
                                className="text-slate-400 hover:text-indigo-600 transition p-1"
                                title="Salin No KPJ BPJS Ketenagakerjaan"
                              >
                                {copiedField === 'bpjsTk' ? <Check className="w-3.5 h-3.5 text-emerald-600" /> : <Copy className="w-3.5 h-3.5" />}
                              </button>
                            )}
                          </div>
                        </div>

                        {/* 4 Program Badge Matrix */}
                        <div className="grid grid-cols-2 sm:grid-cols-4 gap-2 pt-2">
                          <div className="p-3 bg-slate-50 dark:bg-slate-800/40 rounded-xl border border-slate-100 dark:border-slate-750 text-center">
                            <span className="text-[10px] font-bold text-slate-400 block">JHT (Hari Tua)</span>
                            <span className={`inline-block mt-1 text-[11px] font-extrabold ${fullData.hasJht ? 'text-emerald-600 dark:text-emerald-400' : 'text-slate-400'}`}>
                              {fullData.hasJht ? '✓ 5.7% (3.7% + 2%)' : 'Non-Aktif'}
                            </span>
                          </div>

                          <div className="p-3 bg-slate-50 dark:bg-slate-800/40 rounded-xl border border-slate-100 dark:border-slate-750 text-center">
                            <span className="text-[10px] font-bold text-slate-400 block">JP (Pensiun)</span>
                            <span className={`inline-block mt-1 text-[11px] font-extrabold ${fullData.hasJp ? 'text-emerald-600 dark:text-emerald-400' : 'text-slate-400'}`}>
                              {fullData.hasJp ? '✓ 3.0% (2% + 1%)' : 'Non-Aktif'}
                            </span>
                          </div>

                          <div className="p-3 bg-slate-50 dark:bg-slate-800/40 rounded-xl border border-slate-100 dark:border-slate-750 text-center">
                            <span className="text-[10px] font-bold text-slate-400 block">JKK (Kecelakaan)</span>
                            <span className="inline-block mt-1 text-[11px] font-extrabold text-blue-600 dark:text-blue-400">
                              ✓ 0.54% (Perusahaan)
                            </span>
                          </div>

                          <div className="p-3 bg-slate-50 dark:bg-slate-800/40 rounded-xl border border-slate-100 dark:border-slate-750 text-center">
                            <span className="text-[10px] font-bold text-slate-400 block">JKM (Kematian)</span>
                            <span className="inline-block mt-1 text-[11px] font-extrabold text-blue-600 dark:text-blue-400">
                              ✓ 0.30% (Perusahaan)
                            </span>
                          </div>
                        </div>
                      </div>
                    </div>
                  )}

                  {/* TAB 4: PRESENSI & AKSES */}
                  {detailTab === 'access' && (
                    <div className="space-y-4 animate-in fade-in duration-150">
                      {/* Presensi & Lokasi Card */}
                      <div className="bg-white dark:bg-slate-850 p-5 rounded-2xl border border-slate-100 dark:border-slate-800 shadow-xs space-y-4">
                        <div className="flex items-center justify-between pb-3 border-b border-slate-100 dark:border-slate-800">
                          <h4 className="text-xs font-extrabold text-slate-800 dark:text-slate-100 flex items-center gap-2">
                            <Smartphone className="w-4 h-4 text-indigo-600 dark:text-indigo-400" />
                            Hak Akses Presensi Mobile
                          </h4>
                          <span className="text-[10px] text-slate-400 font-medium">Aplikasi Flutter</span>
                        </div>

                        <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                          <div className="p-3 bg-slate-50 dark:bg-slate-800/40 rounded-xl border border-slate-100 dark:border-slate-750 flex items-center justify-between">
                            <div>
                              <span className="text-xs font-bold text-slate-800 dark:text-slate-100 block">Izin WFH</span>
                              <span className="text-[10px] text-slate-400">Presensi dari rumah</span>
                            </div>
                            <span className={`px-2 py-0.5 text-[10px] font-bold rounded-full ${
                              fullData.wfhEnabled
                                ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-400'
                                : 'bg-slate-100 text-slate-500'
                            }`}>
                              {fullData.wfhEnabled ? 'Diizinkan' : 'Dilarang'}
                            </span>
                          </div>

                          <div className="p-3 bg-slate-50 dark:bg-slate-800/40 rounded-xl border border-slate-100 dark:border-slate-750 flex items-center justify-between">
                            <div>
                              <span className="text-xs font-bold text-slate-800 dark:text-slate-100 block">Kunci Radius GPS</span>
                              <span className="text-[10px] text-slate-400">Wajib di kantor</span>
                            </div>
                            <span className={`px-2 py-0.5 text-[10px] font-bold rounded-full ${
                              fullData.radiusEnabled
                                ? 'bg-indigo-50 text-indigo-700 dark:bg-indigo-950/40 dark:text-indigo-400'
                                : 'bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-400'
                            }`}>
                              {fullData.radiusEnabled ? 'Terkunci' : 'Bebas'}
                            </span>
                          </div>

                          <div className="p-3 bg-slate-50 dark:bg-slate-800/40 rounded-xl border border-slate-100 dark:border-slate-750 flex items-center justify-between">
                            <div>
                              <span className="text-xs font-bold text-slate-800 dark:text-slate-100 block">Akses Presensi</span>
                              <span className="text-[10px] text-slate-400">Modul absensi</span>
                            </div>
                            <span className="px-2 py-0.5 text-[10px] font-bold rounded-full bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-400">
                              Aktif
                            </span>
                          </div>
                        </div>
                      </div>

                      {/* Device Binding & Kuota Card */}
                      <div className="bg-white dark:bg-slate-850 p-5 rounded-2xl border border-slate-100 dark:border-slate-800 shadow-xs space-y-4">
                        <div className="flex items-center justify-between pb-3 border-b border-slate-100 dark:border-slate-800">
                          <h4 className="text-xs font-extrabold text-slate-800 dark:text-slate-100 flex items-center gap-2">
                            <ShieldCheck className="w-4 h-4 text-indigo-600 dark:text-indigo-400" />
                            Device Binding & Batas Finansial
                          </h4>
                          <span className="text-[10px] text-slate-400 font-medium">Keamanan Akun</span>
                        </div>

                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                          <div className="space-y-1">
                            <span className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">Perangkat Terikat (Device Binding)</span>
                            <div className="bg-slate-50 dark:bg-slate-800/60 px-3 py-2.5 rounded-xl border border-slate-100 dark:border-slate-700/60">
                              <div className="flex items-center gap-2">
                                <Smartphone className="w-4 h-4 text-indigo-600 dark:text-indigo-400" />
                                <span className="text-xs font-bold text-slate-800 dark:text-slate-100">{fullData.deviceName}</span>
                              </div>
                              <p className="text-[10px] text-slate-400 font-mono mt-1">ID: {fullData.deviceId}</p>
                            </div>
                          </div>

                          <div className="space-y-1">
                            <span className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">Batas Klaim Struk Bulanan</span>
                            <div className="bg-slate-50 dark:bg-slate-800/60 px-3 py-2.5 rounded-xl border border-slate-100 dark:border-slate-700/60">
                              <span className="text-xs font-bold font-mono text-slate-800 dark:text-slate-100">
                                {formatCurrency(fullData.limit)}
                              </span>
                              <p className="text-[10px] text-slate-400 mt-0.5">Batas maksimal reimbursement bulanan</p>
                            </div>
                          </div>
                        </div>
                      </div>
                    </div>
                  )}
                    </motion.div>
                  </AnimatePresence>
                </div>

              </motion.div>
            </div>
          );
        })()}
      </AnimatePresence>

    </div>
  );
};
