# 06 — Referensi REST API

Base URL: `http://<host>:8000/api/v1`
Auth: header `Authorization: Bearer <token Sanctum>` + header `X-Platform: web|mobile` (beberapa endpoint memvalidasi platform).
Koleksi Postman tersedia di `expenseflow-backend/postman/`.

Legend akses:
- 🌐 publik
- 👤 semua user terautentikasi
- 💼 karyawan (`attendance_access` = fitur presensi aktif / `receipt_access`)
- 🛡 HRD/Admin/Super Admin
- 💰 Finance/HRD/Admin/Super Admin

## Auth

| Method | Path | Akses | Controller |
|--------|------|-------|------------|
| GET | `/ping` | 🌐 | closure — health check |
| POST | `/login` | 🌐 (throttle 5/min email, 120/min IP) | `AuthController@login` |
| POST | `/logout` | 👤 | `AuthController@logout` |
| GET | `/me` | 👤 | `AuthController@me` |

## Struk Mobile (prefix `/employee`, middleware company + receipt_access) — 💼

| Method | Path | Controller |
|--------|------|------------|
| POST | `/employee/receipts` | `ReceiptController@store` |
| GET | `/employee/receipts` | `ReceiptController@myReceipts` |
| GET | `/employee/receipts/{receipt}` | `ReceiptController@show` |
| PATCH | `/employee/receipts/{receipt}/claim` | `ReceiptController@updateClaim` |
| POST | `/employee/receipts/{receipt}/submit` | `ReceiptController@submit` |
| DELETE | `/employee/receipts/{receipt}` | `ReceiptController@destroy` |
| GET | `/employee/my-schedule` | `ShiftController@mySchedule` |

## Dashboard Expense (prefix `/dashboard`) — 💰 finance,hrd,admin,super_admin

### Receipt approval (sub-filter role: finance,admin,super_admin)
| Method | Path | Controller |
|--------|------|------------|
| GET | `/dashboard/receipts` (inbox submitted) | `ReceiptController@inbox` |
| GET | `/dashboard/receipts/all?status=` | `ReceiptController@dashboardReceipts` |
| GET | `/dashboard/receipts/{receipt}` | `ReceiptController@show` |
| GET | `/dashboard/receipts/{receipt}/image` | `ReceiptController@image` |
| POST | `/dashboard/receipts/{receipt}/approve` | `ReceiptController@approve` |
| POST | `/dashboard/receipts/{receipt}/reject` | `ReceiptController@reject` |

### Vendor · Invoice · Notifikasi · Log · Settings
| Method | Path | Controller |
|--------|------|------------|
| GET/POST | `/dashboard/vendors` | `VendorController@index/store` |
| PATCH | `/dashboard/vendors/{vendor}` | `VendorController@update` |
| POST | `/dashboard/vendors/{vendor}/toggle` | `VendorController@toggleActive` |
| GET/POST | `/dashboard/invoices` | `InvoiceController@index/store` |
| GET | `/dashboard/invoices/{invoice}` | `InvoiceController@show` |
| POST | `/dashboard/invoices/{invoice}/approve` | `InvoiceController@approve` |
| POST | `/dashboard/invoices/{invoice}/reject` | `InvoiceController@reject` |
| GET | `/dashboard/notifications?only_unread=` | `NotificationController@index` |
| POST | `/dashboard/notifications/read-all` | `NotificationController@markAllRead` |
| POST | `/dashboard/notifications/{id}/read` | `NotificationController@markRead` |
| DELETE | `/dashboard/notifications/{id}` | `NotificationController@destroy` |
| GET | `/dashboard/activity-logs` | `ActivityLogController@index` |
| GET | `/dashboard/settings` | `SettingsController@index` |
| PUT/PATCH | `/dashboard/settings` | `SettingsController@update` |

## Manajemen Karyawan (prefix `/admin`) — 🛡 hrd,admin,super_admin

| Method | Path | Catatan |
|--------|------|---------|
| GET | `/admin/users` | HRD boleh lihat daftar |
| POST | `/admin/users` | hanya admin & super_admin |
| PUT | `/admin/users/{user}` | hanya admin & super_admin |
| PATCH | `/admin/users/{user}/deactivate` | hanya admin & super_admin |
| PATCH | `/admin/users/{user}/activate` | hanya admin & super_admin |
| POST | `/admin/users/{user}/reset-password` | hanya admin & super_admin |

> Pemisahan role mencegah privilege escalation oleh HRD.

