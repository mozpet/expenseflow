# Arsitektur & Spesifikasi: Custom Role, Branch Scoping, & Multi-Level Approval

Dokumen ini adalah referensi teknis lengkap untuk pengembangan fitur **Custom Role Dinamis**, **Pembatasan Akses Berbasis Cabang (Branch Scoping)**, dan **Alur Persetujuan Bertingkat (Multi-Level Approval)** pada aplikasi ExpenseFlow.

---

## 1. Ringkasan & Keputusan Desain Utama

1. **1 Akun = 1 Role:**
   - Setiap karyawan memiliki tepat 1 role (disimpan pada relasi `users.role_id`). Kolom string `users.role` tetap disinkronkan dengan `slug` role untuk menjaga kompatibilitas backward dengan middleware yang sudah ada.
2. **Platform Akses:**
   - **`both` (Mobile & Web Dashboard - Dual Akses)**: Untuk user berwenang atau pengelola (Superadmin, Admin, HRD, Finance, SPV Cabang, Manager). Wajib memiliki akses mobile untuk presensi online/absensi karyawan, dan dashboard web untuk mengelola modul sesuai matriks perizinan.
   - **`mobile_only` (Khusus Mobile Saja - Staff Pelaksana)**: Untuk karyawan pelaksana/lapangan biasa. Murni presensi GPS/Face, izin cuti, lembur, dan scan struk di aplikasi mobile. Akses login ke dashboard web otomatis diblokir (HTTP 403).
   > *Catatan: Opsi `web_only` sengaja ditiadakan karena seluruh entitas user/karyawan di ExpenseFlow wajib memegang akun mobile untuk keperluan presensi online dan kehadiran kerja.*
3. **Cakupan Cabang (Branch Scoping):**
   - **`all` (Semua Cabang)**: Bebas akses seluruh cabang perusahaan (Head Office, Direksi, Super Admin).
   - **`specific` (Cabang Tertentu)**: Akses dibatasi pada cabang yang dicentang di tabel relasi `role_branches`.
   - **`self` (Sesuai Kantor Penempatan)**: Otomatis mengikuti `user.attendance_setting_id` milik user yang bersangkutan.
4. **Matriks Hak Akses Modul (Granular Checkbox):**
   - Tingkat akses per modul:
     - `none`: Modul disembunyikan di menu web & endpoint API diblokir (403).
     - `read`: Hanya bisa melihat daftar, detail modal, dan ekspor. Tombol mutasi/aksi di-disable.
     - `manage`: Akses penuh untuk membuat, mengedit, menghapus, atau memproses data.
5. **Multi-Level Approval Struk Reimbursement:**
   - Dikelola di Pengaturan Finance (`company_settings` / `settings`).
   - Berjenjang berdasarkan rentang nominal klaim (Tiering):
     - Tier 1 (< 500rb): 1 orang Finance.
     - Tier 2 (500rb - 1jt): 2 orang Finance (Finance 1 lalu Finance 2).
     - Tier 3 (> 1jt): 2 tahap (Finance Staff ➔ SPV Finance / Finance Head).
6. **Multi-Level Approval Lembur (Overtime):**
   - Alur 2 tahap:
     - Tahap 1: Persetujuan SPV / Atasan langsung (`pending_spv`).
     - Tahap 2: Persetujuan HRD (`pending_hrd` ➔ `approved`).
     - Penolakan di tahap manapun langsung menggugurkan lembur (`rejected`).
7. **Pemisahan Peran: Role (Hak Akses Sistem) vs Jabatan / Posisi (Struktur Organisasi):**
   - **Role (`roles`)**: Mengatur wewenang teknis di dalam aplikasi (modul mana yang boleh di-read/manage, platform `both` vs `mobile_only`, dan cabang mana yang diizinkan).
   - **Jabatan (`positions`)**: Mengatur nama jabatan kerja struktural dan status kepemimpinan via flag `is_supervisor` (apakah merupakan Supervisor / Atasan).
   - **Divisi (`divisions`)**: Mengatur departemen atau unit kerja karyawan.
   - **Atasan Langsung (`users.manager_id`)**: Menentukan relasi pelaporan langsung per individu.
   - *Prinsip*: Perusahaan tidak perlu membuat custom role baru hanya untuk membedakan 'Staf' vs 'Supervisor' jika hak akses modulnya sama. Cukup gunakan Master Jabatan dengan flag `is_supervisor` aktif.

---

## 2. Struktur Database (Schema)

### 2.1. Tabel `roles`
Menyimpan daftar role (baik role bawaan sistem maupun custom role buatan Superadmin per perusahaan).

| Kolom | Tipe Data | Keterangan |
| :--- | :--- | :--- |
| `id` | BIGINT UNSIGNED (PK) | Primary key |
| `company_id` | BIGINT UNSIGNED, Nullable | FK ke `companies.id`. `NULL` untuk built-in role default. |
| `name` | VARCHAR(100) | Nama tampilan (misal: "SPV Finance", "Staff Lapangan") |
| `slug` | VARCHAR(100) | Identifier unik (misal: "spv_finance", "employee") |
| `description` | TEXT, Nullable | Keterangan fungsi role |
| `platform` | ENUM('mobile_only', 'web_only', 'both') | Default: `both` |
| `branch_scope` | ENUM('all', 'specific', 'self') | Default: `all` |
| `is_builtin` | BOOLEAN | Default `false`. `true` untuk 5 role bawaan (tidak bisa dihapus) |
| `is_active` | BOOLEAN | Default `true` |
| `timestamps` | TIMESTAMP | `created_at`, `updated_at` |

- **Index/Unique:** `UNIQUE(company_id, slug)`

### 2.2. Tabel `role_permissions`
Menyimpan matriks hak akses per modul untuk tiap role.

| Kolom | Tipe Data | Keterangan |
| :--- | :--- | :--- |
| `id` | BIGINT UNSIGNED (PK) | Primary key |
| `role_id` | BIGINT UNSIGNED | FK ke `roles.id` ON DELETE CASCADE |
| `module` | VARCHAR(50) | Nama modul (lihat daftar modul di bawah) |
| `access_level` | ENUM('none', 'read', 'manage') | Tingkat hak akses |
| `timestamps` | TIMESTAMP | `created_at`, `updated_at` |

- **Index/Unique:** `UNIQUE(role_id, module)`

**Daftar Modul Standar:**
- `receipt` (Struk Reimbursement)
- `expense_report` (Laporan Dinas / Bundling Struk)
- `invoice` (Invoice Vendor)
- `vendor` (Master Vendor)
- `user` (Karyawan & Profil)
- `attendance` (Presensi & Rekap Kehadiran)
- `leave` (Pengajuan Cuti & Saldo)
- `overtime` (Persetujuan Lembur)
- `shift` (Shift & Penjadwalan Roster)
- `audit_log` (Log Aktivitas & Keamanan)
- `settings` (Pengaturan Sistem, Cabang & Finance)
- `role_management` (Manajemen Role & Hak Akses — proteksi perubahan wewenang)

