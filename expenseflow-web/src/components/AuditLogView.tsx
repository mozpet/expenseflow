import React, { useState, useEffect } from 'react';
import { AuditLog } from '../types';
import {
  ShieldCheck,
  Download,
  Activity,
  History,
  FileSpreadsheet,
  AlertTriangle,
  User,
  Clock,
  Calendar,
  XCircle
} from 'lucide-react';

import CustomDatePicker from './CustomDatePicker';

interface AuditLogViewProps {
  logs: AuditLog[];
}

export const AuditLogView: React.FC<AuditLogViewProps> = ({ logs }) => {
  const [startDate, setStartDate] = useState('');
  const [endDate, setEndDate] = useState('');

  // Pagination state
  const [currentPage, setCurrentPage] = useState<number>(1);
  const [pageSize, setPageSize] = useState<number>(25);

  useEffect(() => {
    setCurrentPage(1);
  }, [startDate, endDate]);

  // Filter logs berdasarkan date range
  const filteredLogs = logs.filter(log => {
    if (!log.created_at) return true;
    const logDate = log.created_at.substring(0, 10);
    return (!startDate || logDate >= startDate) &&
           (!endDate || logDate <= endDate);
  });

  const totalPages = Math.max(1, Math.ceil(filteredLogs.length / pageSize));
  const paginatedLogs = filteredLogs.slice((currentPage - 1) * pageSize, currentPage * pageSize);

  return (
    <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl p-5 shadow-sm space-y-4">
      {/* Header section with export */}
      <div className="flex flex-col gap-3 border-b border-slate-100 dark:border-slate-800 pb-4">
        <div>
          <h3 className="text-sm font-bold text-slate-800 dark:text-slate-100 flex items-center gap-1.5 font-sans">
            <ShieldCheck className="w-4.5 h-4.5 text-indigo-600 shrink-0" />
            Audit Log Sistem Keuangan ExpenseFlow
          </h3>
          <p className="text-xs text-slate-400 dark:text-slate-500 mt-0.5">
            Log permanen transaksi tidak dapat diubah (immutable ledger trail) • {filteredLogs.length} entri
          </p>
        </div>

        <div className="flex gap-2 w-full flex-wrap items-center">
          <div className="flex gap-2 items-center w-full sm:w-auto">
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


          <button className="flex items-center gap-1.5 px-3 py-1.5 border border-slate-200 dark:border-slate-700 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg text-xs font-medium text-slate-600 dark:text-slate-300 transition ml-auto">
            <Download className="w-3.5 h-3.5" />
            <span>Export Audit Log</span>
          </button>
        </div>
      </div>

      {/* Audit List */}
      <div className="space-y-3.5 pt-1.5">
        {filteredLogs.length === 0 ? (
          <p className="text-center text-slate-400 dark:text-slate-500 text-xs py-8">
            Tidak ditemukan audit log untuk tanggal yang dipilih
          </p>
        ) : (
          paginatedLogs.map((log) => {
          // Detect tag colors/icons
          let iconColor = 'bg-indigo-600';
          if (log.title.includes('disetujui') || log.title.includes('berhasil')) {
            iconColor = 'bg-emerald-600';
          } else if (log.title.includes('variance') || log.title.includes('flag')) {
            iconColor = 'bg-amber-600';
          } else if (log.title.includes('ditolak') || log.title.includes('gagalkan')) {
            iconColor = 'bg-rose-600';
          }

          return (
            <div 
              key={log.id} 
              className="flex gap-3 items-start p-3 hover:bg-slate-50/50 dark:hover:bg-slate-850/20 rounded-xl transition border border-transparent hover:border-slate-100 dark:hover:border-slate-800"
            >
              {/* Colored Indicator Dot */}
              <div className={`w-2.5 h-2.5 rounded-full shrink-0 ${iconColor} mt-1.5`} />
              
              <div className="space-y-1 text-xs">
                <p className="font-bold text-slate-800 dark:text-slate-200">
                  {log.title}
                </p>
                <div className="text-slate-505 dark:text-slate-400 font-sans break-all sm:break-normal">
                  {log.details}
                </div>
              </div>
            </div>
          );
        })
        )}
      </div>

      {/* Pagination footer */}
      {filteredLogs.length >= 25 && (
        <div className="flex flex-col sm:flex-row items-center justify-between gap-3 pt-4 border-t border-slate-100 dark:border-slate-800 text-xs">
          <div className="flex items-center gap-2 text-slate-500 dark:text-slate-400">
            <span>
              Menampilkan <strong className="text-slate-800 dark:text-slate-200 font-bold font-mono">
                {Math.min((currentPage - 1) * pageSize + 1, filteredLogs.length)} - {Math.min(currentPage * pageSize, filteredLogs.length)}
              </strong> dari <strong className="text-slate-800 dark:text-slate-200 font-bold font-mono">{filteredLogs.length}</strong> log
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
