# Perubahan — Fitur WFH Toggle (Presensi Opsional oleh HRD)

Tanggal: 2026-06-19

## Tujuan

Membuat presensi bersifat **opsional/terkontrol HRD** per karyawan:

- HRD punya tombol switch di web untuk mengizinkan karyawan presensi dari rumah (Work From Home).
- Jika **WFH diizinkan (ON)** → karyawan bisa presensi dari mobile app Flutter dari mana saja (tanpa cek lokasi kantor).
- Jika **WFH tidak diizinkan (OFF)** → presensi mobile diblokir; karyawan presensi di kantor melalui **perangkat presensi (hardware)**, bukan via aplikasi.

## Status: SELESAI ✅

Setelah ditelusuri, ternyata **hampir seluruh fitur sudah ada** di codebase. Hanya **1 route yang hilang** sehingga tombol switch HRD belum bisa dipanggil dari web. Route tersebut sudah ditambahkan.

## Perubahan Kode

### `routes/api.php` (baris 93) — TAMBAH 1 baris

Menambahkan route untuk tombol switch WFH di dalam group `dashboard/attendance` (middleware `role:hrd,admin,super_admin`):

```php
Route::post('/users/{id}/toggle-wfh', [AttendanceController::class, 'toggleWfh']);
```

Sebelumnya method `toggleWfh()` sudah ada di controller, tapi belum punya route — jadi tidak bisa diakses dari web HRD.

### Jalankan migration `wfh_enabled` (fix error 500)

Endpoint List users sempat error 500: `Unknown column 'wfh_enabled'`. Penyebabnya migration
`2026_06_19_000007_add_wfh_enabled_to_users_table` masih berstatus **Pending**. Sudah dijalankan:

```bash
php artisan migrate
```

### `checkIn()` — presensi mobile dibatasi hanya untuk WFH

Karyawan kantor (WFO) presensi melalui **perangkat presensi (hardware)**, bukan via aplikasi.
Logika onsite/radius di `checkIn()` dihapus. Sekarang:

- `wfh_enabled = true`  → check-in WFH berhasil tanpa cek lokasi (`type "wfh"`, `status "wfh"`).
- `wfh_enabled = false` → check-in via aplikasi **ditolak** (`403`):
  *"Presensi aplikasi hanya untuk karyawan WFH. Presensi di kantor dilakukan melalui perangkat presensi."*

File: `app/Http/Controllers/API/AttendanceController.php` — method `checkIn()`. Dependency
`LocationService` yang hanya dipakai logika radius ikut dihapus (import + constructor).

### Postman — request onsite dihapus

Dua request `Check-in onsite (dalam radius)` dan `Check-in onsite (terlalu jauh → 403)` dihapus dari
collection karena presensi kantor tidak lagi lewat API. Tersisa `Check-in WFH`.

### `toggleWfh()` — 1 switch saja (sinkron `attendance_enabled`)

Masalah: Budi `wfh_enabled=1` tapi check-in ditolak *"Fitur presensi belum diaktifkan oleh HRD."*
karena gerbang route `/attendance/*` (middleware `attendance_access`) mengecek **`attendance_enabled`**
yang masih `0` — flag berbeda dari `wfh_enabled`.

Solusi: switch WFH dijadikan satu-satunya kontrol. Di `toggleWfh()`, `attendance_enabled`
disinkronkan mengikuti `wfh_enabled`:

```php
$target->wfh_enabled        = ! $target->wfh_enabled;
$target->attendance_enabled = $target->wfh_enabled; // WFH = gerbang presensi mobile
```

- WFH **ON**  → `attendance_enabled = true`  → karyawan bisa presensi mobile.
- WFH **OFF** → `attendance_enabled = false` → presensi mobile mati (kantor via hardware).

Data lama diselaraskan satu kali: `UPDATE users SET attendance_enabled = wfh_enabled` (2 baris).

### Laporan presensi — pembeda lokasi via `check_in_type`, bukan `status`

Masalah: `report?status=present` tidak memunculkan Budi, karena check-in WFH menyetel
`status='wfh'` (bukan `present`).

Solusi: `status` = hasil kehadiran (`present`/`late`/`absent`); pembeda lokasi = `check_in_type`
(`wfh`/`onsite`). Perubahan:

- `checkIn()`: check-in WFH kini set `status='present'` (sebelumnya `'wfh'`), `check_in_type` tetap `'wfh'`.
- `reportAttendance()`:
  - validasi status jadi `in:present,late,absent` (buang `wfh`);
  - tambah filter opsional `type` (`in:onsite,wfh`) → `?type=wfh` / `?type=onsite`;
  - summary kini `present/late/absent` + blok baru `by_type { onsite, wfh }`.
- Data lama: `UPDATE attendances SET status='present' WHERE status='wfh'` (1 baris).
- Postman: tambah request `Laporan presensi (filter lokasi: type=wfh|onsite)`.

Onsite via hardware nanti tinggal insert row `check_in_type='onsite'`, `status='present'/'late'`.

---

## Penambahan Fitur HRD (sesuai standar perusahaan)

Enam fitur ditambahkan ke `AttendanceController.php` + `routes/api.php`.

### 1. Deteksi telat untuk WFH
Check-in WFH kini menghitung `present`/`late` dari jam kerja kantor pertama perusahaan
(`work_start_time` + `late_tolerance_minutes`). Helper baru `determineStatus()`.
Jika perusahaan belum punya setting kantor → default `present`.

### 2. List pengajuan izin/cuti (HRD)
`GET /dashboard/attendance/leaves?status=&leave_type=&user_id=` — daftar semua pengajuan
untuk di-review HRD (sebelumnya HRD hanya dapat notifikasi).

### 3. Dashboard presensi hari ini
`GET /dashboard/attendance/today` — ringkasan + daftar: sudah check-in, belum check-in,
sedang izin/cuti. Untuk monitoring HRD tiap pagi.

### 4. Kuota / saldo cuti
- Tabel baru `leave_balances` (user_id, year, leave_type cuti|sakit, quota, used) — kuota default 12 hari.
- `approveLeave()` kini **memotong saldo** otomatis & **menolak (422)** jika saldo kurang.
- `GET /dashboard/attendance/leave-balances` (HRD lihat saldo)
- `POST /dashboard/attendance/leave-balances` (HRD atur kuota)
- `GET /attendance/leave-balance` (karyawan lihat saldo sendiri)
- Model baru: `app/Models/LeaveBalance.php`; migration `2026_06_19_000008_create_leave_balances_table.php`.

### 5. Rekap bulanan per karyawan (fondasi payroll)
`GET /dashboard/attendance/summary?user_id=&month=&year=` — rekap hadir/telat/absen,
breakdown onsite/wfh, dan jumlah izin/sakit/cuti yang disetujui pada bulan tsb.

### 6. Export laporan CSV
`GET /dashboard/attendance/report/export` — download CSV (filter sama dengan report:
start_date, end_date, department, status, type).

### 7. Cuti bisa diajukan semua karyawan (termasuk onsite)
`leave-request` & `leave-balance` dipindahkan keluar dari gerbang `attendance_access`.
Di `routes/api.php` kini ada 2 group dengan prefix `attendance`:
- `['auth:sanctum','company','attendance_access']` → `check-in`, `check-out`, `my` (butuh WFH ON).
- `['auth:sanctum','company']` → `leave-request`, `leave-balance` (semua karyawan).

Dengan ini karyawan onsite (attendance OFF) tetap bisa mengajukan cuti & melihat saldonya.

## Verifikasi

Route sudah terdaftar dengan benar (`php artisan route:list`):

```
POST  api/v1/dashboard/attendance/users/{id}/toggle-wfh  →  API\AttendanceController@toggleWfh
```

## Komponen yang Sudah Ada (tidak perlu diubah)

| Komponen | File | Keterangan |
|---|---|---|
| Kolom `wfh_enabled` di tabel `users` | `database/migrations/2026_06_19_000007_add_wfh_enabled_to_users_table.php` | default `false` → karyawan presensi kantor |
| Method `toggleWfh()` | `app/Http/Controllers/API/AttendanceController.php:98` | switch ON/OFF + catat activity log |
| Logika `checkIn()` | `app/Http/Controllers/API/AttendanceController.php` | `wfh_enabled=true` → check-in WFH tanpa cek lokasi; `false` → ditolak (presensi kantor via hardware) |
| `listUsers()` kirim status `wfh_enabled` | `app/Http/Controllers/API/AttendanceController.php:146` | untuk tampilan switch di web HRD |
| `me()` / `userPayload()` kirim `wfh_enabled` | `app/Http/Controllers/API/AuthController.php:95` | agar Flutter tahu boleh presensi mobile atau tidak |

