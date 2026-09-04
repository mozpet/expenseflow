import React, { useState, useRef } from 'react';
import {
  Upload,
  FileSpreadsheet,
  Download,
  CheckCircle2,
  AlertCircle,
  ArrowRight,
  ArrowLeft,
  X,
  RefreshCw,
  Settings,
  Eye,
  Check,
  FileText,
  AlertTriangle,
  Copy,
  Users
} from 'lucide-react';
import { motion, AnimatePresence } from 'motion/react';
import * as XLSX from 'xlsx';
import { userApi, BulkImportPayload, BulkImportResponse } from '../services/endpoints';
import { invalidateCache } from '../services/api';

interface Office {
  id: number;
  office_name: string;
}

interface ImportEmployeeModalProps {
  isOpen: boolean;
  onClose: () => void;
  offices: Office[];
  onSuccess: (importedCount: number) => void;
  onAddAuditLog?: (title: string, details: string, bg: string) => void;
  onAddNotification?: (type: 'due' | 'flag' | 'new' | 'success', title: string, subtitle: string) => void;
}

interface TargetField {
  key: string;
  label: string;
  required?: boolean;
  description: string;
  aliases: string[];
}

const TARGET_FIELDS: TargetField[] = [
  {
    key: 'name',
    label: 'Nama Lengkap',
    required: true,
    description: 'Nama karyawan sesuai KTP/kontrak',
    aliases: ['nama', 'name', 'fullname', 'full name', 'karyawan', 'pegawai', 'nama lengkap', 'employee', 'nama karyawan'],
  },
  {
    key: 'email',
    label: 'Email Akun',
    required: true,
    description: 'Email login aktif unik',
    aliases: ['email', 'surel', 'mail', 'e-mail', 'alamat email', 'email address', 'user email', 'surel kantor', 'email kantor'],
  },
  {
    key: 'employee_code',
    label: 'NIK / ID Karyawan',
    description: 'Nomor induk kepegawaian kantor',
    aliases: ['nik', 'nip', 'kode', 'id', 'no induk', 'employee id', 'no pegawai', 'kode karyawan', 'id karyawan', 'nomor induk', 'emp id'],
  },
  {
    key: 'identity_number',
    label: 'NIK KTP (16 Digit)',
    description: 'Nomor KTP untuk administrasi pajak/BPJS',
    aliases: ['ktp', 'nik ktp', 'no ktp', 'nomor ktp', 'identity', 'identity number', 'id card', 'no identitas'],
  },
  {
    key: 'phone',
    label: 'Nomor HP / WhatsApp',
    description: 'Kontak aktif karyawan',
    aliases: ['hp', 'telepon', 'telp', 'phone', 'no hp', 'whatsapp', 'wa', 'no telp', 'handphone', 'mobile', 'kontak', 'no wa'],
  },
  {
    key: 'gender',
    label: 'Jenis Kelamin',
    description: 'Laki-laki / Perempuan (K3 Shift Malam)',
    aliases: ['gender', 'jenis kelamin', 'jk', 'kelamin', 'sex'],
  },
  {
    key: 'birth_place',
    label: 'Tempat Lahir',
    description: 'Kota / tempat kelahiran',
    aliases: ['tempat lahir', 'birth place', 'birthplace', 'kota lahir', 'tempat_lahir'],
  },
  {
    key: 'birth_date',
    label: 'Tanggal Lahir',
    description: 'Format YYYY-MM-DD (K3 Shift Malam)',
    aliases: ['tanggal lahir', 'birth date', 'birthdate', 'tgl lahir', 'dob', 'date of birth', 'tanggal_lahir'],
  },
  {
    key: 'department',
    label: 'Departemen / Divisi',
    description: 'Unit kerja atau divisi kantor',
    aliases: ['departemen', 'department', 'divisi', 'dept', 'bagian', 'div', 'unit kerja', 'bidang', 'seksi'],
  },
  {
    key: 'role',
    label: 'Role / Hak Akses',
    description: 'employee / finance / hrd / admin',
    aliases: ['role', 'peran', 'jabatan', 'posisi', 'level', 'akses', 'hak akses'],
  },
  {
    key: 'employment_type',
    label: 'Status Karyawan',
    description: 'PKWTT / PKWT / Probation / Internship',
    aliases: ['tipe kontrak', 'status kerja', 'status karyawan', 'employment type', 'kontrak', 'status hubungan kerja', 'status'],
  },
  {
    key: 'bank_name',
    label: 'Nama Bank Transfer',
    description: 'BCA, Mandiri, BRI, BNI, dll.',
    aliases: ['bank', 'nama bank', 'bank name', 'rekening bank', 'bank transfer'],
  },
  {
    key: 'bank_account_no',
    label: 'Nomor Rekening Bank',
    description: 'Nomor rekening untuk transfer disbursement',
    aliases: ['rekening', 'no rekening', 'nomor rekening', 'account number', 'no rek', 'bank account', 'no akun', 'norek'],
  },
  {
    key: 'bank_account_holder',
    label: 'Atas Nama Rekening',
    description: 'Nama pemilik rekening bank',
    aliases: ['atas nama', 'pemilik rekening', 'nama rekening', 'account holder', 'nama pemilik', 'an rekening', 'a/n'],
  },
  {
    key: 'joined_date',
    label: 'Tanggal Masuk',
    description: 'Format YYYY-MM-DD atau tanggal standar',
    aliases: ['tanggal masuk', 'tgl masuk', 'join date', 'joined date', 'tgl gabung', 'masuk', 'tanggal bergabung', 'tgl join'],
  },
  {
    key: 'leave_balance',
    label: 'Saldo Cuti Awal',
    description: 'Sisa kuota cuti tahunan berjalan (angka)',
    aliases: ['saldo cuti', 'kuota cuti', 'sisa cuti', 'cuti tahunan', 'hak cuti', 'cuti', 'annual leave', 'jatah cuti'],
  },
  {
    key: 'monthly_claim_limit',
    label: 'Limit Klaim Bulanan (IDR)',
    description: 'Plafon klaim struk/reimbursement (angka)',
    aliases: ['limit', 'plafon', 'limit klaim', 'monthly limit', 'batas klaim', 'plafon klaim', 'max klaim'],
  },
];

