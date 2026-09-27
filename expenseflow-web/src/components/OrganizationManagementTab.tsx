import React, { useState, useEffect, useCallback, useMemo } from 'react';
import {
  Briefcase,
  Layers,
  Plus,
  Search,
  Building,
  Edit2,
  Trash2,
  CheckCircle2,
  AlertTriangle,
  RefreshCw,
  Sparkles,
  ShieldCheck,
  UserCheck,
  Users,
  Info,
  X,
  Check,
  Tag,
} from 'lucide-react';
import {
  divisionApi,
  positionApi,
  DivisionItem,
  PositionItem,
} from '../services/endpoints';
import { ConfirmationDialog } from './ConfirmationDialog';

interface OrganizationManagementTabProps {
  onAddAuditLog: (title: string, desc: string, color: string) => void;
  onError: (e: unknown, fallback: string) => void;
}

export const OrganizationManagementTab: React.FC<OrganizationManagementTabProps> = ({
  onAddAuditLog,
  onError,
}) => {
  const [subTab, setSubTab] = useState<'divisions' | 'positions'>('divisions');
  const [divisions, setDivisions] = useState<DivisionItem[]>([]);
  const [positions, setPositions] = useState<PositionItem[]>([]);
  const [loading, setLoading] = useState(false);

  // Search & Filter
  const [searchQuery, setSearchQuery] = useState('');
  const [selectedDivisionFilter, setSelectedDivisionFilter] = useState<string>('all');

  // Modals - Divisions
  const [isDivisionModalOpen, setIsDivisionModalOpen] = useState(false);
  const [editingDivision, setEditingDivision] = useState<DivisionItem | null>(null);
  const [divisionForm, setDivisionForm] = useState({
    name: '',
    code: '',
    description: '',
    is_active: true,
  });

  // Modals - Positions
  const [isPositionModalOpen, setIsPositionModalOpen] = useState(false);
  const [editingPosition, setEditingPosition] = useState<PositionItem | null>(null);
  const [positionForm, setPositionForm] = useState({
    name: '',
    division_id: '' as number | '',
    is_supervisor: false,
    description: '',
    is_active: true,
  });

  // Saving / deleting state
  const [saving, setSaving] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);
  const [successBanner, setSuccessBanner] = useState<string | null>(null);

  // Delete dialog state
  const [itemToDelete, setItemToDelete] = useState<{
    type: 'division' | 'position';
    item: DivisionItem | PositionItem;
  } | null>(null);
  const [deleting, setDeleting] = useState(false);
  const [deleteError, setDeleteError] = useState<string | null>(null);

  // Load Data
  const loadData = useCallback(async () => {
    setLoading(true);
    try {
      const [divRes, posRes]: any = await Promise.all([
        divisionApi.list(undefined, true),
        positionApi.list(undefined, true),
      ]);
      const divList = Array.isArray(divRes?.data)
        ? divRes.data
        : Array.isArray(divRes?.divisions)
        ? divRes.divisions
        : Array.isArray(divRes)
        ? divRes
        : [];
      const posList = Array.isArray(posRes?.data)
        ? posRes.data
        : Array.isArray(posRes?.positions)
        ? posRes.positions
        : Array.isArray(posRes)
        ? posRes
        : [];

      setDivisions(divList);
      setPositions(posList);
    } catch (err) {
      onError(err, 'Gagal memuat data struktur organisasi.');
    } finally {
      setLoading(false);
    }
  }, [onError]);

  useEffect(() => {
    loadData();
  }, [loadData]);

  // Filtered Divisions
  const filteredDivisions = useMemo(() => {
    return divisions.filter((d) => {
      const matchSearch =
        !searchQuery ||
        d.name.toLowerCase().includes(searchQuery.toLowerCase()) ||
        (d.code && d.code.toLowerCase().includes(searchQuery.toLowerCase())) ||
        (d.description && d.description.toLowerCase().includes(searchQuery.toLowerCase()));
      return matchSearch;
    });
  }, [divisions, searchQuery]);

  // Division Stats Summary
  const divisionStats = useMemo(() => {
    const total = divisions.length;
    const active = divisions.filter((d) => d.is_active).length;
    const totalPos = divisions.reduce((acc, d) => acc + (d.positions_count ?? (d.positions?.length ?? 0)), 0);
    const totalUsers = divisions.reduce((acc, d) => acc + (d.users_count ?? 0), 0);
    return { total, active, totalPos, totalUsers };
  }, [divisions]);

  // Filtered Positions
  const filteredPositions = useMemo(() => {
    return positions.filter((p) => {
      const matchSearch =
        !searchQuery ||
        p.name.toLowerCase().includes(searchQuery.toLowerCase()) ||
        (p.description && p.description.toLowerCase().includes(searchQuery.toLowerCase()));
      const matchDivision =
        selectedDivisionFilter === 'all' ||
        (selectedDivisionFilter === 'none' ? !p.division_id : String(p.division_id) === selectedDivisionFilter);
      return matchSearch && matchDivision;
    });
  }, [positions, searchQuery, selectedDivisionFilter]);



  // ── Division Actions ──
  const handleOpenAddDivision = () => {
    setEditingDivision(null);
    setDivisionForm({ name: '', code: '', description: '', is_active: true });
    setFormError(null);
    setIsDivisionModalOpen(true);
  };

  const handleOpenEditDivision = (div: DivisionItem) => {
    setEditingDivision(div);
    setDivisionForm({
      name: div.name,
      code: div.code || '',
      description: div.description || '',
      is_active: div.is_active,
    });
    setFormError(null);
    setIsDivisionModalOpen(true);
  };

  const handleSaveDivision = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!divisionForm.name.trim()) {
      setFormError('Nama divisi wajib diisi.');
      return;
    }

    setSaving(true);
    setFormError(null);
    try {
      if (editingDivision) {
        await divisionApi.update(editingDivision.id, {
          name: divisionForm.name.trim(),
          code: divisionForm.code.trim().toUpperCase() || undefined,
          description: divisionForm.description.trim() || undefined,
          is_active: divisionForm.is_active,
        });
        setSuccessBanner(`Divisi "${divisionForm.name}" berhasil diperbarui.`);
        onAddAuditLog('Divisi Diperbarui', `Divisi ${divisionForm.name}`, 'indigo');
      } else {
        await divisionApi.create({
          name: divisionForm.name.trim(),
          code: divisionForm.code.trim().toUpperCase() || undefined,
          description: divisionForm.description.trim() || undefined,
          is_active: divisionForm.is_active,
        });
        setSuccessBanner(`Divisi baru "${divisionForm.name}" berhasil ditambahkan.`);
        onAddAuditLog('Divisi Dibuat', `Divisi ${divisionForm.name}`, 'indigo');
      }
      setIsDivisionModalOpen(false);
      loadData();
      setTimeout(() => setSuccessBanner(null), 4000);
    } catch (err: any) {
      const msg =
        err?.data?.errors?.code?.[0] ||
        err?.data?.errors?.name?.[0] ||
        err?.data?.message ||
        err?.message ||
        'Gagal menyimpan divisi.';
      setFormError(msg);
    } finally {
      setSaving(false);
    }
  };

  // ── Position Actions ──
  const handleOpenAddPosition = () => {
    setEditingPosition(null);
    setPositionForm({
      name: '',
      division_id: divisions.length > 0 ? divisions[0].id : '',
      is_supervisor: false,
      description: '',
      is_active: true,
    });
    setFormError(null);
    setIsPositionModalOpen(true);
  };

  const handleOpenEditPosition = (pos: PositionItem) => {
    setEditingPosition(pos);
    setPositionForm({
      name: pos.name,
      division_id: pos.division_id ?? '',
      is_supervisor: pos.is_supervisor,
      description: pos.description || '',
      is_active: pos.is_active,
    });
    setFormError(null);
    setIsPositionModalOpen(true);
  };

  const handleSavePosition = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!positionForm.name.trim()) {
      setFormError('Nama jabatan wajib diisi.');
      return;
    }

    setSaving(true);
    setFormError(null);
    try {
      const payload = {
        name: positionForm.name.trim(),
        division_id: positionForm.division_id ? Number(positionForm.division_id) : null,
        is_supervisor: positionForm.is_supervisor,
        description: positionForm.description.trim() || undefined,
        is_active: positionForm.is_active,
      };

      if (editingPosition) {
        await positionApi.update(editingPosition.id, payload);
        setSuccessBanner(`Jabatan "${positionForm.name}" berhasil diperbarui.`);
        onAddAuditLog('Jabatan Diperbarui', `Jabatan ${positionForm.name}`, 'indigo');
      } else {
        await positionApi.create(payload);
        setSuccessBanner(`Jabatan baru "${positionForm.name}" berhasil ditambahkan.`);
        onAddAuditLog('Jabatan Dibuat', `Jabatan ${positionForm.name}`, 'indigo');
      }
      setIsPositionModalOpen(false);
      loadData();
      setTimeout(() => setSuccessBanner(null), 4000);
    } catch (err: any) {
      setFormError(err?.message || 'Gagal menyimpan jabatan.');
    } finally {
      setSaving(false);
    }
  };

  // ── Delete Item ──
  const handleConfirmDelete = async () => {
    if (!itemToDelete) return;
    setDeleting(true);
    setDeleteError(null);
    try {
      if (itemToDelete.type === 'division') {
        await divisionApi.destroy(itemToDelete.item.id);
        setSuccessBanner(`Divisi "${itemToDelete.item.name}" berhasil dihapus.`);
        onAddAuditLog('Divisi Dihapus', `Divisi ${itemToDelete.item.name}`, 'rose');
      } else {
        await positionApi.destroy(itemToDelete.item.id);
        setSuccessBanner(`Jabatan "${itemToDelete.item.name}" berhasil dihapus.`);
        onAddAuditLog('Jabatan Dihapus', `Jabatan ${itemToDelete.item.name}`, 'rose');
      }
      setItemToDelete(null);
      loadData();
      setTimeout(() => setSuccessBanner(null), 4000);
    } catch (err: any) {
      setDeleteError(err?.data?.message || err?.message || 'Gagal menghapus data.');
    } finally {
      setDeleting(false);
    }
  };

  return (
    <div className="space-y-6">
      {/* Sub-tab Navigation */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-2 border-b border-slate-200 dark:border-slate-800">
        <div className="flex flex-wrap items-center gap-2">
          <button
            onClick={() => {
              setSubTab('divisions');
              setSearchQuery('');
            }}
            className={`flex items-center gap-2 px-4 py-2 rounded-xl text-xs font-bold transition ${
              subTab === 'divisions'
                ? 'bg-indigo-600 text-white shadow-sm shadow-indigo-500/20'
                : 'bg-white dark:bg-slate-800/80 text-slate-600 dark:text-slate-300 border border-slate-200 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-800'
            }`}
          >
            <Building className="w-3.5 h-3.5" />
            <span>Master Divisi ({divisions.length})</span>
          </button>
          <button
            onClick={() => {
              setSubTab('positions');
              setSearchQuery('');
            }}
            className={`flex items-center gap-2 px-4 py-2 rounded-xl text-xs font-bold transition ${
              subTab === 'positions'
                ? 'bg-indigo-600 text-white shadow-sm shadow-indigo-500/20'
                : 'bg-white dark:bg-slate-800/80 text-slate-600 dark:text-slate-300 border border-slate-200 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-800'
            }`}
          >
            <Briefcase className="w-3.5 h-3.5" />
            <span>Master Jabatan & Posisi ({positions.length})</span>
          </button>
        </div>

        <div className="flex items-center gap-2">
          <button
            onClick={loadData}
            disabled={loading}
            className="p-2 border border-slate-200 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-800 text-slate-600 dark:text-slate-400 rounded-xl transition"
            title="Segarkan data"
          >
            <RefreshCw className={`w-3.5 h-3.5 ${loading ? 'animate-spin' : ''}`} />
          </button>
          {subTab === 'divisions' ? (
            <button
              onClick={handleOpenAddDivision}
              className="flex items-center gap-1.5 px-3.5 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-bold shadow-sm transition"
            >
              <Plus className="w-3.5 h-3.5" />
              <span>Tambah Divisi</span>
            </button>
          ) : (
            <button
              onClick={handleOpenAddPosition}
              className="flex items-center gap-1.5 px-3.5 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-bold shadow-sm transition"
            >
              <Plus className="w-3.5 h-3.5" />
              <span>Tambah Jabatan</span>
            </button>
          )}
        </div>
      </div>

      {/* Success Notification */}
      {successBanner && (
        <div className="flex items-center gap-2 bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-200 dark:border-emerald-800 text-emerald-800 dark:text-emerald-300 px-4 py-3 rounded-2xl text-xs font-semibold animate-in fade-in duration-200">
          <CheckCircle2 className="w-4 h-4 text-emerald-600 shrink-0" />
          <span>{successBanner}</span>
          <button onClick={() => setSuccessBanner(null)} className="ml-auto text-emerald-600 hover:text-emerald-800">
            <X className="w-3.5 h-3.5" />
          </button>
        </div>
      )}

      {/* Filter & Search Bar */}
      <div className="flex flex-col sm:flex-row items-center gap-3">
        <div className="relative flex-1 w-full">
          <Search className="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2" />
          <input
            type="text"
            placeholder={
              subTab === 'divisions'
                ? 'Cari nama atau kode divisi...'
                : subTab === 'positions'
                ? 'Cari nama jabatan atau deskripsi...'
                : 'Cari nama, kode atau level job grade...'
            }
            value={searchQuery}
            onChange={(e) => setSearchQuery(e.target.value)}
            className="w-full pl-9.5 pr-4 py-2 text-xs bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-600 transition"
          />
        </div>

        {subTab === 'positions' && (
          <div className="w-full sm:w-64">
            <select
              value={selectedDivisionFilter}
              onChange={(e) => setSelectedDivisionFilter(e.target.value)}
              className="w-full py-2 px-3 text-xs bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-600 transition"
            >
              <option value="all">Semua Divisi ({positions.length})</option>
              {divisions.map((d) => (
                <option key={d.id} value={String(d.id)}>
                  {d.name} {d.code ? `(${d.code})` : ''}
                </option>
              ))}
              <option value="none">Tanpa Divisi</option>
            </select>
          </div>
        )}
      </div>

      {/* ── Content: Master Divisi ── */}
      {subTab === 'divisions' && (
        <div className="space-y-4">
          {/* Summary Cards */}
          <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
            <div className="p-3.5 bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800 rounded-2xl flex items-center gap-3 shadow-xs">
              <div className="w-10 h-10 rounded-xl bg-indigo-50 dark:bg-indigo-950/50 text-indigo-600 dark:text-indigo-400 flex items-center justify-center shrink-0">
                <Building className="w-5 h-5" />
              </div>
              <div className="min-w-0">
                <span className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block truncate">Total Divisi</span>
                <span className="text-base font-extrabold text-slate-900 dark:text-white">{divisionStats.total}</span>
              </div>
            </div>
            <div className="p-3.5 bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800 rounded-2xl flex items-center gap-3 shadow-xs">
              <div className="w-10 h-10 rounded-xl bg-emerald-50 dark:bg-emerald-950/50 text-emerald-600 dark:text-emerald-400 flex items-center justify-center shrink-0">
                <CheckCircle2 className="w-5 h-5" />
              </div>
              <div className="min-w-0">
                <span className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block truncate">Divisi Aktif</span>
                <span className="text-base font-extrabold text-emerald-600 dark:text-emerald-400">{divisionStats.active}</span>
              </div>
            </div>
            <div className="p-3.5 bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800 rounded-2xl flex items-center gap-3 shadow-xs">
              <div className="w-10 h-10 rounded-xl bg-teal-50 dark:bg-teal-950/50 text-teal-600 dark:text-teal-400 flex items-center justify-center shrink-0">
                <Briefcase className="w-5 h-5" />
              </div>
              <div className="min-w-0">
                <span className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block truncate">Jabatan Terikat</span>
                <span className="text-base font-extrabold text-slate-900 dark:text-white">{divisionStats.totalPos}</span>
              </div>
            </div>
            <div className="p-3.5 bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800 rounded-2xl flex items-center gap-3 shadow-xs">
              <div className="w-10 h-10 rounded-xl bg-blue-50 dark:bg-blue-950/50 text-blue-600 dark:text-blue-400 flex items-center justify-center shrink-0">
                <Users className="w-5 h-5" />
              </div>
              <div className="min-w-0">
                <span className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block truncate">Staf Terhubung</span>
                <span className="text-base font-extrabold text-slate-900 dark:text-white">{divisionStats.totalUsers}</span>
              </div>
            </div>
          </div>

          <div className="bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800 rounded-2xl overflow-hidden shadow-xs">
            <div className="overflow-x-auto">
              <table className="w-full text-left text-xs">
                <thead className="bg-slate-50 dark:bg-slate-800/60 border-b border-slate-200 dark:border-slate-800 text-slate-500 dark:text-slate-400 uppercase text-[10px] font-bold tracking-wider">
                  <tr>
                    <th className="py-3 px-4">Nama Divisi</th>
                    <th className="py-3 px-4">Kode</th>
                    <th className="py-3 px-4">Deskripsi</th>
                    <th className="py-3 px-4 text-center">Jumlah Jabatan</th>
                    <th className="py-3 px-4 text-center">Jumlah Staf</th>
                    <th className="py-3 px-4 text-center">Status</th>
                    <th className="py-3 px-4 text-right">Aksi</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100 dark:divide-slate-800/80 text-slate-700 dark:text-slate-200">
                  {filteredDivisions.length === 0 ? (
                    <tr>
                      <td colSpan={7} className="text-center py-12 text-slate-400">
                        <div className="flex flex-col items-center justify-center gap-2">
                          <Building className="w-8 h-8 text-slate-300 dark:text-slate-600" />
                          <p className="font-semibold">Belum ada data divisi</p>
                          <p className="text-[11px] text-slate-400">Klik "Tambah Divisi" untuk menambahkan divisi baru.</p>
                        </div>
                      </td>
                    </tr>
                  ) : (
                    filteredDivisions.map((div) => (
                      <tr key={div.id} className="hover:bg-slate-50/50 dark:hover:bg-slate-800/40 transition">
                        <td className="py-3.5 px-4 font-semibold text-slate-900 dark:text-white flex items-center gap-2">
                          <div className="w-7 h-7 rounded-lg bg-indigo-50 dark:bg-indigo-950/50 text-indigo-600 dark:text-indigo-400 flex items-center justify-center font-bold text-xs">
                            {div.name.slice(0, 1).toUpperCase()}
                          </div>
                          <div>
                            <span className="block">{div.name}</span>
                          </div>
                        </td>
                        <td className="py-3.5 px-4">
                          {div.code ? (
                            <span className="px-2 py-0.5 rounded-md bg-slate-100 dark:bg-slate-800 font-mono text-[11px] font-semibold text-slate-700 dark:text-slate-300">
                              {div.code}
                            </span>
                          ) : (
                            <span className="text-slate-400">—</span>
                          )}
                        </td>
                        <td className="py-3.5 px-4 text-slate-500 dark:text-slate-400 max-w-xs truncate" title={div.description || undefined}>
                          {div.description || '—'}
                        </td>
                        <td className="py-3.5 px-4 text-center font-semibold text-slate-600 dark:text-slate-300">
                          {div.positions_count ?? (div.positions ? div.positions.length : 0)}
                        </td>
                        <td className="py-3.5 px-4 text-center">
                          <span className="inline-flex items-center gap-1 font-semibold text-slate-600 dark:text-slate-300">
                            <Users className="w-3 h-3 text-slate-400" />
                            {div.users_count ?? 0}
                          </span>
                        </td>
                        <td className="py-3.5 px-4 text-center">
                          <span
                            className={`inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-bold ${
                              div.is_active
                                ? 'bg-emerald-50 dark:bg-emerald-950/40 text-emerald-700 dark:text-emerald-400 border border-emerald-200 dark:border-emerald-800'
                                : 'bg-slate-100 dark:bg-slate-800 text-slate-500 border border-slate-200 dark:border-slate-700'
                            }`}
                          >
                            <span className={`w-1.5 h-1.5 rounded-full ${div.is_active ? 'bg-emerald-500' : 'bg-slate-400'}`} />
                            {div.is_active ? 'Aktif' : 'Nonaktif'}
                          </span>
                        </td>
                        <td className="py-3.5 px-4 text-right">
                          <div className="flex items-center justify-end gap-1.5">
                            <button
                              onClick={() => handleOpenEditDivision(div)}
                              className="p-1.5 hover:bg-indigo-50 dark:hover:bg-indigo-950/40 text-slate-500 hover:text-indigo-600 rounded-lg transition"
                              title="Edit Divisi"
                            >
                              <Edit2 className="w-3.5 h-3.5" />
                            </button>
                            <button
                              onClick={() => {
                                setDeleteError(null);
                                setItemToDelete({ type: 'division', item: div });
                              }}
                              className="p-1.5 hover:bg-rose-50 dark:hover:bg-rose-950/40 text-slate-400 hover:text-rose-600 rounded-lg transition"
                              title="Hapus Divisi"
                            >
                              <Trash2 className="w-3.5 h-3.5" />
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
        </div>
      )}

      {/* ── Content: Master Jabatan & Posisi ── */}
      {subTab === 'positions' && (
        <div className="bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800 rounded-2xl overflow-hidden shadow-xs">
          <div className="overflow-x-auto">
            <table className="w-full text-left text-xs">
              <thead className="bg-slate-50 dark:bg-slate-800/60 border-b border-slate-200 dark:border-slate-800 text-slate-500 dark:text-slate-400 uppercase text-[10px] font-bold tracking-wider">
                <tr>
                  <th className="py-3 px-4">Nama Jabatan / Posisi</th>
                  <th className="py-3 px-4">Divisi Terkait</th>
                  <th className="py-3 px-4 text-center">Wewenang Supervisor</th>
                  <th className="py-3 px-4">Deskripsi</th>
                  <th className="py-3 px-4 text-center">Jumlah Karyawan</th>
                  <th className="py-3 px-4 text-center">Status</th>
                  <th className="py-3 px-4 text-right">Aksi</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100 dark:divide-slate-800/80 text-slate-700 dark:text-slate-200">
                {filteredPositions.length === 0 ? (
                  <tr>
                    <td colSpan={7} className="text-center py-12 text-slate-400">
                      <div className="flex flex-col items-center justify-center gap-2">
                        <Briefcase className="w-8 h-8 text-slate-300 dark:text-slate-600" />
                        <p className="font-semibold">Belum ada posisi kerja / jabatan</p>
                        <p className="text-[11px] text-slate-400">Klik "Tambah Jabatan" untuk menambahkan posisi baru.</p>
                      </div>
                    </td>
                  </tr>
                ) : (
                  filteredPositions.map((pos) => {
                    return (
                      <tr key={pos.id} className="hover:bg-slate-50/50 dark:hover:bg-slate-800/40 transition">
                        <td className="py-3.5 px-4 font-semibold text-slate-900 dark:text-white flex items-center gap-2">
                          <div
                            className={`w-7 h-7 rounded-lg flex items-center justify-center font-bold text-xs ${
                              pos.is_supervisor
                                ? 'bg-teal-50 dark:bg-teal-950/50 text-teal-600 dark:text-teal-400'
                                : 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400'
                            }`}
                          >
                            <Briefcase className="w-3.5 h-3.5" />
                          </div>
                          <div>
                            <span className="block">{pos.name}</span>
                          </div>
                        </td>
                        <td className="py-3.5 px-4">
                          {pos.division ? (
                            <span className="px-2.5 py-1 rounded-lg bg-indigo-50 dark:bg-indigo-950/40 text-indigo-700 dark:text-indigo-300 font-semibold text-[11px] inline-flex items-center gap-1 border border-indigo-100 dark:border-indigo-900/50">
                              <Building className="w-3 h-3 text-indigo-500" />
                              {pos.division.name}
                            </span>
                          ) : (
                            <span className="text-slate-400 italic">Umum / Semua Divisi</span>
                          )}
                        </td>
                        <td className="py-3.5 px-4 text-center">
                          {pos.is_supervisor ? (
                            <span className="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[10px] font-extrabold bg-teal-50 dark:bg-teal-950/40 text-teal-700 dark:text-teal-300 border border-teal-200 dark:border-teal-800 shadow-xs">
                              <ShieldCheck className="w-3 h-3 text-teal-600 dark:text-teal-400" />
                              SPV (Level 1 Lembur)
                            </span>
                          ) : (
                            <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-semibold text-slate-500 bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700">
                              Staff Biasa
                            </span>
                          )}
                        </td>
                        <td className="py-3.5 px-4 text-slate-500 dark:text-slate-400 max-w-xs truncate">
                          {pos.description || '—'}
                        </td>
                        <td className="py-3.5 px-4 text-center">
                          <span className="inline-flex items-center gap-1 font-semibold text-slate-600 dark:text-slate-300">
                            <Users className="w-3 h-3 text-slate-400" />
                            {pos.users_count ?? 0}
                          </span>
                        </td>
                        <td className="py-3.5 px-4 text-center">
                          <span
                            className={`inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-bold ${
                              pos.is_active
                                ? 'bg-emerald-50 dark:bg-emerald-950/40 text-emerald-700 dark:text-emerald-400 border border-emerald-200 dark:border-emerald-800'
                                : 'bg-slate-100 dark:bg-slate-800 text-slate-500 border border-slate-200 dark:border-slate-700'
                            }`}
                          >
                            <span className={`w-1.5 h-1.5 rounded-full ${pos.is_active ? 'bg-emerald-500' : 'bg-slate-400'}`} />
                            {pos.is_active ? 'Aktif' : 'Nonaktif'}
                          </span>
                        </td>
                        <td className="py-3.5 px-4 text-right">
                          <div className="flex items-center justify-end gap-1.5">
                            <button
                              onClick={() => handleOpenEditPosition(pos)}
                              className="p-1.5 hover:bg-indigo-50 dark:hover:bg-indigo-950/40 text-slate-500 hover:text-indigo-600 rounded-lg transition"
                              title="Edit Jabatan"
                            >
                              <Edit2 className="w-3.5 h-3.5" />
                            </button>
                            <button
                              onClick={() => {
                                setDeleteError(null);
                                setItemToDelete({ type: 'position', item: pos });
                              }}
                              className="p-1.5 hover:bg-rose-50 dark:hover:bg-rose-950/40 text-slate-400 hover:text-rose-600 rounded-lg transition"
                              title="Hapus Jabatan"
                            >
                              <Trash2 className="w-3.5 h-3.5" />
                            </button>
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
      )}



      {/* ── Modal: Tambah / Edit Divisi ── */}
      {isDivisionModalOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm animate-in fade-in duration-200">
          <div className="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-3xl p-6 shadow-2xl max-w-md w-full space-y-4">
            <div className="flex items-center justify-between pb-3 border-b border-slate-100 dark:border-slate-800">
              <div className="flex items-center gap-2.5">
                <div className="w-9 h-9 rounded-2xl bg-indigo-50 dark:bg-indigo-950/50 text-indigo-600 dark:text-indigo-400 flex items-center justify-center">
                  <Building className="w-5 h-5" />
                </div>
                <div>
                  <h3 className="text-sm font-extrabold text-slate-900 dark:text-white">
                    {editingDivision ? 'Edit Data Divisi' : 'Tambah Divisi Baru'}
                  </h3>
                  <p className="text-[11px] text-slate-400">Kelompokkan karyawan dan rantai approval per unit kerja</p>
                </div>
              </div>
              <button
                type="button"
                onClick={() => setIsDivisionModalOpen(false)}
                className="p-1.5 hover:bg-slate-100 dark:hover:bg-slate-800 text-slate-400 hover:text-slate-600 rounded-xl transition"
              >
                <X className="w-4 h-4" />
              </button>
            </div>

            {formError && (
              <div className="flex items-center gap-2 bg-rose-50 dark:bg-rose-950/40 border border-rose-200 dark:border-rose-800 text-rose-700 dark:text-rose-300 p-3 rounded-xl text-xs">
                <AlertTriangle className="w-4 h-4 shrink-0 text-rose-500" />
                <span>{formError}</span>
              </div>
            )}

            <form onSubmit={handleSaveDivision} className="space-y-4">
              <div className="space-y-1">
                <label className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">
                  Nama Divisi *
                </label>
                <input
                  type="text"
                  required
                  placeholder="Contoh: Information Technology, Sales & Marketing"
                  value={divisionForm.name}
                  onChange={(e) => setDivisionForm({ ...divisionForm, name: e.target.value })}
                  className="w-full text-xs px-3.5 py-2.5 bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-xl focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-600 transition"
                />
              </div>

              <div className="space-y-1">
                <label className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">
                  Kode Divisi (Unik)
                </label>
                <input
                  type="text"
                  placeholder="Contoh: IT, FINA, LOGI"
                  value={divisionForm.code}
                  onChange={(e) => setDivisionForm({ ...divisionForm, code: e.target.value.toUpperCase().replace(/\s+/g, '') })}
                  maxLength={10}
                  className="w-full text-xs px-3.5 py-2.5 bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-xl font-mono focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-600 transition uppercase"
                />
                <p className="text-[10px] text-slate-400">
                  Kode unik identitas divisi. Tidak boleh kembar dengan divisi lain.
                </p>
              </div>

              <div className="space-y-1">
                <label className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">
                  Deskripsi / Keterangan
                </label>
                <textarea
                  rows={2}
                  placeholder="Deskripsi singkat fungsi divisi ini..."
                  value={divisionForm.description}
                  onChange={(e) => setDivisionForm({ ...divisionForm, description: e.target.value })}
                  className="w-full text-xs px-3.5 py-2 bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-xl focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-600 transition"
                />
              </div>

              <div className="flex items-center justify-between p-3 rounded-xl bg-slate-50 dark:bg-slate-800/40 border border-slate-200 dark:border-slate-700">
                <div>
                  <span className="text-xs font-bold text-slate-800 dark:text-slate-200 block">Status Aktif</span>
                  <span className="text-[10px] text-slate-400">Divisi aktif dapat dipilih saat registrasi karyawan</span>
                </div>
                <input
                  type="checkbox"
                  checked={divisionForm.is_active}
                  onChange={(e) => setDivisionForm({ ...divisionForm, is_active: e.target.checked })}
                  className="w-4 h-4 rounded text-indigo-600 focus:ring-indigo-500 cursor-pointer"
                />
              </div>

              <div className="flex gap-2.5 pt-2">
                <button
                  type="button"
                  onClick={() => setIsDivisionModalOpen(false)}
                  className="flex-1 py-2.5 border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-400 rounded-xl text-xs font-bold hover:bg-slate-50 dark:hover:bg-slate-800 transition"
                >
                  Batal
                </button>
                <button
                  type="submit"
                  disabled={saving}
                  className="flex-1 py-2.5 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-60 text-white font-bold rounded-xl text-xs transition shadow-sm"
                >
                  {saving ? 'Menyimpan...' : 'Simpan Divisi'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* ── Modal: Tambah / Edit Jabatan ── */}
      {isPositionModalOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm animate-in fade-in duration-200">
          <div className="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-3xl p-6 shadow-2xl max-w-md w-full space-y-4">
            <div className="flex items-center justify-between pb-3 border-b border-slate-100 dark:border-slate-800">
              <div className="flex items-center gap-2.5">
                <div className="w-9 h-9 rounded-2xl bg-teal-50 dark:bg-teal-950/50 text-teal-600 dark:text-teal-400 flex items-center justify-center">
                  <Briefcase className="w-5 h-5" />
                </div>
                <div>
                  <h3 className="text-sm font-extrabold text-slate-900 dark:text-white">
                    {editingPosition ? 'Edit Jabatan / Posisi' : 'Tambah Jabatan Baru'}
                  </h3>
                  <p className="text-[11px] text-slate-400">Atur posisi kerja dan hak otorisasi SPV</p>
                </div>
              </div>
              <button
                type="button"
                onClick={() => setIsPositionModalOpen(false)}
                className="p-1.5 hover:bg-slate-100 dark:hover:bg-slate-800 text-slate-400 hover:text-slate-600 rounded-xl transition"
              >
                <X className="w-4 h-4" />
              </button>
            </div>

            {formError && (
              <div className="flex items-center gap-2 bg-rose-50 dark:bg-rose-950/40 border border-rose-200 dark:border-rose-800 text-rose-700 dark:text-rose-300 p-3 rounded-xl text-xs">
                <AlertTriangle className="w-4 h-4 shrink-0 text-rose-500" />
                <span>{formError}</span>
              </div>
            )}

            <form onSubmit={handleSavePosition} className="space-y-4">
              <div className="space-y-1">
                <label className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">
                  Nama Jabatan / Posisi Kerja *
                </label>
                <input
                  type="text"
                  required
                  placeholder="Contoh: SPV IT Infrastructure, Staff Developer"
                  value={positionForm.name}
                  onChange={(e) => setPositionForm({ ...positionForm, name: e.target.value })}
                  className="w-full text-xs px-3.5 py-2.5 bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-xl focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-600 transition"
                />
              </div>

              <div className="space-y-1">
                <label className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">
                  Divisi Penempatan *
                </label>
                <select
                  required
                  value={positionForm.division_id}
                  onChange={(e) => setPositionForm({ ...positionForm, division_id: e.target.value ? Number(e.target.value) : '' })}
                  className="w-full text-xs px-3.5 py-2.5 bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-xl focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-600 transition"
                >
                  <option value="">Pilih Divisi</option>
                  {divisions.map((d) => (
                    <option key={d.id} value={d.id}>
                      {d.name} {d.code ? `(${d.code})` : ''}
                    </option>
                  ))}
                </select>
              </div>

              {/* Checkbox Wewenang Supervisor */}
              <div className="p-3.5 rounded-2xl bg-teal-50/60 dark:bg-teal-950/30 border border-teal-200 dark:border-teal-800/60 space-y-1.5">
                <label className="flex items-start gap-2.5 cursor-pointer">
                  <input
                    type="checkbox"
                    checked={positionForm.is_supervisor}
                    onChange={(e) => setPositionForm({ ...positionForm, is_supervisor: e.target.checked })}
                    className="w-4 h-4 rounded text-teal-600 focus:ring-teal-500 mt-0.5 cursor-pointer"
                  />
                  <div>
                    <span className="text-xs font-bold text-teal-900 dark:text-teal-200 flex items-center gap-1.5">
                      <ShieldCheck className="w-3.5 h-3.5 text-teal-600 dark:text-teal-400" />
                      Wewenang Atasan Langsung (SPV)
                    </span>
                    <p className="text-[10px] text-teal-700/80 dark:text-teal-300/70 leading-relaxed mt-0.5">
                      Karyawan dengan jabatan ini akan muncul di daftar pilihan <strong>Atasan Langsung (SPV)</strong> dan berhak menyetujui pengajuan cuti, izin, serta lembur Tahap 1 (SPV) untuk staf di divisi bersangkutan atau bawahan langsungnya.
                    </p>
                  </div>
                </label>
              </div>

              <div className="space-y-1">
                <label className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">
                  Deskripsi / Tanggung Jawab
                </label>
                <textarea
                  rows={2}
                  placeholder="Uraian singkat peran atau kualifikasi jabatan..."
                  value={positionForm.description}
                  onChange={(e) => setPositionForm({ ...positionForm, description: e.target.value })}
                  className="w-full text-xs px-3.5 py-2 bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-xl focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-600 transition"
                />
              </div>

              <div className="flex items-center justify-between p-3 rounded-xl bg-slate-50 dark:bg-slate-800/40 border border-slate-200 dark:border-slate-700">
                <div>
                  <span className="text-xs font-bold text-slate-800 dark:text-slate-200 block">Status Aktif</span>
                  <span className="text-[10px] text-slate-400">Jabatan aktif dapat dipilih pada profil karyawan</span>
                </div>
                <input
                  type="checkbox"
                  checked={positionForm.is_active}
                  onChange={(e) => setPositionForm({ ...positionForm, is_active: e.target.checked })}
                  className="w-4 h-4 rounded text-indigo-600 focus:ring-indigo-500 cursor-pointer"
                />
              </div>

              <div className="flex gap-2.5 pt-2">
                <button
                  type="button"
                  onClick={() => setIsPositionModalOpen(false)}
                  className="flex-1 py-2.5 border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-400 rounded-xl text-xs font-bold hover:bg-slate-50 dark:hover:bg-slate-800 transition"
                >
                  Batal
                </button>
                <button
                  type="submit"
                  disabled={saving}
                  className="flex-1 py-2.5 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-60 text-white font-bold rounded-xl text-xs transition shadow-sm"
                >
                  {saving ? 'Menyimpan...' : 'Simpan Jabatan'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}



      {/* ── Dialog Konfirmasi Hapus ── */}
      {itemToDelete && (
        <ConfirmationDialog
          isOpen={true}
          title={`Hapus ${itemToDelete.type === 'division' ? 'Divisi' : 'Jabatan'}?`}
          message={
            <div className="space-y-2 text-xs">
              <p>
                Anda akan menghapus{' '}
                {itemToDelete.type === 'division' ? 'divisi' : 'jabatan'}:{' '}
                <strong className="text-slate-900 dark:text-white">"{itemToDelete.item.name}"</strong>.
              </p>
              {itemToDelete.type === 'division' && (((itemToDelete.item as DivisionItem).users_count ?? 0) > 0 || ((itemToDelete.item as DivisionItem).positions_count ?? 0) > 0) && (
                <div className="p-3 bg-amber-50 dark:bg-amber-950/40 border border-amber-200 dark:border-amber-800 text-amber-800 dark:text-amber-300 rounded-xl text-xs font-medium space-y-1">
                  <div className="flex items-center gap-1.5 font-bold">
                    <AlertTriangle className="w-4 h-4 shrink-0 text-amber-500" />
                    <span>Divisi masih memiliki entitas terikat:</span>
                  </div>
                  <ul className="list-disc list-inside text-[11px] pl-1 space-y-0.5 text-amber-700 dark:text-amber-400">
                    {((itemToDelete.item as DivisionItem).positions_count ?? 0) > 0 && (
                      <li>{(itemToDelete.item as DivisionItem).positions_count} jabatan terikat</li>
                    )}
                    {((itemToDelete.item as DivisionItem).users_count ?? 0) > 0 && (
                      <li>{(itemToDelete.item as DivisionItem).users_count} karyawan aktif</li>
                    )}
                  </ul>
                  <p className="text-[10px] text-amber-600 dark:text-amber-400 pt-1">
                    * Harap pindahkan atau kosongkan jabatan dan karyawan terlebih dahulu sebelum menghapus divisi ini.
                  </p>
                </div>
              )}
              {deleteError && (
                <div className="p-3 bg-rose-50 dark:bg-rose-950/40 border border-rose-200 dark:border-rose-800 text-rose-700 dark:text-rose-300 rounded-xl text-xs font-medium flex items-center gap-2">
                  <AlertTriangle className="w-4 h-4 shrink-0 text-rose-500" />
                  <span>{deleteError}</span>
                </div>
              )}
              <p className="text-slate-500 dark:text-slate-400 text-[11px]">
                {itemToDelete.type === 'division'
                  ? 'Catatan: Divisi yang masih memiliki karyawan atau jabatan terikat tidak dapat dihapus demi integritas data relasional.'
                  : 'Catatan: Jabatan yang masih diemban oleh karyawan aktif tidak dapat dihapus.'}
              </p>
            </div>
          }
          type="danger"
          confirmText={deleting ? 'Menghapus...' : 'Ya, Hapus'}
          onConfirm={handleConfirmDelete}
          onCancel={() => {
            if (!deleting) {
              setItemToDelete(null);
              setDeleteError(null);
            }
          }}
        />
      )}
    </div>
  );
};
