// Fungsi pemanggil API per-resource. Semua mengembalikan data mentah backend;
// transformasi ke tipe frontend dilakukan di mappers.ts / komponen.

import {
  apiGet,
  apiPost,
  apiPut,
  apiPatch,
  apiDelete,
  apiUpload,
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
  // Inbox: struk submitted yang menunggu approval (paginated, mendukung filter cabang)
  inbox: (params?: { attendance_setting_id?: number | string; per_page?: number } | boolean, forceRefresh = false) => {
    const isBool = typeof params === 'boolean';
    const queryParams = isBool ? undefined : params;
    const shouldRefresh = isBool ? params : forceRefresh;
    return apiGet('/dashboard/receipts', queryParams, { forceRefresh: shouldRefresh });
  },
  // Semua struk dengan filter status + summary + cabang
  all: (
    filter?: 'submitted' | 'approved' | 'rejected' | 'paid' | { status?: string; attendance_setting_id?: number | string; per_page?: number },
    forceRefresh = false
  ) => {
    const queryParams = typeof filter === 'string' ? { status: filter } : filter;
    return apiGet('/dashboard/receipts/all', queryParams, { forceRefresh });
  },
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
  exportDisbursement: (status: 'approved' | 'paid' = 'approved', branchId?: number | string) =>
    apiDownload(
      '/dashboard/receipts/export-disbursement',
      `rekap-transfer-reimbursement-${status}-${new Date().toISOString().slice(0, 10)}.csv`,
      { status, attendance_setting_id: branchId }
    ),
  reject: (id: number | string, notes: string) =>
    apiPost(`/dashboard/receipts/${id}/reject`, { notes }),
  // Fetch image as blob dan convert ke data URL untuk display di <img>
  // Mendukung multi-foto per struk melalui parameter imageId / index
  fetchImageAsDataUrl: async (id: number | string, imageId?: number | string): Promise<string | null> => {
    try {
      const headers: Record<string, string> = { 'X-Platform': 'web' };
      const token = getToken();
      if (token) headers['Authorization'] = `Bearer ${token}`;

      const query = imageId !== undefined ? `?image_id=${imageId}` : '';
      const response = await fetch(`${BASE_URL}/dashboard/receipts/${id}/image${query}`, { headers });
      if (!response.ok) return null;

      const blob = await response.blob();
      return URL.createObjectURL(blob);
    } catch {
      return null;
    }
  },
};

// ─── Expense Reports (Laporan Pengeluaran Dinas / Bundling) ──
export const expenseReportApi = {
  list: (params?: { status?: string; attendance_setting_id?: number | string }, forceRefresh = false) =>
    apiGet('/dashboard/expense-reports', params, { forceRefresh }),
  show: (id: number | string) => apiGet(`/dashboard/expense-reports/${id}`),
  approve: (id: number | string, notes?: string, approvedReceiptIds?: number[]) =>
    apiPost(`/dashboard/expense-reports/${id}/approve`, { notes, approved_receipt_ids: approvedReceiptIds }),
  reject: (id: number | string, notes: string) =>
    apiPost(`/dashboard/expense-reports/${id}/reject`, { notes }),
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
    // Prioritas 1
    emergency_contact_name?: string;
    emergency_contact_relation?: string;
    emergency_contact_phone?: string;
    emergency_contact_address?: string;
    ktp_address?: string;
    ktp_postal_code?: string;
    ktp_city?: string;
    ktp_province?: string;
    domicile_address?: string;
    is_domicile_same_as_ktp?: boolean;
    religion?: string;
    marital_status?: string;
    number_of_dependents?: number;
    blood_type?: string;
    medical_conditions?: string;
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
  supervisors: (params?: { division_id?: number | string; exclude_user_id?: number | string; attendance_setting_id?: number | string }, forceRefresh = false) =>
    apiGet<{
      success: boolean;
      supervisors: Array<{
        id: number;
        name: string;
        email?: string;
        employee_code?: string;
        division_id: number | null;
        division_name?: string;
        position_id: number | null;
        position_name?: string;
        is_supervisor?: boolean;
        attendance_setting_id?: number | null;
        office_name?: string;
        division?: { id: number; name: string };
        position?: { id: number; name: string; is_supervisor: boolean };
      }>;
    }>('/admin/users/supervisors', params as Record<string, string | number>, { forceRefresh }),
  create: (payload: Record<string, unknown>) => apiPost('/admin/users', payload),
  update: (id: number | string, payload: Record<string, unknown>) =>
    apiPut(`/admin/users/${id}`, payload),
  deactivate: (id: number | string, data?: {
    exit_date?: string;
    exit_reason?: string;
    exit_notes?: string;
    severance_status?: string;
    clearance_status?: string;
  }) => apiPatch(`/admin/users/${id}/deactivate`, data),
  activate: (id: number | string) => apiPatch(`/admin/users/${id}/activate`),
  destroy: (id: number | string) => apiDelete<{ message: string }>(`/admin/users/${id}`),
  bulkImport: (payload: BulkImportPayload) =>
    apiPost<BulkImportResponse>('/admin/users/bulk-import', payload),
};

// ─── Divisions, Positions & Job Grades (Struktur Organisasi) ─────────────
export interface DivisionItem {
  id: number;
  company_id: number;
  name: string;
  code: string | null;
  description: string | null;
  is_active: boolean;
  users_count?: number;
  positions_count?: number;
  positions?: PositionItem[];
  created_at: string;
  updated_at: string;
}

