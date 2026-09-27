import React, { useState, useEffect, useCallback, useMemo } from 'react';
import {
  Shield,
  Plus,
  Search,
  Building2,
  Smartphone,
  Monitor,
  Layers,
  Users,
  Edit2,
  Trash2,
  CheckCircle2,
  AlertTriangle,
  RefreshCw,
  Sparkles,
  MapPin,
  Lock,
  Eye,
  Edit3,
  Ban,
  Info,
} from 'lucide-react';
import { roleApi, RoleItem } from '../services/endpoints';
import { RoleFormModal } from './RoleFormModal';

interface RoleManagementTabProps {
  offices: { id: number; office_name: string }[];
  onAddAuditLog: (title: string, desc: string, color: string) => void;
  onError: (e: unknown, fallback: string) => void;
}

export const RoleManagementTab: React.FC<RoleManagementTabProps> = ({
  offices,
  onAddAuditLog,
  onError,
}) => {
  const [roles, setRoles] = useState<RoleItem[]>([]);
  const [loading, setLoading] = useState(false);
  const [searchQuery, setSearchQuery] = useState('');
  const [platformFilter, setPlatformFilter] = useState<'all' | 'mobile_only' | 'both'>('all');

  // Modal State
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [roleToEdit, setRoleToEdit] = useState<RoleItem | null>(null);

  // Delete Dialog State
  const [roleToDelete, setRoleToDelete] = useState<RoleItem | null>(null);
  const [deleting, setDeleting] = useState(false);
  const [deleteError, setDeleteError] = useState<string | null>(null);

  // Success Notification Banner
  const [successBanner, setSuccessBanner] = useState<string | null>(null);

  const loadRoles = useCallback(async () => {
    setLoading(true);
    try {
      const res: any = await roleApi.list(undefined, true);
      setRoles(res?.data || []);
    } catch (err) {
      onError(err, 'Gagal memuat data role dan hak akses.');
    } finally {
      setLoading(false);
    }
  }, [onError]);

  useEffect(() => {
    loadRoles();
  }, [loadRoles]);

  const handleOpenCreate = () => {
    setRoleToEdit(null);
    setIsModalOpen(true);
  };

  const handleOpenEdit = (role: RoleItem) => {
    setRoleToEdit(role);
    setIsModalOpen(true);
  };

  const handleRoleSaved = (savedRole: RoleItem) => {
    loadRoles();
    setSuccessBanner(
      roleToEdit
        ? `Role "${savedRole.name}" berhasil diperbarui.`
        : `Custom Role "${savedRole.name}" berhasil dibuat.`
    );
    onAddAuditLog(
      roleToEdit ? 'Role Diperbarui' : 'Custom Role Dibuat',
      `Role "${savedRole.name}" (${savedRole.slug}) dengan platform ${savedRole.platform} & scope ${savedRole.branch_scope}`,
      'indigo'
    );
    setTimeout(() => setSuccessBanner(null), 4000);
  };

  const handleConfirmDelete = async () => {
    if (!roleToDelete) return;
    setDeleting(true);
    setDeleteError(null);

    try {
      await roleApi.destroy(roleToDelete.id);
      setSuccessBanner(`Role "${roleToDelete.name}" berhasil dihapus.`);
      onAddAuditLog(
        'Custom Role Dihapus',
        `Role "${roleToDelete.name}" (${roleToDelete.slug}) telah dihapus dari sistem`,
        'rose'
      );
      setRoleToDelete(null);
      loadRoles();
      setTimeout(() => setSuccessBanner(null), 4000);
    } catch (err: any) {
      setDeleteError(err?.message || 'Gagal menghapus role.');
    } finally {
      setDeleting(false);
    }
  };

  // Filtered Roles
  const filteredRoles = useMemo(() => {
    return roles.filter((role) => {
      const matchSearch =
        role.name.toLowerCase().includes(searchQuery.toLowerCase()) ||
        role.slug.toLowerCase().includes(searchQuery.toLowerCase()) ||
        (role.description && role.description.toLowerCase().includes(searchQuery.toLowerCase()));

      const matchPlatform = platformFilter === 'all' || role.platform === platformFilter;

      return matchSearch && matchPlatform;
    });
  }, [roles, searchQuery, platformFilter]);

  // Statistics
  const stats = useMemo(() => {
    const total = roles.length;
    const builtin = roles.filter((r) => r.is_builtin).length;
    const custom = total - builtin;
    const mobileOnly = roles.filter((r) => r.platform === 'mobile_only').length;
    const both = roles.filter((r) => r.platform === 'both').length;

    return { total, builtin, custom, mobileOnly, both };
  }, [roles]);

  return (
    <div className="space-y-6">
      {/* Banner Success */}
      {successBanner && (
        <div className="bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-200 dark:border-emerald-800 rounded-2xl p-4 flex items-center gap-3 text-xs text-emerald-800 dark:text-emerald-300 shadow-sm animate-in fade-in slide-in-from-top-2">
          <CheckCircle2 className="w-5 h-5 text-emerald-600 dark:text-emerald-400 shrink-0" />
          <span className="font-semibold">{successBanner}</span>
        </div>
      )}

      {/* Architecture Concept Callout: Role vs Jabatan & Divisi */}
      <div className="bg-slate-50 dark:bg-slate-800/40 border border-slate-200 dark:border-slate-800 rounded-2xl p-4 flex items-start gap-3.5">
        <div className="w-8 h-8 rounded-xl bg-indigo-100 dark:bg-indigo-950/80 text-indigo-600 dark:text-indigo-400 flex items-center justify-center shrink-0 mt-0.5">
          <Info className="w-4 h-4" />
        </div>
        <div className="space-y-1 text-xs">
          <div className="font-bold text-slate-800 dark:text-slate-100">
            Prinsip Pemisahan: Role Aplikasi vs Jabatan & Divisi Organisasi
          </div>
          <p className="text-slate-500 dark:text-slate-400 leading-relaxed">
            <span className="font-semibold text-slate-700 dark:text-slate-300">Role Sistem</span> mengatur wewenang akses aplikasi (hak kelola modul, platform dual akses mobile/web, dan pembatasan cabang kantor). Sedangkan <span className="font-semibold text-slate-700 dark:text-slate-300">Struktur Jabatan & Level SPV/Atasan</span> dikelola melalui menu <span className="underline font-medium text-indigo-600 dark:text-indigo-400">Master Divisi & Jabatan</span> (dengan flag Supervisor) serta relasi Atasan Langsung pada data karyawan. Perusahaan tidak perlu membuat custom role baru hanya untuk membedakan Staf vs Supervisor jika hak modulnya sama.
          </p>
        </div>
      </div>

      {/* Top Banner / Summary Cards */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        {/* Total Roles */}
        <div className="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl p-4 shadow-xs flex items-center justify-between">
          <div>
            <span className="text-[11px] font-bold text-slate-400 uppercase tracking-wider block">
              Total Role
            </span>
            <div className="flex items-baseline gap-2 mt-1">
              <span className="text-2xl font-black text-slate-800 dark:text-slate-100 font-mono">
                {stats.total}
              </span>
              <span className="text-xs text-slate-500 dark:text-slate-400">
                ({stats.builtin} sistem, {stats.custom} kustom)
              </span>
            </div>
          </div>
          <div className="w-10 h-10 rounded-xl bg-indigo-50 dark:bg-indigo-950/50 flex items-center justify-center text-indigo-600 dark:text-indigo-400 border border-indigo-100 dark:border-indigo-900/50">
            <Shield className="w-5 h-5" />
          </div>
        </div>

        {/* Both Platforms */}
        <div className="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl p-4 shadow-xs flex items-center justify-between">
          <div>
            <span className="text-[11px] font-bold text-slate-400 uppercase tracking-wider block">
              Mobile & Web
            </span>
            <div className="flex items-baseline gap-2 mt-1">
              <span className="text-2xl font-black text-slate-800 dark:text-slate-100 font-mono">
                {stats.both}
              </span>
              <span className="text-xs text-indigo-600 dark:text-indigo-400 font-medium">
                Dual Akses
              </span>
            </div>
          </div>
          <div className="w-10 h-10 rounded-xl bg-indigo-50 dark:bg-indigo-950/50 flex items-center justify-center text-indigo-600 dark:text-indigo-400 border border-indigo-100 dark:border-indigo-900/50">
            <Layers className="w-5 h-5" />
          </div>
        </div>

        {/* Mobile Only */}
        <div className="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl p-4 shadow-xs flex items-center justify-between">
          <div>
            <span className="text-[11px] font-bold text-slate-400 uppercase tracking-wider block">
              Mobile Saja
            </span>
            <div className="flex items-baseline gap-2 mt-1">
              <span className="text-2xl font-black text-slate-800 dark:text-slate-100 font-mono">
                {stats.mobileOnly}
              </span>
              <span className="text-xs text-sky-600 dark:text-sky-400 font-medium">
                Presensi & Scan
              </span>
            </div>
          </div>
          <div className="w-10 h-10 rounded-xl bg-sky-50 dark:bg-sky-950/50 flex items-center justify-center text-sky-600 dark:text-sky-400 border border-sky-100 dark:border-sky-900/50">
            <Smartphone className="w-5 h-5" />
          </div>
        </div>

        {/* Custom Roles Active */}
        <div className="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl p-4 shadow-xs flex items-center justify-between">
          <div>
            <span className="text-[11px] font-bold text-slate-400 uppercase tracking-wider block">
              Role Kustom
            </span>
            <div className="flex items-baseline gap-2 mt-1">
              <span className="text-2xl font-black text-slate-800 dark:text-slate-100 font-mono">
                {stats.custom}
              </span>
              <span className="text-xs text-emerald-600 dark:text-emerald-400 font-medium">
                Buatan Perusahaan
              </span>
            </div>
          </div>
          <div className="w-10 h-10 rounded-xl bg-emerald-50 dark:bg-emerald-950/50 flex items-center justify-center text-emerald-600 dark:text-emerald-400 border border-emerald-100 dark:border-emerald-900/50">
            <CheckCircle2 className="w-5 h-5" />
          </div>
        </div>
      </div>

      {/* Control Bar: Title, Search, Platform Tabs & Add Button */}
      <div className="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-3xl p-5 shadow-xs space-y-4">
        <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
          <div>
            <h3 className="text-sm font-bold text-slate-800 dark:text-slate-100 flex items-center gap-2">
              <span>Daftar Role & Hak Akses</span>
              <span className="text-xs py-0.5 px-2.5 rounded-full bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400 font-semibold font-mono">
                {filteredRoles.length}
              </span>
            </h3>
            <p className="text-xs text-slate-400 mt-0.5">
              Kelola peran pengguna perusahaan. Buat custom role untuk membatasi akses kantor cabang dan hak akses modul secara spesifik.
            </p>
          </div>

          <div className="flex items-center gap-2.5 shrink-0">
            <button
              onClick={loadRoles}
              disabled={loading}
              title="Segarkan Data"
              className="p-2.5 rounded-xl border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800 transition disabled:opacity-50"
            >
              <RefreshCw className={`w-4 h-4 ${loading ? 'animate-spin' : ''}`} />
            </button>

            <button
              onClick={handleOpenCreate}
              className="flex items-center gap-2 px-4 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold shadow-sm shadow-indigo-500/20 transition transform active:scale-95"
            >
              <Plus className="w-4 h-4" />
              <span>Buat Custom Role Baru</span>
            </button>
          </div>
        </div>

        {/* Filter & Search Bar */}
        <div className="flex flex-col sm:flex-row items-center gap-3 pt-2 border-t border-slate-100 dark:border-slate-800">
          <div className="relative flex-1 w-full">
            <Search className="w-4 h-4 absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400" />
            <input
              type="text"
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              placeholder="Cari role berdasarkan nama atau deskripsi..."
              className="w-full text-xs pl-9.5 pr-4 py-2 bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-xl text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-600 transition"
            />
          </div>

          {/* Platform Segment Tabs */}
          <div className="flex items-center bg-slate-100 dark:bg-slate-800 p-1 rounded-xl w-full sm:w-auto overflow-x-auto">
            <button
              onClick={() => setPlatformFilter('all')}
              className={`px-3 py-1.5 rounded-lg text-xs font-semibold whitespace-nowrap transition ${
                platformFilter === 'all'
                  ? 'bg-white dark:bg-slate-700 text-indigo-600 dark:text-indigo-300 shadow-xs'
                  : 'text-slate-500 hover:text-slate-700 dark:text-slate-400'
              }`}
            >
              Semua
            </button>
            <button
              onClick={() => setPlatformFilter('both')}
              className={`px-3 py-1.5 rounded-lg text-xs font-semibold whitespace-nowrap transition ${
                platformFilter === 'both'
                  ? 'bg-white dark:bg-slate-700 text-indigo-600 dark:text-indigo-300 shadow-xs'
                  : 'text-slate-500 hover:text-slate-700 dark:text-slate-400'
              }`}
            >
              Mobile & Web
            </button>
            <button
              onClick={() => setPlatformFilter('mobile_only')}
              className={`px-3 py-1.5 rounded-lg text-xs font-semibold whitespace-nowrap transition ${
                platformFilter === 'mobile_only'
                  ? 'bg-white dark:bg-slate-700 text-indigo-600 dark:text-indigo-300 shadow-xs'
                  : 'text-slate-500 hover:text-slate-700 dark:text-slate-400'
              }`}
            >
              📱 Khusus Mobile
            </button>
          </div>
        </div>
      </div>

      {/* Role Cards Grid */}
      {loading && roles.length === 0 ? (
        <div className="p-12 text-center bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-3xl">
          <RefreshCw className="w-8 h-8 mx-auto text-indigo-500 animate-spin mb-3" />
          <p className="text-xs text-slate-500 font-semibold">Memuat daftar role & matriks izin...</p>
        </div>
      ) : filteredRoles.length === 0 ? (
        <div className="p-12 text-center bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-3xl space-y-3">
          <div className="w-12 h-12 rounded-2xl bg-slate-100 dark:bg-slate-800 flex items-center justify-center mx-auto text-slate-400">
            <Shield className="w-6 h-6" />
          </div>
          <div>
            <h4 className="text-sm font-bold text-slate-700 dark:text-slate-200">Tidak ada role yang sesuai</h4>
            <p className="text-xs text-slate-400 mt-1">Coba sesuaikan kata kunci pencarian atau filter platform Anda.</p>
          </div>
        </div>
      ) : (
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4.5">
          {filteredRoles.map((role) => {
            const isBuiltIn = role.is_builtin;
            const userCount = role.users_count || 0;

            // Compute permissions summary
            const perms = role.permissions || [];
            const manageCount = perms.filter((p) => p.access_level === 'manage').length;
            const readCount = perms.filter((p) => p.access_level === 'read').length;
            const noneCount = perms.filter((p) => p.access_level === 'none' || p.access_level === 'spv' || p.access_level === 'hrd').length - perms.filter((p) => p.access_level === 'spv' || p.access_level === 'hrd').length;
            const overtimePerm = perms.find((p) => p.module === 'overtime')?.access_level ?? 'none';
            const receiptPerm = perms.find((p) => p.module === 'receipt')?.access_level ?? 'none';

            return (
              <div
                key={role.id}
                className={`bg-white dark:bg-slate-900 border rounded-3xl p-5 shadow-xs transition-all hover:shadow-md flex flex-col justify-between ${
                  isBuiltIn
                    ? 'border-indigo-100 dark:border-indigo-950/60 bg-gradient-to-b from-indigo-50/20 to-transparent'
                    : 'border-slate-200 dark:border-slate-800'
                }`}
              >
                <div>
                  {/* Header: Title & Badges */}
                  <div className="flex items-start justify-between gap-2 mb-2.5">
                    <div>
                      <div className="flex items-center gap-2">
                        <h4 className="text-sm font-bold text-slate-800 dark:text-slate-100">
                          {role.name}
                        </h4>
                        {isBuiltIn ? (
                          <span className="px-2 py-0.5 rounded-full text-[9px] font-bold bg-indigo-50 dark:bg-indigo-950/60 text-indigo-600 dark:text-indigo-400 border border-indigo-200/60 dark:border-indigo-800/60">
                            Sistem
                          </span>
                        ) : (
                          <span className="px-2 py-0.5 rounded-full text-[9px] font-bold bg-emerald-50 dark:bg-emerald-950/60 text-emerald-600 dark:text-emerald-400 border border-emerald-200/60 dark:border-emerald-800/60">
                            Kustom
                          </span>
                        )}
                      </div>
                      <span className="text-[10px] text-slate-400 font-mono mt-0.5 block">
                        {role.slug}
                      </span>
                    </div>

                    {/* Active / Inactive Pill */}
                    <span
                      className={`w-2 h-2 rounded-full mt-1.5 ${
                        role.is_active ? 'bg-emerald-500 ring-4 ring-emerald-500/20' : 'bg-slate-300 dark:bg-slate-700'
                      }`}
                      title={role.is_active ? 'Role Aktif' : 'Role Nonaktif'}
                    />
                  </div>

                  {/* Description */}
                  <p className="text-xs text-slate-500 dark:text-slate-400 line-clamp-2 min-h-[32px] mb-4">
                    {role.description || 'Tidak ada deskripsi peran.'}
                  </p>

                  {/* Badges: Platform & Branch Scope */}
                  <div className="flex flex-wrap gap-1.5 mb-4">
                    {/* Platform Badge */}
                    <span className="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg text-[10px] font-bold bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300">
                      {role.platform === 'both' && (
                        <>
                          <Layers className="w-3 h-3 text-indigo-500" />
                          <span>Mobile & Web</span>
                        </>
                      )}
                      {role.platform === 'mobile_only' && (
                        <>
                          <Smartphone className="w-3 h-3 text-sky-500" />
                          <span>Mobile Saja</span>
                        </>
                      )}
                    </span>

                    {/* Branch Scope Badge */}
                    <span className="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg text-[10px] font-bold bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300">
                      {role.branch_scope === 'all' && (
                        <>
                          <Building2 className="w-3 h-3 text-blue-500" />
                          <span>Semua Cabang</span>
                        </>
                      )}
                      {role.branch_scope === 'self' && (
                        <>
                          <MapPin className="w-3 h-3 text-emerald-500" />
                          <span>Sesuai Penempatan</span>
                        </>
                      )}
                      {role.branch_scope === 'specific' && (
                        <>
                          <Layers className="w-3 h-3 text-amber-500" />
                          <span>
                            {role.branches && role.branches.length > 0
                              ? `${role.branches.length} Cabang Khusus`
                              : 'Cabang Tertentu'}
                          </span>
                        </>
                      )}
                    </span>
                  </div>

                  {/* Permissions Summary Pills */}
                  <div className="p-3 bg-slate-50/70 dark:bg-slate-800/40 rounded-2xl border border-slate-100 dark:border-slate-800/80 mb-4 space-y-1.5">
                    <div className="flex items-center justify-between">
                      <span className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">
                        Wewenang Modul
                      </span>
                      {overtimePerm === 'spv' && (
                        <span className="inline-flex items-center gap-1 text-[9px] font-bold px-2 py-0.5 rounded-full bg-amber-50 dark:bg-amber-950/60 text-amber-700 dark:text-amber-300 border border-amber-200/60 dark:border-amber-900/60">
                          ⚡ Lembur: Lv 1 (SPV)
                        </span>
                      )}
                      {overtimePerm === 'hrd' && (
                        <span className="inline-flex items-center gap-1 text-[9px] font-bold px-2 py-0.5 rounded-full bg-sky-50 dark:bg-sky-950/60 text-sky-700 dark:text-sky-300 border border-sky-200/60 dark:border-sky-900/60">
                          ⭐ Lembur: Lv 2 (HRD)
                        </span>
                      )}
                      {overtimePerm === 'manage' && (
                        <span className="inline-flex items-center gap-1 text-[9px] font-bold px-2 py-0.5 rounded-full bg-emerald-50 dark:bg-emerald-950/60 text-emerald-700 dark:text-emerald-300 border border-emerald-200/60 dark:border-emerald-900/60">
                          🛡️ Lembur: Penuh
                        </span>
                      )}
                      {receiptPerm === 'finance' && (
                        <span className="inline-flex items-center gap-1 text-[9px] font-bold px-2 py-0.5 rounded-full bg-emerald-50 dark:bg-emerald-950/60 text-emerald-700 dark:text-emerald-300 border border-emerald-200/60 dark:border-emerald-900/60">
                          💰 Struk: Lv 1 (Finance)
                        </span>
                      )}
                      {receiptPerm === 'spv' && (
                        <span className="inline-flex items-center gap-1 text-[9px] font-bold px-2 py-0.5 rounded-full bg-violet-50 dark:bg-violet-950/60 text-violet-700 dark:text-violet-300 border border-violet-200/60 dark:border-violet-900/60">
                          🏷️ Struk: Lv 2 (SPV Finance)
                        </span>
                      )}
                      {receiptPerm === 'manage' && (
                        <span className="inline-flex items-center gap-1 text-[9px] font-bold px-2 py-0.5 rounded-full bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 border border-slate-200/60 dark:border-slate-700/60">
                          🛡️ Struk: Penuh
                        </span>
                      )}
                    </div>
                    <div className="flex items-center gap-2">
                      <span className="inline-flex items-center gap-1 text-[11px] font-bold text-emerald-600 dark:text-emerald-400">
                        <Edit3 className="w-3 h-3" />
                        <span>{manageCount} Manage</span>
                      </span>
                      <span className="text-slate-300 dark:text-slate-700">•</span>
                      <span className="inline-flex items-center gap-1 text-[11px] font-bold text-blue-600 dark:text-blue-400">
                        <Eye className="w-3 h-3" />
                        <span>{readCount} Read</span>
                      </span>
                      <span className="text-slate-300 dark:text-slate-700">•</span>
                      <span className="inline-flex items-center gap-1 text-[11px] font-bold text-slate-400">
                        <Ban className="w-3 h-3" />
                        <span>{noneCount} None</span>
                      </span>
                    </div>
                  </div>
                </div>

                {/* Footer: User count & Action Buttons */}
                <div className="pt-3 border-t border-slate-100 dark:border-slate-800 flex items-center justify-between">
                  <div className="flex items-center gap-1.5 text-xs text-slate-500 dark:text-slate-400 font-medium">
                    <Users className="w-3.5 h-3.5 text-slate-400" />
                    <span>{userCount} Karyawan</span>
                  </div>

                  <div className="flex items-center gap-1">
                    <button
                      onClick={() => handleOpenEdit(role)}
                      className="p-2 rounded-xl text-slate-500 hover:text-indigo-600 hover:bg-indigo-50 dark:hover:bg-indigo-950/50 transition"
                      title={isBuiltIn ? 'Lihat / Sesuaikan Izin Modul' : 'Edit Role'}
                    >
                      <Edit2 className="w-3.5 h-3.5" />
                    </button>

                    {!isBuiltIn && (
                      <button
                        onClick={() => {
                          setDeleteError(null);
                          setRoleToDelete(role);
                        }}
                        className="p-2 rounded-xl text-slate-400 hover:text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-950/50 transition"
                        title="Hapus Custom Role"
                      >
                        <Trash2 className="w-3.5 h-3.5" />
                      </button>
                    )}
                  </div>
                </div>
              </div>
            );
          })}
        </div>
      )}

      {/* Role Create / Edit Modal */}
      <RoleFormModal
        isOpen={isModalOpen}
        roleToEdit={roleToEdit}
        offices={offices}
        onClose={() => setIsModalOpen(false)}
        onSaved={handleRoleSaved}
      />

      {/* Delete Confirmation Modal */}
      {roleToDelete && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
          <div
            className="fixed inset-0 bg-slate-900/60 backdrop-blur-sm transition-opacity"
            onClick={() => !deleting && setRoleToDelete(null)}
          />
          <div className="relative w-full max-w-md bg-white dark:bg-slate-900 rounded-3xl shadow-2xl border border-slate-100 dark:border-slate-800 p-6 z-10 space-y-4">
            <div className="w-12 h-12 rounded-2xl bg-rose-50 dark:bg-rose-950/60 border border-rose-100 dark:border-rose-900/60 flex items-center justify-center text-rose-600 mx-auto">
              <AlertTriangle className="w-6 h-6" />
            </div>

            <div className="text-center">
              <h3 className="text-base font-bold text-slate-800 dark:text-slate-100">
                Hapus Custom Role?
              </h3>
              <p className="text-xs text-slate-500 dark:text-slate-400 mt-1">
                Apakah Anda yakin ingin menghapus role <strong className="text-slate-700 dark:text-slate-200 font-bold">"{roleToDelete.name}"</strong>?
              </p>
            </div>

            {roleToDelete.users_count && roleToDelete.users_count > 0 ? (
              <div className="p-3 bg-amber-50 dark:bg-amber-950/40 border border-amber-200 dark:border-amber-800 rounded-2xl text-xs text-amber-800 dark:text-amber-300">
                <strong>Perhatian:</strong> Masih terdapat <strong>{roleToDelete.users_count} karyawan</strong> yang menggunakan role ini. Pindahkan role karyawan ke peran lain terlebih dahulu sebelum menghapus role ini.
              </div>
            ) : null}

            {deleteError && (
              <div className="p-3 bg-rose-50 dark:bg-rose-950/40 border border-rose-200 dark:border-rose-900 rounded-2xl text-xs text-rose-700 dark:text-rose-400">
                {deleteError}
              </div>
            )}

            <div className="flex items-center gap-2 pt-2">
              <button
                type="button"
                disabled={deleting}
                onClick={() => setRoleToDelete(null)}
                className="flex-1 px-4 py-2.5 rounded-xl text-xs font-semibold border border-slate-200 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-800 transition"
              >
                Batal
              </button>
              <button
                type="button"
                disabled={deleting || Boolean(roleToDelete.users_count && roleToDelete.users_count > 0)}
                onClick={handleConfirmDelete}
                className="flex-1 px-4 py-2.5 rounded-xl text-xs font-semibold bg-rose-600 hover:bg-rose-700 text-white transition disabled:opacity-50"
              >
                {deleting ? 'Menghapus...' : 'Ya, Hapus Role'}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};