### 2.3. Tabel `role_branches`
Menyimpan cabang yang diizinkan jika `branch_scope = 'specific'`.

| Kolom | Tipe Data | Keterangan |
| :--- | :--- | :--- |
| `id` | BIGINT UNSIGNED (PK) | Primary key |
| `role_id` | BIGINT UNSIGNED | FK ke `roles.id` ON DELETE CASCADE |
| `attendance_setting_id` | BIGINT UNSIGNED | FK ke `attendance_settings.id` ON DELETE CASCADE |
| `timestamps` | TIMESTAMP | `created_at`, `updated_at` |

- **Index/Unique:** `UNIQUE(role_id, attendance_setting_id)`

### 2.4. Pembaruan Tabel `users`
- Penambahan kolom: `role_id` (BIGINT UNSIGNED, Nullable, FK ke `roles.id` ON DELETE SET NULL).
- Kolom string `role` tetap ada untuk sinkronisasi nilai `slug`.

### 2.5. Pembaruan Tabel `receipts` & `receipt_approvals` (Fase 2)
Kolom alur persetujuan bertingkat struk reimbursement:

| Tabel | Kolom | Tipe Data | Keterangan |
| :--- | :--- | :--- | :--- |
| `receipts` | `approval_tier` | VARCHAR(50), Nullable | Nama/ID aturan tier saat submit (misal: "Tier 1 (< Rp 500.000)") |
| `receipts` | `required_approvals` | INT UNSIGNED DEFAULT 1 | Jumlah persetujuan yang dibutuhkan untuk status approved |
| `receipts` | `current_approvals` | INT UNSIGNED DEFAULT 0 | Jumlah persetujuan yang telah didapatkan saat ini |
| `receipt_approvals` | `approval_level` | INT UNSIGNED DEFAULT 1 | Urutan tingkat persetujuan (Level 1, Level 2) |

### 2.6. Pembaruan Tabel `overtime_approvals` (Fase 2)
Kolom persetujuan lembur bertingkat 2 tahap (SPV ➔ HRD):

| Kolom | Tipe Data | Keterangan |
| :--- | :--- | :--- |
| `current_step` | ENUM('spv', 'hrd') DEFAULT 'spv' | Tahapan persetujuan aktif (`spv` = Tahap 1 SPV, `hrd` = Tahap 2 HRD). *(Sesuai skema migrasi aktual `current_step`, bukan `current_level`)* |
| `spv_id` | BIGINT UNSIGNED, Nullable | FK ke `users.id` (Approver Tahap 1: Supervisor / Atasan Langsung) |
| `spv_approved_at` | TIMESTAMP, Nullable | Waktu persetujuan SPV Tahap 1 |
| `spv_notes` | TEXT, Nullable | Catatan atau rekomendasi persetujuan dari SPV |
| `reviewed_by` | BIGINT UNSIGNED, Nullable | FK ke `users.id` (Approver Tahap 2: HRD / Admin) |
| `reviewed_at` | TIMESTAMP, Nullable | Waktu persetujuan final HRD |

---

## 3. Tahapan Pengerjaan (Phasing Roadmap)

### 🔹 FASE 1: Backend & Database Foundation (Custom Role & Permission Engine)
- Migration: `roles`, `role_permissions`, `role_branches`, dan penambahan `role_id` di `users`.
- Model: `Role`, `RolePermission`, `RoleBranch` beserta relasi dan method helper (`hasPermission`, `allowsBranch`).
- Seeder: Inisialisasi 5 Built-in Roles (`employee`, `finance`, `hrd`, `admin`, `super_admin`) & sinkronisasi data `users.role_id` yang sudah ada.
- API Endpoints Role Management:
  - `GET /api/v1/admin/roles` — List semua role (built-in + custom).
  - `POST /api/v1/admin/roles` — Buat custom role baru (dengan platform, branch scope, dan permissions).
  - `GET /api/v1/admin/roles/{id}` — Detail role beserta permissions & cabang.
  - `PUT /api/v1/admin/roles/{id}` — Update custom role.
  - `DELETE /api/v1/admin/roles/{id}` — Hapus custom role (validasi tidak ada karyawan yang sedang menggunakan).
  - `GET /api/v1/admin/roles/modules` — Metadata daftar modul & pilihan level akses untuk form UI.
- Middleware & Helper:
  - `CheckPermission` middleware (`permission:module,level`).
  - Helper trait `BranchScopeFilter` untuk query controller.
- Pengujian: Automated Feature Test untuk verifikasi isolasi role, hak akses, dan branch scope.

### 🔹 FASE 2: Multi-Approval Workflow (Struk & Lembur) — [STATUS: SELESAI]
- Logika Tiering Struk di `Receipt::resolveApprovalTier`:
  - Tier 1 (< Rp 500.000): 1 Finance / Admin
  - Tier 2 (Rp 500.000 - Rp 1.000.000): 2 Finance / Admin (Anti-double approval oleh orang yang sama)
  - Tier 3 (> Rp 1.000.000): 2 Tahap (Tahap 1 Tim Finance, Tahap 2 SPV Finance / Finance Manager / Head)
- Modifikasi `ReceiptController`:
  - `submit`: penetapan `approval_tier`, `required_approvals`, inisialisasi `current_approvals = 0`.
  - `approve`: validasi hak cabang, anti-double approval, validasi SPV di Tier 3 tahap 2, transisi status `partially_approved` vs `approved` final, pencatatan log aktivitas dan notifikasi bertahap.
  - `reject`: penolakan struk dari status `submitted` maupun `partially_approved` dengan verifikasi cabang.
  - `inbox`: query menyertakan `submitted` dan `partially_approved`, scoping cabang, atribut `already_approved_by_me`.
  - `dashboardReceipts`: filter status `partially_approved` dan counter ringkasan.
- Modifikasi `AttendanceController` & `OvertimeApproval`:
  - Alur 2-Step Lembur (Tahap 1 SPV ➔ Tahap 2 HRD).
  - Inisialisasi pengajuan lembur dengan `current_step = 'spv'`.
  - `approveOvertime`: Tahap 1 SPV approve ➔ status tetap pending, `current_step = 'hrd'`, notifikasi ke HRD; Tahap 2 HRD approve ➔ status `approved` dan menit lembur terkunci untuk payroll. Validasi karyawan tidak bisa approve diri sendiri.
  - `rejectOvertime`: penolakan pada tahap SPV atau HRD langsung menggugurkan lembur (`rejected`) dan me-reset `attendances.overtime_minutes = 0`.
  - `listOvertimeApprovals`: filter `?step=spv|hrd`, relasi nama SPV, counter `pending_spv` dan `pending_hrd`.
  - `myOvertimeApprovals`: informasi step lembur dan catatan review SPV untuk mobile.
