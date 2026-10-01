import React, { useState, useEffect, useCallback, useMemo } from 'react';
import {
  BookOpen,
  FileSpreadsheet,
  Download,
  CheckCircle2,
  AlertTriangle,
  RefreshCw,
  Sliders,
  Building2,
  Users,
  Loader2,
  Calendar,
  Layers,
  ArrowRight,
  Filter,
} from 'lucide-react';
import { payrollApi } from '../services/endpoints';
import type {
  GlJournalResponse,
  GlJournalFlat,
  GlJournalGrouped,
  GlJournalSegment,
  GlLine,
} from '../types';
import {
  formatCurrency,
  errMsg,
  inputCls,
  btnPrimary,
  btnGhost,
  Banner,
  useBanner,
  EmptyState,
  Spinner,
} from './PayrollManagement';
import { PayrollGlAccounts } from './PayrollGlAccounts';

interface PayrollGlJournalProps {
  canManage: boolean;
  initialPayrollId?: number;
}

export const PayrollGlJournal: React.FC<PayrollGlJournalProps> = ({
  canManage,
  initialPayrollId,
}) => {
  const { banner, show, clear } = useBanner();

  // Sub-tab: 'preview' (Jurnal) vs 'coa' (Pemetaan Akun)
  const [subTab, setSubTab] = useState<'preview' | 'coa'>('preview');

  // Daftar payroll runs
  const [runs, setRuns] = useState<any[]>([]);
  const [runsLoading, setRunsLoading] = useState(true);
  const [selectedRunId, setSelectedRunId] = useState<number | null>(initialPayrollId ?? null);

  // Group by: 'none' | 'division' | 'branch'
  const [groupBy, setGroupBy] = useState<'none' | 'division' | 'branch'>('none');

  // Preview state
  const [journal, setJournal] = useState<GlJournalResponse | null>(null);
  const [loadingJournal, setLoadingJournal] = useState(false);

  // Export state
  const [exportingFormat, setExportingFormat] = useState<'csv' | 'json' | null>(null);

  // 1. Muat daftar runs
  const loadRuns = useCallback(async () => {
    setRunsLoading(true);
    try {
      const res = await payrollApi.listRuns();
      const allRuns: any[] = res?.data ?? [];
      // Hanya run yang sudah dihitung (calculated, submitted, approved, paid)
      const eligible = allRuns.filter((r) =>
        ['calculated', 'submitted', 'approved', 'paid'].includes(r.status)
      );
      setRuns(eligible);
      setSelectedRunId((prev) => {
        if (prev && eligible.some((r) => r.id === prev)) return prev;
        return eligible.length > 0 ? eligible[0].id : null;
      });
    } catch (e: any) {
      show('error', errMsg(e, 'Gagal memuat daftar run untuk jurnal GL.'));
    } finally {
      setRunsLoading(false);
    }
  }, [show]);

  useEffect(() => {
    loadRuns();
  }, [loadRuns]);

  // 2. Muat preview jurnal
  const loadJournal = useCallback(
    async (runId: number, group: 'none' | 'division' | 'branch') => {
      setLoadingJournal(true);
      try {
        const res: any = await payrollApi.previewGlJournal(runId, group);
        const journalData: GlJournalResponse = res?.data ?? res;
        setJournal(journalData);
      } catch (e: any) {
        show('error', errMsg(e, 'Gagal memuat pratinjau jurnal GL.'));
      } finally {
        setLoadingJournal(false);
      }
    },
    [show]
  );

  useEffect(() => {
    if (selectedRunId) {
      loadJournal(selectedRunId, groupBy);
    } else {
      setJournal(null);
    }
  }, [selectedRunId, groupBy, loadJournal]);

  // 3. Ekspor berkas jurnal
  const handleExport = async (format: 'csv' | 'json') => {
    if (!selectedRunId) return;
    setExportingFormat(format);
    try {
      await payrollApi.exportGlJournal(selectedRunId, format, groupBy);
      show('success', `Jurnal payroll berhasil diekspor (${format.toUpperCase()}).`);
    } catch (e: any) {
      show('error', errMsg(e, 'Gagal mengekspor jurnal payroll.'));
    } finally {
      setExportingFormat(null);
    }
  };

  const selectedRun = useMemo(
    () => runs.find((r) => r.id === selectedRunId),
    [runs, selectedRunId]
  );

  const actualJournal = useMemo(() => {
    if (!journal) return null;
    return ((journal as any)?.data ?? journal) as GlJournalResponse;
  }, [journal]);

  const isGrouped = actualJournal != null && 'segments' in actualJournal;
  const flatJournal = actualJournal && !('segments' in actualJournal) ? (actualJournal as GlJournalFlat) : null;
  const groupedJournal = isGrouped ? (actualJournal as GlJournalGrouped) : null;

  return (
    <div className="space-y-5">
      <Banner banner={banner} onClose={clear} />

      {/* Navigasi Sub-Tab: Pratinjau Jurnal vs Pemetaan Akun */}
      <div className="flex items-center justify-between gap-3 border-b border-slate-200 dark:border-slate-800 pb-2">
        <div className="flex gap-2">
          <button
            onClick={() => setSubTab('preview')}
            className={`inline-flex items-center gap-2 px-3.5 py-2 rounded-xl text-xs font-semibold transition cursor-pointer ${
              subTab === 'preview'
                ? 'bg-indigo-600 text-white shadow-xs shadow-indigo-500/20'
                : 'text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800'
            }`}
          >
            <BookOpen className="w-4 h-4" />
            <span>Pratinjau & Ekspor Jurnal</span>
          </button>
          <button
            onClick={() => setSubTab('coa')}
            className={`inline-flex items-center gap-2 px-3.5 py-2 rounded-xl text-xs font-semibold transition cursor-pointer ${
              subTab === 'coa'
                ? 'bg-indigo-600 text-white shadow-xs shadow-indigo-500/20'
                : 'text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800'
            }`}
          >
            <Sliders className="w-4 h-4" />
            <span>Pemetaan Akun (COA)</span>
          </button>
        </div>
      </div>

      {subTab === 'coa' ? (
        <PayrollGlAccounts
          canManage={canManage}
          onAccountsUpdated={() => {
            if (selectedRunId) loadJournal(selectedRunId, groupBy);
          }}
        />
      ) : (
        <div className="space-y-5">
          {/* Header Kontrol: Pemilih Run, Dimensi Grouping, dan Tombol Ekspor */}
          <div className="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-4 shadow-xs flex flex-col lg:flex-row lg:items-center justify-between gap-4">
            <div className="flex items-center gap-3">
              <div className="w-10 h-10 rounded-xl bg-indigo-50 dark:bg-indigo-950/60 flex items-center justify-center text-indigo-600 dark:text-indigo-400">
                <FileSpreadsheet className="w-5 h-5" />
              </div>
              <div>
                <h3 className="text-sm font-bold text-slate-900 dark:text-white">
                  Jurnal Akuntansi Payroll (General Ledger)
                </h3>
                <p className="text-xs text-slate-500 dark:text-slate-400">
                  Pratinjau jurnal debit/kredit seimbang per run atau berdimensi cost center (Divisi/Cabang).
                </p>
              </div>
            </div>

            <div className="flex items-center gap-2.5 flex-wrap">
              {/* Dropdown Pemilih Payroll Run */}
              <div className="flex items-center gap-2">
                <span className="text-xs font-semibold text-slate-500 dark:text-slate-400 whitespace-nowrap">
                  Periode Run:
                </span>
                <select
                  value={selectedRunId ?? ''}
                  onChange={(e) => setSelectedRunId(Number(e.target.value))}
                  disabled={runsLoading || runs.length === 0}
                  className={`${inputCls} !py-2 !text-xs max-w-xs cursor-pointer`}
                >
                  {runs.length === 0 && <option value="">Tidak ada payroll yang sudah dihitung</option>}
                  {runs.map((r) => (
                    <option key={r.id} value={r.id}>
                      {r.period_label || `Periode ${r.period_month}/${r.period_year}`} — ({r.status.toUpperCase()})
                    </option>
                  ))}
                </select>
              </div>

              {/* Dropdown Group By */}
              <div className="flex items-center gap-2">
                <span className="text-xs font-semibold text-slate-500 dark:text-slate-400 whitespace-nowrap">
                  Dimensi:
                </span>
                <select
                  value={groupBy}
                  onChange={(e) => setGroupBy(e.target.value as 'none' | 'division' | 'branch')}
                  disabled={!selectedRunId || loadingJournal}
                  className={`${inputCls} !py-2 !text-xs cursor-pointer`}
                >
                  <option value="none">Flat (Seluruh Perusahaan)</option>
                  <option value="division">Per Divisi (Cost Center)</option>
                  <option value="branch">Per Cabang / Kantor</option>
                </select>
              </div>

              {/* Tombol Ekspor CSV & JSON */}
              {canManage && selectedRun && (
                <div className="flex items-center gap-1.5">
                  <button
                    onClick={() => handleExport('csv')}
                    disabled={exportingFormat !== null || loadingJournal}
                    className="inline-flex items-center gap-1 px-3 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold transition cursor-pointer disabled:opacity-50"
                  >
                    {exportingFormat === 'csv' ? (
                      <Loader2 className="w-3.5 h-3.5 animate-spin" />
                    ) : (
                      <Download className="w-3.5 h-3.5" />
                    )}
                    <span>Ekspor CSV</span>
                  </button>
                  <button
                    onClick={() => handleExport('json')}
                    disabled={exportingFormat !== null || loadingJournal}
                    className="inline-flex items-center gap-1 px-3 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700 text-xs font-semibold transition cursor-pointer disabled:opacity-50"
                  >
                    {exportingFormat === 'json' ? (
                      <Loader2 className="w-3.5 h-3.5 animate-spin" />
                    ) : (
                      <Download className="w-3.5 h-3.5" />
                    )}
                    <span>JSON</span>
                  </button>
                </div>
              )}
            </div>
          </div>

          {loadingJournal ? (
            <Spinner label="Menyusun pratinjau jurnal akuntansi..." />
          ) : !journal ? (
            <EmptyState
              icon={<BookOpen className="w-8 h-8" />}
              title="Pilih Periode Run"
              subtitle={
                runs.length === 0
                  ? 'Belum ada periode payroll yang sudah dihitung (calculated / approved). Silakan hitung payroll terlebih dahulu pada tab Proses Payroll.'
                  : 'Pilih periode payroll di atas untuk melihat jurnal buku besar.'
              }
            />
          ) : (
            <div className="space-y-6">
              {/* Tampilan 1: Flat Journal (group_by === 'none') */}
              {flatJournal && (
                <div className="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 overflow-hidden shadow-xs">
                  <div className="px-5 py-3.5 border-b border-slate-100 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-800/40 flex items-center justify-between">
                    <div className="flex items-center gap-2">
                      <BookOpen className="w-4 h-4 text-indigo-500" />
                      <span className="text-xs font-bold text-slate-800 dark:text-slate-200">
                        Jurnal Buku Besar — {flatJournal.payroll?.period_label || (flatJournal.payroll ? `Periode ${flatJournal.payroll.period_month}/${flatJournal.payroll.period_year}` : 'Periode Run')}
                      </span>
                    </div>
                    <div>
                      {flatJournal.balanced ? (
                        <span className="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[11px] font-bold bg-emerald-50 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800/40">
                          <CheckCircle2 className="w-3.5 h-3.5" />
                          Seimbang (Debit = Kredit)
                        </span>
                      ) : (
                        <span className="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[11px] font-bold bg-rose-50 text-rose-700 dark:bg-rose-950/60 dark:text-rose-300 border border-rose-200 dark:border-rose-800/40">
                          <AlertTriangle className="w-3.5 h-3.5" />
                          Tidak Seimbang
                        </span>
                      )}
                    </div>
                  </div>

                  <div className="overflow-x-auto">
                    <table className="w-full text-xs">
                      <thead>
                        <tr className="text-left text-slate-400 border-b border-slate-100 dark:border-slate-800 bg-slate-50/30 dark:bg-slate-800/20">
                          <th className="px-4 py-2.5 font-semibold">Kode Akun</th>
                          <th className="px-4 py-2.5 font-semibold">Nama Akun Buku Besar</th>
                          <th className="px-4 py-2.5 font-semibold text-right">Debit</th>
                          <th className="px-4 py-2.5 font-semibold text-right">Kredit</th>
                        </tr>
                      </thead>
                      <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                        {(flatJournal.lines ?? []).map((l, idx) => (
                          <tr
                            key={idx}
                            className="hover:bg-slate-50/60 dark:hover:bg-slate-800/40 transition"
                          >
                            <td className="px-4 py-3 font-mono font-bold text-slate-800 dark:text-slate-200">
                              {l.account_code}
                            </td>
                            <td className="px-4 py-3 text-slate-700 dark:text-slate-300">
                              <span className="font-semibold block">{l.account_name}</span>
                              <span className="text-[10px] text-slate-400 font-mono block">
                                {l.account_key || (l as any).key || l.description}
                              </span>
                            </td>
                            <td className="px-4 py-3 text-right font-mono font-medium text-slate-900 dark:text-slate-100">
                              {l.debit > 0 ? formatCurrency(l.debit) : '-'}
                            </td>
                            <td className="px-4 py-3 text-right font-mono font-medium text-slate-900 dark:text-slate-100">
                              {l.credit > 0 ? formatCurrency(l.credit) : '-'}
                            </td>
                          </tr>
                        ))}
                      </tbody>
                      <tfoot>
                        <tr className="border-t-2 border-slate-200 dark:border-slate-700 bg-slate-50/80 dark:bg-slate-800/60 font-bold">
                          <td colSpan={2} className="px-4 py-3 text-slate-800 dark:text-slate-100">
                            TOTAL
                          </td>
                          <td className="px-4 py-3 text-right font-mono text-indigo-600 dark:text-indigo-400">
                            {formatCurrency(flatJournal.total_debit)}
                          </td>
                          <td className="px-4 py-3 text-right font-mono text-indigo-600 dark:text-indigo-400">
                            {formatCurrency(flatJournal.total_credit)}
                          </td>
                        </tr>
                      </tfoot>
                    </table>
                  </div>
                </div>
              )}

              {/* Tampilan 2: Grouped Journal (division / branch) */}
              {groupedJournal && (
                <div className="space-y-6">
                  {/* Banner Grand Total */}
                  <div className="rounded-2xl border border-indigo-200 dark:border-indigo-900/40 bg-indigo-50/40 dark:bg-indigo-950/20 p-4 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                    <div className="flex items-center gap-3">
                      <div className="w-9 h-9 rounded-xl bg-indigo-600 text-white flex items-center justify-center">
                        <Layers className="w-5 h-5" />
                      </div>
                      <div>
                        <span className="text-xs font-bold text-slate-900 dark:text-white block">
                          Jurnal Terkelompok: {groupedJournal.group_by === 'division' ? 'Per Divisi (Cost Center)' : 'Per Cabang Kantor'}
                        </span>
                        <span className="text-[11px] text-slate-500 dark:text-slate-400">
                          Total {(groupedJournal.segments ?? []).length} segmen biaya
                        </span>
                      </div>
                    </div>

                    <div className="flex items-center gap-6">
                      <div>
                        <span className="text-[10px] text-slate-400 uppercase font-semibold block">
                          Grand Total Debit
                        </span>
                        <span className="font-mono text-xs font-bold text-indigo-600 dark:text-indigo-400">
                          {formatCurrency(groupedJournal.grand_total_debit)}
                        </span>
                      </div>
                      <div>
                        <span className="text-[10px] text-slate-400 uppercase font-semibold block">
                          Grand Total Kredit
                        </span>
                        <span className="font-mono text-xs font-bold text-indigo-600 dark:text-indigo-400">
                          {formatCurrency(groupedJournal.grand_total_credit)}
                        </span>
                      </div>
                      <div>
                        {groupedJournal.balanced ? (
                          <span className="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800/40">
                            <CheckCircle2 className="w-3 h-3" /> Seimbang
                          </span>
                        ) : (
                          <span className="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[10px] font-bold bg-rose-50 text-rose-700 dark:bg-rose-950/60 dark:text-rose-300 border border-rose-200 dark:border-rose-800/40">
                            <AlertTriangle className="w-3 h-3" /> Selisih
                          </span>
                        )}
                      </div>
                    </div>
                  </div>

                  {/* Daftar Segmen */}
                  {(groupedJournal.segments ?? []).map((seg, sIdx) => {
                    const isSentinel = seg.segment_id === null;
                    return (
                      <div
                        key={seg.key ?? sIdx}
                        className={`rounded-2xl border overflow-hidden shadow-xs ${
                          isSentinel
                            ? 'border-dashed border-slate-300 dark:border-slate-700 bg-slate-50/30 dark:bg-slate-900/30'
                            : 'border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900'
                        }`}
                      >
                        <div className="px-5 py-3 border-b border-slate-100 dark:border-slate-800 bg-slate-50/60 dark:bg-slate-800/50 flex items-center justify-between">
                          <div className="flex items-center gap-2">
                            <span className="w-2.5 h-2.5 rounded-full bg-indigo-500" />
                            <h4 className="text-xs font-bold text-slate-800 dark:text-slate-100">
                              {seg.label}
                            </h4>
                            {isSentinel && (
                              <span className="text-[10px] px-2 py-0.5 rounded-full bg-slate-200 text-slate-700 dark:bg-slate-800 dark:text-slate-400">
                                Unallocated
                              </span>
                            )}
                          </div>
                          <div className="flex items-center gap-3">
                            <span className="text-[11px] text-slate-500 dark:text-slate-400 font-mono">
                              Debit: {formatCurrency(seg.total_debit)} | Kredit: {formatCurrency(seg.total_credit)}
                            </span>
                            {seg.balanced ? (
                              <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300">
                                <CheckCircle2 className="w-3 h-3" /> OK
                              </span>
                            ) : (
                              <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-rose-50 text-rose-700 dark:bg-rose-950/60 dark:text-rose-300">
                                <AlertTriangle className="w-3 h-3" /> Selisih
                              </span>
                            )}
                          </div>
                        </div>

                        <div className="overflow-x-auto">
                          <table className="w-full text-xs">
                            <thead>
                              <tr className="text-left text-slate-400 border-b border-slate-100 dark:border-slate-800 bg-slate-50/20 dark:bg-slate-800/10">
                                <th className="px-4 py-2 font-semibold">Kode Akun</th>
                                <th className="px-4 py-2 font-semibold">Nama Akun</th>
                                <th className="px-4 py-2 font-semibold text-right">Debit</th>
                                <th className="px-4 py-2 font-semibold text-right">Kredit</th>
                              </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                              {(seg.lines ?? []).map((l, lIdx) => (
                                <tr
                                  key={lIdx}
                                  className="hover:bg-slate-50/40 dark:hover:bg-slate-800/20 transition"
                                >
                                  <td className="px-4 py-2.5 font-mono font-bold text-slate-800 dark:text-slate-200">
                                    {l.account_code}
                                  </td>
                                  <td className="px-4 py-2.5 text-slate-700 dark:text-slate-300">
                                    <span className="font-semibold block">{l.account_name}</span>
                                    <span className="text-[10px] text-slate-400 font-mono block">
                                      {l.account_key || (l as any).key || l.description}
                                    </span>
                                  </td>
                                  <td className="px-4 py-2.5 text-right font-mono font-medium text-slate-900 dark:text-slate-100">
                                    {l.debit > 0 ? formatCurrency(l.debit) : '-'}
                                  </td>
                                  <td className="px-4 py-2.5 text-right font-mono font-medium text-slate-900 dark:text-slate-100">
                                    {l.credit > 0 ? formatCurrency(l.credit) : '-'}
                                  </td>
                                </tr>
                              ))}
                            </tbody>
                          </table>
                        </div>
                      </div>
                    );
                  })}
                </div>
              )}
            </div>
          )}
        </div>
      )}
    </div>
  );
};
