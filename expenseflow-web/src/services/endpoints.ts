// Fungsi pemanggil API per-resource. Semua mengembalikan data mentah backend;
// transformasi ke tipe frontend dilakukan di mappers.ts / komponen.

import {
  apiGet,
  apiPost,
  apiPut,
  apiPatch,
  apiDelete,
  apiDownload,
  apiViewFile,
  setToken,
  setStoredUser,
  clearToken,
  getToken,
  setTokenExpiresAt,
  BASE_URL,
  invalidateCache,
} from './api';


// ─── Auth ───────────────────────────────────────────────────
export const authApi = {
  login: async (email: string, password: string) => {
    const res = await apiPost<{ message: string; user: any; token: string; token_expires_at?: string | null }>('/login', {
      email,
      password,
    });
    setToken(res.token);
    setStoredUser(res.user);
    setTokenExpiresAt(res.token_expires_at ?? null);
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
  sendForgotPasswordOtp: (email: string) =>
    apiPost<{ status: string; message: string; cooldown_seconds?: number; expires_in_seconds?: number; debug_otp?: string }>(
      '/auth/forgot-password/send-otp',
      { email }
    ),
  verifyForgotPasswordOtp: (email: string, otp: string) =>
    apiPost<{ status: string; message: string; reset_token: string }>(
      '/auth/forgot-password/verify-otp',
      { email, otp }
    ),
  resetPassword: (payload: { email: string; reset_token: string; password: string; password_confirmation: string }) =>
    apiPost<{ status: string; message: string }>(
      '/auth/forgot-password/reset',
      payload
    ),
};

// ─── Receipts (struk) ───────────────────────────────────────
export const receiptApi = {
  // Inbox: struk submitted yang menunggu approval (paginated)
  inbox: (forceRefresh = false) => apiGet('/dashboard/receipts', undefined, { forceRefresh }),
  // Semua struk dengan filter status + summary
  all: (status?: 'submitted' | 'approved' | 'rejected' | 'paid', forceRefresh = false) =>
    apiGet('/dashboard/receipts/all', { status }, { forceRefresh }),
  show: (id: number | string) => apiGet(`/dashboard/receipts/${id}`),
  approve: (id: number | string, notes: string, approvedAmount?: number) =>
    apiPost(`/dashboard/receipts/${id}/approve`, {
      notes,
      approved_amount: approvedAmount !== undefined ? approvedAmount : undefined,
    }),
  bulkApprove: (receiptIds: number[], notes?: string) =>
    apiPost('/dashboard/receipts/bulk-approve', {
      receipt_ids: receiptIds,
      notes,
    }),
  pay: (id: number | string, payload: { payment_method: string; payment_ref_no?: string }) =>
    apiPost(`/dashboard/receipts/${id}/pay`, payload),
  bulkPay: (receiptIds: number[], payload: { payment_method: string; payment_ref_no?: string }) =>
    apiPost('/dashboard/receipts/bulk-pay', {
      receipt_ids: receiptIds,
      ...payload,
    }),
  exportDisbursement: (status: 'approved' | 'paid' = 'approved') =>
    apiDownload(
      '/dashboard/receipts/export-disbursement',
      `rekap-transfer-reimbursement-${status}-${new Date().toISOString().slice(0, 10)}.csv`,
      { status }
    ),
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
  list: (status?: 'pending' | 'approved' | 'rejected', forceRefresh = false) =>
    apiGet('/dashboard/invoices', { status }, { forceRefresh }),
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
  list: (forceRefresh = false) => apiGet('/dashboard/vendors', undefined, { forceRefresh }),
  create: (payload: Record<string, unknown>) => apiPost('/dashboard/vendors', payload),
  update: (id: number | string, payload: Record<string, unknown>) =>
    apiPatch(`/dashboard/vendors/${id}`, payload),
  toggle: (id: number | string) => apiPost(`/dashboard/vendors/${id}/toggle`),
};

export interface BulkImportPayload {
  users: Array<{
    name: string;
    email: string;
    employee_code?: string;
    identity_number?: string;
    phone?: string;
    gender?: string;
    birth_place?: string;
    birth_date?: string;
    is_pregnant?: boolean;
    department?: string;
    role?: string;
    attendance_setting_id?: number | null;
    monthly_claim_limit?: number | null;
    employment_type?: string;
    joined_date?: string;
    contract_start_date?: string;
    contract_end_date?: string;
    bank_name?: string;
    bank_account_no?: string;
    bank_account_holder?: string;
    leave_balance?: number;
  }>;
  default_password?: string;
  default_role?: string;
  default_attendance_setting_id?: number | null;
  default_employment_type?: string;
  default_wfh_enabled?: boolean;
  default_attendance_enabled?: boolean;
  default_radius_enabled?: boolean;
}

export interface BulkImportResponse {
  message: string;
  total: number;
  imported: number;
  skipped: number;
  errors: Array<{
    row: number;
    name: string;
    email: string;
    reason: string;
  }>;
  imported_users?: any[];
}

// ─── Users (karyawan) ───────────────────────────────────────
export const userApi = {
  list: (params?: { include_inactive?: boolean }, forceRefresh = false) =>
    apiGet('/admin/users', params, { forceRefresh }),
  create: (payload: Record<string, unknown>) => apiPost('/admin/users', payload),
  update: (id: number | string, payload: Record<string, unknown>) =>
    apiPut(`/admin/users/${id}`, payload),
  deactivate: (id: number | string) => apiPatch(`/admin/users/${id}/deactivate`),
  activate: (id: number | string) => apiPatch(`/admin/users/${id}/activate`),
  destroy: (id: number | string) => apiDelete<{ message: string }>(`/admin/users/${id}`),
  bulkImport: (payload: BulkImportPayload) =>
    apiPost<BulkImportResponse>('/admin/users/bulk-import', payload),
};

// ─── Notifications ──────────────────────────────────────────
export const notificationApi = {
  list: (onlyUnread = false, forceRefresh = false) =>
    apiGet('/dashboard/notifications', { only_unread: onlyUnread ? 1 : undefined }, { forceRefresh }),
  markAllRead: () => apiPost('/dashboard/notifications/read-all'),
  markRead: (id: string) => apiPost(`/dashboard/notifications/${id}/read`),
  destroy: (id: string) => apiDelete(`/dashboard/notifications/${id}`),
};

// ─── Activity logs (audit) ──────────────────────────────────
export const activityLogApi = {
  list: (filters?: {
    search?: string;
    severity?: string;
    category?: string;
    action?: string;
    entity_type?: string;
    start_date?: string;
    end_date?: string;
    page?: number;
    per_page?: number;
  }, forceRefresh = false) => apiGet<{ data: any[]; current_page: number; last_page: number; total: number }>('/dashboard/activity-logs', filters as Record<string, string | number>, { forceRefresh }),

  exportCsv: (filters?: {
    search?: string;
    severity?: string;
    category?: string;
    action?: string;
    start_date?: string;
    end_date?: string;
  }) => apiDownload(
    '/dashboard/activity-logs',
    `audit_logs_${new Date().toISOString().slice(0, 10)}.csv`,
    { ...filters, export: 'csv' } as Record<string, string | number>
  ),
};

// ─── Attendance (presensi) — HRD/Admin dashboard ────────────
export const attendanceApi = {
  // Dashboard presensi hari ini
  today: (forceRefresh = false) => apiGet('/dashboard/attendance/today', undefined, { forceRefresh }),

  // Daftar karyawan + status attendance/WFH
  users: (params?: { filter?: 'enabled' | 'disabled'; per_page?: number | string } | 'enabled' | 'disabled', forceRefresh = false) =>
    apiGet('/dashboard/attendance/users', typeof params === 'string' ? { filter: params } : (params as Record<string, string | number | boolean>), { forceRefresh }),
  // Semua karyawan aktif (tanpa pagination) — untuk dropdown pengecualian libur
  // Didukung in-memory smart cache (5 menit) + request deduplication terpusat
  allUsers: (forceRefresh = false) =>
    apiGet('/dashboard/attendance/users/all', undefined, { forceRefresh }),
  toggleWfh: (id: number | string) =>
    apiPost(`/dashboard/attendance/users/${id}/toggle-wfh`),
  toggleRadius: (id: number | string) =>
    apiPost(`/dashboard/attendance/users/${id}/toggle-radius`),

  // Pengajuan izin/cuti
  leaves: (filters?: {
    status?: 'pending' | 'approved' | 'rejected';
    leave_type?: 'wfh' | 'izin' | 'sakit' | 'cuti';
    user_id?: number;
    page?: number;
    per_page?: number;
  }, forceRefresh = false) => apiGet('/dashboard/attendance/leaves', filters, { forceRefresh }),
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
  leaveBalances: (filters?: { user_id?: number; year?: number }, forceRefresh = false) =>
    apiGet('/dashboard/attendance/leave-balances', filters, { forceRefresh }),
  leaveBalanceHistories: (filters?: { office_id?: string; year?: number; search?: string }, forceRefresh = false) =>
    apiGet('/dashboard/attendance/leave-balance-history', filters, { forceRefresh }),
  resetOfficeLeaveBalances: (officeId: number | string) =>
    apiPost(`/dashboard/attendance/settings/${officeId}/reset-leave-balances`),
  setLeaveBalance: (payload: {
    user_id: number;
    leave_type: 'cuti' | 'izin';
    quota: number;
    year?: number;
  }) => apiPost('/dashboard/attendance/leave-balances', payload),

  // Laporan presensi — mencakup baris virtual absent/leave
  report: (filters?: {
    start_date?: string;
    end_date?: string;
    department?: string;
    status?: 'present' | 'late' | 'absent' | 'early_leave' | 'cuti' | 'izin' | 'sakit' | 'wfh' | string;
    type?: 'onsite' | 'wfh' | 'field' | string;
    search?: string;
    office_id?: number | string;
    page?: number;
  }, forceRefresh = false) => apiGet('/dashboard/attendance/report', filters as Record<string, string | number | boolean>, { forceRefresh }),
  exportReport: (filters?: {
    start_date?: string;
    end_date?: string;
    department?: string;
    status?: string;
    type?: string;
    search?: string;
    office_id?: number | string;
  }) =>
    apiDownload(
      '/dashboard/attendance/report/export',
      `laporan-presensi-${new Date().toISOString().slice(0, 10)}.csv`,
      filters as Record<string, string | number | boolean>,
    ),
  monthlySummary: (filters: { user_id: number; month?: number; year?: number }, forceRefresh = false) =>
    apiGet('/dashboard/attendance/summary', filters, { forceRefresh }),

  // CRUD pengaturan kantor (lokasi & radius presensi) — didukung Smart in-memory cache & deduplikasi request
  settings: {
    list: (forceRefresh = false) =>
      apiGet('/dashboard/attendance/settings', undefined, { forceRefresh }),
    clearCache: () => {
      invalidateCache('/dashboard/attendance/settings');
    },
    create: (payload: Record<string, unknown>) =>
      apiPost('/dashboard/attendance/settings', payload),
    update: (id: number | string, payload: Record<string, unknown>) =>
      apiPut(`/dashboard/attendance/settings/${id}`, payload),
    destroy: (id: number | string) =>
      apiDelete(`/dashboard/attendance/settings/${id}`),
  },

  // Kalender libur nasional / cuti bersama perusahaan
  holidays: {
    list: (year?: number, forceRefresh = false) =>
      apiGet('/dashboard/attendance/holidays', { year }, { forceRefresh }),
    previewNational: (year?: number) =>
      apiGet('/dashboard/attendance/holidays/preview-national', { year }),
    syncNational: (payload: { year: number; holidays: any[]; collective_treatment?: 'nasional' | 'collective'; overwrite_existing?: boolean }) =>
      apiPost('/dashboard/attendance/holidays/sync-national', payload),
    previewCollective: (payload: { holiday_id?: number | null; date: string; name: string; attendance_setting_id?: number | null; excluded_user_ids?: number[] }) =>
      apiPost('/dashboard/attendance/holidays/collective-preview', payload),
    create: (payload: { date: string; name: string; type?: 'nasional' | 'collective' | 'perusahaan'; is_collective?: boolean; attendance_setting_id?: number | null; excluded_user_ids?: number[] }) =>
      apiPost('/dashboard/attendance/holidays', payload),
    update: (id: number | string, payload: { date: string; name: string; type?: 'nasional' | 'collective' | 'perusahaan'; attendance_setting_id?: number | null; excluded_user_ids?: number[] }) =>
      apiPut(`/dashboard/attendance/holidays/${id}`, payload),
    destroy: (id: number | string) =>
      apiDelete(`/dashboard/attendance/holidays/${id}`),
  },

  // Rekap opt-in cuti bersama
  collectiveLeaveDetail: (id: number | string) =>
    apiGet(`/dashboard/attendance/collective-leaves/${id}/detail`),
};

// ─── Shift / Custom Scheduling — HRD ────────────────────────
// Satu baris jadwal harian dalam sebuah template shift.
export interface ShiftScheduleInput {
  day_of_week: number;              // 0=Minggu … 6=Sabtu
  is_off: boolean;                  // true = shift libur di hari itu
  is_wfh?: boolean;                 // true = WFH di hari itu (hanya saat !is_off)
  is_field?: boolean;               // true = Lapangan di hari itu (hanya saat !is_off && is_wfh)
  work_start_time?: string | null;  // "H:i" (wajib jika !is_off)
  work_end_time?: string | null;    // "H:i"
}

// ── Pola Rotasi Shift (Recurring Rolling Cycles) ──
export interface ShiftPatternItemInput {
  day_order: number;
  shift_id?: number | null;
  is_off: boolean;
  work_start_time?: string | null;
  work_end_time?: string | null;
  break_minutes?: number;
  is_cross_day?: boolean;
}

export interface ShiftPatternItem {
  id: number;
  shift_pattern_id: number;
  day_order: number;
  shift_id: number | null;
  is_off: boolean;
  work_start_time: string | null;
  work_end_time: string | null;
  break_minutes: number;
  is_cross_day: boolean;
  shift?: {
    id: number;
    name: string;
    color?: string;
  } | null;
}

export interface ShiftPattern {
  id: number;
  company_id: number;
  attendance_setting_id?: number | null;
  office?: {
    id: number;
    office_name: string;
  } | null;
  name: string;
  description?: string | null;
  cycle_days: number;
  is_active: boolean;
  active_users_count?: number;
  created_at?: string;
  updated_at?: string;
  items: ShiftPatternItem[];
}

export const shiftApi = {
  // ── Template shift ──
  list: (filters?: { is_active?: boolean; attendance_setting_id?: number }, forceRefresh = false) =>
    apiGet('/dashboard/attendance/shifts', filters as Record<string, string | number | boolean>, { forceRefresh }),
  create: (payload: {
    name: string;
    description?: string;
    attendance_setting_id: number;
    schedules: ShiftScheduleInput[];
    color?: string | null;
  }) => apiPost('/dashboard/attendance/shifts', payload),
  update: (
    id: number | string,
    payload: {
      name?: string;
      description?: string;
      attendance_setting_id?: number;
      schedules?: ShiftScheduleInput[];
      color?: string | null;
    },
  ) => apiPut(`/dashboard/attendance/shifts/${id}`, payload),
  toggleActive: (id: number | string) =>
    apiPost(`/dashboard/attendance/shifts/${id}/toggle-active`),
  destroy: (id: number | string) => apiDelete(`/dashboard/attendance/shifts/${id}`),

  // ── Daftar karyawan yang terkait sebuah template shift ──
  users: (id: number | string, forceRefresh = false) =>
    apiGet(`/dashboard/attendance/shifts/${id}/users`, undefined, { forceRefresh }),

  // ── Roster harian (siapa masuk shift apa pada tanggal tertentu) ──
  roster: (filters?: { date?: string; attendance_setting_id?: number; search?: string }, forceRefresh = false) =>
    apiGet('/dashboard/attendance/shifts/roster', filters as Record<string, string | number>, { forceRefresh }),

  // ── Riwayat assignment shift seorang karyawan ──
  history: (userId: number | string, forceRefresh = false) =>
    apiGet(`/dashboard/attendance/users/${userId}/shift-history`, undefined, { forceRefresh }),

  // ── Assignment ──
  assign: (payload: {
    user_id: number;
    shift_id?: number | null;          // null = kembali ke default kantor atau penugasan pola rotasi
    shift_pattern_id?: number | null;  // penugasan pola rotasi siklus
    anchor_day_order?: number;         // hari ke berapa dalam siklus pada start_date
    start_date: string;
    end_date?: string;                 // opsional — tanggal berakhir shift, setelahnya kembali ke jam default
    notes?: string;
  }) => apiPost('/dashboard/attendance/assign-shift', payload),
  bulkAssign: (payload: {
    user_ids: number[];
    shift_id?: number | null;
    shift_pattern_id?: number | null;
    anchor_day_order?: number;
    start_date: string;
    end_date?: string;                 // opsional — tanggal berakhir shift untuk semua karyawan
    notes?: string;
  }) => apiPost('/dashboard/attendance/bulk-assign', payload),
  updateAssignment: (
    id: number | string,
    payload: {
      shift_id?: number | null;
      shift_pattern_id?: number | null;
      anchor_day_order?: number;
      start_date?: string;
      end_date?: string | null;
      notes?: string;
    },
  ) => apiPut(`/dashboard/attendance/assignments/${id}`, payload),
  destroyAssignment: (id: number | string) =>
    apiDelete(`/dashboard/attendance/assignments/${id}`),

  // ── Preview jadwal efektif user+tanggal ──
  effectiveSchedule: (userId: number, date: string) =>
    apiGet('/dashboard/attendance/effective-schedule', { user_id: userId, date }),

  // ── Kalender shift bulanan ──
  calendar: (month: number, year: number, attendanceSettingId?: number, forceRefresh = false) =>
    apiGet('/dashboard/attendance/shifts/calendar', {
      month,
      year,
      ...(attendanceSettingId ? { attendance_setting_id: attendanceSettingId } : {}),
    }, { forceRefresh }),

  // ── Pola Rotasi Shift (Recurring Rolling Cycles) ──
  patterns: {
    list: (filters?: { is_active?: boolean; attendance_setting_id?: number }, forceRefresh = false) =>
      apiGet('/dashboard/attendance/shift-patterns', filters as Record<string, string | number | boolean>, { forceRefresh }),
    get: (id: number | string, forceRefresh = false) =>
      apiGet(`/dashboard/attendance/shift-patterns/${id}`, undefined, { forceRefresh }),
    create: (payload: {
      name: string;
      description?: string;
      attendance_setting_id?: number | null;
      cycle_days: number;
      is_active?: boolean;
      items: ShiftPatternItemInput[];
    }) => apiPost('/dashboard/attendance/shift-patterns', payload),
    update: (
      id: number | string,
      payload: {
        name?: string;
        description?: string;
        attendance_setting_id?: number | null;
        cycle_days?: number;
        is_active?: boolean;
        items?: ShiftPatternItemInput[];
      },
    ) => apiPut(`/dashboard/attendance/shift-patterns/${id}`, payload),
    destroy: (id: number | string) =>
      apiDelete(`/dashboard/attendance/shift-patterns/${id}`),
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
    per_page?: number;
  }, forceRefresh = false) => apiGet('/dashboard/attendance/overtime-approvals', filters as Record<string, string | number>, { forceRefresh }),

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
    per_page?: number;
  }, forceRefresh = false) => apiGet('/dashboard/attendance/device-changes', filters as Record<string, string | number>, { forceRefresh }),

  approve: (id: number | string, notes?: string) =>
    apiPost(`/dashboard/attendance/device-changes/${id}/approve`, { notes }),

  reject: (id: number | string, notes: string) =>
    apiPost(`/dashboard/attendance/device-changes/${id}/reject`, { notes }),
};

