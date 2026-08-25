# Kekurangan & Temuan Bug — AttendanceController & ShiftController

> Hasil code review menyeluruh terhadap `app/Http/Controllers/API/AttendanceController.php` (±4.000 baris) dan `app/Http/Controllers/API/ShiftController.php` (±2.550 baris) per **2026-08-25**.
> Ditulis dari dua sudut pandang: reviewer kode (bug/cacat logika) dan HRD (kekurangan operasional).

---

## 🔴 Bagian 1 — Bug / Cacat Logika

### 1. KRITIS — Libur nasional bocor lintas perusahaan (multi-tenant leak)

**Lokasi:** `AttendanceController::storeHolidays()` (~line 1869–1874), juga `updateHolidays()` & `destroyHolidays()`

```php
// Saat menambah libur nasional:
Holiday::whereDate('date', $date)->where('is_collective', false)->delete();
```

- Query delete **tanpa filter `company_id`** → HRD perusahaan A yang menambah libur nasional ikut **menghapus libur milik perusahaan B dan C** di tanggal yang sama.
- `storeHolidays()`, `updateHolidays()`, dan `destroyHolidays()` **tidak punya guard super_admin** untuk tipe nasional. Padahal `rules.md` mencatat fix 2026-08-22 klaim "libur nasional hanya bisa di-CRUD super_admin" — guard-nya tidak ada di kode (regresi / kelupaan implementasi).
- Dampak: kehilangan data lintas tenant + privilege escalation HRD.

**Fix yang disarankan:**
1. Tambah guard: tipe `nasional` hanya boleh dibuat/diubah/dihapus oleh `super_admin`.
2. Scope semua query delete/update dengan `company_id` sesuai konteks.

---

### 2. TINGGI — Cuti bersama salah hitung untuk karyawan shift ✅ SELESAI 2026-08-25

**Lokasi:** `AttendanceController::createCollectiveLeaveRequests()` (~line 2552)

```php
$totalDays = $this->countWorkingDays(
    Carbon::parse($date), Carbon::parse($date),
    $companyId,
    $holiday->attendance_setting_id
    // ← TANPA parameter $userId → fallback isWeekend() global
);
```

- `total_days` dihitung **sekali untuk SEMUA karyawan** tanpa mempertimbangkan jadwal shift masing-masing.
- Akibat konkret: cuti bersama jatuh di Sabtu/Minggu → `total_days = 0` → **tidak ada leave_request dibuat sama sekali**, padahal karyawan shift minggu/sabtu justru dijadwalkan masuk hari itu.
- Kasus nyata: karyawan dengan shift "shif minggu" (Sabtu–Minggu masuk) tidak mendapat pilihan opt-in cuti bersama.
- `respondCollectiveLeave()` punya auto-create sebagai jaring pengaman, tapi notifikasi massal & angka "pending" di dashboard HRD sudah salah sejak awal.

**Fix yang disarankan:** hitung `total_days` per-karyawan (`countWorkingDays(..., $user->id)` di dalam loop), atau minimal perbaiki perhitungan pending di dashboard agar konsisten dengan auto-create.