## Alur Lengkap

### 1. Web HRD — menekan tombol switch
```
POST /api/v1/dashboard/attendance/users/{id}/toggle-wfh
Authorization: Bearer <token_hrd>

Respon (ON):
{
  "message": "Mode WFH diaktifkan — karyawan bisa presensi dari rumah.",
  "user": { "id": 5, "name": "Budi", "wfh_enabled": true }
}

Respon (OFF):
{
  "message": "Mode WFH dinonaktifkan — karyawan presensi dari kantor.",
  "user": { "id": 5, "name": "Budi", "wfh_enabled": false }
}
```

### 2. Flutter Mobile — cek apakah boleh presensi mobile
```
GET /api/v1/me

Respon: user.wfh_enabled
  → true  : tampilkan tombol presensi mobile
  → false : sembunyikan/disable tombol, tampilkan pesan "Presensi harus dari kantor"
```

### 3. Flutter Mobile — presensi
```
POST /api/v1/attendance/check-in
{ "latitude": ..., "longitude": ... }

  → Jika wfh_enabled = true  : check-in berhasil (type "wfh", status present/late)
  → Jika wfh_enabled = false : ditolak 403
                               "Presensi aplikasi hanya untuk karyawan WFH..."
```

## Catatan untuk Tim Flutter

Cukup baca field `wfh_enabled` dari respons `GET /api/v1/me` (atau dari respons login):
- `true`  → tampilkan tombol presensi mobile.
- `false` → sembunyikan/disable tombol; presensi kantor lewat perangkat presensi (hardware).

Presensi onsite **tidak lagi** ditangani API ini.

---

# Perubahan — Sesi 2026-06-22

## 1. Fix Date Picker Localization (Flutter)

**Masalah:** `showDatePicker` dengan `locale: Locale('id', 'ID')` crash karena `flutter_localizations` belum di-setup.

**File yang diubah:**
- `cobain/pubspec.yaml` — tambah `flutter_localizations: sdk: flutter`
- `cobain/lib/main.dart` — tambah `import flutter_localizations`, `localizationsDelegates`, dan `supportedLocales` ke `MaterialApp`

---

## 2. Riwayat Izin/Cuti dari Backend

**Masalah:** Riwayat izin hanya disimpan di memori lokal Flutter saat submit — hilang setelah app di-restart atau setelah HRD approve/reject.

**Root cause:** `IzinCutiScreen` ada dalam `IndexedStack` di `home_screen.dart`; `initState` tidak pernah jalan ulang saat tab dibuka kembali, dan tidak ada endpoint untuk fetch riwayat izin karyawan.

### Backend
- `AttendanceController.php` — tambah method `myLeaves()`: return semua riwayat izin user yang login
- `routes/api.php` — tambah `GET /attendance/my-leaves` (middleware `auth:sanctum + company`)

### Flutter
- `lib/services/api_service.dart` — tambah `myLeaves()`
- `lib/presensi_provider.dart` — tambah `fetchLeaveRequests()`, field `_loadingLeaves`, getter `loadingLeaves`
- `lib/screens/izin_cuti_screen.dart`:
  - `initState` panggil `fetchLeaveRequests()` + `fetchLeaveBalance()`
  - `home_screen.dart` onTap index 3 → panggil kedua fetch
  - Tab Riwayat: tambah `CircularProgressIndicator` dan `RefreshIndicator` (pull-to-refresh)
  - FAB "Ajukan Izin" → refetch setelah kembali (provider di-capture sebelum `await`)
- `lib/presensi_provider.dart` — `fetchLeaveRequests()` normalisasi tanggal ISO `2026-06-26T00:00:00Z` → `2026-06-26` via `_dateOnly()`

---

## 3. Saldo Cuti — Rename "Sakit" → "Izin", Hapus Kuota

**Permintaan:** Label "Sakit" di tab Saldo Cuti diganti "Izin"; tidak ada batas maksimum hari, tapi tetap dihitung jika karyawan ajukan izin atau sakit.

### Backend
- `database/migrations/2026_06_21_181454_rename_sakit_to_izin_in_leave_balances.php` — **migration baru**: ubah enum `leave_type` dari `['cuti','sakit']` → `['cuti','izin']`, pindahkan data lama `sakit → izin` dengan `quota=0`
- `AttendanceController.php`:
  - `DEFAULT_LEAVE_QUOTA` → hanya `['cuti' => 12]` (sakit dihapus)
  - `approveLeave()` — cuti tetap cek & potong kuota 12 hari; izin/sakit tidak dicek kuota tapi tetap dihitung di `leave_balances` dengan `leave_type='izin'`
  - `myLeaveBalance()` — buat baris default 'cuti' (quota=12) dan 'izin' (quota=0)

### Flutter
- `lib/screens/izin_cuti_screen.dart` — `_BalanceCard` refactor:
  - `isCuti = leaveType == 'cuti'` → cuti tampil progress bar + sisa hari; izin hanya tampil total hari terpakai
  - Label "Sakit" → "Izin", warna `Colors.purple`, icon `Icons.event_busy_outlined`

---

## 4. Integrasi OCR Backend ke Flutter (Full)

**Masalah:** Seluruh alur upload struk, OCR, riwayat, dan beranda di Flutter masih hardcoded/mock — tidak terhubung ke backend.

### Backend
- `ReceiptController.php` — `myReceipts()`: tambah `selectRaw` subquery `rejection_reason` dari `receipt_approvals` agar tidak perlu extra request saat tampil di list

### Flutter — File Baru
- `lib/providers/receipt_provider.dart` — **BARU**: model `ReceiptRecord` + `ReceiptProvider` (upload, poll OCR, finalize & submit, fetch list, stats)

### Flutter — File Dimodifikasi
- `lib/services/api_service.dart`:
  - Tambah case `PATCH` di `_request()`
  - Tambah 5 endpoint: `uploadReceipt(bytes, fileName)` (multipart), `getReceipt(id)`, `myReceipts()`, `updateClaim(id, ...)`, `submitReceipt(id)`
- `lib/screens/submit_step2_screen.dart` — **rewrite**: upload foto → poll OCR setiap 2 detik (max 60s) → tampilkan data OCR terkunci atau form manual jika OCR gagal → submit ke backend
- `lib/screens/status_screen.dart` — terima `ReceiptRecord` asli dari backend, tampilkan data real
- `lib/screens/riwayat_screen.dart` — **rewrite**: fetch dari `GET /employee/receipts`, filter status, pull-to-refresh, `_ReceiptCard` berbasis `ReceiptRecord`
- `lib/screens/home_screen.dart` — statistik real (total bulan ini, jumlah disetujui), daftar 5 receipt terbaru dari `ReceiptProvider`; `initState` fetch receipts; onTap tab Izin & Cuti trigger fetch
- `lib/screens/detail_pengajuan_screen.dart` — **rewrite**: terima `ReceiptRecord`, tampilkan data OCR asli + alasan penolakan
- `lib/main.dart` — daftarkan `ReceiptProvider` di `MultiProvider`

### Alur Upload Struk (Baru)
```
Step1: pilih foto → bytes (Uint8List)
Step2 initState:
  POST /employee/receipts → dapat receipt_id
  Poll GET /employee/receipts/{id} tiap 2s → ocr_status done/failed
  Tampilkan OCR terkunci (atau form manual)
User isi kategori + catatan → "Kirim ke Finance"
  PATCH /employee/receipts/{id}/claim
  POST /employee/receipts/{id}/submit
  GET /employee/receipts/{id}  ← ambil data lengkap
→ StatusScreen(receipt)
```

---

## 5. Fix Flutter Web — Upload Berbasis Bytes

**Masalah:** App berjalan di Flutter Web; `dart:io File`, `MultipartFile.fromPath()`, dan `Image.file()` tidak tersedia di web → error langsung saat pilih gambar.

**Solusi:** Ganti seluruh alur ke `Uint8List` (bytes) yang universal di semua platform.

**File yang diubah:**
- `lib/photo_provider.dart` — simpan `Uint8List bytes` + `String fileName` via `XFile.readAsBytes()` (hapus `dart:io File`)
- `lib/screens/submit_step1_screen.dart` — tambah pilihan sumber **Kamera / Galeri** via bottom sheet; preview `Image.memory(bytes)`; kirim `bytes + fileName` ke Step2
- `lib/screens/submit_step2_screen.dart` — terima `Uint8List imageBytes + String fileName`; preview `Image.memory`
- `lib/services/api_service.dart` — `uploadReceipt(Uint8List, String)` pakai `MultipartFile.fromBytes` (hapus `dart:io`)
- `lib/providers/receipt_provider.dart` — `uploadReceipt(Uint8List, String)`

