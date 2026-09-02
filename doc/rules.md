# ExpenseFlow — Project Rules

## Deskripsi Proyek
Aplikasi manajemen pengeluaran perusahaan.
Karyawan foto struk via Flutter mobile,
tim finance approve via web dashboard.
Dilengkapi fitur invoice vendor dengan
multi-level approval dan sistem presensi (attendance) berbasis GPS.

## Tech Stack
- **Mobile**   : Flutter + Dio + Firebase FCM
- **Backend**  : Laravel 11 (Framework 13.15.0) + Sanctum + MySQL
- **Web**      : HTML/CSS/JS + Alpine.js + Tailwind
- **OCR**      : Tesseract (dev) / Google Cloud Vision API (production)
- **Queue**    : Laravel Queue (driver: database)
- **Storage**  : Local disk (`storage/app/private/`), nanti R2
- **Auth**     : Sanctum token — mobile TANPA expiry (tetap login sampai Logout), web expired 24 jam
- **Rate Limit**: Login 5 attempts/menit/email + 120 attempts/menit/IP (retry_after di-clamp ≤ 60s)

## Status Saat Ini
- Frontend Flutter : SELESAI
- Frontend Web     : SELESAI
- Backend Laravel  : SEDANG DIKERJAKAN
  - Auth & security        : SELESAI (login, rate limit, token expiry)
  - Receipt (struk) CRUD   : SELESAI (upload OCR, submit, approve/reject, variance)
  - Invoice multi-level    : SELESAI (store, approve 3 level, reject)
  - Vendor management      : SELESAI (CRUD, toggle active)
  - User management        : SELESAI (CRUD, deactivate, reset password)
  - Presensi (attendance)  : SELESAI (check-in/out WFH, leave, report, CSV export)
  - Custom Shift/Scheduling: SELESAI (shift per karyawan & cabang, override jam kerja, roster, bulk assign) — 2026-07-04
  - Payroll (gaji)         : BELUM (task tercatat di bawah — "Roadmap Fitur Payroll")
  - Custom Role Management  : BELUM (rencana fitur — lihat section "Role System" → Custom Role)
  - Sedang di              : (tambahkan fitur baru di sini)

---

## Struktur Folder Backend
```
app/
  Http/
    Controllers/
      API/
        AuthController.php          ← login, logout, me
        ReceiptController.php       ← struk: store, updateClaim, submit, approve, reject, show, myReceipts, inbox, dashboardReceipts
        InvoiceController.php       ← invoice: store, approve (multi-level), reject
        VendorController.php        ← vendor: index, store, update, toggleActive
        ForgotPasswordController.php  ← public OTP password reset: sendOtp, verifyOtp, resetPassword
        UserController.php          ← user: index, store, update, deactivate, activate, destroy
        AttendanceController.php    ← presensi: checkIn, checkOut, myAttendance, requestLeave, myLeaveBalance,
                                       toggleAttendance, toggleWfh, listUsers, approveLeave, rejectLeave, listLeaves,
                                       today, monthlySummary, reportAttendance, exportReport,
                                       listLeaveBalances, setLeaveBalance,
                                       listSettings, storeSettings, showSettings, updateSettings, destroySettings
    Middleware/
      RoleMiddleware.php            ← cek role user (parameter: role:finance,hrd,admin)
      CompanyMiddleware.php         ← isolasi data per company_id (super_admin bypass)
      ReceiptAccessMiddleware.php   ← hanya role employee boleh akses receipt
      AttendanceAccessMiddleware.php← hanya attendance_enabled=true boleh akses presensi
  Models/
    User.php, Company.php, LoginAttempt.php
    Receipt.php, ReceiptImage.php, ReceiptApproval.php
    Invoice.php, InvoiceItem.php, InvoiceApproval.php
    Vendor.php
    Attendance.php, AttendanceSetting.php
    LeaveRequest.php, LeaveBalance.php
  Services/
    OcrService.php                  ← facade, pilih driver dari config/ocr.php
    LocationService.php             ← Haversine distance calculator
    Ocr/
      OcrDriverInterface.php        ← interface: analyze(string $imagePath): array
      TesseractDriver.php           ← Tesseract OCR (dev)
      GoogleVisionDriver.php        ← Google Cloud Vision (prod)
      Concerns/
        ParsesOcrText.php           ← shared trait: extractAmount, extractMerchant, extractDate
  Jobs/
    ProcessOcrJob.php               ← queue job: OCR → parse → simpan ocr_raw_* → recalculateVariance
database/
  migrations/                       ← 35 file migration (lihat daftar di bawah)
  seeders/
    DatabaseSeeder.php              ← dummy data untuk testing Postman
routes/
  api.php                           ← semua route, prefix /api/v1
config/
  ocr.php                           ← OCR_DRIVER config (tesseract / google_vision)
bootstrap/
  app.php                           ← middleware alias registration
```

---

## Database Tables (21 tabel + Laravel defaults)

### Core Tables
| # | Tabel | Keterangan |
|---|-------|-----------|
| 1 | `companies` | Perusahaan (name, email, phone, address, logo, is_active) |
| 2 | `users` | Karyawan (company_id, employee_code, name, email, password, role, department, monthly_claim_limit, is_active, attendance_enabled, wfh_enabled, radius_enabled) |
| 3 | `personal_access_tokens` | Sanctum token (otomatis) |
| 4 | `password_reset_tokens` | Reset password |
| 5 | `login_attempts` | Log percobaan login (user_id nullable, ip_address, user_agent, status, attempted_at) |
| 6 | `company_settings` | Pengaturan perusahaan (key-value) |

### Receipt (Struk) Tables
| # | Tabel | Keterangan |
|---|-------|-----------|
| 7 | `receipts` | Struk (company_id, user_id, receipt_number, sha256_hash, image_path, vendor_name, total_amount nullable, claimed_amount nullable, receipt_date nullable, currency, status, submitted_at, ocr_status, ocr_raw_amount, ocr_raw_merchant, ocr_raw_date, ocr_attempts, ocr_error, variance_flag, variance_pct, category, notes) |
| 8 | `receipt_images` | Gambar struk (receipt_id, file_path, file_name, file_size, mime_type) |
| 9 | `receipt_approvals` | Approval struk (receipt_id, user_id, status, notes) |

### Invoice & Vendor Tables
| # | Tabel | Keterangan |
|---|-------|-----------|
| 10 | `vendors` | Vendor (company_id, name, email, phone, address, contact_person, tax_id, bank_name, bank_account_no, bank_account_name, is_active) |
| 11 | `invoices` | Invoice (company_id, vendor_id, user_id, invoice_number, po_number, subtotal, tax_amount, discount_amount, total_amount, due_date, invoice_date, currency, status, max_approval_level, current_approval_level, source, category, notes) |
| 12 | `invoice_items` | Item invoice (invoice_id, description, quantity, unit_price, total_price) |
| 13 | `invoice_images` | Gambar invoice (invoice_id, file_path, file_name, file_size, mime_type) |
| 14 | `invoice_approvals` | Approval invoice (invoice_id, user_id, status, approval_level, reviewed_at, rejection_reason, notes) |

### Audit & Notification Tables
| # | Tabel | Keterangan |
|---|-------|-----------|
| 15 | `activity_logs` | Log aktivitas (company_id, user_id, action, description, subject_type, subject_id, entity_type, entity_id, properties) |
| 16 | `notifications` | Notifikasi (id UUID, type, notifiable_type, notifiable_id, user_id, data JSON, entity_type, entity_id, read_at) |

### Attendance (Presensi) Tables
| # | Tabel | Keterangan |
|---|-------|-----------|
| 17 | `attendances` | Presensi harian (user_id, company_id, date, check_in_time, check_in_lat, check_in_lng, check_in_distance_meters, check_in_type [onsite/wfh/field], check_in_photo, check_out_time, check_out_lat, check_out_lng, check_out_type, status [present/late/absent], **work_minutes**, **overtime_minutes**, **is_holiday**, **auto_checkout_at**, **is_auto_checkout**, notes) |
| 18 | `attendance_settings` | Pengaturan kantor (company_id, office_name, office_latitude, office_longitude, radius_meters default 100, work_start_time default 08:00, work_end_time default 17:00, late_tolerance_minutes default 15, require_selfie, allow_wfh, wfh_checkin_window_minutes, overtime_enabled default true, min_overtime_minutes default 30, checkout_reminder_minutes default 30, auto_checkout_grace_minutes default 60, **default_leave_quota** default 12, **leave_reset_date** 'MM-DD' nullable, **last_leave_reset_on** date nullable) |
| 19 | `leave_requests` | Pengajuan cuti/izin (user_id, company_id, leave_type [wfh/izin/sakit/cuti], start_date, end_date, total_days, reason, status [pending/approved/rejected], approved_by, approved_at, rejection_reason) |
| 20 | `leave_balances` | Saldo cuti (user_id, company_id, year, leave_type, quota, used) |
| 20b | `holidays` | Kalender libur (company_id **nullable** → NULL = libur nasional semua company, date, name, is_national). Unique (company_id, date). Dipakai untuk hitung hari kerja cuti & lembur hari libur. |
| 20c | `overtime_approvals` | Approval lembur (attendance_id, user_id, company_id, overtime_minutes, status [pending/approved/rejected], reviewed_by, reviewed_at, notes, is_auto_checkout). Dibuat saat checkout jika ada lembur. |
| 20d | `shifts` | Template shift (company_id, **attendance_setting_id** nullable=milik cabang/null=company-wide, name, description, is_active). Ditambah 2026-07-04. |
| 20e | `shift_schedules` | Detail 7 hari per shift dengan **VERSIONING** (shift_id, **effective_date**, day_of_week 0=Minggu–6=Sabtu, work_start_time, work_end_time, is_off, is_cross_day). Unique(shift_id, day_of_week, effective_date). **Versi yang berlaku pada tanggal T = baris dengan effective_date ≤ T terbesar.** Edit jam kerja shift membuat VERSI BARU (effective_date = hari ini + max(1, shift_notice_days)); versi lama tetap berlaku sebelum tanggal efektif. |
| 20f | `user_shifts` | Assignment shift ke karyawan (user_id, shift_id **nullable**=default kantor, start_date, **end_date nullable**, notes). Unique(user_id, start_date). **Shift berlaku pada tanggal T jika: start_date ≤ T DAN (end_date NULL ATAU end_date ≥ T).** Diurutkan DESC start_date → ambil first() = assignment terbaru yang mencakup tanggal T. |
| 20g | `holiday_exclusions` | **Pengecualian karyawan pada hari libur** (holiday_id, user_id, timestamps, unique(holiday_id, user_id)). Ditambah 2026-08-20. Karyawan yang masuk daftar ini **TIDAK dianggap libur** pada tanggal tsb: tetap hari kerja normal, tidak dibuatkan leave_request cuti bersama, tidak dianggap libur di kalender mobile, dan tidak terpotong saldo. Dipakai `isNonWorkingDay()`, `countWorkingDays()`, `ShiftController::calendar()`. |

> **Kebijakan Saldo Cuti Bersama (2026-08-20):** kolom `attendance_settings.collective_leave_policy` **DIDROP** (migration `2026_08_20_064513...`). Perilaku di-**hardcode** menjadi `block`: karyawan hanya boleh ikut cuti bersama jika sisa saldo cuti ≥ `total_days`. Jika tidak cukup → ditolak (422 "Saldo cuti Anda tidak cukup...") dan di mobile banner menampilkan peringatan + tombol "Ya, Saya Ikut" di-disable.
>
> **Banner cuti bersama — sembunyikan jika karyawan sudah libur:** `listCollectiveLeaves()` menetapkan `show_banner=false` bila pada tanggal cuti bersama karyawan (a) libur dari jadwal shift (`ShiftController::resolveSchedule(...)['is_off'] == true`, termasuk template shift OFF & hari di luar work_days) atau (b) punya cuti pribadi `status=approved` yang mencakup tanggal tsb. Tujuan: hindari ikut cuti bersama & potong saldo saat karyawan sebenarnya sudah libur.
>
> **Optimasi N+1 (helper `resolveOffDatesForUser`):** perhitungan `is_off` per tanggal cuti bersama memakai helper privat bulk di `AttendanceController` yang meniru `ShiftController::resolveSchedule()` persis (UserShift + ShiftSchedule + office di-preload sekali), menurunkan query 24 → 4 untuk 8 tanggal tanpa mengubah perilaku. |

### Laravel Defaults
| # | Tabel | Keterangan |
|---|-------|-----------|
| 21 | `failed_jobs` | Queue job yang gagal |
| 22 | `cache` | Cache (Laravel default) |
| 23 | `jobs` / `job_batches` | Queue (Laravel default) |

---

## Role System — SANGAT PENTING

### Arsitektur Role (Sistem Hybrid: Built-in + Custom)

Sistem role ExpenseFlow menggunakan **dua lapis**:

1. **Built-in Role** — role bawaan sistem yang tidak bisa dihapus, permission-nya sudah terdefinisi sebagai *baseline* dan bisa dijadikan template.
2. **Custom Role** — role tambahan yang dibuat oleh `admin`/`super_admin` per perusahaan, permission-nya bisa dikonfigurasi penuh oleh user.

---

### Built-in Role (Tidak Bisa Dihapus)
| Role | Platform | Scan Struk (Mobile) | Approval Struk (Web) | Akses Presensi | Approval Invoice & Fitur Finance |
|------|----------|---------------------|----------------------|----------------|-----------------|
| `employee` | HANYA mobile | ✅ CRUD struk sendiri | ❌ | ✅ (jika attendance_enabled) | ❌ |
| `finance` | mobile + web | ✅ scan & submit struk sendiri | ✅ approval struk karyawan | ✅ (jika attendance_enabled) | Level 1 (Finance Manager) |
| `hrd` | mobile + web | ✅ scan & submit struk sendiri | ❌ **TIDAK ada akses approval struk** | ✅ (jika attendance_enabled) | ❌ **TIDAK ada akses fitur finance & invoice** |
| `admin` | mobile + web | ✅ scan & submit struk sendiri | ✅ approval struk karyawan | ✅ (jika attendance_enabled) | Level 1 + Level 2 (+ Direksi) |
| `super_admin` | mobile + web | ✅ scan & submit struk sendiri | ✅ approval struk karyawan | ✅ (selalu) | Level 1 + 2 + 3 (+ Komisaris) |

> **Scan Struk Mobile:** Semua role bisa upload foto, scan OCR, dan submit struk pengeluaran sendiri
> via Flutter. Setiap user hanya bisa lihat & kelola struk miliknya sendiri (ownership check di controller).

> **Fitur Finance Web (Dashboard):** Seluruh modul finance (Approval Struk Reimbursement, Invoice Vendor & Multi-level Approval, Master Data Vendor, serta Pengaturan Aturan/Threshold Finance) **khusus finance, admin, super_admin** — HRD dikecualikan penuh.
> Route finance memakai middleware `role:finance,admin,super_admin` (tanpa `hrd`).

> **Catatan akses manajemen:** Menu **Manajemen** (Karyawan + Presensi & Cuti + Shift + Lembur + Device Binding + Rekrutmen) adalah ranah
> **HRD/admin/super_admin**. **Finance dikecualikan** — route `admin/users*` dan `dashboard/attendance*`
> memakai middleware `role:hrd,admin,super_admin` (tanpa `finance`), dan seluruh menu manajemen disembunyikan
> di web untuk finance. HRD fokus ke pengelolaan SDM & Presensi, sedangkan Finance fokus ke transaksi pengeluaran (struk, invoice, vendor).

---

### Custom Role — Fitur Manajemen Role (Roadmap)

> **Status:** BELUM DIIMPLEMENTASI — dicatat sebagai rencana fitur ke depan.

`admin` dan `super_admin` dapat membuat role baru sesuai kebutuhan perusahaan (mis. `operational_manager`, `branch_head`, `it_support`), kemudian mengatur permission apa saja yang bisa diakses oleh role tersebut.

#### Konsep Permission Granular
Setiap permission merepresentasikan satu aksi spesifik yang bisa di-toggle ON/OFF per role:

| Grup | Permission Key | Keterangan |
|------|---------------|------------|
| **Receipt** | `receipt.view_own` | Lihat struk milik sendiri |
| | `receipt.upload` | Upload & scan struk |
| | `receipt.submit` | Submit struk ke finance |
| | `receipt.approve` | Approve struk karyawan |
| | `receipt.reject` | Reject struk karyawan |
| | `receipt.view_all` | Lihat semua struk perusahaan |
| **Invoice** | `invoice.create` | Buat invoice baru |
| | `invoice.approve_l1` | Approve invoice Level 1 |
| | `invoice.approve_l2` | Approve invoice Level 2 |
| | `invoice.approve_l3` | Approve invoice Level 3 |
| | `invoice.view` | Lihat daftar invoice |
| **Vendor** | `vendor.create` | Tambah vendor |
| | `vendor.edit` | Edit vendor |
| | `vendor.toggle` | Aktif/nonaktif vendor |
| **Karyawan** | `user.view` | Lihat daftar karyawan |
| | `user.create` | Tambah karyawan |
| | `user.edit` | Edit karyawan |
| | `user.deactivate` | Nonaktifkan karyawan |
| | `user.delete` | Hapus karyawan (user nonaktif) |
| | `user.reset_password` | Reset password karyawan |
| **Presensi** | `attendance.view_all` | Lihat presensi semua karyawan |
| | `attendance.manage` | Kelola pengaturan presensi |
| | `attendance.leave_approve` | Approve/reject cuti |
| | `attendance.overtime_approve` | Approve/reject lembur |
| | `attendance.shift_manage` | Kelola shift & roster |
| | `attendance.holiday_manage` | Kelola kalender libur |
| **Payroll** | `payroll.generate` | Generate payroll |
| | `payroll.approve` | Approve payroll |
| | `payroll.view` | Lihat rekap payroll |
| **Role** | `role.create` | Buat role baru |
| | `role.edit` | Edit permission role |
| | `role.delete` | Hapus custom role |
| | `role.assign` | Assign role ke karyawan |
| **Settings** | `settings.view` | Lihat pengaturan perusahaan |
| | `settings.edit` | Ubah pengaturan perusahaan |

