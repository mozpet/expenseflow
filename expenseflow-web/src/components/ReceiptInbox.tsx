import React, { useState, useEffect } from 'react';
import { Receipt, ReceiptStatus, AppSettings, StrukApproval } from '../types';
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
  Save,
  RefreshCw,
  RotateCw,
  RotateCcw,
  CheckSquare,
  Square,
  Landmark,
  CreditCard,
  Copy,
  ShieldAlert,
  CheckCheck,
} from 'lucide-react';
import { useDebounce } from '../hooks/useDebounce';
import { ConfirmationDialog } from './ConfirmationDialog';
import { receiptApi } from '../services/endpoints';
import { ReceiptHistory } from './ReceiptHistory';

interface ReceiptInboxProps {
  receipts: Receipt[];
  receiptHistory?: StrukApproval[];
  onApprove: (id: string, catatan: string, approvedAmount?: number) => void;
  onBulkApprove?: (ids: string[], catatan?: string) => Promise<void> | void;
  onReject: (id: string, catatan: string) => void;
  onPay?: (id: string, payload: { payment_method: string; payment_ref_no?: string }) => Promise<void> | void;
  onBulkPay?: (ids: string[], payload: { payment_method: string; payment_ref_no?: string }) => Promise<void> | void;
  currentSettings: AppSettings;
  onSaveSettings: (settings: AppSettings) => void;
  onRefresh?: () => void;
  refreshing?: boolean;
  initialTab?: 'inbox' | 'history' | 'settings';
}