---

## 6. Aksi Draft Receipt — Foto Ulang & Hapus

**Permintaan:** Receipt berstatus draft (foto sudah diupload, belum di-submit) bisa di-foto ulang, dihapus, atau dibatalkan dari tab Riwayat.

### Backend
- `ReceiptController.php` — tambah method `destroy()`: soft delete hanya jika `status == 'draft'` dan milik sendiri (403 jika bukan owner, 422 jika bukan draft); catat ke `activity_logs` dengan action `receipt_deleted`
- `routes/api.php` — tambah `DELETE /employee/receipts/{receipt}` di dalam grup `receipt_access`

### Flutter
- `lib/services/api_service.dart` — tambah case `DELETE` di `_request()` + method `deleteReceipt(int id)`
- `lib/providers/receipt_provider.dart` — tambah `deleteDraft(int id)`: hapus backend + remove dari list lokal secara optimistik
- `lib/screens/detail_pengajuan_screen.dart` — **refactor ke StatefulWidget**:
  - Banner khusus draft (abu-abu, pesan sesuai `ocrStatus`)
  - Section "AKSI" hanya untuk draft: tombol **Foto Ulang** (hapus draft lama → Step1) dan **Hapus Draft** (konfirmasi dialog → hapus)
  - State `_isDeleting` disable tombol saat proses
- `lib/screens/riwayat_screen.dart` — tambah hint teks "Ketuk untuk lihat aksi" (biru italic) di bawah tanggal card draft

### Alur Foto Ulang
```
Tap card draft → bottom sheet detail
Tap "Foto Ulang":
  deleteDraft(id) → hapus dari backend + list lokal
  Navigator.pop() → tutup bottom sheet
  Navigator.push → SubmitStep1Screen() → mulai ulang
```

---

## 7. Fix OCR — Foto Horizontal (EXIF Auto-Rotation)

**Masalah:** Tesseract gagal membaca struk yang difoto secara landscape. HP modern menyimpan orientasi di EXIF tag, bukan di piksel — Tesseract membaca piksel mentah sehingga teks miring 90°.

**File yang diubah:** `app/Services/Ocr/TesseractDriver.php`

**Perubahan:**
- Sebelum memanggil Tesseract, baca EXIF orientation via `@exif_read_data()`
- Jika orientation bukan 1 (normal tegak), putar gambar dengan PHP GD (`imagerotate()`) dan simpan ke temp file
- Mapping: orientation `6` → `-90°` (landscape kanan), `8` → `+90°` (landscape kiri), `3` → `180°` (terbalik)
- Temp file dihapus di `finally` block setelah OCR selesai
- PSM mode tetap `6` — optimal untuk struk setelah rotasi benar
- Tambah private method `correctOrientation(string $imagePath, int $orientation): ?string`
- Semua operasi GD di-wrap `@` untuk fail-gracefully (fallback ke path asli jika GD gagal)

---

## 8. Fix Duplicate Receipt Number Setelah Soft Delete

**Masalah:** Setelah draft di-hapus (soft delete), `generateReceiptNumber()` tidak melihat record yang sudah dihapus (SoftDeletes otomatis exclude) sehingga nomor yang sama di-generate ulang → UNIQUE constraint violation `Duplicate entry 'RCP-YYYYMMDD-XXXX'`.

**File yang diubah:** `app/Http/Controllers/API/ReceiptController.php`

**Perubahan:** Satu baris — ganti `Receipt::where(...)` → `Receipt::withTrashed()->where(...)` di method `generateReceiptNumber()` agar nomor yang pernah dipakai (termasuk soft-deleted) tidak di-generate ulang.

---

## Catatan Penting untuk Sesi Berikutnya

### Android (jika compile ke APK)
1. **INTERNET permission** — hanya ada di `debug/AndroidManifest.xml`. Untuk release APK, tambahkan ke `main/AndroidManifest.xml`:
   ```xml
   <uses-permission android:name="android.permission.INTERNET"/>
   ```
2. **Cleartext HTTP** — Android 9+ blokir `http://`. Tambahkan `android:usesCleartextTraffic="true"` di `<application>` di `main/AndroidManifest.xml`, atau gunakan `network_security_config.xml`.
3. **Base URL** — `api_config.dart` saat ini pakai `127.0.0.1:8000` (untuk web/desktop). Ganti ke:
   - Emulator Android: `http://10.0.2.2:8000/api/v1`
   - HP fisik: `http://[IP-LAN-PC]:8000/api/v1` + `php artisan serve --host=0.0.0.0`

### Queue Worker (wajib untuk OCR)
OCR berjalan secara async via queue. Pastikan selalu jalankan:
```bash
php artisan queue:work
```

### Status Fitur
| Fitur | Status |
|---|---|
| Auth & security | SELESAI |
| Receipt (struk) CRUD + OCR | SELESAI |
| Invoice multi-level | SELESAI |
| Vendor management | SELESAI |
| User management | SELESAI |
| Presensi (attendance) | SELESAI |
| Flutter integrasi OCR + Riwayat + Beranda | SELESAI |
| Draft receipt: foto ulang & hapus | SELESAI |
| Integrasi Web Dashboard (React) | SELESAI |

---

# Perubahan — Sesi 2026-06-23 (Integrasi Web Dashboard React)

## Tujuan
Menghubungkan frontend web dashboard (`web akuntan`, React 19 + Vite + TS) ke backend
Laravel via REST API. Sebelumnya seluruh halaman web masih memakai data mock lokal.

## Endpoint Backend Baru (grup `dashboard`, middleware `role:finance,hrd,admin,super_admin` + `company`)

Beberapa halaman web tidak punya endpoint, jadi ditambahkan:

### 1. Invoice — list & detail
- `GET /api/v1/dashboard/invoices?status=pending|approved|rejected` → `InvoiceController@index`
  (paginate + blok `summary {pending, approved, rejected}`).
- `GET /api/v1/dashboard/invoices/{invoice}` → `InvoiceController@show`
  (detail lengkap: vendor, user, items, approvals).
- File: `app/Http/Controllers/API/InvoiceController.php`.

### 2. Notifikasi
- `GET /api/v1/dashboard/notifications?only_unread=1` → `NotificationController@index`
  (decode kolom JSON `data`, sertakan `unread_count`).
- `POST /api/v1/dashboard/notifications/read-all` → `markAllRead`.
- `POST /api/v1/dashboard/notifications/{id}/read` → `markRead`.
- `DELETE /api/v1/dashboard/notifications/{id}` → `destroy`.
- File baru: `app/Http/Controllers/API/NotificationController.php` (pakai `DB::table('notifications')`).

### 3. Audit Log
- `GET /api/v1/dashboard/activity-logs?action=&entity_type=` → `ActivityLogController@index`
  (join ke `users` untuk nama pelaku).
- File baru: `app/Http/Controllers/API/ActivityLogController.php`.

### 4. Pengaturan (threshold & batas klaim)
- `GET /api/v1/dashboard/settings` → `SettingsController@index`.
- `PUT|PATCH /api/v1/dashboard/settings` → `SettingsController@update` (upsert ke `company_settings`).
- Key: `variance_limit`, `max_claim_limit`, `threshold_single`, `threshold_two`, `threshold_three`.
- File baru: `app/Http/Controllers/API/SettingsController.php` (tabel `company_settings` key-value).

### 5. Foto struk untuk web
- `GET /api/v1/dashboard/receipts/{receipt}/image` → `ReceiptController@image`
  (stream file privat dari disk `local`, cek akses company/owner).

Semua route didaftarkan di `routes/api.php` dalam grup `dashboard`.

## Catatan Integrasi Frontend
- Base URL API dikonfigurasi via `VITE_API_BASE_URL` (default `http://localhost:8000/api/v1`).
- Login web mengirim header `X-Platform: web`; token Sanctum disimpan di `localStorage`.
- Scan Invoice di web disimpan sebagai invoice manual (`source` Scan) — belum ada OCR invoice.
- Jalankan backend: `php artisan serve` + `php artisan queue:work` (OCR struk async).

## Manajemen Presensi di Web (menu baru)
Halaman **Presensi & Cuti** (grup Manajemen) menyambungkan endpoint attendance HRD/Admin
yang sudah ada (tidak ada perubahan backend). Komponen: `src/components/AttendanceManagement.tsx`,
API: `attendanceApi` di `endpoints.ts` (+ helper `apiDownload` untuk CSV). Enam tab:
- **Hari Ini** → `GET /dashboard/attendance/today` (rekap check-in / belum / izin).
- **Izin & Cuti** → `GET /dashboard/attendance/leaves` + approve/reject.
- **Karyawan & WFH** → `GET /dashboard/attendance/users` + toggle `POST .../users/{id}/toggle-wfh`.
- **Saldo Cuti** → `GET /dashboard/attendance/leave-balances`.
- **Laporan** → `GET /dashboard/attendance/report` + export CSV `.../report/export`.
- **Kantor** → CRUD `.../settings` (lokasi, radius, jam kerja, toleransi telat).

