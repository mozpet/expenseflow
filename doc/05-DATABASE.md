# 05 — Database (MySQL)

Nama database: `expenseflow_db` (lihat `.env` backend). Semua skema didefinisikan lewat migrasi di `expenseflow-backend/database/migrations/`.

## Tabel Inti

| Tabel | Migrasi | Fungsi |
|-------|---------|--------|
| `users` | `0001_01_01_000000` + tambahan: employee fields, attendance_enabled, wfh_enabled, radius_enabled, fcm_token, device binding (device id/platform), office, phone, employment fields | Akun & data karyawan; kolom role (`employee/finance/hrd/admin/super_admin`) |
| `companies` / company settings | `2026_06_10_000002_create_company_settings_table` | Data perusahaan & pengaturan global |
| `personal_access_tokens` | `..._002749` | Token Sanctum (kolom `expires_at`; NULL = mobile persisten) |
| `cache`, `jobs` | `0001_01_01_000001/_000002` | Cache framework + queue driver database |

## Domain Expense

| Tabel | Fungsi |
|-------|--------|
| `receipts` | Struk klaim karyawan. Evolusi: claim OCR fields, ocr_status, amounts nullable, variance_pct, submitted_at |
| `receipt_images` | File gambar struk |
| `receipt_approvals` | Jejak approve/reject Finance |
| `vendors` | Master vendor (+ kolom bank) |
| `invoices` | Invoice vendor (+ approval columns) |
| `invoice_items` | Rincian item invoice |
| `invoice_images` | Gambar invoice hasil scan |
| `invoice_approvals` | Jejak approval + tracking |
| `activity_logs` | Audit log (entity_type/entity_id ditambahkan belakangan) |
| `notifications` | Notifikasi in-app per user |

## Domain Presensi

### `attendances`
Satu baris per kehadiran per hari:
- `check_in_type` enum: onsite/wfh/**field** (ditambah bertahap)
- status: present/late/**early_leave**/absent
- kolom overtime (check-out melewati jam → kaitan `overtime_approvals`)
- `auto_checkout` (penanda ditutup sistem)

### Pengaturan — `company_settings` (AttendanceSetting)
Per kantor/perusahaan: koordinat lat/lng + radius presensi, jam kerja default, window WFH check-in, early leave tolerance, overtime & auto-checkout settings, work days, **custom schedules**, weekly hours, shift notice days.
> Catatan: kolom `collective_leave_policy` sempat dibuat lalu **di-drop** — kebijakan cuti bersama selalu *block* (saldo tidak cukup → 422).

### Cuti & Libur
- `leave_requests` — izin/sakit/cuti/WFH; rejection_reason, document_path (surat sakit), kolom collective (is_collective, holiday id), `holiday_compensated_days`.
- `leave_balances` — kuota per user/tahun; kolom `izin` (rename dari `sakit`).
- `holidays` — libur nasional / cuti bersama (`is_collective`) / perusahaan; `attendance_setting_id` (per kantor); unique constraint diperbarui.
- `holiday_exclusions` — pengecualian user tertentu dari sebuah holiday.

### Lembur & Device
- `overtime_approvals` — pengajuan/approval lembur (+ `overtime_reason`).
- `device_change_requests` — permohonan pindah perangkat (device binding).

### Shift
- `shifts` — template shift (nama, deskripsi, warna, `attendance_setting_id`, is_active).
- `shift_schedules` — jadwal harian template: `day_of_week`, is_off/is_wfh/is_field, `cross_day` (shift lintas tengah malam), `effective_date`.
- `user_shifts` — assignment user↔shift dengan start/end date.

### Performa
- `2026_07_03_000001_add_dashboard_performance_indexes` — index komposit untuk dashboard presensi.

## Relasi Utama

```
companies 1─* users
users 1─* attendances            users 1─* leave_requests ─* holidays (collective)
users 1─* leave_balances         holidays 1─* holiday_exclusions ─* users
users 1─* user_shifts *─1 shifts 1─* shift_schedules
shifts *─1 attendance_settings   holidays *─1 attendance_settings
users 1─* receipts 1─* receipt_images / receipt_approvals
users 1─* invoices 1─* invoice_items / invoice_images / invoice_approvals
invoices *─1 vendors             users 1─* overtime_approvals / device_change_requests
users 1─* notifications / activity_logs / login_attempts / personal_access_tokens
```

## Seeder

| Seeder | Isi |
|--------|-----|
| `DatabaseSeeder.php` | data dasar (company, admin, dll.) |
| `HolidaySeeder.php` | hari libur nasional |

## Catatan Penting

1. Banyak perubahan skema dilakukan incremental via migrasi terpisah (bukan edit migrasi lama) — jalankan `php artisan migrate:fresh --seed` hanya di lingkungan dev.
2. Token mobile dibuat `expires_at = NULL` (login persisten) — lihat [04-MOBILE-FLUTTER.md](04-MOBILE-FLUTTER.md).
3. Kebijakan saldo cuti bersama: selalu *block* bila saldo kurang (HTTP 422), tanpa opsi allow-negative.
