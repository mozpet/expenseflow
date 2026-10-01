# ExpenseFlow — Analisis Kesiapan & Roadmap Fitur KPI (Key Performance Indicator / Penilaian Kinerja)

> **Dokumen Analisis Kesiapan Codebase & Spesifikasi Teknis-Bisnis Modul KPI**
> Dibuat: 2026-10-01
> Referensi: `doc/rules.md` (2692 baris, dibaca penuh), kode backend `expenseflow-backend/`, dan database dev live (snapshot per 2026-10-01).
> Metode: grep menyeluruh seluruh `app/`, `database/`, `routes/`, dan `doc/rules.md` untuk kata kunci `kpi`, `performance_review`, `appraisal`, `scorecard` — **nol hasil** (selain label UI generik "Ringkasan KPI" pada dashboard yang tidak berkaitan dengan fitur penilaian kinerja). Disimpulkan: **fitur KPI/penilaian kinerja belum ada sama sekali**, baik di kode maupun di dokumentasi roadmap manapun.

---

## 🎯 Kesimpulan Singkat

**Codebase ini SUDAH SIAP (secara fondasi) untuk ditambahkan fitur KPI**, dengan beberapa catatan gap yang harus diisi dulu (lihat §1). Alasan utamanya:

1. Struktur organisasi (divisi, posisi/jabatan dengan penanda SPV, atasan langsung via `manager_id`) **sudah ada dan terisi data nyata** (bukan sekadar skema kosong).
2. Sistem role/permission **modular** sudah ada — tinggal mendaftarkan 1 modul baru `kpi`.
3. Infrastruktur audit log (`AuditLogger` + tabel `activity_logs`), notifikasi (`notifications` + `FcmService`), dan mesin formula kustom (`Formula/` DSL milik Payroll) **sudah dibangun dan bisa dipakai ulang** untuk KPI tanpa membangun dari nol.
4. Data mentah perilaku kerja (presensi, lembur, cuti, SLA approval struk/invoice) **sudah terekam** dan bisa langsung dipakai sebagai salah satu sumber skor KPI otomatis.

Tidak ditemukan hal yang **memblokir** pembangunan fitur KPI — gap yang ada bersifat "perlu dibangun baru" (tabel, modul, UI), bukan "perlu merombak arsitektur yang sudah ada".

---

## 1. Gap Analysis — Apa yang Masih Kurang

### 1.1. Tidak ada skema/kode KPI sama sekali (blocker fitur, bukan blocker arsitektur)
- Tidak ada tabel `kpi_*`, `performance_reviews`, `appraisals`, atau `scorecards` di `database/migrations/`.
- Tidak ada model, controller, service, atau route terkait KPI.
- Tidak ada konsep **periode/siklus penilaian** (bulanan/kuartalan/tahunan) di sistem manapun — ini perlu dirancang baru.
- **Dampak**: seluruh modul KPI adalah pekerjaan net-new. Ini normal untuk fitur baru, bukan tanda codebase tidak siap.

### 1.2. Modul permission `kpi` belum terdaftar
- `app/Models/Role.php` memiliki konstanta `AVAILABLE_MODULES` yang saat ini berisi persis 13 modul: `receipt`, `expense_report`, `invoice`, `vendor`, `user`, `attendance`, `leave`, `overtime`, `shift`, `payroll`, `audit_log`, `settings`, `role_management`.
- Sistem role bersifat **modul-level** (bukan per-aksi granular): tiap role punya baris `role_permissions` per modul dengan `access_level` enum `none|read|manage` (unique per `[role_id, module]`).
- **Yang harus dilakukan**: tambah key `kpi` ke `AVAILABLE_MODULES`, lalu seed `role_permissions` untuk role bawaan (`admin`/`super_admin` → `manage`, `hrd` → `manage`, karyawan biasa → `read` untuk skor milik sendiri, atasan/SPV → `manage` terbatas ke anak buah — perlu dicek apakah `branch_scope`/`role_branches` yang ada cukup, atau perlu mekanisme "scope ke subordinate" baru).

