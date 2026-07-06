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
- **Auth**     : Sanctum token, expired 24 jam
- **Rate Limit**: Login 5 attempts/menit/IP

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
| 18 | `attendance_settings` | Pengaturan kantor (company_id, office_name, office_latitude, office_longitude, radius_meters default 100, work_start_time default 08:00, work_end_time default 17:00, late_tolerance_minutes default 15, require_selfie, allow_wfh, wfh_checkin_window_minutes, **overtime_enabled** default true, **min_overtime_minutes** default 30, **checkout_reminder_minutes** default 30, **auto_checkout_grace_minutes** default 60) |
| 19 | `leave_requests` | Pengajuan cuti/izin (user_id, company_id, leave_type [wfh/izin/sakit/cuti], start_date, end_date, total_days, reason, status [pending/approved/rejected], approved_by, approved_at, rejection_reason) |
| 20 | `leave_balances` | Saldo cuti (user_id, company_id, year, leave_type, quota, used) |
| 20b | `holidays` | Kalender libur (company_id **nullable** → NULL = libur nasional semua company, date, name, is_national). Unique (company_id, date). Dipakai untuk hitung hari kerja cuti & lembur hari libur. |
| 20c | `overtime_approvals` | Approval lembur (attendance_id, user_id, company_id, overtime_minutes, status [pending/approved/rejected], reviewed_by, reviewed_at, notes, is_auto_checkout). Dibuat saat checkout jika ada lembur. |
| 20d | `shifts` | Template shift (company_id, **attendance_setting_id** nullable=milik cabang/null=company-wide, name, description, is_active). Ditambah 2026-07-04. |
| 20e | `shift_schedules` | Detail 7 hari per shift (shift_id, day_of_week 0=Minggu–6=Sabtu, work_start_time, work_end_time, is_off). Unique(shift_id, day_of_week). |
| 20f | `user_shifts` | Assignment shift ke karyawan (user_id, shift_id **nullable**=default kantor, start_date, notes). Unique(user_id, start_date). **Shift aktif = start_date terbaru yang ≤ hari ini.** |

### Laravel Defaults
| # | Tabel | Keterangan |
|---|-------|-----------|
| 21 | `failed_jobs` | Queue job yang gagal |
| 22 | `cache` | Cache (Laravel default) |
| 23 | `jobs` / `job_batches` | Queue (Laravel default) |

---

## Role System — SANGAT PENTING

### Role yang Tersedia
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
- Karyawan hanya boleh edit: `category` dan `notes`
- Jika OCR gagal: karyawan boleh isi manual `claimed_amount`, `total_amount`, `receipt_date`, `vendor_name`
- Variance flag: otomatis `true` jika selisih claimed vs ocr_raw > 10%
- Variance pct: `abs(claimed - ocrRaw) / ocrRaw * 100`

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
- Rate limited: 5 attempts per menit per IP
- Password: min 8, max 255 karakter
- Token expired: 24 jam
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
GET  /api/v1/attendance/holidays            → listHolidays (read-only, untuk kalender mobile)
GET  /api/v1/attendance/my-overtime         → myOvertimeApprovals (riwayat status lembur karyawan)
POST /api/v1/attendance/fcm-token           → registerFcmToken (simpan FCM token device untuk push notif)
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

