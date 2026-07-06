// Fungsi pemanggil API per-resource. Semua mengembalikan data mentah backend;
// transformasi ke tipe frontend dilakukan di mappers.ts / komponen.

import { apiGet, apiPost, apiPut, apiPatch, apiDelete, apiDownload, setToken, setStoredUser, clearToken, getToken, BASE_URL } from './api';

// ─── Auth ───────────────────────────────────────────────────
export const authApi = {
  login: async (email: string, password: string) => {
    const res = await apiPost<{ message: string; user: any; token: string }>('/login', {
      email,
      password,
    });
    setToken(res.token);
    setStoredUser(res.user);
    return res;
  },
  me: () => apiGet<{ user: any }>('/me'),
  logout: async () => {
    try {
      await apiPost('/logout');
    } finally {
      clearToken();
    }
  },
};

// ─── Receipts (struk) ───────────────────────────────────────
export const receiptApi = {
  // Inbox: struk submitted yang menunggu approval (paginated)
  inbox: () => apiGet('/dashboard/receipts'),
  // Semua struk dengan filter status + summary
  all: (status?: 'submitted' | 'approved' | 'rejected') =>
    apiGet('/dashboard/receipts/all', { status }),
  show: (id: number | string) => apiGet(`/dashboard/receipts/${id}`),
  approve: (id: number | string, notes: string) =>
    apiPost(`/dashboard/receipts/${id}/approve`, { notes }),
  reject: (id: number | string, notes: string) =>
    apiPost(`/dashboard/receipts/${id}/reject`, { notes }),
  // Fetch image as blob dan convert ke data URL untuk display di <img>
  fetchImageAsDataUrl: async (id: number | string): Promise<string | null> => {
    try {
      const headers: Record<string, string> = { 'X-Platform': 'web' };
      const token = getToken();
      if (token) headers['Authorization'] = `Bearer ${token}`;

      const response = await fetch(`${BASE_URL}/dashboard/receipts/${id}/image`, { headers });
      if (!response.ok) return null;

      const blob = await response.blob();
      return URL.createObjectURL(blob);
    } catch {
      return null;
    }
  },
};

// ─── Invoices ───────────────────────────────────────────────
export const invoiceApi = {
  list: (status?: 'pending' | 'approved' | 'rejected') =>
    apiGet('/dashboard/invoices', { status }),
  show: (id: number | string) => apiGet(`/dashboard/invoices/${id}`),
  create: (payload: {
    vendor_id: number;
    invoice_number: string;
    invoice_date: string;
    due_date: string;
    category: string;
    po_number?: string;
    notes?: string;
    items: { description: string; quantity: number; unit_price: number }[];
  }) => apiPost('/dashboard/invoices', payload),
  approve: (id: number | string, notes?: string) =>
    apiPost(`/dashboard/invoices/${id}/approve`, { notes }),
  reject: (id: number | string, rejection_reason: string) =>
    apiPost(`/dashboard/invoices/${id}/reject`, { rejection_reason }),
};

// ─── Vendors ────────────────────────────────────────────────
export const vendorApi = {
  list: () => apiGet('/dashboard/vendors'),
  create: (payload: Record<string, unknown>) => apiPost('/dashboard/vendors', payload),
  update: (id: number | string, payload: Record<string, unknown>) =>
    apiPatch(`/dashboard/vendors/${id}`, payload),
  toggle: (id: number | string) => apiPost(`/dashboard/vendors/${id}/toggle`),
};

// ─── Users (karyawan) ───────────────────────────────────────
export const userApi = {
  list: () => apiGet('/admin/users'),
  create: (payload: Record<string, unknown>) => apiPost('/admin/users', payload),
  update: (id: number | string, payload: Record<string, unknown>) =>
    apiPut(`/admin/users/${id}`, payload),
  deactivate: (id: number | string) => apiPatch(`/admin/users/${id}/deactivate`),
  activate: (id: number | string) => apiPatch(`/admin/users/${id}/activate`),
  resetPassword: (id: number | string, password: string) =>
    apiPost(`/admin/users/${id}/reset-password`, { password }),
};

// ─── Notifications ──────────────────────────────────────────
export const notificationApi = {
  list: (onlyUnread = false) =>
    apiGet('/dashboard/notifications', { only_unread: onlyUnread ? 1 : undefined }),
  markAllRead: () => apiPost('/dashboard/notifications/read-all'),
  markRead: (id: string) => apiPost(`/dashboard/notifications/${id}/read`),
  destroy: (id: string) => apiDelete(`/dashboard/notifications/${id}`),
};

// ─── Activity logs (audit) ──────────────────────────────────
export const activityLogApi = {
  list: (filters?: { action?: string; entity_type?: string }) =>
    apiGet('/dashboard/activity-logs', filters),
};