export interface PositionItem {
  id: number;
  company_id: number;
  division_id: number | null;
  name: string;
  is_supervisor: boolean;
  description: string | null;
  is_active: boolean;
  division?: { id: number; name: string; code: string | null } | null;
  users_count?: number;
  created_at?: string;
  updated_at?: string;
}

export const divisionApi = {
  list: (params?: { search?: string; is_active?: boolean }, forceRefresh = false) =>
    apiGet<{ success: boolean; data: DivisionItem[]; divisions?: DivisionItem[] }>('/admin/divisions', params as Record<string, string | number | boolean>, { forceRefresh }),
  get: (id: number | string) =>
    apiGet<{ success: boolean; data: DivisionItem }>(`/admin/divisions/${id}`),
  create: (payload: { name: string; code?: string; description?: string; is_active?: boolean }) =>
    apiPost<{ success: boolean; message: string; data: DivisionItem }>('/admin/divisions', payload),
  update: (id: number | string, payload: { name?: string; code?: string; description?: string; is_active?: boolean }) =>
    apiPut<{ success: boolean; message: string; data: DivisionItem }>(`/admin/divisions/${id}`, payload),
  destroy: (id: number | string) =>
    apiDelete<{ success: boolean; message: string }>(`/admin/divisions/${id}`),
};