#### Aturan Custom Role
- Custom role bersifat **per company** — tidak bisa dipakai lintas perusahaan.
- Custom role **tidak bisa menghapus atau mengubah built-in role** (`employee`, `finance`, `hrd`, `admin`, `super_admin`).
- Hanya `admin` dan `super_admin` yang bisa membuat/mengedit/menghapus custom role.
- Saat role dihapus: karyawan yang memakai role tersebut wajib di-assign ulang ke role lain (tidak boleh rolenya menjadi null/kosong).
- Built-in role tetap memakai logika middleware berbasis string (`role:finance,hrd,...`); custom role menggunakan permission check (`hasPermission('receipt.approve')`).
- `super_admin` **selalu bypass** semua permission check (sama seperti sekarang).
- Platform restriction (mobile-only untuk `employee`) tetap berlaku untuk built-in role; custom role bisa dikonfigurasi platform aksesnya (mobile / web / keduanya).

#### Tabel Database yang Dibutuhkan (Belum Dibuat)
```
roles
  - id, company_id (nullable → NULL = built-in), name, slug, description,
    is_builtin (bool), platform [mobile/web/both], is_active

role_permissions
  - id, role_id, permission_key (varchar, mis. 'receipt.approve'), granted (bool)

user_roles (jika 1 user bisa punya >1 role, opsional)
  - id, user_id, role_id
  (Alternatif: tetap 1 role per user di tabel users.role, tambahkan FK role_id)
```

#### API Endpoint yang Direncanakan
```
# Role Management (admin/super_admin)
GET    /api/v1/admin/roles                    → index (list semua role: built-in + custom)
POST   /api/v1/admin/roles                    → store (buat custom role baru)
GET    /api/v1/admin/roles/{id}               → show (detail role + permissions)
PUT    /api/v1/admin/roles/{id}               → update (edit nama/deskripsi/platform)
DELETE /api/v1/admin/roles/{id}               → destroy (hanya custom role, karyawan sudah re-assign)

# Permission Management (admin/super_admin)
GET    /api/v1/admin/roles/{id}/permissions   → listPermissions (daftar permission per role)
PUT    /api/v1/admin/roles/{id}/permissions   → syncPermissions (set ulang semua permission role)
PATCH  /api/v1/admin/roles/{id}/permissions/{key} → togglePermission (toggle satu permission)

# Assign Role ke User (admin/super_admin)
PATCH  /api/v1/admin/users/{id}/role          → assignRole (ganti role karyawan)
```

#### Keputusan yang Perlu Ditanyakan Sebelum Implementasi
1. **1 user = 1 role atau bisa multi-role?** — Saat ini 1 user 1 role; multi-role menambah kompleksitas permission conflict.
2. **Apakah built-in role permission-nya bisa di-override per perusahaan?** — Atau murni hardcode di middleware?
3. **Platform restriction untuk custom role**: apakah user bisa pilih role X hanya bisa login di mobile atau web atau keduanya?
4. **Approval invoice untuk custom role**: apakah custom role bisa di-set sebagai approver level berapa?
5. **Urutan implementasi**: apakah custom role dibuat dulu UI-nya di web, atau API-nya dulu?

---

### Cara Cek Platform
Header `X-Platform: mobile` atau `web`
- employee login via web → **403** "Karyawan hanya bisa login di aplikasi mobile."
- semua user akses presensi tanpa attendance_enabled → **403** via AttendanceAccessMiddleware

### Aturan Fitur di Mobile
- **semua role** → bisa akses: struk (receipt/scan), presensi (jika attendance_enabled)
- Non-employee TIDAK bisa akses receipt di mobile

### Aturan Fitur Presensi
- Semua role bisa presensi di mobile
- TAPI harus `attendance_enabled = true` di tabel users
- HRD yang mengatur `attendance_enabled` per user (toggle via web)
- Jika `attendance_enabled = false` → 403

### WFH (Work From Home)
- Karyawan request WFH → HRD approve → bisa absen dari rumah
- HRD toggle `wfh_enabled` per user (via web)
- Saat `wfh_enabled = true`, `attendance_enabled` otomatis true
- Check-in WFH tidak validasi lokasi GPS (tanpa radius check)
- Status present/late tetap dihitung dari jam kerja perusahaan

---

## Security Rules (Keamanan Wajib)

### Receipt (Struk)
- Foto struk: **immutable** setelah upload (SHA-256 hash)
- OCR raw: `ocr_raw_amount`, `ocr_raw_merchant`, `ocr_raw_date` **TIDAK BOLEH** diupdate setelah diisi
- Karyawan hanya boleh edit: `category`, `notes`, dan `claimed_amount`
- Jika OCR gagal: karyawan boleh isi manual `claimed_amount`, `total_amount`, `receipt_date`, `vendor_name`
- Jika OCR berhasil: karyawan boleh ubah `claimed_amount` saja; `total_amount`, `receipt_date`, `vendor_name` terkunci (diisi OCR)
- Variance flag: otomatis `true` jika selisih claimed vs ocr_raw > `variance_limit` (company_settings, default 10%)
- Variance pct: `abs(claimed - ocrRaw) / ocrRaw * 100`
- Variance flag = warning di dashboard finance, TIDAK memblokir submit

### Status Flow Receipt
```
draft → submitted (submitted_at diisi) → approved / rejected
         ↑ ocr_status harus 'done' dulu
         ↑ jika ocr_status 'failed' → 400 "OCR gagal, isi data manual dulu"
         ↑ jika ocr_status 'pending' → 400 "OCR masih diproses"
```

### Activity Logs
- Semua perubahan wajib masuk `activity_logs`
- Standardized action names:
  - Receipt: `receipt_uploaded`, `receipt_updated`, `receipt_submitted`, `receipt_approved`, `receipt_rejected`
  - Invoice: `invoice_created`, `invoice_approved`, `invoice_rejected`
  - Vendor: `vendor_created`, `vendor_updated`, `vendor_activated`, `vendor_deactivated`
  - Attendance: `attendance_check_in`, `attendance_check_out`, `attendance_auto_checkout`, `attendance_toggled`, `wfh_toggled`
  - Leave: `leave_requested`, `leave_approved`, `leave_rejected`, `leave_balance_set`
  - Overtime: `overtime_approved`, `overtime_rejected`
  - Setting: `attendance_setting_created`, `attendance_setting_updated`, `attendance_setting_deleted`
  - Holiday: `holiday_created`, `holiday_deleted`

### Login
- Login gagal wajib masuk `login_attempts`
- Rate limited (via `throttle:login`): 5 attempts/menit per **email** + 120 attempts/menit per **IP** (longgar untuk NAT & CGNAT seluler). `retry_after` di-clamp maksimal 60 detik agar tidak menampilkan waktu tunggu yang tidak wajar.
- Password: min 8, max 255 karakter
- Token mobile: TANPA expiry (tetap login sampai user klik Logout). Single-session mobile: login baru di HP lain menghapus token lama.
- Token web: expired 24 jam.
- X-Platform header divalidasi (whitelist: mobile, web)

---

## Invoice Multi-Level Approval

### Approval Level (otomatis berdasarkan nominal)
| Total Amount | Max Level | Approver |
|-------------|-----------|----------|
| < Rp 10.000.000 | Level 1 | Finance Manager (finance, admin, super_admin) |
| Rp 10jt - 50jt | Level 2 | + Direksi (admin, super_admin) |
| > Rp 50.000.000 | Level 3 | + Komisaris (super_admin) |

### Alur Approval
```
store() → status=pending, current_approval_level=0
  ↓
approve() level 0 → current=1, status=pending (tunggu level berikutnya)
  ↓
approve() level 1 → current=2, status=pending (tunggu level berikutnya)
  ↓
approve() level 2 → current=3, status=approved ✅ FINAL
```
- reject() → status langsung `rejected` (tidak peduli level)
- Notifikasi otomatis ke approver level berikutnya atau pembuat invoice

### Perhitungan Invoice
```
subtotal = Σ (quantity × unit_price) per item
ppn_amount (tax_amount) = subtotal × 11%
total_amount = subtotal + ppn_amount
```

---

## API Route Map

### Public
```
POST /api/v1/login                          → AuthController@login (throttle: 5/min)
```

### Authenticated (auth:sanctum)
```
POST /api/v1/logout                         → AuthController@logout
GET  /api/v1/me                             → AuthController@me
```

### Employee (auth:sanctum + role:employee + company + receipt_access)
```
POST /api/v1/employee/receipts              → store (upload foto + OCR)
GET  /api/v1/employee/receipts              → myReceipts (list struk sendiri)
GET  /api/v1/employee/receipts/{id}         → show (detail, ownership check)
PATCH /api/v1/employee/receipts/{id}/claim  → updateClaim (edit category/notes)
POST /api/v1/employee/receipts/{id}/submit  → submit (kirim ke finance)
```

### Dashboard Finance (auth:sanctum + role:finance,admin,super_admin + company)
```
# Receipt Approval & Disbursement — KHUSUS finance,admin,super_admin (HRD dikecualikan)
GET  /api/v1/dashboard/receipts             → inbox (struk menunggu approval)
GET  /api/v1/dashboard/receipts/all         → dashboardReceipts (filter status + summary)
POST /api/v1/dashboard/receipts/bulk-approve → bulkApprove
POST /api/v1/dashboard/receipts/bulk-pay    → bulkDisburse
GET  /api/v1/dashboard/receipts/export-disbursement → exportDisbursement
GET  /api/v1/dashboard/receipts/{id}        → show (detail, no ownership check)
GET  /api/v1/dashboard/receipts/{id}/image  → image (stream foto struk)
POST /api/v1/dashboard/receipts/{id}/approve → approve
POST /api/v1/dashboard/receipts/{id}/reject  → reject
POST /api/v1/dashboard/receipts/{id}/pay    → disburse

# Vendor Management — KHUSUS finance,admin,super_admin
GET  /api/v1/dashboard/vendors              → index (list vendor perusahaan)
POST /api/v1/dashboard/vendors              → store (tambah vendor)
PATCH /api/v1/dashboard/vendors/{id}        → update
POST /api/v1/dashboard/vendors/{id}/toggle  → toggleActive

# Invoice — KHUSUS finance,admin,super_admin
GET  /api/v1/dashboard/invoices             → index (list + filter status + summary)
GET  /api/v1/dashboard/invoices/{id}        → show (detail + items + approvals)
POST /api/v1/dashboard/invoices             → store (input invoice manual)
POST /api/v1/dashboard/invoices/{id}/approve → approve (multi-level)
POST /api/v1/dashboard/invoices/{id}/reject  → reject

# Pengaturan threshold & batas klaim (Finance Rules) — KHUSUS finance,admin,super_admin
GET       /api/v1/dashboard/settings        → index
PUT/PATCH /api/v1/dashboard/settings        → update (upsert company_settings)
```

### Dashboard Shared (auth:sanctum + role:finance,hrd,admin,super_admin + company)
```
# Notifikasi
GET    /api/v1/dashboard/notifications        → index (+ unread_count)
POST   /api/v1/dashboard/notifications/read-all → markAllRead
POST   /api/v1/dashboard/notifications/{id}/read → markRead
DELETE /api/v1/dashboard/notifications/{id}   → destroy

# Audit log
GET  /api/v1/dashboard/activity-logs        → index (filter action, entity_type)
```

### Admin (auth:sanctum + role:hrd,admin,super_admin + company)
```
GET  /api/v1/admin/users                    → index (list karyawan)
POST /api/v1/admin/users                    → store (tambah user) [+ role:admin,super_admin]
PUT  /api/v1/admin/users/{id}               → update
PATCH /api/v1/admin/users/{id}/deactivate   → deactivate
PATCH /api/v1/admin/users/{id}/activate     → activate
DELETE /api/v1/admin/users/{id}             → destroy (soft delete)
```

### Attendance Dashboard (auth:sanctum + role:hrd,admin,super_admin + company)
```
GET  /api/v1/dashboard/attendance/users          → listUsers
POST /api/v1/dashboard/attendance/users/{id}/toggle-wfh    → toggleWfh
POST /api/v1/dashboard/attendance/users/{id}/toggle-radius → toggleRadius
GET  /api/v1/dashboard/attendance/leaves         → listLeaves
POST /api/v1/dashboard/attendance/leaves/{id}/approve → approveLeave
POST /api/v1/dashboard/attendance/leaves/{id}/reject  → rejectLeave
GET  /api/v1/dashboard/attendance/today          → today (dashboard hari ini)
GET  /api/v1/dashboard/attendance/summary        → monthlySummary
GET  /api/v1/dashboard/attendance/report         → reportAttendance
GET  /api/v1/dashboard/attendance/report/export  → exportReport (CSV)
GET  /api/v1/dashboard/attendance/leave-balances → listLeaveBalances
POST /api/v1/dashboard/attendance/leave-balances → setLeaveBalance
GET  /api/v1/dashboard/attendance/settings       → listSettings
POST /api/v1/dashboard/attendance/settings       → storeSettings
GET  /api/v1/dashboard/attendance/settings/{id}  → showSettings
PUT/PATCH /api/v1/dashboard/attendance/settings/{id} → updateSettings
DELETE /api/v1/dashboard/attendance/settings/{id} → destroySettings

# Kalender libur nasional / cuti bersama
GET    /api/v1/dashboard/attendance/holidays        → listHolidays (filter ?year=)
POST   /api/v1/dashboard/attendance/holidays        → storeHolidays (tambah libur nasional/khusus perusahaan)
DELETE /api/v1/dashboard/attendance/holidays/{id}   → destroyHolidays (libur nasional tidak bisa dihapus)

# Approval Lembur — HRD/admin/super_admin
GET  /api/v1/dashboard/attendance/overtime-approvals             → listOvertimeApprovals (filter status/user/tanggal)
POST /api/v1/dashboard/attendance/overtime-approvals/{id}/approve → approveOvertime (lembur dikonfirmasi)
POST /api/v1/dashboard/attendance/overtime-approvals/{id}/reject  → rejectOvertime (overtime_minutes = 0)

# Custom Shift / Scheduling — HRD/admin/super_admin (ditambah 2026-07-04)
GET    /api/v1/dashboard/attendance/shifts/roster    → roster (shift aktif karyawan hari ini; filter ?date=&attendance_setting_id=&search=)
GET    /api/v1/dashboard/attendance/shifts           → index (filter ?is_active=&attendance_setting_id=)
POST   /api/v1/dashboard/attendance/shifts           → store (template + 7 jadwal harian, wajib attendance_setting_id)
PUT/PATCH /api/v1/dashboard/attendance/shifts/{id}   → update
DELETE /api/v1/dashboard/attendance/shifts/{id}      → destroy (diblokir bila masih dipakai assignment)
GET    /api/v1/dashboard/attendance/users/{id}/shift-history → shiftHistory
POST   /api/v1/dashboard/attendance/assign-shift     → assignShift (shift_id=null → kembali ke default kantor)
POST   /api/v1/dashboard/attendance/bulk-assign      → bulkAssign (1 shift → banyak karyawan; toleran sebagian)
PUT/PATCH /api/v1/dashboard/attendance/assignments/{id} → updateAssignment
DELETE /api/v1/dashboard/attendance/assignments/{id}    → destroyAssignment
GET    /api/v1/dashboard/attendance/effective-schedule → effectiveSchedule (preview jadwal user+tanggal)
# CATATAN: /shifts/roster HARUS didefinisikan SEBELUM /shifts/{id} agar tidak tertangkap route param.
```

### Attendance Mobile (auth:sanctum + company + attendance_access)
```
POST /api/v1/attendance/check-in            → checkIn (WFH only; response: reminder_at & auto_checkout_at)
POST /api/v1/attendance/check-out           → checkOut (hitung work_minutes & overtime_minutes; buat overtime_approval jika ada lembur)
```

### Status, Riwayat, Leave & Lembur (auth:sanctum + company, tanpa gerbang attendance_access)
```
GET  /api/v1/attendance/status              → checkStatus (status presensi hari ini, flag wfh_enabled live + scheduled_auto_checkout_at)
GET  /api/v1/attendance/my                  → myAttendance (riwayat presensi user yang login + flag wfh_enabled)
GET  /api/v1/attendance/leave-balance       → myLeaveBalance (saldo cuti karyawan)
POST /api/v1/attendance/leave-request       → requestLeave (total_days = HARI KERJA saja, lewati weekend/libur)
GET  /api/v1/attendance/leave-preview       → leavePreview (hitungan hari efektif + skipped_dates berlabel utk badge mobile; ?start_date=&end_date=)
GET  /api/v1/attendance/holidays            → listHolidays (read-only, untuk kalender mobile)
GET  /api/v1/attendance/my-overtime         → myOvertimeApprovals (riwayat status lembur karyawan)
POST /api/v1/attendance/fcm-token           → registerFcmToken (simpan FCM token device untuk push notif)
GET  /api/v1/attendance/shift-updates       → shiftUpdates (notifikasi shift terbaru belum dibaca, untuk banner Flutter)
POST /api/v1/attendance/dismiss-shift-update → dismissShiftUpdate (tandai notifikasi shift sudah dibaca)
GET  /api/v1/attendance/my-schedule-calendar → myScheduleCalendar (kalender jadwal bulanan per-hari; ?month=&year=)
```

---

## Middleware Aliases (bootstrap/app.php)
| Alias | Class | Fungsi |
|-------|-------|--------|
| `role` | RoleMiddleware | Cek role user di whitelist parameter |
| `company` | CompanyMiddleware | Isolasi data per company_id (super_admin bypass) |
| `receipt_access` | ReceiptAccessMiddleware | Hanya employee boleh akses receipt |
| `attendance_access` | AttendanceAccessMiddleware | Hanya attendance_enabled=true boleh presensi |

---

## Environment Variables (.env)
```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=expenseflow_db
DB_USERNAME=root
DB_PASSWORD=

QUEUE_CONNECTION=database
FILESYSTEM_DISK=local

OCR_DRIVER=tesseract
TESSERACT_PATH="C:/Program Files/Tesseract-OCR/tesseract.exe"
```

