export type ReceiptStatus = 'Review' | 'Pending' | 'Partially Approved' | 'Disetujui' | 'Dibayar' | 'Ditolak';

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
  rawStatus?: string;
  approvalTier?: number;
  requiredApprovals?: number;
  currentApprovals?: number;
  alreadyApprovedByMe?: boolean;
  approvalsHistory?: Array<{
    id: number;
    user_name: string;
    role: string;
    tier: number;
    approved_amount: number;
    catatan?: string;
    created_at: string;
  }>;
  varianceFlag?: boolean;
  variancePct?: number;
  tanggal: string;
  ocrDate?: string;
  submittedAt?: string;
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
  notes?: string; // Keterangan / catatan dari karyawan saat mengajukan struk
  tanggal?: string; // Format YYYY-MM-DD untuk filtering
  ocrDate?: string;
  submittedAt?: string;
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
  // Aturan approval bertingkat struk reimbursement
  receiptTier1Threshold: number; // Batas atas Tier 1 (nominal < ini = 1 approval Finance)
  receiptTier2Threshold: number; // Batas atas Tier 2 / bawah Tier 3 (nominal ≥ ini = wajib SPV)
  receiptTier2Mode: 'two_finance' | 'finance_and_spv'; // Mode Tier 2
}

export interface Division {
  id: number;
  company_id: number;
  name: string;
  code: string | null;
  description: string | null;
  is_active: boolean;
  users_count?: number;
  positions_count?: number;
  positions?: Position[];
  created_at: string;
  updated_at: string;
}

export interface Position {
  id: number;
  company_id: number;
  division_id: number | null;
  name: string;
  is_supervisor: boolean;
  description: string | null;
  is_active: boolean;
  division?: Division | { id: number; name: string; code: string | null };
  users_count?: number;
  created_at?: string;
  updated_at?: string;
}

// ─── Payroll Types (Fase 2 & Fase 3) ───────────────────────────
export interface BpjsProfile {
  has_bpjs_kes: boolean;
  has_bpjs_tk: boolean;
  has_jkp: boolean;
  jkk_risk_class: number;
  bpjs_kes_no_masked?: string | null;
  bpjs_tk_no_masked?: string | null;
}

export interface CalculationStep {
  step_code: string;
  step_sequence: number;
  input_payload?: Record<string, any> | null;
  final_result: string | number;
  rule_reference?: string | null;
}

export interface PayslipItemDetail {
  id?: number;
  label: string;
  code?: string;
  amount: string | number;
  source?: string;
  is_statutory?: boolean;
}

export type PayrollAdjustmentType = 'earning' | 'deduction';
export type PayrollAdjustmentStatus = 'pending' | 'approved' | 'applied' | 'voided';

export interface PayrollAdjustment {
  id: number;
  company_id?: number;
  user_id: number;
  type: PayrollAdjustmentType;
  name: string;
  amount: string | number;
  is_taxable: boolean;
  reason?: string | null;
  source_document_path?: string | null;
  status: PayrollAdjustmentStatus;
  payroll_id?: number | null;
  retroactive_payroll_id?: number | null;
  created_by: number;
  approved_by?: number | null;
  approved_at?: string | null;
  voided_by?: number | null;
  voided_at?: string | null;
  void_reason?: string | null;
  user?: {
    id: number;
    name: string;
    employee_code?: string;
    department?: string;
  };
  created_by_user?: {
    id: number;
    name: string;
  };
  approved_by_user?: {
    id: number;
    name: string;
  };
  voided_by_user?: {
    id: number;
    name: string;
  };
  created_at?: string;
  updated_at?: string;
}

export type BankAccountStatus = 'pending_verification' | 'active' | 'superseded' | 'rejected';

export interface EmployeeBankAccount {
  id: number;
  company_id?: number;
  user_id: number;
  bank_name: string;
  bank_account_no_masked: string;
  bank_account_holder: string;
  bank_branch?: string | null;
  swift_code?: string | null;
  status: BankAccountStatus;
  is_primary: boolean;
  requested_by?: number;
  verified_by?: number | null;
  verified_at?: string | null;
  notes?: string | null;
  reject_reason?: string | null;
  requested_by_user?: {
    id: number;
    name: string;
  };
  verified_by_user?: {
    id: number;
    name: string;
  };
  created_at?: string;
  updated_at?: string;
}

