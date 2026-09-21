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

### 2.5. Pembaruan Tabel `receipts` (Fase 2)
- `approval_tier`: VARCHAR(50) Nullable (menyimpan nama/ID aturan tier saat submit).
- `required_approvals`: INT DEFAULT 1 (jumlah approval yang diperlukan).
- `current_approvals`: INT DEFAULT 0 (jumlah approval yang sudah didapat).

### 2.6. Pembaruan Tabel `overtime_approvals` (Fase 2)
- `current_level`: ENUM('spv', 'hrd') DEFAULT 'spv'.
- `spv_id`: BIGINT UNSIGNED Nullable (FK ke `users.id`).
- `spv_approved_at`: TIMESTAMP Nullable.
- `spv_notes`: TEXT Nullable.
- `reviewed_by`: BIGINT UNSIGNED Nullable (HRD/Admin).
- `reviewed_at`: TIMESTAMP Nullable (Waktu persetujuan HRD).

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

### ⚙️ 5.2. Bug Logika Bisnis & Persetujuan Multi-Approval (Medium / High)

4. **[SELESAI] Salah Panggil Atribut `code` vs `slug` pada Overtime Approval:**
   - **Lokasi:** `app/Http/Controllers/API/AttendanceController.php` (`approveOvertime()`)
   - **Deskripsi:** Terdapat baris `$actor->roleRelation?->code` padahal tabel dan model `Role` hanya memiliki kolom `slug`.
   - **Solusi:** Ganti atribut `$actor->roleRelation?->code` menjadi `$actor->roleRelation?->slug`.
   - **Status:** ✅ **SELESAI**. Diganti menjadi `$actor->roleRelation?->slug` dan diverifikasi di `MultiApprovalWorkflowTest`.

5. **[SELESAI] Deteksi Otorisator Tier 3 Struk Terlalu Kaku Berdasarkan Keyword Bahasa Inggris:**
   - **Lokasi:** `app/Http/Controllers/API/ReceiptController.php` (`approve()`)
   - **Deskripsi:** Pengecekan approver tahap 2 untuk struk di atas Rp 1.000.000 hanya mencocokkan substring (`spv`, `head`, `manager`, `supervisor`). Nama jabatan dalam bahasa Indonesia seperti *"Kepala Keuangan"*, *"Kabag Finance"*, *"Otorisator"*, atau *"Direktur"* ditolak dengan pesan error `SPV_FINANCE_REQUIRED` (422).
   - **Solusi:** Tambahkan kata kunci bahasa Indonesia (`kepala`, `kabag`, `ketua`, `lead`, `otorisator`, `direktur`, `vp`) dan utamakan evaluasi permission `MODULE_RECEIPT, manage`.
   - **Status:** ✅ **SELESAI**. Ditambahkan kata kunci bahasa Indonesia (`kepala`, `kabag`, `ketua`, `lead`, `otorisator`, `direktur`, `vp`, `koordinator`) secara simetris pada slug dan nama role di `ReceiptController.php` dan diverifikasi di `MultiApprovalWorkflowTest`.

6. **[SELESAI] Tabrakan Heuristik String Path Matching di `RoleMiddleware`:**
   - **Lokasi:** `app/Http/Middleware/RoleMiddleware.php` (`checkCustomRolePermission()`)
   - **Deskripsi:** Route `/api/v1/dashboard/attendance/users` cocok dengan `str_contains($path, 'users')` sebelum `str_contains($path, 'attendance')`, sehingga menuntut hak akses `MODULE_USER` bukan `MODULE_ATTENDANCE`.
   - **Solusi:** Urutkan prioritas route yang lebih spesifik atau gunakan pemetaan route name/action yang presisi.
   - **Status:** ✅ **SELESAI**. Diterapkan pemetaan presisi berbasis Controller & Action Method (`evaluateControllerPermission`) serta urutan heuristik berjenjang spesifik-ke-umum (`evaluatePathPermission`), diverifikasi dengan `RoleMiddlewarePathCollisionTest` (6 test, 15 assertions lulus 100%).

### 📦 5.3. Integritas Data & Tenant Isolation (Data Integrity)

7. **[SELESAI] State Inkonstan `users.role` saat Custom Role Dihapus:**
   - **Lokasi:** `app/Http/Controllers/API/RoleController.php` (`destroy()`), `app/Models/Role.php` (`booted`), dan `app/Models/User.php` (`booted`).
   - **Deskripsi:** Saat role dihapus dari database, `users.role_id` menjadi `NULL`, namun `users.role` tetap menyimpan string slug lama, menyebabkan user masuk ke *zombie state* (semua request API ditolak 403).
   - **Solusi:**
     1. Terapkan proteksi ganda pada `RoleController::destroy()`: memvalidasi penolakan 422 jika masih terdapat user yang terasosiasi baik via `role_id` maupun string `users.role`.
     2. Di dalam transaksi penghapusan `destroy()` dan hook event `Role::deleting`, lakukan sinkronisasi pembersihan otomatis: setiap user yang terasosiasi dialihkan secara aman ke role bawaan `employee` (`role_id = $employeeRoleId`, `role = 'employee'`).
     3. Terapkan event hook `User::saving` untuk memastikan sinkronisasi dua arah antara `role_id` dan slug `role` secara deterministik serta fallback otomatis ke `employee` jika `role_id` bernilai `null`.
   - **Status:** ✅ **SELESAI**. Telah diimplementasikan dan diverifikasi dengan automated test suite `RoleDeletionConsistencyTest` (6 test, 26 assertions lulus 100%) serta regression suite (59 test, 259 assertions lulus 100%).

8. **Super Admin Tenant Isolation di `RoleController::index`:**
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

9. **Payload Auth `/me` dan `/login` Belum Mengirimkan Full Permissions Matrix:**
   - **Lokasi:** `app/Http/Controllers/API/AuthController.php` (`userPayload()`)
   - **Deskripsi:** Response payload user hanya menyertakan boolean `can_manage_roles` dan `can_read_roles`, belum menyertakan dictionary lengkap `permissions: { [module]: level }`.
   - **Solusi:** Kembalikan matriks permissions lengkap dalam payload `/me` dan `/login` untuk konsumsi web dan mobile.

10. **Hardcoded Role Filter pada Sidebar Web Dashboard (`App.tsx`):**
    - **Lokasi:** `expenseflow-web/src/App.tsx`
    - **Deskripsi:** Visibilitas menu sidebar (misal grup Finance, Manajemen Karyawan) masih menggunakan boolean hardcoded seperti `!isHrd` atau `!isFinance`. User dengan Custom Role tidak dapat melihat menu yang diizinkan jika slug-nya tidak sama persis dengan role built-in.
    - **Solusi:** Integrasikan helper `hasPermission(module, level)` di frontend React untuk menentukan render menu sidebar dan tombol aksi secara dinamis.
