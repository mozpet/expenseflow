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
| GET | `/dashboard/sync-versions` | `SyncVersionController@index` — + `timezone`, `timezone_label`; `synced_at` jam lokal cabang |
| GET | `/dashboard/activity-logs` | `ActivityLogController@index` — ekspor CSV berheader `Waktu Server (WIB)` (audit lintas cabang: satu zona render) |
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

Setiap baris laporan membawa `check_in_time_local`, `check_out_time_local`, `timezone`, `timezone_label` — jam sudah dalam zona cabang penempatan karyawan, jadi FE **tidak boleh** mengonversi lagi. CSV mendapat kolom terakhir `Zona`; kolom `corrected_at` sengaja tetap zona penyimpanan (cap audit). Default `month`/`year` dasbor tetap zona penyimpanan agar satu perusahaan melihat satu kalender.

### Koreksi Presensi Manual HRD
POST `/corrections` (throttle:heavy) — satu endpoint untuk mengoreksi baris presensi yang sudah ada **dan** membuat baris baru (backfill alpha / lupa check-in), karena `attendances` unique(`user_id`,`date`). Butuh gerbang konfirmasi `"SIMPAN"`; seluruh field turunan (jam kerja, lembur, status) selalu dihitung ulang server.

Respons memuat `timezone`, `timezone_label`, `timezone_offset_minutes` yang diambil dari **snapshot presensi itu** (`snap_timezone`) — jadi mengoreksi presensi Januari memakai zona yang berlaku saat itu, bukan zona cabang hari ini. ⚠️ Form HRD **wajib memotong string jam** dari payload; `new Date(v).getHours()` akan mengikuti zona laptop HRD dan menggeser jam.

### Pengaturan Kantor (CRUD AttendanceSetting)
GET/POST `/settings` · GET/PUT-PATCH/DELETE `/settings/{attendanceSetting}` · GET `/timezones?latitude=&longitude=` (katalog zona waktu untuk dropdown, `AttendanceController@listTimezones`)
> Denda telat (2026-10-09): `POST/PUT /settings` menerima `late_fine_per_minute`, `late_count_mode` (`after_tolerance`|`after_work_start`), `late_fine_max_per_day` (field berbahaya → `confirm_dangerous: "SIMPAN"`). Laporan/CSV memuat `late_minutes`, `late_fine_amount` (CSV: `Denda Telat (Rp)`). Payroll calculate: opsi `late_penalty` (default true) → item "Potongan Keterlambatan", step `LATE_PENALTY`.
> Aturan upah lembur (2026-10-10): `POST/PUT /settings` menerima `overtime_rule` (`government`|`custom`, default `government`), `overtime_rate_amount` (Rp, wajib > 0 bila `custom`, selain itu 422 `errors.overtime_rate_amount`), `overtime_rate_unit` (`hour`|`minute`), `overtime_rate_taxable` (bool, default true). Keempatnya field berbahaya → `confirm_dangerous: "SIMPAN"`. Payroll: `government` → item "Tunjangan Lembur" (PP 35/2021); `custom` → item "Bonus Lembur" (`source=overtime`, `is_taxable` sesuai flag). Opsi calculate `overtime=false` menonaktifkan keduanya.

Zona waktu cabang (multi-zona 2026-10-08 — detail di `doc/rules.md` §"Presensi Multi-Zona Waktu"):

- `POST`/`PUT` menerima `timezone` **opsional**. Bila kosong → ditebak dari `office_latitude`/`office_longitude`; override manual selalu menang. Nilai di luar katalog (`Asia/Jakarta`/`Asia/Makassar`/`Asia/Jayapura`) → **422**.
- ⚠️ `timezone` termasuk **field berbahaya** → butuh `confirm_dangerous: "SIMPAN"` dan muncul di `dangerous_changed_fields[]`. NULL → `Asia/Jakarta` dinormalisasi lebih dulu, jadi bukan perubahan berbahaya.
- Respons list/show/store/update memuat `timezone`, `timezone_label` (`WIB`/`WITA`/`WIT`), `timezone_offset_minutes` (420/480/540). `POST` mengembalikan objek di key **`setting`** (bukan `data`), status 201.

```json
// GET /dashboard/attendance/timezones?latitude=-5.1477&longitude=119.4327
{
  "timezones": [
    { "id": "Asia/Jakarta", "label": "WIB", "name": "Waktu Indonesia Barat",
      "offset_minutes": 420, "regions": "Sumatra, Jawa, Kalimantan Barat & Tengah",
      "is_suggested": false }
  ],
  "storage": "Asia/Jakarta",
  "suggested": { "timezone": "Asia/Makassar", "label": "WITA", "source": "box" }
}
```

