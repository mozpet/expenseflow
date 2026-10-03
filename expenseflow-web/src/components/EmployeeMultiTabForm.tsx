import React from 'react';
import {
  Award,
  Briefcase,
  UserCheck,
  Wallet,
  ShieldCheck,
  Shield,
  Smartphone,
  Check,
  ChevronLeft,
  ChevronRight,
  Eye,
  EyeOff,
  Building,
  Clock,
  Sparkles,
  Phone,
  Mail,
  Heart,
  MapPin,
  PhoneCall,
  FileText,
  AlertTriangle,
  Info,
  Lock,
  Ban,
  RefreshCw,
  Landmark,
  BadgePercent,
  X,
  UploadCloud,
  Trash2,
  Download,
  ExternalLink,
  File,
  Plus,
  Loader2,
  GraduationCap,
  UserX,
  Receipt,
  Monitor,
  Infinity as InfinityIcon,
} from 'lucide-react';
import CustomDatePicker from './CustomDatePicker';
import { userDocumentApi, UserDocument, roleApi, RoleItem, userApi, DivisionItem, PositionItem } from '../services/endpoints';
import { RoleFormModal } from './RoleFormModal';
import { useAuth } from '../auth/AuthContext';
import { FormTabType, FORM_TABS } from './KaryawanManagement';

interface Office {
  id: number;
  office_name: string;
  overtime_enabled?: boolean;
}

interface EmployeeMultiTabFormProps {
  isEdit: boolean;
  formTab: FormTabType;
  setFormTab: (tab: FormTabType) => void;
  form: any;
  setForm: React.Dispatch<React.SetStateAction<any>>;
  onSubmit: (e: React.FormEvent) => void;
  onCancel: () => void;
  submitting: boolean;
  editEmployee?: any | null;
  offices: Office[];
  departments: string[];
  divisions?: DivisionItem[];
  positions?: PositionItem[];
}

