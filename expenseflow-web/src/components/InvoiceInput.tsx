import React, { useState, useEffect } from 'react';
import { Invoice, InvoiceItem } from '../types';
import {
  FilePlus,
  Trash2,
  Plus,
  CheckCircle,
  AlertCircle
} from 'lucide-react';
import { vendorApi, invoiceApi } from '../services/endpoints';
import { ApiError } from '../services/api';

interface InvoiceInputProps {
  onAddInvoice: (invoice: Invoice) => void;
}

interface VendorOption {
  id: number;
  name: string;
  tax_id?: string | null;
  is_active?: boolean;
}

export const InvoiceInput: React.FC<InvoiceInputProps> = ({ onAddInvoice }) => {
  const [step] = useState<1 | 2 | 3>(2);
  const [invoiceId, setInvoiceId] = useState('');
  const [vendorId, setVendorId] = useState<number | ''>('');
  const [vendors, setVendors] = useState<VendorOption[]>([]);
  const [poNumber, setPoNumber] = useState('');
  const [tanggalInv, setTanggalInv] = useState('');
  const [jatuhTempo, setJatuhTempo] = useState('');
  const [kategori, setKategori] = useState('Software');
  const [keterangan, setKeterangan] = useState('');

  const [submitting, setSubmitting] = useState(false);
  const [errorMsg, setErrorMsg] = useState<string | null>(null);

  // Interactive items list
  const [items, setItems] = useState<InvoiceItem[]>([]);

  const [newDesc, setNewDesc] = useState('');
  const [newQty, setNewQty] = useState(1);
  const [newHarga, setNewHarga] = useState(0);

  // Muat daftar vendor (hanya yang aktif) untuk dropdown.
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

  // Totals calculations
  const itemsSubtotal = items.reduce((sum, item) => sum + item.subtotal, 0);
  const calculatedPpn = Math.round(itemsSubtotal * 0.11);
  const grandTotal = itemsSubtotal + calculatedPpn;

  const handleAddItem = () => {
    if (!newDesc.trim()) return;
    const itemSubtotal = newQty * (newHarga || 0);
    const newItem: InvoiceItem = {
      id: `item-${items.length + 1}-${newDesc.slice(0, 4)}`,
      deskripsi: newDesc,
      qty: newQty,
      harga: newHarga,
      subtotal: itemSubtotal
    };
    setItems([...items, newItem]);
    setNewDesc('');
    setNewQty(1);
    setNewHarga(0);
  };

  const handleRemoveItem = (id: string) => {
    setItems(items.filter(it => it.id !== id));
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setErrorMsg(null);

    if (vendorId === '') {
      setErrorMsg('Pilih vendor terlebih dahulu.');
      return;
    }
    if (items.length === 0) {
      setErrorMsg('Tambahkan minimal 1 item tagihan.');
      return;
    }
    if (!invoiceId.trim() || !tanggalInv || !jatuhTempo) {
      setErrorMsg('Nomor invoice, tanggal invoice, dan jatuh tempo wajib diisi.');
      return;
    }

    setSubmitting(true);
    try {
      const created: any = await invoiceApi.create({
        vendor_id: Number(vendorId),
        invoice_number: invoiceId.trim(),
        invoice_date: tanggalInv,
        due_date: jatuhTempo,
        category: kategori,
        po_number: poNumber || undefined,
        notes: keterangan || undefined,
        items: items.map((it) => ({
          description: it.deskripsi,
          quantity: it.qty,
          unit_price: it.harga,
        })),
      });

      // Beri tahu parent agar refresh + pindah ke inbox.
      onAddInvoice({
        id: created?.invoice?.invoice_number ?? invoiceId,
        vendor: selectedVendor?.name ?? '—',
        total: grandTotal,
        jatuhTempo,
        kategori,
        sumber: 'Manual',
        status: 'Pending',
      } as Invoice);

      // Reset Form
      setInvoiceId('');
      setVendorId('');
      setPoNumber('');
      setKeterangan('');
      setItems([]);
    } catch (err) {
      if (err instanceof ApiError) {
        const firstError =
          err.data?.errors && Object.values(err.data.errors)[0];
        setErrorMsg(Array.isArray(firstError) ? firstError[0] : err.message);
      } else {
        setErrorMsg('Gagal mengirim invoice.');
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
    <form onSubmit={handleSubmit} className="grid grid-cols-1 lg:grid-cols-2 gap-6">
      {/* Form Left Side */}
      <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl p-5 shadow-sm space-y-4">
        <div className="flex justify-between items-center pb-3 border-b border-slate-100 dark:border-slate-800">
          <h3 className="text-sm font-bold text-slate-800 dark:text-slate-100 flex items-center gap-1.5">
            <FilePlus className="w-4 h-4 text-indigo-600" />
            Input Invoice Manual
          </h3>
        </div>

        {/* Custom Stepper Visualizer */}
        <div className="flex gap-1 pb-2">
          <div className="flex-1 text-center pb-2 border-b-2 border-emerald-500 font-medium text-[11px] text-emerald-600 dark:text-emerald-400 flex items-center justify-center gap-1">
            <CheckCircle className="w-3.5 h-3.5" />
            <span>1. Data Vendor</span>
          </div>
          <div className={`flex-1 text-center pb-2 border-b-2 font-semibold text-[11px] flex items-center justify-center gap-1 ${
            step >= 2 ? 'border-indigo-500 text-indigo-600' : 'border-slate-100 text-slate-400'
          }`}>
            <span>2. Detail Invoice</span>
          </div>
          <div className={`flex-1 text-center pb-2 border-b-2 font-medium text-[11px] flex items-center justify-center gap-1 ${
            step >= 3 ? 'border-indigo-500 text-indigo-600' : 'border-slate-100 text-slate-400'
          }`}>
            <span>3. Review & Simpan</span>
          </div>
        </div>

        <div className="space-y-3.5 text-xs">
          {/* Banner error validasi */}
          {errorMsg && (
            <div className="flex items-start gap-2 bg-rose-50 border border-rose-200 text-rose-700 rounded-lg px-3 py-2.5">
              <AlertCircle className="w-4 h-4 shrink-0 mt-0.5" />
              <span>{errorMsg}</span>
            </div>
          )}

          {/* Form groups */}
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label className="text-slate-500 dark:text-slate-400 font-medium mb-1 block">Nomor Invoice</label>
              <input
                type="text"
                value={invoiceId}
                onChange={(e) => setInvoiceId(e.target.value)}
                className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50/50 dark:bg-slate-800/10 text-slate-800 dark:text-slate-150 focus:outline-none focus:ring-1 focus:ring-blue-500"
                placeholder="Contoh: INV-0192"
                required
              />
            </div>
            <div>
              <label className="text-slate-500 dark:text-slate-400 font-medium mb-1 block">Kategori Pembiayaan</label>
              <select
                value={kategori}
                onChange={(e) => setKategori(e.target.value)}
                className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50/50 dark:bg-slate-800/10 text-slate-800 dark:text-slate-200 focus:outline-none"
              >
                <option>Software</option>
                <option>Percetakan</option>
                <option>Logistik</option>
                <option>Konsultasi</option>
                <option>Lainnya</option>
              </select>
            </div>
          </div>

          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label className="text-slate-500 dark:text-slate-400 font-medium mb-1 block">Nama Vendor</label>
              <select
                value={vendorId}
                onChange={(e) => setVendorId(e.target.value === '' ? '' : Number(e.target.value))}
                className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-800/10 text-slate-800 dark:text-slate-150 focus:outline-none"
                required
              >
                <option value="">— Pilih Vendor —</option>
                {vendors.map((v) => (
                  <option key={v.id} value={v.id}>{v.name}</option>
                ))}
              </select>
            </div>
            <div>
              <label className="text-slate-500 dark:text-slate-400 font-medium mb-1 block">NPWP Vendor</label>
              <input
                type="text"
                value={selectedVendor?.tax_id ?? ''}
                readOnly
                placeholder="Otomatis dari data vendor"
                className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-100 dark:bg-slate-800/30 text-slate-600 dark:text-slate-400 focus:outline-none font-mono"
              />
            </div>
          </div>

          <div>
            <label className="text-slate-500 dark:text-slate-400 font-medium mb-1 block">Nomor PO (opsional)</label>
            <input
              type="text"
              value={poNumber}
              onChange={(e) => setPoNumber(e.target.value)}
              placeholder="Contoh: PO-0035"
              className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-800/10 text-slate-800 dark:text-slate-150 focus:outline-none"
            />
          </div>

          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label className="text-slate-500 dark:text-slate-400 font-medium mb-1 block">Tanggal Invoice</label>
              <input
                type="date"
                value={tanggalInv}
                onChange={(e) => setTanggalInv(e.target.value)}
                className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-800/10 text-slate-800 dark:text-slate-150 focus:outline-none"
                required
              />
            </div>
            <div>
              <label className="text-slate-500 dark:text-slate-400 font-medium mb-1 block">Tanggal Jatuh Tempo</label>
              <input
                type="date"
                value={jatuhTempo}
                onChange={(e) => setJatuhTempo(e.target.value)}
                className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-800/10 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                required
              />
            </div>
          </div>

          <div>
            <label className="text-slate-500 dark:text-slate-400 font-medium mb-1 block">Keterangan Tambahan / Deskripsi</label>
            <textarea 
              rows={2}
              value={keterangan} 
              onChange={(e) => setKeterangan(e.target.value)} 
              className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-800/10 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-1 focus:ring-indigo-500"
              placeholder="Tulis rincian peruntukan pembayaran..."
            />
          </div>

        </div>

        <div className="flex gap-2.5 pt-2">
          <button
            type="submit"
            disabled={items.length === 0 || submitting}
            className={`flex-1 py-2.5 text-white font-semibold rounded-xl text-xs shadow-sm transition flex items-center justify-center gap-2 ${
              items.length === 0 || submitting ? 'bg-slate-300 cursor-not-allowed' : 'bg-indigo-600 hover:bg-indigo-700 hover:shadow shadow-indigo-500/20'
            }`}
          >
            {submitting && <span className="w-3.5 h-3.5 border-2 border-white/40 border-t-white rounded-full animate-spin" />}
            {submitting ? 'Mengirim...' : 'Kirim Untuk Approval'}
          </button>
        </div>
      </div>

      {/* Rincian Line Items & Approval Flow (Right Column) */}
      <div className="space-y-4">
        <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl p-5 shadow-sm space-y-4">
          <div className="flex justify-between items-center pb-3 border-b border-slate-100 dark:border-slate-800/80">
            <h3 className="text-sm font-bold text-slate-800 dark:text-slate-100">
              Item Tagihan Invoice
            </h3>
            <span className="text-[10px] bg-slate-100 dark:bg-slate-800 dark:text-slate-300 text-slate-600 font-bold px-2 py-0.5 rounded">
              {items.length} Baris
            </span>
          </div>

          {/* Form to insert temporary line item */}
          <div className="bg-slate-50/50 dark:bg-slate-850/30 p-3.5 rounded-xl border border-slate-150/40 dark:border-slate-800 space-y-2 text-xs">
            <div className="font-semibold text-slate-800 dark:text-slate-300 text-[11px] mb-1">Tambah Baris Invoice</div>
            <div className="grid grid-cols-1 md:grid-cols-1 gap-2">
              <input 
                type="text" 
                placeholder="Deskripsi item... (Contoh: Adobe Licenses)"
                value={newDesc}
                onChange={(e) => setNewDesc(e.target.value)}
                className="p-2 border border-slate-200 dark:border-slate-700 rounded-lg text-xs bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100"
              />
            </div>
            <div className="grid grid-cols-3 gap-2">
              <div>
                <label className="text-[10px] text-slate-400">Qty</label>
                <input 
                  type="number" 
                  value={newQty}
                  onChange={(e) => setNewQty(Math.max(1, parseInt(e.target.value) || 1))}
                  className="w-full p-2 border border-slate-200 dark:border-slate-700 rounded-lg text-xs bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-150 font-mono text-center"
                />
              </div>
              <div className="col-span-2">
                <label className="text-[10px] text-slate-400">Harga Satuan (Rp)</label>
                <input 
                  type="number" 
                  placeholder="Rp..."
                  value={newHarga || ''}
                  onChange={(e) => setNewHarga(parseInt(e.target.value) || 0)}
                  className="w-full p-2 border border-slate-200 dark:border-slate-700 rounded-lg text-xs bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-150 font-mono"
                />
              </div>
            </div>
            <button 
              type="button" 
              onClick={handleAddItem}
              className="w-full py-1.5 bg-indigo-50 text-indigo-700 hover:bg-indigo-100 dark:bg-indigo-950/40 dark:text-indigo-400 rounded-lg font-bold flex items-center justify-center gap-1 transition"
            >
              <Plus className="w-3.5 h-3.5" />
              <span>Tambah Rincian Baris</span>
            </button>
          </div>

          {/* Current list table */}
          {items.length === 0 ? (
            <div className="text-center py-6 text-slate-400 text-xs">Belum ada rincian item. Masukkan minimal 1 rincian item.</div>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-xs text-left border-collapse">
                <thead>
                  <tr className="bg-slate-55 border-b border-slate-100 dark:border-slate-800">
                    <th className="py-2 px-3 text-slate-500 font-semibold">Deskripsi</th>
                    <th className="py-2 px-3 text-slate-500 font-semibold text-center w-12">Qty</th>
                    <th className="py-2 px-3 text-slate-500 font-semibold text-right">Harga</th>
                    <th className="py-3.5 px-3 text-slate-500 font-semibold text-right">Subtotal</th>
                    <th className="py-2 px-3 tracking-wide"></th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                  {items.map((item) => (
                    <tr key={item.id} className="hover:bg-slate-50 dark:hover:bg-slate-850">
                      <td className="py-2.5 px-3 font-medium text-slate-800 dark:text-slate-300">
                        {item.deskripsi}
                      </td>
                      <td className="py-2.5 px-3 text-center text-slate-650 font-mono">{item.qty}</td>
                      <td className="py-2.5 px-3 text-right font-mono text-slate-650">{formatCurrency(item.harga)}</td>
                      <td className="py-2.5 px-3 text-right font-semibold font-mono text-slate-800 dark:text-slate-200">{formatCurrency(item.subtotal)}</td>
                      <td className="py-2.5 px-3 text-right">
                        <button 
                          type="button"
                          onClick={() => handleRemoveItem(item.id)}
                          className="hover:text-rose-600 text-slate-400 p-1"
                        >
                          <Trash2 className="w-4 h-4" />
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}

          {/* Totals Breakdown */}
          <div className="border-t border-slate-150 dark:border-slate-850 pt-3 space-y-1.5 text-xs text-slate-600 dark:text-slate-400">
            <div className="flex justify-between">
              <span>Subtotal Pembiayaan</span>
              <span className="font-mono">{formatCurrency(itemsSubtotal)}</span>
            </div>
            <div className="flex justify-between">
              <span>PPN (11% otomatis terhitung)</span>
              <span className="font-mono">{formatCurrency(calculatedPpn)}</span>
            </div>
            <div className="flex justify-between font-bold text-slate-900 dark:text-slate-100 pt-2 border-t border-slate-100 dark:border-slate-800 text-[13px]">
              <span>Grand Total</span>
              <span className="text-indigo-600 dark:text-indigo-400 font-mono">{formatCurrency(grandTotal)}</span>
            </div>
          </div>
        </div>

        {/* Visual Approval Chain */}
        <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl p-5 shadow-sm space-y-3.5">
          <h4 className="text-xs font-bold text-slate-800 dark:text-slate-100">Alur Approval Invoice</h4>
          <div className="space-y-3 pl-1 text-[11px] leading-relaxed">
            <div className="flex gap-3">
              <div className="relative flex flex-col items-center">
                <span className="w-6 h-6 rounded-full bg-indigo-600 text-white font-bold text-[10px] flex items-center justify-center shrink-0">1</span>
                <span className="w-0.5 h-6 bg-slate-200 dark:bg-slate-800"></span>
              </div>
              <div className="pt-0.5">
                <p className="font-bold text-slate-800 dark:text-slate-200">Staff Finance Input</p>
                <span className="text-slate-400 block">Identifikasi, formulasi detail & dokumen lampiran</span>
              </div>
            </div>

            <div className="flex gap-3">
              <div className="relative flex flex-col items-center">
                <span className="w-6 h-6 rounded-full bg-indigo-600 text-white font-bold text-[10px] flex items-center justify-center shrink-0">2</span>
                <span className="w-0.5 h-6 bg-slate-200 dark:bg-slate-800"></span>
              </div>
              <div className="pt-0.5">
                <p className="font-bold text-slate-800 dark:text-slate-200">Finance Manager Review</p>
                <span className="text-slate-400 block">Sari Rahma memverifikasi NPWP, PO & Rekening Bank Vendor</span>
              </div>
            </div>

            <div className="flex gap-3">
              <div className="relative flex flex-col items-center">
                <span className="w-6 h-6 rounded-full bg-indigo-100 text-indigo-700 dark:bg-indigo-950 dark:text-indigo-400 font-bold text-[10px] flex items-center justify-center shrink-0">3</span>
                <span className="w-0.5 h-6 bg-slate-150 dark:bg-slate-850"></span>
              </div>
              <div className="pt-0.5">
                <p className="font-bold text-slate-700 dark:text-slate-350">Direksi Approval (&gt; Rp 10 jt)</p>
                <span className="text-slate-400 block">Nominal melebihi batas, perlu tandatangan direktur tingkat II</span>
              </div>
            </div>

            <div className="flex gap-3">
              <div className="relative flex flex-col items-center">
                <span className="w-6 h-6 rounded-full bg-slate-100 text-slate-400 dark:bg-slate-800 dark:text-slate-600 font-bold text-[10px] flex items-center justify-center shrink-0">4</span>
              </div>
              <div className="pt-0.5">
                <p className="font-bold text-slate-400 dark:text-slate-500">Pembayaran Bank Mandiri Auto-disbursement</p>
                <span className="text-slate-400 block text-[10px]">Verifikasi token token & pengunduhan PDF bukti transfer</span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </form>
  );
};
