export type ReceiptStatus = 'Review' | 'Pending' | 'Disetujui' | 'Dibayar' | 'Ditolak';

export interface ReceiptItem {
  name: string;
  qty: number;
  price: number;
  total: number;
}

export interface Receipt {
  id: string;
  karyawan: string;
  initials: string;
  avatarBg: string; // Tailwind class
  avatarColor: string; // Tailwind class
  merchant: string;
  ocrNominal: number;
  klaim: number;
  approvedAmount?: number;
  kategori: string;
  status: ReceiptStatus;
  tanggal: string;
  departemen: string;
  imageUrl?: string; // URL endpoint untuk foto struk
  items?: ReceiptItem[];
  subtotal?: number;
  tax?: number;
  discount?: number;
  isPotentialDuplicate?: boolean;
  duplicateReceiptNumber?: string;
  duplicateTotalAmount?: number;
  duplicateReason?: string;
  duplicateReferenceId?: number;
  duplicateReference?: {
    id: number;
    receiptNumber: string;
    totalAmount: number;
    receiptDate?: string;
    imagePath?: string;
    uploaderName?: string;
    department?: string;
  };
  notes?: string;
  paidAt?: string;
  paidBy?: string;
  paymentMethod?: string;
  paymentRefNo?: string;
  bankName?: string;
  bankAccountNo?: string;
  bankAccountHolder?: string;
}

export interface StrukApproval {
  id: string;
  karyawan: string;
  merchant: string;
  nominal: number;
  approvedAmount?: number;
  keputusan: 'Disetujui' | 'Dibayar' | 'Ditolak';
  diprosesOleh: string;
  waktu: string;
  catatan: string;
  tanggal?: string; // Format YYYY-MM-DD untuk filtering
  approvedBy?: {
    id: string;
    name: string;
    email: string;
    role: string;
  };
  approvedAt?: string;
  items?: ReceiptItem[];
  subtotal?: number;
  tax?: number;
  discount?: number;
  ocrNominal?: number;
  kategori?: string;
  isPotentialDuplicate?: boolean;
  duplicateReceiptNumber?: string;
  duplicateTotalAmount?: number;
  duplicateReason?: string;
  duplicateReferenceId?: number;
  duplicateReference?: {
    id: number;
    receiptNumber: string;
    totalAmount: number;
    receiptDate?: string;
    imagePath?: string;
    uploaderName?: string;
    department?: string;
  };
  paidAt?: string;
  paidBy?: string;
  paymentMethod?: string;
  paymentRefNo?: string;
  bankName?: string;
  bankAccountNo?: string;
  bankAccountHolder?: string;
}

export type InvoiceStatus = 'Due' | 'Pending' | 'Dibayar' | 'Ditolak';
export type InvoiceSource = 'Scan' | 'Manual';

export interface InvoiceItem {
  id: string;
  deskripsi: string;
  qty: number;
  harga: number;
  subtotal: number;
}

export interface Invoice {
  id: string; // e.g., INV-0042
  vendor: string;
  total: number;
  jatuhTempo: string;
  kategori: string;
  sumber: InvoiceSource;
  status: InvoiceStatus;
  catatan?: string;
  npwp?: string;
  tanggalInv?: string;
  ppn?: number;
  keterangan?: string;
  items?: InvoiceItem[];
  sha256Hash?: string;
  uploadOleh?: string;
  waktuUpload?: string;
  // ID numerik asli dari backend (dipakai untuk aksi approve/reject).
  backendId?: number;
  // Approval multi-level: berapa level sudah disetujui & berapa level dibutuhkan.
  currentApprovalLevel?: number;
  maxApprovalLevel?: number;
  // ID user yang sudah menyetujui invoice ini (untuk separation of duties).
  approverUserIds?: number[];
}

export interface AuditLog {
  id: string;
  iconBg: string; // e.g., bg-green-500
  title: string;
  details: string;
  action?: string;
  category?: 'HR_EMPLOYEE' | 'PAYROLL_FINANCE' | 'EXPENSE_CLAIM' | 'ATTENDANCE_OFFICE' | 'SECURITY_AUTH' | 'COMPANY_SETTINGS' | string;
  severity?: 'info' | 'warning' | 'critical';
  userName?: string;
  userRole?: string;
  ipAddress?: string;
  userAgent?: string;
  entityType?: string;
  entityId?: number;
  oldValues?: Record<string, any> | null;
  newValues?: Record<string, any> | null;
  waktu: string;
  created_at?: string; // ISO format tanggal asli untuk filtering
}

export interface NotificationItem {
  id: string;
  type: 'due' | 'flag' | 'new' | 'success';
  title: string;
  subtitle: string;
  time: string;
  read: boolean;
  targetPage?: string;
  targetLabel?: string;
  rawType?: string;
  entityType?: string;
  entityId?: number;
}

export interface AppSettings {
  varianceLimit: number; // in %
  maxClaimLimit: number; // in IDR
  thresholdSingle: string;
  thresholdTwo: string;
  thresholdThree: string;
}