type WizardStep = 1 | 2 | 3 | 4 | 5;

export const ImportEmployeeModal: React.FC<ImportEmployeeModalProps> = ({
  isOpen,
  onClose,
  offices,
  onSuccess,
  onAddAuditLog,
  onAddNotification,
}) => {
  const [step, setStep] = useState<WizardStep>(1);
  const [file, setFile] = useState<File | null>(null);
  const [fileName, setFileName] = useState<string>('');
  const [fileHeaders, setFileHeaders] = useState<string[]>([]);
  const [rawRows, setRawRows] = useState<Record<string, any>[]>([]);
  const [isReadingFile, setIsReadingFile] = useState<boolean>(false);
  const [readError, setReadError] = useState<string | null>(null);

  // Column Mapping: { [targetKey: string]: sourceFileHeader }
  const [mapping, setMapping] = useState<Record<string, string>>({});

  // Default Values
  const [defaultOfficeId, setDefaultOfficeId] = useState<number | ''>(offices[0]?.id ?? '');
  const [defaultRole, setDefaultRole] = useState<string>('employee');
  const [defaultEmploymentType, setDefaultEmploymentType] = useState<string>('PKWTT');
  const [defaultPassword, setDefaultPassword] = useState<string>('Karyawan123!');
  const [defaultAttendanceEnabled, setDefaultAttendanceEnabled] = useState<boolean>(true);
  const [defaultWfhEnabled, setDefaultWfhEnabled] = useState<boolean>(false);
  const [defaultRadiusEnabled, setDefaultRadiusEnabled] = useState<boolean>(false);

  // Execution & Results
  const [isSubmitting, setIsSubmitting] = useState<boolean>(false);
  const [importResult, setImportResult] = useState<BulkImportResponse | null>(null);
  const [importError, setImportError] = useState<string | null>(null);
  const [copiedErrors, setCopiedErrors] = useState<boolean>(false);

  const fileInputRef = useRef<HTMLInputElement>(null);

  // Reset on open/close
  const handleClose = () => {
    setStep(1);
    setFile(null);
    setFileName('');
    setFileHeaders([]);
    setRawRows([]);
    setMapping({});
    setImportResult(null);
    setImportError(null);
    setReadError(null);
    onClose();
  };

  if (!isOpen) return null;

  // ─── Auto Matching Function ──────────────────────────────────────────────
  const autoMatchColumns = (headers: string[]) => {
    const newMapping: Record<string, string> = {};
    const usedHeaders = new Set<string>();

    TARGET_FIELDS.forEach((field) => {
      for (const header of headers) {
        if (usedHeaders.has(header)) continue;
        const normalizedHeader = header.toLowerCase().replace(/[^a-z0-9]/g, ' ').trim();

        // Cek apakah header cocok persis dengan salah satu alias
        const isMatch = field.aliases.some((alias) => {
          const cleanAlias = alias.toLowerCase().replace(/[^a-z0-9]/g, ' ').trim();
          return (
            normalizedHeader === cleanAlias ||
            normalizedHeader.includes(cleanAlias) ||
            cleanAlias.includes(normalizedHeader)
          );
        });

        if (isMatch) {
          newMapping[field.key] = header;
          usedHeaders.add(header);
          break;
        }
      }
    });

    setMapping(newMapping);
  };

  // ─── Handle File Upload ──────────────────────────────────────────────────
  const handleFile = async (selectedFile: File) => {
    setReadError(null);
    setIsReadingFile(true);
    setFileName(selectedFile.name);
    setFile(selectedFile);

    try {
      const buffer = await selectedFile.arrayBuffer();
      const workbook = XLSX.read(buffer, { type: 'array' });
      const firstSheetName = workbook.SheetNames[0];
      if (!firstSheetName) {
        throw new Error('File tidak memiliki sheet data.');
      }
      const sheet = workbook.Sheets[firstSheetName];
      const jsonData = XLSX.utils.sheet_to_json<Record<string, any>>(sheet, {
        raw: false,
        defval: '',
      });

      if (!jsonData || jsonData.length === 0) {
        throw new Error('File Excel/CSV kosong atau tidak memiliki baris data.');
      }

      // Ambil headers dari baris pertama
      const headers = Object.keys(jsonData[0] || {});
      if (headers.length === 0) {
        throw new Error('Tidak dapat menemukan kolom header pada baris pertama.');
      }

      setFileHeaders(headers);
      setRawRows(jsonData);
      autoMatchColumns(headers);
      setStep(2);
    } catch (err: any) {
      setReadError(err?.message || 'Gagal membaca file Excel/CSV.');
    } finally {
      setIsReadingFile(false);
    }
  };

  // ─── Download Template Standard ──────────────────────────────────────────
  const downloadTemplate = (format: 'xlsx' | 'csv') => {
    const templateHeaders = [
      'NIK / Kode Karyawan',
      'Nama Lengkap',
      'Email Perusahaan',
      'Nomor HP / WA',
      'Jenis Kelamin',
      'Tempat Lahir',
      'Tanggal Lahir',
      'Departemen',
      'Jabatan / Role',
      'Status Karyawan',
      'Nama Bank',
      'Nomor Rekening',
      'Atas Nama Rekening',
      'Tanggal Masuk',
      'Saldo Cuti Awal',
      'NIK KTP'
    ];

    const sampleRows = [
      [
        'EMP001',
        'Budi Santoso',
        'budi.santoso@perusahaan.com',
        '081234567890',
        'Laki-laki',
        'Jakarta',
        '1995-05-12',
        'IT Engineering',
        'employee',
        'PKWTT',
        'BCA',
        '1234567890',
        'Budi Santoso',
        '2024-01-15',
        '12',
        '3171012345670001'
      ],
      [
        'EMP002',
        'Siti Rahma',
        'siti.rahma@perusahaan.com',
        '081298765432',
        'Perempuan',
        'Bandung',
        '1998-08-20',
        'Finance & Accounting',
        'finance',
        'PKWTT',
        'Bank Mandiri',
        '9876543210',
        'Siti Rahma',
        '2024-03-01',
        '10',
        '3171012345670002'
      ],
      [
        'EMP003',
        'Dimas Arya',
        'dimas.arya@perusahaan.com',
        '081311223344',
        'Laki-laki',
        'Surabaya',
        '2002-11-04',
        'Operasional',
        'employee',
        'PKWT',
        'BRI',
        '5544332211',
        'Dimas Arya',
        '2025-06-10',
        '12',
        '3171012345670003'
      ],
    ];

    const wsData = [templateHeaders, ...sampleRows];
    const ws = XLSX.utils.aoa_to_sheet(wsData);
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'Template Karyawan');

    const fileName = `Template_Import_Karyawan_ExpenseFlow.${format}`;
    if (format === 'xlsx') {
      XLSX.writeFile(wb, fileName, { bookType: 'xlsx' });
    } else {
      XLSX.writeFile(wb, fileName, { bookType: 'csv' });
    }
  };

  // ─── Generate Preview Data ───────────────────────────────────────────────
  const getMappedRows = () => {
    return rawRows.map((row) => {
      const mappedUser: Record<string, any> = {};

      TARGET_FIELDS.forEach((field) => {
        const sourceHeader = mapping[field.key];
        if (sourceHeader && row[sourceHeader] !== undefined) {
          mappedUser[field.key] = String(row[sourceHeader]).trim();
        }
      });

      return mappedUser;
    });
  };

  const previewRows = getMappedRows().slice(0, 5);
  const totalMapped = Object.keys(mapping).filter((k) => !!mapping[k]).length;
  const isNameMapped = !!mapping['name'];
  const isEmailMapped = !!mapping['email'];

  // ─── Pre-validation Warnings ─────────────────────────────────────────────
  const getPreValidationErrors = () => {
    const errors: string[] = [];
    if (!isNameMapped) errors.push('Kolom "Nama Lengkap" belum dipetakan (wajib).');
    if (!isEmailMapped) errors.push('Kolom "Email Akun" belum dipetakan (wajib).');

    let invalidEmailCount = 0;
    const seenEmails = new Set<string>();
    let duplicateEmailCount = 0;

    const emailHeader = mapping['email'];
    if (emailHeader) {
      rawRows.forEach((row) => {
        const rawEmail = String(row[emailHeader] || '').trim().toLowerCase();
        if (rawEmail) {
          if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(rawEmail)) {
            invalidEmailCount++;
          }
          if (seenEmails.has(rawEmail)) {
            duplicateEmailCount++;
          } else {
            seenEmails.add(rawEmail);
          }
        }
      });
    }

    if (invalidEmailCount > 0) {
      errors.push(`${invalidEmailCount} baris memiliki format email tidak valid.`);
    }
    if (duplicateEmailCount > 0) {
      errors.push(`${duplicateEmailCount} baris memiliki email kembar di dalam file.`);
    }

    return errors;
  };

  const preValidationErrors = getPreValidationErrors();

  // ─── Execute Bulk Import ──────────────────────────────────────────────────
  const handleExecuteImport = async () => {
    setIsSubmitting(true);
    setImportError(null);

    try {
      const mappedList = getMappedRows();

      const payload: BulkImportPayload = {
        users: mappedList.map((u) => ({
          name: u.name || '',
          email: u.email || '',
          employee_code: u.employee_code || undefined,
          identity_number: u.identity_number || undefined,
          phone: u.phone || undefined,
          department: u.department || undefined,
          role: u.role || undefined,
          attendance_setting_id: defaultOfficeId ? Number(defaultOfficeId) : null,
          monthly_claim_limit: u.monthly_claim_limit ? Number(u.monthly_claim_limit) : undefined,
          employment_type: u.employment_type || defaultEmploymentType,
          joined_date: u.joined_date || undefined,
          bank_name: u.bank_name || undefined,
          bank_account_no: u.bank_account_no || undefined,
          bank_account_holder: u.bank_account_holder || undefined,
          leave_balance: u.leave_balance ? Number(u.leave_balance) : 12,
        })),
        default_password: defaultPassword,
        default_role: defaultRole,
        default_attendance_setting_id: defaultOfficeId ? Number(defaultOfficeId) : null,
        default_employment_type: defaultEmploymentType,
        default_attendance_enabled: defaultAttendanceEnabled,
        default_wfh_enabled: defaultWfhEnabled,
        default_radius_enabled: defaultRadiusEnabled,
      };

      const res = await userApi.bulkImport(payload);
      setImportResult(res);
      setStep(5);

      // Invalidate cache
      invalidateCache('/admin/users');
      invalidateCache('/dashboard/attendance/users');

      onSuccess(res.imported);

      if (onAddAuditLog) {
        onAddAuditLog(
          'Impor Data Massal Karyawan',
          `Berhasil mengimpor ${res.imported} dari ${res.total} karyawan dari file ${fileName}.`,
          'bg-emerald-500'
        );
      }
      if (onAddNotification) {
        onAddNotification(
          'success',
          'Impor Karyawan Selesai',
          `${res.imported} karyawan baru berhasil ditambahkan.`
        );
      }
    } catch (err: any) {
      setImportError(err?.message || 'Terjadi kesalahan saat memproses impor data.');
    } finally {
      setIsSubmitting(false);
    }
  };

  const copyErrorLog = () => {
    if (!importResult?.errors?.length) return;
    const text = importResult.errors
      .map((e) => `Baris ${e.row} [${e.name || '-'}] (${e.email || '-'}): ${e.reason}`)
      .join('\n');
    navigator.clipboard.writeText(text);
    setCopiedErrors(true);
    setTimeout(() => setCopiedErrors(false), 2000);
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-xs overflow-y-auto">
      <motion.div
        initial={{ opacity: 0, scale: 0.96 }}
        animate={{ opacity: 1, scale: 1 }}
        exit={{ opacity: 0, scale: 0.96 }}
        className="bg-white dark:bg-slate-900 w-full max-w-4xl rounded-2xl shadow-2xl border border-slate-200 dark:border-slate-800 overflow-hidden my-6 flex flex-col max-h-[90vh]"
      >
        {/* Modal Header */}
        <div className="px-6 py-4 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between bg-slate-50/50 dark:bg-slate-800/40">
          <div className="flex items-center gap-3">
            <div className="p-2.5 rounded-xl bg-emerald-500/10 text-emerald-600 dark:text-emerald-400">
              <FileSpreadsheet className="w-5 h-5" />
            </div>
            <div>
              <h2 className="text-base font-bold text-slate-800 dark:text-slate-100 flex items-center gap-2">
                Migrasi & Impor Data Karyawan
                <span className="text-[11px] font-normal px-2 py-0.5 rounded-full bg-slate-200/70 dark:bg-slate-700 text-slate-600 dark:text-slate-300">
                  Langkah {step} dari 5
                </span>
              </h2>
              <p className="text-xs text-slate-500 dark:text-slate-400">
                {step === 1 && 'Unggah spreadsheet Excel (.xlsx, .xls) atau CSV'}
                {step === 2 && 'Cocokkan nama kolom file Anda dengan field aplikasi (Smart Column Mapping)'}
                {step === 3 && 'Tentukan nilai bawaan untuk data yang tidak ada di file'}
                {step === 4 && 'Periksa pratinjau data sebelum disimpan ke database'}
                {step === 5 && 'Laporan hasil eksekusi impor massal'}
              </p>
            </div>
          </div>
          <button
            onClick={handleClose}
            className="p-1.5 rounded-lg text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 transition"
          >
            <X className="w-5 h-5" />
          </button>
        </div>

        {/* Wizard Steps Progress Indicator */}
        <div className="px-6 py-2.5 bg-slate-100/60 dark:bg-slate-800/30 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between text-xs">
          {[
            { s: 1, label: 'Upload File' },
            { s: 2, label: 'Mapping Kolom' },
            { s: 3, label: 'Nilai Default' },
            { s: 4, label: 'Pratinjau' },
            { s: 5, label: 'Selesai' },
          ].map((item) => (
            <div key={item.s} className="flex items-center gap-2">
              <div
                className={`w-6 h-6 rounded-full flex items-center justify-center font-bold text-[11px] transition ${
                  step === item.s
                    ? 'bg-emerald-600 text-white shadow-xs'
                    : step > item.s
                    ? 'bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300'
                    : 'bg-slate-200 dark:bg-slate-700 text-slate-500 dark:text-slate-400'
                }`}
              >
                {step > item.s ? <Check className="w-3.5 h-3.5" /> : item.s}
              </div>
              <span
                className={`font-medium hidden sm:inline ${
                  step === item.s
                    ? 'text-emerald-700 dark:text-emerald-300 font-bold'
                    : step > item.s
                    ? 'text-slate-700 dark:text-slate-300'
                    : 'text-slate-400 dark:text-slate-500'
                }`}
              >
                {item.label}
              </span>
              {item.s < 5 && <div className="w-4 sm:w-8 h-px bg-slate-200 dark:bg-slate-700" />}
            </div>
          ))}
        </div>

        {/* Modal Body */}
        <div className="p-6 overflow-y-auto flex-1 text-sm text-slate-700 dark:text-slate-200">
          {/* ─── STEP 1: UPLOAD & TEMPLATE ───────────────────────────────── */}
          {step === 1 && (
            <div className="space-y-6">
              {readError && (
                <div className="p-3.5 rounded-xl bg-rose-50 dark:bg-rose-950/40 border border-rose-200 dark:border-rose-900 text-rose-700 dark:text-rose-300 text-xs flex items-center gap-2">
                  <AlertCircle className="w-4 h-4 shrink-0" />
                  <span>{readError}</span>
                </div>
              )}

              {/* Drag & Drop Zone */}
              <div
                onClick={() => fileInputRef.current?.click()}
                onDragOver={(e) => e.preventDefault()}
                onDrop={(e) => {
                  e.preventDefault();
                  if (e.dataTransfer.files?.[0]) {
                    handleFile(e.dataTransfer.files[0]);
                  }
                }}
                className="border-2 border-dashed border-slate-300 dark:border-slate-700 hover:border-emerald-500 dark:hover:border-emerald-500 rounded-2xl p-8 text-center cursor-pointer transition bg-slate-50/50 dark:bg-slate-800/30 group"
              >
                <input
                  ref={fileInputRef}
                  type="file"
                  accept=".xlsx,.xls,.csv"
                  className="hidden"
                  onChange={(e) => {
                    if (e.target.files?.[0]) {
                      handleFile(e.target.files[0]);
                    }
                  }}
                />
                <div className="w-14 h-14 rounded-2xl bg-emerald-50 dark:bg-emerald-950/60 text-emerald-600 dark:text-emerald-400 flex items-center justify-center mx-auto mb-3.5 group-hover:scale-105 transition">
                  {isReadingFile ? (
                    <RefreshCw className="w-7 h-7 animate-spin" />
                  ) : (
                    <Upload className="w-7 h-7" />
                  )}
                </div>
                <h3 className="font-bold text-slate-800 dark:text-slate-100 text-base mb-1">
                  Pilih file spreadsheet Anda atau tarik ke sini
                </h3>
                <p className="text-xs text-slate-500 dark:text-slate-400 mb-3">
                  Mendukung format Microsoft Excel (<strong>.xlsx</strong>, <strong>.xls</strong>) atau <strong>.csv</strong> (Maksimal 1.000 baris per file)
                </p>
                <span className="inline-block py-1.5 px-3.5 rounded-lg bg-emerald-600 text-white text-xs font-semibold shadow-xs">
                  Jelajahi File Komputer
                </span>
              </div>

              {/* Download Standard Template Section */}
              <div className="p-4 rounded-xl border border-slate-200 dark:border-slate-800 bg-slate-50/60 dark:bg-slate-800/40 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
                <div className="flex items-center gap-3">
                  <div className="p-2 rounded-lg bg-indigo-50 dark:bg-indigo-950/60 text-indigo-600 dark:text-indigo-400 shrink-0">
                    <FileText className="w-5 h-5" />
                  </div>
                  <div>
                    <h4 className="font-semibold text-slate-800 dark:text-slate-200 text-xs">
                      Belum punya format Excel? Unduh Template Resmi
                    </h4>
                    <p className="text-[11px] text-slate-500 dark:text-slate-400">
                      Berisi susunan kolom standar beserta contoh data realistis yang siap diisi.
                    </p>
                  </div>
                </div>
                <div className="flex items-center gap-2 shrink-0">
                  <button
                    type="button"
                    onClick={() => downloadTemplate('xlsx')}
                    className="flex items-center gap-1.5 py-1.5 px-3 rounded-lg text-xs font-semibold bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-700 shadow-xs transition"
                  >
                    <Download className="w-3.5 h-3.5 text-emerald-600" />
                    <span>Format .xlsx</span>
                  </button>
                  <button
                    type="button"
                    onClick={() => downloadTemplate('csv')}
                    className="flex items-center gap-1.5 py-1.5 px-3 rounded-lg text-xs font-semibold bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-700 shadow-xs transition"
                  >
                    <Download className="w-3.5 h-3.5 text-blue-600" />
                    <span>Format .csv</span>
                  </button>
                </div>
              </div>
            </div>
          )}

          {/* ─── STEP 2: COLUMN MAPPING ──────────────────────────────────── */}
          {step === 2 && (
            <div className="space-y-4">
              <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2 p-3 rounded-xl bg-emerald-50/70 dark:bg-emerald-950/30 border border-emerald-200/60 dark:border-emerald-800/60 text-emerald-800 dark:text-emerald-300 text-xs">
                <div className="flex items-center gap-2">
                  <CheckCircle2 className="w-4 h-4 text-emerald-600 shrink-0" />
                  <span>
                    Berhasil membaca file <strong>{fileName}</strong> ({rawRows.length} baris data).
                  </span>
                </div>
                <span className="font-semibold px-2 py-0.5 rounded-md bg-white/80 dark:bg-slate-800 text-emerald-700 dark:text-emerald-300 shrink-0">
                  {totalMapped} dari {TARGET_FIELDS.length} field terpetakan
                </span>
              </div>

              <div className="border border-slate-200 dark:border-slate-800 rounded-xl overflow-hidden">
                <table className="w-full text-left text-xs">
                  <thead className="bg-slate-100/70 dark:bg-slate-800/60 text-slate-600 dark:text-slate-300 border-b border-slate-200 dark:border-slate-800 font-semibold">
                    <tr>
                      <th className="p-3">Field di Aplikasi ExpenseFlow</th>
                      <th className="p-3">Keterangan</th>
                      <th className="p-3 w-72">Kolom di File Excel Anda</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                    {TARGET_FIELDS.map((field) => {
                      const selectedHeader = mapping[field.key] || '';
                      return (
                        <tr key={field.key} className="hover:bg-slate-50/50 dark:hover:bg-slate-800/30 transition">
                          <td className="p-3">
                            <div className="flex items-center gap-1.5 font-semibold text-slate-800 dark:text-slate-200">
                              <span>{field.label}</span>
                              {field.required && (
                                <span className="text-rose-500 font-bold" title="Field Wajib">*</span>
                              )}
                            </div>
                          </td>
                          <td className="p-3 text-slate-500 dark:text-slate-400">
                            {field.description}
                          </td>
                          <td className="p-3">
                            <select
                              value={selectedHeader}
                              onChange={(e) => {
                                setMapping({
                                  ...mapping,
                                  [field.key]: e.target.value,
                                });
                              }}
                              className={`w-full py-1.5 px-2.5 rounded-lg border text-xs focus:ring-2 focus:ring-emerald-500 transition ${
                                selectedHeader
                                  ? 'border-emerald-400 bg-emerald-50/40 dark:bg-emerald-950/20 text-emerald-900 dark:text-emerald-200 font-medium'
                                  : field.required
                                  ? 'border-rose-300 bg-rose-50/30 dark:bg-rose-950/20 text-rose-700 dark:text-rose-300'
                                  : 'border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-300'
                              }`}
                            >
                              <option value="">-- Abaikan / Jangan Impor --</option>
                              {fileHeaders.map((header) => (
                                <option key={header} value={header}>
                                  {header}
                                </option>
                              ))}
                            </select>
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            </div>
          )}

          {/* ─── STEP 3: DEFAULT VALUES ──────────────────────────────────── */}
          {step === 3 && (
            <div className="space-y-5">
              <div className="p-3.5 rounded-xl bg-blue-50/70 dark:bg-blue-950/30 border border-blue-200/60 dark:border-blue-800/60 text-blue-800 dark:text-blue-300 text-xs">
                Nilai bawaan di bawah ini akan otomatis diterapkan jika kolom tersebut kosong atau tidak dicantumkan di file Excel Anda.
              </div>

              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                {/* Kantor Penempatan Default */}
                <div>
                  <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1.5">
                    Kantor Penempatan Bawaan
                  </label>
                  <select
                    value={defaultOfficeId}
                    onChange={(e) => setDefaultOfficeId(e.target.value ? Number(e.target.value) : '')}
                    className="w-full py-2 px-3 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-xs font-medium text-slate-800 dark:text-slate-200 focus:ring-2 focus:ring-emerald-500"
                  >
                    <option value="">-- Tanpa Kantor Penempatan --</option>
                    {offices.map((off) => (
                      <option key={off.id} value={off.id}>
                        {off.office_name}
                      </option>
                    ))}
                  </select>
                  <p className="text-[11px] text-slate-400 mt-1">
                    Digunakan untuk radius presensi kantor bagi karyawan baru.
                  </p>
                </div>

                {/* Role Akun Default */}
                <div>
                  <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1.5">
                    Role / Hak Akses Bawaan
                  </label>
                  <select
                    value={defaultRole}
                    onChange={(e) => setDefaultRole(e.target.value)}
                    className="w-full py-2 px-3 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-xs font-medium text-slate-800 dark:text-slate-200 focus:ring-2 focus:ring-emerald-500"
                  >
                    <option value="employee">Karyawan (employee)</option>
                    <option value="finance">Finance / Keuangan</option>
                    <option value="hrd">HRD / Personalia</option>
                    <option value="admin">Admin Perusahaan</option>
                  </select>
                  <p className="text-[11px] text-slate-400 mt-1">
                    Role standar untuk karyawan yang tidak menentukan role di Excel.
                  </p>
                </div>

                {/* Status Hubungan Kerja */}
                <div>
                  <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1.5">
                    Status Hubungan Kerja Bawaan
                  </label>
                  <select
                    value={defaultEmploymentType}
                    onChange={(e) => setDefaultEmploymentType(e.target.value)}
                    className="w-full py-2 px-3 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-xs font-medium text-slate-800 dark:text-slate-200 focus:ring-2 focus:ring-emerald-500"
                  >
                    <option value="PKWTT">PKWTT (Karyawan Tetap)</option>
                    <option value="PKWT">PKWT (Karyawan Kontrak)</option>
                    <option value="Probation">Probation (Masa Percobaan)</option>
                    <option value="Internship">Internship (Magang)</option>
                  </select>
                </div>

                {/* Password Sementara */}
                <div>
                  <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1.5">
                    Password Sementara Default
                  </label>
                  <input
                    type="text"
                    value={defaultPassword}
                    onChange={(e) => setDefaultPassword(e.target.value)}
                    placeholder="Minimal 6 karakter"
                    className="w-full py-2 px-3 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-xs font-medium text-slate-800 dark:text-slate-200 focus:ring-2 focus:ring-emerald-500"
                  />
                  <p className="text-[11px] text-slate-400 mt-1">
                    Karyawan dapat mereset password mereka via fitur Lupa Password di aplikasi.
                  </p>
                </div>
              </div>

              {/* Toggles Izin Akses */}
              <div className="pt-2 border-t border-slate-200 dark:border-slate-800 space-y-3">
                <h4 className="text-xs font-bold text-slate-800 dark:text-slate-200">
                  Izin Presensi & Lokasi Bawaan
                </h4>
                <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                  <label className="flex items-center gap-2 p-3 rounded-xl border border-slate-200 dark:border-slate-800 cursor-pointer hover:bg-slate-50 dark:hover:bg-slate-800/50">
                    <input
                      type="checkbox"
                      checked={defaultAttendanceEnabled}
                      onChange={(e) => setDefaultAttendanceEnabled(e.target.checked)}
                      className="rounded text-emerald-600 focus:ring-emerald-500"
                    />
                    <div className="text-xs">
                      <div className="font-semibold text-slate-800 dark:text-slate-200">Presensi Mobile</div>
                      <div className="text-[10px] text-slate-400">Boleh absen via app</div>
                    </div>
                  </label>
                  <label className="flex items-center gap-2 p-3 rounded-xl border border-slate-200 dark:border-slate-800 cursor-pointer hover:bg-slate-50 dark:hover:bg-slate-800/50">
                    <input
                      type="checkbox"
                      checked={defaultWfhEnabled}
                      onChange={(e) => setDefaultWfhEnabled(e.target.checked)}
                      className="rounded text-emerald-600 focus:ring-emerald-500"
                    />
                    <div className="text-xs">
                      <div className="font-semibold text-slate-800 dark:text-slate-200">Mode WFH</div>
                      <div className="text-[10px] text-slate-400">Boleh presensi di rumah</div>
                    </div>
                  </label>
                  <label className="flex items-center gap-2 p-3 rounded-xl border border-slate-200 dark:border-slate-800 cursor-pointer hover:bg-slate-50 dark:hover:bg-slate-800/50">
                    <input
                      type="checkbox"
                      checked={defaultRadiusEnabled}
                      onChange={(e) => setDefaultRadiusEnabled(e.target.checked)}
                      className="rounded text-emerald-600 focus:ring-emerald-500"
                    />
                    <div className="text-xs">
                      <div className="font-semibold text-slate-800 dark:text-slate-200">Kunci Radius GPS</div>
                      <div className="text-[10px] text-slate-400">Wajib di lokasi kantor</div>
                    </div>
                  </label>
                </div>
              </div>
            </div>
          )}

          {/* ─── STEP 4: PREVIEW & VALIDATION ────────────────────────────── */}
          {step === 4 && (
            <div className="space-y-4">
              {importError && (
                <div className="p-3.5 rounded-xl bg-rose-50 dark:bg-rose-950/40 border border-rose-200 dark:border-rose-900 text-rose-700 dark:text-rose-300 text-xs flex items-center gap-2">
                  <AlertCircle className="w-4 h-4 shrink-0" />
                  <span>{importError}</span>
                </div>
              )}

              {preValidationErrors.length > 0 && (
                <div className="p-3.5 rounded-xl bg-amber-50/80 dark:bg-amber-950/40 border border-amber-200 dark:border-amber-900 text-amber-800 dark:text-amber-300 text-xs space-y-1">
                  <div className="font-bold flex items-center gap-1.5">
                    <AlertTriangle className="w-4 h-4 text-amber-600" />
                    <span>Catatan Peringatan Pra-Impor:</span>
                  </div>
                  <ul className="list-disc list-inside space-y-0.5 text-[11px] pl-1">
                    {preValidationErrors.map((err, idx) => (
                      <li key={idx}>{err}</li>
                    ))}
                  </ul>
                  <p className="text-[10px] text-amber-700/80 dark:text-amber-400/80 pt-1">
                    * Baris yang bermasalah akan otomatis dilewati secara aman saat proses impor berlangsung.
                  </p>
                </div>
              )}

              <div className="flex items-center justify-between">
                <span className="text-xs font-bold text-slate-700 dark:text-slate-300 flex items-center gap-1.5">
                  <Eye className="w-4 h-4 text-indigo-500" />
                  Pratinjau 5 Baris Data Pertama (Total: {rawRows.length} Karyawan)
                </span>
                <span className="text-[11px] text-slate-400">
                  Password bawaan: <code className="bg-slate-100 dark:bg-slate-800 px-1.5 py-0.5 rounded font-mono">{defaultPassword}</code>
                </span>
              </div>

              <div className="border border-slate-200 dark:border-slate-800 rounded-xl overflow-x-auto">
                <table className="w-full text-left text-xs">
                  <thead className="bg-slate-100/70 dark:bg-slate-800/60 text-slate-600 dark:text-slate-300 border-b border-slate-200 dark:border-slate-800 font-semibold whitespace-nowrap">
                    <tr>
                      <th className="p-2.5">No</th>
                      <th className="p-2.5">NIK</th>
                      <th className="p-2.5">Nama Lengkap</th>
                      <th className="p-2.5">Email Akun</th>
                      <th className="p-2.5">No HP</th>
                      <th className="p-2.5">Departemen</th>
                      <th className="p-2.5">Bank & Rekening</th>
                      <th className="p-2.5">Saldo Cuti</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100 dark:divide-slate-800 whitespace-nowrap">
                    {previewRows.map((row, idx) => (
                      <tr key={idx} className="hover:bg-slate-50/50 dark:hover:bg-slate-800/30">
                        <td className="p-2.5 text-slate-400">{idx + 1}</td>
                        <td className="p-2.5 font-mono text-slate-600 dark:text-slate-400">
                          {row.employee_code || '-'}
                        </td>
                        <td className="p-2.5 font-semibold text-slate-800 dark:text-slate-200">
                          {row.name || <span className="text-rose-500 font-normal italic">Kosong</span>}
                        </td>
                        <td className="p-2.5 text-slate-600 dark:text-slate-300">
                          {row.email ? (
                            <span>{row.email}</span>
                          ) : (
                            <span className="text-rose-500 italic">Kosong</span>
                          )}
                        </td>
                        <td className="p-2.5 text-slate-500 dark:text-slate-400">
                          {row.phone || '-'}
                        </td>
                        <td className="p-2.5 text-slate-600 dark:text-slate-300">
                          {row.department || '-'}
                        </td>
                        <td className="p-2.5 text-slate-500 dark:text-slate-400">
                          {row.bank_name ? `${row.bank_name} - ${row.bank_account_no || '-'}` : '-'}
                        </td>
                        <td className="p-2.5 text-slate-600 dark:text-slate-300 font-semibold">
                          {row.leave_balance ? `${row.leave_balance} Hari` : '12 Hari (Default)'}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          )}

          {/* ─── STEP 5: RESULTS REPORT ──────────────────────────────────── */}
          {step === 5 && importResult && (
            <div className="space-y-5 text-center py-4">
              <div className="w-16 h-16 rounded-full bg-emerald-100 dark:bg-emerald-950/60 text-emerald-600 dark:text-emerald-400 flex items-center justify-center mx-auto shadow-sm">
                <CheckCircle2 className="w-9 h-9" />
              </div>

              <div>
                <h3 className="text-lg font-bold text-slate-800 dark:text-slate-100 mb-1">
                  Impor Data Karyawan Selesai!
                </h3>
                <p className="text-xs text-slate-500 dark:text-slate-400">
                  {importResult.message}
                </p>
              </div>

              {/* Statistics Cards */}
              <div className="grid grid-cols-3 gap-3 max-w-lg mx-auto">
                <div className="p-3.5 rounded-xl border border-slate-200 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-800/40">
                  <div className="text-xl font-extrabold text-slate-800 dark:text-slate-100">
                    {importResult.total}
                  </div>
                  <div className="text-[11px] text-slate-400 font-medium">Total Baris File</div>
                </div>
                <div className="p-3.5 rounded-xl border border-emerald-200 dark:border-emerald-900 bg-emerald-50/40 dark:bg-emerald-950/20">
                  <div className="text-xl font-extrabold text-emerald-600 dark:text-emerald-400">
                    {importResult.imported}
                  </div>
                  <div className="text-[11px] text-emerald-700/80 dark:text-emerald-400 font-medium">Berhasil Ditambahkan</div>
                </div>
                <div className="p-3.5 rounded-xl border border-amber-200 dark:border-amber-900 bg-amber-50/40 dark:bg-amber-950/20">
                  <div className="text-xl font-extrabold text-amber-600 dark:text-amber-400">
                    {importResult.skipped}
                  </div>
                  <div className="text-[11px] text-amber-700/80 dark:text-amber-400 font-medium">Dilewati / Gagal</div>
                </div>
              </div>

              {/* Error list if any */}
              {importResult.errors && importResult.errors.length > 0 && (
                <div className="text-left border border-amber-200 dark:border-amber-900/60 rounded-xl overflow-hidden max-w-2xl mx-auto">
                  <div className="p-3 bg-amber-50/70 dark:bg-amber-950/40 border-b border-amber-200 dark:border-amber-900/60 flex items-center justify-between text-xs">
                    <span className="font-bold text-amber-800 dark:text-amber-300 flex items-center gap-1.5">
                      <AlertTriangle className="w-4 h-4 text-amber-600" />
                      Detail {importResult.errors.length} Baris yang Dilewati
                    </span>
                    <button
                      type="button"
                      onClick={copyErrorLog}
                      className="flex items-center gap-1 py-1 px-2.5 rounded-md bg-white dark:bg-slate-800 text-[11px] font-semibold text-slate-700 dark:text-slate-300 border border-slate-300 dark:border-slate-700 shadow-2xs hover:bg-slate-50 transition"
                    >
                      <Copy className="w-3 h-3" />
                      <span>{copiedErrors ? 'Disalin!' : 'Salin Log Error'}</span>
                    </button>
                  </div>
                  <div className="max-h-40 overflow-y-auto divide-y divide-slate-100 dark:divide-slate-800 text-xs">
                    {importResult.errors.map((err, idx) => (
                      <div key={idx} className="p-2.5 flex items-start gap-2 bg-white dark:bg-slate-900">
                        <span className="px-1.5 py-0.5 rounded bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400 font-mono text-[10px] shrink-0">
                          Baris {err.row}
                        </span>
                        <div className="flex-1">
                          <span className="font-semibold text-slate-800 dark:text-slate-200 mr-1.5">
                            {err.name} ({err.email})
                          </span>
                          <span className="text-rose-600 dark:text-rose-400 font-medium">
                            — {err.reason}
                          </span>
                        </div>
                      </div>
                    ))}
                  </div>
                </div>
              )}
            </div>
          )}
        </div>

        {/* Modal Footer */}
        <div className="px-6 py-3.5 border-t border-slate-100 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-800/40 flex items-center justify-between">
          <div>
            {step > 1 && step < 5 && (
              <button
                type="button"
                onClick={() => setStep((s) => (s - 1) as WizardStep)}
                className="flex items-center gap-1.5 py-2 px-3.5 rounded-xl text-xs font-semibold text-slate-600 dark:text-slate-300 hover:bg-slate-200/60 dark:hover:bg-slate-700 transition"
              >
                <ArrowLeft className="w-4 h-4" />
                <span>Sebelumnya</span>
              </button>
            )}
          </div>

          <div className="flex items-center gap-2">
            {step < 5 && (
              <button
                type="button"
                onClick={handleClose}
                className="py-2 px-4 rounded-xl text-xs font-semibold text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800 transition"
              >
                Batal
              </button>
            )}

            {step === 2 && (
              <button
                type="button"
                disabled={!isNameMapped || !isEmailMapped}
                onClick={() => setStep(3)}
                className="flex items-center gap-1.5 py-2 px-4 rounded-xl text-xs font-bold bg-emerald-600 hover:bg-emerald-700 disabled:opacity-50 disabled:cursor-not-allowed text-white shadow-sm transition"
              >
                <span>Lanjut ke Nilai Bawaan</span>
                <ArrowRight className="w-4 h-4" />
              </button>
            )}

            {step === 3 && (
              <button
                type="button"
                onClick={() => setStep(4)}
                className="flex items-center gap-1.5 py-2 px-4 rounded-xl text-xs font-bold bg-emerald-600 hover:bg-emerald-700 text-white shadow-sm transition"
              >
                <span>Lanjut ke Pratinjau</span>
                <ArrowRight className="w-4 h-4" />
              </button>
            )}

            {step === 4 && (
              <button
                type="button"
                disabled={isSubmitting || !isNameMapped || !isEmailMapped}
                onClick={handleExecuteImport}
                className="flex items-center gap-1.5 py-2 px-5 rounded-xl text-xs font-bold bg-emerald-600 hover:bg-emerald-700 disabled:opacity-50 text-white shadow-md shadow-emerald-600/20 transition"
              >
                {isSubmitting ? (
                  <>
                    <RefreshCw className="w-4 h-4 animate-spin" />
                    <span>Memproses Impor...</span>
                  </>
                ) : (
                  <>
                    <Check className="w-4 h-4" />
                    <span>Mulai Impor ({rawRows.length} Karyawan)</span>
                  </>
                )}
              </button>
            )}

            {step === 5 && (
              <button
                type="button"
                onClick={handleClose}
                className="py-2 px-5 rounded-xl text-xs font-bold bg-emerald-600 hover:bg-emerald-700 text-white shadow-sm transition"
              >
                Tutup & Lihat Data
              </button>
            )}
          </div>
        </div>
      </motion.div>
    </div>
  );
};
