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
        UserController.php          ← user: index, store, update, deactivate, resetPassword
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
| Role | Platform | Scan Struk (Mobile) | Approval Struk (Web) | Akses Presensi | Approval Invoice |
|------|----------|---------------------|----------------------|----------------|-----------------|
| `employee` | HANYA mobile | ✅ CRUD struk sendiri | ❌ | ✅ (jika attendance_enabled) | ❌ |
| `finance` | mobile + web | ✅ scan & submit struk sendiri | ✅ approval struk karyawan | ✅ (jika attendance_enabled) | Level 1 (Finance Manager) |
| `hrd` | mobile + web | ✅ scan & submit struk sendiri | ❌ **TIDAK ada akses approval struk** | ✅ (jika attendance_enabled) | Level 1 (Finance Manager) |
| `admin` | mobile + web | ✅ scan & submit struk sendiri | ✅ approval struk karyawan | ✅ (jika attendance_enabled) | Level 1 + Level 2 (+ Direksi) |
| `super_admin` | mobile + web | ✅ scan & submit struk sendiri | ✅ approval struk karyawan | ✅ (selalu) | Level 1 + 2 + 3 (+ Komisaris) |

> **Scan Struk Mobile:** Semua role bisa upload foto, scan OCR, dan submit struk pengeluaran sendiri
> via Flutter. Setiap user hanya bisa lihat & kelola struk miliknya sendiri (ownership check di controller).

> **Approval Struk Web (Dashboard):** Tetap **khusus finance, admin, super_admin** — HRD dikecualikan.
> Route `dashboard/receipts*` memakai middleware `role:finance,admin,super_admin` (tanpa `hrd`).

> **Catatan akses manajemen:** Menu **Manajemen** (Karyawan + Presensi & Cuti) adalah ranah
> **HRD/admin/super_admin**. **Finance dikecualikan** — route `admin/users*` dan `dashboard/attendance*`
> sudah memakai middleware `role:hrd,admin,super_admin` (tanpa `finance`), dan kedua menu disembunyikan
> di web untuk finance. Finance fokus ke approval struk & invoice.

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
| < Rp 10.000.000 | Level 1 | Finance Manager (finance, hrd, admin, super_admin) |
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

### Dashboard (auth:sanctum + role:finance,hrd,admin,super_admin + company)
```
# Receipt Approval — KHUSUS finance,admin,super_admin (HRD dikecualikan via nested role)
GET  /api/v1/dashboard/receipts             → inbox (struk menunggu approval)
GET  /api/v1/dashboard/receipts/all         → dashboardReceipts (filter status + summary)
GET  /api/v1/dashboard/receipts/{id}        → show (detail, no ownership check)
GET  /api/v1/dashboard/receipts/{id}/image  → image (stream foto struk)
POST /api/v1/dashboard/receipts/{id}/approve → approve
POST /api/v1/dashboard/receipts/{id}/reject  → reject

# Vendor Management
GET  /api/v1/dashboard/vendors              → index (list vendor perusahaan)
POST /api/v1/dashboard/vendors              → store (tambah vendor)
PATCH /api/v1/dashboard/vendors/{id}        → update
POST /api/v1/dashboard/vendors/{id}/toggle  → toggleActive

# Invoice
GET  /api/v1/dashboard/invoices             → index (list + filter status + summary)
GET  /api/v1/dashboard/invoices/{id}        → show (detail + items + approvals)
POST /api/v1/dashboard/invoices             → store (input invoice manual)
POST /api/v1/dashboard/invoices/{id}/approve → approve (multi-level)
POST /api/v1/dashboard/invoices/{id}/reject  → reject

# Receipt image (foto struk privat untuk web)
GET  /api/v1/dashboard/receipts/{id}/image  → image (stream file)

# Notifikasi
GET    /api/v1/dashboard/notifications        → index (+ unread_count)
POST   /api/v1/dashboard/notifications/read-all → markAllRead
POST   /api/v1/dashboard/notifications/{id}/read → markRead
DELETE /api/v1/dashboard/notifications/{id}   → destroy

# Audit log
GET  /api/v1/dashboard/activity-logs        → index (filter action, entity_type)

# Pengaturan threshold & batas klaim
GET       /api/v1/dashboard/settings        → index
PUT/PATCH /api/v1/dashboard/settings        → update (upsert company_settings)
```

### Admin (auth:sanctum + role:hrd,admin,super_admin + company)
```
GET  /api/v1/admin/users                    → index (list karyawan)
POST /api/v1/admin/users                    → store (tambah user) [+ role:admin,super_admin]
PUT  /api/v1/admin/users/{id}               → update
PATCH /api/v1/admin/users/{id}/deactivate   → deactivate
POST /api/v1/admin/users/{id}/reset-password → resetPassword
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
GET  /api/v1/attendance/status              → checkStatus (status presensi hari ini + scheduled_auto_checkout_at)
```