export const positionApi = {
  list: (params?: { division_id?: number | string; is_supervisor?: boolean; is_active?: boolean; search?: string }, forceRefresh = false) =>
    apiGet<{ success: boolean; data: PositionItem[]; positions?: PositionItem[] }>('/admin/positions', params as Record<string, string | number | boolean>, { forceRefresh }),
  get: (id: number | string) =>
    apiGet<{ success: boolean; data: PositionItem }>(`/admin/positions/${id}`),
  create: (payload: { name: string; division_id?: number | null; is_supervisor?: boolean; description?: string; is_active?: boolean }) =>
    apiPost<{ success: boolean; message: string; data: PositionItem }>('/admin/positions', payload),
  update: (id: number | string, payload: { name?: string; division_id?: number | null; is_supervisor?: boolean; description?: string; is_active?: boolean }) =>
    apiPut<{ success: boolean; message: string; data: PositionItem }>(`/admin/positions/${id}`, payload),
  destroy: (id: number | string) =>
    apiDelete<{ success: boolean; message: string }>(`/admin/positions/${id}`),
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
  toggleAttendance: (id: number | string) =>
    apiPost(`/dashboard/attendance/users/${id}/toggle-attendance`),
  updateMobilePolicy: (
    id: number | string,
    data: { attendance_enabled: boolean; wfh_enabled?: boolean; radius_enabled?: boolean }
  ) => apiPut(`/dashboard/attendance/users/${id}/mobile-policy`, data),
  toggleWfh: (id: number | string) =>
    apiPost(`/dashboard/attendance/users/${id}/toggle-wfh`),
  toggleRadius: (id: number | string) =>
    apiPost(`/dashboard/attendance/users/${id}/toggle-radius`),
  toggleDinasLuar: (id: number | string) =>
    apiPost(`/dashboard/attendance/users/${id}/toggle-dinas-luar`),
  toggleFlexitime: (id: number | string) =>
    apiPost(`/dashboard/attendance/users/${id}/toggle-flexitime`),
  attendancePhotoUrl: async (
    id: number | string,
  ): Promise<string | null> => {
    try {
      const headers: Record<string, string> = { 'X-Platform': 'web' };
      const token = getToken();
      if (token) headers['Authorization'] = `Bearer ${token}`;

      const response = await fetch(`${BASE_URL}/dashboard/attendance/attendances/${id}/photo`, { headers });
      if (!response.ok) return null;

      const blob = await response.blob();
      return URL.createObjectURL(blob);
    } catch {
      return null;
    }
  },

  // Pengajuan izin/cuti
  leaves: (filters?: {
    status?: 'pending' | 'approved' | 'rejected';
    leave_type?: string;
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
    leave_type?: string;
    quota?: number;
    allow_leave?: boolean;
    year?: number;
    balances?: Array<{ leave_type: string; quota: number }>;
  }) => apiPost('/dashboard/attendance/leave-balances', payload),

  // Pengaturan jenis cuti per kantor (toggle on/off, kuota default kantor, & reset tahunan)
  leaveTypeSettings: (officeId?: number | string, forceRefresh = false) =>
    apiGet<LeaveTypeSettingsResponse>('/dashboard/attendance/leave-types', officeId ? { attendance_setting_id: officeId } : undefined, { forceRefresh }),
  updateLeaveTypeSettings: (payload: {
    attendance_setting_id: number;
    default_leave_quota?: number;
    leave_reset_date?: string | null;
    leave_multi_approval_enabled?: boolean;
    settings?: Array<{
      leave_type: string;
      is_enabled: boolean;
      quota_days: number;
      requires_document?: boolean;
      notes?: string | null;
    }>;
  }) => apiPut<{ message: string }>('/dashboard/attendance/leave-types', payload),

  // Laporan presensi — mencakup baris virtual absent/leave
  report: (filters?: {
    start_date?: string;
    end_date?: string;
    department?: string;
    status?: 'present' | 'late' | 'absent' | 'early_leave' | 'cuti' | 'izin' | 'sakit' | 'wfh' | string;
    type?: 'onsite' | 'wfh' | 'field' | string;
    search?: string;
    office_id?: number | string;
    shift_id?: number | string;
    per_page?: number | string;
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
    shift_id?: number | string;
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
  name?: string | null;
  color?: string | null;
  is_off: boolean;
  work_start_time?: string | null;
  work_end_time?: string | null;
  break_minutes?: number;
  late_tolerance_minutes?: number | null;
  is_cross_day?: boolean;
  is_wfh?: boolean;
  is_field?: boolean;
}

export interface ShiftPatternItem {
  id: number;
  shift_pattern_id: number;
  day_order: number;
  shift_id: number | null;
  name?: string | null;
  color?: string | null;
  is_off: boolean;
  work_start_time: string | null;
  work_end_time: string | null;
  break_minutes: number;
  late_tolerance_minutes?: number | null;
  is_cross_day: boolean;
  is_wfh?: boolean;
  is_field?: boolean;
  shift?: {
    id: number;
    name: string;
    color?: string;
  } | null;
}

export interface ShiftPatternDayOverrideInput {
  day_of_week: number; // 0=Minggu, 1=Senin, ..., 6=Sabtu
  work_start_time?: string | null;
  work_end_time?: string | null;
  break_minutes?: number | null;
  late_tolerance_minutes?: number | null;
}

export interface ShiftPatternDayOverride {
  id?: number;
  shift_pattern_id?: number;
  day_of_week: number;
  work_start_time?: string | null;
  work_end_time?: string | null;
  break_minutes?: number | null;
  late_tolerance_minutes?: number | null;
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
  color?: string | null;
  late_tolerance_minutes?: number | null;
  cycle_days: number;
  is_active: boolean;
  active_users_count?: number;
  assigned_count?: number;
  created_at?: string;
  updated_at?: string;
  items: ShiftPatternItem[];
  day_overrides?: ShiftPatternDayOverride[];
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
    late_tolerance_minutes?: number | null;
  }) => apiPost('/dashboard/attendance/shifts', payload),
  update: (
    id: number | string,
    payload: {
      name?: string;
      description?: string;
      attendance_setting_id?: number;
      schedules?: ShiftScheduleInput[];
      color?: string | null;
      late_tolerance_minutes?: number | null;
    },
  ) => apiPut(`/dashboard/attendance/shifts/${id}`, payload),
  toggleActive: (id: number | string) =>
    apiPost(`/dashboard/attendance/shifts/${id}/toggle-active`),
  destroy: (id: number | string) => apiDelete(`/dashboard/attendance/shifts/${id}`),

  // ── Daftar karyawan yang terkait sebuah template shift ──
  users: (id: number | string, forceRefresh = false) =>
    apiGet(`/dashboard/attendance/shifts/${id}/users`, undefined, { forceRefresh }),

  // ── Roster harian (siapa masuk shift apa pada tanggal tertentu) ──
  roster: (
    filters?: {
      date?: string;
      attendance_setting_id?: number;
      search?: string;
      department?: string;
      status?: string;
      shift_name?: string;
      page?: number;
      per_page?: number | string;
    },
    forceRefresh = false,
  ) =>
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
  calendar: (month: number, year: number, attendanceSettingId?: number, department?: string, forceRefresh = false) =>
    apiGet('/dashboard/attendance/shifts/calendar', {
      month,
      year,
      ...(attendanceSettingId ? { attendance_setting_id: attendanceSettingId } : {}),
      ...(department ? { department } : {}),
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
      color?: string | null;
      late_tolerance_minutes?: number | null;
      attendance_setting_id?: number | null;
      cycle_days: number;
      is_active?: boolean;
      items: ShiftPatternItemInput[];
      day_overrides?: ShiftPatternDayOverrideInput[];
    }) => apiPost('/dashboard/attendance/shift-patterns', payload),
    update: (
      id: number | string,
      payload: {
        name?: string;
        description?: string;
        color?: string | null;
        late_tolerance_minutes?: number | null;
        attendance_setting_id?: number | null;
        cycle_days?: number;
        is_active?: boolean;
        items?: ShiftPatternItemInput[];
        day_overrides?: ShiftPatternDayOverrideInput[];
      },
    ) => apiPut(`/dashboard/attendance/shift-patterns/${id}`, payload),
    toggleActive: (id: number | string) =>
      apiPost(`/dashboard/attendance/shift-patterns/${id}/toggle-active`),
    users: (id: number | string, forceRefresh = false) =>
      apiGet(`/dashboard/attendance/shift-patterns/${id}/users`, undefined, { forceRefresh }),
    destroy: (id: number | string) =>
      apiDelete(`/dashboard/attendance/shift-patterns/${id}`),
  },
};

