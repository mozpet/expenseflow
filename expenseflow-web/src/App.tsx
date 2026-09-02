import React, { useState, useEffect, useCallback, Suspense, lazy } from 'react';
import { defaultSettings } from './data';
import {
  Receipt,
  StrukApproval,
  Invoice,
  NotificationItem,
  AppSettings
} from './types';
import { useAuth } from './auth/AuthContext';
import { LoginPage } from './components/LoginPage';
import {
  receiptApi,
  invoiceApi,
  notificationApi,
  settingsApi,
  overtimeApi,
  deviceChangeApi,
} from './services/endpoints';
import {
  mapReceipt,
  mapReceiptToApproval,
  mapInvoice,
  mapNotification,
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
const RecruitmentManagement = lazy(() => import('./components/RecruitmentManagement').then(m => ({ default: m.RecruitmentManagement })));

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
  Smartphone,
  Briefcase
} from 'lucide-react';

export default function App() {
  const { user, isAuthenticated, loading: authLoading, logout } = useAuth();

  // HRD tidak punya akses seluruh fitur finance (struk, invoice, vendor, setting) → disembunyikan.
  const isHrd = user?.role === 'hrd';
  const FINANCE_PAGES = ['inbox', 'riwayat-struk', 'invoice-inbox', 'input-invoice', 'scan-invoice', 'riwayat-invoice', 'master-vendor'];

  // Finance tidak punya akses menu Manajemen (Karyawan & Presensi/Cuti = ranah HRD).
  const isFinance = user?.role === 'finance';
  const MANAGEMENT_PAGES = ['karyawan', 'presensi', 'shift', 'overtime', 'device-changes', 'rekrutmen'];

  // Pengaturan Aturan hanya untuk admin & super_admin.
  const isAdminOrSuperAdmin = user?.role === 'admin' || user?.role === 'super_admin';
  const SETTINGS_PAGES = ['setting'];

  // Global React States
  // HRD mendarat langsung ke Manajemen Karyawan (bukan fitur Finance).
  const [activePage, setActivePage] = useState<string>(
    user?.role === 'hrd' ? 'karyawan' : 'inbox',
  );
  const [isSidebarOpen, setIsSidebarOpen] = useState<boolean>(false);
  const [isSidebarCollapsed, setIsSidebarCollapsed] = useState<boolean>(false);

  const [receipts, setReceipts] = useState<Receipt[]>([]);
  const [receiptHistory, setReceiptHistory] = useState<StrukApproval[]>([]);
  const [invoices, setInvoices] = useState<Invoice[]>([]);
  const [invoiceHistory, setInvoiceHistory] = useState<Invoice[]>([]);
  // auditLogs dipindahkan ke AuditLogView (on-demand, sesuai rules state management)
  const [notifications, setNotifications] = useState<NotificationItem[]>([]);
  const [settings, setSettings] = useState<AppSettings>(defaultSettings);

  const [dataLoading, setDataLoading] = useState<boolean>(false);
  const [dataError, setDataError] = useState<string | null>(null);
  const [pendingOvertimeCount, setPendingOvertimeCount] = useState<number>(0);
  const [pendingDeviceCount, setPendingDeviceCount] = useState<number>(0);
  const [refreshing, setRefreshing] = useState<boolean>(false);

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
      list.filter((r: any) => r.status === 'approved' || r.status === 'paid' || r.status === 'rejected').map(mapReceiptToApproval),
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

  // loadAuditLogs dipindahkan ke AuditLogView — data audit hanya dibutuhkan saat halaman Audit Log dibuka

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
      // HRD tidak punya akses finance → jangan panggil endpoint receipt, invoice, atau settings (akan 403).
      const tasks = [loadNotifications(), loadPendingOvertime(), loadPendingDevice()];
      if (user?.role !== 'hrd') {
        tasks.push(loadInvoices(), loadReceipts(), loadReceiptHistory(), loadSettings());
      }
      await Promise.all(tasks);
    } catch (e: any) {
      setDataError(e?.message ?? 'Gagal memuat data dari server.');
    } finally {
      setDataLoading(false);
    }
  }, [loadReceipts, loadReceiptHistory, loadInvoices, loadNotifications, loadSettings, loadPendingOvertime, loadPendingDevice, user?.role]);

  useEffect(() => {
    if (isAuthenticated) loadAll();
  }, [isAuthenticated, loadAll]);

  // Jaga-jaga: alihkan user dari halaman yang tidak boleh ia akses.
  useEffect(() => {
    // HRD tidak boleh di halaman finance → alihkan ke karyawan.
    if (isHrd && FINANCE_PAGES.includes(activePage)) {
      setActivePage('karyawan');
    }
    // Finance tidak boleh di halaman manajemen → alihkan ke inbox struk.
    if (isFinance && MANAGEMENT_PAGES.includes(activePage)) {
      setActivePage('inbox');
    }
    // HRD & finance tidak boleh di Pengaturan Aturan → alihkan ke halaman default.
    if (!isAdminOrSuperAdmin && SETTINGS_PAGES.includes(activePage)) {
      setActivePage(user?.role === 'hrd' ? 'karyawan' : 'inbox');
    }
  }, [isHrd, isFinance, isAdminOrSuperAdmin, activePage]);

  // Otomatis tandai notifikasi modul sebagai 'dibaca' ketika user membuka halamannya
  useEffect(() => {
    if (!activePage || activePage === 'notif') return;
    const unreadForCurrentPage = notifications.filter(n => !n.read && n.targetPage === activePage);
    if (unreadForCurrentPage.length > 0) {
      unreadForCurrentPage.forEach(n => {
        notificationApi.markRead(n.id).catch(() => {});
      });
      setNotifications(prev =>
        prev.map(n => n.targetPage === activePage ? { ...n, read: true } : n)
      );
    }
  }, [activePage, notifications]);

  const formatCurrency = (val: number) => {
    return new Intl.NumberFormat('id-ID', {
      style: 'currency',
      currency: 'IDR',
      maximumFractionDigits: 0
    }).format(val);
  };

  // 1. Receipt approve/reject/bulk-approve/pay/bulk-pay → panggil API lalu refresh.
  const handleApproveReceipt = async (id: string, note: string, approvedAmount?: number) => {
    await receiptApi.approve(id, note, approvedAmount);
    await Promise.all([loadReceipts(), loadReceiptHistory(), loadNotifications()]);
  };

  const handleBulkApproveReceipts = async (ids: string[], note?: string) => {
    await receiptApi.bulkApprove(ids.map(Number), note);
    await Promise.all([loadReceipts(), loadReceiptHistory(), loadNotifications()]);
  };

  const handleRejectReceipt = async (id: string, note: string) => {
    await receiptApi.reject(id, note || 'Ditolak oleh Finance');
    await Promise.all([loadReceipts(), loadReceiptHistory(), loadNotifications()]);
  };

  const handlePayReceipt = async (id: string, payload: { payment_method: string; payment_ref_no?: string }) => {
    await receiptApi.pay(id, payload);
    await Promise.all([loadReceipts(), loadReceiptHistory(), loadNotifications()]);
  };

  const handleBulkPayReceipts = async (ids: string[], payload: { payment_method: string; payment_ref_no?: string }) => {
    await receiptApi.bulkPay(ids.map(Number), payload);
    await Promise.all([loadReceipts(), loadReceiptHistory(), loadNotifications()]);
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
    await Promise.all([loadInvoices(), loadNotifications()]);
  };

  const handleApproveInvoice = async (id: string, note: string) => {
    const bid = invoiceBackendId(id);
    if (bid == null) return;
    try {
      await invoiceApi.approve(bid, note);
    } catch (e: any) {
      alert(e?.message ?? 'Gagal menyetujui invoice.');
    }
    await Promise.all([loadInvoices(), loadNotifications()]);
  };

  const handleRejectInvoice = async (id: string, note: string) => {
    const bid = invoiceBackendId(id);
    if (bid == null) return;
    try {
      await invoiceApi.reject(bid, note || 'Ditolak oleh review finance');
    } catch (e: any) {
      alert(e?.message ?? 'Gagal menolak invoice.');
    }
    await Promise.all([loadInvoices(), loadNotifications()]);
  };

  // 3. Invoice baru (Input manual / Scan) sudah disimpan via API di komponen;
  //    di sini cukup refresh daftar lalu kembali ke inbox.
  const handleAddNewInvoice = async (_inv: Invoice) => {
    await Promise.all([loadInvoices(), loadNotifications()]);
    setActivePage('invoice-inbox');
  };

  // 4. Notifications interaction
  const handleMarkAllRead = async () => {
    await notificationApi.markAllRead();
    await loadNotifications();
  };

  const handleMarkRead = async (id: string) => {
    try {
      await notificationApi.markRead(id);
    } catch (e) {
      console.error('Failed to mark notification as read:', e);
    }
    setNotifications((prev) =>
      prev.map((n) => (n.id === id ? { ...n, read: true } : n))
    );
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
    // audit log tidak perlu di-refresh dari sini — AuditLogView mengurus sendiri
  };

  // Refresh ulang data struk (inbox) — dipakai tombol Refresh di halaman Inbox Struk.
  const refreshReceipts = async () => {
    setRefreshing(true);
    try {
      await Promise.all([loadReceipts(), loadReceiptHistory(), loadNotifications()]);
    } catch (e: any) {
      setDataError(e?.message ?? 'Gagal memuat data dari server.');
    } finally {
      setRefreshing(false);
    }
  };

  // Refresh ulang data riwayat struk — dipakai tombol Refresh di halaman Riwayat Struk.
  const refreshReceiptHistory = async () => {
    setRefreshing(true);
    try {
      await Promise.all([loadReceiptHistory(), loadNotifications()]);
    } catch (e: any) {
      setDataError(e?.message ?? 'Gagal memuat data dari server.');
    } finally {
      setRefreshing(false);
    }
  };

  // Dipakai komponen Karyawan & Vendor untuk refresh audit/notif setelah aksi.
  // handleAddAuditLogDirect: audit log kini self-contained di AuditLogView.
  // Fungsi ini tetap ada agar prop signature komponen anak tidak perlu diubah.
  const handleAddAuditLogDirect = (_title: string, _details: string, _bgBg: string) => {
    // no-op: AuditLogView akan auto-refresh saat dibuka kembali
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
    'inbox': 'Struk Reimbursement & Klaim',
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
    'device-changes': 'Approval Pindah Perangkat',
    'rekrutmen': 'Rekrutmen & Seleksi',
  };

  // Render proper view based on activePage
  const renderContentView = () => {
    switch (activePage) {
      case 'inbox':
        return (
          <ReceiptInbox
            receipts={receipts}
            receiptHistory={receiptHistory}
            onApprove={handleApproveReceipt}
            onBulkApprove={handleBulkApproveReceipts}
            onReject={handleRejectReceipt}
            onPay={handlePayReceipt}
            onBulkPay={handleBulkPayReceipts}
            currentSettings={settings}
            onSaveSettings={handleSaveSettings}
            onRefresh={() => {
              refreshReceipts();
              refreshReceiptHistory();
            }}
            refreshing={refreshing}
            initialTab="inbox"
          />
        );
      case 'riwayat-struk':
        return (
          <ReceiptInbox
            receipts={receipts}
            receiptHistory={receiptHistory}
            onApprove={handleApproveReceipt}
            onBulkApprove={handleBulkApproveReceipts}
            onReject={handleRejectReceipt}
            onPay={handlePayReceipt}
            onBulkPay={handleBulkPayReceipts}
            currentSettings={settings}
            onSaveSettings={handleSaveSettings}
            onRefresh={() => {
              refreshReceipts();
              refreshReceiptHistory();
            }}
            refreshing={refreshing}
            initialTab="history"
          />
        );
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
        return <AuditLogView />;
      case 'notif':
        return (
          <NotificationsView 
            notifications={notifications} 
            onMarkAllRead={handleMarkAllRead} 
            onDismiss={handleDismissNotification} 
            onNavigate={navigateTo}
            onMarkRead={handleMarkRead}
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
      case 'rekrutmen':
        return <RecruitmentManagement />;
      default:
        return (
          <ReceiptInbox
            receipts={receipts}
            onApprove={handleApproveReceipt}
            onBulkApprove={handleBulkApproveReceipts}
            onReject={handleRejectReceipt}
            currentSettings={settings}
            onSaveSettings={handleSaveSettings}
            onRefresh={refreshReceipts}
            refreshing={refreshing}
          />
        );
    }
  };

  const unreadNotifCount = notifications.filter(n => !n.read).length;
  const unreadLeavesCount = notifications.filter(n => !n.read && n.targetPage === 'presensi').length;
  const unreadOvertimeCount = notifications.filter(n => !n.read && n.targetPage === 'overtime').length;
  const unreadReceiptCount = notifications.filter(n => !n.read && n.targetPage === 'inbox').length;
  const unreadInvoiceCount = notifications.filter(n => !n.read && n.targetPage === 'invoice-inbox').length;
  const unreadDeviceCount = notifications.filter(n => !n.read && n.targetPage === 'device-changes').length;
  const unreadRecruitmentCount = notifications.filter(n => !n.read && n.targetPage === 'rekrutmen').length;

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
        
        {/* SIDEBAR NAVIGATION PANEL (Responsive overlay on mobile, collapsible left panel on desk) */}
        <aside className={`
          fixed inset-y-0 left-0 z-50 lg:z-30 bg-[#0f172a] text-slate-300 flex flex-col select-none transform transition-all duration-300 ease-in-out
          lg:translate-x-0 lg:static lg:h-auto
          ${isSidebarOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'}
          ${isSidebarCollapsed ? 'lg:w-[72px] w-64' : 'lg:w-64 w-64'}
        `}>
          {/* Sidebar Top branding header */}
          <div className={`h-14 lg:h-16 px-4 border-b border-slate-800/60 flex items-center shrink-0 ${isSidebarCollapsed ? 'lg:justify-center justify-between' : 'justify-between'}`}>
            <div className="flex items-center gap-2.5 min-w-0">
              {/* Burger Button in Sidebar Header (Always beside ExpenseFlow logo in all screens/full screen) */}
              <button 
                onClick={() => {
                  if (window.innerWidth < 1024) {
                    setIsSidebarOpen(false);
                  } else {
                    setIsSidebarCollapsed(!isSidebarCollapsed);
                  }
                }}
                className="p-1.5 hover:bg-slate-800 rounded-lg text-slate-400 hover:text-white transition cursor-pointer shrink-0"
                title={isSidebarCollapsed ? 'Perluas Sidebar' : 'Ciutkan Sidebar'}
              >
                <Menu className="w-5 h-5" />
              </button>

              {(!isSidebarCollapsed || window.innerWidth < 1024) && (
                <div className="flex items-center gap-2.5 min-w-0">
                  <div className="w-7.5 h-7.5 bg-indigo-500 rounded-lg flex items-center justify-center shrink-0 shadow-md shadow-indigo-500/20">
                    <div className="w-3.5 h-3.5 border-2 border-white rounded-xs"></div>
                  </div>
                  <div className="min-w-0">
                    <h1 className="font-bold text-white text-sm font-sans tracking-tight leading-none truncate">ExpenseFlow</h1>
                    <span className="text-[9px] text-slate-400 block font-medium mt-0.5 truncate">Finance Portal</span>
                  </div>
                </div>
              )}
            </div>

            {/* Mobile close sidebar drawer button */}
            <button 
              onClick={() => setIsSidebarOpen(false)}
              className="lg:hidden p-1.5 hover:bg-slate-800 rounded-full text-slate-400 transition cursor-pointer"
            >
              <X className="w-4 h-4" />
            </button>
          </div>

          {/* Sidebar Groups Links scrollbar list */}
          <div className={`flex-1 overflow-y-auto ${isSidebarCollapsed ? 'lg:px-2 px-4' : 'px-4'} py-5 space-y-6`}>
            
            {/* Group: Finance (Struk Reimbursement & Invoice Vendor) — disembunyikan untuk HRD */}
            {!isHrd && (
            <div className="space-y-1.5">
              {!isSidebarCollapsed ? (
                <span className="px-3 text-[10px] uppercase tracking-wider text-slate-500 font-bold block mb-2 font-mono">
                  Finance
                </span>
              ) : (
                <div className="hidden lg:block my-2 border-t border-slate-800/60" />
              )}
              
              <button
                onClick={() => navigateTo('inbox')}
                className={`w-full text-left rounded-lg text-xs font-semibold flex items-center transition-colors duration-150 cursor-pointer ${
                  isSidebarCollapsed ? 'lg:justify-center p-2.5' : 'justify-between py-2 px-3'
                } ${
                  activePage === 'inbox' || activePage === 'riwayat-struk'
                    ? 'bg-indigo-600/15 text-white border-l-2 border-indigo-500' 
                    : 'text-slate-400 hover:bg-slate-800 hover:text-white'
                }`}
                title={isSidebarCollapsed ? 'Struk Reimbursement' : undefined}
              >
                <div className={`flex items-center ${isSidebarCollapsed ? 'lg:justify-center' : 'gap-2.5'}`}>
                  <div className="relative">
                    <Inbox className="w-4 h-4 opacity-80" />
                    {pendingReceiptCount > 0 && (
                      <span className="hidden lg:flex absolute -top-1.5 -right-2 w-3.5 h-3.5 rounded-full bg-indigo-500 text-white text-[8px] font-bold items-center justify-center">
                        {pendingReceiptCount}
                      </span>
                    )}
                    {unreadReceiptCount > 0 && (
                      <span className="absolute -top-1 -right-1 w-2 h-2 rounded-full bg-rose-500 animate-ping" />
                    )}
                  </div>
                  {(!isSidebarCollapsed || window.innerWidth < 1024) && <span>Struk Reimbursement</span>}
                </div>
                {!isSidebarCollapsed && pendingReceiptCount > 0 && (
                  <span className="text-[10px] px-1.5 py-0.5 rounded-full bg-indigo-500 text-white font-bold font-mono">
                    {pendingReceiptCount}
                  </span>
                )}
              </button>

              <button
                onClick={() => navigateTo('invoice-inbox')}
                className={`w-full text-left rounded-lg text-xs font-semibold flex items-center transition-colors duration-150 cursor-pointer ${
                  isSidebarCollapsed ? 'lg:justify-center p-2.5' : 'justify-between py-2 px-3'
                } ${
                  activePage === 'invoice-inbox' 
                    ? 'bg-indigo-600/15 text-white border-l-2 border-indigo-500' 
                    : 'text-slate-400 hover:bg-slate-800 hover:text-white'
                }`}
                title={isSidebarCollapsed ? 'Inbox Invoice' : undefined}
              >
                <div className={`flex items-center ${isSidebarCollapsed ? 'lg:justify-center' : 'gap-2.5'}`}>
                  <div className="relative">
                    <FileText className="w-4 h-4 opacity-80" />
                    {pendingInvoiceCount > 0 && (
                      <span className="hidden lg:flex absolute -top-1.5 -right-2 w-3.5 h-3.5 rounded-full bg-amber-500 text-white text-[8px] font-bold items-center justify-center">
                        {pendingInvoiceCount}
                      </span>
                    )}
                    {unreadInvoiceCount > 0 && (
                      <span className="absolute -top-1 -right-1 w-2 h-2 rounded-full bg-rose-500 animate-ping" />
                    )}
                  </div>
                  {(!isSidebarCollapsed || window.innerWidth < 1024) && <span>Inbox Invoice</span>}
                </div>
                {!isSidebarCollapsed && pendingInvoiceCount > 0 && (
                  <span className="text-[10px] px-1.5 py-0.5 rounded-full bg-amber-500 text-white font-bold font-mono">
                    {pendingInvoiceCount}
                  </span>
                )}
              </button>

              <button
                onClick={() => navigateTo('input-invoice')}
                className={`w-full text-left rounded-lg text-xs font-semibold flex items-center transition-colors duration-150 cursor-pointer ${
                  isSidebarCollapsed ? 'lg:justify-center p-2.5' : 'gap-2.5 py-2 px-3'
                } ${
                  activePage === 'input-invoice' 
                    ? 'bg-indigo-600/15 text-white border-l-2 border-indigo-500' 
                    : 'text-slate-400 hover:bg-slate-800 hover:text-white'
                }`}
                title={isSidebarCollapsed ? 'Input Invoice' : undefined}
              >
                <FilePlus className="w-4 h-4 opacity-80" />
                {(!isSidebarCollapsed || window.innerWidth < 1024) && <span>Input Invoice</span>}
              </button>

              <button
                onClick={() => navigateTo('scan-invoice')}
                className={`w-full text-left rounded-lg text-xs font-semibold flex items-center transition-colors duration-150 cursor-pointer ${
                  isSidebarCollapsed ? 'lg:justify-center p-2.5' : 'gap-2.5 py-2 px-3'
                } ${
                  activePage === 'scan-invoice' 
                    ? 'bg-indigo-600/15 text-white border-l-2 border-indigo-500' 
                    : 'text-slate-400 hover:bg-slate-800 hover:text-white'
                }`}
                title={isSidebarCollapsed ? 'Scan Invoice OCR' : undefined}
              >
                <Scan className="w-4 h-4 opacity-80" />
                {(!isSidebarCollapsed || window.innerWidth < 1024) && <span>Scan Invoice OCR</span>}
              </button>

              <button
                onClick={() => navigateTo('riwayat-invoice')}
                className={`w-full text-left rounded-lg text-xs font-semibold flex items-center transition-colors duration-150 cursor-pointer ${
                  isSidebarCollapsed ? 'lg:justify-center p-2.5' : 'gap-2.5 py-2 px-3'
                } ${
                  activePage === 'riwayat-invoice' 
                    ? 'bg-indigo-600/15 text-white border-l-2 border-indigo-500' 
                    : 'text-slate-400 hover:bg-slate-800 hover:text-white'
                }`}
                title={isSidebarCollapsed ? 'Riwayat Invoice' : undefined}
              >
                <History className="w-4 h-4 opacity-80" />
                {(!isSidebarCollapsed || window.innerWidth < 1024) && <span>Riwayat Invoice</span>}
              </button>

              <button
                onClick={() => navigateTo('master-vendor')}
                className={`w-full text-left rounded-lg text-xs font-semibold flex items-center transition-colors duration-150 cursor-pointer ${
                  isSidebarCollapsed ? 'lg:justify-center p-2.5' : 'gap-2.5 py-2 px-3'
                } ${
                  activePage === 'master-vendor' 
                    ? 'bg-indigo-600/15 text-white border-l-2 border-indigo-500' 
                    : 'text-slate-400 hover:bg-slate-800 hover:text-white'
                }`}
                title={isSidebarCollapsed ? 'Master Vendor' : undefined}
              >
                <Building className="w-4 h-4 opacity-80" />
                {(!isSidebarCollapsed || window.innerWidth < 1024) && <span>Master Vendor</span>}
              </button>
            </div>
            )}

            {/* Group 2.5: Manajemen — disembunyikan untuk finance (ranah HRD/admin) */}
            {!isFinance && (
            <div className="space-y-1.5">
              {!isSidebarCollapsed ? (
                <span className="px-3 text-[10px] uppercase tracking-wider text-slate-500 font-bold block mb-2 font-mono">
                  Manajemen
                </span>
              ) : (
                <div className="hidden lg:block my-2 border-t border-slate-800/60" />
              )}
              
              <button
                onClick={() => navigateTo('karyawan')}
                className={`w-full text-left rounded-lg text-xs font-semibold flex items-center transition-colors duration-150 cursor-pointer ${
                  isSidebarCollapsed ? 'lg:justify-center p-2.5' : 'gap-2.5 py-2 px-3'
                } ${
                  activePage === 'karyawan'
                    ? 'bg-indigo-600/15 text-white border-l-2 border-indigo-500'
                    : 'text-slate-400 hover:bg-slate-800 hover:text-white'
                }`}
                title={isSidebarCollapsed ? 'Karyawan' : undefined}
              >
                <Users className="w-4 h-4 opacity-80" />
                {(!isSidebarCollapsed || window.innerWidth < 1024) && <span>Karyawan</span>}
              </button>

              <button
                onClick={() => navigateTo('presensi')}
                className={`w-full text-left rounded-lg text-xs font-semibold flex items-center transition-colors duration-150 cursor-pointer ${
                  isSidebarCollapsed ? 'lg:justify-center p-2.5' : 'justify-between py-2 px-3'
                } ${
                  activePage === 'presensi'
                    ? 'bg-indigo-600/15 text-white border-l-2 border-indigo-500'
                    : 'text-slate-400 hover:bg-slate-800 hover:text-white'
                }`}
                title={isSidebarCollapsed ? 'Presensi & Cuti' : undefined}
              >
                <div className={`flex items-center ${isSidebarCollapsed ? 'lg:justify-center' : 'gap-2.5'}`}>
                  <div className="relative">
                    <CalendarCheck className="w-4 h-4 opacity-80" />
                    {unreadLeavesCount > 0 && (
                      <span className="absolute -top-1 -right-1 flex h-2 w-2">
                        <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-rose-400 opacity-75"></span>
                        <span className="relative inline-flex rounded-full h-2 w-2 bg-rose-500"></span>
                      </span>
                    )}
                  </div>
                  {(!isSidebarCollapsed || window.innerWidth < 1024) && <span>Presensi & Cuti</span>}
                </div>
                {!isSidebarCollapsed && unreadLeavesCount > 0 && (
                  <span className="text-[10px] px-1.5 py-0.5 rounded-full bg-rose-500 text-white font-bold font-mono animate-pulse">
                    {unreadLeavesCount}
                  </span>
                )}
              </button>

              <button
                onClick={() => navigateTo('shift')}
                className={`w-full text-left rounded-lg text-xs font-semibold flex items-center transition-colors duration-150 cursor-pointer ${
                  isSidebarCollapsed ? 'lg:justify-center p-2.5' : 'gap-2.5 py-2 px-3'
                } ${
                  activePage === 'shift'
                    ? 'bg-indigo-600/15 text-white border-l-2 border-indigo-500'
                    : 'text-slate-400 hover:bg-slate-800 hover:text-white'
                }`}
                title={isSidebarCollapsed ? 'Shift & Jadwal' : undefined}
              >
                <CalendarClock className="w-4 h-4 opacity-80" />
                {(!isSidebarCollapsed || window.innerWidth < 1024) && <span>Shift & Jadwal</span>}
              </button>

              <button
                onClick={() => navigateTo('overtime')}
                className={`w-full text-left rounded-lg text-xs font-semibold flex items-center transition-colors duration-150 cursor-pointer ${
                  isSidebarCollapsed ? 'lg:justify-center p-2.5' : 'justify-between py-2 px-3'
                } ${
                  activePage === 'overtime'
                    ? 'bg-indigo-600/15 text-white border-l-2 border-indigo-500'
                    : 'text-slate-400 hover:bg-slate-800 hover:text-white'
                }`}
                title={isSidebarCollapsed ? 'Approval Lembur' : undefined}
              >
                <div className={`flex items-center ${isSidebarCollapsed ? 'lg:justify-center' : 'gap-2.5'}`}>
                  <div className="relative">
                    <FileSpreadsheet className="w-4 h-4 opacity-80" />
                    {pendingOvertimeCount > 0 && (
                      <span className="hidden lg:flex absolute -top-1.5 -right-2 w-3.5 h-3.5 rounded-full bg-orange-500 text-white text-[8px] font-bold items-center justify-center">
                        {pendingOvertimeCount}
                      </span>
                    )}
                    {unreadOvertimeCount > 0 && (
                      <span className="absolute -top-1 -right-1 w-2 h-2 rounded-full bg-rose-500 animate-ping" />
                    )}
                  </div>
                  {(!isSidebarCollapsed || window.innerWidth < 1024) && <span>Approval Lembur</span>}
                </div>
                {!isSidebarCollapsed && pendingOvertimeCount > 0 && (
                  <span className="text-[10px] px-1.5 py-0.5 rounded-full bg-orange-500 text-white font-bold font-mono">
                    {pendingOvertimeCount}
                  </span>
                )}
              </button>

              <button
                onClick={() => navigateTo('device-changes')}
                className={`w-full text-left rounded-lg text-xs font-semibold flex items-center transition-colors duration-150 cursor-pointer ${
                  isSidebarCollapsed ? 'lg:justify-center p-2.5' : 'justify-between py-2 px-3'
                } ${
                  activePage === 'device-changes'
                    ? 'bg-indigo-600/15 text-white border-l-2 border-indigo-500'
                    : 'text-slate-400 hover:bg-slate-800 hover:text-white'
                }`}
                title={isSidebarCollapsed ? 'Pindah Perangkat' : undefined}
              >
                <div className={`flex items-center ${isSidebarCollapsed ? 'lg:justify-center' : 'gap-2.5'}`}>
                  <div className="relative">
                    <Smartphone className="w-4 h-4 opacity-80" />
                    {pendingDeviceCount > 0 && (
                      <span className="hidden lg:flex absolute -top-1.5 -right-2 w-3.5 h-3.5 rounded-full bg-orange-500 text-white text-[8px] font-bold items-center justify-center">
                        {pendingDeviceCount}
                      </span>
                    )}
                    {unreadDeviceCount > 0 && (
                      <span className="absolute -top-1 -right-1 w-2 h-2 rounded-full bg-rose-500 animate-ping" />
                    )}
                  </div>
                  {(!isSidebarCollapsed || window.innerWidth < 1024) && <span>Pindah Perangkat</span>}
                </div>
                {!isSidebarCollapsed && pendingDeviceCount > 0 && (
                  <span className="text-[10px] px-1.5 py-0.5 rounded-full bg-orange-500 text-white font-bold font-mono">
                    {pendingDeviceCount}
                  </span>
                )}
              </button>

              <button
                onClick={() => navigateTo('rekrutmen')}
                className={`w-full text-left rounded-lg text-xs font-semibold flex items-center transition-colors duration-150 cursor-pointer ${
                  isSidebarCollapsed ? 'lg:justify-center p-2.5' : 'justify-between py-2 px-3'
                } ${
                  activePage === 'rekrutmen'
                    ? 'bg-indigo-600/15 text-white border-l-2 border-indigo-500'
                    : 'text-slate-400 hover:bg-slate-800 hover:text-white'
                }`}
                title={isSidebarCollapsed ? 'Rekrutmen' : undefined}
              >
                <div className={`flex items-center ${isSidebarCollapsed ? 'lg:justify-center' : 'gap-2.5'}`}>
                  <div className="relative">
                    <Briefcase className="w-4 h-4 opacity-80" />
                    {unreadRecruitmentCount > 0 && (
                      <span className="absolute -top-1 -right-1 flex h-2 w-2">
                        <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-rose-400 opacity-75"></span>
                        <span className="relative inline-flex rounded-full h-2 w-2 bg-rose-500"></span>
                      </span>
                    )}
                  </div>
                  {(!isSidebarCollapsed || window.innerWidth < 1024) && <span>Rekrutmen</span>}
                </div>
                {!isSidebarCollapsed && unreadRecruitmentCount > 0 && (
                  <span className="text-[10px] px-1.5 py-0.5 rounded-full bg-rose-500 text-white font-bold font-mono animate-pulse">
                    {unreadRecruitmentCount}
                  </span>
                )}
              </button>
            </div>
            )}

            {/* Group 3: Reporting & Systems */}
            <div className="space-y-1.5">
              {!isSidebarCollapsed ? (
                <span className="px-3 text-[10px] uppercase tracking-wider text-slate-500 font-bold block mb-2 font-mono">
                  Laporan & Sistem
                </span>
              ) : (
                <div className="hidden lg:block my-2 border-t border-slate-800/60" />
              )}

              <button
                onClick={() => navigateTo('laporan')}
                className={`w-full text-left rounded-lg text-xs font-semibold flex items-center transition-colors duration-150 cursor-pointer ${
                  isSidebarCollapsed ? 'lg:justify-center p-2.5' : 'gap-2.5 py-2 px-3'
                } ${
                  activePage === 'laporan' 
                    ? 'bg-indigo-600/15 text-white border-l-2 border-indigo-500' 
                    : 'text-slate-400 hover:bg-slate-800 hover:text-white'
                }`}
                title={isSidebarCollapsed ? 'Laporan Gabungan' : undefined}
              >
                <BarChart3 className="w-4 h-4 opacity-80" />
                {(!isSidebarCollapsed || window.innerWidth < 1024) && <span>Laporan Gabungan</span>}
              </button>

              <button
                onClick={() => navigateTo('auditlog')}
                className={`w-full text-left rounded-lg text-xs font-semibold flex items-center transition-colors duration-150 cursor-pointer ${
                  isSidebarCollapsed ? 'lg:justify-center p-2.5' : 'gap-2.5 py-2 px-3'
                } ${
                  activePage === 'auditlog' 
                    ? 'bg-indigo-600/15 text-white border-l-2 border-indigo-500' 
                    : 'text-slate-400 hover:bg-slate-800 hover:text-white'
                }`}
                title={isSidebarCollapsed ? 'Audit Log' : undefined}
              >
                <ShieldCheck className="w-4 h-4 opacity-80" />
                {(!isSidebarCollapsed || window.innerWidth < 1024) && <span>Audit Log</span>}
              </button>

              <button
                onClick={() => navigateTo('notif')}
                className={`w-full text-left rounded-lg text-xs font-semibold flex items-center transition-colors duration-150 cursor-pointer ${
                  isSidebarCollapsed ? 'lg:justify-center p-2.5' : 'justify-between py-2 px-3'
                } ${
                  activePage === 'notif' 
                    ? 'bg-indigo-600/15 text-white border-l-2 border-indigo-500' 
                    : 'text-slate-400 hover:bg-slate-800 hover:text-white'
                }`}
                title={isSidebarCollapsed ? 'Notifikasi' : undefined}
              >
                <div className={`flex items-center ${isSidebarCollapsed ? 'lg:justify-center' : 'gap-2.5'}`}>
                  <div className="relative">
                    <Bell className="w-4 h-4 opacity-80" />
                    {unreadNotifCount > 0 && (
                      <span className="hidden lg:flex absolute -top-1.5 -right-2 w-3.5 h-3.5 rounded-full bg-rose-500 text-white text-[8px] font-bold items-center justify-center animate-pulse">
                        {unreadNotifCount}
                      </span>
                    )}
                  </div>
                  {(!isSidebarCollapsed || window.innerWidth < 1024) && <span>Notifikasi</span>}
                </div>
                {!isSidebarCollapsed && unreadNotifCount > 0 && (
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
                className={`w-full text-left rounded-lg text-xs font-semibold flex items-center transition-colors duration-150 cursor-pointer ${
                  isSidebarCollapsed ? 'lg:justify-center p-2.5' : 'gap-2.5 py-2 px-3'
                } ${
                  activePage === 'setting'
                    ? 'bg-indigo-600/15 text-white border-l-2 border-indigo-500'
                    : 'text-slate-400 hover:bg-slate-800 hover:text-white'
                }`}
                title={isSidebarCollapsed ? 'Pengaturan Aturan' : undefined}
              >
                <Settings className="w-4 h-4 opacity-80" />
                {(!isSidebarCollapsed || window.innerWidth < 1024) && <span>Pengaturan Aturan</span>}
              </button>
              )}
            </div>

          </div>

          {/* Fixed bottom footer with avatar */}
          <div className={`p-4 border-t border-slate-800 shrink-0 ${isSidebarCollapsed ? 'lg:flex lg:flex-col lg:items-center lg:gap-2 lg:px-2 space-y-2' : 'space-y-2'}`}>
            <div className={`flex items-center gap-3 bg-slate-900/60 p-2 rounded-xl border border-slate-850 ${isSidebarCollapsed ? 'lg:justify-center lg:p-1.5' : ''}`}>
              <span className="w-8 h-8 rounded-full bg-indigo-600/20 border border-indigo-500/30 text-indigo-300 font-bold text-xs flex items-center justify-center shrink-0 font-mono" title={userName}>
                {userInitials}
              </span>
              {(!isSidebarCollapsed || window.innerWidth < 1024) && (
                <div className="min-w-0">
                  <p className="text-[11px] font-bold text-white truncate">{userName}</p>
                  <p className="text-[10px] text-slate-400 truncate">{roleLabel[userRole] ?? userRole}</p>
                </div>
              )}
            </div>
            <button
              onClick={() => logout()}
              className={`flex items-center justify-center gap-2 rounded-lg text-[11px] font-semibold text-slate-300 hover:bg-slate-800 hover:text-white border border-slate-800 transition cursor-pointer ${
                isSidebarCollapsed ? 'lg:w-8 lg:h-8 lg:p-0 w-full py-2' : 'w-full py-2'
              }`}
              title="Keluar"
            >
              <LogOut className="w-3.5 h-3.5" />
              {(!isSidebarCollapsed || window.innerWidth < 1024) && <span>Keluar</span>}
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
          <header className="hidden lg:flex h-16 bg-white dark:bg-slate-900 border-b border-slate-200 dark:border-slate-800 px-6 items-center justify-between sticky top-0 z-20 select-none">
            <div className="flex items-center gap-3">
              {/* Burger Button next to Logo / Title in Desktop Header */}
              <button
                onClick={() => setIsSidebarCollapsed(!isSidebarCollapsed)}
                className="p-2 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-xl text-slate-500 dark:text-slate-400 hover:text-slate-800 dark:hover:text-white transition cursor-pointer"
                title={isSidebarCollapsed ? 'Perluas Sidebar' : 'Ciutkan Sidebar'}
              >
                <Menu className="w-5 h-5" />
              </button>

              <div className="flex items-center gap-2">
                <h2 className="font-bold text-slate-800 dark:text-white text-sm tracking-tight font-sans uppercase">System Portal</h2>
                <span className="text-slate-300">/</span>
                <span className="text-xs font-semibold text-slate-500 font-sans">
                  {pageTitles[activePage] || 'Portal'}
                </span>
              </div>
            </div>

            <div className="flex items-center gap-3">
              {/* Desktop Notification Bell with Unread Dot */}
              <button 
                onClick={() => navigateTo('notif')}
                className="relative p-2 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-xl text-slate-500 dark:text-slate-400 hover:text-indigo-600 dark:hover:text-white transition cursor-pointer"
                title={`Notifikasi Sistem (${unreadNotifCount} Baru)`}
              >
                <Bell className="w-5 h-5" />
                {unreadNotifCount > 0 && (
                  <span className="absolute top-1.5 right-1.5 flex h-2.5 w-2.5">
                    <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-rose-400 opacity-75"></span>
                    <span className="relative inline-flex rounded-full h-2.5 w-2.5 bg-rose-500"></span>
                  </span>
                )}
              </button>

              {/* Shortcut buttons */}
              <button 
                onClick={() => alert('Exporting global ledger reports...')}
                className="flex items-center gap-1.5 px-3 py-1.5 border border-slate-200 dark:border-slate-750 hover:bg-slate-50 dark:hover:bg-slate-800 rounded-lg text-xs font-semibold text-slate-600 dark:text-slate-350 transition cursor-pointer"
              >
                <Download className="w-3.5 h-3.5 text-indigo-500" />
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
            {activePage !== 'shifts' && (
              <div className="flex items-center justify-between pb-3 border-b border-slate-200 dark:border-slate-800">
                <div>
                  <h2 className="text-lg md:text-xl font-bold text-slate-900 dark:text-white tracking-tight leading-none font-sans flex items-center gap-2">
                    {activePage === 'inbox' && <ReceiptIcon className="w-5 h-5 text-indigo-500 shrink-0" />}
                    {pageTitles[activePage] || 'ExpenseFlow Portal'}
                  </h2>
                  {activePage === 'inbox' && (
                    <p className="text-xs text-slate-500 dark:text-slate-400 mt-1.5 font-sans">
                      Sistem verifikasi otomatis multi-channel berbasis OCR &amp; fraud alert flag
                    </p>
                  )}
                </div>
              </div>
            )}

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