export const EmployeeMultiTabForm: React.FC<EmployeeMultiTabFormProps> = ({
  isEdit,
  formTab,
  setFormTab,
  form,
  setForm,
  onSubmit,
  onCancel,
  submitting,
  editEmployee,
  offices,
  departments,
  divisions = [],
  positions = [],
}) => {
  // Hitung kategori TER berdasarkan status PTKP
  const getTerCategory = (ptkp: string): { cat: 'A' | 'B' | 'C'; desc: string } => {
    const terA = ['TK/0', 'TK/1', 'K/0'];
    const terB = ['TK/2', 'TK/3', 'K/1', 'K/2'];
    if (terA.includes(ptkp)) return { cat: 'A', desc: 'Tarif Efektif Bulanan Kategori A (Penghasilan s.d Rp 5,4 Juta = 0%)' };
    if (terB.includes(ptkp)) return { cat: 'B', desc: 'Tarif Efektif Bulanan Kategori B (Penghasilan s.d Rp 6,2 Juta = 0%)' };
    return { cat: 'C', desc: 'Tarif Efektif Bulanan Kategori C (Penghasilan s.d Rp 6,6 Juta = 0%)' };
  };

  const terInfo = getTerCategory(form.ptkpStatus || 'TK/0');

  // Kantor penempatan terpilih
  const selectedOffice = React.useMemo(() => {
    if (!form.officeId) return null;
    return offices.find((o) => Number(o.id) === Number(form.officeId)) || null;
  }, [form.officeId, offices]);

  // Cek apakah cabang ini menonaktifkan hitung lembur otomatis
  const isBranchOvertimeDisabled = selectedOffice ? selectedOffice.overtime_enabled === false : false;

  // Jika kantor cabang yang dipilih menonaktifkan lembur, pastikan form.overtimeEligible otomatis diset false
  React.useEffect(() => {
    if (isBranchOvertimeDisabled && form.overtimeEligible) {
      setForm((prev: any) => ({ ...prev, overtimeEligible: false }));
    }
  }, [isBranchOvertimeDisabled, form.overtimeEligible, setForm]);

  // Kalkulasi umur otomatis dari tanggal lahir
  const calculatedAge = React.useMemo(() => {
    if (!form.birthDate) return null;
    const birth = new Date(form.birthDate);
    if (isNaN(birth.getTime())) return null;
    const today = new Date();
    let age = today.getFullYear() - birth.getFullYear();
    const monthDiff = today.getMonth() - birth.getMonth();
    if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birth.getDate())) {
      age--;
    }
    return age >= 0 ? age : null;
  }, [form.birthDate]);

  // Indikator kelengkapan tab untuk visual stepper (Hanya hijau jika data esensial tab telah terisi)
  const getTabStatus = React.useCallback((tabId: FormTabType): { isComplete: boolean } => {
    const isDeskless = form.can_login === false;

    // Tab 1: Pekerjaan & Organisasi
    if (tabId === 'work') {
      const hasName = Boolean(form.nama && String(form.nama).trim().length > 0);
      const hasEmail = isDeskless ? true : Boolean(form.email && String(form.email).trim().length > 0);
      const hasRole = Boolean(form.role || form.role_id);
      return { isComplete: hasName && hasEmail && hasRole };
    }

    // Tab 2: Data Pribadi & Kontak Darurat (Wajib NIK 16 digit, No HP, & Tgl Lahir)
    if (tabId === 'personal') {
      const hasNik = Boolean(form.nikKtp && String(form.nikKtp).replace(/\D/g, '').length === 16);
      const hasHp = Boolean(form.hp && String(form.hp).replace(/\D/g, '').length >= 10);
      const hasBirthDate = Boolean(form.birthDate && String(form.birthDate).trim().length > 0);
      return { isComplete: hasNik && hasHp && hasBirthDate };
    }

    // Tab 3: Finansial & Payroll (Wajib Gaji Pokok > 0 & No Rekening)
    if (tabId === 'payroll') {
      const hasSalary = Boolean(form.basicSalary && Number(form.basicSalary) > 0);
      const hasAccount = Boolean(form.bankAccountNo && String(form.bankAccountNo).trim().length >= 5);
      return { isComplete: hasSalary && hasAccount };
    }

    // Tab 4: BPJS & Perpajakan (Minimal salah satu nomor BPJS atau NPWP terisi)
    if (tabId === 'bpjs') {
      const hasBpjsKes = Boolean(form.bpjsKesehatanNo && String(form.bpjsKesehatanNo).trim().length >= 10);
      const hasBpjsTk = Boolean(form.bpjsKetenagakerjaanNo && String(form.bpjsKetenagakerjaanNo).trim().length >= 10);
      const hasNpwp = Boolean(form.npwp && String(form.npwp).replace(/\D/g, '').length >= 15);
      return { isComplete: hasBpjsKes || hasBpjsTk || hasNpwp };
    }

    // Tab 5: Akses Sistem & Dokumen
    if (tabId === 'access') {
      if (isEdit) return { isComplete: true };
      if (isDeskless) {
        // Mode non-sistem tidak butuh password, tapi nama karyawan harus sudah mulai diisi
        return { isComplete: Boolean(form.nama && String(form.nama).trim().length > 0) };
      }
      const hasPass = Boolean(form.password && String(form.password).length >= 8);
      const match = Boolean(hasPass && form.password === form.confirmPassword);
      return { isComplete: match };
    }

    return { isComplete: false };
  }, [
    form.nama,
    form.email,
    form.role,
    form.role_id,
    form.can_login,
    form.nikKtp,
    form.hp,
    form.birthDate,
    form.basicSalary,
    form.bankAccountNo,
    form.bpjsKesehatanNo,
    form.bpjsKetenagakerjaanNo,
    form.npwp,
    form.password,
    form.confirmPassword,
    isEdit,
  ]);

  // Generator password acak 1-klik untuk akun baru
  const generateRandomPassword = () => {
    const uppercase = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    const lowercase = 'abcdefghijkmnpqrstuvwxyz';
    const numbers = '23456789';
    const specials = '!@#$%&*';
    let res = '';
    res += uppercase[Math.floor(Math.random() * uppercase.length)];
    res += lowercase[Math.floor(Math.random() * lowercase.length)];
    res += numbers[Math.floor(Math.random() * numbers.length)];
    res += specials[Math.floor(Math.random() * specials.length)];
    const all = uppercase + lowercase + numbers + specials;
    for (let i = 0; i < 6; i++) {
      res += all[Math.floor(Math.random() * all.length)];
    }
    setForm((prev: any) => ({
      ...prev,
      password: res,
      confirmPassword: res,
      showPassword: true,
    }));
  };

  // ─── State Dokumen Digital Karyawan (Fitur 4) ────────────────────
  const [documents, setDocuments] = React.useState<UserDocument[]>([]);
  const [loadingDocs, setLoadingDocs] = React.useState<boolean>(false);
  const [uploadingType, setUploadingType] = React.useState<string | null>(null);
  const [deletingDocId, setDeletingDocId] = React.useState<number | null>(null);
  const [previewDoc, setPreviewDoc] = React.useState<{ url: string; title: string; isPdf: boolean; isImage: boolean } | null>(null);
  const [customDocType, setCustomDocType] = React.useState<string>('lainnya');
  const [customDocTitle, setCustomDocTitle] = React.useState<string>('');

  // ─── State Role Dinamis & In-place Role Creator ──────────────────
  const { user: currentUser } = useAuth();
  const canManageRoles =
    currentUser?.role === 'super_admin' ||
    currentUser?.role === 'admin' ||
    Boolean((currentUser as any)?.can_manage_roles);

  const [availableRoles, setAvailableRoles] = React.useState<RoleItem[]>([]);
  const [isRoleModalOpen, setIsRoleModalOpen] = React.useState<boolean>(false);
  const [roleToEditInModal, setRoleToEditInModal] = React.useState<RoleItem | null>(null);

  React.useEffect(() => {
    roleApi
      .list()
      .then((res: any) => {
        if (res?.data && Array.isArray(res.data)) {
          setAvailableRoles(res.data);
        }
      })
      .catch(() => {});
  }, []);

  React.useEffect(() => {
    if (offices.length === 1 && !form.officeId) {
      setForm((prev: any) => ({ ...prev, officeId: offices[0].id }));
    }
  }, [offices, form.officeId, setForm]);

  const currentSelectedRole = React.useMemo(() => {
    const roleIdOrSlug = form.role_id || form.role;
    return availableRoles.find(
      (r) => r.id === roleIdOrSlug || String(r.id) === String(roleIdOrSlug) || r.slug === roleIdOrSlug
    ) || null;
  }, [availableRoles, form.role_id, form.role]);

  // Sinkronisasi otomatis role_id dan role slug ke state form saat role ditemukan
  React.useEffect(() => {
    if (currentSelectedRole) {
      if (form.role_id !== currentSelectedRole.id || form.role !== currentSelectedRole.slug) {
        setForm((prev: any) => ({
          ...prev,
          role: currentSelectedRole.slug,
          role_id: currentSelectedRole.id,
        }));
      }
    }
  }, [currentSelectedRole, form.role_id, form.role, setForm]);

  const handleRoleSavedInEmployeeForm = (savedRole: RoleItem) => {
    setAvailableRoles((prev) => {
      const exists = prev.some((r) => r.id === savedRole.id);
      if (exists) {
        return prev.map((r) => (r.id === savedRole.id ? savedRole : r));
      }
      return [...prev, savedRole];
    });

    // Otomatis pasang role yang baru dibuat / diedit ke form karyawan
    setForm((prev: any) => ({
      ...prev,
      role: savedRole.slug,
      role_id: savedRole.id,
    }));

    setIsRoleModalOpen(false);
    setRoleToEditInModal(null);
  };

  // ─── State Supervisor (Atasan Langsung) & Posisi ────────────────
  const [supervisors, setSupervisors] = React.useState<any[]>([]);
  const [loadingSupervisors, setLoadingSupervisors] = React.useState<boolean>(false);

  React.useEffect(() => {
    let active = true;
    setLoadingSupervisors(true);
    userApi
      .supervisors({
        division_id: form.division_id || undefined,
        attendance_setting_id: form.officeId || undefined,
        exclude_user_id: editEmployee?.backendId || undefined,
      })
      .then((res: any) => {
        if (active) {
          const list = Array.isArray(res?.data)
            ? res.data
            : Array.isArray(res?.supervisors)
            ? res.supervisors
            : Array.isArray(res)
            ? res
            : [];
          setSupervisors(list);
        }
      })
      .catch((err) => {
        console.error('Gagal memuat supervisor:', err);
      })
      .finally(() => {
        if (active) setLoadingSupervisors(false);
      });

    return () => {
      active = false;
    };
  }, [form.division_id, form.officeId, editEmployee?.backendId]);

  // Daftar posisi yang sesuai divisi terpilih
  const availablePositions = React.useMemo(() => {
    if (!positions || positions.length === 0) return [];
    if (!form.division_id) return positions;
    return positions.filter(
      (p) => !p.division_id || String(p.division_id) === String(form.division_id)
    );
  }, [positions, form.division_id]);

  const selectedPosition = React.useMemo(() => {
    return positions.find((p) => String(p.id) === String(form.position_id));
  }, [positions, form.position_id]);

  // Muat dokumen digital saat mode edit dan tab access dibuka
  React.useEffect(() => {
    if (isEdit && editEmployee?.backendId && formTab === 'access') {
      setLoadingDocs(true);
      userDocumentApi.list(editEmployee.backendId)
        .then((res) => {
          setDocuments(res.documents || []);
        })
        .catch((err) => {
          console.error('Gagal memuat dokumen digital karyawan', err);
        })
        .finally(() => {
          setLoadingDocs(false);
        });
    }
  }, [isEdit, editEmployee?.backendId, formTab]);

  const handleUploadDocument = async (docType: string, file: File, title?: string) => {
    if (!editEmployee?.backendId) return;
    const docConfig = STANDARD_DOCUMENTS.find(d => d.type === docType);
    if (!validateFileSize(file, docConfig?.allowedFormat)) return;

    setUploadingType(docType);
    const formData = new FormData();
    formData.append('file', file);
    formData.append('document_type', docType);
    if (title) formData.append('title', title);
    try {
      const res = await userDocumentApi.upload(editEmployee.backendId, formData);
      if (res.document) {
        setDocuments((prev) => [res.document, ...prev.filter((d) => d.id !== res.document.id)]);
      }
    } catch (err: any) {
      alert(err?.message || 'Gagal mengunggah berkas dokumen');
    } finally {
      setUploadingType(null);
    }
  };

  const handleDeleteDocument = async (docId: number, title: string) => {
    if (!editEmployee?.backendId) return;
    if (!window.confirm(`Apakah Anda yakin ingin menghapus berkas "${title}" dari arsip karyawan?`)) return;
    setDeletingDocId(docId);
    try {
      await userDocumentApi.delete(editEmployee.backendId, docId);
      setDocuments((prev) => prev.filter((d) => d.id !== docId));
    } catch (err: any) {
      alert(err?.message || 'Gagal menghapus berkas dokumen');
    } finally {
      setDeletingDocId(null);
    }
  };

  const handlePreviewDocument = async (doc: UserDocument) => {
    if (!editEmployee?.backendId) return;
    if (doc.is_image) {
      setPreviewDoc({
        url: userDocumentApi.streamUrl(editEmployee.backendId, doc.id),
        title: doc.title || doc.file_name,
        isPdf: false,
        isImage: true,
      });
    } else if (doc.is_pdf) {
      try {
        await userDocumentApi.viewFile(editEmployee.backendId, doc.id, doc.title || doc.file_name);
      } catch (err: any) {
        alert(err?.message || 'Gagal membuka berkas PDF');
      }
    } else {
      userDocumentApi.download(editEmployee.backendId, doc.id, doc.file_name);
    }
  };

  const handleSelectPendingDocument = (docType: string, file: File) => {
    const docConfig = STANDARD_DOCUMENTS.find(d => d.type === docType);
    if (!validateFileSize(file, docConfig?.allowedFormat)) return;

    setForm((prev: any) => ({
      ...prev,
      pendingDocuments: {
        ...(prev.pendingDocuments || {}),
        [docType]: file,
      },
    }));
  };

  const handleRemovePendingDocument = (docType: string) => {
    setForm((prev: any) => {
      const updated = { ...(prev.pendingDocuments || {}) };
      delete updated[docType];
      return { ...prev, pendingDocuments: updated };
    });
  };

  const STANDARD_DOCUMENTS = [
    {
      type: 'ktp',
      label: 'Foto / Scan KTP Asli',
      badge: 'Identitas Resmi',
      desc: 'Verifikasi NIK 16 digit & kesesuaian identitas kependudukan',
      icon: UserCheck,
      required: true,
      allowedFormat: 'image',
    },
    {
      type: 'kartu_keluarga',
      label: 'Kartu Keluarga (KK)',
      badge: 'Tanggungan BPJS & PTKP',
      desc: 'Verifikasi susunan keluarga untuk PTKP & kepesertaan BPJS',
      icon: Heart,
      required: false,
      allowedFormat: 'both',
    },
    {
      type: 'npwp',
      label: 'Kartu NPWP',
      badge: 'Perpajakan PPh 21',
      desc: 'Bukti wajib pajak untuk perhitungan PPh 21 TER 2024',
      icon: BadgePercent,
      required: false,
      allowedFormat: 'image',
    },
    {
      type: 'buku_tabungan',
      label: 'Buku Tabungan / Rekening',
      badge: 'Payroll Perbankan',
      desc: 'Konfirmasi nama pemegang & nomor rekening transfer gaji',
      icon: Landmark,
      required: false,
      allowedFormat: 'image',
    },
    {
      type: 'kontrak_kerja',
      label: 'Surat Kontrak Kerja (SPK)',
      badge: 'Legalitas Kepegawaian',
      desc: 'Surat perjanjian kerja (PKWT/PKWTT) bertandatangan lengkap',
      icon: Briefcase,
      required: false,
      allowedFormat: 'pdf',
    },
    {
      type: 'ijazah',
      label: 'Ijazah Terakhir',
      badge: 'Kualifikasi Disnaker',
      desc: 'Ijazah pendidikan formal untuk pelaporan WLKP Kemenaker',
      icon: FileText,
      required: false,
      allowedFormat: 'pdf',
    },
    {
      type: 'sertifikat',
      label: 'Sertifikat Keahlian / K3',
      badge: 'Kompetensi K3',
      desc: 'Sertifikasi K3, lisensi operator (SIO), atau SIM operasional',
      icon: ShieldCheck,
      required: false,
      allowedFormat: 'pdf',
    },
  ];

  const getAcceptString = (allowedFormat?: string) => {
    if (allowedFormat === 'image') return '.jpg,.jpeg,.png,.webp';
    if (allowedFormat === 'pdf') return '.pdf';
    return '.pdf,.jpg,.jpeg,.png,.webp';
  };

  const validateFileSize = (file: File, allowedFormat?: string) => {
    const isImage = file.type.startsWith('image/');
    // Jika format spesifik gambar ATAU tipe aslinya gambar, cek max 5MB
    if (allowedFormat === 'image' || isImage) {
      if (file.size > 5 * 1024 * 1024) {
        alert('Ukuran foto maksimal 5 MB!');
        return false;
      }
    } else {
      // PDF atau dokumen lainnya max 10MB
      if (file.size > 10 * 1024 * 1024) {
        alert('Ukuran dokumen maksimal 10 MB!');
        return false;
      }
    }
    return true;
  };

  return (
    <form onSubmit={onSubmit} className="space-y-6 leading-relaxed">

      {/* ─── 1. FORM HEADER ────────────────────────────────────────────── */}
      <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-3xl p-6 shadow-xs">
        <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
          <div>
            <div className="flex items-center gap-2 mb-1">
              <button
                type="button"
                onClick={onCancel}
                className="p-1 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 transition cursor-pointer"
                title="Kembali ke daftar"
              >
                <ChevronLeft className="w-5 h-5" />
              </button>
              <h2 className="text-base font-extrabold text-slate-900 dark:text-white flex items-center gap-2">
                {isEdit ? (
                  <>
                    <Briefcase className="w-5 h-5 text-indigo-600" />
                    Edit Data Karyawan:{' '}
                    <span className="text-indigo-600 dark:text-indigo-400 font-bold">
                      {editEmployee?.nama || form.nama}
                    </span>{' '}
                    <span className="text-xs font-mono font-normal text-slate-400">
                      ({editEmployee?.id})
                    </span>
                  </>
                ) : (
                  <>
                    <UserCheck className="w-5 h-5 text-indigo-600" />
                    Tambah Karyawan Baru
                  </>
                )}
              </h2>
            </div>
            <p className="text-xs text-slate-500 dark:text-slate-400 ml-7">
              {isEdit
                ? 'Perbarui informasi kepegawaian, kontak darurat K3, rekening perbankan, dan data perpajakan karyawan.'
                : 'Lengkapi formulir 5 tab di bawah untuk mendaftarkan karyawan baru ke sistem HR & Presensi.'}
            </p>
          </div>

          <div className="flex items-center gap-2 self-end sm:self-auto shrink-0">
            <span className="px-3 py-1 text-xs font-bold rounded-full bg-indigo-50 dark:bg-indigo-950/50 text-indigo-700 dark:text-indigo-400 border border-indigo-100 dark:border-indigo-800">
              Langkah {FORM_TABS.find(t => t.id === formTab)?.step} dari 5
            </span>
          </div>
        </div>
      </div>

      {/* ─── 2. STEPPER TAB BAR (5 TABS) ───────────────────────────────── */}
      <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl p-1.5 shadow-xs overflow-x-auto scrollbar-none">
        <div className="flex items-center gap-1.5 min-w-max">
          {FORM_TABS.map((tab) => {
            const Icon = tab.icon;
            const isActive = formTab === tab.id;
            return (
              <button
                key={tab.id}
                type="button"
                onClick={() => setFormTab(tab.id)}
                className={`flex items-center gap-3 px-4 py-3 rounded-xl transition cursor-pointer text-left ${
                  isActive
                    ? 'bg-indigo-600 text-white font-bold shadow-sm ring-1 ring-indigo-500/20'
                    : 'text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800/60'
                }`}
              >
                <div
                  className={`w-7 h-7 rounded-lg flex items-center justify-center text-xs font-black shrink-0 relative ${
                    isActive
                      ? 'bg-white/20 text-white'
                      : 'bg-slate-100 dark:bg-slate-800 text-slate-500 dark:text-slate-400'
                  }`}
                >
                  {tab.step}
                  {getTabStatus(tab.id).isComplete && (
                    <span className="absolute -top-1 -right-1 w-3.5 h-3.5 rounded-full bg-emerald-500 text-white flex items-center justify-center shadow-xs" title="Data pada tab ini lengkap">
                      <Check className="w-2 h-2 stroke-[3]" />
                    </span>
                  )}
                </div>
                <div className="min-w-0 pr-1">
                  <div className="text-xs font-extrabold leading-tight flex items-center gap-1.5">
                    <Icon className={`w-3.5 h-3.5 ${isActive ? 'text-white' : 'text-slate-400'}`} />
                    <span>{tab.label}</span>
                  </div>
                  <div
                    className={`text-[10px] mt-0.5 font-normal truncate ${
                      isActive ? 'text-indigo-100' : 'text-slate-400 dark:text-slate-500'
                    }`}
                  >
                    {tab.sublabel}
                  </div>
                </div>
              </button>
            );
          })}
        </div>
      </div>

      {/* ─── 3. TAB 1: PEKERJAAN & ORGANISASI ──────────────────────────── */}
      {formTab === 'work' && (
        <div className="space-y-6">
          {/* Status Hubungan Kerja Karyawan Selector */}
          <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-3xl p-6 shadow-xs space-y-4">
            <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-2 pb-3 border-b border-slate-100 dark:border-slate-800">
              <div>
                <span className="text-xs font-bold text-slate-800 dark:text-slate-100 flex items-center gap-2">
                  <Briefcase className="w-4 h-4 text-indigo-600" />
                  Status Hubungan Kerja Karyawan *
                </span>
                <p className="text-[11px] text-slate-400 dark:text-slate-500 mt-0.5">
                  Formulir dan skema benefit BPJS otomatis menyesuaikan pilihan status kontrak kerja ini.
                </p>
              </div>
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
              {/* PKWTT */}
              <button
                type="button"
                onClick={() =>
                  setForm({
                    ...form,
                    employmentType: 'PKWTT',
                    hasJht: true,
                    hasJp: true,
                    salaryType: 'monthly',
                    overtimeEligible: isBranchOvertimeDisabled ? false : true,
                  })
                }
                className={`p-4 rounded-2xl border text-left transition duration-150 cursor-pointer flex flex-col justify-between relative overflow-hidden ${
                  form.employmentType === 'PKWTT'
                    ? 'bg-teal-50/60 dark:bg-teal-950/30 border-teal-500 ring-2 ring-teal-500/20 shadow-sm'
                    : 'bg-white dark:bg-slate-800/40 border-slate-200 dark:border-slate-700 hover:border-teal-300 dark:hover:border-teal-700'
                }`}
              >
                {form.employmentType === 'PKWTT' && (
                  <span className="absolute top-2 right-2 w-5 h-5 rounded-full bg-teal-500 text-white flex items-center justify-center">
                    <Check className="w-3 h-3" />
                  </span>
                )}
                <div>
                  <div className="w-8 h-8 rounded-xl bg-teal-100 dark:bg-teal-900/50 text-teal-700 dark:text-teal-400 flex items-center justify-center mb-2.5">
                    <ShieldCheck className="w-4.5 h-4.5" />
                  </div>
                  <h4 className="text-xs font-bold text-slate-800 dark:text-slate-100">PKWTT (Tetap)</h4>
                  <p className="text-[10px] text-slate-500 dark:text-slate-400 mt-1 leading-relaxed">
                    Karyawan tetap tanpa batas kontrak. Fasilitas BPJS lengkap (JHT + JP) & cuti tahunan penuh.
                  </p>
                </div>
                <div className="mt-3 pt-2.5 border-t border-teal-100 dark:border-teal-900/40 text-[9px] font-semibold text-teal-700 dark:text-teal-400">
                  • Gaji Bulanan • BPJS Lengkap
                </div>
              </button>

              {/* PKWT */}
              <button
                type="button"
                onClick={() =>
                  setForm({
                    ...form,
                    employmentType: 'PKWT',
                    hasJht: true,
                    hasJp: true,
                    salaryType: 'monthly',
                    overtimeEligible: isBranchOvertimeDisabled ? false : true,
                  })
                }
                className={`p-4 rounded-2xl border text-left transition duration-150 cursor-pointer flex flex-col justify-between relative overflow-hidden ${
                  form.employmentType === 'PKWT'
                    ? 'bg-blue-50/60 dark:bg-blue-950/30 border-blue-500 ring-2 ring-blue-500/20 shadow-sm'
                    : 'bg-white dark:bg-slate-800/40 border-slate-200 dark:border-slate-700 hover:border-blue-300 dark:hover:border-blue-700'
                }`}
              >
                {form.employmentType === 'PKWT' && (
                  <span className="absolute top-2 right-2 w-5 h-5 rounded-full bg-blue-500 text-white flex items-center justify-center">
                    <Check className="w-3 h-3" />
                  </span>
                )}
                <div>
                  <div className="w-8 h-8 rounded-xl bg-blue-100 dark:bg-blue-900/50 text-blue-700 dark:text-blue-400 flex items-center justify-center mb-2.5">
                    <Clock className="w-4.5 h-4.5" />
                  </div>
                  <h4 className="text-xs font-bold text-slate-800 dark:text-slate-100">PKWT (Kontrak)</h4>
                  <p className="text-[10px] text-slate-500 dark:text-slate-400 mt-1 leading-relaxed">
                    Karyawan kontrak bertempo. Membutuhkan tanggal mulai & selesai kontrak (reminder H-30).
                  </p>
                </div>
                <div className="mt-3 pt-2.5 border-t border-blue-100 dark:border-blue-900/40 text-[9px] font-semibold text-blue-700 dark:text-blue-400">
                  • Wajib Tgl Kontrak • Evaluasi
                </div>
              </button>

              {/* Probation */}
              <button
                type="button"
                onClick={() =>
                  setForm({
                    ...form,
                    employmentType: 'Probation',
                    hasJht: true,
                    hasJp: false,
                    salaryType: 'monthly',
                    overtimeEligible: isBranchOvertimeDisabled ? false : true,
                  })
                }
                className={`p-4 rounded-2xl border text-left transition duration-150 cursor-pointer flex flex-col justify-between relative overflow-hidden ${
                  form.employmentType === 'Probation'
                    ? 'bg-amber-50/60 dark:bg-amber-950/30 border-amber-500 ring-2 ring-amber-500/20 shadow-sm'
                    : 'bg-white dark:bg-slate-800/40 border-slate-200 dark:border-slate-700 hover:border-amber-300 dark:hover:border-amber-700'
                }`}
              >
                {form.employmentType === 'Probation' && (
                  <span className="absolute top-2 right-2 w-5 h-5 rounded-full bg-amber-500 text-white flex items-center justify-center">
                    <Check className="w-3 h-3" />
                  </span>
                )}
                <div>
                  <div className="w-8 h-8 rounded-xl bg-amber-100 dark:bg-amber-900/50 text-amber-700 dark:text-amber-400 flex items-center justify-center mb-2.5">
                    <Sparkles className="w-4.5 h-4.5" />
                  </div>
                  <h4 className="text-xs font-bold text-slate-800 dark:text-slate-100">Probasi (Percobaan)</h4>
                  <p className="text-[10px] text-slate-500 dark:text-slate-400 mt-1 leading-relaxed">
                    Calon karyawan tetap dalam masa evaluasi kinerja (biasanya 3–6 bulan) sebelum diangkat PKWTT.
                  </p>
                </div>
                <div className="mt-3 pt-2.5 border-t border-amber-100 dark:border-amber-900/40 text-[9px] font-semibold text-amber-700 dark:text-amber-400">
                  • Evaluasi 3-6 Bulan • Calon Tetap
                </div>
              </button>

              {/* Internship */}
              <button
                type="button"
                onClick={() =>
                  setForm({
                    ...form,
                    employmentType: 'Internship',
                    hasJht: false,
                    hasJp: false,
                    salaryType: 'daily',
                    overtimeEligible: false,
                  })
                }
                className={`p-4 rounded-2xl border text-left transition duration-150 cursor-pointer flex flex-col justify-between relative overflow-hidden ${
                  form.employmentType === 'Internship'
                    ? 'bg-purple-50/60 dark:bg-purple-950/30 border-purple-500 ring-2 ring-purple-500/20 shadow-sm'
                    : 'bg-white dark:bg-slate-800/40 border-slate-200 dark:border-slate-700 hover:border-purple-300 dark:hover:border-purple-700'
                }`}
              >
                {form.employmentType === 'Internship' && (
                  <span className="absolute top-2 right-2 w-5 h-5 rounded-full bg-purple-500 text-white flex items-center justify-center">
                    <Check className="w-3 h-3" />
                  </span>
                )}
                <div>
                  <div className="w-8 h-8 rounded-xl bg-purple-100 dark:bg-purple-900/50 text-purple-700 dark:text-purple-400 flex items-center justify-center mb-2.5">
                    <Sparkles className="w-4.5 h-4.5" />
                  </div>
                  <h4 className="text-xs font-bold text-slate-800 dark:text-slate-100">Magang (Internship)</h4>
                  <p className="text-[10px] text-slate-500 dark:text-slate-400 mt-1 leading-relaxed">
                    Peserta magang / PKL dengan uang saku harian/bulanan. Tidak dipotong iuran JHT & JP BPJS.
                  </p>
                </div>
                <div className="mt-3 pt-2.5 border-t border-purple-100 dark:border-purple-900/40 text-[9px] font-semibold text-purple-700 dark:text-purple-400">
                  • Uang Saku Harian • Bebas JHT/JP
                </div>
              </button>
            </div>
          </div>

          {/* Data Akun & Struktur Organisasi */}
          <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-3xl p-6 shadow-xs space-y-4">
            <h3 className="text-xs font-extrabold text-slate-800 dark:text-slate-100 flex items-center gap-2 pb-3 border-b border-slate-100 dark:border-slate-800">
              <Building className="w-4 h-4 text-indigo-600" />
              Identitas Akun & Penempatan Organisasi
            </h3>

            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
              {/* NIK / Kode Karyawan */}
              <div className="space-y-1">
                <label className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">
                  Nomor Induk Karyawan (NIK Perusahaan)
                </label>
                <input
                  type="text"
                  value={form.nik || ''}
                  onChange={(e) => setForm({ ...form, nik: e.target.value })}
                  placeholder="Contoh: EMP-001 (otomatis jika kosong)"
                  className="w-full text-xs px-3.5 py-2.5 bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-xl focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-600 transition"
                />
              </div>

              {/* Nama Lengkap */}
              <div className="space-y-1">
                <label className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">
                  Nama Lengkap Karyawan *
                </label>
                <input
                  type="text"
                  required
                  value={form.nama || ''}
                  onChange={(e) => setForm({ ...form, nama: e.target.value })}
                  placeholder="Masukkan nama lengkap"
                  className="w-full text-xs px-3.5 py-2.5 bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-xl focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-600 transition"
                />
              </div>

              {/* Email */}
              <div className="space-y-1">
                <div className="flex items-center justify-between">
                  <label className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">
                    {form.can_login === false ? 'Email Perusahaan (Opsional)' : 'Email Perusahaan (Login) *'}
                  </label>
                  {form.can_login === false && (
                    <span className="text-[9px] font-semibold text-slate-400 bg-slate-100 dark:bg-slate-800 px-1.5 py-0.5 rounded">
                      Non-Sistem
                    </span>
                  )}
                </div>
                <input
                  type="email"
                  required={form.can_login !== false}
                  value={form.email || ''}
                  onChange={(e) => setForm({ ...form, email: e.target.value })}
                  placeholder={form.can_login === false ? 'Opsional (otomatis dibuat jika kosong)' : 'karyawan@perusahaan.com'}
                  className="w-full text-xs px-3.5 py-2.5 bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-xl focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-600 transition"
                />
              </div>

              {/* Nomor HP */}
              <div className="space-y-1">
                <label className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">
                  Nomor HP / WhatsApp
                </label>
                <input
                  type="text"
                  value={form.hp || ''}
                  onChange={(e) => setForm({ ...form, hp: e.target.value })}
                  placeholder="Contoh: 081234567890"
                  className="w-full text-xs px-3.5 py-2.5 bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-xl focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-600 transition"
                />
              </div>

              {/* Divisi */}
              <div className="space-y-1">
                <label className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">
                  Divisi *
                </label>
                <select
                  required
                  value={form.division_id ? String(form.division_id) : ''}
                  onChange={(e) => {
                    const divId = e.target.value ? Number(e.target.value) : '';
                    const chosenDiv = divisions.find((d) => d.id === divId);
                    setForm((prev: any) => {
                      const curPos = positions.find((p) => p.id === Number(prev.position_id));
                      const shouldResetPos = curPos && curPos.division_id && curPos.division_id !== divId;
                      return {
                        ...prev,
                        division_id: divId,
                        dept: chosenDiv ? chosenDiv.name : '',
                        position_id: shouldResetPos ? '' : prev.position_id,
                        jabatan: shouldResetPos ? '' : prev.jabatan,
                      };
                    });
                  }}
                  className="w-full text-xs px-3.5 py-2.5 bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-xl focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-600 transition"
                >
                  <option value="">Pilih Divisi</option>
                  {divisions.length > 0 ? (
                    divisions.map((d) => (
                      <option key={d.id} value={d.id}>
                        {d.name} {d.code ? `(${d.code})` : ''}
                      </option>
                    ))
                  ) : (
                    departments.map((d) => (
                      <option key={d} value={d}>
                        {d}
                      </option>
                    ))
                  )}
                </select>
              </div>

              {/* Jabatan / Posisi Kerja */}
              <div className="space-y-1">
                <label className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">
                  Jabatan / Posisi Kerja
                </label>
                {availablePositions.length > 0 ? (
                  <select
                    value={form.position_id ? String(form.position_id) : ''}
                    onChange={(e) => {
                      const posId = e.target.value ? Number(e.target.value) : '';
                      const chosenPos = positions.find((p) => p.id === posId);
                      setForm((prev: any) => ({
                        ...prev,
                        position_id: posId,
                        jabatan: chosenPos ? chosenPos.name : '',
                        division_id: (!prev.division_id && chosenPos?.division_id) ? chosenPos.division_id : prev.division_id,
                        dept: (!prev.dept && chosenPos?.division?.name) ? chosenPos.division.name : prev.dept,
                      }));
                    }}
                    className="w-full text-xs px-3.5 py-2.5 bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-xl focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-600 transition"
                  >
                    <option value="">Pilih Jabatan</option>
                    {availablePositions.map((pos) => (
                      <option key={pos.id} value={pos.id}>
                        {pos.name} {pos.is_supervisor ? '⭐ (SPV — Approval Lembur & Cuti Step 1)' : ''}
                      </option>
                    ))}
                  </select>
                ) : (
                  <input
                    type="text"
                    value={form.jabatan || ''}
                    onChange={(e) => setForm({ ...form, jabatan: e.target.value })}
                    placeholder="Contoh: Senior Staff, Supervisor"
                    className="w-full text-xs px-3.5 py-2.5 bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-xl focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-600 transition"
                  />
                )}
                {selectedPosition?.is_supervisor && (
                  <div className="mt-2 p-2.5 rounded-xl bg-amber-50/90 dark:bg-amber-950/40 border border-amber-200 dark:border-amber-800/60 text-[11px] text-amber-900 dark:text-amber-200 flex items-start gap-2">
                    <Sparkles className="w-3.5 h-3.5 text-amber-600 dark:text-amber-400 shrink-0 mt-0.5" />
                    <div>
                      <span className="font-bold">Posisi Atasan (Supervisor): </span>
                      <span>Jabatan ini memiliki wewenang approval bawahan. Disarankan menetapkan Role Sistem ke <strong>Supervisor</strong> agar menu persetujuan aktif.</span>
                    </div>
                  </div>
                )}
              </div>



              {/* Atasan Langsung (SPV) */}
              <div className="space-y-1">
                <label className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">
                  Atasan Langsung (SPV) — Approver Lembur Lv1
                </label>
                <select
                  value={form.manager_id ? String(form.manager_id) : ''}
                  onChange={(e) => {
                    const mgrId = e.target.value ? Number(e.target.value) : '';
                    const chosenMgr = supervisors.find((s) => s.id === mgrId);
                    setForm((prev: any) => ({
                      ...prev,
                      manager_id: mgrId,
                      atasan: chosenMgr ? chosenMgr.name : '',
                    }));
                  }}
                  className="w-full text-xs px-3.5 py-2.5 bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-xl focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-600 transition"
                >
                  <option value="">Belum Ditentukan / Tanpa Atasan Langsung</option>
                  {supervisors.map((s) => (
                    <option key={s.id} value={s.id}>
                      {s.name} ({s.position_name || s.position?.name || 'Supervisor'} - {s.division_name || s.division?.name || 'Divisi'}) {s.office_name ? `• ${s.office_name}` : ''}
                    </option>
                  ))}
                </select>
                <p className="text-[9px] text-slate-400">
                  {loadingSupervisors ? (
                    'Memuat kandidat SPV...'
                  ) : form.division_id ? (
                    'Pemegang jabatan SPV pada divisi ini yang dapat meng-approve lembur Level 1.'
                  ) : (
                    'Pilih divisi terlebih dahulu untuk memfilter SPV terkait divisi.'
                  )}
                </p>
              </div>

              {/* Role Sistem */}
              <div className="space-y-1.5">
                <div className="flex items-center justify-between">
                  <label className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">
                    Hak Akses / Role Sistem *
                  </label>
                  {canManageRoles && (
                    <div className="flex items-center gap-2">
                      {currentSelectedRole && !currentSelectedRole.is_builtin && (
                        <button
                          type="button"
                          onClick={() => {
                            setRoleToEditInModal(currentSelectedRole);
                            setIsRoleModalOpen(true);
                          }}
                          className="inline-flex items-center gap-1 text-[11px] font-semibold text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200 transition"
                          title="Ubah hak akses atau cabang untuk role kustom ini"
                        >
                          <Shield className="w-3 h-3 text-indigo-500" />
                          <span>Edit Role Ini</span>
                        </button>
                      )}
                      <button
                        type="button"
                        onClick={() => {
                          setRoleToEditInModal(null);
                          setIsRoleModalOpen(true);
                        }}
                        className="inline-flex items-center gap-1 text-[11px] font-bold text-indigo-600 dark:text-indigo-400 hover:text-indigo-700 dark:hover:text-indigo-300 transition hover:underline"
                      >
                        <Plus className="w-3.5 h-3.5" />
                        <span>+ Buat Role Baru</span>
                      </button>
                    </div>
                  )}
                </div>
                <select
                  required
                  disabled={!canManageRoles}
                  value={currentSelectedRole?.id ? String(currentSelectedRole.id) : (form.role_id ? String(form.role_id) : (form.role || 'employee'))}
                  onChange={(e) => {
                    const val = e.target.value;
                    const selectedRole = availableRoles.find((r) => String(r.id) === val || r.slug === val);
                    if (selectedRole) {
                      setForm((prev: any) => ({ ...prev, role: selectedRole.slug, role_id: selectedRole.id }));
                    } else {
                      setForm((prev: any) => ({ ...prev, role: val }));
                    }
                  }}
                  className={`w-full text-xs px-3.5 py-2.5 rounded-xl border transition ${
                    !canManageRoles
                      ? 'bg-slate-100 dark:bg-slate-800/40 text-slate-500 dark:text-slate-400 border-slate-200 dark:border-slate-800 cursor-not-allowed'
                      : 'bg-slate-50 dark:bg-slate-800/60 border-slate-200 dark:border-slate-700 focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-600'
                  }`}
                >
                  {availableRoles.length > 0 ? (
                    availableRoles.map((r) => (
                      <option key={r.id} value={r.id}>
                        {r.name} ({r.platform === 'mobile_only' ? 'Mobile' : 'Mobile & Web'}{!r.is_builtin ? ' — Kustom' : ''})
                      </option>
                    ))
                  ) : (
                    <>
                      <option value="employee">Pegawai (Presensi & Klaim Biasa)</option>
                      <option value="hrd">Staf HRD (Kelola Karyawan & Roster)</option>
                      <option value="finance">Staf Finance (Approval Keuangan)</option>
                      <option value="admin">Administrator (Akses Penuh)</option>
                    </>
                  )}
                </select>
                {!canManageRoles ? (
                  <p className="text-[10px] text-amber-600 dark:text-amber-400 flex items-center gap-1 font-medium">
                    <Lock className="w-3 h-3" />
                    <span>Perubahan role dikunci. Anda tidak memiliki izin <strong>Manajemen Role & Hak Akses</strong>.</span>
                  </p>
                ) : (
                  <p className="text-[10px] text-slate-400 dark:text-slate-500 flex items-center gap-1">
                    <span>💡 Ingin wewenang khusus atau batasan cabang? Klik</span>
                    <button
                      type="button"
                      onClick={() => {
                        setRoleToEditInModal(null);
                        setIsRoleModalOpen(true);
                      }}
                      className="font-bold text-indigo-600 dark:text-indigo-400 hover:underline"
                    >
                      + Buat Role Baru
                    </button>
                    <span>untuk merancang peran langsung dari form ini.</span>
                  </p>
                )}
              </div>

              {/* Kantor Penempatan */}
              <div className="space-y-1 sm:col-span-2">
                <label className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">
                  Kantor Penempatan (Lokasi Presensi & Radius)
                </label>
                <select
                  value={form.officeId ?? ''}
                  onChange={(e) =>
                    setForm({
                      ...form,
                      officeId: e.target.value === '' ? '' : Number(e.target.value),
                    })
                  }
                  className="w-full text-xs px-3.5 py-2.5 bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-xl focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-600 transition"
                >
                  {offices.length !== 1 && (
                    <option value="">Belum ditentukan / Semua Kantor</option>
                  )}
                  {offices.map((o) => (
                    <option key={o.id} value={o.id}>
                      {o.office_name}
                    </option>
                  ))}
                </select>
              </div>
            </div>
          </div>

          {/* Masa Kerja, Kontrak & Limit Klaim */}
          <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-3xl p-6 shadow-xs space-y-4">
            <h3 className="text-xs font-extrabold text-slate-800 dark:text-slate-100 flex items-center gap-2 pb-3 border-b border-slate-100 dark:border-slate-800">
              <Clock className="w-4 h-4 text-indigo-600" />
              Masa Kerja, Periode Kontrak & Batas Klaim
            </h3>

            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
              {/* Tanggal Bergabung */}
              <div className="space-y-1">
                <label className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">
                  Tanggal Mulai Bergabung
                </label>
                <CustomDatePicker
                  value={form.joinedDate || ''}
                  onChange={(val) => setForm({ ...form, joinedDate: val })}
                  placeholder="Pilih tanggal bergabung"
                />
              </div>

              {/* Periode Kontrak if PKWT / Probation / Internship */}
              {(form.employmentType === 'PKWT' ||
                form.employmentType === 'Probation' ||
                form.employmentType === 'Internship') && (
                <>
                  <div className="space-y-1">
                    <label className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">
                      Tanggal Mulai Kontrak
                    </label>
                    <CustomDatePicker
                      value={form.contractStartDate || ''}
                      onChange={(val) => {
                        const shouldResetEnd = form.contractEndDate && val && form.contractEndDate < val;
                        setForm({
                          ...form,
                          contractStartDate: val,
                          contractEndDate: shouldResetEnd ? '' : form.contractEndDate,
                        });
                      }}
                      placeholder="Mulai kontrak"
                    />
                  </div>

                  <div className="space-y-1">
                    <div className="flex items-center justify-between">
                      <label className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">
                        Tanggal Berakhir Kontrak
                      </label>
                      {form.contractStartDate && (
                        <span className="text-[10px] text-indigo-600 dark:text-indigo-400 font-semibold">
                          Min: {form.contractStartDate}
                        </span>
                      )}
                    </div>
                    <CustomDatePicker
                      value={form.contractEndDate || ''}
                      min={form.contractStartDate || undefined}
                      onChange={(val) => setForm({ ...form, contractEndDate: val })}
                      placeholder={form.contractStartDate ? 'Pilih tanggal selesai kontrak' : 'Pilih mulai kontrak terlebih dahulu'}
                      disabled={!form.contractStartDate}
                    />
                    {!form.contractStartDate && (
                      <p className="text-[10px] text-amber-500 dark:text-amber-400 font-medium">
                        * Tentukan tanggal mulai kontrak terlebih dahulu
                      </p>
                    )}
                    {form.contractStartDate && (
                      <p className="text-[10px] text-slate-400">
                        Tanggal sebelum {form.contractStartDate} dinonaktifkan otomatis.
                      </p>
                    )}
                  </div>
                </>
              )}

              {/* Batas & Hak Akses Klaim Struk Bulanan (3 Pilihan Segmen) */}
              <div className="space-y-3 sm:col-span-2 lg:col-span-3 pt-2 border-t border-slate-100 dark:border-slate-800">
                <div className="flex items-center justify-between">
                  <label className="text-[10px] font-bold text-slate-400 uppercase tracking-wider flex items-center gap-1.5">
                    <Receipt className="w-3.5 h-3.5 text-indigo-500" />
                    Kebijakan Batas Klaim Struk Bulanan
                  </label>
                  <span className="text-[10px] font-semibold text-slate-400">
                    Pilih batas plafon atau nonaktifkan fitur klaim
                  </span>
                </div>



                {/* 3 Opsi Kartu / Segment */}
                <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                  {/* Opsi 1: Nominal Tertentu */}
                  <button
                    type="button"
                    onClick={() => {
                      setForm({
                        ...form,
                        allow_receipt_claim: true,
                        limit: form.limit && Number(form.limit) > 0 ? form.limit : 2000000,
                      });
                    }}
                    className={`p-3.5 rounded-2xl border text-left transition-all relative flex flex-col justify-between ${
                      form.allow_receipt_claim !== false && form.limit !== null && form.limit !== '' && Number(form.limit) > 0
                        ? 'border-indigo-500 bg-indigo-50/50 dark:bg-indigo-950/30 text-indigo-900 dark:text-indigo-200 ring-2 ring-indigo-500/20 shadow-xs'
                        : 'border-slate-200 dark:border-slate-700 hover:border-slate-300 dark:hover:border-slate-600 bg-white dark:bg-slate-800/40 text-slate-700 dark:text-slate-300'
                    }`}
                  >
                    <div>
                      <div className="flex items-center justify-between mb-1.5">
                        <span className="w-7 h-7 rounded-xl bg-indigo-100 dark:bg-indigo-900/60 text-indigo-600 dark:text-indigo-400 flex items-center justify-center font-bold text-xs">
                          Rp
                        </span>
                        {form.allow_receipt_claim !== false && form.limit !== null && form.limit !== '' && Number(form.limit) > 0 && (
                          <span className="w-4 h-4 rounded-full bg-indigo-600 text-white flex items-center justify-center">
                            <Check className="w-2.5 h-2.5 stroke-[3]" />
                          </span>
                        )}
                      </div>
                      <div className="text-xs font-bold">Nominal Tertentu</div>
                      <div className="text-[10px] text-slate-500 dark:text-slate-400 mt-0.5 leading-relaxed">
                        Dibatasi plafon anggaran per bulan
                      </div>
                    </div>
                  </button>

                  {/* Opsi 2: Unlimited (Tanpa Batas) */}
                  <button
                    type="button"
                    onClick={() => {
                      setForm({
                        ...form,
                        allow_receipt_claim: true,
                        limit: null,
                      });
                    }}
                    className={`p-3.5 rounded-2xl border text-left transition-all relative flex flex-col justify-between ${
                      form.allow_receipt_claim !== false && (form.limit === null || form.limit === '' || Number(form.limit) <= 0)
                        ? 'border-emerald-500 bg-emerald-50/50 dark:bg-emerald-950/30 text-emerald-900 dark:text-emerald-200 ring-2 ring-emerald-500/20 shadow-xs'
                        : 'border-slate-200 dark:border-slate-700 hover:border-slate-300 dark:hover:border-slate-600 bg-white dark:bg-slate-800/40 text-slate-700 dark:text-slate-300'
                    }`}
                  >
                    <div>
                      <div className="flex items-center justify-between mb-1.5">
                        <span className="w-7 h-7 rounded-xl bg-emerald-100 dark:bg-emerald-900/60 text-emerald-600 dark:text-emerald-400 flex items-center justify-center font-bold text-xs">
                          <InfinityIcon className="w-4 h-4" />
                        </span>
                        {form.allow_receipt_claim !== false && (form.limit === null || form.limit === '' || Number(form.limit) <= 0) && (
                          <span className="w-4 h-4 rounded-full bg-emerald-600 text-white flex items-center justify-center">
                            <Check className="w-2.5 h-2.5 stroke-[3]" />
                          </span>
                        )}
                      </div>
                      <div className="text-xs font-bold">Unlimited</div>
                      <div className="text-[10px] text-slate-500 dark:text-slate-400 mt-0.5 leading-relaxed">
                        Bebas klaim tanpa limit anggaran
                      </div>
                    </div>
                  </button>

                  {/* Opsi 3: Dinonaktifkan (Tidak Berhak Klaim) */}
                  <button
                    type="button"
                    onClick={() => {
                      setForm({
                        ...form,
                        allow_receipt_claim: false,
                        limit: null,
                      });
                    }}
                    className={`p-3.5 rounded-2xl border text-left transition-all relative flex flex-col justify-between ${
                      form.allow_receipt_claim === false
                        ? 'border-rose-500 bg-rose-50/50 dark:bg-rose-950/30 text-rose-900 dark:text-rose-200 ring-2 ring-rose-500/20 shadow-xs'
                        : 'border-slate-200 dark:border-slate-700 hover:border-slate-300 dark:hover:border-slate-600 bg-white dark:bg-slate-800/40 text-slate-700 dark:text-slate-300'
                    }`}
                  >
                    <div>
                      <div className="flex items-center justify-between mb-1.5">
                        <span className="w-7 h-7 rounded-xl bg-rose-100 dark:bg-rose-900/60 text-rose-600 dark:text-rose-400 flex items-center justify-center font-bold text-xs">
                          <Ban className="w-4 h-4" />
                        </span>
                        {form.allow_receipt_claim === false && (
                          <span className="w-4 h-4 rounded-full bg-rose-600 text-white flex items-center justify-center">
                            <Check className="w-2.5 h-2.5 stroke-[3]" />
                          </span>
                        )}
                      </div>
                      <div className="text-xs font-bold">Dinonaktifkan</div>
                      <div className="text-[10px] text-slate-500 dark:text-slate-400 mt-0.5 leading-relaxed">
                        Klaim struk mobile di-disable
                      </div>
                    </div>
                  </button>
                </div>

                {/* Dynamic Panel Berdasarkan Opsi Terpilih */}
                {form.allow_receipt_claim !== false && form.limit !== null && form.limit !== '' && Number(form.limit) > 0 ? (
                  <div className="p-3.5 rounded-2xl bg-indigo-50/40 dark:bg-indigo-950/20 border border-indigo-100 dark:border-indigo-900/40 space-y-2">
                    <label className="text-[10px] font-bold text-indigo-950 dark:text-indigo-300 uppercase tracking-wider block">
                      Nominal Plafon Bulanan (IDR)
                    </label>
                    <div className="relative">
                      <span className="absolute left-3.5 top-2.5 text-xs text-indigo-500 dark:text-indigo-400 font-bold">Rp</span>
                      <input
                        type="number"
                        min="0"
                        step="any"
                        value={form.limit === null || form.limit === '' ? '' : form.limit}
                        onChange={(e) =>
                          setForm({
                            ...form,
                            limit: e.target.value === '' ? '' : Number(e.target.value),
                          })
                        }
                        placeholder="Masukkan nominal batas, misal 2000000"
                        className="w-full text-xs pl-10 pr-3.5 py-2.5 bg-white dark:bg-slate-800 border border-indigo-200 dark:border-indigo-800/60 rounded-xl focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-600 transition font-mono font-semibold"
                      />
                    </div>
                    {form.limit !== null && form.limit !== '' && Number(form.limit) > 0 && (
                      <p className="text-[10px] text-indigo-600 dark:text-indigo-400 font-semibold mt-1">
                        Terbaca: Rp {Number(form.limit).toLocaleString('id-ID')} / bulan
                      </p>
                    )}
                    {/* Quick preset chips */}
                    <div className="flex flex-wrap items-center gap-1.5 pt-1">
                      <span className="text-[10px] text-slate-400 font-medium">Preset cepat:</span>

                      {[1000000, 2000000, 3000000, 5000000, 10000000].map((presetVal) => (
                        <button
                          key={presetVal}
                          type="button"
                          onClick={() => setForm({ ...form, limit: presetVal })}
                          className={`px-2 py-0.5 rounded-lg text-[10px] font-semibold transition ${
                            Number(form.limit) === presetVal
                              ? 'bg-indigo-600 text-white'
                              : 'bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-700'
                          }`}
                        >
                          Rp {(presetVal / 1000000).toFixed(presetVal % 1000000 === 0 ? 0 : 1)} Juta
                        </button>
                      ))}
                    </div>
                    <p className="text-[10px] text-indigo-700 dark:text-indigo-300 mt-1 flex items-center gap-1">
                      <Info className="w-3 h-3 shrink-0" />
                      Karyawan tidak dapat mengajukan klaim struk baru jika akumulasi klaim bulan berjalan melebihi batas ini.
                    </p>
                  </div>
                ) : form.allow_receipt_claim === false ? (
                  <div className="p-3.5 rounded-2xl bg-rose-50/60 dark:bg-rose-950/30 border border-rose-200 dark:border-rose-900/40 flex items-start gap-2.5">
                    <Ban className="w-4 h-4 text-rose-600 dark:text-rose-400 shrink-0 mt-0.5" />
                    <div>
                      <div className="text-xs font-bold text-rose-800 dark:text-rose-300">
                        Fitur Klaim Struk Terkunci (Nonaktif)
                      </div>
                      <p className="text-[10px] text-rose-700 dark:text-rose-400 mt-0.5 leading-relaxed">
                        Fitur klaim struk di aplikasi mobile karyawan akan dinonaktifkan secara otomatis (seperti sistem penonaktifan presensi mobile). Tombol &ldquo;Foto Struk&rdquo; dan formulir klaim akan terkunci, serta request API ditolak oleh backend (HTTP 403 Forbidden).
                      </p>
                    </div>
                  </div>
                ) : (
                  <div className="p-3.5 rounded-2xl bg-emerald-50/60 dark:bg-emerald-950/30 border border-emerald-200 dark:border-emerald-900/40 flex items-start gap-2.5">
                    <InfinityIcon className="w-4 h-4 text-emerald-600 dark:text-emerald-400 shrink-0 mt-0.5" />
                    <div>
                      <div className="text-xs font-bold text-emerald-800 dark:text-emerald-300">
                        Klaim Tanpa Batas Anggaran (Unlimited)
                      </div>
                      <p className="text-[10px] text-emerald-700 dark:text-emerald-400 mt-0.5 leading-relaxed">
                        Karyawan dapat mengajukan klaim struk reimbursement dan laporan pengeluaran dinas secara bebas tanpa batasan plafon bulanan.
                      </p>
                    </div>
                  </div>
                )}
              </div>
            </div>
          </div>

          {/* Informasi Terminasi & Pengakhiran Kerja (Khusus Edit / Karyawan Nonaktif atau Terisi) */}
          {isEdit && (
            <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-3xl p-6 shadow-xs space-y-4">
              <div className="flex items-center justify-between pb-3 border-b border-slate-100 dark:border-slate-800">
                <h3 className="text-xs font-extrabold text-slate-800 dark:text-slate-100 flex items-center gap-2">
                  <UserX className="w-4 h-4 text-rose-600 dark:text-rose-400" />
                  Informasi Terminasi & Offboarding (Karyawan Keluar)
                </h3>
                <span className="text-[10px] text-slate-400 font-medium">
                  Riwayat Pengakhiran Hubungan Kerja
                </span>
              </div>

              <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                {/* Tanggal Efektif Keluar */}
                <div className="space-y-1">
                  <label className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">
                    Tanggal Efektif Keluar
                  </label>
                  <CustomDatePicker
                    value={form.exitDate || ''}
                    onChange={(val) => setForm({ ...form, exitDate: val })}
                    placeholder="Pilih tanggal keluar"
                  />
                </div>

                {/* Alasan Terminasi */}
                <div className="space-y-1">
                  <label className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">
                    Alasan Pengakhiran Kerja
                  </label>
                  <select
                    value={form.exitReason || ''}
                    onChange={(e) => setForm({ ...form, exitReason: e.target.value })}
                    className="w-full text-xs px-3.5 py-2.5 bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-xl focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-600 transition"
                  >
                    <option value="">-- Pilih Alasan --</option>
                    <option value="Resign / Mengundurkan Diri Sukarela">Resign / Mengundurkan Diri Sukarela</option>
                    <option value="Habis Masa Kontrak (PKWT Selesai)">Habis Masa Kontrak (PKWT Selesai)</option>
                    <option value="Pemutusan Hubungan Kerja (PHK)">Pemutusan Hubungan Kerja (PHK)</option>
                    <option value="Pensiun">Pensiun</option>
                    <option value="Pelanggaran Disiplin / Indisipliner">Pelanggaran Disiplin / Indisipliner</option>
                    <option value="Cuti Panjang / Non-Aktif Sementara">Cuti Panjang / Non-Aktif Sementara</option>
                    <option value="Meninggal Dunia">Meninggal Dunia</option>
                    <option value="Lainnya">Lainnya</option>
                  </select>
                </div>

                {/* Status Hak / Pesangon */}
                <div className="space-y-1">
                  <label className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">
                    Status Hak / Pesangon / UPMK
                  </label>
                  <select
                    value={form.severanceStatus || ''}
                    onChange={(e) => setForm({ ...form, severanceStatus: e.target.value })}
                    className="w-full text-xs px-3.5 py-2.5 bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-xl focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-600 transition"
                  >
                    <option value="">-- Pilih Status --</option>
                    <option value="Tidak Ada Pesangon">Tidak Ada Pesangon</option>
                    <option value="Lunas / Selesai">Lunas / Selesai Dibayarkan</option>
                    <option value="Sedang Diproses HR & Finance">Sedang Diproses HR & Finance</option>
                    <option value="Menunggu Verifikasi Persetujuan">Menunggu Verifikasi Persetujuan</option>
                  </select>
                </div>

                {/* Status Clearance Aset */}
                <div className="space-y-1">
                  <label className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">
                    Clearance Aset Kantor
                  </label>
                  <select
                    value={form.clearanceStatus || ''}
                    onChange={(e) => setForm({ ...form, clearanceStatus: e.target.value })}
                    className="w-full text-xs px-3.5 py-2.5 bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-xl focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-600 transition"
                  >
                    <option value="">-- Pilih Status Clearance --</option>
                    <option value="Selesai (Completed)">Selesai (Semua Aset Kembali)</option>
                    <option value="Sebagian (In Progress)">Sebagian (Masih Ada Aset)</option>
                    <option value="Belum (Pending)">Belum Ada Pengembalian</option>
                    <option value="Tidak Ada Aset Dipinjam">Tidak Ada Aset Dipinjam</option>
                  </select>
                </div>

                {/* Catatan Serah Terima */}
                <div className="space-y-1 sm:col-span-2 lg:col-span-4">
                  <label className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">
                    Catatan Serah Terima / Detail Alasan Keluar
                  </label>
                  <textarea
                    rows={2}
                    value={form.exitNotes || ''}
                    onChange={(e) => setForm({ ...form, exitNotes: e.target.value })}
                    placeholder="Catatan serah terima pekerjaan, pengembalian aset/laptop, kontak setelah resign..."
                    className="w-full text-xs px-3.5 py-2.5 bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-xl focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-600 transition"
                  />
                </div>
              </div>
            </div>
          )}
        </div>
      )}

      {/* ─── 4. TAB 2: DATA PRIBADI & KEPENDUDUKAN ─────────────────────── */}
      {formTab === 'personal' && (
        <div className="space-y-6">
          {/* Identitas Kependudukan & Sipil */}
          <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-3xl p-6 shadow-xs space-y-4">
            <h3 className="text-xs font-extrabold text-slate-800 dark:text-slate-100 flex items-center gap-2 pb-3 border-b border-slate-100 dark:border-slate-800">
              <FileText className="w-4 h-4 text-indigo-600" />
              Identitas Kependudukan & Status Sipil
            </h3>

            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
              {/* NIK KTP */}
              <div className="space-y-1">
                <div className="flex items-center justify-between">
                  <label className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">
                    Nomor Induk Kependudukan (NIK KTP 16 Digit)
                  </label>
                  {form.nikKtp && form.nikKtp.length === 16 && (
                    <span className="text-[10px] font-bold text-emerald-600 dark:text-emerald-400 flex items-center gap-0.5">
                      <Check className="w-3 h-3" /> 16 Digit Valid
                    </span>
                  )}
                  {form.nikKtp && form.nikKtp.length > 0 && form.nikKtp.length < 16 && (
                    <span className="text-[10px] font-semibold text-amber-500">
                      {form.nikKtp.length}/16 digit
                    </span>
                  )}
                </div>
                <input
                  type="text"
                  maxLength={16}
                  value={form.nikKtp || ''}
                  onChange={(e) => setForm({ ...form, nikKtp: e.target.value.replace(/\D/g, '') })}
                  placeholder="3171xxxxxxxxxxxx"
                  className="w-full text-xs font-mono px-3.5 py-2.5 bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-xl focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-600 transition"
                />
              </div>

              {/* Tempat Lahir */}
              <div className="space-y-1">
                <label className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">
                  Tempat Lahir
                </label>
                <input
                  type="text"
                  value={form.birthPlace || ''}
                  onChange={(e) => setForm({ ...form, birthPlace: e.target.value })}
                  placeholder="Contoh: Jakarta"
                  className="w-full text-xs px-3.5 py-2.5 bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-xl focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-600 transition"
                />
              </div>

              {/* Tanggal Lahir */}
              <div className="space-y-1">
                <label className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">
                  Tanggal Lahir
                </label>
                <CustomDatePicker
                  value={form.birthDate || ''}
                  onChange={(val) => setForm({ ...form, birthDate: val })}
                  placeholder="Pilih tanggal lahir"
                />
              </div>

              {/* Umur (Tahun) - Otomatis Terkalkulasi */}
              <div className="space-y-1">
                <div className="flex items-center justify-between">
                  <label className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">
                    Umur (Tahun)
                  </label>
                  {calculatedAge !== null && (
                    <span className={`text-[10px] font-bold px-1.5 py-0.5 rounded-full ${
                      calculatedAge < 17
                        ? 'bg-rose-100 dark:bg-rose-950/50 text-rose-700 dark:text-rose-400'
                        : 'bg-emerald-100 dark:bg-emerald-950/50 text-emerald-700 dark:text-emerald-400'
                    }`}>
                      {calculatedAge < 17 ? 'Di bawah 17 th' : 'Usia Produktif'}
                    </span>
                  )}
                </div>
                <div className="relative">
                  <input
                    type="text"
                    readOnly
                    value={calculatedAge !== null ? `${calculatedAge} Tahun` : ''}
                    placeholder="Otomatis dari tgl lahir"
                    className="w-full text-xs font-semibold px-3.5 py-2.5 bg-slate-100/80 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 rounded-xl text-slate-700 dark:text-slate-200 cursor-not-allowed select-none transition"
                  />
                  {calculatedAge !== null && (
                    <div className="absolute right-3 top-1/2 -translate-y-1/2 pointer-events-none">
                      <span className="text-[10px] font-bold text-slate-400 uppercase">th</span>
                    </div>
                  )}
                </div>
                <p className="text-[10px] text-slate-400">
                  {calculatedAge !== null
                    ? `Dihitung otomatis per hari ini (${calculatedAge} tahun).`
                    : 'Terisi otomatis saat tanggal lahir dipilih.'}
                </p>
              </div>

              {/* Jenis Kelamin */}
              <div className="space-y-1">
                <label className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">
                  Jenis Kelamin
                </label>
                <select
                  value={form.gender || 'Laki-laki'}
                  onChange={(e) => setForm({ ...form, gender: e.target.value })}
                  className="w-full text-xs px-3.5 py-2.5 bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-xl focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-600 transition"
                >
                  <option value="Laki-laki">Laki-laki</option>
                  <option value="Perempuan">Perempuan</option>
                </select>
              </div>

              {/* Agama */}
              <div className="space-y-1">
                <label className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">
                  Agama
                </label>
                <select
                  value={form.religion || 'Islam'}
                  onChange={(e) => setForm({ ...form, religion: e.target.value })}
                  className="w-full text-xs px-3.5 py-2.5 bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-xl focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-600 transition"
                >
                  <option value="Islam">Islam</option>
                  <option value="Kristen">Kristen Protestan</option>
                  <option value="Katolik">Katolik</option>
                  <option value="Hindu">Hindu</option>
                  <option value="Buddha">Buddha</option>
                  <option value="Konghucu">Konghucu</option>
                </select>
              </div>

              {/* Status Pernikahan */}
              <div className="space-y-1">
                <label className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">
                  Status Pernikahan
                </label>
                <select
                  value={form.maritalStatus || 'single'}
                  onChange={(e) => setForm({ ...form, maritalStatus: e.target.value })}
                  className="w-full text-xs px-3.5 py-2.5 bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-xl focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-600 transition"
                >
                  <option value="single">Belum Menikah (Lajang)</option>
                  <option value="married">Menikah</option>
                  <option value="divorced">Cerai Hidup (Janda/Duda)</option>
                  <option value="widowed">Cerai Mati</option>
                </select>
              </div>

              {/* Jumlah Tanggungan */}
              <div className="space-y-1">
                <label className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">
                  Jumlah Tanggungan Keluarga
                </label>
                <input
                  type="number"
                  min="0"
                  max="20"
                  value={form.numberOfDependents === '' ? '' : form.numberOfDependents}
                  onChange={(e) => setForm({ ...form, numberOfDependents: e.target.value === '' ? '' : Number(e.target.value) })}
                  placeholder="0"
                  className="w-full text-xs px-3.5 py-2.5 bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-xl focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-600 transition"
                />
              </div>

              {/* Checkbox Hamil if Perempuan */}
              {form.gender === 'Perempuan' && (
                <div className="sm:col-span-2 flex items-center gap-3 p-3 bg-amber-50/70 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-900/40 rounded-xl">
                  <input
                    type="checkbox"
                    id="isPregnantCheck"
                    checked={Boolean(form.isPregnant)}
                    onChange={(e) => setForm({ ...form, isPregnant: e.target.checked })}
                    className="w-4 h-4 rounded text-amber-600 focus:ring-amber-500"
                  />
                  <label htmlFor="isPregnantCheck" className="text-xs text-amber-900 dark:text-amber-300 font-medium cursor-pointer">
                    Sedang Hamil (Proteksi K3 Beban Kerja Fisik & Hak Cuti Melahirkan)
                  </label>
                </div>
              )}
            </div>
          </div>

          {/* Kesehatan & Medis K3 */}
          <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-3xl p-6 shadow-xs space-y-4">
            <h3 className="text-xs font-extrabold text-slate-800 dark:text-slate-100 flex items-center gap-2 pb-3 border-b border-slate-100 dark:border-slate-800">
              <Heart className="w-4 h-4 text-rose-600" />
              Kesehatan & Riwayat Medis K3
            </h3>

            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
              {/* Golongan Darah */}
              <div className="space-y-1">
                <label className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">
                  Golongan Darah
                </label>
                <select
                  value={form.bloodType || ''}
                  onChange={(e) => setForm({ ...form, bloodType: e.target.value })}
                  className="w-full text-xs px-3.5 py-2.5 bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-xl focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-600 transition"
                >
                  <option value="">Tidak Tahu / Belum Cek</option>
                  <option value="A">A</option>
                  <option value="B">B</option>
                  <option value="AB">AB</option>
                  <option value="O">O</option>
                  <option value="A+">A+</option>
                  <option value="B+">B+</option>
                  <option value="AB+">AB+</option>
                  <option value="O+">O+</option>
                </select>
              </div>

              {/* Riwayat Penyakit Khusus */}
              <div className="space-y-1 sm:col-span-2">
                <label className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">
                  Riwayat Penyakit Khusus / Alergi Obat
                </label>
                <input
                  type="text"
                  value={form.medicalConditions || ''}
                  onChange={(e) => setForm({ ...form, medicalConditions: e.target.value })}
                  placeholder="Contoh: Asma, Alergi Antibiotik, Riwayat Jantung (opsional)"
                  className="w-full text-xs px-3.5 py-2.5 bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-xl focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-600 transition"
                />
              </div>
            </div>
          </div>

          {/* Kontak Darurat (Emergency Contact K3) */}
          <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-3xl p-6 shadow-xs space-y-4">
            <div className="flex flex-col sm:flex-row sm:items-center justify-between pb-3 border-b border-slate-100 dark:border-slate-800 gap-2">
              <h3 className="text-xs font-extrabold text-slate-800 dark:text-slate-100 flex items-center gap-2">
                <PhoneCall className="w-4 h-4 text-emerald-600" />
                Kontak Darurat (Wajib Standar K3 Perusahaan)
              </h3>
              <span className="text-[10px] text-emerald-700 dark:text-emerald-400 font-semibold bg-emerald-50 dark:bg-emerald-950/40 px-2 py-0.5 rounded-full">
                Tanggap Darurat Medis
              </span>
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
              {/* Nama Kontak */}
              <div className="space-y-1">
                <label className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">
                  Nama Kontak Darurat
                </label>
                <input
                  type="text"
                  value={form.emergencyContactName || ''}
                  onChange={(e) => setForm({ ...form, emergencyContactName: e.target.value })}
                  placeholder="Contoh: Budi Santoso"
                  className="w-full text-xs px-3.5 py-2.5 bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-xl focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-600 transition"
                />
              </div>

              {/* Hubungan */}
              <div className="space-y-1">
                <label className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">
                  Hubungan Keluarga
                </label>
                <select
                  value={form.emergencyContactRelation || 'Keluarga'}
                  onChange={(e) => setForm({ ...form, emergencyContactRelation: e.target.value })}
                  className="w-full text-xs px-3.5 py-2.5 bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-xl focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-600 transition"
                >
                  <option value="Orang Tua">Orang Tua</option>
                  <option value="Pasangan">Pasangan (Suami/Istri)</option>
                  <option value="Saudara Kandung">Saudara Kandung</option>
                  <option value="Keluarga">Keluarga Besar</option>
                  <option value="Teman">Teman Dekat</option>
                  <option value="Lainnya">Lainnya</option>
                </select>
              </div>

              {/* No. HP Kontak Darurat */}
              <div className="space-y-1">
                <label className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">
                  No. HP / Telepon Darurat
                </label>
                <input
                  type="text"
                  value={form.emergencyContactPhone || ''}
                  onChange={(e) => setForm({ ...form, emergencyContactPhone: e.target.value })}
                  placeholder="0812xxxxxxxx"
                  className="w-full text-xs font-mono px-3.5 py-2.5 bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-xl focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-600 transition"
                />
              </div>

              {/* Alamat Kontak Darurat */}
              <div className="space-y-1 sm:col-span-2 lg:col-span-3">
                <label className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">
                  Alamat Tempat Tinggal Kontak Darurat
                </label>
                <input
                  type="text"
                  value={form.emergencyContactAddress || ''}
                  onChange={(e) => setForm({ ...form, emergencyContactAddress: e.target.value })}
                  placeholder="Alamat lengkap kontak darurat (opsional)"
                  className="w-full text-xs px-3.5 py-2.5 bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-xl focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-600 transition"
                />
              </div>
            </div>
          </div>

          {/* Alamat KTP & Domisili Tempat Tinggal */}
          <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-3xl p-6 shadow-xs space-y-4">
            <h3 className="text-xs font-extrabold text-slate-800 dark:text-slate-100 flex items-center gap-2 pb-3 border-b border-slate-100 dark:border-slate-800">
              <MapPin className="w-4 h-4 text-indigo-600" />
              Alamat Sesuai KTP & Domisili Tempat Tinggal
            </h3>

            <div className="space-y-4">
              {/* Alamat KTP */}
              <div className="space-y-1">
                <label className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">
                  Alamat Lengkap Sesuai KTP (Nama Jalan, RT/RW, Kelurahan, Kecamatan)
                </label>
                <textarea
                  rows={2}
                  value={form.ktpAddress || ''}
                  onChange={(e) => setForm({ ...form, ktpAddress: e.target.value })}
                  placeholder="Nama jalan, nomor rumah, RT/RW, Kelurahan, Kecamatan"
                  className="w-full text-xs px-3.5 py-2.5 bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-xl focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-600 transition"
                />
              </div>

              {/* Kota, Provinsi, Kode Pos KTP */}
              <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div className="space-y-1">
                  <label className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">
                    Kota / Kabupaten KTP
                  </label>
                  <input
                    type="text"
                    value={form.ktpCity || ''}
                    onChange={(e) => setForm({ ...form, ktpCity: e.target.value })}
                    placeholder="Contoh: Jakarta Selatan"
                    className="w-full text-xs px-3.5 py-2.5 bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-xl focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-600 transition"
                  />
                </div>
                <div className="space-y-1">
                  <label className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">
                    Provinsi KTP
                  </label>
                  <input
                    type="text"
                    value={form.ktpProvince || ''}
                    onChange={(e) => setForm({ ...form, ktpProvince: e.target.value })}
                    placeholder="Contoh: DKI Jakarta"
                    className="w-full text-xs px-3.5 py-2.5 bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-xl focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-600 transition"
                  />
                </div>
                <div className="space-y-1">
                  <label className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">
                    Kode Pos KTP
                  </label>
                  <input
                    type="text"
                    maxLength={10}
                    value={form.ktpPostalCode || ''}
                    onChange={(e) => setForm({ ...form, ktpPostalCode: e.target.value.replace(/\D/g, '') })}
                    placeholder="Contoh: 12560"
                    className="w-full text-xs font-mono px-3.5 py-2.5 bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-xl focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-600 transition"
                  />
                </div>
              </div>

              {/* Checkbox Sama dengan KTP */}
              <div className="flex items-center gap-2.5 p-3 bg-slate-50 dark:bg-slate-800/40 rounded-xl border border-slate-200/60 dark:border-slate-700/60">
                <input
                  type="checkbox"
                  id="sameAsKtpCheck"
                  checked={Boolean(form.isDomicileSameAsKtp)}
                  onChange={(e) => setForm({ ...form, isDomicileSameAsKtp: e.target.checked })}
                  className="w-4 h-4 rounded text-indigo-600 focus:ring-indigo-500"
                />
                <label htmlFor="sameAsKtpCheck" className="text-xs text-slate-700 dark:text-slate-300 font-medium cursor-pointer">
                  Alamat tempat tinggal domisili saat ini sama dengan alamat KTP
                </label>
              </div>

              {/* Alamat Domisili if different */}
              {!form.isDomicileSameAsKtp && (
                <div className="space-y-1 pt-1 animate-in fade-in duration-150">
                  <label className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">
                    Alamat Domisili Tempat Tinggal Saat Ini (Kost / Kontrakan / Rumah Singgah)
                  </label>
                  <textarea
                    rows={2}
                    value={form.domicileAddress || ''}
                    onChange={(e) => setForm({ ...form, domicileAddress: e.target.value })}
                    placeholder="Masukkan alamat domisili tempat tinggal aktual saat ini"
                    className="w-full text-xs px-3.5 py-2.5 bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-xl focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-600 transition"
                  />
                </div>
              )}
            </div>
          </div>

          {/* Latar Belakang Pendidikan Terakhir */}
          <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-3xl p-6 shadow-xs space-y-4">
            <div className="flex items-center justify-between pb-3 border-b border-slate-100 dark:border-slate-800">
              <h3 className="text-xs font-extrabold text-slate-800 dark:text-slate-100 flex items-center gap-2">
                <GraduationCap className="w-4 h-4 text-indigo-600" />
                Latar Belakang Pendidikan Terakhir
              </h3>
              <span className="text-[10px] text-slate-400 font-medium">
                Kualifikasi Formal & Riwayat Kelulusan
              </span>
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
              {/* Jenjang Pendidikan */}
              <div className="space-y-1">
                <label className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">
                  Jenjang Pendidikan
                </label>
                <select
                  value={form.educationLevel || ''}
                  onChange={(e) => setForm({ ...form, educationLevel: e.target.value })}
                  className="w-full text-xs px-3.5 py-2.5 bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-xl focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-600 transition"
                >
                  <option value="">-- Pilih Jenjang --</option>
                  <option value="SMA / SMK">SMA / SMK / Sederajat</option>
                  <option value="D1 / D2">D1 / D2</option>
                  <option value="D3">D3 (Diploma Tiga)</option>
                  <option value="D4 / S1">D4 / S1 (Sarjana)</option>
                  <option value="S2">S2 (Magister)</option>
                  <option value="S3">S3 (Doktor)</option>
                  <option value="SMP">SMP / Sederajat</option>
                  <option value="SD">SD / Sederajat</option>
                  <option value="Lainnya">Lainnya / Informal</option>
                </select>
              </div>

              {/* Tahun Kelulusan */}
              <div className="space-y-1">
                <label className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">
                  Tahun Kelulusan
                </label>
                <input
                  type="number"
                  min={1960}
                  max={new Date().getFullYear() + 5}
                  value={form.graduationYear || ''}
                  onChange={(e) => setForm({ ...form, graduationYear: e.target.value })}
                  placeholder="Contoh: 2022"
                  className="w-full text-xs font-mono px-3.5 py-2.5 bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-xl focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-600 transition"
                />
              </div>

              {/* Nama Institusi */}
              <div className="space-y-1 lg:col-span-2">
                <label className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">
                  Nama Institusi / Universitas / Sekolah
                </label>
                <input
                  type="text"
                  value={form.institutionName || ''}
                  onChange={(e) => setForm({ ...form, institutionName: e.target.value })}
                  placeholder="Contoh: Universitas Indonesia / SMKN 1 Jakarta"
                  className="w-full text-xs px-3.5 py-2.5 bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-xl focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-600 transition"
                />
              </div>

              {/* Jurusan / Program Studi */}
              <div className="space-y-1 sm:col-span-2 lg:col-span-4">
                <label className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">
                  Jurusan / Program Studi / Peminatan
                </label>
                <input
                  type="text"
                  value={form.major || ''}
                  onChange={(e) => setForm({ ...form, major: e.target.value })}
                  placeholder="Contoh: Teknik Informatika / Manajemen Bisnis / Akuntansi / Rekayasa Perangkat Lunak"
                  className="w-full text-xs px-3.5 py-2.5 bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-xl focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-600 transition"
                />
              </div>
            </div>
          </div>
        </div>
      )}

      {/* ─── 5. TAB 3: FINANSIAL, GAJI & PAJAK ─────────────────────────── */}
      {formTab === 'payroll' && (
        <div className="space-y-6">
          {/* Rekening Payroll Bank */}
          <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-3xl p-6 shadow-xs space-y-4">
            <h3 className="text-xs font-extrabold text-slate-800 dark:text-slate-100 flex items-center gap-2 pb-3 border-b border-slate-100 dark:border-slate-800">
              <Landmark className="w-4 h-4 text-indigo-600" />
              Rekening Payroll Perbankan (Disbursement Gaji)
            </h3>

            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
              {/* Nama Bank */}
              <div className="space-y-1">
                <label className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">
                  Nama Bank Payroll
                </label>
                <select
                  value={form.bankName || 'BCA'}
                  onChange={(e) => setForm({ ...form, bankName: e.target.value })}
                  className="w-full text-xs px-3.5 py-2.5 bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-xl focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-600 transition font-medium"
                >
                  <option value="BCA">BCA (Bank Central Asia)</option>
                  <option value="Bank Mandiri">Bank Mandiri</option>
                  <option value="BRI">BRI (Bank Rakyat Indonesia)</option>
                  <option value="BNI">BNI (Bank Negara Indonesia)</option>
                  <option value="CIMB Niaga">CIMB Niaga</option>
                  <option value="Bank Syariah Indonesia">BSI (Bank Syariah Indonesia)</option>
                  <option value="Bank Permata">Bank Permata</option>
                  <option value="Bank Danamon">Bank Danamon</option>
                  <option value="Bank Lainnya">Bank Lainnya</option>
                </select>
              </div>

              {/* No. Rekening Bank */}
              <div className="space-y-1">
                <label className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">
                  Nomor Rekening Bank
                </label>
                <input
                  type="text"
                  value={form.bankAccountNo || ''}
                  onChange={(e) => setForm({ ...form, bankAccountNo: e.target.value.replace(/\D/g, '') })}
                  placeholder="Contoh: 1234567890"
                  className="w-full text-xs font-mono font-bold px-3.5 py-2.5 bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-xl focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-600 transition"
                />
              </div>

              {/* Atas Nama Rekening */}
              <div className="space-y-1">
                <label className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">
                  Nama Pemilik Rekening (Atas Nama)
                </label>
                <input
                  type="text"
                  value={form.bankAccountHolder || ''}
                  onChange={(e) => setForm({ ...form, bankAccountHolder: e.target.value })}
                  placeholder="Harus sesuai buku tabungan"
                  className="w-full text-xs px-3.5 py-2.5 bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-xl focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-600 transition"
                />
              </div>
            </div>
          </div>

          {/* Skema Kompensasi & Gaji */}
          <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-3xl p-6 shadow-xs space-y-4">
            <h3 className="text-xs font-extrabold text-slate-800 dark:text-slate-100 flex items-center gap-2 pb-3 border-b border-slate-100 dark:border-slate-800">
              <Wallet className="w-4 h-4 text-indigo-600" />
              Skema Gaji Pokok & Hak Upah Lembur
            </h3>

            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
              {/* Skema Gaji */}
              <div className="space-y-1">
                <label className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">
                  Skema Pembayaran Gaji
                </label>
                <select
                  value={form.salaryType || 'monthly'}
                  onChange={(e) => setForm({ ...form, salaryType: e.target.value })}
                  className="w-full text-xs px-3.5 py-2.5 bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-xl focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-600 transition"
                >
                  <option value="monthly">Gaji Bulanan (Monthly)</option>
                  <option value="daily">Upah Harian (Daily)</option>
                  <option value="hourly">Upah Per Jam (Hourly)</option>
                </select>
              </div>

              {/* Estimasi Gaji Pokok */}
              <div className="space-y-1">
                <label className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">
                  Nominal Gaji Pokok (IDR)
                </label>
                <div className="relative">
                  <span className="absolute left-3.5 top-2.5 text-xs text-slate-400 font-semibold">Rp</span>
                  <input
                    type="number"
                    min="0"
                    step="any"
                    value={form.basicSalary === null || form.basicSalary === '' ? '' : form.basicSalary}
                    onChange={(e) =>
                      setForm({
                        ...form,
                        basicSalary: e.target.value === '' ? '' : Number(e.target.value),
                      })
                    }
                    placeholder="Contoh: 7500000"
                    className="w-full text-xs pl-10 pr-3.5 py-2.5 bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-xl focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-600 transition font-mono font-bold"
                  />
                </div>
                {form.basicSalary && Number(form.basicSalary) > 0 ? (
                  <p className="text-[10px] text-emerald-600 dark:text-emerald-400 font-semibold mt-1">
                    Terbaca: Rp {Number(form.basicSalary).toLocaleString('id-ID')} / {form.salaryType === 'daily' ? 'hari' : form.salaryType === 'hourly' ? 'jam' : 'bulan'}
                  </p>
                ) : null}
              </div>


            </div>
          </div>

          {/* Perpajakan PPh 21 TER 2024 */}
          <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-3xl p-6 shadow-xs space-y-4">
            <div className="flex flex-col sm:flex-row sm:items-center justify-between pb-3 border-b border-slate-100 dark:border-slate-800 gap-2">
              <h3 className="text-xs font-extrabold text-slate-800 dark:text-slate-100 flex items-center gap-2">
                <BadgePercent className="w-4 h-4 text-indigo-600" />
                Perpajakan Pajak Penghasilan (PPh 21 TER 2024)
              </h3>
              <span className="text-[10px] text-indigo-700 dark:text-indigo-400 font-semibold bg-indigo-50 dark:bg-indigo-950/40 px-2 py-0.5 rounded-full">
                PP 58/2023 & PMK 168/2023
              </span>
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
              {/* NPWP */}
              <div className="space-y-1">
                <label className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">
                  NPWP (15 / 16 Digit)
                </label>
                <input
                  type="text"
                  value={form.npwp || ''}
                  onChange={(e) => setForm({ ...form, npwp: e.target.value })}
                  placeholder="00.000.000.0-000.000"
                  className="w-full text-xs font-mono px-3.5 py-2.5 bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-xl focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-600 transition"
                />
              </div>

              {/* Status PTKP */}
              <div className="space-y-1">
                <label className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">
                  Status PTKP (Penghasilan Tidak Kena Pajak)
                </label>
                <select
                  value={form.ptkpStatus || 'TK/0'}
                  onChange={(e) => setForm({ ...form, ptkpStatus: e.target.value })}
                  className="w-full text-xs px-3.5 py-2.5 bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-xl focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-600 transition font-bold"
                >
                  <optgroup label="Tidak Kawin (TK)">
                    <option value="TK/0">TK/0 (Lajang tanpa tanggungan) - TER A</option>
                    <option value="TK/1">TK/1 (Lajang 1 tanggungan) - TER A</option>
                    <option value="TK/2">TK/2 (Lajang 2 tanggungan) - TER B</option>
                    <option value="TK/3">TK/3 (Lajang 3 tanggungan) - TER B</option>
                  </optgroup>
                  <optgroup label="Kawin (K)">
                    <option value="K/0">K/0 (Menikah tanpa tanggungan) - TER A</option>
                    <option value="K/1">K/1 (Menikah 1 tanggungan) - TER B</option>
                    <option value="K/2">K/2 (Menikah 2 tanggungan) - TER B</option>
                    <option value="K/3">K/3 (Menikah 3 tanggungan) - TER C</option>
                  </optgroup>
                </select>
              </div>

              {/* Metode Pajak */}
              <div className="space-y-1">
                <label className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">
                  Metode Pemotongan PPh 21
                </label>
                <select
                  value={form.taxMethod || 'gross'}
                  onChange={(e) => setForm({ ...form, taxMethod: e.target.value })}
                  className="w-full text-xs px-3.5 py-2.5 bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-xl focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-600 transition"
                >
                  <option value="gross">Gross (Pajak dipotong dari gaji)</option>
                  <option value="gross_up">Gross-Up (Tunjangan pajak perush)</option>
                  <option value="nett">Nett (Pajak ditanggung perush)</option>
                </select>
              </div>
            </div>

            {/* Banner Dinamis TER */}
            <div className="p-3 bg-indigo-50/70 dark:bg-indigo-950/30 border border-indigo-100 dark:border-indigo-900/40 rounded-xl flex items-center gap-2.5 text-xs text-indigo-900 dark:text-indigo-300">
              <Info className="w-4 h-4 text-indigo-600 shrink-0" />
              <span>
                <strong className="font-bold">Kategori Tarif TER: {terInfo.cat}</strong> — {terInfo.desc}
              </span>
            </div>
          </div>
        </div>
      )}

      {/* ─── 6. TAB 4: JAMINAN SOSIAL (BPJS) ───────────────────────────── */}
      {formTab === 'bpjs' && (
        <div className="space-y-6">
          {/* BPJS Ketenagakerjaan */}
          <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-3xl p-6 shadow-xs space-y-4">
            <div className="flex flex-col sm:flex-row sm:items-center justify-between pb-3 border-b border-slate-100 dark:border-slate-800 gap-2">
              <div className="flex items-center gap-2">
                <ShieldCheck className="w-4 h-4 text-indigo-600" />
                <h3 className="text-xs font-extrabold text-slate-800 dark:text-slate-100">
                  BPJS Ketenagakerjaan (Ketenagakerjaan & Pensiun)
                </h3>
              </div>
              <label className="flex items-center gap-2 cursor-pointer">
                <input
                  type="checkbox"
                  checked={Boolean(form.bpjsKetenagakerjaanEnabled)}
                  onChange={(e) => setForm({ ...form, bpjsKetenagakerjaanEnabled: e.target.checked })}
                  className="w-4 h-4 rounded text-indigo-600 focus:ring-indigo-500"
                />
                <span className="text-xs font-bold text-slate-700 dark:text-slate-300">Kepesertaan Aktif</span>
              </label>
            </div>

            <div className="space-y-4">
              <div className="space-y-1">
                <label className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">
                  Nomor Kartu Peserta Jamsostek (KPJ / No. BPJS TK)
                </label>
                <input
                  type="text"
                  value={form.bpjsKetenagakerjaanNo || ''}
                  onChange={(e) => setForm({ ...form, bpjsKetenagakerjaanNo: e.target.value.replace(/\D/g, '') })}
                  placeholder="Contoh: 22012345678"
                  className="w-full text-xs font-mono px-3.5 py-2.5 bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-xl focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-600 transition"
                />
              </div>

              {/* 4 Program Jamsostek Checklist */}
              <div className="space-y-2">
                <span className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">
                  Program Perlindungan BPJS Ketenagakerjaan yang Diikuti
                </span>
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                  {/* JHT */}
                  <label className="flex items-start gap-3 p-3 bg-slate-50 dark:bg-slate-800/50 rounded-xl border border-slate-200 dark:border-slate-700 cursor-pointer">
                    <input
                      type="checkbox"
                      checked={Boolean(form.hasJht)}
                      onChange={(e) => setForm({ ...form, hasJht: e.target.checked })}
                      className="w-4 h-4 rounded text-indigo-600 focus:ring-indigo-500 mt-0.5"
                    />
                    <div>
                      <span className="text-xs font-bold text-slate-800 dark:text-slate-100 block">
                        JHT - Jaminan Hari Tua
                      </span>
                      <span className="text-[10px] text-slate-400">
                        Iuran: 3.7% Perusahaan + 2.0% Potong Gaji Karyawan
                      </span>
                    </div>
                  </label>

                  {/* JP */}
                  <label
                    className={`flex items-start gap-3 p-3 bg-slate-50 dark:bg-slate-800/50 rounded-xl border border-slate-200 dark:border-slate-700 cursor-pointer ${
                      form.employmentType === 'Internship' ? 'opacity-50 cursor-not-allowed' : ''
                    }`}
                  >
                    <input
                      type="checkbox"
                      checked={Boolean(form.hasJp)}
                      disabled={form.employmentType === 'Internship'}
                      onChange={(e) => setForm({ ...form, hasJp: e.target.checked })}
                      className="w-4 h-4 rounded text-indigo-600 focus:ring-indigo-500 mt-0.5"
                    />
                    <div>
                      <span className="text-xs font-bold text-slate-800 dark:text-slate-100 block">
                        JP - Jaminan Pensiun
                      </span>
                      <span className="text-[10px] text-slate-400">
                        Iuran: 2.0% Perusahaan + 1.0% Karyawan (Khusus PKWTT/PKWT)
                      </span>
                    </div>
                  </label>

                  {/* JKK */}
                  <label className="flex items-start gap-3 p-3 bg-slate-50 dark:bg-slate-800/50 rounded-xl border border-slate-200 dark:border-slate-700 cursor-pointer">
                    <input
                      type="checkbox"
                      checked={form.hasJkk !== false}
                      onChange={(e) => setForm({ ...form, hasJkk: e.target.checked })}
                      className="w-4 h-4 rounded text-indigo-600 focus:ring-indigo-500 mt-0.5"
                    />
                    <div>
                      <span className="text-xs font-bold text-slate-800 dark:text-slate-100 block">
                        JKK - Jaminan Kecelakaan Kerja
                      </span>
                      <span className="text-[10px] text-slate-400">
                        Iuran: 0.24% – 1.74% 100% Ditanggung Perusahaan
                      </span>
                    </div>
                  </label>

                  {/* JKM */}
                  <label className="flex items-start gap-3 p-3 bg-slate-50 dark:bg-slate-800/50 rounded-xl border border-slate-200 dark:border-slate-700 cursor-pointer">
                    <input
                      type="checkbox"
                      checked={form.hasJkm !== false}
                      onChange={(e) => setForm({ ...form, hasJkm: e.target.checked })}
                      className="w-4 h-4 rounded text-indigo-600 focus:ring-indigo-500 mt-0.5"
                    />
                    <div>
                      <span className="text-xs font-bold text-slate-800 dark:text-slate-100 block">
                        JKM - Jaminan Kematian
                      </span>
                      <span className="text-[10px] text-slate-400">
                        Iuran: 0.30% 100% Ditanggung Perusahaan
                      </span>
                    </div>
                  </label>
                </div>
              </div>
            </div>
          </div>

          {/* BPJS Kesehatan */}
          <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-3xl p-6 shadow-xs space-y-4">
            <div className="flex flex-col sm:flex-row sm:items-center justify-between pb-3 border-b border-slate-100 dark:border-slate-800 gap-2">
              <div className="flex items-center gap-2">
                <Heart className="w-4 h-4 text-emerald-600" />
                <h3 className="text-xs font-extrabold text-slate-800 dark:text-slate-100">
                  BPJS Kesehatan (Jaminan Pemeliharaan Kesehatan)
                </h3>
              </div>
              <label className="flex items-center gap-2 cursor-pointer">
                <input
                  type="checkbox"
                  checked={Boolean(form.bpjsKesehatanEnabled)}
                  onChange={(e) => setForm({ ...form, bpjsKesehatanEnabled: e.target.checked })}
                  className="w-4 h-4 rounded text-emerald-600 focus:ring-emerald-500"
                />
                <span className="text-xs font-bold text-slate-700 dark:text-slate-300">Kepesertaan Aktif</span>
              </label>
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div className="space-y-1">
                <label className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">
                  Nomor Kartu Indonesia Sehat (No. BPJS Kesehatan 13 Digit)
                </label>
                <input
                  type="text"
                  maxLength={13}
                  value={form.bpjsKesehatanNo || ''}
                  onChange={(e) => setForm({ ...form, bpjsKesehatanNo: e.target.value.replace(/\D/g, '') })}
                  placeholder="Contoh: 0001234567890"
                  className="w-full text-xs font-mono px-3.5 py-2.5 bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-xl focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-600 transition"
                />
              </div>

              <div className="p-3 bg-slate-50 dark:bg-slate-800/40 rounded-xl border border-slate-200/60 dark:border-slate-700/60 text-xs text-slate-600 dark:text-slate-400 flex items-center">
                <span>
                  <strong className="text-slate-800 dark:text-slate-200 font-bold block mb-0.5">Skema Iuran 5%:</strong>
                  4% Ditanggung Perusahaan + 1% Dipotong dari Gaji Karyawan (Maks. batas atas Rp 12 Juta).
                </span>
              </div>
            </div>
          </div>
        </div>
      )}

      {/* ─── 7. TAB 5: DOKUMEN & AKSES PERANGKAT ────────────────────────── */}
      {formTab === 'access' && (
        <div className="space-y-6">
          {/* ─── FITUR AKSES SISTEM & PRESENSI (SISTEM CHECKBOX BERTINGKAT / PARENT-CHILD) ─── */}
          <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-3xl p-6 shadow-xs space-y-5">
            <div className="pb-3 border-b border-slate-100 dark:border-slate-800">
              <h3 className="text-xs font-extrabold text-slate-800 dark:text-slate-100 flex items-center gap-2">
                <Smartphone className="w-4 h-4 text-indigo-600" />
                Akses Akun Sistem & Kebijakan Presensi
              </h3>
              <p className="text-[11px] text-slate-400 dark:text-slate-500 mt-0.5">
                Atur kepemilikan akun login karyawan (digital vs non-sistem) serta hak presensi mobile dan jam kerja.
              </p>
            </div>

            {/* LEVEL 1: MASTER PARENT CHECKBOX */}
            <div className={`p-4 rounded-2xl border transition-all ${
              form.can_login !== false
                ? 'bg-indigo-50/40 dark:bg-indigo-950/20 border-indigo-200 dark:border-indigo-800'
                : 'bg-slate-50 dark:bg-slate-800/40 border-slate-200 dark:border-slate-700'
            }`}>
              <label className="flex items-start gap-3.5 cursor-pointer">
                <input
                  type="checkbox"
                  checked={form.can_login !== false}
                  onChange={(e) => {
                    const checked = e.target.checked;
                    setForm((prev: any) => ({
                      ...prev,
                      can_login: checked,
                      // Jika dimatikan (Non-Sistem), matikan presensi mobile dll.
                      attendanceEnabled: checked ? (prev.attendanceEnabled ?? true) : false,
                      wfhEnabled: checked ? prev.wfhEnabled : false,
                      radiusEnabled: checked ? prev.radiusEnabled : false,
                    }));
                  }}
                  className="w-5 h-5 rounded text-indigo-600 focus:ring-indigo-500 mt-0.5"
                />
                <div className="flex-1 min-w-0">
                  <div className="flex items-center gap-2 flex-wrap">
                    <span className="text-xs font-bold text-slate-900 dark:text-white">
                      Karyawan Memiliki Akun Login (Akses Digital Sistem)
                    </span>
                    {form.can_login !== false ? (
                      <span className="px-2 py-0.5 rounded-full text-[10px] font-bold bg-indigo-100 dark:bg-indigo-900/60 text-indigo-700 dark:text-indigo-300">
                        Akun Digital Aktif
                      </span>
                    ) : (
                      <span className="px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300">
                        Mode Non-Sistem (Deskless)
                      </span>
                    )}
                  </div>
                  <p className="text-[11px] text-slate-500 dark:text-slate-400 mt-1 leading-relaxed">
                    {form.can_login !== false
                      ? 'Karyawan memiliki akun digital untuk login ke sistem ExpenseFlow (aplikasi mobile / web dashboard).'
                      : 'Centang kotak ini jika karyawan membutuhkan akun login. Jika tidak dicentang (contoh: OB, Satpam, Driver), karyawan tidak dapat login dan tidak memerlukan password. Data tetap tersimpan untuk Payroll & BPJS.'}
                  </p>
                </div>
              </label>
            </div>

            {/* JIKA PARENT TIDAK DICENTANG (NON-SISTEM): BANNER INFORMATIF */}
            {form.can_login === false ? (
              <div className="p-4 rounded-2xl bg-amber-50/80 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-800/60 text-xs text-amber-900 dark:text-amber-200 space-y-2.5">
                <div className="flex items-center gap-2 font-bold">
                  <ShieldCheck className="w-4 h-4 text-amber-600 dark:text-amber-400" />
                  <span>Mode Karyawan Non-Sistem Aktif (Deskless Worker)</span>
                </div>
                <p className="text-[11px] text-amber-800 dark:text-amber-300/90 leading-relaxed">
                  Karyawan ini tidak memiliki akun login (Web maupun HP) dan tidak memerlukan password. Kehadiran dapat dicatat menggunakan <strong>Kios Presensi Cabang</strong>, <strong>Mesin Fingerprint / Kartu RFID</strong>, atau <strong>Diabsenkan langsung oleh Atasan</strong>.
                </p>
                <div className="flex flex-wrap gap-2 pt-1">
                  <span className="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-white/80 dark:bg-slate-900 border border-amber-300 dark:border-amber-700/80 text-amber-800 dark:text-amber-300">
                    ✓ Terdata di Payroll & Slip Gaji
                  </span>
                  <span className="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-white/80 dark:bg-slate-900 border border-amber-300 dark:border-amber-700/80 text-amber-800 dark:text-amber-300">
                    ✓ Terdata di Laporan BPJS & Pajak
                  </span>
                  <span className="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-white/80 dark:bg-slate-900 border border-amber-300 dark:border-amber-700/80 text-amber-800 dark:text-amber-300">
                    ✓ Tanpa Beban Kredensial Login
                  </span>
                </div>
              </div>
            ) : (
              /* LEVEL 2: CHILD CHECKBOXES (JIKA PARENT DICENTANG) */
              <div className="space-y-4 pt-1 border-l-2 border-indigo-200 dark:border-indigo-800/60 ml-3 pl-4 sm:ml-4 sm:pl-5">
                {/* Banner informasi jika ada izin presensi yang terkunci oleh shift aktif */}
                {(() => {
                  const shiftLocks = isEdit ? (editEmployee?.shiftLocks || editEmployee?.shift_locks) : null;
                  const hasLocks = Boolean(shiftLocks?.has_active_shift && (shiftLocks?.lock_attendance || shiftLocks?.lock_wfh || shiftLocks?.lock_radius));
                  if (!hasLocks) return null;
                  return (
                    <div className="flex items-start gap-2.5 p-3.5 rounded-2xl bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-800/60 text-xs text-amber-800 dark:text-amber-300">
                      <AlertTriangle className="w-4 h-4 text-amber-600 dark:text-amber-400 shrink-0 mt-0.5" />
                      <div className="space-y-0.5">
                        <p className="font-bold">Izin Presensi Terkunci oleh Penugasan Shift</p>
                        <p className="text-[11px] text-amber-700 dark:text-amber-400/90 leading-relaxed">
                          Karyawan terikat penugasan shift aktif/mendatang: <strong className="font-bold text-amber-900 dark:text-amber-200">'{shiftLocks.shift_name}'</strong>. Izin presensi yang dibutuhkan oleh shift ini dikunci dan tidak dapat dinonaktifkan di sini. Untuk mengubahnya, sesuaikan atau selesaikan penugasan shift di menu Manajemen Shift terlebih dahulu.
                        </p>
                      </div>
                    </div>
                  );
                })()}

                <div className="space-y-1">
                  <span className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">
                    Hak Akses Platform & Izin Presensi
                  </span>
                  <p className="text-[11px] text-slate-500 dark:text-slate-400">
                    Pilih platform yang boleh diakses dan metode kehadiran karyawan.
                  </p>
                </div>

                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                  {/* Akses Web Dashboard (Info sesuai Role) */}
                  <div className="p-3.5 rounded-2xl border bg-slate-50/80 dark:bg-slate-800/40 border-slate-200 dark:border-slate-700/80 space-y-1.5">
                    <div className="flex items-center gap-2">
                      <Monitor className="w-4 h-4 text-indigo-600 dark:text-indigo-400" />
                      <span className="text-xs font-bold text-slate-800 dark:text-slate-100">Akses Web Dashboard</span>
                    </div>
                    <p className="text-[10px] text-slate-400 leading-relaxed">
                      {form.role === 'employee'
                        ? 'Role "Employee" diarahkan login via aplikasi mobile. Akses web terbuka otomatis untuk role Staff Admin/Finance/SPV.'
                        : 'Karyawan memiliki wewenang untuk login ke dashboard web sesuai dengan modul pada Role-nya.'}
                    </p>
                    <span className="inline-block text-[9px] font-bold px-1.5 py-0.5 rounded-md bg-indigo-50 dark:bg-indigo-950/60 text-indigo-600 dark:text-indigo-400 border border-indigo-100 dark:border-indigo-800">
                      Role: {form.role || 'employee'}
                    </span>
                  </div>

                  {/* Presensi Diizinkan (Akses Mobile HP) */}
                  {(() => {
                    const shiftLocks = isEdit ? (editEmployee?.shiftLocks || editEmployee?.shift_locks) : null;
                    const isAttendanceLocked = Boolean(shiftLocks?.lock_attendance);

                    return (
                      <label
                        className={`flex items-start gap-3 p-3.5 rounded-2xl border transition ${
                          isAttendanceLocked
                            ? 'cursor-not-allowed bg-amber-50/40 dark:bg-amber-950/20 border-amber-300 dark:border-amber-700/60'
                            : form.attendanceEnabled !== false
                              ? 'bg-indigo-50/40 dark:bg-indigo-950/20 border-indigo-200 dark:border-indigo-800 cursor-pointer'
                              : 'bg-slate-50 dark:bg-slate-800/50 border-slate-200 dark:border-slate-700 cursor-pointer'
                        }`}
                        title={isAttendanceLocked ? shiftLocks?.reason_attendance : undefined}
                      >
                        <input
                          type="checkbox"
                          disabled={isAttendanceLocked}
                          checked={form.attendanceEnabled !== false}
                          onChange={(e) => {
                            if (isAttendanceLocked) return;
                            const checked = e.target.checked;
                            setForm({
                              ...form,
                              attendanceEnabled: checked,
                              wfhEnabled: checked ? (form.wfhEnabled !== false) : false,
                              radiusEnabled: checked ? ((form.wfhEnabled !== false) && (form.radiusEnabled !== false)) : false,
                            });
                          }}
                          className="w-4 h-4 rounded text-indigo-600 focus:ring-indigo-500 mt-0.5 disabled:opacity-50 disabled:cursor-not-allowed"
                        />
                        <div className="flex-1 min-w-0">
                          <div className="flex items-center gap-1.5 flex-wrap">
                            <span className="text-xs font-bold text-slate-800 dark:text-slate-100 block">
                              Presensi di HP (Akses Mobile)
                            </span>
                            {isAttendanceLocked && (
                              <span
                                className="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-full text-[9px] font-bold bg-rose-100 dark:bg-rose-950/60 text-rose-700 dark:text-rose-400 border border-rose-200 dark:border-rose-800"
                                title={shiftLocks?.reason_attendance}
                              >
                                <Ban className="w-2.5 h-2.5 shrink-0" /> Terkunci
                              </span>
                            )}
                          </div>
                          <span className="text-[10px] text-slate-400 block truncate">Bisa check-in/out mandiri di smartphone</span>
                        </div>
                      </label>
                    );
                  })()}

                  {/* Boleh WFH */}
                  {(() => {
                    const shiftLocks = isEdit ? (editEmployee?.shiftLocks || editEmployee?.shift_locks) : null;
                    const isWfhLocked = Boolean(shiftLocks?.lock_wfh);
                    const isAttActive = form.attendanceEnabled !== false;
                    const isWfhChecked = isAttActive && form.wfhEnabled !== false;
                    const isWfhDisabled = !isAttActive || isWfhLocked;
                    const wfhTitle = isWfhLocked
                      ? shiftLocks?.reason_wfh
                      : (!isAttActive ? 'Presensi di HP harus aktif terlebih dahulu' : undefined);

                    return (
                      <label
                        className={`flex items-start gap-3 p-3.5 rounded-2xl border transition ${
                          isWfhLocked
                            ? 'cursor-not-allowed bg-amber-50/40 dark:bg-amber-950/20 border-amber-300 dark:border-amber-700/60'
                            : !isAttActive
                              ? 'opacity-50 cursor-not-allowed bg-slate-100/60 dark:bg-slate-800/20 border-slate-200 dark:border-slate-800'
                              : isWfhChecked
                                ? 'bg-emerald-50/40 dark:bg-emerald-950/20 border-emerald-200 dark:border-emerald-800 cursor-pointer'
                                : 'bg-slate-50 dark:bg-slate-800/50 border-slate-200 dark:border-slate-700 cursor-pointer'
                        }`}
                        title={wfhTitle}
                      >
                        <input
                          type="checkbox"
                          disabled={isWfhDisabled}
                          checked={isWfhChecked}
                          onChange={(e) => {
                            if (isWfhDisabled) return;
                            const checked = e.target.checked;
                            setForm({
                              ...form,
                              wfhEnabled: checked,
                              radiusEnabled: false,
                            });
                          }}
                          className="w-4 h-4 rounded text-indigo-600 focus:ring-indigo-500 mt-0.5 disabled:opacity-50 disabled:cursor-not-allowed"
                        />
                        <div className="flex-1 min-w-0">
                          <div className="flex items-center gap-1.5 flex-wrap">
                            <span className="text-xs font-bold text-slate-800 dark:text-slate-100 block">Izinkan Presensi WFH</span>
                            {isWfhLocked && (
                              <span
                                className="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-full text-[9px] font-bold bg-rose-100 dark:bg-rose-950/60 text-rose-700 dark:text-rose-400 border border-rose-200 dark:border-rose-800"
                                title={shiftLocks?.reason_wfh}
                              >
                                <Ban className="w-2.5 h-2.5 shrink-0" /> Terkunci
                              </span>
                            )}
                          </div>
                          <span className="text-[10px] text-slate-400 block truncate">Boleh absen di luar kantor / dinas</span>
                        </div>
                      </label>
                    );
                  })()}

                  {/* Validasi Radius */}
                  {(() => {
                    const shiftLocks = isEdit ? (editEmployee?.shiftLocks || editEmployee?.shift_locks) : null;
                    const isRadiusLocked = Boolean(shiftLocks?.lock_radius);
                    const isAttActive = form.attendanceEnabled !== false;
                    const isWfhChecked = isAttActive && form.wfhEnabled !== false;
                    const isRadiusChecked = isAttActive && isWfhChecked && Boolean(form.radiusEnabled);
                    const isRadiusDisabled = !isAttActive || !isWfhChecked || isRadiusLocked;
                    const radiusTitle = isRadiusLocked
                      ? shiftLocks?.reason_radius
                      : (!isAttActive
                          ? 'Presensi di HP harus aktif terlebih dahulu'
                          : (!isWfhChecked ? 'Izinkan Presensi WFH harus aktif terlebih dahulu' : undefined));

                    return (
                      <label
                        className={`flex items-start gap-3 p-3.5 rounded-2xl border transition ${
                          isRadiusLocked
                            ? 'cursor-not-allowed bg-amber-50/40 dark:bg-amber-950/20 border-amber-300 dark:border-amber-700/60'
                            : isRadiusDisabled
                              ? 'opacity-50 cursor-not-allowed bg-slate-100/60 dark:bg-slate-800/20 border-slate-200 dark:border-slate-800'
                              : isRadiusChecked
                                ? 'bg-amber-50/40 dark:bg-amber-950/20 border-amber-200 dark:border-amber-800 cursor-pointer'
                                : 'bg-slate-50 dark:bg-slate-800/50 border-slate-200 dark:border-slate-700 cursor-pointer'
                        }`}
                        title={radiusTitle}
                      >
                        <input
                          type="checkbox"
                          disabled={isRadiusDisabled}
                          checked={isRadiusChecked}
                          onChange={(e) => {
                            if (isRadiusDisabled) return;
                            setForm({ ...form, radiusEnabled: e.target.checked });
                          }}
                          className="w-4 h-4 rounded text-indigo-600 focus:ring-indigo-500 mt-0.5 disabled:opacity-50 disabled:cursor-not-allowed"
                        />
                        <div className="flex-1 min-w-0">
                          <div className="flex items-center gap-1.5 flex-wrap">
                            <span className="text-xs font-bold text-slate-800 dark:text-slate-100 block">Validasi Radius Geofence</span>
                            {isRadiusLocked && (
                              <span
                                className="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-full text-[9px] font-bold bg-rose-100 dark:bg-rose-950/60 text-rose-700 dark:text-rose-400 border border-rose-200 dark:border-rose-800"
                                title={shiftLocks?.reason_radius}
                              >
                                <Ban className="w-2.5 h-2.5 shrink-0" /> Terkunci
                              </span>
                            )}
                          </div>
                          <span className="text-[10px] text-slate-400 block truncate">Kunci presensi sesuai koordinat GPS</span>
                        </div>
                      </label>
                    );
                  })()}

                  {/* Jam Fleksibel (Flexitime) */}
                  <label className={`flex items-start gap-3 p-3.5 rounded-2xl border transition cursor-pointer ${
                    Boolean(form.flexitimeEnabled)
                      ? 'bg-teal-50/40 dark:bg-teal-950/20 border-teal-200 dark:border-teal-800'
                      : 'bg-slate-50 dark:bg-slate-800/50 border-slate-200 dark:border-slate-700'
                  }`}>
                    <input
                      type="checkbox"
                      checked={Boolean(form.flexitimeEnabled)}
                      onChange={(e) => setForm({ ...form, flexitimeEnabled: e.target.checked })}
                      className="w-4 h-4 rounded text-teal-600 focus:ring-teal-500 mt-0.5"
                    />
                    <div>
                      <span className="text-xs font-bold text-slate-800 dark:text-slate-100 block">Jam Fleksibel (Flexitime)</span>
                      <span className="text-[10px] text-slate-400">Jadwal datang & pulang fleksibel</span>
                    </div>
                  </label>

                  {/* Hak Lembur Overtime */}
                  <label
                    className={`flex items-start gap-3 p-3.5 rounded-2xl border transition ${
                      isBranchOvertimeDisabled
                        ? 'bg-slate-100/70 dark:bg-slate-800/40 border-slate-200 dark:border-slate-800/80 opacity-75 cursor-not-allowed select-none'
                        : 'bg-slate-50 dark:bg-slate-800/50 border-slate-200 dark:border-slate-700 cursor-pointer'
                    }`}
                    title={
                      isBranchOvertimeDisabled
                        ? `Cabang "${selectedOffice?.office_name}" menonaktifkan fitur lembur otomatis.`
                        : undefined
                    }
                  >
                    <input
                      type="checkbox"
                      disabled={isBranchOvertimeDisabled}
                      checked={Boolean(isBranchOvertimeDisabled ? false : form.overtimeEligible)}
                      onChange={(e) => {
                        if (!isBranchOvertimeDisabled) {
                          setForm({ ...form, overtimeEligible: e.target.checked });
                        }
                      }}
                      className={`w-4 h-4 rounded mt-0.5 ${
                        isBranchOvertimeDisabled
                          ? 'text-slate-400 cursor-not-allowed accent-slate-400'
                          : 'text-indigo-600 focus:ring-indigo-500 cursor-pointer accent-indigo-600'
                      }`}
                    />
                    <div className="flex-1">
                      <div className="flex items-center gap-2">
                        <span
                          className={`text-xs font-bold block ${
                            isBranchOvertimeDisabled
                              ? 'text-slate-500 dark:text-slate-400'
                              : 'text-slate-800 dark:text-slate-100'
                          }`}
                        >
                          Berhak Upah Lembur
                        </span>
                        {isBranchOvertimeDisabled && (
                          <span className="text-[9px] font-bold px-2 py-0.5 rounded-full bg-amber-500/10 text-amber-600 dark:text-amber-400 border border-amber-500/20">
                            Cabang Nonaktif
                          </span>
                        )}
                      </div>
                      <span className="text-[10px] text-slate-400 block mt-0.5 leading-normal">
                        {isBranchOvertimeDisabled ? (
                          <>
                            Terkunci: Fitur hitung lembur otomatis dinonaktifkan pada pengaturan kantor{' '}
                            <strong className="text-slate-600 dark:text-slate-300">
                              {selectedOffice?.office_name ? `"${selectedOffice.office_name}"` : 'ini'}
                            </strong>
                            . Karyawan pada cabang ini tidak dapat diberikan hak lembur.
                          </>
                        ) : (
                          'Rumus Depnaker 1/173 x Gaji'
                        )}
                      </span>
                    </div>
                  </label>
                </div>
              </div>
            )}
          </div>

          {/* Keamanan Akun / Perangkat Binding */}
          <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-3xl p-6 shadow-xs space-y-4">
            <h3 className="text-xs font-extrabold text-slate-800 dark:text-slate-100 flex items-center gap-2 pb-3 border-b border-slate-100 dark:border-slate-800">
              <Lock className="w-4 h-4 text-indigo-600" />
              {isEdit ? 'Status Perangkat Terikat (Device Binding)' : 'Kredensial Akun & Kata Sandi Baru'}
            </h3>

            {form.can_login === false ? (
              <div className="p-4 rounded-2xl bg-slate-50 dark:bg-slate-800/40 border border-slate-200 dark:border-slate-700 text-xs text-slate-600 dark:text-slate-300 flex items-center gap-2.5">
                <Lock className="w-4 h-4 text-slate-400 shrink-0" />
                <span>Password tidak diperlukan karena akun ini tidak memiliki akses login (Non-Sistem / Deskless).</span>
              </div>
            ) : !isEdit ? (
              /* Add Mode: Password & Confirm Password */
              <div className="space-y-4">
                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                  <span className="text-[11px] text-slate-500 dark:text-slate-400">
                    Tentukan password sementara untuk login karyawan pertama kali.
                  </span>
                  <button
                    type="button"
                    onClick={generateRandomPassword}
                    className="self-start sm:self-auto text-[10px] font-bold text-indigo-600 dark:text-indigo-400 hover:text-indigo-700 dark:hover:text-indigo-300 flex items-center gap-1 cursor-pointer bg-indigo-50 dark:bg-indigo-950/40 px-2.5 py-1 rounded-lg border border-indigo-100 dark:border-indigo-800 transition"
                  >
                    <Sparkles className="w-3 h-3" />
                    Buat Password Acak
                  </button>
                </div>
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                  <div className="space-y-1">
                    <label className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">
                      Password Login Karyawan *
                    </label>
                    <div className="relative">
                      <input
                        type={form.showPassword ? 'text' : 'password'}
                        required
                        value={form.password || ''}
                        onChange={(e) => setForm({ ...form, password: e.target.value })}
                        placeholder="Minimal 8 karakter"
                        className="w-full text-xs px-3.5 py-2.5 bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-xl focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-600 transition pr-10"
                      />
                      <button
                        type="button"
                        onClick={() => setForm({ ...form, showPassword: !form.showPassword })}
                        className="absolute right-3 top-2.5 text-slate-400 hover:text-slate-600 cursor-pointer"
                      >
                        {form.showPassword ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
                      </button>
                    </div>
                  </div>

                  <div className="space-y-1">
                    <label className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">
                      Konfirmasi Ulang Password *
                    </label>
                    <input
                      type={form.showPassword ? 'text' : 'password'}
                      required
                      value={form.confirmPassword || ''}
                      onChange={(e) => setForm({ ...form, confirmPassword: e.target.value })}
                      placeholder="Ulangi password"
                      className="w-full text-xs px-3.5 py-2.5 bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-xl focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-600 transition"
                    />
                  </div>
                </div>

                {form.password && form.confirmPassword && (
                  form.password === form.confirmPassword ? (
                    <div className="p-2.5 px-3 bg-emerald-50 dark:bg-emerald-950/20 border border-emerald-100 dark:border-emerald-900/30 rounded-xl text-emerald-700 dark:text-emerald-400 text-xs flex items-center gap-1.5">
                      <Check className="w-4 h-4 shrink-0" />
                      <span>Password terkonfirmasi cocok.</span>
                    </div>
                  ) : (
                    <div className="p-2.5 px-3 bg-rose-50 dark:bg-rose-950/20 border border-rose-100 dark:border-rose-900/30 rounded-xl text-rose-600 dark:text-rose-400 text-xs flex items-center gap-1.5">
                      <X className="w-4 h-4 shrink-0" />
                      <span>Password konfirmasi tidak cocok!</span>
                    </div>
                  )
                )}
              </div>
            ) : (
              /* Edit Mode: Device Binding status */
              <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div className="space-y-1">
                  <span className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">Nama Perangkat HP</span>
                  <div className="p-3 bg-slate-50 dark:bg-slate-800/60 rounded-xl border border-slate-100 dark:border-slate-700/60 text-xs font-semibold text-slate-800 dark:text-slate-200">
                    {editEmployee?.deviceName || 'Belum Terikat'}
                  </div>
                </div>

                <div className="space-y-1">
                  <span className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">Device ID Hardware</span>
                  <div className="p-3 bg-slate-50 dark:bg-slate-800/60 rounded-xl border border-slate-100 dark:border-slate-700/60 text-xs font-mono text-slate-700 dark:text-slate-300 truncate">
                    {editEmployee?.deviceId || '—'}
                  </div>
                </div>

                <div className="space-y-1 flex flex-col justify-end">
                  <button
                    type="button"
                    onClick={() => alert('Fitur Reset Device ID: Karyawan dapat mengikat HP baru pada login berikutnya.')}
                    className="w-full py-2.5 px-3 bg-amber-50 hover:bg-amber-100 dark:bg-amber-950/40 text-amber-800 dark:text-amber-300 border border-amber-300 dark:border-amber-800 rounded-xl text-xs font-bold transition flex items-center justify-center gap-2 cursor-pointer"
                  >
                    <RefreshCw className="w-3.5 h-3.5" />
                    <span>Reset Kuncian Perangkat</span>
                  </button>
                </div>
              </div>
            )}
          </div>

          {/* ─── PENGARSIPAN BERKAS & DOKUMEN DIGITAL KARYAWAN (FITUR 4) ─── */}
          <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-3xl p-6 shadow-xs space-y-6">
            <div className="flex flex-col sm:flex-row sm:items-center justify-between pb-4 border-b border-slate-100 dark:border-slate-800 gap-2">
              <div>
                <h3 className="text-sm font-extrabold text-slate-900 dark:text-white flex items-center gap-2">
                  <FileText className="w-5 h-5 text-indigo-600" />
                  Pengarsipan Berkas & Dokumen Digital Karyawan
                </h3>
                <p className="text-xs text-slate-500 dark:text-slate-400 mt-1">
                  Arsipkan salinan resmi KTP, Kartu Keluarga, NPWP, Rekening Tabungan, dan Surat Kontrak (PDF, JPG, PNG maks 10 MB).
                </p>
              </div>
              <div className="flex items-center gap-2">
                <span className="px-3 py-1 text-xs font-bold rounded-full bg-indigo-50 dark:bg-indigo-950/50 text-indigo-700 dark:text-indigo-400 border border-indigo-100 dark:border-indigo-800">
                  {isEdit
                    ? `${documents.length} Berkas Terarsip`
                    : `${Object.keys(form.pendingDocuments || {}).length} Berkas Dipilih`}
                </span>
              </div>
            </div>

            {/* Grid Dokumen Resmi Utama */}
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              {STANDARD_DOCUMENTS.map((docItem) => {
                const Icon = docItem.icon;
                const existingDoc = isEdit ? documents.find((d) => d.document_type === docItem.type) : null;
                const pendingFile = !isEdit ? form.pendingDocuments?.[docItem.type] : null;
                const isUploading = uploadingType === docItem.type;

                return (
                  <div
                    key={docItem.type}
                    className={`p-4 rounded-2xl border transition-all ${
                      existingDoc
                        ? 'bg-emerald-50/40 dark:bg-emerald-950/20 border-emerald-200 dark:border-emerald-800/40'
                        : pendingFile
                        ? 'bg-indigo-50/40 dark:bg-indigo-950/20 border-indigo-200 dark:border-indigo-800/40'
                        : 'bg-slate-50/60 dark:bg-slate-800/30 border-slate-200/80 dark:border-slate-700/80 border-dashed'
                    }`}
                  >
                    <div className="flex items-start justify-between gap-3 mb-2.5">
                      <div className="flex items-center gap-2.5">
                        <div
                          className={`w-9 h-9 rounded-xl flex items-center justify-center shrink-0 ${
                            existingDoc
                              ? 'bg-emerald-100 dark:bg-emerald-900/60 text-emerald-700 dark:text-emerald-400'
                              : pendingFile
                              ? 'bg-indigo-100 dark:bg-indigo-900/60 text-indigo-700 dark:text-indigo-400'
                              : 'bg-slate-200/70 dark:bg-slate-700/70 text-slate-500 dark:text-slate-400'
                          }`}
                        >
                          <Icon className="w-4.5 h-4.5" />
                        </div>
                        <div>
                          <div className="flex items-center gap-1.5">
                            <h4 className="text-xs font-bold text-slate-800 dark:text-slate-100">
                              {docItem.label}
                            </h4>
                            {docItem.required && (
                              <span className="text-[10px] text-rose-500 font-bold" title="Wajib">
                                *
                              </span>
                            )}
                          </div>
                          <span className="text-[9px] font-semibold text-slate-400 uppercase tracking-wider block">
                            {docItem.badge}
                          </span>
                        </div>
                      </div>

                      {/* Status Badge */}
                      {existingDoc ? (
                        <span className="inline-flex items-center gap-1 px-2 py-0.5 text-[10px] font-bold text-emerald-700 dark:text-emerald-400 bg-emerald-100 dark:bg-emerald-950/50 rounded-full border border-emerald-200 dark:border-emerald-800">
                          <Check className="w-2.5 h-2.5" />
                          Terarsip
                        </span>
                      ) : pendingFile ? (
                        <span className="inline-flex items-center gap-1 px-2 py-0.5 text-[10px] font-bold text-indigo-700 dark:text-indigo-400 bg-indigo-100 dark:bg-indigo-950/50 rounded-full border border-indigo-200 dark:border-indigo-800">
                          Siap Unggah
                        </span>
                      ) : (
                        <span className="px-2 py-0.5 text-[10px] font-medium text-slate-400 bg-slate-100 dark:bg-slate-800 rounded-full">
                          Belum Ada
                        </span>
                      )}
                    </div>

                    <p className="text-[11px] text-slate-500 dark:text-slate-400 mb-3 leading-relaxed">
                      {docItem.desc}
                    </p>

                    {/* State 1: Dokumen Sudah Terarsip di Database (Mode Edit) */}
                    {existingDoc && (
                      <div className="pt-2.5 border-t border-emerald-100 dark:border-emerald-900/40 space-y-2">
                        <div className="flex items-center justify-between text-[11px]">
                          <span className="font-mono text-slate-700 dark:text-slate-300 truncate max-w-[200px]" title={existingDoc.file_name}>
                            {existingDoc.file_name}
                          </span>
                          <span className="text-[10px] font-semibold text-emerald-700 dark:text-emerald-400 shrink-0">
                            {existingDoc.file_size_formatted || `${(existingDoc.file_size / 1024).toFixed(1)} KB`}
                          </span>
                        </div>

                        <div className="flex items-center justify-between gap-1.5 pt-1">
                          <div className="flex items-center gap-1.5">
                            <button
                              type="button"
                              onClick={() => handlePreviewDocument(existingDoc)}
                              className="px-2.5 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-xs font-bold transition flex items-center gap-1 cursor-pointer shadow-xs"
                            >
                              <Eye className="w-3.5 h-3.5" />
                              Lihat
                            </button>
                            <button
                              type="button"
                              onClick={() => userDocumentApi.download(existingDoc.user_id, existingDoc.id, existingDoc.file_name)}
                              className="px-2.5 py-1.5 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 hover:bg-slate-50 text-slate-700 dark:text-slate-200 rounded-lg text-xs font-semibold transition flex items-center gap-1 cursor-pointer"
                              title="Unduh Berkas"
                            >
                              <Download className="w-3.5 h-3.5" />
                              Unduh
                            </button>
                          </div>

                          <div className="flex items-center gap-1.5">
                            {/* Ganti file label trigger */}
                            <label className="px-2 py-1.5 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 hover:bg-slate-50 text-slate-600 dark:text-slate-300 rounded-lg text-[11px] font-medium transition cursor-pointer flex items-center gap-1">
                              <UploadCloud className="w-3 h-3 text-slate-400" />
                              Ganti
                              <input
                                type="file"
                                accept={getAcceptString(docItem.allowedFormat)}
                                className="hidden"
                                disabled={isUploading}
                                onChange={(e) => {
                                  const f = e.target.files?.[0];
                                  if (f) handleUploadDocument(docItem.type, f, docItem.label);
                                  e.target.value = '';
                                }}
                              />
                            </label>

                            <button
                              type="button"
                              disabled={deletingDocId === existingDoc.id}
                              onClick={() => handleDeleteDocument(existingDoc.id, existingDoc.title || docItem.label)}
                              className="p-1.5 text-rose-500 hover:text-rose-700 hover:bg-rose-50 dark:hover:bg-rose-950/30 rounded-lg transition cursor-pointer"
                              title="Hapus Berkas dari Arsip"
                            >
                              <Trash2 className="w-3.5 h-3.5" />
                            </button>
                          </div>
                        </div>
                      </div>
                    )}

                    {/* State 2: Berkas Dipilih untuk Registrasi Baru (Mode Add) */}
                    {!isEdit && pendingFile && (
                      <div className="pt-2.5 border-t border-indigo-100 dark:border-indigo-900/40 space-y-2">
                        <div className="flex items-center justify-between text-[11px]">
                          <span className="font-mono text-slate-700 dark:text-slate-300 truncate max-w-[200px]" title={pendingFile.name}>
                            {pendingFile.name}
                          </span>
                          <span className="text-[10px] font-semibold text-indigo-600 dark:text-indigo-400 shrink-0">
                            {(pendingFile.size / 1024).toFixed(1)} KB
                          </span>
                        </div>
                        <div className="flex items-center justify-between pt-1">
                          <span className="text-[10px] text-slate-400">
                            Otomatis diunggah saat formulir disimpan
                          </span>
                          <button
                            type="button"
                            onClick={() => handleRemovePendingDocument(docItem.type)}
                            className="px-2 py-1 bg-rose-50 hover:bg-rose-100 dark:bg-rose-950/40 text-rose-600 dark:text-rose-400 rounded-lg text-[10px] font-bold transition flex items-center gap-1 cursor-pointer"
                          >
                            <X className="w-3 h-3" />
                            Batal
                          </button>
                        </div>
                      </div>
                    )}

                    {/* State 3: Belum Ada Berkas (Bisa Upload Langsung / Pilih File) */}
                    {(!existingDoc && !pendingFile) && (
                      <div className="pt-2 border-t border-slate-200/60 dark:border-slate-700/60">
                        <label className="w-full py-2 px-3 rounded-xl border border-slate-200 dark:border-slate-700 hover:border-indigo-400 dark:hover:border-indigo-500 bg-white dark:bg-slate-800/60 hover:bg-indigo-50/40 dark:hover:bg-indigo-950/30 transition flex items-center justify-center gap-2 text-xs font-semibold text-slate-700 dark:text-slate-300 cursor-pointer shadow-2xs">
                          {isUploading ? (
                            <>
                              <Loader2 className="w-4 h-4 text-indigo-600 animate-spin" />
                              <span>Mengunggah...</span>
                            </>
                          ) : (
                            <>
                              <UploadCloud className="w-4 h-4 text-indigo-600" />
                              <span>Pilih Berkas ({docItem.label.split(' ')[0]})</span>
                            </>
                          )}
                          <input
                            type="file"
                            accept=".pdf,.jpg,.jpeg,.png,.webp"
                            className="hidden"
                            disabled={isUploading}
                            onChange={(e) => {
                              const f = e.target.files?.[0];
                              if (f) {
                                if (isEdit) {
                                  handleUploadDocument(docItem.type, f, docItem.label);
                                } else {
                                  handleSelectPendingDocument(docItem.type, f);
                                }
                              }
                              e.target.value = '';
                            }}
                          />
                        </label>
                      </div>
                    )}
                  </div>
                );
              })}
            </div>

            {/* Unggah Dokumen Tambahan Lainnya (Khusus Mode Edit) */}
            {isEdit && (
              <div className="pt-4 border-t border-slate-100 dark:border-slate-800 space-y-3">
                <div className="flex items-center justify-between">
                  <h4 className="text-xs font-bold text-slate-800 dark:text-slate-200 flex items-center gap-1.5">
                    <Plus className="w-3.5 h-3.5 text-indigo-600" />
                    Unggah Dokumen Tambahan Lainnya
                  </h4>
                  <span className="text-[10px] text-slate-400">
                    Sertifikat K3, Paklaring Lama, Ijazah Khusus, dll.
                  </span>
                </div>

                <div className="p-4 rounded-2xl bg-slate-50 dark:bg-slate-800/40 border border-slate-200 dark:border-slate-700 space-y-3">
                  <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div className="space-y-1">
                      <label className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">
                        Kategori Dokumen
                      </label>
                      <select
                        value={customDocType}
                        onChange={(e) => setCustomDocType(e.target.value)}
                        className="w-full text-xs px-3 py-2 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl focus:ring-2 focus:ring-indigo-500/20"
                      >
                        <option value="sertifikat">Sertifikat Keahlian / K3</option>
                        <option value="ijazah">Ijazah / Transkrip</option>
                        <option value="kontrak_kerja">Surat Perjanjian / Adendum</option>
                        <option value="lainnya">Dokumen Lainnya</option>
                      </select>
                    </div>

                    <div className="space-y-1 sm:col-span-2">
                      <label className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">
                        Judul Dokumen Tambahan
                      </label>
                      <input
                        type="text"
                        value={customDocTitle}
                        onChange={(e) => setCustomDocTitle(e.target.value)}
                        placeholder="Contoh: Sertifikat Ahli K3 Umum Kemenaker"
                        className="w-full text-xs px-3 py-2 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl focus:ring-2 focus:ring-indigo-500/20"
                      />
                    </div>
                  </div>

                  <div className="flex justify-end pt-1">
                    <label className="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-bold transition flex items-center gap-1.5 cursor-pointer shadow-xs">
                      {uploadingType === 'custom' ? (
                        <>
                          <Loader2 className="w-3.5 h-3.5 animate-spin" />
                          <span>Mengunggah...</span>
                        </>
                      ) : (
                        <>
                          <UploadCloud className="w-3.5 h-3.5" />
                          <span>Pilih & Unggah Berkas</span>
                        </>
                      )}
                      <input
                        type="file"
                        accept=".pdf,.jpg,.jpeg,.png,.webp"
                        className="hidden"
                        disabled={uploadingType === 'custom'}
                        onChange={(e) => {
                          const f = e.target.files?.[0];
                          if (f) {
                            handleUploadDocument(customDocType, f, customDocTitle || undefined);
                            setCustomDocTitle('');
                          }
                          e.target.value = '';
                        }}
                      />
                    </label>
                  </div>
                </div>

                {/* Daftar Dokumen Lainnya yang Sudah Terunggah */}
                {documents.filter((d) => !STANDARD_DOCUMENTS.some((s) => s.type === d.document_type)).length > 0 && (
                  <div className="space-y-2 pt-2">
                    <span className="text-[11px] font-bold text-slate-700 dark:text-slate-300 block">
                      Dokumen Tambahan Terarsip:
                    </span>
                    <div className="space-y-1.5">
                      {documents
                        .filter((d) => !STANDARD_DOCUMENTS.some((s) => s.type === d.document_type))
                        .map((extraDoc) => (
                          <div
                            key={extraDoc.id}
                            className="p-3 bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 flex items-center justify-between text-xs"
                          >
                            <div className="flex items-center gap-2 min-w-0">
                              <File className="w-4 h-4 text-indigo-600 shrink-0" />
                              <div className="min-w-0">
                                <span className="font-bold text-slate-800 dark:text-slate-100 block truncate">
                                  {extraDoc.title || extraDoc.file_name}
                                </span>
                                <span className="text-[10px] text-slate-400 font-mono">
                                  {extraDoc.file_name} • {extraDoc.file_size_formatted || `${(extraDoc.file_size / 1024).toFixed(1)} KB`}
                                </span>
                              </div>
                            </div>
                            <div className="flex items-center gap-1.5 shrink-0 ml-2">
                              <button
                                type="button"
                                onClick={() => handlePreviewDocument(extraDoc)}
                                className="px-2.5 py-1 bg-indigo-50 dark:bg-indigo-950/40 text-indigo-600 dark:text-indigo-400 rounded-lg text-xs font-bold hover:bg-indigo-100 transition flex items-center gap-1 cursor-pointer"
                              >
                                <Eye className="w-3 h-3" />
                                Lihat
                              </button>
                              <button
                                type="button"
                                onClick={() => userDocumentApi.download(extraDoc.user_id, extraDoc.id, extraDoc.file_name)}
                                className="p-1.5 hover:bg-slate-100 dark:hover:bg-slate-700 text-slate-500 rounded-lg transition cursor-pointer"
                                title="Unduh"
                              >
                                <Download className="w-3.5 h-3.5" />
                              </button>
                              <button
                                type="button"
                                disabled={deletingDocId === extraDoc.id}
                                onClick={() => handleDeleteDocument(extraDoc.id, extraDoc.title || extraDoc.file_name)}
                                className="p-1.5 text-rose-500 hover:bg-rose-50 dark:hover:bg-rose-950/30 rounded-lg transition cursor-pointer"
                                title="Hapus"
                              >
                                <Trash2 className="w-3.5 h-3.5" />
                              </button>
                            </div>
                          </div>
                        ))}
                    </div>
                  </div>
                )}
              </div>
            )}
          </div>
        </div>
      )}


      {/* ─── 8. NAVIGATION FOOTER ──────────────────────────────────────── */}
      <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-3xl p-4 shadow-xs flex flex-col sm:flex-row items-center justify-between gap-3">
        <div className="flex items-center gap-2 w-full sm:w-auto">
          <button
            type="button"
            onClick={onCancel}
            className="px-4 py-2.5 border border-slate-200 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-800 text-slate-600 dark:text-slate-300 rounded-xl text-xs font-semibold transition cursor-pointer"
          >
            Batal
          </button>
          {formTab !== 'work' && (
            <button
              type="button"
              onClick={() => {
                const idx = FORM_TABS.findIndex((t) => t.id === formTab);
                if (idx > 0) setFormTab(FORM_TABS[idx - 1].id);
              }}
              className="px-4 py-2.5 border border-indigo-200 dark:border-indigo-900/60 hover:bg-indigo-50 dark:hover:bg-indigo-950/40 text-indigo-600 dark:text-indigo-400 rounded-xl text-xs font-bold transition cursor-pointer flex items-center gap-1.5"
            >
              <ChevronLeft className="w-4 h-4" />
              Sebelumnya
            </button>
          )}
        </div>

        <div className="flex items-center gap-2 w-full sm:w-auto justify-end">
          {formTab !== 'access' && (
            <button
              type="button"
              onClick={() => {
                const idx = FORM_TABS.findIndex((t) => t.id === formTab);
                if (idx < FORM_TABS.length - 1) setFormTab(FORM_TABS[idx + 1].id);
              }}
              className="px-4 py-2.5 bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 rounded-xl text-xs font-bold transition cursor-pointer flex items-center gap-1.5"
            >
              <span>Selanjutnya</span>
              <ChevronRight className="w-4 h-4" />
            </button>
          )}
          <button
            type="submit"
            disabled={(!isEdit && form.password !== form.confirmPassword) || submitting}
            className="px-6 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white font-extrabold text-xs rounded-xl shadow-md shadow-indigo-500/20 transition cursor-pointer disabled:opacity-50 flex items-center justify-center gap-2"
          >
            <Check className="w-4 h-4" />
            <span>
              {submitting
                ? 'Menyimpan...'
                : isEdit
                ? 'Simpan Perubahan Data'
                : 'Simpan Karyawan Baru'}
            </span>
          </button>
        </div>
      </div>

      {/* Modal Preview Berkas Dokumen (Lightbox) */}
      {previewDoc && (
        <div className="fixed inset-0 z-50 overflow-hidden flex items-center justify-center p-4 bg-slate-950/80 backdrop-blur-xs animate-in fade-in duration-150">
          <div className="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-3xl max-w-4xl w-full max-h-[90vh] flex flex-col shadow-2xl overflow-hidden">
            <div className="flex items-center justify-between p-4 px-6 border-b border-slate-100 dark:border-slate-800 bg-slate-50/70 dark:bg-slate-800/40">
              <div className="flex items-center gap-2">
                <FileText className="w-5 h-5 text-indigo-600" />
                <h4 className="text-sm font-bold text-slate-800 dark:text-slate-100">{previewDoc.title}</h4>
              </div>
              <div className="flex items-center gap-2">
                <button
                  type="button"
                  onClick={() => window.open(previewDoc.url, '_blank')}
                  className="p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 text-slate-500 hover:text-slate-700 dark:hover:text-slate-200 transition cursor-pointer"
                  title="Buka di tab baru"
                >
                  <ExternalLink className="w-4 h-4" />
                </button>
                <button
                  type="button"
                  onClick={() => setPreviewDoc(null)}
                  className="p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 text-slate-500 hover:text-slate-700 dark:hover:text-slate-200 transition cursor-pointer"
                  title="Tutup"
                >
                  <X className="w-5 h-5" />
                </button>
              </div>
            </div>
            <div className="flex-1 overflow-auto p-4 flex items-center justify-center bg-slate-900/10 dark:bg-slate-950/50 min-h-[300px]">
              {previewDoc.isImage ? (
                <img
                  src={previewDoc.url}
                  alt={previewDoc.title}
                  className="max-h-[70vh] max-w-full rounded-2xl object-contain shadow-md"
                />
              ) : (
                <iframe
                  src={`${previewDoc.url}#toolbar=1`}
                  title={previewDoc.title}
                  className="w-full h-[70vh] rounded-xl border border-slate-200 dark:border-slate-800"
                />
              )}
            </div>
          </div>
        </div>
      )}

      {/* Modal Buat / Edit Custom Role Langsung dari Form Karyawan */}
      {isRoleModalOpen && (
        <RoleFormModal
          isOpen={isRoleModalOpen}
          roleToEdit={roleToEditInModal}
          offices={offices}
          onClose={() => {
            setIsRoleModalOpen(false);
            setRoleToEditInModal(null);
          }}
          onSaved={handleRoleSavedInEmployeeForm}
        />
      )}
    </form>
  );
};

