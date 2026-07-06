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

export function mapReceipt(r: any): Receipt {
  const karyawan = r.user?.name ?? r.vendor_name ?? 'Tanpa Nama';
  const av = avatarFor(karyawan);
  const ocr = num(r.ocr_raw_amount ?? r.total_amount);
  const klaim = num(r.claimed_amount ?? r.total_amount ?? r.ocr_raw_amount);
  return {
    id: String(r.id),
    karyawan,
    initials: initialsOf(karyawan),
    avatarBg: av.bg,
    avatarColor: av.color,
    merchant: r.vendor_name ?? r.ocr_raw_merchant ?? '—',
    ocrNominal: ocr,
    klaim,
    kategori: r.category ?? '—',
    status: mapReceiptStatus(r.status, r.variance_flag),
    tanggal: formatTanggal(r.receipt_date ?? r.submitted_at ?? r.created_at),
    departemen: r.user?.department ?? '—',
    imageUrl: undefined, // Will be loaded asynchronously in component
  };
}

// Riwayat approval struk (struk approved/rejected) → StrukApproval
export function mapReceiptToApproval(r: any): StrukApproval {
  // Ambil nama approver, fallback ke "Finance"
  const approverName = r.approvals?.[0]?.user?.name ?? r.approved_by?.name ?? 'Finance';

  // Extract tanggal YYYY-MM-DD dari timestamp untuk filtering
  const dateStr = (r.submitted_at ?? r.created_at ?? '').substring(0, 10);

  return {
    id: String(r.id),
    karyawan: r.user?.name ?? '—',
    merchant: (r.vendor_name && r.vendor_name.trim() !== '') ? r.vendor_name : (r.ocr_raw_merchant ?? '—'),
    nominal: num(r.claimed_amount ?? r.total_amount),
    keputusan: r.status === 'approved' ? 'Disetujui' : 'Ditolak',
    diprosesOleh: approverName,
    waktu: formatWaktu(r.submitted_at ?? r.created_at),
    catatan: r.approvals?.[0]?.notes ?? r.rejection_reason ?? '—',
    tanggal: dateStr,
    // Tambah detail approver dari response baru
    approvedBy: r.approved_by && {
      id: r.approved_by.id,
      name: r.approved_by.name,
      email: r.approved_by.email,
      role: r.approved_by.role,
    },
    approvedAt: r.approved_at,
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

export function mapNotification(n: any): NotificationItem {
  const data = typeof n.data === 'string' ? safeParse(n.data) : n.data ?? {};
  return {
    id: String(n.id),
    type: mapNotifType(n.type ?? ''),
    title: data.title ?? data.message ?? n.type ?? 'Notifikasi',
    subtitle: data.subtitle ?? data.message ?? '',
    time: formatWaktu(n.created_at),
    read: !!n.read_at,
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

function colorForAction(action: string): string {
  for (const key of Object.keys(ACTION_COLOR)) {
    if (action.includes(key)) return ACTION_COLOR[key];
  }
  return 'bg-slate-600';
}

export function mapAuditLog(l: any): AuditLog {
  const actor = l.user_name ?? 'Sistem';
  return {
    id: String(l.id),
    iconBg: colorForAction(l.action ?? ''),
    title: l.description ?? l.action ?? 'Aktivitas',
    details: `${actor}${l.user_role ? ' (' + l.user_role + ')' : ''} · ${formatWaktu(l.created_at)}`,
    waktu: formatWaktu(l.created_at),
    created_at: l.created_at, // Simpan tanggal asli untuk filtering
  };
}

// ─── Settings ───────────────────────────────────────────────
export function mapSettings(s: any): AppSettings {
  return {
    varianceLimit: num(s.variance_limit),
    maxClaimLimit: num(s.max_claim_limit),
    thresholdSingle: s.threshold_single ?? '',
    thresholdTwo: s.threshold_two ?? '',
    thresholdThree: s.threshold_three ?? '',
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
