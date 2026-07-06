import React, { useState } from 'react';
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

interface AuditLogViewProps {
  logs: AuditLog[];
}

export const AuditLogView: React.FC<AuditLogViewProps> = ({ logs }) => {
  const [startDate, setStartDate] = useState('');
  const [endDate, setEndDate] = useState('');

  // Filter logs berdasarkan date range
  const filteredLogs = logs.filter(log => {
    if (!log.created_at) return true;
    const logDate = log.created_at.substring(0, 10);
    return (!startDate || logDate >= startDate) &&
           (!endDate || logDate <= endDate);
  });

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
                onClick={() => {
                  setStartDate('');
                  setEndDate('');
                }}
                className="p-1.5 text-slate-400 hover:text-slate-600 dark:hover:text-slate-300 transition"
                title="Bersihkan filter tanggal"
              >
                <XCircle className="w-3.5 h-3.5" />
              </button>
            )}
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
          filteredLogs.map((log) => {
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
    </div>
  );
};