---

# Perubahan — Sesi 2026-06-25 (Radius Check-in Lapangan)

## Tujuan
HRD bisa mengaktifkan/menonaktifkan validasi radius GPS per karyawan. Karyawan lapangan wajib check-in di sekitar area kerja; karyawan WFH bebas dari mana saja.

## Tiga Mode Check-in Mobile

| `wfh_enabled` | `radius_enabled` | Hasil |
|---|---|---|
| `false` | (bebas) | 403 — gunakan perangkat presensi kantor |
| `true` | `false` | WFH bebas, tanpa cek lokasi (`check_in_type = 'wfh'`) |
| `true` | `true` | Lapangan, wajib dalam radius lokasi kerja (`check_in_type = 'field'`) |

## Perubahan Kode

### Migration baru
`database/migrations/2026_06_25_000001_add_radius_enabled_to_users_table.php`
— tambah kolom `radius_enabled BOOLEAN DEFAULT false` setelah `wfh_enabled`.

Jalankan: `php artisan migrate`

### `app/Models/User.php`
- Tambah `radius_enabled` ke `#[Fillable]`
- Tambah `'radius_enabled' => 'boolean'` ke `casts()`
- Tambah method `hasRadiusEnabled(): bool`

### `app/Http/Controllers/API/AttendanceController.php`
- Import `use App\Services\LocationService`
- Tambah method `toggleRadius()` — toggle `radius_enabled`, catat activity log `radius_toggled`
- Modifikasi `checkIn()`:
  - Mode lapangan: cari kantor terdekat dengan Haversine, tolak jika `distance > radius_meters`
  - Response 403 lapangan menyertakan `distance_meters`, `radius_meters`, `office_name`
  - `check_in_type` = `'field'` (lapangan) atau `'wfh'` (bebas)
  - `check_in_distance_meters` diisi untuk mode lapangan, `null` untuk WFH
- Update `listUsers()` — tambah `radius_enabled` di kolom select

### `app/Http/Controllers/API/AuthController.php`
- Tambah `radius_enabled` ke `userPayload()` agar Flutter tahu mode karyawan saat login

### `routes/api.php`
- Tambah `POST /dashboard/attendance/users/{id}/toggle-radius` → `toggleRadius()`

## Contoh Response toggleRadius

```json
// Aktifkan (ON)
{
  "message": "Validasi radius diaktifkan — karyawan harus presensi di sekitar area kerja.",
  "user": { "id": 5, "name": "Budi", "wfh_enabled": true, "radius_enabled": true }
}

// Nonaktifkan (OFF)
{
  "message": "Validasi radius dinonaktifkan — karyawan bisa presensi dari mana saja (WFH).",
  "user": { "id": 5, "name": "Budi", "wfh_enabled": true, "radius_enabled": false }
}
```

## Contoh Response checkIn — Tolak Radius

```json
{
  "message": "Anda berada di luar area kerja. Jarak Anda 350 meter, batas radius 100 meter dari Kantor Pusat.",
  "distance_meters": 350,
  "radius_meters": 100,
  "office_name": "Kantor Pusat"
}
```

## Catatan untuk Tim Flutter
Baca `radius_enabled` dari respons `GET /api/v1/me`:
- `false` → mode WFH, tidak perlu tampilkan info lokasi
- `true` → mode lapangan, tampilkan jarak ke kantor terdekat sebelum check-in sebagai panduan

---

# Perubahan — Sesi 2026-06-25 (WFH Check-in Window)

## Tujuan
Mencegah karyawan WFH presensi di luar jam wajar (subuh/malam setelah tengah malam reset). HRD bisa mengatur berapa menit sebelum jam masuk karyawan WFH diizinkan presensi.

## Logika
- Kolom baru `wfh_checkin_window_minutes` di `attendance_settings` (default 120 menit, nullable).
- `NULL` = tidak ada pembatasan.
- Integer N = check-in WFH hanya boleh mulai dari `work_start_time - N menit`.
- Hanya berlaku untuk mode WFH (`wfh_enabled=true, radius_enabled=false`). Mode lapangan tidak terpengaruh.

## Contoh
Jam masuk 09:00, window 120 menit → karyawan WFH bisa presensi mulai **07:00 WIB**.
Jika presensi sebelum 07:00 → `403`:
```json
{
  "message": "Presensi WFH belum bisa dilakukan. Silakan presensi mulai jam 07:00 WIB.",
  "window_open_at": "07:00",
  "work_start_time": "09:00"
}
```

## Perubahan Kode

### Migration baru
`database/migrations/2026_06_25_000003_add_wfh_checkin_window_to_attendance_settings.php`
— tambah kolom `wfh_checkin_window_minutes INT DEFAULT 120 NULLABLE`.

Jalankan: `php artisan migrate`

### `app/Models/AttendanceSetting.php`
- Tambah `wfh_checkin_window_minutes` ke `$fillable` dan `casts()`.

### `app/Http/Controllers/API/AttendanceController.php`
- `settingRules()`: tambah validasi `sometimes|nullable|integer|min:0|max:720`.
- `checkIn()`: tambah blok window check setelah cek double check-in — hanya untuk mode WFH, mengacu ke kantor pertama perusahaan.

### `web akuntan/src/components/AttendanceManagement.tsx` (OfficesTab)
- Tambah field "Window WFH" di form tambah/edit kantor.
- Tampilkan info window di card kantor.

## Catatan untuk Tim Flutter
Jika check-in WFH ditolak dengan `window_open_at` di response 403, tampilkan pesan:
> "Presensi belum tersedia. Silakan coba lagi setelah jam {window_open_at} WIB."

---

# Perubahan — Sesi 2026-06-29 (Manajemen Libur Nasional Manual)

## Keputusan Desain
Sistem **tidak** menyediakan fitur seed otomatis untuk libur tahun berikutnya. HRD mengelola kalender libur nasional **sepenuhnya manual** melalui tab **Libur Nasional** di web dashboard.

## Alasan
- Tanggal hari raya (Lebaran, Imlek, Nyepi, dll) **tidak dapat diprediksi akurat** — pemerintah umumkan via SKB 3 Menteri tiap Oktober-November.
- Weekend (Sabtu/Minggu) sudah **otomatis** via `Carbon::isWeekend()` — tidak perlu di-seed.
- Libur nasional bersifat **tetap per tahun** — tidak sering berubah, sehingga input manual 1x per tahun cukup efisien.

## Alur Kerja HRD Setiap Ganti Tahun

1. **Desember tahun berjalan** — pemerintah umumkan SKB libur nasional tahun depan.
2. HRD buka **Web Dashboard → Presensi & Cuti → Tab Libur Nasional**.
3. Klik **Tambah Libur** untuk setiap tanggal merah (15-17 hari/tahun):
   - Tanggal
   - Nama libur
   - Centang **Libur Nasional** (berlaku semua perusahaan)
4. Sistem otomatis pakai data ini untuk:
   - Hitung `total_days` cuti (hanya hari kerja)
   - Deteksi lembur di hari libur (`overtime_minutes` = seluruh `work_minutes`)

## Endpoint yang Tersedia (sudah ada sejak awal)

### HRD/Admin/Super Admin
```
GET    /api/v1/dashboard/attendance/holidays?year=2027
       → listHolidays (filter per tahun, tampilkan libur nasional + libur khusus perusahaan)

POST   /api/v1/dashboard/attendance/holidays
       → storeHolidays (tambah libur nasional atau libur khusus perusahaan)
       Body: { date, name, is_national }

DELETE /api/v1/dashboard/attendance/holidays/{id}
       → destroyHolidays (hanya libur khusus perusahaan yang bisa dihapus, libur nasional protected)
```

### Karyawan (Mobile, read-only)
```
GET /api/v1/attendance/holidays?year=2027
    → listHolidays (untuk kalender mobile, tampilkan libur nasional + libur perusahaan sendiri)
```

## Catatan Penting
- **Libur nasional** (`company_id = NULL`, `is_national = true`) tidak bisa dihapus via API — tetapi **bisa diubah** (nama & tanggal) jika SKB berubah atau ada koreksi.
- **Libur khusus perusahaan** (`company_id` terisi, `is_national = false`) bisa ditambah/ubah/hapus oleh HRD perusahaan tersebut (cuti bersama, anniversary, dll).
- Seeder `HolidaySeeder` hanya berisi data **2026** — untuk tahun berikutnya HRD input manual.

