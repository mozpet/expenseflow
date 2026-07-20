import React, { useState, useEffect, useCallback, Suspense, lazy } from 'react';
import { defaultSettings } from './data';
import {
  Receipt,
  StrukApproval,
  Invoice,
  AuditLog,
  NotificationItem,
  AppSettings
} from './types';
import { useAuth } from './auth/AuthContext';
import { LoginPage } from './components/LoginPage';
import {
  receiptApi,
  invoiceApi,
  notificationApi,
  activityLogApi,
  settingsApi,
  overtimeApi,
  deviceChangeApi,
} from './services/endpoints';
import {
  mapReceipt,
  mapReceiptToApproval,
  mapInvoice,
  mapNotification,
  mapAuditLog,
  mapSettings,
} from './services/mappers';

// Lazy-loaded route components for code splitting
const ReceiptInbox = lazy(() => import('./components/ReceiptInbox').then(m => ({ default: m.ReceiptInbox })));
const ReceiptHistory = lazy(() => import('./components/ReceiptHistory').then(m => ({ default: m.ReceiptHistory })));
const InvoiceInbox = lazy(() => import('./components/InvoiceInbox').then(m => ({ default: m.InvoiceInbox })));
const InvoiceInput = lazy(() => import('./components/InvoiceInput').then(m => ({ default: m.InvoiceInput })));
const InvoiceScan = lazy(() => import('./components/InvoiceScan').then(m => ({ default: m.InvoiceScan })));
const InvoiceHistoryView = lazy(() => import('./components/InvoiceHistory').then(m => ({ default: m.InvoiceHistory })));
const Reports = lazy(() => import('./components/Reports').then(m => ({ default: m.Reports })));
const AuditLogView = lazy(() => import('./components/AuditLogView').then(m => ({ default: m.AuditLogView })));
const NotificationsView = lazy(() => import('./components/NotificationsView').then(m => ({ default: m.NotificationsView })));
const KaryawanManagement = lazy(() => import('./components/KaryawanManagement').then(m => ({ default: m.KaryawanManagement })));
const MasterVendor = lazy(() => import('./components/MasterVendor').then(m => ({ default: m.MasterVendor })));
const AttendanceManagement = lazy(() => import('./components/AttendanceManagement').then(m => ({ default: m.AttendanceManagement })));
const ShiftManagement = lazy(() => import('./components/ShiftManagement').then(m => ({ default: m.ShiftManagement })));
const OvertimeApprovalView = lazy(() => import('./components/OvertimeApprovalView').then(m => ({ default: m.OvertimeApprovalView })));
const DeviceChangeApprovalView = lazy(() => import('./components/DeviceChangeApprovalView').then(m => ({ default: m.DeviceChangeApprovalView })));
const SettingsManagement = lazy(() => import('./components/SettingsManagement').then(m => ({ default: m.SettingsManagement })));

import { 
  Inbox, 
  CheckCheck, 
  FileText, 
  FilePlus, 
  Scan, 
  History, 
  BarChart3, 
  ShieldCheck, 
  Bell, 
  Settings, 
  Menu, 
  X, 
  FileSpreadsheet,
  Download,
  Receipt as ReceiptIcon,
  Users,
  Building,
  LogOut,
  AlertCircle,
  CalendarCheck,
  CalendarClock,
  Smartphone
} from 'lucide-react';