`source`: `box` (kotak pengecualian — Bali 115°E tapi WITA, Maluku 128°E tapi WIT) · `longitude` · `default`. Tanpa koordinat, `suggested` = `null` dan katalog tetap 3 entri.

### Kalender Libur & Cuti Bersama
GET/POST `/holidays` · PUT-PATCH/DELETE `/holidays/{holiday}` · GET `/collective-leaves/{holiday}/detail` (rekap opt-in)

### Approval Lembur
GET `/overtime-approvals?status=&user_id=&start_date=&end_date=&page=` · POST `/overtime-approvals/{id}/approve` · POST `/overtime-approvals/{id}/reject`

Baris daftar lembur (HRD, SPV, maupun `/attendance/my-overtime`) membawa `timezone` + `timezone_label`, dan ISO8601-nya kini memakai offset cabang (`+07:00`/`+08:00`/`+09:00`).

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

#### Objek `timezone` (multi-zona 2026-10-08)

Ketiga endpoint di atas mengembalikan objek berikut. **Zona otoritatif adalah zona kantor cabang penempatan** — status telat/hadir, jadwal, lembur, dan `attendances.date` selalu dihitung di sana. Zona lokasi fisik karyawan (diturunkan server dari GPS) hanya untuk tampilan.

```json
"timezone": {
  "branch": { "id": "Asia/Jakarta", "label": "WIB", "name": "Waktu Indonesia Barat",
              "offset_minutes": 420, "office_name": "Kantor Pusat Jakarta" },
  "local":  { "id": "Asia/Jayapura", "label": "WIT", "name": "Waktu Indonesia Timur",
              "offset_minutes": 540 },
  "is_cross_timezone": true,
  "branch_time": "08:20",
  "local_time": "10:20",
  "display": "08:20 WIB (10:20 WIT lokasi Anda)"
}
```

- String **`display` dibentuk backend**. Web & Flutter cukup menampilkannya — **tidak boleh** mengonversi zona sendiri (aturan proyek: seluruh teks pengguna berasal dari payload API).
- Bila karyawan berada di zona yang sama dengan cabangnya, `is_cross_timezone` = `false` dan `display` hanya `"08:20 WITA"` (tanpa kurung). `local` tetap terisi — nilainya sama dengan `branch`. `local` bernilai `null` hanya bila zona lokasi memang **tidak bisa ditentukan** (mis. check-out tanpa GPS). Jadi gerbang tampilan FE adalah `is_cross_timezone`, bukan `local != null`.
- `check-in` menerima 2 field **opsional** yang murni audit dan tidak pernah memengaruhi perhitungan: `device_timezone` (IANA, `nullable|string|timezone`) dan `device_utc_offset_minutes` (`nullable|integer|between:-840,840`). Jam presensi tetap mutlak dari jam server (aturan Anti-Clock-Spoofing); selisih antara klaim perangkat dan zona hasil GPS justru menjadi sinyal kecurangan.
- `offices[]` dan kantor utama di `/attendance/status` serta riwayat mendapat `timezone` + `timezone_label` (model Flutter `OfficeArea` perlu 2 field baru).
- `flexitime_config` mendapat `timezone_label`; `arrival_start`/`core_end`/`target_checkout_time` kini jam lokal cabang.
- `reminder_at`, `auto_checkout_at`, `scheduled_auto_checkout_at` tetap ISO8601 **dengan offset** — kini offset cabang. Flutter sudah benar via `tz.TZDateTime.from(...)` + `absoluteTime`; cukup hapus literal `'WIB'`.

#### Error presensi yang membawa label zona

```json
// 403 — check-in melewati batas cutoff (label mengikuti zona cabang, mis. "WITA")
{ "message": "...", "cutoff_at": "09:00", "work_start_time": "08:00",
  "cutoff_minutes": 60, "timezone_label": "WITA" }

// 422 — urutan checkout tidak valid
{ "check_in_time": "08:00", "check_out_time": "07:45",
  "timezone_label": "WITA", "time_inversion": true }

// 422 — interval minimum checkout belum terpenuhi
{ "min_checkout_minutes": 10, "check_in_time": "08:00",
  "earliest_checkout_at": "08:10", "timezone_label": "WITA",
  "remaining_minutes": 7, "is_cooldown": true }
```

422 jendela WFH juga membawa `timezone_label`. Semua jam di payload di atas sudah jam lokal cabang.

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
