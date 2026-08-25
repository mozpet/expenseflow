# 02 — Backend (Laravel)

Path project: `expenseflow-backend/`

## Tech Stack

| Aspek | Nilai |
|-------|-------|
| Framework | Laravel ^13.8 |
| PHP | ^8.3 |
| Auth | Laravel Sanctum ^4.3 (personal access token) |
| Database | MySQL (`DB_CONNECTION=mysql`, db `expenseflow_db`) |
| Queue | `database` driver (job OCR) |
| OCR | `thiagoalessio/tesseract_ocr` + driver Google Vision (pilih via `OCR_DRIVER`) |
| HTTP client | guzzlehttp/guzzle (dipakai FcmService) |

## Struktur Folder Penting

```
expenseflow-backend/
├── app/
│   ├── Console/Commands/          → 3 artisan command terjadwal
│   ├── Http/
│   │   ├── Controllers/API/       → 10 controller REST
│   │   └── Middleware/            → 4 middleware custom
│   ├── Jobs/ProcessOcrJob.php     → queue job OCR struk
│   ├── Models/                    → 20 model Eloquent
│   ├── Providers/
│   └── Services/                  → FcmService, LocationService, OcrService(+driver), ShiftRestService
├── bootstrap/app.php              → registrasi middleware & rate limiter
├── config/                        → konfigurasi Laravel
├── database/
│   ├── migrations/                → ±80 file migrasi
│   └── seeders/                   → DatabaseSeeder, HolidaySeeder
├── postman/                       → koleksi Postman (Attendance & Complete)
├── resources/
├── routes/api.php                 → seluruh route REST (prefix v1)
├── routes/console.php             → definisi scheduler
└── tests/                         → Feature: Attendance/Auth/Invoice/Receipt; Unit: middleware, LocationService, OCR parsing
```

## Controller (`app/Http/Controllers/API/`)

| File | Tanggung jawab |
|------|----------------|
| `AuthController.php` | Login (rate-limited), logout, `me`. Device binding + single session per platform |
| `UserController.php` | CRUD karyawan, activate/deactivate, reset password (HRD/Admin/Super Admin) |
| `ReceiptController.php` | Upload/klaim/submit struk (mobile), inbox & approve/reject (web Finance) |
| `InvoiceController.php` | List/detail/create invoice, approve/reject |
| `VendorController.php` | CRUD vendor + toggle aktif |
| `NotificationController.php` | List notifikasi, mark read/all-read, delete |
| `ActivityLogController.php` | Audit log dengan filter action/entity |
| `SettingsController.php` | Threshold variance & batas klaim perusahaan |
| `AttendanceController.php` | **Controller terbesar (~4.000 baris)**: check-in/out, riwayat, cuti/izin, saldo cuti, libur & cuti bersama, lembur, device change, pengaturan kantor, laporan/export CSV |
| `ShiftController.php` | Template shift, jadwal, assignment (individual/massal), roster, kalender, shift updates karyawan |

## Model (`app/Models/`)

| Model | Tabel | Catatan |
|-------|-------|---------|
| `User` | users | role, office, wfh/radius/attendance flag, device binding, fcm_token, employment fields |
| `Company` | companies | pemilik data (multi-company via middleware `company`) |
| `AttendanceSetting` | company_settings | lokasi kantor (lat/lng/radius), jam kerja, window WFH, overtime & auto-checkout settings, work days, custom schedules, weekly hours |
| `Attendance` | attendances | check-in/out, tipe (onsite/wfh/field), status (present/late/absent/early_leave), kolom overtime, auto_checkout |
| `LeaveRequest` | leave_requests | izin/sakit/cuti/wfh, rejection_reason, document_path, kolom collective leave, holiday_compensated_days |
| `LeaveBalance` | leave_balances | kuota cuti/sakit per user per tahun (kolom `izin` hasil rename dari `sakit`) |
| `Holiday` | holidays | nasional / cuti bersama (is_collective) / perusahaan; attendance_setting_id; exclusions via `holiday_exclusions` |
| `OvertimeApproval` | overtime_approvals | pengajuan lembur + approval HRD + reason |
| `DeviceChangeRequest` | device_change_requests | permintaan pindah perangkat (device binding) |
| `Shift` | shifts | template shift (nama, warna, attendance_setting_id, is_active) |
| `ShiftSchedule` | shift_schedules | jadwal harian template: day_of_week, is_off/is_wfh/is_field, cross_day, effective_date |
| `UserShift` | user_shifts | assignment shift ke user dengan start/end date |
| `Receipt` | receipts | struk klaim, amount nullable, ocr fields, status, submitted_at, variance_pct |
| `ReceiptImage` | receipt_images | file gambar struk |
| `ReceiptApproval` | receipt_approvals | jejak approval/reject struk |
| `Invoice` | invoices | invoice vendor + kolom approval |
| `InvoiceItem` | invoice_items | rincian item invoice |
| `InvoiceApproval` | invoice_approvals | jejak approval + tracking |
| `Vendor` | vendors | master vendor + kolom bank |
| `LoginAttempt` | login_attempts | audit percobaan login (user_id nullable) |