export interface PayrollLog {
  id: number;
  action: string;
  user_id?: number | null;
  user_name?: string | null;
  notes?: string | null;
  before_state?: Record<string, any> | null;
  after_state?: Record<string, any> | null;
  ip_address?: string | null;
  sequence: number;
  prev_hash: string;
  record_hash: string;
  created_at: string;
}

export interface RunLogsResponse {
  logs: PayrollLog[];
  integrity: {
    ok: boolean;
    broken_at?: number | null;
  };
}

export interface PinStatus {
  has_pin: boolean;
  set_at?: string | null;
}

// ─── Payroll Types (Fase 4+: Grup Payroll & Profil Pajak Perusahaan) ──────────

export interface PayrollGroup {
  id: number;
  company_id?: number;
  name: string;
  code?: string | null;
  description?: string | null;
  is_active: boolean;
  users_count?: number;
  created_at?: string;
  updated_at?: string;
}

export interface CompanyTaxProfile {
  name: string;
  address?: string | null;
  has_npwp: boolean;
  npwp_masked: string | null;
}

// ─── Payroll Types (Fase 4: Disbursement, GL & Tax) ─────────────────────────

export type PayrollUserRef = number | { id: number; name: string } | null;

export interface BankFileFormatOption {
  key: string;
  label: string;
  extension: string;
}

export type PaymentBatchStatus =
  | 'prepared'
  | 'file_generated'
  | 'uploaded'
  | 'partially_settled'
  | 'settled'
  | 'reconciled';

export type PaymentItemStatus = 'pending' | 'success' | 'failed' | 'rejected_by_bank';

export interface PayrollPaymentItem {
  id: number;
  payment_batch_id: number;
  payslip_id: number;
  user_id: number;
  bank_name: string;
  bank_account_no_masked: string;
  bank_account_holder: string;
  amount: string | number;
  status: PaymentItemStatus;
  bank_reference_no?: string | null;
  failure_reason?: string | null;
  settled_at?: string | null;
  created_at?: string;
  updated_at?: string;
  user?: {
    id: number;
    name: string;
  };
}

export interface PayrollPaymentBatch {
  id: number;
  payroll_id: number;
  company_id?: number;
  batch_reference: string;
  bank_format: string;
  total_records: number;
  total_amount: string | number;
  status: PaymentBatchStatus;
  file_checksum?: string | null;
  generated_by?: PayrollUserRef;
  reconciled_by?: PayrollUserRef;
  reconciled_at?: string | null;
  created_at?: string;
  updated_at?: string;
  items_count?: number;
  items?: PayrollPaymentItem[];
  generated_by_user?: { id: number; name: string };
  reconciled_by_user?: { id: number; name: string };
  payroll?: {
    id: number;
    period_month: number;
    period_year: number;
    status: string;
  };
}

export interface PaymentBatchesResponse {
  data: PayrollPaymentBatch[];
  bank_formats: BankFileFormatOption[];
}

export interface PaymentBatchGeneratePayload {
  bank_format: string;
  value_date?: string;
  pin?: string;
}

export interface PaymentBatchReconcileItem {
  payment_item_id: number;
  status: 'success' | 'failed' | 'rejected_by_bank';
  bank_reference_no?: string;
  failure_reason?: string;
}

export interface PaymentBatchReconcilePayload {
  results: PaymentBatchReconcileItem[];
  pin?: string;
}

// ─── General Ledger Types (Fase 4 lanjutan) ──────────────────────────────────

export interface GlAccount {
  key: string;
  label?: string;
  side?: 'debit' | 'credit';
  account_code: string;
  account_name: string;
  default_code?: string;
  default_name?: string;
  is_overridden: boolean;
}

export interface GlAccountsResponse {
  data: GlAccount[];
}

export interface GlLine {
  key?: string;
  account_key?: string;
  account_code: string;
  account_name: string;
  description?: string;
  debit: number;
  credit: number;
}