### 1.3. Beberapa field HRIS yang relevan untuk KPI masih berstatus roadmap (belum diimplementasikan)
`doc/rules.md` Bagian 10 (Analisis Kelengkapan Form Data Karyawan) sendiri sudah mencatat field-field berikut sebagai **belum ada** di `fillable` model `User` (dikonfirmasi ulang di sesi ini — fillable list berhenti di `exit_date`/`exit_reason`/`exit_notes`/`severance_status`/`clearance_status`, tidak ada field di bawah ini):
- `job_level` / `grade` (tingkat jabatan) — **relevan langsung untuk KPI** karena target/bobot KPI biasanya berbeda per level/grade.
- `religion`, `marital_status`, `number_of_dependents`, data BPJS/NPWP — tidak langsung relevan ke KPI, kecuali KPI dikaitkan ke bonus/insentif yang terkena pajak.
- Field pendidikan, kontak darurat, dsb — tidak relevan ke KPI.

**Rekomendasi**: KPI **tidak perlu menunggu** seluruh gap HRIS ini selesai. Hanya `job_level`/`grade` yang berpotensi dibutuhkan jika target KPI ingin dibedakan per jenjang jabatan — dan ini **opsional untuk MVP** (bisa pakai `position_id`/`division_id` dulu sebagai pembeda target, karena keduanya sudah ada dan terisi).

### 1.4. Belum ada mekanisme "siapa menilai siapa" yang terotomasi penuh
- `users.manager_id` (atasan langsung) dan `positions.is_supervisor` sudah ada dan terisi (34 posisi, 7 divisi, 201 user, 2 company — data live, bukan skema kosong). Ini **cukup** sebagai dasar routing penilaian (atasan menilai bawahan langsung).
- Namun belum ada konsep delegasi penilai, penilaian berjenjang (atasan dari atasan), atau penilaian 360° (rekan kerja/bawahan ikut menilai) — ini keputusan desain yang perlu diambil eksplisit (lihat §2.2, scope MVP vs lanjutan).

### 1.5. Belum ada mesin agregasi data perilaku → skor otomatis
- Data mentah sudah ada (presensi, lembur, cuti, SLA approval), tapi belum ada job/service yang mengubahnya menjadi skor KPI. Ini pekerjaan baru, tapi polanya mengikuti pola `ProcessAttendanceBackgroundJob` yang sudah ada.

### Ringkasan Checklist Kesiapan

| Aspek | Status | Keterangan |
|---|:---:|---|
| Struktur organisasi (divisi/posisi/atasan) | ✅ Siap | `divisions`, `positions.is_supervisor`, `users.manager_id` — terisi data nyata |
| Sistem role & permission | 🟡 Perlu 1 langkah | Tambah modul `kpi` ke `AVAILABLE_MODULES` + seed `role_permissions` |
| Audit log mutasi KPI | ✅ Siap pakai ulang | `AuditLogger` + `activity_logs` (kategori baru bisa ditambah, mis. `CATEGORY_KPI`) |
| Notifikasi (reminder, hasil skor) | ✅ Siap pakai ulang | `notifications` + `FcmService`, tinggal tambah `type` baru |
| Mesin skor/formula tertimbang | ✅ Siap pakai ulang | `app/Services/Payroll/Formula/*` (Lexer/Parser/Evaluator/Engine) — dirancang generik, tidak spesifik Payroll |
| Sumber data perilaku otomatis | ✅ Data tersedia | `attendances.work_minutes/overtime_minutes/status`, `leave_requests`, `receipts.submitted_at`+approval timestamps |
| Field `job_level`/`grade` untuk target berjenjang | ⬜ Belum ada | Opsional untuk MVP; bisa pakai `position_id`/`division_id` dulu |
| Skema tabel KPI | ⬜ Belum ada | Net-new, lihat §3 |
| Siklus/periode penilaian | ⬜ Belum ada konsep | Net-new, lihat §2 |
| UI web & mobile | ⬜ Belum ada | Net-new |