// ─── Overtime approvals — HRD & SPV ─────────────────────────
export const overtimeApi = {
  list: (filters?: {
    status?: 'pending' | 'approved' | 'rejected';
    step?: 'spv' | 'hrd';
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

// ─── Role & Permission Management (Custom Role Engine) ──────
export interface RolePermissionItem {
  id?: number;
  module: string;
  access_level: 'none' | 'read' | 'manage';
}

export interface RoleBranchItem {
  id: number;
  office_name?: string;
}

export interface RoleItem {
  id: number;
  company_id: number | null;
  name: string;
  slug: string;
  description: string | null;
  platform: 'mobile_only' | 'both';
  branch_scope: 'all' | 'specific' | 'self';
  is_builtin: boolean;
  is_active: boolean;
  created_at: string;
  updated_at: string;
  permissions?: RolePermissionItem[];
  branches?: RoleBranchItem[];
  users_count?: number;
}

export interface RoleModuleOption {
  key: string;
  label: string;
  description?: string;
}

export const roleApi = {
  list: (params?: { search?: string; platform?: string; is_active?: boolean }, forceRefresh = false) =>
    apiGet<{ success: boolean; data: RoleItem[] }>('/admin/roles', params, { forceRefresh }),

  modules: () =>
    apiGet<{
      success: boolean;
      modules: Record<string, string>;
      access_levels: Record<string, string>;
      platforms: Record<string, string>;
      branch_scopes: Record<string, string>;
    }>('/admin/roles/modules'),

  get: (id: number | string) =>
    apiGet<{ success: boolean; data: RoleItem }>(`/admin/roles/${id}`),

  create: (payload: {
    name: string;
    description?: string;
    platform: 'mobile_only' | 'both';
    branch_scope: 'all' | 'specific' | 'self';
    branch_ids?: number[];
    permissions: Record<string, 'none' | 'read' | 'manage'> | { module: string; access_level: 'none' | 'read' | 'manage' }[];
  }) => apiPost<{ success: boolean; message: string; data: RoleItem }>('/admin/roles', payload),

  update: (
    id: number | string,
    payload: {
      name?: string;
      description?: string;
      platform?: 'mobile_only' | 'both';
      branch_scope?: 'all' | 'specific' | 'self';
      branch_ids?: number[];
      is_active?: boolean;
      permissions?: Record<string, 'none' | 'read' | 'manage'> | { module: string; access_level: 'none' | 'read' | 'manage' }[];
    }
  ) => apiPut<{ success: boolean; message: string; data: RoleItem }>(`/admin/roles/${id}`, payload),

  destroy: (id: number | string) =>
    apiDelete<{ success: boolean; message: string }>(`/admin/roles/${id}`),
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
    apiGet<{ settings: any; branch_settings?: any[]; receipt_approval_rules?: any }>('/dashboard/settings', undefined, { forceRefresh }),
  clearCache: () => {
    invalidateCache('/dashboard/settings');
  },
  update: (payload: Record<string, any>) => apiPut<{ settings: any; branch_settings?: any[]; receipt_approval_rules?: any }>('/dashboard/settings', payload),
  updateBranch: (branchId: number | string, payload: {
    variance_limit: number | null;
    max_claim_limit: number | null;
  }) => apiPut<{ message: string; branch: any }>(`/dashboard/settings/branches/${branchId}`, payload),
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

// ─── Data Version Sync (Ketentuan 3) ────────────────────────
export const syncApi = {
  getVersions: () =>
    apiGet<Record<string, string>>(
      '/dashboard/sync-versions',
      undefined,
      { cache: false },
    ),
};

// ─── Berkas Digital Karyawan (User Documents) ────────────────
export interface UserDocument {
  id: number;
  company_id: number;
  user_id: number;
  document_type: 'ktp' | 'kartu_keluarga' | 'npwp' | 'buku_tabungan' | 'kontrak_kerja' | 'ijazah' | 'sertifikat' | 'lainnya';
  title: string | null;
  file_path: string;
  file_name: string;
  file_size: number;
  file_size_formatted?: string;
  mime_type: string;
  is_pdf?: boolean;
  is_image?: boolean;
  uploaded_by?: number | null;
  uploader?: {
    id: number;
    name: string;
    role: string;
  } | null;
  notes?: string | null;
  created_at: string;
  updated_at: string;
}

export interface UserDocumentsResponse {
  success: boolean;
  user: {
    id: number;
    name: string;
    employee_code: string;
  };
  documents: UserDocument[];
}

export const userDocumentApi = {
  list: (userId: number | string) =>
    apiGet<UserDocumentsResponse>(`/admin/users/${userId}/documents`, undefined, { cache: false }),

  upload: (userId: number | string, formData: FormData) =>
    apiUpload<{ success: boolean; message: string; document: UserDocument }>(`/admin/users/${userId}/documents`, formData),

  delete: (userId: number | string, documentId: number | string) =>
    apiDelete<{ success: boolean; message: string }>(`/admin/users/${userId}/documents/${documentId}`),

  download: (userId: number | string, documentId: number | string, fileName: string) =>
    apiDownload(`/admin/users/${userId}/documents/${documentId}/download`, fileName),

  streamUrl: (userId: number | string, documentId: number | string) =>
    `${BASE_URL}/admin/users/${userId}/documents/${documentId}/stream`,

  viewFile: async (userId: number | string, documentId: number | string, title?: string) =>
    apiViewFile(`/admin/users/${userId}/documents/${documentId}/stream`, title || 'Dokumen Karyawan'),
};

// ─── Payroll (Penggajian) — Finance/HRD dashboard ───────────
// Semua endpoint di bawah /dashboard/payroll/* butuh izin modul Payroll.
// Data gaji/PPh21/NPWP bersifat sensitif → GET memakai { cache: false } agar
// selalu segar (modul admin trafik rendah, kesegaran > kecepatan cache).
export type PayrollStatus = 'draft' | 'calculated' | 'submitted' | 'approved' | 'paid' | 'rejected';

import type {
  BpjsProfile,
  PayrollAdjustment,
  EmployeeBankAccount,
  RunLogsResponse,
  PinStatus,
  PayrollPaymentBatch,
  PaymentBatchesResponse,
  PaymentBatchGeneratePayload,
  PaymentBatchReconcilePayload,
  GlAccount,
  GlAccountsResponse,
  GlJournalResponse,
  TaxCertificate1721A1,
  TaxCertificate1721A1Detail,
  PayslipTraceResponse,
  PayrollGroup,
  CompanyTaxProfile,
  LeaveTypeSettingsResponse,
  JobLevel,
  SalaryGrade,
  CurrencyRate,
  SeveranceCase,
  SeverancePreview,
  EbupotSchemaStatus,
  TaxProfile,
} from '../types';

export const payrollApi = {
  // ── Komponen gaji (master per perusahaan) ──
  listComponents: () =>
    apiGet<{ data: any[] }>('/dashboard/payroll/components', undefined, { cache: false }),

  createComponent: (payload: {
    code: string;
    name: string;
    type: 'earning' | 'deduction';
    calc_type?: 'fixed' | 'manual' | 'auto';
    category?: string;
    is_taxable?: boolean;
    is_active?: boolean;
    sort_order?: number;
  }) => apiPost<{ message: string; data: any }>('/dashboard/payroll/components', payload),

  updateComponent: (id: number | string, payload: Record<string, any>) =>
    apiPut<{ message: string; data: any }>(`/dashboard/payroll/components/${id}`, payload),

  deleteComponent: (id: number | string) =>
    apiDelete<{ message: string }>(`/dashboard/payroll/components/${id}`),

  // ── Gaji karyawan (gaji pokok efektif + tunjangan tetap + profil pajak + BPJS) ──
  listSalaries: () =>
    apiGet<{ data: any[] }>('/dashboard/payroll/salaries', undefined, { cache: false }),

  getSalary: (userId: number | string) =>
    apiGet<{ data: any }>(`/dashboard/payroll/salaries/${userId}`, undefined, { cache: false }),

  saveSalary: (
    userId: number | string,
    payload: {
      basic_salary: number;
      effective_date: string;
      notes?: string;
      salary_grade_id?: number | null;
      job_level_id?: number | null;
      currency?: string;
    }
  ) => apiPost<{ message: string; data: any }>(`/dashboard/payroll/salaries/${userId}`, payload),

  saveSalaryComponent: (
    userId: number | string,
    payload: { salary_component_id: number; amount: number; effective_date: string },
  ) => apiPost<{ message: string; data: any }>(`/dashboard/payroll/salaries/${userId}/components`, payload),

  deleteSalaryComponent: (componentId: number | string) =>
    apiDelete<{ message: string }>(`/dashboard/payroll/salary-components/${componentId}`),

  saveTaxProfile: (
    userId: number | string,
    payload: {
      ptkp_status: string;
      tax_method?: 'gross' | 'gross_up';
      npwp?: string;
      tax_subject_type?: 'domestic' | 'foreign';
      treaty_country?: string | null;
      treaty_rate?: number | null;
      foreign_tax_id?: string | null;
    },
  ) => apiPut<{ message: string; data: any }>(`/dashboard/payroll/salaries/${userId}/tax-profile`, payload),

  // Fase 2: Simpan profil kepesertaan BPJS
  saveBpjsProfile: (
    userId: number | string,
    payload: {
      has_bpjs_kes: boolean;
      has_bpjs_tk: boolean;
      has_jkp?: boolean;
      jkk_risk_class?: number;
      bpjs_kes_no?: string;
      bpjs_tk_no?: string;
    },
  ) => apiPut<{ message: string; data: BpjsProfile }>(`/dashboard/payroll/salaries/${userId}/bpjs-profile`, payload),

  // ── Batch payroll (run) — draft → calculated → submitted → approved → paid ──
  listRuns: () =>
    apiGet<{ data: any[] }>('/dashboard/payroll/runs', undefined, { cache: false }),

  createRun: (payload: {
    period_month: number;
    period_year: number;
    attendance_setting_id?: number | null;
    run_type?: 'regular' | 'thr' | 'severance';
    payroll_group_id?: number | null;
    is_year_end?: boolean;
    notes?: string;
  }) => apiPost<{ message: string; data: any }>('/dashboard/payroll/runs', payload),

  getRun: (id: number | string) =>
    apiGet<{ data: any }>(`/dashboard/payroll/runs/${id}`, undefined, { cache: false }),

  calculateRun: (
    id: number | string,
    options?: {
      pph21?: boolean;
      bpjs?: boolean;
      overtime?: boolean;
      attendance_deduction?: boolean;
      receipt_reimbursement?: boolean;
      loan_installment?: boolean;
      working_days_divisor?: number;
    },
  ) => apiPost<{ message: string; data: any }>(`/dashboard/payroll/runs/${id}/calculate`, options ?? {}),

  submitRun: (id: number | string) =>
    apiPost<{ message: string; data: any }>(`/dashboard/payroll/runs/${id}/submit`),

  approveRun: (id: number | string, pin?: string) =>
    apiPost<{ message: string; data: any }>(`/dashboard/payroll/runs/${id}/approve`, pin ? { pin } : {}),

  rejectRun: (id: number | string, reason: string) =>
    apiPost<{ message: string; data: any }>(`/dashboard/payroll/runs/${id}/reject`, { reason }),

  markPaid: (id: number | string, pin?: string) =>
    apiPost<{ message: string; data: any }>(`/dashboard/payroll/runs/${id}/mark-paid`, pin ? { pin } : {}),

  deleteRun: (id: number | string) =>
    apiDelete<{ message: string }>(`/dashboard/payroll/runs/${id}`),

  // Fase 3 Modul C: Jejak audit tamper-evident hash-chain
  getRunLogs: (id: number | string) =>
    apiGet<{ data: RunLogsResponse }>(`/dashboard/payroll/runs/${id}/logs`, undefined, { cache: false }),

  // ── Fase 4+: Grup Payroll (CRUD + scoping run) ──
  listGroups: () =>
    apiGet<{ data: PayrollGroup[] }>('/dashboard/payroll/groups', undefined, { cache: false }),

  createGroup: (payload: { name: string; code?: string; description?: string; is_active?: boolean }) =>
    apiPost<{ message: string; data: PayrollGroup }>('/dashboard/payroll/groups', payload),

  updateGroup: (id: number | string, payload: { name: string; code?: string; description?: string; is_active?: boolean }) =>
    apiPut<{ message: string; data: PayrollGroup }>(`/dashboard/payroll/groups/${id}`, payload),

  deleteGroup: (id: number | string) =>
    apiDelete<{ message: string }>(`/dashboard/payroll/groups/${id}`),

  // ── Fase 4+: Profil Pajak Perusahaan (NPWP pemberi kerja) ──
  getCompanyTaxProfile: () =>
    apiGet<{ data: CompanyTaxProfile }>('/dashboard/payroll/company-tax-profile', undefined, { cache: false }),

  saveCompanyTaxProfile: (payload: { npwp?: string }) =>
    apiPut<{ message: string; data: CompanyTaxProfile }>('/dashboard/payroll/company-tax-profile', payload),

  // ── Fase 3 Modul A: Penyesuaian & Koreksi Retroaktif (Adjustments) ──
  listAdjustments: (filters?: { status?: string; user_id?: number | string; payroll_id?: number | string }) =>
    apiGet<{ data: PayrollAdjustment[] }>('/dashboard/payroll/adjustments', filters as Record<string, string | number>, { cache: false }),

  createAdjustment: (payload: {
    user_id: number | string;
    type: 'earning' | 'deduction';
    name: string;
    amount: number;
    is_taxable?: boolean;
    reason?: string;
    source_document_path?: string | null;
    retroactive_payroll_id?: number | null;
  }) => apiPost<{ message: string; data: PayrollAdjustment }>('/dashboard/payroll/adjustments', payload),

  approveAdjustment: (id: number | string) =>
    apiPost<{ message: string; data: PayrollAdjustment }>(`/dashboard/payroll/adjustments/${id}/approve`),

  voidAdjustment: (id: number | string, reason: string) =>
    apiPost<{ message: string; data: PayrollAdjustment }>(`/dashboard/payroll/adjustments/${id}/void`, { reason }),

  // ── Fase 3 Modul B: Rekening Bank Karyawan (Proteksi & Maker-Checker) ──
  listBankAccounts: (userId: number | string) =>
    apiGet<{ data: EmployeeBankAccount[] }>(`/dashboard/payroll/employees/${userId}/bank-accounts`, undefined, { cache: false }),

  submitBankAccount: (
    userId: number | string,
    payload: {
      bank_name: string;
      bank_account_no: string;
      bank_account_holder: string;
      bank_branch?: string | null;
      swift_code?: string | null;
      notes?: string | null;
    },
  ) => apiPost<{ message: string; data: EmployeeBankAccount }>(`/dashboard/payroll/employees/${userId}/bank-account`, payload),

  verifyBankAccount: (id: number | string) =>
    apiPost<{ message: string; data: EmployeeBankAccount }>(`/dashboard/payroll/bank-accounts/${id}/verify`),

  rejectBankAccount: (id: number | string, reason: string) =>
    apiPost<{ message: string; data: EmployeeBankAccount }>(`/dashboard/payroll/bank-accounts/${id}/reject`, { reason }),

  // ── Fase 3 Modul D: Step-up PIN Keamanan ──
  getPinStatus: () =>
    apiGet<{ data: PinStatus }>('/dashboard/payroll/security/pin', undefined, { cache: false }),

  setPin: (payload: {
    current_password: string;
    current_pin?: string;
    pin: string;
    pin_confirmation: string;
  }) => apiPost<{ message: string; data: { has_pin: boolean } }>('/dashboard/payroll/security/pin', payload),

  // ── Slip gaji (sisi dashboard) ──
  listPayslips: (filters?: { payroll_id?: number | string; user_id?: number | string }) =>
    apiGet<{ data: any[] }>('/dashboard/payroll/payslips', filters as Record<string, string | number>, { cache: false }),

  getPayslip: (id: number | string) =>
    apiGet<{ data: { payslip: any; period: string; earnings: any[]; deductions: any[]; calculation_steps?: any[] } }>(
      `/dashboard/payroll/payslips/${id}`,
      undefined,
      { cache: false },
    ),

  downloadPayslipPdf: (id: number | string, filename?: string) =>
    apiDownload(`/dashboard/payroll/payslips/${id}/pdf`, filename ?? `slip-gaji-${id}.pdf`),

  viewPayslipPdf: (id: number | string, title?: string) =>
    apiViewFile(`/dashboard/payroll/payslips/${id}/pdf`, title ?? 'Slip Gaji'),

  // ── Kasbon / pinjaman karyawan (cicilan otomatis dipotong saat mark-paid) ──
  listLoans: (filters?: { user_id?: number | string; status?: string }) =>
    apiGet<{ data: any[] }>('/dashboard/payroll/loans', filters as Record<string, string | number>, { cache: false }),

  createLoan: (payload: {
    user_id: number;
    title: string;
    principal: number;
    installment_amount: number;
    tenor_months: number;
    start_period_month: number;
    start_period_year: number;
    notes?: string;
  }) => apiPost<{ message: string; data: any }>('/dashboard/payroll/loans', payload),

  approveLoan: (id: number | string) =>
    apiPost<{ message: string; data: any }>(`/dashboard/payroll/loans/${id}/approve`),

  cancelLoan: (id: number | string) =>
    apiPost<{ message: string; data: any }>(`/dashboard/payroll/loans/${id}/cancel`),

  // ── Disbursement Bank (Fase 4, Spec §21) ──────────────────────────────────
  listPaymentBatches: (payrollId: number | string) =>
    apiGet<PaymentBatchesResponse>(
      `/dashboard/payroll/runs/${payrollId}/payment-batches`,
      undefined,
      { cache: false }
    ),

  getPaymentBatch: (batchId: number | string) =>
    apiGet<{ data: PayrollPaymentBatch }>(
      `/dashboard/payroll/payment-batches/${batchId}`,
      undefined,
      { cache: false }
    ),

  generatePaymentBatch: (payrollId: number | string, payload: PaymentBatchGeneratePayload) =>
    apiPost<{ message: string; skipped_no_bank: number; data: PayrollPaymentBatch }>(
      `/dashboard/payroll/runs/${payrollId}/payment-batches`,
      payload
    ),

  downloadPaymentBatchFile: (batchId: number | string, filename?: string) =>
    apiDownload(
      `/dashboard/payroll/payment-batches/${batchId}/download`,
      filename ?? `transfer-batch-${batchId}.txt`
    ),

  reconcilePaymentBatch: (batchId: number | string, payload: PaymentBatchReconcilePayload) =>
    apiPost<{ message: string; data?: PayrollPaymentBatch }>(
      `/dashboard/payroll/payment-batches/${batchId}/reconcile`,
      payload
    ),

  // ── General Ledger / Jurnal Akuntansi (Fase 4 lanjutan, Spec §22.1 - 22.2) ──
  listGlAccounts: () =>
    apiGet<GlAccountsResponse>('/dashboard/payroll/gl-accounts', undefined, { cache: false }),

  saveGlAccounts: (accounts: Array<{ key: string; account_code: string; account_name: string }>) =>
    apiPut<{ message: string; data: GlAccount[] }>('/dashboard/payroll/gl-accounts', { accounts }),

  resetGlAccounts: () =>
    apiPost<{ message: string; data: GlAccount[] }>('/dashboard/payroll/gl-accounts/reset'),

  previewGlJournal: (payrollId: number | string, groupBy?: 'none' | 'division' | 'branch') =>
    apiGet<{ data: GlJournalResponse } | GlJournalResponse>(
      `/dashboard/payroll/runs/${payrollId}/gl-preview`,
      groupBy && groupBy !== 'none' ? { group_by: groupBy } : undefined,
      { cache: false }
    ),

  exportGlJournal: (
    payrollId: number | string,
    format: 'csv' | 'json' = 'csv',
    groupBy?: 'none' | 'division' | 'branch'
  ) =>
    apiDownload(
      `/dashboard/payroll/runs/${payrollId}/gl-export`,
      `jurnal-payroll-${payrollId}.${format}`,
      { format, ...(groupBy && groupBy !== 'none' ? { group_by: groupBy } : {}) }
    ),

  // ── Pajak 1721-A1 & Coretax (Fase 4 lanjutan, Spec §22.3) ───────────────────
  listTax1721A1: (taxYear?: number) =>
    apiGet<{ tax_year: number; data: TaxCertificate1721A1[] }>(
      '/dashboard/payroll/tax/1721a1',
      taxYear ? { tax_year: taxYear } : undefined,
      { cache: false }
    ),

  getTax1721A1: (userId: number | string, taxYear?: number) =>
    apiGet<{ tax_year: number; data: TaxCertificate1721A1Detail }>(
      `/dashboard/payroll/tax/1721a1/${userId}`,
      taxYear ? { tax_year: taxYear } : undefined,
      { cache: false }
    ),

  downloadTax1721A1Pdf: (userId: number | string, taxYear?: number, filename?: string) =>
    apiDownload(
      `/dashboard/payroll/tax/1721a1/${userId}/pdf`,
      filename ?? `1721A1-${userId}-${taxYear ?? new Date().getFullYear()}.pdf`,
      taxYear ? { tax_year: taxYear } : undefined
    ),

  exportTax1721A1: (taxYear?: number, format: 'csv' | 'json' = 'csv') =>
    apiDownload(
      '/dashboard/payroll/tax/1721a1/export',
      `1721A1-rekap-${taxYear ?? new Date().getFullYear()}.${format}`,
      { format, ...(taxYear ? { tax_year: taxYear } : {}) }
    ),

  // Fase 4+: e-Bupot 21/26 DRAF XML (non-resmi, belum tervalidasi XSD DJP)
  exportEbupotDraft: (taxYear?: number) =>
    apiDownload(
      '/dashboard/payroll/tax/ebupot/export',
      `ebupot-draf-${taxYear ?? new Date().getFullYear()}.xml`,
      taxYear ? { tax_year: taxYear } : undefined
    ),

  // ── Fase 6: e-Bupot XML Tervalidasi Skema XSD ──
  getEbupotSchemaStatus: () =>
    apiGet<{ data: EbupotSchemaStatus }>('/dashboard/payroll/tax/ebupot/schema', undefined, { cache: false }),

  exportEbupotXml: (taxYear?: number) =>
    apiDownload(
      '/dashboard/payroll/tax/ebupot/export',
      `ebupot-${taxYear ?? new Date().getFullYear()}.xml`,
      taxYear ? { tax_year: taxYear } : undefined
    ),

  // ── Calculation Trace (Fase 4 lanjutan, Spec §22.3) ─────────────────────────
  getPayslipTrace: (payslipId: number | string) =>
    apiGet<PayslipTraceResponse>(
      `/dashboard/payroll/payslips/${payslipId}/calculation-trace`,
      undefined,
      { cache: false }
    ),

  // ── Fase 6: Struktur & Skala Upah (Jenjang Jabatan & Golongan Upah) ──
  listJobLevels: () =>
    apiGet<{ data: JobLevel[] }>('/dashboard/payroll/job-levels', undefined, { cache: false }),

  createJobLevel: (payload: {
    name: string;
    code?: string;
    rank?: number;
    description?: string;
    is_active?: boolean;
  }) => apiPost<{ message: string; data: JobLevel }>('/dashboard/payroll/job-levels', payload),

  updateJobLevel: (
    id: number | string,
    payload: {
      name: string;
      code?: string;
      rank?: number;
      description?: string;
      is_active?: boolean;
    }
  ) => apiPut<{ message: string; data: JobLevel }>(`/dashboard/payroll/job-levels/${id}`, payload),

  deleteJobLevel: (id: number | string) =>
    apiDelete<{ message: string }>(`/dashboard/payroll/job-levels/${id}`),

  listSalaryGrades: (params?: { job_level_id?: number | string }) =>
    apiGet<{ data: SalaryGrade[] }>('/dashboard/payroll/salary-grades', params as Record<string, string | number>, { cache: false }),

  createSalaryGrade: (payload: {
    name: string;
    code?: string;
    job_level_id?: number | null;
    min_salary: number;
    mid_salary?: number | null;
    max_salary: number;
    currency?: string;
    description?: string;
    is_active?: boolean;
  }) => apiPost<{ message: string; data: SalaryGrade }>('/dashboard/payroll/salary-grades', payload),

  updateSalaryGrade: (
    id: number | string,
    payload: {
      name: string;
      code?: string;
      job_level_id?: number | null;
      min_salary: number;
      mid_salary?: number | null;
      max_salary: number;
      currency?: string;
      description?: string;
      is_active?: boolean;
    }
  ) => apiPut<{ message: string; data: SalaryGrade }>(`/dashboard/payroll/salary-grades/${id}`, payload),

  deleteSalaryGrade: (id: number | string) =>
    apiDelete<{ message: string }>(`/dashboard/payroll/salary-grades/${id}`),

  // ── Fase 6: Master Kurs Valuta Asing (Effective-Dated) ──
  listCurrencyRates: (params?: { currency?: string }) =>
    apiGet<{ data: CurrencyRate[] }>('/dashboard/payroll/currency-rates', params as Record<string, string>, { cache: false }),

  createCurrencyRate: (payload: {
    currency: string;
    rate_to_idr: number | string;
    effective_date: string;
    source?: string;
  }) => apiPost<{ message: string; data: CurrencyRate }>('/dashboard/payroll/currency-rates', payload),

  updateCurrencyRate: (
    id: number | string,
    payload: {
      rate_to_idr: number | string;
      source?: string;
    }
  ) => apiPut<{ message: string; data: CurrencyRate }>(`/dashboard/payroll/currency-rates/${id}`, payload),

  deleteCurrencyRate: (id: number | string) =>
    apiDelete<{ message: string }>(`/dashboard/payroll/currency-rates/${id}`),

  // ── Fase 6: Exit Settlement / Berkas Pesangon (PP 35/2021) ──
  listSeveranceCases: (params?: { payroll_id?: number | string; status?: string; termination_type?: string }) =>
    apiGet<{ data: SeveranceCase[] }>('/dashboard/payroll/severance-cases', params as Record<string, string | number>, { cache: false }),

  getSeveranceCase: (id: number | string) =>
    apiGet<{ data: SeveranceCase }>(`/dashboard/payroll/severance-cases/${id}`, undefined, { cache: false }),

  previewSeveranceCase: (id: number | string) =>
    apiGet<{ data: SeverancePreview }>(`/dashboard/payroll/severance-cases/${id}/preview`, undefined, { cache: false }),

  createSeveranceCase: (payload: Partial<SeveranceCase>) =>
    apiPost<{ message: string; data: SeveranceCase }>('/dashboard/payroll/severance-cases', payload),

  updateSeveranceCase: (id: number | string, payload: Partial<SeveranceCase>) =>
    apiPut<{ message: string; data: SeveranceCase }>(`/dashboard/payroll/severance-cases/${id}`, payload),

  deleteSeveranceCase: (id: number | string) =>
    apiDelete<{ message: string }>(`/dashboard/payroll/severance-cases/${id}`),
};






