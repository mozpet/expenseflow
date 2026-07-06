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
  AlertCircle,
} from 'lucide-react';
import { receiptApi } from '../services/endpoints';

interface ReceiptHistoryProps {
  approvals: StrukApproval[];
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

export const ReceiptHistory: React.FC<ReceiptHistoryProps> = ({ approvals }) => {
  const [searchQuery, setSearchQuery] = useState('');
  const [statusFilter, setStatusFilter] = useState('semua');
  const [startDate, setStartDate] = useState('');
  const [endDate, setEndDate] = useState('');
  const [lightboxUrl, setLightboxUrl] = useState<string | null>(null);

  const filteredApprovals = approvals.filter(a => {
    const matchesSearch = a.karyawan.toLowerCase().includes(searchQuery.toLowerCase()) ||
           a.merchant.toLowerCase().includes(searchQuery.toLowerCase()) ||
           a.catatan.toLowerCase().includes(searchQuery.toLowerCase());

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

          <div className="flex gap-2 w-full flex-wrap items-center">
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

            <select
              value={statusFilter}
              onChange={(e) => setStatusFilter(e.target.value)}
              className="px-3 py-1.5 border border-slate-200 dark:border-slate-700 rounded-lg text-xs font-medium bg-slate-50 dark:bg-slate-800/50 text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 transition cursor-pointer focus:outline-none focus:ring-2 focus:ring-indigo-500"
            >
              <option value="semua">Semua Status</option>
              <option value="disetujui">✓ Disetujui</option>
              <option value="ditolak">✗ Ditolak</option>
            </select>

            <div className="flex gap-1.5 items-center">
              <Calendar className="w-3.5 h-3.5 text-slate-400 shrink-0" />
              <input
                type="date"
                value={startDate}
                onChange={(e) => setStartDate(e.target.value)}
                className="px-2 py-1.5 border border-slate-200 dark:border-slate-700 rounded-lg text-xs bg-slate-50 dark:bg-slate-800/50 text-slate-700 dark:text-slate-300 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                title="Tanggal mulai"
              />
              <span className="text-slate-400 text-xs">–</span>
              <input
                type="date"
                value={endDate}
                onChange={(e) => setEndDate(e.target.value)}
                className="px-2 py-1.5 border border-slate-200 dark:border-slate-700 rounded-lg text-xs bg-slate-50 dark:bg-slate-800/50 text-slate-700 dark:text-slate-300 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                title="Tanggal selesai"
              />
              {(startDate || endDate) && (
                <button
                  onClick={() => { setStartDate(''); setEndDate(''); }}
                  className="p-1.5 text-slate-400 hover:text-slate-600 dark:hover:text-slate-300 transition"
                  title="Bersihkan filter tanggal"
                >
                  <XCircle className="w-3.5 h-3.5" />
                </button>
              )}
            </div>

            <button className="flex items-center gap-1.5 px-3 py-1.5 border border-slate-200 dark:border-slate-700 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg text-xs font-medium text-slate-600 dark:text-slate-300 transition ml-auto sm:ml-0">
              <Download className="w-3.5 h-3.5" />
              <span className="hidden xs:inline">Export Excel</span>
            </button>
          </div>
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
                <th className="py-3 px-4 font-semibold text-slate-500 dark:text-slate-400 text-xs uppercase tracking-wider w-10 text-center">Struk</th>
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
                      <td className="py-3 px-4">
                        <ReceiptImageCell receiptId={item.id} onOpen={setLightboxUrl} />
                      </td>
                    </tr>
                  );
                })
              )}
            </tbody>
          </table>
        </div>
      </div>

      {/* Lightbox modal */}
      {lightboxUrl && (
        <div
          className="fixed inset-0 z-50 flex items-center justify-center bg-black/80 backdrop-blur-sm p-4"
          onClick={() => setLightboxUrl(null)}
        >
          <div
            className="relative max-w-2xl w-full"
            onClick={(e) => e.stopPropagation()}
          >
            <button
              onClick={() => setLightboxUrl(null)}
              className="absolute -top-3 -right-3 z-10 w-8 h-8 flex items-center justify-center rounded-full bg-white dark:bg-slate-800 shadow-lg text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-700 transition"
            >
              <X className="w-4 h-4" />
            </button>
            <img
              src={lightboxUrl}
              alt="Foto struk"
              className="w-full max-h-[80vh] object-contain rounded-2xl shadow-2xl"
            />
            <p className="text-center text-white/60 text-[10px] mt-2 flex items-center justify-center gap-1">
              <ZoomIn className="w-3 h-3" /> Klik di luar gambar untuk menutup
            </p>
          </div>
        </div>
      )}
    </>
  );
};