---

## [2026-06-29] - Endpoint Update Libur Nasional

### Perubahan
**Tambah endpoint untuk HRD mengubah libur nasional dan libur perusahaan**

```
PUT/PATCH /api/v1/dashboard/attendance/holidays/{holiday}
→ updateHolidays (ubah nama & tanggal libur)
Body: { date, name }
```

### Detail Implementasi
- **File**: `app/Http/Controllers/API/AttendanceController.php` — method `updateHolidays()`
- **Route**: `routes/api.php` — `Route::match(['put', 'patch'], '/holidays/{holiday}', ...)`
- **Authorization**: HRD/admin/super_admin bisa edit:
  - Libur nasional (`company_id = NULL`)
  - Libur milik perusahaannya sendiri
- **Validasi**:
  - `date` (required|date) & `name` (required|string|max:255)
  - Cegah duplikat: tanggal baru tidak boleh bentrok dengan libur lain di scope yang sama
- **Activity Log**: `holiday_updated` dengan detail perubahan (dari → ke)
- **Response**: Object libur yang sudah diupdate dengan info scope (nasional/perusahaan)

### Use Case
HRD bisa mengoreksi:
- **Tanggal libur nasional** jika pemerintah mengubah SKB (misal: cuti bersama dipindah)
- **Nama libur** jika ada typo atau perubahan resmi
- **Libur perusahaan** (cuti bersama internal, anniversary, dll)

### Perbedaan dengan `destroyHolidays()`
- **Update (PUT/PATCH)**: Libur nasional **bisa diubah** ✅
- **Delete (DELETE)**: Libur nasional **tidak bisa dihapus** ❌ (403 Forbidden)

---

# Perubahan — Sistem Reminder Checkout, Auto-Checkout & Approval Lembur

Tanggal: 2026-07-02

## Tujuan

Mencegah karyawan WFH lupa checkout atau "cheating" (tidak checkout untuk menghitung lembur palsu). Alur:
1. **Reminder** → Flutter kirim notifikasi lokal + backend kirim FCM saat karyawan belum checkout `checkout_reminder_minutes` menit setelah jam pulang.
2. **Auto-Checkout** → Sistem otomatis checkout karyawan yang masih belum checkout setelah `auto_checkout_grace_minutes` menit.
3. **Approval Lembur** → Setiap lembur (dari checkout manual MAUPUN auto-checkout) wajib disetujui HRD. Jika ditolak, `overtime_minutes = 0`.

## Status: SELESAI ✅

---

## Perubahan Database (4 Migration Baru)

### `2026_07_02_000001_add_fcm_token_to_users_table.php`
- Tambah kolom `fcm_token VARCHAR(512) nullable` di tabel `users`
- Untuk menyimpan token Firebase Cloud Messaging device karyawan

### `2026_07_02_000002_add_auto_checkout_to_attendances_table.php`
- Tambah `auto_checkout_at TIMESTAMP nullable` — waktu sistem melakukan auto-checkout
- Tambah `is_auto_checkout BOOLEAN default false` — penanda checkout dilakukan sistem

### `2026_07_02_000003_create_overtime_approvals_table.php`
- Tabel baru `overtime_approvals` (kolom: `attendance_id`, `user_id`, `company_id`, `overtime_minutes`, `status [pending/approved/rejected]`, `reviewed_by`, `reviewed_at`, `notes`, `is_auto_checkout`)
- Index: `(company_id, status)` dan `(user_id, status)`

### `2026_07_02_000004_add_auto_checkout_settings_to_attendance_settings_table.php`
- Tambah `checkout_reminder_minutes INT default 30` — berapa menit setelah jam pulang reminder dikirim
- Tambah `auto_checkout_grace_minutes INT default 60` — berapa menit setelah jam pulang sistem auto-checkout

---

## File Baru

### `app/Models/OvertimeApproval.php`
Model Eloquent untuk tabel `overtime_approvals`. Relasi: `attendance`, `user`, `reviewer`.

### `app/Services/FcmService.php`
Service untuk kirim push notification via Firebase Cloud Messaging (FCM Legacy HTTP API).
- `send($token, $title, $body, $data)` — kirim ke satu device
- `sendMulticast($tokens, $title, $body, $data)` — kirim ke banyak device
- Jika `FCM_SERVER_KEY` tidak dikonfigurasi di `.env`, pengiriman dilewati (sistem tetap berfungsi).

### `app/Console/Commands/AutoCheckoutCommand.php`
Artisan command `attendance:auto-checkout`. Dijalankan scheduler setiap 5 menit:
1. Cari semua attendance hari ini yang check-in tapi belum check-out
2. Jika waktu ≥ `work_end_time + checkout_reminder_minutes` → kirim FCM reminder
3. Jika waktu ≥ `work_end_time + auto_checkout_grace_minutes` → auto-checkout
4. Hitung `overtime_minutes` otomatis
5. Buat record `overtime_approvals` (status: pending) jika ada lembur
6. Notifikasi HRD via DB notifications
7. Notifikasi karyawan via FCM bahwa ia telah di-auto-checkout

---

## Perubahan File yang Ada

### `app/Models/Attendance.php`
- Tambah `auto_checkout_at` dan `is_auto_checkout` ke `$fillable` dan `casts()`
- Tambah relasi `overtimeApproval()`

### `app/Models/User.php`
- Tambah `fcm_token` ke `#[Fillable(...)]` attribute

### `app/Http/Controllers/API/AttendanceController.php`
- Import `OvertimeApproval` dan `FcmService`
- `checkIn()` → tambah `reminder_at` dan `auto_checkout_at` di response (untuk Flutter scheduling)
- `checkOut()` → setelah checkout, cek apakah ada lembur → `createOvertimeApproval()`
- Tambah helper `createOvertimeApproval()` — buat record approval + notifikasi HRD
- Tambah helper `sendFcmPush()` — wrapper FCM via FcmService
- Tambah `registerFcmToken()` — simpan FCM token device
- Tambah `checkStatus()` — cek status presensi hari ini (termasuk jadwal auto-checkout)
- Tambah `listOvertimeApprovals()` — HRD lihat daftar pengajuan lembur
- Tambah `approveOvertime()` — HRD setujui lembur (FCM notif ke karyawan)
- Tambah `rejectOvertime()` — HRD tolak lembur (`overtime_minutes = 0`)
- Tambah `myOvertimeApprovals()` — riwayat lembur karyawan sendiri
- Update `myAttendance()` → sertakan `is_auto_checkout` di response
- Update `settingRules()` → validasi `checkout_reminder_minutes` dan `auto_checkout_grace_minutes`

### `config/services.php`
- Tambah konfigurasi `fcm.server_key` dari env `FCM_SERVER_KEY`

### `routes/api.php` (route baru)
```
# HRD Dashboard (auth:sanctum + role:hrd,admin,super_admin + company)
GET  /api/v1/dashboard/attendance/overtime-approvals         → listOvertimeApprovals
POST /api/v1/dashboard/attendance/overtime-approvals/{id}/approve → approveOvertime
POST /api/v1/dashboard/attendance/overtime-approvals/{id}/reject  → rejectOvertime

# Mobile (auth:sanctum + company + attendance_access)
GET  /api/v1/attendance/status                → checkStatus (polling status presensi hari ini)

# Mobile (auth:sanctum + company)
GET  /api/v1/attendance/my-overtime          → myOvertimeApprovals
POST /api/v1/attendance/fcm-token            → registerFcmToken
```

### `routes/console.php`
- Daftarkan scheduler `attendance:auto-checkout` setiap 5 menit (`everyFiveMinutes()`)

---

## Setup yang Diperlukan

### .env
```env
FCM_SERVER_KEY=your-firebase-server-key-here
```

### Jalankan migration
```bash
php artisan migrate
```

### Aktifkan scheduler (di server production, tambahkan ke crontab)
```bash
* * * * * cd /path/to/project && php artisan schedule:run >> /dev/null 2>&1
```

---

## Panduan Flutter (Sisi Mobile App)

### 1. Daftar FCM Token saat Login
```dart
// Setelah login berhasil, simpan FCM token ke backend
final token = await FirebaseMessaging.instance.getToken();
await api.post('/attendance/fcm-token', {'fcm_token': token});
```

### 2. Jadwalkan Notifikasi Lokal saat Check-in Berhasil
Response `POST /attendance/check-in` sekarang menyertakan:
```json
{
  "reminder_at": "2026-07-02T17:30:00+07:00",
  "auto_checkout_at": "2026-07-02T18:00:00+07:00"
}
```