// ─── Settings ───────────────────────────────────────────────
export const settingsApi = {
  get: (forceRefresh = false) =>
    apiGet<{ settings: any }>('/dashboard/settings', undefined, { forceRefresh }),
  clearCache: () => {
    invalidateCache('/dashboard/settings');
  },
  update: (payload: {
    variance_limit: number;
    max_claim_limit: number;
    threshold_single: string;
    threshold_two: string;
    threshold_three: string;
  }) => apiPut<{ settings: any }>('/dashboard/settings', payload),
};

// ─── Recruitment — HRD & Admin ──────────────────────────────
export const recruitmentApi = {
  listPostings: (filters?: { status?: string; search?: string; page?: number; per_page?: number }, forceRefresh = false) =>
    apiGet<{ data: any[]; meta: any; summary: any }>('/recruitment/postings', filters as Record<string, string | number>, { forceRefresh }),

  createPosting: (payload: any) =>
    apiPost<{ message: string; data: any }>('/recruitment/postings', payload),

  getPosting: (id: number | string) =>
    apiGet<{ data: any }>(`/recruitment/postings/${id}`),

  updatePosting: (id: number | string, payload: any) =>
    apiPut<{ message: string; data: any }>(`/recruitment/postings/${id}`, payload),

  deletePosting: (id: number | string) =>
    apiDelete<{ message: string }>(`/recruitment/postings/${id}`),

  publishPosting: (id: number | string) =>
    apiPatch<{ message: string; data: any }>(`/recruitment/postings/${id}/publish`),

  closePosting: (id: number | string) =>
    apiPatch<{ message: string; data: any }>(`/recruitment/postings/${id}/close`),

  listApplications: (postingId?: number | string, filters?: { status?: string; search?: string; page?: number; per_page?: number }, forceRefresh = false) => {
    const url = postingId ? `/recruitment/postings/${postingId}/applications` : '/recruitment/applications';
    return apiGet<{ posting?: any; data: any[]; meta: any; summary?: any }>(url, filters as Record<string, string | number>, { forceRefresh });
  },

  getApplication: (id: number | string) =>
    apiGet<{ data: any }>(`/recruitment/applications/${id}`),

  updateApplicationStatus: (id: number | string, payload: { status: string; notes?: string }) =>
    apiPatch<{ message: string; data: any }>(`/recruitment/applications/${id}/status`, payload),

  deleteApplication: (id: number | string) =>
    apiDelete<{ message: string }>(`/recruitment/applications/${id}`),

  viewResume: async (id: number | string, fullName?: string) =>
    apiViewFile(`/recruitment/applications/${id}/resume`, fullName ? `CV — ${fullName}` : 'Berkas CV'),


  downloadResume: async (id: number | string, fullName: string) =>
    apiDownload(`/recruitment/applications/${id}/resume`, `CV_${fullName.replace(/\s+/g, '_')}.pdf`),
};



