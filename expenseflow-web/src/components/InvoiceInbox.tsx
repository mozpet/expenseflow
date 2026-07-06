import React, { useState } from 'react';
import { Invoice } from '../types';
import { 
  FileText, 
  AlertTriangle, 
  Clock, 
  Check, 
  X, 
  Search, 
  FileCheck2,
  Lock,
  Calendar,
  Eye,
  CreditCard,
  Building
} from 'lucide-react';
import { ConfirmationDialog } from './ConfirmationDialog';

interface InvoiceInboxProps {
  invoices: Invoice[];
  onPay: (id: string, catatan: string) => void;
  onApprove: (id: string, catatan: string) => void;
  onReject: (id: string, catatan: string) => void;
  // User yang login — untuk menentukan apakah tombol approve boleh tampil.
  currentUserId?: number;
  currentUserRole?: string;
}

// Label setiap level (selaras dengan backend InvoiceController::levelLabel).
const LEVEL_LABELS = ['Finance Manager', 'Direksi', 'Komisaris'];

// Role yang berwenang approve pada level saat ini (currentLevel = jumlah approval
// yang sudah masuk). Selaras dengan backend InvoiceController::allowedRolesForLevel.
function roleAllowedForLevel(role: string, currentLevel: number): boolean {
  if (currentLevel === 0) return ['finance', 'hrd', 'admin', 'super_admin'].includes(role);
  if (currentLevel === 1) return ['admin', 'super_admin'].includes(role);
  if (currentLevel === 2) return ['super_admin'].includes(role);
  return false;
}