Gunakan `flutter_local_notifications` + `timezone` untuk jadwalkan:
- **Reminder** di `reminder_at` → "Jangan lupa checkout!"
- **Auto-checkout warning** 5 menit sebelum `auto_checkout_at` → "Sistem akan auto-checkout dalam 5 menit!"

### 3. Tampilkan Status Lembur
Flutter bisa polling `GET /attendance/status` untuk cek status real-time, termasuk apakah overtime sudah approved/rejected oleh HRD.

---

## Logika Bisnis

| Skenario | Behavior |
|---|---|
| Karyawan checkout manual, ada lembur | Buat `overtime_approval` (pending) → HRD approve/reject |
| Karyawan lupa checkout → reminder | FCM push + DB notification di `checkout_reminder_minutes` |
| Karyawan lupa checkout → auto-checkout | Sistem checkout, buat `overtime_approval` (pending, is_auto_checkout=true) |
| HRD approve lembur | `overtime_minutes` tetap sesuai hitungan → masuk payroll |
| HRD reject lembur | `overtime_minutes` di-reset ke 0 → tidak masuk payroll |

---

# Perubahan — Flutter Notification Integration (Lanjutan)

Tanggal: 2026-07-02

## Tujuan
Melengkapi sisi Flutter dari sistem reminder checkout & auto-checkout yang backend-nya selesai di sesi sebelumnya.

## Status: SELESAI ✅

## File yang Diubah / Ditambah

### `lib/main.dart`
- Tambah `import notification_service.dart`
- Panggil `await NotificationService().init()` di `main()` sebelum `runApp()`

### `android/app/src/main/AndroidManifest.xml`
- Tambah permissions: `INTERNET`, `POST_NOTIFICATIONS`, `SCHEDULE_EXACT_ALARM`, `USE_EXACT_ALARM`, `RECEIVE_BOOT_COMPLETED`
- Tambah `<receiver>` untuk `ScheduledNotificationBootReceiver` dan `ScheduledNotificationReceiver` (flutter_local_notifications — restore alarm setelah reboot)

### `lib/providers/auth_provider.dart`
- Import `notification_service.dart`
- Tambah method `_initNotifications()`: request permission + `registerFcmTokenIfAvailable()`
- Dipanggil setelah `login()` berhasil dan setelah `loadSession()` berhasil (auto-login)

### `lib/screens/home_screen.dart`
- `_HomeScreenState` implement `WidgetsBindingObserver`
- Override `didChangeAppLifecycleState`: panggil `syncStatusFromBackend()` saat `AppLifecycleState.resumed`
- `initState`: tambah `addObserver(this)` + panggil `syncStatusFromBackend()` saat pertama buka
- `dispose`: tambah `removeObserver(this)`
- `onTap` tab Presensi (index 2): panggil `syncStatusFromBackend()`

### `lib/presensi_provider.dart`
- `PresensiRecord` tambah field: `id` (attendance ID), `isAutoCheckout`, `overtimeStatus` (nullable)
- `copyWith()` support semua field baru
- `fetchMyAttendance()`: populate `id`, `isAutoCheckout` dari API; lalu panggil `_loadOvertimeStatuses()`
- Tambah method `_loadOvertimeStatuses()`: fetch `GET /attendance/my-overtime`, map `attendance_id → status`, update `overtimeStatus` di setiap `PresensiRecord`

### `lib/screens/presensi_history_screen.dart`
- `_buildHistoryCard()` refactor:
  - Badge "Auto-Checkout" (ungu) jika `record.isAutoCheckout == true`
  - Badge "Hari Libur" (merah) tetap ada
  - Tampilkan badge status approval lembur di bawah chip "Lembur: Xj Ym":
    - `pending` / null → "Menunggu HRD" (oranye/abu)
    - `approved` → "Disetujui" (hijau)
    - `rejected` → "Ditolak" (merah)
  - Helper methods: `_badge()` dan `_overtimeStatusBadge()`

## Alur Lengkap (Flutter)

```
App start / login
  → NotificationService.init() (main)
  → AuthProvider._initNotifications()
      → requestPermission() — minta izin notifikasi Android/iOS
      → registerFcmTokenIfAvailable() — placeholder (TODO: Firebase)

Check-in berhasil
  → Backend response: {reminder_at, auto_checkout_at}
  → NotificationService.scheduleCheckoutReminder(reminder_at)
      → zonedSchedule ID=10 "Jangan Lupa Checkout!"
  → NotificationService.scheduleAutoCheckoutWarning(auto_checkout_at - 5 menit)
      → zonedSchedule ID=11 "Auto-Checkout dalam 5 Menit!"

Checkout manual berhasil
  → NotificationService.cancelCheckoutNotifications() → cancel ID 10 & 11

App resume ke foreground / buka tab Presensi
  → PresensiProvider.syncStatusFromBackend()
      → GET /attendance/status
      → Jika backend catat auto-checkout & lokal belum: update state + cancel notif + show ID=12
      → Jika overtime_approval.reviewed_at dalam 5 menit terakhir:
          approved → show ID=20 "Lembur Disetujui"
          rejected → show ID=21 "Lembur Ditolak"

Halaman Riwayat Presensi (tab Presensi)
  → fetchMyAttendance() → populate is_auto_checkout per record
  → _loadOvertimeStatuses() → GET /attendance/my-overtime → map ke record
  → _buildHistoryCard() tampilkan badge Auto-Checkout + status lembur
```

## Catatan Setup

Notifikasi terjadwal (exact alarm) memerlukan Android 12+ user grant izin di Settings → Apps → Special app access → Alarms & reminders.

Untuk FCM push notification (dari backend), `registerFcmTokenIfAvailable()` di `NotificationService` masih placeholder. Untuk production, integrasikan `firebase_messaging` package dan uncomment kode di dalam method tersebut.

---

# Perubahan — Device Binding Mobile (Cegah Titip Absen / Buddy Punching)

Tanggal: 2026-07-02

## Tujuan

Mencegah karyawan "titip absen" dengan meminjamkan ID & password ke rekan.
Tiap akun karyawan (role `employee`) terikat ke **1 perangkat**. Pindah
perangkat wajib **disetujui HR** lewat dashboard.

Kebijakan yang dipilih:
- **Device pertama** → auto-bind (trust-on-first-use), tanpa approval.
- **Device sama** → login normal.
- **Device berbeda** → login **DITOLAK** + otomatis buat permintaan pindah
  perangkat (pending) yang dikirim ke HR.
- **HR approve** → device baru **menggantikan** device lama (1 akun = 1 device);
  token mobile lama dihapus agar device lama tak bisa dipakai lagi.

## Status: SELESAI ✅ (backend + dashboard). Perlu penyesuaian sisi Flutter.

## Perubahan Backend

### Migration (BARU)
- `2026_07_02_000005_add_device_binding_to_users_table.php`
  → kolom `device_id`, `device_name`, `device_bound_at` di tabel `users`.
- `2026_07_02_000006_create_device_change_requests_table.php`
  → tabel `device_change_requests` (permintaan pindah perangkat + status approval).

### Model
- `app/Models/DeviceChangeRequest.php` (BARU) — relasi `user`, `reviewer`.
- `app/Models/User.php` — tambah 3 kolom device ke Fillable + cast `device_bound_at`.

### `AuthController::login`
- Validasi input `device_id` & `device_name`.
- Gerbang device binding untuk `platform === 'mobile' && role === 'employee'`.
- Helper baru `requestDeviceChange()` — buat/refresh permintaan pending +
  notifikasi ke HR/admin/super_admin (type `device_change_pending`).

### `AttendanceController` (endpoint dashboard HR)
- `listDeviceChanges()`, `approveDeviceChange()`, `rejectDeviceChange()`.

### `routes/api.php` (group `dashboard/attendance`, role hrd/admin/super_admin)
```php
Route::get('/device-changes', [AttendanceController::class, 'listDeviceChanges']);
Route::post('/device-changes/{id}/approve', [AttendanceController::class, 'approveDeviceChange']);
Route::post('/device-changes/{id}/reject', [AttendanceController::class, 'rejectDeviceChange']);
```

## Perubahan Dashboard (web akuntan)
- `src/services/endpoints.ts` — `deviceChangeApi` (list/approve/reject).
- `src/components/DeviceChangeApprovalView.tsx` (BARU) — UI approval HR
  (summary cards, filter, tabel lama→baru, modal approve/reject, pagination).
- `src/App.tsx` — lazy import, route `device-changes`, menu sidebar
  "Pindah Perangkat" (grup Manajemen) + badge jumlah pending, judul halaman.

## TODO sisi Flutter (WAJIB agar fitur jalan)
1. Kirim `device_id` (stabil, mis. dari `device_info_plus` / secure storage) &
   `device_name` di body request **login**.