- Verifikasi: Automated Feature Tests `MultiApprovalWorkflowTest` lulus 100% (11 test, 88 assertions) tanpa regresi pada test existing.

### 🔹 FASE 3: Frontend Web Dashboard UI — [STATUS: SELESAI]
- Tab Mandiri di Halaman Pengaturan Utama ([SettingsManagement.tsx](file:///e:/koding/coba/backend-gawe/expenseflow-web/src/components/SettingsManagement.tsx)):
  - Tab **Manajemen Role & Hak Akses** dipisahkan di luar modal edit kantor sebagai tab teratas bersama "Kantor Presensi" dan "Aturan Klaim & Invoice".
- Komponen Daftar & Manajemen Role ([RoleManagementTab.tsx](file:///e:/koding/coba/backend-gawe/expenseflow-web/src/components/RoleManagementTab.tsx)):
  - Summary cards (Total Role, Dual Akses, Mobile Saja, Web Saja).
  - Search live & platform filter tabs.
  - Kartu role interaktif dengan badge platform, cakupan cabang, ringkasan perizinan modul, jumlah user aktif, serta proteksi penghapusan role bawaan.
- Modal Pembuatan & Edit Role Bertahap ([RoleFormModal.tsx](file:///e:/koding/coba/backend-gawe/expenseflow-web/src/components/RoleFormModal.tsx)):
  - Tab 1: Info & Platform (Radio card: `both`, `mobile_only`, `web_only`).
  - Tab 2: Cakupan Cabang (`all`, `self`, `specific` dengan checklist dinamis kantor cabang aktif).
  - Tab 3: Matriks Hak Akses Modul (11 modul operasional dengan pilihan tri-state `none`, `read`, `manage`, plus aksi massal `Semua Read`, `Semua Manage`, `Reset Semua`).
- Integrasi Form Karyawan ([EmployeeMultiTabForm.tsx](file:///e:/koding/coba/backend-gawe/expenseflow-web/src/components/EmployeeMultiTabForm.tsx)):
  - Dropdown role terhubung dinamis dengan API role `/api/v1/admin/roles`.
- Peningkatan UI Multi-Level Overtime Approval ([OvertimeApprovalView.tsx](file:///e:/koding/coba/backend-gawe/expenseflow-web/src/components/OvertimeApprovalView.tsx)):
  - 4 Summary stat cards (Menunggu SPV Tahap 1, Menunggu HRD Tahap 2, Disetujui, Ditolak).
  - 5 Tab filter status & step approval (`spv` vs `hrd`).
  - Badge baris tabel bertahap `Tahap 1: SPV` (Amber) vs `Tahap 2: HRD Final` (Blue) dengan tanda centang SPV.
  - Modal aksi berkonteks sesuai tahapan SPV vs HRD (dengan detail persetujuan dan catatan rekomendasi SPV).
- Peningkatan UI Multi-Tier Receipt Approval ([ReceiptInbox.tsx](file:///e:/koding/coba/backend-gawe/expenseflow-web/src/components/ReceiptInbox.tsx)):
  - Badge `Approval {current}/{required}` untuk status `partially_approved`.
  - Proteksi anti-double approval: tombol approval digantikan dengan indikator `Sudah ACC` jika user yang login telah menyetujui sebelumnya.
  - Timeline riwayat approval berjenjang di dalam modal detail struk.
- Verifikasi:
  - Kompilasi TypeScript & Vite build 100% lulus (`npm run build`).
  - Verifikasi browser visual end-to-end via browser automation.

---

## 4. Keamanan & Proteksi
1. `super_admin` **selalu bypass** seluruh pengecekan izin dan cabang.
2. Built-in role (`is_builtin = true`) tidak dapat dihapus atau diubah slug-nya.
3. Custom role hanya dapat dihapus jika `assigned_users_count == 0` (mencegah user tanpa role).
4. User dengan platform `mobile_only` langsung ditolak saat login ke web (status 403).

---

## 5. Temuan Masalah, Bug, Celah Keamanan & Rencana Perbaikan (Audit Codebase)

Berdasarkan audit menyeluruh antara arsitektur dokumen ini dengan implementasi codebase riil (`expenseflow-backend` dan `expenseflow-web`), ditemukan 10 poin temuan teknis yang perlu diperbaiki:

### 🚨 5.1. Celah Keamanan & Bypass Hak Akses (Critical / High)

1. **[SELESAI] Bypass Login Web Dashboard untuk Custom Role `mobile_only`:**
   - **Lokasi:** `app/Http/Controllers/API/AuthController.php` (`login()`)
   - **Deskripsi:** Pengecekan blokir web hanya memeriksa `$role === 'employee'`. Karyawan dengan Custom Role ber-platform `mobile_only` (misal: *Staff Lapangan*, *Kurir*) memiliki `$user->role = 'staff_lapangan'` sehingga lolos login ke dashboard web dan mendapatkan token Sanctum web 24 jam.
   - **Solusi:** Periksa apakah platform role akun tersebut adalah `mobile_only` (`$user->roleRelation?->platform === 'mobile_only'` atau `$user->role === 'employee'`).
   - **Status:** ✅ **SELESAI**. Telah diperbaiki di `AuthController.php` dan diverifikasi dengan `AuthPlatformGuardTest`.

2. **[SELESAI] Bypass Device Binding (Hardware Lock Anti-Titip Absen) pada Custom Role:**
   - **Lokasi:** `app/Http/Controllers/API/AuthController.php` (`login()`)
   - **Deskripsi:** Pengecekan hardware device binding di-*hardcode* dengan `in_array($role, ['employee', 'hrd', 'finance', 'admin', 'super_admin'])`. Akun dengan custom role tidak akan pernah diikat ID handphonenya (bebas gonta-ganti HP tanpa izin HRD).
   - **Solusi:** Terapkan device binding untuk seluruh user di platform mobile tanpa membatasi role string tertentu.
   - **Status:** ✅ **SELESAI**. Telah ditegakkan untuk seluruh user pada platform mobile di `AuthController.php`.

3. **[SELESAI] Kerentanan Slug Collision & Privilege Escalation pada Pembuatan Role:**
   - **Lokasi:** `app/Http/Controllers/API/RoleController.php` (`store()`)
   - **Deskripsi:** Pengecekan keunikan slug di `store()` hanya memfilter `where('company_id', $companyId)`. Karena 5 built-in roles tersimpan dengan `company_id = NULL`, admin perusahaan bisa membuat custom role bernama `"Admin"`, menghasilkan slug `admin` untuk company tersebut. Akibatnya, user custom role tersebut mendapatkan akses penuh ke seluruh middleware yang mengecek string `'admin'`.
   - **Solusi:** Daftarkan reserved slugs (`admin`, `super_admin`, `hrd`, `finance`, `employee`) dan pastikan query keunikan slug mengecek built-in roles (`whereNull('company_id')->orWhere('company_id', $companyId)`).
   - **Status:** ✅ **SELESAI**. Konstanta `RESERVED_SLUGS` diterapkan di `RoleController.php`, slug otomatis diberi suffix `_custom`, dan diverifikasi dengan `RoleSlugCollisionTest`.

4. **Celah Penegakan Akses Platform `web_only` pada Login Mobile:**
   - **Lokasi:** `app/Http/Controllers/API/AuthController.php` (`login()`), `RoleFormModal.tsx`
   - **Deskripsi:** Dokumen Bagian 1.2 mencatat bahwa `web_only` ditiadakan. Namun, skema database dan form UI `RoleFormModal.tsx` tetap menyediakan pilihan radio `web_only`. Di backend `AuthController.php`, tidak ada penolakan login mobile untuk role ber-platform `web_only`. Jika admin membuat role dengan opsi `web_only`, akun tersebut masih bisa login ke aplikasi mobile.
   - **Solusi:** Tambahkan pengecekan blokir login mobile di `AuthController::login`: jika `$platform === 'mobile'` dan `$roleModel->platform === 'web_only'`, tolak dengan HTTP 403 `Role Anda hanya diizinkan mengakses Web Dashboard.` (atau alternatifnya hapus opsi `web_only` dari modal UI jika seluruh karyawan wajib absensi mobile).
   - **Status:** ⏳ **PERLU DIPERBAIKI**.

### ⚙️ 5.2. Bug Logika Bisnis & Persetujuan Multi-Approval (Medium / High)

5. **[SELESAI] Salah Panggil Atribut `code` vs `slug` pada Overtime Approval:**
   - **Lokasi:** `app/Http/Controllers/API/AttendanceController.php` (`approveOvertime()`)
   - **Deskripsi:** Terdapat baris `$actor->roleRelation?->code` padahal tabel dan model `Role` hanya memiliki kolom `slug`.
   - **Solusi:** Ganti atribut `$actor->roleRelation?->code` menjadi `$actor->roleRelation?->slug`.
   - **Status:** ✅ **SELESAI**. Diganti menjadi `$actor->roleRelation?->slug` dan diverifikasi di `MultiApprovalWorkflowTest`.

6. **[SELESAI] Deteksi Otorisator Tier 3 Struk Terlalu Kaku Berdasarkan Keyword Bahasa Inggris:**
   - **Lokasi:** `app/Http/Controllers/API/ReceiptController.php` (`approve()`)
   - **Deskripsi:** Pengecekan approver tahap 2 untuk struk di atas Rp 1.000.000 hanya mencocokkan substring (`spv`, `head`, `manager`, `supervisor`). Nama jabatan dalam bahasa Indonesia seperti *"Kepala Keuangan"*, *"Kabag Finance"*, *"Otorisator"*, atau *"Direktur"* ditolak dengan pesan error `SPV_FINANCE_REQUIRED` (422).
   - **Solusi:** Tambahkan kata kunci bahasa Indonesia (`kepala`, `kabag`, `ketua`, `lead`, `otorisator`, `direktur`, `vp`) dan utamakan evaluasi permission `MODULE_RECEIPT, manage`.
   - **Status:** ✅ **SELESAI**. Ditambahkan kata kunci bahasa Indonesia (`kepala`, `kabag`, `ketua`, `lead`, `otorisator`, `direktur`, `vp`, `koordinator`) secara simetris pada slug dan nama role di `ReceiptController.php` dan diverifikasi di `MultiApprovalWorkflowTest`.

7. **[SELESAI] Tabrakan Heuristik String Path Matching di `RoleMiddleware`:**
   - **Lokasi:** `app/Http/Middleware/RoleMiddleware.php` (`checkCustomRolePermission()`)
   - **Deskripsi:** Route `/api/v1/dashboard/attendance/users` cocok dengan `str_contains($path, 'users')` sebelum `str_contains($path, 'attendance')`, sehingga menuntut hak akses `MODULE_USER` bukan `MODULE_ATTENDANCE`.
   - **Solusi:** Urutkan prioritas route yang lebih spesifik atau gunakan pemetaan route name/action yang presisi.
   - **Status:** ✅ **SELESAI**. Diterapkan pemetaan presisi berbasis Controller & Action Method (`evaluateControllerPermission`) serta urutan heuristik berjenjang spesifik-ke-umum (`evaluatePathPermission`), diverifikasi dengan `RoleMiddlewarePathCollisionTest` (6 test, 15 assertions lulus 100%).

8. **[SELESAI] Inkonsistensi Otorisasi SPV pada Multi-Tier Receipt vs Master Jabatan/Supervisor:**
   - **Lokasi:** `app/Http/Controllers/API/ReceiptController.php` (`approve()`, `bulkApprove()`)
   - **Deskripsi:** Evaluasi approver tahap 2 untuk struk Tier 3 (> Rp 1.000.000) sebelumnya hanya memeriksa string slug dan nama role (`str_contains($userRoleCode, 'spv')`). Hal ini tidak terintegrasi dengan Master Jabatan (`positions.is_supervisor`) maupun helper `$user->isSupervisor()`. Jika sebuah perusahaan membuat Custom Role bernama `"Finance Team"` (slug `finance_team_custom`), lalu seorang karyawan ditugaskan dengan Jabatan `"Supervisor Keuangan"` (`position.is_supervisor = true`), sistem akan menolak persetujuan dengan error `SPV_FINANCE_REQUIRED` (422) hanya karena nama role-nya tidak mengandung kata 'spv'.
   - **Solusi & Implementasi:**
     1. Harmonisasi logika otorisasi agar menyertakan evaluasi jabatan struktural:
        `$isSpvOrAbove = ($user->position && $user->position->is_supervisor) || $user->isSupervisor() || in_array($userRoleCode, ['super_admin', 'admin']) || ...;`
     2. Menjaga pemisahan tegas peran: hak akses aplikasi atas modul struk (`MODULE_RECEIPT`) dikelola oleh Role, sedangkan status kepemimpinan / wewenang otorisasi struktural dikelola oleh Master Jabatan (`is_supervisor`) atau Atasan Langsung (`manager_id`).
     3. Merapikan pengecekan level `spv` di `Role.php` dan `User.php` agar tidak menyebabkan *privilege creep* (staff finance biasa dengan wewenang `manage` tidak lagi otomatis disalahartikan sebagai level `spv`).
   - **Status:** ✅ **SELESAI**. Diimplementasikan di `ReceiptController.php`, `Role.php`, dan `User.php`, serta diverifikasi dengan automated test suite `MultiApprovalWorkflowTest` (14 test, 108 assertions lulus 100%).

9. **[SELESAI] Bypass Multi-Tier Approval, Otorisasi SPV, dan Branch Scope pada `ReceiptController::bulkApprove`:**
   - **Lokasi:** `app/Http/Controllers/API/ReceiptController.php` (`bulkApprove()`)
   - **Deskripsi:**
     1. `bulkApprove()` sebelumnya langsung mengubah status seluruh struk terpilih menjadi `'approved'` secara masal tanpa memeriksa kolom `required_approvals` atau tier nominal struk. Akibatnya, struk bernilai puluhan juta rupiah (Tier 3) yang wajib disetujui 2 orang termasuk SPV Finance dapat disetujui secara sepihak dalam 1 klik oleh staf finance biasa.
     2. Tidak ada validasi `allowsBranch()` terhadap struk yang dipilih, sehingga user yang dibatasi pada cabang tertentu dapat menyetujui struk dari cabang lain jika ID-nya dikirimkan dalam payload masal.
     3. Terdapat bug PHP *Undefined Variable* `$limit` pada string respons respons error.
   - **Solusi & Implementasi:**
     1. Di dalam loop `bulkApprove()`, diterapkan filter ketat: lewati struk yang cabangnya tidak diizinkan (`!$user->allowsBranch`), lewati struk yang sudah disetujui oleh user bersangkutan (anti-double approval), dan lewati struk Tier 3 tahap 2 jika approver bukan SPV/Manager.
     2. Transisi status bertahap yang konsisten: jika `required_approvals > 1` dan `current_approvals == 0`, status berubah menjadi `partially_approved` dengan `current_approvals = 1` (bukan langsung `approved`).
     3. Perbaiki variabel `$limit` menjadi `$defaultCompanyLimit`.
   - **Status:** ✅ **SELESAI**. Diimplementasikan di `ReceiptController.php` dan diverifikasi dengan automated test `test_receipt_bulk_approve_enforces_branch_scope_anti_double_approval_and_staged_transition` pada `MultiApprovalWorkflowTest`.

### 📦 5.3. Integritas Data & Tenant Isolation (Data Integrity)

10. **[SELESAI] State Inkonstan `users.role` saat Custom Role Dihapus:**
    - **Lokasi:** `app/Http/Controllers/API/RoleController.php` (`destroy()`), `app/Models/Role.php` (`booted`), dan `app/Models/User.php` (`booted`).
    - **Deskripsi:** Saat role dihapus dari database, `users.role_id` menjadi `NULL`, namun `users.role` tetap menyimpan string slug lama, menyebabkan user masuk ke *zombie state* (semua request API ditolak 403).
    - **Solusi:**
      1. Terapkan proteksi ganda pada `RoleController::destroy()`: memvalidasi penolakan 422 jika masih terdapat user yang terasosiasi baik via `role_id` maupun string `users.role`.
      2. Di dalam transaksi penghapusan `destroy()` dan hook event `Role::deleting`, lakukan sinkronisasi pembersihan otomatis: setiap user yang terasosiasi dialihkan secara aman ke role bawaan `employee` (`role_id = $employeeRoleId`, `role = 'employee'`).
      3. Terapkan event hook `User::saving` untuk memastikan sinkronisasi dua arah antara `role_id` dan slug `role` secara deterministik serta fallback otomatis ke `employee` jika `role_id` bernilai `null`.
    - **Status:** ✅ **SELESAI**. Telah diimplementasikan dan diverifikasi dengan automated test suite `RoleDeletionConsistencyTest` (6 test, 26 assertions lulus 100%) serta regression suite (59 test, 259 assertions lulus 100%).

11. **Super Admin Tenant Isolation di `RoleController::index`:**
    - **Lokasi:** `app/Http/Controllers/API/RoleController.php` (`index()`, `store()`, `show()`, `update()`, `destroy()`)
    - **Deskripsi:** Super Admin (`company_id = NULL`) sebelumnya hanya menerima built-in roles karena filter `whereNull('company_id')` tanpa memperhatikan kebutuhan inspeksi atau manajemen custom roles antar tenant perusahaan.
    - **Solusi & Implementasi:**
      1. Pada `RoleController::index()`:
         - Jika pemanggil adalah **Super Admin**:
           - Dapat menyertakan parameter `?company_id=X` untuk memfilter built-in roles + custom roles milik perusahaan X, sekaligus menghitung `users_count` khusus perusahaan tersebut.
           - Jika parameter `?company_id` tidak disertakan, mengembalikan 5 built-in roles + seluruh custom roles dari semua tenant perusahaan dengan relasi `company:id,name` di-eager load.
         - Jika pemanggil adalah **Tenant Admin** biasa:
           - Dikunci secara ketat ke `user->company_id`. Manipulasi query parameter `?company_id=Y` diabaikan sepenuhnya untuk menjaga isolasi data antar penyewa (*tenant isolation*).
      2. Pada `RoleController::store()`: Super Admin dapat membuat Custom Role untuk perusahaan tertentu dengan menyertakan `company_id` pada payload (divalidasi terhadap tabel `companies`).
      3. Pada `RoleController::show()`, `update()`, dan `destroy()`: Super Admin diizinkan melihat, memperbarui, dan menghapus custom role milik tenant manapun, dengan validasi cabang (`branch_ids`) yang otomatis mencocokkan kantor cabang milik perusahaan target.
    - **Status:** ✅ **SELESAI**. Telah diimplementasikan dan diverifikasi dengan automated test suite `RoleSuperAdminTenantTest` (6 test, 38 assertions lulus 100%) serta regression suite (65 test, 297 assertions lulus 100%).

### 💻 5.4. Integrasi Frontend & Kontrak API (Frontend Gaps)

12. **Payload Auth `/me` dan `/login` Belum Mengirimkan Full Permissions Matrix:**
    - **Lokasi:** `app/Http/Controllers/API/AuthController.php` (`userPayload()`)
    - **Deskripsi:** Response payload user hanya menyertakan boolean `can_manage_roles` dan `can_read_roles`, belum menyertakan dictionary lengkap `permissions: { [module]: level }`.
    - **Solusi:** Kembalikan matriks permissions lengkap dalam payload `/me` dan `/login` untuk konsumsi web dan mobile.

13. **Hardcoded Role Filter pada Sidebar Web Dashboard (`App.tsx`):**
    - **Lokasi:** `expenseflow-web/src/App.tsx`
    - **Deskripsi:** Visibilitas menu sidebar (misal grup Finance, Manajemen Karyawan) masih menggunakan boolean hardcoded seperti `!isHrd` atau `!isFinance`. User dengan Custom Role tidak dapat melihat menu yang diizinkan jika slug-nya tidak sama persis dengan role built-in.
    - **Solusi:** Integrasikan helper `hasPermission(module, level)` di frontend React untuk menentukan render menu sidebar dan tombol aksi secara dinamis.

---

### 🛡️ 5.5. Multi-Level Approval Pengajuan Izin/Cuti (SPV ➔ HRD)

14. **Workflow Multi-Level Approval Izin & Cuti Sejajar dengan Lembur:**
    - **Status:** ✅ **SELESAI (100%)**.
    - **Komponen Database & Model:**
      - Migration: `2026_09_27_000001_add_multi_approval_columns_to_leave_requests_table.php` (menambahkan `current_step`, `spv_id`, `spv_approved_at`, `spv_notes`, `notes`).
      - Model [LeaveRequest.php](file:///e:/koding/coba/backend-gawe/expenseflow-backend/app/Models/LeaveRequest.php): relasi `spv(): BelongsTo`.
      - Model [Role.php](file:///e:/koding/coba/backend-gawe/expenseflow-backend/app/Models/Role.php) & [User.php](file:///e:/koding/coba/backend-gawe/expenseflow-backend/app/Models/User.php): integrasi permission modul `leave` level `'spv'` & `'hrd'`.
    - **Engine Persetujuan 2 Tahap ([AttendanceController.php](file:///e:/koding/coba/backend-gawe/expenseflow-backend/app/Http/Controllers/API/AttendanceController.php)):**
      - **Submit:** Karyawan mengajukan izin/cuti ➔ `current_step = 'spv'`, `status = 'pending'`, push notifikasi FCM dikirim ke atasan langsung (`manager_id`).
      - **Tahap 1 (SPV):** Disetujui oleh atasan langsung / SPV divisi tanpa memotong kuota cuti. Mengisi `spv_id`, `spv_approved_at`, `spv_notes`, dan memajukan `current_step = 'hrd'`, status tetap `'pending'`.
      - **Tahap 2 (HRD):** HRD/Admin memeriksa dan mengunci saldo kuota secara atomik (`lockForUpdate`), memotong kuota cuti, menetapkan `status = 'approved'`, `approved_by`, `approved_at`, dan mengirimkan push notifikasi FCM kelulusan ke karyawan.
      - **Penolakan:** Dapat dilakukan di Tahap 1 oleh SPV atau Tahap 2 oleh HRD dengan alasan penolakan wajib (`rejection_reason`).
      - **Proteksi Guard:** Anti-self approval (atasan tidak bisa menyetujui izin dirinya sendiri) dan isolasi cabang (branch scoping). Cuti bersama otomatis (`holiday_id != null`) tetap diproteksi agar tidak bisa di-approve manual.
    - **Web Dashboard ([AttendanceManagement.tsx](file:///e:/koding/coba/backend-gawe/expenseflow-web/src/components/AttendanceManagement.tsx)):**
      - Filter dropdown tahap pengajuan (`Semua Tahap`, `Tahap 1: SPV`, `Tahap 2: HRD`).
      - Tampilan nama atasan langsung di bawah nama karyawan (`Atasan: ...`).
      - Badge status modern (`Tahap 1: SPV` oranye vs `Tahap 2: HRD` biru langit).
      - Catatan persetujuan SPV transparan di tooltip/detail.
    - **Aplikasi Mobile Flutter ([expenseflow-mobile](file:///e:/koding/coba/backend-gawe/expenseflow-mobile)):**
      - Provider `SpvLeaveProvider` dan API Service endpoint `/attendance/spv/leave-approvals`.
      - Layanan Mobile SPV: `SpvLeaveApprovalScreen` lengkap dengan tab Menunggu, Disetujui, Ditolak, serta modal bottom-sheet konfirmasi.
      - Banner Beranda Terpadu di `home_screen.dart` menampilkan counter real-time untuk Lembur Tim dan Izin & Cuti Tim.
      - Tampilan riwayat karyawan di `izin_cuti_screen.dart` menampilkan status transparan ("Menunggu SPV" vs "Menunggu HRD • Disetujui SPV").
    - **Automated Tests:**
      - Suite `LeaveMultiApprovalWorkflowTest`: 8 test cases komprehensif lulus 100% (31 assertions).
      - Regression suite `DivisionPositionSupervisorOvertimeTest` & `MultiApprovalWorkflowTest`: 26 test lulus 100%.
      - Production build web (`npm run build`) & Flutter analysis (`dart analyze lib/`): 0 error / No issues found.

  - **5.6. Konfigurasi Fleksibilitas Multi-Level Approval per Cabang (Edit Kantor):**
    - **Latar Belakang & Kebutuhan:** Perusahaan bertumbuh seringkali memiliki cabang pusat besar dengan struktur hierarki berjenjang (SPV ➔ HRD), namun memiliki kantor cabang kecil/satelit/outlet ramping yang tidak memiliki posisi Supervisor di lapangan. Untuk cabang ramping tersebut, pengajuan lembur maupun cuti/izin idealnya dapat langsung diproses oleh HRD dalam 1 tahap agar alur kerja tidak terhambat.
    - **Arsitektur Database:**
      - Kolom pada tabel `attendance_settings`:
        - `overtime_multi_approval_enabled` (boolean, default: `true`)
        - `leave_multi_approval_enabled` (boolean, default: `true`)
      - Model [AttendanceSetting.php](file:///e:/koding/coba/backend-gawe/expenseflow-backend/app/Models/AttendanceSetting.php) menambahkan kedua atribut ke `$fillable` dan `$casts` sebagai `boolean`.
    - **Alur Kerja Dinamis di Controller ([AttendanceController.php](file:///e:/koding/coba/backend-gawe/expenseflow-backend/app/Http/Controllers/API/AttendanceController.php)):**
      - **Jika Multi-Approval Aktif (`true` - Default):**
        - Lembur: Pengajuan masuk dengan `current_step = 'spv'`, notifikasi ke atasan langsung, disetujui SPV di Tahap 1, lalu disahkan HRD di Tahap 2.
        - Cuti/Izin: Pengajuan masuk dengan `current_step = 'spv'`, notifikasi ke atasan langsung, disetujui SPV di Tahap 1, lalu diputuskan HRD di Tahap 2 sekaligus pemotongan kuota.
      - **Jika Multi-Approval Dinonaktifkan (`false` - Mode Langsung HRD):**
        - Lembur: Pengajuan langsung masuk dengan `current_step = 'hrd'`, notifikasi diarahkan langsung ke HRD/Admin, dan HRD dapat langsung menyetujui pengajuan dalam 1 langkah tanpa menunggu SPV.
        - Cuti/Izin: Pengajuan langsung masuk dengan `current_step = 'hrd'`, notifikasi diarahkan langsung ke HRD/Admin, dan HRD dapat langsung memutuskan & memotong kuota dalam 1 langkah.
    - **Dashboard Web Pengaturan Kantor ([SettingsManagement.tsx](file:///e:/koding/coba/backend-gawe/expenseflow-web/src/components/SettingsManagement.tsx)):**
      - Pada tab "Edit Kantor" / "Tambah Kantor", terdapat **Section 6: Alur Persetujuan Bertingkat (Approval Workflow Cabang)** dengan dua switch kontrol modern:
        1. *Approval Lembur Bertingkat* (Aktif: SPV ➔ HRD / Nonaktif: Langsung HRD).
        2. *Approval Cuti & Izin Bertingkat* (Aktif: SPV ➔ HRD / Nonaktif: Langsung HRD).
      - Pada daftar kartu kantor (Offices Grid), setiap cabang menampilkan pill badge ringkasan alur approval yang sedang aktif (`Lembur: Bertingkat / Langsung HRD` & `Cuti/Izin: Bertingkat / Langsung HRD`).
    - **Automated Feature Tests ([BranchMultiApprovalToggleTest.php](file:///e:/koding/coba/backend-gawe/expenseflow-backend/tests/Feature/BranchMultiApprovalToggleTest.php)):**
      - `test_branch_with_leave_multi_approval_enabled_starts_at_spv_step` (lulus).
      - `test_branch_with_leave_multi_approval_disabled_starts_at_hrd_step_and_allows_instant_hrd_approval` (lulus).
      - `test_branch_with_overtime_multi_approval_enabled_starts_at_spv_step` (lulus).
      - `test_branch_with_overtime_multi_approval_disabled_starts_at_hrd_step_and_allows_instant_hrd_approval` (lulus).
      - `test_update_office_settings_persists_multi_approval_toggles` (lulus).

  - **5.7. Master Job Grade & Sistem Pewarisan Plafon Klaim Bulanan Otomatis:**
    - **Latar Belakang & Kebutuhan Bisnis:**
      Mengatur batas plafon klaim bulanan (`monthly_claim_limit`) secara manual satu per satu untuk puluhan hingga ratusan karyawan sangat memakan waktu HRD dan rawan kesalahan (*human error*). Sistem Job Grade / Level hierarki (contoh: Level 1: Staff, Level 2: Senior Staff, Level 3: Supervisor, Level 4: Manager, Level 5: Director) memungkinkan perusahaan menetapkan plafon standar bulanan per level, sehingga seluruh karyawan dengan jabatan bersangkutan otomatis mewarisi limit tersebut tanpa perlu konfigurasi individual manual.
    - **Arsitektur Database & Migrasi:**
      - Migration: [2026_09_27_100000_create_job_grades_table.php](file:///e:/koding/coba/backend-gawe/expenseflow-backend/database/migrations/2026_09_27_100000_create_job_grades_table.php)
      - Tabel `job_grades`:
        - `id` (bigint unsigned, primary key)
        - `company_id` (foreign key `companies`, cascade on delete)
        - `level` (unsignedInteger, unique per company)
        - `name` (varchar 100, unique per company)
        - `code` (varchar 50, nullable)
        - `default_monthly_claim_limit` (decimal 15,2, default 0, `0` = unlimited / tanpa batas nominal)
        - `description` (text, nullable)
        - `is_active` (boolean, default true)
        - `timestamps`
      - Tabel `positions`: penambahan kolom foreign key `job_grade_id` (`nullable`, `onDelete('set null')`).
      - Tabel `users`: penambahan kolom foreign key `job_grade_id` (`nullable`, `onDelete('set null')`).
    - **Model & Cascading Priority Rule ([User.php](file:///e:/koding/coba/backend-gawe/expenseflow-backend/app/Models/User.php)):**
      - Atribut terkomputasi `getEffectiveMonthlyClaimLimitAttribute()` menerapkan 5 tingkat prioritas cascading:
        1. **Manual Override Individu:** `user.monthly_claim_limit` jika bernilai `> 0`.
        2. **Direct Employee Job Grade:** `user.job_grade_id` (jika karyawan diberikan penugasan grade khusus yang berbeda dari jabatan).
        3. **Inherited Position Job Grade:** `user.position.job_grade_id` (diwarisi otomatis dari posisi jabatan yang diemban).
        4. **Global Company Setting:** `company_settings.monthly_claim_limit` (fallback pengaturan perusahaan).
        5. **Unlimited:** `0` (bebas klaim).
      - Atribut terkomputasi `getEffectiveJobGradeAttribute()` mengembalikan instance objek `JobGrade` aktif (user grade || position grade).
      - Eager loading `'position.jobGrade'` dan `'jobGrade'` pada `UserController::index` untuk menjamin efisiensi performa tanpa N+1 queries.
    - **REST API Endpoints ([JobGradeController.php](file:///e:/koding/coba/backend-gawe/expenseflow-backend/app/Http/Controllers/API/JobGradeController.php)):**
      - `GET /api/v1/admin/job-grades`: List job grades dengan perhitungan `positions_count` & `users_count`.
      - `POST /api/v1/admin/job-grades`: Buat job grade baru dengan validasi keunikan level & nama per perusahaan, serta integrasi `AuditLogger`.
      - `GET /api/v1/admin/job-grades/{id}`: Detail satu job grade.
      - `PUT /api/v1/admin/job-grades/{id}`: Pembaruan data job grade dengan pencatatan audit perubahan (*old values* vs *new values*).
      - `DELETE /api/v1/admin/job-grades/{id}`: Proteksi integritas relasi — ditolak dengan HTTP 422 jika masih memiliki keterikatan aktif dengan posisi jabatan atau akun karyawan.
      - Integrasi `job_grade_id` pada CRUD [PositionController.php](file:///e:/koding/coba/backend-gawe/expenseflow-backend/app/Http/Controllers/API/PositionController.php) dan [UserController.php](file:///e:/koding/coba/backend-gawe/expenseflow-backend/app/Http/Controllers/API/UserController.php).
    - **Frontend Antarmuka Web:**
      - [OrganizationManagementTab.tsx](file:///e:/koding/coba/backend-gawe/expenseflow-web/src/components/OrganizationManagementTab.tsx):
        - Subtab ke-3: **Master Job Grade & Level** dengan tabel interaktif (Level, Nama, Kode, Plafon Bulanan Standar, Jabatan Terkait, Karyawan Langsung, Status Aktif, Aksi Edit/Hapus).
        - Modal Tambah & Edit Job Grade lengkap dengan preset nominal cepat (1 Jt, 2 Jt, 3 Jt, 5 Jt, 10 Jt, Unlimited).
        - Modal Tambah/Edit Jabatan dilengkapi dropdown pilihan Job Grade untuk pewarisan otomatis.
        - Kolom "Job Grade (Plafon Standar)" pada tabel Jabatan & Posisi.
      - [EmployeeMultiTabForm.tsx](file:///e:/koding/coba/backend-gawe/expenseflow-web/src/components/EmployeeMultiTabForm.tsx):
        - Tab 1 (Pekerjaan & Organisasi): Dropdown "Job Grade & Level Plafon" dengan indikator visual apakah diwarisi dari jabatan atau override khusus.
        - Bagian "Kebijakan Batas Klaim Struk Bulanan": Card ringkasan plafon standar Job Grade efektif, badge status ("Mewarisi Standar Grade" vs "Nominal Khusus Karyawan Aktif"), dan tombol preset cepat "Sesuai Grade (Rp X Jt)".
      - [KaryawanManagement.tsx](file:///e:/koding/coba/backend-gawe/expenseflow-web/src/components/KaryawanManagement.tsx):
        - Tabel karyawan menampilkan status plafon secara jelas: Nonaktif, Nominal Khusus (Khusus), atau Standar Job Grade (Grade Lv X), atau Unlimited.
    - **Automated Feature Tests ([JobGradeManagementAndInheritanceTest.php](file:///e:/koding/coba/backend-gawe/expenseflow-backend/tests/Feature/JobGradeManagementAndInheritanceTest.php)):**
      - `test_job_grade_crud_by_admin`: Pembuatan, pembacaan, pembaruan, dan penghapusan job grade lulus 100%.
      - `test_job_grade_deletion_prevented_when_in_use_by_position_or_user`: Verifikasi proteksi penghapusan ketika grade masih dipakai oleh jabatan atau karyawan lulus 100%.
      - `test_cascading_claim_limit_inheritance_precedence`: Verifikasi 4 skenario urutan prioritas klaim (override > user grade > position grade > default 0) lulus 100%.
      - `test_user_api_returns_effective_claim_limit_and_grade`: Verifikasi serialisasi atribut `effective_monthly_claim_limit` dan `effective_job_grade` pada respons user API lulus 100%.

  - **5.8. Penegasan Batas Antara Role vs Jabatan & Eliminasi Total Fuzzy String Matching:**
    - **Latar Belakang & Masalah Arsitektur:**
      Sebelumnya, sistem memiliki kecenderungan mencampuradukkan konsep **Role** (hak akses teknis aplikasi) dengan **Jabatan / Hierarki Kepemimpinan** (struktur organisasi). Terdapat banyak penggunaan heuristik pencocokan string (*fuzzy string matching*) seperti `str_contains($roleSlug, 'spv')`, `'manager'`, `'head'`, `'kepala'`, `'kabag'` pada controller approval struk, lembur, dan cuti. Hal ini menimbulkan kelemahan fatal:
      1. Jika perusahaan membuat Custom Role dengan nama *"Supervisor"* tetapi akun tersebut tidak memiliki jabatan struktural maupun bawahan, sistem keliru memberinya hak menyetujui pengajuan karyawan.
      2. Sebaliknya, karyawan dengan Jabatan struktural Supervisor (`positions.is_supervisor = true`) atau Atasan Langsung (`manager_id`) yang menggunakan Custom Role bernama non-SPV (misal: *"Finance Team"* atau *"Operasional"*) dapat terblokir dari wewenang approval bawahan langsungnya.
      3. *Privilege Creep*: Wewenang modul `receipt: manage` disalahartikan sebagai level `spv` untuk menyetujui struk Tier 3 tahap 2.
    - **Prinsip & Pemisahan Tanggung Jawab:**
      1. **Role (`roles` & `role_permissions`):**
         - Menentukan platform akses (`both`, `mobile_only`).
         - Menentukan cakupan kantor cabang (`all`, `specific`, `self`).
         - Menentukan hak akses modul (`receipt`, `invoice`, `attendance`, `leave`, `overtime`, dll) pada level `none`, `read`, `manage`, atau wewenang khusus (`spv`, `hrd`).
         - Menyediakan bypass administratif bawaan untuk `super_admin`, `admin`, dan `hrd`.
      2. **Jabatan / Posisi (`positions.is_supervisor`) & Atasan Langsung (`users.manager_id`):**
         - Menentukan kepemimpinan struktural, garis komando pelaporan per individu, dan wewenang approval Step 1 (SPV) untuk lembur serta cuti/izin.
         - Karyawan berhak menyetujui pengajuan Step 1 bawahan jika:
           - Merupakan atasan langsung individu bersangkutan (`$user->id === $subordinate->manager_id`), ATAU
           - Memiliki jabatan supervisor dalam divisi yang sama (`$user->position?->is_supervisor && $user->division_id === $subordinate->division_id`), ATAU
           - Memiliki hak akses modul lembur/cuti berlevel `'spv'` / `'hrd'`, atau bypass administratif (`super_admin`, `admin`, `hrd`).
      3. **Eliminasi Total Fuzzy String Matching (Zero Fuzzy Matching):**
         - Seluruh pemeriksaan substring pada slug atau nama role dihapus 100% dari codebase.
         - Helper `User::isSupervisor()` dievaluasi murni berbasis:
           1. `$this->position && $this->position->is_supervisor`
           2. `$this->subordinates()->exists()`
           3. `$this->hasPermission(Role::MODULE_OVERTIME, 'spv') || $this->hasPermission(Role::MODULE_LEAVE, 'spv')`
           4. Bypass: `in_array($userRoleCode, ['super_admin', 'admin', 'hrd'], true)`
         - Pada [ReceiptController.php](file:///e:/koding/coba/backend-gawe/expenseflow-backend/app/Http/Controllers/API/ReceiptController.php) (`approve` dan `bulkApprove`): evaluasi approver Tier 3 tahap 2 mengecek posisi supervisor struktural dan hak peran `receipt: spv` secara eksplisit, tanpa heuristik nama role.
         - Pada `Role::hasPermission()`: pencegahan privilege creep memastikan level `'spv'` untuk `MODULE_RECEIPT` strictly membutuhkan `access_level === 'spv'` (tidak otomatis dipenuhi oleh `manage`).
    - **Suite Pengujian Khusus ([RoleVsPositionSeparationTest.php](file:///e:/koding/coba/backend-gawe/expenseflow-backend/tests/Feature/RoleVsPositionSeparationTest.php)):**
      - `test_custom_role_with_spv_name_cannot_approve_subordinate_without_supervisor_position_or_subordinates`: Memastikan role kustom dengan nama/slug SPV tanpa posisi supervisor ditolak 403 `SPV_REQUIRED`.
      - `test_plain_employee_with_is_supervisor_position_can_approve_subordinate_step1`: Memastikan karyawan biasa dengan jabatan `is_supervisor = true` berhasil menyetujui lembur bawahan divisinya.
      - `test_plain_employee_assigned_as_direct_manager_can_approve_subordinate_step1`: Memastikan karyawan biasa yang ditugaskan sebagai direct `manager_id` berhasil menyetujui lembur bawahan langsungnya.
      - `test_finance_staff_with_manage_permission_cannot_approve_tier3_receipt_without_spv_position`: Memastikan staf finance dengan wewenang `manage` tetapi non-supervisor ditolak 422 `SPV_FINANCE_REQUIRED` saat menyetujui Tier 3 tahap 2.
      - `test_supervisor_from_other_division_cannot_approve_employee_with_assigned_manager`: Memastikan supervisor dari divisi lain ditolak 403 `DIRECT_SUPERVISOR_REQUIRED` jika karyawan memiliki atasan langsungnya sendiri.


