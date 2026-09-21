import React, { useState, useEffect } from 'react';
import {
  X,
  Shield,
  Smartphone,
  Monitor,
  Layers,
  Building2,
  MapPin,
  Check,
  AlertCircle,
  FileText,
  Briefcase,
  Users,
  CalendarCheck,
  CalendarDays,
  Clock,
  RotateCcw,
  ShieldAlert,
  Settings,
  Eye,
  Edit3,
  Ban,
} from 'lucide-react';
import { roleApi, RoleItem, RolePermissionItem } from '../services/endpoints';

interface RoleFormModalProps {
  isOpen: boolean;
  roleToEdit: RoleItem | null;
  offices: { id: number; office_name: string }[];
  onClose: () => void;
  onSaved: (role: RoleItem) => void;
}

const MODULES_CONFIG = [
  { key: 'receipt', name: 'Struk Reimbursement', desc: 'Scan OCR struk fisik, klaim biaya & persetujuan keuangan', icon: FileText },
  { key: 'expense_report', name: 'Laporan Dinas (Bundling)', desc: 'Pengelompokan struk perjalanan dinas & batch approval', icon: Briefcase },
  { key: 'invoice', name: 'Invoice Vendor', desc: 'Pencatatan tagihan invoice vendor & multi-level approval', icon: FileText },
  { key: 'vendor', name: 'Master Vendor', desc: 'Kelola data rekanan vendor, status aktif & riwayat log', icon: Building2 },
  { key: 'user', name: 'Karyawan & Profil', desc: 'Data master pegawai, penempatan kantor & berkas digital', icon: Users },
  { key: 'attendance', name: 'Presensi & Kehadiran', desc: 'Rekap kehadiran harian, GPS geofencing, radius & laporan', icon: CalendarCheck },
  { key: 'leave', name: 'Pengajuan Cuti', desc: 'Permohonan cuti tahunan, sakit, izin & kuota saldo cuti', icon: CalendarDays },
  { key: 'overtime', name: 'Persetujuan Lembur', desc: 'Approval lembur berjenjang (SPV & HRD) & verifikasi payroll', icon: Clock },
  { key: 'shift', name: 'Manajemen Shift & Roster', desc: 'Penjadwalan jadwal kerja bergilir, pola rotasi & assignment', icon: RotateCcw },
  { key: 'audit_log', name: 'Audit Log Sensitif', desc: 'Catatan jejak rekam perubahan data rahasia perusahaan', icon: ShieldAlert },
  { key: 'settings', name: 'Pengaturan Kantor & Finance', desc: 'Konfigurasi kantor cabang, batas toleransi klaim & finance', icon: Settings },
  { key: 'role_management', name: 'Manajemen Role & Hak Akses', desc: 'Pembuatan custom role, matriks izin modul & wewenang akun', icon: Shield },
];