export interface GlJournalFlat {
  group_by?: 'none';
  lines: GlLine[];
  total_debit: number;
  total_credit: number;
  balanced: boolean;
  payroll?: {
    id: number;
    period_month?: number;
    period_year?: number;
    period_label?: string;
    status?: string;
    total_gross?: number | string;
    total_deduction?: number | string;
    total_bpjs_company?: number | string;
    total_net?: number | string;
  };
}

export interface GlJournalSegment {
  key: string;
  segment_id: number | null;
  label: string;
  lines: GlLine[];
  total_debit: number;
  total_credit: number;
  balanced: boolean;
}

export interface GlJournalGrouped {
  group_by: 'division' | 'branch';
  dimension: string;
  segments: GlJournalSegment[];
  grand_total_debit: number;
  grand_total_credit: number;
  balanced: boolean;
  payroll?: {
    id: number;
    period_month?: number;
    period_year?: number;
    period_label?: string;
    status?: string;
    total_gross?: number | string;
    total_deduction?: number | string;
    total_bpjs_company?: number | string;
    total_net?: number | string;
  };
}

export type GlJournalResponse = GlJournalFlat | GlJournalGrouped;

// ─── Tax 1721-A1 Types (Fase 4 lanjutan) ─────────────────────────────────────

export interface TaxCertificate1721A1 {
  user_id: number;
  employee_code?: string | null;
  employee_name: string;
  position_name?: string | null;
  npwp_masked?: string | null;
  has_npwp?: boolean;
  ptkp_status?: string | null;
  tax_year?: number;
  months_count?: number;
  months_active?: number;
  // Field backend canonical (AnnualTaxAggregator):
  bruto?: number;
  bruto_regular?: number;
  bruto_irregular?: number;
  bruto_benefit?: number;
  biaya_jabatan?: number;
  iuran_pensiun?: number;
  neto?: number;
  ptkp?: number;
  pkp?: number;
  pph21_terutang?: number;
  pph21_dipotong?: number;
  selisih?: number;
  // Field aliases:
  gross_income?: number;
  deduction_total?: number;
  net_income?: number;
  ptkp_amount?: number;
  taxable_income?: number;
  pph21_annual?: number;
  pph21_paid?: number;
  pph21_underpayment?: number;
  has_underpayment?: boolean;
}

export interface TaxCertificate1721A1Detail extends TaxCertificate1721A1 {
  company_name?: string;
  company_npwp_masked?: string | null;
  division_name?: string | null;
  details?: Record<string, any>;
}

// ─── Calculation Trace Types (Fase 4 lanjutan) ───────────────────────────────

export interface CalculationTraceStep {
  step_code: string;
  step_sequence: number;
  formula_version?: string | null;
  input_payload?: Record<string, any> | null;
  raw_result?: string | number | null;
  rounding_diff?: string | number | null;
  final_result: string | number;
  rule_reference?: string | null;
}

export interface PayslipTraceResponse {
  data: {
    payslip: {
      id: number;
      payroll_id: number;
      employee_name: string;
      employee_code?: string | null;
      period_month: number;
      period_year: number;
      ptkp_status?: string | null;
      gross: string | number;
      taxable_income: string | number;
      pph21: string | number;
      total_deduction: string | number;
      net: string | number;
      status: string;
    };
    steps: CalculationTraceStep[];
    earnings: Array<{
      label: string;
      code?: string;
      amount: string | number;
      is_taxable?: boolean;
      is_statutory?: boolean;
      source?: string;
      notes?: string | null;
    }>;
    deductions: Array<{
      label: string;
      code?: string;
      amount: string | number;
      is_taxable?: boolean;
      is_statutory?: boolean;
      source?: string;
      notes?: string | null;
    }>;
  };
}

export interface LeaveTypeSettingItem {
  leave_type: string;
  label: string;
  is_enabled: boolean;
  quota_days: number;
  requires_document: boolean;
  default_quota_days: number;
  gender_restriction?: 'Perempuan' | 'Laki-laki' | 'female' | 'male' | null;
  marital_restriction?: 'married' | string | null;
  pregnancy_restriction?: boolean;
  family_related?: boolean;
  legal_basis?: string | null;
  description?: string | null;
  eligibility_notes?: string | null;
  notes?: string | null;
}

