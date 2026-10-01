import React, { useState, useEffect, useCallback, useMemo } from 'react';
import {
  Building2,
  Download,
  CheckCircle2,
  AlertCircle,
  Clock,
  ShieldCheck,
  RefreshCw,
  Eye,
  Check,
  X,
  FileText,
  Calendar,
  Users,
  DollarSign,
  Loader2,
  ChevronRight,
  ShieldAlert,
} from 'lucide-react';
import { payrollApi } from '../services/endpoints';
import type {
  PayrollPaymentBatch,
  PayrollPaymentItem,
  PaymentBatchStatus,
  BankFileFormatOption,
  PinStatus,
  PayrollUserRef,
} from '../types';
import {
  formatCurrency,
  formatDate,
  errMsg,
  inputCls,
  btnPrimary,
  btnGhost,
  Banner,
  useBanner,
  Field,
  Modal,
  EmptyState,
  Spinner,
  ActionWithPinModal,
} from './PayrollManagement';

const BATCH_STATUS: Record<PaymentBatchStatus, { label: string; cls: string; dot: string }> = {
  prepared:          { label: 'Disiapkan',         cls: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300', dot: 'bg-slate-400' },
  file_generated:    { label: 'Berkas Dibuat',     cls: 'bg-sky-50 text-sky-700 dark:bg-sky-950/50 dark:text-sky-300', dot: 'bg-sky-500' },
  uploaded:          { label: 'Diunggah ke Bank',  cls: 'bg-amber-50 text-amber-700 dark:bg-amber-950/50 dark:text-amber-300', dot: 'bg-amber-500' },
  partially_settled: { label: 'Sebagian Selesai',  cls: 'bg-purple-50 text-purple-700 dark:bg-purple-950/50 dark:text-purple-300', dot: 'bg-purple-500' },
  settled:           { label: 'Selesai',           cls: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300', dot: 'bg-emerald-500' },
  reconciled:        { label: 'Terekonsiliasi',    cls: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300', dot: 'bg-emerald-500' },
};

const ITEM_STATUS: Record<string, { label: string; cls: string }> = {
  pending:          { label: 'Menunggu',    cls: 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-400' },
  success:          { label: 'Berhasil',    cls: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300' },
  failed:           { label: 'Gagal',       cls: 'bg-rose-50 text-rose-700 dark:bg-rose-950/50 dark:text-rose-300' },
  rejected_by_bank: { label: 'Ditolak Bank', cls: 'bg-amber-50 text-amber-700 dark:bg-amber-950/50 dark:text-amber-300' },
};

function renderUserName(ref: PayrollUserRef | any): string {
  if (!ref) return '-';
  if (typeof ref === 'object' && ref.name) return ref.name;
  if (typeof ref === 'number') return `User #${ref}`;
  return String(ref);
}

interface PayrollDisbursementProps {
  canManage: boolean;
  initialPayrollId?: number;
  onSelectRun?: (runId: number) => void;
}

export const PayrollDisbursement: React.FC<PayrollDisbursementProps> = ({
  canManage,
  initialPayrollId,
}) => {
  const { banner, show, clear } = useBanner();

  // Daftar payroll runs (hanya status approved & paid yang relevan untuk disbursement)
  const [runs, setRuns] = useState<any[]>([]);
  const [runsLoading, setRunsLoading] = useState(true);
  const [selectedRunId, setSelectedRunId] = useState<number | null>(initialPayrollId ?? null);

  // Batches untuk run terpilih
  const [batches, setBatches] = useState<PayrollPaymentBatch[]>([]);
  const [bankFormats, setBankFormats] = useState<BankFileFormatOption[]>([]);
  const [loadingBatches, setLoadingBatches] = useState(false);

  // Form generate batch baru
  const [showGenerateModal, setShowGenerateModal] = useState(false);
  const [selectedFormat, setSelectedFormat] = useState<string>('');
  const [valueDate, setValueDate] = useState<string>(new Date().toISOString().slice(0, 10));
  const [generating, setGenerating] = useState(false);

  // PIN step-up status
  const [pinStatus, setPinStatus] = useState<PinStatus | null>(null);
  const [showPinModal, setShowPinModal] = useState(false);

  // Detail & Rekonsiliasi batch
  const [detailBatchId, setDetailBatchId] = useState<number | null>(null);
  const [detailBatch, setDetailBatch] = useState<PayrollPaymentBatch | null>(null);
  const [loadingDetail, setLoadingDetail] = useState(false);

  // Draft rekonsiliasi lokal: item_id => { status, bank_reference_no, failure_reason }
  const [reconcileEdits, setReconcileEdits] = useState<
    Record<number, { status: 'success' | 'failed' | 'rejected_by_bank'; bank_reference_no?: string; failure_reason?: string }>
  >({});
  const [savingReconcile, setSavingReconcile] = useState(false);

  // Unduh progress
  const [downloadingId, setDownloadingId] = useState<number | null>(null);

  // 1. Muat daftar runs yang relevan (approved / paid)
  const loadRuns = useCallback(async () => {
    setRunsLoading(true);
    try {
      const res = await payrollApi.listRuns();
      const allRuns: any[] = res?.data ?? [];
      const eligible = allRuns.filter((r) => r.status === 'approved' || r.status === 'paid');
      setRuns(eligible);
      if (!selectedRunId && eligible.length > 0) {
        setSelectedRunId(eligible[0].id);
      }
    } catch (e: any) {
      show('error', errMsg(e, 'Gagal memuat daftar payroll run.'));
    } finally {
      setRunsLoading(false);
    }
  }, [selectedRunId, show]);

  useEffect(() => {
    loadRuns();
  }, [loadRuns]);

  // Cek status PIN saat komponen mount
  useEffect(() => {
    payrollApi
      .getPinStatus()
      .then((res: any) => setPinStatus(res?.data ?? null))
      .catch(() => {});
  }, []);

  // 2. Muat payment batches untuk run terpilih
  const loadBatches = useCallback(async (runId: number) => {
    setLoadingBatches(true);
    try {
      const res = await payrollApi.listPaymentBatches(runId);
      setBatches(res?.data ?? []);
      const formats = res?.bank_formats ?? [];
      setBankFormats(formats);
      if (formats.length > 0 && !selectedFormat) {
        setSelectedFormat(formats[0].key);
      }
    } catch (e: any) {
      show('error', errMsg(e, 'Gagal memuat batch pembayaran bank.'));
    } finally {
      setLoadingBatches(false);
    }
  }, [selectedFormat, show]);

  useEffect(() => {
    if (selectedRunId) {
      loadBatches(selectedRunId);
    } else {
      setBatches([]);
    }
  }, [selectedRunId, loadBatches]);

  // 3. Muat detail batch untuk rekonsiliasi / audit
  const loadDetail = useCallback(async (batchId: number) => {
    setLoadingDetail(true);
    try {
      const res = await payrollApi.getPaymentBatch(batchId);
      setDetailBatch(res?.data ?? null);
      setReconcileEdits({});
    } catch (e: any) {
      show('error', errMsg(e, 'Gagal memuat rincian batch pembayaran.'));
    } finally {
      setLoadingDetail(false);
    }
  }, [show]);

  const openDetail = (b: PayrollPaymentBatch) => {
    setDetailBatchId(b.id);
    loadDetail(b.id);
  };

  const closeDetail = () => {
    setDetailBatchId(null);
    setDetailBatch(null);
    setReconcileEdits({});
  };

  // 4. Unduh berkas transfer bank
  const handleDownload = async (batch: PayrollPaymentBatch) => {
    setDownloadingId(batch.id);
    try {
      await payrollApi.downloadPaymentBatchFile(
        batch.id,
        `${batch.batch_reference}.txt`
      );
      show('success', `Berkas transfer ${batch.batch_reference} berhasil diunduh.`);
    } catch (e: any) {
      show('error', errMsg(e, 'Gagal mengunduh berkas transfer. Pastikan Anda memiliki izin akses.'));
    } finally {
      setDownloadingId(null);
    }
  };

  // 5. Submit Generate Batch
  const handleGenerateSubmit = async (pin?: string) => {
    if (!selectedRunId) return;
    if (!selectedFormat) {
      show('error', 'Pilih format berkas bank.');
      return;
    }

    setGenerating(true);
    try {
      const res = await payrollApi.generatePaymentBatch(selectedRunId, {
        bank_format: selectedFormat,
        value_date: valueDate,
        pin,
      });

      setShowGenerateModal(false);
      setShowPinModal(false);
      await loadBatches(selectedRunId);

      const skipped = res?.skipped_no_bank ?? 0;
      if (skipped > 0) {
        show(
          'info',
          `Berkas transfer ${res.data?.batch_reference} berhasil dibuat. Perhatian: ${skipped} karyawan di-skip karena belum memiliki nomor rekening bank valid.`
        );
      } else {
        show('success', `Berkas transfer ${res.data?.batch_reference} berhasil dibuat.`);
      }
    } catch (e: any) {
      show('error', errMsg(e, 'Gagal membuat berkas transfer bank.'));
      throw e;
    } finally {
      setGenerating(false);
    }
  };

  // 6. Tangani perubahan status item rekonsiliasi
  const handleItemStatusChange = (
    itemId: number,
    newStatus: 'success' | 'failed' | 'rejected_by_bank'
  ) => {
    setReconcileEdits((prev) => ({
      ...prev,
      [itemId]: {
        ...prev[itemId],
        status: newStatus,
        failure_reason: newStatus === 'success' ? '' : prev[itemId]?.failure_reason ?? '',
      },
    }));
  };

  const handleItemRefChange = (itemId: number, refNo: string) => {
    setReconcileEdits((prev) => ({
      ...prev,
      [itemId]: {
        ...prev[itemId],
        status: prev[itemId]?.status ?? 'success',
        bank_reference_no: refNo,
      },
    }));
  };

  const handleItemReasonChange = (itemId: number, reason: string) => {
    setReconcileEdits((prev) => ({
      ...prev,
      [itemId]: {
        ...prev[itemId],
        status: prev[itemId]?.status ?? 'failed',
        failure_reason: reason,
      },
    }));
  };

  // 7. Simpan Rekonsiliasi
  const handleSaveReconcile = async () => {
    if (!detailBatchId) return;
    const dirtyIds = Object.keys(reconcileEdits).map(Number);
    if (dirtyIds.length === 0) {
      show('info', 'Belum ada baris yang diubah.');
      return;
    }

    const payloadResults = dirtyIds.map((id) => ({
      payment_item_id: id,
      status: reconcileEdits[id].status,
      bank_reference_no: reconcileEdits[id].bank_reference_no || undefined,
      failure_reason: reconcileEdits[id].failure_reason || undefined,
    }));

    setSavingReconcile(true);
    try {
      const res = await payrollApi.reconcilePaymentBatch(detailBatchId, {
        results: payloadResults,
      });
      show('success', res.message || 'Hasil rekonsiliasi bank berhasil disimpan.');

      // Re-fetch detail batch dan batch list untuk data segar
      await loadDetail(detailBatchId);
      if (selectedRunId) {
        await loadBatches(selectedRunId);
      }
    } catch (e: any) {
      show('error', errMsg(e, 'Gagal menyimpan rekonsiliasi bank.'));
    } finally {
      setSavingReconcile(false);
    }
  };

  const selectedRun = useMemo(
    () => runs.find((r) => r.id === selectedRunId),
    [runs, selectedRunId]
  );

  return (
    <div className="space-y-5">
      <Banner banner={banner} onClose={clear} />

      {/* Header bar: Pemilih Run & Tombol Aksi */}
      <div className="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-4 shadow-xs flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div className="flex items-center gap-3">
          <div className="w-10 h-10 rounded-xl bg-indigo-50 dark:bg-indigo-950/60 flex items-center justify-center text-indigo-600 dark:text-indigo-400">
            <Building2 className="w-5 h-5" />
          </div>
          <div>
            <h2 className="text-sm font-bold text-slate-900 dark:text-white">
              Disbursement Bank & Transfer Gaji
            </h2>
            <p className="text-xs text-slate-500 dark:text-slate-400">
              Buat berkas transfer bank mitra (BCA, Mandiri, BRI, BNI, CSV) dan rekonsiliasi hasil pembayaran.
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
              {runs.length === 0 && <option value="">Tidak ada payroll yang disetujui/dibayar</option>}
              {runs.map((r) => (
                <option key={r.id} value={r.id}>
                  {r.period_label || `Periode ${r.period_month}/${r.period_year}`} — ({r.status.toUpperCase()})
                </option>
              ))}
            </select>
          </div>

          {/* Tombol Buat Berkas Transfer */}
          {canManage && selectedRun && (
            <button
              onClick={() => {
                if (bankFormats.length > 0 && !selectedFormat) {
                  setSelectedFormat(bankFormats[0].key);
                }
                setShowGenerateModal(true);
              }}
              disabled={generating}
              className={btnPrimary}
            >
              <FileText className="w-4 h-4" />
              <span>Buat Berkas Transfer</span>
            </button>
          )}
        </div>
      </div>

      {/* Rangkuman Periode Terpilih */}
      {selectedRun && (
        <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
          <div className="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-3.5">
            <span className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">
              Total Neto Gaji
            </span>
            <span className="text-sm font-bold text-indigo-600 dark:text-indigo-400 mt-1 block">
              {formatCurrency(selectedRun.total_net)}
            </span>
          </div>
          <div className="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-3.5">
            <span className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">
              Jumlah Karyawan
            </span>
            <span className="text-sm font-bold text-slate-800 dark:text-slate-100 mt-1 block">
              {selectedRun.employees_count ?? selectedRun.total_employees ?? '-'} Orang
            </span>
          </div>
          <div className="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-3.5">
            <span className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">
              Batch Transfer Dibuat
            </span>
            <span className="text-sm font-bold text-slate-800 dark:text-slate-100 mt-1 block">
              {batches.length} Batch
            </span>
          </div>
          <div className="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-3.5">
            <span className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">
              Status Payroll
            </span>
            <span className="text-xs font-semibold text-emerald-600 dark:text-emerald-400 mt-1.5 flex items-center gap-1.5">
              <CheckCircle2 className="w-3.5 h-3.5" />
              {selectedRun.status.toUpperCase()}
            </span>
          </div>
        </div>
      )}

      {/* Tabel Daftar Batch Pembayaran */}
      <div className="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 overflow-hidden shadow-xs">
        <div className="px-5 py-3.5 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
          <h3 className="text-xs font-bold text-slate-700 dark:text-slate-200 flex items-center gap-2">
            <Building2 className="w-4 h-4 text-indigo-500" />
            Riwayat Batch Transfer Bank ({batches.length})
          </h3>
          {selectedRunId && (
            <button
              onClick={() => loadBatches(selectedRunId)}
              disabled={loadingBatches}
              className="text-xs text-slate-500 hover:text-indigo-600 dark:hover:text-indigo-400 flex items-center gap-1.5 transition cursor-pointer"
            >
              <RefreshCw className={`w-3.5 h-3.5 ${loadingBatches ? 'animate-spin' : ''}`} />
              Segarkan
            </button>
          )}
        </div>

        {loadingBatches ? (
          <Spinner label="Memuat batch transfer..." />
        ) : batches.length === 0 ? (
          <EmptyState
            icon={<Building2 className="w-8 h-8" />}
            title="Belum Ada Berkas Transfer"
            subtitle="Klik 'Buat Berkas Transfer' di atas untuk meng-generate berkas transfer bank mitra."
          />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-xs">
              <thead>
                <tr className="text-left text-slate-400 border-b border-slate-100 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-800/40">
                  <th className="px-4 py-3 font-semibold">Referensi Batch</th>
                  <th className="px-4 py-3 font-semibold">Format Bank</th>
                  <th className="px-4 py-3 font-semibold text-right">Jml Data</th>
                  <th className="px-4 py-3 font-semibold text-right">Total Nominal</th>
                  <th className="px-4 py-3 font-semibold text-center">Status</th>
                  <th className="px-4 py-3 font-semibold">Dibuat Oleh</th>
                  <th className="px-4 py-3 font-semibold">Tanggal</th>
                  <th className="px-4 py-3 font-semibold text-right">Aksi</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                {batches.map((b) => {
                  const st = BATCH_STATUS[b.status] ?? BATCH_STATUS.prepared;
                  return (
                    <tr
                      key={b.id}
                      className="hover:bg-slate-50/70 dark:hover:bg-slate-800/50 transition"
                    >
                      <td className="px-4 py-3 font-mono font-medium text-slate-900 dark:text-slate-100">
                        {b.batch_reference}
                      </td>
                      <td className="px-4 py-3 font-medium text-slate-700 dark:text-slate-300">
                        {bankFormats.find((f) => f.key === b.bank_format)?.label ?? b.bank_format}
                      </td>
                      <td className="px-4 py-3 text-right text-slate-700 dark:text-slate-300 font-medium">
                        {b.total_records}
                      </td>
                      <td className="px-4 py-3 text-right font-bold text-slate-900 dark:text-slate-100">
                        {formatCurrency(b.total_amount)}
                      </td>
                      <td className="px-4 py-3 text-center">
                        <span
                          className={`inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-semibold ${st.cls}`}
                        >
                          <span className={`w-1.5 h-1.5 rounded-full ${st.dot}`} />
                          {st.label}
                        </span>
                      </td>
                      <td className="px-4 py-3 text-slate-600 dark:text-slate-400">
                        {renderUserName(b.generated_by)}
                      </td>
                      <td className="px-4 py-3 text-slate-500 dark:text-slate-400 whitespace-nowrap">
                        {formatDate(b.created_at)}
                      </td>
                      <td className="px-4 py-3 text-right whitespace-nowrap">
                        <div className="inline-flex items-center gap-1.5">
                          {canManage && (
                            <button
                              onClick={() => handleDownload(b)}
                              disabled={downloadingId === b.id}
                              title="Unduh Berkas Transfer Bank"
                              className="p-1.5 rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-600 dark:text-slate-300 hover:text-indigo-600 dark:hover:text-indigo-400 transition cursor-pointer disabled:opacity-50"
                            >
                              {downloadingId === b.id ? (
                                <Loader2 className="w-3.5 h-3.5 animate-spin" />
                              ) : (
                                <Download className="w-3.5 h-3.5" />
                              )}
                            </button>
                          )}
                          <button
                            onClick={() => openDetail(b)}
                            className="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-indigo-50 dark:bg-indigo-950/60 hover:bg-indigo-100 text-indigo-600 dark:text-indigo-400 text-xs font-semibold transition cursor-pointer"
                          >
                            <Eye className="w-3.5 h-3.5" />
                            <span>Rincian</span>
                          </button>
                        </div>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {/* Modal 1: Generate Batch Transfer */}
      <Modal
        open={showGenerateModal}
        onClose={() => setShowGenerateModal(false)}
        title="Buat Berkas Transfer Bank"
        icon={<Building2 className="w-4 h-4 text-indigo-500" />}
        maxW="max-w-md"
        footer={
          <>
            <button
              onClick={() => setShowGenerateModal(false)}
              className={btnGhost}
              disabled={generating}
            >
              Batal
            </button>
            <button
              onClick={() => {
                if (pinStatus?.has_pin) {
                  setShowPinModal(true);
                } else {
                  handleGenerateSubmit();
                }
              }}
              className={btnPrimary}
              disabled={generating}
            >
              {generating ? (
                <Loader2 className="w-3.5 h-3.5 animate-spin" />
              ) : (
                <Check className="w-3.5 h-3.5" />
              )}
              Lanjutkan Generate
            </button>
          </>
        }
      >
        <div className="space-y-4">
          <div className="rounded-xl border border-sky-200 dark:border-sky-900/40 bg-sky-50/60 dark:bg-sky-950/30 p-3 text-xs text-sky-800 dark:text-sky-300 leading-relaxed">
            Sistem akan menyusun berkas transfer bank sesuai spesifikasi format mitra bank. Nomor rekening lengkap hanya disimpan dalam berkas privat terenkripsi.
          </div>

          <Field label="Format Bank Mitra" required hint="Pilih standar berkas perbankan yang Anda gunakan.">
            <select
              value={selectedFormat}
              onChange={(e) => setSelectedFormat(e.target.value)}
              className={inputCls}
            >
              {bankFormats.map((f) => (
                <option key={f.key} value={f.key}>
                  {f.label} (.{f.extension})
                </option>
              ))}
            </select>
          </Field>

          <Field label="Tanggal Efektif Transfer" required hint="Tanggal eksekusi dana di portal perbankan.">
            <input
              type="date"
              value={valueDate}
              onChange={(e) => setValueDate(e.target.value)}
              className={inputCls}
            />
          </Field>

          {pinStatus?.has_pin && (
            <div className="flex items-center gap-2 text-xs text-emerald-700 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-950/40 p-2.5 rounded-xl border border-emerald-200 dark:border-emerald-800/40">
              <ShieldCheck className="w-4 h-4 shrink-0" />
              <span>Otorisasi step-up PIN keamanan diperlukan sebelum pembuatan berkas.</span>
            </div>
          )}
        </div>
      </Modal>

      {/* Modal 2: Step-up PIN Keamanan */}
      <ActionWithPinModal
        open={showPinModal}
        onClose={() => setShowPinModal(false)}
        title="Otorisasi Pembuatan Berkas Bank"
        icon={<ShieldCheck className="w-4 h-4 text-indigo-500" />}
        message="Masukkan PIN Keamanan Anda untuk mengonfirmasi pembuatan berkas transfer bank."
        confirmText="Konfirmasi & Buat"
        hasPin={Boolean(pinStatus?.has_pin)}
        onConfirm={handleGenerateSubmit}
      />

      {/* Modal 3: Detail & Rekonsiliasi Batch */}
      {detailBatchId && (
        <Modal
          open={Boolean(detailBatchId)}
          onClose={closeDetail}
          title={`Detail & Rekonsiliasi: ${detailBatch?.batch_reference ?? 'Memuat...'}`}
          icon={<Building2 className="w-4 h-4 text-indigo-500" />}
          maxW="max-w-4xl"
          footer={
            <div className="flex items-center justify-between w-full">
              <div className="text-xs text-slate-500 dark:text-slate-400">
                {Object.keys(reconcileEdits).length > 0 ? (
                  <span className="text-indigo-600 dark:text-indigo-400 font-semibold">
                    {Object.keys(reconcileEdits).length} baris diubah (belum disimpan)
                  </span>
                ) : (
                  <span>Belum ada perubahan rekonsiliasi</span>
                )}
              </div>
              <div className="flex items-center gap-2">
                <button onClick={closeDetail} className={btnGhost} disabled={savingReconcile}>
                  Tutup
                </button>
                {canManage &&
                  detailBatch &&
                  detailBatch.status !== 'reconciled' &&
                  detailBatch.status !== 'settled' && (
                    <button
                      onClick={handleSaveReconcile}
                      className={btnPrimary}
                      disabled={savingReconcile || Object.keys(reconcileEdits).length === 0}
                    >
                      {savingReconcile ? (
                        <Loader2 className="w-3.5 h-3.5 animate-spin" />
                      ) : (
                        <Check className="w-3.5 h-3.5" />
                      )}
                      Simpan Hasil Rekonsiliasi
                    </button>
                  )}
              </div>
            </div>
          }
        >
          {loadingDetail || !detailBatch ? (
            <Spinner label="Memuat rincian item pembayaran..." />
          ) : (
            <div className="space-y-4">
              {/* Stat card ringkas */}
              <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 bg-slate-50 dark:bg-slate-800/40 p-3.5 rounded-xl border border-slate-100 dark:border-slate-800 text-xs">
                <div>
                  <span className="text-slate-400 block font-semibold text-[10px] uppercase">
                    Format Bank
                  </span>
                  <span className="font-bold text-slate-800 dark:text-slate-200 mt-0.5 block">
                    {detailBatch.bank_format}
                  </span>
                </div>
                <div>
                  <span className="text-slate-400 block font-semibold text-[10px] uppercase">
                    Total Transfer
                  </span>
                  <span className="font-bold text-indigo-600 dark:text-indigo-400 mt-0.5 block">
                    {formatCurrency(detailBatch.total_amount)}
                  </span>
                </div>
                <div>
                  <span className="text-slate-400 block font-semibold text-[10px] uppercase">
                    Status Batch
                  </span>
                  <span className="font-bold text-slate-800 dark:text-slate-200 mt-0.5 block">
                    {BATCH_STATUS[detailBatch.status]?.label ?? detailBatch.status}
                  </span>
                </div>
                <div>
                  <span className="text-slate-400 block font-semibold text-[10px] uppercase">
                    Checksum SHA-256
                  </span>
                  <span
                    className="font-mono text-[10px] text-slate-600 dark:text-slate-400 mt-0.5 block truncate"
                    title={detailBatch.file_checksum ?? '-'}
                  >
                    {detailBatch.file_checksum ? detailBatch.file_checksum.slice(0, 16) + '...' : '-'}
                  </span>
                </div>
              </div>

              {/* Petunjuk Rekonsiliasi */}
              {canManage && detailBatch.status !== 'reconciled' && detailBatch.status !== 'settled' && (
                <div className="rounded-xl border border-amber-200 dark:border-amber-900/40 bg-amber-50/60 dark:bg-amber-950/30 p-3 text-xs text-amber-800 dark:text-amber-300 leading-relaxed flex items-start gap-2">
                  <AlertCircle className="w-4 h-4 shrink-0 mt-0.5" />
                  <div>
                    <p className="font-semibold">Mode Rekonsiliasi Pembayaran Bank</p>
                    <p className="mt-0.5">
                      Tandai status hasil transfer tiap karyawan (Berhasil / Gagal / Ditolak Bank) berdasarkan mutasi atau laporan rekening koran dari bank mitra. Klik &quot;Simpan Hasil Rekonsiliasi&quot; untuk memperbarui status.
                    </p>
                  </div>
                </div>
              )}

              {/* Tabel Items */}
              <div className="rounded-xl border border-slate-200 dark:border-slate-800 overflow-hidden">
                <table className="w-full text-xs">
                  <thead>
                    <tr className="text-left text-slate-400 border-b border-slate-100 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-800/40">
                      <th className="px-3.5 py-2.5 font-semibold">Karyawan</th>
                      <th className="px-3.5 py-2.5 font-semibold">Bank & Rekening</th>
                      <th className="px-3.5 py-2.5 font-semibold text-right">Nominal</th>
                      <th className="px-3.5 py-2.5 font-semibold text-center">Status</th>
                      <th className="px-3.5 py-2.5 font-semibold">Ref Bank / Alasan</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                    {(detailBatch.items ?? []).map((item) => {
                      const isSettled = item.status === 'success';
                      const currentEdit = reconcileEdits[item.id];
                      const activeStatus = currentEdit?.status ?? item.status;
                      const activeRef = currentEdit?.bank_reference_no ?? item.bank_reference_no ?? '';
                      const activeReason = currentEdit?.failure_reason ?? item.failure_reason ?? '';

                      return (
                        <tr
                          key={item.id}
                          className="hover:bg-slate-50/50 dark:hover:bg-slate-800/30 transition"
                        >
                          <td className="px-3.5 py-2.5">
                            <span className="font-semibold text-slate-900 dark:text-slate-100 block">
                              {item.bank_account_holder || item.user?.name || `User #${item.user_id}`}
                            </span>
                          </td>
                          <td className="px-3.5 py-2.5">
                            <span className="font-medium text-slate-700 dark:text-slate-300 block">
                              {item.bank_name || '-'}
                            </span>
                            <span className="font-mono text-[11px] text-slate-400 block">
                              {item.bank_account_no_masked}
                            </span>
                          </td>
                          <td className="px-3.5 py-2.5 text-right font-bold text-slate-900 dark:text-slate-100">
                            {formatCurrency(item.amount)}
                          </td>
                          <td className="px-3.5 py-2.5 text-center">
                            {isSettled ? (
                              <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-400">
                                <CheckCircle2 className="w-3 h-3" /> Berhasil
                              </span>
                            ) : canManage &&
                              detailBatch.status !== 'reconciled' &&
                              detailBatch.status !== 'settled' ? (
                              <select
                                value={activeStatus}
                                onChange={(e) =>
                                  handleItemStatusChange(
                                    item.id,
                                    e.target.value as 'success' | 'failed' | 'rejected_by_bank'
                                  )
                                }
                                className="rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-2 py-1 text-[11px] font-semibold text-slate-700 dark:text-slate-300 cursor-pointer"
                              >
                                <option value="pending">Menunggu</option>
                                <option value="success">Berhasil</option>
                                <option value="failed">Gagal</option>
                                <option value="rejected_by_bank">Ditolak Bank</option>
                              </select>
                            ) : (
                              <span
                                className={`inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold ${
                                  ITEM_STATUS[item.status]?.cls ?? 'bg-slate-100 text-slate-600'
                                }`}
                              >
                                {ITEM_STATUS[item.status]?.label ?? item.status}
                              </span>
                            )}
                          </td>
                          <td className="px-3.5 py-2.5">
                            {isSettled ? (
                              <div className="text-[11px] text-slate-500">
                                {item.bank_reference_no && (
                                  <span className="font-mono block">Ref: {item.bank_reference_no}</span>
                                )}
                                {item.settled_at && (
                                  <span className="text-[10px] text-slate-400 block">
                                    Settled: {formatDate(item.settled_at)}
                                  </span>
                                )}
                              </div>
                            ) : canManage &&
                              detailBatch.status !== 'reconciled' &&
                              detailBatch.status !== 'settled' ? (
                              activeStatus === 'success' ? (
                                <input
                                  type="text"
                                  placeholder="No. Ref Bank (opsional)"
                                  value={activeRef}
                                  onChange={(e) => handleItemRefChange(item.id, e.target.value)}
                                  className="w-full rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-2 py-1 text-[11px] text-slate-800 dark:text-slate-100 placeholder-slate-400 outline-none"
                                />
                              ) : activeStatus === 'failed' || activeStatus === 'rejected_by_bank' ? (
                                <input
                                  type="text"
                                  placeholder="Alasan kegagalan transfer"
                                  value={activeReason}
                                  onChange={(e) => handleItemReasonChange(item.id, e.target.value)}
                                  className="w-full rounded-lg border border-rose-200 dark:border-rose-900/60 bg-white dark:bg-slate-800 px-2 py-1 text-[11px] text-rose-700 dark:text-rose-300 placeholder-slate-400 outline-none"
                                />
                              ) : (
                                <span className="text-slate-400 text-[11px]">-</span>
                              )
                            ) : (
                              <span className="text-slate-500 text-[11px]">
                                {item.failure_reason || item.bank_reference_no || '-'}
                              </span>
                            )}
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            </div>
          )}
        </Modal>
      )}
    </div>
  );
};