---

## OCR Pipeline
```
Employee upload foto (store)
  → SHA-256 hash (immutable)
  → Simpan ke storage/app/private/receipts/
  → Dispatch ProcessOcrJob (queue: database)
  → ProcessOcrJob::handle()
    → OcrService::analyze() → pilih driver dari config/ocr.php
      → TesseractDriver ATAU GoogleVisionDriver
      → ParsesOcrText trait (extractAmount, extractMerchant, extractDate)
    → Simpan ocr_raw_amount, ocr_raw_merchant, ocr_raw_date (immutable)
    → Pre-fill claimed_amount jika kosong
    → recalculateVariance() → variance_flag & variance_pct
    → Catat ke activity_logs: ocr_completed / ocr_failed
  → Jika gagal 3x retry → masuk failed_jobs
```

---

## Presensi (Attendance) Pipeline
```
HRD toggle wfh_enabled per user (via web)
  → attendance_enabled otomatis sinkron

Karyawan WFH check-in (via mobile)
  → Validasi: wfh_enabled harus true
  → Cegah double check-in
  → Status: present / late (dari jam kerja perusahaan)
  → check_in_type = 'wfh' (tanpa validasi radius)
  → Response menyertakan reminder_at & auto_checkout_at untuk Flutter scheduling notif lokal

Karyawan check-out (via mobile)
  → Harus sudah check-in hari ini
  → check_out_type ikut mode check-in
  → Hitung work_minutes (check_in → check_out)
  → Hitung overtime_minutes otomatis (lihat Pipeline Lembur & Libur)
  → Simpan is_holiday (true jika weekend/libur)
  → Jika overtime_minutes > 0 → buat overtime_approval (pending) + notifikasi HRD

Sistem Auto-Checkout (scheduler setiap 5 menit via attendance:auto-checkout)
  → Cari attendance yang check-in tapi belum check-out
  → Jika waktu ≥ work_end_time + checkout_reminder_minutes → kirim FCM reminder
  → Jika waktu ≥ work_end_time + auto_checkout_grace_minutes → auto-checkout
  → is_auto_checkout = true, auto_checkout_at = waktu sistem checkout
  → Buat overtime_approval (pending, is_auto_checkout=true) jika ada lembur

HRD Dashboard
  → today(): rekap presensi hari ini (checked_in / not_checked_in / on_leave)
  → reportAttendance(): filter by date, department, status, type (+ total_overtime_minutes)
  → exportReport(): download CSV (kolom Lembur & Hari Libur)
  → monthlySummary(): rekap bulanan per karyawan + total lembur (fondasi payroll)
  → listOvertimeApprovals(): daftar pengajuan lembur (filter status/user/tanggal)
  → approveOvertime(): setujui lembur (overtime_minutes dikonfirmasi ke payroll)
  → rejectOvertime(): tolak lembur (overtime_minutes di-reset ke 0)
```

## Pipeline Lembur & Approval Lembur (overtime_approvals)
```
Setiap lembur WAJIB persetujuan HRD sebelum masuk hitungan payroll:

Tabel overtime_approvals
  → status: pending / approved / rejected
  → is_auto_checkout: true jika dibuat oleh sistem auto-checkout

Alur approval:
  checkout (manual/auto) → overtime_minutes > 0
    → buat overtime_approval (status: pending)
    → notifikasi HRD (DB notifications + FCM ke HRD jika ada fcm_token)
  HRD approve
    → status = approved
    → overtime_minutes di attendances TETAP (sudah dikonfirmasi)
    → notifikasi karyawan (DB + FCM)
  HRD reject
    → status = rejected
    → overtime_minutes di attendances = 0 (tidak masuk payroll)
    → notifikasi karyawan (DB + FCM)

monthlySummary() sudah menjumlahkan overtime_minutes dari attendances.
Setelah HRD reject, overtime_minutes=0 → tidak terhitung di payroll.
```

## Pipeline Lembur & Libur (overtime + holidays)
```
Kalender libur (tabel holidays)
  → company_id NULL = libur nasional (berlaku semua company), is_national=true
  → company_id terisi = cuti bersama / libur khusus perusahaan (HRD tambah via web)
  → Libur nasional di-seed: HolidaySeeder (libur 2026). HRD TIDAK bisa hapus libur nasional.

Lembur OTOMATIS saat check-out (per kantor: overtime_enabled, min_overtime_minutes)
  → overtime_enabled=false                 → overtime_minutes = 0
  → hari libur/weekend (is_holiday=true)    → overtime_minutes = SELURUH work_minutes
  → hari kerja, check-out > work_end_time   → overtime_minutes = menit lewat jam pulang,
                                              hanya jika ≥ min_overtime_minutes (default 30)
  → Acuan jam kerja = kantor pertama perusahaan (sama seperti determineStatus)

total_days cuti = HARI KERJA saja
  → requestLeave() pakai countWorkingDays(): lewati Sabtu/Minggu & tanggal di holidays
  → Rentang yang seluruhnya weekend/libur → 422 (tidak ada hari kerja)
```

## Pengaturan Auto-Checkout (attendance_settings)
| Kolom | Default | Keterangan |
|---|---|---|
| `checkout_reminder_minutes` | 30 | Menit setelah work_end_time → kirim reminder FCM |
| `auto_checkout_grace_minutes` | 60 | Menit setelah work_end_time → auto-checkout sistem |

HRD bisa ubah per kantor via `PUT /api/v1/dashboard/attendance/settings/{id}`.

---

## Saldo Cuti & Reset Tahunan per Kantor (2026-08-25)
Kuota cuti karyawan tidak lagi hardcoded 12 hari — dikendalikan **per kantor** di form
Tambah/Edit Kantor (Pengaturan → Kantor Presensi):

| Kolom `attendance_settings` | Format | Keterangan |
|---|---|---|
| `default_leave_quota` | int, default 12 | Kuota REFERENSI per kantor — tampil di tab Saldo Cuti sebagai panduan HRD (`office_default_quota`) & dipakai saat reset tahunan. TIDAK lagi otomatis diberikan ke karyawan. |
| `leave_reset_date` | 'MM-DD' nullable (tanpa tahun) | Tanggal anniversary reset otomatis, ulang tiap tahun. NULL = mati. |
| `last_leave_reset_on` | date nullable | Penanda anniversary terakhir yang sudah diproses (anti dobel + catch-up). |

### Kebijakan Saldo Cuti Non-Aktif Default (UPDATE 2026-08-25)
- **Karyawan baru / yang belum punya baris saldo → cuti NON-AKTIF.** Semua auto-create
  `LeaveBalance::firstOrCreate(..., 'cuti', ...)` (myLeaveBalance, requestLeave,
  approveLeave, respondCollectiveLeave, listCollectiveLeaves, reapply helper) kini membuat
  baris dengan **`quota = 0`**. Konvensi: **`quota = 0` = non-aktif**.
- **Aktivasi hanya oleh HRD manual** via tab Saldo Cuti (`POST /dashboard/attendance/leave-balances`
  → `setLeaveBalance`, isi kuota > 0). Tidak ada endpoint/flag tambahan.
- Penegakan di `requestLeave()`: **cek cukup `quota <= 0`** (UPDATE 2026-08-28 — kondisi lama
  `quota=0 && used=0` salah karena karyawan dgn quota=0 & used>0 bisa lolos). Pesan selalu
  *"belum diaktifkan oleh HRD"* bila quota ≤ 0, berapapun nilai used. Saldo habis (quota>0,
  remaining=0) tetap menghasilkan pesan *"saldo habis/tidak cukup"* dari cek remaining di bawahnya.
- **Cuti melintasi tanggal reset — Anniversary Split (2026-08-25):** pengajuan/approval cuti
  yang rentangnya MELEWATI `leave_reset_date` kantor divalidasi DUA alokasi:
  hari kerja SEBELUM tanggal reset dibatasi sisa saldo berjalan (`quota - used`);
  hari PADA/SETELAH reset dibatasi kuota baru (`default_leave_quota`, karena reset menset used=0).
  Contoh: reset 10 Juni, sisa 2 hari, ajukan 8–11 Juni → 2 hari sebelum vs sisa lama ✓,
  2 hari sesudah vs kuota baru ✓ → diperbolehkan. Implementasi:
  helper `splitLeaveAroundReset()` + `workingDatesBetween()` di AttendanceController;
  dipakai requestLeave() & approveLeave(). Deduksi tetap penuh ke saldo berjalan — sisa bisa
  tampil minus sesaat sampai anniversary me-reset used=0 (konsisten secara akhir).
  Rentang tanpa pivot reset → perilaku lama (cek total vs sisa).
- `listLeaveBalances()` menampilkan baris belum-dibuat sebagai non-aktif (quota 0) +
  field referensi `office_default_quota` dan flag `active` — tidak ada lagi fallback hardcoded 12.
- Reset otomatis: command `attendance:reset-leave-balances` (scheduler harian 00:03,
  `routes/console.php`). Bila tanggal anniversary sudah/sedang tiba dan belum diproses,
  saldo cuti TAHUN BERJALAN hanya karyawan dengan baris AKTIF (quota > 0) dibuat-ulang:
  `quota = default_leave_quota`, `used = 0`. **Reset tidak mengaktifkan saldo yang belum
  pernah diaktifkan HRD** (quota 0 / tanpa baris dilewati). Baris tahun lama dibiarkan untuk riwayat.
- Server mati beberapa hari → reset tetap dieksekusi sekali saat menyala lagi (catch-up
  via `last_leave_reset_on`); tidak pernah dobel.
- HRD menghapus jadwal reset (null) → `last_leave_reset_on` ikut di-null agar jadwal baru
  nantinya bisa langsung diproses.

---

## Leave (Cuti/Izin) Pipeline
```
Karyawan requestLeave (via mobile, tanpa gerbang attendance_access)
  → leave_type: wfh / izin / sakit / cuti
  → Hitung total_days
  → Status: pending
  → Notifikasi ke semua HRD/admin perusahaan
```

> **KEBIJAKAN EFEKTIF-HARI (2026-08-26):** `requestLeave()` TIDAK lagi menolak pengajuan bila ada tanggal libur/off-day di tengah rentang (hard-reject off-day shift & overlap rentang penuh DIHAPUS). Pengajuan tetap terkirim ke dashboard HRD dengan `start_date`/`end_date` ASLI; `total_days` hanya menghitung tanggal efektif setelah skip:
> 1. Libur nasional / perusahaan / **cabang** (`officeId` kini dikirim ke `workingDatesBetween()` — sebelumnya null sehingga libur cabang bocor)
> 2. Cuti bersama yang di-*accept* karyawan (pending/declined tetap hari kerja — semantik mapan 2026-08-17)
> 3. Cuti pribadi sendiri yang sudah diajukan (pending/approved, skip PER-TANGGAL via query `whereNull('holiday_id')`; rejected tidak di-skip) — anti doble pengajuan
> 4. Libur mingguan kantor default (`work_days`)
> 5. Off-day shift efektif (`resolveOffDatesForUser`, versi jadwal berlaku per tanggal)
>
> Contoh: ajukan 2–5 Agustus, tanggal 3 libur → tersimpan `total_days = 3`. Semua tanggal non-efektif dilaporkan di response `skipped_dates[]` (`{date, reason: holiday_or_off_day|already_requested, detail}`). Ditolak 422 hanya jika tidak ada satu pun hari efektif. `splitLeaveAroundReset()` menerima parameter optional `$effectiveDates` agar `days_before + days_after == total_days`.

```
HRD approveLeave (via web)
  → Cek saldo leave_balances (untuk cuti/sakit, default 12 hari/tahun)
  → Potong saldo jika cukup
  → Status: approved
  → Notifikasi ke karyawan

HRD rejectLeave (via web)
  → Wajib isi rejection_reason
  → Status: rejected
  → Notifikasi ke karyawan
```

---

## Penting untuk Agent
- Selalu gunakan **Laravel 11 syntax**
- Selalu catat aktivitas ke `activity_logs` dengan standardized action names
- Selalu return **JSON response**
- Bahasa komentar kode: **Bahasa Indonesia**
- Gunakan `Storage::disk('local')->path()` untuk akses file (JANGAN `storage_path()`)
- OCR fields (`ocr_raw_*`) immutable — hanya boleh diisi sekali via ProcessOcrJob
- Variance formula: `abs(claimed - ocrRaw) / ocrRaw * 100`, flag jika > 10%
- Invoice PPN: `subtotal * 0.11`
- Semua route dashboard sudah include `super_admin` di middleware role
- Login rate limit: 5 attempts/menit/email + 120 attempts/menit/IP via `throttle:login` (retry_after di-clamp ≤ 60s)
- Token mobile tanpa expiry; web 24 jam

---

# Roadmap & Analisis Kekurangan (Perspektif Manajemen)

Catatan ini ditulis dari sudut pandang **manajer perusahaan** (finance/operasional/HR):
fitur apa yang masih kurang dan menimbulkan **risiko bisnis**, **kebocoran anggaran**, atau **kehilangan nilai**.
Saat ini aplikasi kuat di sisi *input & approval*, tapi lemah di sisi **siklus uang keluar (pencairan dana/pembayaran)**, **kontrol limit anggaran**, dan **efisiensi verifikasi finance**.

> 🎯 **FOKUS SPRINT SAAT INI: SISTEM STRUK (Reimbursement & Klaim Karyawan)**  
> Seluruh prioritas di bawah ini difokuskan penuh untuk menyempurnakan siklus hidup struk: dari pengunggahan mobile, verifikasi anti-fraud, penegakan batas anggaran, approval masal di dashboard finance, hingga pencairan transfer bank ke karyawan.  
> *(Untuk sistem Invoice Vendor dipisahkan ke roadmap tahap berikutnya agar sprint saat ini terarah).*

---

## Tabel Prioritas Sistem Struk (Klaim & Reimbursement)

| Prioritas | Fitur & Modul | Risiko Bisnis / Kenapa Penting | Kebutuhan Teknis & Dampak |
|---|---|---|---|
| **P0 — Kritis** | **Pelacakan Pencairan Dana (Disbursement Flow: `approved` → `paid`)** | Setelah struk `approved`, **tidak ada status "dibayar"** di sistem. Finance tidak tahu mana yang sudah ditransfer ke karyawan dan mana yang belum → **risiko dobel transfer atau klaim karyawan tidak terbayar**. | • Status baru: `paid` (lunas dicairkan)<br>• Tabel / kolom pencairan: `paid_at`, `paid_by`, `payment_method`, `payment_ref_no`, `payment_proof_path`<br>• Tab / filter baru di web: "Menunggu Pembayaran" & "Sudah Dibayar" |
| **P0 — Kritis** | **Data Rekening Bank Karyawan (Employee Bank Account)** | Finance harus mentransfer uang reimbursement ke karyawan, namun profil karyawan saat ini **tidak memiliki data rekening bank** tujuan transfer. | • Kolom di `users`: `bank_name`, `bank_account_no`, `bank_account_holder`<br>• Form edit profil karyawan di Web HRD & Mobile |
| **P0 — Kritis** | **Penegakan Batas Anggaran Klaim (Enforce Claim & Monthly Budget Limit)** | Kolom `monthly_claim_limit` (user) & `max_claim_limit` (setting) sudah ada di database & UI setting tapi **belum divalidasi di backend**. Karyawan bisa submit klaim bernominal tak terbatas → **anggaran bocor**. | • Validasi backend saat `store()` & `submit()`:<br>1. Nominal per struk ≤ `max_claim_limit`<br>2. Akumulasi bulan berjalan (`submitted` + `approved` + `paid`) ≤ `monthly_claim_limit`<br>• Pesan error HTTP 422 informatif |
| **P0 — Kritis** | **Deteksi Struk Duplikat Cerdas (Anti-Fraud: Multi-Angle Scan)** | SHA-256 hanya mendeteksi file foto yang persis sama. Jika karyawan memfoto ulang struk fisik yang sama dari sudut berbeda, hash berubah sehingga lolos → **rawan klaim ganda disengaja**. | • Cek duplikasi berbasis atribut OCR & metadata:<br>`(company_id, vendor_name/ocr_merchant, total_amount, receipt_date)`<br>• Flag peringatan "Potensi Duplikat" di dashboard Finance |
| **P0 — Kritis** | **Approval Penyesuaian Nominal oleh Finance (Partial / Adjusted Amount Approval)** | Saat ini Finance hanya bisa Full Approve atau Full Reject. Jika karyawan klaim Rp 120.000 tapi kebijakan kantor hanya menanggung Rp 100.000, Finance terpaksa tolak total dan minta foto ulang. | • Kolom `approved_amount` di `receipts`<br>• Form approval di modal finance: input nominal yang disetujui + catatan penyesuaian |
| **P1 — Tinggi** | **Approval Masal Struk (Bulk / Batch Approval Web)** | Finance harus mengklik dan membuka modal satu per satu untuk ratusan struk. Ini sangat lambat untuk klaim rutin bervolume tinggi. | • Checkbox multi-select di `ReceiptInbox.tsx`<br>• Tombol "Setujui X Struk Terpilih" (terutama untuk struk clean OCR / tanpa variance flag)<br>• Endpoint `POST /dashboard/receipts/bulk-approve` |
| **P1 — Tinggi** | **Ekspor Rekap Transfer Bank Massal (Disbursement Batch Export)** | Finance/Kasir harus mengetik nomor rekening dan nominal transfer satu per satu di Internet Banking. | • Fitur ekspor CSV/Excel daftar transfer siap upload untuk internet banking (BCA KlikBisnis, Mandiri MCM, BRI CMS, BNI Direct)<br>• Mengelompokkan total reimbursement per karyawan |
| **P1 — Tinggi** | **Validasi Kedaluwarsa Usia Struk (Receipt Age / Claim Cut-off)** | Karyawan bisa mengklaim struk belanja beberapa bulan / tahun lalu yang sudah tutup buku fiskal. | • Setting batas maksimal usia struk (misal: max 30 hari / 60 hari dari tanggal transaksi)<br>• Validasi otomatis saat upload / submit |
| **P1 — Tinggi** | **Eskalasi SLA & Push Notifikasi FCM Status Struk** | Struk yang diajukan mengendap lama tanpa review; karyawan tidak tahu kapan klaimnya disetujui / dibayar tanpa sering cek manual. | • Auto-reminder ke Finance untuk struk pending > 3 hari<br>• Push Notifikasi FCM ke HP karyawan saat status struk: `approved`, `rejected` (beserta alasan), dan `paid` (dana telah ditransfer) |
| **P2 — Menengah** | **Kategori Pengeluaran Dinamis & Plafon per Kategori (Expense Categories & Policy Rules)** | Kategori saat ini masih hardcoded teks statis. Perusahaan tidak bisa menambah kategori baru (mis. Parkir, Medis, Hotel) atau menetapkan plafon khusus per kategori. | • Tabel `expense_categories` (company_id, name, max_limit, requires_notes, is_active)<br>• Manajemen kategori pengeluaran di Web Dashboard Settings |
| **P2 — Menengah** | **Ekspor Laporan & Rekap Klaim Lengkap (Excel / PDF Summary)** | Finance kesulitan membuat laporan pertanggungjawaban bulanan untuk direksi atau audit pajak. | • Ekspor spreadsheet rekap pengeluaran dengan filter tanggal, departemen, karyawan, kategori, dan status bayar<br>• Download paket arsip foto struk digital |
| **P2 — Menengah** | **Multi-Item / Rincian Belanja Struk (Itemized Expenses Split)** | OCR sudah membaca `ocr_raw_items`, namun data disimpan dalam satu nominal global. Karyawan tidak bisa memecah struk yang berisi belanja campuran (mis. ATK + konsumsi kantor). | • Tabel `receipt_items` (receipt_id, item_name, quantity, amount, category)<br>• Alokasi biaya multi-kategori dari 1 struk |
| **P2 — Menengah** | **Dashboard Analitik Pengeluaran Klaim Karyawan** | Manajemen tidak memiliki visibilitas pola pengeluaran operasional karyawan. | • Grafik tren pengeluaran bulanan, breakdown per kategori, top spenders, dan realisasi budget per departemen |
| **P3 — Rendah** | **Multi-Scan / Batch Upload Foto Struk di Mobile (Flutter)** | Karyawan yang sering dinas luar repot mengunggah struk satu per satu. | • Fitur scan beberapa struk beruntun (3-5 struk) dalam satu sesi di mobile Flutter |
| **P3 — Rendah** | **Delegasi Approver Struk (Approval Delegation)** | Saat PIC finance cuti, verifikasi struk terhenti. | • Tabel `approval_delegations` untuk melimpahkan wewenang approval sementara ke staf finance pengganti |
| **P3 — Rendah** | **Opsi Pencairan Terintegrasi ke Slip Gaji (Reimbursement on Payroll)** | Perusahaan yang mencairkan reimbursement bersamaan dengan gaji bulanan harus menyalin data manual. | • Opsi "Cairkan via Payroll": nominal struk `approved` otomatis masuk sebagai komponen reimbursement pada rekap gaji bulanan karyawan |