export const RoleFormModal: React.FC<RoleFormModalProps> = ({
  isOpen,
  roleToEdit,
  offices,
  onClose,
  onSaved,
}) => {
  const [activeTab, setActiveTab] = useState<'basic' | 'branch' | 'permissions'>('basic');
  const [name, setName] = useState('');
  const [description, setDescription] = useState('');
  const [platform, setPlatform] = useState<'mobile_only' | 'both'>('both');
  const [branchScope, setBranchScope] = useState<'all' | 'specific' | 'self'>('all');
  const [selectedBranchIds, setSelectedBranchIds] = useState<number[]>([]);
  const [permissions, setPermissions] = useState<Record<string, 'none' | 'read' | 'manage'>>({});
  const [isActive, setIsActive] = useState(true);

  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!isOpen) return;

    setError(null);
    setActiveTab('basic');

    if (roleToEdit) {
      setName(roleToEdit.name || '');
      setDescription(roleToEdit.description || '');
      setPlatform(roleToEdit.platform || 'both');
      setBranchScope(roleToEdit.branch_scope || 'all');
      setIsActive(roleToEdit.is_active !== undefined ? roleToEdit.is_active : true);

      // Populate branches
      const branchIds = (roleToEdit.branches || []).map((b) => b.id);
      setSelectedBranchIds(branchIds);

      // Populate permissions
      const initialPerms: Record<string, 'none' | 'read' | 'manage'> = {};
      MODULES_CONFIG.forEach((m) => {
        initialPerms[m.key] = 'none';
      });

      if (roleToEdit.permissions && Array.isArray(roleToEdit.permissions)) {
        roleToEdit.permissions.forEach((p) => {
          initialPerms[p.module] = p.access_level;
        });
      }

      setPermissions(initialPerms);
    } else {
      // Default new role
      setName('');
      setDescription('');
      setPlatform('both');
      setBranchScope('all');
      setSelectedBranchIds([]);
      setIsActive(true);

      const defaultPerms: Record<string, 'none' | 'read' | 'manage'> = {};
      MODULES_CONFIG.forEach((m) => {
        defaultPerms[m.key] = 'none';
      });
      // Default common permissions
      defaultPerms.receipt = 'read';
      defaultPerms.attendance = 'read';
      setPermissions(defaultPerms);
    }
  }, [isOpen, roleToEdit]);

  if (!isOpen) return null;

  const handleBranchToggle = (branchId: number) => {
    setSelectedBranchIds((prev) =>
      prev.includes(branchId) ? prev.filter((id) => id !== branchId) : [...prev, branchId]
    );
  };

  const handleSelectAllBranches = () => {
    if (selectedBranchIds.length === offices.length) {
      setSelectedBranchIds([]);
    } else {
      setSelectedBranchIds(offices.map((o) => o.id));
    }
  };

  const handlePermissionChange = (moduleKey: string, level: 'none' | 'read' | 'manage') => {
    setPermissions((prev) => ({
      ...prev,
      [moduleKey]: level,
    }));
  };

  const setAllPermissions = (level: 'none' | 'read' | 'manage') => {
    const updated: Record<string, 'none' | 'read' | 'manage'> = {};
    MODULES_CONFIG.forEach((m) => {
      updated[m.key] = level;
    });
    setPermissions(updated);
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError(null);

    if (!name.trim()) {
      setError('Nama role wajib diisi.');
      setActiveTab('basic');
      return;
    }

    if (branchScope === 'specific' && selectedBranchIds.length === 0) {
      setError('Pilih minimal satu kantor cabang jika cakupan cabang diatur ke "Cabang Tertentu".');
      setActiveTab('branch');
      return;
    }

    setSaving(true);

    try {
      const payload = {
        name: name.trim(),
        description: description.trim() || undefined,
        platform,
        branch_scope: branchScope,
        branch_ids: branchScope === 'specific' ? selectedBranchIds : [],
        is_active: isActive,
        permissions,
      };

      let res: any;
      if (roleToEdit) {
        res = await roleApi.update(roleToEdit.id, payload);
      } else {
        res = await roleApi.create(payload);
      }

      if (res?.data) {
        onSaved(res.data);
        onClose();
      } else {
        throw new Error(res?.message || 'Gagal menyimpan role.');
      }
    } catch (err: any) {
      setError(err?.message || 'Terjadi kesalahan saat menyimpan role.');
    } finally {
      setSaving(false);
    }
  };

  const isBuiltIn = Boolean(roleToEdit?.is_builtin);

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6 overflow-y-auto">
      {/* Backdrop */}
      <div
        className="fixed inset-0 bg-slate-900/60 backdrop-blur-sm transition-opacity"
        onClick={onClose}
      />

      {/* Modal Card */}
      <div className="relative w-full max-w-4xl bg-white dark:bg-slate-900 rounded-3xl shadow-2xl border border-slate-100 dark:border-slate-800 overflow-hidden z-10 flex flex-col max-h-[90vh]">
        {/* Header */}
        <div className="px-6 py-5 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between bg-slate-50/50 dark:bg-slate-800/30">
          <div className="flex items-center gap-3">
            <div className="w-10 h-10 rounded-2xl bg-indigo-50 dark:bg-indigo-950/50 flex items-center justify-center border border-indigo-100 dark:border-indigo-900/50 text-indigo-600 dark:text-indigo-400">
              <Shield className="w-5 h-5" />
            </div>
            <div>
              <div className="flex items-center gap-2">
                <h2 className="text-base font-bold text-slate-800 dark:text-slate-100 font-sans">
                  {roleToEdit ? `Edit Role: ${roleToEdit.name}` : 'Buat Custom Role Baru'}
                </h2>
                {isBuiltIn && (
                  <span className="px-2 py-0.5 rounded-full text-[10px] font-bold bg-indigo-50 dark:bg-indigo-950/60 text-indigo-600 dark:text-indigo-400 border border-indigo-200/60 dark:border-indigo-800/60">
                    Sistem (Built-in)
                  </span>
                )}
              </div>
              <p className="text-xs text-slate-400">
                Atur platform akses, pembatasan cabang kantor, dan matriks hak akses modul secara granular.
              </p>
            </div>
          </div>
          <button
            onClick={onClose}
            className="w-8 h-8 rounded-xl flex items-center justify-center text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 transition"
          >
            <X className="w-4 h-4" />
          </button>
        </div>

        {/* Step / Tab Selector */}
        <div className="flex border-b border-slate-100 dark:border-slate-800 px-6 bg-white dark:bg-slate-900 overflow-x-auto">
          <button
            type="button"
            onClick={() => setActiveTab('basic')}
            className={`py-3 px-4 text-xs font-bold border-b-2 whitespace-nowrap transition flex items-center gap-2 ${
              activeTab === 'basic'
                ? 'border-indigo-600 text-indigo-600 dark:text-indigo-400'
                : 'border-transparent text-slate-400 hover:text-slate-600 dark:hover:text-slate-300'
            }`}
          >
            <span className="w-5 h-5 rounded-full bg-slate-100 dark:bg-slate-800 flex items-center justify-center text-[10px]">
              1
            </span>
            Informasi Dasar & Platform
          </button>

          <button
            type="button"
            onClick={() => setActiveTab('branch')}
            className={`py-3 px-4 text-xs font-bold border-b-2 whitespace-nowrap transition flex items-center gap-2 ${
              activeTab === 'branch'
                ? 'border-indigo-600 text-indigo-600 dark:text-indigo-400'
                : 'border-transparent text-slate-400 hover:text-slate-600 dark:hover:text-slate-300'
            }`}
          >
            <span className="w-5 h-5 rounded-full bg-slate-100 dark:bg-slate-800 flex items-center justify-center text-[10px]">
              2
            </span>
            Cakupan Cabang (Branch Scoping)
          </button>

          <button
            type="button"
            onClick={() => setActiveTab('permissions')}
            className={`py-3 px-4 text-xs font-bold border-b-2 whitespace-nowrap transition flex items-center gap-2 ${
              activeTab === 'permissions'
                ? 'border-indigo-600 text-indigo-600 dark:text-indigo-400'
                : 'border-transparent text-slate-400 hover:text-slate-600 dark:hover:text-slate-300'
            }`}
          >
            <span className="w-5 h-5 rounded-full bg-slate-100 dark:bg-slate-800 flex items-center justify-center text-[10px]">
              3
            </span>
            Matriks Hak Akses Modul
          </button>
        </div>

        {/* Error Alert */}
        {error && (
          <div className="mx-6 mt-4 p-3.5 bg-rose-50 dark:bg-rose-950/40 border border-rose-200 dark:border-rose-900 rounded-2xl flex items-center gap-3 text-xs text-rose-700 dark:text-rose-400">
            <AlertCircle className="w-4 h-4 shrink-0" />
            <div className="flex-1">{error}</div>
            <button onClick={() => setError(null)} className="text-rose-500 hover:text-rose-700">
              ✕
            </button>
          </div>
        )}

        {/* Form Body */}
        <form onSubmit={handleSubmit} className="flex-1 overflow-y-auto p-6 space-y-6">
          {/* ─── TAB 1: BASIC & PLATFORM ─── */}
          {activeTab === 'basic' && (
            <div className="space-y-6">
              <div className="grid grid-cols-1 md:grid-cols-2 gap-5">
                <div className="space-y-1.5">
                  <label className="text-xs font-bold text-slate-700 dark:text-slate-300">
                    Nama Role <span className="text-rose-500">*</span>
                  </label>
                  <input
                    type="text"
                    required
                    value={name}
                    onChange={(e) => setName(e.target.value)}
                    placeholder="Contoh: SPV Finance, Auditor Wilayah Timur"
                    className="w-full text-xs px-3.5 py-2.5 bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-xl text-slate-800 dark:text-slate-100 focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-600 transition"
                  />
                  {roleToEdit && (
                    <p className="text-[10px] text-slate-400 font-mono">
                      Slug identifier: <span className="text-indigo-600 dark:text-indigo-400">{roleToEdit.slug}</span>
                    </p>
                  )}
                </div>

                <div className="space-y-1.5">
                  <label className="text-xs font-bold text-slate-700 dark:text-slate-300">
                    Status Role
                  </label>
                  <div className="flex items-center gap-3 pt-1">
                    <label className="relative inline-flex items-center cursor-pointer">
                      <input
                        type="checkbox"
                        checked={isActive}
                        onChange={(e) => setIsActive(e.target.checked)}
                        className="sr-only peer"
                      />
                      <div className="w-11 h-6 bg-slate-200 peer-focus:outline-none rounded-full peer dark:bg-slate-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-slate-600 peer-checked:bg-emerald-600"></div>
                    </label>
                    <span className="text-xs font-semibold text-slate-600 dark:text-slate-300">
                      {isActive ? 'Aktif (Dapat Diberikan ke Karyawan)' : 'Nonaktif (Diarsipkan)'}
                    </span>
                  </div>
                </div>
              </div>

              <div className="space-y-1.5">
                <label className="text-xs font-bold text-slate-700 dark:text-slate-300">
                  Deskripsi / Tugas Utama Role
                </label>
                <textarea
                  rows={2}
                  value={description}
                  onChange={(e) => setDescription(e.target.value)}
                  placeholder="Jelaskan fungsi peran ini di perusahaan (misal: Menyetujui struk tier 2 dan mengawasi jadwal operasional cabang Surabaya)..."
                  className="w-full text-xs px-3.5 py-2.5 bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-xl text-slate-800 dark:text-slate-100 focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-600 transition resize-none"
                />
              </div>

              {/* Platform Selection Cards */}
              <div className="space-y-2">
                <label className="text-xs font-bold text-slate-700 dark:text-slate-300 block">
                  Platform Akses Pengguna <span className="text-rose-500">*</span>
                </label>
                <p className="text-[11px] text-slate-400">
                  Tentukan lingkungan di mana karyawan pemegang role ini diizinkan untuk login dan beraktivitas.
                </p>

                <div className="grid grid-cols-1 md:grid-cols-2 gap-3.5 pt-1">
                  {/* Both */}
                  <div
                    onClick={() => setPlatform('both')}
                    className={`cursor-pointer rounded-2xl p-4 border transition-all flex flex-col justify-between ${
                      platform === 'both'
                        ? 'bg-indigo-50/70 dark:bg-indigo-950/40 border-indigo-500 ring-2 ring-indigo-500/20 shadow-sm'
                        : 'bg-white dark:bg-slate-800/40 border-slate-200 dark:border-slate-700 hover:border-slate-300 dark:hover:border-slate-600'
                    }`}
                  >
                    <div>
                      <div className="flex items-center justify-between mb-2">
                        <div className="w-8 h-8 rounded-xl bg-indigo-100 dark:bg-indigo-900/60 flex items-center justify-center text-indigo-600 dark:text-indigo-400">
                          <Layers className="w-4 h-4" />
                        </div>
                        {platform === 'both' && <Check className="w-4 h-4 text-indigo-600 dark:text-indigo-400" />}
                      </div>
                      <h4 className="text-xs font-bold text-slate-800 dark:text-slate-100">Mobile & Web Dashboard (Dual Akses)</h4>
                      <p className="text-[11px] text-slate-500 dark:text-slate-400 mt-1 leading-relaxed">
                        Karyawan dapat login ke aplikasi mobile untuk presensi online & klaim, serta dapat login ke web dashboard sesuai hak akses modul.
                      </p>
                    </div>
                    <span className="mt-3 text-[10px] font-semibold text-indigo-600 dark:text-indigo-400">
                      Rekomendasi untuk SPV, HRD, Finance, Admin & Pimpinan
                    </span>
                  </div>

                  {/* Mobile Only */}
                  <div
                    onClick={() => setPlatform('mobile_only')}
                    className={`cursor-pointer rounded-2xl p-4 border transition-all flex flex-col justify-between ${
                      platform === 'mobile_only'
                        ? 'bg-sky-50/70 dark:bg-sky-950/40 border-sky-500 ring-2 ring-sky-500/20 shadow-sm'
                        : 'bg-white dark:bg-slate-800/40 border-slate-200 dark:border-slate-700 hover:border-slate-300 dark:hover:border-slate-600'
                    }`}
                  >
                    <div>
                      <div className="flex items-center justify-between mb-2">
                        <div className="w-8 h-8 rounded-xl bg-sky-100 dark:bg-sky-900/60 flex items-center justify-center text-sky-600 dark:text-sky-400">
                          <Smartphone className="w-4 h-4" />
                        </div>
                        {platform === 'mobile_only' && (
                          <Check className="w-4 h-4 text-sky-600 dark:text-sky-400" />
                        )}
                      </div>
                      <h4 className="text-xs font-bold text-slate-800 dark:text-slate-100">📱 Khusus Mobile Saja (Staff Pelaksana)</h4>
                      <p className="text-[11px] text-slate-500 dark:text-slate-400 mt-1 leading-relaxed">
                        Hanya bisa login via aplikasi Flutter HP untuk presensi online, lembur, dan klaim mandiri. Akses dashboard web otomatis ditolak (HTTP 403).
                      </p>
                    </div>
                    <span className="mt-3 text-[10px] font-semibold text-sky-600 dark:text-sky-400">
                      Standar untuk Staff Karyawan & Tenaga Lapangan
                    </span>
                  </div>
                </div>
              </div>
            </div>
          )}

          {/* ─── TAB 2: BRANCH SCOPING ─── */}
          {activeTab === 'branch' && (
            <div className="space-y-6">
              <div>
                <label className="text-xs font-bold text-slate-700 dark:text-slate-300 block mb-1">
                  Cakupan Kantor Cabang (Branch Scoping) <span className="text-rose-500">*</span>
                </label>
                <p className="text-[11px] text-slate-400">
                  Mengontrol batas data struk, karyawan, kehadiran, dan laporan yang dapat dilihat atau diproses oleh role ini.
                </p>
              </div>

              <div className="grid grid-cols-1 md:grid-cols-3 gap-3.5">
                {/* All */}
                <div
                  onClick={() => setBranchScope('all')}
                  className={`cursor-pointer rounded-2xl p-4 border transition-all ${
                    branchScope === 'all'
                      ? 'bg-indigo-50/70 dark:bg-indigo-950/40 border-indigo-500 ring-2 ring-indigo-500/20 shadow-sm'
                      : 'bg-white dark:bg-slate-800/40 border-slate-200 dark:border-slate-700 hover:border-slate-300 dark:hover:border-slate-600'
                  }`}
                >
                  <div className="flex items-center justify-between mb-2">
                    <div className="w-8 h-8 rounded-xl bg-blue-100 dark:bg-blue-900/60 flex items-center justify-center text-blue-600 dark:text-blue-400">
                      <Building2 className="w-4 h-4" />
                    </div>
                    {branchScope === 'all' && <Check className="w-4 h-4 text-indigo-600 dark:text-indigo-400" />}
                  </div>
                  <h4 className="text-xs font-bold text-slate-800 dark:text-slate-100">🏢 Semua Cabang</h4>
                  <p className="text-[11px] text-slate-500 dark:text-slate-400 mt-1">
                    Bebas mengakses data di seluruh kantor cabang perusahaan (Kantor Pusat / Direksi).
                  </p>
                </div>

                {/* Self */}
                <div
                  onClick={() => setBranchScope('self')}
                  className={`cursor-pointer rounded-2xl p-4 border transition-all ${
                    branchScope === 'self'
                      ? 'bg-indigo-50/70 dark:bg-indigo-950/40 border-indigo-500 ring-2 ring-indigo-500/20 shadow-sm'
                      : 'bg-white dark:bg-slate-800/40 border-slate-200 dark:border-slate-700 hover:border-slate-300 dark:hover:border-slate-600'
                  }`}
                >
                  <div className="flex items-center justify-between mb-2">
                    <div className="w-8 h-8 rounded-xl bg-emerald-100 dark:bg-emerald-900/60 flex items-center justify-center text-emerald-600 dark:text-emerald-400">
                      <MapPin className="w-4 h-4" />
                    </div>
                    {branchScope === 'self' && <Check className="w-4 h-4 text-indigo-600 dark:text-indigo-400" />}
                  </div>
                  <h4 className="text-xs font-bold text-slate-800 dark:text-slate-100">📍 Sesuai Kantor Penempatan</h4>
                  <p className="text-[11px] text-slate-500 dark:text-slate-400 mt-1">
                    Otomatis mengikuti kantor cabang tempat user ditempatkan (dinamis per individu karyawan).
                  </p>
                </div>

                {/* Specific */}
                <div
                  onClick={() => setBranchScope('specific')}
                  className={`cursor-pointer rounded-2xl p-4 border transition-all ${
                    branchScope === 'specific'
                      ? 'bg-indigo-50/70 dark:bg-indigo-950/40 border-indigo-500 ring-2 ring-indigo-500/20 shadow-sm'
                      : 'bg-white dark:bg-slate-800/40 border-slate-200 dark:border-slate-700 hover:border-slate-300 dark:hover:border-slate-600'
                  }`}
                >
                  <div className="flex items-center justify-between mb-2">
                    <div className="w-8 h-8 rounded-xl bg-amber-100 dark:bg-amber-900/60 flex items-center justify-center text-amber-600 dark:text-amber-400">
                      <Layers className="w-4 h-4" />
                    </div>
                    {branchScope === 'specific' && <Check className="w-4 h-4 text-indigo-600 dark:text-indigo-400" />}
                  </div>
                  <h4 className="text-xs font-bold text-slate-800 dark:text-slate-100">📌 Cabang Tertentu</h4>
                  <p className="text-[11px] text-slate-500 dark:text-slate-400 mt-1">
                    Pilih secara spesifik daftar kantor cabang yang diizinkan untuk diakses oleh role ini.
                  </p>
                </div>
              </div>

              {/* Specific Branch Selection Checklist */}
              {branchScope === 'specific' && (
                <div className="p-4 bg-slate-50 dark:bg-slate-800/40 border border-slate-200 dark:border-slate-700 rounded-2xl space-y-3 animate-in fade-in duration-200">
                  <div className="flex items-center justify-between pb-2 border-b border-slate-200 dark:border-slate-700">
                    <span className="text-xs font-bold text-slate-700 dark:text-slate-300">
                      Pilih Kantor Cabang yang Diizinkan:
                    </span>
                    <button
                      type="button"
                      onClick={handleSelectAllBranches}
                      className="text-xs font-semibold text-indigo-600 dark:text-indigo-400 hover:underline"
                    >
                      {selectedBranchIds.length === offices.length ? 'Batal Pilih Semua' : 'Pilih Semua'}
                    </button>
                  </div>

                  {offices.length === 0 ? (
                    <p className="text-xs text-slate-400 italic py-2">
                      Belum ada kantor cabang yang terdaftar di sistem.
                    </p>
                  ) : (
                    <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-2.5">
                      {offices.map((office) => {
                        const checked = selectedBranchIds.includes(office.id);
                        return (
                          <label
                            key={office.id}
                            className={`flex items-center gap-2.5 p-2.5 rounded-xl border cursor-pointer transition text-xs ${
                              checked
                                ? 'bg-indigo-50 dark:bg-indigo-950/40 border-indigo-300 dark:border-indigo-800 text-indigo-900 dark:text-indigo-200 font-semibold'
                                : 'bg-white dark:bg-slate-800 border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-750'
                            }`}
                          >
                            <input
                              type="checkbox"
                              checked={checked}
                              onChange={() => handleBranchToggle(office.id)}
                              className="w-4 h-4 rounded text-indigo-600 focus:ring-indigo-500 border-slate-300 dark:border-slate-600"
                            />
                            <span className="truncate">{office.office_name}</span>
                          </label>
                        );
                      })}
                    </div>
                  )}
                </div>
              )}
            </div>
          )}

          {/* ─── TAB 3: PERMISSIONS MATRIX ─── */}
          {activeTab === 'permissions' && (
            <div className="space-y-4">
              <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pb-3 border-b border-slate-100 dark:border-slate-800">
                <div>
                  <h3 className="text-xs font-bold text-slate-800 dark:text-slate-100">
                    Matriks Hak Akses Per Modul
                  </h3>
                  <p className="text-[11px] text-slate-400">
                    Pilih tingkat wewenang untuk setiap modul dalam dashboard ExpenseFlow.
                  </p>
                </div>
                <div className="flex items-center gap-1.5 shrink-0">
                  <button
                    type="button"
                    onClick={() => setAllPermissions('read')}
                    className="px-2.5 py-1.5 rounded-lg text-[11px] font-semibold bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 hover:bg-slate-200 transition"
                  >
                    Semua Read
                  </button>
                  <button
                    type="button"
                    onClick={() => setAllPermissions('manage')}
                    className="px-2.5 py-1.5 rounded-lg text-[11px] font-semibold bg-indigo-50 dark:bg-indigo-950/50 text-indigo-600 dark:text-indigo-400 border border-indigo-200/60 dark:border-indigo-800/60 hover:bg-indigo-100 transition"
                  >
                    Semua Manage
                  </button>
                  <button
                    type="button"
                    onClick={() => setAllPermissions('none')}
                    className="px-2.5 py-1.5 rounded-lg text-[11px] font-semibold text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-950/30 transition"
                  >
                    Reset (None)
                  </button>
                </div>
              </div>

              {/* Module Table / Card List */}
              <div className="space-y-2.5">
                {MODULES_CONFIG.map((mod) => {
                  const currentLevel = permissions[mod.key] || 'none';
                  const Icon = mod.icon;

                  return (
                    <div
                      key={mod.key}
                      className="p-3.5 bg-slate-50/50 dark:bg-slate-800/30 border border-slate-200/70 dark:border-slate-700/60 rounded-2xl flex flex-col sm:flex-row sm:items-center justify-between gap-3 hover:bg-slate-50 dark:hover:bg-slate-800/60 transition"
                    >
                      <div className="flex items-start gap-3">
                        <div className="w-8 h-8 rounded-xl bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 flex items-center justify-center text-indigo-600 dark:text-indigo-400 shrink-0 mt-0.5 sm:mt-0 shadow-xs">
                          <Icon className="w-4 h-4" />
                        </div>
                        <div>
                          <h4 className="text-xs font-bold text-slate-800 dark:text-slate-100">
                            {mod.name}
                          </h4>
                          <p className="text-[11px] text-slate-500 dark:text-slate-400">
                            {mod.desc}
                          </p>
                        </div>
                      </div>

                      {/* Tri-state Radio Segment */}
                      <div className="flex items-center bg-white dark:bg-slate-800 p-1 rounded-xl border border-slate-200 dark:border-slate-700 shrink-0">
                        {/* NONE */}
                        <button
                          type="button"
                          onClick={() => handlePermissionChange(mod.key, 'none')}
                          className={`flex items-center gap-1 px-3 py-1.5 rounded-lg text-xs font-semibold transition ${
                            currentLevel === 'none'
                              ? 'bg-slate-100 dark:bg-slate-700 text-slate-700 dark:text-slate-200 shadow-xs'
                              : 'text-slate-400 hover:text-slate-600 dark:hover:text-slate-300'
                          }`}
                        >
                          <Ban className="w-3 h-3" />
                          <span>None</span>
                        </button>

                        {/* READ */}
                        <button
                          type="button"
                          onClick={() => handlePermissionChange(mod.key, 'read')}
                          className={`flex items-center gap-1 px-3 py-1.5 rounded-lg text-xs font-semibold transition ${
                            currentLevel === 'read'
                              ? 'bg-blue-500 text-white shadow-xs'
                              : 'text-slate-400 hover:text-slate-600 dark:hover:text-slate-300'
                          }`}
                        >
                          <Eye className="w-3 h-3" />
                          <span>Read</span>
                        </button>

                        {/* MANAGE */}
                        <button
                          type="button"
                          onClick={() => handlePermissionChange(mod.key, 'manage')}
                          className={`flex items-center gap-1 px-3 py-1.5 rounded-lg text-xs font-semibold transition ${
                            currentLevel === 'manage'
                              ? 'bg-emerald-600 text-white shadow-xs'
                              : 'text-slate-400 hover:text-slate-600 dark:hover:text-slate-300'
                          }`}
                        >
                          <Edit3 className="w-3 h-3" />
                          <span>Manage</span>
                        </button>
                      </div>
                    </div>
                  );
                })}
              </div>
            </div>
          )}

          {/* Footer Actions */}
          <div className="pt-4 border-t border-slate-100 dark:border-slate-800 flex items-center justify-between">
            <div className="text-[11px] text-slate-400">
              {activeTab === 'basic' && 'Langkah 1 dari 3: Identitas & Platform'}
              {activeTab === 'branch' && 'Langkah 2 dari 3: Batasan Cabang Kantor'}
              {activeTab === 'permissions' && 'Langkah 3 dari 3: Matriks Hak Akses'}
            </div>

            <div className="flex items-center gap-2">
              {activeTab !== 'basic' && (
                <button
                  type="button"
                  onClick={() => {
                    if (activeTab === 'permissions') setActiveTab('branch');
                    else if (activeTab === 'branch') setActiveTab('basic');
                  }}
                  className="px-4 py-2 rounded-xl text-xs font-semibold border border-slate-200 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-800 transition"
                >
                  Kembali
                </button>
              )}

              {activeTab !== 'permissions' ? (
                <button
                  type="button"
                  onClick={() => {
                    if (activeTab === 'basic') setActiveTab('branch');
                    else if (activeTab === 'branch') setActiveTab('permissions');
                  }}
                  className="px-4 py-2 rounded-xl text-xs font-semibold bg-indigo-600 text-white hover:bg-indigo-700 transition"
                >
                  Lanjut ke {activeTab === 'basic' ? 'Cakupan Cabang' : 'Matriks Izin'} →
                </button>
              ) : (
                <button
                  type="submit"
                  disabled={saving}
                  className="px-5 py-2 rounded-xl text-xs font-semibold bg-indigo-600 hover:bg-indigo-700 text-white transition flex items-center gap-1.5 shadow-sm shadow-indigo-500/20 disabled:opacity-50"
                >
                  {saving ? (
                    'Menyimpan...'
                  ) : (
                    <>
                      <Check className="w-4 h-4" />
                      {roleToEdit ? 'Simpan Perubahan Role' : 'Buat Custom Role Sekarang'}
                    </>
                  )}
                </button>
              )}
            </div>
          </div>
        </form>
      </div>
    </div>
  );
};