export default function App() {
  const { user, isAuthenticated, loading: authLoading, logout } = useAuth();

  // HRD tidak punya akses struk karyawan (khusus finance) → halaman struk disembunyikan.
  const isHrd = user?.role === 'hrd';
  const RECEIPT_PAGES = ['inbox', 'riwayat-struk'];

  // Finance tidak punya akses menu Manajemen (Karyawan & Presensi/Cuti = ranah HRD).
  const isFinance = user?.role === 'finance';
  const MANAGEMENT_PAGES = ['karyawan', 'presensi', 'shift', 'overtime', 'device-changes'];

  // Pengaturan Aturan hanya untuk admin & super_admin.
  const isAdminOrSuperAdmin = user?.role === 'admin' || user?.role === 'super_admin';
  const SETTINGS_PAGES = ['setting'];

  // Global React States
  // HRD mendarat langsung ke Inbox Invoice (bukan Inbox Struk).
  const [activePage, setActivePage] = useState<string>(
    user?.role === 'hrd' ? 'invoice-inbox' : 'inbox',
  );
  const [isSidebarOpen, setIsSidebarOpen] = useState<boolean>(false);

  const [receipts, setReceipts] = useState<Receipt[]>([]);
  const [receiptHistory, setReceiptHistory] = useState<StrukApproval[]>([]);
  const [invoices, setInvoices] = useState<Invoice[]>([]);
  const [invoiceHistory, setInvoiceHistory] = useState<Invoice[]>([]);
  const [auditLogs, setAuditLogs] = useState<AuditLog[]>([]);
  const [notifications, setNotifications] = useState<NotificationItem[]>([]);
  const [settings, setSettings] = useState<AppSettings>(defaultSettings);

  const [dataLoading, setDataLoading] = useState<boolean>(false);
  const [dataError, setDataError] = useState<string | null>(null);
  const [pendingOvertimeCount, setPendingOvertimeCount] = useState<number>(0);
  const [pendingDeviceCount, setPendingDeviceCount] = useState<number>(0);

  // Helper baca pagination Laravel ({ data: [...] }) atau array biasa.
  const rows = (res: any): any[] => {
    if (Array.isArray(res)) return res;
    if (Array.isArray(res?.data)) return res.data;
    return [];
  };

  // ─── Loaders per resource (dipakai ulang setelah aksi) ─────
  const loadReceipts = useCallback(async () => {
    const res = await receiptApi.inbox();
    setReceipts(rows(res).map(mapReceipt));
  }, []);

  const loadReceiptHistory = useCallback(async () => {
    const res = await receiptApi.all();
    const list = rows(res.receipts ?? res);
    setReceiptHistory(
      list.filter((r: any) => r.status === 'approved' || r.status === 'rejected').map(mapReceiptToApproval),
    );
  }, []);

  const loadInvoices = useCallback(async () => {
    const res = await invoiceApi.list();
    const list = rows(res.invoices ?? res).map(mapInvoice);
    setInvoices(list.filter((i) => i.status === 'Pending' || i.status === 'Due'));
    setInvoiceHistory(list.filter((i) => i.status === 'Dibayar' || i.status === 'Ditolak'));
  }, []);

  const loadNotifications = useCallback(async () => {
    const res = await notificationApi.list();
    setNotifications(rows(res.notifications ?? res).map(mapNotification));
  }, []);

  const loadAuditLogs = useCallback(async () => {
    const res = await activityLogApi.list();
    setAuditLogs(rows(res).map(mapAuditLog));
  }, []);

  const loadSettings = useCallback(async () => {
    const res = await settingsApi.get();
    setSettings(mapSettings(res.settings));
  }, []);

  // Muat semua data awal saat user terautentikasi.
  const loadPendingOvertime = useCallback(async () => {
    if (user?.role === 'finance') return; // finance tidak punya akses
    try {
      const res = await overtimeApi.list({ status: 'pending', page: 1 });
      const total = res?.total ?? res?.meta?.total ?? (res?.data?.length ?? 0);
      setPendingOvertimeCount(total);
    } catch { /* diam */ }
  }, [user?.role]);

  const loadPendingDevice = useCallback(async () => {
    if (user?.role === 'finance') return; // finance tidak punya akses
    try {
      const res = await deviceChangeApi.list({ status: 'pending', page: 1 });
      const total = res?.total ?? res?.meta?.total ?? (res?.data?.length ?? 0);
      setPendingDeviceCount(total);
    } catch { /* diam */ }
  }, [user?.role]);

  const loadAll = useCallback(async () => {
    setDataLoading(true);
    setDataError(null);
    try {
      // HRD tidak punya akses struk → jangan panggil endpoint receipt (akan 403).
      const tasks = [loadInvoices(), loadNotifications(), loadAuditLogs(), loadSettings(), loadPendingOvertime(), loadPendingDevice()];
      if (user?.role !== 'hrd') {
        tasks.push(loadReceipts(), loadReceiptHistory());
      }
      await Promise.all(tasks);
    } catch (e: any) {
      setDataError(e?.message ?? 'Gagal memuat data dari server.');
    } finally {
      setDataLoading(false);
    }
  }, [loadReceipts, loadReceiptHistory, loadInvoices, loadNotifications, loadAuditLogs, loadSettings, loadPendingOvertime, loadPendingDevice, user?.role]);

  useEffect(() => {
    if (isAuthenticated) loadAll();
  }, [isAuthenticated, loadAll]);

  // Jaga-jaga: alihkan user dari halaman yang tidak boleh ia akses.
  useEffect(() => {
    // HRD tidak boleh di halaman struk → alihkan ke invoice.
    if (isHrd && RECEIPT_PAGES.includes(activePage)) {
      setActivePage('invoice-inbox');
    }
    // Finance tidak boleh di halaman manajemen → alihkan ke inbox struk.
    if (isFinance && MANAGEMENT_PAGES.includes(activePage)) {
      setActivePage('inbox');
    }
    // HRD & finance tidak boleh di Pengaturan Aturan → alihkan ke halaman default.
    if (!isAdminOrSuperAdmin && SETTINGS_PAGES.includes(activePage)) {
      setActivePage(user?.role === 'hrd' ? 'invoice-inbox' : 'inbox');
    }
  }, [isHrd, isFinance, isAdminOrSuperAdmin, activePage]);

  const formatCurrency = (val: number) => {
    return new Intl.NumberFormat('id-ID', {
      style: 'currency',
      currency: 'IDR',
      maximumFractionDigits: 0
    }).format(val);
  };

  // 1. Receipt approve/reject → panggil API lalu refresh.
  const handleApproveReceipt = async (id: string, note: string) => {
    await receiptApi.approve(id, note);
    await Promise.all([loadReceipts(), loadReceiptHistory(), loadAuditLogs(), loadNotifications()]);
  };

  const handleRejectReceipt = async (id: string, note: string) => {
    await receiptApi.reject(id, note || 'Ditolak oleh Finance');
    await Promise.all([loadReceipts(), loadReceiptHistory(), loadAuditLogs(), loadNotifications()]);
  };

  // Cari backendId numerik dari daftar invoice berdasarkan id tampilan.
  const invoiceBackendId = (displayId: string): number | undefined =>
    (invoices.find(i => i.id === displayId) as any)?.backendId;

  // 2. Invoice approve/pay/reject → API + refresh.
  //    Tampilkan pesan error backend (mis. 403 "Anda sudah menyetujui") via alert
  //    agar tidak silent. Selalu refresh daftar agar progress level ikut terbarui.
  const handlePayInvoice = async (id: string, note: string) => {
    const bid = invoiceBackendId(id);
    if (bid == null) return;
    try {
      await invoiceApi.approve(bid, note || 'Pembayaran diselesaikan');
    } catch (e: any) {
      alert(e?.message ?? 'Gagal memproses invoice.');
    }
    await Promise.all([loadInvoices(), loadAuditLogs(), loadNotifications()]);
  };

  const handleApproveInvoice = async (id: string, note: string) => {
    const bid = invoiceBackendId(id);
    if (bid == null) return;
    try {
      await invoiceApi.approve(bid, note);
    } catch (e: any) {
      alert(e?.message ?? 'Gagal menyetujui invoice.');
    }
    await Promise.all([loadInvoices(), loadAuditLogs(), loadNotifications()]);
  };

  const handleRejectInvoice = async (id: string, note: string) => {
    const bid = invoiceBackendId(id);
    if (bid == null) return;
    try {
      await invoiceApi.reject(bid, note || 'Ditolak oleh review finance');
    } catch (e: any) {
      alert(e?.message ?? 'Gagal menolak invoice.');
    }
    await Promise.all([loadInvoices(), loadAuditLogs(), loadNotifications()]);
  };

  // 3. Invoice baru (Input manual / Scan) sudah disimpan via API di komponen;
  //    di sini cukup refresh daftar lalu kembali ke inbox.
  const handleAddNewInvoice = async (_inv: Invoice) => {
    await Promise.all([loadInvoices(), loadAuditLogs(), loadNotifications()]);
    setActivePage('invoice-inbox');
  };

  // 4. Notifications interaction
  const handleMarkAllRead = async () => {
    await notificationApi.markAllRead();
    await loadNotifications();
  };

  const handleDismissNotification = async (id: string) => {
    await notificationApi.destroy(id);
    await loadNotifications();
  };

  // 5. App Settings interaction
  const handleSaveSettings = async (newSettings: AppSettings) => {
    const res = await settingsApi.update({
      variance_limit: newSettings.varianceLimit,
      max_claim_limit: newSettings.maxClaimLimit,
      threshold_single: newSettings.thresholdSingle,
      threshold_two: newSettings.thresholdTwo,
      threshold_three: newSettings.thresholdThree,
    });
    setSettings(mapSettings(res.settings));
    await loadAuditLogs();
  };

  // Dipakai komponen Karyawan & Vendor untuk refresh audit/notif setelah aksi.
  const handleAddAuditLogDirect = (_title: string, _details: string, _bgBg: string) => {
    loadAuditLogs();
  };

  const handleAddNotificationDirect = (
    _type: 'due' | 'flag' | 'new' | 'success',
    _title: string,
    _subtitle: string,
  ) => {
    loadNotifications();
  };

  // Side navigation helper
  const navigateTo = (pageName: string) => {
    setActivePage(pageName);
    setIsSidebarOpen(false); // Close mobile sidebar automatically
  };

  const pageTitles: { [key: string]: string } = {
    'inbox': 'Inbox Struk Karyawan',
    'riwayat-struk': 'Riwayat Approval Struk',
    'invoice-inbox': 'Inbox Invoice Vendor',
    'input-invoice': 'Input Invoice Manual',
    'scan-invoice': 'Scan Invoice OCR',
    'riwayat-invoice': 'Riwayat Approval Invoice',
    'laporan': 'Laporan Gabungan Arus Dana',
    'auditlog': 'Audit Log Transaksi',
    'notif': 'Notifikasi Sistem',
    'setting': 'Pengaturan Aturan',
    'karyawan': 'Manajemen Karyawan',
    'master-vendor': 'Master Data Vendor',
    'presensi': 'Manajemen Presensi & Cuti',
    'shift': 'Manajemen Shift & Jadwal',
    'overtime': 'Approval Lembur Karyawan',
    'device-changes': 'Approval Pindah Perangkat'
  };

  // Render proper view based on activePage
  const renderContentView = () => {
    switch (activePage) {
      case 'inbox':
        return (
          <ReceiptInbox 
            receipts={receipts} 
            onApprove={handleApproveReceipt} 
            onReject={handleRejectReceipt}
            currentSettings={settings}
            onSaveSettings={handleSaveSettings}
          />
        );
      case 'riwayat-struk':
        return <ReceiptHistory approvals={receiptHistory} />;
      case 'invoice-inbox':
        return (
          <InvoiceInbox
            invoices={invoices}
            onPay={handlePayInvoice}
            onApprove={handleApproveInvoice}
            onReject={handleRejectInvoice}
            currentUserId={user?.id}
            currentUserRole={user?.role}
          />
        );
      case 'input-invoice':
        return <InvoiceInput onAddInvoice={handleAddNewInvoice} />;
      case 'scan-invoice':
        return <InvoiceScan onAddInvoice={handleAddNewInvoice} />;
      case 'riwayat-invoice':
        return <InvoiceHistoryView historyInvoices={invoiceHistory} />;
      case 'laporan':
        return (
          <Reports 
            receipts={receipts} 
            receiptHistory={receiptHistory} 
            invoices={invoices} 
            invoiceHistory={invoiceHistory} 
          />
        );
      case 'auditlog':
        return <AuditLogView logs={auditLogs} />;
      case 'notif':
        return (
          <NotificationsView 
            notifications={notifications} 
            onMarkAllRead={handleMarkAllRead} 
            onDismiss={handleDismissNotification} 
          />
        );
      case 'setting':
        return (
          <SettingsManagement
            onAddAuditLog={handleAddAuditLogDirect}
            currentSettings={settings}
            onSaveSettings={handleSaveSettings}
          />
        );
      case 'karyawan':
        return (
          <KaryawanManagement 
            onAddAuditLog={handleAddAuditLogDirect} 
            onAddNotification={handleAddNotificationDirect} 
          />
        );
      case 'master-vendor':
        return (
          <MasterVendor
            onAddAuditLog={handleAddAuditLogDirect}
            onAddNotification={handleAddNotificationDirect}
          />
        );
      case 'presensi':
        return (
          <AttendanceManagement
            onAddAuditLog={handleAddAuditLogDirect}
            onAddNotification={handleAddNotificationDirect}
          />
        );
      case 'shift':
        return <ShiftManagement onAddAuditLog={handleAddAuditLogDirect} />;
      case 'overtime':
        return <OvertimeApprovalView />;
      case 'device-changes':
        return <DeviceChangeApprovalView />;
      default:
        return (
          <ReceiptInbox
            receipts={receipts}
            onApprove={handleApproveReceipt}
            onReject={handleRejectReceipt}
            currentSettings={settings}
            onSaveSettings={handleSaveSettings}
          />
        );
    }
  };

  const unreadNotifCount = notifications.filter(n => !n.read).length;
  const pendingReceiptCount = receipts.length;
  const pendingInvoiceCount = invoices.length;

  // Inisial & nama untuk avatar header (dari user login).
  const userName = user?.name ?? 'Pengguna';
  const userRole = user?.role ?? '';
  const userInitials = userName
    .split(/\s+/)
    .slice(0, 2)
    .map((s) => s[0])
    .join('')
    .toUpperCase();
  const roleLabel: Record<string, string> = {
    finance: 'Finance Manager',
    hrd: 'HRD',
    admin: 'Admin',
    super_admin: 'Super Admin',
  };

  // ─── Gerbang autentikasi ───────────────────────────────────
  // Selama verifikasi token awal, tampilkan loader.
  if (authLoading) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-[#0f172a]">
        <div className="flex flex-col items-center gap-3">
          <div className="w-8 h-8 border-3 border-indigo-300/40 border-t-indigo-400 rounded-full animate-spin" />
          <span className="text-xs font-medium text-slate-400">Memuat sesi...</span>
        </div>
      </div>
    );
  }

  // Belum login → tampilkan halaman login.
  if (!isAuthenticated) {
    return <LoginPage />;
  }

  return (
    <div className="h-screen overflow-hidden bg-slate-50/50 text-slate-805 dark:bg-slate-950 dark:text-slate-100 flex flex-col font-sans antialiased">
      
      {/* Mobile Top Header navbar */}
      <header className="lg:hidden h-14 bg-[#0f172a] text-slate-300 border-b border-slate-800 px-4 flex items-center justify-between sticky top-0 z-40 select-none shadow-sm">
        <div className="flex items-center gap-2">
          <button 
            onClick={() => setIsSidebarOpen(true)}
            className="p-1.5 hover:bg-slate-805 hover:bg-slate-800 rounded-lg text-slate-300 transition"
          >
            <Menu className="w-5 h-5" />
          </button>
          
          <div className="flex items-center gap-2">
            <div className="w-7 h-7 bg-indigo-500 rounded flex items-center justify-center shrink-0">
              <div className="w-3.5 h-3.5 border border-white rounded-xs"></div>
            </div>
            <span className="font-bold text-white text-sm tracking-tight">ExpenseFlow</span>
          </div>
        </div>

        <div className="flex items-center gap-2.5">
          {/* Mobile notification shortcut */}
          <button 
            onClick={() => navigateTo('notif')}
            className="relative p-1.5 hover:bg-slate-800 rounded-lg text-slate-300 transition"
          >
            <Bell className="w-4.5 h-4.5" />
            {unreadNotifCount > 0 && (
              <span className="absolute top-1 right-1 w-2 h-2 rounded-full bg-rose-500 animate-ping" />
            )}
          </button>
          
          {/* Mobile Profile logo mockup */}
          <span className="w-7 h-7 rounded-full bg-indigo-500/20 text-indigo-300 font-semibold text-[10px] flex items-center justify-center font-mono">
            SR
          </span>
        </div>
      </header>

      <div className="flex flex-1 relative overflow-hidden">
        
        {/* SIDEBAR NAVIGATION PANEL (Responsive overlay on mobile, fixed left panel on desk) */}
        <aside className={`
          fixed inset-y-0 left-0 z-50 lg:z-30 w-64 bg-[#0f172a] text-slate-300 flex flex-col select-none transform transition-transform duration-300 ease-in-out
          lg:translate-x-0 lg:static lg:h-auto
          ${isSidebarOpen ? 'translate-x-0' : '-translate-x-full'}
        `}>
          {/* Sidebar Top branding header */}
          <div className="h-14 lg:h-16 px-5 border-b border-slate-800/60 flex items-center justify-between shrink-0">
            <div className="flex items-center gap-3">
              <div className="w-8 h-8 bg-indigo-500 rounded-lg flex items-center justify-center shrink-0 shadow-md shadow-indigo-500/20">
                <div className="w-4 h-4 border-2 border-white rounded-sm"></div>
              </div>
              <div>
                <h1 className="font-bold text-white text-md font-sans tracking-tight leading-none">ExpenseFlow</h1>
                <span className="text-[10px] text-slate-400 block font-medium mt-1">Finance Portal</span>
              </div>
            </div>

            {/* Mobile close sidebar drawer button */}
            <button 
              onClick={() => setIsSidebarOpen(false)}
              className="lg:hidden p-1.5 hover:bg-slate-800 rounded-full text-slate-400 transition"
            >
              <X className="w-4 h-4" />
            </button>
          </div>

          {/* Sidebar Groups Links scrollbar list */}
          <div className="flex-1 overflow-y-auto px-4 py-5 space-y-6">
            
            {/* Group 1: Employee Receipts — disembunyikan untuk HRD (struk khusus finance) */}
            {!isHrd && (
            <div className="space-y-1.5">
              <span className="px-3 text-[10px] uppercase tracking-wider text-slate-500 font-bold block mb-2 font-mono">
                Struk Karyawan
              </span>
              
              <button
                onClick={() => navigateTo('inbox')}
                className={`w-full text-left py-2 px-3 rounded-lg text-xs font-semibold flex items-center justify-between transition-colors duration-150 ${
                  activePage === 'inbox' 
                    ? 'bg-indigo-600/15 text-white border-l-2 border-indigo-500 pl-2.5' 
                    : 'text-slate-400 hover:bg-slate-800 hover:text-white'
                }`}
              >
                <div className="flex items-center gap-2.5">
                  <Inbox className="w-4 h-4 opacity-80" />
                  <span>Inbox Struk</span>
                </div>
                {pendingReceiptCount > 0 && (
                  <span className="text-[10px] px-1.5 py-0.5 rounded-full bg-indigo-500 text-white font-bold font-mono">
                    {pendingReceiptCount}
                  </span>
                )}
              </button>

              <button
                onClick={() => navigateTo('riwayat-struk')}
                className={`w-full text-left py-2 px-3 rounded-lg text-xs font-semibold flex items-center gap-2.5 transition-colors duration-150 ${
                  activePage === 'riwayat-struk' 
                    ? 'bg-indigo-600/15 text-white border-l-2 border-indigo-500 pl-2.5' 
                    : 'text-slate-400 hover:bg-slate-800 hover:text-white'
                }`}
              >
                <CheckCheck className="w-4 h-4 opacity-80" />
                <span>Riwayat Approval</span>
              </button>
            </div>
            )}

            {/* Group 2: Vendor Invoices */}
            <div className="space-y-1.5">
              <span className="px-3 text-[10px] uppercase tracking-wider text-slate-500 font-bold block mb-2 font-mono">
                Invoice Vendor
              </span>
              
              <button
                onClick={() => navigateTo('invoice-inbox')}
                className={`w-full text-left py-2 px-3 rounded-lg text-xs font-semibold flex items-center justify-between transition-colors duration-150 ${
                  activePage === 'invoice-inbox' 
                    ? 'bg-indigo-600/15 text-white border-l-2 border-indigo-500 pl-2.5' 
                    : 'text-slate-400 hover:bg-slate-800 hover:text-white'
                }`}
              >
                <div className="flex items-center gap-2.5">
                  <FileText className="w-4 h-4 opacity-80" />
                  <span>Inbox Invoice</span>
                </div>
                {pendingInvoiceCount > 0 && (
                  <span className="text-[10px] px-1.5 py-0.5 rounded-full bg-amber-500 text-white font-bold font-mono">
                    {pendingInvoiceCount}
                  </span>
                )}
              </button>

              <button
                onClick={() => navigateTo('input-invoice')}
                className={`w-full text-left py-2 px-3 rounded-lg text-xs font-semibold flex items-center gap-2.5 transition-colors duration-150 ${
                  activePage === 'input-invoice' 
                    ? 'bg-indigo-600/15 text-white border-l-2 border-indigo-500 pl-2.5' 
                    : 'text-slate-400 hover:bg-slate-800 hover:text-white'
                }`}
              >
                <FilePlus className="w-4 h-4 opacity-80" />
                <span>Input Invoice</span>
              </button>

              <button
                onClick={() => navigateTo('scan-invoice')}
                className={`w-full text-left py-2 px-3 rounded-lg text-xs font-semibold flex items-center gap-2.5 transition-colors duration-150 ${
                  activePage === 'scan-invoice' 
                    ? 'bg-indigo-600/15 text-white border-l-2 border-indigo-500 pl-2.5' 
                    : 'text-slate-400 hover:bg-slate-800 hover:text-white'
                }`}
              >
                <Scan className="w-4 h-4 opacity-80" />
                <span>Scan Invoice OCR</span>
              </button>

              <button
                onClick={() => navigateTo('riwayat-invoice')}
                className={`w-full text-left py-2 px-3 rounded-lg text-xs font-semibold flex items-center gap-2.5 transition-colors duration-150 ${
                  activePage === 'riwayat-invoice' 
                    ? 'bg-indigo-600/15 text-white border-l-2 border-indigo-500 pl-2.5' 
                    : 'text-slate-400 hover:bg-slate-800 hover:text-white'
                }`}
              >
                <History className="w-4 h-4 opacity-80" />
                <span>Riwayat Invoice</span>
              </button>

              <button
                onClick={() => navigateTo('master-vendor')}
                className={`w-full text-left py-2 px-3 rounded-lg text-xs font-semibold flex items-center gap-2.5 transition-colors duration-150 ${
                  activePage === 'master-vendor' 
                    ? 'bg-indigo-600/15 text-white border-l-2 border-indigo-500 pl-2.5' 
                    : 'text-slate-400 hover:bg-slate-800 hover:text-white'
                }`}
              >
                <Building className="w-4 h-4 opacity-80" />
                <span>Master Vendor</span>
              </button>
            </div>

            {/* Group 2.5: Manajemen — disembunyikan untuk finance (ranah HRD/admin) */}
            {!isFinance && (
            <div className="space-y-1.5">
              <span className="px-3 text-[10px] uppercase tracking-wider text-slate-500 font-bold block mb-2 font-mono">
                Manajemen
              </span>
              
              <button
                onClick={() => navigateTo('karyawan')}
                className={`w-full text-left py-2 px-3 rounded-lg text-xs font-semibold flex items-center gap-2.5 transition-colors duration-150 ${
                  activePage === 'karyawan'
                    ? 'bg-indigo-600/15 text-white border-l-2 border-indigo-500 pl-2.5'
                    : 'text-slate-400 hover:bg-slate-800 hover:text-white'
                }`}
              >
                <Users className="w-4 h-4 opacity-80" />
                <span>Karyawan</span>
              </button>

              <button
                onClick={() => navigateTo('presensi')}
                className={`w-full text-left py-2 px-3 rounded-lg text-xs font-semibold flex items-center gap-2.5 transition-colors duration-150 ${
                  activePage === 'presensi'
                    ? 'bg-indigo-600/15 text-white border-l-2 border-indigo-500 pl-2.5'
                    : 'text-slate-400 hover:bg-slate-800 hover:text-white'
                }`}
              >
                <CalendarCheck className="w-4 h-4 opacity-80" />
                <span>Presensi & Cuti</span>
              </button>

              <button
                onClick={() => navigateTo('shift')}
                className={`w-full text-left py-2 px-3 rounded-lg text-xs font-semibold flex items-center gap-2.5 transition-colors duration-150 ${
                  activePage === 'shift'
                    ? 'bg-indigo-600/15 text-white border-l-2 border-indigo-500 pl-2.5'
                    : 'text-slate-400 hover:bg-slate-800 hover:text-white'
                }`}
              >
                <CalendarClock className="w-4 h-4 opacity-80" />
                <span>Shift & Jadwal</span>
              </button>

              <button
                onClick={() => navigateTo('overtime')}
                className={`w-full text-left py-2 px-3 rounded-lg text-xs font-semibold flex items-center justify-between transition-colors duration-150 ${
                  activePage === 'overtime'
                    ? 'bg-indigo-600/15 text-white border-l-2 border-indigo-500 pl-2.5'
                    : 'text-slate-400 hover:bg-slate-800 hover:text-white'
                }`}
              >
                <div className="flex items-center gap-2.5">
                  <FileSpreadsheet className="w-4 h-4 opacity-80" />
                  <span>Approval Lembur</span>
                </div>
                {pendingOvertimeCount > 0 && (
                  <span className="text-[10px] px-1.5 py-0.5 rounded-full bg-orange-500 text-white font-bold font-mono">
                    {pendingOvertimeCount}
                  </span>
                )}
              </button>

              <button
                onClick={() => navigateTo('device-changes')}
                className={`w-full text-left py-2 px-3 rounded-lg text-xs font-semibold flex items-center justify-between transition-colors duration-150 ${
                  activePage === 'device-changes'
                    ? 'bg-indigo-600/15 text-white border-l-2 border-indigo-500 pl-2.5'
                    : 'text-slate-400 hover:bg-slate-800 hover:text-white'
                }`}
              >
                <div className="flex items-center gap-2.5">
                  <Smartphone className="w-4 h-4 opacity-80" />
                  <span>Pindah Perangkat</span>
                </div>
                {pendingDeviceCount > 0 && (
                  <span className="text-[10px] px-1.5 py-0.5 rounded-full bg-orange-500 text-white font-bold font-mono">
                    {pendingDeviceCount}
                  </span>
                )}
              </button>
            </div>
            )}

            {/* Group 3: Reporting & Systems */}
            <div className="space-y-1.5">
              <span className="px-3 text-[10px] uppercase tracking-wider text-slate-500 font-bold block mb-2 font-mono">
                Laporan & Sistem
              </span>

              <button
                onClick={() => navigateTo('laporan')}
                className={`w-full text-left py-2 px-3 rounded-lg text-xs font-semibold flex items-center gap-2.5 transition-colors duration-150 ${
                  activePage === 'laporan' 
                    ? 'bg-indigo-600/15 text-white border-l-2 border-indigo-500 pl-2.5' 
                    : 'text-slate-400 hover:bg-slate-800 hover:text-white'
                }`}
              >
                <BarChart3 className="w-4 h-4 opacity-80" />
                <span>Laporan Gabungan</span>
              </button>

              <button
                onClick={() => navigateTo('auditlog')}
                className={`w-full text-left py-2 px-3 rounded-lg text-xs font-semibold flex items-center gap-2.5 transition-colors duration-150 ${
                  activePage === 'auditlog' 
                    ? 'bg-indigo-600/15 text-white border-l-2 border-indigo-500 pl-2.5' 
                    : 'text-slate-400 hover:bg-slate-800 hover:text-white'
                }`}
              >
                <ShieldCheck className="w-4 h-4 opacity-80" />
                <span>Audit Log</span>
              </button>

              <button
                onClick={() => navigateTo('notif')}
                className={`w-full text-left py-2 px-3 rounded-lg text-xs font-semibold flex items-center justify-between transition-colors duration-150 ${
                  activePage === 'notif' 
                    ? 'bg-indigo-600/15 text-white border-l-2 border-indigo-500 pl-2.5' 
                    : 'text-slate-400 hover:bg-slate-800 hover:text-white'
                }`}
              >
                <div className="flex items-center gap-2.5">
                  <Bell className="w-4 h-4 opacity-80" />
                  <span>Notifikasi</span>
                </div>
                {unreadNotifCount > 0 && (
                  <span className="text-[10px] px-1.5 py-0.5 rounded-full bg-rose-500 text-white font-bold font-mono animate-pulse">
                    {unreadNotifCount}
                  </span>
                )}
              </button>
            </div>

            {/* Separator block */}
            <div className="border-t border-slate-800/80 pt-4 space-y-1">
              {/* Pengaturan Aturan — hanya admin & super_admin */}
              {isAdminOrSuperAdmin && (
              <button
                onClick={() => navigateTo('setting')}
                className={`w-full text-left py-2 px-3 rounded-lg text-xs font-semibold flex items-center gap-2.5 transition-colors duration-150 ${
                  activePage === 'setting'
                    ? 'bg-indigo-600/15 text-white border-l-2 border-indigo-500 pl-2.5'
                    : 'text-slate-400 hover:bg-slate-800 hover:text-white'
                }`}
              >
                <Settings className="w-4 h-4 opacity-80" />
                <span>Pengaturan Aturan</span>
              </button>
              )}
            </div>

          </div>

          {/* Fixed bottom footer with avatar */}
          <div className="p-4 border-t border-slate-800 shrink-0 space-y-2">
            <div className="flex items-center gap-3 bg-slate-900/60 p-2.5 rounded-xl border border-slate-850">
              <span className="w-8 h-8 rounded-full bg-indigo-550 bg-indigo-600/20 border border-indigo-500/30 text-indigo-300 font-bold text-xs flex items-center justify-center shrink-0 font-mono">
                {userInitials}
              </span>
              <div className="min-w-0">
                <p className="text-[11px] font-bold text-white truncate">{userName}</p>
                <p className="text-[10px] text-slate-400 truncate">{roleLabel[userRole] ?? userRole}</p>
              </div>
            </div>
            <button
              onClick={() => logout()}
              className="w-full flex items-center justify-center gap-2 py-2 rounded-lg text-[11px] font-semibold text-slate-300 hover:bg-slate-800 hover:text-white border border-slate-800 transition"
            >
              <LogOut className="w-3.5 h-3.5" />
              <span>Keluar</span>
            </button>
          </div>
        </aside>

        {/* Mobile sidebar overlay backdrop */}
        {isSidebarOpen && (
          <div 
            onClick={() => setIsSidebarOpen(false)}
            className="fixed inset-0 bg-slate-900/40 backdrop-blur-xs z-40 lg:hidden"
          />
        )}

        {/* MAIN BODY LAYOUT WRAPPER */}
        <main className="flex-1 flex flex-col min-w-0">
          
          {/* Main Top Header Navbar (Desktop Only) */}
          <header className="hidden lg:flex h-16 bg-white dark:bg-slate-900 border-b border-slate-200 dark:border-slate-800 px-8 items-center justify-between sticky top-0 z-20 select-none">
            <div className="flex items-center gap-3">
              <h2 className="font-bold text-slate-800 dark:text-white text-sm tracking-tight font-sans uppercase">System Portal</h2>
              <span className="text-slate-300">/</span>
              <span className="text-xs font-semibold text-slate-500 font-sans">
                {pageTitles[activePage] || 'Portal'}
              </span>
            </div>

            <div className="flex items-center gap-4">
              {/* Shortcut buttons */}
              <button 
                onClick={() => alert('Exporting global ledger reports...')}
                className="flex items-center gap-1.5 px-3 py-1.5 border border-slate-200 dark:border-slate-750 hover:bg-slate-50 dark:hover:bg-slate-800 rounded-lg text-xs font-semibold text-slate-600 dark:text-slate-350 transition"
              >
                <Download className="w-3.5 h-3.5 text-indigo-550 text-indigo-500" />
                <span>Export Gabungan</span>
              </button>

              <div className="flex items-center gap-2.5">
                <span className="w-8 h-8 rounded-full bg-indigo-50 text-indigo-600 font-bold text-xs flex items-center justify-center font-mono shrink-0 border border-indigo-100">{userInitials}</span>
                <div className="text-left leading-none">
                  <span className="text-[11px] font-bold text-slate-800 dark:text-white block">{userName}</span>
                  <span className="text-[9px] text-slate-400 block mt-1">{roleLabel[userRole] ?? userRole}</span>
                </div>
              </div>
            </div>
          </header>

          {/* SCROLLABLE INTERACTIVE VIEW CONTENT CONTAINER */}
          <div className="flex-1 p-6 md:p-8 overflow-y-auto space-y-6">
            
            {/* Real responsive Header Title block on Desktop/Mobile */}
            <div className="flex items-center justify-between pb-3 border-b border-slate-200 dark:border-slate-800">
              <div>
                <h2 className="text-lg md:text-xl font-bold text-slate-900 dark:text-white tracking-tight leading-none font-sans flex items-center gap-2">
                  {activePage === 'inbox' && <ReceiptIcon className="w-5 h-5 text-indigo-500 shrink-0" />}
                  {pageTitles[activePage] || 'ExpenseFlow Portal'}
                </h2>
                <p className="text-xs text-slate-500 dark:text-slate-400 mt-1.5 font-sans">
                  Sistem verifikasi otomatis multi-channel berbasis OCR &amp; fraud alert flag
                </p>
              </div>
            </div>

            {/* Banner error koneksi/data */}
            {dataError && (
              <div className="flex items-start gap-2 bg-rose-50 border border-rose-200 text-rose-700 rounded-lg px-4 py-3 text-xs">
                <AlertCircle className="w-4 h-4 shrink-0 mt-0.5" />
                <div className="flex-1">
                  <p className="font-semibold">Gagal memuat data</p>
                  <p className="mt-0.5">{dataError}</p>
                </div>
                <button
                  onClick={() => loadAll()}
                  className="text-rose-700 font-semibold underline hover:no-underline shrink-0"
                >
                  Coba lagi
                </button>
              </div>
            )}

            {/* Indikator memuat data awal */}
            {dataLoading && !dataError && (
              <div className="text-[11px] text-slate-400 flex items-center gap-2">
                <span className="w-3 h-3 border-2 border-slate-300 border-t-indigo-500 rounded-full animate-spin" />
                Menyinkronkan data dari server...
              </div>
            )}

            {/* Render selected controller view on-the-fly */}
            <div className="animate-in fade-in slide-in-from-bottom-2 duration-355 duration-300">
              <Suspense fallback={
                <div className="flex items-center justify-center py-20">
                  <div className="flex flex-col items-center gap-3">
                    <div className="w-8 h-8 border-3 border-indigo-200 border-t-indigo-600 rounded-full animate-spin" />
                    <span className="text-xs font-medium text-slate-400">Memuat halaman...</span>
                  </div>
                </div>
              }>
                {renderContentView()}
              </Suspense>
            </div>

          </div>

        </main>

      </div>
    </div>
  );
}