export const InvoiceInbox: React.FC<InvoiceInboxProps> = ({
  invoices,
  onPay,
  onApprove,
  onReject,
  currentUserId,
  currentUserRole = '',
}) => {
  // Apakah user yang login boleh approve invoice ini pada level saat ini?
  const canApprove = (inv: Invoice): boolean => {
    if (inv.status !== 'Pending') return false;
    const cur = inv.currentApprovalLevel ?? 0;
    const max = inv.maxApprovalLevel ?? 1;
    if (cur >= max) return false; // sudah final
    if (!roleAllowedForLevel(currentUserRole, cur)) return false;
    // Separation of duties: tidak boleh approve invoice yang sudah pernah ia setujui.
    if (currentUserId != null && (inv.approverUserIds ?? []).includes(currentUserId)) {
      return false;
    }
    // Super Admin disimpan untuk approval level tertinggi (final) saja.
    if (currentUserRole === 'super_admin' && cur + 1 < max) return false;
    return true;
  };

  // Penjelasan singkat kenapa tombol approve tidak tersedia (status menunggu).
  const approvalHint = (inv: Invoice): string => {
    const cur = inv.currentApprovalLevel ?? 0;
    const max = inv.maxApprovalLevel ?? 1;
    if (cur >= max) return 'Disetujui penuh';
    if (currentUserId != null && (inv.approverUserIds ?? []).includes(currentUserId)) {
      return 'Anda sudah menyetujui · menunggu approver lain';
    }
    if (currentUserRole === 'super_admin' && cur + 1 < max) {
      return `Menunggu ${LEVEL_LABELS[cur] ?? 'approver'} · Super Admin disimpan untuk approval final`;
    }
    return `Menunggu ${LEVEL_LABELS[cur] ?? 'approver'}`;
  };
  const [filter, setFilter] = useState<'all' | 'due' | 'review'>('all');
  const [searchQuery, setSearchQuery] = useState('');
  const [selectedInvoice, setSelectedInvoice] = useState<Invoice | null>(null);
  const [paymentNote, setPaymentNote] = useState('');
  const [showModal, setShowModal] = useState(false);

  // Reusable confirmation dialog state
  const [confirmState, setConfirmState] = useState<{
    isOpen: boolean;
    title: string;
    message: string | React.ReactNode;
    confirmText?: string;
    type: 'danger' | 'warning' | 'success' | 'info';
    onConfirm: () => void;
  } | null>(null);

  const openConfirm = (opts: {
    title: string;
    message: string | React.ReactNode;
    confirmText?: string;
    type: 'danger' | 'warning' | 'success' | 'info';
    onConfirm: () => void;
  }) => {
    setConfirmState({
      isOpen: true,
      ...opts
    });
  };


  // Filter invoice list
  const filteredInvoices = invoices.filter(inv => {
    const matchesSearch = inv.vendor.toLowerCase().includes(searchQuery.toLowerCase()) ||
                          inv.id.toLowerCase().includes(searchQuery.toLowerCase());
    
    if (!matchesSearch) return false;
    if (filter === 'all') return true;
    if (filter === 'due') return inv.status === 'Due';
    if (filter === 'review') return inv.status === 'Pending';
    return true;
  });

  const handleOpenDetail = (inv: Invoice) => {
    setSelectedInvoice(inv);
    setPaymentNote('');
    setShowModal(true);
  };

  const handleActionClick = (inv: Invoice, action: 'pay' | 'approve' | 'reject') => {
    if (action === 'pay') {
      openConfirm({
        title: 'Pembayaran Invoice Vendor',
        message: (
          <span>
            Apakah Anda yakin ingin langsung memproses pembayaran penuh ke vendor <strong>{inv.vendor}</strong> sebesar <strong>{formatCurrency(inv.total)}</strong> untuk invoice nomor <strong>{inv.id}</strong>?
          </span>
        ),
        confirmText: 'Ya, Bayar Sekarang',
        type: 'danger',
        onConfirm: () => onPay(inv.id, 'Diselesaikan & dibayar via inbox')
      });
    } else if (action === 'approve') {
      openConfirm({
        title: 'Setujui Pembayaran Invoice',
        message: (
          <span>
            Apakah Anda yakin ingin menyetujui pemrosesan invoice dari <strong>{inv.vendor}</strong> sebesar <strong>{formatCurrency(inv.total)}</strong> untuk pembayaran selanjutnya?
          </span>
        ),
        confirmText: 'Ya, Setujui',
        type: 'success',
        onConfirm: () => onApprove(inv.id, 'Disetujui untuk pembayaran')
      });
    } else {
      setSelectedInvoice(inv);
      setPaymentNote('');
      setShowModal(true);
    }
  };

  const submitPay = () => {
    if (selectedInvoice) {
      openConfirm({
        title: selectedInvoice.status === 'Due' ? 'Proses & Bayar Sekarang' : 'Setujui Pembayaran',
        message: (
          <div className="space-y-1.5">
            <p>Apakah Anda yakin ingin memproses invoice dari <strong>{selectedInvoice.vendor}</strong> sebesar <strong>{formatCurrency(selectedInvoice.total)}</strong>?</p>
            <p className="p-2.5 bg-indigo-50 dark:bg-indigo-950/20 border border-indigo-100 dark:border-indigo-900/30 rounded-xl text-indigo-755 dark:text-indigo-400">
              <strong>Catatan Transaksi:</strong> {paymentNote || 'Dibayar penuh via bank transfer'}
            </p>
          </div>
        ),
        confirmText: selectedInvoice.status === 'Due' ? 'Ya, Bayar Sekarang' : 'Ya, Setujui',
        type: 'success',
        onConfirm: () => {
          onPay(selectedInvoice.id, paymentNote || 'Dibayar penuh via bank transfer');
          setShowModal(false);
          setSelectedInvoice(null);
        }
      });
    }
  };

  const submitReject = () => {
    if (selectedInvoice) {
      openConfirm({
        title: 'Tolak Invoice Vendor',
        message: (
          <div className="space-y-1.5">
            <p>Apakah Anda yakin ingin menolak berkas invoice dari <strong>{selectedInvoice.vendor}</strong> nomor <strong>{selectedInvoice.id}</strong>?</p>
            <p className="p-2.5 bg-rose-50 dark:bg-rose-950/20 border border-rose-100 dark:border-rose-900/30 rounded-xl text-rose-750 dark:text-rose-400">
              <strong>Alasan Penolakan:</strong> {paymentNote || 'Ditolak: dokumen tidak valid atau double'}
            </p>
          </div>
        ),
        confirmText: 'Ya, Tolak',
        type: 'danger',
        onConfirm: () => {
          onReject(selectedInvoice.id, paymentNote || 'Ditolak: dokumen tidak valid atau double');
          setShowModal(false);
          setSelectedInvoice(null);
        }
      });
    }
  };

  const formatCurrency = (val: number) => {
    return new Intl.NumberFormat('id-ID', {
      style: 'currency',
      currency: 'IDR',
      maximumFractionDigits: 0
    }).format(val);
  };

  const pendingCount = invoices.filter(i => i.status === 'Pending').length;
  const dueCount = invoices.filter(i => i.status === 'Due').length;
  const totalUnpaid = invoices.reduce((sum, i) => sum + i.total, 0);

  return (
    <div className="space-y-4">
      {/* Mini Stats Card */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-3">
        <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-xl p-4 shadow-sm">
          <p className="text-xs text-slate-400 dark:text-slate-500">Menunggu Approval</p>
          <div className="flex items-baseline gap-2 mt-1">
            <span className="text-2xl font-bold font-mono text-slate-800 dark:text-slate-100">{pendingCount + dueCount}</span>
            <span className="text-[10px] text-slate-400">invoice</span>
          </div>
        </div>
        <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-xl p-4 shadow-sm">
          <p className="text-xs text-slate-400 dark:text-slate-500">Tagihan Belum Dibayar</p>
          <div className="flex items-baseline gap-2 mt-1">
            <span className="text-md sm:text-lg font-bold font-mono text-slate-800 dark:text-slate-100">{formatCurrency(totalUnpaid)}</span>
          </div>
        </div>
        <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-xl p-4 shadow-sm">
          <p className="text-xs text-slate-400 dark:text-slate-500">Jatuh Tempo</p>
          <div className="flex items-baseline gap-2 mt-1">
            <span className={`text-2xl font-bold font-mono ${dueCount > 0 ? 'text-rose-600' : 'text-slate-600'}`}>{dueCount}</span>
            <span className="text-[10px] bg-rose-50 text-rose-700 dark:bg-rose-950/40 dark:text-rose-400 px-1.5 py-0.5 rounded font-medium">Hari ini</span>
          </div>
        </div>
        <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-xl p-4 shadow-sm">
          <p className="text-xs text-slate-400 dark:text-slate-500">Pembayaran Mei</p>
          <div className="flex items-baseline gap-2 mt-1">
            <span className="text-2xl font-bold font-mono text-emerald-600">11</span>
            <span className="text-[10px] text-emerald-600 font-medium">Rp 124,5 jt</span>
          </div>
        </div>
      </div>

      {/* Main Container */}
      <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl p-5 shadow-sm">
        {/* Filters and Search Bar */}
        <div className="flex flex-col sm:flex-row gap-3 justify-between items-center mb-5 pb-4 border-b border-slate-100 dark:border-slate-800/80">
          <div className="flex flex-wrap gap-2 w-full sm:w-auto">
            <button
              onClick={() => setFilter('all')}
              className={`px-3 py-1.5 rounded-full text-xs font-medium transition ${
                filter === 'all'
                  ? 'bg-indigo-600 text-white'
                  : 'bg-slate-50 text-slate-600 hover:bg-slate-100 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700'
              }`}
            >
              Semua ({invoices.length})
            </button>
            <button
              onClick={() => setFilter('due')}
              className={`px-3 py-1.5 rounded-full text-xs font-medium flex items-center gap-1.5 transition ${
                filter === 'due'
                  ? 'bg-rose-600 text-white'
                  : 'bg-rose-50 text-rose-700 hover:bg-rose-100 dark:bg-rose-950/30 dark:text-rose-400 dark:hover:bg-rose-950/50'
              }`}
            >
              <Clock className="w-3.5 h-3.5" />
              Jatuh Tempo ({dueCount})
            </button>
            <button
              onClick={() => setFilter('review')}
              className={`px-3 py-1.5 rounded-full text-xs font-medium transition ${
                filter === 'review'
                  ? 'bg-amber-600 text-white'
                  : 'bg-slate-50 text-slate-600 hover:bg-slate-100 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700'
              }`}
            >
              Pending ({pendingCount})
            </button>
          </div>

          <div className="relative w-full sm:w-64">
            <Search className="absolute left-3 top-2.5 w-4 h-4 text-slate-400" />
            <input
              type="text"
              placeholder="Cari vendor atau No. invoice..."
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              className="w-full pl-9 pr-4 py-2 border border-slate-200 dark:border-slate-700 rounded-xl text-xs bg-slate-50 dark:bg-slate-800/50 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-1 focus:ring-indigo-500 transition"
            />
          </div>
        </div>

        {/* Invoice List Table */}
        <div className="overflow-x-auto">
          <table className="w-full text-left border-collapse min-w-[750px]">
            <thead>
              <tr className="border-b border-slate-100 dark:border-slate-800">
                <th className="py-3 px-4 font-semibold text-slate-500 dark:text-slate-400 text-xs uppercase tracking-wider">No. Invoice</th>
                <th className="py-3 px-4 font-semibold text-slate-500 dark:text-slate-400 text-xs uppercase tracking-wider">Vendor</th>
                <th className="py-3 px-4 font-semibold text-slate-500 dark:text-slate-400 text-xs uppercase tracking-wider font-mono">Total Tagihan</th>
                <th className="py-3 px-4 font-semibold text-slate-500 dark:text-slate-400 text-xs uppercase tracking-wider">Jatuh Tempo</th>
                <th className="py-3 px-4 font-semibold text-slate-500 dark:text-slate-400 text-xs uppercase tracking-wider">Kategori</th>
                <th className="py-3 px-4 font-semibold text-slate-500 dark:text-slate-400 text-xs uppercase tracking-wider">Metode</th>
                <th className="py-3 px-4 font-semibold text-slate-500 dark:text-slate-400 text-xs uppercase tracking-wider">Status</th>
                <th className="py-3 px-4 font-semibold text-slate-500 dark:text-slate-400 text-xs uppercase tracking-wider text-right">Aksi</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100 dark:divide-slate-800/65">
              {filteredInvoices.length === 0 ? (
                <tr>
                  <td colSpan={8} className="py-12 text-center text-slate-400 dark:text-slate-500 text-xs font-medium">
                    <FileText className="w-8 h-8 mx-auto mb-2 opacity-40 text-slate-400" />
                    Tidak ada invoice yang perlu digarap
                  </td>
                </tr>
              ) : (
                filteredInvoices.map((inv) => {
                  const isDue = inv.status === 'Due';
                  return (
                    <tr 
                      key={inv.id} 
                      className={`hover:bg-slate-50/50 dark:hover:bg-slate-800/10 transition-colors ${
                        isDue ? 'bg-rose-50/15 dark:bg-rose-950/5' : ''
                      }`}
                    >
                      <td className="py-3 px-4 text-xs font-semibold font-mono text-indigo-600 dark:text-indigo-400">
                        {inv.id}
                      </td>
                      <td className="py-3 px-4 text-xs font-semibold text-slate-800 dark:text-slate-200">
                        {inv.vendor}
                      </td>
                      <td className="py-3 px-4 text-xs font-semibold font-mono text-slate-800 dark:text-slate-150">
                        {formatCurrency(inv.total)}
                      </td>
                      <td className="py-3 px-4">
                        {isDue ? (
                          <span className="text-xs font-bold text-rose-600 font-mono">Hari ini</span>
                        ) : (
                          <span className="text-xs text-slate-600 dark:text-slate-400 font-sans">{inv.jatuhTempo}</span>
                        )}
                      </td>
                      <td className="py-3 px-4 text-xs text-slate-650 dark:text-slate-400">
                        {inv.kategori}
                      </td>
                      <td className="py-3 px-4">
                        <span className={`text-[10px] font-bold px-2 py-0.5 rounded ${
                          inv.sumber === 'Scan' 
                            ? 'bg-indigo-50 text-indigo-700 dark:bg-indigo-950/40 dark:text-indigo-400' 
                            : 'bg-purple-50 text-purple-700 dark:bg-purple-950/40 dark:text-purple-400'
                        }`}>
                          {inv.sumber}
                        </span>
                      </td>
                      <td className="py-3 px-4">
                        <div className="flex flex-col gap-1">
                          {isDue ? (
                            <span className="inline-flex items-center gap-1 text-[10px] font-semibold px-2 py-0.5 rounded-md bg-rose-150 text-rose-800 dark:bg-rose-900/30 dark:text-rose-400 animate-pulse w-fit">
                              Due
                            </span>
                          ) : (
                            <span className="inline-flex items-center gap-1 text-[10px] font-semibold px-2 py-0.5 rounded-md bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-400 w-fit">
                              Pending
                            </span>
                          )}
                          {/* Progress approval multi-level (mis. 1/3) */}
                          {(inv.maxApprovalLevel ?? 1) > 1 && (
                            <span className="inline-flex items-center gap-1 text-[10px] font-medium text-slate-500 dark:text-slate-400 w-fit">
                              <Lock className="w-3 h-3" />
                              Approval {inv.currentApprovalLevel ?? 0}/{inv.maxApprovalLevel}
                            </span>
                          )}
                        </div>
                      </td>
                      <td className="py-3 px-4">
                        <div className="flex gap-1.5 justify-end">
                          <button
                            onClick={() => handleOpenDetail(inv)}
                            className="p-1 px-2.5 border border-slate-200 dark:border-slate-700 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-md text-slate-500 dark:text-slate-400 text-[11px] font-medium transition flex items-center gap-1"
                          >
                            <Eye className="w-3.5 h-3.5" />
                            Detail
                          </button>
                          {isDue ? (
                            <button
                              onClick={() => handleActionClick(inv, 'pay')}
                              className="p-1 px-2.5 bg-rose-600 hover:bg-rose-700 text-white rounded-md text-[11px] font-semibold tracking-wide transition flex items-center gap-1"
                            >
                              <CreditCard className="w-3.5 h-3.5" />
                              Bayar
                            </button>
                          ) : canApprove(inv) ? (
                            <button
                              onClick={() => handleActionClick(inv, 'approve')}
                              className="p-1 px-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-md text-[11px] font-medium transition flex items-center gap-1"
                            >
                              <Check className="w-3.5 h-3.5" />
                              Setuju
                            </button>
                          ) : (
                            // Tidak berwenang approve di level ini → tampilkan status menunggu.
                            <span className="p-1 px-2.5 text-[11px] font-medium text-slate-400 dark:text-slate-500 flex items-center gap-1 whitespace-nowrap">
                              <Clock className="w-3.5 h-3.5" />
                              {approvalHint(inv)}
                            </span>
                          )}
                        </div>
                      </td>
                    </tr>
                  );
                })
              )}
            </tbody>
          </table>
        </div>
      </div>

      {/* Invoice Detail Modal */}
      {showModal && selectedInvoice && (
        <div className="fixed inset-0 z-50 bg-slate-900/40 dark:bg-slate-950/60 backdrop-blur-xs flex items-center justify-center p-4">
          <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-100 dark:border-slate-800 w-full max-w-lg p-6 shadow-2xl relative max-h-[90vh] overflow-y-auto animate-in fade-in zoom-in duration-200">
            <button
              onClick={() => {
                setShowModal(false);
                setSelectedInvoice(null);
              }}
              className="absolute right-4 top-4 hover:bg-slate-100 dark:hover:bg-slate-800 p-1.5 rounded-full text-slate-400 dark:text-slate-500 transition"
            >
              <X className="w-4 h-4" />
            </button>

            <h3 className="text-sm font-bold text-slate-900 dark:text-slate-100 flex items-center gap-1.5 mb-4 border-b border-slate-100 dark:border-slate-800 pb-3">
              <FileText className="w-4.5 h-4.5 text-indigo-600" />
              Detail Invoice Vendor — {selectedInvoice.id}
            </h3>

            {/* Simulated PDF doc area */}
            <div className="bg-slate-50 dark:bg-slate-950/50 rounded-xl p-4 flex flex-col items-center justify-center border border-dashed border-slate-200 dark:border-slate-800 mb-4">
              <div className="w-10 h-10 rounded-full bg-slate-200 dark:bg-slate-850 flex items-center justify-center text-slate-400 dark:text-slate-500 mb-1">
                <FileText className="w-5 h-5 text-indigo-600" />
              </div>
              <p className="text-xs font-semibold text-slate-700 dark:text-slate-300">
                {selectedInvoice.sumber === 'Scan' ? `invoice_${selectedInvoice.vendor.replace(/\s+/g, '_')}_ocred.pdf` : 'manual_invoice_doc.pdf'}
              </p>
              {selectedInvoice.sha256Hash && (
                <span className="text-[10px] text-slate-400 font-mono mt-0.5">SHA256: {selectedInvoice.sha256Hash.substring(0, 24)}...</span>
              )}
            </div>

            {/* Alert Box for Due Dates */}
            {selectedInvoice.status === 'Due' && (
              <div className="bg-rose-50/50 dark:bg-rose-950/20 border border-rose-100 dark:border-rose-900/50 rounded-xl p-3 mb-4 text-xs text-rose-700 dark:text-rose-400 flex items-start gap-2.5">
                <Clock className="w-4 h-4 text-rose-600 mt-0.5 shrink-0" />
                <div>
                  <span className="font-bold">URGENT: Jatuh Tempo Hari Ini</span>
                  <p className="text-[10px] opacity-90 mt-0.5">Invoice ini harus dibayar hari ini untuk menghindari suspensi akun software korporat.</p>
                </div>
              </div>
            )}

            {/* Detailed Metadata Grid */}
            <div className="grid grid-cols-2 md:grid-cols-3 gap-3.5 mb-5 text-xs text-slate-650 bg-slate-50/50 dark:bg-slate-850/40 p-4 rounded-xl border border-slate-100 dark:border-slate-800">
              <div>
                <span className="text-slate-400 dark:text-slate-500 block text-[10px]">No. Invoice</span>
                <span className="font-semibold text-slate-800 dark:text-slate-200 font-mono">{selectedInvoice.id}</span>
              </div>
              <div>
                <span className="text-slate-400 dark:text-slate-500 block text-[10px]">Vendor</span>
                <span className="font-semibold text-slate-800 dark:text-slate-200">{selectedInvoice.vendor}</span>
              </div>
              {selectedInvoice.npwp && (
                <div>
                  <span className="text-slate-400 dark:text-slate-500 block text-[10px]">NPWP Vendor</span>
                  <span className="font-semibold text-slate-800 dark:text-slate-200 font-mono">{selectedInvoice.npwp}</span>
                </div>
              )}
              <div>
                <span className="text-slate-400 dark:text-slate-500 block text-[10px]">Kategori</span>
                <span className="font-semibold text-slate-800 dark:text-slate-200">{selectedInvoice.kategori}</span>
              </div>
              {selectedInvoice.tanggalInv && (
                <div>
                  <span className="text-slate-400 dark:text-slate-500 block text-[10px]">Tanggal Invoice</span>
                  <span className="font-semibold text-slate-800 dark:text-slate-200">{selectedInvoice.tanggalInv}</span>
                </div>
              )}
              <div>
                <span className="text-slate-400 dark:text-slate-500 block text-[10px]">Jatuh Tempo</span>
                <span className="font-semibold text-rose-600 font-bold">{selectedInvoice.jatuhTempo}</span>
              </div>
            </div>

            {/* Line Items Table if exists */}
            {selectedInvoice.items && selectedInvoice.items.length > 0 && (
              <div className="mb-5">
                <h4 className="text-xs font-bold text-slate-700 dark:text-slate-300 mb-2">Item Tagihan ({selectedInvoice.items.length})</h4>
                <div className="border border-slate-100 dark:border-slate-800 rounded-lg overflow-hidden">
                  <table className="w-full text-left text-xs">
                    <thead>
                      <tr className="bg-slate-50 dark:bg-slate-850 border-b border-slate-100 dark:border-slate-800">
                        <th className="py-2 px-3 text-[10px] text-slate-455 font-semibold">Deskripsi</th>
                        <th className="py-2 px-3 text-[10px] text-slate-455 text-center font-semibold">Qty</th>
                        <th className="py-2 px-3 text-[10px] text-slate-455 text-right font-semibold">Harga Satuan</th>
                        <th className="py-2 px-3 text-[10px] text-slate-455 text-right font-semibold">Subtotal</th>
                      </tr>
                    </thead>
                    <tbody>
                      {selectedInvoice.items.map((it) => (
                        <tr key={it.id} className="border-b border-slate-100 dark:border-slate-800 last:border-b-0">
                          <td className="py-2 px-3 text-slate-700 dark:text-slate-350">{it.deskripsi}</td>
                          <td className="py-2 px-3 text-center">{it.qty}</td>
                          <td className="py-2 px-3 text-right font-mono">{formatCurrency(it.harga)}</td>
                          <td className="py-2 px-3 text-right font-semibold font-mono">{formatCurrency(it.subtotal)}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
                {/* Total breakdowns */}
                <div className="mt-3 pl-20 space-y-1.5 text-xs">
                  <div className="flex justify-between text-slate-505">
                    <span>Subtotal</span>
                    <span className="font-mono">{formatCurrency(selectedInvoice.total - (selectedInvoice.ppn ?? 0))}</span>
                  </div>
                  {selectedInvoice.ppn && (
                    <div className="flex justify-between text-slate-505">
                      <span>PPN 11%</span>
                      <span className="font-mono">{formatCurrency(selectedInvoice.ppn)}</span>
                    </div>
                  )}
                  <div className="flex justify-between font-bold text-slate-900 dark:text-slate-100 pt-2 border-t border-slate-100 dark:border-slate-800">
                    <span>Total Tagihan</span>
                    <span className="font-mono text-indigo-600 dark:text-indigo-400">{formatCurrency(selectedInvoice.total)}</span>
                  </div>
                </div>
              </div>
            )}

            {/* Input Note */}
            <div className="space-y-2 mb-5">
              <label className="text-xs font-semibold text-slate-700 dark:text-slate-300 block">
                Catatan Transaksi / Alasan Penolakan
              </label>
              <textarea
                rows={2}
                placeholder="Tulis instruksi transfer, no. rekening bank, atau alasan penolakan..."
                value={paymentNote}
                onChange={(e) => setPaymentNote(e.target.value)}
                className="w-full text-xs p-3 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50/50 dark:bg-slate-800/20 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-1 focus:ring-indigo-500 transition"
              />
            </div>

            {/* Info progress approval multi-level */}
            {(selectedInvoice.maxApprovalLevel ?? 1) > 1 && (
              <div className="mb-4 flex items-center gap-2 text-xs bg-slate-50 dark:bg-slate-850/40 border border-slate-100 dark:border-slate-800 rounded-xl p-3 text-slate-600 dark:text-slate-300">
                <Lock className="w-4 h-4 text-indigo-500 shrink-0" />
                <span>
                  Approval bertingkat{' '}
                  <strong>{selectedInvoice.currentApprovalLevel ?? 0}/{selectedInvoice.maxApprovalLevel}</strong>
                  {' '}— {approvalHint(selectedInvoice)}
                </span>
              </div>
            )}

            {/* Action Buttons */}
            <div className="flex gap-2.5">
              <button
                onClick={submitReject}
                className="flex-1 py-2 px-4 bg-rose-600 hover:bg-rose-700 text-white font-medium rounded-xl text-xs transition"
              >
                Tolak Invoice
              </button>
              {(selectedInvoice.status === 'Due' || canApprove(selectedInvoice)) ? (
                <button
                  onClick={submitPay}
                  className="flex-1 py-2 px-4 bg-emerald-600 hover:bg-emerald-700 text-white font-medium rounded-xl text-xs transition"
                >
                  {selectedInvoice.status === 'Due' ? 'Proses & Bayar Sekarang' : 'Setujui Pembayaran'}
                </button>
              ) : (
                <span className="flex-1 py-2 px-4 bg-slate-100 dark:bg-slate-800 text-slate-400 dark:text-slate-500 font-medium rounded-xl text-xs text-center flex items-center justify-center gap-1.5">
                  <Clock className="w-3.5 h-3.5" />
                  {approvalHint(selectedInvoice)}
                </span>
              )}
            </div>
          </div>
        </div>
      )}

      {/* Reusable Confirmation Dialog */}
      {confirmState && (
        <ConfirmationDialog
          isOpen={confirmState.isOpen}
          onClose={() => setConfirmState(null)}
          onConfirm={() => {
            confirmState.onConfirm();
            setConfirmState(null);
          }}
          title={confirmState.title}
          message={confirmState.message}
          confirmText={confirmState.confirmText}
          type={confirmState.type}
        />
      )}
    </div>
  );
};
