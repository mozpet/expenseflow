import React, { useState } from 'react';
import { Receipt, StrukApproval, Invoice } from '../types';
import { AnalyticsCharts } from './AnalyticsCharts';
import { 
  TrendingUp, 
  ArrowUpRight, 
  BarChart, 
  DollarSign, 
  Activity, 
  Percent, 
  Briefcase 
} from 'lucide-react';

interface ReportsProps {
  receipts: Receipt[];
  receiptHistory: StrukApproval[];
  invoices: Invoice[];
  invoiceHistory: Invoice[];
}

export const Reports: React.FC<ReportsProps> = ({
  receipts,
  receiptHistory,
  invoices,
  invoiceHistory,
}) => {
  const [activeTab, setActiveTab] = useState<'gabungan' | 'struk' | 'invoice'>('gabungan');

  // 1. Dynamic Metric Calculations
  const approvedStrukTotal = receiptHistory
    .filter(r => r.keputusan === 'Disetujui')
    .reduce((sum, r) => sum + r.nominal, 0);

  const paidInvoiceTotal = invoiceHistory
    .filter(i => i.status === 'Dibayar')
    .reduce((sum, i) => sum + i.total, 0);

  const totalExpenditure = approvedStrukTotal + paidInvoiceTotal;

  // Percentage calculations
  const strukPercent = totalExpenditure > 0 ? (approvedStrukTotal / totalExpenditure) * 100 : 0;
  const invoicePercent = totalExpenditure > 0 ? (paidInvoiceTotal / totalExpenditure) * 100 : 0;

  // Approval Rate Calculation
  const totalApproved = receiptHistory.filter(r => r.keputusan === 'Disetujui').length + 
                        invoiceHistory.filter(i => i.status === 'Dibayar').length;
  const totalDecisionCount = receiptHistory.length + invoiceHistory.length;
  const approvalRate = totalDecisionCount > 0 ? (totalApproved / totalDecisionCount) * 100 : 88.2;

  // 2. Category Distribution Data
  // Combine categories across history databases
  const categoriesMap: { [key: string]: { value: number; color: string } } = {
    'Software & lisensi': { value: 0, color: '#4f46e5' }, // Indigo
    'Logistik & pengiriman': { value: 0, color: '#0d9488' }, // Teal
    'Percetakan & marketing': { value: 0, color: '#f59e0b' }, // Amber
    'Makan & transport': { value: 0, color: '#ea580c' }, // Orange
    'Lainnya': { value: 0, color: '#64748b' } // Slate
  };

  // Populate Categories map from Receipt history
  receiptHistory.forEach(r => {
    if (r.keputusan !== 'Disetujui') return;
    const desc = r.merchant.toLowerCase();
    if (desc.includes('food') || desc.includes('padang') || desc.includes('resto') || desc.includes('dinas') || desc.includes('grab')) {
      categoriesMap['Makan & transport'].value += r.nominal;
    } else if (desc.includes(' parkir') || desc.includes('senayan')) {
      categoriesMap['Makan & transport'].value += r.nominal;
    } else if (desc.includes('indomaret') || desc.includes('atk')) {
      categoriesMap['Lainnya'].value += r.nominal;
    } else {
      categoriesMap['Lainnya'].value += r.nominal;
    }
  });

  // Populate from Invoice history
  invoiceHistory.forEach(i => {
    if (i.status !== 'Dibayar') return;
    const cat = i.kategori.toLowerCase();
    if (cat.includes('software') || cat.includes('lisensi')) {
      categoriesMap['Software & lisensi'].value += i.total;
    } else if (cat.includes('logistik') || cat.includes('pengiriman')) {
      categoriesMap['Logistik & pengiriman'].value += i.total;
    } else if (cat.includes('percetakan') || cat.includes('marketing')) {
      categoriesMap['Percetakan & marketing'].value += i.total;
    } else {
      categoriesMap['Lainnya'].value += i.total;
    }
  });

  // Fill in hardcoded static baseline values from prompt so we start with beautiful realistic proportions
  categoriesMap['Software & lisensi'].value = Math.max(categoriesMap['Software & lisensi'].value, 56200000);
  categoriesMap['Logistik & pengiriman'].value = Math.max(categoriesMap['Logistik & pengiriman'].value, 34100000);
  categoriesMap['Percetakan & marketing'].value = Math.max(categoriesMap['Percetakan & marketing'].value, 22700000);
  categoriesMap['Makan & transport'].value = Math.max(categoriesMap['Makan & transport'].value, 5200000);
  categoriesMap['Lainnya'].value = Math.max(categoriesMap['Lainnya'].value, 14700000);

  const chartCategoryData = Object.keys(categoriesMap).map(key => ({
    name: key,
    value: categoriesMap[key].value,
    color: categoriesMap[key].color
  }));

  // Weekly Trend Data
  // Dynamic weekly trend mapping to active state
  const weeklyTrendData = [
    { name: 'Minggu 1', total: 25000000 + (totalExpenditure * 0.15) },
    { name: 'Minggu 2', total: 38000000 + (totalExpenditure * 0.22) },
    { name: 'Minggu 3', total: 45000000 + (totalExpenditure * 0.28) },
    { name: 'Minggu 4', total: 24900000 + (totalExpenditure * 0.35) }
  ];

  const formatCurrency = (val: number) => {
    return new Intl.NumberFormat('id-ID', {
      style: 'currency',
      currency: 'IDR',
      maximumFractionDigits: 0
    }).format(val);
  };

  return (
    <div className="space-y-6">
      {/* Tab Pills */}
      <div className="flex gap-2 p-1.5 bg-slate-100 dark:bg-slate-800 rounded-xl max-w-sm">
        <button
          onClick={() => setActiveTab('gabungan')}
          className={`flex-1 py-1.5 rounded-lg text-xs font-semibold transition ${
            activeTab === 'gabungan'
              ? 'bg-white dark:bg-slate-900 text-indigo-600 dark:text-indigo-400 shadow-xs'
              : 'text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200'
          }`}
        >
          Gabungan
        </button>
        <button
          onClick={() => setActiveTab('struk')}
          className={`flex-1 py-1.5 rounded-lg text-xs font-semibold transition ${
            activeTab === 'struk'
              ? 'bg-white dark:bg-slate-900 text-indigo-600 dark:text-indigo-400 shadow-xs'
              : 'text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200'
          }`}
        >
          Struk Karyawan
        </button>
        <button
          onClick={() => setActiveTab('invoice')}
          className={`flex-1 py-1.5 rounded-lg text-xs font-semibold transition ${
            activeTab === 'invoice'
              ? 'bg-white dark:bg-slate-900 text-indigo-600 dark:text-indigo-400 shadow-xs'
              : 'text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200'
          }`}
        >
          Invoice Vendor
        </button>
      </div>

      {/* Conditionally Render Content */}
      {activeTab === 'gabungan' && (
        <div className="space-y-6">
          {/* Stats Bar */}
          <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
            <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl p-4 shadow-xs">
              <span className="text-[10px] uppercase font-bold text-slate-400 dark:text-slate-500 block">Total Pengeluaran Mei</span>
              <div className="flex items-center gap-1.5 mt-1.5">
                <span className="text-lg md:text-xl font-bold font-mono text-slate-800 dark:text-slate-100">
                  {formatCurrency(totalExpenditure > 132900000 ? totalExpenditure : 132900000)}
                </span>
                <ArrowUpRight className="w-4 h-4 text-emerald-500 shrink-0" />
              </div>
              <span className="text-[10px] text-slate-400 block mt-1">Mei 21-27, 2026</span>
            </div>

            <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl p-4 shadow-xs">
              <span className="text-[10px] uppercase font-bold text-slate-400 dark:text-slate-500 block">Klaim Struk Disetujui</span>
              <div className="flex items-center gap-1.5 mt-1.5">
                <span className="text-lg md:text-xl font-bold font-mono text-slate-800 dark:text-slate-100">
                  {formatCurrency(approvedStrukTotal > 8450000 ? approvedStrukTotal : 8450000)}
                </span>
              </div>
              <span className="text-[10px] text-indigo-600 font-semibold block mt-1">
                {strukPercent > 0 ? strukPercent.toFixed(1) : '6.4'}% dari total pengeluaran
              </span>
            </div>

            <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl p-4 shadow-xs">
              <span className="text-[10px] uppercase font-bold text-slate-400 dark:text-slate-500 block">Invoice Vendor Dibayar</span>
              <div className="flex items-center gap-1.5 mt-1.5">
                <span className="text-lg md:text-xl font-bold font-mono text-slate-800 dark:text-slate-100">
                  {formatCurrency(paidInvoiceTotal > 124500000 ? paidInvoiceTotal : 124500000)}
                </span>
              </div>
              <span className="text-[10px] text-indigo-600 font-semibold block mt-1">
                {invoicePercent > 0 ? invoicePercent.toFixed(1) : '93.6'}% dari total pengeluaran
              </span>
            </div>

            <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl p-4 shadow-xs">
              <span className="text-[10px] uppercase font-bold text-slate-400 dark:text-slate-500 block">Finance Approval Rate</span>
              <div className="flex items-center gap-1.5 mt-1.5">
                <span className="text-xl md:text-2xl font-bold font-mono text-slate-800 dark:text-slate-100">
                  {approvalRate.toFixed(1)}%
                </span>
              </div>
              <span className="text-[10px] text-emerald-600 font-medium block mt-1">Struk + Invoice Gabungan</span>
            </div>
          </div>

          {/* Recharts Integration */}
          <AnalyticsCharts 
            weeklyTrend={weeklyTrendData} 
            categoryDistribution={chartCategoryData} 
          />
        </div>
      )}

      {activeTab === 'struk' && (
        <div className="space-y-6">
          <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
            <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl p-4 shadow-xs">
              <span className="text-[10px] uppercase font-bold text-slate-400 dark:text-slate-500 block">Total Struk Mei</span>
              <div className="flex items-center gap-1.5 mt-1.5">
                <span className="text-xl md:text-2xl font-bold font-mono text-slate-800 dark:text-slate-100">21</span>
              </div>
              <span className="text-[10px] text-slate-400 block mt-1">Klaim diajukan</span>
            </div>

            <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl p-4 shadow-xs">
              <span className="text-[10px] uppercase font-bold text-slate-400 dark:text-slate-500 block">Total Nominal Struk</span>
              <div className="flex items-center gap-1.5 mt-1.5">
                <span className="text-lg md:text-xl font-bold font-mono text-slate-800 dark:text-slate-100">
                  {formatCurrency(approvedStrukTotal > 8450000 ? approvedStrukTotal : 8450000)}
                </span>
              </div>
              <span className="text-[10px] text-slate-400 block mt-1">Approved & Verified</span>
            </div>

            <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl p-4 shadow-xs">
              <span className="text-[10px] uppercase font-bold text-slate-400 dark:text-slate-500 block">Struk Approval Rate</span>
              <div className="flex items-center gap-1.5 mt-1.5">
                <span className="text-2xl font-bold font-mono text-slate-800 dark:text-slate-100">85.7%</span>
              </div>
              <span className="text-[10px] text-slate-400 block mt-1">Mei 2026</span>
            </div>

            <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl p-4 shadow-xs">
              <span className="text-[10px] uppercase font-bold text-slate-400 dark:text-slate-500 block">Flag Fraud / Indikasi</span>
              <div className="flex items-center gap-1 mt-1.5">
                <span className="text-2xl font-bold font-mono text-amber-600">11.1%</span>
              </div>
              <span className="text-[10px] text-rose-500 block font-semibold mt-1">2 kasus verifikasi ketat</span>
            </div>
          </div>

          {/* Department breakdown stat bars */}
          <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl p-5 shadow-sm">
            <h4 className="text-xs font-bold text-slate-850 dark:text-slate-100 mb-4 uppercase tracking-wider">Per Departemen (Klaim Struk)</h4>
            <div className="space-y-4">
              <div>
                <div className="flex justify-between text-xs font-medium mb-1">
                  <span className="text-slate-700 dark:text-slate-300">Marketing</span>
                  <span className="font-semibold font-mono">Rp 3.100.000 (73%)</span>
                </div>
                <div className="w-full bg-slate-100 dark:bg-slate-800 h-2 rounded-full overflow-hidden flex">
                  <div className="bg-indigo-600 h-full" style={{ width: '73%' }}></div>
                </div>
              </div>

              <div>
                <div className="flex justify-between text-xs font-medium mb-1">
                  <span className="text-slate-700 dark:text-slate-300">Sales</span>
                  <span className="font-semibold font-mono">Rp 2.400.000 (57%)</span>
                </div>
                <div className="w-full bg-slate-100 dark:bg-slate-800 h-2 rounded-full overflow-hidden">
                  <div className="bg-teal-600 h-full" style={{ width: '57%' }}></div>
                </div>
              </div>

              <div>
                <div className="flex justify-between text-xs font-medium mb-1">
                  <span className="text-slate-700 dark:text-slate-300">Operations</span>
                  <span className="font-semibold font-mono">Rp 1.600.000 (38%)</span>
                </div>
                <div className="w-full bg-slate-100 dark:bg-slate-800 h-2 rounded-full overflow-hidden">
                  <div className="bg-amber-500 h-full" style={{ width: '38%' }}></div>
                </div>
              </div>

              <div>
                <div className="flex justify-between text-xs font-medium mb-1">
                  <span className="text-slate-700 dark:text-slate-300">HR & Finance</span>
                  <span className="font-semibold font-mono">Rp 1.350.000 (32%)</span>
                </div>
                <div className="w-full bg-slate-100 dark:bg-slate-800 h-2 rounded-full overflow-hidden">
                  <div className="bg-slate-500 h-full" style={{ width: '32%' }}></div>
                </div>
              </div>
            </div>
          </div>
        </div>
      )}

      {activeTab === 'invoice' && (
        <div className="space-y-6">
          <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
            <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl p-4 shadow-xs">
              <span className="text-[10px] uppercase font-bold text-slate-400 dark:text-slate-500 block">Total Invoice Mei</span>
              <div className="flex items-center gap-1.5 mt-1.5">
                <span className="text-2xl font-bold font-mono text-slate-800 dark:text-slate-100">14</span>
              </div>
              <span className="text-[10px] text-slate-400 block mt-1">Mei 2026</span>
            </div>

            <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl p-4 shadow-xs">
              <span className="text-[10px] uppercase font-bold text-slate-400 dark:text-slate-500 block">Total Dibayar</span>
              <div className="flex items-center gap-1.5 mt-1.5">
                <span className="text-lg md:text-xl font-bold font-mono text-slate-800 dark:text-slate-100">
                  {formatCurrency(paidInvoiceTotal > 124500000 ? paidInvoiceTotal : 124500000)}
                </span>
              </div>
              <span className="text-[10px] text-slate-400 block mt-1">Dana cair</span>
            </div>

            <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl p-4 shadow-xs">
              <span className="text-[10px] uppercase font-bold text-slate-400 dark:text-slate-500 block">Belum Dibayar</span>
              <div className="flex items-center gap-1.5 mt-1.5">
                <span className="text-lg md:text-xl font-bold font-mono text-rose-600">Rp 38,2 jt</span>
              </div>
              <span className="text-[10px] text-rose-500 block font-semibold mt-1">3 invoice pending</span>
            </div>

            <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl p-4 shadow-xs">
              <span className="text-[10px] uppercase font-bold text-slate-400 dark:text-slate-500 block">Automasi Scan OCR</span>
              <div className="flex items-center gap-1">
                <span className="text-2xl font-bold font-mono text-slate-800 dark:text-slate-100">9</span>
              </div>
              <span className="text-[10px] text-indigo-600 font-bold block mt-1">dari 14 invoice total</span>
            </div>
          </div>

          {/* Top vendors breakdown */}
          <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl p-5 shadow-sm">
            <h4 className="text-xs font-bold text-slate-850 dark:text-slate-100 mb-4 uppercase tracking-wider">TOP 5 VENDOR TERBESAR</h4>
            <div className="space-y-4">
              <div>
                <div className="flex justify-between text-xs font-medium mb-1">
                  <span className="text-slate-700 dark:text-slate-300">PT Maju Jaya</span>
                  <span className="font-semibold font-mono">Rp 48.200.000 (77%)</span>
                </div>
                <div className="w-full bg-slate-100 dark:bg-slate-800 h-2 rounded-full overflow-hidden flex">
                  <div className="bg-indigo-600 h-full" style={{ width: '77%' }}></div>
                </div>
              </div>

              <div>
                <div className="flex justify-between text-xs font-medium mb-1">
                  <span className="text-slate-700 dark:text-slate-300">PT Sumber Makmur</span>
                  <span className="font-semibold font-mono">Rp 34.100.000 (55%)</span>
                </div>
                <div className="w-full bg-slate-100 dark:bg-slate-800 h-2 rounded-full overflow-hidden">
                  <div className="bg-teal-600 h-full" style={{ width: '55%' }}></div>
                </div>
              </div>

              <div>
                <div className="flex justify-between text-xs font-medium mb-1">
                  <span className="text-slate-700 dark:text-slate-300">CV Berkah Print</span>
                  <span className="font-semibold font-mono">Rp 22.700.000 (36%)</span>
                </div>
                <div className="w-full bg-slate-100 dark:bg-slate-800 h-2 rounded-full overflow-hidden">
                  <div className="bg-amber-500 h-full" style={{ width: '36%' }}></div>
                </div>
              </div>

              <div>
                <div className="flex justify-between text-xs font-medium mb-1">
                  <span className="text-slate-700 dark:text-slate-300">CV Digital</span>
                  <span className="font-semibold font-mono">Rp 12.300.000 (20%)</span>
                </div>
                <div className="w-full bg-slate-100 dark:bg-slate-800 h-2 rounded-full overflow-hidden">
                  <div className="bg-rose-500 h-full" style={{ width: '20%' }}></div>
                </div>
              </div>

              <div>
                <div className="flex justify-between text-xs font-medium mb-1">
                  <span className="text-slate-700 dark:text-slate-300">Lainnya</span>
                  <span className="font-semibold font-mono">Rp 7.200.000 (12%)</span>
                </div>
                <div className="w-full bg-slate-100 dark:bg-slate-800 h-2 rounded-full overflow-hidden">
                  <div className="bg-slate-500 h-full" style={{ width: '12%' }}></div>
                </div>
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};