**Kesimpulan**: tidak ada gap yang memblokir. Lanjut ke roadmap/PRD di bawah.

---

## 2. Konsep Produk — Apa yang Dinilai oleh KPI

Mengikuti pola dokumentasi `rules.md`/`07-PAYROLL-ROADMAP.md` (bertahap, MVP dulu), KPI dirancang dengan **dua jenis indikator** yang bisa dicampur per jabatan/divisi:

1. **KPI Otomatis (berbasis data sistem)** — dihitung dari data yang sudah terekam, tanpa input manual:
   - Kedisiplinan presensi: % hari `present` vs `late`/`absent` (dari `attendances.status`), total `work_minutes` vs target jam kerja.
   - Kedisiplinan lembur: pola `overtime_minutes` (lembur berlebihan bisa jadi indikator negatif beban kerja/efisiensi, tergantung kebijakan perusahaan).
   - Pola cuti: frekuensi & kepatuhan prosedur (dari `leave_requests`).
   - SLA approval (khusus role approver/SPV/HRD/Finance): rata-rata waktu dari `submitted_at` → `spv_approved_at`/`approved_at` pada `receipts`, `overtime_approvals`, `leave_requests` — mengukur kecepatan atasan memproses approval.

2. **KPI Manual (dinilai atasan)** — skor yang diinput atasan langsung (`manager_id`) atau SPV (`positions.is_supervisor`) di akhir periode, berbasis target/sasaran kerja yang disepakati di awal periode (goal-setting), dengan bobot per indikator.

**Skor akhir** = kombinasi tertimbang dari indikator otomatis + manual, dihitung lewat **Formula DSL** yang sudah ada (reuse dari Payroll), supaya bobot per perusahaan bisa dikustomisasi tanpa ubah kode (konsisten dengan prinsip multi-tenant yang sudah dipegang di seluruh sistem).

### 2.1. Siklus Penilaian
- Periode dikonfigurasi per company (`company_id`): bulanan / kuartalan / tahunan.
- Status siklus: `draft` → `active` (karyawan & atasan bisa isi) → `closed` (terkunci, hanya bisa dilihat) — pola immutable-setelah-closed ini konsisten dengan prinsip `approved`/`paid` immutable di Payroll.

### 2.2. Siapa Menilai Siapa (MVP)
- **MVP**: atasan langsung (`users.manager_id`) menilai bawahan langsungnya. Sederhana, langsung pakai data yang sudah ada, tanpa perlu field baru.
- **Lanjutan (bukan MVP)**: penilaian berjenjang/360° — didokumentasikan sebagai roadmap Fase 3, bukan diblokir, hanya didahulukan yang sederhana dulu (pola yang sama dipakai di Payroll: MVP dulu, enterprise fitur menyusul).

---

## 3. Rancangan Skema Database (Draft Migrasi)

Mengikuti konvensi penamaan & kolom yang sudah konsisten di seluruh codebase (snake_case, `company_id` di setiap tabel, timestamps, FK dengan `restrictOnDelete()`/`cascadeOnDelete()` sesuai sensitivitas data).

