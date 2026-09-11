import React, { useState, useEffect, useCallback } from 'react';
import { Receipt, ReceiptStatus, AppSettings, StrukApproval, ExpenseReport, ReceiptImage } from '../types';
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
  Ban,
  CheckCircle2,
  Briefcase,
  Layers,
  FileText,
  ChevronLeft,
  ChevronRight,
  Image as ImageIcon,
} from 'lucide-react';
import { useDebounce } from '../hooks/useDebounce';
import { ConfirmationDialog } from './ConfirmationDialog';
import { receiptApi, attendanceApi, settingsApi, expenseReportApi } from '../services/endpoints';
import { ReceiptHistory } from './ReceiptHistory';
import { mapExpenseReport, parseReceiptItems, formatTanggal } from '../services/mappers';

interface ReceiptInboxProps {
  receipts: Receipt[];
  receiptHistory?: StrukApproval[];
  offices?: { id: number; office_name: string }[];
  onApprove: (id: string, catatan: string, approvedAmount?: number) => void;
  onBulkApprove?: (ids: string[], catatan?: string) => Promise<void> | void;
  onReject: (id: string, catatan: string) => void;
  onPay?: (id: string, payload: { payment_method: string; payment_ref_no?: string }) => Promise<void> | void;
  onBulkPay?: (ids: string[], payload: { payment_method: string; payment_ref_no?: string }) => Promise<void> | void;
  currentSettings: AppSettings;
  onSaveSettings: (settings: AppSettings) => void;
  onSaveBranchSettings?: (branchId: number, limits: { varianceLimit: number | null; maxClaimLimit: number | null }) => Promise<void>;
  onRefresh?: () => void;
  refreshing?: boolean;
  initialTab?: 'inbox' | 'reports' | 'history' | 'settings';
}