### Leave & Lembur (auth:sanctum + company, tanpa gerbang attendance_access)
```
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
fitur apa yang masih kurang dan menimbulkan **risiko bisnis** atau **kehilangan nilai**.
Saat ini aplikasi kuat di sisi *input & approval*, tapi lemah di sisi **siklus uang keluar
(pembayaran)**, **kontrol anggaran**, dan **payroll/HR lanjutan**.

## Prioritas

| Prioritas | Fitur | Kenapa penting (risiko bila tidak ada) |
|---|---|---|
| **P0 — Kritis** | Pelacakan pembayaran (disbursement) | Setelah struk/invoice `approved`, **tidak ada status "dibayar"** yang sebenarnya. Tidak tahu mana yang sudah ditransfer → risiko bayar dobel / tidak terbayar. |
| **P0 — Kritis** | Enforce batas klaim & anggaran | `monthly_claim_limit` (user) & `max_claim_limit` (setting) ada di DB tapi **belum ditegakkan** saat submit. Karyawan bisa klaim melebihi batas → bocor anggaran. |
| **P0 — Kritis** | Delegasi / backup approver | Jika approver (mis. satu-satunya super_admin/admin) sedang cuti, **approval macet**. Perlu mekanisme delegasi sementara atau approver cadangan. |
| **P1 — Tinggi** | Deteksi invoice/struk duplikat | Belum ada cek duplikat berbasis (vendor + nominal + tanggal). Struk sudah pakai SHA-256, tapi invoice manual rawan diinput 2x → bayar dobel. |
| **P1 — Tinggi** | Eskalasi & reminder approval (SLA) | Invoice/struk `pending` bisa mengendap tanpa batas. Perlu reminder otomatis + eskalasi bila lewat SLA (mis. 3 hari) → cash flow & hubungan vendor terjaga. |
| **P1 — Tinggi** | Modul payroll (lanjutan `monthlySummary`) | `monthlySummary` disebut "fondasi payroll" tapi belum ada perhitungan gaji/potongan/THR. Ini nilai bisnis terbesar berikutnya untuk HR. |
| **P2 — Menengah** | Purchase Order (PO) workflow | `po_number` hanya kolom teks, tanpa siklus PO → GR → invoice matching (3-way match). Kontrol pengadaan lemah. |
| **P2 — Menengah** | OCR untuk invoice vendor | "Scan Invoice" web masih disimpan sebagai invoice manual (`invoice_images` ada, OCR belum). Input manual lambat & rawan salah ketik. |
| **P2 — Menengah** | Dashboard analitik pengeluaran | Belum ada tren spend per kategori/departemen/vendor. Manajer butuh visibilitas untuk pengambilan keputusan. |
| **P2 — Menengah** | Ekspor ke software akuntansi | Belum ada integrasi/format ekspor (mis. Accurate, Jurnal, atau jurnal umum). Rekonsiliasi manual memakan waktu. |
| **P3 — Nice to have** | Pembayaran parsial & cicilan invoice | Saat ini approve = lunas penuh. Tidak mendukung termin pembayaran. |
| **P3 — Nice to have** | 2FA untuk role finance/admin | Akun yang menyetujui uang sebaiknya pakai 2FA — memperkuat kontrol internal. |
| **P3 — Nice to have** | Pengajuan koreksi presensi | Karyawan lupa check-out / salah catat butuh alur koreksi yang di-approve HRD. |

## Catatan Desain Penting

### Siklus pembayaran (yang paling kritis)
Tambahkan status & tabel pembayaran agar siklus uang keluar lengkap:
```
receipt/invoice: approved → scheduled (dijadwalkan bayar) → paid (lunas)
```
- Tabel baru `payments` (entity_type, entity_id, amount, method, paid_at, paid_by, reference_no, bank_account).
- Rekening tujuan: untuk struk → rekening karyawan; untuk invoice → rekening vendor (`vendors.bank_*` sudah ada).
- Cegah pembayaran dobel: satu entity hanya boleh punya satu pembayaran `paid` aktif.

### Enforce batas klaim
Saat `submit` struk: jumlahkan klaim `approved` + `submitted` bulan berjalan, tolak (422)
bila melebihi `monthly_claim_limit` user (fallback ke `max_claim_limit` company).

### Delegasi approver
Tabel `approval_delegations` (from_user_id, to_user_id, start_date, end_date, scope).
Saat cek role approval, sertakan user yang menerima delegasi aktif. Mengatasi kasus
"super_admin disimpan untuk level tertinggi" ketika approver tunggal berhalangan.

### Kalender libur & cuti
Tabel `holidays` (company_id, date, name). Dipakai untuk:
- Hitung `total_days` cuti tanpa weekend & libur.
- Penentuan `present/late/absent` (tidak menandai absen di hari libur).

> Urutan implementasi yang disarankan: **P0 → P1 → P2 → P3**. Mulai dari siklus
> pembayaran & enforce anggaran karena keduanya berdampak langsung ke uang perusahaan.
### fiture depannya


- buat web site terpisa untuk calon rekrutmen kerja.
- buat form untuk hrd untuk kualifikasi calon karyawan 
- list / halaman untuk hrd untuk menyeleksi calon karyawan yang masuk sesuai kualifikasi 

- karyawan bisa di ajust oleh hrd masuk di hari sabtu atau minggu
  → ✅ SELESAI 2026-07-04 (fitur Custom Shift/Scheduling, lihat perubahan.md sesi 2026-07-04)

- reminder bug, super admin tidak bisa approvel invoice lv 3 

---

# Roadmap Fitur Payroll (Gaji)
> 📄 **Dokumentasi & Spesifikasi Lengkap Telah Dipindahkan:**  
> Roadmap detail, analisis kekurangan data/tabel, aturan PPh 21 TER 2024 (PP 58/2023), BPJS Ketenagakerjaan & Kesehatan, lembur (PP 35/2021), THR (Permenaker 6/2016), multi-cabang, skema database, dan daftar API endpoint kini terdokumentasi lengkap di:  
> **👉 [doc/07-PAYROLL-ROADMAP.md](07-PAYROLL-ROADMAP.md)**

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


buat endpoint untuk delete user, tapi user harus nonaktif terlebih dahulu lalu hapus user dengan verifikasi type delete 

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


# note untuk refaktoring
perbaiki dulu error di atas , 