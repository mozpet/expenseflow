import React, { useState, useEffect } from 'react';
import { Invoice } from '../types';
import {
  History,
  Search,
  Download,
  Check,
  X,
  User,
  FileText,
  Filter
} from 'lucide-react';
import { useDebounce } from '../hooks/useDebounce';

interface InvoiceHistoryProps {
  historyInvoices: Invoice[];
}

export const InvoiceHistory: React.FC<InvoiceHistoryProps> = ({ historyInvoices }) => {
  const [searchQuery, setSearchQuery] = useState('');
  const [statusFilter, setStatusFilter] = useState('semua');
  const debouncedSearch = useDebounce(searchQuery, 500);

  // Pagination state
  const [currentPage, setCurrentPage] = useState<number>(1);
  const [pageSize, setPageSize] = useState<number>(25);

  useEffect(() => {
    setCurrentPage(1);
  }, [debouncedSearch, statusFilter]);

  const filteredHistory = historyInvoices.filter(i => {
    const matchesSearch = !debouncedSearch ||
           i.vendor.toLowerCase().includes(debouncedSearch.toLowerCase()) ||
           i.id.toLowerCase().includes(debouncedSearch.toLowerCase()) ||
           (i.catatan && i.catatan.toLowerCase().includes(debouncedSearch.toLowerCase()));

    const matchesStatus = statusFilter === 'semua' ||
           (statusFilter === 'dibayar' && i.status === 'Dibayar') ||
           (statusFilter === 'ditolak' && i.status === 'Ditolak');

    return matchesSearch && matchesStatus;
  });

  const totalPages = Math.max(1, Math.ceil(filteredHistory.length / pageSize));
  const paginatedHistory = filteredHistory.slice((currentPage - 1) * pageSize, currentPage * pageSize);

  const formatCurrency = (val: number) => {
    return new Intl.NumberFormat('id-ID', {
      style: 'currency',
      currency: 'IDR',
      maximumFractionDigits: 0
    }).format(val);
  };

  return (
    <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl p-5 shadow-sm space-y-4">
      {/* Header section with export */}
      <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3 border-b border-slate-100 dark:border-slate-800 pb-4">
        <div>
          <h3 className="text-sm font-bold text-slate-800 dark:text-slate-100 flex items-center gap-1.5">
            <History className="w-4 h-4 text-indigo-600" />
            Riwayat Approval Invoice Vendor
          </h3>
          <p className="text-xs text-slate-400 dark:text-slate-500 mt-0.5">
            Rekam jejak rincian pembayaran kontribusi vendor eksternal & software korporat
          </p>
        </div>

        <div className="flex gap-2 w-full sm:w-auto flex-wrap">
          <div className="relative flex-1 sm:w-56 shrink-0">
            <Search className="absolute left-3 top-2 w-3.5 h-3.5 text-slate-400" />
            <input
              type="text"
              placeholder="Cari riwayat invoice..."
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              className="w-full pl-9 pr-3 py-1.5 border border-slate-200 dark:border-slate-700 rounded-lg text-xs bg-slate-50 dark:bg-slate-800/50 text-slate-800 dark:text-slate-100 focus:outline-none"
            />
          </div>
          <button className="flex items-center gap-1.5 px-3 py-1.5 border border-slate-200 dark:border-slate-700 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg text-xs font-medium text-slate-600 dark:text-slate-300 transition">
            <Download className="w-3.5 h-3.5" />
            <span>Export PDF</span>
          </button>
        </div>
      </div>

      {/* Tab status filter */}
      <div className="flex flex-wrap items-center gap-1 border-b border-slate-200 dark:border-slate-800 -mb-px">
        {[
          { key: 'semua', label: 'Semua Riwayat', count: historyInvoices.length },
          { key: 'dibayar', label: 'Dibayar', count: historyInvoices.filter(i => i.status === 'Dibayar').length },
          { key: 'ditolak', label: 'Ditolak', count: historyInvoices.filter(i => i.status === 'Ditolak').length },
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

      {/* History Table */}
      <div className="overflow-x-auto">
        <table className="w-full text-left border-collapse min-w-[750px]">
          <thead>
            <tr className="border-b border-slate-100 dark:border-slate-800/80">
              <th className="py-3 px-4 font-semibold text-slate-500 dark:text-slate-400 text-xs uppercase tracking-wider">No. Inv</th>
              <th className="py-3 px-4 font-semibold text-slate-500 dark:text-slate-400 text-xs uppercase tracking-wider font-sans">Vendor</th>
              <th className="py-3 px-4 font-semibold text-slate-500 dark:text-slate-400 text-xs uppercase tracking-wider">Total Tagihan</th>
              <th className="py-3 px-4 font-semibold text-slate-500 dark:text-slate-400 text-xs uppercase tracking-wider">Metode</th>
              <th className="py-3 px-4 font-semibold text-slate-500 dark:text-slate-400 text-xs uppercase tracking-wider">Keputusan</th>
              <th className="py-3 px-4 font-semibold text-slate-500 dark:text-slate-400 text-xs uppercase tracking-wider">Disetujui Oleh</th>
              <th className="py-3 px-4 font-semibold text-slate-500 dark:text-slate-400 text-xs uppercase tracking-wider">Catatan Audit</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
            {filteredHistory.length === 0 ? (
              <tr>
                <td colSpan={7} className="py-12 text-center text-slate-400 dark:text-slate-500 text-xs">
                  Tidak ditemukan riwayat pembayaran yang cocok
                </td>
              </tr>
            ) : (
              paginatedHistory.map((item) => {
                const paid = item.status === 'Dibayar';
                return (
                  <tr key={item.id} className="hover:bg-slate-50/50 dark:hover:bg-slate-850/20 transition-colors">
                    <td className="py-3 px-4 font-semibold font-mono text-xs text-indigo-600 dark:text-indigo-400">
                      {item.id}
                    </td>
                    <td className="py-3 px-4 text-xs font-semibold text-slate-700 dark:text-slate-300">
                      {item.vendor}
                    </td>
                    <td className="py-3 px-4 text-xs font-semibold font-mono text-slate-700 dark:text-slate-250">
                      {formatCurrency(item.total)}
                    </td>
                    <td className="py-3 px-4">
                      <span className={`text-[10px] font-bold px-2 py-0.5 rounded ${
                        item.sumber === 'Scan' 
                          ? 'bg-indigo-50 text-indigo-700 dark:bg-indigo-950/40 dark:text-indigo-400' 
                          : 'bg-purple-50 text-purple-700 dark:bg-purple-950/40 dark:text-purple-400'
                      }`}>
                        {item.sumber}
                      </span>
                    </td>
                    <td className="py-3 px-4">
                      {paid ? (
                        <span className="inline-flex items-center gap-1 text-[10px] font-semibold px-2 py-0.5 rounded-md bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-400">
                          <Check className="w-3 h-3" />
                          Dibayar
                        </span>
                      ) : (
                        <span className="inline-flex items-center gap-1 text-[10px] font-semibold px-2 py-0.5 rounded-md bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-400">
                          <X className="w-3 h-3" />
                          Ditolak
                        </span>
                      )}
                    </td>
                    <td className="py-3 px-4 text-xs text-slate-600 dark:text-slate-400">
                      <div className="flex items-center gap-1.5">
                        <User className="w-3.5 h-3.5 text-slate-400" />
                        <span>{item.uploadOleh || 'Sari Rahma'}</span>
                      </div>
                    </td>
                    <td className="py-3 px-4 text-xs text-slate-500 dark:text-slate-500 font-sans italic">
                      {item.catatan || '—'}
                    </td>
                  </tr>
                );
              })
            )}
          </tbody>
        </table>
      </div>

      {/* Pagination footer */}
      {filteredHistory.length >= 25 && (
        <div className="flex flex-col sm:flex-row items-center justify-between gap-3 pt-4 border-t border-slate-100 dark:border-slate-800 text-xs">
          <div className="flex items-center gap-2 text-slate-500 dark:text-slate-400">
            <span>
              Menampilkan <strong className="text-slate-800 dark:text-slate-200 font-bold font-mono">
                {Math.min((currentPage - 1) * pageSize + 1, filteredHistory.length)} - {Math.min(currentPage * pageSize, filteredHistory.length)}
              </strong> dari <strong className="text-slate-800 dark:text-slate-200 font-bold font-mono">{filteredHistory.length}</strong> riwayat
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
    </div>
  );
};
