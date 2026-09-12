// Konversi bentuk data backend (snake_case) → tipe frontend (types.ts).

import {
  Receipt,
  ReceiptStatus,
  StrukApproval,
  Invoice,
  InvoiceStatus,
  InvoiceSource,
  AuditLog,
  NotificationItem,
  AppSettings,
  ExpenseReport,
  ExpenseReportStatus,
} from '../types';
import { receiptApi } from './endpoints';

// ─── Util tampilan ──────────────────────────────────────────
const AVATAR_COLORS = [
  { bg: 'bg-rose-100', color: 'text-rose-700' },
  { bg: 'bg-amber-100', color: 'text-amber-700' },
  { bg: 'bg-emerald-100', color: 'text-emerald-700' },
  { bg: 'bg-blue-100', color: 'text-blue-700' },
  { bg: 'bg-indigo-100', color: 'text-indigo-700' },
  { bg: 'bg-purple-100', color: 'text-purple-700' },
];

export function initialsOf(name: string): string {
  if (!name) return '?';
  const parts = name.trim().split(/\s+/);
  if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
  return (parts[0][0] + parts[1][0]).toUpperCase();
}

function avatarFor(seed: string) {
  let hash = 0;
  for (let i = 0; i < seed.length; i++) hash = seed.charCodeAt(i) + ((hash << 5) - hash);
  return AVATAR_COLORS[Math.abs(hash) % AVATAR_COLORS.length];
}

export function formatTanggal(value?: string | null): string {
  if (!value) return '—';
  const d = new Date(value);
  if (isNaN(d.getTime())) return value;
  return d.toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' });
}

export function formatWaktu(value?: string | null): string {
  if (!value) return '—';
  const d = new Date(value);
  if (isNaN(d.getTime())) return value;
  return d.toLocaleString('id-ID', {
    day: 'numeric',
    month: 'short',
    hour: '2-digit',
    minute: '2-digit',
  });
}

const num = (v: unknown): number => {
  const n = typeof v === 'string' ? parseFloat(v) : (v as number);
  return Number.isFinite(n) ? n : 0;
};

// ─── Receipt (struk) — status backend → label frontend ──────
function mapReceiptStatus(status: string, varianceFlag?: boolean): ReceiptStatus {
  switch (status) {
    case 'paid':
      return 'Dibayar';
    case 'approved':
      return 'Disetujui';
    case 'rejected':
      return 'Ditolak';
    case 'submitted':
    case 'pending':
      return varianceFlag ? 'Review' : 'Pending';
    default:
      return 'Pending';
  }
}

export function parseReceiptItems(raw: any) {
  if (!raw) return [];
  let list = raw;
  if (typeof raw === 'string') {
    try {
      list = JSON.parse(raw);
    } catch {
      return [];
    }
  }
  if (!Array.isArray(list)) return [];
  return list.map((it: any) => ({
    name: String(it.name || ''),
    qty: num(it.qty) || 1,
    price: num(it.price) || 0,
    total: num(it.total) || (num(it.qty || 1) * num(it.price || 0)),
  }));
}