2. Tangani response login `403` dengan `device_mismatch: true` → tampilkan
   layar "Menunggu persetujuan HR untuk pindah perangkat".
3. (Sudah dari fitur sebelumnya) header `X-Platform: mobile` di semua request.

## Catatan
- Web dashboard TIDAK terkena device binding (hanya mobile + role employee).
- Test suite (`php artisan test`) gagal karena bug **pre-existing**: beberapa
  migration lama pakai `ALTER TABLE ... MODIFY COLUMN` (khusus MySQL) yang tidak
  didukung SQLite in-memory saat test. Tidak berkaitan dengan fitur ini.
  Migration device binding tidak memakai `MODIFY` dan sukses di MySQL.

---

# Perubahan — Penempatan Kantor Karyawan (Office Assignment)

Tanggal: 2026-07-02

## Tujuan
HR bisa menetapkan **kantor tempat karyawan bekerja** (mis. Kantor Pusat,
Kantor Cabang) saat **tambah** & **edit** karyawan, lewat dropdown. Sumber
dropdown = daftar kantor yang sudah ada di menu Presensi & Cuti → Kantor
(tabel `attendance_settings`). Field bersifat **opsional** (boleh
"Belum ditentukan").

## Status: SELESAI ✅

## Perubahan Backend
- Migration BARU `2026_07_02_000007_add_office_to_users_table.php`
  → kolom `attendance_setting_id` (nullable, FK ke `attendance_settings`,
    `nullOnDelete`) di tabel `users`.
- `app/Models/User.php` — `attendance_setting_id` ke Fillable + relasi
  `office()` (belongsTo `AttendanceSetting`).
- `app/Http/Controllers/API/UserController.php`:
  - `index()` — eager load `office:id,office_name` + kolom di select.
  - `store()` & `update()` — validasi `attendance_setting_id` dengan
    `Rule::exists('attendance_settings','id')->where('company_id', ...)`
    (kantor wajib milik perusahaan yang sama), simpan ke user, sertakan di response.

## Perubahan Dashboard (web akuntan)
- `src/components/KaryawanManagement.tsx`:
  - Import `attendanceApi`; state `offices` + `loadOffices()` via
    `attendanceApi.settings.list()` (`{ settings: [...] }`).
  - Tipe `Employee` + `mapEmployee()` — tambah `officeId` & `officeName`
    (dari `u.attendance_setting_id` & `u.office.office_name`).
  - Dropdown "Kantor Penempatan" di **form tambah** & **edit modal** (tab info).
  - Kirim `attendance_setting_id` di payload create & update ('' → null).
  - Tampilkan nama kantor di kolom Departemen pada tabel daftar karyawan.

## Catatan
- Tidak ada endpoint baru — reuse `GET /dashboard/attendance/settings`.
- Kalau belum ada kantor, dropdown menampilkan hint untuk menambah kantor
  lebih dulu di menu Presensi & Cuti → Kantor.

---

# Perubahan — Sesi 2026-07-03 (Scan Struk Terbuka untuk Semua Role)

## Tujuan

Sebelumnya hanya role `employee` yang bisa scan & submit struk via Flutter mobile.
Fitur dibuka ke **semua role** (finance, hrd, admin, super_admin) sehingga siapapun
bisa mengajukan pengeluaran dari aplikasi mobile.

## Status: SELESAI ✅

## Yang Berubah

### Sebelum
- Route `/employee/receipts*` hanya bisa diakses role `employee`
- `User::canAccessReceipts()` return `true` hanya jika `role === 'employee'`
- Finance/HRD/admin/super_admin yang login ke mobile tidak bisa scan struk

### Sesudah
- Semua role authenticated bisa akses `/employee/receipts*`
- `User::canAccessReceipts()` return `true` untuk semua role
- Ownership di controller tetap — user hanya bisa lihat & kelola struk miliknya sendiri

## Perubahan File Backend

### `routes/api.php`
Hapus `role:employee` dari middleware group receipt mobile:
```php
// Sebelum
Route::middleware(['auth:sanctum', 'role:employee', 'company'])

// Sesudah
Route::middleware(['auth:sanctum', 'company'])
```

### `app/Models/User.php`
```php
// Sebelum
public function canAccessReceipts(): bool
{
    return $this->role === 'employee';
}

// Sesudah
public function canAccessReceipts(): bool
{
    return true; // semua role bisa scan & submit struk via mobile
}
```

### `app/Http/Middleware/ReceiptAccessMiddleware.php`
Update komentar & pesan error agar sesuai kebijakan baru.

## Tidak Ada Perubahan Flutter

UI Flutter sudah menampilkan tab Riwayat, FAB kamera scan, dan semua layar
receipt tanpa cek role — tidak perlu diubah. Semua role yang login ke mobile
langsung bisa menggunakan fitur scan.

## Tidak Ada Perubahan Approval Struk (Web)

Route dashboard approval struk (`/dashboard/receipts*`) tetap memakai
`role:finance,admin,super_admin` — HRD dan employee tetap tidak bisa approve
struk di web dashboard.

## Implikasi Bisnis

Finance/HRD/admin yang scan struk mereka sendiri akan diapprove oleh finance
atau admin lain. Self-approval tidak diblokir secara teknis (belum diimplementasi
pencegahan self-approval), tapi alur normal tetap berjalan.

---

# Perubahan — Sesi 2026-07-04 (Fitur Custom Shift / Scheduling per Karyawan & Cabang)

Tanggal: 2026-07-04

## Tujuan

Memungkinkan HRD **menimpa (override) aturan jam kerja global kantor** untuk
karyawan tertentu — misalnya menjadwalkan karyawan masuk di hari Sabtu/Minggu,
atau memakai jam kerja berbeda. Aturan lama (`attendance_settings` per kantor)
tetap berjalan sebagai **fallback default** bila karyawan tidak punya shift khusus.

Sekaligus memperbaiki dukungan **multi-cabang**: tiap cabang (satu baris
`attendance_settings`) kini punya jam kerja & set shift sendiri, dan logika
presensi memakai cabang tempat karyawan ditempatkan.

## Status: SELESAI ✅

## Konsep Data

Satu baris `attendance_settings` = **satu cabang kantor** (sudah ada sejak awal,
punya `office_name`, `work_start_time`, `work_end_time`, `late_tolerance_minutes`,
koordinat & radius sendiri). `users.attendance_setting_id` menautkan karyawan ke
cabangnya (relasi `$user->office()`).

**Logika "shift aktif":** ambil `user_shifts` dengan `start_date` **terbaru yang
sudah ≤ tanggal hari ini**. Jika `shift_id = null` → kembali ke default cabang.
Jika tidak ada baris sama sekali → default cabang.

## Perubahan Database (4 Migration Baru)

| Migration | Isi |
|---|---|
| `2026_07_04_000001_create_shifts_table` | Template shift: `company_id`, `name`, `description`, `is_active` |
| `2026_07_04_000002_create_shift_schedules_table` | Detail 7 hari per shift: `day_of_week` (0=Minggu–6=Sabtu), `work_start_time`, `work_end_time`, `is_off`. Unique(`shift_id`,`day_of_week`) |
| `2026_07_04_000003_create_user_shifts_table` | Mapping karyawan→shift: `user_id`, `shift_id` (nullable=default kantor), `start_date`, `notes`. Unique(`user_id`,`start_date`) |
| `2026_07_04_000004_add_attendance_setting_id_to_shifts_table` | Shift milik **cabang**: `attendance_setting_id` (nullable FK, null=company-wide) |

## File Baru

- `app/Models/Shift.php` — relasi `company()`, `office()`, `schedules()`, `userShifts()`
- `app/Models/ShiftSchedule.php` — accessor `day_name` (nama hari Bahasa Indonesia)
- `app/Models/UserShift.php` — relasi `user()`, `shift()`; cast `start_date` → date
- `app/Http/Controllers/API/ShiftController.php` — seluruh endpoint shift + helper
  `resolveSchedule(User, date)` (static, dipakai lintas kelas)

## Perubahan File yang Ada

### `app/Http/Controllers/API/AttendanceController.php`
- Helper baru `getWorkSchedule(User, date)` → delegasi ke `ShiftController::resolveSchedule()`
- `determineStatus()` — signature berubah jadi `(User, Carbon, date)`, pakai jam masuk shift aktif
- `checkIn()` — WFH window & `reminder_at`/`auto_checkout_at` pakai jam shift; response tambah `active_shift`
- `checkOut()` — pass `$user` ke helper lembur
- `calculateOvertime()` & `checkEarlyLeave()` — signature `(User, ...)`, sadar shift:
  hari `is_off` → seluruh menit kerja jadi lembur; hari kerja shift → lembur dihitung
  setelah jam pulang shift (berlaku walau Sabtu/Minggu)
