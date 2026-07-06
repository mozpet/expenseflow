import React, { useState, useEffect } from 'react';
import { Invoice } from '../types';
import {
  Scan,
  Sparkles,
  FileText,
  Check,
  ShieldCheck,
  Loader2,
  UploadCloud,
  Clock,
  AlertTriangle
} from 'lucide-react';
import { vendorApi, invoiceApi } from '../services/endpoints';
import { ApiError } from '../services/api';

interface InvoiceScanProps {
  onAddInvoice: (invoice: Invoice) => void;
}

interface VendorOption {
  id: number;
  name: string;
  tax_id?: string | null;
  is_active?: boolean;
}

export const InvoiceScan: React.FC<InvoiceScanProps> = ({ onAddInvoice }) => {
  const [loading, setLoading] = useState(false);
  const [loadingStep, setLoadingStep] = useState('');
  const [hasScanned, setHasScanned] = useState(true);
  const [kategori, setKategori] = useState('Software');
  const [catatanVerifikasi, setCatatanVerifikasi] = useState('');

  // Field yang dapat diisi user (karena belum ada OCR invoice di backend).
  const [vendors, setVendors] = useState<VendorOption[]>([]);
  const [vendorId, setVendorId] = useState<number | ''>('');
  const [invoiceNumber, setInvoiceNumber] = useState('');
  const [invoiceDate, setInvoiceDate] = useState('');
  const [dueDate, setDueDate] = useState('');
  const [subtotal, setSubtotal] = useState(0);
  const [submitting, setSubmitting] = useState(false);
  const [errorMsg, setErrorMsg] = useState<string | null>(null);

  // Muat daftar vendor aktif untuk dropdown.
  useEffect(() => {
    vendorApi
      .list()
      .then((res: any) => {
        const list = Array.isArray(res?.data) ? res.data : Array.isArray(res) ? res : [];
        setVendors(list.filter((v: VendorOption) => v.is_active !== false));
      })
      .catch(() => setVendors([]));
  }, []);

  const selectedVendor = vendors.find((v) => v.id === vendorId);
  const calculatedPpn = Math.round(subtotal * 0.11);
  const total = subtotal + calculatedPpn;

  const handleSimulateScan = () => {
    setLoading(true);
    setHasScanned(false);

    // Animasi langkah OCR (visual saja — ekstraksi invoice belum di backend).
    const steps = [
      'Menghubungkan ke layanan OCR engine...',
      'Mengekstraksi layout baris koordinat...',
      'Membaca teks dokumen...',
      'Mencocokkan NPWP & Nama Entitas Vendor...',
      'Memverifikasi integritas total aritmatika...',
      'Selesai! Lengkapi data di bawah...'
    ];

    steps.forEach((stepText, idx) => {
      setTimeout(() => {
        setLoadingStep(stepText);
        if (idx === steps.length - 1) {
          setLoading(false);
          setHasScanned(true);
        }
      }, (idx + 1) * 450);
    });
  };

  const handleSubmit = async () => {
    if (!hasScanned) return;
    setErrorMsg(null);

    if (vendorId === '') {
      setErrorMsg('Pilih vendor terlebih dahulu.');
      return;
    }
    if (!invoiceNumber.trim() || !invoiceDate || !dueDate || subtotal <= 0) {
      setErrorMsg('Nomor invoice, tanggal, jatuh tempo, dan subtotal wajib diisi.');
      return;
    }

    setSubmitting(true);
    try {
      const created: any = await invoiceApi.create({
        vendor_id: Number(vendorId),
        invoice_number: invoiceNumber.trim(),
        invoice_date: invoiceDate,
        due_date: dueDate,
        category: kategori,
        notes: catatanVerifikasi || undefined,
        items: [
          { description: 'Tagihan hasil scan invoice', quantity: 1, unit_price: subtotal },
        ],
      });

      onAddInvoice({
        id: created?.invoice?.invoice_number ?? invoiceNumber,
        vendor: selectedVendor?.name ?? '—',
        total,
        jatuhTempo: dueDate,
        kategori,
        sumber: 'Scan',
        status: 'Pending',
      } as Invoice);

      // Reset
      setVendorId('');
      setInvoiceNumber('');
      setSubtotal(0);
      setCatatanVerifikasi('');
    } catch (err) {
      if (err instanceof ApiError) {
        const firstError = err.data?.errors && Object.values(err.data.errors)[0];
        setErrorMsg(Array.isArray(firstError) ? firstError[0] : err.message);
      } else {
        setErrorMsg('Gagal menyimpan invoice.');
      }
    } finally {
      setSubmitting(false);
    }
  };

  const formatCurrency = (val: number) => {
    return new Intl.NumberFormat('id-ID', {
      style: 'currency',
      currency: 'IDR',
      maximumFractionDigits: 0
    }).format(val);
  };

  return (
    <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
      {/* Left Column Form */}
      <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl p-5 shadow-sm space-y-4">
        <div className="flex justify-between items-center pb-3 border-b border-slate-100 dark:border-slate-800">
          <h3 className="text-sm font-bold text-slate-800 dark:text-slate-100 flex items-center gap-1.5">
            <Scan className="w-4.5 h-4.5 text-indigo-600 animate-pulse" />
            Scan Invoice — OCR Otomatis
          </h3>
          <span className="text-[10px] bg-indigo-50 text-indigo-700 dark:bg-indigo-950/30 dark:text-indigo-400 font-mono font-bold px-2 py-0.5 rounded flex items-center gap-1">
            <Sparkles className="w-3 h-3" />
            AI Powered
          </span>
        </div>

        {/* Stepper progress */}
        <div className="flex gap-1">
          <div className="flex-1 text-center pb-2 border-b-2 border-emerald-500 font-bold text-[10px] text-emerald-600 flex items-center justify-center gap-1.5">
            <Check className="w-3.5 h-3.5" />
            <span>1. Upload File </span>
          </div>
          <div className={`flex-1 text-center pb-2 border-b-2 font-bold text-[10px] flex items-center justify-center gap-1.5 ${
            hasScanned ? 'border-indigo-500 text-indigo-600' : 'border-slate-100 text-slate-400'
          }`}>
            <span>2. Hasil OCR</span>
          </div>
          <div className={`flex-1 text-center pb-2 border-b-2 font-bold text-[10px] flex items-center justify-center gap-1.5 ${
            hasScanned ? 'border-slate-300 text-slate-500' : 'border-slate-100 text-slate-400'
          }`}>
            <span>3. Verifikasi</span>
          </div>
        </div>

        {/* Upload Zone */}
        {!loading && (
          <div 
            onClick={handleSimulateScan}
            className="border-2 border-dashed border-slate-200 dark:border-slate-800 bg-slate-50 hover:bg-slate-100 dark:bg-slate-950/20 dark:hover:bg-slate-950/40 rounded-xl p-5 text-center cursor-pointer transition flex flex-col items-center justify-center gap-2"
          >
            <UploadCloud className="w-8 h-8 text-indigo-600" />
            <div>
              <p className="text-xs font-semibold text-slate-700 dark:text-slate-300">invoice_PT_MajuJaya_042.pdf</p>
              <span className="text-[11px] text-emerald-600 block font-semibold mt-0.5">Dokumen berhasil terunggah (234 KB)</span>
            </div>
            <p className="text-[10px] text-slate-400">Klik di sini untuk men-scan ulang berkas atau mengunggah berkas lain</p>
          </div>
        )}

        {/* Scan progress loader */}
        {loading && (
          <div className="py-12 border border-indigo-100 dark:border-indigo-950 rounded-xl bg-indigo-50/10 dark:bg-indigo-950/5 flex flex-col items-center justify-center gap-3">
            <Loader2 className="w-8 h-8 text-indigo-600 animate-spin" />
            <p className="text-xs font-semibold text-indigo-800 dark:text-indigo-300">{loadingStep}</p>
            <span className="text-[10px] text-slate-400 font-mono">Running Azure Document Intelligence OCR Core v3.0</span>
          </div>
        )}

        {/* OCR review + form lengkapi data */}
        {hasScanned && !loading && (
          <div className="space-y-4">
            {/* Catatan: OCR invoice belum tersedia di backend */}
            <div className="bg-amber-50/50 border border-amber-200 dark:bg-amber-950/20 dark:border-amber-900/50 rounded-xl p-3 text-[11px] text-amber-800 dark:text-amber-400 flex items-start gap-2 leading-relaxed">
              <AlertTriangle className="w-4 h-4 mt-0.5 text-amber-600 shrink-0" />
              <span>
                Ekstraksi OCR untuk invoice belum tersedia di server. Lengkapi data invoice secara
                manual di bawah ini, lalu kirim untuk approval. Invoice akan tercatat dengan sumber "Scan".
              </span>
            </div>

            {errorMsg && (
              <div className="flex items-start gap-2 bg-rose-50 border border-rose-200 text-rose-700 rounded-lg px-3 py-2.5 text-xs">
                <AlertTriangle className="w-4 h-4 shrink-0 mt-0.5" />
                <span>{errorMsg}</span>
              </div>
            )}

            {/* Editable Fields */}
            <div className="space-y-3.5 pt-1 text-xs">
              <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div className="space-y-1">
                  <label className="font-semibold text-slate-700 dark:text-slate-300 block">No. Invoice</label>
                  <input
                    type="text"
                    value={invoiceNumber}
                    onChange={(e) => setInvoiceNumber(e.target.value)}
                    placeholder="INV-2026-0042"
                    className="w-full p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50/50 dark:bg-slate-800/20 text-slate-800 dark:text-slate-100 focus:outline-none font-mono"
                  />
                </div>
                <div className="space-y-1">
                  <label className="font-semibold text-slate-700 dark:text-slate-300 block">Vendor</label>
                  <select
                    value={vendorId}
                    onChange={(e) => setVendorId(e.target.value === '' ? '' : Number(e.target.value))}
                    className="w-full p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50/50 dark:bg-slate-800/20 text-slate-800 dark:text-slate-100 focus:outline-none"
                  >
                    <option value="">— Pilih Vendor —</option>
                    {vendors.map((v) => (
                      <option key={v.id} value={v.id}>{v.name}</option>
                    ))}
                  </select>
                </div>
              </div>

              <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div className="space-y-1">
                  <label className="font-semibold text-slate-700 dark:text-slate-300 block">Tanggal Faktur</label>
                  <input
                    type="date"
                    value={invoiceDate}
                    onChange={(e) => setInvoiceDate(e.target.value)}
                    className="w-full p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50/50 dark:bg-slate-800/20 text-slate-800 dark:text-slate-100 focus:outline-none"
                  />
                </div>
                <div className="space-y-1">
                  <label className="font-semibold text-slate-700 dark:text-slate-300 block">Jatuh Tempo</label>
                  <input
                    type="date"
                    value={dueDate}
                    onChange={(e) => setDueDate(e.target.value)}
                    className="w-full p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50/50 dark:bg-slate-800/20 text-slate-800 dark:text-slate-100 focus:outline-none"
                  />
                </div>
              </div>

              <div className="space-y-1">
                <label className="font-semibold text-slate-700 dark:text-slate-300 block">Subtotal (sebelum PPN)</label>
                <input
                  type="number"
                  value={subtotal || ''}
                  onChange={(e) => setSubtotal(parseInt(e.target.value) || 0)}
                  placeholder="Rp..."
                  className="w-full p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50/50 dark:bg-slate-800/20 text-slate-800 dark:text-slate-100 focus:outline-none font-mono"
                />
              </div>

              {/* Ringkasan perhitungan */}
              <div className="bg-indigo-50/50 border border-indigo-100 dark:bg-indigo-950/10 dark:border-indigo-900/40 rounded-xl p-3 space-y-1.5 font-sans">
                <div className="flex justify-between text-slate-600 dark:text-slate-400">
                  <span>PPN 11%</span>
                  <span className="font-mono">{formatCurrency(calculatedPpn)}</span>
                </div>
                <div className="flex justify-between font-bold text-slate-900 dark:text-white pt-1.5 border-t border-indigo-100 dark:border-indigo-950/50">
                  <span>Total Tagihan</span>
                  <span className="text-indigo-700 dark:text-indigo-400 font-mono">{formatCurrency(total)}</span>
                </div>
              </div>

              <div className="space-y-1">
                <label className="font-semibold text-slate-700 dark:text-slate-300 block">Kategori Pembiayaan</label>
                <select
                  value={kategori}
                  onChange={(e) => setKategori(e.target.value)}
                  className="w-full p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50/50 dark:bg-slate-800/20 text-slate-800 dark:text-slate-100 focus:outline-none"
                >
                  <option>Software</option>
                  <option>Percetakan</option>
                  <option>Logistik</option>
                  <option>Konsultasi</option>
                  <option>Lainnya</option>
                </select>
              </div>

              <div className="space-y-1">
                <label className="font-semibold text-slate-700 dark:text-slate-300 block">Catatan Verifikasi</label>
                <textarea
                  rows={2}
                  value={catatanVerifikasi}
                  onChange={(e) => setCatatanVerifikasi(e.target.value)}
                  className="w-full p-3 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50/50 dark:bg-slate-800/20 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                  placeholder="Contoh: No PO sesuai..."
                />
              </div>

              <button
                type="button"
                onClick={handleSubmit}
                disabled={submitting}
                className="w-full py-3 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-60 text-white font-bold text-xs rounded-xl shadow-md transition uppercase tracking-wider flex items-center justify-center gap-2"
              >
                {submitting && <span className="w-3.5 h-3.5 border-2 border-white/40 border-t-white rounded-full animate-spin" />}
                {submitting ? 'Mengirim...' : 'Kirim Untuk Approval'}
              </button>
            </div>
          </div>
        )}
      </div>

      {/* Right Column Preview */}
      <div className="space-y-4">
        {/* Document Frame Mockup */}
        <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl p-5 shadow-sm space-y-4">
          <h4 className="text-xs font-bold text-slate-800 dark:text-slate-100 flex items-center gap-1.5">
            <FileText className="w-4 h-4 text-indigo-600" />
            Preview Dokumen Terlampir
          </h4>
          <div className="bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-850 rounded-xl flex flex-col items-center justify-center p-8 h-64 text-slate-400 select-none">
            <Scan className="w-12 h-12 text-slate-300 dark:text-slate-800 animate-pulse mb-2" />
            <p className="text-xs font-bold text-slate-700 dark:text-slate-300">invoice_PT_MajuJaya_042.pdf</p>
            <span className="text-[10px] text-slate-400 mt-0.5">Halaman 1 dari 2 · PDF Terlindungi sandi SHA256</span>
          </div>
        </div>

        {/* File Integrity block */}
        <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl p-5 shadow-sm space-y-3">
          <h4 className="text-xs font-bold text-slate-800 dark:text-slate-100 flex items-center gap-1.5">
            <ShieldCheck className="w-4.5 h-4.5 text-emerald-600" />
            Integritas Dokumen Digital
          </h4>
          <div className="space-y-2 text-xs divide-y divide-slate-100 dark:divide-slate-800">
            <div className="flex justify-between py-1.5">
              <span className="text-slate-400">Sumber</span>
              <span className="font-semibold text-slate-800 dark:text-slate-200">Scan (input manual)</span>
            </div>
            <div className="flex justify-between py-1.5">
              <span className="text-slate-400">Vendor terpilih</span>
              <span className="font-medium text-slate-800 dark:text-slate-200">{selectedVendor?.name ?? '—'}</span>
            </div>
            <div className="flex justify-between py-1.5">
              <span className="text-slate-400">NPWP Vendor</span>
              <span className="font-mono text-[10px] text-slate-500">{selectedVendor?.tax_id ?? '—'}</span>
            </div>
            <div className="flex justify-between py-1.5">
              <span className="text-slate-400">Status</span>
              <span className="font-bold text-amber-600 flex items-center gap-1.5 font-sans">
                <Clock className="w-3.5 h-3.5" />
                Menunggu pengiriman
              </span>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
};