export function mapReceipt(r: any): Receipt {
  const karyawan = r.user?.name ?? r.vendor_name ?? 'Tanpa Nama';
  const av = avatarFor(karyawan);
  const ocr = num(r.ocr_raw_amount ?? r.total_amount);
  const klaim = num(r.claimed_amount ?? r.total_amount ?? r.ocr_raw_amount);
  const approvedAmount = r.approved_amount !== null && r.approved_amount !== undefined ? num(r.approved_amount) : undefined;
  const items = parseReceiptItems(r.ocr_raw_items);
  const subtotal = r.ocr_raw_subtotal !== null && r.ocr_raw_subtotal !== undefined ? num(r.ocr_raw_subtotal) : undefined;
  const tax = r.ocr_raw_tax !== null && r.ocr_raw_tax !== undefined ? num(r.ocr_raw_tax) : undefined;
  const discount = r.ocr_raw_discount !== null && r.ocr_raw_discount !== undefined ? num(r.ocr_raw_discount) : undefined;

  const hasDiff = ocr > 0 && Math.abs(klaim - ocr) > 0.01;
  const isVariance = Boolean(r.variance_flag);
  const variancePct = r.variance_pct !== null && r.variance_pct !== undefined
    ? num(r.variance_pct)
    : (ocr > 0 ? Math.round((Math.abs(klaim - ocr) / ocr) * 10000) / 100 : undefined);

  return {
    id: String(r.id),
    karyawan,
    initials: initialsOf(karyawan),
    avatarBg: av.bg,
    avatarColor: av.color,
    merchant: r.vendor_name ?? r.ocr_raw_merchant ?? '—',
    ocrNominal: ocr,
    klaim,
    approvedAmount,
    kategori: r.category ?? '—',
    status: mapReceiptStatus(r.status, isVariance),
    varianceFlag: isVariance,
    variancePct,
    tanggal: formatTanggal(r.receipt_date ?? r.submitted_at ?? r.created_at),
    departemen: r.user?.department ?? '—',
    cabang: r.office?.office_name ?? r.user?.office?.office_name ?? '—',
    cabangId: r.attendance_setting_id ? Number(r.attendance_setting_id) : (r.user?.attendance_setting_id ? Number(r.user.attendance_setting_id) : undefined),
    imageUrl: undefined, // Will be loaded asynchronously in component
    items: items.length > 0 ? items : undefined,
    subtotal,
    tax,
    discount,
    isPotentialDuplicate: Boolean(r.is_potential_duplicate),
    duplicateReceiptNumber: r.duplicate_reference?.receipt_number,
    duplicateTotalAmount: (() => {
      const d = r.duplicate_reference;
      if (!d) return undefined;
      const v = d.total_amount ?? d.claimed_amount ?? d.ocr_raw_amount ?? d.approved_amount;
      return v !== null && v !== undefined ? num(v) : undefined;
    })(),
    duplicateReason: r.duplicate_reason,
    duplicateReferenceId: r.duplicate_reference_id ? Number(r.duplicate_reference_id) : undefined,
    duplicateReference: r.duplicate_reference ? {
      id: Number(r.duplicate_reference.id),
      receiptNumber: r.duplicate_reference.receipt_number,
      totalAmount: (() => {
        const d = r.duplicate_reference;
        const v = d.total_amount ?? d.claimed_amount ?? d.ocr_raw_amount ?? d.approved_amount;
        return v !== null && v !== undefined ? num(v) : klaim;
      })(),
      receiptDate: formatTanggal(r.duplicate_reference.receipt_date ?? r.duplicate_reference.submitted_at ?? r.duplicate_reference.created_at),
      imagePath: r.duplicate_reference.image_path,
      uploaderName: r.duplicate_reference.user?.name,
      department: r.duplicate_reference.user?.department,
    } : undefined,
    notes: r.notes,
    paidAt: r.paid_at ? formatTanggal(r.paid_at) : undefined,
    paidBy: r.paid_by?.name ?? r.paidBy?.name,
    paymentMethod: r.payment_method,
    paymentRefNo: r.payment_ref_no,
    bankName: r.user?.bank_name,
    bankAccountNo: r.user?.bank_account_no,
    bankAccountHolder: r.user?.bank_account_holder,
    images: Array.isArray(r.images) ? r.images.map((img: any) => ({
      id: Number(img.id),
      receipt_id: img.receipt_id ? Number(img.receipt_id) : undefined,
      file_path: String(img.file_path || ''),
      file_name: String(img.file_name || ''),
      file_size: img.file_size ? Number(img.file_size) : undefined,
      mime_type: img.mime_type ? String(img.mime_type) : undefined,
    })) : undefined,
    expenseReportId: r.expense_report_id ? Number(r.expense_report_id) : null,
    expenseReport: r.expense_report ? {
      id: Number(r.expense_report.id),
      report_number: String(r.expense_report.report_number || ''),
      title: String(r.expense_report.title || ''),
      status: String(r.expense_report.status || ''),
    } : (r.expenseReport ? {
      id: Number(r.expenseReport.id),
      report_number: String(r.expenseReport.report_number || ''),
      title: String(r.expenseReport.title || ''),
      status: String(r.expenseReport.status || ''),
    } : null),
  };
}