```
kpi_cycles                     -- siklus/periode penilaian per company
  id, company_id, name, period_type (enum: monthly|quarterly|yearly),
  start_date, end_date, status (enum: draft|active|closed),
  created_by, timestamps

kpi_templates                  -- set indikator KPI yang bisa dipasangkan ke posisi/divisi
  id, company_id, name, description,
  applies_to_position_id (nullable FK positions), applies_to_division_id (nullable FK divisions),
  is_active, timestamps

kpi_indicators                 -- indikator individual dalam satu template
  id, kpi_template_id (FK), name, description,
  type (enum: automatic|manual), source_metric (nullable string -- mis. 'attendance_punctuality', 'approval_sla'),
  weight (decimal 5,2 -- persentase bobot), target_value (nullable decimal),
  formula_expression (nullable text -- dieksekusi lewat Formula DSL jika type=automatic kompleks),
  timestamps

kpi_assignments                -- penugasan template KPI ke user untuk 1 siklus
  id, company_id, kpi_cycle_id (FK), user_id (FK users), kpi_template_id (FK),
  assigned_by (FK users -- atasan/HRD), status (enum: pending|in_progress|submitted|reviewed|finalized),
  timestamps

kpi_scores                     -- skor per indikator per assignment
  id, kpi_assignment_id (FK), kpi_indicator_id (FK),
  automatic_value (nullable decimal -- hasil hitung otomatis),
  manual_score (nullable decimal -- input atasan, skala 1-5 atau 1-100 sesuai config company),
  manual_notes (nullable text),
  scored_by (nullable FK users), scored_at (nullable timestamp),
  timestamps

kpi_final_results              -- hasil akhir terkalkulasi per assignment (cache, immutable setelah finalized)
  id, kpi_assignment_id (FK, unique),
  final_score (decimal), grade (nullable string -- mis. 'A'/'B'/'C' sesuai mapping company),
  calculated_at, finalized_at (nullable), finalized_by (nullable FK users),
  timestamps
```

**Catatan desain** (konsisten dengan pola Payroll yang sudah terbukti):
- `kpi_final_results` yang sudah `finalized_at` **immutable** (409/422 jika ada percobaan ubah) — sama seperti `payroll_runs` yang `approved`/`paid`.
- Semua mutasi (buat cycle, assign template, submit skor, finalize) **wajib** log ke `activity_logs` lewat `AuditLogger` dengan kategori baru `CATEGORY_KPI = 'KPI_PERFORMANCE'`.
- `kpi_indicators.formula_expression` dieksekusi lewat `FormulaEvaluator` yang sudah ada di `app/Services/Payroll/Formula/` — **tidak** menulis ulang parser baru. Pertimbangkan memindahkan/alias namespace dari `Payroll\Formula` ke lokasi netral (mis. `app/Services/Formula/`) agar tidak membingungkan secara penamaan, tanpa mengubah logic.

---

## 4. Modul Permission

Tambahkan ke `app/Models/Role.php`:

```php
const AVAILABLE_MODULES = [
    // ...existing 13 modules...
    'kpi', // baru
];
```

Lalu seed `role_permissions`:
- `super_admin`/`admin` → `manage` (penuh, semua company dalam scope-nya).
- `hrd` (jika ada role ini) → `manage` (kelola cycle, template, lihat semua hasil).
- Role dengan `positions.is_supervisor = true` → `manage` **terbatas ke bawahan langsung** (perlu logic tambahan di controller: filter by `manager_id = auth()->id()`, bukan lewat `role_branches` yang scope-nya branch, bukan subordinate — ini gap kecil yang perlu ditangani di level query, bukan di level skema permission).
- Karyawan biasa → `read` (hanya skor milik sendiri).

---

## 5. Rancangan Endpoint API

Mengikuti konvensi route yang sudah ada (`/api/v1/dashboard/...` untuk web/HRD, `/api/v1/employee/...` untuk mobile karyawan):

**Dashboard (HRD/Admin/Atasan):**
- `GET/POST /api/v1/dashboard/kpi/cycles`
- `GET/POST /api/v1/dashboard/kpi/templates`
- `POST /api/v1/dashboard/kpi/cycles/{id}/assign` — assign template ke user(s)
- `GET /api/v1/dashboard/kpi/assignments?cycle_id=&user_id=&division_id=`
- `POST /api/v1/dashboard/kpi/assignments/{id}/scores` — atasan input skor manual
- `POST /api/v1/dashboard/kpi/assignments/{id}/finalize`
- `GET /api/v1/dashboard/kpi/assignments/{id}/automatic-preview` — preview hasil hitung otomatis sebelum finalize

