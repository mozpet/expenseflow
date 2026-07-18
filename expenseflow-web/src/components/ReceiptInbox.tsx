import React, { useState, useEffect } from 'react';
import { Receipt, ReceiptStatus, AppSettings } from '../types';
import {
  Inbox,
  AlertTriangle,
  Check,
  X,
  Search,
  User,
  Building2,
  Coins,
  Calendar,
  Eye,
  FileSpreadsheet,
  ZoomIn,
  ZoomOut,
  Maximize2,
  SlidersHorizontal,
  Save
} from 'lucide-react';
import { ConfirmationDialog } from './ConfirmationDialog';
import { receiptApi } from '../services/endpoints';

interface ReceiptInboxProps {
  receipts: Receipt[];
  onApprove: (id: string, catatan: string) => void;
  onReject: (id: string, catatan: string) => void;
  currentSettings: AppSettings;
  onSaveSettings: (settings: AppSettings) => void;
}

export const ReceiptInbox: React.FC<ReceiptInboxProps> = ({
  receipts,
  onApprove,
  onReject,
  currentSettings,
  onSaveSettings,
}) => {
  const [filter, setFilter] = useState<'all' | 'flag' | 'pend'>('all');
  const [searchQuery, setSearchQuery] = useState('');
  const [selectedReceipt, setSelectedReceipt] = useState<Receipt | null>(null);
  const [rejectionNote, setRejectionNote] = useState('');
  const [showModal, setShowModal] = useState(false);
  const [loadingImage, setLoadingImage] = useState(false);
  const [imageUrl, setImageUrl] = useState<string | null>(null);
  const [showImagePreview, setShowImagePreview] = useState(false);
  const [zoomLevel, setZoomLevel] = useState(100);
  const [localSettings, setLocalSettings] = useState<AppSettings>(currentSettings ?? { varianceLimit: 10, maxClaimLimit: 500000 });
  const [varianceInput, setVarianceInput] = useState(String(currentSettings?.varianceLimit ?? 10));
  const [claimInput, setClaimInput] = useState(String(currentSettings?.maxClaimLimit ?? 500000));
  const [activeTab, setActiveTab] = useState<'inbox' | 'settings'>('inbox');

  // Sync localSettings with currentSettings prop
  useEffect(() => {
    setLocalSettings(currentSettings);
    setVarianceInput(String(currentSettings?.varianceLimit ?? 10));
    setClaimInput(String(currentSettings?.maxClaimLimit ?? 500000));
  }, [currentSettings]);

  const handleSaveLimits = () => {
    const updated = {
      ...localSettings,
      varianceLimit: Number(varianceInput) || 0,
      maxClaimLimit: Number(claimInput) || 0,
    };
    setLocalSettings(updated);
    onSaveSettings(updated);
  };

  // State for reusable confirmation dialog
  const [confirmState, setConfirmState] = useState<{
    isOpen: boolean;
    title: string;
    message: string | React.ReactNode;
    confirmText?: string;
    type: 'danger' | 'warning' | 'success' | 'info';
    onConfirm: () => void;
  } | null>(null);

  const openConfirm = (opts: {
    title: string;
    message: string | React.ReactNode;
    confirmText?: string;
    type: 'danger' | 'warning' | 'success' | 'info';
    onConfirm: () => void;
  }) => {
    setConfirmState({
      isOpen: true,
      ...opts
    });
  };


  // Filter receipt list
  const filteredReceipts = receipts.filter(r => {
    const matchesSearch = r.karyawan.toLowerCase().includes(searchQuery.toLowerCase()) ||
                          r.merchant.toLowerCase().includes(searchQuery.toLowerCase());
    
    if (!matchesSearch) return false;
    if (filter === 'all') return true;
    if (filter === 'flag') return r.status === 'Review';
    if (filter === 'pend') return r.status === 'Pending';
    return true;
  });

  const handleOpenDetail = (receipt: Receipt) => {
    setSelectedReceipt(receipt);
    setRejectionNote('');
    setShowModal(true);
    setImageUrl(null);
    setLoadingImage(true);
  };

  // Fetch image ketika modal dibuka
  useEffect(() => {
    if (!showModal || !selectedReceipt) {
      if (imageUrl) URL.revokeObjectURL(imageUrl);
      setImageUrl(null);
      return;
    }

    const loadImage = async () => {
      try {
        const url = await receiptApi.fetchImageAsDataUrl(selectedReceipt.id);
        setImageUrl(url);
      } catch (err) {
        console.error('Error loading image:', err);
        setImageUrl(null);
      } finally {
        setLoadingImage(false);
      }
    };

    loadImage();

    return () => {
      if (imageUrl) URL.revokeObjectURL(imageUrl);
    };
  }, [showModal, selectedReceipt?.id]);

  // Keyboard shortcuts untuk image preview
  useEffect(() => {
    if (!showImagePreview) return;

    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'Escape') {
        setShowImagePreview(false);
      } else if (e.key === '+' || e.key === '=') {
        setZoomLevel(prev => Math.min(prev + 10, 300));
      } else if (e.key === '-') {
        setZoomLevel(prev => Math.max(prev - 10, 50));
      } else if (e.key === '0') {
        setZoomLevel(100);
      }
    };

    window.addEventListener('keydown', handleKeyDown);
    return () => window.removeEventListener('keydown', handleKeyDown);
  }, [showImagePreview]);

  const handleActionClick = (receipt: Receipt, approved: boolean) => {
    if (approved) {
      openConfirm({
        title: 'Setujui Pengajuan Klaim Struk',
        message: (
          <span>
            Apakah Anda yakin ingin menyetujui pengajuan klaim struk dari <strong>{receipt.karyawan}</strong> sebesar <strong>{formatCurrency(receipt.klaim)}</strong> untuk merchant <strong>{receipt.merchant}</strong>?
          </span>
        ),
        confirmText: 'Ya, Setujui',
        type: 'success',
        onConfirm: () => onApprove(receipt.id, 'Disetujui via inbox')
      });
    } else {
      setSelectedReceipt(receipt);
      setRejectionNote('');
      setShowModal(true);
    }
  };

  const submitApprove = () => {
    if (selectedReceipt) {
      openConfirm({
        title: 'Persetujuan Klaim Struk Karyawan',
        message: (
          <span>
            Apakah Anda yakin data struk dari <strong>{selectedReceipt.karyawan}</strong> sebesar <strong>{formatCurrency(selectedReceipt.klaim)}</strong> sudah sesuai dan siap disetujui? This action cannot be undone.
          </span>
        ),
        confirmText: 'Ya, Setujui Klaim',
        type: 'success',
        onConfirm: () => {
          onApprove(selectedReceipt.id, 'Disetujui setelah diverifikasi');
          if (imageUrl) URL.revokeObjectURL(imageUrl);
          setShowModal(false);
          setSelectedReceipt(null);
          setImageUrl(null);
        }
      });
    }
  };

  const submitReject = () => {
    if (selectedReceipt) {
      openConfirm({
        title: 'Tolak Pengajuan Klaim Struk',
        message: (
          <div className="space-y-1.5">
            <p>Apakah Anda yakin ingin menolak pengajuan klaim struk dari <strong>{selectedReceipt.karyawan}</strong> sebesar <strong>{formatCurrency(selectedReceipt.klaim)}</strong>?</p>
            <p className="p-2.5 bg-rose-50 dark:bg-rose-950/20 border border-rose-100 dark:border-rose-900/30 rounded-xl text-rose-750 dark:text-rose-400">
              <strong>Alasan Penolakan:</strong> {rejectionNote || 'Ditolak: data tidak sesuai'}
            </p>
          </div>
        ),
        confirmText: 'Ya, Tolak Klaim',
        type: 'danger',
        onConfirm: () => {
          onReject(selectedReceipt.id, rejectionNote || 'Ditolak: data tidak sesuai');
          if (imageUrl) URL.revokeObjectURL(imageUrl);
          setShowModal(false);
          setSelectedReceipt(null);
          setImageUrl(null);
        }
      });
    }
  };

  const formatCurrency = (val: number) => {
    return new Intl.NumberFormat('id-ID', {
      style: 'currency',
      currency: 'IDR',
      maximumFractionDigits: 0
    }).format(val);
  };

  const pendingCount = receipts.filter(r => r.status === 'Pending').length;
  const reviewCount = receipts.filter(r => r.status === 'Review').length;

  return (
    <div className="space-y-4">
      {/* Mini Stats Card */}
      <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
        <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-xl p-4 shadow-sm">
          <p className="text-xs text-slate-400 dark:text-slate-500">Menunggu Review</p>
          <div className="flex items-baseline gap-2 mt-1">
            <span className="text-2xl font-bold font-mono text-slate-800 dark:text-slate-100">{reviewCount + pendingCount}</span>
            <span className="text-[10px] text-amber-600 font-medium">Perlu tindakan</span>
          </div>
        </div>
        <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-xl p-4 shadow-sm">
          <p className="text-xs text-slate-400 dark:text-slate-500">Flagged Variance</p>
          <div className="flex items-baseline gap-2 mt-1">
            <span className="text-2xl font-bold font-mono text-rose-600">{reviewCount}</span>
            <span className="text-[10px] bg-rose-50 text-rose-700 dark:bg-rose-950/40 dark:text-rose-400 px-1.5 py-0.5 rounded font-mono font-medium">Auto-Flag</span>
          </div>
        </div>
        <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-xl p-4 shadow-sm2">
          <p className="text-xs text-slate-400 dark:text-slate-500">Status Normal</p>
          <div className="flex items-baseline gap-2 mt-1">
            <span className="text-2xl font-bold font-mono text-emerald-600">{pendingCount}</span>
            <span className="text-[10px] text-emerald-600">Aman</span>
          </div>
        </div>
        <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-xl p-4 shadow-sm2">
          <p className="text-xs text-slate-400 dark:text-slate-500">Total Perlu Dicek</p>
          <div className="flex items-baseline gap-2 mt-1">
            <span className="text-2xl font-bold font-mono text-indigo-600">{filteredReceipts.length}</span>
            <span className="text-[10px] text-slate-400">items</span>
          </div>
        </div>
      </div>

      {/* Main Container with Tabs */}
      <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl shadow-sm">
        {/* Tab Bar */}
        <div className="flex border-b border-slate-100 dark:border-slate-800">
          <button
            onClick={() => setActiveTab('inbox')}
            className={`flex items-center gap-2 px-5 py-3 text-sm font-medium transition border-b-2 ${
              activeTab === 'inbox'
                ? 'border-indigo-600 text-indigo-600 dark:text-indigo-400 dark:border-indigo-400'
                : 'border-transparent text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-300'
            }`}
          >
            <Inbox className="w-4 h-4" />
            Inbox Struk
          </button>
          <button
            onClick={() => setActiveTab('settings')}
            className={`flex items-center gap-2 px-5 py-3 text-sm font-medium transition border-b-2 ${
              activeTab === 'settings'
                ? 'border-indigo-600 text-indigo-600 dark:text-indigo-400 dark:border-indigo-400'
                : 'border-transparent text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-300'
            }`}
          >
            <SlidersHorizontal className="w-4 h-4" />
            Pengaturan
          </button>
        </div>

        <div className="p-5">
        {activeTab === 'inbox' ? (
          <>
        {/* Filters and Search Bar */}
        <div className="flex flex-col sm:flex-row gap-3 justify-between items-center mb-5 pb-4 border-b border-slate-100 dark:border-slate-800/80">
          <div className="flex flex-wrap gap-2 w-full sm:w-auto">
            <button
               onClick={() => setFilter('all')}
              className={`px-3 py-1.5 rounded-full text-xs font-medium transition ${
                filter === 'all'
                  ? 'bg-indigo-600 text-white'
                  : 'bg-slate-50 text-slate-600 hover:bg-slate-100 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700'
              }`}
            >
              Semua ({receipts.length})
            </button>
            <button
               onClick={() => setFilter('flag')}
              className={`px-3 py-1.5 rounded-full text-xs font-medium flex items-center gap-1.5 transition ${
                filter === 'flag'
                  ? 'bg-amber-600 text-white'
                  : 'bg-amber-50 text-amber-700 hover:bg-amber-100 dark:bg-amber-950/30 dark:text-amber-400 dark:hover:bg-amber-950/50'
              }`}
            >
              <AlertTriangle className="w-3.5 h-3.5" />
              Perlu Review ({reviewCount})
            </button>
            <button
               onClick={() => setFilter('pend')}
              className={`px-3 py-1.5 rounded-full text-xs font-medium transition ${
                filter === 'pend'
                  ? 'bg-emerald-600 text-white'
                  : 'bg-slate-50 text-slate-600 hover:bg-slate-100 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700'
              }`}
            >
              Normal ({pendingCount})
            </button>
          </div>

          <div className="relative w-full sm:w-64">
            <Search className="absolute left-3 top-2.5 w-4 h-4 text-slate-400" />
            <input
               type="text"
              placeholder="Cari karyawan atau toko..."
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              className="w-full pl-9 pr-4 py-2 border border-slate-200 dark:border-slate-700 rounded-xl text-xs bg-slate-50 dark:bg-slate-800/50 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-1 focus:ring-indigo-500 transition"
            />
          </div>
        </div>

        {/* Table representation */}
        <div className="overflow-x-auto">
          <table className="w-full text-left border-collapse min-w-[700px]">
            <thead>
              <tr className="border-b border-slate-100 dark:border-slate-800">
                <th className="py-3 px-4 font-semibold text-slate-500 dark:text-slate-400 text-xs uppercase tracking-wider">Karyawan</th>
                <th className="py-3 px-4 font-semibold text-slate-500 dark:text-slate-400 text-xs uppercase tracking-wider">Merchant / Toko</th>
                <th className="py-3 px-4 font-semibold text-slate-500 dark:text-slate-400 text-xs uppercase tracking-wider">OCR Nominal</th>
                <th className="py-3 px-4 font-semibold text-slate-500 dark:text-slate-400 text-xs uppercase tracking-wider font-mono">Klaim Karyawan</th>
                <th className="py-3 px-4 font-semibold text-slate-500 dark:text-slate-400 text-xs uppercase tracking-wider">Kategori</th>
                <th className="py-3 px-4 font-semibold text-slate-500 dark:text-slate-400 text-xs uppercase tracking-wider">Status</th>
                <th className="py-3 px-4 font-semibold text-slate-500 dark:text-slate-400 text-xs uppercase tracking-wider text-right">Aksi</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100 dark:divide-slate-800/65">
              {filteredReceipts.length === 0 ? (
                <tr>
                  <td colSpan={7} className="py-12 text-center text-slate-400 dark:text-slate-500 text-xs font-medium">
                    <Inbox className="w-8 h-8 mx-auto mb-2 opacity-40 text-slate-400" />
                    Tidak ada struk yang memerlukan persetujuan
                  </td>
                </tr>
              ) : (
                filteredReceipts.map((receipt) => {
                  const isFlagged = receipt.status === 'Review';
                  return (
                    <tr 
                      key={receipt.id} 
                      className={`group hover:bg-slate-50/50 dark:hover:bg-slate-800/20 transition-colors ${
                        isFlagged ? 'bg-amber-50/20 dark:bg-amber-950/5' : ''
                      }`}
                    >
                      <td className="py-3.5 px-4">
                        <div className="flex items-center gap-3">
                          <span className={`w-8 h-8 rounded-full ${receipt.avatarBg} ${receipt.avatarColor} font-semibold flex items-center justify-center text-xs shrink-0 select-none`}>
                            {receipt.initials}
                          </span>
                          <div>
                            <p className="text-xs font-medium text-slate-800 dark:text-slate-100">{receipt.karyawan}</p>
                            <span className="text-[10px] text-slate-400">{receipt.departemen}</span>
                          </div>
                        </div>
                      </td>
                      <td className="py-3.5 px-4 text-xs font-semibold text-slate-700 dark:text-slate-300">
                        {receipt.merchant}
                      </td>
                      <td className="py-3.5 px-4 text-xs font-mono text-slate-500 dark:text-slate-400">
                        {formatCurrency(receipt.ocrNominal)}
                      </td>
                      <td className="py-3.5 px-4">
                        {isFlagged ? (
                          <div className="flex items-center gap-1.5 font-semibold text-rose-600 font-mono text-xs">
                            <AlertTriangle className="w-4 h-4 text-rose-500 shrink-0" />
                            <span>{formatCurrency(receipt.klaim)}</span>
                          </div>
                        ) : (
                          <span className="font-semibold text-slate-800 dark:text-slate-100 font-mono text-xs">
                            {formatCurrency(receipt.klaim)}
                          </span>
                        )}
                      </td>
                      <td className="py-3.5 px-4">
                        <span className="text-[10px] font-medium px-2 py-0.5 rounded bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300 font-sans">
                          {receipt.kategori}
                        </span>
                      </td>
                      <td className="py-3.5 px-4">
                        {isFlagged ? (
                          <span className="inline-flex items-center gap-1 text-[10px] font-semibold px-2 py-0.5 rounded bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-400">
                            Review
                          </span>
                        ) : (
                          <span className="inline-flex items-center gap-1 text-[10px] font-semibold px-2 py-0.5 rounded bg-indigo-100 text-indigo-800 dark:bg-indigo-900/40 dark:text-indigo-400">
                            Pending
                          </span>
                        )}
                      </td>
                      <td className="py-3.5 px-4">
                        <div className="flex gap-1.5 justify-end">
                          <button
                            onClick={() => handleOpenDetail(receipt)}
                            className="p-1 px-2.5 border border-slate-200 dark:border-slate-700 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-md text-slate-500 dark:text-slate-400 text-[11px] font-medium transition flex items-center gap-1"
                          >
                            <Eye className="w-3.5 h-3.5" />
                            Detail
                          </button>
                          {!isFlagged && (
                            <button
                              onClick={() => handleActionClick(receipt, true)}
                              className="p-1 px-2.5 bg-emerald-600 text-white hover:bg-emerald-700 rounded-md text-[11px] font-medium transition flex items-center gap-1"
                            >
                              <Check className="w-3.5 h-3.5" />
                              Setuju
                            </button>
                          )}
                          <button
                            onClick={() => handleActionClick(receipt, false)}
                            className="p-1 px-2.5 bg-rose-500 hover:bg-rose-600 text-white rounded-md text-[11px] font-medium transition flex items-center gap-1"
                          >
                            <X className="w-3.5 h-3.5" />
                            Tolak
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
          </>
        ) : (
          /* Settings Tab Content */
          <div className="space-y-6">
            <div className="grid md:grid-cols-2 gap-6">
              <div>
                <label className="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Variance Limit</label>
                <div className="flex items-center gap-2">
                  <input
                    type="text"
                    inputMode="numeric"
                    value={varianceInput}
                    onChange={(e) => setVarianceInput(e.target.value.replace(/[^0-9.]/g, ''))}
                    className="w-full px-3 py-2 border border-slate-300 dark:border-slate-600 rounded-lg text-sm dark:bg-slate-800 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                  />
                  <span className="text-sm text-slate-500 dark:text-slate-400">%</span>
                </div>
                <p className="text-xs text-slate-400 mt-1">Struk dengan selisih melebihi batas ini akan ditandai untuk review</p>
              </div>
              <div>
                <label className="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Maks. Klaim per Bulan</label>
                <div className="flex items-center gap-2">
                  <span className="text-sm text-slate-500 dark:text-slate-400">Rp</span>
                  <input
                    type="text"
                    inputMode="numeric"
                    value={claimInput}
                    onChange={(e) => setClaimInput(e.target.value.replace(/[^0-9]/g, ''))}
                    className="w-full px-3 py-2 border border-slate-300 dark:border-slate-600 rounded-lg text-sm dark:bg-slate-800 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                  />
                </div>
                <p className="text-xs text-slate-400 mt-1">Batas maksimum klaim struk per karyawan per bulan</p>
              </div>
            </div>

            <div className="flex justify-end">
              <button
                onClick={handleSaveLimits}
                className="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-sm font-medium transition flex items-center gap-2"
              >
                <Save className="w-4 h-4" />
                Simpan Pengaturan
              </button>
            </div>
          </div>
        )}
        </div>
      </div>

      {/* Detail & Rejection Note Modal */}
      {showModal && selectedReceipt && (
        <div className="fixed inset-0 z-50 bg-slate-900/40 dark:bg-slate-950/60 backdrop-blur-xs flex items-center justify-center p-4">
          <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-100 dark:border-slate-800 w-full max-w-md p-6 shadow-2xl relative animate-in fade-in zoom-in duration-200">
            <button
              onClick={() => {
                if (imageUrl) URL.revokeObjectURL(imageUrl);
                setShowModal(false);
                setSelectedReceipt(null);
                setImageUrl(null);
              }}
              className="absolute right-4 top-4 hover:bg-slate-100 dark:hover:bg-slate-800 p-1.5 rounded-full text-slate-400 dark:text-slate-500 transition"
            >
              <X className="w-4 h-4" />
            </button>

            <h3 className="text-sm font-bold text-slate-900 dark:text-slate-100 flex items-center gap-2 mb-4">
              <FileSpreadsheet className="w-4.5 h-4.5 text-indigo-600" />
              Verifikasi Struk — {selectedReceipt.karyawan}
            </h3>

            {/* Receipt Image Preview */}
            <div className="bg-slate-50 dark:bg-slate-950/50 rounded-xl overflow-hidden border border-dashed border-slate-200 dark:border-slate-800 mb-4">
              {loadingImage ? (
                <div className="p-8 flex flex-col items-center justify-center">
                  <div className="w-8 h-8 border-3 border-slate-200 dark:border-slate-700 border-t-indigo-500 rounded-full animate-spin mb-2" />
                  <p className="text-xs font-medium text-slate-500 dark:text-slate-400">Memuat gambar...</p>
                </div>
              ) : imageUrl ? (
                <div className="flex flex-col items-center justify-center p-4">
                  <button
                    onClick={() => {
                      setShowImagePreview(true);
                      setZoomLevel(100);
                    }}
                    className="group relative max-w-full max-h-80 rounded-lg overflow-hidden hover:ring-2 hover:ring-indigo-500 transition focus:outline-none focus:ring-2 focus:ring-indigo-500"
                  >
                    <img
                      src={imageUrl}
                      alt={`Struk ${selectedReceipt.id}`}
                      className="max-w-full max-h-80 object-contain rounded-lg"
                      loading="lazy"
                    />
                    <div className="absolute inset-0 bg-black/0 group-hover:bg-black/20 transition flex items-center justify-center rounded-lg">
                      <div className="bg-black/60 text-white px-3 py-2 rounded-lg opacity-0 group-hover:opacity-100 transition flex items-center gap-2 text-xs font-medium">
                        <Maximize2 className="w-3.5 h-3.5" />
                        Klik untuk zoom
                      </div>
                    </div>
                  </button>
                  <p className="text-xs font-medium text-slate-600 dark:text-slate-400 mt-3">
                    Captured: {selectedReceipt.tanggal}
                  </p>
                </div>
              ) : (
                <div className="p-4 flex flex-col items-center justify-center">
                  <div className="w-12 h-12 rounded-full bg-slate-200 dark:bg-slate-800 flex items-center justify-center text-slate-400 dark:text-slate-500 mb-1">
                    <FileSpreadsheet className="w-6 h-6" />
                  </div>
                  <p className="text-xs font-medium text-slate-700 dark:text-slate-300">Gambar tidak ditemukan</p>
                  <span className="text-[10px] text-slate-400">receipt_image_{selectedReceipt.id.toLowerCase()}.jpg</span>
                </div>
              )}
            </div>

            {/* Flagged Alert Box */}
            {selectedReceipt.status === 'Review' && (
              <div className="bg-rose-50/50 dark:bg-rose-950/20 border border-rose-100 dark:border-rose-900/50 rounded-xl p-3.5 mb-4 text-xs text-rose-700 dark:text-rose-400">
                <div className="flex items-center gap-2 font-bold mb-1">
                  <AlertTriangle className="w-4.5 h-4.5 text-rose-600 dark:text-rose-400 shrink-0" />
                  <span>Sistem mendeteksi selisih ekstrim!</span>
                </div>
                <div className="grid grid-cols-3 gap-2 mt-2 pt-2 border-t border-rose-100 dark:border-rose-950 text-center font-mono">
                  <div>
                    <label className="text-[10px] text-slate-400 block font-sans">Klaim</label>
                    <span className="font-semibold text-rose-600 block">{formatCurrency(selectedReceipt.klaim)}</span>
                  </div>
                  <div className="flex items-center justify-center text-slate-300">
                    →
                  </div>
                  <div>
                    <label className="text-[10px] text-slate-400 block font-sans">OCR Struk</label>
                    <span className="font-semibold text-emerald-600 dark:text-emerald-400 block">{formatCurrency(selectedReceipt.ocrNominal)}</span>
                  </div>
                </div>
                <p className="mt-2 text-[10px] opacity-80 leading-relaxed font-sans text-rose-600/90 dark:text-rose-400/90">
                  Selisih sebesar <strong>+{( ((selectedReceipt.klaim - selectedReceipt.ocrNominal) / selectedReceipt.ocrNominal) * 105).toFixed(0)}%</strong>. Mohon verifikasi fisik struk sebelum menyetujui.
                </p>
              </div>
            )}

            {/* Details Fields */}
            <div className="grid grid-cols-2 gap-3 mb-4 text-xs border-b border-slate-100 dark:border-slate-800 pb-4">
              <div>
                <span className="text-slate-400 dark:text-slate-500 block">Karyawan</span>
                <span className="font-semibold text-slate-800 dark:text-slate-200">{selectedReceipt.karyawan}</span>
              </div>
              <div>
                <span className="text-slate-400 dark:text-slate-500 block">Depertemen</span>
                <span className="font-semibold text-slate-800 dark:text-slate-200">{selectedReceipt.departemen}</span>
              </div>
              <div>
                <span className="text-slate-400 dark:text-slate-500 block">Merchant Toko</span>
                <span className="font-semibold text-slate-800 dark:text-slate-200">{selectedReceipt.merchant}</span>
              </div>
              <div>
                <span className="text-slate-400 dark:text-slate-500 block">Kategori</span>
                <span className="font-semibold text-slate-800 dark:text-slate-200">{selectedReceipt.kategori}</span>
              </div>
            </div>

            {/* Notes Form */}
            <div className="space-y-2 mb-5">
              <label className="text-xs font-semibold text-slate-700 dark:text-slate-300 block">
                Catatan Verifikasi atau Alasan Penolakan (wajib jika ditolak)
              </label>
              <textarea
                rows={2}
                placeholder="Tulis alasan di sini..."
                value={rejectionNote}
                onChange={(e) => setRejectionNote(e.target.value)}
                className="w-full text-xs p-3 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50/50 dark:bg-slate-800/20 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-1 focus:ring-indigo-500 transition"
              />
            </div>

            {/* Action Buttons */}
            <div className="flex gap-2.5">
              <button
                onClick={submitReject}
                className="flex-1 py-2 px-4 bg-rose-600 hover:bg-rose-700 text-white font-medium rounded-xl text-xs transition"
              >
                Tolak Pengajuan
              </button>
              <button
                onClick={submitApprove}
                className="flex-1 py-2 px-4 bg-emerald-600 hover:bg-emerald-700 text-white font-medium rounded-xl text-xs transition"
              >
                Setujui Pengajuan
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Reusable Confirmation Dialog */}
      {confirmState && (
        <ConfirmationDialog
          isOpen={confirmState.isOpen}
          onClose={() => setConfirmState(null)}
          onConfirm={() => {
            confirmState.onConfirm();
            setConfirmState(null);
          }}
          title={confirmState.title}
          message={confirmState.message}
          confirmText={confirmState.confirmText}
          type={confirmState.type}
        />
      )}

      {/* Fullscreen Image Preview Modal with Zoom */}
      {showImagePreview && imageUrl && (
        <div className="fixed inset-0 z-[60] bg-black/95 flex flex-col items-center justify-center p-4">
          {/* Toolbar */}
          <div className="absolute top-0 left-0 right-0 bg-gradient-to-b from-black/80 to-transparent p-4 flex items-center justify-between">
            <div className="text-white text-sm font-medium">
              Zoom: <span className="font-bold font-mono">{zoomLevel}%</span>
            </div>
            <div className="flex items-center gap-2">
              <button
                onClick={() => setZoomLevel(prev => Math.max(prev - 10, 50))}
                className="p-2 hover:bg-white/20 rounded-lg text-white transition"
                title="Zoom out (-)  atau scroll"
              >
                <ZoomOut className="w-5 h-5" />
              </button>
              <button
                onClick={() => setZoomLevel(100)}
                className="px-3 py-2 hover:bg-white/20 rounded-lg text-white text-xs font-medium transition"
                title="Reset zoom (0)"
              >
                Reset
              </button>
              <button
                onClick={() => setZoomLevel(prev => Math.min(prev + 10, 300))}
                className="p-2 hover:bg-white/20 rounded-lg text-white transition"
                title="Zoom in (+)  atau scroll"
              >
                <ZoomIn className="w-5 h-5" />
              </button>
              <button
                onClick={() => setShowImagePreview(false)}
                className="p-2 hover:bg-white/20 rounded-lg text-white transition ml-2"
                title="Tutup (ESC)"
              >
                <X className="w-5 h-5" />
              </button>
            </div>
          </div>

          {/* Image Container with Scroll/Pan */}
          <div className="flex-1 flex items-center justify-center overflow-hidden w-full">
            <div
              className="flex items-center justify-center cursor-grab active:cursor-grabbing transition-transform"
              onWheel={(e) => {
                e.preventDefault();
                if (e.deltaY < 0) {
                  setZoomLevel(prev => Math.min(prev + 10, 300));
                } else {
                  setZoomLevel(prev => Math.max(prev - 10, 50));
                }
              }}
            >
              <img
                src={imageUrl}
                alt="Receipt Preview"
                className="object-contain select-none"
                style={{
                  transform: `scale(${zoomLevel / 100})`,
                  maxWidth: '100%',
                  maxHeight: '100%',
                  transition: 'transform 150ms ease-out',
                }}
                loading="lazy"
              />
            </div>
          </div>

          {/* Footer Info */}
          <div className="absolute bottom-0 left-0 right-0 bg-gradient-to-t from-black/80 to-transparent p-4 text-center text-white/70 text-xs">
            <p>Gunakan <kbd className="px-2 py-1 bg-white/10 rounded text-white/90 font-mono">+</kbd> / <kbd className="px-2 py-1 bg-white/10 rounded text-white/90 font-mono">-</kbd> atau scroll untuk zoom • <kbd className="px-2 py-1 bg-white/10 rounded text-white/90 font-mono">ESC</kbd> untuk tutup</p>
          </div>
        </div>
      )}
    </div>
  );
};