- `checkStatus()` — response tambah `active_shift`, auto-checkout pakai jam shift

### `app/Console/Commands/AutoCheckoutCommand.php`
- Sadar shift: jam pulang & perhitungan lembur konsisten dengan `checkOut()` manual
  (via `ShiftController::resolveSchedule()`). Sebelumnya selalu pakai jam kantor
  & menganggap Sabtu/Minggu libur → salah untuk karyawan shift.
- `is_holiday` tetap murni kalender (disamakan dengan jalur manual)

### `routes/api.php`
Tambah 10 route di grup `dashboard/attendance` (role `hrd,admin,super_admin` + `company`).

## Endpoint Baru (Dashboard HRD)

| Method | Path | Fungsi |
|---|---|---|
| GET | `/shifts` | Daftar template (filter `?is_active=`, `?attendance_setting_id=`) |
| POST | `/shifts` | Buat template + 7 jadwal harian (wajib `attendance_setting_id`) |
| PUT/PATCH | `/shifts/{id}` | Ubah template + jadwal |
| DELETE | `/shifts/{id}` | Hapus template (diblokir bila masih dipakai assignment) |
| GET | `/shifts/roster` | Daftar shift aktif karyawan hari ini (filter `?date=`, `?attendance_setting_id=`, `?search=`) |
| GET | `/users/{id}/shift-history` | Riwayat assignment shift karyawan |
| POST | `/assign-shift` | Assign shift ke 1 karyawan (`shift_id=null` = kembali ke default) |
| POST | `/bulk-assign` | Assign 1 shift ke banyak karyawan; toleran sebagian (lapor `assigned`/`skipped`) |
| PUT/PATCH | `/assignments/{id}` | Ubah assignment |
| DELETE | `/assignments/{id}` | Hapus assignment |
| GET | `/effective-schedule` | Preview jadwal efektif user+tanggal (untuk UI sebelum submit) |

## Isolasi Data & Keamanan

- Semua endpoint di grup `company` (CompanyMiddleware) — isolasi per `company_id`.
- HRD **terpusat**: bisa kelola semua karyawan se-perusahaan, dengan **filter cabang**
  (`attendance_setting_id`) di `index`/`roster`.
- Assign dicegah **lintas cabang**: shift milik cabang A tidak bisa diberikan ke
  karyawan cabang B (`assertBranchMatch()`). Shift company-wide (`attendance_setting_id=null`)
  boleh untuk siapa saja.
- Activity logs: `shift_created`, `shift_updated`, `shift_deleted`, `shift_assigned`,
  `shift_removed`, `shift_assignment_updated`, `shift_assignment_deleted`.
- Notifikasi ke karyawan (tabel `notifications` + FCM) saat di-assign/ubah/hapus shift.

## Validasi Penting

- Jadwal wajib **tepat 7 hari unik** (0–6).
- Hari non-libur wajib mengisi jam masuk & pulang.
- **Shift lintas tengah malam ditolak** (`work_end_time <= work_start_time`) — perhitungan
  lembur belum mendukungnya, dicegah agar data tidak salah.
- Cegah dua assignment dengan `start_date` sama untuk satu karyawan (koreksi via
  `PUT /assignments/{id}` atau `DELETE`).
- Timezone konsisten **WIB (Asia/Jakarta)** di seluruh perbandingan tanggal/jam.

## Bug Diperbaiki (branch-aware)

Sebelumnya `resolveSchedule()` (dan kode lama) selalu mengambil **cabang pertama
perusahaan** (`orderBy('id')->first()`). Akibatnya karyawan Cabang Surabaya dinilai
pakai jam kerja Cabang Jakarta. Kini memakai `$user->office` (cabang karyawan),
fallback ke cabang pertama hanya bila `attendance_setting_id` karyawan belum di-set.

## Catatan untuk Tim Flutter

- `GET /attendance/status` & response `check-in` kini mengembalikan `active_shift`
  (`shift_name`, `work_start_time`, `work_end_time`) — null bila pakai jam kantor default.
- `reminder_at` / `auto_checkout_at` sudah menyesuaikan jam pulang shift aktif.

## Belum Dikerjakan (opsional, dicatat untuk sesi berikutnya)

- Optimasi N+1 di `roster` (resolveSchedule per karyawan) — belum kritis karena
  difilter per cabang.
- Menampilkan info shift di dashboard `/today`.
- Shift lintas tengah malam (night shift 22:00–06:00) — saat ini ditolak validasi.

---

# Perubahan — Sesi 2026-07-06

## 1. Device Binding Toggle via ENV (`DEVICE_BINDING_ENABLED`)

### Masalah
Fitur device binding (baris 76–109 `AuthController::login()`) memblokir login dari Flutter Web
(browser) karena browser tidak bisa menyediakan `device_id` yang stabil → response
`422 "Identitas perangkat tidak terdeteksi"`.

### Solusi
Tambahkan ENV variable `DEVICE_BINDING_ENABLED` sebagai saklar. Bila `false`, seluruh blok
device binding dilewati. Default `true` untuk production.

### Perubahan File

#### `config/app.php`
Tambah satu key setelah `debug`:
```php
'device_binding_enabled' => (bool) env('DEVICE_BINDING_ENABLED', true),
```

#### `app/Http/Controllers/API/AuthController.php` — method `login()`
Kondisi blok device binding (baris 76) ditambah guard:
```php
// Sebelum
if ($platform === 'mobile' && $role === 'employee') {

// Sesudah
if ($platform === 'mobile' && $role === 'employee'
    && config('app.device_binding_enabled', true)) {
```

#### `.env`
```env
# Set false untuk development/Flutter Web (tanpa device_id). Set true di production.
DEVICE_BINDING_ENABLED=false
```

### Catatan
- Device binding tetap aktif untuk mobile production — cukup set `DEVICE_BINDING_ENABLED=true` di `.env` server.
- Setelah ubah `.env`, jalankan `php artisan config:clear`.

---

## 2. Fix Bug — Cek Saldo Cuti Sebelum Submit Pengajuan

### Masalah
`requestLeave()` tidak memeriksa saldo cuti sebelum membuat record. Pengecekan hanya ada
di `approveLeave()`, sehingga karyawan bisa submit cuti meski kuota habis — HRD baru
mengetahuinya saat approve dan terpaksa menolak di tahap akhir.

### Solusi
Tambahkan blok cek saldo di `requestLeave()` **sebelum** dokumen disimpan dan record dibuat,
khusus untuk `leave_type === 'cuti'`. Izin/sakit/wfh tidak terkena pengecekan (tidak ada kuota).

### Perubahan File

#### `app/Http/Controllers/API/AttendanceController.php` — method `requestLeave()`
Sisipkan setelah blok `if ($totalDays < 1)`:

```php
// Cek saldo cuti sebelum membuat pengajuan agar karyawan langsung tahu di awal
if ($validated['leave_type'] === 'cuti') {
    $year    = Carbon::parse($validated['start_date'])->year;
    $balance = LeaveBalance::firstOrCreate(
        ['user_id' => $user->id, 'year' => $year, 'leave_type' => 'cuti'],
        ['company_id' => $user->company_id, 'quota' => self::DEFAULT_LEAVE_QUOTA['cuti'], 'used' => 0]
    );
    $remaining = $balance->quota - $balance->used;
    if ($remaining <= 0) {
        return response()->json([
            'message'         => 'Saldo cuti Anda sudah habis. Tidak dapat mengajukan cuti.',
            'remaining_quota' => 0,
        ], 422);
    }
    if ($totalDays > $remaining) {
        return response()->json([
            'message'         => "Saldo cuti tidak cukup. Sisa {$remaining} hari, diminta {$totalDays} hari.",
            'remaining_quota' => $remaining,
        ], 422);
    }
}
```

### Response yang Mungkin

| Kondisi | HTTP | Pesan |
|---|---|---|
| `remaining <= 0` | 422 | "Saldo cuti Anda sudah habis" + `remaining_quota: 0` |
| `total_days > remaining` | 422 | "Saldo cuti tidak cukup. Sisa X hari, diminta Y hari" + `remaining_quota: X` |
| Kuota cukup | 201 | Pengajuan berhasil seperti biasa |
| `leave_type` bukan `cuti` | 201 | Tidak dicek, langsung lolos |

### Catatan untuk Tim Flutter
`GET /attendance/leave-balance` sudah mengembalikan field `remaining` per tipe cuti.
Jika `remaining <= 0` untuk `leave_type === 'cuti'`, **disable/sembunyikan tombol "Ajukan Cuti"**
dan tampilkan pesan: *"Saldo cuti Anda sudah habis (0 hari tersisa)"*.
