import React, { useState, useEffect, useCallback, useMemo } from 'react';
import {
  Coins, Plus, Pencil, Trash2, X, RefreshCw, Check, CheckCircle2,
  AlertCircle, AlertTriangle, ShieldCheck, ChevronRight, Info, Save,
  Loader2, ArrowUpDown, Sliders, Globe, Building2, Lock, Search, Filter
} from 'lucide-react';
import { payrollApi } from '../services/endpoints';
import type { CurrencyRate } from '../types';
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

const formatRate6 = (val: string | number | undefined | null) => {
  if (val === undefined || val === null || val === '') return '0,000000';
  const num = Number(val);
  if (isNaN(num)) return String(val);
  return new Intl.NumberFormat('id-ID', {
    minimumFractionDigits: 6,
    maximumFractionDigits: 6,
  }).format(num);
};

const formatDateIndo = (dateStr: string) => {
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

const COMMON_CURRENCIES = [
  { code: 'USD', name: 'US Dollar ($)' },
  { code: 'SGD', name: 'Singapore Dollar (S$)' },
  { code: 'EUR', name: 'Euro (€)' },
  { code: 'JPY', name: 'Japanese Yen (¥)' },
  { code: 'GBP', name: 'British Pound (£)' },
  { code: 'AUD', name: 'Australian Dollar (A$)' },
  { code: 'CNY', name: 'Chinese Yuan (¥)' },
  { code: 'MYR', name: 'Malaysian Ringgit (RM)' },
];

export const MasterKursValas: React.FC<Props> = ({ canManage }) => {
  const [rates, setRates] = useState<CurrencyRate[]>([]);
  const [loading, setLoading] = useState(true);
  const [banner, setBanner] = useState<{ type: 'success' | 'error' | 'info'; text: string } | null>(null);

  // Filters
  const [currencyFilter, setCurrencyFilter] = useState('');
  const [searchQuery, setSearchQuery] = useState('');
  const [scopeFilter, setScopeFilter] = useState<'all' | 'company' | 'global'>('all');

  // Create Modal
  const [showCreateModal, setShowCreateModal] = useState(false);
  const [createForm, setCreateForm] = useState({
    currency: 'USD',
    customCurrency: '',
    rate_to_idr: '',
    effective_date: new Date().toISOString().slice(0, 10),
    source: 'KMK',
  });
  const [creating, setCreating] = useState(false);

  // Edit Modal
  const [editingRate, setEditingRate] = useState<CurrencyRate | null>(null);
  const [editForm, setEditForm] = useState({
    rate_to_idr: '',
    source: '',
  });
  const [updating, setUpdating] = useState(false);

  // Delete Dialog
  const [deletingRate, setDeletingRate] = useState<CurrencyRate | null>(null);
  const [deleting, setDeleting] = useState(false);

  const fetchRates = useCallback(async () => {
    setLoading(true);
    try {
      const res = await payrollApi.listCurrencyRates(
        currencyFilter ? { currency: currencyFilter.toUpperCase() } : undefined
      );
      setRates(res?.data ?? []);
    } catch (e: any) {
      setBanner({ type: 'error', text: errMsg(e, 'Gagal memuat daftar master kurs valuta asing.') });
    } finally {
      setLoading(false);
    }
  }, [currencyFilter]);

  useEffect(() => {
    fetchRates();
  }, [fetchRates]);

  const filteredRates = useMemo(() => {
    return rates.filter((r) => {
      if (scopeFilter !== 'all' && r.scope !== scopeFilter) return false;
      if (searchQuery.trim()) {
        const q = searchQuery.toLowerCase();
        const curMatch = r.currency.toLowerCase().includes(q);
        const srcMatch = (r.source || '').toLowerCase().includes(q);
        const rateMatch = String(r.rate_to_idr).includes(q);
        if (!curMatch && !srcMatch && !rateMatch) return false;
      }
      return true;
    });
  }, [rates, scopeFilter, searchQuery]);

  const handleOpenCreate = () => {
    setCreateForm({
      currency: 'USD',
      customCurrency: '',
      rate_to_idr: '',
      effective_date: new Date().toISOString().slice(0, 10),
      source: 'KMK',
    });
    setShowCreateModal(true);
  };

  const handleCreate = async (e: React.FormEvent) => {
    e.preventDefault();
    const finalCurrency = (
      createForm.currency === 'OTHER' ? createForm.customCurrency : createForm.currency
    ).trim().toUpperCase();

    if (!finalCurrency || finalCurrency.length !== 3) {
      setBanner({ type: 'error', text: 'Kode mata uang harus 3 karakter (misal: USD, SGD).' });
      return;
    }

    if (finalCurrency === 'IDR') {
      setBanner({
        type: 'error',
        text: 'IDR adalah mata uang basis — kursnya selalu 1 dan tidak perlu diisi dalam master kurs.',
      });
      return;
    }

    const rateNum = parseFloat(createForm.rate_to_idr.replace(/,/g, '.'));
    if (isNaN(rateNum) || rateNum <= 0) {
      setBanner({ type: 'error', text: 'Nilai kurs ke IDR harus berupa angka positif lebih dari 0.' });
      return;
    }

    if (!createForm.effective_date) {
      setBanner({ type: 'error', text: 'Tanggal berlaku wajib diisi.' });
      return;
    }

    setCreating(true);
    try {
      await payrollApi.createCurrencyRate({
        currency: finalCurrency,
        rate_to_idr: rateNum,
        effective_date: createForm.effective_date,
        source: createForm.source.trim() || undefined,
      });
      setBanner({ type: 'success', text: `Kurs ${finalCurrency} berhasil ditambahkan.` });
      setShowCreateModal(false);
      fetchRates();
    } catch (e: any) {
      setBanner({ type: 'error', text: errMsg(e, 'Gagal menambahkan kurs valuta asing.') });
    } finally {
      setCreating(false);
    }
  };

  const handleOpenEdit = (rate: CurrencyRate) => {
    if (!rate.is_editable) {
      setBanner({ type: 'error', text: 'Baris kurs acuan global bersifat read-only dan tidak dapat diubah.' });
      return;
    }
    setEditingRate(rate);
    setEditForm({
      rate_to_idr: String(rate.rate_to_idr),
      source: rate.source || '',
    });
  };

  const handleUpdate = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!editingRate) return;

    const rateNum = parseFloat(editForm.rate_to_idr.replace(/,/g, '.'));
    if (isNaN(rateNum) || rateNum <= 0) {
      setBanner({ type: 'error', text: 'Nilai kurs ke IDR harus berupa angka positif lebih dari 0.' });
      return;
    }

    setUpdating(true);
    try {
      await payrollApi.updateCurrencyRate(editingRate.id, {
        rate_to_idr: rateNum,
        source: editForm.source.trim() || undefined,
      });
      setBanner({ type: 'success', text: `Kurs ${editingRate.currency} berhasil diperbarui.` });
      setEditingRate(null);
      fetchRates();
    } catch (e: any) {
      setBanner({ type: 'error', text: errMsg(e, 'Gagal memperbarui kurs.') });
    } finally {
      setUpdating(false);
    }
  };

  const handleDelete = async () => {
    if (!deletingRate) return;
    setDeleting(true);
    try {
      await payrollApi.deleteCurrencyRate(deletingRate.id);
      setBanner({ type: 'success', text: `Kurs ${deletingRate.currency} berhasil dihapus.` });
      setDeletingRate(null);
      fetchRates();
    } catch (e: any) {
      setBanner({ type: 'error', text: errMsg(e, 'Gagal menghapus kurs.') });
    } finally {
      setDeleting(false);
    }
  };

  return (
    <div className="space-y-6">
      {/* Alert / Banner */}
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

      {/* Header Info & Rate Lock Notice */}
      <div className="flex flex-col md:flex-row md:items-center justify-between gap-4 bg-gradient-to-br from-indigo-50/50 via-slate-50/50 to-white dark:from-slate-900/60 dark:via-slate-900/40 dark:to-slate-950 border border-slate-200/80 dark:border-slate-800/80 rounded-2xl p-5 shadow-sm">
        <div className="space-y-1">
          <div className="flex items-center gap-2">
            <span className="p-2 rounded-xl bg-indigo-100 dark:bg-indigo-950/60 text-indigo-600 dark:text-indigo-400">
              <Coins className="w-5 h-5" />
            </span>
            <div>
              <h3 className="text-sm font-bold text-slate-900 dark:text-white flex items-center gap-2">
                Master Kurs Valuta Asing (Effective-Dated)
              </h3>
              <p className="text-xs text-slate-500 dark:text-slate-400">
                Pencatatan kurs multi-mata uang dengan presisi 6 desimal untuk perhitungan gaji dan pajak ekspatriat.
              </p>
            </div>
          </div>
        </div>

        {canManage && (
          <button
            type="button"
            onClick={handleOpenCreate}
            className={btnPrimary}
          >
            <Plus className="w-4 h-4" />
            <span>Tambah Kurs Baru</span>
          </button>
        )}
      </div>

      {/* Rule Notice Banner */}
      <div className="flex items-start gap-3 rounded-xl bg-amber-50/80 dark:bg-amber-950/20 border border-amber-200/80 dark:border-amber-900/40 p-3.5 text-xs text-amber-800 dark:text-amber-300">
        <Lock className="w-4 h-4 mt-0.5 shrink-0 text-amber-600 dark:text-amber-400" />
        <div className="leading-relaxed">
          <strong className="font-semibold">Ketentuan Rate Lock:</strong> Kurs yang dipakai terkunci ke dalam batch saat kalkulasi payroll dijalankan. Menambahkan atau mengubah kurs baru <em>tidak akan mengubah</em> nilai slip pada batch yang sudah berstatus tersimpan atau disetujui.
        </div>
      </div>

      {/* Filter Bar */}
      <div className="flex flex-wrap items-center justify-between gap-3 bg-white dark:bg-slate-900 p-3 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-sm">
        <div className="flex flex-wrap items-center gap-2 flex-1 min-w-[280px]">
          {/* Search Box */}
          <div className="relative flex-1 max-w-xs">
            <Search className="w-3.5 h-3.5 absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
            <input
              type="text"
              placeholder="Cari mata uang, sumber..."
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              className={`${inputCls} pl-8 py-1.5`}
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

          {/* Scope Filter */}
          <div className="flex items-center rounded-xl bg-slate-100 dark:bg-slate-800 p-1 text-xs">
            <button
              type="button"
              onClick={() => setScopeFilter('all')}
              className={`px-3 py-1 rounded-lg font-medium transition cursor-pointer ${
                scopeFilter === 'all'
                  ? 'bg-white dark:bg-slate-900 text-slate-900 dark:text-white shadow-xs'
                  : 'text-slate-500 hover:text-slate-700 dark:text-slate-400'
              }`}
            >
              Semua ({rates.length})
            </button>
            <button
              type="button"
              onClick={() => setScopeFilter('company')}
              className={`px-3 py-1 rounded-lg font-medium transition cursor-pointer ${
                scopeFilter === 'company'
                  ? 'bg-white dark:bg-slate-900 text-slate-900 dark:text-white shadow-xs'
                  : 'text-slate-500 hover:text-slate-700 dark:text-slate-400'
              }`}
            >
              Perusahaan ({rates.filter((r) => r.scope === 'company').length})
            </button>
            <button
              type="button"
              onClick={() => setScopeFilter('global')}
              className={`px-3 py-1 rounded-lg font-medium transition cursor-pointer ${
                scopeFilter === 'global'
                  ? 'bg-white dark:bg-slate-900 text-slate-900 dark:text-white shadow-xs'
                  : 'text-slate-500 hover:text-slate-700 dark:text-slate-400'
              }`}
            >
              Global ({rates.filter((r) => r.scope === 'global').length})
            </button>
          </div>
        </div>

        <div className="flex items-center gap-2">
          <button
            type="button"
            onClick={fetchRates}
            disabled={loading}
            className={btnGhost}
            title="Muat ulang data kurs"
          >
            <RefreshCw className={`w-3.5 h-3.5 ${loading ? 'animate-spin' : ''}`} />
            <span>Segarkan</span>
          </button>
        </div>
      </div>

      {/* Rates Table */}
      <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-sm overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full text-left text-xs">
            <thead className="bg-slate-50 dark:bg-slate-800/70 border-b border-slate-200 dark:border-slate-800 text-slate-500 dark:text-slate-400 font-semibold uppercase tracking-wider">
              <tr>
                <th className="py-3 px-4">Mata Uang</th>
                <th className="py-3 px-4">Kurs ke IDR (6 Desimal)</th>
                <th className="py-3 px-4">Tanggal Berlaku</th>
                <th className="py-3 px-4">Sumber Kurs</th>
                <th className="py-3 px-4">Cakupan (Scope)</th>
                <th className="py-3 px-4 text-right">Aksi</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100 dark:divide-slate-800 text-slate-700 dark:text-slate-300">
              {loading ? (
                <tr>
                  <td colSpan={6} className="py-12 text-center text-slate-400">
                    <Loader2 className="w-6 h-6 animate-spin mx-auto mb-2 text-indigo-500" />
                    <span>Memuat master kurs valuta asing...</span>
                  </td>
                </tr>
              ) : filteredRates.length === 0 ? (
                <tr>
                  <td colSpan={6} className="py-12 text-center text-slate-400">
                    <Coins className="w-8 h-8 mx-auto mb-2 text-slate-300 dark:text-slate-600" />
                    <p className="font-semibold text-slate-600 dark:text-slate-300">Belum ada data kurs valuta asing</p>
                    <p className="text-xs text-slate-400 mt-1">
                      {searchQuery ? 'Tidak ada kurs yang cocok dengan kriteria pencarian.' : 'Klik tombol Tambah Kurs Baru untuk menambahkan kurs pertama.'}
                    </p>
                  </td>
                </tr>
              ) : (
                filteredRates.map((rate) => {
                  const isGlobal = rate.scope === 'global';
                  return (
                    <tr
                      key={rate.id}
                      className="hover:bg-slate-50/80 dark:hover:bg-slate-800/40 transition-colors"
                    >
                      {/* Currency */}
                      <td className="py-3 px-4 font-bold text-slate-900 dark:text-white">
                        <div className="flex items-center gap-2">
                          <span className="w-8 h-8 rounded-xl bg-indigo-50 dark:bg-indigo-950/60 border border-indigo-200 dark:border-indigo-800 text-indigo-700 dark:text-indigo-300 flex items-center justify-center font-mono text-xs font-bold">
                            {rate.currency}
                          </span>
                          <span>{rate.currency}</span>
                        </div>
                      </td>

                      {/* Rate to IDR */}
                      <td className="py-3 px-4 font-mono font-medium text-slate-900 dark:text-white">
                        <span className="text-slate-400 text-[10px] mr-1">Rp</span>
                        <span className="text-indigo-600 dark:text-indigo-400 font-bold">
                          {formatRate6(rate.rate_to_idr)}
                        </span>
                      </td>

                      {/* Effective Date */}
                      <td className="py-3 px-4 text-slate-600 dark:text-slate-400">
                        {formatDateIndo(rate.effective_date)}
                      </td>

                      {/* Source */}
                      <td className="py-3 px-4">
                        <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-[11px] font-medium bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300">
                          {rate.source || 'KMK'}
                        </span>
                      </td>

                      {/* Scope Badge */}
                      <td className="py-3 px-4">
                        {isGlobal ? (
                          <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-medium bg-sky-50 dark:bg-sky-950/40 text-sky-700 dark:text-sky-300 border border-sky-200 dark:border-sky-800">
                            <Globe className="w-3 h-3 text-sky-500" />
                            Global (Acuan)
                          </span>
                        ) : (
                          <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-medium bg-emerald-50 dark:bg-emerald-950/40 text-emerald-700 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800">
                            <Building2 className="w-3 h-3 text-emerald-500" />
                            Perusahaan
                          </span>
                        )}
                      </td>

                      {/* Actions */}
                      <td className="py-3 px-4 text-right">
                        {rate.is_editable && canManage ? (
                          <div className="inline-flex items-center gap-1.5">
                            <button
                              type="button"
                              onClick={() => handleOpenEdit(rate)}
                              className="p-1.5 rounded-lg text-slate-500 hover:text-indigo-600 hover:bg-indigo-50 dark:hover:bg-indigo-950/60 transition cursor-pointer"
                              title="Ubah nilai kurs & sumber"
                            >
                              <Pencil className="w-3.5 h-3.5" />
                            </button>
                            <button
                              type="button"
                              onClick={() => setDeletingRate(rate)}
                              className="p-1.5 rounded-lg text-slate-500 hover:text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-950/60 transition cursor-pointer"
                              title="Hapus kurs"
                            >
                              <Trash2 className="w-3.5 h-3.5" />
                            </button>
                          </div>
                        ) : (
                          <span
                            className="inline-flex items-center gap-1 text-[11px] text-slate-400 font-medium px-2 py-0.5 rounded-lg bg-slate-100/70 dark:bg-slate-800/70"
                            title="Kurs global acuan sistem hanya dapat dibaca"
                          >
                            <Lock className="w-3 h-3" />
                            Read-Only
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

      {/* CREATE MODAL */}
      {showCreateModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-xs">
          <div className="w-full max-w-md bg-white dark:bg-slate-900 rounded-3xl border border-slate-200 dark:border-slate-800 shadow-2xl overflow-hidden animate-in fade-in zoom-in-95 duration-150">
            <div className="flex items-center justify-between px-6 py-4 border-b border-slate-100 dark:border-slate-800">
              <div className="flex items-center gap-2">
                <span className="p-2 rounded-xl bg-indigo-50 dark:bg-indigo-950/50 text-indigo-600 dark:text-indigo-400">
                  <Coins className="w-4 h-4" />
                </span>
                <h3 className="font-bold text-sm text-slate-900 dark:text-white">
                  Tambah Master Kurs Valas
                </h3>
              </div>
              <button
                type="button"
                onClick={() => setShowCreateModal(false)}
                className="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 cursor-pointer"
              >
                <X className="w-4 h-4" />
              </button>
            </div>

            <form onSubmit={handleCreate} className="p-6 space-y-4 text-xs">
              {/* Currency Selector */}
              <div>
                <label className="block font-semibold text-slate-700 dark:text-slate-300 mb-1">
                  Mata Uang <span className="text-rose-500">*</span>
                </label>
                <select
                  value={createForm.currency}
                  onChange={(e) => setCreateForm({ ...createForm, currency: e.target.value })}
                  className={inputCls}
                >
                  {COMMON_CURRENCIES.map((c) => (
                    <option key={c.code} value={c.code}>
                      {c.code} - {c.name}
                    </option>
                  ))}
                  <option value="OTHER">Mata Uang Lainnya (Ketik Manual)...</option>
                </select>
              </div>

              {createForm.currency === 'OTHER' && (
                <div>
                  <label className="block font-semibold text-slate-700 dark:text-slate-300 mb-1">
                    Kode Mata Uang (3 Huruf) <span className="text-rose-500">*</span>
                  </label>
                  <input
                    type="text"
                    maxLength={3}
                    placeholder="misal: CAD, KRW"
                    value={createForm.customCurrency}
                    onChange={(e) =>
                      setCreateForm({ ...createForm, customCurrency: e.target.value.toUpperCase() })
                    }
                    className={`${inputCls} uppercase font-mono`}
                    required
                  />
                  <p className="text-[11px] text-slate-400 mt-1">
                    IDR tidak diperbolehkan karena merupakan mata uang basis.
                  </p>
                </div>
              )}

              {/* Rate to IDR */}
              <div>
                <label className="block font-semibold text-slate-700 dark:text-slate-300 mb-1">
                  Kurs ke Rupiah (IDR) <span className="text-rose-500">*</span>
                </label>
                <div className="relative">
                  <span className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 font-medium">
                    Rp
                  </span>
                  <input
                    type="number"
                    step="0.000001"
                    min="0.000001"
                    placeholder="misal: 16250.500000"
                    value={createForm.rate_to_idr}
                    onChange={(e) => setCreateForm({ ...createForm, rate_to_idr: e.target.value })}
                    className={`${inputCls} pl-9 font-mono font-medium`}
                    required
                  />
                </div>
                <p className="text-[11px] text-slate-400 mt-1">
                  Mendukung ketelitian hingga 6 desimal (contoh: 16000.000000).
                </p>
              </div>

              {/* Effective Date */}
              <div>
                <label className="block font-semibold text-slate-700 dark:text-slate-300 mb-1">
                  Tanggal Berlaku <span className="text-rose-500">*</span>
                </label>
                <input
                  type="date"
                  value={createForm.effective_date}
                  onChange={(e) => setCreateForm({ ...createForm, effective_date: e.target.value })}
                  className={inputCls}
                  required
                />
                <p className="text-[11px] text-slate-400 mt-1">
                  Sistem otomatis memilih kurs berlaku terakhir ≤ tanggal kalkulasi payroll.
                </p>
              </div>

              {/* Source */}
              <div>
                <label className="block font-semibold text-slate-700 dark:text-slate-300 mb-1">
                  Sumber Kurs
                </label>
                <input
                  type="text"
                  placeholder="KMK, BI Tengah, Bank Mandiri, dsb."
                  value={createForm.source}
                  onChange={(e) => setCreateForm({ ...createForm, source: e.target.value })}
                  className={inputCls}
                />
              </div>

              <div className="pt-2 flex items-center justify-end gap-2 border-t border-slate-100 dark:border-slate-800">
                <button
                  type="button"
                  onClick={() => setShowCreateModal(false)}
                  disabled={creating}
                  className={btnGhost}
                >
                  Batal
                </button>
                <button
                  type="submit"
                  disabled={creating}
                  className={btnPrimary}
                >
                  {creating && <Loader2 className="w-3.5 h-3.5 animate-spin" />}
                  <span>Simpan Kurs</span>
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* EDIT MODAL */}
      {editingRate && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-xs">
          <div className="w-full max-w-md bg-white dark:bg-slate-900 rounded-3xl border border-slate-200 dark:border-slate-800 shadow-2xl overflow-hidden animate-in fade-in zoom-in-95 duration-150">
            <div className="flex items-center justify-between px-6 py-4 border-b border-slate-100 dark:border-slate-800">
              <div className="flex items-center gap-2">
                <span className="p-2 rounded-xl bg-indigo-50 dark:bg-indigo-950/50 text-indigo-600 dark:text-indigo-400">
                  <Pencil className="w-4 h-4" />
                </span>
                <h3 className="font-bold text-sm text-slate-900 dark:text-white">
                  Ubah Kurs {editingRate.currency}
                </h3>
              </div>
              <button
                type="button"
                onClick={() => setEditingRate(null)}
                className="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 cursor-pointer"
              >
                <X className="w-4 h-4" />
              </button>
            </div>

            <form onSubmit={handleUpdate} className="p-6 space-y-4 text-xs">
              <div className="bg-slate-50 dark:bg-slate-800/50 p-3.5 rounded-xl border border-slate-200/60 dark:border-slate-700/60 space-y-1">
                <div className="flex justify-between text-slate-500">
                  <span>Mata Uang:</span>
                  <span className="font-bold font-mono text-slate-900 dark:text-white">{editingRate.currency}</span>
                </div>
                <div className="flex justify-between text-slate-500">
                  <span>Tanggal Berlaku:</span>
                  <span className="font-medium text-slate-900 dark:text-white">{formatDateIndo(editingRate.effective_date)}</span>
                </div>
                <p className="text-[10px] text-slate-400 pt-1">
                  Mata uang dan tanggal berlaku tidak dapat diubah. Buat baris baru bila ada perubahan tanggal berlaku.
                </p>
              </div>

              {/* Rate to IDR */}
              <div>
                <label className="block font-semibold text-slate-700 dark:text-slate-300 mb-1">
                  Kurs ke Rupiah (IDR) <span className="text-rose-500">*</span>
                </label>
                <div className="relative">
                  <span className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 font-medium">
                    Rp
                  </span>
                  <input
                    type="number"
                    step="0.000001"
                    min="0.000001"
                    value={editForm.rate_to_idr}
                    onChange={(e) => setEditForm({ ...editForm, rate_to_idr: e.target.value })}
                    className={`${inputCls} pl-9 font-mono font-medium`}
                    required
                  />
                </div>
              </div>

              {/* Source */}
              <div>
                <label className="block font-semibold text-slate-700 dark:text-slate-300 mb-1">
                  Sumber Kurs
                </label>
                <input
                  type="text"
                  value={editForm.source}
                  onChange={(e) => setEditForm({ ...editForm, source: e.target.value })}
                  className={inputCls}
                />
              </div>

              <div className="pt-2 flex items-center justify-end gap-2 border-t border-slate-100 dark:border-slate-800">
                <button
                  type="button"
                  onClick={() => setEditingRate(null)}
                  disabled={updating}
                  className={btnGhost}
                >
                  Batal
                </button>
                <button
                  type="submit"
                  disabled={updating}
                  className={btnPrimary}
                >
                  {updating && <Loader2 className="w-3.5 h-3.5 animate-spin" />}
                  <span>Simpan Perubahan</span>
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* DELETE CONFIRMATION */}
      {deletingRate && (
        <ConfirmationDialog
          isOpen={true}
          title="Hapus Master Kurs"
          message={`Apakah Anda yakin ingin menghapus kurs ${deletingRate.currency} untuk tanggal berlaku ${formatDateIndo(deletingRate.effective_date)}? Tindakan ini tidak dapat dibatalkan.`}
          confirmLabel={deleting ? 'Menghapus...' : 'Hapus Kurs'}
          confirmVariant="danger"
          isLoading={deleting}
          onConfirm={handleDelete}
          onCancel={() => setDeletingRate(null)}
        />
      )}
    </div>
  );
};