## Presensi Dashboard (prefix `/dashboard/attendance`) — 🛡

### Karyawan & toggle
GET `/users` (filter enabled/disabled) · GET `/users/all` (tanpa pagination, dropdown pengecualian libur) · POST `/users/{id}/toggle-wfh` · POST `/users/{id}/toggle-radius`

### Cuti/Izin
GET `/leaves?status=&leave_type=&user_id=` · GET `/leaves/{leave}/document` (file privat) · POST `/leaves/{id}/approve` · POST `/leaves/{id}/reject` · GET `/leave-balances?user_id=&year=` · POST `/leave-balances` (set kuota)

### Laporan
GET `/today` · GET `/summary?user_id&month&year` · GET `/report?start_date&end_date&department&status&type&search&office_id&page` · GET `/report/export` (CSV download)

### Pengaturan Kantor (CRUD AttendanceSetting)
GET/POST `/settings` · GET/PUT-PATCH/DELETE `/settings/{attendanceSetting}`

### Kalender Libur & Cuti Bersama
GET/POST `/holidays` · PUT-PATCH/DELETE `/holidays/{holiday}` · GET `/collective-leaves/{holiday}/detail` (rekap opt-in)

### Approval Lembur
GET `/overtime-approvals?status=&user_id=&start_date=&end_date=&page=` · POST `/overtime-approvals/{id}/approve` · POST `/overtime-approvals/{id}/reject`

### Approval Pindah Perangkat
GET `/device-changes?status=&page=` · POST `/device-changes/{id}/approve` · POST `/device-changes/{id}/reject`

### Shift Management
| Method | Path | Controller |
|--------|------|------------|
| GET | `/shifts/calendar?month&year[&attendance_setting_id]` | kalender bulanan shift |
| GET | `/shifts/roster?date[&attendance_setting_id&search]` | roster harian |
| GET/POST | `/shifts` | template list/create |
| GET | `/shifts/{id}/users` | karyawan pemakai shift |
| PUT/PATCH | `/shifts/{id}` | update template (+schedules) |
| POST | `/shifts/{id}/toggle-active` | aktif/nonaktif |
| DELETE | `/shifts/{id}` | hapus template |
| GET | `/users/{id}/shift-history` | riwayat assignment karyawan |
| POST | `/assign-shift` | assign individual (shift_id null = default kantor) |
| POST | `/bulk-assign` | assign massal multi-karyawan |
| PUT/PATCH | `/assignments/{id}` | ubah assignment |
| DELETE | `/assignments/{id}` | hapus assignment |
| GET | `/effective-schedule?user_id&date` | preview jadwal efektif |

## Presensi Mobile (prefix `/attendance`)

Gerbang `attendance_access` (karyawan presensi aktif) — 💼:

| Method | Path | Controller |
|--------|------|------------|
| POST | `/attendance/check-in` | `AttendanceController@checkIn` |
| POST | `/attendance/check-out` | `AttendanceController@checkOut` |
| GET | `/attendance/status` | `AttendanceController@checkStatus` |

Semua karyawan (tanpa gerbang attendance_access; onsite tetap bisa lihat riwayat) — 👤:

| Method | Path | Controller |
|--------|------|------------|
| GET | `/attendance/my` | riwayat presensi pribadi |
| GET | `/attendance/leave-balance` | saldo cuti pribadi |
| GET | `/attendance/my-leaves` | pengajuan cuti pribadi |
| POST | `/attendance/leave-request` | ajukan izin/sakit/cuti/WFH |
| GET | `/attendance/my-overtime` | lembur milik sendiri |
| POST | `/attendance/fcm-token` | registrasi token push |
| GET | `/attendance/shift-updates` | notifikasi perubahan shift (banner beranda) |
| POST | `/attendance/dismiss-shift-update` | tutup banner shift |
| GET | `/attendance/my-schedule-calendar` | kalender jadwal bulanan |
| GET | `/attendance/collective-leaves` | daftar cuti bersama |
| POST | `/attendance/collective-leave/{holiday}/respond` | ikut/tidak ikut cuti bersama (422 bila saldo kurang) |

## Format Error Umum

```json
// 429 rate limit login
{ "message": "Terlalu banyak percobaan login. Coba lagi dalam X menit (Y detik).",
  "retry_after": 60, "rate_limit": true }

// 422 saldo cuti bersama tidak cukup → banner mobile, tombol ikut disabled
```