---

## Roadmap Sistem Invoice Vendor (Ditunda — Tahap Berikutnya)

> ⏸️ **Status:** Ditunda sementara waktu agar tim fokus 100% menyelesaikan dan mematangkan Sistem Struk (Klaim Reimbursement Karyawan) di atas.

| Prioritas | Fitur Invoice | Keterangan |
|---|---|---|
| **P1** | **Pelacakan Pembayaran Invoice (`approved` → `paid`)** | Pencatatan status pembayaran transfer ke rekening vendor (`vendors.bank_*`) beserta nomor referensi & bukti transfer. |
| **P1** | **Deteksi Duplikasi Invoice Vendor** | Pengecekan nomor invoice ganda per vendor dan kombinasi data `(vendor_id + total_amount + invoice_date)`. |
| **P2** | **OCR Scanner untuk Invoice Vendor** | Ekstraksi otomatis nomor invoice, subtotal, PPN, dan baris item dari scan file PDF/gambar invoice vendor. |
| **P2** | **Purchase Order (PO) 3-Way Matching** | Siklus pengadaan terstruktur: PO → Goods Receipt (GR) → Invoice Matching untuk mencegah tagihan di luar pesanan. |
| **P3** | **Pembayaran Parsial & Termin Invoice** | Dukungan pembayaran bertahap (down payment / termin 1, 2, 3) untuk vendor proyek besar. |

---

## Catatan Desain Teknis Sistem Struk (Receipt Deep-Dive)

### 1. Siklus Pembayaran & Pencairan Reimbursement (Disbursement Lifecycle)
```
draft → submitted → approved → paid (lunas ditransfer)
         ↓             ↓
      rejected      rejected
```
- **Penambahan Status di Tabel `receipts`**:
  - Kolom `status`: enum(`'draft'`, `'submitted'`, `'approved'`, `'rejected'`, `'paid'`).
  - Kolom pencairan dana pada tabel `receipts`:
    - `paid_at` (timestamp tanggal transfer)
    - `paid_by` (user_id finance/admin yang memproses)
    - `payment_method` (enum: `'bank_transfer'`, `'cash'`, `'payroll'`)
    - `payment_ref_no` (nomor referensi / nomor transaksi bank)
    - `payment_proof_path` (file bukti transfer, opsional)
- **Data Rekening Karyawan (Tabel `users`)**:
  - `bank_name` (misal: BCA, Mandiri, BRI, BNI)
  - `bank_account_no` (nomor rekening karyawan)
  - `bank_account_holder` (nama pemilik rekening sesuai buku tabungan)

### 2. Enforce Batas Klaim & Anggaran (Budget Enforcement Rules)
Saat karyawan memanggil `store()` dan `submit()` struk:
1. **Plafon per Transaksi**: Validasi `claimed_amount <= company_settings.max_claim_limit` (jika diatur).
2. **Plafon Bulanan Karyawan**:
   ```php
   $currentMonthSpend = Receipt::where('user_id', $user->id)
       ->whereIn('status', ['submitted', 'approved', 'paid'])
       ->whereMonth('created_at', now()->month)
       ->whereYear('created_at', now()->year)
       ->where('id', '!=', $receipt->id)
       ->sum('claimed_amount');

   $limit = $user->monthly_claim_limit ?? $companySetting->monthly_claim_limit;
   if ($limit > 0 && ($currentMonthSpend + $receipt->claimed_amount) > $limit) {
       return response()->json([
           'message' => 'Pengajuan klaim melebihi batas anggaran bulanan Anda (Sisa kuota anggaran: Rp ' . number_format($limit - $currentMonthSpend, 0, ',', '.') . ').',
           'current_spend' => $currentMonthSpend,
           'limit' => $limit,
       ], 422);
   }
   ```

### 3. Mesin Anti-Fraud Deteksi Duplikat Struk
Deteksi duplikasi bekerja dalam 2 lapis:
1. **Lapis 1 (Binary Exact)**: Cek kolom `sha256_hash` pada file gambar (mencegah upload ulang file gambar yang identik).
2. **Lapis 2 (Attribute Heuristic)**: Jika hash berbeda (misal karyawan memfoto ulang struk dari sudut lain), sistem mengecek kemiripan data teks OCR & tanggal:
   ```php
   $potentialDuplicate = Receipt::where('company_id', $companyId)
       ->where('id', '!=', $receipt->id)
       ->where('status', '!=', 'rejected')
       ->where('total_amount', $receipt->total_amount)
       ->where('receipt_date', $receipt->receipt_date)
       ->where(function($q) use ($receipt) {
           $q->where('vendor_name', 'like', '%' . $receipt->vendor_name . '%')
             ->orWhere('ocr_raw_merchant', 'like', '%' . $receipt->ocr_raw_merchant . '%');
       })
       ->first();
   ```
   Jika ditemukan kemiripan, sistem menandai flag `is_potential_duplicate = true` dan `duplicate_reference_id` sehingga tampil badge peringatan kuning mencolok di dashboard review Finance.

### 4. Penyesuaian Nominal oleh Finance (Adjusted / Partial Approval)
- Saat review, Finance dapat mengubah nilai `approved_amount` (default sama dengan `claimed_amount`).
- Jika `approved_amount < claimed_amount`, Finance wajib mengisi catatan alasan penyesuaian (misal: "Biaya parkir Rp 20.000 tidak di-reimburse sesuai kebijakan kantor").
- Karyawan menerima notifikasi bahwa klaim disetujui sebagian dengan rincian nominal dan alasan.

### 5. Format Ekspor Transfer Massal Bank (Batch Disbursement)
Ekspor otomatis mengelompokkan beberapa struk `approved` milik karyawan yang sama menjadi satu baris transfer akumulatif:
```
No | Kode Karyawan | Nama Karyawan | Bank | No Rekening | Nama Pemilik Rekening | Total Transfer | Daftar No Struk
1  | EMP-001       | Budi Santoso  | BCA  | 1234567890  | Budi Santoso          | Rp 450.000     | RCP-20260831-0001, RCP-20260831-0004
```

> Urutan implementasi yang disarankan: **P0 → P1 → P2 → P3**. Mulai dari siklus
> pembayaran & enforce anggaran karena keduanya berdampak langsung ke uang perusahaan.
### fiture depannya

- buat web site terpisa untuk calon rekrutmen kerja.
  → ✅ SELESAI 2026-08-28 (`expenseflow-public` — Static HTML, JS, CSS, Tailwind CDN)
- buat form untuk hrd untuk kualifikasi calon karyawan 
  → ✅ SELESAI 2026-08-28 (Tab Form Rekrutmen di `expenseflow-web` via `RecruitmentManagement.tsx`)
- pada syarat dan kualifikasi buatkan pilihan user ingin input list atau teks bebas
  → ✅ SELESAI 2026-08-29 (Mode List butir interaktif + Mode Teks Bebas dengan reorder up/down, Enter shortcut, auto-split paste & quick recommendation chips di `RecruitmentManagement.tsx`)
- kuota pelamar (`max_applicants`) jangan ditampilkan ke publik di portal karir (tetap berfungsi sebagai pembatas otomatis di backend/HRD)
  → ✅ SELESAI 2026-08-29 (Dihapus dari kartu lowongan di `jobs.js` dan halaman rincian `detail.html` / `apply.js`)
- list / halaman untuk hrd untuk menyeleksi calon karyawan yang masuk sesuai kualifikasi 
  → ✅ SELESAI 2026-08-28 (Tab Pelamar & Detail Seleksi di `expenseflow-web` via `RecruitmentManagement.tsx`)
- penyempurnaan UI kalender jadwal shift & detail hari pada mobile (`jadwal_shift_screen.dart`): banner atas khusus nama jadwal shift / jam kantor default; detail hari fokus ke jam kerja (badge shift malam/lintas hari), atau 1 kartu tunggal untuk Libur Nasional (dengan nama hari libur), Libur Perusahaan/Cabang, Cuti Bersama, Cuti Mandiri, atau Hari Libur (OFF) tanpa tumpang-tindih banner.
  → ✅ SELESAI 2026-08-29 (`expenseflow-mobile/lib/screens/jadwal_shift_screen.dart`)
- pemisahan eksplisit Cuti Bersama (`is_collective = true`) dengan Libur Cabang / Perusahaan biasa pada mobile: Cuti Bersama kini diprioritaskan dan ditampilkan dengan kartu Cuti Bersama (Badge Amber) + nama cuti bersama, bukan Libur Cabang.
  → ✅ SELESAI 2026-08-29 (`expenseflow-mobile/lib/screens/jadwal_shift_screen.dart`)
- subtitle rentang tanggal pada banner shift atas (`_buildShiftInfoCard`) hanya ditampilkan jika shift memiliki tanggal berakhir (`end_date != null`, format: `tanggal mulai - tanggal akhir`). Jika shift unlimited / tanpa batas waktu atau jam kantor default, subtitle tanggal disembunyikan.
  → ✅ SELESAI 2026-08-29 (`expenseflow-backend`, `expenseflow-mobile`)
- penanganan Cuti Bersama di dashboard HRD (`AttendanceManagement.tsx` & `AttendanceController.php`):
  - Form input pengecualian murni menampung pengecualian manual yang dipilih HRD (tidak menjejalkan user cuti nonaktif sebelum disimpan).
  - Saat HRD menekan tombol Simpan pada form Cuti Bersama, sistem memanggil API `POST /dashboard/attendance/holidays/collective-preview` dan memunculkan modal konfirmasi pratinjau payload yang merincikan:
    - Statistik peserta yang diikutsertakan (`total_eligible`) vs yang tidak ikut (`total_excluded`).
    - Daftar lengkap nama karyawan yang dikecualikan/tidak ikut beserta **badge alasan jelas**:
      1. `Cuti Nonaktif`: Kuota cuti tahunan belum diaktifkan oleh HRD (`quota <= 0`).
      2. `Sisa Kuota Habis`: Karyawan sudah kehabisan kuota cuti tahunan (`remaining <= 0`).
      3. `Sudah Cuti/Izin/Sakit`: Karyawan sudah memiliki pengajuan cuti mandiri/izin/sakit yang berstatus approved pada tanggal tersebut.
      4. `Libur Shift (OFF)`: Hari tersebut merupakan hari libur menurut jadwal shift kerja karyawan.
      5. `Pengecualian Manual`: Karyawan yang sengaja dipilih untuk dikecualikan oleh HRD pada form.
    - Accordion daftar karyawan yang diikutsertakan beserta sisa kuota cuti masing-masing.
  - Setelah HRD mengonfirmasi dan Cuti Bersama tersimpan, backend otomatis mengecualikan user-user tersebut secara permanen ke tabel `holiday_exclusions` sehingga mereka tidak akan menerima pesan/banner cuti bersama di mobile dan saldo cutinya tidak terpotong.
  - **Pemisahan Eksplisit Pengecualian Manual HRD vs Pengecualian Otomatis Sistem (Permanen)**:
    - Karyawan yang berstatus **Pengecualian Manual HRD** (karyawan yang memenuhi seluruh syarat seperti kuota aktif, hari kerja shift, dan tidak sedang cuti lain namun sengaja dikecualikan oleh HRD): pada form Ubah Libur, HRD dapat mengembalikan karyawan tersebut agar **kembali mengikuti cuti bersama** dengan mengeklik tombol `X` pada chip namanya lalu menyimpan perubahan. Backend akan mendeteksi karyawan yang eligible, melepaskan dari `holiday_exclusions`, dan membuatkan pengajuan `LeaveRequest` cuti bersama baru.
    - **Pengembalian Saldo Cuti Jika Karyawan Dikecualikan Setelah Terlanjur Ikut (`accepted`)**: Jika sebelumnya karyawan sudah memilih "Ikut" cuti bersama dan saldonya telah terpotong 1 hari, lalu HRD mengubah keputusan dan mengecualikan karyawan tersebut pada form Ubah Libur:
      - Sistem **otomatis mengembalikan saldo cutinya (+1 hari)** ke akun karyawan (`LeaveBalance.used` dikurangi).
      - Menghapus `LeaveRequest` cuti bersama karyawan tersebut dan memasukkan karyawan ke daftar pengecualian (`holiday_exclusions`).
      - Mengirimkan notifikasi ke aplikasi mobile karyawan bahwa jadwal cuti bersama dibatalkan oleh HRD, saldo cuti dikembalikan, dan karyawan dijadwalkan masuk kerja normal.
    - Karyawan yang berstatus **Pengecualian Otomatis Sistem** (karena cuti nonaktif `quota <= 0`, sisa saldo habis `remaining <= 0`, sudah ada cuti mandiri/izin/sakit approved, atau libur shift): bersifat **permanen terkunci** dan ditampilkan di kolom sederhana berlabel *"Pengecualian Otomatis"* tanpa tombol `X` karena tidak memenuhi syarat untuk mengikuti cuti bersama.
    - Pada kartu kalender dan detail hari libur, setiap karyawan yang dikecualikan diberi pembeda badge yang jelas: `(Manual)` vs `(Cuti Nonaktif / Auto)`.
  - Menu dropdown pilihan cakupan **"Semua Kantor (Semua Cabang)"** pada form Cuti Bersama & Libur Perusahaan hanya dapat diakses dan disimpan oleh **Super Admin**. HRD / Admin cabang hanya dapat mengatur libur/cuti bersama untuk cabang kantor spesifik.
  - **Perubahan Tipe dari Cuti Bersama ke Libur Perusahaan / Nasional**: Ketika HRD mengubah tipe libur dari Cuti Bersama menjadi Libur Perusahaan biasa atau Libur Nasional, seluruh exclusion otomatis Cuti Bersama (seperti karyawan dengan kuota cuti 0 / nonaktif) otomatis dibersihkan dari `holiday_exclusions` dan hanya pengecualian manual HRD yang dipertahankan. Seluruh karyawan (termasuk yang cutinya belum aktif) otomatis diikutsertakan dalam Libur Perusahaan / Nasional tanpa pengurangan saldo.
  - **Pemberitahuan Pembatalan Cuti Bersama & Cuti Mandiri di Mobile**:
    - Ketika Cuti Bersama dibatalkan / dihapus oleh HRD (atau ketika karyawan dikecualikan oleh HRD setelah terlanjur ikut `accepted`), serta ketika pengajuan cuti mandiri dibatalkan/dikompensasi karena adanya hari libur baru atau ditolak oleh HRD:
      - Sistem mengirimkan pemberitahuan ke aplikasi mobile karyawan (`notifications` table).
      - Pada mobile screen (menu Icon Surat / Pesan & Notifikasi), kartu pemberitahuan pembatalan tampil dengan judul, tanggal, deskripsi *"Cuti bersama [Nama Libur] pada [Tanggal] telah dibatalkan oleh HRD. Saldo cuti Anda telah dikembalikan."* (atau untuk cuti mandiri), dan dilengkapi tombol **"Mengerti"**.
      - Menekan tombol **"Mengerti"** akan menandai notifikasi sebagai dibaca (`POST /attendance/dismiss-cancellation/{id}`) dan otomatis menghilangkannya dari daftar pesan serta memperbarui badge counter.
  - Konfirmasi hapus hari libur / cuti bersama menggunakan modal dialog konfirmasi (`ConfirmationDialog`), bukan popup bawaan browser.
  → ✅ SELESAI 2026-08-29 (`expenseflow-web`, `expenseflow-backend`, `expenseflow-mobile`)