> **✅ DIPERBAIKI 2026-08-25** — dua perubahan di `AttendanceController`:
> 1. `createCollectiveLeaveRequests()`: `total_days` kini dihitung **per-karyawan** (dengan `$userId`, shift-aware). Karyawan yang hari cuti bersama LIBUR dari jadwal shift-nya dilewati — tidak dibuatkan leave_request dan tidak dinotifikasi. Karena itu juga, notifikasi `collective_leave_announced` hanya dikirim ke karyawan terdampak.
>    - **Tambahan fix (2026-08-25, kasus #86):** filter `attendance_enabled=true` DIHAPUS — sebelumnya HRD/finance/super_admin dengan attendance_enabled=0 (dewi@majubersama.co.id, super@majubersama.co.id) ter-skip dari cuti bersama padahal jadwal shift-nya kerja di tanggal tsb dan mereka memang harus ikut. Yang menentukan: aktif + cabang target + jadwal kerja di tanggal itu.
>    - **Idempoten:** regenerate (via updateHolidays scope change) tidak lagi membuat duplikat — user yang sudah punya leave_request untuk holiday tsb di-skip.
> 2. `listHolidays()` → `collective_summary`: angka `pending`/`total` kini diturunkan dari leave_requests NYATA (bukan total karyawan aktif cabang), sehingga sinkron dengan banner mobile.
>
> Verifikasi (holiday #86 "adsfsdfsdf", Kamis 27 Aug 2026, Kantor Pusat): 4 leave_request pending = Siti Rahayu, Rina Susanti, **Dewi Lestari, Super Admin**; Budi libur Kamis benar di-skip; regenerate ke-2 tidak menambah baris (idempoten ✔); summary HRD pending=4/total=4 ✔.

---

### 3. SEDANG — `requestLeave()` memakai waktu UTC bukan WIB ✅ SELESAI 2026-08-25

**Lokasi:** `AttendanceController::requestLeave()` (~line 3914)

```php
$todayStr = now()->toDateString(); // ← UTC!
```

- Controller sudah punya helper `todayDate()` (WIB) yang dibuat persis untuk menghindari ini, tapi tidak dipakai di sini.
- Jam 00:00–06:59 WIB (= 17:00–23:59 UTC kemarin): validasi "pengajuan harus besok ke atas" membandingkan dengan tanggal UTC yang salah → pengajuan bisa ditolak/diterima di tanggal keliru.

**Fix:** ganti `$todayStr = $this->todayDate();`

> **✅ DIPERBAIKI 2026-08-25** — `$todayStr` kini memakai `$this->todayDate()` (WIB).
> Dampak perubahan TERLOKALISASI di satu baris pembanding validasi; tidak menyentuh data/jadwal tersimpan.
> Verifikasi (simulasi jam dini hari, Rabu 02:00 WIB = Selasa 19:00 UTC):
> ajukan utk hari ini WIB → ditolak 422 ✓ (kode lama menerima — celah);
> ajukan utk besok WIB → diterima 201 ✓.

---

### 4. SEDANG — Guard jam kerja membandingkan kolom yang salah

**Lokasi:** `ShiftController::assignShift()` (~line 927)

```php
->where('id', '!=', $validated['shift_id'] ?? 0) // ← PK user_shifts vs shift_id input
```

- Membandingkan primary key `user_shifts.id` dengan `shift_id` dari input request — dua entitas berbeda.
- Guard anti-"ubah shift di tengah jam kerja" jadi salah mengecualikan/menyasar assignment.

**Fix:** bandingkan dengan assignment lama secara eksplisit, mis. simpan `$activeOld` dulu lalu exclude by id assignment itu, atau filter `where('shift_id', '!=', ...)` jika maksudnya shift baru.

---

### 5. SEDANG — `bulkAssign()` tidak konsisten dengan `assignShift()`

**Lokasi:** `ShiftController::bulkAssign()`

- Guard `coversToday` / `checkWithinWorkingHours` (anti-rubah jadwal di tengah jam kerja) hanya ada di `assignShift()` individual dan `updateAssignment()`.
- Bulk assign **melewatinya sepenuhnya** → HRD bisa mengubah jadwal puluhan karyawan di tengah shift hari ini lewat jalur massal.

**Fix:** replika guard coversToday per-karyawan di loop bulk (skip + laporkan seperti validasi lain).

---

### 6. RENDAH — Race condition saldo cuti

**Lokasi:** `approveLeave()` & `respondCollectiveLeave()`

- Pola cek-saldo-lalu-increment dilakukan **tanpa DB transaction / lockForUpdate**.
- Dua approval hampir bersamaan (HRD ganda, atau respond cuti bersama bersamaan) bisa membuat saldo minus.

**Fix:** bungkus dalam `DB::transaction` + `LeaveBalance::lockForUpdate()` saat cek & potong saldo.

---

### 7. RENDAH — `monthlySummary()` absent undercount

**Lokasi:** `AttendanceController::monthlySummary()`

```php
$attendanceDays = (int) array_sum($attCounts->toArray());
```

- Menjumlahkan **semua** record presensi bulan itu, termasuk presensi di hari libur nasional (yang tidak masuk `working_days`).
- Karyawan masuk di hari libur nasional → jumlah hadir naik tapi hari wajib masuk tetap → angka `absent = working_days - attendance - leave` jadi lebih rendah dari kenyataan (bisa minus, ditahan `max(0,...)`).

**Fix:** hitung `attendanceDays` hanya dari tanggal yang termasuk `working_days` (join/filter per tanggal off-map).

---

### 8. RENDAH — Lain-lain

| Lokasi | Masalah |
|--------|---------|
| `ShiftController::store()` line ~257 | `'is_active' => $validated['is_active'] ?? true` — field `is_active` TIDAK ada di rules validasi → tidak pernah bisa di-set saat create (selalu true). Hapus baris atau tambahkan rule. |
| `ShiftController::destroyAssignment()` | Assignment dibuat-hari-ini-lalu-diakhiri-hari-ini dapat `end_date = start_date` (= hari ini) → masih aktif hari ini, padahal pesan response mengklaim "kembali ke default mulai hari ini". |
| `approveLeave()` / `rejectLeave()` | Notifikasi ke karyawan hanya DB notification, tanpa push FCM — tidak konsisten dengan overtime approval yang mengirim FCM. |
| `checkOut()` | Tidak memvalidasi GPS sama sekali (check-in divalidasi ketat radius; checkout koordinatnya cuma disimpan). Device binding mengurangi risiko, tapi tetap celah "checkout dari mana saja". |

---

## 👔 Bagian 2 — Kekurangan dari Sudut Pandang HRD

| # | Kekurangan | Dampak Operasional |
|---|-----------|-------------------|
| 1 | **Tidak ada koreksi presensi manual** — HRD tidak bisa edit/lengkapi absen yang salah (lupa checkout sudah ter-handle auto-checkout, tapi check-in hilang/salah lokasi tidak bisa diperbaiki HRD) | Data laporan permanen salah; sudah tercatat di roadmap rules.md P3 |
| 2 | **Tidak ada batas maksimal lembur** — UU 13/2003: lembur maks 4 jam/hari & 18 jam/minggu. Sistem menghitung lembur tanpa cap | Risiko kepatuhan ketenagakerjaan |
| 3 | **Tidak ada notifikasi proaktif ke HRD saat pengaturan kantor diubah** — rekomendasi strategi proteksi #3 di rules.md sendiri belum diimplement | Perubahan `work_end_time`/grace mendadak bisa merusak auto-checkout hari itu; audit log ada tapi pasif (tidak ada yang membaca) |
| 4 | **Tarif/rate lembur belum ada** — approval lembur menghasilkan menit, tidak ada konversi nominal | Payroll (roadmap FASE 2) nanti mentok di sini |
| 5 | **Shift swap antar karyawan belum ada** — semua perubahan harus lewat HRD manual | Beban admin tinggi untuk shift 24 jam (sudah di roadmap P1) |
| 6 | **Delegasi approver** — kalau satu-satunya admin/super_admin berhalangan (cuti), approval lembur/device-change macet | Proses berhenti saat orang kuncinya tidak ada (roadmap P0 delegasi) |
| 7 | **Laporan tidak bisa dikelompokkan per shift** — filter laporan hanya department/office/status/type | HRD tidak bisa menjawab "berapa telat di shift malam minggu ini?" tanpa export manual |

### Yang Sudah Baik (patut dipertahankan)
- Versioning jadwal shift dengan minimum notice period (H-N hari)
- Validasi K3: jeda istirahat antar shift (<8 jam tolak, 8–11 jam warning), min 1 hari libur/minggu, batas jam mingguan
- Device binding + approval pindah perangkat (anti titip absen)
- Audit trail lengkap via activity_logs dengan standardized action names
- Pemisahan role & isolasi company_id yang cukup rapi di mayoritas endpoint

---

## ✅ Bagian 3 — Rekomendasi Urutan Perbaikan

| Urutan | Item | Alasan |
|--------|------|--------|
| 1 | Bug #1 (multi-tenant holidays) | Kehilangan data lintas perusahaan — risiko tertinggi |
| 2 | Bug #2 (total_days cuti bersama) | Langsung memengaruhi karyawan shift & akurasi dashboard HRD |
| 3 | Bug #3 & #4 (one-line fix) | Murah, cepat, menghilangkan perilaku tak terduga |
| 4 | Bug #5–#8 | Masuk sprint berikutnya |
| 5 | Kekurangan HRD #2 (batas lembur UU) & #6 (delegasi) | Kepatuhan & resiliensi proses |

---
*Catatan dibuat otomatis hasil review Claude — 2026-08-25. Status: Bug #2 & Bug #3 SELESAI (2026-08-25); sisanya masih open/belum diperbaiki.*