// Riwayat approval struk (struk approved/paid/rejected) → StrukApproval
export function mapReceiptToApproval(r: any): StrukApproval {
  // Ambil nama approver, fallback ke "Finance"
  const approverName = r.approvals?.[0]?.user?.name ?? r.approved_by?.name ?? 'Finance';

  // Extract tanggal YYYY-MM-DD dari timestamp untuk filtering
  const dateStr = (r.submitted_at ?? r.created_at ?? '').substring(0, 10);
  const items = parseReceiptItems(r.ocr_raw_items);
  const subtotal = r.ocr_raw_subtotal !== null && r.ocr_raw_subtotal !== undefined ? num(r.ocr_raw_subtotal) : undefined;
  const tax = r.ocr_raw_tax !== null && r.ocr_raw_tax !== undefined ? num(r.ocr_raw_tax) : undefined;
  const discount = r.ocr_raw_discount !== null && r.ocr_raw_discount !== undefined ? num(r.ocr_raw_discount) : undefined;
  const ocrNominal = r.ocr_raw_amount !== null && r.ocr_raw_amount !== undefined ? num(r.ocr_raw_amount) : undefined;
  const approvedAmount = r.approved_amount !== null && r.approved_amount !== undefined ? num(r.approved_amount) : undefined;

  let keputusan: 'Disetujui' | 'Dibayar' | 'Ditolak' = 'Ditolak';
  if (r.status === 'paid') {
    keputusan = 'Dibayar';
  } else if (r.status === 'approved') {
    keputusan = 'Disetujui';
  }

  return {
    id: String(r.id),
    karyawan: r.user?.name ?? '—',
    merchant: (r.vendor_name && r.vendor_name.trim() !== '') ? r.vendor_name : (r.ocr_raw_merchant ?? '—'),
    nominal: num(r.claimed_amount ?? r.total_amount),
    approvedAmount,
    keputusan,
    diprosesOleh: approverName,
    waktu: formatWaktu(r.submitted_at ?? r.created_at),
    catatan: r.approvals?.[0]?.notes ?? r.rejection_reason ?? '—',
    tanggal: dateStr,
    cabang: r.office?.office_name ?? r.user?.office?.office_name ?? '—',
    cabangId: r.attendance_setting_id ? Number(r.attendance_setting_id) : (r.user?.attendance_setting_id ? Number(r.user.attendance_setting_id) : undefined),
    branchVarianceLimit: (r.office?.variance_limit !== null && r.office?.variance_limit !== undefined)
      ? Number(r.office.variance_limit)
      : (r.user?.office?.variance_limit !== null && r.user?.office?.variance_limit !== undefined
        ? Number(r.user.office.variance_limit)
        : null),
    branchMaxClaimLimit: (r.office?.max_claim_limit !== null && r.office?.max_claim_limit !== undefined)
      ? Number(r.office.max_claim_limit)
      : (r.user?.office?.max_claim_limit !== null && r.user?.office?.max_claim_limit !== undefined
        ? Number(r.user.office.max_claim_limit)
        : null),
    // Tambah detail approver dari response baru
    approvedBy: r.approved_by && {
      id: r.approved_by.id,
      name: r.approved_by.name,
      email: r.approved_by.email,
      role: r.approved_by.role,
    },
    approvedAt: r.approved_at,
    items: items.length > 0 ? items : undefined,
    subtotal,
    tax,
    discount,
    ocrNominal,
    kategori: r.category ?? '—',
    isPotentialDuplicate: Boolean(r.is_potential_duplicate),
    duplicateReceiptNumber: r.duplicate_reference?.receipt_number,
    duplicateTotalAmount: (() => {
      const d = r.duplicate_reference;
      if (!d) return undefined;
      const v = d.total_amount ?? d.claimed_amount ?? d.ocr_raw_amount ?? d.approved_amount;
      return v !== null && v !== undefined ? num(v) : undefined;
    })(),
    duplicateReason: r.duplicate_reason,
    duplicateReferenceId: r.duplicate_reference_id ? Number(r.duplicate_reference_id) : undefined,
    duplicateReference: r.duplicate_reference ? {
      id: Number(r.duplicate_reference.id),
      receiptNumber: r.duplicate_reference.receipt_number,
      totalAmount: (() => {
        const d = r.duplicate_reference;
        const v = d.total_amount ?? d.claimed_amount ?? d.ocr_raw_amount ?? d.approved_amount;
        return v !== null && v !== undefined ? num(v) : num(r.claimed_amount ?? r.total_amount ?? r.ocr_raw_amount);
      })(),
      receiptDate: formatTanggal(r.duplicate_reference.receipt_date ?? r.duplicate_reference.submitted_at ?? r.duplicate_reference.created_at),
      imagePath: r.duplicate_reference.image_path,
      uploaderName: r.duplicate_reference.user?.name,
      department: r.duplicate_reference.user?.department,
    } : undefined,
    paidAt: r.paid_at ? formatTanggal(r.paid_at) : undefined,
    paidBy: r.paid_by?.name ?? r.paidBy?.name,
    paymentMethod: r.payment_method,
    paymentRefNo: r.payment_ref_no,
    bankName: r.user?.bank_name,
    bankAccountNo: r.user?.bank_account_no,
    bankAccountHolder: r.user?.bank_account_holder,
    images: Array.isArray(r.images) ? r.images.map((img: any) => ({
      id: Number(img.id),
      receipt_id: img.receipt_id ? Number(img.receipt_id) : undefined,
      file_path: String(img.file_path || ''),
      file_name: String(img.file_name || ''),
      file_size: img.file_size ? Number(img.file_size) : undefined,
      mime_type: img.mime_type ? String(img.mime_type) : undefined,
    })) : undefined,
    expenseReportId: r.expense_report_id ? Number(r.expense_report_id) : null,
    expenseReport: r.expense_report ? {
      id: Number(r.expense_report.id),
      report_number: String(r.expense_report.report_number || ''),
      title: String(r.expense_report.title || ''),
      status: String(r.expense_report.status || ''),
    } : (r.expenseReport ? {
      id: Number(r.expenseReport.id),
      report_number: String(r.expenseReport.report_number || ''),
      title: String(r.expenseReport.title || ''),
      status: String(r.expenseReport.status || ''),
    } : null),
  };
}