export const ReceiptInbox: React.FC<ReceiptInboxProps> = ({
  receipts,
  receiptHistory = [],
  offices: propOffices,
  onApprove,
  onBulkApprove,
  onReject,
  onPay,
  onBulkPay,
  currentSettings,
  onSaveSettings,
  onSaveBranchSettings,
  onRefresh,
  refreshing,
  initialTab = 'inbox',
}) => {
  const [filter, setFilter] = useState<'all' | 'flag' | 'pend' | 'dup'>('all');
  const [selectedBranch, setSelectedBranch] = useState<string>('all');
  const [offices, setOffices] = useState<{ id: number; office_name: string }[]>(propOffices || []);

  // Fetch offices if not provided via props (Finance read-only access)
  useEffect(() => {
    if (!propOffices || propOffices.length === 0) {
      attendanceApi.settings.list()
        .then((res: any) => {
          const list = res?.settings ?? [];
          if (Array.isArray(list) && list.length > 0) {
            setOffices(list);
          }
        })
        .catch(() => { });
    }
  }, [propOffices]);

  useEffect(() => {
    if (propOffices && propOffices.length > 0) {
      setOffices(propOffices);
    }
  }, [propOffices]);

  const [searchQuery, setSearchQuery] = useState('');
  const [selectedReceipt, setSelectedReceipt] = useState<Receipt | null>(null);
  const [rejectionNote, setRejectionNote] = useState('');
  const [approvedAmountInput, setApprovedAmountInput] = useState<string>('');
  const [showModal, setShowModal] = useState(false);
  const [loadingImage, setLoadingImage] = useState(false);
  const [imageUrl, setImageUrl] = useState<string | null>(null);
  const [selectedImageIndex, setSelectedImageIndex] = useState<number>(0);
  const [showImagePreview, setShowImagePreview] = useState(false);
  const [zoomLevel, setZoomLevel] = useState(100);
  const [rotation, setRotation] = useState(0);
  const [showCompareModal, setShowCompareModal] = useState<boolean>(false);
  const [compareImageUrl, setCompareImageUrl] = useState<string | null>(null);
  const [loadingCompareImage, setLoadingCompareImage] = useState<boolean>(false);
  const [localSettings, setLocalSettings] = useState<AppSettings>(currentSettings ?? { varianceLimit: 10, maxClaimLimit: 2000000 });
  const [varianceInput, setVarianceInput] = useState(String(currentSettings?.varianceLimit ?? 10));
  const [claimInput, setClaimInput] = useState(String(currentSettings?.maxClaimLimit ?? 2000000));
  const [activeTab, setActiveTab] = useState<'inbox' | 'reports' | 'history' | 'settings'>(initialTab);

  // State Laporan Pengeluaran Dinas (Bundling)
  const [expenseReports, setExpenseReports] = useState<ExpenseReport[]>([]);
  const [loadingReports, setLoadingReports] = useState<boolean>(false);
  const [selectedReport, setSelectedReport] = useState<ExpenseReport | null>(null);
  const [showReportModal, setShowReportModal] = useState<boolean>(false);
  const [reportFilter, setReportFilter] = useState<'all' | 'submitted' | 'approved' | 'rejected'>('all');
  const [reportActionNote, setReportActionNote] = useState<string>('');
  const [processingReportAction, setProcessingReportAction] = useState<boolean>(false);
  const [reportSearchQuery, setReportSearchQuery] = useState<string>('');
  const [selectedReportReceiptIds, setSelectedReportReceiptIds] = useState<number[]>([]);

  // State Preview Struk di dalam Laporan Dinas (Read-Only Modal)
  const [previewReportReceipt, setPreviewReportReceipt] = useState<any | null>(null);
  const [previewReportImageUrl, setPreviewReportImageUrl] = useState<string | null>(null);
  const [loadingPreviewReportImage, setLoadingPreviewReportImage] = useState<boolean>(false);
  const [previewReportImageIndex, setPreviewReportImageIndex] = useState<number>(0);

  // State konfigurasi per cabang
  const [selectedSettingScope, setSelectedSettingScope] = useState<'global' | number>('global');
  const [isCustomBranchLimit, setIsCustomBranchLimit] = useState(false);
  const [branchVarianceInput, setBranchVarianceInput] = useState('');
  const [branchClaimInput, setBranchClaimInput] = useState('');
  const [savingBranchSettings, setSavingBranchSettings] = useState(false);
  const [settingSuccessMessage, setSettingSuccessMessage] = useState<string | null>(null);

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
    setClaimInput(String(currentSettings?.maxClaimLimit ?? 2000000));
  }, [currentSettings]);

  // Sync input form saat beralih lingkup cabang
  useEffect(() => {
    if (selectedSettingScope === 'global') {
      setIsCustomBranchLimit(false);
      setBranchVarianceInput('');
      setBranchClaimInput('');
    } else {
      const branch = currentSettings?.branchSettings?.find((b) => b.id === selectedSettingScope);
      if (branch && (branch.varianceLimit !== null || branch.maxClaimLimit !== null)) {
        setIsCustomBranchLimit(true);
        setBranchVarianceInput(branch.varianceLimit !== null ? String(branch.varianceLimit) : String(currentSettings?.varianceLimit ?? 10));
        setBranchClaimInput(branch.maxClaimLimit !== null ? String(branch.maxClaimLimit) : String(currentSettings?.maxClaimLimit ?? 2000000));
      } else {
        setIsCustomBranchLimit(false);
        setBranchVarianceInput(String(currentSettings?.varianceLimit ?? 10));
        setBranchClaimInput(String(currentSettings?.maxClaimLimit ?? 2000000));
      }
    }
  }, [selectedSettingScope, currentSettings]);

  const handleSaveLimits = () => {
    const cleaned = varianceInput.trim();
    if (!/^\d{1,2}$/.test(cleaned)) {
      alert('Variance Limit hanya boleh diisi maksimal 2 digit angka (0 sampai 99%).');
      return;
    }
    const numVariance = Number(cleaned);
    if (numVariance < 0 || numVariance > 99) {
      alert('Variance Limit harus berupa angka antara 0 sampai 99%.');
      return;
    }
    const updated = {
      ...localSettings,
      varianceLimit: numVariance,
      maxClaimLimit: Number(claimInput) || 0,
    };
    setLocalSettings(updated);
    onSaveSettings(updated);
    setSettingSuccessMessage('Pengaturan limit default perusahaan berhasil diperbarui!');
    setTimeout(() => setSettingSuccessMessage(null), 4000);
  };

  const handleSaveBranchLimits = async () => {
    if (typeof selectedSettingScope !== 'number') return;
    setSavingBranchSettings(true);
    try {
      let vLimit: number | null = null;
      let cLimit: number | null = null;

      if (isCustomBranchLimit) {
        const cleaned = branchVarianceInput.trim();
        if (!/^\d{1,2}$/.test(cleaned)) {
          alert('Variance Limit cabang hanya boleh diisi maksimal 2 digit angka (0 sampai 99%).');
          setSavingBranchSettings(false);
          return;
        }
        const numVariance = Number(cleaned);
        if (numVariance < 0 || numVariance > 99) {
          alert('Variance Limit cabang harus berupa angka antara 0 sampai 99%.');
          setSavingBranchSettings(false);
          return;
        }
        vLimit = numVariance;
        cLimit = branchClaimInput ? Number(branchClaimInput) : 0;
      }

      if (onSaveBranchSettings) {
        await onSaveBranchSettings(selectedSettingScope, {
          varianceLimit: vLimit,
          maxClaimLimit: cLimit,
        });
      } else {
        await settingsApi.updateBranch(selectedSettingScope, {
          variance_limit: vLimit,
          max_claim_limit: cLimit,
        });
      }

      const branchName = offices.find(o => o.id === selectedSettingScope)?.office_name ?? `Cabang #${selectedSettingScope}`;
      setSettingSuccessMessage(`Pengaturan limit untuk ${branchName} berhasil disimpan!`);
      setTimeout(() => setSettingSuccessMessage(null), 4000);
      onRefresh?.();
    } catch (e: any) {
      alert(e?.message ?? 'Gagal menyimpan pengaturan cabang.');
    } finally {
      setSavingBranchSettings(false);
    }
  };

  const handleResetBranchLimit = async (branchId: number) => {
    const branchName = offices.find(o => o.id === branchId)?.office_name ?? `Cabang #${branchId}`;
    openConfirm({
      title: `Reset Limit ${branchName}`,
      message: `Apakah Anda yakin ingin mengembalikan pengaturan limit ${branchName} ke default perusahaan?`,
      confirmText: 'Ya, Kembalikan ke Default',
      type: 'warning',
      onConfirm: async () => {
        try {
          if (onSaveBranchSettings) {
            await onSaveBranchSettings(branchId, { varianceLimit: null, maxClaimLimit: null });
          } else {
            await settingsApi.updateBranch(branchId, { variance_limit: null, max_claim_limit: null });
          }
          if (selectedSettingScope === branchId) {
            setIsCustomBranchLimit(false);
          }
          setSettingSuccessMessage(`Limit ${branchName} telah dikembalikan ke default perusahaan.`);
          setTimeout(() => setSettingSuccessMessage(null), 4000);
          onRefresh?.();
        } catch (e: any) {
          alert(e?.message ?? 'Gagal me-reset limit cabang.');
        }
      },
    });
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
  }, [debouncedSearch, filter, selectedBranch]);

  const varianceLimit = currentSettings?.varianceLimit ?? 10;

  const getVarianceAnalysis = (r: Receipt) => {
    const nominalDiff = r.ocrNominal > 0 ? r.klaim - r.ocrNominal : 0;
    const absDiff = Math.abs(nominalDiff);
    const hasDiff = r.ocrNominal > 0 && absDiff > 0.01;
    const diffPct = r.variancePct !== undefined && r.variancePct !== null
      ? Number(r.variancePct)
      : (r.ocrNominal > 0 ? Math.round((absDiff / r.ocrNominal) * 100) : 0);

    // Evaluasi effective limit untuk struk ini (prioritaskan limit khusus cabang struk)
    const branchSetting = r.cabangId && currentSettings?.branchSettings
      ? currentSettings.branchSettings.find((b) => b.id === r.cabangId)
      : null;

    const effectiveVarianceLimit = r.branchVarianceLimit !== null && r.branchVarianceLimit !== undefined
      ? r.branchVarianceLimit
      : (branchSetting?.varianceLimit !== null && branchSetting?.varianceLimit !== undefined
        ? branchSetting.varianceLimit
        : (currentSettings?.varianceLimit ?? 10));

    const isBranchSpecific = (r.branchVarianceLimit !== null && r.branchVarianceLimit !== undefined) ||
      (branchSetting?.varianceLimit !== null && branchSetting?.varianceLimit !== undefined);

    // Melebihi limit jika selisih nominal ada DAN persentasenya secara matematis > effectiveVarianceLimit
    const isOverLimit = hasDiff && diffPct > effectiveVarianceLimit;
    // Masih di bawah atau sama dengan batas limit (kuning) jika ada selisih nominal tapi diffPct <= effectiveVarianceLimit
    const isWithinLimit = hasDiff && !isOverLimit;

    return {
      nominalDiff,
      absDiff,
      hasDiff,
      diffPct,
      effectiveVarianceLimit,
      isBranchSpecific,
      isOverLimit,
      isWithinLimit,
    };
  };

  // Helper evaluasi variance & duplikat khusus untuk struk di dalam Laporan Dinas (Bundling)
  const getReportReceiptVarianceAnalysis = (rcp: any) => {
    const klaim = Number(rcp.claimed_amount ?? rcp.total_amount ?? 0);
    const ocr = Number(rcp.ocr_raw_amount ?? rcp.total_amount ?? 0);
    const nominalDiff = ocr > 0 ? klaim - ocr : 0;
    const absDiff = Math.abs(nominalDiff);
    const hasDiff = ocr > 0 && absDiff > 0.01;
    const diffPct = rcp.variance_pct !== undefined && rcp.variance_pct !== null
      ? Number(rcp.variance_pct)
      : (ocr > 0 ? Math.round((absDiff / ocr) * 100) : 0);

    const branchSetting = selectedReport?.attendance_setting_id && currentSettings?.branchSettings
      ? currentSettings.branchSettings.find((b) => b.id === selectedReport.attendance_setting_id)
      : null;

    const effectiveVarianceLimit = branchSetting?.varianceLimit !== null && branchSetting?.varianceLimit !== undefined
      ? branchSetting.varianceLimit
      : (currentSettings?.varianceLimit ?? 10);

    const isOverLimit = hasDiff && (Boolean(rcp.variance_flag) || diffPct > effectiveVarianceLimit);
    const isWithinLimit = hasDiff && !isOverLimit;
    const isDuplicate = Boolean(rcp.is_potential_duplicate);

    return {
      klaim,
      ocr,
      nominalDiff,
      absDiff,
      hasDiff,
      diffPct,
      effectiveVarianceLimit,
      isOverLimit,
      isWithinLimit,
      isDuplicate,
      duplicateReason: rcp.duplicate_reason,
      duplicateReference: rcp.duplicate_reference || rcp.duplicateReference,
    };
  };

  const isVarianceReceipt = (r: Receipt) => {
    const analysis = getVarianceAnalysis(r);
    return r.status === 'Review' || analysis.hasDiff;
  };

  // Filter receipts by branch first so counts reflect the active branch filter
  const branchReceipts = receipts.filter((receipt) => {
    if (selectedBranch === 'all') return true;
    const branchIdStr = String(selectedBranch);
    if (receipt.cabangId !== undefined && receipt.cabangId !== null) {
      return String(receipt.cabangId) === branchIdStr;
    }
    if (receipt.cabang) {
      return offices.some(o => String(o.id) === branchIdStr && o.office_name.toLowerCase() === receipt.cabang?.toLowerCase());
    }
    return false;
  });

  // Filter receipt list
  const filteredReceipts = branchReceipts.filter((receipt) => {
    const matchesSearch =
      receipt.karyawan.toLowerCase().includes(debouncedSearch.toLowerCase()) ||
      receipt.merchant.toLowerCase().includes(debouncedSearch.toLowerCase()) ||
      (receipt.kategori && receipt.kategori.toLowerCase().includes(debouncedSearch.toLowerCase()));

    const isFlagged = isVarianceReceipt(receipt);
    const isPending = !isFlagged;
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

    const selectedReceipts = receipts.filter(r => selectedIds.includes(r.id));
    const overLimitSelected = selectedReceipts.filter(r => getVarianceAnalysis(r).isOverLimit);
    const validSelected = selectedReceipts.filter(r => !getVarianceAnalysis(r).isOverLimit);

    if (overLimitSelected.length > 0 && validSelected.length === 0) {
      alert(`Semua (${overLimitSelected.length}) struk terpilih melebihi batas toleransi Variance Limit cabangnya masing-masing dan tidak dapat disetujui Finance.`);
      return;
    }

    openConfirm({
      title: 'Setujui Masal Struk Terpilih',
      message: (
        <div className="space-y-2 text-xs">
          <p>
            Apakah Anda yakin ingin menyetujui sekaligus <strong>{validSelected.length} struk</strong> terpilih? Struk yang disetujui akan siap masuk antrean pencairan/transfer.
          </p>
          {overLimitSelected.length > 0 && (
            <div className="p-2.5 rounded-lg bg-rose-50 dark:bg-rose-950/40 border border-rose-200 dark:border-rose-900/60 text-rose-700 dark:text-rose-300 font-medium">
              ⚠️ <strong>{overLimitSelected.length} struk</strong> melebihi batas toleransi Variance Limit cabang masing-masing dan otomatis dilewati (tidak disetujui).
            </div>
          )}
        </div>
      ),
      confirmText: `Ya, Setujui (${validSelected.length})`,
      type: 'success',
      onConfirm: async () => {
        setBulkProcessing(true);
        try {
          const idsToApprove = validSelected.map(r => r.id);
          if (onBulkApprove) {
            await onBulkApprove(idsToApprove, 'Persetujuan masal via Inbox');
          } else {
            await receiptApi.bulkApprove(idsToApprove.map(Number), 'Persetujuan masal via Inbox');
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
    setSelectedImageIndex(0);
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

  // Fetch image on demand (supports multi-image per receipt)
  useEffect(() => {
    if (!showModal || !selectedReceipt) return;

    let isMounted = true;
    const loadImage = async () => {
      setLoadingImage(true);
      try {
        const imageId = selectedReceipt.images && selectedReceipt.images[selectedImageIndex]
          ? selectedReceipt.images[selectedImageIndex].id
          : undefined;
        const url = await receiptApi.fetchImageAsDataUrl(selectedReceipt.id, imageId);
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
  }, [showModal, selectedReceipt?.id, selectedImageIndex]);

  // Fetch image on demand for report receipt preview
  useEffect(() => {
    if (!previewReportReceipt) {
      if (previewReportImageUrl) {
        URL.revokeObjectURL(previewReportImageUrl);
        setPreviewReportImageUrl(null);
      }
      return;
    }

    let isMounted = true;
    const loadReportReceiptImage = async () => {
      setLoadingPreviewReportImage(true);
      try {
        const imageId = previewReportReceipt.images && previewReportReceipt.images[previewReportImageIndex]
          ? previewReportReceipt.images[previewReportImageIndex].id
          : undefined;
        const url = await receiptApi.fetchImageAsDataUrl(previewReportReceipt.id, imageId);
        if (isMounted) {
          setPreviewReportImageUrl(url);
        }
      } catch (err) {
        console.error('Failed to load report receipt image:', err);
      } finally {
        if (isMounted) setLoadingPreviewReportImage(false);
      }
    };

    loadReportReceiptImage();

    return () => {
      isMounted = false;
    };
  }, [previewReportReceipt?.id, previewReportImageIndex]);

  // Load Expense Reports on tab active or branch change
  const loadExpenseReports = useCallback(async () => {
    setLoadingReports(true);
    try {
      const branchParam = selectedBranch !== 'all' ? selectedBranch : undefined;
      const res: any = await expenseReportApi.list({ attendance_setting_id: branchParam });
      const list = Array.isArray(res?.reports)
        ? res.reports
        : (Array.isArray(res?.data) ? res.data : (Array.isArray(res) ? res : []));
      setExpenseReports(list.map(mapExpenseReport));
    } catch (e) {
      console.error('Failed to load expense reports:', e);
    } finally {
      setLoadingReports(false);
    }
  }, [selectedBranch]);

  useEffect(() => {
    if (activeTab === 'reports') {
      loadExpenseReports();
    }
  }, [activeTab, loadExpenseReports]);

  const handleOpenReportDetail = async (report: ExpenseReport) => {
    setSelectedReport(report);
    setReportActionNote('');
    setShowReportModal(true);

    const initReportReceipts = (rep: ExpenseReport) => {
      const recs = rep.receipts || [];
      const pendingOrSubmitted = recs
        .filter((r: any) => r.status === 'submitted' || r.status === 'pending')
        .map((r: any) => Number(r.id));
      setSelectedReportReceiptIds(pendingOrSubmitted.length > 0 ? pendingOrSubmitted : recs.map((r: any) => Number(r.id)));
    };

    initReportReceipts(report);

    try {
      const res: any = await expenseReportApi.show(report.id);
      if (res?.report) {
        const mapped = mapExpenseReport(res.report);
        setSelectedReport(mapped);
        initReportReceipts(mapped);
      }
    } catch (e) {
      console.error('Failed to fetch expense report details:', e);
    }
  };

  const handleToggleReportReceipt = (rcpId: number) => {
    setSelectedReportReceiptIds((prev) =>
      prev.includes(rcpId) ? prev.filter((id) => id !== rcpId) : [...prev, rcpId]
    );
  };

  const handleToggleAllReportReceipts = () => {
    if (!selectedReport?.receipts) return;
    const selectable = selectedReport.receipts.filter((r: any) => r.status === 'submitted' || r.status === 'pending');
    if (selectedReportReceiptIds.length === selectable.length) {
      setSelectedReportReceiptIds([]);
    } else {
      setSelectedReportReceiptIds(selectable.map((r: any) => Number(r.id)));
    }
  };

  const handleApproveReport = (report: ExpenseReport) => {
    const reportUser = report.userName || report.user?.name || 'Karyawan';
    const receipts = report.receipts || [];
    const selectable = receipts.filter((r: any) => r.status === 'submitted' || r.status === 'pending');
    const approvedCount = selectedReportReceiptIds.length;
    const rejectedCount = Math.max(0, selectable.length - approvedCount);

    if (selectable.length > 0 && approvedCount === 0) {
      alert('Tidak ada struk yang dicentang untuk disetujui. Silakan centang minimal 1 struk, atau pilih "Tolak Laporan" jika ingin menolak seluruh laporan.');
      return;
    }

    const totalApprovedClaim = receipts
      .filter((r: any) => selectedReportReceiptIds.includes(Number(r.id)))
      .reduce((sum: number, r: any) => sum + Number(r.claimed_amount ?? r.total_amount ?? 0), 0);

    const isPartial = rejectedCount > 0;

    openConfirm({
      title: isPartial ? 'Persetujuan Sebagian (Partial Approval)' : 'Setujui Seluruh Laporan Pengeluaran Dinas',
      message: (
        <div className="space-y-2.5 text-xs">
          <p>
            Konfirmasi keputusan verifikasi laporan dinas <strong>{report.title}</strong> dari <strong>{reportUser}</strong>:
          </p>
          <div className="p-2.5 rounded-lg bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-200 dark:border-emerald-800 text-emerald-800 dark:text-emerald-200">
            ✓ <strong>{approvedCount} struk</strong> akan <strong>DISETUJUI</strong> senilai <strong>{formatCurrency(totalApprovedClaim)}</strong>.
          </div>
          {isPartial && (
            <div className="p-2.5 rounded-lg bg-rose-50 dark:bg-rose-950/40 border border-rose-200 dark:border-rose-800 text-rose-800 dark:text-rose-200">
              ✕ <strong>{rejectedCount} struk</strong> yang tidak dicentang akan <strong>DITOLAK</strong> secara otomatis.
            </div>
          )}
          {reportActionNote && (
            <div className="p-2 bg-slate-50 dark:bg-slate-800 rounded-lg text-slate-600 dark:text-slate-300">
              <strong>Catatan:</strong> {reportActionNote}
            </div>
          )}
        </div>
      ),
      confirmText: isPartial ? `Setujui ${approvedCount} Struk & Tolak ${rejectedCount}` : 'Ya, Setujui Seluruh Struk',
      type: isPartial ? 'warning' : 'success',
      onConfirm: async () => {
        setProcessingReportAction(true);
        try {
          await expenseReportApi.approve(
            report.id,
            reportActionNote || (isPartial ? `Disetujui sebagian (${approvedCount} struk disetujui, ${rejectedCount} ditolak)` : 'Disetujui bundle via dashboard'),
            selectedReportReceiptIds
          );
          setShowReportModal(false);
          setSelectedReport(null);
          loadExpenseReports();
          onRefresh?.();
        } catch (e: any) {
          alert(e?.message || 'Gagal memproses persetujuan laporan dinas.');
        } finally {
          setProcessingReportAction(false);
        }
      }
    });
  };

  const handleRejectReport = (report: ExpenseReport) => {
    const reportUser = report.userName || report.user?.name || 'Karyawan';
    if (!reportActionNote.trim()) {
      alert('Mohon tuliskan alasan penolakan laporan dinas.');
      return;
    }
    openConfirm({
      title: 'Tolak Laporan Pengeluaran Dinas',
      message: (
        <div className="space-y-2 text-xs">
          <p>
            Apakah Anda yakin ingin menolak laporan dinas <strong>{report.title}</strong> dari <strong>{reportUser}</strong>?
          </p>
          <p className="p-2.5 bg-rose-50 dark:bg-rose-950/40 border border-rose-200 dark:border-rose-800 text-rose-700 dark:text-rose-300">
            <strong>Alasan Penolakan:</strong> {reportActionNote}
          </p>
        </div>
      ),
      confirmText: 'Ya, Tolak Bundle',
      type: 'danger',
      onConfirm: async () => {
        setProcessingReportAction(true);
        try {
          await expenseReportApi.reject(report.id, reportActionNote);
          setShowReportModal(false);
          setSelectedReport(null);
          loadExpenseReports();
          onRefresh?.();
        } catch (e: any) {
          alert(e?.message || 'Gagal menolak laporan dinas.');
        } finally {
          setProcessingReportAction(false);
        }
      }
    });
  };

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

  const overLimitCount = branchReceipts.filter(r => getVarianceAnalysis(r).isOverLimit).length;
  const withinLimitCount = branchReceipts.filter(r => getVarianceAnalysis(r).isWithinLimit).length;
  const reviewCount = overLimitCount + withinLimitCount;
  const pendingCount = branchReceipts.filter(r => !getVarianceAnalysis(r).hasDiff).length;
  const duplicateCount = branchReceipts.filter(r => r.isPotentialDuplicate).length;

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
          <p className="text-xs text-slate-400 dark:text-slate-500">Selisih Variance</p>
          <div className="flex items-baseline gap-2 mt-1">
            <span className="text-2xl font-bold font-mono text-rose-600">{reviewCount}</span>
            <span className="text-[10px] bg-rose-50 text-rose-700 dark:bg-rose-950/40 dark:text-rose-400 px-1.5 py-0.5 rounded font-mono font-medium" title={`${overLimitCount} Merah (> Limit ${varianceLimit}%), ${withinLimitCount} Kuning (≤ Limit ${varianceLimit}%)`}>
              {overLimitCount} Merah • {withinLimitCount} Kuning
            </span>
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
            onClick={() => setActiveTab('reports')}
            className={`flex items-center gap-1.5 px-4 py-2.5 text-xs font-bold border-b-2 -mb-px transition cursor-pointer whitespace-nowrap ${
              activeTab === 'reports'
                ? 'border-indigo-500 text-indigo-600 dark:text-indigo-400'
                : 'border-transparent text-slate-400 hover:text-slate-600 dark:text-slate-500 dark:hover:text-slate-300'
            }`}
          >
            <Briefcase className="w-3.5 h-3.5" />
            <span>Laporan Dinas (Bundling)</span>
            {expenseReports.filter((r) => r.status === 'submitted').length > 0 && (
              <span className="text-[10px] px-1.5 py-0.2 rounded-full font-mono font-bold bg-amber-100 dark:bg-amber-900/50 text-amber-700 dark:text-amber-300">
                {expenseReports.filter((r) => r.status === 'submitted').length}
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
              Semua ({branchReceipts.length})
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

          <div className="flex flex-col sm:flex-row items-center gap-2 w-full sm:w-auto">
            {/* Filter Cabang Dropdown */}
            <div className="relative w-full sm:w-48 shrink-0">
              <Building2 className="absolute left-2.5 top-2.5 w-3.5 h-3.5 text-slate-400 pointer-events-none" />
              <select
                value={selectedBranch}
                onChange={(e) => setSelectedBranch(e.target.value)}
                className="w-full pl-8 pr-7 py-2 border border-slate-200 dark:border-slate-700 rounded-xl text-xs bg-slate-50 dark:bg-slate-800/50 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-1 focus:ring-indigo-500 transition cursor-pointer appearance-none truncate"
                title="Filter berdasarkan Cabang Kantor"
              >
                <option value="all">Semua Cabang {offices.length > 0 ? `(${offices.length})` : ''}</option>
                {offices.map((office) => (
                  <option key={office.id} value={String(office.id)}>
                    {office.office_name}
                  </option>
                ))}
              </select>
              <div className="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2 text-slate-400">
                <svg className="fill-current h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                  <path d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"/>
                </svg>
              </div>
            </div>

            <div className="relative w-full sm:w-60">
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
                  const { nominalDiff, absDiff, hasDiff, diffPct, effectiveVarianceLimit, isBranchSpecific, isOverLimit, isWithinLimit } = getVarianceAnalysis(receipt);
                  const isDuplicate = Boolean(receipt.isPotentialDuplicate);
                  const isSelected = selectedIds.includes(receipt.id);

                  return (
                    <tr 
                      key={receipt.id} 
                      className={`group hover:bg-slate-50/50 dark:hover:bg-slate-800/20 transition-colors ${
                        isSelected 
                          ? 'bg-indigo-50/30 dark:bg-indigo-950/20' 
                          : isDuplicate 
                            ? 'bg-purple-50/20 dark:bg-purple-950/10' 
                            : isOverLimit 
                              ? 'bg-rose-50/35 dark:bg-rose-950/20' 
                              : isWithinLimit 
                                ? 'bg-amber-50/25 dark:bg-amber-950/15' 
                                : ''
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
                            <div className="flex items-center gap-1.5 flex-wrap">
                              <span className="text-[10px] text-slate-400">{receipt.departemen}</span>
                              {receipt.cabang && (
                                <>
                                  <span className="text-slate-300 dark:text-slate-700 text-[10px]">•</span>
                                  <span className="inline-flex items-center gap-0.5 text-[9.5px] font-medium text-indigo-600 dark:text-indigo-400 bg-indigo-50 dark:bg-indigo-950/40 px-1 py-0.2 rounded">
                                    <Building2 className="w-2.5 h-2.5 shrink-0" />
                                    <span className="truncate max-w-[100px]">{receipt.cabang}</span>
                                  </span>
                                </>
                              )}
                            </div>
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
                        {isOverLimit ? (
                          <div>
                            <div className="flex items-center gap-1.5 font-bold text-rose-600 dark:text-rose-400 font-mono text-xs">
                              <AlertTriangle className="w-3.5 h-3.5 text-rose-500 shrink-0" />
                              <span>{formatCurrency(receipt.klaim)}</span>
                            </div>
                            <div className="text-[10px] text-rose-600 dark:text-rose-400 font-semibold mt-0.5 font-mono">
                              {nominalDiff > 0 ? `+${formatCurrency(nominalDiff)}` : `-${formatCurrency(absDiff)}`} ({nominalDiff > 0 ? `+${diffPct}%` : `-${diffPct}%`})
                            </div>
                          </div>
                        ) : isWithinLimit ? (
                          <div>
                            <div className="flex items-center gap-1.5 font-bold text-amber-600 dark:text-amber-400 font-mono text-xs">
                              <AlertTriangle className="w-3.5 h-3.5 text-amber-500 shrink-0" />
                              <span>{formatCurrency(receipt.klaim)}</span>
                            </div>
                            <div className="text-[10px] text-amber-600 dark:text-amber-400 font-semibold mt-0.5 font-mono">
                              {nominalDiff > 0 ? `+${formatCurrency(nominalDiff)}` : `-${formatCurrency(absDiff)}`} ({nominalDiff > 0 ? `+${diffPct}%` : `-${diffPct}%`})
                            </div>
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
                          {isOverLimit ? (
                            <span 
                              className="inline-flex items-center gap-1 text-[10px] font-bold px-2 py-0.5 rounded bg-rose-100 text-rose-800 dark:bg-rose-950/60 dark:text-rose-300 border border-rose-200 dark:border-rose-900/50 shadow-2xs"
                              title={`Selisih nominal ${diffPct}% melebihi batas Variance Limit (${effectiveVarianceLimit}%${isBranchSpecific ? ` khusus ${receipt.cabang}` : ''}). Tidak dapat disetujui Finance.`}
                            >
                              <AlertTriangle className="w-2.5 h-2.5 text-rose-600 dark:text-rose-400" />
                              Melebihi Limit ({diffPct}%)
                            </span>
                          ) : isWithinLimit ? (
                            <span 
                              className="inline-flex items-center gap-1 text-[10px] font-bold px-2 py-0.5 rounded bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300 border border-amber-200 dark:border-amber-800 shadow-2xs"
                              title={`Selisih nominal ${diffPct}% masih di bawah batas toleransi Variance Limit (${effectiveVarianceLimit}%${isBranchSpecific ? ` khusus ${receipt.cabang}` : ''}). Aman disetujui.`}
                            >
                              <AlertTriangle className="w-2.5 h-2.5 text-amber-600 dark:text-amber-400" />
                              Beda Nominal (≤{effectiveVarianceLimit}%)
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
                          {isOverLimit ? (
                            <button
                              type="button"
                              disabled
                              title={`Tidak dapat disetujui: Selisih nominal (${diffPct}%) melebihi batas Variance Limit (${effectiveVarianceLimit}%${isBranchSpecific ? ` khusus ${receipt.cabang}` : ''}).`}
                              className="p-1 px-2.5 bg-slate-100 dark:bg-slate-800 text-slate-400 dark:text-slate-500 border border-slate-200 dark:border-slate-700 rounded-md text-[11px] font-medium transition flex items-center gap-1 cursor-not-allowed opacity-60"
                            >
                              <Ban className="w-3 h-3 text-rose-500" />
                              <span>Blokir ACC</span>
                            </button>
                          ) : !isDuplicate ? (
                            <button
                              onClick={() => handleActionClick(receipt, true)}
                              className="p-1 px-2.5 bg-emerald-600 text-white hover:bg-emerald-700 rounded-md text-[11px] font-medium transition flex items-center gap-1 cursor-pointer"
                              title="Setujui struk ini"
                            >
                              <Check className="w-3.5 h-3.5" />
                              Setuju
                            </button>
                          ) : null}
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
        ) : activeTab === 'reports' ? (
          /* Laporan Pengeluaran Dinas (Bundling) Tab Content */
          <div className="space-y-4">
            {/* Filter & Search Bar */}
            <div className="flex flex-col sm:flex-row gap-3 justify-between items-center pb-3 border-b border-slate-100 dark:border-slate-800">
              <div className="flex flex-wrap gap-2 w-full sm:w-auto">
                <button
                  type="button"
                  onClick={() => setReportFilter('all')}
                  className={`px-3 py-1.5 rounded-full text-xs font-medium transition cursor-pointer ${
                    reportFilter === 'all'
                      ? 'bg-indigo-600 text-white'
                      : 'bg-slate-50 text-slate-600 hover:bg-slate-100 dark:bg-slate-800 dark:text-slate-300'
                  }`}
                >
                  Semua ({expenseReports.length})
                </button>
                <button
                  type="button"
                  onClick={() => setReportFilter('submitted')}
                  className={`px-3 py-1.5 rounded-full text-xs font-medium flex items-center gap-1.5 transition cursor-pointer ${
                    reportFilter === 'submitted'
                      ? 'bg-amber-600 text-white'
                      : 'bg-amber-50 text-amber-700 hover:bg-amber-100 dark:bg-amber-950/30 dark:text-amber-400'
                  }`}
                >
                  <AlertTriangle className="w-3.5 h-3.5" />
                  Menunggu Review ({expenseReports.filter(r => r.status === 'submitted').length})
                </button>
                <button
                  type="button"
                  onClick={() => setReportFilter('approved')}
                  className={`px-3 py-1.5 rounded-full text-xs font-medium flex items-center gap-1.5 transition cursor-pointer ${
                    reportFilter === 'approved'
                      ? 'bg-emerald-600 text-white'
                      : 'bg-emerald-50 text-emerald-700 hover:bg-emerald-100 dark:bg-emerald-950/30 dark:text-emerald-400'
                  }`}
                >
                  <Check className="w-3.5 h-3.5" />
                  Disetujui ({expenseReports.filter(r => r.status === 'approved').length})
                </button>
                <button
                  type="button"
                  onClick={() => setReportFilter('rejected')}
                  className={`px-3 py-1.5 rounded-full text-xs font-medium flex items-center gap-1.5 transition cursor-pointer ${
                    reportFilter === 'rejected'
                      ? 'bg-rose-600 text-white'
                      : 'bg-rose-50 text-rose-700 hover:bg-rose-100 dark:bg-rose-950/30 dark:text-rose-400'
                  }`}
                >
                  <Ban className="w-3.5 h-3.5" />
                  Ditolak ({expenseReports.filter(r => r.status === 'rejected').length})
                </button>
              </div>

              <div className="flex items-center gap-2.5 w-full sm:w-auto">
                <div className="relative flex-1 sm:w-64">
                  <Search className="w-3.5 h-3.5 absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
                  <input
                    type="text"
                    placeholder="Cari laporan / karyawan..."
                    value={reportSearchQuery}
                    onChange={(e) => setReportSearchQuery(e.target.value)}
                    className="w-full pl-8.5 pr-3 py-1.5 rounded-lg border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-xs text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                  />
                </div>
                <button
                  type="button"
                  onClick={loadExpenseReports}
                  disabled={loadingReports}
                  className="p-1.5 rounded-lg border border-slate-200 dark:border-slate-700 hover:bg-slate-100 dark:hover:bg-slate-800 text-slate-500 transition cursor-pointer"
                  title="Muat Ulang Laporan"
                >
                  <RefreshCw className={`w-3.5 h-3.5 ${loadingReports ? 'animate-spin' : ''}`} />
                </button>
              </div>
            </div>

            {/* Reports List Table */}
            {loadingReports ? (
              <div className="py-12 flex flex-col items-center justify-center gap-2 text-slate-400">
                <RefreshCw className="w-6 h-6 animate-spin text-indigo-500" />
                <span className="text-xs">Memuat daftar laporan dinas...</span>
              </div>
            ) : (() => {
              const filteredReports = expenseReports.filter((report) => {
                const q = reportSearchQuery.toLowerCase();
                const matchesSearch =
                  !q ||
                  report.title.toLowerCase().includes(q) ||
                  report.reportNumber.toLowerCase().includes(q) ||
                  (report.userName && report.userName.toLowerCase().includes(q));
                const matchesFilter = reportFilter === 'all' || report.status === reportFilter;
                return matchesSearch && matchesFilter;
              });

              if (filteredReports.length === 0) {
                return (
                  <div className="py-12 text-center text-slate-400 dark:text-slate-500 text-xs space-y-2">
                    <Briefcase className="w-8 h-8 mx-auto opacity-40" />
                    <p>Tidak ada laporan pengeluaran dinas yang cocok.</p>
                  </div>
                );
              }

              return (
                <div className="overflow-x-auto border border-slate-200 dark:border-slate-800 rounded-xl">
                  <table className="w-full text-left text-xs">
                    <thead className="bg-slate-50 dark:bg-slate-800/60 text-slate-500 dark:text-slate-400 font-semibold border-b border-slate-200 dark:border-slate-800">
                      <tr>
                        <th className="py-2.5 px-4">No. Laporan</th>
                        <th className="py-2.5 px-4">Judul & Periode Dinas</th>
                        <th className="py-2.5 px-4">Karyawan & Cabang</th>
                        <th className="py-2.5 px-4 text-center">Jml Struk</th>
                        <th className="py-2.5 px-4 text-right">Total Klaim</th>
                        <th className="py-2.5 px-4 text-center">Status</th>
                        <th className="py-2.5 px-4 text-right">Aksi</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                      {filteredReports.map((report) => (
                        <tr key={report.id} className="hover:bg-slate-50/60 dark:hover:bg-slate-800/40 transition">
                          <td className="py-3 px-4 font-mono font-bold text-indigo-600 dark:text-indigo-400">
                            {report.reportNumber}
                          </td>
                          <td className="py-3 px-4">
                            <div className="font-semibold text-slate-800 dark:text-slate-200">{report.title}</div>
                            <div className="text-[11px] text-slate-400 flex items-center gap-1 mt-0.5">
                              <Calendar className="w-3 h-3 text-slate-400" />
                              <span>{report.startDate} s/d {report.endDate}</span>
                            </div>
                          </td>
                          <td className="py-3 px-4">
                            <div className="font-semibold text-slate-700 dark:text-slate-200">{report.userName}</div>
                            <div className="text-[11px] text-slate-400 flex items-center gap-1">
                              <Building2 className="w-3 h-3 text-indigo-400" />
                              <span>{report.branchName || 'Kantor Pusat'}</span>
                            </div>
                          </td>
                          <td className="py-3 px-4 text-center">
                            <span className="px-2 py-0.5 rounded-full bg-slate-100 dark:bg-slate-800 font-mono font-bold text-slate-700 dark:text-slate-300 text-[11px]">
                              {report.totalReceipts} Struk
                            </span>
                          </td>
                          <td className="py-3 px-4 text-right font-mono font-bold text-slate-800 dark:text-slate-100">
                            {formatCurrency(report.totalClaimedAmount)}
                          </td>
                          <td className="py-3 px-4 text-center">
                            {report.status === 'submitted' ? (
                              <span className="px-2 py-0.5 rounded-full bg-amber-100 dark:bg-amber-950/60 text-amber-700 dark:text-amber-300 font-semibold text-[10.5px]">
                                Menunggu Review
                              </span>
                            ) : report.status === 'approved' ? (
                              <span className="px-2 py-0.5 rounded-full bg-emerald-100 dark:bg-emerald-950/60 text-emerald-700 dark:text-emerald-300 font-semibold text-[10.5px]">
                                Disetujui
                              </span>
                            ) : report.status === 'rejected' ? (
                              <span className="px-2 py-0.5 rounded-full bg-rose-100 dark:bg-rose-950/60 text-rose-700 dark:text-rose-300 font-semibold text-[10.5px]">
                                Ditolak
                              </span>
                            ) : (
                              <span className="px-2 py-0.5 rounded-full bg-slate-100 text-slate-600 text-[10.5px]">
                                Draft
                              </span>
                            )}
                          </td>
                          <td className="py-3 px-4 text-right">
                            <button
                              type="button"
                              onClick={() => handleOpenReportDetail(report)}
                              className="px-3 py-1 bg-indigo-50 dark:bg-indigo-950/50 hover:bg-indigo-100 text-indigo-600 dark:text-indigo-400 border border-indigo-200 dark:border-indigo-800/60 rounded-lg text-xs font-semibold transition cursor-pointer"
                            >
                              Review Bundle
                            </button>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              );
            })()}
          </div>
        ) : activeTab === 'history' ? (
          /* History & Disbursement Tab Content */
          <div className="-m-5">
            <ReceiptHistory
              approvals={receiptHistory}
              offices={offices}
              onPay={onPay}
              onBulkPay={onBulkPay}
              onRefresh={onRefresh}
              refreshing={refreshing}
            />
          </div>
        ) : (
          /* Settings Tab Content */
          <div className="space-y-6">
            {/* Header & Success Banner */}
            {settingSuccessMessage && (
              <div className="bg-emerald-50 dark:bg-emerald-950/30 border border-emerald-200 dark:border-emerald-800 rounded-xl p-4 flex items-center gap-3 text-xs text-emerald-800 dark:text-emerald-300 animate-in fade-in duration-200">
                <CheckCircle2 className="w-5 h-5 text-emerald-600 dark:text-emerald-400 shrink-0" />
                <span className="font-semibold">{settingSuccessMessage}</span>
              </div>
            )}

            {/* Scope Selection Bar */}
            <div className="bg-slate-50 dark:bg-slate-800/40 border border-slate-200 dark:border-slate-800 rounded-xl p-4 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
              <div>
                <label className="text-xs font-bold text-slate-700 dark:text-slate-300 block">
                  Pilih Lingkup Pengaturan Limit
                </label>
                <p className="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">
                  Atur limit default global perusahaan atau tentukan batas khusus untuk cabang tertentu.
                </p>
              </div>

              <div className="flex items-center gap-2 w-full sm:w-auto">
                <Building2 className="w-4 h-4 text-indigo-600 dark:text-indigo-400 shrink-0" />
                <select
                  value={selectedSettingScope}
                  onChange={(e) => {
                    const val = e.target.value === 'global' ? 'global' : Number(e.target.value);
                    setSelectedSettingScope(val);
                  }}
                  className="w-full sm:w-64 px-3 py-2 text-xs font-semibold rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-indigo-500 cursor-pointer shadow-2xs"
                >
                  <option value="global">🏢 Default Perusahaan (Semua Cabang)</option>
                  <optgroup label="Limit Khusus Per Cabang">
                    {offices.map((office) => {
                      const branchSetting = currentSettings?.branchSettings?.find((b) => b.id === office.id);
                      const isCustom = branchSetting && (branchSetting.varianceLimit !== null || branchSetting.maxClaimLimit !== null);
                      return (
                        <option key={office.id} value={office.id}>
                          📍 {office.office_name} {isCustom ? '★ (Khusus)' : ''}
                        </option>
                      );
                    })}
                  </optgroup>
                </select>
              </div>
            </div>

            {/* Form Section */}
            {selectedSettingScope === 'global' ? (
              /* Global Settings Form */
              <div className="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl p-5 space-y-4 shadow-2xs">
                <div className="border-b border-slate-100 dark:border-slate-800 pb-3 flex items-center justify-between">
                  <div>
                    <h3 className="text-sm font-bold text-slate-800 dark:text-slate-100 flex items-center gap-2">
                      <span>Pengaturan Limit Default Perusahaan</span>
                      <span className="text-[10px] px-2 py-0.5 rounded-full bg-indigo-50 dark:bg-indigo-950/50 text-indigo-700 dark:text-indigo-300 font-medium">
                        Berlaku Global
                      </span>
                    </h3>
                    <p className="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                      Batas ini menjadi standar acuan untuk semua cabang kantor yang belum mengonfigurasi batas khusus.
                    </p>
                  </div>
                </div>

                <div className="grid md:grid-cols-2 gap-5 pt-1">
                  <div>
                    <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">
                      Variance Limit Default (Toleransi Selisih OCR)
                    </label>
                    <div className="flex items-center gap-2">
                      <input
                        type="text"
                        inputMode="numeric"
                        maxLength={2}
                        placeholder="10"
                        value={varianceInput}
                        onChange={(e) => {
                          const onlyDigits = e.target.value.replace(/\D/g, '').slice(0, 2);
                          setVarianceInput(onlyDigits);
                        }}
                        className="w-full px-3 py-2 border border-slate-300 dark:border-slate-700 rounded-lg text-xs dark:bg-slate-800 dark:text-slate-100 font-mono font-bold focus:outline-none focus:ring-2 focus:ring-indigo-500"
                      />
                      <span className="text-xs font-bold text-slate-500">%</span>
                    </div>
                    <p className="text-[11px] text-slate-400 mt-1">
                      Maksimal 2 digit angka (0–99%). Selisih OCR & klaim di atas nilai ini otomatis diblokir dari persetujuan.
                    </p>
                  </div>

                  <div>
                    <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">
                      Maksimal Klaim per Transaksi
                    </label>
                    <div className="flex items-center gap-2">
                      <span className="text-xs font-bold text-slate-500">Rp</span>
                      <input
                        type="text"
                        inputMode="numeric"
                        placeholder="2000000"
                        value={claimInput}
                        onChange={(e) => setClaimInput(e.target.value.replace(/\D/g, ''))}
                        className="w-full px-3 py-2 border border-slate-300 dark:border-slate-700 rounded-lg text-xs dark:bg-slate-800 dark:text-slate-100 font-mono font-bold focus:outline-none focus:ring-2 focus:ring-indigo-500"
                      />
                    </div>
                    <p className="text-[11px] text-slate-400 mt-1">
                      Batas nominal maksimum dalam 1 pengajuan struk yang diizinkan sistem.
                    </p>
                  </div>
                </div>

                <div className="flex justify-end pt-3 border-t border-slate-100 dark:border-slate-800">
                  <button
                    type="button"
                    onClick={handleSaveLimits}
                    className="flex items-center gap-1.5 px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-xs font-bold transition shadow-xs cursor-pointer"
                  >
                    <Save className="w-3.5 h-3.5" />
                    Simpan Pengaturan Global
                  </button>
                </div>
              </div>
            ) : (
              /* Branch Specific Settings Form */
              (() => {
                const currentBranchName = offices.find(o => o.id === selectedSettingScope)?.office_name ?? `Cabang #${selectedSettingScope}`;
                const currentBranchSetting = currentSettings?.branchSettings?.find(b => b.id === selectedSettingScope);
                const hasExistingCustom = currentBranchSetting && (currentBranchSetting.varianceLimit !== null || currentBranchSetting.maxClaimLimit !== null);

                return (
                  <div className="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl p-5 space-y-4 shadow-2xs">
                    <div className="border-b border-slate-100 dark:border-slate-800 pb-3 flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                      <div>
                        <h3 className="text-sm font-bold text-slate-800 dark:text-slate-100 flex items-center gap-2">
                          <span>Pengaturan Limit Cabang: {currentBranchName}</span>
                          {hasExistingCustom ? (
                            <span className="text-[10px] px-2 py-0.5 rounded-full bg-emerald-50 dark:bg-emerald-950/50 text-emerald-700 dark:text-emerald-300 font-bold">
                              Batas Khusus Aktif
                            </span>
                          ) : (
                            <span className="text-[10px] px-2 py-0.5 rounded-full bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400 font-medium">
                              Mewarisi Default
                            </span>
                          )}
                        </h3>
                        <p className="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                          Tentukan apakah {currentBranchName} menggunakan aturan toleransi khusus atau mengikuti standar perusahaan.
                        </p>
                      </div>

                      {hasExistingCustom && (
                        <button
                          type="button"
                          onClick={() => handleResetBranchLimit(Number(selectedSettingScope))}
                          className="flex items-center gap-1 text-xs text-rose-600 hover:text-rose-700 hover:underline cursor-pointer"
                        >
                          <RotateCcw className="w-3.5 h-3.5" />
                          <span>Kembalikan ke Default</span>
                        </button>
                      )}
                    </div>

                    {/* Radio Options */}
                    <div className="grid sm:grid-cols-2 gap-3">
                      <label className={`p-3.5 rounded-xl border-2 flex items-start gap-3 cursor-pointer transition ${
                        !isCustomBranchLimit
                          ? 'border-indigo-500 bg-indigo-50/40 dark:bg-indigo-950/20'
                          : 'border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 hover:bg-slate-50 dark:hover:bg-slate-800/40'
                      }`}>
                        <input
                          type="radio"
                          name="branch_policy_mode"
                          checked={!isCustomBranchLimit}
                          onChange={() => setIsCustomBranchLimit(false)}
                          className="mt-0.5 text-indigo-600 focus:ring-indigo-500 cursor-pointer"
                        />
                        <div className="space-y-0.5">
                          <span className="text-xs font-bold text-slate-800 dark:text-slate-200 block">
                            Ikuti Default Global Perusahaan
                          </span>
                          <span className="text-[11px] text-slate-500 dark:text-slate-400 block leading-relaxed">
                            Mewarisi Variance Limit ({currentSettings?.varianceLimit ?? 10}%) dan Maks. Klaim ({formatCurrency(currentSettings?.maxClaimLimit ?? 2000000)}).
                          </span>
                        </div>
                      </label>

                      <label className={`p-3.5 rounded-xl border-2 flex items-start gap-3 cursor-pointer transition ${
                        isCustomBranchLimit
                          ? 'border-indigo-500 bg-indigo-50/40 dark:bg-indigo-950/20'
                          : 'border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 hover:bg-slate-50 dark:hover:bg-slate-800/40'
                      }`}>
                        <input
                          type="radio"
                          name="branch_policy_mode"
                          checked={isCustomBranchLimit}
                          onChange={() => setIsCustomBranchLimit(true)}
                          className="mt-0.5 text-indigo-600 focus:ring-indigo-500 cursor-pointer"
                        />
                        <div className="space-y-0.5">
                          <span className="text-xs font-bold text-slate-800 dark:text-slate-200 block">
                            Tentukan Batas Khusus Cabang Ini
                          </span>
                          <span className="text-[11px] text-slate-500 dark:text-slate-400 block leading-relaxed">
                            Atur nilai limit khusus yang hanya berlaku untuk transaksi struk di {currentBranchName}.
                          </span>
                        </div>
                      </label>
                    </div>

                    {/* Inputs (Active only when isCustomBranchLimit is true) */}
                    {isCustomBranchLimit && (
                      <div className="grid md:grid-cols-2 gap-5 pt-2 animate-in fade-in duration-150">
                        <div>
                          <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">
                            Variance Limit Cabang {currentBranchName} (%)
                          </label>
                          <div className="flex items-center gap-2">
                            <input
                              type="text"
                              inputMode="numeric"
                              maxLength={2}
                              placeholder={String(currentSettings?.varianceLimit ?? 10)}
                              value={branchVarianceInput}
                              onChange={(e) => {
                                const onlyDigits = e.target.value.replace(/\D/g, '').slice(0, 2);
                                setBranchVarianceInput(onlyDigits);
                              }}
                              className="w-full px-3 py-2 border border-slate-300 dark:border-slate-700 rounded-lg text-xs dark:bg-slate-800 dark:text-slate-100 font-mono font-bold focus:outline-none focus:ring-2 focus:ring-indigo-500"
                            />
                            <span className="text-xs font-bold text-slate-500">%</span>
                          </div>
                          <p className="text-[11px] text-slate-400 mt-1">
                            Toleransi variansi struk karyawan {currentBranchName} (0–99%).
                          </p>
                        </div>

                        <div>
                          <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">
                            Maksimal Klaim per Transaksi (Rp)
                          </label>
                          <div className="flex items-center gap-2">
                            <span className="text-xs font-bold text-slate-500">Rp</span>
                            <input
                              type="text"
                              inputMode="numeric"
                              placeholder={String(currentSettings?.maxClaimLimit ?? 2000000)}
                              value={branchClaimInput}
                              onChange={(e) => setBranchClaimInput(e.target.value.replace(/\D/g, ''))}
                              className="w-full px-3 py-2 border border-slate-300 dark:border-slate-700 rounded-lg text-xs dark:bg-slate-800 dark:text-slate-100 font-mono font-bold focus:outline-none focus:ring-2 focus:ring-indigo-500"
                            />
                          </div>
                          <p className="text-[11px] text-slate-400 mt-1">
                            Batas maksimal per klaim untuk karyawan {currentBranchName}.
                          </p>
                        </div>
                      </div>
                    )}

                    <div className="flex justify-end gap-2 pt-3 border-t border-slate-100 dark:border-slate-800">
                      <button
                        type="button"
                        onClick={handleSaveBranchLimits}
                        disabled={savingBranchSettings}
                        className="flex items-center gap-1.5 px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-xs font-bold transition shadow-xs disabled:opacity-50 cursor-pointer"
                      >
                        {savingBranchSettings ? (
                          <RefreshCw className="w-3.5 h-3.5 animate-spin" />
                        ) : (
                          <Save className="w-3.5 h-3.5" />
                        )}
                        Simpan Limit {currentBranchName}
                      </button>
                    </div>
                  </div>
                );
              })()
            )}

            {/* Summary Table: Semua Cabang Kantor */}
            <div className="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl overflow-hidden shadow-2xs">
              <div className="p-4 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
                <div>
                  <h4 className="text-xs font-bold text-slate-800 dark:text-slate-200 flex items-center gap-2">
                    <Building2 className="w-4 h-4 text-indigo-500" />
                    <span>Daftar Konfigurasi Limit Seluruh Cabang Kantor</span>
                  </h4>
                  <p className="text-[11px] text-slate-400 mt-0.5">
                    Ringkasan batasan variansi dan plafon klaim untuk seluruh kantor cabang perusahaan.
                  </p>
                </div>
              </div>

              <div className="overflow-x-auto">
                <table className="w-full text-left text-xs">
                  <thead className="bg-slate-50 dark:bg-slate-800/60 text-slate-500 dark:text-slate-400 font-semibold border-b border-slate-200 dark:border-slate-800">
                    <tr>
                      <th className="py-2.5 px-4">Nama Cabang</th>
                      <th className="py-2.5 px-4">Variance Limit</th>
                      <th className="py-2.5 px-4">Maks. Klaim per Transaksi</th>
                      <th className="py-2.5 px-4">Status Kebijakan</th>
                      <th className="py-2.5 px-4 text-right">Aksi</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                    {offices.length === 0 ? (
                      <tr>
                        <td colSpan={5} className="py-6 text-center text-slate-400">
                          Tidak ada data cabang kantor.
                        </td>
                      </tr>
                    ) : (
                      offices.map((office) => {
                        const branchSetting = currentSettings?.branchSettings?.find((b) => b.id === office.id);
                        const hasCustomVariance = branchSetting?.varianceLimit !== null && branchSetting?.varianceLimit !== undefined;
                        const hasCustomClaim = branchSetting?.maxClaimLimit !== null && branchSetting?.maxClaimLimit !== undefined;
                        const isCustom = hasCustomVariance || hasCustomClaim;

                        const effectiveVar = hasCustomVariance
                          ? `${branchSetting.varianceLimit}%`
                          : `${currentSettings?.varianceLimit ?? 10}% (Default)`;

                        const effectiveClaim = hasCustomClaim
                          ? formatCurrency(branchSetting.maxClaimLimit!)
                          : `${formatCurrency(currentSettings?.maxClaimLimit ?? 2000000)} (Default)`;

                        return (
                          <tr key={office.id} className="hover:bg-slate-50/60 dark:hover:bg-slate-800/40 transition">
                            <td className="py-3 px-4 font-semibold text-slate-800 dark:text-slate-200">
                              📍 {office.office_name}
                            </td>
                            <td className="py-3 px-4">
                              <span className={`font-mono font-semibold ${hasCustomVariance ? 'text-emerald-600 dark:text-emerald-400 font-bold' : 'text-slate-500 dark:text-slate-400'}`}>
                                {effectiveVar}
                              </span>
                            </td>
                            <td className="py-3 px-4">
                              <span className={`font-mono font-semibold ${hasCustomClaim ? 'text-emerald-600 dark:text-emerald-400 font-bold' : 'text-slate-500 dark:text-slate-400'}`}>
                                {effectiveClaim}
                              </span>
                            </td>
                            <td className="py-3 px-4">
                              {isCustom ? (
                                <span className="inline-flex items-center gap-1 text-[10px] font-bold px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800/50">
                                  Batas Khusus
                                </span>
                              ) : (
                                <span className="inline-flex items-center gap-1 text-[10px] font-medium px-2 py-0.5 rounded-full bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-400">
                                  Default Perusahaan
                                </span>
                              )}
                            </td>
                            <td className="py-3 px-4 text-right">
                              <div className="flex items-center justify-end gap-1.5">
                                <button
                                  type="button"
                                  onClick={() => {
                                    setSelectedSettingScope(office.id);
                                    window.scrollTo({ top: 200, behavior: 'smooth' });
                                  }}
                                  className="px-2.5 py-1 text-[11px] font-semibold text-indigo-600 dark:text-indigo-400 hover:bg-indigo-50 dark:hover:bg-indigo-950/40 rounded-lg transition cursor-pointer"
                                >
                                  Edit Limit
                                </button>
                                {isCustom && (
                                  <button
                                    type="button"
                                    onClick={() => handleResetBranchLimit(office.id)}
                                    className="px-2 py-1 text-[11px] font-medium text-rose-600 dark:text-rose-400 hover:bg-rose-50 dark:hover:bg-rose-950/40 rounded-lg transition cursor-pointer"
                                    title="Kembalikan ke Default"
                                  >
                                    Reset
                                  </button>
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
          </div>
        )}
        </div>
      </div>

      {/* Detail & Approval Modal */}
      {showModal && selectedReceipt && (() => {
        const isActionable = selectedReceipt.status === 'Pending' || selectedReceipt.status === 'Menunggu' || selectedReceipt.status === 'Review';

        return (
        <div className="fixed inset-0 z-50 bg-slate-900/40 dark:bg-slate-950/60 backdrop-blur-xs flex items-center justify-center p-4">
          <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-100 dark:border-slate-800 w-full max-w-lg max-h-[88vh] flex flex-col shadow-2xl relative animate-in fade-in zoom-in duration-200 overflow-hidden">
            
            {/* Modal Header */}
            <div className="p-4 sm:p-5 pb-3 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between shrink-0">
              <div className="flex items-center gap-2">
                <FileSpreadsheet className="w-4.5 h-4.5 text-indigo-600 shrink-0" />
                <h3 className="text-sm font-bold text-slate-900 dark:text-slate-100">
                  {isActionable ? `Verifikasi Struk — ${selectedReceipt.karyawan}` : `Detail Struk — ${selectedReceipt.nomorStruk || selectedReceipt.karyawan}`}
                </h3>
                <span className={`px-2 py-0.5 rounded-full text-[10px] font-semibold ${
                  selectedReceipt.status === 'Disetujui'
                    ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300'
                    : selectedReceipt.status === 'Ditolak'
                    ? 'bg-rose-100 text-rose-700 dark:bg-rose-950/60 dark:text-rose-300'
                    : selectedReceipt.status === 'Dibayar'
                    ? 'bg-blue-100 text-blue-700 dark:bg-blue-950/60 dark:text-blue-300'
                    : 'bg-amber-100 text-amber-700 dark:bg-amber-950/60 dark:text-amber-300'
                }`}>
                  {selectedReceipt.status}
                </span>
              </div>
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
              {/* Status Banner when read-only / not actionable */}
              {!isActionable && (
                <div className={`p-3 rounded-xl border text-xs space-y-1 ${
                  selectedReceipt.status === 'Disetujui'
                    ? 'bg-emerald-50 dark:bg-emerald-950/40 border-emerald-200 dark:border-emerald-900/60 text-emerald-900 dark:text-emerald-200'
                    : selectedReceipt.status === 'Ditolak'
                    ? 'bg-rose-50 dark:bg-rose-950/40 border-rose-200 dark:border-rose-900/60 text-rose-900 dark:text-rose-200'
                    : selectedReceipt.status === 'Dibayar'
                    ? 'bg-blue-50 dark:bg-blue-950/40 border-blue-200 dark:border-blue-900/60 text-blue-900 dark:text-blue-200'
                    : 'bg-slate-50 dark:bg-slate-800/40 border-slate-200 dark:border-slate-800 text-slate-800'
                }`}>
                  <div className="flex items-center gap-1.5 font-bold">
                    {selectedReceipt.status === 'Disetujui' ? (
                      <CheckCircle2 className="w-4 h-4 text-emerald-600 dark:text-emerald-400" />
                    ) : selectedReceipt.status === 'Ditolak' ? (
                      <Ban className="w-4 h-4 text-rose-600 dark:text-rose-400" />
                    ) : (
                      <CheckCheck className="w-4 h-4 text-blue-600 dark:text-blue-400" />
                    )}
                    <span>Status Struk: {selectedReceipt.status}</span>
                  </div>
                  {selectedReceipt.status === 'Disetujui' && (
                    <p className="text-[11px] text-emerald-700 dark:text-emerald-300">
                      Nominal Disetujui: <strong>{formatCurrency(selectedReceipt.approvedAmount ?? selectedReceipt.klaim)}</strong>
                      {selectedReceipt.catatan ? ` • Catatan: ${selectedReceipt.catatan}` : ''}
                    </p>
                  )}
                  {selectedReceipt.status === 'Ditolak' && (
                    <p className="text-[11px] text-rose-700 dark:text-rose-300">
                      {selectedReceipt.catatan ? (
                        <><strong>Alasan Penolakan:</strong> {selectedReceipt.catatan}</>
                      ) : (
                        'Pengajuan struk ini telah ditolak oleh Finance / Verifikator.'
                      )}
                    </p>
                  )}
                  {selectedReceipt.status === 'Dibayar' && (
                    <p className="text-[11px] text-blue-700 dark:text-blue-300">
                      Klaim sebesar <strong>{formatCurrency(selectedReceipt.approvedAmount ?? selectedReceipt.klaim)}</strong> telah dicairkan ke rekening karyawan.
                    </p>
                  )}
                </div>
              )}

              {/* Compact Receipt Image Preview */}
              <div className="bg-slate-50 dark:bg-slate-950/50 rounded-xl overflow-hidden border border-dashed border-slate-200 dark:border-slate-800">
                {/* Multi-Photo Selector if multiple photos exist */}
                {selectedReceipt.images && selectedReceipt.images.length > 1 && (
                  <div className="flex items-center gap-1.5 p-2 bg-slate-100/80 dark:bg-slate-900 border-b border-slate-200 dark:border-slate-800 overflow-x-auto">
                    {selectedReceipt.images.map((img, idx) => {
                      const isPrimary = idx === 0 || img.image_type === 'primary';
                      const label = isPrimary
                        ? '1. Struk Utama'
                        : (img.image_type === 'edc_slip'
                            ? `${idx + 1}. Slip EDC`
                            : (img.image_type === 'detail' ? `${idx + 1}. Nota Rincian` : `${idx + 1}. Lampiran`));
                      return (
                        <button
                          key={img.id || idx}
                          type="button"
                          onClick={() => setSelectedImageIndex(idx)}
                          className={`px-2.5 py-1 rounded-lg text-[11px] font-semibold transition flex items-center gap-1.5 shrink-0 cursor-pointer ${
                            selectedImageIndex === idx
                              ? 'bg-white dark:bg-slate-800 text-indigo-600 dark:text-indigo-400 shadow-xs border border-slate-300 dark:border-slate-700'
                              : 'text-slate-500 hover:text-slate-800 dark:hover:text-slate-200'
                          }`}
                        >
                          <ImageIcon className="w-3 h-3" />
                          <span>{label}</span>
                        </button>
                      );
                    })}
                  </div>
                )}
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
                        <span className="truncate">
                          {selectedImageIndex === 0
                            ? 'Foto Struk Fisik Utama'
                            : (selectedReceipt.images?.[selectedImageIndex]?.image_type === 'edc_slip'
                                ? 'Lampiran: Slip EDC / Pembayaran'
                                : (selectedReceipt.images?.[selectedImageIndex]?.image_type === 'detail'
                                    ? 'Lampiran: Nota Rincian Item'
                                    : `Lampiran Foto #${selectedImageIndex + 1}`))}
                        </span>
                        {selectedReceipt.images && selectedReceipt.images.length > 1 && (
                          <span className="text-[10px] px-1.5 py-0.2 rounded bg-indigo-100 dark:bg-indigo-900/60 text-indigo-700 dark:text-indigo-300 font-mono font-bold">
                            {selectedImageIndex + 1}/{selectedReceipt.images.length}
                          </span>
                        )}
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

              {/* Variance Alert Box (Kuning jika <= limit, Merah jika > limit) */}
              {(() => {
                const { nominalDiff, absDiff, hasDiff, diffPct, effectiveVarianceLimit, isBranchSpecific, isOverLimit } = getVarianceAnalysis(selectedReceipt);
                if (!hasDiff) return null;

                if (isOverLimit) {
                  return (
                    <div className="bg-rose-50/90 dark:bg-rose-950/40 border border-rose-200 dark:border-rose-900/60 rounded-xl p-3.5 text-xs text-rose-900 dark:text-rose-200 space-y-2.5">
                      <div className="flex items-center gap-2 font-bold text-rose-700 dark:text-rose-400">
                        <AlertTriangle className="w-4 h-4 text-rose-600 dark:text-rose-400 shrink-0" />
                        <span>🚨 Peringatan Kritis: Selisih OCR vs Klaim Melebihi Variance Limit {isBranchSpecific && selectedReceipt.cabang ? `Cabang ${selectedReceipt.cabang}` : ''} ({diffPct}% &gt; {effectiveVarianceLimit}%)</span>
                      </div>
                      <div className="grid grid-cols-3 gap-2 mt-2 pt-2 border-t border-rose-100 dark:border-rose-900/50 text-center font-mono">
                        <div>
                          <label className="text-[10px] text-slate-500 dark:text-slate-400 block font-sans">Klaim Karyawan</label>
                          <span className="font-bold text-rose-600 block">{formatCurrency(selectedReceipt.klaim)}</span>
                        </div>
                        <div className="flex items-center justify-center text-slate-400 text-xs font-sans">vs</div>
                        <div>
                          <label className="text-[10px] text-slate-500 dark:text-slate-400 block font-sans">OCR Terbaca</label>
                          <span className="font-bold text-emerald-600 dark:text-emerald-400 block">{formatCurrency(selectedReceipt.ocrNominal)}</span>
                        </div>
                      </div>
                      <div className="pt-2 border-t border-rose-100 dark:border-rose-900/40 text-[11px] font-sans flex items-center justify-between">
                        <span className="text-slate-600 dark:text-slate-400 font-medium">Selisih Nominal:</span>
                        <span className="font-mono font-bold text-rose-600 dark:text-rose-400">
                          {nominalDiff > 0 ? `+${formatCurrency(nominalDiff)} (+${diffPct}%)` : `-${formatCurrency(absDiff)} (-${diffPct}%)`}
                        </span>
                      </div>
                      <div className="p-2.5 rounded-lg bg-rose-100/80 dark:bg-rose-900/40 border border-rose-200 dark:border-rose-800 text-[11px] font-medium text-rose-900 dark:text-rose-200 leading-relaxed">
                        ❌ <strong>Tidak Dapat Disetujui (Blokir ACC):</strong> Selisih nominal klaim melebihi batas toleransi Variance Limit {isBranchSpecific && selectedReceipt.cabang ? `khusus Cabang ${selectedReceipt.cabang}` : 'perusahaan'} ({effectiveVarianceLimit}%). Sesuai aturan, Finance <u>tidak dapat menyetujui</u> klaim ini. Silakan tolak pengajuan atau gunakan tombol 'Sesuai OCR' di bawah untuk menyesuaikan nominal persetujuan.
                      </div>
                    </div>
                  );
                }

                // isWithinLimit (Kuning)
                return (
                  <div className="bg-amber-50/90 dark:bg-amber-950/40 border border-amber-200 dark:border-amber-800/80 rounded-xl p-3.5 text-xs text-amber-900 dark:text-amber-200 space-y-2.5">
                    <div className="flex items-center gap-2 font-bold text-amber-700 dark:text-amber-400">
                      <AlertTriangle className="w-4 h-4 text-amber-600 dark:text-amber-400 shrink-0" />
                      <span>⚠️ Peringatan: Selisih OCR vs Klaim dalam Batas Toleransi {isBranchSpecific && selectedReceipt.cabang ? `Cabang ${selectedReceipt.cabang}` : ''} ({diffPct}% ≤ {effectiveVarianceLimit}%)</span>
                    </div>
                    <div className="grid grid-cols-3 gap-2 mt-2 pt-2 border-t border-amber-100 dark:border-amber-900/50 text-center font-mono">
                      <div>
                        <label className="text-[10px] text-slate-500 dark:text-slate-400 block font-sans">Klaim Karyawan</label>
                        <span className="font-bold text-amber-600 block">{formatCurrency(selectedReceipt.klaim)}</span>
                      </div>
                      <div className="flex items-center justify-center text-slate-400 text-xs font-sans">vs</div>
                      <div>
                        <label className="text-[10px] text-slate-500 dark:text-slate-400 block font-sans">OCR Terbaca</label>
                        <span className="font-bold text-emerald-600 dark:text-emerald-400 block">{formatCurrency(selectedReceipt.ocrNominal)}</span>
                      </div>
                    </div>
                    <div className="pt-2 border-t border-amber-100 dark:border-amber-900/40 text-[11px] font-sans flex items-center justify-between">
                      <span className="text-slate-600 dark:text-slate-400 font-medium">Selisih Nominal:</span>
                      <span className="font-mono font-bold text-amber-600 dark:text-amber-400">
                        {nominalDiff > 0 ? `+${formatCurrency(nominalDiff)} (+${diffPct}%)` : `-${formatCurrency(absDiff)} (-${diffPct}%)`}
                      </span>
                    </div>
                    <div className="p-2.5 rounded-lg bg-amber-100/80 dark:bg-amber-900/40 border border-amber-200 dark:border-amber-800 text-[11px] font-medium text-amber-900 dark:text-amber-200 leading-relaxed">
                      ✅ <strong>Dapat Disetujui:</strong> Selisih nominal klaim masih berada di bawah atau sama dengan batas toleransi Variance Limit {isBranchSpecific && selectedReceipt.cabang ? `khusus Cabang ${selectedReceipt.cabang}` : 'perusahaan'} ({effectiveVarianceLimit}%). Pengajuan ini aman dan <u>dapat disetujui</u> oleh Finance.
                    </div>
                  </div>
                );
              })()}

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
                  <span className="text-slate-400 dark:text-slate-500 text-[10px] block">Cabang Kantor</span>
                  <span className="font-semibold text-slate-800 dark:text-slate-200 flex items-center gap-1">
                    <Building2 className="w-3 h-3 text-indigo-500 shrink-0" />
                    {selectedReceipt.cabang || 'Kantor Pusat / Belum Diatur'}
                  </span>
                </div>
                <div>
                  <span className="text-slate-400 dark:text-slate-500 text-[10px] block">Merchant Toko</span>
                  <span className="font-semibold text-slate-800 dark:text-slate-200">{selectedReceipt.merchant}</span>
                </div>
                <div className="col-span-2">
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

              {/* Penyesuaian Nominal Approval & Catatan hanya untuk actionable status (Pending / Review) */}
              {isActionable && (
                <>
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
                </>
              )}
            </div>

            {/* Modal Sticky Footer */}
            {isActionable ? (
              (() => {
                const { effectiveVarianceLimit, isBranchSpecific } = getVarianceAnalysis(selectedReceipt);
                const currentApprovedVal = approvedAmountInput !== '' ? Number(approvedAmountInput) : selectedReceipt.klaim;
                const currentApprovedDiff = selectedReceipt.ocrNominal > 0 ? Math.abs(currentApprovedVal - selectedReceipt.ocrNominal) : 0;
                const currentApprovedPct = selectedReceipt.ocrNominal > 0 ? Math.round((currentApprovedDiff / selectedReceipt.ocrNominal) * 100) : 0;
                const isCurrentApprovedOverLimit = selectedReceipt.ocrNominal > 0 && currentApprovedDiff > 0.01 && currentApprovedPct > effectiveVarianceLimit;

                return (
                  <div className="p-4 sm:p-5 pt-3 border-t border-slate-100 dark:border-slate-800 flex flex-col gap-2.5 bg-slate-50/50 dark:bg-slate-900/50 shrink-0">
                    {isCurrentApprovedOverLimit && (
                      <div className="p-2.5 rounded-lg bg-rose-50 dark:bg-rose-950/40 border border-rose-200 dark:border-rose-900/60 text-[11px] text-rose-700 dark:text-rose-300 flex items-center gap-2 font-medium">
                        <Ban className="w-4 h-4 text-rose-600 shrink-0" />
                        <span>
                          Persetujuan diblokir: Nominal ({formatCurrency(currentApprovedVal)}) melebihi batas Variance Limit {isBranchSpecific && selectedReceipt.cabang ? `Cabang ${selectedReceipt.cabang}` : ''} ({effectiveVarianceLimit}%). Klik <strong>'Sesuai OCR'</strong> di atas jika ingin menyetujui nominal fisik struk.
                        </span>
                      </div>
                    )}
                    <div className="flex gap-2.5">
                      <button
                        onClick={submitReject}
                        className="flex-1 py-2.5 px-4 bg-rose-600 hover:bg-rose-700 text-white font-medium rounded-xl text-xs transition cursor-pointer"
                      >
                        Tolak Pengajuan
                      </button>
                      <button
                        onClick={submitApprove}
                        disabled={isCurrentApprovedOverLimit}
                        className={`flex-1 py-2.5 px-4 font-medium rounded-xl text-xs transition ${
                          isCurrentApprovedOverLimit
                            ? 'bg-slate-200 dark:bg-slate-800 text-slate-400 dark:text-slate-500 border border-slate-300 dark:border-slate-700 cursor-not-allowed opacity-60'
                            : 'bg-emerald-600 hover:bg-emerald-700 text-white cursor-pointer'
                        }`}
                      >
                        {isCurrentApprovedOverLimit
                          ? `Tidak Dapat Disetujui (> Limit ${effectiveVarianceLimit}%)`
                          : Number(approvedAmountInput) < selectedReceipt.klaim
                            ? 'Setujui Sesuai Penyesuaian'
                            : 'Setujui Pengajuan'}
                      </button>
                    </div>
                  </div>
                );
              })()
            ) : (
              <div className="p-4 sm:p-5 pt-3 border-t border-slate-100 dark:border-slate-800 flex justify-end bg-slate-50/50 dark:bg-slate-900/50 shrink-0">
                <button
                  type="button"
                  onClick={() => {
                    if (imageUrl) URL.revokeObjectURL(imageUrl);
                    setShowModal(false);
                    setSelectedReceipt(null);
                    setImageUrl(null);
                  }}
                  className="w-full sm:w-auto px-5 py-2.5 bg-slate-800 hover:bg-slate-900 dark:bg-slate-700 dark:hover:bg-slate-600 text-white font-medium rounded-xl text-xs transition cursor-pointer"
                >
                  Tutup
                </button>
              </div>
            )}
          </div>
        </div>
        );
      })()}

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
        <div className="fixed inset-0 z-[70] bg-black/95 flex flex-col items-center justify-center p-4">
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

          {/* Multi-Photo Navigation Arrows */}
          {selectedReceipt?.images && selectedReceipt.images.length > 1 && (
            <>
              <button
                type="button"
                disabled={selectedImageIndex === 0}
                onClick={() => setSelectedImageIndex(p => Math.max(p - 1, 0))}
                className="absolute left-4 top-1/2 -translate-y-1/2 p-3 bg-black/60 hover:bg-black/80 disabled:opacity-30 disabled:cursor-not-allowed text-white rounded-full transition z-10 cursor-pointer shadow-lg"
                title="Foto Sebelumnya"
              >
                <ChevronLeft className="w-6 h-6" />
              </button>
              <button
                type="button"
                disabled={selectedImageIndex >= selectedReceipt.images.length - 1}
                onClick={() => setSelectedImageIndex(p => Math.min(p + 1, selectedReceipt.images.length - 1))}
                className="absolute right-4 top-1/2 -translate-y-1/2 p-3 bg-black/60 hover:bg-black/80 disabled:opacity-30 disabled:cursor-not-allowed text-white rounded-full transition z-10 cursor-pointer shadow-lg"
                title="Foto Selanjutnya"
              >
                <ChevronRight className="w-6 h-6" />
              </button>
            </>
          )}

          {/* Footer Info & Multi-Photo Switcher */}
          <div className="absolute bottom-0 left-0 right-0 bg-gradient-to-t from-black/90 via-black/70 to-transparent p-4 flex flex-col items-center gap-2 text-white/70 text-xs">
            {selectedReceipt?.images && selectedReceipt.images.length > 1 && (
              <div className="flex items-center gap-2 mb-1">
                {selectedReceipt.images.map((img, idx) => {
                  const isPrimary = idx === 0 || img.image_type === 'primary';
                  const label = isPrimary
                    ? '1. Struk Utama'
                    : (img.image_type === 'edc_slip'
                        ? `${idx + 1}. Slip EDC`
                        : (img.image_type === 'detail' ? `${idx + 1}. Nota Rincian` : `${idx + 1}. Lampiran`));
                  return (
                    <button
                      key={img.id || idx}
                      type="button"
                      onClick={() => setSelectedImageIndex(idx)}
                      className={`px-3 py-1 rounded-full text-xs font-semibold transition cursor-pointer ${
                        selectedImageIndex === idx
                          ? 'bg-indigo-600 text-white shadow-md'
                          : 'bg-white/10 hover:bg-white/20 text-white/80'
                      }`}
                    >
                      {label}
                    </button>
                  );
                })}
              </div>
            )}
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
                  {imageUrl ? (
                    <img src={imageUrl} alt="Struk Baru" className="max-h-[320px] w-auto object-contain rounded shadow-xs" />
                  ) : (
                    <span className="text-xs text-slate-400">Memuat gambar struk...</span>
                  )}
                </div>
              </div>

              {/* Kolom Kanan: Struk Asli / Referensi */}
              <div className="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-4 flex flex-col shadow-xs">
                <div className="flex items-center justify-between pb-3 border-b border-slate-100 dark:border-slate-800 mb-3">
                  <div>
                    <span className="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400">
                      Struk Referensi Asli (Database)
                    </span>
                    <h4 className="text-sm font-bold text-slate-800 dark:text-slate-200 mt-1 font-mono">
                      #{selectedReceipt.duplicateReferenceId} ({selectedReceipt.duplicateReference?.receiptNumber || 'Ref'})
                    </h4>
                  </div>
                  <span className="text-base font-extrabold text-slate-700 dark:text-slate-300 font-mono">
                    {formatCurrency(selectedReceipt.duplicateReference?.claimedAmount ?? 0)}
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

      {/* ─── MODAL REVIEW LAPORAN PENGELUARAN DINAS (BUNDLING) ─── */}
      {showReportModal && selectedReport && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/70 backdrop-blur-xs">
          <div className="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl border border-slate-200 dark:border-slate-800 w-full max-w-4xl max-h-[90vh] flex flex-col overflow-hidden animate-in fade-in zoom-in-95 duration-150">
            {/* Header */}
            <div className="px-6 py-4 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between bg-indigo-50/50 dark:bg-indigo-950/20">
              <div className="flex items-center gap-3">
                <div className="p-2.5 bg-indigo-600 text-white rounded-xl shadow-xs">
                  <Briefcase className="w-5 h-5" />
                </div>
                <div>
                  <div className="flex items-center gap-2">
                    <span className="font-mono text-xs font-bold text-indigo-600 dark:text-indigo-400">
                      {selectedReport.reportNumber}
                    </span>
                    {selectedReport.status === 'submitted' ? (
                      <span className="px-2 py-0.5 rounded-full bg-amber-100 dark:bg-amber-950/60 text-amber-700 dark:text-amber-300 font-semibold text-[10px]">
                        Menunggu Approval
                      </span>
                    ) : selectedReport.status === 'approved' ? (
                      <span className="px-2 py-0.5 rounded-full bg-emerald-100 dark:bg-emerald-950/60 text-emerald-700 dark:text-emerald-300 font-semibold text-[10px]">
                        Disetujui
                      </span>
                    ) : selectedReport.status === 'rejected' ? (
                      <span className="px-2 py-0.5 rounded-full bg-rose-100 dark:bg-rose-950/60 text-rose-700 dark:text-rose-300 font-semibold text-[10px]">
                        Ditolak
                      </span>
                    ) : null}
                  </div>
                  <h3 className="text-base font-bold text-slate-900 dark:text-slate-100 mt-0.5">
                    {selectedReport.title}
                  </h3>
                </div>
              </div>
              <button
                type="button"
                onClick={() => {
                  setShowReportModal(false);
                  setSelectedReport(null);
                }}
                className="p-1.5 rounded-lg text-slate-400 hover:text-slate-600 hover:bg-slate-100 dark:hover:bg-slate-800 transition cursor-pointer"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            {/* Modal Body */}
            <div className="flex-1 overflow-y-auto p-6 space-y-5">
              {/* Summary Cards */}
              <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
                <div className="p-3 bg-slate-50 dark:bg-slate-800/50 rounded-xl border border-slate-100 dark:border-slate-800">
                  <span className="text-[11px] text-slate-400 block">Karyawan</span>
                  <strong className="text-xs text-slate-800 dark:text-slate-200 block truncate">
                    {selectedReport.userName}
                  </strong>
                  <span className="text-[10.5px] text-slate-400 block">{selectedReport.department || '—'}</span>
                </div>
                <div className="p-3 bg-slate-50 dark:bg-slate-800/50 rounded-xl border border-slate-100 dark:border-slate-800">
                  <span className="text-[11px] text-slate-400 block">Cabang Kantor</span>
                  <strong className="text-xs text-slate-800 dark:text-slate-200 block truncate">
                    {selectedReport.branchName || 'Kantor Pusat'}
                  </strong>
                </div>
                <div className="p-3 bg-slate-50 dark:bg-slate-800/50 rounded-xl border border-slate-100 dark:border-slate-800">
                  <span className="text-[11px] text-slate-400 block">Periode Dinas</span>
                  <strong className="text-xs text-slate-800 dark:text-slate-200 block truncate">
                    {selectedReport.startDate} s/d {selectedReport.endDate}
                  </strong>
                </div>
                <div className="p-3 bg-indigo-50/60 dark:bg-indigo-950/30 rounded-xl border border-indigo-100 dark:border-indigo-900/50">
                  <span className="text-[11px] text-indigo-600 dark:text-indigo-400 font-semibold block">Total Klaim Bundle</span>
                  <strong className="text-sm font-mono text-indigo-700 dark:text-indigo-300 block">
                    {formatCurrency(selectedReport.totalClaimedAmount)}
                  </strong>
                  <span className="text-[10px] text-indigo-500 font-semibold block">({selectedReport.totalReceipts} Struk Terlampir)</span>
                </div>
              </div>

              {selectedReport.description && (
                <div className="p-3 bg-slate-50 dark:bg-slate-800/40 rounded-xl border border-slate-100 dark:border-slate-800 text-xs">
                  <span className="text-[10.5px] text-slate-400 font-semibold block uppercase">Keperluan / Keterangan Dinas:</span>
                  <p className="text-slate-700 dark:text-slate-300 mt-1">{selectedReport.description}</p>
                </div>
              )}

              {/* Struk List Table inside the report */}
              <div className="space-y-2.5">
                <div className="flex items-center justify-between">
                  <h4 className="text-xs font-bold text-slate-800 dark:text-slate-200 flex items-center gap-1.5">
                    <FileSpreadsheet className="w-4 h-4 text-indigo-500" />
                    <span>Daftar Struk dalam Laporan ({selectedReport.receipts?.length || 0})</span>
                  </h4>
                </div>

                {/* Banner Status Seleksi Checklist */}
                {selectedReport.status === 'submitted' && (() => {
                  const selectable = (selectedReport.receipts || []).filter(
                    (r: any) => r.status === 'submitted' || r.status === 'pending'
                  );
                  const approvedCount = selectedReportReceiptIds.length;
                  const rejectedCount = Math.max(0, selectable.length - approvedCount);
                  const totalApprovedClaim = (selectedReport.receipts || [])
                    .filter((r: any) => selectedReportReceiptIds.includes(Number(r.id)))
                    .reduce((sum: number, r: any) => sum + Number(r.claimed_amount ?? r.total_amount ?? 0), 0);

                  return (
                    <div className="flex flex-wrap items-center justify-between gap-2.5 p-3 bg-indigo-50/70 dark:bg-indigo-950/40 border border-indigo-200/80 dark:border-indigo-800/60 rounded-xl text-xs">
                      <div className="flex items-center gap-2">
                        <CheckSquare className="w-4 h-4 text-indigo-600 dark:text-indigo-400 shrink-0" />
                        <div>
                          <span className="text-slate-700 dark:text-slate-300">
                            Pilihan Persetujuan:{' '}
                            <strong className="text-indigo-700 dark:text-indigo-300 font-bold">
                              {approvedCount} dari {selectable.length} struk dipilih
                            </strong>{' '}
                            (Total Disetujui: <strong className="font-mono text-emerald-700 dark:text-emerald-300">{formatCurrency(totalApprovedClaim)}</strong>)
                          </span>
                          <p className="text-[10.5px] text-slate-500 dark:text-slate-400 mt-0.5">
                            Centang struk yang valid. Struk yang tidak dicentang otomatis ditolak saat tombol persetujuan diklik.
                          </p>
                        </div>
                      </div>
                      {rejectedCount > 0 && (
                        <span className="inline-flex items-center gap-1 text-[11px] font-semibold text-rose-700 dark:text-rose-300 bg-rose-50 dark:bg-rose-950/50 px-2.5 py-1 rounded-lg border border-rose-200 dark:border-rose-900/60 shrink-0">
                          <Ban className="w-3 h-3 text-rose-500" />
                          {rejectedCount} struk akan ditolak
                        </span>
                      )}
                    </div>
                  );
                })()}

                <div className="overflow-x-auto border border-slate-200 dark:border-slate-800 rounded-xl">
                  <table className="w-full text-left text-xs">
                    <thead className="bg-slate-50 dark:bg-slate-800/60 text-slate-500 dark:text-slate-400 font-semibold border-b border-slate-200 dark:border-slate-800">
                      <tr>
                        {selectedReport.status === 'submitted' && (
                          <th className="py-2.5 px-3 w-10 text-center">
                            <button
                              type="button"
                              onClick={handleToggleAllReportReceipts}
                              className="text-slate-400 hover:text-indigo-600 transition cursor-pointer"
                              title={selectedReportReceiptIds.length === (selectedReport.receipts || []).filter((r: any) => r.status === 'submitted' || r.status === 'pending').length ? 'Batal Pilih Semua' : 'Pilih Semua Struk'}
                            >
                              {selectedReportReceiptIds.length > 0 && selectedReportReceiptIds.length === (selectedReport.receipts || []).filter((r: any) => r.status === 'submitted' || r.status === 'pending').length ? (
                                <CheckSquare className="w-4 h-4 text-indigo-600" />
                              ) : selectedReportReceiptIds.length > 0 ? (
                                <div className="w-4 h-4 rounded border-2 border-indigo-600 bg-indigo-600 text-white flex items-center justify-center text-[9px] font-bold">
                                  -
                                </div>
                              ) : (
                                <Square className="w-4 h-4 text-slate-300 dark:text-slate-600" />
                              )}
                            </button>
                          </th>
                        )}
                        <th className="py-2.5 px-3">No. Struk</th>
                        <th className="py-2.5 px-3">Merchant</th>
                        <th className="py-2.5 px-3">Kategori</th>
                        <th className="py-2.5 px-3 text-right">Nominal Klaim</th>
                        <th className="py-2.5 px-3 text-right">OCR Terbaca</th>
                        <th className="py-2.5 px-3 text-center">Status</th>
                        <th className="py-2.5 px-3 text-right">Foto</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                      {(!selectedReport.receipts || selectedReport.receipts.length === 0) ? (
                        <tr>
                          <td colSpan={selectedReport.status === 'submitted' ? 8 : 7} className="py-6 text-center text-slate-400">
                            Belum ada struk yang dikaitkan ke laporan dinas ini.
                          </td>
                        </tr>
                      ) : (
                        selectedReport.receipts.map((rcp: any) => {
                          const vAnalysis = getReportReceiptVarianceAnalysis(rcp);
                          const isChecked = selectedReportReceiptIds.includes(Number(rcp.id));
                          const isSelectable = rcp.status === 'submitted' || rcp.status === 'pending';

                          return (
                            <tr
                              key={rcp.id}
                              className={`transition-colors ${
                                isChecked && selectedReport.status === 'submitted'
                                  ? 'bg-indigo-50/40 dark:bg-indigo-950/20'
                                  : vAnalysis.isDuplicate
                                  ? 'bg-purple-50/25 dark:bg-purple-950/15'
                                  : vAnalysis.isOverLimit
                                  ? 'bg-rose-50/30 dark:bg-rose-950/15'
                                  : vAnalysis.isWithinLimit
                                  ? 'bg-amber-50/20 dark:bg-amber-950/10'
                                  : 'hover:bg-slate-50/50 dark:hover:bg-slate-800/30'
                              }`}
                            >
                              {selectedReport.status === 'submitted' && (
                                <td className="py-2.5 px-3 text-center">
                                  {isSelectable ? (
                                    <button
                                      type="button"
                                      onClick={() => handleToggleReportReceipt(Number(rcp.id))}
                                      className="text-slate-400 hover:text-indigo-600 transition cursor-pointer"
                                    >
                                      {isChecked ? (
                                        <CheckSquare className="w-4 h-4 text-indigo-600" />
                                      ) : (
                                        <Square className="w-4 h-4 text-slate-300 dark:text-slate-600" />
                                      )}
                                    </button>
                                  ) : (
                                    <span className="text-slate-300 dark:text-slate-600 text-xs">—</span>
                                  )}
                                </td>
                              )}
                              <td className="py-2.5 px-3 font-mono font-medium text-slate-700 dark:text-slate-300">
                                {rcp.receipt_number || `#${rcp.id}`}
                              </td>
                              <td className="py-2.5 px-3 font-semibold text-slate-800 dark:text-slate-200">
                                {rcp.vendor_name || rcp.ocr_raw_merchant || rcp.display_merchant || '—'}
                              </td>
                              <td className="py-2.5 px-3 text-slate-500">
                                {rcp.category || 'Lainnya'}
                              </td>
                              <td className="py-2.5 px-3 text-right">
                                {vAnalysis.isOverLimit ? (
                                  <div>
                                    <div className="flex items-center justify-end gap-1 font-bold text-rose-600 dark:text-rose-400 font-mono">
                                      <AlertTriangle className="w-3 h-3 text-rose-500 shrink-0" />
                                      <span>{formatCurrency(vAnalysis.klaim)}</span>
                                    </div>
                                    <div className="text-[10px] text-rose-600 dark:text-rose-400 font-semibold font-mono">
                                      {vAnalysis.nominalDiff > 0 ? `+${formatCurrency(vAnalysis.nominalDiff)}` : `-${formatCurrency(vAnalysis.absDiff)}`} ({vAnalysis.nominalDiff > 0 ? `+${vAnalysis.diffPct}%` : `-${vAnalysis.diffPct}%`})
                                    </div>
                                  </div>
                                ) : vAnalysis.isWithinLimit ? (
                                  <div>
                                    <div className="flex items-center justify-end gap-1 font-bold text-amber-600 dark:text-amber-400 font-mono">
                                      <AlertTriangle className="w-3 h-3 text-amber-500 shrink-0" />
                                      <span>{formatCurrency(vAnalysis.klaim)}</span>
                                    </div>
                                    <div className="text-[10px] text-amber-600 dark:text-amber-400 font-semibold font-mono">
                                      {vAnalysis.nominalDiff > 0 ? `+${formatCurrency(vAnalysis.nominalDiff)}` : `-${formatCurrency(vAnalysis.absDiff)}`} ({vAnalysis.nominalDiff > 0 ? `+${vAnalysis.diffPct}%` : `-${vAnalysis.diffPct}%`})
                                    </div>
                                  </div>
                                ) : (
                                  <span className="font-mono font-bold text-slate-800 dark:text-slate-100">
                                    {formatCurrency(vAnalysis.klaim)}
                                  </span>
                                )}
                              </td>
                              <td className="py-2.5 px-3 text-right font-mono text-emerald-600 dark:text-emerald-400">
                                {formatCurrency(vAnalysis.ocr)}
                              </td>
                              <td className="py-2.5 px-3 text-center">
                                <div className="flex flex-col items-center gap-1">
                                  <span className={`px-2 py-0.5 rounded-full text-[10px] font-semibold ${
                                    rcp.status === 'approved'
                                      ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300'
                                      : rcp.status === 'rejected'
                                      ? 'bg-rose-100 text-rose-700 dark:bg-rose-950/60 dark:text-rose-300'
                                      : 'bg-amber-100 text-amber-700 dark:bg-amber-950/60 dark:text-amber-300'
                                  }`}>
                                    {rcp.status}
                                  </span>
                                  {vAnalysis.isOverLimit && (
                                    <span
                                      className="inline-flex items-center gap-1 text-[9px] font-bold px-1.5 py-0.2 rounded bg-rose-100 text-rose-800 dark:bg-rose-950/60 dark:text-rose-300 border border-rose-200"
                                      title={`Selisih nominal ${vAnalysis.diffPct}% melebihi batas Variance Limit (${vAnalysis.effectiveVarianceLimit}%).`}
                                    >
                                      <AlertTriangle className="w-2.5 h-2.5 text-rose-600" />
                                      Melebihi Limit ({vAnalysis.diffPct}%)
                                    </span>
                                  )}
                                  {vAnalysis.isWithinLimit && (
                                    <span
                                      className="inline-flex items-center gap-1 text-[9px] font-bold px-1.5 py-0.2 rounded bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300 border border-amber-200"
                                      title={`Selisih nominal ${vAnalysis.diffPct}% masih di dalam toleransi (${vAnalysis.effectiveVarianceLimit}%).`}
                                    >
                                      <AlertTriangle className="w-2.5 h-2.5 text-amber-600" />
                                      Beda Nominal (≤{vAnalysis.effectiveVarianceLimit}%)
                                    </span>
                                  )}
                                  {vAnalysis.isDuplicate &&
                                    rcp.status !== 'approved' &&
                                    rcp.status !== 'rejected' &&
                                    rcp.status !== 'paid' &&
                                    selectedReport.status !== 'approved' &&
                                    selectedReport.status !== 'rejected' &&
                                    selectedReport.status !== 'paid' && (
                                    <span
                                      className="inline-flex items-center gap-1 text-[9px] font-bold px-1.5 py-0.2 rounded bg-purple-100 text-purple-800 dark:bg-purple-950/50 dark:text-purple-300 border border-purple-200"
                                      title={vAnalysis.duplicateReason || 'Terindikasi duplikat dengan struk lain.'}
                                    >
                                      <ShieldAlert className="w-2.5 h-2.5 text-purple-600" />
                                      Potensi Duplikat
                                    </span>
                                  )}
                                </div>
                              </td>
                              <td className="py-2.5 px-3 text-right">
                                <button
                                  type="button"
                                  onClick={() => {
                                    setPreviewReportReceipt(rcp);
                                    setPreviewReportImageIndex(0);
                                  }}
                                  className="p-1 px-2 text-[11px] font-semibold bg-indigo-50 hover:bg-indigo-100 text-indigo-600 dark:bg-indigo-950 dark:text-indigo-400 rounded-md transition flex items-center gap-1 ml-auto cursor-pointer"
                                >
                                  <Eye className="w-3 h-3" />
                                  <span>Lihat</span>
                                </button>
                              </td>
                            </tr>
                          );
                        })
                      )}
                    </tbody>
                  </table>
                </div>
              </div>

              {/* Action Note if pending */}
              {selectedReport.status === 'submitted' && (
                <div className="space-y-1.5 pt-2 border-t border-slate-100 dark:border-slate-800">
                  <label className="text-xs font-semibold text-slate-700 dark:text-slate-300">
                    Catatan Review Finance (Opsional untuk Setuju, Wajib jika Tolak)
                  </label>
                  <textarea
                    rows={2}
                    value={reportActionNote}
                    onChange={(e) => setReportActionNote(e.target.value)}
                    placeholder="Contoh: Disetujui seluruh struk kegiatan dinas luar kota."
                    className="w-full p-2.5 text-xs rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                  />
                </div>
              )}
            </div>

            {/* Modal Footer */}
            <div className="px-6 py-3.5 border-t border-slate-100 dark:border-slate-800 bg-white dark:bg-slate-900 flex justify-end gap-2.5">
              <button
                type="button"
                onClick={() => {
                  setShowReportModal(false);
                  setSelectedReport(null);
                }}
                disabled={processingReportAction}
                className="px-4 py-2 text-xs font-semibold text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-xl transition cursor-pointer"
              >
                Tutup
              </button>

              {selectedReport.status === 'submitted' && (() => {
                const selectable = (selectedReport.receipts || []).filter(
                  (r: any) => r.status === 'submitted' || r.status === 'pending'
                );
                const approvedCount = selectedReportReceiptIds.length;
                const totalApprovedClaim = (selectedReport.receipts || [])
                  .filter((r: any) => selectedReportReceiptIds.includes(Number(r.id)))
                  .reduce((sum: number, r: any) => sum + Number(r.claimed_amount ?? r.total_amount ?? 0), 0);
                const isPartial = selectable.length > approvedCount && approvedCount > 0;

                return (
                  <>
                    <button
                      type="button"
                      onClick={() => handleRejectReport(selectedReport)}
                      disabled={processingReportAction}
                      className="px-4 py-2 text-xs font-bold text-white bg-rose-600 hover:bg-rose-700 rounded-xl transition cursor-pointer disabled:opacity-50"
                    >
                      Tolak Seluruh Laporan
                    </button>
                    <button
                      type="button"
                      onClick={() => handleApproveReport(selectedReport)}
                      disabled={processingReportAction || approvedCount === 0}
                      className={`px-4 py-2 text-xs font-bold text-white rounded-xl transition cursor-pointer disabled:opacity-50 flex items-center gap-1.5 shadow-xs ${
                        isPartial
                          ? 'bg-amber-600 hover:bg-amber-700'
                          : 'bg-emerald-600 hover:bg-emerald-700'
                      }`}
                      title={approvedCount === 0 ? 'Centang minimal 1 struk untuk disetujui' : undefined}
                    >
                      <Check className="w-3.5 h-3.5" />
                      <span>
                        {isPartial
                          ? `Setujui ${approvedCount} Struk Terpilih (${formatCurrency(totalApprovedClaim)})`
                          : `Setujui Seluruh Bundle (${formatCurrency(totalApprovedClaim)})`}
                      </span>
                    </button>
                  </>
                );
              })()}
            </div>
          </div>
        </div>
      )}

      {/* Modal Preview Struk di dalam Laporan Dinas (Read-Only) */}
      {previewReportReceipt && (
        <div className="fixed inset-0 z-[60] bg-slate-900/60 dark:bg-slate-950/70 backdrop-blur-xs flex items-center justify-center p-4">
          <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-100 dark:border-slate-800 w-full max-w-lg max-h-[88vh] flex flex-col shadow-2xl relative animate-in fade-in zoom-in duration-200 overflow-hidden">
            
            {/* Header */}
            <div className="p-4 sm:p-5 pb-3 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between shrink-0">
              <div>
                <div className="flex items-center gap-2">
                  <FileSpreadsheet className="w-4.5 h-4.5 text-indigo-600 shrink-0" />
                  <h3 className="text-sm font-bold text-slate-900 dark:text-slate-100">
                    Rincian Struk — {previewReportReceipt.receipt_number || `#${previewReportReceipt.id}`}
                  </h3>
                  <span className={`px-2 py-0.5 rounded-full text-[10px] font-semibold ${
                    previewReportReceipt.status === 'approved'
                      ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300'
                      : previewReportReceipt.status === 'rejected'
                      ? 'bg-rose-100 text-rose-700 dark:bg-rose-950/60 dark:text-rose-300'
                      : 'bg-amber-100 text-amber-700 dark:bg-amber-950/60 dark:text-amber-300'
                  }`}>
                    {previewReportReceipt.status === 'approved' ? 'Disetujui' : (previewReportReceipt.status === 'rejected' ? 'Ditolak' : 'Menunggu Review')}
                  </span>
                </div>
                {selectedReport && (
                  <p className="text-[11px] text-slate-400 mt-0.5">
                    Bagian dari: <strong className="text-slate-600 dark:text-slate-300 font-mono">{selectedReport.reportNumber}</strong> ({selectedReport.title})
                  </p>
                )}
              </div>
              <button
                type="button"
                onClick={() => {
                  if (previewReportImageUrl) URL.revokeObjectURL(previewReportImageUrl);
                  setPreviewReportReceipt(null);
                  setPreviewReportImageUrl(null);
                }}
                className="hover:bg-slate-100 dark:hover:bg-slate-800 p-1.5 rounded-full text-slate-400 dark:text-slate-500 transition cursor-pointer"
              >
                <X className="w-4 h-4" />
              </button>
            </div>

            {/* Body */}
            <div className="p-4 sm:p-5 overflow-y-auto flex-1 space-y-3.5">
              {/* Photo Section */}
              <div className="bg-slate-50 dark:bg-slate-950/50 rounded-xl overflow-hidden border border-dashed border-slate-200 dark:border-slate-800">
                {previewReportReceipt.images && previewReportReceipt.images.length > 1 && (
                  <div className="flex items-center gap-1.5 p-2 bg-slate-100/80 dark:bg-slate-900 border-b border-slate-200 dark:border-slate-800 overflow-x-auto">
                    {previewReportReceipt.images.map((img: any, idx: number) => {
                      const isPrimary = idx === 0 || img.image_type === 'primary';
                      const label = isPrimary
                        ? '1. Struk Utama'
                        : (img.image_type === 'edc_slip'
                            ? `${idx + 1}. Slip EDC`
                            : (img.image_type === 'detail' ? `${idx + 1}. Nota Rincian` : `${idx + 1}. Lampiran`));
                      return (
                        <button
                          key={img.id || idx}
                          type="button"
                          onClick={() => setPreviewReportImageIndex(idx)}
                          className={`px-2.5 py-1 rounded-lg text-[11px] font-semibold transition flex items-center gap-1.5 shrink-0 cursor-pointer ${
                            previewReportImageIndex === idx
                              ? 'bg-white dark:bg-slate-800 text-indigo-600 dark:text-indigo-400 shadow-xs border border-slate-300 dark:border-slate-700'
                              : 'text-slate-500 hover:text-slate-800 dark:hover:text-slate-200'
                          }`}
                        >
                          <ImageIcon className="w-3 h-3" />
                          <span>{label}</span>
                        </button>
                      );
                    })}
                  </div>
                )}
                {loadingPreviewReportImage ? (
                  <div className="p-4 flex items-center justify-center gap-2">
                    <div className="w-4 h-4 border-2 border-slate-200 dark:border-slate-700 border-t-indigo-500 rounded-full animate-spin" />
                    <p className="text-xs font-medium text-slate-500 dark:text-slate-400">Memuat foto struk...</p>
                  </div>
                ) : previewReportImageUrl ? (
                  <div className="p-2.5 flex items-center gap-3">
                    <button
                      type="button"
                      onClick={() => {
                        setImageUrl(previewReportImageUrl);
                        setShowImagePreview(true);
                        setZoomLevel(100);
                      }}
                      className="group relative h-20 w-24 rounded-lg overflow-hidden border border-slate-200 dark:border-slate-700 bg-black/5 shrink-0 focus:outline-none focus:ring-2 focus:ring-indigo-500 cursor-pointer"
                      title="Klik untuk memperbesar struk"
                    >
                      <img
                        src={previewReportImageUrl}
                        alt={`Struk ${previewReportReceipt.id}`}
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
                        <span className="truncate">
                          {previewReportImageIndex === 0
                            ? 'Foto Struk Fisik Utama'
                            : (previewReportReceipt.images?.[previewReportImageIndex]?.image_type === 'edc_slip'
                                ? 'Lampiran: Slip EDC / Pembayaran'
                                : (previewReportReceipt.images?.[previewReportImageIndex]?.image_type === 'detail'
                                    ? 'Lampiran: Nota Rincian Item'
                                    : `Lampiran Foto #${previewReportImageIndex + 1}`))}
                        </span>
                        {previewReportReceipt.images && previewReportReceipt.images.length > 1 && (
                          <span className="text-[10px] px-1.5 py-0.2 rounded bg-indigo-100 dark:bg-indigo-900/60 text-indigo-700 dark:text-indigo-300 font-mono font-bold">
                            {previewReportImageIndex + 1}/{previewReportReceipt.images.length}
                          </span>
                        )}
                      </div>
                      <p className="text-[11px] text-slate-400 mt-0.5">
                        Tanggal Struk: {formatTanggal(previewReportReceipt.receipt_date || previewReportReceipt.created_at)}
                      </p>
                      <button
                        type="button"
                        onClick={() => {
                          setImageUrl(previewReportImageUrl);
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

              {/* Status Banner */}
              <div className={`p-3 rounded-xl border text-xs space-y-1 ${
                previewReportReceipt.status === 'approved'
                  ? 'bg-emerald-50 dark:bg-emerald-950/40 border-emerald-200 dark:border-emerald-900/60 text-emerald-900 dark:text-emerald-200'
                  : previewReportReceipt.status === 'rejected'
                  ? 'bg-rose-50 dark:bg-rose-950/40 border-rose-200 dark:border-rose-900/60 text-rose-900 dark:text-rose-200'
                  : 'bg-amber-50 dark:bg-amber-950/40 border-amber-200 dark:border-amber-900/60 text-amber-900 dark:text-amber-200'
              }`}>
                <div className="flex items-center gap-1.5 font-bold">
                  {previewReportReceipt.status === 'approved' ? (
                    <CheckCircle2 className="w-4 h-4 text-emerald-600 dark:text-emerald-400" />
                  ) : previewReportReceipt.status === 'rejected' ? (
                    <Ban className="w-4 h-4 text-rose-600 dark:text-rose-400" />
                  ) : (
                    <AlertTriangle className="w-4 h-4 text-amber-600 dark:text-amber-400" />
                  )}
                  <span>
                    Status: {previewReportReceipt.status === 'approved' ? 'Struk Disetujui' : (previewReportReceipt.status === 'rejected' ? 'Struk Ditolak' : 'Menunggu Approval Bundle')}
                  </span>
                </div>
                {previewReportReceipt.status === 'rejected' && (
                  <p className="text-[11px] text-rose-700 dark:text-rose-300">
                    {selectedReport?.rejection_reason ? (
                      <><strong>Alasan Penolakan Laporan:</strong> {selectedReport.rejection_reason}</>
                    ) : (
                      'Struk ini termasuk dalam paket laporan dinas yang ditolak oleh Finance.'
                    )}
                  </p>
                )}
                {previewReportReceipt.status === 'approved' && (
                  <p className="text-[11px] text-emerald-700 dark:text-emerald-300">
                    Struk ini telah disetujui bersama paket Laporan Dinas.
                  </p>
                )}
                {previewReportReceipt.status !== 'approved' && previewReportReceipt.status !== 'rejected' && (
                  <p className="text-[11px] text-amber-700 dark:text-amber-300">
                    Struk ini menunggu persetujuan/penolakan dari verifikasi paket Laporan Dinas.
                  </p>
                )}
              </div>

              {/* Peringatan Potensi Duplikat */}
              {previewReportReceipt.is_potential_duplicate && (
                <div className="p-3 rounded-xl border border-purple-200 dark:border-purple-800 bg-purple-50/80 dark:bg-purple-950/40 text-xs space-y-1.5">
                  <div className="flex items-center gap-1.5 font-bold text-purple-800 dark:text-purple-300">
                    <ShieldAlert className="w-4 h-4 text-purple-600 dark:text-purple-400 shrink-0" />
                    <span>Peringatan: Terdeteksi Potensi Struk Duplikat</span>
                  </div>
                  <p className="text-[11px] text-purple-700 dark:text-purple-300">
                    {previewReportReceipt.duplicate_reason || 'Foto struk atau kombinasi merchant, nominal & tanggal struk ini terindikasi duplikat dengan struk lain di sistem.'}
                  </p>
                  {(previewReportReceipt.duplicate_reference || previewReportReceipt.duplicateReference) && (() => {
                    const dupRef = previewReportReceipt.duplicate_reference || previewReportReceipt.duplicateReference;
                    return (
                      <div className="pt-1 border-t border-purple-200 dark:border-purple-800/60 flex items-center justify-between text-[10.5px] text-purple-700 dark:text-purple-300">
                        <span>Referensi Asli: <strong className="font-mono">#{dupRef.receipt_number || dupRef.id}</strong></span>
                        <span className="font-mono font-bold">{formatCurrency(Number(dupRef.total_amount ?? dupRef.claimed_amount ?? 0))}</span>
                      </div>
                    );
                  })()}
                </div>
              )}

              {/* Analisis Variance Limit (Klaim vs OCR) */}
              {(() => {
                const klaim = Number(previewReportReceipt.claimed_amount ?? previewReportReceipt.total_amount ?? 0);
                const ocr = Number(previewReportReceipt.ocr_raw_amount ?? previewReportReceipt.total_amount ?? 0);
                const nominalDiff = ocr > 0 ? klaim - ocr : 0;
                const absDiff = Math.abs(nominalDiff);
                const hasDiff = ocr > 0 && absDiff > 0.01;
                if (!hasDiff) return null;

                const diffPct = previewReportReceipt.variance_pct !== undefined && previewReportReceipt.variance_pct !== null
                  ? Number(previewReportReceipt.variance_pct)
                  : Math.round((absDiff / ocr) * 100);

                const branchSetting = selectedReport?.attendance_setting_id && currentSettings?.branchSettings
                  ? currentSettings.branchSettings.find((b) => b.id === selectedReport.attendance_setting_id)
                  : null;

                const effectiveLimit = branchSetting?.varianceLimit !== null && branchSetting?.varianceLimit !== undefined
                  ? branchSetting.varianceLimit
                  : (currentSettings?.varianceLimit ?? 10);

                const isOver = Boolean(previewReportReceipt.variance_flag) || diffPct > effectiveLimit;

                return (
                  <div className={`p-3 rounded-xl border text-xs space-y-2 ${
                    isOver
                      ? 'bg-rose-50/80 dark:bg-rose-950/40 border-rose-200 dark:border-rose-900/60'
                      : 'bg-amber-50/80 dark:bg-amber-950/40 border-amber-200 dark:border-amber-900/60'
                  }`}>
                    <div className="flex items-center justify-between">
                      <div className={`flex items-center gap-1.5 font-bold ${isOver ? 'text-rose-700 dark:text-rose-300' : 'text-amber-700 dark:text-amber-300'}`}>
                        <AlertTriangle className={`w-4 h-4 ${isOver ? 'text-rose-600' : 'text-amber-600'}`} />
                        <span>
                          {isOver
                            ? `Selisih Nominal Melebihi Variance Limit (+${diffPct}%)`
                            : `Selisih Nominal Masih dalam Toleransi (≤${effectiveLimit}%)`}
                        </span>
                      </div>
                      <span className={`text-[10px] font-bold px-2 py-0.5 rounded-full ${
                        isOver ? 'bg-rose-100 text-rose-800 dark:bg-rose-900/60' : 'bg-amber-100 text-amber-800 dark:bg-amber-900/60'
                      }`}>
                        {isOver ? 'Over Limit' : 'Toleransi OK'}
                      </span>
                    </div>
                    <div className="grid grid-cols-3 gap-2 text-[11px] pt-1.5 border-t border-slate-200/60 dark:border-slate-800">
                      <div>
                        <span className="text-slate-400 block text-[10px]">Nominal Klaim:</span>
                        <strong className="font-mono text-slate-800 dark:text-slate-200">{formatCurrency(klaim)}</strong>
                      </div>
                      <div>
                        <span className="text-slate-400 block text-[10px]">OCR Terbaca:</span>
                        <strong className="font-mono text-emerald-600 dark:text-emerald-400">{formatCurrency(ocr)}</strong>
                      </div>
                      <div>
                        <span className="text-slate-400 block text-[10px]">Selisih:</span>
                        <strong className={`font-mono ${isOver ? 'text-rose-600 dark:text-rose-400' : 'text-amber-600 dark:text-amber-400'}`}>
                          {nominalDiff > 0 ? `+${formatCurrency(nominalDiff)}` : `-${formatCurrency(absDiff)}`} ({diffPct}%)
                        </strong>
                      </div>
                    </div>
                  </div>
                );
              })()}

              {/* Data Fields */}
              <div className="grid grid-cols-2 gap-2.5 text-xs bg-slate-50 dark:bg-slate-800/40 p-3 rounded-xl border border-slate-100 dark:border-slate-800">
                <div>
                  <span className="text-slate-400 dark:text-slate-500 text-[10px] block">Merchant / Toko</span>
                  <span className="font-semibold text-slate-800 dark:text-slate-200">
                    {previewReportReceipt.vendor_name || previewReportReceipt.ocr_raw_merchant || previewReportReceipt.display_merchant || '—'}
                  </span>
                </div>
                <div>
                  <span className="text-slate-400 dark:text-slate-500 text-[10px] block">Kategori</span>
                  <span className="font-semibold text-slate-800 dark:text-slate-200">
                    {previewReportReceipt.category || 'Lain-lain / Operasional'}
                  </span>
                </div>
                <div>
                  <span className="text-slate-400 dark:text-slate-500 text-[10px] block">Nominal Klaim Karyawan</span>
                  <span className="font-mono font-bold text-slate-900 dark:text-slate-100 text-xs">
                    {formatCurrency(Number(previewReportReceipt.claimed_amount ?? previewReportReceipt.total_amount ?? 0))}
                  </span>
                </div>
                <div>
                  <span className="text-slate-400 dark:text-slate-500 text-[10px] block">Nominal Terbaca OCR</span>
                  <span className="font-mono font-bold text-emerald-600 dark:text-emerald-400 text-xs">
                    {formatCurrency(Number(previewReportReceipt.total_amount ?? 0))}
                  </span>
                </div>
              </div>

              {/* Items Breakdown if OCR read items */}
              {(() => {
                const items = parseReceiptItems(previewReportReceipt.ocr_raw_items);
                if (items.length === 0) return null;
                return (
                  <div className="border border-slate-200 dark:border-slate-800 rounded-xl overflow-hidden text-xs">
                    <div className="bg-slate-100/80 dark:bg-slate-800/60 px-3 py-2 border-b border-slate-200 dark:border-slate-800 flex justify-between items-center">
                      <span className="font-bold text-slate-700 dark:text-slate-300 flex items-center gap-1.5 text-[11px]">
                        <FileSpreadsheet className="w-3.5 h-3.5 text-indigo-500" />
                        Rincian Item OCR ({items.length} item)
                      </span>
                      <span className="text-[10px] font-semibold text-emerald-600 bg-emerald-50 dark:bg-emerald-950/40 px-1.5 py-0.5 rounded">
                        AI OCR
                      </span>
                    </div>
                    <div className="divide-y divide-slate-100 dark:divide-slate-800 max-h-40 overflow-y-auto bg-white dark:bg-slate-900/50">
                      {items.map((item: any, idx: number) => (
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
                );
              })()}

              {/* Subtotal / Tax / Discount Breakdown */}
              {(previewReportReceipt.ocr_raw_subtotal !== undefined || previewReportReceipt.ocr_raw_discount !== undefined || previewReportReceipt.ocr_raw_tax !== undefined) && (
                <div className="bg-slate-50/80 dark:bg-slate-800/30 border border-slate-200/80 dark:border-slate-800 rounded-xl p-3 space-y-1.5 text-xs font-mono">
                  {previewReportReceipt.ocr_raw_subtotal !== undefined && previewReportReceipt.ocr_raw_subtotal !== null && (
                    <div className="flex justify-between text-slate-600 dark:text-slate-400 text-[11px]">
                      <span>Subtotal</span>
                      <span>{formatCurrency(Number(previewReportReceipt.ocr_raw_subtotal))}</span>
                    </div>
                  )}
                  {previewReportReceipt.ocr_raw_discount !== undefined && previewReportReceipt.ocr_raw_discount !== null && Number(previewReportReceipt.ocr_raw_discount) > 0 && (
                    <div className="flex justify-between text-emerald-600 dark:text-emerald-400 text-[11px] font-semibold">
                      <span>Diskon / Promo</span>
                      <span>- {formatCurrency(Number(previewReportReceipt.ocr_raw_discount))}</span>
                    </div>
                  )}
                  {previewReportReceipt.ocr_raw_tax !== undefined && previewReportReceipt.ocr_raw_tax !== null && Number(previewReportReceipt.ocr_raw_tax) > 0 && (
                    <div className="flex justify-between text-amber-600 dark:text-amber-400 text-[11px] font-semibold">
                      <span>Pajak (Tax / PPN)</span>
                      <span>+ {formatCurrency(Number(previewReportReceipt.ocr_raw_tax))}</span>
                    </div>
                  )}
                  <div className="flex justify-between pt-1.5 border-t border-slate-200 dark:border-slate-700 text-slate-900 dark:text-slate-100 font-bold text-xs">
                    <span className="font-sans">Total OCR</span>
                    <span>{formatCurrency(Number(previewReportReceipt.total_amount ?? 0))}</span>
                  </div>
                </div>
              )}
            </div>

            {/* Footer */}
            <div className="p-4 sm:p-5 pt-3 border-t border-slate-100 dark:border-slate-800 flex items-center justify-between bg-slate-50/50 dark:bg-slate-900/50 shrink-0">
              <span className="text-[11px] text-slate-400">
                💡 Keputusan verifikasi berlaku untuk satu laporan dinas.
              </span>
              <button
                type="button"
                onClick={() => {
                  if (previewReportImageUrl) URL.revokeObjectURL(previewReportImageUrl);
                  setPreviewReportReceipt(null);
                  setPreviewReportImageUrl(null);
                }}
                className="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white font-medium rounded-xl text-xs transition cursor-pointer"
              >
                Kembali ke Laporan
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};