## Middleware Custom (`app/Http/Middleware/`)

| Class | Alias | Fungsi |
|-------|-------|--------|
| `RoleMiddleware` | `role:finance,hrd,...` | cek role user terhadap daftar |
| `CompanyMiddleware` | `company` | scope data ke perusahaan user |
| `AttendanceAccessMiddleware` | `attendance_access` | gerbang fitur presensi: hanya user `attendance_enabled = true` |
| `ReceiptAccessMiddleware` | `receipt_access` | gerbang akses fitur struk mobile |

## Services (`app/Services/`)

| Service | Fungsi |
|---------|--------|
| `FcmService.php` | kirim push notification via Firebase HTTP API (guzzle) |
| `LocationService.php` | hitung jarak haversine titik GPS vs koordinat kantor, validasi radius |
| `OcrService.php` + `app/Services/Ocr/*` | abstraksi OCR: interface `OcrDriverInterface`, driver `TesseractDriver` & `GoogleVisionDriver`, trait `Concerns/ParsesOcrText` |
| `ShiftRestService.php` | logika hari rest/libur shift dan jadwal efektif karyawan |

## Job Queue

- `app/Jobs/ProcessOcrJob.php` — memproses gambar struk → teks → parse nominal/vendor; dipicu saat upload struk, dijalankan worker `queue:listen` (driver `database`).

## Artisan Command Terjadwal (`routes/console.php`)

| Command | Jadwal | Fungsi |
|---------|--------|--------|
| `attendance:auto-checkout` (`AutoCheckoutCommand.php`) | tiap 5 menit, withoutOverlapping, background | auto checkout karyawan yang lupa logout + reminder push sebelum batas |
| `attendance:auto-decline-collective-leave` (`AutoDeclineCollectiveLeaveCommand.php`) | tiap jam (:07) | pending → declined untuk cuti bersama yang sudah lewat tanpa respons |
| `attendance:sync-shift-wfh` (`SyncShiftWfhStatus.php`) | daily 00:01 | sinkron flag WFH/radius user berdasarkan shift hari itu |

> Jalankan `php artisan schedule:work` (atau cron sistem) agar ketiganya berjalan.

## Autentikasi & Keamanan

- **Login** `POST /api/v1/login` — throttle `login`: **5 req/menit per email** + **120 req/menit per IP**. Response 429 menyertakan `retry_after` yang **di-clamp maksimal 60 detik** (pengaman bug cache timer korup — lihat komentar di `routes/api.php`).
- Token Sanctum dikembalikan bersama `token_expires_at`; web 24 jam, mobile NULL (persisten).
- **Device binding**: login mobile mencatat device id; akun terikat 1 device, pindah device membuat `device_change_requests` yang harus diapprove HRD.
- **Single session** per platform: login baru merevoke token lama platform tersebut.
- Header `X-Platform` membedakan web/mobile untuk guard tertentu (`AuthPlatformGuardTest`).

## Testing

- Feature: `tests/Feature/{AuthTest,AuthPlatformGuardTest,AttendanceTest,ReceiptTest,InvoiceTest}.php`
- Unit: `tests/Unit/{AccessMiddlewareTest,LocationServiceTest,ParsesOcrTextTest}.php`
- Jalankan: `composer test`

## Menjalankan Backend

```bash
composer install
cp .env.example .env        # set DB & OCR_DRIVER
php artisan key:generate
php artisan migrate --seed
php artisan serve           # http://127.0.0.1:8000
# terminal terpisah:
php artisan schedule:work
php artisan queue:listen --tries=1 --timeout=0
```