// ─── Invoice ────────────────────────────────────────────────
function mapInvoiceStatus(status: string): InvoiceStatus {
  switch (status) {
    case 'approved':
      return 'Dibayar';
    case 'rejected':
      return 'Ditolak';
    case 'pending':
    default:
      return 'Pending';
  }
}

export function mapInvoice(i: any): Invoice {
  const items =
    (i.items ?? []).map((it: any) => ({
      id: String(it.id),
      deskripsi: it.description,
      qty: num(it.quantity),
      harga: num(it.unit_price),
      subtotal: num(it.total_price ?? num(it.quantity) * num(it.unit_price)),
    })) || [];

  return {
    id: i.invoice_number ?? String(i.id),
    vendor: i.vendor_name ?? i.vendor?.name ?? '—',
    total: num(i.total_amount),
    jatuhTempo: formatTanggal(i.due_date),
    kategori: i.category ?? '—',
    sumber: (i.source === 'scan' ? 'Scan' : 'Manual') as InvoiceSource,
    status: mapInvoiceStatus(i.status),
    catatan: i.notes ?? undefined,
    npwp: i.vendor?.tax_id ?? undefined,
    tanggalInv: i.invoice_date ? formatTanggal(i.invoice_date) : undefined,
    ppn: num(i.tax_amount ?? i.ppn_amount),
    keterangan: i.notes ?? undefined,
    items,
    uploadOleh: i.user?.name ?? undefined,
    waktuUpload: i.created_at ? formatWaktu(i.created_at) : undefined,
    // Simpan id numerik asli backend untuk keperluan aksi (approve/reject).
    backendId: i.id,
    // Info approval multi-level.
    currentApprovalLevel: num(i.current_approval_level),
    maxApprovalLevel: num(i.max_approval_level),
    // Hanya approval berstatus 'approved' yang dihitung sebagai sudah menyetujui.
    approverUserIds: (i.approvals ?? [])
      .filter((a: any) => a.status === 'approved')
      .map((a: any) => num(a.user_id)),
  } as Invoice & { backendId?: number };
}

// ─── Notifications ──────────────────────────────────────────
// type backend bervariasi; petakan ke 4 kategori UI.
function mapNotifType(type: string): NotificationItem['type'] {
  if (type.includes('approved') || type.includes('paid') || type.includes('success'))
    return 'success';
  if (type.includes('reject') || type.includes('variance') || type.includes('flag'))
    return 'flag';
  if (type.includes('due')) return 'due';
  return 'new';
}