// ─── Attendance (presensi) — HRD/Admin dashboard ────────────
export const attendanceApi = {
  // Dashboard presensi hari ini
  today: () => apiGet('/dashboard/attendance/today'),

  // Daftar karyawan + status attendance/WFH
  users: (filter?: 'enabled' | 'disabled') =>
    apiGet('/dashboard/attendance/users', { filter }),
  toggleWfh: (id: number | string) =>
    apiPost(`/dashboard/attendance/users/${id}/toggle-wfh`),
  toggleRadius: (id: number | string) =>
    apiPost(`/dashboard/attendance/users/${id}/toggle-radius`),

  // Pengajuan izin/cuti
  leaves: (filters?: {
    status?: 'pending' | 'approved' | 'rejected';
    leave_type?: 'wfh' | 'izin' | 'sakit' | 'cuti';
    user_id?: number;
  }) => apiGet('/dashboard/attendance/leaves', filters),
  approveLeave: (id: number | string) =>
    apiPost(`/dashboard/attendance/leaves/${id}/approve`),
  rejectLeave: (id: number | string, rejection_reason: string) =>
    apiPost(`/dashboard/attendance/leaves/${id}/reject`, { rejection_reason }),

  // Ambil surat dokter (file privat) sebagai object URL untuk ditampilkan.
  leaveDocumentUrl: async (
    id: number | string,
  ): Promise<{ url: string; isPdf: boolean } | null> => {
    try {
      const headers: Record<string, string> = { 'X-Platform': 'web' };
      const token = getToken();
      if (token) headers['Authorization'] = `Bearer ${token}`;

      const response = await fetch(`${BASE_URL}/dashboard/attendance/leaves/${id}/document`, { headers });
      if (!response.ok) return null;

      const blob = await response.blob();
      return { url: URL.createObjectURL(blob), isPdf: blob.type === 'application/pdf' };
    } catch {
      return null;
    }
  },

  // Saldo / kuota cuti
  leaveBalances: (filters?: { user_id?: number; year?: number }) =>
    apiGet('/dashboard/attendance/leave-balances', filters),
  setLeaveBalance: (payload: {
    user_id: number;
    leave_type: 'cuti' | 'sakit';
    quota: number;
    year?: number;
  }) => apiPost('/dashboard/attendance/leave-balances', payload),

  // Laporan presensi — mencakup baris virtual absent/leave
  report: (filters?: {
    start_date?: string;
    end_date?: string;
    department?: string;
    status?: 'present' | 'late' | 'absent' | 'early_leave' | 'cuti' | 'izin' | 'sakit' | 'wfh';
    type?: 'onsite' | 'wfh' | 'field';
    page?: number;
  }) => apiGet('/dashboard/attendance/report', filters as Record<string, string | number | boolean>),
  exportReport: (filters?: {
    start_date?: string;
    end_date?: string;
    department?: string;
    status?: string;
    type?: string;
  }) =>
    apiDownload(
      '/dashboard/attendance/report/export',
      `laporan-presensi-${new Date().toISOString().slice(0, 10)}.csv`,
      filters as Record<string, string | number | boolean>,
    ),
  monthlySummary: (filters: { user_id: number; month?: number; year?: number }) =>
    apiGet('/dashboard/attendance/summary', filters),

  // CRUD pengaturan kantor (lokasi & radius presensi)
  settings: {
    list: () => apiGet('/dashboard/attendance/settings'),
    create: (payload: Record<string, unknown>) =>
      apiPost('/dashboard/attendance/settings', payload),
    update: (id: number | string, payload: Record<string, unknown>) =>
      apiPut(`/dashboard/attendance/settings/${id}`, payload),
    destroy: (id: number | string) =>
      apiDelete(`/dashboard/attendance/settings/${id}`),
  },

  // Kalender libur nasional / cuti bersama perusahaan
  holidays: {
    list: (year?: number) =>
      apiGet('/dashboard/attendance/holidays', { year }),
    create: (payload: { date: string; name: string }) =>
      apiPost('/dashboard/attendance/holidays', payload),
    update: (id: number | string, payload: { date: string; name: string }) =>
      apiPut(`/dashboard/attendance/holidays/${id}`, payload),
    destroy: (id: number | string) =>
      apiDelete(`/dashboard/attendance/holidays/${id}`),
  },
};

// ─── Overtime approvals — HRD ───────────────────────────────
export const overtimeApi = {
  list: (filters?: {
    status?: 'pending' | 'approved' | 'rejected';
    user_id?: number;
    start_date?: string;
    end_date?: string;
    page?: number;
  }) => apiGet('/dashboard/attendance/overtime-approvals', filters as Record<string, string | number>),

  approve: (id: number | string, notes?: string) =>
    apiPost(`/dashboard/attendance/overtime-approvals/${id}/approve`, { notes }),

  reject: (id: number | string, notes: string) =>
    apiPost(`/dashboard/attendance/overtime-approvals/${id}/reject`, { notes }),
};

// ─── Device change approvals — HRD (device binding, cegah titip absen) ──
export const deviceChangeApi = {
  list: (filters?: {
    status?: 'pending' | 'approved' | 'rejected';
    page?: number;
  }) => apiGet('/dashboard/attendance/device-changes', filters as Record<string, string | number>),

  approve: (id: number | string, notes?: string) =>
    apiPost(`/dashboard/attendance/device-changes/${id}/approve`, { notes }),

  reject: (id: number | string, notes: string) =>
    apiPost(`/dashboard/attendance/device-changes/${id}/reject`, { notes }),
};

// ─── Settings ───────────────────────────────────────────────
export const settingsApi = {
  get: () => apiGet<{ settings: any }>('/dashboard/settings'),
  update: (payload: {
    variance_limit: number;
    max_claim_limit: number;
    threshold_single: string;
    threshold_two: string;
    threshold_three: string;
  }) => apiPut<{ settings: any }>('/dashboard/settings', payload),
};