export interface LeaveTypeSettingsResponse {
  attendance_setting_id: number | null;
  office_name: string | null;
  default_leave_quota?: number;
  leave_reset_date?: string | null;
  leave_multi_approval_enabled?: boolean;
  leave_types: LeaveTypeSettingItem[];
}

// ─── Payroll Types (Fase 6: Struktur & Skala Upah, Valas & PPh 26, Exit Settlement, e-Bupot XSD) ───

export interface JobLevel {
  id: number;
  company_id?: number;
  name: string;
  code?: string | null;
  rank: number;
  description?: string | null;
  is_active: boolean;
  grades_count?: number;
  created_at?: string;
  updated_at?: string;
}

export interface SalaryGrade {
  id: number;
  company_id?: number;
  job_level_id?: number | null;
  name: string;
  code?: string | null;
  min_salary: string | number;
  mid_salary?: string | number | null;
  max_salary: string | number;
  currency?: string;
  description?: string | null;
  is_active: boolean;
  job_level?: {
    id: number;
    name: string;
    rank: number;
  } | null;
  created_at?: string;
  updated_at?: string;
}

export interface CurrencyRate {
  id: number;
  company_id?: number | null;
  currency: string;
  rate_to_idr: string | number;
  effective_date: string;
  source?: string | null;
  is_editable: boolean;
  scope: 'company' | 'global';
  created_at?: string;
  updated_at?: string;
}

export type SeveranceTerminationType = 'phk' | 'pkwt_end' | 'resign' | 'retirement' | 'death';

export interface SeveranceCase {
  id: number;
  company_id?: number;
  user_id: number;
  payroll_id?: number | null;
  termination_type: SeveranceTerminationType;
  termination_reason?: string | null;
  termination_date: string;
  last_working_date?: string | null;
  employment_type: 'pkwt' | 'pkwtt';
  contract_start_date?: string | null;
  contract_end_date?: string | null;
  up_multiplier: string | number;
  upmk_multiplier: string | number;
  include_uph: boolean;
  annual_leave_balance_days: string | number;
  relocation_cost: string | number;
  other_compensation: string | number;
  separation_pay: string | number;
  asset_deduction: string | number;
  settle_loans: boolean;
  status: 'draft' | 'calculated' | 'closed';
  notes?: string | null;
  user?: {
    id: number;
    name: string;
    employee_code?: string;
    position_id?: number;
  };
  payroll?: {
    id: number;
    period_month: number;
    period_year: number;
    run_type: string;
    status: string;
  };
  created_at?: string;
  updated_at?: string;
}

export interface SeverancePreview {
  severance_case_id: number;
  user_id: number;
  employee_name: string;
  termination_type: string;
  termination_date: string;
  monthly_wage: string | number;
  tenure_months: number;
  tenure_years: number;
  up_months: number;
  upmk_months: number;
  up: string | number;
  upmk: string | number;
  uph: string | number;
  uph_breakdown: {
    leave_compensation: string | number;
    housing_medical: string | number;
    housing_medical_rate: number;
    relocation_cost: string | number;
    other_compensation: string | number;
  };
  pkwt_compensation: string | number;
  pkwt_months: number;
  separation_pay: string | number;
  final_tax_base: string | number;
  pph21_final: string | number;
  pph21_final_effective_rate: number;
  loan_settlement: string | number;
  asset_deduction: string | number;
  total_gross: string | number;
  total_deduction: string | number;
  total_net: string | number;
}

export interface EbupotSchemaStatus {
  official_schema_installed: boolean;
  schema_label: string;
  validated: boolean;
  resmi: boolean;
  expected_path: string;
  message: string;
}

export interface TaxProfile {
  ptkp_status: string;
  has_npwp: boolean;
  npwp_masked?: string | null;
  tax_method: 'gross' | 'gross_up';
  tax_subject_type?: 'domestic' | 'foreign';
  treaty_country?: string | null;
  treaty_rate?: number | null;
  foreign_tax_id?: string | null;
}