function resolveNotifTarget(type: string, entityType?: string): { targetPage?: string; targetLabel?: string } {
  const t = (type || '').toLowerCase();
  const e = (entityType || '').toLowerCase();

  if (t.includes('holiday') || e === 'holiday') {
    return { targetPage: 'presensi', targetLabel: 'Hari Libur' };
  }
  if (t.includes('leave') || t.includes('cuti') || t.includes('izin') || t.includes('sakit') || e === 'leave' || e === 'leave_request') {
    return { targetPage: 'presensi', targetLabel: 'Presensi & Cuti' };
  }
  if (t.includes('overtime') || t.includes('lembur') || e === 'overtime' || e === 'overtime_approval') {
    return { targetPage: 'overtime', targetLabel: 'Approval Lembur' };
  }
  if (t.includes('receipt') || t.includes('struk') || e === 'receipt') {
    return { targetPage: 'inbox', targetLabel: 'Inbox Struk' };
  }
  if (t.includes('invoice') || e === 'invoice') {
    return { targetPage: 'invoice-inbox', targetLabel: 'Inbox Invoice' };
  }
  if (t.includes('device') || t.includes('perangkat') || e === 'device_change_request') {
    return { targetPage: 'device-changes', targetLabel: 'Pindah Perangkat' };
  }
  if (t.includes('job') || t.includes('applicant') || t.includes('rekrutmen') || e === 'job_posting' || e === 'job_application') {
    return { targetPage: 'rekrutmen', targetLabel: 'Rekrutmen' };
  }
  if (t.includes('shift') || e === 'shift' || e === 'user_shift') {
    return { targetPage: 'shift', targetLabel: 'Shift & Jadwal' };
  }
  if (t.includes('setting') || e === 'attendance_setting') {
    return { targetPage: 'setting', targetLabel: 'Pengaturan' };
  }
  if (t.includes('karyawan') || t.includes('user') || e === 'user') {
    return { targetPage: 'karyawan', targetLabel: 'Manajemen Karyawan' };
  }

  return {};
}

export function mapNotification(n: any): NotificationItem {
  const data = typeof n.data === 'string' ? safeParse(n.data) : n.data ?? {};
  const rawType = n.type ?? '';
  const entityType = n.entity_type ?? data.entity_type;
  const target = resolveNotifTarget(rawType, entityType);

  return {
    id: String(n.id),
    type: mapNotifType(rawType),
    title: data.title ?? data.message ?? rawType ?? 'Notifikasi',
    subtitle: data.subtitle ?? data.message ?? '',
    time: formatWaktu(n.created_at),
    read: !!n.read_at,
    targetPage: target.targetPage,
    targetLabel: target.targetLabel,
    rawType,
    entityType,
    entityId: n.entity_id ?? data.entity_id,
  };
}

function safeParse(s: string) {
  try {
    return JSON.parse(s);
  } catch {
    return {};
  }
}

// ─── Activity logs (audit) ──────────────────────────────────
const ACTION_COLOR: Record<string, string> = {
  approved: 'bg-emerald-600',
  created: 'bg-blue-600',
  uploaded: 'bg-blue-600',
  submitted: 'bg-blue-600',
  rejected: 'bg-rose-600',
  deactivated: 'bg-rose-600',
  updated: 'bg-slate-700',
};

function colorForAction(action: string, severity?: string): string {
  if (severity === 'critical') return 'bg-rose-600';
  if (severity === 'warning') return 'bg-amber-600';
  for (const key of Object.keys(ACTION_COLOR)) {
    if (action.includes(key)) return ACTION_COLOR[key];
  }
  return 'bg-indigo-600';
}