export const ReceiptInbox: React.FC<ReceiptInboxProps> = ({
  receipts,
  receiptHistory = [],
  onApprove,
  onBulkApprove,
  onReject,
  onPay,
  onBulkPay,
  currentSettings,
  onSaveSettings,
  onRefresh,
  refreshing,
  initialTab = 'inbox',
}) => {
  const [filter, setFilter] = useState<'all' | 'flag' | 'pend' | 'dup'>('all');
  const [searchQuery, setSearchQuery] = useState('');
  const [selectedReceipt, setSelectedReceipt] = useState<Receipt | null>(null);
  const [rejectionNote, setRejectionNote] = useState('');
  const [approvedAmountInput, setApprovedAmountInput] = useState<string>('');
  const [showModal, setShowModal] = useState(false);
  const [loadingImage, setLoadingImage] = useState(false);
  const [imageUrl, setImageUrl] = useState<string | null>(null);
  const [showImagePreview, setShowImagePreview] = useState(false);
  const [zoomLevel, setZoomLevel] = useState(100);
  const [rotation, setRotation] = useState(0);
  const [showCompareModal, setShowCompareModal] = useState<boolean>(false);
  const [compareImageUrl, setCompareImageUrl] = useState<string | null>(null);
  const [loadingCompareImage, setLoadingCompareImage] = useState<boolean>(false);
  const [localSettings, setLocalSettings] = useState<AppSettings>(currentSettings ?? { varianceLimit: 10, maxClaimLimit: 500000 });
  const [varianceInput, setVarianceInput] = useState(String(currentSettings?.varianceLimit ?? 10));
  const [claimInput, setClaimInput] = useState(String(currentSettings?.maxClaimLimit ?? 500000));
  const [activeTab, setActiveTab] = useState<'inbox' | 'history' | 'settings'>(initialTab);

  useEffect(() => {
    if (initialTab) {
      setActiveTab(initialTab);
    }
  }, [initialTab]);

  // Bulk Selection state
  const [selectedIds, setSelectedIds] = useState<string[]>([]);
  const [bulkProcessing, setBulkProcessing] = useState(false);

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

  const debouncedSearch = useDebounce(searchQuery, 500);

  // Pagination state
  const [currentPage, setCurrentPage] = useState<number>(1);
  const [pageSize, setPageSize] = useState<number>(25);

  useEffect(() => {
    setCurrentPage(1);
    setSelectedIds([]);
  }, [debouncedSearch, filter]);

  // Filter receipt list
  const filteredReceipts = receipts.filter((receipt) => {
    const matchesSearch =
      receipt.karyawan.toLowerCase().includes(debouncedSearch.toLowerCase()) ||
      receipt.merchant.toLowerCase().includes(debouncedSearch.toLowerCase()) ||
      (receipt.kategori && receipt.kategori.toLowerCase().includes(debouncedSearch.toLowerCase()));

    const isFlagged = receipt.status === 'Review';
    const isPending = receipt.status === 'Pending';
    const isDuplicate = Boolean(receipt.isPotentialDuplicate);

    let matchesTab = true;
    if (filter === 'flag') matchesTab = isFlagged;
    if (filter === 'pend') matchesTab = isPending;
    if (filter === 'dup') matchesTab = isDuplicate;

    return matchesSearch && matchesTab;
  });

  const totalPages = Math.max(1, Math.ceil(filteredReceipts.length / pageSize));
  const paginatedReceipts = filteredReceipts.slice((currentPage - 1) * pageSize, currentPage * pageSize);

  const handleToggleSelectAll = () => {
    if (selectedIds.length === paginatedReceipts.length && paginatedReceipts.length > 0) {
      setSelectedIds([]);
    } else {
      setSelectedIds(paginatedReceipts.map((r) => r.id));
    }
  };

  const handleToggleSelectRow = (id: string) => {
    setSelectedIds((prev) =>
      prev.includes(id) ? prev.filter((item) => item !== id) : [...prev, id]
    );
  };

  const handleBulkApproveClick = () => {
    if (selectedIds.length === 0) return;
    openConfirm({
      title: 'Setujui Masal Struk Terpilih',
      message: (
        <span>
          Apakah Anda yakin ingin menyetujui sekaligus <strong>{selectedIds.length} struk</strong> terpilih? Semua struk ini akan berstatus <strong>Disetujui</strong> dan siap masuk antrean pencairan/transfer.
        </span>
      ),
      confirmText: `Ya, Setujui (${selectedIds.length})`,
      type: 'success',
      onConfirm: async () => {
        setBulkProcessing(true);
        try {
          if (onBulkApprove) {
            await onBulkApprove(selectedIds, 'Persetujuan masal via Inbox');
          } else {
            await receiptApi.bulkApprove(selectedIds.map(Number), 'Persetujuan masal via Inbox');
            if (onRefresh) onRefresh();
          }
          setSelectedIds([]);
        } catch (err: any) {
          alert(err?.message || 'Gagal menyetujui masal struk.');
        } finally {
          setBulkProcessing(false);
        }
      },
    });
  };

  const handleOpenDetail = (receipt: Receipt) => {
    setSelectedReceipt(receipt);
    setRejectionNote('');
    setApprovedAmountInput(String(receipt.approvedAmount ?? receipt.klaim));
    setShowModal(true);
    setImageUrl(null);
  };

  const handleOpenCompare = async () => {
    if (!selectedReceipt?.duplicateReferenceId) return;
    setShowCompareModal(true);
    setLoadingCompareImage(true);
    setCompareImageUrl(null);
    try {
      const url = await receiptApi.fetchImageAsDataUrl(selectedReceipt.duplicateReferenceId);
      setCompareImageUrl(url);
    } catch {
      setCompareImageUrl(null);
    } finally {
      setLoadingCompareImage(false);
    }
  };

  // Fetch image on demand
  useEffect(() => {
    if (!showModal || !selectedReceipt) return;

    let isMounted = true;
    const loadImage = async () => {
      setLoadingImage(true);
      try {
        const url = await receiptApi.fetchImageAsDataUrl(selectedReceipt.id);
        if (isMounted) {
          setImageUrl(url);
        }
      } catch (err) {
        console.error('Failed to load image:', err);
      } finally {
        if (isMounted) setLoadingImage(false);
      }
    };

    loadImage();

    return () => {
      isMounted = false;
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
        setRotation(0);
      } else if (e.key === 'r' || e.key === 'R') {
        setRotation(prev => (prev + 90) % 360);
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
      setApprovedAmountInput(String(receipt.approvedAmount ?? receipt.klaim));
      setShowModal(true);
    }
  };

  const submitApprove = () => {
    if (selectedReceipt) {
      const parsedApprovedAmount = approvedAmountInput !== '' ? Number(approvedAmountInput) : selectedReceipt.klaim;

      if (parsedApprovedAmount < selectedReceipt.klaim && !rejectionNote.trim()) {
        alert('Catatan verifikasi wajib diisi jika nominal yang disetujui lebih kecil dari nominal klaim.');
        return;
      }

      const isAdjusted = parsedApprovedAmount < selectedReceipt.klaim;

      openConfirm({
        title: isAdjusted ? 'Persetujuan Nominal Disesuaikan' : 'Persetujuan Klaim Struk Karyawan',
        message: (
          <div className="space-y-2">
            <p>
              Apakah Anda yakin data struk dari <strong>{selectedReceipt.karyawan}</strong> sebesar{' '}
              <strong>{formatCurrency(parsedApprovedAmount)}</strong>
              {isAdjusted && (
                <span className="text-amber-600 font-semibold block text-xs mt-1">
                  (Disesuaikan dari klaim awal: {formatCurrency(selectedReceipt.klaim)})
                </span>
              )}{' '}
              sudah sesuai dan siap disetujui?
            </p>
          </div>
        ),
        confirmText: 'Ya, Setujui Klaim',
        type: 'success',
        onConfirm: () => {
          onApprove(
            selectedReceipt.id,
            rejectionNote || 'Disetujui setelah diverifikasi',
            parsedApprovedAmount
          );
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
  const duplicateCount = receipts.filter(r => r.isPotentialDuplicate).length;

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
            <span className="text-[10px] bg-rose-50 text-rose-700 dark:bg-rose-950/40 dark:text-rose-400 px-1.5 py-0.5 rounded font-mono font-medium">Selisih OCR</span>
          </div>
        </div>
        <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-xl p-4 shadow-sm">
          <p className="text-xs text-slate-400 dark:text-slate-500">Potensi Duplikat</p>
          <div className="flex items-baseline gap-2 mt-1">
            <span className="text-2xl font-bold font-mono text-purple-600">{duplicateCount}</span>
            <span className="text-[10px] bg-purple-50 text-purple-700 dark:bg-purple-950/40 dark:text-purple-400 px-1.5 py-0.5 rounded font-mono font-medium">Anti-Fraud</span>
          </div>
        </div>
        <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-xl p-4 shadow-sm">
          <p className="text-xs text-slate-400 dark:text-slate-500">Status Normal</p>
          <div className="flex items-baseline gap-2 mt-1">
            <span className="text-2xl font-bold font-mono text-emerald-600">{pendingCount}</span>
            <span className="text-[10px] text-emerald-600">Aman</span>
          </div>
        </div>
      </div>

      {/* Main Container with Tabs */}
      <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl shadow-sm">
        {/* Tab Bar */}
        <div className="flex border-b border-slate-200 dark:border-slate-800 items-center overflow-x-auto">
          <button
            onClick={() => setActiveTab('inbox')}
            className={`flex items-center gap-1.5 px-4 py-2.5 text-xs font-bold border-b-2 -mb-px transition cursor-pointer whitespace-nowrap ${
              activeTab === 'inbox'
                ? 'border-indigo-500 text-indigo-600 dark:text-indigo-400'
                : 'border-transparent text-slate-400 hover:text-slate-600 dark:text-slate-500 dark:hover:text-slate-300'
            }`}
          >
            <Inbox className="w-3.5 h-3.5" />
            <span>Inbox Struk</span>
            {receipts.length > 0 && (
              <span className={`text-[10px] px-1.5 py-0.2 rounded-full font-mono font-bold ${
                activeTab === 'inbox'
                  ? 'bg-indigo-100 dark:bg-indigo-900/50 text-indigo-700 dark:text-indigo-300'
                  : 'bg-slate-100 dark:bg-slate-800 text-slate-500'
              }`}>
                {receipts.length}
              </span>
            )}
          </button>

          <button
            onClick={() => setActiveTab('history')}
            className={`flex items-center gap-1.5 px-4 py-2.5 text-xs font-bold border-b-2 -mb-px transition cursor-pointer whitespace-nowrap ${
              activeTab === 'history'
                ? 'border-indigo-500 text-indigo-600 dark:text-indigo-400'
                : 'border-transparent text-slate-400 hover:text-slate-600 dark:text-slate-500 dark:hover:text-slate-300'
            }`}
          >
            <CheckCheck className="w-3.5 h-3.5" />
            <span>Riwayat Approval & Pencairan</span>
            {receiptHistory && receiptHistory.length > 0 && (
              <span className={`text-[10px] px-1.5 py-0.2 rounded-full font-mono font-bold ${
                activeTab === 'history'
                  ? 'bg-indigo-100 dark:bg-indigo-900/50 text-indigo-700 dark:text-indigo-300'
                  : 'bg-slate-100 dark:bg-slate-800 text-slate-500'
              }`}>
                {receiptHistory.length}
              </span>
            )}
          </button>

          <button
            onClick={() => setActiveTab('settings')}
            className={`flex items-center gap-1.5 px-4 py-2.5 text-xs font-bold border-b-2 -mb-px transition cursor-pointer whitespace-nowrap ${
              activeTab === 'settings'
                ? 'border-indigo-500 text-indigo-600 dark:text-indigo-400'
                : 'border-transparent text-slate-400 hover:text-slate-600 dark:text-slate-500 dark:hover:text-slate-300'
            }`}
          >
            <SlidersHorizontal className="w-3.5 h-3.5" />
            <span>Pengaturan & Limit</span>
          </button>

          <div className="ml-auto flex items-center gap-2 pr-3">
            <button
              onClick={onRefresh}
              disabled={refreshing}
              className="flex items-center justify-center gap-1.5 px-3 py-1.5 bg-indigo-50 dark:bg-indigo-900/30 border border-indigo-100 dark:border-indigo-800 text-indigo-600 dark:text-indigo-400 hover:bg-indigo-100 dark:hover:bg-indigo-900/50 rounded-lg text-xs font-bold transition shrink-0 disabled:opacity-50 cursor-pointer"
              title="Refresh Data"
            >
              <RefreshCw className={`w-3.5 h-3.5 ${refreshing ? 'animate-spin' : ''}`} />
              <span className="hidden sm:inline">Refresh</span>
            </button>
          </div>
        </div>

        <div className="p-5">
        {activeTab === 'inbox' ? (
          <>
        {/* Filters and Search Bar */}
        <div className="flex flex-col sm:flex-row gap-3 justify-between items-center mb-4 pb-4 border-b border-slate-100 dark:border-slate-800/80">
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
            {duplicateCount > 0 && (
              <button
                onClick={() => setFilter('dup')}
                className={`px-3 py-1.5 rounded-full text-xs font-medium flex items-center gap-1.5 transition ${
                  filter === 'dup'
                    ? 'bg-purple-600 text-white'
                    : 'bg-purple-50 text-purple-700 hover:bg-purple-100 dark:bg-purple-950/30 dark:text-purple-400 dark:hover:bg-purple-950/50'
                }`}
              >
                <ShieldAlert className="w-3.5 h-3.5" />
                Duplikat ({duplicateCount})
              </button>
            )}
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

        {/* Bulk Action Toolbar */}
        {selectedIds.length > 0 && (
          <div className="flex items-center justify-between p-3 px-4 bg-indigo-50 dark:bg-indigo-950/40 border border-indigo-100 dark:border-indigo-800/60 rounded-xl mb-4 text-xs">
            <div className="flex items-center gap-2 text-indigo-900 dark:text-indigo-200 font-medium">
              <CheckSquare className="w-4 h-4 text-indigo-600" />
              <span>
                <strong>{selectedIds.length} struk</strong> dipilih untuk aksi masal
              </span>
            </div>
            <div className="flex items-center gap-2">
              <button
                onClick={() => setSelectedIds([])}
                className="px-3 py-1.5 text-slate-600 dark:text-slate-300 hover:bg-white dark:hover:bg-slate-800 rounded-lg text-xs font-medium transition cursor-pointer"
              >
                Batal Pilih
              </button>
              <button
                onClick={handleBulkApproveClick}
                disabled={bulkProcessing}
                className="px-3.5 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-xs font-bold transition flex items-center gap-1.5 shadow-xs disabled:opacity-50 cursor-pointer"
              >
                <Check className="w-3.5 h-3.5" />
                <span>{bulkProcessing ? 'Memproses...' : `Setujui Terpilih (${selectedIds.length})`}</span>
              </button>
            </div>
          </div>
        )}

        {/* Table representation */}
        <div className="overflow-x-auto">
          <table className="w-full text-left border-collapse min-w-[760px]">
            <thead>
              <tr className="border-b border-slate-100 dark:border-slate-800">
                <th className="py-3 px-3 w-10 text-center">
                  <button
                    type="button"
                    onClick={handleToggleSelectAll}
                    className="text-slate-400 hover:text-indigo-600 transition"
                    title={selectedIds.length === paginatedReceipts.length ? 'Batalkan semua' : 'Pilih semua'}
                  >
                    {selectedIds.length > 0 && selectedIds.length === paginatedReceipts.length ? (
                      <CheckSquare className="w-4 h-4 text-indigo-600" />
                    ) : (
                      <Square className="w-4 h-4 text-slate-400" />
                    )}
                  </button>
                </th>
                <th className="py-3 px-3 font-semibold text-slate-500 dark:text-slate-400 text-xs uppercase tracking-wider">Karyawan</th>
                <th className="py-3 px-3 font-semibold text-slate-500 dark:text-slate-400 text-xs uppercase tracking-wider">Merchant / Toko</th>
                <th className="py-3 px-3 font-semibold text-slate-500 dark:text-slate-400 text-xs uppercase tracking-wider">OCR Nominal</th>
                <th className="py-3 px-3 font-semibold text-slate-500 dark:text-slate-400 text-xs uppercase tracking-wider font-mono">Klaim Karyawan</th>
                <th className="py-3 px-3 font-semibold text-slate-500 dark:text-slate-400 text-xs uppercase tracking-wider">Kategori</th>
                <th className="py-3 px-3 font-semibold text-slate-500 dark:text-slate-400 text-xs uppercase tracking-wider">Status & Anti-Fraud</th>
                <th className="py-3 px-3 font-semibold text-slate-500 dark:text-slate-400 text-xs uppercase tracking-wider text-right">Aksi</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100 dark:divide-slate-800/65">
              {filteredReceipts.length === 0 ? (
                <tr>
                  <td colSpan={8} className="py-12 text-center text-slate-400 dark:text-slate-500 text-xs font-medium">
                    <Inbox className="w-8 h-8 mx-auto mb-2 opacity-40 text-slate-400" />
                    Tidak ada struk yang memerlukan persetujuan
                  </td>
                </tr>
              ) : (
                paginatedReceipts.map((receipt) => {
                  const isFlagged = receipt.status === 'Review';
                  const isDuplicate = Boolean(receipt.isPotentialDuplicate);
                  const isSelected = selectedIds.includes(receipt.id);

                  return (
                    <tr 
                      key={receipt.id} 
                      className={`group hover:bg-slate-50/50 dark:hover:bg-slate-800/20 transition-colors ${
                        isSelected ? 'bg-indigo-50/30 dark:bg-indigo-950/20' : isDuplicate ? 'bg-purple-50/20 dark:bg-purple-950/10' : isFlagged ? 'bg-amber-50/20 dark:bg-amber-950/5' : ''
                      }`}
                    >
                      <td className="py-3.5 px-3 text-center">
                        <button
                          type="button"
                          onClick={() => handleToggleSelectRow(receipt.id)}
                          className="text-slate-400 hover:text-indigo-600 transition"
                        >
                          {isSelected ? (
                            <CheckSquare className="w-4 h-4 text-indigo-600" />
                          ) : (
                            <Square className="w-4 h-4 text-slate-300 dark:text-slate-600" />
                          )}
                        </button>
                      </td>
                      <td className="py-3.5 px-3">
                        <div className="flex items-center gap-2.5">
                          <span className={`w-8 h-8 rounded-full ${receipt.avatarBg} ${receipt.avatarColor} font-semibold flex items-center justify-center text-xs shrink-0 select-none`}>
                            {receipt.initials}
                          </span>
                          <div>
                            <p className="text-xs font-medium text-slate-800 dark:text-slate-100">{receipt.karyawan}</p>
                            <span className="text-[10px] text-slate-400">{receipt.departemen}</span>
                          </div>
                        </div>
                      </td>
                      <td className="py-3.5 px-3 text-xs font-semibold text-slate-700 dark:text-slate-300">
                        {receipt.merchant}
                      </td>
                      <td className="py-3.5 px-3 text-xs font-mono text-slate-500 dark:text-slate-400">
                        {formatCurrency(receipt.ocrNominal)}
                      </td>
                      <td className="py-3.5 px-3">
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
                      <td className="py-3.5 px-3">
                        <span className="text-[10px] font-medium px-2 py-0.5 rounded bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300 font-sans">
                          {receipt.kategori}
                        </span>
                      </td>
                      <td className="py-3.5 px-3">
                        <div className="flex flex-col gap-1 items-start">
                          {isFlagged ? (
                            <span className="inline-flex items-center gap-1 text-[10px] font-semibold px-2 py-0.5 rounded bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-400">
                              Review
                            </span>
                          ) : (
                            <span className="inline-flex items-center gap-1 text-[10px] font-semibold px-2 py-0.5 rounded bg-indigo-100 text-indigo-800 dark:bg-indigo-900/40 dark:text-indigo-400">
                              Pending
                            </span>
                          )}
                          {isDuplicate && (
                            <span className="inline-flex items-center gap-1 text-[9px] font-bold px-1.5 py-0.5 rounded bg-purple-100 text-purple-800 dark:bg-purple-950/50 dark:text-purple-300 border border-purple-200 dark:border-purple-800" title={`Mirip dengan struk ${receipt.duplicateReceiptNumber || ''}`}>
                              <ShieldAlert className="w-2.5 h-2.5 text-purple-600" />
                              Potensi Duplikat
                            </span>
                          )}
                        </div>
                      </td>
                      <td className="py-3.5 px-3">
                        <div className="flex gap-1.5 justify-end">
                          <button
                            onClick={() => handleOpenDetail(receipt)}
                            className="p-1 px-2.5 border border-slate-200 dark:border-slate-700 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-md text-slate-500 dark:text-slate-400 text-[11px] font-medium transition flex items-center gap-1 cursor-pointer"
                          >
                            <Eye className="w-3.5 h-3.5" />
                            Detail
                          </button>
                          {!isFlagged && !isDuplicate && (
                            <button
                              onClick={() => handleActionClick(receipt, true)}
                              className="p-1 px-2.5 bg-emerald-600 text-white hover:bg-emerald-700 rounded-md text-[11px] font-medium transition flex items-center gap-1 cursor-pointer"
                            >
                              <Check className="w-3.5 h-3.5" />
                              Setuju
                            </button>
                          )}
                          <button
                            onClick={() => handleActionClick(receipt, false)}
                            className="p-1 px-2.5 bg-rose-500 hover:bg-rose-600 text-white rounded-md text-[11px] font-medium transition flex items-center gap-1 cursor-pointer"
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

        {/* Pagination footer */}
        {filteredReceipts.length >= 25 && (
          <div className="flex flex-col sm:flex-row items-center justify-between gap-3 pt-4 mt-2 border-t border-slate-100 dark:border-slate-800 text-xs">
            <div className="flex items-center gap-2 text-slate-500 dark:text-slate-400">
              <span>
                Menampilkan <strong className="text-slate-800 dark:text-slate-200 font-bold font-mono">
                  {Math.min((currentPage - 1) * pageSize + 1, filteredReceipts.length)} - {Math.min(currentPage * pageSize, filteredReceipts.length)}
                </strong> dari <strong className="text-slate-800 dark:text-slate-200 font-bold font-mono">{filteredReceipts.length}</strong> struk
              </span>
              <span className="hidden sm:inline">•</span>
              <div className="flex items-center gap-1.5">
                <span className="hidden sm:inline">Per hal:</span>
                <select
                  value={pageSize}
                  onChange={(e) => {
                    setPageSize(Number(e.target.value));
                    setCurrentPage(1);
                  }}
                  className="py-0.5 px-2 text-xs font-semibold border border-slate-200 dark:border-slate-700 rounded-lg bg-slate-50 dark:bg-slate-800 text-slate-700 dark:text-slate-300 focus:outline-none cursor-pointer"
                >
                  <option value={25}>25</option>
                  <option value={50}>50</option>
                  <option value={100}>100</option>
                </select>
              </div>
            </div>

            <div className="flex items-center gap-1.5">
              <button
                type="button"
                onClick={() => setCurrentPage(1)}
                disabled={currentPage === 1}
                className="p-1 px-2 rounded-lg border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800 disabled:opacity-40 disabled:cursor-not-allowed transition font-medium cursor-pointer"
                title="Halaman Pertama"
              >
                «
              </button>
              <button
                type="button"
                onClick={() => setCurrentPage(p => Math.max(1, p - 1))}
                disabled={currentPage === 1}
                className="p-1 px-2 rounded-lg border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800 disabled:opacity-40 disabled:cursor-not-allowed transition font-medium cursor-pointer"
                title="Halaman Sebelumnya"
              >
                ‹
              </button>
              <span className="px-2 font-semibold text-slate-700 dark:text-slate-300">
                Hal <span className="font-mono font-bold text-indigo-600 dark:text-indigo-400">{currentPage}</span> / <span className="font-mono">{totalPages}</span>
              </span>
              <button
                type="button"
                onClick={() => setCurrentPage(p => Math.min(totalPages, p + 1))}
                disabled={currentPage === totalPages}
                className="p-1 px-2 rounded-lg border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800 disabled:opacity-40 disabled:cursor-not-allowed transition font-medium cursor-pointer"
                title="Halaman Berikutnya"
              >
                ›
              </button>
              <button
                type="button"
                onClick={() => setCurrentPage(totalPages)}
                disabled={currentPage === totalPages}
                className="p-1 px-2 rounded-lg border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800 disabled:opacity-40 disabled:cursor-not-allowed transition font-medium cursor-pointer"
                title="Halaman Terakhir"
              >
                »
              </button>
            </div>
          </div>
        )}
          </>
        ) : activeTab === 'history' ? (
          /* History & Disbursement Tab Content */
          <div className="-m-5">
            <ReceiptHistory
              approvals={receiptHistory}
              onPay={onPay}
              onBulkPay={onBulkPay}
              onRefresh={onRefresh}
              refreshing={refreshing}
            />
          </div>
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
                className="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-sm font-medium transition flex items-center gap-2 cursor-pointer"
              >
                <Save className="w-4 h-4" />
                Simpan Pengaturan
              </button>
            </div>
          </div>
        )}
        </div>
      </div>

      {/* Detail & Approval Modal */}
      {showModal && selectedReceipt && (
        <div className="fixed inset-0 z-50 bg-slate-900/40 dark:bg-slate-950/60 backdrop-blur-xs flex items-center justify-center p-4">
          <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-100 dark:border-slate-800 w-full max-w-lg max-h-[88vh] flex flex-col shadow-2xl relative animate-in fade-in zoom-in duration-200 overflow-hidden">
            
            {/* Modal Header */}
            <div className="p-4 sm:p-5 pb-3 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between shrink-0">
              <h3 className="text-sm font-bold text-slate-900 dark:text-slate-100 flex items-center gap-2">
                <FileSpreadsheet className="w-4.5 h-4.5 text-indigo-600 shrink-0" />
                <span>Verifikasi Struk — {selectedReceipt.karyawan}</span>
              </h3>
              <button
                onClick={() => {
                  if (imageUrl) URL.revokeObjectURL(imageUrl);
                  setShowModal(false);
                  setSelectedReceipt(null);
                  setImageUrl(null);
                }}
                className="hover:bg-slate-100 dark:hover:bg-slate-800 p-1.5 rounded-full text-slate-400 dark:text-slate-500 transition cursor-pointer"
              >
                <X className="w-4 h-4" />
              </button>
            </div>

            {/* Modal Scrollable Body */}
            <div className="p-4 sm:p-5 overflow-y-auto flex-1 space-y-3.5">
              {/* Compact Receipt Image Preview */}
              <div className="bg-slate-50 dark:bg-slate-950/50 rounded-xl overflow-hidden border border-dashed border-slate-200 dark:border-slate-800">
                {loadingImage ? (
                  <div className="p-4 flex items-center justify-center gap-2">
                    <div className="w-4 h-4 border-2 border-slate-200 dark:border-slate-700 border-t-indigo-500 rounded-full animate-spin" />
                    <p className="text-xs font-medium text-slate-500 dark:text-slate-400">Memuat gambar...</p>
                  </div>
                ) : imageUrl ? (
                  <div className="p-2.5 flex items-center gap-3">
                    <button
                      onClick={() => {
                        setShowImagePreview(true);
                        setZoomLevel(100);
                      }}
                      className="group relative h-20 w-24 rounded-lg overflow-hidden border border-slate-200 dark:border-slate-700 bg-black/5 shrink-0 focus:outline-none focus:ring-2 focus:ring-indigo-500 cursor-pointer"
                      title="Klik untuk memperbesar struk"
                    >
                      <img
                        src={imageUrl}
                        alt={`Struk ${selectedReceipt.id}`}
                        className="w-full h-full object-cover group-hover:scale-105 transition duration-200"
                        loading="lazy"
                      />
                      <div className="absolute inset-0 bg-black/25 group-hover:bg-black/40 transition flex items-center justify-center">
                        <Maximize2 className="w-4 h-4 text-white drop-shadow" />
                      </div>
                    </button>
                    <div className="flex-1 min-w-0">
                      <div className="flex items-center gap-1.5 text-xs font-semibold text-slate-800 dark:text-slate-200">
                        <FileSpreadsheet className="w-3.5 h-3.5 text-indigo-500 shrink-0" />
                        <span className="truncate">Foto Struk Fisik</span>
                      </div>
                      <p className="text-[11px] text-slate-400 mt-0.5">Tanggal: {selectedReceipt.tanggal}</p>
                      <button
                        onClick={() => {
                          setShowImagePreview(true);
                          setZoomLevel(100);
                        }}
                        className="mt-1 inline-flex items-center gap-1 text-[11px] font-semibold text-indigo-600 dark:text-indigo-400 hover:underline cursor-pointer"
                      >
                        <Maximize2 className="w-3 h-3" />
                        Klik untuk Perbesar (Zoom)
                      </button>
                    </div>
                  </div>
                ) : (
                  <div className="p-3 flex items-center justify-center gap-2 text-slate-400 text-xs">
                    <FileSpreadsheet className="w-4 h-4" />
                    <span>Gambar struk tidak ditemukan</span>
                  </div>
                )}
              </div>

              {/* Duplicate Warning Box */}
              {selectedReceipt.isPotentialDuplicate && (
                <div className="bg-purple-50/80 dark:bg-purple-950/40 border border-purple-200 dark:border-purple-800/60 rounded-xl p-3.5 text-xs text-purple-900 dark:text-purple-200 space-y-2">
                  <div className="flex items-center justify-between">
                    <div className="flex items-center gap-2 font-bold text-purple-700 dark:text-purple-300">
                      <ShieldAlert className="w-4 h-4 shrink-0" />
                      <span>Peringatan: Terindikasi Struk Duplikat!</span>
                    </div>
                    {selectedReceipt.duplicateReferenceId && (
                      <button
                        type="button"
                        onClick={handleOpenCompare}
                        className="px-2.5 py-1 text-[11px] font-semibold bg-purple-600 hover:bg-purple-700 text-white rounded-lg shadow-xs flex items-center gap-1 transition cursor-pointer"
                      >
                        <Eye className="w-3 h-3" />
                        <span>Bandingkan Struk</span>
                      </button>
                    )}
                  </div>
                  <p className="text-[11px] text-purple-800 dark:text-purple-300 leading-relaxed">
                    {selectedReceipt.duplicateReason || (
                      <>
                        Sistem mendeteksi struk ini memiliki kemiripan dengan struk lain{' '}
                        {selectedReceipt.duplicateReceiptNumber && (
                          <strong className="underline font-mono">({selectedReceipt.duplicateReceiptNumber})</strong>
                        )}.
                      </>
                    )}
                  </p>
                  {selectedReceipt.duplicateReference && (
                    <div className="bg-white/70 dark:bg-slate-900/60 border border-purple-100 dark:border-purple-900/50 rounded-lg p-2 text-[10.5px] grid grid-cols-2 gap-1.5 font-mono">
                      <div>
                        <span className="text-slate-400 font-sans block">Struk Referensi Asli:</span>
                        <span className="font-semibold text-purple-700 dark:text-purple-300">
                          {selectedReceipt.duplicateReference.receiptNumber}
                        </span>
                      </div>
                      <div>
                        <span className="text-slate-400 font-sans block">Pengunggah Asli:</span>
                        <span className="font-semibold text-slate-700 dark:text-slate-200">
                          {selectedReceipt.duplicateReference.uploaderName || 'Karyawan lain'} {selectedReceipt.duplicateReference.department ? `(${selectedReceipt.duplicateReference.department})` : ''}
                        </span>
                      </div>
                    </div>
                  )}
                </div>
              )}

              {/* Flagged Variance Alert Box */}
              {selectedReceipt.status === 'Review' && (
                <div className="bg-rose-50/50 dark:bg-rose-950/20 border border-rose-100 dark:border-rose-900/50 rounded-xl p-3 text-xs text-rose-700 dark:text-rose-400">
                  <div className="flex items-center gap-2 font-bold mb-1">
                    <AlertTriangle className="w-4 h-4 text-rose-600 dark:text-rose-400 shrink-0" />
                    <span>Sistem mendeteksi selisih OCR vs Klaim!</span>
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
                    Selisih sebesar <strong>+{(((selectedReceipt.klaim - selectedReceipt.ocrNominal) / (selectedReceipt.ocrNominal || 1)) * 100).toFixed(0)}%</strong>. Mohon verifikasi fisik struk sebelum menyetujui.
                  </p>
                </div>
              )}

              {/* Details Fields */}
              <div className="grid grid-cols-2 gap-2.5 text-xs bg-slate-50 dark:bg-slate-800/40 p-3 rounded-xl border border-slate-100 dark:border-slate-800">
                <div>
                  <span className="text-slate-400 dark:text-slate-500 text-[10px] block">Karyawan</span>
                  <span className="font-semibold text-slate-800 dark:text-slate-200">{selectedReceipt.karyawan}</span>
                </div>
                <div>
                  <span className="text-slate-400 dark:text-slate-500 text-[10px] block">Departemen</span>
                  <span className="font-semibold text-slate-800 dark:text-slate-200">{selectedReceipt.departemen}</span>
                </div>
                <div>
                  <span className="text-slate-400 dark:text-slate-500 text-[10px] block">Merchant Toko</span>
                  <span className="font-semibold text-slate-800 dark:text-slate-200">{selectedReceipt.merchant}</span>
                </div>
                <div>
                  <span className="text-slate-400 dark:text-slate-500 text-[10px] block">Kategori</span>
                  <span className="font-semibold text-slate-800 dark:text-slate-200">{selectedReceipt.kategori}</span>
                </div>
              </div>

              {/* Bank Account Info Card for Disbursement */}
              {(selectedReceipt.bankName || selectedReceipt.bankAccountNo) && (
                <div className="p-3 bg-emerald-50/50 dark:bg-emerald-950/20 border border-emerald-100 dark:border-emerald-900/30 rounded-xl text-xs space-y-1">
                  <div className="flex items-center gap-1.5 text-emerald-800 dark:text-emerald-300 font-bold text-[11px]">
                    <Landmark className="w-3.5 h-3.5" />
                    <span>Rekening Pencairan Reimbursement</span>
                  </div>
                  <div className="grid grid-cols-2 gap-2 pt-1 font-mono text-[11px]">
                    <div>
                      <span className="text-slate-400 font-sans block text-[10px]">Bank:</span>
                      <strong className="text-slate-700 dark:text-slate-300">{selectedReceipt.bankName || '—'}</strong>
                    </div>
                    <div>
                      <span className="text-slate-400 font-sans block text-[10px]">No. Rekening:</span>
                      <strong className="text-slate-700 dark:text-slate-300">{selectedReceipt.bankAccountNo || '—'}</strong>
                    </div>
                  </div>
                </div>
              )}

              {/* Itemized Receipt Items Breakdown */}
              {selectedReceipt.items && selectedReceipt.items.length > 0 && (
                <div className="border border-slate-200 dark:border-slate-800 rounded-xl overflow-hidden text-xs">
                  <div className="bg-slate-100/80 dark:bg-slate-800/60 px-3 py-2 border-b border-slate-200 dark:border-slate-800 flex justify-between items-center">
                    <span className="font-bold text-slate-700 dark:text-slate-300 flex items-center gap-1.5 text-[11px]">
                      <FileSpreadsheet className="w-3.5 h-3.5 text-indigo-500" />
                      Rincian Belanja ({selectedReceipt.items.length} item)
                    </span>
                    <span className="text-[10px] font-semibold text-emerald-600 bg-emerald-50 dark:bg-emerald-950/40 px-1.5 py-0.5 rounded">
                      AI OCR
                    </span>
                  </div>
                  <div className="divide-y divide-slate-100 dark:divide-slate-800 max-h-48 overflow-y-auto bg-white dark:bg-slate-900/50">
                    {selectedReceipt.items.map((item, idx) => (
                      <div key={idx} className="px-3 py-2 flex justify-between items-start gap-2 hover:bg-slate-50/50 dark:hover:bg-slate-800/30">
                        <div className="flex-1 min-w-0">
                          <p className="font-medium text-slate-800 dark:text-slate-200 truncate text-[11px]">{item.name}</p>
                          <p className="text-[10px] text-slate-400">
                            {item.qty}x @ {formatCurrency(item.price)}
                          </p>
                        </div>
                        <span className="font-semibold font-mono text-slate-700 dark:text-slate-300 text-[11px] shrink-0">
                          {formatCurrency(item.total)}
                        </span>
                      </div>
                    ))}
                  </div>
                </div>
              )}

              {/* Subtotal, Diskon, Pajak Breakdown */}
              {(selectedReceipt.subtotal !== undefined || selectedReceipt.discount !== undefined || selectedReceipt.tax !== undefined) && (
                <div className="bg-slate-50/80 dark:bg-slate-800/30 border border-slate-200/80 dark:border-slate-800 rounded-xl p-3 space-y-1.5 text-xs font-mono">
                  {selectedReceipt.subtotal !== undefined && (
                    <div className="flex justify-between text-slate-600 dark:text-slate-400 text-[11px]">
                      <span>Subtotal</span>
                      <span>{formatCurrency(selectedReceipt.subtotal)}</span>
                    </div>
                  )}
                  {selectedReceipt.discount !== undefined && selectedReceipt.discount > 0 && (
                    <div className="flex justify-between text-emerald-600 dark:text-emerald-400 text-[11px] font-semibold">
                      <span>Diskon / Promo</span>
                      <span>- {formatCurrency(selectedReceipt.discount)}</span>
                    </div>
                  )}
                  {selectedReceipt.tax !== undefined && selectedReceipt.tax > 0 && (
                    <div className="flex justify-between text-amber-600 dark:text-amber-400 text-[11px] font-semibold">
                      <span>Pajak (Tax / PPN)</span>
                      <span>+ {formatCurrency(selectedReceipt.tax)}</span>
                    </div>
                  )}
                  <div className="flex justify-between pt-1.5 border-t border-slate-200 dark:border-slate-700 text-slate-900 dark:text-slate-100 font-bold text-xs">
                    <span className="font-sans">Total OCR</span>
                    <span>{formatCurrency(selectedReceipt.ocrNominal)}</span>
                  </div>
                </div>
              )}

              {/* Penyesuaian Nominal Approval (Partial Approval) */}
              <div className="p-3 bg-indigo-50/40 dark:bg-indigo-950/20 border border-indigo-100 dark:border-indigo-900/40 rounded-xl space-y-2 text-xs">
                <div className="flex justify-between items-center">
                  <label className="font-bold text-indigo-950 dark:text-indigo-200 text-[11px] flex items-center gap-1.5">
                    <Coins className="w-3.5 h-3.5 text-indigo-600" />
                    <span>Nominal yang Disetujui (IDR)</span>
                  </label>
                  <div className="flex gap-1.5">
                    <button
                      type="button"
                      onClick={() => setApprovedAmountInput(String(selectedReceipt.klaim))}
                      className="px-2 py-0.5 rounded bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-[10px] font-semibold text-slate-600 hover:text-indigo-600 transition"
                    >
                      Sesuai Klaim
                    </button>
                    {selectedReceipt.ocrNominal > 0 && selectedReceipt.ocrNominal !== selectedReceipt.klaim && (
                      <button
                        type="button"
                        onClick={() => setApprovedAmountInput(String(selectedReceipt.ocrNominal))}
                        className="px-2 py-0.5 rounded bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-[10px] font-semibold text-slate-600 hover:text-indigo-600 transition"
                      >
                        Sesuai OCR
                      </button>
                    )}
                  </div>
                </div>
                <div className="relative">
                  <span className="absolute left-3 top-2.5 text-xs font-bold text-slate-400 font-mono">Rp</span>
                  <input
                    type="number"
                    value={approvedAmountInput}
                    onChange={(e) => setApprovedAmountInput(e.target.value)}
                    placeholder={String(selectedReceipt.klaim)}
                    className="w-full text-xs p-2.5 pl-9 border border-indigo-200 dark:border-indigo-800 rounded-xl bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-1 focus:ring-indigo-500 font-mono font-bold"
                  />
                </div>
                {Number(approvedAmountInput) < selectedReceipt.klaim && (
                  <p className="text-[10px] text-amber-600 dark:text-amber-400 font-medium">
                    ⚠️ Nominal disetujui lebih kecil dari klaim awal. Wajib isi catatan penjelasan di bawah.
                  </p>
                )}
              </div>

              {/* Notes Form */}
              <div className="space-y-1.5">
                <label className="text-xs font-semibold text-slate-700 dark:text-slate-300 block">
                  Catatan Verifikasi / Alasan Penyesuaian / Alasan Penolakan {Number(approvedAmountInput) < selectedReceipt.klaim ? '(Wajib Diisi)' : ''}
                </label>
                <textarea
                  rows={2}
                  placeholder="Tulis catatan atau alasan di sini..."
                  value={rejectionNote}
                  onChange={(e) => setRejectionNote(e.target.value)}
                  className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50/50 dark:bg-slate-800/20 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-1 focus:ring-indigo-500 transition"
                />
              </div>
            </div>

            {/* Modal Sticky Footer */}
            <div className="p-4 sm:p-5 pt-3 border-t border-slate-100 dark:border-slate-800 flex gap-2.5 bg-slate-50/50 dark:bg-slate-900/50 shrink-0">
              <button
                onClick={submitReject}
                className="flex-1 py-2.5 px-4 bg-rose-600 hover:bg-rose-700 text-white font-medium rounded-xl text-xs transition cursor-pointer"
              >
                Tolak Pengajuan
              </button>
              <button
                onClick={submitApprove}
                className="flex-1 py-2.5 px-4 bg-emerald-600 hover:bg-emerald-700 text-white font-medium rounded-xl text-xs transition cursor-pointer"
              >
                {Number(approvedAmountInput) < selectedReceipt.klaim ? 'Setujui Sebagian' : 'Setujui Pengajuan'}
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

      {/* Fullscreen Image Preview Modal with Zoom & Rotate */}
      {showImagePreview && imageUrl && (
        <div className="fixed inset-0 z-[60] bg-black/95 flex flex-col items-center justify-center p-4">
          {/* Toolbar */}
          <div className="absolute top-0 left-0 right-0 bg-gradient-to-b from-black/80 to-transparent p-4 flex items-center justify-between">
            <div className="text-white text-sm font-medium flex items-center gap-2">
              <span>Zoom: <strong className="font-mono">{zoomLevel}%</strong></span>
              {rotation !== 0 && (
                <span className="text-xs text-indigo-400 bg-indigo-950/60 px-2 py-0.5 rounded-full border border-indigo-800/60">
                  Rotasi: {rotation}°
                </span>
              )}
            </div>
            <div className="flex items-center gap-1.5 sm:gap-2">
              <button
                onClick={() => setZoomLevel(prev => Math.max(prev - 10, 50))}
                className="p-2 hover:bg-white/20 rounded-lg text-white transition"
                title="Zoom out (-)"
              >
                <ZoomOut className="w-5 h-5" />
              </button>
              <button
                onClick={() => {
                  setZoomLevel(100);
                  setRotation(0);
                }}
                className="px-2.5 py-1.5 hover:bg-white/20 rounded-lg text-white text-xs font-medium transition"
                title="Reset zoom & rotasi (0)"
              >
                Reset
              </button>
              <button
                onClick={() => setZoomLevel(prev => Math.min(prev + 10, 300))}
                className="p-2 hover:bg-white/20 rounded-lg text-white transition"
                title="Zoom in (+)"
              >
                <ZoomIn className="w-5 h-5" />
              </button>

              <div className="h-5 w-px bg-white/20 mx-1" />

              <button
                onClick={() => setRotation(prev => (prev - 90 + 360) % 360)}
                className="p-2 hover:bg-white/20 rounded-lg text-white transition"
                title="Putar ke Kiri (-90°)"
              >
                <RotateCcw className="w-5 h-5" />
              </button>
              <button
                onClick={() => setRotation(prev => (prev + 90) % 360)}
                className="p-2 hover:bg-white/20 rounded-lg text-white transition"
                title="Putar ke Kanan (+90° / Shortcut R)"
              >
                <RotateCw className="w-5 h-5" />
              </button>

              <div className="h-5 w-px bg-white/20 mx-1" />

              <button
                onClick={() => setShowImagePreview(false)}
                className="p-2 hover:bg-white/20 rounded-lg text-white transition"
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
                  transform: `scale(${zoomLevel / 100}) rotate(${rotation}deg)`,
                  maxWidth: rotation % 180 !== 0 ? '75vh' : '90vw',
                  maxHeight: rotation % 180 !== 0 ? '75vw' : '85vh',
                  transition: 'transform 200ms cubic-bezier(0.2, 0, 0, 1)',
                }}
                loading="lazy"
              />
            </div>
          </div>

          {/* Footer Info */}
          <div className="absolute bottom-0 left-0 right-0 bg-gradient-to-t from-black/80 to-transparent p-4 text-center text-white/70 text-xs">
            <p>
              Gunakan <kbd className="px-2 py-1 bg-white/10 rounded text-white/90 font-mono">+</kbd> / <kbd className="px-2 py-1 bg-white/10 rounded text-white/90 font-mono">-</kbd> atau scroll untuk zoom • <kbd className="px-2 py-1 bg-white/10 rounded text-white/90 font-mono">R</kbd> untuk rotate • <kbd className="px-2 py-1 bg-white/10 rounded text-white/90 font-mono">0</kbd> untuk reset • <kbd className="px-2 py-1 bg-white/10 rounded text-white/90 font-mono">ESC</kbd> untuk tutup
            </p>
          </div>
        </div>
      )}

      {/* ─── MODAL PERBANDINGAN STRUK DUPLIKAT BERDAMPINGAN ─── */}
      {showCompareModal && selectedReceipt && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/70 backdrop-blur-xs">
          <div className="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl border border-slate-200 dark:border-slate-800 w-full max-w-5xl max-h-[90vh] flex flex-col overflow-hidden animate-in fade-in zoom-in-95 duration-150">
            {/* Modal Header */}
            <div className="px-6 py-4 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between bg-purple-50/50 dark:bg-purple-950/20">
              <div className="flex items-center gap-2.5">
                <div className="p-2 bg-purple-100 dark:bg-purple-900/50 text-purple-600 dark:text-purple-300 rounded-xl">
                  <ShieldAlert className="w-5 h-5" />
                </div>
                <div>
                  <h3 className="text-sm font-bold text-slate-900 dark:text-slate-100">
                    Perbandingan Struk Terindikasi Duplikat (Side-by-Side)
                  </h3>
                  <p className="text-xs text-slate-500 dark:text-slate-400">
                    {selectedReceipt.duplicateReason || 'Bandingkan foto dan rincian struk baru dengan struk referensi yang sudah ada.'}
                  </p>
                </div>
              </div>
              <button
                onClick={() => setShowCompareModal(false)}
                className="p-1.5 rounded-lg text-slate-400 hover:text-slate-600 hover:bg-slate-100 dark:hover:bg-slate-800 transition cursor-pointer"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            {/* Modal Body - 2 Columns */}
            <div className="flex-1 overflow-y-auto p-6 grid grid-cols-1 md:grid-cols-2 gap-6 bg-slate-50/50 dark:bg-slate-950/50">
              {/* Kolom Kiri: Struk Baru (Saat Ini) */}
              <div className="bg-white dark:bg-slate-900 rounded-xl border-2 border-purple-300 dark:border-purple-700/60 p-4 flex flex-col shadow-xs">
                <div className="flex items-center justify-between pb-3 border-b border-slate-100 dark:border-slate-800 mb-3">
                  <div>
                    <span className="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider bg-purple-100 dark:bg-purple-900 text-purple-700 dark:text-purple-300">
                      Struk yang Diajukan (Baru)
                    </span>
                    <h4 className="text-sm font-bold text-slate-800 dark:text-slate-200 mt-1 font-mono">
                      #{selectedReceipt.id} ({selectedReceipt.kategori})
                    </h4>
                  </div>
                  <span className="text-base font-extrabold text-purple-600 dark:text-purple-400 font-mono">
                    {formatCurrency(selectedReceipt.klaim)}
                  </span>
                </div>

                <div className="space-y-1.5 text-xs mb-3 text-slate-600 dark:text-slate-300">
                  <div className="flex justify-between">
                    <span className="text-slate-400">Karyawan:</span>
                    <span className="font-semibold">{selectedReceipt.karyawan} ({selectedReceipt.departemen})</span>
                  </div>
                  <div className="flex justify-between">
                    <span className="text-slate-400">Merchant:</span>
                    <span className="font-semibold">{selectedReceipt.merchant}</span>
                  </div>
                  <div className="flex justify-between">
                    <span className="text-slate-400">Tanggal:</span>
                    <span className="font-semibold">{selectedReceipt.tanggal}</span>
                  </div>
                </div>

                {/* Gambar Struk Baru */}
                <div className="flex-1 min-h-[280px] bg-slate-100 dark:bg-slate-950 rounded-lg flex items-center justify-center overflow-hidden border border-slate-200 dark:border-slate-800 p-2">
                  {loadingImage ? (
                    <div className="flex flex-col items-center gap-2 text-slate-400 text-xs">
                      <RefreshCw className="w-5 h-5 animate-spin" />
                      <span>Memuat gambar...</span>
                    </div>
                  ) : imageUrl ? (
                    <img src={imageUrl} alt="Struk Baru" className="max-h-[320px] w-auto object-contain rounded shadow-xs" />
                  ) : (
                    <span className="text-xs text-slate-400">Gambar tidak tersedia</span>
                  )}
                </div>
              </div>

              {/* Kolom Kanan: Struk Asli / Referensi */}
              <div className="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-4 flex flex-col shadow-xs">
                <div className="flex items-center justify-between pb-3 border-b border-slate-100 dark:border-slate-800 mb-3">
                  <div>
                    <span className="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300">
                      Struk Referensi Asli (Terdahulu)
                    </span>
                    <h4 className="text-sm font-bold text-slate-800 dark:text-slate-200 mt-1 font-mono">
                      {selectedReceipt.duplicateReceiptNumber || `#${selectedReceipt.duplicateReferenceId}`}
                    </h4>
                  </div>
                  <span className="text-base font-extrabold text-slate-700 dark:text-slate-300 font-mono">
                    {selectedReceipt.duplicateTotalAmount ? formatCurrency(selectedReceipt.duplicateTotalAmount) : formatCurrency(selectedReceipt.duplicateReference?.totalAmount ?? selectedReceipt.klaim)}
                  </span>
                </div>

                <div className="space-y-1.5 text-xs mb-3 text-slate-600 dark:text-slate-300">
                  <div className="flex justify-between">
                    <span className="text-slate-400">Pengunggah Asli:</span>
                    <span className="font-semibold">
                      {selectedReceipt.duplicateReference?.uploaderName || 'Karyawan Lain'} {selectedReceipt.duplicateReference?.department ? `(${selectedReceipt.duplicateReference.department})` : ''}
                    </span>
                  </div>
                  <div className="flex justify-between">
                    <span className="text-slate-400">Tanggal:</span>
                    <span className="font-semibold">
                      {selectedReceipt.duplicateReference?.receiptDate || selectedReceipt.tanggal}
                    </span>
                  </div>
                </div>

                {/* Gambar Struk Asli */}
                <div className="flex-1 min-h-[280px] bg-slate-100 dark:bg-slate-950 rounded-lg flex items-center justify-center overflow-hidden border border-slate-200 dark:border-slate-800 p-2">
                  {loadingCompareImage ? (
                    <div className="flex flex-col items-center gap-2 text-slate-400 text-xs">
                      <RefreshCw className="w-5 h-5 animate-spin" />
                      <span>Memuat gambar referensi...</span>
                    </div>
                  ) : compareImageUrl ? (
                    <img src={compareImageUrl} alt="Struk Referensi" className="max-h-[320px] w-auto object-contain rounded shadow-xs" />
                  ) : (
                    <span className="text-xs text-slate-400">Gambar referensi tidak ditemukan</span>
                  )}
                </div>
              </div>
            </div>

            {/* Modal Footer */}
            <div className="px-6 py-3 border-t border-slate-100 dark:border-slate-800 bg-white dark:bg-slate-900 flex justify-end gap-2">
              <button
                type="button"
                onClick={() => setShowCompareModal(false)}
                className="px-4 py-2 text-xs font-semibold text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-xl transition cursor-pointer"
              >
                Tutup Perbandingan
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};
