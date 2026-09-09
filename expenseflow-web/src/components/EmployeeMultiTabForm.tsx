import React from 'react';
import {
  Briefcase,
  UserCheck,
  Wallet,
  ShieldCheck,
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
  RefreshCw,
  Landmark,
  BadgePercent,
  X
} from 'lucide-react';
import CustomDatePicker from './CustomDatePicker';
import { FormTabType, FORM_TABS } from './KaryawanManagement';

interface Office {
  id: number;
  office_name: string;
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
                  className={`w-7 h-7 rounded-lg flex items-center justify-center text-xs font-black shrink-0 ${
                    isActive
                      ? 'bg-white/20 text-white'
                      : 'bg-slate-100 dark:bg-slate-800 text-slate-500 dark:text-slate-400'
                  }`}
                >
                  {tab.step}
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
                    overtimeEligible: true,
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
                    overtimeEligible: true,
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
                    overtimeEligible: true,
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
                <label className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">
                  Email Perusahaan (Login) *
                </label>
                <input
                  type="email"
                  required
                  value={form.email || ''}
                  onChange={(e) => setForm({ ...form, email: e.target.value })}
                  placeholder="karyawan@perusahaan.com"
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

              {/* Departemen */}
              <div className="space-y-1">
                <label className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">
                  Departemen *
                </label>
                <select
                  value={form.dept || ''}
                  onChange={(e) => setForm({ ...form, dept: e.target.value })}
                  className="w-full text-xs px-3.5 py-2.5 bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-xl focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-600 transition"
                >
                  <option value="">Pilih Departemen</option>
                  {departments.map((d) => (
                    <option key={d} value={d}>
                      {d}
                    </option>
                  ))}
                </select>
              </div>

              {/* Jabatan */}
              <div className="space-y-1">
                <label className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">
                  Jabatan / Posisi Kerja
                </label>
                <input
                  type="text"
                  value={form.jabatan || ''}
                  onChange={(e) => setForm({ ...form, jabatan: e.target.value })}
                  placeholder="Contoh: Senior Staff, Supervisor"
                  className="w-full text-xs px-3.5 py-2.5 bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-xl focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-600 transition"
                />
              </div>

              {/* Role Sistem */}
              <div className="space-y-1">
                <label className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">
                  Hak Akses / Role Sistem *
                </label>
                <select
                  required
                  value={form.role || 'employee'}
                  onChange={(e) => setForm({ ...form, role: e.target.value })}
                  className="w-full text-xs px-3.5 py-2.5 bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-xl focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-600 transition"
                >
                  <option value="employee">Pegawai (Presensi & Klaim Biasa)</option>
                  <option value="hrd">Staf HRD (Kelola Karyawan & Roster)</option>
                  <option value="finance">Staf Finance (Approval Keuangan)</option>
                  <option value="admin">Administrator (Akses Penuh)</option>
                </select>
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
                  <option value="">Belum ditentukan / Semua Kantor</option>
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
                      onChange={(val) => setForm({ ...form, contractStartDate: val })}
                      placeholder="Mulai kontrak"
                    />
                  </div>