export function mapAuditLog(l: any): AuditLog {
  const actor = l.user_name ?? 'Sistem';
  return {
    id: String(l.id),
    iconBg: colorForAction(l.action ?? '', l.severity),
    title: l.description ?? l.action ?? 'Aktivitas',
    details: `${actor}${l.user_role ? ' (' + l.user_role + ')' : ''} · ${formatWaktu(l.created_at)}`,
    action: l.action,
    category: l.category,
    severity: l.severity ?? 'info',
    userName: l.user_name,
    userRole: l.user_role,
    ipAddress: l.ip_address,
    userAgent: l.user_agent,
    entityType: l.entity_type,
    entityId: l.entity_id,
    oldValues: l.old_values,
    newValues: l.new_values,
    waktu: formatWaktu(l.created_at),
    created_at: l.created_at, // Simpan tanggal asli untuk filtering
  };
}

// ─── Settings ───────────────────────────────────────────────
export function mapSettings(s: any, branchSettingsRaw?: any[]): AppSettings {
  const settingsObj = s?.settings ?? s;
  const rawBranches = branchSettingsRaw ?? s?.branch_settings ?? [];

  return {
    varianceLimit: num(settingsObj?.variance_limit),
    maxClaimLimit: num(settingsObj?.max_claim_limit),
    thresholdSingle: settingsObj?.threshold_single ?? '',
    thresholdTwo: settingsObj?.threshold_two ?? '',
    thresholdThree: settingsObj?.threshold_three ?? '',
    branchSettings: Array.isArray(rawBranches)
      ? rawBranches.map((b: any) => ({
          id: num(b.id),
          officeName: b.office_name ?? '',
          varianceLimit: b.variance_limit !== null && b.variance_limit !== undefined ? num(b.variance_limit) : null,
          maxClaimLimit: b.max_claim_limit !== null && b.max_claim_limit !== undefined ? num(b.max_claim_limit) : null,
        }))
      : undefined,
  };
}

export function settingsToPayload(s: AppSettings) {
  return {
    variance_limit: s.varianceLimit,
    max_claim_limit: s.maxClaimLimit,
    threshold_single: s.thresholdSingle,
    threshold_two: s.thresholdTwo,
    threshold_three: s.thresholdThree,
  };
}

// ─── Expense Report (Bundling Perjalanan Dinas) ──────────────
export function mapExpenseReport(r: any): ExpenseReport {
  const userName = r.user?.name || r.userName || 'Karyawan';
  const department = r.user?.department || r.department || '';
  const branchName = r.office?.office_name || r.branchName || 'Kantor Pusat';
  const claimedAmt = num(r.total_claimed_amount ?? r.totalClaimedAmount ?? 0);
  const approvedAmt = r.total_approved_amount !== null && r.total_approved_amount !== undefined
    ? num(r.total_approved_amount)
    : (r.totalApprovedAmount !== null && r.totalApprovedAmount !== undefined ? num(r.totalApprovedAmount) : null);
  const receiptsList = Array.isArray(r.receipts) ? r.receipts : [];
  const receiptsCount = num(r.receipts_count ?? r.totalReceipts ?? receiptsList.length);

  return {
    id: Number(r.id),
    company_id: r.company_id ? Number(r.company_id) : undefined,
    user_id: r.user_id ? Number(r.user_id) : undefined,
    attendance_setting_id: r.attendance_setting_id ? Number(r.attendance_setting_id) : null,
    report_number: String(r.report_number || r.reportNumber || ''),
    reportNumber: String(r.report_number || r.reportNumber || ''),
    title: String(r.title || 'Laporan Pengeluaran'),
    description: r.description ?? null,
    start_date: r.start_date || r.startDate || null,
    startDate: r.start_date ? formatTanggal(r.start_date) : (r.startDate || '—'),
    end_date: r.end_date || r.endDate || null,
    endDate: r.end_date ? formatTanggal(r.end_date) : (r.endDate || '—'),
    status: (r.status as ExpenseReportStatus) || 'draft',
    total_claimed_amount: claimedAmt,
    totalClaimedAmount: claimedAmt,
    total_approved_amount: approvedAmt,
    totalApprovedAmount: approvedAmt,
    submitted_at: r.submitted_at || null,
    approved_at: r.approved_at || null,
    rejection_reason: r.rejection_reason || null,
    paid_at: r.paid_at || null,
    userName,
    department,
    branchName,
    totalReceipts: receiptsCount,
    receipts_count: receiptsCount,
    user: r.user,
    office: r.office,
    receipts: receiptsList,
    created_at: r.created_at,
    updated_at: r.updated_at,
  };
}
