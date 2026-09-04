import React, { useState, useEffect, useMemo } from 'react';
import { AuditLog } from '../types';
import {
  ShieldCheck,
  Download,
  AlertTriangle,
  RefreshCw,
  Search,
  SlidersHorizontal,
  User,
  Globe,
  Clock,
  ArrowRight,
  ShieldAlert,
  Info,
  CheckCircle2,
  X,
  Layers,
  FileCode2,
} from 'lucide-react';
import CustomDatePicker from './CustomDatePicker';
import { activityLogApi } from '../services/endpoints';
import { invalidateCache } from '../services/api';
import { mapAuditLog } from '../services/mappers';

export const AuditLogView: React.FC = () => {
  const [logs, setLogs] = useState<AuditLog[]>([]);
  const [loading, setLoading] = useState<boolean>(true);
  const [fetchError, setFetchError] = useState<string | null>(null);
  const [exporting, setExporting] = useState<boolean>(false);

  // Filter states
  const [searchQuery, setSearchQuery] = useState('');
  const [severityFilter, setSeverityFilter] = useState<'all' | 'critical' | 'warning' | 'info'>('all');
  const [categoryFilter, setCategoryFilter] = useState<string>('all');
  const [startDate, setStartDate] = useState('');
  const [endDate, setEndDate] = useState('');

  // Modal Diff state
  const [selectedDiffLog, setSelectedDiffLog] = useState<AuditLog | null>(null);

  // Pagination state
  const [currentPage, setCurrentPage] = useState<number>(1);
  const [pageSize, setPageSize] = useState<number>(25);

  const fetchLogs = async (forceRefresh = false) => {
    setLoading(true);
    setFetchError(null);
    try {
      if (forceRefresh) invalidateCache('/dashboard/activity-logs');
      const res = await activityLogApi.list({
        per_page: 100,
      }, forceRefresh);
      const arr = Array.isArray(res) ? res : Array.isArray((res as any)?.data) ? (res as any).data : [];
      setLogs(arr.map(mapAuditLog));
    } catch (e: any) {
      setFetchError(e?.message ?? 'Gagal memuat audit log.');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchLogs();
  }, []);

  useEffect(() => {
    setCurrentPage(1);
  }, [searchQuery, severityFilter, categoryFilter, startDate, endDate]);

  // Statistics
  const stats = useMemo(() => {
    const total = logs.length;
    const critical = logs.filter(l => l.severity === 'critical').length;
    const warning = logs.filter(l => l.severity === 'warning').length;
    const info = logs.filter(l => l.severity === 'info' || !l.severity).length;
    return { total, critical, warning, info };
  }, [logs]);

  // Client-side filtering
  const filteredLogs = useMemo(() => {
    return logs.filter(log => {
      // Date filter
      if (log.created_at) {
        const logDate = log.created_at.substring(0, 10);
        if (startDate && logDate < startDate) return false;
        if (endDate && logDate > endDate) return false;
      }

      // Severity filter
      if (severityFilter !== 'all') {
        const sev = log.severity || 'info';
        if (sev !== severityFilter) return false;
      }

      // Category filter
      if (categoryFilter !== 'all') {
        if (log.category !== categoryFilter) return false;
      }

      // Search query
      if (searchQuery.trim()) {
        const q = searchQuery.toLowerCase();
        const matchTitle = log.title?.toLowerCase().includes(q);
        const matchDetails = log.details?.toLowerCase().includes(q);
        const matchAction = log.action?.toLowerCase().includes(q);
        const matchActor = log.userName?.toLowerCase().includes(q);
        const matchIp = log.ipAddress?.toLowerCase().includes(q);
        if (!matchTitle && !matchDetails && !matchAction && !matchActor && !matchIp) {
          return false;
        }
      }

      return true;
    });
  }, [logs, startDate, endDate, severityFilter, categoryFilter, searchQuery]);

  const totalPages = Math.max(1, Math.ceil(filteredLogs.length / pageSize));
  const paginatedLogs = filteredLogs.slice((currentPage - 1) * pageSize, currentPage * pageSize);

  const handleExportCsv = async () => {
    setExporting(true);
    try {
      await activityLogApi.exportCsv({
        search: searchQuery || undefined,
        severity: severityFilter !== 'all' ? severityFilter : undefined,
        category: categoryFilter !== 'all' ? categoryFilter : undefined,
        start_date: startDate || undefined,
        end_date: endDate || undefined,
      });
    } catch (err: any) {
      alert(err?.message || 'Gagal mengekspor audit log.');
    } finally {
      setExporting(false);
    }
  };

  const categoryOptions = [
    { key: 'all', label: 'Semua Kategori' },
    { key: 'HR_EMPLOYEE', label: '👥 HR & Karyawan' },
    { key: 'PAYROLL_FINANCE', label: '💳 Keuangan & Rekening' },
    { key: 'EXPENSE_CLAIM', label: '🧾 Klaim Reimbursement' },
    { key: 'ATTENDANCE_OFFICE', label: '📍 Presensi & Kantor' },
    { key: 'COMPANY_SETTINGS', label: '⚙️ Pengaturan Sistem' },
    { key: 'SECURITY_AUTH', label: '🔒 Keamanan & Auth' },
  ];

  return (
    <div className="space-y-4">
      {/* ─── SUMMARY STATS BAR ─── */}
      <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
        <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl p-4 shadow-xs">
          <span className="text-slate-400 text-xs font-medium block">Total Log Sistem</span>
          <div className="flex items-baseline justify-between mt-1">
            <span className="text-xl font-bold text-slate-800 dark:text-slate-100 font-mono">{stats.total}</span>
            <span className="text-[11px] font-semibold text-slate-500 bg-slate-100 dark:bg-slate-800 px-2 py-0.5 rounded-full">
              Semua
            </span>
          </div>
        </div>

        <div
          onClick={() => setSeverityFilter(prev => prev === 'critical' ? 'all' : 'critical')}
          className={`bg-white dark:bg-slate-900 border rounded-2xl p-4 shadow-xs cursor-pointer transition ${
            severityFilter === 'critical'
              ? 'border-rose-400 ring-2 ring-rose-400/20 dark:border-rose-700'
              : 'border-slate-100 dark:border-slate-800 hover:border-rose-200'
          }`}
        >
          <span className="text-rose-500 text-xs font-semibold flex items-center gap-1">
            <ShieldAlert className="w-3.5 h-3.5" />
            Perubahan Kritis
          </span>
          <div className="flex items-baseline justify-between mt-1">
            <span className="text-xl font-bold text-rose-600 dark:text-rose-400 font-mono">{stats.critical}</span>
            <span className="text-[11px] font-semibold text-rose-700 dark:text-rose-300 bg-rose-50 dark:bg-rose-950/40 px-2 py-0.5 rounded-full">
              Data Sensitif
            </span>
          </div>
        </div>

        <div
          onClick={() => setSeverityFilter(prev => prev === 'warning' ? 'all' : 'warning')}
          className={`bg-white dark:bg-slate-900 border rounded-2xl p-4 shadow-xs cursor-pointer transition ${
            severityFilter === 'warning'
              ? 'border-amber-400 ring-2 ring-amber-400/20 dark:border-amber-700'
              : 'border-slate-100 dark:border-slate-800 hover:border-amber-200'
          }`}
        >
          <span className="text-amber-500 text-xs font-semibold flex items-center gap-1">
            <AlertTriangle className="w-3.5 h-3.5" />
            Peringatan
          </span>
          <div className="flex items-baseline justify-between mt-1">
            <span className="text-xl font-bold text-amber-600 dark:text-amber-400 font-mono">{stats.warning}</span>
            <span className="text-[11px] font-semibold text-amber-700 dark:text-amber-300 bg-amber-50 dark:bg-amber-950/40 px-2 py-0.5 rounded-full">
              Konfigurasi
            </span>
          </div>
        </div>

        <div
          onClick={() => setSeverityFilter(prev => prev === 'info' ? 'all' : 'info')}
          className={`bg-white dark:bg-slate-900 border rounded-2xl p-4 shadow-xs cursor-pointer transition ${
            severityFilter === 'info'
              ? 'border-indigo-400 ring-2 ring-indigo-400/20 dark:border-indigo-700'
              : 'border-slate-100 dark:border-slate-800 hover:border-indigo-200'
          }`}
        >
          <span className="text-indigo-500 text-xs font-semibold flex items-center gap-1">
            <Info className="w-3.5 h-3.5" />
            Aktivitas Reguler
          </span>
          <div className="flex items-baseline justify-between mt-1">
            <span className="text-xl font-bold text-indigo-600 dark:text-indigo-400 font-mono">{stats.info}</span>
            <span className="text-[11px] font-semibold text-indigo-700 dark:text-indigo-300 bg-indigo-50 dark:bg-indigo-950/40 px-2 py-0.5 rounded-full">
              Informasi
            </span>
          </div>
        </div>
      </div>

      {/* ─── MAIN AUDIT LOG CARD ─── */}
      <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl p-5 shadow-xs space-y-4">
        {/* Header & Title */}
        <div className="flex flex-col md:flex-row md:items-center justify-between gap-3 border-b border-slate-100 dark:border-slate-800 pb-4">
          <div>
            <h3 className="text-sm font-bold text-slate-800 dark:text-slate-100 flex items-center gap-2">
              <ShieldCheck className="w-5 h-5 text-indigo-600 shrink-0" />
              Audit Trail Sistem Keuangan & Kepegawaian
            </h3>
            <p className="text-xs text-slate-400 dark:text-slate-500 mt-0.5">
              Buku log permanen tidak dapat diubah (immutable ledger trail) untuk audit & kepatuhan data.
            </p>
          </div>

          <div className="flex items-center gap-2">
            <button
              onClick={() => fetchLogs(true)}
              disabled={loading}
              className="flex items-center gap-1.5 px-3 py-1.5 border border-slate-200 dark:border-slate-700 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-xl text-xs font-semibold text-slate-600 dark:text-slate-300 transition cursor-pointer disabled:opacity-50"
            >
              <RefreshCw className={`w-3.5 h-3.5 ${loading ? 'animate-spin' : ''}`} />
              <span>Refresh</span>
            </button>

            <button
              onClick={handleExportCsv}
              disabled={exporting || filteredLogs.length === 0}
              className="flex items-center gap-1.5 px-3.5 py-1.5 bg-emerald-600 hover:bg-emerald-700 disabled:opacity-50 text-white rounded-xl text-xs font-semibold shadow-xs transition cursor-pointer"
            >
              <Download className={`w-3.5 h-3.5 ${exporting ? 'animate-bounce' : ''}`} />
              <span>{exporting ? 'Mengekspor...' : 'Ekspor CSV'}</span>
            </button>
          </div>
        </div>

        {/* Filter Controls Row */}
        <div className="flex flex-col lg:flex-row gap-3 items-stretch lg:items-center justify-between">
          {/* Search Input */}
          <div className="relative flex-1 min-w-[240px]">
            <Search className="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
            <input
              type="text"
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              placeholder="Cari pelaku, perubahan, aksi, atau IP address..."
              className="w-full pl-9 pr-3 py-1.5 text-xs bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-indigo-500/20"
            />
          </div>

          {/* Category Dropdown */}
          <div className="min-w-[180px]">
            <select
              value={categoryFilter}
              onChange={(e) => setCategoryFilter(e.target.value)}
              className="w-full py-1.5 px-3 text-xs font-medium border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-800 text-slate-700 dark:text-slate-200 focus:outline-none cursor-pointer"
            >
              {categoryOptions.map(opt => (
                <option key={opt.key} value={opt.key}>{opt.label}</option>
              ))}
            </select>
          </div>

          {/* Date Pickers */}
          <div className="flex items-center gap-1.5">
            <div className="w-32">
              <CustomDatePicker
                value={startDate}
                onChange={setStartDate}
                placeholder="Dari tgl"
                size="sm"
              />
            </div>
            <span className="text-slate-400 text-xs">–</span>
            <div className="w-32">
              <CustomDatePicker
                value={endDate}
                onChange={setEndDate}
                placeholder="Sampai tgl"
                size="sm"
              />
            </div>
            {(startDate || endDate || searchQuery || severityFilter !== 'all' || categoryFilter !== 'all') && (
              <button
                onClick={() => {
                  setStartDate('');
                  setEndDate('');
                  setSearchQuery('');
                  setSeverityFilter('all');
                  setCategoryFilter('all');
                }}
                className="p-1.5 text-slate-400 hover:text-slate-600 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 transition"
                title="Reset Filter"
              >
                <X className="w-4 h-4" />
              </button>
            )}
          </div>
        </div>

        {/* Severity Tabs */}
        <div className="flex items-center gap-1.5 border-b border-slate-100 dark:border-slate-800 pb-2">
          {[
            { key: 'all', label: 'Semua Severity', count: logs.length },
            { key: 'critical', label: '🚨 Kritis / Sensitif', count: stats.critical },
            { key: 'warning', label: '⚠️ Peringatan', count: stats.warning },
            { key: 'info', label: 'ℹ️ Reguler', count: stats.info },
          ].map(tab => (
            <button
              key={tab.key}
              onClick={() => setSeverityFilter(tab.key as any)}
              className={`px-3 py-1 rounded-lg text-xs font-semibold transition cursor-pointer flex items-center gap-1.5 ${
                severityFilter === tab.key
                  ? 'bg-indigo-50 dark:bg-indigo-950/60 text-indigo-600 dark:text-indigo-400 border border-indigo-200 dark:border-indigo-800'
                  : 'text-slate-500 hover:bg-slate-50 dark:hover:bg-slate-800 border border-transparent'
              }`}
            >
              <span>{tab.label}</span>
              <span className="text-[10px] px-1.5 py-0.2 rounded-full bg-black/5 dark:bg-white/10 font-mono">
                {tab.count}
              </span>
            </button>
          ))}
        </div>

        {/* Loading & Error State */}
        {loading && (
          <div className="flex justify-center items-center py-12">
            <RefreshCw className="w-6 h-6 animate-spin text-indigo-500" />
            <span className="ml-2 text-sm text-slate-400">Memuat riwayat audit log...</span>
          </div>
        )}

        {!loading && fetchError && (
          <div className="flex items-center gap-2 text-rose-500 text-sm py-8 justify-center">
            <AlertTriangle className="w-5 h-5" />
            <span>{fetchError}</span>
          </div>
        )}

        {/* Audit Log Entries List */}
        {!loading && !fetchError && (
          <>
            <div className="space-y-2.5 pt-1">
              {filteredLogs.length === 0 ? (
                <div className="text-center py-12 text-slate-400">
                  <ShieldCheck className="w-10 h-10 mx-auto text-slate-300 dark:text-slate-700 mb-2" />
                  <p className="text-xs font-semibold">Tidak ditemukan audit log dengan filter yang dipilih</p>
                  <p className="text-[11px] text-slate-400 mt-0.5">Coba sesuaikan kata kunci pencarian atau tanggal.</p>
                </div>
              ) : (
                paginatedLogs.map((log) => {
                  const hasDiff = (log.oldValues && Object.keys(log.oldValues).length > 0) ||
                                  (log.newValues && Object.keys(log.newValues).length > 0);
                  const isCritical = log.severity === 'critical';
                  const isWarning = log.severity === 'warning';

                  return (
                    <div
                      key={log.id}
                      className={`p-3.5 rounded-xl border transition flex flex-col sm:flex-row sm:items-center justify-between gap-3 ${
                        isCritical
                          ? 'bg-rose-50/40 dark:bg-rose-950/20 border-rose-200 dark:border-rose-900/50'
                          : isWarning
                          ? 'bg-amber-50/30 dark:bg-amber-950/15 border-amber-200 dark:border-amber-900/40'
                          : 'bg-white dark:bg-slate-900 border-slate-100 dark:border-slate-800 hover:border-slate-200'
                      }`}
                    >
                      {/* Left info & actor */}
                      <div className="space-y-1.5 flex-1 min-w-0">
                        <div className="flex flex-wrap items-center gap-1.5">
                          {/* Severity Tag */}
                          {isCritical ? (
                            <span className="px-2 py-0.5 rounded-md text-[10px] font-bold uppercase tracking-wider bg-rose-100 dark:bg-rose-900/60 text-rose-700 dark:text-rose-300 flex items-center gap-1">
                              <ShieldAlert className="w-3 h-3" />
                              Kritis
                            </span>
                          ) : isWarning ? (
                            <span className="px-2 py-0.5 rounded-md text-[10px] font-bold uppercase tracking-wider bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300 flex items-center gap-1">
                              <AlertTriangle className="w-3 h-3" />
                              Peringatan
                            </span>
                          ) : (
                            <span className="px-2 py-0.5 rounded-md text-[10px] font-bold uppercase tracking-wider bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400">
                              Info
                            </span>
                          )}

                          {/* Category Tag */}
                          {log.category && (
                            <span className="px-2 py-0.5 rounded-md text-[10px] font-semibold bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 font-mono">
                              {log.category}
                            </span>
                          )}

                          {/* Action Code */}
                          {log.action && (
                            <span className="text-[10px] font-mono text-slate-400 dark:text-slate-500">
                              [{log.action}]
                            </span>
                          )}
                        </div>

                        {/* Title / Description */}
                        <p className="text-xs font-bold text-slate-800 dark:text-slate-100 leading-snug">
                          {log.title}
                        </p>

                        {/* Actor & Metadata Footer */}
                        <div className="flex flex-wrap items-center gap-2 text-[11px] text-slate-500 dark:text-slate-400">
                          <span className="flex items-center gap-1 font-medium text-slate-700 dark:text-slate-300">
                            <User className="w-3 h-3 text-slate-400" />
                            {log.userName || 'Sistem'}
                            {log.userRole && (
                              <span className="text-[10px] text-indigo-600 dark:text-indigo-400 bg-indigo-50 dark:bg-indigo-950 px-1.5 py-0.2 rounded font-mono">
                                {log.userRole}
                              </span>
                            )}
                          </span>

                          <span>•</span>

                          <span className="flex items-center gap-1">
                            <Clock className="w-3 h-3 text-slate-400" />
                            {log.waktu}
                          </span>

                          {log.ipAddress && (
                            <>
                              <span>•</span>
                              <span className="flex items-center gap-1 font-mono text-[10px] text-slate-400">
                                <Globe className="w-3 h-3" />
                                {log.ipAddress}
                              </span>
                            </>
                          )}
                        </div>
                      </div>

                      {/* Right Action: Diff Button */}
                      {hasDiff && (
                        <div className="shrink-0 self-start sm:self-center">
                          <button
                            type="button"
                            onClick={() => setSelectedDiffLog(log)}
                            className="px-3 py-1.5 text-xs font-semibold bg-indigo-50 dark:bg-indigo-950/60 hover:bg-indigo-100 dark:hover:bg-indigo-900/60 text-indigo-600 dark:text-indigo-400 border border-indigo-200 dark:border-indigo-800 rounded-xl transition flex items-center gap-1.5 shadow-2xs cursor-pointer"
                          >
                            <FileCode2 className="w-3.5 h-3.5" />
                            <span>Lihat Perubahan</span>
                          </button>
                        </div>
                      )}
                    </div>
                  );
                })
              )}
            </div>

            {/* Pagination Footer */}
            {filteredLogs.length > pageSize && (
              <div className="flex flex-col sm:flex-row items-center justify-between gap-3 pt-4 border-t border-slate-100 dark:border-slate-800 text-xs">
                <div className="flex items-center gap-2 text-slate-500 dark:text-slate-400">
                  <span>
                    Menampilkan{' '}
                    <strong className="text-slate-800 dark:text-slate-200 font-bold font-mono">
                      {Math.min((currentPage - 1) * pageSize + 1, filteredLogs.length)} - {Math.min(currentPage * pageSize, filteredLogs.length)}
                    </strong>{' '}
                    dari <strong className="text-slate-800 dark:text-slate-200 font-bold font-mono">{filteredLogs.length}</strong> log
                  </span>
                  <span>•</span>
                  <div className="flex items-center gap-1.5">
                    <span>Per hal:</span>
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
                  >
                    «
                  </button>
                  <button
                    type="button"
                    onClick={() => setCurrentPage(p => Math.max(1, p - 1))}
                    disabled={currentPage === 1}
                    className="p-1 px-2 rounded-lg border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800 disabled:opacity-40 disabled:cursor-not-allowed transition font-medium cursor-pointer"
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
                  >
                    ›
                  </button>
                  <button
                    type="button"
                    onClick={() => setCurrentPage(totalPages)}
                    disabled={currentPage === totalPages}
                    className="p-1 px-2 rounded-lg border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800 disabled:opacity-40 disabled:cursor-not-allowed transition font-medium cursor-pointer"
                  >
                    »
                  </button>
                </div>
              </div>
            )}
          </>
        )}
      </div>

      {/* ─── MODAL DIFF PERUBAHAN DATA SENSITIF ─── */}
      {selectedDiffLog && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/70 backdrop-blur-xs">
          <div className="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl border border-slate-200 dark:border-slate-800 w-full max-w-2xl max-h-[85vh] flex flex-col overflow-hidden animate-in fade-in zoom-in-95 duration-150">
            {/* Modal Header */}
            <div className="px-5 py-4 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between bg-slate-50/70 dark:bg-slate-800/40">
              <div className="flex items-center gap-2.5">
                <div className={`p-2 rounded-xl text-white ${
                  selectedDiffLog.severity === 'critical' ? 'bg-rose-600' : 'bg-indigo-600'
                }`}>
                  <FileCode2 className="w-4 h-4" />
                </div>
                <div>
                  <h3 className="text-sm font-bold text-slate-900 dark:text-slate-100">
                    Rincian Perubahan Nilai (Audit Snapshot Diff)
                  </h3>
                  <p className="text-xs text-slate-500 dark:text-slate-400">
                    Log #{selectedDiffLog.id} • {selectedDiffLog.title}
                  </p>
                </div>
              </div>
              <button
                onClick={() => setSelectedDiffLog(null)}
                className="p-1.5 rounded-lg text-slate-400 hover:text-slate-600 hover:bg-slate-100 dark:hover:bg-slate-800 transition cursor-pointer"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            {/* Modal Context Info */}
            <div className="px-5 py-3 bg-slate-100/60 dark:bg-slate-800/80 border-b border-slate-100 dark:border-slate-800 grid grid-cols-2 sm:grid-cols-4 gap-2 text-xs">
              <div>
                <span className="text-slate-400 text-[10.5px] block">Pelaku:</span>
                <span className="font-semibold text-slate-800 dark:text-slate-200">
                  {selectedDiffLog.userName || 'Sistem'} ({selectedDiffLog.userRole || '-'})
                </span>
              </div>
              <div>
                <span className="text-slate-400 text-[10.5px] block">Waktu:</span>
                <span className="font-semibold text-slate-800 dark:text-slate-200 font-mono">
                  {selectedDiffLog.waktu}
                </span>
              </div>
              <div>
                <span className="text-slate-400 text-[10.5px] block">IP Address:</span>
                <span className="font-semibold text-slate-800 dark:text-slate-200 font-mono">
                  {selectedDiffLog.ipAddress || '-'}
                </span>
              </div>
              <div>
                <span className="text-slate-400 text-[10.5px] block">Entitas Terkait:</span>
                <span className="font-semibold text-slate-800 dark:text-slate-200 font-mono">
                  {selectedDiffLog.entityType ? `${selectedDiffLog.entityType} #${selectedDiffLog.entityId}` : '-'}
                </span>
              </div>
            </div>

            {/* Modal Body - Diff Table */}
            <div className="flex-1 overflow-y-auto p-5 space-y-3">
              <div className="border border-slate-200 dark:border-slate-800 rounded-xl overflow-hidden text-xs">
                <table className="w-full text-left border-collapse">
                  <thead>
                    <tr className="bg-slate-100/80 dark:bg-slate-800 border-b border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 font-bold">
                      <th className="p-3 w-1/3">Field / Atribut</th>
                      <th className="p-3 w-1/3 text-rose-600 dark:text-rose-400">Nilai Sebelum (Old)</th>
                      <th className="p-3 w-1/3 text-emerald-600 dark:text-emerald-400">Nilai Sesudah (New)</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                    {(() => {
                      const oldObj = selectedDiffLog.oldValues || {};
                      const newObj = selectedDiffLog.newValues || {};
                      const allKeys = Array.from(new Set([...Object.keys(oldObj), ...Object.keys(newObj)]));

                      if (allKeys.length === 0) {
                        return (
                          <tr>
                            <td colSpan={3} className="p-4 text-center text-slate-400">
                              Tidak ada snapshot nilai atribut yang tercatat.
                            </td>
                          </tr>
                        );
                      }

                      return allKeys.map((key) => {
                        const oldVal = oldObj[key];
                        const newVal = newObj[key];
                        const isChanged = JSON.stringify(oldVal) !== JSON.stringify(newVal);

                        const formatVal = (v: any) => {
                          if (v === null || v === undefined) return <span className="text-slate-300 italic font-mono">(kosong)</span>;
                          if (typeof v === 'boolean') return <span className="font-mono">{v ? 'true' : 'false'}</span>;
                          if (typeof v === 'object') return <pre className="font-mono text-[10px]">{JSON.stringify(v, null, 2)}</pre>;
                          return <span className="font-mono break-all">{String(v)}</span>;
                        };

                        return (
                          <tr
                            key={key}
                            className={isChanged ? 'bg-amber-50/20 dark:bg-amber-950/10' : ''}
                          >
                            <td className="p-3 font-semibold font-mono text-slate-700 dark:text-slate-300">
                              {key}
                            </td>
                            <td className="p-3 bg-rose-50/30 dark:bg-rose-950/20 text-rose-800 dark:text-rose-300 line-through opacity-85">
                              {formatVal(oldVal)}
                            </td>
                            <td className="p-3 bg-emerald-50/30 dark:bg-emerald-950/20 text-emerald-800 dark:text-emerald-300 font-semibold">
                              {formatVal(newVal)}
                            </td>
                          </tr>
                        );
                      });
                    })()}
                  </tbody>
                </table>
              </div>
            </div>

            {/* Modal Footer */}
            <div className="px-5 py-3 border-t border-slate-100 dark:border-slate-800 bg-white dark:bg-slate-900 flex justify-end">
              <button
                type="button"
                onClick={() => setSelectedDiffLog(null)}
                className="px-4 py-2 text-xs font-semibold text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-xl transition cursor-pointer"
              >
                Tutup
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};