- karyawan bisa di ajust oleh hrd masuk di hari sabtu atau minggu
  → ✅ SELESAI 2026-07-04 (fitur Custom Shift/Scheduling, lihat perubahan.md sesi 2026-07-04)

- reminder bug, super admin tidak bisa approvel invoice lv 3 

---

# Roadmap Fitur Payroll (Gaji)
> 📄 **Dokumentasi & Spesifikasi Lengkap Telah Dipindahkan:**  
> Roadmap detail, analisis kekurangan data/tabel, aturan PPh 21 TER 2024 (PP 58/2023), BPJS Ketenagakerjaan & Kesehatan, lembur (PP 35/2021), THR (Permenaker 6/2016), multi-cabang, skema database, audit kekurangan, kontrol keamanan PII/fraud, dan daftar API endpoint kini terdokumentasi lengkap di:  
> **👉 [doc/07-PAYROLL-ROADMAP.md](07-PAYROLL-ROADMAP.md)** (Revisi V3 — Audit & Backlog Kepatuhan/Fleksibilitas).

reminders: bug user statusnya sedang cuti di hari itu masih bisa presensi, 
fix: ✅ SELESAI 2026-07-09 — checkIn() di AttendanceController sekarang mengecek LeaveRequest (approved, leave_type cuti/sakit/izin) yang mencakup tanggal hari ini; jika ada → tolak 403 dengan pesan status cuti/sakit/izin.

reminder: tambahkan 1 data json untuk presensi mobile jika lembur approval di terima dan di tolak jika tidak lebur null
✅ SELESAI 2026-07-09 — myAttendance() kini eager-load overtimeApproval dan menyertakan field `overtime_approval` (id, status, overtime_minutes, notes, reviewed_at, is_auto_checkout) atau null jika tidak ada lembur.

---

# Analisis Performa Backend — Delay ~500ms Seragam (2026-07-14)✅ SELESAI

## Gejala
Log `php artisan serve` menunjukkan **hampir semua request ~510–515ms** (device-changes,
overtime-approvals, today, settings, users) — konsisten walau bobot query tiap endpoint
berbeda-beda. Sesekali muncul ~2ms (request duplikat/short-circuit). `admin/users` kadang ~1s.

**Kesimpulan:** delay bukan di controller (kalau di controller, angkanya akan bervariasi
sesuai berat query). Ini **overhead per-request TETAP** — semua request "diseret" ke ~500ms
oleh sesuatu yang jalan di setiap request / lingkungan dev. Yang **BUKAN** penyebab (sudah dicek):
tidak ada `sleep()`/`usleep()`, tidak ada HTTP call di middleware (FCM/Vision hanya di job/OCR),
DB sudah `127.0.0.1` (bukan `localhost`), tidak ada Telescope/Debugbar, middleware semua ringan.

## Root Cause: OPcache TIDAK AKTIF ⭐ TERKONFIRMASI

Test `GET /api/v1/ping` (route statis, tanpa DB/auth/middleware) → **~520ms**.
Artinya delay bukan di controller/DB/middleware, tapi **di level PHP sendiri**.

Dicek: `php -r "echo extension_loaded('Zend OPcache') ? 'yes' : 'no';"` → **no**.
File `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.ini`:
- Baris 833: `;zend_extension=opcache` → extension tidak di-load
- Baris 1517: `;opcache.enable=1` → setting off

**Tanpa OPcache, PHP compile ulang ratusan file Laravel di SETIAP request.**
Di Windows + NTFS (filesystem I/O lambat), ini mudah makan ~500ms.

### Fix yang Sudah Diterapkan (2026-07-14)
1. ✅ `php.ini:833` → `zend_extension=opcache` (uncomment)
2. ✅ `php.ini:1517` → `opcache.enable=1` (uncomment)
3. ✅ `php.ini:1526` → `opcache.max_accelerated_files=10000` (uncomment, Laravel banyak file)
4. ⏳ **Restart Laragon/server PHP** (WAJIB agar php.ini terbaca ulang)
5. ⏳ **Test ulang** `curl /api/v1/ping` — target < 50ms

### Optimasi Tambahan (belum diterapkan, opsional)
| # | Item | Dampak | Cara |
|---|------|--------|------|
| 1 | `PHP_CLI_SERVER_WORKERS=4` | request paralel di dev | uncomment `.env:15` |
| 2 | `config:cache` + `route:cache` | skip parsing config/route | jalankan saat uji performa |
| 3 | `APP_DEBUG=false` | matikan stack trace/query log | `.env:3` (wajib di prod) |
| 4 | Cache/Session → `file` atau `redis` | kurangi DB round-trip | `.env` ubah driver |
| 5 | `admin/users` ~1s | kemungkinan N+1 | cek `UserController@index` |


buat endpoint untuk delete user, tapi user harus nonaktif terlebih dahulu lalu hapus user dengan verifikasi type delete --selesai

---

## Roadmap Fitur Shift Lanjutan (2026-07-18)

### Status Validasi Shift yang Sudah Ada
- ✅ Jeda istirahat K3 antar shift (< 8 jam ditolak, 8–11 jam warning) — `ShiftRestService`
- ✅ Min 1 hari libur per minggu (UU 13/2003 Pasal 79) — hard rule, tidak bisa dinonaktifkan
- ✅ Batas jam kerja per minggu (UU 13/2003 Pasal 77) — toggle ON/OFF per kantor (`enforce_weekly_hours`, default OFF)

### P1 — Penting (Operasional & K3)
- [ ] **Batas shift malam berturut-turut** (max 5–7 malam berurutan) — standar K3 ritme sirkadian; perlu counter shift malam per karyawan --sedang di pertimbangkan
- ✅ **Minimum notice perubahan jadwal** (H-N hari sebelum berlaku) — HRD dapat peringatan warning jika assign/ubah shift < N hari (N diatur per kantor, default 0=off)
- [ ] **Shift swap antar karyawan** — request tukar shift + approval HRD; saat ini semua perubahan harus lewat HRD manual --soon
- ✅ **Roster jadwal shift di mobile** — karyawan bisa lihat jadwal shift mereka ke depan; saat ini hanya ada `/my-schedule` statis

### P2 — Nilai Tambah
- [ ] **Rotasi shift otomatis periodik** — sistem 3-roster saat ini assign manual; rotasi setiap N minggu perlu scheduling otomatis --soon
- [ ] **Unavailability karyawan** — karyawan bisa menyatakan tanggal tidak tersedia untuk dipertimbangkan HRD saat assign shift --di pertimbangkan






### Bug / Isu Aktif
- [x] **Discrepancy Status Roster & Hari Ini di UI**: Telah diperbaiki di backend (`ShiftController::resolveSchedule()`). Sebelumnya `resolveSchedule` tidak mengecek tabel `Holiday` (sehingga roster menampilkan hari kerja di hari libur), dan pencarian shift aktif (mengabaikan shift kedaluwarsa) tidak setara dengan endpoint `today`. Sekarang keduanya 100% tersinkronisasi sehingga frontend (react) menampilkan status yang sama di 'Hari Ini' dan 'Roster Harian'.

fitur baru: - tambahkan tipe kontrak user karyawan Tetap / Kontrak / Magang (80%)
            - Tanggal bergabung user untuk menghitung masa kerja (ini next deh)
            - foto dan avatar 

Atasan langsung (Direct Manager)
Untuk approval chain — siapa yang approve cuti/lembur karyawan ini. <-- ini skip dulu di buatkan terakhir karna di buat untuk pembuatan role dan jabatan yang lebih kompleks 

---

## Ketentuan & Matriks Tipe Hubungan Kerja (Employment Type)

Berikut adalah panduan aturan bisnis, tindakan sistem, dan benefit untuk setiap jenis tipe hubungan kerja pada modul Manajemen Karyawan HRIS ExpenseFlow:

| Tipe Kerja | Deskripsi | Aturan Tanggal | Indikator / Tindakan Sistem | Hak Cuti & Benefit |
|---|---|---|---|---|
| **PKWTT** *(Tetap)* | Karyawan Perjanjian Kerja Waktu Tidak Pertentu. | Cukup `joined_date` (tanpa tanggal berakhir kontrak). | Bebas pengingat masa kontrak. Akun aktif tanpa batas waktu. | • Hak kuota cuti tahunan penuh (12 hari/tahun)<br>• Batas klaim bulanan standar/penuh<br>• Akses WFH & presensi mobile sesuai persetujuan HRD |
| **PKWT** *(Kontrak)* | Karyawan Perjanjian Kerja Waktu Pertentu (Kontrak). | Wajib mengisi `contract_start_date` & `contract_end_date`. | **Indikator Masa Kontrak:**<br>• 🟢 **Aktif**: Sisa kontrak > 30 hari<br>• 🟡 **Mendekati Expired**: Sisa kontrak ≤ 30 hari (Peringatan HRD untuk evaluasi/perpanjangan)<br>• 🔴 **Expired**: Tanggal kontrak telah lewat | • Hak cuti & klaim sesuai durasi kontrak<br>• Notifikasi/peringatan perpanjangan kontrak bagi HRD |
| **Probation** *(Probasi)* | Karyawan dalam masa percobaan (biasanya 3–6 bulan). | Memiliki `joined_date` & target tanggal lulus probasi. | Indicator status **Probasi** (Badge Amber). Pengingat HRD untuk evaluasi kelulusan probasi karyawan. | • Akses presensi dasar & klaim struk<br>• Kuota cuti tahunan ditangguhkan hingga lulus probasi (opsional)<br>• Evaluasi konversi ke PKWTT / PKWT |
| **Internship** *(Magang)* | Siswa / Mahasiswa / Tenaga Magang / Freelance. | Berdurasi terbatas sesuai proyek/periode magang. | Indicator status **Magang** (Badge Purple). Filter khusus peserta magang. | • Presensi harian mobile/onsite<br>• Limit klaim opsional/terbatas<br>• Tanpa akumulasi kuota cuti tahunan |

refaktoring code untuk terakhir saja

---

# Pengingat Penting — Form Edit Kantor & Perubahan Mendadak (2026-08-13) --selesai

Peringatan untuk agent & developer: **Field di Form Edit Kantor (`attendance_settings`)**
jangan diubah sembarangan karena berdampak langsung ke sistem presensi yang sedang berjalan **hari itu juga**.
Setiap perubahan pada field berikut punya risiko bug/ketidakadilan data:

## Dampak Perubahan Menyentuh Sistem Lain (Ceklis Saat Edit Kantor) --selesai 

| Field Kantor | Dampak Jika Diubah Mendadak (di Tengah Hari) | Komponen Terdampak |
|---|---|---|
| `work_start_time` / `work_end_time` | Karyawan yang sudah check-in hari ini bisa **mendadak dianggap telat**; hitungan lembur (check_out - work_end) & auto-checkout jadi kacau. | `determineStatus()`, `checkOut()`, `AutoCheckoutCommand` |
| `office_latitude` / `office_longitude` / `radius_meters` | Karyawan onsite/field yang sedang check-in mendadak **ditolak 403 Out of Radius**. | `AttendanceController::checkIn()` (GPS) |
| `auto_checkout_grace_minutes` / `checkout_reminder_minutes` | Jika grace diperkecil ekstrem (mis. 120→10 menit), **Cron Job auto-checkout langsung menutup presensi semua karyawan yang belum checkout**. | `AutoCheckoutCommand` (Laravel Scheduler) |
| `late_tolerance_minutes` | Karyawan yang check-in siang/sore mendapat perlakuan beda vs yang check-in pagi. | `determineStatus()` |
| `overtime_enabled` / `min_overtime_minutes` | Karyawan yang sedang lembur mendadak tidak mendapat draft approval lembur. | `checkOut()`, `overtime_approvals` |
| `shift_notice_days` (H-N) | Mengubah nilai mendadak mempengaruhi tanggal efektif saat HRD edit template shift. | `ShiftController::update()` (versioning) |
| `enforce_weekly_hours` / `max_weekly_hours` | Template shift yang sudah ter-assign bisa mendadak gagal validasi mingguan. | Validasi K3 Shift |

## Aturan Validasi WAJIB Form Edit Kantor (Backend Laravel)

```php
$validated = $request->validate([
    'office_name'                  => 'required|string|max:100',
    'office_latitude'              => 'required|numeric|between:-90,90',
    'office_longitude'             => 'required|numeric|between:-180,180',
    'radius_meters'                => 'required|integer|min:10|max:10000',
    'work_start_time'              => 'required|date_format:H:i',
    'work_end_time'                => 'required|date_format:H:i',
    'late_tolerance_minutes'       => 'required|integer|min:0|max:240',
    'checkout_reminder_minutes'    => 'required|integer|min:5',
    'auto_checkout_grace_minutes'  => 'required|integer|gt:checkout_reminder_minutes',
    'overtime_enabled'             => 'required|boolean',
    'min_overtime_minutes'         => 'nullable|required_if:overtime_enabled,true|integer|min:15',
    'shift_notice_days'            => 'required|integer|min:0|max:30',
    'enforce_weekly_hours'         => 'required|boolean',
    'max_weekly_hours'             => 'nullable|required_if:enforce_weekly_hours,true|integer|min:20|max:84',
]);
```

> **Aturan Emas:** `auto_checkout_grace_minutes` HARUS `gt:checkout_reminder_minutes`
> (grace period lebih besar dari reminder), agar Cron Job auto-checkout tidak menutup
> presensi karyawan sebelum mereka sempat menerima pengingat.

## Strategi Proteksi yang Disarankan (Untuk Dampak Perubahan Mendadak)
Perubahan jam operasional, toleransi keterlambatan, atau radius GPS di tengah hari dapat merusak perhitungan presensi karyawan yang sedang bekerja. Berikut adalah urutan strategi mitigasi berdasarkan efektivitasnya:

1. **(Rekomendasi Utama) Snapshot Acuan Jam Kerja Hari Ini**: Simpan/referensi jam kantor, radius, dan toleransi (salin nilainya dari `attendance_settings` ke `attendances`) pada saat karyawan check-in. Dengan begitu, perubahan pengaturan di siang hari tidak mempengaruhi perhitungan check-out & lembur karyawan yang masuk di pagi harinya. (Tingkat keamanan: Sangat Tinggi) ✅ SELESAI 2026-08-26
2. **Efektif H+1 (Versioning Pengaturan)**: Perubahan pengaturan tidak langsung menimpa data aktif, melainkan disimpan sebagai draf/versi baru yang mulai berlaku jam 00:00 esok harinya (mirip dengan logika versioning shift). (Tingkat keamanan: Sangat Tinggi) — TIDAK DIPERLUKAN (redundan dengan snapshot #1)
3. **UI Confirmation Dialog & Friction**: Saat HRD mengubah Jam Kerja / Lokasi GPS / Auto-Checkout di Form Edit Kantor, tampilkan peringatan mencolok dan harus di-ketik (misal: Ketik 'SIMPAN').
   > "⚠️ Anda mengubah Jam Kerja / Lokasi GPS / Auto-Checkout di tengah hari. Ini akan mengubah aturan presensi & perhitungan otomatis untuk karyawan hari ini. Lanjutkan?" (Tingkat keamanan: Rendah - hanya mencegah *human error*) ✅ SELESAI 2026-08-26 (backend + frontend web — lihat bawah)
4. **Subscribe Notifikasi**: Kirim notifikasi (DB + FCM) ke seluruh HRD/Admin saat pengaturan kantor diubah, agar perubahan bisa diaudit bersama. ✅ SELESAI (Tingkat Keamanan: Rendah - responsif bukan preventif)

### Implementasi Snapshot + Gerbang Konfirmasi "SIMPAN" (2026-08-26)
**Migration** `2026_08_26_000001_add_setting_snapshot_to_attendances_table.php` — kolom
`snap_*` di `attendances` (semua nullable; baris lama otomatis pakai jalur lama tanpa backfill):
kantor acuan (`snap_office_id`, lat/lng/radius), jadwal efektif saat check-in
(`snap_source`, `snap_work_start_time`, `snap_work_end_time`, `snap_is_off`,
`snap_is_cross_day`), aturan lembur/pulang-awal/auto-checkout (`snap_overtime_enabled`,
`snap_min_overtime_minutes`, `snap_early_leave_tolerance_minutes`, `snap_reminder_minutes`,
`snap_grace_minutes`).

**Alur snapshot** (helper di `App\Models\Attendance`: `buildSnapshot()`, `hasSnapshot()`,
`snapshotSchedule()`, `snapshotOffice()`):
1. `checkIn()` → tulis snapshot jadwal efektif (hasil `resolveSchedule`) + kantor acuan
   (kantor terdekat bila radius check berjalan) ke kolom `snap_*`.
2. `checkOut()` → validasi radius checkout memakai koordinat/radius snapshot (HRD memindah/
   memperkecil radius siang hari tidak menolak checkout); hitung work/lembur/early-leave dari
   schedule snapshot.
3. `AutoCheckoutCommand` → reminder & auto-checkout memakai jam pulang + grace/reminder
   snapshot; konsisten dengan `checkOut()` manual.
4. `checkStatus()` → `scheduled_auto_checkout_at` dari snapshot (tidak bergeser saat setting
   diedit); tampilan shift aktif tetap live.
5. Perubahan setting hanya berpengaruh ke karyawan yang **belum check-in** hari itu & seluruh
   presensi esok hari.

**Gerbang konfirmasi `updateSettings()`**: bila field "berbahaya" benar-benar BERUBAH nilainya
(`work_start_time`, `work_end_time`, `work_days`, `custom_schedules`, `office_latitude`,
`office_longitude`, `radius_meters`, `late_tolerance_minutes`, `early_leave_tolerance_minutes`,
`overtime_enabled`, `min_overtime_minutes`, `checkout_reminder_minutes`,
`auto_checkout_grace_minutes`) dan request TIDAK menyertakan `confirm_dangerous = "SIMPAN"` →
**422** dengan `requires_confirmation`, `confirmation_phrase: "SIMPAN"`,
`dangerous_changed_fields[]`. Frontend wajib menampilkan dialog ketik-"SIMPAN" lalu mengirim
ulang payload + field konfirmasi. Field aman (mis. `office_name`) tidak butuh konfirmasi.
Response sukses & notifikasi HRD menyertakan daftar field berbahaya yang berubah.
Test: `tests/Feature/SettingSnapshotTest.php`.

**Frontend web (2026-08-26):** `SettingsManagement.tsx` (OfficesTab) menangani 422
`requires_confirmation` dari `doSave()` → menampilkan dialog peringatan berisi daftar field
berbahaya yang berubah (dengan label ramah Indonesia) + input wajib ketik persis "SIMPAN"
(tombol simpan disabled sampai frasa cocok). Konfirmasi mengirim ulang payload +
`confirm_dangerous = "SIMPAN"`. Form Tambah Kantor tidak terdampak (endpoint create tanpa gerbang).

Jadi bukan "ditunda ke besok", melainkan: siapa yang sudah terlanjur masuk, dia aman dengan aturan saat dia masuk. Sisanya langsung ikut aturan baru. Ini yang membuat perubahan mendadak tidak merugikan siapa pun yang sedang bekerja

------------------------------------------------------------------------------

masih ada bug di assign massal pada roster harian --selesai
peingatan cuti yagn sama jadwalnya hanya di cabang yang sama --selesai
fitur yang di tambah:
1. tambahkan di pengaturan edit kantor bahwa cuti bisa edit oleh orang -- selesai

bug untuk fitur sistem cuti bersama di dalam tab kalender pada file @AttedenceManagmenet.tsx, HRD memilih tangal cuti bersama, lalu masuk ke mobile setiap karyawan, karyawan di kasih pilihan mau ikut atau tidak, jika ikut saldo cuti terpakai, jika tidak masuk sesuai jadwal:
1. ada bug view kalender di web, seharusnya view cuti bersama di cabang x hanya terlihat oleh cabang x saja
   --selesai ✅
2. bug pending, seharusnya jumlah pending ada, karyawan yang dalam cabang tersebut belum respon pada cuti bersama
   ✅ SELESAI 2026-08-16 — collective_summary.pending kini dihitung dari total karyawan aktif di
   cabang (attendance_enabled=true) dikurangi accepted & declined; bukan dari leave_requests saja.
   Ditambah field `total` di collective_summary untuk transparansi -- selesai
3. bug, cuti bersama kenapa menjadi libur bersama, user yang tidak ikut cuti bersama akan masuk serperti hari normal sesuai jadwal
   ✅ SELESAI 2026-08-17 — `isNonWorkingDay()` di AttendanceController & AutoCheckoutCommand sudah fix.
   Lalu method `today()`, `monthlySummary()`, `reportAttendance()`, dan `countWorkingDays()`
   diperbaiki agar tidak menganggap cuti bersama sebagai hari libur default. Sekarang hanya menganggap libur
   jika karyawan mempunyai leave_request dengan collective_status='accepted'. Karyawan yang declined/pending
   tetap diperlakukan sebagai hari kerja normal (is_holiday=false, lembur & early_leave dihitung normal).
4. bug pada pengaturan edit kantor tentang regulasi cuti pada setiap kantor
   ✅ SELESAI 2026-08-16 — respondCollectiveLeave(): policy sebelumnya selalu diambil dari kantor
   pertama perusahaan (::where('company_id',...)->first()). Diperbaiki: gunakan
   $user->attendance_setting_id dengan fallback ke kantor pertama jika belum di-assign.


   ada bug: kantor A menambahkan libur nasional untuk semua kantor cabang, namun kantor B bisa menghapus libur nasional yang di buat oleh kantor A ✅ SELESAI 2026-08-22 — Opsi A: libur nasional (company_id NULL) kini master data global yang hanya bisa di-CRUD oleh super_admin. Guard ditambahkan di storeHolidays(), updateHolidays(), dan destroyHolidays() di AttendanceController. HRD/Admin tetap bebas mengelola libur perusahaan/cabang miliknya.

   ada bug lagi: user yang sudah assigned shift, di dalam shif itu pada tanggal 25 agustus adalah jadwal dia libur shift , tapi dia mengajukan cuti/izin/sakit/wfh dan sistem memperbolehkan dia mengajukan cuti/izin/sakit/wfh, padahal seharusnya user tidak bisa mengajukan cuti/izin/sakit/wfh kalau di jadwal shif dia libur , tolong buatkan validasi bahwa dia libur pada jadwal shif tersebut ✅ SELESAI 2026-08-22

  ada bug lagi: user a adalah pegawai kantor cabang B yang di mana hari libur dari kantor cabang B(kantor default) adalah dalam 1 minggu kantor cabang b libur di hari sabtu dan minggu, lalu user a assigned shift yang di mana dalam shift tersebut hari sabtu dan minggu jadwal user a masuk kerja, lalu user a ingin mengajukan cuti pada hari minggu namun tidak bisa karena sistem membaca bahwa dia libur kerja(karena kantor default libur di hari minggu) padahal saat ini karyawan tersebut di assigned shift shift di mana shif itu hari minggu dan sabtu user a masuk ✅ SELESAI 2026-08-22

  ada bug pada device binding, user tetap bisa login walaupun device id nya tidak sesuai dengan device id yang terdaftar

5. Fitur Reset Saldo Cuti & Riwayat Periode Lampau (2026-08-30) ✅ SELESAI
   - Tabel `leave_balance_histories`: menyimpan arsip snapshot saldo cuti (kuota awal, terpakai, sisa) dan pemakaian izin/sakit per siklus/periode reset.
   - Command `attendance:reset-leave-balances` & endpoint manual reset kantor:
     - Mengarsipkan data pemakaian periode yang berakhir ke `leave_balance_histories`.
     - Me-reset saldo cuti ke kuota baru (`used = 0`).
     - Me-reset pemakaian izin/sakit menjadi 0 (`used = 0`) untuk periode baru.
   - Frontend Tab Saldo Cuti (`AttendanceManagement.tsx`):
     - Sub-tab "Saldo Berjalan (Periode Aktif)": kartu saldo aktif cuti dan izin/sakit tahun berjalan.
     - Sub-tab "Riwayat Saldo Sebelumnya": ringkasan KPI dan tabel arsip riwayat pemakaian cuti & izin/sakit periode lalu lengkap dengan filter tahun, kantor cabang, dan pencarian.

6. Arsitektur Queue Jobs & Background Processing (2026-08-31) ✅ AKTIF
   - **Konsep**: Operasi non-kritis (audit trail, notifikasi FCM, OCR) dipindahkan ke background queue agar response time API tetap instan (< 30ms) dan server tahan lonjakan trafik saat peak hour.
   - **Job yang Sudah Aktif**:
     1. `ProcessOcrJob`: Pemrosesan gambar dan ekstraksi teks struk klaim belanja via Google Cloud Vision API.
     2. `ProcessAttendanceBackgroundJob`: Menangani insert `activity_logs` dan pengiriman push notification FCM presensi (check-in / check-out) secara asinkron.
   - **Cara Menjalankan Worker**:
     ```bash
     php artisan queue:work
     ```
     *(Satu worker default memproses seluruh antrean job OCR, Presensi, dan Notifikasi)*.
   - **Kandidat Fitur untuk Queue Jobs Selanjutnya**:
     - 🔔 **Notifikasi FCM Approval**: Struk Klaim, Invoice (Level 1/2/3), Pengajuan Lembur, Cuti/Izin, dan Broadcast Cuti Bersama massal.
     - 📊 **Ekspor/Impor File Besar**: Ekspor laporan presensi & klaim pengeluaran ke Excel/CSV/PDF, serta impor spreadsheet karyawan/shift massal.
     - 💰 **Kalkulasi Payroll & Slip Gaji**: Perhitungan massal jam kerja/lembur/potongan di akhir bulan dan generate PDF slip gaji.
     - 🖼️ **Kompresi Media**: Optimasi resolusi foto bukti absensi & auto-backup ke cloud storage.

7. Jeda Minimal Check-out (Cooldown Buffer) Setelah Check-in (2026-08-31) ✅ AKTIF
   - **Tujuan**: Mencegah karyawan langsung check-out detik berikutnya setelah check-in (*accidental tap* di saku / presensi kilat) yang membuat status menjadi *Pulang Awal (Early Leave)* dan merusak akurasi total jam kerja rekap payroll.
   - **Pengaturan per Kantor**: Kolom `min_checkout_interval_minutes` di `attendance_settings` (default: 10 menit, isi 0 untuk mematikan). Dapat diatur HRD melalui Web *Pengaturan Kantor*.
   - **Snapshot Check-in**: Kolom `snap_min_checkout_interval_minutes` pada tabel `attendances` dibekukan saat karyawan check-in.
   - **Validasi Backend (`checkOut`)**: Jika `now()` belum mencapai minimal $X$ menit dari `check_in_time`, sistem mengembalikan error HTTP `422 Unprocessable Entity` dengan rincian `earliest_checkout_at`, `remaining_minutes`, dan `is_cooldown: true`.
   - **Tampilan Mobile (Flutter)**: Menampilkan dialog penjelasan dan jam buka presensi pulang saat karyawan mencoba check-out sebelum jeda minimal tercapai.

8. Daftar Fitur Notifikasi HP (Mobile Push FCM & Local Notifications) ✅ AKTIF
   Aplikasi mobile Flutter ExpenseFlow menggunakan integrasi **Firebase Cloud Messaging (FCM HTTP v1)** dan **Local Notifications** (`flutter_local_notifications`). Berikut daftar seluruh fitur yang mengirim notifikasi ke HP karyawan:

   - **A. Presensi & Jam Kerja**:
     1. `⏰ Reminder Checkout (Lokal)`: Notifikasi pengingat otomatis di HP karyawan $N$ menit setelah jam pulang kerja agar karyawan tidak lupa check-out.
     2. `⚠️ Warning Auto-Checkout (Lokal)`: Peringatan darurat 5 menit sebelum sistem backend mengeksekusi auto-checkout otomatis.
     3. `🔔 Konfirmasi Auto-Checkout (FCM/Lokal)`: Notifikasi saat sistem backend mengeksekusi auto-checkout karena karyawan melebihi batas waktu toleransi jam pulang.
     4. `📅 Perubahan Jadwal Kerja / Shift (FCM)`: Notifikasi ke karyawan saat HRD menugaskan (*assign*) atau mengubah shift kerja baru.

   - **B. Pengajuan Lembur (Overtime)**:
     5. `⏰ Pengajuan Lembur Masuk (FCM)`: Notifikasi ke HRD/Atasan saat karyawan check-out melebihi jam kerja normal.
     6. `✅ Lembur Disetujui (FCM)`: Notifikasi ke karyawan saat jam lembur disetujui oleh HRD.
     7. `❌ Lembur Ditolak (FCM)`: Notifikasi ke karyawan beserta alasan jika jam lembur ditolak HRD.

   - **C. Pengajuan Cuti, Izin, & Libur Perusahaan**:
     8. `✅ Pengajuan Cuti/Izin Disetujui (FCM)`: Notifikasi ke karyawan saat tiket cuti, izin, atau sakit disetujui HRD.
     9. `❌ Pengajuan Cuti/Izin Ditolak (FCM)`: Notifikasi ke karyawan saat tiket cuti/izin/sakit ditolak HRD beserta alasan penolakan.
     10. `🏖️ Pengumuman Cuti Bersama (FCM)`: Notifikasi siaran (*broadcast*) ke seluruh karyawan saat HRD merilis jadwal cuti bersama baru.
     11. `❌ Cuti Bersama Dibatalkan (FCM)`: Notifikasi ke karyawan saat cuti bersama dibatalkan oleh HRD (dengan info pengembalian saldo cuti).
     12. `🔄 Reset Saldo Cuti Tahunan (FCM)`: Pemberitahuan saat kuota cuti tahunan di-reset dan pemakaian periode lalu diarsipkan.

   - **D. Keamanan & Device Binding**:
     13. `📱 Pindah Perangkat Disetujui (FCM)`: Notifikasi ke karyawan saat permohonan ganti HP disetujui HRD sehingga bisa login di HP baru.
     14. `❌ Pindah Perangkat Ditolak (FCM)`: Notifikasi ke karyawan saat permohonan ganti HP ditolak HRD.

9. Arsitektur Sistem Laporan & Performa Skala Besar (1.000+ Karyawan) (2026-08-31) ✅ AKTIF
   Aplikasi dirancang dan diuji untuk menangani **1.000+ akun karyawan secara simultan di berbagai cabang** dengan respon backend sub-milidetik dan antarmuka web (UI) tetap ringan serta responsif (60 FPS).

   - **A. Karakteristik & Tantangan Beban (1.000 Karyawan)**:
     - **Trafik Peak Hour**: Check-in/out massal pada jam masuk kerja dan ekspor rekap laporan bulanan.
     - **DOM Bloat di Frontend**: Merender 1.000 baris tabel sekaligus (disertai tombol, status badge, dan input) membebani memori browser dan memicu frame drop.
     - **Overhead Network Waterfall**: Memanggil puluhan API berurutan (misal request loop per karyawan) menyebabkan latency akumulatif.

   - **B. Optimasi Query Backend (Sub-millisecond Performance)**:
     - **Index & Filtering**: Endpoint `reportAttendance`, `today`, dan `monthlySummary` memanfaatkan compound index pada kolom `date`, `company_id`, dan `user_id`.
     - **Single-Pass Aggregation & In-Memory Grouping**: Menghilangkan query N+1. Data jadwal kantor, shift kustom, approval cuti, dan presensi dimuat secara batch (*eager loading* / bulk array lookup) dalam 1 query tunggal.
     - **Benchmark Kecepatan**: Eksekusi query database untuk agregasi 1.000 karyawan selesai dalam **0.17 ms – 5 ms**.
     - **Dukungan `per_page` Hingga 2000**: Endpoint seperti `listUsers`, `listLeaves`, `listOvertimeApprovals`, dan `listDeviceChanges` mendukung parameter `per_page=2000` (default 2000) untuk memuat data utuh dalam satu request tanpa pemotongan parsial.

   - **C. Optimasi Frontend Web (Lightweight UI & 60 FPS Rendering)**:
     - **Paginasi Standar di Semua Halaman (25 / 50 / 100 data per halaman — bar otomatis di-hide jika data < 25)**:
       - Tab Roster Shift (`ShiftManagement.tsx`)
       - Tab Approval & Saldo Cuti (`AttendanceManagement.tsx`)
       - Halaman Manajemen Karyawan (`KaryawanManagement.tsx`)
       - Verifikasi Struk & Riwayat (`ReceiptInbox.tsx` & `ReceiptHistory.tsx`)
       - Inbox & Riwayat Invoice Vendor (`InvoiceInbox.tsx` & `InvoiceHistory.tsx`)
       - Master Data Vendor (`MasterVendor.tsx`)
       - Audit Trail & Notifikasi (`AuditLogView.tsx` & `NotificationsView.tsx`)
     - **Optimistic UI Updates (0ms Feedback)**:
       - Toggle WFH dan Radius GPS langsung memperbarui status tombol seketika di state lokal sebelum konfirmasi server selesai.
     - **Eliminasi Network Waterfall**:
       - Mengganti loop request bertahap dengan single call API `per_page=2000`.
     - **Debounced Search (500ms)**:
       - Mencegah render ulang tabel saat pengguna mengetik filter pencarian nama karyawan atau merchant.

   - **D. Hasil Uji Performa**:
     - Waktu respon API: **< 30 ms**.
     - Waktu rendering browser: **< 16 ms (mulus 60 FPS)** tanpa freeze saat beralih antar halaman atau memfilter 1.000 data.

10. Panduan Spesifikasi Server & Infrastruktur Berdasarkan Jumlah Karyawan (Server Sizing & Hardware Resource Guide) ✅ PANDUAN DEPLOYMENT
    Panduan kapasitas perangkat keras (*hardware*), alokasi memori, arsitektur server, dan estimasi biaya operasional (*cost*) untuk menjalankan ekosistem **ExpenseFlow (Backend Laravel API + Queue Worker + Database + Web UI React + Mobile Flutter Push FCM + OCR Vision)** dari skala UMKM hingga skala Enterprise Multi-Cabang:

    ### A. Matriks Ringkasan Cepat (Quick Sizing Matrix)
    | Skala Karyawan | Rekomendasi vCPU | Rekomendasi RAM | Storage (SSD/NVMe) | Database & Cache | Model Arsitektur Server | Estimasi Biaya Cloud / Bulan |
    | :--- | :--- | :--- | :--- | :--- | :--- | :--- |
    | **1 – 100 User** *(Starter)* | 2 vCPU | 2 GB – 4 GB | 25 GB – 40 GB SSD | MySQL 8.0 (Co-located) + File Cache | Single VPS Monolith All-in-One | $5 – $15 / bln *(Rp 80rb - 240rb)* |
    | **100 – 500 User** *(Medium)* | 4 vCPU | 8 GB | 60 GB – 100 GB NVMe | MySQL 8.0 (Buffer 4GB) + Redis Cache/Queue | Single VPS Optimized (Tuned Stack) | $20 – $45 / bln *(Rp 320rb - 720rb)* |
    | **500 – 1.000 User** *(Large)* | 8 vCPU (4 App + 4 DB) | 16 GB (8 App + 8 DB) | 120 GB SSD + Cloud Object Storage | Dedicated DB Instance + Managed Redis 1GB | Split Architecture (App Server + DB Server) | $70 – $140 / bln *(Rp 1.1jt - 2.2jt)* |
    | **1.000 – 5.000 User** *(Enterprise)* | 16 – 24 vCPU (Multi-Node) | 32 GB – 64 GB | 250 GB+ NVMe + S3/R2 Storage | Master-Replica MySQL + Redis Cluster | High Availability (Load Balancer + 2-3 App Nodes) | $200 – $450 / bln *(Rp 3.2jt - 7.2jt)* |
    | **5.000 – 20.000+ User** *(Corporate / Holding)* | 32 – 64+ vCPU (Auto-scale) | 128 GB – 256 GB | Multi-TB Object Storage + High IOPS DB | AWS Aurora / Cloud SQL HA + ProxySQL | Kubernetes (K8s) Cluster + Cloud Queue (SQS/PubSub) | $800 – $2.000+ / bln |

    ---

    ### B. Rincian Konfigurasi Teknis per Kategori Skala

    #### 1. Tier 1: Startup & Bisnis Kecil (1 – 100 Karyawan)
    - **Profil Beban**:
      - Trafik check-in/out: ~10–25 request per menit saat jam masuk/pulang kerja.
      - Klaim struk OCR: ~5–15 struk per hari.
      - Ukuran Database: < 1 GB per tahun.
    - **Spesifikasi Server**:
      - **Infrastruktur**: 1 Instance VPS Monolith (App, DB, dan Queue Worker di 1 server yang sama).
      - **CPU**: 2 vCPU.
      - **RAM**: 2 GB – 4 GB RAM.
      - **Storage**: 25 GB – 40 GB SSD.
      - **OS & Stack**: Ubuntu 22.04 LTS, Nginx, PHP 8.2+ (PHP-FPM), MySQL 8.0 / MariaDB, Supervisor.
    - **Tuning Konfigurasi**:
      - PHP-FPM: `pm = dynamic`, `pm.max_children = 15`, `pm.start_servers = 4`.
      - MySQL: `innodb_buffer_pool_size = 1G` (jika RAM 4GB) atau `512M` (jika RAM 2GB).
      - Queue Driver: `database` atau `redis` lokal dengan 1 worker (`php artisan queue:work --tries=3`).
      - Penyimpanan File Struk: Local disk (`storage/app/private/receipts`).

    ---

    #### 2. Tier 2: Bisnis Berkembang (100 – 500 Karyawan)
    - **Profil Beban**:
      - Trafik check-in/out: ~50–120 request per menit saat peak hour (07.45 – 08.05).
      - Klaim struk OCR: ~50–100 struk per hari.
      - Scheduler Auto-Checkout berjalan setiap 5 menit memeriksa 500 user.
      - Ekspor rekap presensi bulanan dan approval izin harian.
    - **Spesifikasi Server**:
      - **Infrastruktur**: 1 VPS High-Frequency Compute Optimized.
      - **CPU**: 4 vCPU.
      - **RAM**: 8 GB RAM.
      - **Storage**: 80 GB – 100 GB NVMe SSD.
      - **OS & Stack**: Ubuntu 22.04 LTS, Nginx, PHP 8.2+, MySQL 8.0, Redis In-Memory.
    - **Tuning Konfigurasi**:
      - PHP-FPM: `pm = dynamic`, `pm.max_children = 35`, `pm.start_servers = 8`, `memory_limit = 256M`.
      - OPcache: `opcache.enable=1`, `opcache.memory_consumption=128`, `opcache.validate_timestamps=0` (di production).
      - MySQL: `innodb_buffer_pool_size = 4G`, `innodb_log_file_size = 512M`, `max_connections = 150`.
      - Cache & Queue: **Redis In-Memory** (pindahkan session, cache query, dan queue dari database ke Redis untuk latency < 1ms).
      - Background Worker: 2 proses worker via Supervisor (1 worker OCR, 1 worker presensi & notifikasi FCM).

    ---

    #### 3. Tier 3: Perusahaan Menengah-Besar (500 – 1.000 Karyawan)
    - **Profil Beban**:
      - Trafik check-in serentak: 300–600 karyawan presensi dalam rentang 15 menit.
      - Multi-cabang (3–10 cabang) dengan aturan jadwal shift kantor berbeda.
      - Laporan bulanan, agregasi lembur, dan kalkulasi payroll massal.
      - Ratusan foto struk klaim & foto bukti presensi per minggu.
    - **Spesifikasi Server (Split Architecture / 2 Server Terpisah)**:
      - **App Node (Web Server + API + Queue Worker)**:
        - **CPU**: 4 vCPU
        - **RAM**: 8 GB RAM
        - **Storage**: 50 GB NVMe SSD
      - **DB Node (Dedicated Database Server)**:
        - **CPU**: 4 vCPU
        - **RAM**: 8 GB – 16 GB RAM
        - **Storage**: 100 GB – 150 GB NVMe SSD (High IOPS)
      - **Media Storage**: **Cloud Object Storage (Cloudflare R2 / AWS S3 / Google Cloud Storage)** untuk memisahkan beban file gambar dari server utama.
    - **Tuning Konfigurasi**:
      - Database Dedicated: `innodb_buffer_pool_size = 6G – 10G`, seluruh dataset user & presensi aktif tersimpan di RAM in-memory.
      - PHP-FPM: `pm.max_children = 60–80`, `request_terminate_timeout = 60s`.
      - Redis Cache: Dedicated Redis 1 GB untuk throttling, lock mutex auto-checkout, dan dispatching event real-time.
      - Cloudflare CDN: Caching aset frontend (Vite React JS, CSS, fonts, icons) di edge server global.

    ---

    #### 4. Tier 4: Skala Enterprise (1.000 – 5.000 Karyawan)
    - **Profil Beban**:
      - Lonjakan check-in/out: 1.500+ request dalam rentang 10 menit pagi hari.
      - 10 – 50 kantor cabang di seluruh Indonesia.
      - Rekap gaji/payroll bulanan memproses jutaan jam kerja dan ribuan lembar slip gaji.
      - Push notifikasi broadcast FCM massal ke ribuan smartphone dalam hitungan detik.
    - **Spesifikasi Infrastruktur (High Availability Cluster)**:
      - **Load Balancer**: Cloud Load Balancer (Nginx Reverse Proxy / AWS ALB / Cloudflare) dengan SSL Termination & DDoS Protection.
      - **Web / API Nodes (2 – 3 Instance)**:
        - Masing-masing: 4 vCPU, 8 GB RAM (Total: 8–12 vCPU, 16–24 GB RAM).
        - Menangani traffic HTTP stateless dengan shared Redis Session & Token Sanctum.
      - **Dedicated Background Worker Node (1 Instance)**:
        - **CPU**: 4 vCPU, 8 GB RAM.
        - Menjalankan 4–8 proses worker (OCR queue, FCM notifications, scheduled report exports, auto-checkout).
      - **Database Cluster (Master-Replica)**:
        - **Primary (Master - Write)**: 8 vCPU, 32 GB RAM, 250 GB NVMe SSD.
        - **Secondary (Read Replica - Read)**: 4 vCPU, 16 GB RAM (Didedikasikan untuk query report, dashboard rekap, dan payroll).
      - **Cache & Key-Value**: Managed Redis Cluster 2 GB – 4 GB RAM.
      - **Storage**: S3 / Cloudflare R2 (Unlimited scalability untuk berkas klaim, invoice, dan dokumen karyawan).

    ---

    #### 5. Tier 5: Holding Company & Korporasi Raksasa (5.000 – 20.000+ Karyawan)
    - **Profil Beban**:
      - Puluhan ribu karyawan aktif presensi di ratusan cabang/pabrik/outlet secara serentak.
      - Puluhan juta baris data presensi dan audit trail per tahun.
      - Kebutuhan SLA uptime 99.99% dengan Zero Downtime Deployment.
    - **Spesifikasi Infrastruktur (Kubernetes Auto-Scaling & Cloud-Native)**:
      - **Cluster Engine**: Kubernetes (AWS EKS / Google GKE / Azure AKS) dengan Horizontal Pod Autoscaler (HPA) menyesuaikan beban jam masuk kerja secara otomatis.
      - **Database Enterprise**: AWS Aurora MySQL Multi-AZ High Availability / Google Cloud SQL Enterprise Plus (16 – 32 vCPU, 64 GB – 128 GB RAM) + Connection Pooler (ProxySQL).
      - **Queue Messaging**: Cloud Managed Queue (AWS SQS / Google Cloud Pub/Sub) untuk pemrosesan paralel nir-batas.
      - **Search & Analytics**: Meilisearch / ElasticSearch untuk pencarian instan nama karyawan, struk, dan audit log jutaan data.
      - **CDN & WAF**: Cloudflare Enterprise / AWS CloudFront dengan Web Application Firewall & Bot Protection.

    ---

    ### C. Checklist Wajib Optimasi Server Sebelum Produksi
    1. **Aktifkan OPcache PHP**: Wajib mengaktifkan `opcache` dengan alokasi minimal 128MB–256MB untuk memangkas waktu eksekusi skrip PHP hingga 70%.
    2. **Tuning MySQL Buffer Pool**: Selalu atur `innodb_buffer_pool_size` minimal 60% – 70% dari total RAM yang tersedia pada server database agar query agregasi tetap berada di memori (0.17 ms).
    3. **Gunakan Queue Worker (Supervisor)**: Jangan pernah menjalankan pemrosesan OCR Vision, push notifikasi FCM, dan log audit di request lifecycle utama. Jalankan via `php artisan queue:work` yang dikelola oleh Supervisor.
    4. **Offload Media ke Object Storage**: Untuk instalasi di atas 500 karyawan, arahkan storage disk `receipts`, `invoices`, dan `avatars` ke AWS S3 atau Cloudflare R2 untuk menghemat IOPS dan mencegah harddisk server kepenuhan.
    5. **Gzip / Brotli & HTTP/2**: Pastikan Nginx mengaktifkan kompresi Brotli/Gzip dan protokol HTTP/2 untuk loading aset frontend React instan.
    6. **Automated Database Backup**: Jadwalkan backup database otomatis harian (e.g. `mysqldump` terenkripsi di-upload ke remote backup bucket) dengan retensi minimal 30 hari.
    7. **Analisis Bisnis & Valuasi Lengkap**: Rincian model bisnis, penetapan harga SaaS per user, analisis BEP, dan valuasi jual putus dapat dilihat pada dokumen: [doc/10-BUSINESS-PRICING-VALUATION-MODEL.md](file:///e:/koding/coba/backend-gawe/doc/10-BUSINESS-PRICING-VALUATION-MODEL.md).

---

## 11. Analisis & Roadmap Fitur: Face Recognition Presensi Karyawan (Rencana Fitur AI) 📋 ROADMAP / BACKLOG

> **Status:** KAJIAN TEKNIS & ROADMAP (Belum diimplementasikan — disiapkan untuk sprint fitur absensi cerdas anti-fraud).

Dokumentasi opsi teknologi, analisis kelebihan-kekurangan, dan arsitektur verifikasi wajah (*facial recognition & anti-spoofing*) untuk check-in/out selfie presensi karyawan di ExpenseFlow.

### A. Matriks Perbandingan Tools Face Recognition (Fase Development / Gratis)

| Pendekatan | Tool / Library | Biaya Dev & Prod | Tingkat Akurasi | Beban Server | Kebutuhan Internet | Kompleksitas Setup |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- |
| **1. On-Device Mobile (Edge AI)** | **Google ML Kit + TFLite (MobileFaceNet)** | **100% GRATIS** | 98.5% (Tinggi) | **0% (Diolah di HP)** | Bisa Offline | Sedang (Pre-processing tensor) |
| **2. Self-Hosted REST API Backend** | **CompreFace (Exadel Docker)** | **100% GRATIS (Open Source)** | 99.8% (ArcFace) | Sedang (RAM 2–4GB) | Perlu ke Server | Sangat Mudah (Docker compose) |
| **3. Microservice AI Python** | **DeepFace / InsightFace (FastAPI)** | **100% GRATIS (Open Source)** | 99.8% (SOTA) | Sedang (RAM 1–2GB) | Perlu ke Server | Sedang (Python service) |
| **4. Cloud API Managed** | **AWS Rekognition / Azure Face** | Gratis 5.000 req/bln (12 bln) | Enterprise Grade | 0% (Serverless) | Perlu Internet Cepat | Sangat Mudah (REST SDK) |

---

### B. Analisis Mendalam: Google ML Kit + TFLite (MobileFaceNet) di Flutter

#### Kelebihan:
1. **100% Gratis Selamanya**: Tanpa biaya langganan API cloud berbayar atau kuota bulanan.
2. **Latensi Sangat Cepat (< 200ms)**: Perhitungan embedding vector wajah dilakukan langsung di chipset HP tanpa jeda upload foto resolusi tinggi ke server.
3. **Nol Beban Server**: Server backend Laravel tidak perlu kartu grafis (GPU) atau CPU besar untuk inferensi neural network.

#### Kekurangan & Tantangan Nyata:
1. **Kerentanan Keamanan Sisi Klien (*Client-Side Vulnerability*)**:
   - Karena perbandingan kemiripan berjalan di aplikasi Flutter, perangkat yang di-*root* atau dimodifikasi (*reverse engineering/Frida*) berisiko memalsukan status verifikasi (`isMatched = true`) sebelum data check-in dikirim ke backend Laravel.
2. **Anti-Spoofing 2D Terbatas**:
   - Kamera HP standar membaca citra 2D datar tanpa sensor kedalaman (3D depth sensor seperti Apple TrueDepth). Jika liveness detection hanya mengandalkan kedipan mata, masih berpotensi diakali dengan rekaman video atau cetakan foto resolusi tinggi.
3. **Beban Komputasi di HP Low-End (Android Entry-Level)**:
   - Stream kamera + ekstraksi tensor wajah memakan CPU/RAM yang menyebabkan *frame drop*, HP cepat panas, inferensi lambat (1–2 detik di chipset kelas bawah), atau potensi crash Out of Memory (OOM).
4. **Ukuran APK Membengkak (+20 MB – 40 MB)**:
   - Binary C++ TFLite native (`libtensorflowlite.so`), file model `.tflite` (~4–6 MB), dan Google Play Services ML Kit native bindings menambah bobot APK.
5. **Kompleksitas Image Pre-Processing di Flutter**:
   - Konversi format stream kamera raw (`YUV420/NV21` Android, `BGRA` iOS) ke Bitmap RGB normalisasi `112x112 pixel` rentan bug rotasi sensor kamera depan (0°, 90°, 270°).
6. **Kesulitan Pembaruan Model AI**:
   - Peningkatan akurasi model AI di kemudian hari mewajibkan rilis update APK/AAB di Play Store & App Store (semua karyawan wajib update app).

---

### C. Rekomendasi Arsitektur Terbaik (Hybrid Architecture)

Untuk menghasilkan sistem absensi wajah yang **100% murah/gratis**, **aman dari kecurangan HP root**, dan **ringan bagi HP karyawan**:

1. **Sisi Mobile (Flutter)**:
   - Gunakan `google_mlkit_face_detection` **hanya** untuk:
     - Deteksi keberadaan wajah di dalam area lingkaran pandu (*guide frame*).
     - *Passive liveness check* (memastikan mata terbuka & berkedip sebelum tombol ambil foto aktif).
2. **Sisi Backend (Laravel + CompreFace / DeepFace Container)**:
   - Foto selfie dikirim ke Laravel bersama payload absensi GPS.
   - Laravel memverifikasi kemiripan wajah via container gratis **CompreFace** (`/api/v1/recognition/faces/verify`) terhadap foto master karyawan di database.
   - Keputusan valid/tidaknya absensi murni ditentukan oleh server (aman dari manipulasi HP root) dan ukuran APK Flutter tetap kecil & ringan.

---

## 12. Sistem Keamanan Anti-Fake GPS & Mock Location Presensi Mobile (2026-08-31) ✅ AKTIF

Sistem keamanan anti-kecurangan lokasi palsu (*Anti-Fake GPS / Mock Location*) untuk memastikan titik koordinat check-in dan check-out presensi mobile karyawan valid dan bebas manipulasi, dengan prinsip **Zero False-Positive** (tidak salah mendeteksi karyawan jujur).

### A. Prinsip Kerja & Zero False-Positive Design
1. **Pengecekan Berbasis OS Asli (`position.isMocked`)**:
   - Memanfaatkan fitur native Android (`location.isMock()`) dan iOS melalui package `geolocator`.
   - **Bebas False-Positive**: Sistem **TIDAK** memblokir karyawan hanya karena mengaktifkan *Developer Options* atau *USB Debugging* biasa (misal untuk transfer file atau tema HP). Flag `isMocked` hanya bernilai `true` jika karyawan secara eksplisit memilih aplikasi pemalsu lokasi di menu *"Select mock location app"*.
2. **Tidak Menggunakan Root-Checker Agresif**:
   - Menghindari pemblokiran palsu pada fitur bawaan HP seperti *Dual Apps / Second Space* di Xiaomi/MIUI, Oppo/ColorOS, atau Infinix.

### B. Validasi Sisi Klien (Flutter Mobile)
1. **Pencegahan Langsung di Halaman Presensi (`presensi_map_screen.dart`)**:
   - Saat `_position.isMocked == true`:
     - Tombol simpan presensi dinonaktifkan / memicu dialog edukasi panduan.
     - Muncul banner peringatan merah: *"Fake GPS / Lokasi Palsu terdeteksi aktif. Klik untuk panduan."*
2. **Dialog Edukasi Solutif**:
   - Jika terdeteksi lokasi palsu, aplikasi tidak hanya menampilkan pesan error, melainkan memberikan langkah spesifik:
     1. Matikan aplikasi Fake GPS.
     2. Buka Pengaturan HP > Opsi Pengembang (Developer Options).
     3. Ubah *Pilih aplikasi lokasi palsu* menjadi *Tidak Ada (None)*.
     4. Tekan tombol Refresh di halaman presensi.
3. **Payload Signature Integrity**:
   - Parameter `is_mocked` dikirimkan dalam payload API `POST /attendance/check-in` dan `POST /attendance/check-out`.

### C. Validasi Sisi Server (Laravel Backend)
1. **Proteksi di Controller (`AttendanceController.php`)**:
   - `checkIn` dan `checkOut` memvalidasi `$request->boolean('is_mocked')`.
   - Jika bernilai `true`, backend langsung menolak dengan response HTTP 403:
     ```json
     {
       "message": "Presensi ditolak. Terdeteksi penggunaan aplikasi Fake GPS / Mock Location pada perangkat Anda. Harap matikan aplikasi pemalsu lokasi untuk melanjutkan.",
       "fake_gps_detected": true
     }
     ```
2. **Testing & Integrity**:
   - Teruji otomatis via unit test `test_checkin_fake_gps_ditolak` dan `test_checkout_fake_gps_ditolak` (100% lulus).

---

## 13. Integrasi Pengajuan WFH Otomatis & Validasi Filter Jadwal Kerja / Libur (2026-08-31) ✅ AKTIF

Sistem pengajuan Work From Home (WFH) karyawan terintegrasi dengan validasi jadwal kerja, kalender libur, dan sistem presensi mobile secara otomatis.

### A. Validasi Cerdas & Auto-Skipping Tanggal Pengajuan WFH (`leave_type === 'wfh'`)
Saat karyawan mengajukan WFH (baik via preview `GET /attendance/leave-preview` maupun submit `POST /attendance/leave-request`), sistem backend secara otomatis menyaring (`skip`) tanggal-tanggal yang tidak memenuhi syarat:
1. **Sudah Terjadwal WFH (`already_wfh`)**:
   - Jika shift karyawan pada tanggal tersebut sudah berstatus WFH (`is_wfh = true`) atau karyawan memiliki status WFH global permanen (`wfh_enabled = true` & `radius_enabled = false`).
   - Karyawan tidak perlu lagi mengajukan WFH untuk hari yang memang sudah dijadwalkan WFH.
2. **Hari Libur & Tanggal Merah (`holiday_or_off_day`)**:
   - Libur nasional, libur perusahaan, dan libur cabang penempatan karyawan otomatis dilewati.
3. **Cuti Bersama (`holiday_or_off_day`)**:
   - Cuti bersama yang telah di-accept karyawan dilewati.
4. **Off-Day Shift & Libur Mingguan (`holiday_or_off_day`)**:
   - Hari libur shift / hari non-kerja kantor dilewati.
5. **Pengajuan Lain yang Bentrok (`already_requested`)**:
   - Tanggal yang sudah memiliki izin, sakit, cuti mandiri, atau WFH sebelumnya (status pending/approved) otomatis dilewati.
6. **Guard Validasi**:
   - Pengajuan hanya dihitung dari sisa **hari kerja kantor biasa (onsite)** yang valid. Jika tidak ada hari kerja valid tersisa (misal seluruh rentang adalah libur/sudah WFH), backend mengembalikan HTTP 422.

### B. Aktivasi Presensi WFH Otomatis Setelah Disetujui HRD
Ketika HRD menyetujui (`approve`) pengajuan WFH karyawan:
1. **Bypass Pembatasan Presensi Kantor**:
   - Karyawan onsite biasa (yang normalnya `wfh_enabled = false`) pada tanggal efektif yang disetujui otomatis diizinkan melakukan presensi masuk dan pulang via mobile (`checkIn` & `checkOut`).
2. **Bypass Validasi Radius Kantor**:
   - Validasi jarak radius kantor dilewati sehingga karyawan dapat melakukan presensi dari rumah tanpa ditolak radius.
3. **Pencatatan Record Presensi**:
   - Kolom `check_in_type` dan `check_out_type` tercatat sebagai `'wfh'`.
4. **Sinkronisasi Status & Kalender Mobile**:
   - Endpoint `GET /attendance/status` dan `GET /attendance/my` mengembalikan `wfh_enabled = true` dan `is_wfh_approved = true` pada tanggal yang disetujui.
   - Endpoint `GET /attendance/my-schedule-calendar` menandai tanggal tersebut dengan `is_wfh = true` dan `wfh_approved = true`.

---

## 14. Standar Spesifikasi Perangkat HP (Smartphone Requirements) untuk Aplikasi Mobile ExpenseFlow (2026-08-31) ✅ PANDUAN PENGGUNA

Panduan spesifikasi minimum dan rekomendasi perangkat telepon pintar (*smartphone*) Android dan iOS untuk menginstal serta menjalankan aplikasi mobile **ExpenseFlow** secara lancar, responsif, dan optimal.

### A. Matriks Spesifikasi Perangkat (Android & iOS)

| Komponen / Fitur | Spesifikasi Minimum (*Minimum Requirements*) | Spesifikasi Rekomendasi (*Recommended*) | Catatan & Dampak Fungsional |
| :--- | :--- | :--- | :--- |
| **Sistem Operasi (Android)** | **Android 8.0 (Oreo / API Level 26)** | **Android 11.0 – Android 15.0+** | Kompatibilitas library Flutter 3.x, enkripsi data lokal, dan keamanan token JWT. |
| **Sistem Operasi (iOS)** | **iOS 14.0** (iPhone 7 / iPhone SE Gen 1+) | **iOS 16.0 – iOS 18.0+** (iPhone 11 ke atas) | Dukungan runtime Swift, APNs (Apple Push Notification), dan otorisasi privasi. |
| **Memori RAM** | **2 GB RAM** | **3 GB – 4 GB RAM+** | Memastikan aplikasi tidak force-close (*OOM*) saat membuka stream kamera resolusi tinggi & render peta koordinat simultan. |
| **Penyimpanan Bebas (*Free Storage*)** | **Minimal 150 MB** | **500 MB – 1 GB+** | Digunakan untuk instalasi APK (~25–35 MB), asset cache, thumbnail gambar struk, dan file sementara (*temporary files*). |
| **Prosesor / Chipset** | Quad-Core 1.5 GHz (ARMv7 / ARM64) | Octa-Core 2.0 GHz+ (Snapdragon 600/700/800 series, MediaTek Helio/Dimensity, Apple Bionic) | Menghasilkan navigasi UI mulus (60 FPS) dan kecepatan rendering halaman. |
| **Kamera Belakang** | **5.0 Megapixel (MP)** | **8.0 MP – 12.0 MP+ (dengan Auto-Focus & Flash)** | **Kritis untuk OCR**: Resolusi kamera dan ketajaman fokus sangat menentukan keberhasilan ekstraksi otomatis teks struk belanja. |
| **Kamera Depan** | 2.0 Megapixel (MP) | 5.0 MP – 8.0 MP+ | Digunakan untuk foto selfie verifikasi kehadiran (jika fitur selfie absensi diaktifkan). |
| **Modul Sensor Lokasi (GPS)** | **A-GPS / GLONASS bawaan HP** | Multi-Band GNSS (GPS, GLONASS, Galileo, BeiDou) | **Kritis untuk Presensi**: Akurasi tinggi (*high accuracy*) diperlukan untuk validasi radius geofence kantor (toleransi 10–50 meter). |
| **Konektivitas Internet** | 3G / HSPA / Wi-Fi stabil (Min. 512 Kbps) | **4G LTE / 5G / Wi-Fi broadband** (Min. 2 Mbps) | Upload foto struk (ukuran 500 KB – 2 MB per struk) dan sinkronisasi realtime status persetujuan. |
| **Layar & Resolusi** | HD 720 × 1280 px (rasio 16:9) | FHD+ 1080 × 2400 px (rasio 18:9, 19.5:9, 20:9) | Tampilan UI Material 3, kalender range picker, dan kartu statistik presensi ter-render proporsional. |
| **Google Play Services (GMS)** | **Wajib Aktif (versi terbaru)** | Versi terupdate otomatis | Diperlukan untuk Firebase Cloud Messaging (FCM Push Notification) dan Google Play Location Provider. |

---

### B. Izin Aplikasi (*App Permissions*) yang Wajib Diberikan

Agar seluruh fitur aplikasi mobile dapat berjalan normal tanpa kendala, karyawan wajib menyetujui izin-izin berikut saat pertama kali membuka aplikasi:

1. **Izin Lokasi (*Location Permission*)**:
   - **Tingkat Akses**: Pilih *"Saat Aplikasi Digunakan"* (*While using the app*).
   - **Mode Presisi**: Wajib mengaktifkan opsi *"Presisi Tepat / Lokasi Akurat"* (*Precise Location*). Jangan gunakan mode *Approximate* agar titik koordinat tidak melenceng dari radius kantor.
2. **Izin Kamera (*Camera Permission*)**:
   - Diperlukan saat memotret struk belanja untuk klaim reimbursement dan mengambil foto selfie presensi.
3. **Izin Notifikasi (*Notification Permission - Khusus Android 13+ & iOS*)**:
   - Wajib diizinkan (*Allow*) agar pengingat jam checkout otomatis (*checkout reminder*), notifikasi persetujuan lembur, cuti, dan pengumuman perusahaan muncul di layar HP.
4. **Izin Penyimpanan / Foto (*Photos / Storage Permission*)**:
   - Diperlukan saat karyawan ingin melampirkan file surat dokter (format PDF atau gambar dari galeri) pada pengajuan izin/sakit.

---

### C. Panduan Pengaturan Khusus Merek HP (Optimasi Latar Belakang & Notifikasi)

Beberapa sistem antarmuka Android bawaan pabrikan memiliki manajemen baterai agresif yang dapat mematikan notifikasi push atau pengingat checkout. Berikut rekomendasi pengaturannya:

1. **Xiaomi / Redmi / POCO (MIUI / HyperOS)**:
   - Buka *Pengaturan > Aplikasi > Kelola Aplikasi > ExpenseFlow*:
     - Aktifkan **Mulai Otomatis (*Autostart*)**.
     - Ubah *Penghemat Baterai* menjadi **Tidak ada pembatasan (*No restrictions*)**.
2. **Oppo / Realme (ColorOS / Realme UI)**:
   - Buka *Pengaturan > Manajemen Aplikasi > ExpenseFlow*:
     - Aktifkan **Izinkan aktivitas latar belakang (*Allow background activity*)**.
     - Di bagian *Baterai*, nonaktifkan *Pengoptimalan Baterai*.
3. **Vivo / iQOO (Funtouch OS)**:
   - Buka *Pengaturan > Baterai > Penggunaan Baterai di Latar Belakang*:
     - Pilih aplikasi *ExpenseFlow* dan ubah ke **Izinkan penggunaan latar belakang tinggi (*High background power usage*)**.
4. **Samsung (One UI)**:
   - Buka *Pengaturan > Aplikasi > ExpenseFlow > Baterai*:
     - Pilih opsi **Tidak Dibatasi (*Unrestricted*)**.
     - Pastikan aplikasi tidak dimasukkan ke dalam daftar *Sleeping apps* atau *Deep sleeping apps*.

---

### D. Perangkat & Kondisi yang TIDAK Didukung / Dilarang (*Unsupported Devices*)

1. **Perangkat dengan Aplikasi Pemalsu Lokasi Aktif (*Mock Location / Fake GPS*)**:
   - Sistem secara otomatis memblokir tombol presensi dan menolak request check-in/out jika terdeteksi manipulasi GPS (`is_mocked = true`).
2. **HP Android Tanpa Layanan Google (*Non-GMS / Huawei HMS murni*)**:
   - Model smartphone tanpa Google Play Services (misal seri Huawei baru tanpa GMS) tidak dapat menerima notifikasi push Firebase Cloud Messaging (FCM) secara realtime dan memerlukan pembaruan status manual.
3. **Perangkat Emulator PC (Bluestacks, Nox, LDPlayer)**:
   - Penggunaan emulator PC untuk absensi mobile sangat tidak disarankan karena tidak memiliki sensor kamera asli dan koordinat GPS fisik yang akurat.

---

# note untuk refaktoring
perbaiki dulu error di atas ,

---

## Panduan State Management Frontend (React Web)

> **Konteks:** `expenseflow-web` menggunakan React dengan State terpusat di `App.tsx` dan state lokal di dalam masing-masing komponen.
> Aturan ini menentukan data mana yang **BOLEH** disimpan di state terpusat (`App.tsx` / Global Cache / Context) dan mana yang **WAJIB** dimuat lokal (*on-demand* per halaman).

---

### ✅ BOLEH — State Terpusat / Global Cache

Data berikut **boleh dan dianjurkan** disimpan di state terpusat karena bersifat **statis, jarang berubah, dan dipakai bersama oleh banyak halaman**:

| Data | Endpoint | Alasan |
|---|---|---|
| **Profil user yang sedang login** | `GET /me` | Dipakai di header, sidebar, dan setiap validasi role. Hanya berubah saat user update profil sendiri. |
| **Pengaturan aplikasi keuangan** (`AppSettings`) | `GET /dashboard/settings` | Dipakai di halaman Struk, Invoice, dan Settings. Hanya berubah jika admin mengubah batas klaim/variance. |
| **Daftar cabang kantor** (`offices`) | `GET /dashboard/attendance/settings` | Dipakai di dropdown Karyawan, Presensi, dan Shift. Sangat jarang berubah. ✅ **Sudah diimplementasikan dengan in-memory cache TTL 60 detik.** |
| **Jumlah badge notifikasi** (unread count) | `GET /dashboard/notifications` | Ditampilkan di sidebar/header seluruh halaman. |
| **Jumlah badge lembur & device change pending** | `GET /overtime?status=pending`, `/device-changes?status=pending` | Ditampilkan di badge menu sidebar. |

---

### ❌ DILARANG — State Terpusat (Wajib Dimuat On-Demand per Halaman)

Data berikut **dilarang** disimpan di state terpusat karena bersifat **dinamis, bervolume besar, atau hanya relevan untuk 1 halaman spesifik**:

| Data | Endpoint | Alasan |
|---|---|---|
| **Presensi hari ini** (`today`) | `GET /dashboard/attendance/today` | Berubah setiap menit (karyawan check-in/out real-time). Harus fresh setiap kali halaman dibuka. |
| **Daftar pengajuan izin/cuti** (`leaves`) | `GET /dashboard/attendance/leaves` | Bervolume besar (bisa ribuan baris). Hanya dibutuhkan saat halaman Presensi aktif. |
| **Daftar karyawan** (`users`) | `GET /dashboard/attendance/users` | Bervolume besar. Hanya dibutuhkan di halaman Karyawan & Presensi. |
| **Laporan presensi** (`report`) | `GET /dashboard/attendance/report` | Bervolume sangat besar + filter dinamis. Wajib on-demand dengan paginasi. |
| **Kalender libur** (`holidays`) | `GET /dashboard/attendance/holidays` | Hanya dibutuhkan di tab Kalender Libur. |
| **Saldo cuti karyawan** (`leave-balances`) | `GET /dashboard/attendance/leave-balances` | Hanya dibutuhkan di tab Saldo Cuti. |
| **Daftar lembur** (`overtime`) | `GET /dashboard/attendance/overtime` | Hanya dibutuhkan di halaman Persetujuan Lembur. |
| **Daftar request ganti perangkat** (`device-changes`) | `GET /dashboard/attendance/device-changes` | Hanya dibutuhkan di halaman Device Change. |
| **Daftar shift** (`shifts`) | `GET /dashboard/shifts` | Hanya dibutuhkan di halaman Manajemen Shift. |
| **Log audit** (`activity-logs`) | `GET /dashboard/activity-logs` | Hanya dibutuhkan di halaman Audit Log. Tidak perlu realtime. |
| **Riwayat pengajuan rekrutmen** (`job-applications`) | `GET /recruitment/applications` | Hanya dibutuhkan di halaman Rekrutmen. Bervolume besar. |
| **Rekap saldo cuti historis** (`leave-balance-histories`) | `GET /dashboard/attendance/leave-balance-histories` | Hanya dibutuhkan di sub-tab Riwayat Saldo. |

---

### ⚠️ PERHATIAN KHUSUS — Struk & Invoice (State Terpusat dengan Batasan)

Struk dan Invoice saat ini dimuat di state terpusat (`App.tsx`) karena dipakai di beberapa tempat, namun ada batasan yang harus dijaga:

| Aturan | Detail |
|---|---|
| **Inbox struk & invoice**: BOLEH state terpusat | Jumlah item inbox biasanya kecil (hanya yang `pending`). |
| **Riwayat struk & invoice**: HATI-HATI | Berpotensi besar jika perusahaan sudah lama beroperasi. Pertimbangkan paginasi server-side jika data > 1.000 baris. |
| **Selalu refresh setelah aksi approve/reject/pay** | Panggil ulang loader (`loadReceipts()`, `loadInvoices()`) setiap setelah mutasi data agar state tidak basi. |

---

### 🏗️ Implementasi Cache yang Sudah Ada

Berikut adalah cache yang sudah diimplementasikan di `src/services/endpoints.ts`:

```typescript
// attendanceApi.settings — In-memory cache TTL 60 detik
attendanceApi.settings.list()          // Membaca cache jika masih valid
attendanceApi.settings.list(true)      // forceRefresh=true untuk paksa fetch ulang
attendanceApi.settings.clearCache()    // Kosongkan cache manual

// settingsApi — In-memory cache TTL 60 detik
settingsApi.get()                      // Membaca cache jika masih valid
settingsApi.get(true)                  // forceRefresh=true
settingsApi.clearCache()               // Kosongkan cache manual
```

**Aturan:** Cache **wajib di-clear** setelah operasi `create`, `update`, atau `destroy` pada data yang bersangkutan agar data tidak basi.

---

### 📐 Ringkasan Keputusan Cepat

```
Pertanyaan sebelum memutuskan strategi data:

1. Apakah data ini dipakai di LEBIH DARI 1 halaman berbeda?
   → YA  : Pertimbangkan state terpusat atau cache.
   → TIDAK: Wajib on-demand lokal di komponen tersebut.

2. Apakah data ini bisa berubah setiap menit (real-time)?
   → YA  : DILARANG state terpusat. Selalu on-demand saat halaman dibuka.
   → TIDAK: Aman dijadikan cache.

3. Apakah jumlah baris data ini bisa > 500 baris?
   → YA  : DILARANG state terpusat. Wajib paginasi server-side.
   → TIDAK: Bisa dipertimbangkan state terpusat.
```

---

*Bagian ini ditambahkan pada 2026-09-02 berdasarkan analisis bottleneck performa Network tab (37 request serentak saat load pertama).*