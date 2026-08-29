import React, { useState, useCallback } from 'react';
import { StrukApproval } from '../types';
import {
  FileSpreadsheet,
  Check,
  X,
  Search,
  Download,
  User,
  MessageSquare,
  Calendar,
  XCircle,
  Image,
  Loader2,
  ZoomIn,
  ZoomOut,
  RotateCw,
  RotateCcw,
  Maximize2,
  AlertCircle,
  RefreshCw,
} from 'lucide-react';
import { receiptApi } from '../services/endpoints';
import { useDebounce } from '../hooks/useDebounce';
import CustomDatePicker from './CustomDatePicker';

interface ReceiptHistoryProps {

  approvals: StrukApproval[];
  onRefresh?: () => void;
  refreshing?: boolean;
}

// Tombol thumbnail per baris — hanya fetch saat diklik, tidak auto-load.
const ReceiptImageCell: React.FC<{
  receiptId: string;
  onOpen: (url: string) => void;
}> = ({ receiptId, onOpen }) => {
  const [loading, setLoading] = useState(false);
  const [failed, setFailed] = useState(false);
  const [cachedUrl, setCachedUrl] = useState<string | null>(null);

  const handleClick = useCallback(async () => {
    if (cachedUrl) { onOpen(cachedUrl); return; }
    setLoading(true);
    const url = await receiptApi.fetchImageAsDataUrl(receiptId);
    setLoading(false);
    if (url) { setCachedUrl(url); onOpen(url); }
    else setFailed(true);
  }, [receiptId, cachedUrl, onOpen]);

  if (loading) return (
    <div className="flex justify-center">
      <Loader2 className="w-4 h-4 text-indigo-400 animate-spin" />
    </div>
  );

  if (failed) return (
    <div className="flex justify-center" title="Gagal memuat gambar">
      <AlertCircle className="w-4 h-4 text-rose-400" />
    </div>
  );

  return (
    <button
      onClick={handleClick}
      className="flex items-center justify-center w-8 h-8 mx-auto rounded-lg bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 hover:bg-indigo-50 dark:hover:bg-indigo-950/30 hover:border-indigo-300 dark:hover:border-indigo-700 transition group"
      title="Lihat foto struk"
    >
      <Image className="w-3.5 h-3.5 text-slate-400 group-hover:text-indigo-500 transition" />
    </button>
  );
};

