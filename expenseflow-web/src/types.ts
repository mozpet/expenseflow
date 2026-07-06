export type ReceiptStatus = 'Review' | 'Pending' | 'Disetujui' | 'Ditolak';

export interface Receipt {
  id: string;
  karyawan: string;
  initials: string;
  avatarBg: string; // Tailwind class
  avatarColor: string; // Tailwind class
  merchant: string;
  ocrNominal: number;
  klaim: number;
  kategori: string;
  status: ReceiptStatus;
  tanggal: string;
  departemen: string;
  imageUrl?: string; // URL endpoint untuk foto struk
}

export interface StrukApproval {
  id: string;
  karyawan: string;
  merchant: string;
  nominal: number;
  keputusan: 'Disetujui' | 'Ditolak';
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
}

export interface AppSettings {
  varianceLimit: number; // in %
  maxClaimLimit: number; // in IDR
  thresholdSingle: string;
  thresholdTwo: string;
  thresholdThree: string;
}
