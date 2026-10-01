import React, { useState, useEffect, useCallback, useMemo } from 'react';
import {
  Layers, Plus, Pencil, Trash2, X, RefreshCw, Check, CheckCircle2,
  AlertCircle, AlertTriangle, ShieldCheck, ChevronRight, Info, Save,
  Loader2, ArrowUpDown, Sliders, Briefcase, Eye, Lock,
} from 'lucide-react';
import { payrollApi } from '../services/endpoints';
import type { JobLevel, SalaryGrade } from '../types';
import { ConfirmationDialog } from './ConfirmationDialog';

interface Props {
  canManage: boolean;
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
    return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(num);
  }
  return `${currency} ${new Intl.NumberFormat('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(num)}`;
};

const errMsg = (e: any, fallback = 'Terjadi kesalahan.') =>
  (e?.message && typeof e.message === 'string' ? e.message : fallback);

export const StrukturSkalaUpah: React.FC<Props> = ({ canManage }) => {
  const [subTab, setSubTab] = useState<'grades' | 'levels'>('grades');
  const [jobLevels, setJobLevels] = useState<JobLevel[]>([]);
  const [salaryGrades, setSalaryGrades] = useState<SalaryGrade[]>([]);
  const [loading, setLoading] = useState(true);
  const [banner, setBanner] = useState<{ type: 'success' | 'error' | 'info'; text: string } | null>(null);

  // Filter golongan upah
  const [filterLevelId, setFilterLevelId] = useState<string>('all');
  const [searchGrade, setSearchGrade] = useState('');

  // Modals Jenjang Jabatan
  const [showLevelModal, setShowLevelModal] = useState(false);
  const [editingLevel, setEditingLevel] = useState<JobLevel | null>(null);
  const [levelForm, setLevelForm] = useState({ name: '', code: '', rank: 1, description: '', is_active: true });
  const [savingLevel, setSavingLevel] = useState(false);
  const [confirmDeleteLevel, setConfirmDeleteLevel] = useState<JobLevel | null>(null);
  const [deactivateOfferLevel, setDeactivateOfferLevel] = useState<JobLevel | null>(null);

  // Modals Golongan Upah
  const [showGradeModal, setShowGradeModal] = useState(false);
  const [editingGrade, setEditingGrade] = useState<SalaryGrade | null>(null);
  const [gradeForm, setGradeForm] = useState({
    name: '',
    code: '',
    job_level_id: '' as string,
    min_salary: '',
    mid_salary: '',
    max_salary: '',
    currency: 'IDR',
    description: '',
    is_active: true,
  });
  const [savingGrade, setSavingGrade] = useState(false);
  const [confirmDeleteGrade, setConfirmDeleteGrade] = useState<SalaryGrade | null>(null);
  const [deactivateOfferGrade, setDeactivateOfferGrade] = useState<SalaryGrade | null>(null);

  const showBanner = useCallback((type: 'success' | 'error' | 'info', text: string) => {
    setBanner({ type, text });
  }, []);

  const loadData = useCallback(async () => {
    setLoading(true);
    try {
      const [levelsRes, gradesRes] = await Promise.all([
        payrollApi.listJobLevels(),
        payrollApi.listSalaryGrades(),
      ]);
      setJobLevels(levelsRes?.data ?? []);
      setSalaryGrades(gradesRes?.data ?? []);
    } catch (e: any) {
      showBanner('error', errMsg(e, 'Gagal memuat data Struktur & Skala Upah.'));
    } finally {
      setLoading(false);
    }
  }, [showBanner]);

  useEffect(() => {
    loadData();
  }, [loadData]);

  // ─────────────────────────────────────────────────────────────
  // HANDLERS: Jenjang Jabatan
  // ─────────────────────────────────────────────────────────────
  const openCreateLevel = () => {
    setEditingLevel(null);
    setLevelForm({
      name: '',
      code: '',
      rank: jobLevels.length > 0 ? Math.max(...jobLevels.map((l) => l.rank || 0)) + 1 : 1,
      description: '',
      is_active: true,
    });
    setShowLevelModal(true);
  };

  const openEditLevel = (level: JobLevel) => {
    setEditingLevel(level);
    setLevelForm({
      name: level.name,
      code: level.code || '',
      rank: level.rank,
      description: level.description || '',
      is_active: level.is_active,
    });
    setShowLevelModal(true);
  };

  const handleSaveLevel = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!levelForm.name.trim()) {
      showBanner('error', 'Nama jenjang jabatan wajib diisi.');
      return;
    }
    setSavingLevel(true);
    try {
      const payload = {
        name: levelForm.name.trim(),
        code: levelForm.code.trim() || undefined,
        rank: Number(levelForm.rank) || 1,
        description: levelForm.description.trim() || undefined,
        is_active: levelForm.is_active,
      };

      if (editingLevel) {
        await payrollApi.updateJobLevel(editingLevel.id, payload);
        showBanner('success', `Jenjang jabatan "${payload.name}" berhasil diperbarui.`);
      } else {
        await payrollApi.createJobLevel(payload);
        showBanner('success', `Jenjang jabatan "${payload.name}" berhasil dibuat.`);
      }
      setShowLevelModal(false);
      await loadData();
    } catch (err: any) {
      showBanner('error', errMsg(err, 'Gagal menyimpan jenjang jabatan.'));
    } finally {
      setSavingLevel(false);
    }
  };

  const handleDeleteLevel = async (level: JobLevel) => {
    try {
      await payrollApi.deleteJobLevel(level.id);
      showBanner('success', `Jenjang jabatan "${level.name}" berhasil dihapus.`);
      setConfirmDeleteLevel(null);
      await loadData();
    } catch (err: any) {
      setConfirmDeleteLevel(null);
      // Jika status 422 karena masih terkait golongan atau baris gaji
      if (err?.status === 422 || err?.response?.status === 422) {
        setDeactivateOfferLevel(level);
      } else {
        showBanner('error', errMsg(err, 'Gagal menghapus jenjang jabatan.'));
      }
    }
  };

  const handleDeactivateLevel = async (level: JobLevel) => {
    try {
      await payrollApi.updateJobLevel(level.id, {
        name: level.name,
        code: level.code || undefined,
        rank: level.rank,
        is_active: false,
      });
      showBanner('success', `Jenjang jabatan "${level.name}" berhasil dinonaktifkan.`);
      setDeactivateOfferLevel(null);
      await loadData();
    } catch (err: any) {
      showBanner('error', errMsg(err, 'Gagal menonaktifkan jenjang jabatan.'));
    }
  };

  // ─────────────────────────────────────────────────────────────
  // HANDLERS: Golongan Upah
  // ─────────────────────────────────────────────────────────────
  const openCreateGrade = () => {
    setEditingGrade(null);
    setGradeForm({
      name: '',
      code: '',
      job_level_id: jobLevels.length > 0 ? String(jobLevels[0].id) : '',
      min_salary: '',
      mid_salary: '',
      max_salary: '',
      currency: 'IDR',
      description: '',
      is_active: true,
    });
    setShowGradeModal(true);
  };

  const openEditGrade = (grade: SalaryGrade) => {
    setEditingGrade(grade);
    setGradeForm({
      name: grade.name,
      code: grade.code || '',
      job_level_id: grade.job_level_id ? String(grade.job_level_id) : '',
      min_salary: String(grade.min_salary ?? ''),
      mid_salary: grade.mid_salary != null ? String(grade.mid_salary) : '',
      max_salary: String(grade.max_salary ?? ''),
      currency: grade.currency || 'IDR',
      description: grade.description || '',
      is_active: grade.is_active,
    });
    setShowGradeModal(true);
  };

  // Validasi rentang golongan client-side
  const gradeValidation = useMemo(() => {
    const min = Number(gradeForm.min_salary);
    const max = Number(gradeForm.max_salary);
    const mid = gradeForm.mid_salary ? Number(gradeForm.mid_salary) : null;

    let minMaxError: string | null = null;
    let midError: string | null = null;

    if (gradeForm.min_salary !== '' && gradeForm.max_salary !== '') {
      if (max < min) {
        minMaxError = 'Upah maksimum tidak boleh lebih kecil dari upah minimum.';
      }
    }
    if (mid !== null && (gradeForm.min_salary !== '' || gradeForm.max_salary !== '')) {
      if ((gradeForm.min_salary !== '' && mid < min) || (gradeForm.max_salary !== '' && mid > max)) {
        midError = 'Titik tengah (midpoint) harus berada di antara upah minimum dan maksimum.';
      }
    }
    return { minMaxError, midError, isValid: !minMaxError && !midError };
  }, [gradeForm.min_salary, gradeForm.max_salary, gradeForm.mid_salary]);

  const handleSaveGrade = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!gradeForm.name.trim()) {
      showBanner('error', 'Nama golongan upah wajib diisi.');
      return;
    }
    if (!gradeForm.min_salary || Number(gradeForm.min_salary) <= 0) {
      showBanner('error', 'Upah minimum harus lebih besar dari 0.');
      return;
    }
    if (!gradeForm.max_salary || Number(gradeForm.max_salary) <= 0) {
      showBanner('error', 'Upah maksimum harus lebih besar dari 0.');
      return;
    }
    if (!gradeValidation.isValid) {
      showBanner('error', gradeValidation.minMaxError || gradeValidation.midError || 'Rentang upah tidak valid.');
      return;
    }

    setSavingGrade(true);
    try {
      const payload = {
        name: gradeForm.name.trim(),
        code: gradeForm.code.trim() || undefined,
        job_level_id: gradeForm.job_level_id ? Number(gradeForm.job_level_id) : null,
        min_salary: Number(gradeForm.min_salary),
        mid_salary: gradeForm.mid_salary ? Number(gradeForm.mid_salary) : null,
        max_salary: Number(gradeForm.max_salary),
        currency: gradeForm.currency || 'IDR',
        description: gradeForm.description.trim() || undefined,
        is_active: gradeForm.is_active,
      };

      if (editingGrade) {
        await payrollApi.updateSalaryGrade(editingGrade.id, payload);
        showBanner('success', `Golongan upah "${payload.name}" berhasil diperbarui.`);
      } else {
        await payrollApi.createSalaryGrade(payload);
        showBanner('success', `Golongan upah "${payload.name}" berhasil dibuat.`);
      }
      setShowGradeModal(false);
      await loadData();
    } catch (err: any) {
      showBanner('error', errMsg(err, 'Gagal menyimpan golongan upah.'));
    } finally {
      setSavingGrade(false);
    }
  };

  const handleDeleteGrade = async (grade: SalaryGrade) => {
    try {
      await payrollApi.deleteSalaryGrade(grade.id);
      showBanner('success', `Golongan upah "${grade.name}" berhasil dihapus.`);
      setConfirmDeleteGrade(null);
      await loadData();
    } catch (err: any) {
      setConfirmDeleteGrade(null);
      if (err?.status === 422 || err?.response?.status === 422) {
        setDeactivateOfferGrade(grade);
      } else {
        showBanner('error', errMsg(err, 'Gagal menghapus golongan upah.'));
      }
    }
  };

  const handleDeactivateGrade = async (grade: SalaryGrade) => {
    try {
      await payrollApi.updateSalaryGrade(grade.id, {
        name: grade.name,
        code: grade.code || undefined,
        job_level_id: grade.job_level_id,
        min_salary: Number(grade.min_salary),
        mid_salary: grade.mid_salary != null ? Number(grade.mid_salary) : null,
        max_salary: Number(grade.max_salary),
        currency: grade.currency || 'IDR',
        is_active: false,
      });
      showBanner('success', `Golongan upah "${grade.name}" berhasil dinonaktifkan.`);
      setDeactivateOfferGrade(null);
      await loadData();
    } catch (err: any) {
      showBanner('error', errMsg(err, 'Gagal menonaktifkan golongan upah.'));
    }
  };

  // Filtered Golongan Upah
  const filteredGrades = useMemo(() => {
    return salaryGrades.filter((g) => {
      if (filterLevelId !== 'all' && String(g.job_level_id ?? '') !== filterLevelId) {
        return false;
      }
      if (searchGrade.trim()) {
        const q = searchGrade.toLowerCase();
        return (
          g.name.toLowerCase().includes(q) ||
          (g.code && g.code.toLowerCase().includes(q)) ||
          (g.job_level?.name && g.job_level.name.toLowerCase().includes(q))
        );
      }
      return true;
    });
  }, [salaryGrades, filterLevelId, searchGrade]);

  // Cari upah min dan maks global untuk skala visual bar
  const globalMaxSalary = useMemo(() => {
    let max = 0;
    salaryGrades.forEach((g) => {
      const v = Number(g.max_salary) || 0;
      if (v > max) max = v;
    });
    return max > 0 ? max : 10000000;
  }, [salaryGrades]);

  return (
    <div className="space-y-5">
      {banner && (
        <div
          className={`flex items-start gap-2.5 p-3.5 rounded-2xl border text-xs transition-all ${
            banner.type === 'success'
              ? 'bg-emerald-50 dark:bg-emerald-950/40 border-emerald-200 dark:border-emerald-800 text-emerald-800 dark:text-emerald-200'
              : banner.type === 'error'
              ? 'bg-rose-50 dark:bg-rose-950/40 border-rose-200 dark:border-rose-800 text-rose-800 dark:text-rose-200'
              : 'bg-indigo-50 dark:bg-indigo-950/40 border-indigo-200 dark:border-indigo-800 text-indigo-800 dark:text-indigo-200'
          }`}
        >
          {banner.type === 'success' ? (
            <CheckCircle2 className="w-4 h-4 text-emerald-600 dark:text-emerald-400 shrink-0 mt-0.5" />
          ) : banner.type === 'error' ? (
            <AlertCircle className="w-4 h-4 text-rose-600 dark:text-rose-400 shrink-0 mt-0.5" />
          ) : (
            <Info className="w-4 h-4 text-indigo-600 dark:text-indigo-400 shrink-0 mt-0.5" />
          )}
          <span className="flex-1 font-medium leading-relaxed">{banner.text}</span>
          <button onClick={() => setBanner(null)} className="opacity-60 hover:opacity-100 p-0.5" aria-label="Tutup">
            <X className="w-3.5 h-3.5" />
          </button>
        </div>
      )}

      {/* Header modul */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl p-5 shadow-xs">
        <div className="flex items-center gap-3">
          <div className="p-2.5 rounded-2xl bg-indigo-50 dark:bg-indigo-950/50 text-indigo-600 dark:text-indigo-400 border border-indigo-100 dark:border-indigo-900/40">
            <Sliders className="w-6 h-6" />
          </div>
          <div>
            <div className="flex items-center gap-2">
              <h2 className="text-base font-bold text-slate-900 dark:text-white">Struktur & Skala Upah</h2>
              <span className="text-[10px] font-semibold px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300 border border-emerald-200/50 dark:border-emerald-800/40">
                Permenaker No. 1/2017
              </span>
            </div>
            <p className="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
              Kelola hierarki jenjang jabatan dan rambu rentang upah minimum–maksimum per golongan.
            </p>
          </div>
        </div>

        <div className="flex items-center gap-2 self-start sm:self-center">
          <button onClick={loadData} disabled={loading} className={btnGhost} title="Segarkan Data">
            <RefreshCw className={`w-3.5 h-3.5 ${loading ? 'animate-spin' : ''}`} />
            <span>Segarkan</span>
          </button>
          {canManage && (
            <button
              onClick={subTab === 'grades' ? openCreateGrade : openCreateLevel}
              className={btnPrimary}
            >
              <Plus className="w-3.5 h-3.5" />
              <span>{subTab === 'grades' ? 'Tambah Golongan' : 'Tambah Jenjang'}</span>
            </button>
          )}
        </div>
      </div>

      {/* Sub-tab switcher */}
      <div className="flex border-b border-slate-200 dark:border-slate-800 gap-6">
        <button
          onClick={() => setSubTab('grades')}
          className={`pb-3 text-xs font-bold transition flex items-center gap-2 border-b-2 cursor-pointer ${
            subTab === 'grades'
              ? 'border-indigo-600 text-indigo-600 dark:text-indigo-400'
              : 'border-transparent text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200'
          }`}
        >
          <Layers className="w-4 h-4" />
          <span>Golongan Upah ({salaryGrades.length})</span>
        </button>
        <button
          onClick={() => setSubTab('levels')}
          className={`pb-3 text-xs font-bold transition flex items-center gap-2 border-b-2 cursor-pointer ${
            subTab === 'levels'
              ? 'border-indigo-600 text-indigo-600 dark:text-indigo-400'
              : 'border-transparent text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200'
          }`}
        >
          <Briefcase className="w-4 h-4" />
          <span>Jenjang Jabatan ({jobLevels.length})</span>
        </button>
      </div>

      {/* ─────────────────────────────────────────────────────────────
          SUB-TAB 1: GOLONGAN UPAH
      ───────────────────────────────────────────────────────────── */}
      {subTab === 'grades' && (
        <div className="space-y-4">
          {/* Filter Bar */}
          <div className="flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl p-4 text-xs">
            <div className="flex items-center gap-2 flex-wrap">
              <span className="font-semibold text-slate-600 dark:text-slate-400">Jenjang:</span>
              <select
                value={filterLevelId}
                onChange={(e) => setFilterLevelId(e.target.value)}
                className={`${inputCls} !w-auto cursor-pointer font-medium`}
              >
                <option value="all">Semua Jenjang Jabatan</option>
                {jobLevels.map((lvl) => (
                  <option key={lvl.id} value={lvl.id}>
                    Rank #{lvl.rank} · {lvl.name} {lvl.code ? `(${lvl.code})` : ''}
                  </option>
                ))}
              </select>
            </div>
            <div className="w-full sm:w-64">
              <input
                type="text"
                placeholder="Cari golongan..."
                value={searchGrade}
                onChange={(e) => setSearchGrade(e.target.value)}
                className={inputCls}
              />
            </div>
          </div>

          {/* Tabel Golongan Upah */}
          <div className="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl overflow-hidden shadow-xs">
            {loading ? (
              <div className="py-16 text-center text-xs text-slate-400 flex flex-col items-center gap-2">
                <Loader2 className="w-6 h-6 animate-spin text-indigo-500" />
                <span>Memuat daftar golongan upah...</span>
              </div>
            ) : filteredGrades.length === 0 ? (
              <div className="py-16 text-center text-xs text-slate-400">
                <Layers className="w-8 h-8 mx-auto mb-2 text-slate-300 dark:text-slate-600" />
                <p className="font-semibold text-slate-600 dark:text-slate-300">Belum ada golongan upah</p>
                <p className="text-[11px] mt-0.5">
                  {searchGrade || filterLevelId !== 'all'
                    ? 'Tidak ada golongan yang sesuai dengan filter.'
                    : 'Klik "Tambah Golongan" untuk membuat skala upah pertama.'}
                </p>
              </div>
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full text-xs text-left">
                  <thead>
                    <tr className="border-b border-slate-100 dark:border-slate-800 text-slate-400 font-semibold bg-slate-50/50 dark:bg-slate-800/40">
                      <th className="py-3 px-4">Golongan</th>
                      <th className="py-3 px-4">Jenjang Jabatan</th>
                      <th className="py-3 px-4 text-right">Upah Minimum</th>
                      <th className="py-3 px-4 text-right">Titik Tengah (Mid)</th>
                      <th className="py-3 px-4 text-right">Upah Maksimum</th>
                      <th className="py-3 px-4 text-center">Spektrum Rentang</th>
                      <th className="py-3 px-4 text-center">Status</th>
                      {canManage && <th className="py-3 px-4 text-right">Aksi</th>}
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100 dark:divide-slate-800/60">
                    {filteredGrades.map((grade) => {
                      const min = Number(grade.min_salary) || 0;
                      const max = Number(grade.max_salary) || 0;
                      const mid = grade.mid_salary ? Number(grade.mid_salary) : null;
                      const cur = grade.currency || 'IDR';

                      // Perhitungan visual bar relative to global max
                      const leftPct = Math.min(100, Math.max(0, (min / globalMaxSalary) * 100));
                      const widthPct = Math.min(100 - leftPct, Math.max(2, ((max - min) / globalMaxSalary) * 100));

                      return (
                        <tr key={grade.id} className="hover:bg-slate-50/80 dark:hover:bg-slate-800/40 transition">
                          <td className="py-3.5 px-4">
                            <div className="font-bold text-slate-900 dark:text-white flex items-center gap-1.5">
                              <span>{grade.name}</span>
                              {grade.code && (
                                <span className="text-[10px] font-mono px-1.5 py-0.5 rounded bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300">
                                  {grade.code}
                                </span>
                              )}
                            </div>
                            {grade.description && (
                              <p className="text-[11px] text-slate-400 mt-0.5 line-clamp-1">{grade.description}</p>
                            )}
                          </td>
                          <td className="py-3.5 px-4 text-slate-700 dark:text-slate-300">
                            {grade.job_level ? (
                              <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-lg bg-indigo-50 dark:bg-indigo-950/40 text-indigo-700 dark:text-indigo-300 font-medium">
                                Rank #{grade.job_level.rank} · {grade.job_level.name}
                              </span>
                            ) : (
                              <span className="text-slate-400 italic">Umum / Semua</span>
                            )}
                          </td>
                          <td className="py-3.5 px-4 text-right font-mono font-semibold text-slate-800 dark:text-slate-200">
                            {formatCurrency(min, cur)}
                          </td>
                          <td className="py-3.5 px-4 text-right font-mono text-slate-500 dark:text-slate-400">
                            {mid !== null ? formatCurrency(mid, cur) : <span className="text-slate-300">—</span>}
                          </td>
                          <td className="py-3.5 px-4 text-right font-mono font-semibold text-slate-800 dark:text-slate-200">
                            {formatCurrency(max, cur)}
                          </td>
                          <td className="py-3.5 px-4">
                            {/* Visualisasi Spektrum Rentang Upah */}
                            <div className="w-36 mx-auto">
                              <div className="w-full bg-slate-100 dark:bg-slate-800 h-2 rounded-full overflow-hidden relative">
                                <div
                                  className="h-full bg-linear-to-r from-indigo-500 to-emerald-500 rounded-full"
                                  style={{
                                    marginLeft: `${leftPct}%`,
                                    width: `${widthPct}%`,
                                  }}
                                  title={`Rentang: ${formatCurrency(min, cur)} – ${formatCurrency(max, cur)}`}
                                />
                              </div>
                              <div className="flex justify-between text-[9px] text-slate-400 font-mono mt-0.5">
                                <span>{min >= 1000000 ? `${(min / 1000000).toFixed(1)}jt` : min}</span>
                                <span>{max >= 1000000 ? `${(max / 1000000).toFixed(1)}jt` : max}</span>
                              </div>
                            </div>
                          </td>
                          <td className="py-3.5 px-4 text-center">
                            {grade.is_active ? (
                              <span className="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-emerald-50 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300">
                                Aktif
                              </span>
                            ) : (
                              <span className="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400">
                                Nonaktif
                              </span>
                            )}
                          </td>
                          {canManage && (
                            <td className="py-3.5 px-4 text-right">
                              <div className="flex items-center justify-end gap-1">
                                <button
                                  onClick={() => openEditGrade(grade)}
                                  className="p-1.5 rounded-lg text-slate-500 hover:text-indigo-600 hover:bg-slate-100 dark:hover:bg-slate-800 transition"
                                  title="Ubah Golongan"
                                >
                                  <Pencil className="w-3.5 h-3.5" />
                                </button>
                                <button
                                  onClick={() => setConfirmDeleteGrade(grade)}
                                  className="p-1.5 rounded-lg text-slate-500 hover:text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-950/30 transition"
                                  title="Hapus Golongan"
                                >
                                  <Trash2 className="w-3.5 h-3.5" />
                                </button>
                              </div>
                            </td>
                          )}
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            )}
          </div>
        </div>
      )}

      {/* ─────────────────────────────────────────────────────────────
          SUB-TAB 2: JENJANG JABATAN
      ───────────────────────────────────────────────────────────── */}
      {subTab === 'levels' && (
        <div className="space-y-4">
          <div className="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl overflow-hidden shadow-xs">
            {loading ? (
              <div className="py-16 text-center text-xs text-slate-400 flex flex-col items-center gap-2">
                <Loader2 className="w-6 h-6 animate-spin text-indigo-500" />
                <span>Memuat jenjang jabatan...</span>
              </div>
            ) : jobLevels.length === 0 ? (
              <div className="py-16 text-center text-xs text-slate-400">
                <Briefcase className="w-8 h-8 mx-auto mb-2 text-slate-300 dark:text-slate-600" />
                <p className="font-semibold text-slate-600 dark:text-slate-300">Belum ada jenjang jabatan</p>
                <p className="text-[11px] mt-0.5">Klik "Tambah Jenjang" untuk menyusun hierarki jabatan.</p>
              </div>
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full text-xs text-left">
                  <thead>
                    <tr className="border-b border-slate-100 dark:border-slate-800 text-slate-400 font-semibold bg-slate-50/50 dark:bg-slate-800/40">
                      <th className="py-3 px-4 w-20 text-center">Tingkat (Rank)</th>
                      <th className="py-3 px-4">Nama Jenjang</th>
                      <th className="py-3 px-4">Kode</th>
                      <th className="py-3 px-4 text-center">Golongan Terhubung</th>
                      <th className="py-3 px-4">Deskripsi</th>
                      <th className="py-3 px-4 text-center">Status</th>
                      {canManage && <th className="py-3 px-4 text-right">Aksi</th>}
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100 dark:divide-slate-800/60">
                    {jobLevels.map((lvl) => (
                      <tr key={lvl.id} className="hover:bg-slate-50/80 dark:hover:bg-slate-800/40 transition">
                        <td className="py-3 px-4 text-center font-mono font-bold text-indigo-600 dark:text-indigo-400">
                          #{lvl.rank}
                        </td>
                        <td className="py-3 px-4 font-bold text-slate-900 dark:text-white">
                          {lvl.name}
                        </td>
                        <td className="py-3 px-4 font-mono text-slate-600 dark:text-slate-300">
                          {lvl.code || '—'}
                        </td>
                        <td className="py-3 px-4 text-center">
                          <span className="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300">
                            {lvl.grades_count ?? 0} golongan
                          </span>
                        </td>
                        <td className="py-3 px-4 text-slate-500 dark:text-slate-400 max-w-xs truncate">
                          {lvl.description || '—'}
                        </td>
                        <td className="py-3 px-4 text-center">
                          {lvl.is_active ? (
                            <span className="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-emerald-50 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300">
                              Aktif
                            </span>
                          ) : (
                            <span className="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400">
                              Nonaktif
                            </span>
                          )}
                        </td>
                        {canManage && (
                          <td className="py-3 px-4 text-right">
                            <div className="flex items-center justify-end gap-1">
                              <button
                                onClick={() => openEditLevel(lvl)}
                                className="p-1.5 rounded-lg text-slate-500 hover:text-indigo-600 hover:bg-slate-100 dark:hover:bg-slate-800 transition"
                                title="Ubah Jenjang"
                              >
                                <Pencil className="w-3.5 h-3.5" />
                              </button>
                              <button
                                onClick={() => setConfirmDeleteLevel(lvl)}
                                className="p-1.5 rounded-lg text-slate-500 hover:text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-950/30 transition"
                                title="Hapus Jenjang"
                              >
                                <Trash2 className="w-3.5 h-3.5" />
                              </button>
                            </div>
                          </td>
                        )}
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </div>
        </div>
      )}

      {/* ─────────────────────────────────────────────────────────────
          MODAL: FORM JENJANG JABATAN
      ───────────────────────────────────────────────────────────── */}
      {showLevelModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-xs">
          <div className="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl p-5 w-full max-w-md shadow-2xl space-y-4">
            <div className="flex items-center justify-between pb-3 border-b border-slate-100 dark:border-slate-800">
              <h3 className="text-sm font-bold text-slate-900 dark:text-white flex items-center gap-2">
                <Briefcase className="w-4 h-4 text-indigo-500" />
                {editingLevel ? 'Ubah Jenjang Jabatan' : 'Tambah Jenjang Jabatan'}
              </h3>
              <button onClick={() => setShowLevelModal(false)} className="p-1 rounded-lg text-slate-400 hover:text-slate-600">
                <X className="w-4 h-4" />
              </button>
            </div>

            <form onSubmit={handleSaveLevel} className="space-y-3.5">
              <div>
                <label className="text-xs font-semibold text-slate-700 dark:text-slate-300">
                  Nama Jenjang <span className="text-rose-500">*</span>
                </label>
                <input
                  type="text"
                  placeholder="Contoh: Supervisor, Manajer, Staf"
                  value={levelForm.name}
                  onChange={(e) => setLevelForm({ ...levelForm, name: e.target.value })}
                  className={`${inputCls} mt-1`}
                  required
                />
              </div>

              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="text-xs font-semibold text-slate-700 dark:text-slate-300">Kode</label>
                  <input
                    type="text"
                    placeholder="Mis. SPV, MGR"
                    value={levelForm.code}
                    onChange={(e) => setLevelForm({ ...levelForm, code: e.target.value.toUpperCase() })}
                    className={`${inputCls} mt-1 font-mono uppercase`}
                    maxLength={20}
                  />
                </div>
                <div>
                  <label className="text-xs font-semibold text-slate-700 dark:text-slate-300">
                    Tingkat Hierarki (Rank) <span className="text-rose-500">*</span>
                  </label>
                  <input
                    type="number"
                    min={1}
                    max={999}
                    value={levelForm.rank}
                    onChange={(e) => setLevelForm({ ...levelForm, rank: Number(e.target.value) || 1 })}
                    className={`${inputCls} mt-1 font-mono`}
                    required
                  />
                  <p className="text-[10px] text-slate-400 mt-0.5">Semakin kecil = semakin awal di hierarki (mis. 1 = Entry)</p>
                </div>
              </div>

              <div>
                <label className="text-xs font-semibold text-slate-700 dark:text-slate-300">Deskripsi</label>
                <textarea
                  rows={2}
                  placeholder="Keterangan tanggung jawab jenjang..."
                  value={levelForm.description}
                  onChange={(e) => setLevelForm({ ...levelForm, description: e.target.value })}
                  className={`${inputCls} mt-1`}
                />
              </div>

              <div className="flex items-center gap-2 pt-1">
                <input
                  type="checkbox"
                  id="level_is_active"
                  checked={levelForm.is_active}
                  onChange={(e) => setLevelForm({ ...levelForm, is_active: e.target.checked })}
                  className="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500 cursor-pointer"
                />
                <label htmlFor="level_is_active" className="text-xs font-medium text-slate-700 dark:text-slate-300 cursor-pointer">
                  Status Jenjang Aktif
                </label>
              </div>

              <div className="flex justify-end gap-2 pt-3 border-t border-slate-100 dark:border-slate-800">
                <button
                  type="button"
                  onClick={() => setShowLevelModal(false)}
                  disabled={savingLevel}
                  className={btnGhost}
                >
                  Batal
                </button>
                <button type="submit" disabled={savingLevel} className={btnPrimary}>
                  {savingLevel ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <Save className="w-3.5 h-3.5" />}
                  <span>Simpan Jenjang</span>
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* ─────────────────────────────────────────────────────────────
          MODAL: FORM GOLONGAN UPAH
      ───────────────────────────────────────────────────────────── */}
      {showGradeModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-xs">
          <div className="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl p-5 w-full max-w-lg shadow-2xl space-y-4 max-h-[92vh] overflow-y-auto">
            <div className="flex items-center justify-between pb-3 border-b border-slate-100 dark:border-slate-800">
              <h3 className="text-sm font-bold text-slate-900 dark:text-white flex items-center gap-2">
                <Layers className="w-4 h-4 text-indigo-500" />
                {editingGrade ? 'Ubah Golongan Upah' : 'Tambah Golongan Upah'}
              </h3>
              <button onClick={() => setShowGradeModal(false)} className="p-1 rounded-lg text-slate-400 hover:text-slate-600">
                <X className="w-4 h-4" />
              </button>
            </div>

            <form onSubmit={handleSaveGrade} className="space-y-3.5">
              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="text-xs font-semibold text-slate-700 dark:text-slate-300">
                    Nama Golongan <span className="text-rose-500">*</span>
                  </label>
                  <input
                    type="text"
                    placeholder="Contoh: Golongan 1A, G-2B"
                    value={gradeForm.name}
                    onChange={(e) => setGradeForm({ ...gradeForm, name: e.target.value })}
                    className={`${inputCls} mt-1`}
                    required
                  />
                </div>
                <div>
                  <label className="text-xs font-semibold text-slate-700 dark:text-slate-300">Kode Golongan</label>
                  <input
                    type="text"
                    placeholder="Mis. G1A"
                    value={gradeForm.code}
                    onChange={(e) => setGradeForm({ ...gradeForm, code: e.target.value.toUpperCase() })}
                    className={`${inputCls} mt-1 font-mono uppercase`}
                    maxLength={20}
                  />
                </div>
              </div>

              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="text-xs font-semibold text-slate-700 dark:text-slate-300">Jenjang Jabatan Terkait</label>
                  <select
                    value={gradeForm.job_level_id}
                    onChange={(e) => setGradeForm({ ...gradeForm, job_level_id: e.target.value })}
                    className={`${inputCls} mt-1 cursor-pointer`}
                  >
                    <option value="">— Tanpa Jenjang Khusus —</option>
                    {jobLevels.map((lvl) => (
                      <option key={lvl.id} value={lvl.id}>
                        Rank #{lvl.rank} · {lvl.name}
                      </option>
                    ))}
                  </select>
                </div>
                <div>
                  <label className="text-xs font-semibold text-slate-700 dark:text-slate-300">Mata Uang</label>
                  <select
                    value={gradeForm.currency}
                    onChange={(e) => setGradeForm({ ...gradeForm, currency: e.target.value })}
                    className={`${inputCls} mt-1 cursor-pointer font-mono font-bold`}
                  >
                    <option value="IDR">IDR (Rupiah)</option>
                    <option value="USD">USD (Dolar AS)</option>
                    <option value="SGD">SGD (Dolar Singapura)</option>
                    <option value="EUR">EUR (Euro)</option>
                    <option value="JPY">JPY (Yen Jepang)</option>
                  </select>
                </div>
              </div>

              {/* Rambu Rentang Gaji (Min - Mid - Max) */}
              <div className="p-3.5 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200/80 dark:border-slate-700/80 space-y-3">
                <span className="text-[11px] font-bold text-slate-700 dark:text-slate-200 flex items-center gap-1.5">
                  <ShieldCheck className="w-3.5 h-3.5 text-indigo-500" />
                  Rambu Rentang Upah Pokok ({gradeForm.currency})
                </span>

                <div className="grid grid-cols-3 gap-2.5">
                  <div>
                    <label className="text-[10px] font-semibold text-slate-500">
                      Upah Min <span className="text-rose-500">*</span>
                    </label>
                    <input
                      type="number"
                      min={0}
                      step={1000}
                      placeholder="0"
                      value={gradeForm.min_salary}
                      onChange={(e) => setGradeForm({ ...gradeForm, min_salary: e.target.value })}
                      className={`${inputCls} font-mono mt-0.5`}
                      required
                    />
                  </div>
                  <div>
                    <label className="text-[10px] font-semibold text-slate-500">Titik Tengah (Mid)</label>
                    <input
                      type="number"
                      min={0}
                      step={1000}
                      placeholder="Opsional"
                      value={gradeForm.mid_salary}
                      onChange={(e) => setGradeForm({ ...gradeForm, mid_salary: e.target.value })}
                      className={`${inputCls} font-mono mt-0.5`}
                    />
                  </div>
                  <div>
                    <label className="text-[10px] font-semibold text-slate-500">
                      Upah Maks <span className="text-rose-500">*</span>
                    </label>
                    <input
                      type="number"
                      min={0}
                      step={1000}
                      placeholder="0"
                      value={gradeForm.max_salary}
                      onChange={(e) => setGradeForm({ ...gradeForm, max_salary: e.target.value })}
                      className={`${inputCls} font-mono mt-0.5`}
                      required
                    />
                  </div>
                </div>

                {/* Error Banner Inline */}
                {(gradeValidation.minMaxError || gradeValidation.midError) && (
                  <div className="p-2 rounded-lg bg-rose-50 dark:bg-rose-950/40 border border-rose-200 dark:border-rose-900/60 text-rose-700 dark:text-rose-300 text-[11px] flex items-center gap-1.5">
                    <AlertTriangle className="w-3.5 h-3.5 shrink-0" />
                    <span>{gradeValidation.minMaxError || gradeValidation.midError}</span>
                  </div>
                )}

                <p className="text-[10px] text-slate-400 leading-relaxed">
                  Penetapan gaji karyawan dengan golongan ini akan divalidasi ketat terhadap rentang ini.
                </p>
              </div>

              <div>
                <label className="text-xs font-semibold text-slate-700 dark:text-slate-300">Deskripsi / Catatan</label>
                <textarea
                  rows={2}
                  placeholder="Keterangan kualifikasi atau penempatan golongan..."
                  value={gradeForm.description}
                  onChange={(e) => setGradeForm({ ...gradeForm, description: e.target.value })}
                  className={`${inputCls} mt-1`}
                />
              </div>

              <div className="flex items-center gap-2 pt-1">
                <input
                  type="checkbox"
                  id="grade_is_active"
                  checked={gradeForm.is_active}
                  onChange={(e) => setGradeForm({ ...gradeForm, is_active: e.target.checked })}
                  className="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500 cursor-pointer"
                />
                <label htmlFor="grade_is_active" className="text-xs font-medium text-slate-700 dark:text-slate-300 cursor-pointer">
                  Status Golongan Aktif (Dapat dipilih pada penetapan gaji)
                </label>
              </div>

              <div className="flex justify-end gap-2 pt-3 border-t border-slate-100 dark:border-slate-800">
                <button
                  type="button"
                  onClick={() => setShowGradeModal(false)}
                  disabled={savingGrade}
                  className={btnGhost}
                >
                  Batal
                </button>
                <button
                  type="submit"
                  disabled={savingGrade || !gradeValidation.isValid}
                  className={btnPrimary}
                >
                  {savingGrade ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <Save className="w-3.5 h-3.5" />}
                  <span>Simpan Golongan</span>
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* ─────────────────────────────────────────────────────────────
          CONFIRMATION DIALOGS: Hapus & Penawaran Nonaktif
      ───────────────────────────────────────────────────────────── */}
      {confirmDeleteLevel && (
        <ConfirmationDialog
          isOpen
          title="Hapus Jenjang Jabatan"
          message={`Apakah Anda yakin ingin menghapus jenjang jabatan "${confirmDeleteLevel.name}"? Jika masih digunakan oleh golongan upah atau riwayat gaji karyawan, penghapusan akan dicegah sistem.`}
          confirmText="Ya, Hapus"
          type="danger"
          onConfirm={() => handleDeleteLevel(confirmDeleteLevel)}
          onClose={() => setConfirmDeleteLevel(null)}
        />
      )}

      {deactivateOfferLevel && (
        <ConfirmationDialog
          isOpen
          title="Nonaktifkan Jenjang Jabatan"
          message={`Jenjang jabatan "${deactivateOfferLevel.name}" tidak dapat dihapus karena masih terkait dengan golongan upah atau riwayat gaji karyawan. Apakah Anda ingin menonaktifkannya saja agar tidak dapat digunakan untuk penugasan baru?`}
          confirmText="Ya, Nonaktifkan"
          type="warning"
          onConfirm={() => handleDeactivateLevel(deactivateOfferLevel)}
          onClose={() => setDeactivateOfferLevel(null)}
        />
      )}

      {confirmDeleteGrade && (
        <ConfirmationDialog
          isOpen
          title="Hapus Golongan Upah"
          message={`Apakah Anda yakin ingin menghapus golongan upah "${confirmDeleteGrade.name}"? Jika sudah melekat pada riwayat gaji karyawan, penghapusan akan dicegah demi integritas jejak audit.`}
          confirmText="Ya, Hapus"
          type="danger"
          onConfirm={() => handleDeleteGrade(confirmDeleteGrade)}
          onClose={() => setConfirmDeleteGrade(null)}
        />
      )}

      {deactivateOfferGrade && (
        <ConfirmationDialog
          isOpen
          title="Nonaktifkan Golongan Upah"
          message={`Golongan upah "${deactivateOfferGrade.name}" tidak dapat dihapus karena melekat pada riwayat gaji karyawan. Apakah Anda ingin menonaktifkannya saja?`}
          confirmText="Ya, Nonaktifkan"
          type="warning"
          onConfirm={() => handleDeactivateGrade(deactivateOfferGrade)}
          onClose={() => setDeactivateOfferGrade(null)}
        />
      )}
    </div>
  );
};

export default StrukturSkalaUpah;