## Leave (Cuti/Izin) Pipeline
```
Karyawan requestLeave (via mobile, tanpa gerbang attendance_access)
  → leave_type: wfh / izin / sakit / cuti
  → Hitung total_days
  → Status: pending
  → Notifikasi ke semua HRD/admin perusahaan

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
- Login rate limit: 5 attempts/menit/IP via `throttle:login`
- Token expiration: 24 jam

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

# Roadmap Fitur Payroll (Gaji) — Rencana Task

> Ditulis 2026-07-04 sebagai rencana sesi berikutnya. **Payroll = perhitungan gaji
> bulanan karyawan** berdasarkan gaji pokok + tunjangan − potongan, memakai data
> presensi/lembur/cuti yang sudah ada. Belum ada satu tabel pun untuk ini.

## Prasyarat / Dependensi (data yang sudah tersedia)
- **Kehadiran** → `attendances` (work_minutes, status present/late/absent/early_leave)
- **Lembur** → `overtime_approvals` (hanya yang `status=approved` yang dibayar)
- **Cuti/izin** → `leave_requests` + `leave_balances` (potongan bila melebihi kuota)
- **Jam kerja/shift** → `shifts`/`user_shifts` (untuk hitung hari kerja seharusnya)
- **Libur** → `holidays` (jangan potong gaji di hari libur)
- **Karyawan & cabang** → `users.attendance_setting_id`

## Prinsip Desain (WAJIB diikuti agar konsisten dgn sistem)
1. **Isolasi `company_id`** di semua tabel & query (pakai CompanyMiddleware).
2. **Multi-cabang**: komponen gaji boleh berbeda per cabang bila perlu (ikuti pola shift).
3. **Immutable setelah final**: payroll yang sudah `paid` tidak boleh diedit — buat
   revisi/adjustment baru, jangan ubah histori (audit trail).
4. **Snapshot, bukan referensi**: saat generate payslip, **salin** nilai komponen &
   ringkasan presensi ke baris payslip. Jika master salary berubah bulan depan,
   payslip lama tidak ikut berubah.
5. **Semua uang pakai `decimal` / integer rupiah**, JANGAN float.
6. **Timezone WIB** untuk penentuan periode (tgl 1–akhir bulan).
7. **Activity logs** untuk setiap aksi: `payroll_generated`, `payroll_approved`,
   `payroll_paid`, `salary_component_updated`.
8. Integrasi dengan roadmap **P0 pembayaran** (`payments`): payslip `approved → paid`
   memakai mekanisme disbursement yang sama.

## Daftar Task (urutan disarankan)

### FASE 1 — Master Data Gaji
- [ ] **T1. Migration `salary_components`** — master komponen gaji per perusahaan:
  `company_id`, `code`, `name`, `type` [earning/deduction], `is_taxable`,
  `calc_type` [fixed/percentage/per_day/per_hour], `is_active`.
  Contoh: Gaji Pokok, Tunjangan Transport, Tunjangan Makan, BPJS, PPh21, Potongan Alpha.
- [ ] **T2. Migration `employee_salaries`** — gaji dasar per karyawan (berlaku efektif):
  `user_id`, `basic_salary`, `effective_date`, `notes`. Pola "efektif terbaru ≤ tanggal"
  sama seperti `user_shifts` (biar konsisten).
- [ ] **T3. Migration `employee_salary_components`** — komponen tetap per karyawan:
  `user_id`, `salary_component_id`, `amount` / `percentage`, `effective_date`.
- [ ] **T4. Models + relasi** — `SalaryComponent`, `EmployeeSalary`, `EmployeeSalaryComponent`.
- [ ] **T5. CRUD master** — endpoint HRD: kelola `salary_components`, set gaji pokok &
  komponen tetap per karyawan (mirip pola ShiftController). Isolasi company_id.

### FASE 2 — Perhitungan & Generate Payslip
- [ ] **T6. Migration `payrolls`** (header periode) — `company_id`, `period_month`,
  `period_year`, `status` [draft/approved/paid], `generated_by`, `approved_by`,
  `total_amount`, `notes`. Unique(company_id, period_month, period_year).
- [ ] **T7. Migration `payslips`** (detail per karyawan) — `payroll_id`, `user_id`,
  snapshot: `basic_salary`, `total_earning`, `total_deduction`, `net_salary`,
  ringkasan presensi (`work_days`, `present_days`, `late_count`, `absent_days`,
  `overtime_minutes`, `overtime_pay`), `status`, `paid_at`.
- [ ] **T8. Migration `payslip_lines`** — rincian komponen per payslip (snapshot
  nama & nominal tiap earning/deduction) untuk transparansi slip gaji.
- [ ] **T9. `PayrollService::generate(company, month, year)`** — service inti:
  - Ambil karyawan aktif per perusahaan/cabang.
  - Hitung hari kerja seharusnya dari shift/jam kantor − libur.
  - Tarik rekap presensi (hadir/telat/alpha), lembur **approved**, cuti melebihi kuota.
  - Terapkan komponen earning/deduction (fixed/percentage/per_day/per_hour).
  - Hitung potongan alpha (absen) & lembur dibayar; hasilkan `net_salary`.
  - Simpan sebagai snapshot (idempoten: regenerate hapus draft lama periode itu).
- [ ] **T10. Endpoint generate** — `POST /dashboard/payroll/generate` (body: month, year,
  optional attendance_setting_id untuk generate per cabang). Hasil status `draft`.

### FASE 3 — Approval, Pembayaran & Slip
- [ ] **T11. Approval payroll** — `POST /dashboard/payroll/{id}/approve` (draft→approved),
  kunci dari edit. Role admin/super_admin (HR boleh generate, keuangan approve — TBD).
- [ ] **T12. Tandai dibayar** — integrasi dgn tabel `payments` (P0 roadmap):
  `approved → paid`, catat `paid_at`, `paid_by`, `reference_no`. Cegah bayar dobel.
- [ ] **T13. Endpoint slip karyawan (mobile)** — `GET /attendance/my-payslips` &
  `GET /attendance/my-payslips/{id}` (read-only, hanya milik sendiri).
- [ ] **T14. Export payslip PDF/CSV** — untuk cetak/kirim slip gaji.

### FASE 4 — Pajak & Penyempurnaan (opsional/lanjutan)
- [ ] **T15. Perhitungan PPh21** (TER/PTKP) — bisa disederhanakan dulu jadi komponen
  persentase, disempurnakan kemudian.
- [ ] **T16. THR / bonus** — payroll tipe khusus di luar siklus bulanan.
- [ ] **T17. Dashboard ringkasan payroll** — total pengeluaran gaji per bulan/cabang.

## Keputusan yang Perlu Ditanyakan Sebelum Mulai (jangan diasumsikan)
1. **Basis potongan alpha**: gaji pokok dibagi hari kerja, atau angka tetap per hari?
2. **Siapa approve payroll**: HRD, finance, atau keduanya (dua langkah)?
3. **Lembur dibayar**: pakai tarif per menit dari mana? (belum ada `overtime_rate`).
4. **PPh21**: implementasi penuh (TER 2024) atau komponen persentase sederhana dulu?
5. **Komponen per cabang atau per perusahaan**: apakah tunjangan beda tiap cabang?
6. **Periode cut-off**: tanggal 1–akhir bulan, atau custom (mis. 26–25)?