export const ReceiptHistory: React.FC<ReceiptHistoryProps> = ({ approvals, onRefresh, refreshing }) => {
  const [searchQuery, setSearchQuery] = useState('');
  const [statusFilter, setStatusFilter] = useState('semua');
  const [startDate, setStartDate] = useState('');
  const [endDate, setEndDate] = useState('');
  const [lightboxUrl, setLightboxUrl] = useState<string | null>(null);
  const [lightboxZoom, setLightboxZoom] = useState(100);
  const [lightboxRotation, setLightboxRotation] = useState(0);
  const [selectedApproval, setSelectedApproval] = useState<StrukApproval | null>(null);
  const [loadingDetailImage, setLoadingDetailImage] = useState(false);
  const [detailImageUrl, setDetailImageUrl] = useState<string | null>(null);
  const debouncedSearch = useDebounce(searchQuery, 500);

  const openLightbox = (url: string) => {
    setLightboxUrl(url);
    setLightboxZoom(100);
    setLightboxRotation(0);
  };

  // Keyboard shortcuts for lightbox preview
  useEffect(() => {
    if (!lightboxUrl) return;

    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'Escape') {
        setLightboxUrl(null);
      } else if (e.key === '+' || e.key === '=') {
        setLightboxZoom(prev => Math.min(prev + 10, 300));
      } else if (e.key === '-') {
        setLightboxZoom(prev => Math.max(prev - 10, 50));
      } else if (e.key === '0') {
        setLightboxZoom(100);
        setLightboxRotation(0);
      } else if (e.key === 'r' || e.key === 'R') {
        setLightboxRotation(prev => (prev + 90) % 360);
      }
    };

    window.addEventListener('keydown', handleKeyDown);
    return () => window.removeEventListener('keydown', handleKeyDown);
  }, [lightboxUrl]);

  const handleOpenDetail = async (item: StrukApproval) => {
    setSelectedApproval(item);
    setDetailImageUrl(null);
    setLoadingDetailImage(true);
    const url = await receiptApi.fetchImageAsDataUrl(item.id);
    setLoadingDetailImage(false);
    setDetailImageUrl(url);
  };

  const filteredApprovals = approvals.filter(a => {
    const matchesSearch = !debouncedSearch ||
           a.karyawan.toLowerCase().includes(debouncedSearch.toLowerCase()) ||
           a.merchant.toLowerCase().includes(debouncedSearch.toLowerCase()) ||
           a.catatan.toLowerCase().includes(debouncedSearch.toLowerCase());

    const matchesStatus = statusFilter === 'semua' ||
           (statusFilter === 'disetujui' && a.keputusan === 'Disetujui') ||
           (statusFilter === 'ditolak' && a.keputusan === 'Ditolak');

    const matchesDateRange = (!startDate || a.tanggal >= startDate) &&
           (!endDate || a.tanggal <= endDate);

    return matchesSearch && matchesStatus && matchesDateRange;
  });

  const formatCurrency = (val: number) => {
    return new Intl.NumberFormat('id-ID', {
      style: 'currency',
      currency: 'IDR',
      maximumFractionDigits: 0
    }).format(val);
  };

  return (
    <>
      <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl p-5 shadow-sm">
        {/* Header section with export */}
        <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3 mb-5 pb-4 border-b border-slate-100 dark:border-slate-800/80">
          <div>
            <h3 className="text-sm font-bold text-slate-800 dark:text-slate-100 flex items-center gap-1.5">
              <FileSpreadsheet className="w-4 h-4 text-indigo-600" />
              Riwayat Approval Struk Karyawan
            </h3>
            <p className="text-xs text-slate-400 dark:text-slate-500 mt-0.5">
              Semua keputusan klaim struk yang telah diproses oleh tim finance
            </p>
          </div>

          <div className="flex gap-2 w-full sm:w-auto flex-wrap items-center">
            <div className="relative flex-1 sm:w-56 shrink-0">
              <Search className="absolute left-3 top-2 w-3.5 h-3.5 text-slate-400" />
              <input
                type="text"
                placeholder="Cari riwayat..."
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
                className="w-full pl-9 pr-3 py-1.5 border border-slate-200 dark:border-slate-700 rounded-lg text-xs bg-slate-50 dark:bg-slate-800/50 text-slate-800 dark:text-slate-100 focus:outline-none"
              />
            </div>

            <div className="flex gap-2 items-center">
              <div className="w-36">
                <CustomDatePicker
                  value={startDate}
                  onChange={setStartDate}
                  placeholder="Dari tanggal"
                  size="sm"
                />
              </div>
              <span className="text-slate-400 text-xs">–</span>
              <div className="w-36">
                <CustomDatePicker
                  value={endDate}
                  onChange={setEndDate}
                  placeholder="Sampai tanggal"
                  size="sm"
                />
              </div>
            </div>


            <button className="flex items-center gap-1.5 px-3 py-1.5 border border-slate-200 dark:border-slate-700 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg text-xs font-medium text-slate-600 dark:text-slate-300 transition">
              <Download className="w-3.5 h-3.5" />
              <span className="hidden xs:inline">Export Excel</span>
            </button>

            <button
              onClick={onRefresh}
              disabled={refreshing}
              className="flex items-center gap-1.5 px-3 py-1.5 bg-indigo-50 dark:bg-indigo-900/30 border border-indigo-100 dark:border-indigo-800 text-indigo-600 dark:text-indigo-400 hover:bg-indigo-100 dark:hover:bg-indigo-900/50 rounded-lg text-xs font-bold transition shrink-0 disabled:opacity-50"
              title="Refresh Data"
            >
              <RefreshCw className={`w-3.5 h-3.5 ${refreshing ? 'animate-spin' : ''}`} />
              <span className="hidden xs:inline">Refresh</span>
            </button>
          </div>
        </div>

        {/* Tab status filter */}
        <div className="flex flex-wrap items-center gap-1 border-b border-slate-200 dark:border-slate-800 mb-4">
          {[
            { key: 'semua', label: 'Semua Riwayat', count: approvals.length },
            { key: 'disetujui', label: 'Disetujui', count: approvals.filter(a => a.keputusan === 'Disetujui').length },
            { key: 'ditolak', label: 'Ditolak', count: approvals.filter(a => a.keputusan === 'Ditolak').length },
          ].map((t) => (
            <button
              key={t.key}
              onClick={() => setStatusFilter(t.key)}
              className={`flex items-center gap-1.5 px-3 py-2 text-xs font-bold border-b-2 -mb-px transition cursor-pointer ${statusFilter === t.key
                ? 'border-indigo-500 text-indigo-600 dark:text-indigo-400'
                : 'border-transparent text-slate-400 hover:text-slate-600 dark:text-slate-500 dark:hover:text-slate-300'
                }`}
            >
              {t.label}
              <span className={`text-[10px] px-1.5 py-0.5 rounded-full font-mono ${statusFilter === t.key ? 'bg-indigo-50 dark:bg-indigo-900/40 text-indigo-600 dark:text-indigo-400' : 'bg-slate-100 dark:bg-slate-800 text-slate-500'}`}>
                {t.count}
              </span>
            </button>
          ))}
        </div>

        {/* History table */}
        <div className="overflow-x-auto">
          <table className="w-full text-left border-collapse min-w-[850px]">
            <thead>
              <tr className="border-b border-slate-100 dark:border-slate-800/80">
                <th className="py-3 px-4 font-semibold text-slate-500 dark:text-slate-400 text-xs uppercase tracking-wider">Karyawan</th>
                <th className="py-3 px-4 font-semibold text-slate-500 dark:text-slate-400 text-xs uppercase tracking-wider">Merchant / Toko</th>
                <th className="py-3 px-4 font-semibold text-slate-500 dark:text-slate-400 text-xs uppercase tracking-wider">Nominal</th>
                <th className="py-3 px-4 font-semibold text-slate-500 dark:text-slate-400 text-xs uppercase tracking-wider">Status</th>
                <th className="py-3 px-4 font-semibold text-slate-500 dark:text-slate-400 text-xs uppercase tracking-wider">Diproses Oleh</th>
                <th className="py-3 px-4 font-semibold text-slate-500 dark:text-slate-400 text-xs uppercase tracking-wider">Waktu Keputusan</th>
                <th className="py-3 px-4 font-semibold text-slate-500 dark:text-slate-400 text-xs uppercase tracking-wider">Catatan</th>
                <th className="py-3 px-4 font-semibold text-slate-500 dark:text-slate-400 text-xs uppercase tracking-wider text-right">Aksi</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100 dark:divide-slate-800/65">
              {filteredApprovals.length === 0 ? (
                <tr>
                  <td colSpan={8} className="py-12 text-center text-slate-400 dark:text-slate-500 text-xs">
                    Tidak ditemukan riwayat yang cocok
                  </td>
                </tr>
              ) : (
                filteredApprovals.map((item) => {
                  const approved = item.keputusan === 'Disetujui';
                  const hasAlertNotes = item.catatan.includes('manipulasi') || item.catatan.includes('variance') || item.catatan.includes('Selisih');

                  return (
                    <tr
                      key={item.id}
                      className={`hover:bg-slate-50/50 dark:hover:bg-slate-800/10 transition-colors ${
                        hasAlertNotes ? 'bg-amber-50/10' : ''
                      }`}
                    >
                      <td className="py-3 px-4">
                        <div className="flex items-center gap-2">
                          <div className="w-6.5 h-6.5 rounded-full bg-slate-100 dark:bg-slate-800 flex items-center justify-center text-slate-600 dark:text-slate-300 font-semibold text-[10px]">
                            {item.karyawan.split(' ').map(n => n[0]).join('')}
                          </div>
                          <span className="text-xs font-semibold text-slate-800 dark:text-slate-200">{item.karyawan}</span>
                        </div>
                      </td>
                      <td className="py-3 px-4 text-xs font-medium text-slate-700 dark:text-slate-300">
                        {item.merchant}
                      </td>
                      <td className={`py-3 px-4 text-xs font-mono font-semibold ${
                        approved ? 'text-slate-800 dark:text-slate-200' : 'text-slate-500 dark:text-slate-400 line-through'
                      }`}>
                        {formatCurrency(item.nominal)}
                      </td>
                      <td className="py-3 px-4">
                        {approved ? (
                          <span className="inline-flex items-center gap-1 text-[10px] font-semibold px-2 py-0.5 rounded-md bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-400">
                            <Check className="w-3 h-3 shrink-0" />
                            Disetujui
                          </span>
                        ) : (
                          <span className="inline-flex items-center gap-1 text-[10px] font-semibold px-2 py-0.5 rounded-md bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-400">
                            <X className="w-3 h-3 shrink-0" />
                            Ditolak
                          </span>
                        )}
                      </td>
                      <td className="py-3 px-4">
                        <div className="flex items-center gap-1 text-xs text-slate-600 dark:text-slate-400">
                          <User className="w-3 h-3 text-slate-400" />
                          <span>{item.diprosesOleh}</span>
                        </div>
                      </td>
                      <td className="py-3 px-4 text-xs font-mono text-slate-500 dark:text-slate-500">
                        {item.waktu}
                      </td>
                      <td className="py-3 px-4">
                        {item.catatan === '—' ? (
                          <span className="text-slate-300 dark:text-slate-600 text-xs">—</span>
                        ) : (
                          <div className={`flex items-center gap-1.5 text-xs ${
                            hasAlertNotes ? 'text-rose-600 dark:text-rose-400 font-semibold' : 'text-slate-600 dark:text-slate-400'
                          }`}>
                            <MessageSquare className="w-3.5 h-3.5 opacity-70 shrink-0" />
                            <span className="truncate max-w-[180px]" title={item.catatan}>{item.catatan}</span>
                          </div>
                        )}
                      </td>
                      <td className="py-3 px-4 text-right">
                        <div className="flex items-center justify-end gap-1.5">
                          <button
                            onClick={() => handleOpenDetail(item)}
                            className="p-1 px-2.5 border border-slate-200 dark:border-slate-700 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-md text-slate-600 dark:text-slate-300 text-[11px] font-medium transition flex items-center gap-1"
                            title="Lihat detail rincian"
                          >
                            <FileSpreadsheet className="w-3.5 h-3.5 text-indigo-500" />
                            Detail
                          </button>
                          <ReceiptImageCell receiptId={item.id} onOpen={openLightbox} />
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

      {/* Detail Modal Riwayat */}
      {selectedApproval && (
        <div className="fixed inset-0 z-50 bg-slate-900/40 dark:bg-slate-950/60 backdrop-blur-xs flex items-center justify-center p-4">
          <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-100 dark:border-slate-800 w-full max-w-lg max-h-[88vh] flex flex-col shadow-2xl relative animate-in fade-in zoom-in duration-200 overflow-hidden">
            
            {/* Modal Header */}
            <div className="p-4 sm:p-5 pb-3 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between shrink-0">
              <h3 className="text-sm font-bold text-slate-900 dark:text-slate-100 flex items-center gap-2">
                <FileSpreadsheet className="w-4.5 h-4.5 text-indigo-600 shrink-0" />
                <span>Detail Riwayat Struk — {selectedApproval.karyawan}</span>
              </h3>
              <button
                onClick={() => {
                  if (detailImageUrl) URL.revokeObjectURL(detailImageUrl);
                  setSelectedApproval(null);
                  setDetailImageUrl(null);
                }}
                className="hover:bg-slate-100 dark:hover:bg-slate-800 p-1.5 rounded-full text-slate-400 dark:text-slate-500 transition"
              >
                <X className="w-4 h-4" />
              </button>
            </div>

            {/* Modal Scrollable Body */}
            <div className="p-4 sm:p-5 overflow-y-auto flex-1 space-y-3.5">
              {/* Compact Receipt Image Preview */}
              <div className="bg-slate-50 dark:bg-slate-950/50 rounded-xl overflow-hidden border border-dashed border-slate-200 dark:border-slate-800">
                {loadingDetailImage ? (
                  <div className="p-4 flex items-center justify-center gap-2">
                    <Loader2 className="w-4 h-4 text-indigo-500 animate-spin" />
                    <p className="text-xs font-medium text-slate-500 dark:text-slate-400">Memuat foto struk...</p>
                  </div>
                ) : detailImageUrl ? (
                  <div className="p-2.5 flex items-center gap-3">
                    <button
                      onClick={() => {
                        openLightbox(detailImageUrl);
                      }}
                      className="group relative h-20 w-24 rounded-lg overflow-hidden border border-slate-200 dark:border-slate-700 bg-black/5 shrink-0 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                      title="Klik untuk memperbesar struk"
                    >
                      <img
                        src={detailImageUrl}
                        alt="Foto Struk"
                        className="w-full h-full object-cover group-hover:scale-105 transition duration-200"
                      />
                      <div className="absolute inset-0 bg-black/25 group-hover:bg-black/40 transition flex items-center justify-center">
                        <ZoomIn className="w-4 h-4 text-white drop-shadow" />
                      </div>
                    </button>
                    <div className="flex-1 min-w-0">
                      <div className="flex items-center gap-1.5 text-xs font-semibold text-slate-800 dark:text-slate-200">
                        <FileSpreadsheet className="w-3.5 h-3.5 text-indigo-500 shrink-0" />
                        <span className="truncate">Foto Struk Fisik</span>
                      </div>
                      <p className="text-[11px] text-slate-400 mt-0.5">Waktu: {selectedApproval.waktu}</p>
                      <button
                        onClick={() => {
                          openLightbox(detailImageUrl);
                        }}
                        className="mt-1 inline-flex items-center gap-1 text-[11px] font-semibold text-indigo-600 dark:text-indigo-400 hover:underline"
                      >
                        <ZoomIn className="w-3 h-3" />
                        Klik untuk Perbesar (Zoom)
                      </button>
                    </div>
                  </div>
                ) : (
                  <div className="p-3 flex items-center justify-center gap-2 text-slate-400 text-xs">
                    <FileSpreadsheet className="w-4 h-4" />
                    <span>Foto struk tidak ditemukan</span>
                  </div>
                )}
              </div>

              {/* Details Fields */}
              <div className="grid grid-cols-2 gap-2.5 text-xs bg-slate-50 dark:bg-slate-800/40 p-3 rounded-xl border border-slate-100 dark:border-slate-800">
                <div>
                  <span className="text-slate-400 dark:text-slate-500 text-[10px] block">Karyawan</span>
                  <span className="font-semibold text-slate-800 dark:text-slate-200">{selectedApproval.karyawan}</span>
                </div>
                <div>
                  <span className="text-slate-400 dark:text-slate-500 text-[10px] block">Merchant Toko</span>
                  <span className="font-semibold text-slate-800 dark:text-slate-200">{selectedApproval.merchant}</span>
                </div>
                <div>
                  <span className="text-slate-400 dark:text-slate-500 text-[10px] block">Status Keputusan</span>
                  <span className={`font-semibold text-xs ${selectedApproval.keputusan === 'Disetujui' ? 'text-emerald-600' : 'text-rose-600'}`}>
                    {selectedApproval.keputusan}
                  </span>
                </div>
                <div>
                  <span className="text-slate-400 dark:text-slate-500 text-[10px] block">Diproses Oleh</span>
                  <span className="font-semibold text-slate-800 dark:text-slate-200">{selectedApproval.diprosesOleh}</span>
                </div>
              </div>

              {/* Itemized Receipt Items Breakdown */}
              {selectedApproval.items && selectedApproval.items.length > 0 && (
                <div className="border border-slate-200 dark:border-slate-800 rounded-xl overflow-hidden text-xs">
                  <div className="bg-slate-100/80 dark:bg-slate-800/60 px-3 py-2 border-b border-slate-200 dark:border-slate-800 flex justify-between items-center">
                    <span className="font-bold text-slate-700 dark:text-slate-300 flex items-center gap-1.5 text-[11px]">
                      <FileSpreadsheet className="w-3.5 h-3.5 text-indigo-500" />
                      Rincian Belanja ({selectedApproval.items.length} item)
                    </span>
                    <span className="text-[10px] font-semibold text-emerald-600 bg-emerald-50 dark:bg-emerald-950/40 px-1.5 py-0.5 rounded">
                      AI OCR
                    </span>
                  </div>
                  <div className="divide-y divide-slate-100 dark:divide-slate-800 max-h-48 overflow-y-auto bg-white dark:bg-slate-900/50">
                    {selectedApproval.items.map((item, idx) => (
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
              {(selectedApproval.subtotal !== undefined || selectedApproval.discount !== undefined || selectedApproval.tax !== undefined || selectedApproval.ocrNominal !== undefined) && (
                <div className="bg-slate-50/80 dark:bg-slate-800/30 border border-slate-200/80 dark:border-slate-800 rounded-xl p-3 space-y-1.5 text-xs font-mono">
                  {selectedApproval.subtotal !== undefined && (
                    <div className="flex justify-between text-slate-600 dark:text-slate-400 text-[11px]">
                      <span>Subtotal</span>
                      <span>{formatCurrency(selectedApproval.subtotal)}</span>
                    </div>
                  )}
                  {selectedApproval.discount !== undefined && selectedApproval.discount > 0 && (
                    <div className="flex justify-between text-emerald-600 dark:text-emerald-400 text-[11px] font-semibold">
                      <span>Diskon / Promo</span>
                      <span>- {formatCurrency(selectedApproval.discount)}</span>
                    </div>
                  )}
                  {selectedApproval.tax !== undefined && selectedApproval.tax > 0 && (
                    <div className="flex justify-between text-amber-600 dark:text-amber-400 text-[11px] font-semibold">
                      <span>Pajak (Tax / PPN)</span>
                      <span>+ {formatCurrency(selectedApproval.tax)}</span>
                    </div>
                  )}
                  {selectedApproval.ocrNominal !== undefined && (
                    <div className="flex justify-between pt-1.5 border-t border-slate-200 dark:border-slate-700 text-slate-900 dark:text-slate-100 font-bold text-xs">
                      <span className="font-sans">Total OCR</span>
                      <span>{formatCurrency(selectedApproval.ocrNominal)}</span>
                    </div>
                  )}
                </div>
              )}

              {/* Catatan */}
              <div className="p-3 bg-slate-50 dark:bg-slate-800/40 rounded-xl border border-slate-100 dark:border-slate-800 text-xs">
                <span className="text-slate-400 dark:text-slate-500 text-[10px] block mb-1">Catatan Keputusan:</span>
                <p className="text-slate-700 dark:text-slate-300 italic">{selectedApproval.catatan || '—'}</p>
              </div>
            </div>

            {/* Modal Sticky Footer */}
            <div className="p-4 sm:p-5 pt-3 border-t border-slate-100 dark:border-slate-800 flex justify-end bg-slate-50/50 dark:bg-slate-900/50 shrink-0">
              <button
                onClick={() => setSelectedApproval(null)}
                className="py-2.5 px-6 bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 font-medium rounded-xl text-xs transition"
              >
                Tutup
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Fullscreen Lightbox Modal with Zoom & Rotate */}
      {lightboxUrl && (
        <div className="fixed inset-0 z-[60] bg-black/95 flex flex-col items-center justify-center p-4">
          {/* Toolbar */}
          <div className="absolute top-0 left-0 right-0 bg-gradient-to-b from-black/80 to-transparent p-4 flex items-center justify-between">
            <div className="text-white text-sm font-medium flex items-center gap-2">
              <span>Zoom: <strong className="font-mono">{lightboxZoom}%</strong></span>
              {lightboxRotation !== 0 && (
                <span className="text-xs text-indigo-400 bg-indigo-950/60 px-2 py-0.5 rounded-full border border-indigo-800/60">
                  Rotasi: {lightboxRotation}°
                </span>
              )}
            </div>
            <div className="flex items-center gap-1.5 sm:gap-2">
              <button
                onClick={() => setLightboxZoom(prev => Math.max(prev - 10, 50))}
                className="p-2 hover:bg-white/20 rounded-lg text-white transition"
                title="Zoom out (-)"
              >
                <ZoomOut className="w-5 h-5" />
              </button>
              <button
                onClick={() => {
                  setLightboxZoom(100);
                  setLightboxRotation(0);
                }}
                className="px-2.5 py-1.5 hover:bg-white/20 rounded-lg text-white text-xs font-medium transition"
                title="Reset zoom & rotasi (0)"
              >
                Reset
              </button>
              <button
                onClick={() => setLightboxZoom(prev => Math.min(prev + 10, 300))}
                className="p-2 hover:bg-white/20 rounded-lg text-white transition"
                title="Zoom in (+)"
              >
                <ZoomIn className="w-5 h-5" />
              </button>

              <div className="h-5 w-px bg-white/20 mx-1" />

              <button
                onClick={() => setLightboxRotation(prev => (prev - 90 + 360) % 360)}
                className="p-2 hover:bg-white/20 rounded-lg text-white transition"
                title="Putar ke Kiri (-90°)"
              >
                <RotateCcw className="w-5 h-5" />
              </button>
              <button
                onClick={() => setLightboxRotation(prev => (prev + 90) % 360)}
                className="p-2 hover:bg-white/20 rounded-lg text-white transition"
                title="Putar ke Kanan (+90° / Shortcut R)"
              >
                <RotateCw className="w-5 h-5" />
              </button>

              <div className="h-5 w-px bg-white/20 mx-1" />

              <button
                onClick={() => setLightboxUrl(null)}
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
                  setLightboxZoom(prev => Math.min(prev + 10, 300));
                } else {
                  setLightboxZoom(prev => Math.max(prev - 10, 50));
                }
              }}
            >
              <img
                src={lightboxUrl}
                alt="Foto Struk Full"
                className="object-contain select-none"
                style={{
                  transform: `scale(${lightboxZoom / 100}) rotate(${lightboxRotation}deg)`,
                  maxWidth: lightboxRotation % 180 !== 0 ? '75vh' : '90vw',
                  maxHeight: lightboxRotation % 180 !== 0 ? '75vw' : '85vh',
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
    </>
  );
};