**Mobile/Employee:**
- `GET /api/v1/employee/kpi/my-assignments?cycle_id=`
- `GET /api/v1/employee/kpi/my-assignments/{id}` — detail skor diri sendiri (read-only)

**Notifikasi** (reuse `FcmService`, tambah `type` baru seperti `kpi_cycle_opened`, `kpi_score_finalized`, `kpi_reminder_pending`).

---

## 6. Roadmap Bertahap (P0–P3)

| Prioritas | Fitur | Keterangan |
|:---:|---|---|
| **P0** | Skema DB inti (`kpi_cycles`, `kpi_templates`, `kpi_indicators`, `kpi_assignments`, `kpi_scores`, `kpi_final_results`) + modul permission `kpi` | Fondasi wajib sebelum apapun |
| **P0** | Flow MVP: atasan langsung (`manager_id`) buat/isi skor manual bawahan, 1 siklus aktif per company, finalize dasar | End-to-end paling sederhana dulu, tanpa KPI otomatis |
| **P1** | Indikator otomatis dari data presensi (`attendances.status`, `work_minutes`) & SLA approval (`submitted_at`→`approved_at`) | Mulai manfaatkan data yang sudah terekam |
| **P1** | Integrasi Formula DSL untuk bobot tertimbang & kalkulasi skor akhir | Reuse mesin Payroll |
| **P1** | UI Web (tab baru di dashboard, mirip pola `PayrollManagement.tsx`) | CRUD cycle/template, review & finalize skor |
| **P1** | UI Mobile (menu KPI di Profil, mirip pola menu Slip Gaji) | Lihat skor sendiri, isi self-assessment jika didesain demikian |
| **P2** | Notifikasi FCM (reminder isi skor, pengumuman hasil) | Reuse `FcmService` |
| **P2** | Audit log kategori `CATEGORY_KPI` di semua mutasi | Reuse `AuditLogger` |
| **P2** | Grade/rank mapping (A/B/C/D) & distribusi (bell curve opsional) | Fitur pelengkap pelaporan |
| **P3** | Field `job_level`/`grade` di `User` untuk target berjenjang per level | Opsional, hanya jika dibutuhkan diferensiasi lebih halus dari `position_id` |
| **P3** | Penilaian berjenjang/360° (rekan kerja, bawahan ikut menilai) | Enterprise lanjutan, bukan MVP |
| **P3** | Kaitan KPI → bonus/insentif payroll (lewat Formula DSL yang sama) | Butuh keputusan bisnis eksplisit dulu; berpotensi menyentuh `payroll` module & tax jika bonus kena pajak |

---

## 7. Aturan Wajib untuk Implementasi (konsisten dengan konvensi proyek)

- Bahasa Indonesia untuk kode/UI/komentar, sesuai seluruh codebase.
- Semua mutasi KPI **wajib** tercatat di `activity_logs` lewat `AuditLogger` (kategori baru `CATEGORY_KPI`).
- Kolom skor/desimal mengikuti pola `decimal:2` → di-serialize sebagai **string** di JSON (parse defensif di frontend), sama seperti kolom uang di Payroll.
- `kpi_final_results` yang sudah `finalized_at` bersifat **immutable** — percobaan ubah → 422.
- Scoping multi-tenant (`company_id`) wajib di setiap query, mengikuti `CompanyMiddleware` yang sudah ada (`super_admin` bypass).
- Tidak pakai `eval()`/raw SQL di mesin formula — reuse `FormulaEvaluator` yang sudah whitelisted.
- Rate limiting: endpoint tulis (assign, submit score, finalize) masuk **Tier 2 (Actions, 45/menit)**; endpoint baca masuk **Tier 1 (General, 120/menit)**, konsisten dengan sistem tiered rate limiting yang sudah ada.