                  <div className="space-y-1">
                    <label className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">
                      Tanggal Berakhir Kontrak
                    </label>
                    <CustomDatePicker
                      value={form.contractEndDate || ''}
                      onChange={(val) => setForm({ ...form, contractEndDate: val })}
                      placeholder="Selesai kontrak"
                    />
                  </div>
                </>
              )}

              {/* Batas Klaim Struk Bulanan */}
              <div className="space-y-1 sm:col-span-2 lg:col-span-3">
                <label className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">
                  Batas Klaim Struk Bulanan (Nominal IDR)
                </label>
                <div className="relative">
                  <span className="absolute left-3.5 top-2.5 text-xs text-slate-400 font-semibold">Rp</span>
                  <input
                    type="number"
                    min="0"
                    step="10000"
                    value={form.limit === null || form.limit === '' ? '' : form.limit}
                    onChange={(e) =>
                      setForm({
                        ...form,
                        limit: e.target.value === '' ? '' : Number(e.target.value),
                      })
                    }
                    placeholder="Kosongkan jika tanpa batas klaim (Unlimited)"
                    className="w-full text-xs pl-10 pr-3.5 py-2.5 bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-xl focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-600 transition"
                  />
                </div>
                <p className="text-[10px] text-slate-400 mt-1">
                  Karyawan tidak dapat mengajukan klaim struk reimbursement jika total klaim bulanan melebihi limit ini.
                </p>
              </div>
            </div>
          </div>
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
                <label className="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">
                  Nomor Induk Kependudukan (NIK KTP 16 Digit)
                </label>
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
                  Alamat Lengkap Sesuai KTP
                </label>
                <textarea
                  rows={2}
                  value={form.ktpAddress || ''}
                  onChange={(e) => setForm({ ...form, ktpAddress: e.target.value })}
                  placeholder="Nama jalan, RT/RW, Kelurahan, Kecamatan, Kota/Kabupaten, Provinsi"
                  className="w-full text-xs px-3.5 py-2.5 bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-xl focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-600 transition"
                />
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
                    step="50000"
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
              </div>

              {/* Hak Lembur Overtime */}
              <div className="space-y-1 sm:col-span-2 lg:col-span-1 flex flex-col justify-end">
                <label className="flex items-center gap-2.5 p-3 bg-slate-50 dark:bg-slate-800/50 rounded-xl border border-slate-200 dark:border-slate-700 cursor-pointer">
                  <input
                    type="checkbox"
                    checked={Boolean(form.overtimeEligible)}
                    onChange={(e) => setForm({ ...form, overtimeEligible: e.target.checked })}
                    className="w-4 h-4 rounded text-indigo-600 focus:ring-indigo-500"
                  />
                  <div className="text-xs">
                    <span className="font-bold text-slate-800 dark:text-slate-100 block">Berhak Upah Lembur</span>
                    <span className="text-[10px] text-slate-400">Rumus Depnaker 1/173 x Gaji</span>
                  </div>
                </label>
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
          {/* Pengaturan Presensi Mobile */}
          <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-3xl p-6 shadow-xs space-y-4">
            <h3 className="text-xs font-extrabold text-slate-800 dark:text-slate-100 flex items-center gap-2 pb-3 border-b border-slate-100 dark:border-slate-800">
              <Smartphone className="w-4 h-4 text-indigo-600" />
              Kebijakan Akses Presensi Mobile App
            </h3>

            <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
              {/* Presensi Diizinkan */}
              <label className="flex items-start gap-3 p-3.5 bg-slate-50 dark:bg-slate-800/50 rounded-2xl border border-slate-200 dark:border-slate-700 cursor-pointer">
                <input
                  type="checkbox"
                  checked={form.attendanceEnabled !== false}
                  onChange={(e) => setForm({ ...form, attendanceEnabled: e.target.checked })}
                  className="w-4 h-4 rounded text-indigo-600 focus:ring-indigo-500 mt-0.5"
                />
                <div>
                  <span className="text-xs font-bold text-slate-800 dark:text-slate-100 block">Presensi Mobile Aktif</span>
                  <span className="text-[10px] text-slate-400">Bisa check-in/out di HP</span>
                </div>
              </label>

              {/* Boleh WFH */}
              <label className="flex items-start gap-3 p-3.5 bg-slate-50 dark:bg-slate-800/50 rounded-2xl border border-slate-200 dark:border-slate-700 cursor-pointer">
                <input
                  type="checkbox"
                  checked={form.wfhEnabled !== false}
                  onChange={(e) => setForm({ ...form, wfhEnabled: e.target.checked })}
                  className="w-4 h-4 rounded text-indigo-600 focus:ring-indigo-500 mt-0.5"
                />
                <div>
                  <span className="text-xs font-bold text-slate-800 dark:text-slate-100 block">Izinkan Presensi WFH</span>
                  <span className="text-[10px] text-slate-400">Boleh absen luar kantor</span>
                </div>
              </label>

              {/* Validasi Radius */}
              <label className="flex items-start gap-3 p-3.5 bg-slate-50 dark:bg-slate-800/50 rounded-2xl border border-slate-200 dark:border-slate-700 cursor-pointer">
                <input
                  type="checkbox"
                  checked={form.radiusEnabled !== false}
                  onChange={(e) => setForm({ ...form, radiusEnabled: e.target.checked })}
                  className="w-4 h-4 rounded text-indigo-600 focus:ring-indigo-500 mt-0.5"
                />
                <div>
                  <span className="text-xs font-bold text-slate-800 dark:text-slate-100 block">Validasi Radius Geofence</span>
                  <span className="text-[10px] text-slate-400">Cek koordinat GPS kantor</span>
                </div>
              </label>
            </div>
          </div>

          {/* Keamanan Akun / Perangkat Binding */}
          <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-3xl p-6 shadow-xs space-y-4">
            <h3 className="text-xs font-extrabold text-slate-800 dark:text-slate-100 flex items-center gap-2 pb-3 border-b border-slate-100 dark:border-slate-800">
              <Lock className="w-4 h-4 text-indigo-600" />
              {isEdit ? 'Status Perangkat Terikat (Device Binding)' : 'Kredensial Akun & Kata Sandi Baru'}
            </h3>

            {!isEdit ? (
              /* Add Mode: Password & Confirm Password */
              <div className="space-y-4">
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

          {/* Filing Berkas Dokumen Digital */}
          <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-3xl p-6 shadow-xs space-y-4">
            <div className="flex items-center justify-between pb-3 border-b border-slate-100 dark:border-slate-800">
              <h3 className="text-xs font-extrabold text-slate-800 dark:text-slate-100 flex items-center gap-2">
                <FileText className="w-4 h-4 text-indigo-600" />
                Kesiapan Pengarsipan Dokumen Karyawan Digital
              </h3>
              <span className="text-[10px] text-slate-400 font-medium">Digital Filing Roadmap</span>
            </div>

            <p className="text-xs text-slate-500 dark:text-slate-400">
              Sistem telah dipersiapkan untuk pengarsipan digital berkas penting kepegawaian. Fitur upload file digital akan aktif pada modul Penggajian & Dokumen Fase 2:
            </p>

            <div className="grid grid-cols-2 sm:grid-cols-5 gap-3">
              {[
                { label: 'Foto KTP Asli', desc: 'Validasi NIK 16 digit' },
                { label: 'Kartu Keluarga', desc: 'Verifikasi Tanggungan' },
                { label: 'Kartu NPWP', desc: 'Bukti Potong PPh 21' },
                { label: 'Buku Tabungan', desc: 'Konfirmasi No Rekening' },
                { label: 'Pas Foto 4x6', desc: 'ID Card & Profil Mobile' },
              ].map((doc, i) => (
                <div key={i} className="p-3 rounded-2xl bg-slate-50 dark:bg-slate-800/40 border border-slate-200/60 dark:border-slate-700/60 text-center space-y-1">
                  <div className="w-7 h-7 mx-auto rounded-lg bg-indigo-50 dark:bg-indigo-950/50 text-indigo-600 dark:text-indigo-400 flex items-center justify-center font-black text-xs">
                    {i + 1}
                  </div>
                  <div className="text-xs font-bold text-slate-800 dark:text-slate-200">{doc.label}</div>
                  <div className="text-[9px] text-slate-400">{doc.desc}</div>
                </div>
              ))}
            </div>
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
    </form>
  );
};
