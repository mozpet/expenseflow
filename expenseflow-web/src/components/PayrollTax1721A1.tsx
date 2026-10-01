import React, { useState, useEffect, useCallback, useMemo } from 'react';
import {
  FileText,
  Download,
  Search,
  RefreshCw,
  Loader2,
  Calendar,
  AlertCircle,
  Eye,
  ShieldAlert,
  Building2,
  User,
  CheckCircle2,
  AlertTriangle,
  Receipt,
  FileSpreadsheet,
} from 'lucide-react';
import { payrollApi } from '../services/endpoints';
import type { TaxCertificate1721A1, TaxCertificate1721A1Detail, EbupotSchemaStatus } from '../types';
import {
  formatCurrency,
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
  MiniStat,
  Row,
  ItemList,
} from './PayrollManagement';

interface PayrollTax1721A1Props {
  canManage: boolean;
}

export const PayrollTax1721A1: React.FC<PayrollTax1721A1Props> = ({ canManage }) => {
  const { banner, show, clear } = useBanner();

  const currentYear = new Date().getFullYear();
  const [taxYear, setTaxYear] = useState<number>(currentYear);
  const [certificates, setCertificates] = useState<TaxCertificate1721A1[]>([]);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState('');

  // Ekspor massal state
  const [exportingFormat, setExportingFormat] = useState<'csv' | 'json' | null>(null);
  const [exportingEbupot, setExportingEbupot] = useState(false);
  const [schemaStatus, setSchemaStatus] = useState<EbupotSchemaStatus | null>(null);
  const [schemaErrors, setSchemaErrors] = useState<string[] | null>(null);

  // Detail Modal
  const [selectedUser, setSelectedUser] = useState<TaxCertificate1721A1 | null>(null);
  const [detailData, setDetailData] = useState<TaxCertificate1721A1Detail | null>(null);
  const [loadingDetail, setLoadingDetail] = useState(false);

  // Download single PDF state
  const [downloadingPdfId, setDownloadingPdfId] = useState<number | null>(null);

  // 1. Muat sertifikat per tahun pajak
  const loadCertificates = useCallback(async () => {
    setLoading(true);
    try {
      const res = await payrollApi.listTax1721A1(taxYear);
      setCertificates(res?.data ?? []);
    } catch (e: any) {
      show('error', errMsg(e, 'Gagal memuat rekapitulasi PPh 21 (1721-A1).'));
    } finally {
      setLoading(false);
    }
  }, [taxYear, show]);

  const loadSchemaStatus = useCallback(async () => {
    try {
      const res = await payrollApi.getEbupotSchemaStatus();
      setSchemaStatus(res?.data ?? null);
    } catch {
      // ignore
    }
  }, []);

  useEffect(() => {
    loadCertificates();
    loadSchemaStatus();
  }, [loadCertificates, loadSchemaStatus]);

  // 2. Muat detail sertifikat untuk modal
  const handleOpenDetail = async (cert: TaxCertificate1721A1) => {
    setSelectedUser(cert);
    setLoadingDetail(true);
    setDetailData(null);
    try {
      const res = await payrollApi.getTax1721A1(cert.user_id, taxYear);
      setDetailData(res?.data ?? cert);
    } catch (e: any) {
      if (e?.response?.status === 404 || e?.status === 404) {
        show('error', 'Data bukti potong pegawai tidak ditemukan di perusahaan ini.');
      } else {
        show('error', errMsg(e, 'Gagal memuat rincian formulir 1721-A1.'));
      }
      setSelectedUser(null);
    } finally {
      setLoadingDetail(false);
    }
  };

  // 3. Unduh berkas PDF bukti potong tunggal
  const handleDownloadPdf = async (cert: TaxCertificate1721A1) => {
    setDownloadingPdfId(cert.user_id);
    try {
      const filename = `1721A1-${cert.employee_code || cert.user_id}-${taxYear}.pdf`;
      await payrollApi.downloadTax1721A1Pdf(cert.user_id, taxYear, filename);
      show('success', `Bukti potong 1721-A1 untuk ${cert.employee_name} berhasil diunduh.`);
    } catch (e: any) {
      if (e?.response?.status === 404 || e?.status === 404) {
        show('error', 'Data bukti potong pegawai tidak ditemukan di perusahaan ini.');
      } else {
        show('error', errMsg(e, 'Gagal mengunduh berkas PDF 1721-A1.'));
      }
    } finally {
      setDownloadingPdfId(null);
    }
  };

  // 4. Ekspor massal CSV / JSON (Coretax)
  const handleExport = async (format: 'csv' | 'json') => {
    setExportingFormat(format);
    try {
      await payrollApi.exportTax1721A1(taxYear, format);
      show(
        'success',
        `Rekapitulasi 1721-A1 / Coretax tahun ${taxYear} berhasil diekspor (${format.toUpperCase()}).`
      );
    } catch (e: any) {
      show('error', errMsg(e, 'Gagal mengekspor data pajak 1721-A1.'));
    } finally {
      setExportingFormat(null);
    }
  };

  // 5. Ekspor e-Bupot 21/26 XML (Tervalidasi Skema XSD)
  const handleExportEbupot = async () => {
    setExportingEbupot(true);
    setSchemaErrors(null);
    try {
      await payrollApi.exportEbupotXml(taxYear);
      show(
        'success',
        `Berkas e-Bupot 21/26 XML tahun ${taxYear} berhasil diunduh.`
      );
    } catch (e: any) {
      if (e?.status === 422 && e?.data?.errors?.schema) {
        const rawErrors = e.data.errors.schema;
        setSchemaErrors(Array.isArray(rawErrors) ? rawErrors : [String(rawErrors)]);
      } else {
        show('error', errMsg(e, 'Gagal mengekspor berkas e-Bupot XML.'));
      }
    } finally {
      setExportingEbupot(false);
    }
  };

  // Filter pencarian
  const filteredList = useMemo(() => {
    if (!search.trim()) return certificates;
    const q = search.toLowerCase();
    return certificates.filter(
      (c) =>
        c.employee_name.toLowerCase().includes(q) ||
        (c.employee_code && c.employee_code.toLowerCase().includes(q)) ||
        (c.npwp_masked && c.npwp_masked.toLowerCase().includes(q))
    );
  }, [certificates, search]);

  // Statistik akumulasi
  const stats = useMemo(() => {
    let totalBruto = 0;
    let totalPPhTerutang = 0;
    let totalPPhDipotong = 0;
    let totalKurangBayar = 0;

    for (const c of certificates) {
      const bruto = c.bruto ?? c.gross_income ?? 0;
      const terutang = c.pph21_terutang ?? c.pph21_annual ?? 0;
      const dipotong = c.pph21_dipotong ?? c.pph21_paid ?? 0;
      const selisih = c.selisih ?? c.pph21_underpayment ?? (terutang - dipotong);

      totalBruto += bruto;
      totalPPhTerutang += terutang;
      totalPPhDipotong += dipotong;
      if (selisih > 0) totalKurangBayar += selisih;
    }

    return {
      count: certificates.length,
      totalBruto,
      totalPPhTerutang,
      totalPPhDipotong,
      totalKurangBayar,
    };
  }, [certificates]);

  const isCurrentOrFutureYear = taxYear >= currentYear;

  // Nilai aktif untuk modal detail
  const activeDetail = detailData || selectedUser;
  const detailBruto = activeDetail?.bruto ?? activeDetail?.gross_income ?? 0;
  const detailBiayaJabatan = activeDetail?.biaya_jabatan ?? 0;
  const detailIuranPensiun = activeDetail?.iuran_pensiun ?? 0;
  const detailPengurang =
    activeDetail?.deduction_total ?? detailBiayaJabatan + detailIuranPensiun;
  const detailNeto = activeDetail?.neto ?? activeDetail?.net_income ?? (detailBruto - detailPengurang);
  const detailPtkp = activeDetail?.ptkp ?? activeDetail?.ptkp_amount ?? 0;
  const detailPkp = activeDetail?.pkp ?? activeDetail?.taxable_income ?? 0;
  const detailTerutang = activeDetail?.pph21_terutang ?? activeDetail?.pph21_annual ?? 0;
  const detailDipotong = activeDetail?.pph21_dipotong ?? activeDetail?.pph21_paid ?? 0;
  const detailSelisih =
    activeDetail?.selisih ?? activeDetail?.pph21_underpayment ?? (detailTerutang - detailDipotong);

  return (
    <div className="space-y-5">
      <Banner banner={banner} onClose={clear} />

      {/* Banner Non-Dismissible Draf Proyeksi Berjalan */}
      {isCurrentOrFutureYear && (
        <div className="rounded-2xl border border-amber-200 dark:border-amber-900/50 bg-amber-50/70 dark:bg-amber-950/30 p-4 flex items-start gap-3 text-xs text-amber-800 dark:text-amber-300">
          <AlertCircle className="w-5 h-5 text-amber-600 dark:text-amber-400 shrink-0 mt-0.5" />
          <div className="space-y-1">
            <h4 className="font-bold">Draf Akumulasi Tahun Pajak Berjalan ({taxYear})</h4>
            <p className="leading-relaxed">
              Data bukti potong Formulir 1721-A1 dan Coretax untuk tahun berjalan bersifat <strong>proyeksi dinamis</strong> sampai seluruh periode penggajian (Januari - Desember) tuntas disetujui dan dibayarkan. Penyesuaian masa pajak terakhir (Desember) akan menyeimbangkan selisih kurang/lebih bayar.
            </p>
          </div>
        </div>
      )}

      {/* Header bar: Judul, Pemilih Tahun, Filter & Ekspor */}
      <div className="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-4 shadow-xs flex flex-col lg:flex-row lg:items-center justify-between gap-4">
        <div className="flex items-center gap-3">
          <div className="w-10 h-10 rounded-xl bg-indigo-50 dark:bg-indigo-950/60 flex items-center justify-center text-indigo-600 dark:text-indigo-400">
            <FileText className="w-5 h-5" />
          </div>
          <div>
            <h3 className="text-sm font-bold text-slate-900 dark:text-white">
              Bukti Potong PPh 21 Formulir 1721-A1 & Ekspor Coretax
            </h3>
            <p className="text-xs text-slate-500 dark:text-slate-400">
              Rekapitulasi tahunan PPh 21 karyawan tetap, cetak formulir 1721-A1 (PDF), dan ekspor data Coretax DJP.
            </p>
          </div>
        </div>

        <div className="flex items-center gap-2 flex-wrap">
          {/* Pemilih Tahun Pajak */}
          <div className="flex items-center gap-1.5">
            <span className="text-xs font-semibold text-slate-500 dark:text-slate-400">
              Tahun Pajak:
            </span>
            <select
              value={taxYear}
              onChange={(e) => setTaxYear(Number(e.target.value))}
              disabled={loading}
              className={`${inputCls} !py-1.5 !text-xs w-28 cursor-pointer font-bold`}
            >
              {[currentYear + 1, currentYear, currentYear - 1, currentYear - 2, currentYear - 3].map(
                (y) => (
                  <option key={y} value={y}>
                    {y}
                  </option>
                )
              )}
            </select>
          </div>

          <button
            onClick={loadCertificates}
            disabled={loading}
            className="p-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-600 dark:text-slate-300 hover:text-indigo-600 dark:hover:text-indigo-400 transition cursor-pointer"
            title="Segarkan data"
          >
            <RefreshCw className={`w-4 h-4 ${loading ? 'animate-spin' : ''}`} />
          </button>

          {/* Ekspor Coretax CSV & JSON serta e-Bupot DRAF XML */}
          {canManage && certificates.length > 0 && (
            <div className="flex flex-col sm:flex-row sm:items-center gap-1.5 flex-wrap">
              <div className="flex items-center gap-1.5 flex-wrap">
                <button
                  onClick={() => handleExport('csv')}
                  disabled={exportingFormat !== null || exportingEbupot || loading}
                  className="inline-flex items-center gap-1 px-3 py-1.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold transition cursor-pointer disabled:opacity-50"
                  title="Ekspor format CSV sesuai skema impor Coretax DJP"
                >
                  {exportingFormat === 'csv' ? (
                    <Loader2 className="w-3.5 h-3.5 animate-spin" />
                  ) : (
                    <Download className="w-3.5 h-3.5" />
                  )}
                  <span>CSV Coretax</span>
                </button>
                <button
                  onClick={() => handleExport('json')}
                  disabled={exportingFormat !== null || exportingEbupot || loading}
                  className="inline-flex items-center gap-1 px-3 py-1.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700 text-xs font-semibold transition cursor-pointer disabled:opacity-50"
                >
                  {exportingFormat === 'json' ? (
                    <Loader2 className="w-3.5 h-3.5 animate-spin" />
                  ) : (
                    <Download className="w-3.5 h-3.5" />
                  )}
                  <span>JSON</span>
                </button>
                <button
                  onClick={handleExportEbupot}
                  disabled={exportingEbupot || exportingFormat !== null || loading}
                  className={`inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-white text-xs font-semibold transition cursor-pointer disabled:opacity-50 shadow-sm ${
                    schemaStatus?.resmi
                      ? 'bg-emerald-600 hover:bg-emerald-700'
                      : 'bg-amber-600 hover:bg-amber-700'
                  }`}
                  title={
                    schemaStatus?.resmi
                      ? 'Unduh berkas XML e-Bupot 21/26 resmi tervalidasi XSD DJP'
                      : 'Unduh berkas XML e-Bupot 21/26 tervalidasi skema internal'
                  }
                >
                  {exportingEbupot ? (
                    <Loader2 className="w-3.5 h-3.5 animate-spin" />
                  ) : (
                    <FileSpreadsheet className="w-3.5 h-3.5" />
                  )}
                  <span>Ekspor e-Bupot (XML)</span>
                </button>
              </div>

              {schemaStatus && (
                <span
                  className={`text-[10px] font-medium px-2 py-0.5 rounded-md self-start sm:self-center flex items-center gap-1 border ${
                    schemaStatus.resmi
                      ? 'bg-emerald-50 dark:bg-emerald-950/40 border-emerald-200 dark:border-emerald-800/60 text-emerald-700 dark:text-emerald-300'
                      : 'bg-amber-50 dark:bg-amber-950/40 border-amber-200 dark:border-amber-800/60 text-amber-700 dark:text-amber-300'
                  }`}
                  title={schemaStatus.message}
                >
                  {schemaStatus.resmi ? (
                    <CheckCircle2 className="w-3 h-3 text-emerald-600 dark:text-emerald-400" />
                  ) : (
                    <AlertTriangle className="w-3 h-3 text-amber-600 dark:text-amber-400" />
                  )}
                  <span>
                    {schemaStatus.resmi
                      ? 'Tervalidasi XSD resmi DJP'
                      : 'Tervalidasi XSD internal'}
                  </span>
                </span>
              )}
            </div>
          )}
        </div>
      </div>

      {/* Ringkasan Statistik Pajak Tahunan */}
      <div className="grid grid-cols-2 md:grid-cols-5 gap-3">
        <MiniStat
          label="Total Karyawan"
          value={stats.count}
          icon={<User className="w-4 h-4 text-indigo-500" />}
        />
        <MiniStat
          label="Total Penghasilan Bruto"
          value={formatCurrency(stats.totalBruto)}
          icon={<Receipt className="w-4 h-4 text-sky-500" />}
        />
        <MiniStat
          label="PPh 21 Terutang Setahun"
          value={formatCurrency(stats.totalPPhTerutang)}
          icon={<FileText className="w-4 h-4 text-amber-500" />}
        />
        <MiniStat
          label="PPh 21 Telah Dipotong"
          value={formatCurrency(stats.totalPPhDipotong)}
          icon={<CheckCircle2 className="w-4 h-4 text-emerald-500" />}
        />
        <MiniStat
          label="Total Selisih Kurang Bayar"
          value={formatCurrency(stats.totalKurangBayar)}
          sub={stats.totalKurangBayar > 0 ? 'Masa pajak akhir' : 'Nihil'}
          icon={<AlertTriangle className="w-4 h-4 text-rose-500" />}
        />
      </div>

      {/* Filter Pencarian */}
      <div className="flex items-center gap-2">
        <div className="relative flex-1 max-w-sm">
          <Search className="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
          <input
            type="text"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Cari nama karyawan, kode, atau NPWP..."
            className={`${inputCls} !pl-9 !py-1.5 !text-xs`}
          />
        </div>
        {search && (
          <button
            onClick={() => setSearch('')}
            className="text-xs text-slate-500 hover:text-slate-700 dark:hover:text-slate-300 font-medium"
          >
            Reset
          </button>
        )}
      </div>

      {/* Tabel Bukti Potong 1721-A1 */}
      {loading ? (
        <Spinner label={`Menghitung rekapitulasi PPh 21 tahun ${taxYear}...`} />
      ) : filteredList.length === 0 ? (
        <EmptyState
          icon={<FileText className="w-8 h-8" />}
          title={search ? 'Pencarian Tidak Ditemukan' : 'Tidak Ada Data PPh 21'}
          subtitle={
            search
              ? 'Tidak ada pegawai yang cocok dengan kata kunci pencarian.'
              : `Belum ada data perhitungan penggajian yang diselesaikan untuk tahun pajak ${taxYear}.`
          }
        />
      ) : (
        <div className="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 overflow-hidden shadow-xs">
          <div className="overflow-x-auto">
            <table className="w-full text-xs">
              <thead>
                <tr className="text-left text-slate-400 border-b border-slate-100 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-800/40">
                  <th className="px-4 py-3 font-semibold">Karyawan</th>
                  <th className="px-4 py-3 font-semibold">NPWP (Masked)</th>
                  <th className="px-4 py-3 font-semibold text-center">PTKP</th>
                  <th className="px-4 py-3 font-semibold text-center">Masa</th>
                  <th className="px-4 py-3 font-semibold text-right">Penghasilan Bruto</th>
                  <th className="px-4 py-3 font-semibold text-right">Penghasilan Neto</th>
                  <th className="px-4 py-3 font-semibold text-right">PPh 21 Terutang</th>
                  <th className="px-4 py-3 font-semibold text-right">PPh 21 Dipotong</th>
                  <th className="px-4 py-3 font-semibold text-right">Kurang/(Lebih)</th>
                  <th className="px-4 py-3 font-semibold text-right">Aksi</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                {filteredList.map((cert) => {
                  const bruto = cert.bruto ?? cert.gross_income ?? 0;
                  const neto = cert.neto ?? cert.net_income ?? 0;
                  const terutang = cert.pph21_terutang ?? cert.pph21_annual ?? 0;
                  const dipotong = cert.pph21_dipotong ?? cert.pph21_paid ?? 0;
                  const selisih = cert.selisih ?? cert.pph21_underpayment ?? (terutang - dipotong);
                  const isDownloading = downloadingPdfId === cert.user_id;

                  return (
                    <tr
                      key={cert.user_id}
                      className="hover:bg-slate-50/60 dark:hover:bg-slate-800/40 transition"
                    >
                      <td className="px-4 py-3">
                        <span className="font-semibold text-slate-900 dark:text-slate-100 block">
                          {cert.employee_name}
                        </span>
                        <div className="flex items-center gap-1.5 text-[10px] text-slate-400">
                          {cert.employee_code && <span className="font-mono">{cert.employee_code}</span>}
                          {cert.position_name && <span>• {cert.position_name}</span>}
                        </div>
                      </td>
                      <td className="px-4 py-3 font-mono">
                        {cert.npwp_masked ? (
                          <span className="text-slate-700 dark:text-slate-300 font-medium">
                            {cert.npwp_masked}
                          </span>
                        ) : (
                          <span className="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-semibold bg-slate-100 dark:bg-slate-800 text-slate-500">
                            Non-NPWP
                          </span>
                        )}
                      </td>
                      <td className="px-4 py-3 text-center">
                        <span className="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-indigo-50 text-indigo-700 dark:bg-indigo-950/60 dark:text-indigo-400 border border-indigo-200 dark:border-indigo-800/40">
                          {cert.ptkp_status || 'TK/0'}
                        </span>
                      </td>
                      <td className="px-4 py-3 text-center text-slate-600 dark:text-slate-300 font-mono">
                        {cert.months_count ?? cert.months_active ?? 12} bln
                      </td>
                      <td className="px-4 py-3 text-right font-mono font-medium text-slate-900 dark:text-slate-100">
                        {formatCurrency(bruto)}
                      </td>
                      <td className="px-4 py-3 text-right font-mono font-medium text-slate-800 dark:text-slate-200">
                        {formatCurrency(neto)}
                      </td>
                      <td className="px-4 py-3 text-right font-mono font-semibold text-indigo-600 dark:text-indigo-400">
                        {formatCurrency(terutang)}
                      </td>
                      <td className="px-4 py-3 text-right font-mono font-medium text-emerald-600 dark:text-emerald-400">
                        {formatCurrency(dipotong)}
                      </td>
                      <td className="px-4 py-3 text-right font-mono font-bold">
                        {selisih > 0 ? (
                          <span className="text-rose-600 dark:text-rose-400">
                            +{formatCurrency(selisih)}
                          </span>
                        ) : selisih < 0 ? (
                          <span className="text-sky-600 dark:text-sky-400">
                            {formatCurrency(selisih)}
                          </span>
                        ) : (
                          <span className="text-slate-400">Rp 0</span>
                        )}
                      </td>
                      <td className="px-4 py-3 text-right">
                        <div className="flex items-center justify-end gap-1.5">
                          <button
                            onClick={() => handleOpenDetail(cert)}
                            className="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg border border-slate-200 dark:border-slate-700 hover:bg-slate-100 dark:hover:bg-slate-800 text-slate-700 dark:text-slate-300 text-xs font-semibold transition cursor-pointer"
                            title="Lihat Formulir 1721-A1"
                          >
                            <Eye className="w-3.5 h-3.5 text-indigo-500" />
                            <span>Lihat Form</span>
                          </button>
                          {canManage && (
                            <button
                              onClick={() => handleDownloadPdf(cert)}
                              disabled={isDownloading}
                              className="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold transition cursor-pointer disabled:opacity-50"
                              title="Unduh Bukti Potong 1721-A1 PDF"
                            >
                              {isDownloading ? (
                                <Loader2 className="w-3.5 h-3.5 animate-spin" />
                              ) : (
                                <Download className="w-3.5 h-3.5" />
                              )}
                              <span>PDF</span>
                            </button>
                          )}
                        </div>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {/* Modal Formulir Resmi 1721-A1 */}
      {selectedUser && (
        <Modal
          open={Boolean(selectedUser)}
          onClose={() => setSelectedUser(null)}
          title={`Formulir 1721-A1 — ${selectedUser.employee_name} (Tahun ${taxYear})`}
          icon={<FileText className="w-5 h-5 text-indigo-500" />}
          maxW="max-w-2xl"
          footer={
            <>
              <button onClick={() => setSelectedUser(null)} className={btnGhost}>
                Tutup
              </button>
              {canManage && (
                <button
                  onClick={() => handleDownloadPdf(selectedUser)}
                  disabled={downloadingPdfId === selectedUser.user_id}
                  className={btnPrimary}
                >
                  {downloadingPdfId === selectedUser.user_id ? (
                    <Loader2 className="w-3.5 h-3.5 animate-spin" />
                  ) : (
                    <Download className="w-3.5 h-3.5" />
                  )}
                  Unduh Bukti Potong Resmi (PDF)
                </button>
              )}
            </>
          }
        >
          {loadingDetail ? (
            <Spinner label="Memuat rincian formulir 1721-A1..." />
          ) : (
            <div className="space-y-5 text-xs text-slate-700 dark:text-slate-300">
              {/* Header Box Format Pajak */}
              <div className="rounded-xl border border-indigo-200 dark:border-indigo-900/60 bg-indigo-50/40 dark:bg-indigo-950/20 p-3.5 flex items-center justify-between">
                <div>
                  <h4 className="font-bold text-slate-900 dark:text-white text-sm">
                    FORMULIR 1721 - A1
                  </h4>
                  <p className="text-[11px] text-slate-500 dark:text-slate-400">
                    Bukti Pemotongan Pajak Penghasilan Pasal 21 bagi Pegawai Tetap
                  </p>
                </div>
                <div className="text-right">
                  <span className="text-[10px] text-slate-400 block font-semibold uppercase">
                    Tahun Pajak
                  </span>
                  <span className="font-bold font-mono text-indigo-600 dark:text-indigo-400 text-sm">
                    {taxYear}
                  </span>
                </div>
              </div>

              {/* Bagian A & B: Identitas */}
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                {/* Identitas Karyawan */}
                <div className="rounded-xl border border-slate-200 dark:border-slate-800 p-3 space-y-1.5 bg-white dark:bg-slate-900/60">
                  <span className="font-bold text-[11px] text-slate-400 uppercase block border-b border-slate-100 dark:border-slate-800 pb-1">
                    B. Identitas Penerima Penghasilan
                  </span>
                  <div className="space-y-1">
                    <p>
                      <strong>Nama:</strong> {activeDetail?.employee_name}
                    </p>
                    <p>
                      <strong>NIK / NIP:</strong> {activeDetail?.employee_code || '-'}
                    </p>
                    <p>
                      <strong>NPWP:</strong>{' '}
                      <span className="font-mono">{activeDetail?.npwp_masked || 'Non-NPWP'}</span>
                    </p>
                    <p>
                      <strong>Jabatan:</strong> {activeDetail?.position_name || '-'}
                    </p>
                    <p>
                      <strong>Status PTKP:</strong>{' '}
                      <span className="font-semibold text-indigo-600 dark:text-indigo-400">
                        {activeDetail?.ptkp_status || 'TK/0'}
                      </span>
                    </p>
                    <p>
                      <strong>Masa Perolehan:</strong>{' '}
                      1 s.d. {activeDetail?.months_count ?? activeDetail?.months_active ?? 12}
                    </p>
                  </div>
                </div>

                {/* Ringkasan Status Pajak */}
                <div className="rounded-xl border border-slate-200 dark:border-slate-800 p-3 space-y-1.5 bg-white dark:bg-slate-900/60">
                  <span className="font-bold text-[11px] text-slate-400 uppercase block border-b border-slate-100 dark:border-slate-800 pb-1">
                    Ringkasan Hasil Perhitungan
                  </span>
                  <div className="space-y-1">
                    <p className="flex justify-between">
                      <span className="text-slate-500">Penghasilan Bruto:</span>
                      <strong className="font-mono">{formatCurrency(detailBruto)}</strong>
                    </p>
                    <p className="flex justify-between">
                      <span className="text-slate-500">Jumlah Pengurang:</span>
                      <strong className="font-mono text-amber-600 dark:text-amber-400">
                        -{formatCurrency(detailPengurang)}
                      </strong>
                    </p>
                    <p className="flex justify-between">
                      <span className="text-slate-500">Penghasilan Neto:</span>
                      <strong className="font-mono">{formatCurrency(detailNeto)}</strong>
                    </p>
                    <p className="flex justify-between">
                      <span className="text-slate-500">PTKP:</span>
                      <strong className="font-mono">-{formatCurrency(detailPtkp)}</strong>
                    </p>
                    <p className="flex justify-between">
                      <span className="text-slate-500">PKP (Dasar Tarif):</span>
                      <strong className="font-mono">{formatCurrency(detailPkp)}</strong>
                    </p>
                  </div>
                </div>
              </div>

              {/* Rincian Komponen 1721-A1 */}
              <div className="rounded-xl border border-slate-200 dark:border-slate-800 overflow-hidden">
                <div className="bg-slate-50 dark:bg-slate-800 px-3.5 py-2 font-bold text-slate-800 dark:text-slate-200 border-b border-slate-200 dark:border-slate-700">
                  Rincian Angka Formulir 1721-A1
                </div>
                <div className="divide-y divide-slate-100 dark:divide-slate-800">
                  <div className="p-3 bg-slate-50/30 dark:bg-slate-900/30">
                    <span className="font-bold text-[11px] text-slate-500 block mb-1">
                      C. PENGHASILAN BRUTO
                    </span>
                    <div className="space-y-1">
                      <Row
                        label="1. Gaji Pokok / Imbalan Teratur"
                        value={formatCurrency(activeDetail?.bruto_regular ?? detailBruto)}
                      />
                      {(activeDetail?.bruto_benefit ?? 0) > 0 && (
                        <Row
                          label="2. Premi Asuransi dibayar Pemberi Kerja (JKK, JKM, BPJS Kes)"
                          value={formatCurrency(activeDetail?.bruto_benefit ?? 0)}
                        />
                      )}
                      {(activeDetail?.bruto_irregular ?? 0) > 0 && (
                        <Row
                          label="3. Tantiem, Bonus, Gratifikasi, Jasa Produksi & THR"
                          value={formatCurrency(activeDetail?.bruto_irregular ?? 0)}
                        />
                      )}
                      <Row
                        label="Jumlah Penghasilan Bruto (1 + 2 + 3)"
                        value={formatCurrency(detailBruto)}
                        bold
                      />
                    </div>
                  </div>

                  <div className="p-3 bg-slate-50/30 dark:bg-slate-900/30">
                    <span className="font-bold text-[11px] text-slate-500 block mb-1">
                      D. PENGURANG PENGHASILAN BRUTO
                    </span>
                    <div className="space-y-1">
                      <Row
                        label="4. Biaya Jabatan (5% dari Bruto, maks 6 Juta/Tahun)"
                        value={formatCurrency(detailBiayaJabatan)}
                      />
                      <Row
                        label="5. Iuran Pensiun / JHT / THT dibayar Pegawai"
                        value={formatCurrency(detailIuranPensiun)}
                      />
                      <Row
                        label="Jumlah Pengurang (4 + 5)"
                        value={formatCurrency(detailPengurang)}
                        bold
                      />
                    </div>
                  </div>

                  <div className="p-3 bg-slate-50/30 dark:bg-slate-900/30">
                    <span className="font-bold text-[11px] text-slate-500 block mb-1">
                      E. PERHITUNGAN PPh PASAL 21
                    </span>
                    <div className="space-y-1">
                      <Row
                        label="6. Jumlah Penghasilan Neto Setahun"
                        value={formatCurrency(detailNeto)}
                      />
                      <Row
                        label="7. Penghasilan Tidak Kena Pajak (PTKP)"
                        value={formatCurrency(detailPtkp)}
                      />
                      <Row
                        label="8. Penghasilan Kena Pajak Setahun (PKP)"
                        value={formatCurrency(detailPkp)}
                        bold
                      />
                      <Row
                        label="9. PPh Pasal 21 Terutang Setahun"
                        value={formatCurrency(detailTerutang)}
                        bold
                      />
                      <Row
                        label="10. PPh Pasal 21 Telah Dipotong & Dilunasi"
                        value={formatCurrency(detailDipotong)}
                      />
                      <div className="flex items-center justify-between pt-1 border-t border-slate-200 dark:border-slate-700">
                        <strong className="text-slate-900 dark:text-white">
                          11. PPh Pasal 21 Kurang / (Lebih) Bayar
                        </strong>
                        <strong
                          className={`font-mono text-sm ${
                            detailSelisih > 0
                              ? 'text-rose-600 dark:text-rose-400'
                              : detailSelisih < 0
                              ? 'text-sky-600 dark:text-sky-400'
                              : 'text-slate-700 dark:text-slate-300'
                          }`}
                        >
                          {detailSelisih > 0 ? `+${formatCurrency(detailSelisih)}` : formatCurrency(detailSelisih)}
                        </strong>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          )}
        </Modal>
      )}

      {/* Modal Galat Validasi Skema XSD e-Bupot */}
      {schemaErrors && (
        <Modal
          open
          onClose={() => setSchemaErrors(null)}
          title="Perbaiki Data Master Sebelum Lapor e-Bupot"
          icon={<AlertCircle className="w-4 h-4 text-rose-500" />}
          maxW="max-w-xl"
          footer={
            <button onClick={() => setSchemaErrors(null)} className={btnPrimary}>
              Tutup & Perbaiki Data
            </button>
          }
        >
          <div className="space-y-3 text-xs">
            <div className="p-3 rounded-xl bg-rose-50 dark:bg-rose-950/40 border border-rose-200 dark:border-rose-900/40 text-rose-800 dark:text-rose-200 leading-relaxed">
              <p className="font-semibold">Dokumen e-Bupot XML tidak lolos validasi skema XSD:</p>
              <p className="text-[11px] opacity-90 mt-0.5">
                Sistem menolak mengunduh berkas cacat agar laporan Anda tidak ditolak oleh sistem DJP. Silakan periksa dan perbaiki galat master data berikut:
              </p>
            </div>

            <div className="rounded-xl border border-slate-200 dark:border-slate-800 max-h-60 overflow-y-auto p-3 bg-slate-50 dark:bg-slate-900 font-mono text-[11px] space-y-1.5 text-rose-700 dark:text-rose-300">
              {schemaErrors.map((err, idx) => (
                <div key={idx} className="flex items-start gap-1.5">
                  <span className="text-slate-400 select-none">•</span>
                  <span>{err}</span>
                </div>
              ))}
            </div>

            <div className="p-3 rounded-xl bg-slate-100 dark:bg-slate-800 text-[11px] text-slate-600 dark:text-slate-300 space-y-1">
              <p className="font-semibold">Langkah Pemecahan Masalah:</p>
              <p>1. Periksa format NPWP (harus 15 atau 16 digit angka valid).</p>
              <p>2. Pastikan Profil Pajak Perusahaan pada menu Pengaturan Payroll sudah diisi lengkap.</p>
              <p>3. Pastikan data karyawan asing telah dilengkapi negara mitra P3B dan tarif yang sah.</p>
            </div>
          </div>
        </Modal>
      )}
    </div>
  );
};
