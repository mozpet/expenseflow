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
  varianceFlag?: boolean;
  variancePct?: number;
  tanggal: string;
  departemen: string;
  cabang?: string;
  cabangId?: number;
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
  branchVarianceLimit?: number | null;
  branchMaxClaimLimit?: number | null;
  images?: ReceiptImage[];
  expenseReportId?: number | null;
  expenseReport?: {
    id: number;
    report_number: string;
    title: string;
    status: string;
  } | null;
}

export interface ReceiptImage {
  id: number;
  receipt_id?: number;
  file_path?: string;
  image_path?: string;
  image_type?: 'primary' | 'edc_slip' | 'detail' | 'other' | string;
  file_name?: string;
  file_size?: number;
  mime_type?: string;
}

export type ExpenseReportStatus = 'draft' | 'submitted' | 'approved' | 'rejected' | 'paid';

export interface ExpenseReport {
  id: number;
  company_id?: number;
  user_id?: number;
  attendance_setting_id?: number | null;
  report_number: string;
  reportNumber?: string;
  title: string;
  description?: string | null;
  start_date?: string | null;
  startDate?: string | null;
  end_date?: string | null;
  endDate?: string | null;
  status: ExpenseReportStatus;
  total_claimed_amount: number;
  totalClaimedAmount?: number;
  total_approved_amount?: number | null;
  totalApprovedAmount?: number | null;
  submitted_at?: string | null;
  approved_at?: string | null;
  rejection_reason?: string | null;
  paid_at?: string | null;
  userName?: string;
  department?: string;
  branchName?: string;
  totalReceipts?: number;
  user?: {
    id: number;
    name: string;
    email: string;
    department?: string;
  };
  office?: {
    id: number;
    office_name: string;
  };
  receipts_count?: number;
  receipts?: any[];
  created_at?: string;
  updated_at?: string;
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
  cabang?: string;
  cabangId?: number;
  branchVarianceLimit?: number | null;
  branchMaxClaimLimit?: number | null;
  images?: ReceiptImage[];
  expenseReportId?: number | null;
  expenseReport?: {
    id: number;
    report_number: string;
    title: string;
    status: string;
  } | null;
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

export interface BranchSetting {
  id: number;
  officeName: string;
  varianceLimit: number | null;
  maxClaimLimit: number | null;
}

export interface AppSettings {
  varianceLimit: number; // in %
  maxClaimLimit: number; // in IDR
  thresholdSingle: string;
  thresholdTwo: string;
  thresholdThree: string;
  branchSettings?: BranchSetting[];
}
