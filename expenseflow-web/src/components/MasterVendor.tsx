import React, { useState, useMemo, useEffect } from 'react';
import {
  Building,
  Plus,
  Info,
  Check,
  X,
  Search,
  Edit2,
  Download,
  AlertCircle
} from 'lucide-react';
import { ConfirmationDialog } from './ConfirmationDialog';
import { vendorApi } from '../services/endpoints';
import { ApiError } from '../services/api';

interface Vendor {
  id: string; // id numerik backend (string)
  nama: string;
  npwp: string;
  alamat: string;
  bank: string;
  noRekening: string;
  atasNama: string;
  totalInvoice: number;
  status: 'Aktif' | 'Nonaktif';
}

// Petakan respons backend → bentuk Vendor lokal.
function mapVendor(v: any): Vendor {
  return {
    id: String(v.id),
    nama: v.name ?? '',
    npwp: v.tax_id ?? '',
    alamat: v.address ?? '',
    bank: v.bank_name ?? '',
    noRekening: v.bank_account_no ?? '',
    atasNama: v.bank_account_name ?? '',
    totalInvoice: v.invoices_count ?? 0,
    status: v.is_active === false ? 'Nonaktif' : 'Aktif',
  };
}

export const MasterVendor: React.FC<{
  onAddAuditLog: (title: string, details: string, bg: string) => void;
  onAddNotification: (type: 'due' | 'flag' | 'new' | 'success', title: string, subtitle: string) => void;
}> = ({ onAddAuditLog, onAddNotification }) => {
  const [vendors, setVendors] = useState<Vendor[]>([]);
  const [loading, setLoading] = useState<boolean>(true);
  const [errorMsg, setErrorMsg] = useState<string | null>(null);

  // Muat daftar vendor dari backend.
  const loadVendors = async () => {
    setLoading(true);
    setErrorMsg(null);
    try {
      const res: any = await vendorApi.list();
      const list = Array.isArray(res?.data) ? res.data : Array.isArray(res) ? res : [];
      setVendors(list.map(mapVendor));
    } catch (e: any) {
      setErrorMsg(e?.message ?? 'Gagal memuat vendor.');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    loadVendors();
  }, []);

  // States
  const [searchQuery, setSearchQuery] = useState<string>('');
  const [statusFilter, setStatusFilter] = useState<'all' | 'Aktif' | 'Nonaktif'>('all');
  
  // Modals Controller
  const [showAddModal, setShowAddModal] = useState<boolean>(false);
  const [editVendor, setEditVendor] = useState<Vendor | null>(null);

  // Form State
  const [vendorForm, setVendorForm] = useState({
    nama: '',
    npwp: '',
    alamat: '',
    bank: 'Bank BCA',
    noRekening: '',
    atasNama: '',
    status: 'Aktif' as 'Aktif' | 'Nonaktif'
  });

  const [confirmDialog, setConfirmDialog] = useState<{
    isOpen: boolean;
    title: string;
    message: string | React.ReactNode;
    confirmText?: string;
    type: 'danger' | 'warning' | 'success' | 'info';
    onConfirm: () => void;
  } | null>(null);

  // Filter and search
  const filteredVendors = useMemo(() => {
    return vendors.filter(v => {
      const matchSearch = v.nama.toLowerCase().includes(searchQuery.toLowerCase()) || 
                          v.npwp.toLowerCase().includes(searchQuery.toLowerCase()) ||
                          v.bank.toLowerCase().includes(searchQuery.toLowerCase());
      const matchStatus = statusFilter === 'all' || v.status === statusFilter;
      return matchSearch && matchStatus;
    });
  }, [vendors, searchQuery, statusFilter]);

  const [submitting, setSubmitting] = useState(false);

  // Susun payload sesuai field backend vendor.
  const toPayload = () => ({
    name: vendorForm.nama,
    npwp: vendorForm.npwp || undefined,
    address: vendorForm.alamat || undefined,
    bank_name: vendorForm.bank,
    bank_account_no: vendorForm.noRekening,
    bank_account_name: vendorForm.atasNama,
  });

  const resetForm = () =>
    setVendorForm({
      nama: '',
      npwp: '',
      alamat: '',
      bank: 'Bank BCA',
      noRekening: '',
      atasNama: '',
      status: 'Aktif',
    });

  const reportError = (err: unknown) => {
    if (err instanceof ApiError) {
      const firstError = err.data?.errors && Object.values(err.data.errors)[0];
      alert(Array.isArray(firstError) ? firstError[0] : err.message);
    } else {
      alert('Terjadi kesalahan.');
    }
  };

  // Submit new vendor handler
  const handleAddSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!vendorForm.nama || !vendorForm.noRekening || !vendorForm.atasNama) {
      alert('Harap isi nama, nomor rekening dan atas nama.');
      return;
    }

    setSubmitting(true);
    try {
      await vendorApi.create(toPayload());
      await loadVendors();
      onAddAuditLog('Vendor Baru Didaftarkan', `Vendor baru '${vendorForm.nama}' didaftarkan dengan bank '${vendorForm.bank}'`, 'bg-emerald-600');
      onAddNotification('success', 'Vendor Berhasil Didaftarkan', `'${vendorForm.nama}' telah terdaftar.`);
      resetForm();
      setShowAddModal(false);
    } catch (err) {
      reportError(err);
    } finally {
      setSubmitting(false);
    }
  };

  // Trigger editing vendor save
  const handleEditClick = (v: Vendor) => {
    setEditVendor(v);
    setVendorForm({
      nama: v.nama,
      npwp: v.npwp,
      alamat: v.alamat,
      bank: v.bank,
      noRekening: v.noRekening,
      atasNama: v.atasNama,
      status: v.status
    });
  };

  const handleEditSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!editVendor) return;

    setSubmitting(true);
    try {
      await vendorApi.update(editVendor.id, toPayload());
      // Jika status diubah, sinkronkan via toggle.
      if (vendorForm.status !== editVendor.status) {
        await vendorApi.toggle(editVendor.id);
      }
      await loadVendors();
      onAddAuditLog('Data Vendor Diperbarui', `Informasi vendor '${vendorForm.nama}' diperbarui`, 'bg-indigo-600');
      setEditVendor(null);
      resetForm();
    } catch (err) {
      reportError(err);
    } finally {
      setSubmitting(false);
    }
  };

  const handleExport = () => {
    setConfirmDialog({
      isOpen: true,
      title: 'Unduh Rekap Vendor',
      message: 'Apakah Anda ingin men-download seluruh rekap master data vendor terdaftar dalam bentuk PDF / Excel?',
      confirmText: 'Download Rekap',
      type: 'info',
      onConfirm: () => {
        onAddAuditLog('Ekspor Master Vendor', `Mengekspor rekap keuangan ${vendors.length} vendor oleh Sari Rahma`, 'bg-indigo-650');
      }
    });
  };

  return (
    <div className="space-y-6 font-sans">
      
      {/* Header section matches image.png */}
      <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl p-5 shadow-xs">
        <div>
          <h3 className="text-sm font-bold text-slate-800 dark:text-slate-100 flex items-center gap-2">
            <Building className="w-5 h-5 text-indigo-650 text-indigo-630 text-indigo-600 shrink-0" />
            Master data vendor
          </h3>
          <p className="text-xs text-slate-400 dark:text-slate-505 mt-1">
            Manajemen rekening bank, NPWP, dan profil penarikan dana seluruh vendor terdaftar.
          </p>
        </div>
        
        <div className="flex gap-2 w-full sm:w-auto shrink-0 select-none">
          <button 
            onClick={handleExport}
            className="flex-1 sm:flex-initial flex items-center justify-center gap-1.5 py-2 px-4 rounded-xl text-xs font-semibold text-slate-600 dark:text-slate-350 hover:bg-slate-50 dark:hover:bg-slate-800 border border-slate-200 dark:border-slate-750 transition duration-150 cursor-pointer"
          >
            <Download className="w-4 h-4 text-slate-500" />
            <span>Export</span>
          </button>
        </div>
      </div>

      {/* Main Vendor register lists matching image.png card design */}
      <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-3xl p-6 shadow-xs space-y-5">
        
        <div className="flex flex-col sm:flex-row justify-between items-stretch sm:items-center gap-3">
          <div>
            <h4 className="text-xs font-bold text-slate-800 dark:text-slate-100 uppercase tracking-wider">
              Master data vendor terdaftar
            </h4>
          </div>
          
          <div className="flex items-center gap-2.5">
            {/* Simple search overlay */}
            <div className="relative">
              <Search className="absolute left-3 top-2.5 w-4 h-4 text-slate-400" />
              <input 
                type="text" 
                placeholder="Cari vendor / bank..."
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
                className="pl-9 pr-4 py-1.5 border border-slate-200 dark:border-slate-700 rounded-xl text-xs bg-slate-50/50 dark:bg-slate-800/40 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-1 focus:ring-indigo-505 transition w-full sm:w-56"
              />
            </div>

            <button 
              onClick={() => {
                setVendorForm({
                  nama: '',
                  npwp: '',
                  alamat: '',
                  bank: 'Bank BCA',
                  noRekening: '',
                  atasNama: '',
                  status: 'Aktif'
                });
                setShowAddModal(true);
              }}
              className="flex items-center justify-center gap-1.5 py-2 px-4 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-bold shadow-sm shadow-indigo-500/15 transition cursor-pointer"
            >
              <Plus className="w-3.5 h-3.5" />
              <span>Tambah vendor</span>
            </button>
          </div>
        </div>

        {/* Warning/Info banner block matching image.png exactly */}
        <div className="bg-indigo-50/40 dark:bg-indigo-950/20 border border-indigo-100 dark:border-indigo-900/30 p-4 rounded-xl text-xs text-indigo-900 dark:text-indigo-400 flex items-start gap-2.5 leading-relaxed">
          <Info className="w-4.5 h-4.5 text-indigo-650 text-indigo-600 shrink-0 mt-0.5" />
          <span>
            Vendor harus didaftarkan dulu sebelum bisa dipilih saat input invoice. Data rekening bank vendor tersimpan di sini dan otomatis muncul saat proses pembayaran.
          </span>
        </div>

        {/* The Card List matching layout & design colors of image.png exactly */}
        <div className="space-y-4">
          {errorMsg && (
            <div className="flex items-center justify-between gap-2 bg-rose-50 border border-rose-200 text-rose-700 rounded-lg px-4 py-3 text-xs">
              <span>{errorMsg}</span>
              <button onClick={loadVendors} className="font-semibold underline shrink-0">Coba lagi</button>
            </div>
          )}
          {loading ? (
            <p className="text-center py-10 text-xs text-slate-400">Memuat vendor...</p>
          ) : filteredVendors.length === 0 ? (
            <p className="text-center py-10 text-xs text-slate-450 dark:text-slate-500">Tidak ada vendor terdaftar yang cocok.</p>
          ) : (
            filteredVendors.map((vendor) => (
              <div 
                key={vendor.id}
                className="border border-slate-100 dark:border-slate-800/80 bg-slate-50/30 dark:bg-slate-950/20 hover:bg-slate-50/50 dark:hover:bg-slate-950/40 rounded-2xl p-5 transition relative duration-150 group"
              >
                <div className="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 border-b border-dashed border-slate-100 dark:border-slate-805 pb-3">
                  <div>
                    <h5 className="text-sm font-bold text-slate-900 dark:text-slate-100 font-sans tracking-tight">
                      {vendor.nama}
                    </h5>
                    <p className="text-[11px] text-slate-540 text-slate-450 mt-1 font-sans">
                      <span className="font-bold">NPWP:</span> {vendor.npwp} <span className="mx-1 text-slate-300">·</span> {vendor.alamat}
                    </p>
                  </div>

                  <div className="flex items-center gap-3 shrink-0 self-end md:self-auto">
                    {vendor.status === 'Aktif' ? (
                      <span className="inline-flex items-center gap-1 px-2.5 py-1 text-[10px] font-bold text-emerald-600 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-950/20 rounded-lg">
                        <Check className="w-3.5 h-3.5 shrink-0" />
                        <span>Aktif</span>
                      </span>
                    ) : (
                      <span className="inline-flex items-center gap-1 px-2.5 py-1 text-[10px] font-bold text-rose-600 dark:text-rose-455 bg-rose-50 dark:bg-rose-950/20 rounded-lg">
                        <X className="w-3.5 h-3.5 shrink-0" />
                        <span>Nonaktif</span>
                      </span>
                    )}

                    <button 
                      onClick={() => handleEditClick(vendor)}
                      className="py-1 px-3 border border-slate-200 dark:border-slate-755 hover:bg-slate-100 dark:hover:bg-slate-800 bg-white dark:bg-slate-900 text-slate-700 dark:text-slate-300 rounded-lg text-xs font-semibold transition cursor-pointer"
                    >
                      Edit
                    </button>
                  </div>
                </div>

                {/* Sub row with account details & stats */}
                <div className="grid grid-cols-2 md:grid-cols-4 gap-4 pt-3.5">
                  <div className="space-y-1">
                    <span className="text-[10px] text-slate-400 block font-semibold uppercase tracking-wider">Bank</span>
                    <span className="text-xs font-bold text-slate-800 dark:text-slate-200">{vendor.bank}</span>
                  </div>

                  <div className="space-y-1">
                    <span className="text-[10px] text-slate-400 block font-semibold uppercase tracking-wider">No. rekening</span>
                    <span className="text-xs font-bold font-mono text-slate-800 dark:text-slate-200 tracking-tight">{vendor.noRekening}</span>
                  </div>

                  <div className="space-y-1">
                    <span className="text-[10px] text-slate-400 block font-semibold uppercase tracking-wider">Atas nama</span>
                    <span className="text-xs font-medium text-slate-700 dark:text-slate-300 truncate block">{vendor.atasNama}</span>
                  </div>

                  <div className="space-y-1">
                    <span className="text-[10px] text-slate-400 block font-semibold uppercase tracking-wider">Total invoice</span>
                    <span className="text-xs font-semibold text-indigo-650 text-indigo-600 group-hover:underline dark:text-indigo-400 cursor-pointer block">
                      {vendor.totalInvoice} invoice
                    </span>
                  </div>
                </div>

              </div>
            ))
          )}
        </div>

      </div>

      {/* MODAL TAMBAH VENDOR */}
      {showAddModal && (
        <div className="fixed inset-0 z-55 flex items-center justify-center p-4 select-none">
          <div onClick={() => setShowAddModal(false)} className="fixed inset-0 bg-slate-900/60 dark:bg-slate-950/80 backdrop-blur-xs" />
          
          <form onSubmit={handleAddSubmit} className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-100 dark:border-slate-800 w-full max-w-md p-6 shadow-2xl relative z-10 space-y-4 max-h-[90vh] overflow-y-auto">
            <div className="flex justify-between items-center pb-3 border-b border-slate-100 dark:border-slate-800">
              <h3 className="text-sm font-bold text-slate-850 dark:text-slate-100 flex items-center gap-1.5">
                <Building className="w-4.5 h-4.5 text-indigo-600" />
                Tambah Vendor Baru
              </h3>
              <button type="button" onClick={() => setShowAddModal(false)} className="p-1 hover:bg-slate-50 text-slate-450 dark:hover:bg-slate-800 rounded-full">
                <X className="w-4.5 h-4.5" />
              </button>
            </div>

            <div className="space-y-3.5 text-xs">
              <div className="space-y-1">
                <label className="text-[10px] font-bold text-slate-400 dark:text-slate-500 block">Nama Vendor *</label>
                <input 
                  type="text" 
                  value={vendorForm.nama}
                  onChange={(e) => setVendorForm({...vendorForm, nama: e.target.value})}
                  required
                  placeholder="Contoh: PT Maju Jaya Indonesia"
                  className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-801/10 text-slate-800 dark:text-slate-100 focus:outline-none"
                />
              </div>

              <div className="space-y-1">
                <label className="text-[10px] font-bold text-slate-400 dark:text-slate-500 block">NPWP Perusahaan *</label>
                <input 
                  type="text" 
                  value={vendorForm.npwp}
                  onChange={(e) => setVendorForm({...vendorForm, npwp: e.target.value})}
                  required
                  placeholder="00.000.000.0-000.000"
                  className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-802/10 text-slate-808 dark:text-slate-100 focus:outline-none font-mono"
                />
              </div>

              <div className="space-y-1">
                <label className="text-[10px] font-bold text-slate-400 dark:text-slate-500 block">Alamat Kantor</label>
                <input 
                  type="text" 
                  value={vendorForm.alamat}
                  onChange={(e) => setVendorForm({...vendorForm, alamat: e.target.value})}
                  placeholder="Alamat lengkap kantor"
                  className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-803/10 text-slate-808 dark:text-slate-100 focus:outline-none"
                />
              </div>

              <div className="border-t border-slate-100 dark:border-slate-800 pt-3">
                <span className="text-[10px] font-bold text-slate-400 block uppercase tracking-wider mb-2">Informasi Rekening Bank</span>
              </div>

              <div className="grid grid-cols-2 gap-3">
                <div className="space-y-1">
                  <label className="text-[10px] font-bold text-slate-400 dark:text-slate-500 block">Pilih Bank *</label>
                  <select 
                    value={vendorForm.bank}
                    onChange={(e) => setVendorForm({...vendorForm, bank: e.target.value})}
                    className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-850 text-slate-800 dark:text-slate-100"
                  >
                    <option value="Bank BCA">Bank BCA</option>
                    <option value="Bank Mandiri">Bank Mandiri</option>
                    <option value="Bank BNI">Bank BNI</option>
                    <option value="Bank BRI">Bank BRI</option>
                    <option value="Bank CIMB Niaga">Bank CIMB Niaga</option>
                  </select>
                </div>

                <div className="space-y-1">
                  <label className="text-[10px] font-bold text-slate-400 dark:text-slate-500 block">Nomor Rekening *</label>
                  <input 
                    type="text" 
                    value={vendorForm.noRekening}
                    onChange={(e) => setVendorForm({...vendorForm, noRekening: e.target.value})}
                    required
                    placeholder="Nomor rekening bank"
                    className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-804/10 text-slate-808 dark:text-slate-100 focus:outline-none font-mono"
                  />
                </div>
              </div>

              <div className="space-y-1">
                <label className="text-[10px] font-bold text-slate-400 dark:text-slate-500 block">Nama Pemilik Rekening (Atas Nama) *</label>
                <input 
                  type="text" 
                  value={vendorForm.atasNama}
                  onChange={(e) => setVendorForm({...vendorForm, atasNama: e.target.value})}
                  required
                  placeholder="Sesuai buku tabungan"
                  className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-805/10 text-slate-808 dark:text-slate-100 focus:outline-none"
                />
              </div>

              <div className="space-y-1">
                <label className="text-[10px] font-bold text-slate-400 dark:text-slate-500 block">Status Registrasi</label>
                <select 
                  value={vendorForm.status}
                  onChange={(e) => setVendorForm({...vendorForm, status: e.target.value as 'Aktif' | 'Nonaktif'})}
                  className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-850 text-slate-800 dark:text-slate-100"
                >
                  <option value="Aktif">Aktif (bisa melampirkan invoice)</option>
                  <option value="Nonaktif">Nonaktif</option>
                </select>
              </div>

              <div className="p-3 bg-indigo-50/40 dark:bg-indigo-950/20 border border-indigo-150 dark:border-indigo-900/40 rounded-xl text-[11px] text-indigo-900 dark:text-indigo-400 flex items-start gap-1.5 leading-relaxed">
                <AlertCircle className="w-5 h-5 shrink-0 text-indigo-650 text-indigo-600 mt-0.5" />
                <span>Akun bank dan nama penerima pembayaran akan divalidasi silang oleh Tim Finance sebelum proses manual transfer dilakukan.</span>
              </div>
            </div>

            <div className="flex gap-2.5 pt-3 border-t border-slate-100 dark:border-slate-800">
              <button 
                type="button" 
                onClick={() => setShowAddModal(false)}
                className="flex-1 py-2.5 border border-slate-200 dark:border-slate-800 dark:hover:bg-slate-805 text-slate-600 dark:text-slate-400 rounded-xl text-xs font-semibold hover:bg-slate-50"
              >
                Batal
              </button>
              <button
                type="submit"
                disabled={submitting}
                className="flex-1 py-2.5 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-60 text-white font-bold rounded-xl text-xs shadow-sm shadow-indigo-500/10 cursor-pointer"
              >
                {submitting ? 'Menyimpan...' : 'Simpan Vendor'}
              </button>
            </div>
          </form>
        </div>
      )}

      {/* MODAL EDIT VENDOR */}
      {editVendor && (
        <div className="fixed inset-0 z-55 flex items-center justify-center p-4">
          <div onClick={() => setEditVendor(null)} className="fixed inset-0 bg-slate-900/60 dark:bg-slate-950/80 backdrop-blur-xs" />
          
          <form onSubmit={handleEditSubmit} className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-100 dark:border-slate-800 w-full max-w-md p-6 shadow-2xl relative z-10 space-y-4 max-h-[90vh] overflow-y-auto">
            <div className="flex justify-between items-center pb-3 border-b border-slate-100 dark:border-slate-805 shrink-0">
              <h3 className="text-sm font-bold text-slate-850 dark:text-slate-100 flex items-center gap-1.5">
                <Edit2 className="w-4.5 h-4.5 text-indigo-600 font-sans" />
                Edit Data Vendor
              </h3>
              <button type="button" onClick={() => setEditVendor(null)} className="p-1 hover:bg-slate-50 text-slate-450 dark:hover:bg-slate-800 rounded-full">
                <X className="w-4.5 h-4.5" />
              </button>
            </div>

            <div className="space-y-3.5 text-xs">
              <div className="space-y-1">
                <label className="text-[10px] font-bold text-slate-400 dark:text-slate-500 block">Nama Vendor *</label>
                <input 
                  type="text" 
                  value={vendorForm.nama}
                  onChange={(e) => setVendorForm({...vendorForm, nama: e.target.value})}
                  required
                  className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-801/10 text-slate-808"
                />
              </div>

              <div className="space-y-1">
                <label className="text-[10px] font-bold text-slate-400 dark:text-slate-505 block">NPWP Perusahaan *</label>
                <input 
                  type="text" 
                  value={vendorForm.npwp}
                  onChange={(e) => setVendorForm({...vendorForm, npwp: e.target.value})}
                  required
                  className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-802/10 text-slate-808 font-mono"
                />
              </div>

              <div className="space-y-1">
                <label className="text-[10px] font-bold text-slate-400 dark:text-slate-505 block font-sans">Alamat Lengkap *</label>
                <input 
                  type="text" 
                  value={vendorForm.alamat}
                  onChange={(e) => setVendorForm({...vendorForm, alamat: e.target.value})}
                  required
                  className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-803/10 text-slate-808"
                />
              </div>

              <div className="border-t border-slate-100 dark:border-slate-850 pt-3">
                <span className="text-[10px] font-bold text-slate-400 block uppercase tracking-wider mb-2">Informasi Rekening Bank</span>
              </div>

              <div className="grid grid-cols-2 gap-3 pb-1">
                <div className="space-y-1">
                  <label className="text-[10px] font-bold text-slate-400 dark:text-slate-500 block">Bank Rekening</label>
                  <select 
                    value={vendorForm.bank}
                    onChange={(e) => setVendorForm({...vendorForm, bank: e.target.value})}
                    className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-850 text-slate-800 block dark:text-slate-100"
                  >
                    <option value="Bank BCA">Bank BCA</option>
                    <option value="Bank Mandiri">Bank Mandiri</option>
                    <option value="Bank BNI">Bank BNI</option>
                    <option value="Bank BRI">Bank BRI</option>
                    <option value="Bank CIMB Niaga">Bank CIMB Niaga</option>
                  </select>
                </div>

                <div className="space-y-1">
                  <label className="text-[10px] font-bold text-slate-400 dark:text-slate-505 block">Nomor Rekening</label>
                  <input 
                    type="text" 
                    value={vendorForm.noRekening}
                    onChange={(e) => setVendorForm({...vendorForm, noRekening: e.target.value})}
                    required
                    className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-804/10 text-slate-808 font-mono"
                  />
                </div>
              </div>

              <div className="space-y-1">
                <label className="text-[10px] font-bold text-slate-400 dark:text-slate-505 block">Atas Nama Penerima *</label>
                <input 
                  type="text" 
                  value={vendorForm.atasNama}
                  onChange={(e) => setVendorForm({...vendorForm, atasNama: e.target.value})}
                  required
                  className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-805/10 text-slate-808"
                />
              </div>

              <div className="space-y-1">
                <label className="text-[10px] font-bold text-slate-400 dark:text-slate-500 block">Status Registrasi Vendor</label>
                <select 
                  value={vendorForm.status}
                  onChange={(e) => setVendorForm({...vendorForm, status: e.target.value as 'Aktif' | 'Nonaktif'})}
                  className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-850 text-slate-850 dark:text-slate-100"
                >
                  <option value="Aktif">Aktif</option>
                  <option value="Nonaktif">Nonaktif</option>
                </select>
              </div>
            </div>

            <div className="flex gap-2.5 pt-3 border-t border-slate-100 dark:border-slate-800">
              <button 
                type="button" 
                onClick={() => setEditVendor(null)}
                className="flex-1 py-2.5 border border-slate-200 dark:border-slate-800 dark:hover:bg-slate-805 text-slate-600 dark:text-slate-450 rounded-xl text-xs font-semibold hover:bg-slate-50 cursor-pointer"
              >
                Batal
              </button>
              <button
                type="submit"
                disabled={submitting}
                className="flex-1 py-2.5 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-60 text-white font-bold rounded-xl text-xs shadow-sm shadow-indigo-500/15 cursor-pointer"
              >
                {submitting ? 'Menyimpan...' : 'Simpan Edit'}
              </button>
            </div>
          </form>
        </div>
      )}

      {/* Confirmation dialog wrapper */}
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
