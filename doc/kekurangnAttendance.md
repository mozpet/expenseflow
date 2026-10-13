# Kekurangan & Temuan Bug — AttendanceController & ShiftController

> Hasil code review menyeluruh terhadap `app/Http/Controllers/API/AttendanceController.php` (±4.000 baris) dan `app/Http/Controllers/API/ShiftController.php` (±2.550 baris) per **2026-08-25**.
> Ditulis dari dua sudut pandang: reviewer kode (bug/cacat logika) dan HRD (kekurangan operasional).

---

## 🔴 Bagian 1 — Bug / Cacat Logika

### 1. KRITIS — Libur nasional bocor lintas perusahaan (multi-tenant leak) & Guard Super Admin ✅ SELESAI 2026-08-28

**Lokasi:** `AttendanceController::storeHolidays()` (~line 2213 & 2260), `updateHolidays()` (~line 2339 & 2426), & `destroyHolidays()` (~line 2482)

```php
// Guard Super Admin untuk Libur Nasional:
if ($isNational && $user->role !== 'super_admin') {
    return response()->json(['message' => 'Hanya super admin yang berwenang menambahkan hari libur nasional.'], 403);
}

// Pencegahan Multi-Tenant Delete Leak:
if ($isNational) {
    Holiday::whereDate('date', $date)
        ->where('is_collective', false)
        ->where(function ($q) use ($companyId) {
            $q->whereNull('company_id')->orWhere('company_id', $companyId);
        })
        ->delete();
}
```

- **Cacat Sebelumnya:** Query delete tidak membatasi `company_id` sehingga penambahan libur nasional menghapus libur milik tenant lain, serta belum adanya guard hak akses `super_admin`.
- **✅ DIPERBAIKI 2026-08-28:** 
  1. Ditambahkan guard: tipe libur `nasional` HANYA boleh dibuat, diubah, dan dihapus oleh akun ber-role `super_admin`.
  2. Scope query pembersihan libur lama saat tambah/update libur nasional dibatasi HANYA untuk libur nasional lama (company_id NULL) atau libur milik perusahaan aktor sendiri, melindungi data milik tenant/perusahaan lain dari penghapusan tidak sengaja.

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

### 4. [SELESAI ✅ 2026-09-04] Fleksibilitas HRD Mengubah/Menghapus Shift di Tengah Hari Kerja dengan Proteksi Snapshot Presensi

**Lokasi Kode:**
- Migrasi DB: `2026_09_04_000003_add_snap_shift_details_to_attendances_table.php` (`snap_shift_id`, `snap_shift_name`).
- Model: `Attendance.php` (fillable, casts, `buildSnapshot()`, `snapshotSchedule()`).
- Controller Presensi: `AttendanceController.php` (`checkStatus()`, `checkOut()`).
- Controller Shift: `ShiftController.php` (`destroyAssignment()`, `updateAssignment()`, `assignShift()`, `bulkAssign()`).
- Frontend Web: `ShiftManagement.tsx` (pemberitahuan proteksi sesi aktif).
- Automated Tests: `tests/Feature/ShiftSnapshotMiddayChangeTest.php` (3 passed tests).

**Masalah Sebelumnya:**
1. Di masa lalu, terdapat guard kaku `checkWithinWorkingHours()` yang menolak HRD (HTTP 422) jika mencoba mengubah (`updateAssignment`) atau menghapus (`destroyAssignment`) penugasan shift seorang karyawan di tengah jam kerja aktif hari itu.
2. Penugasan shift baru juga memblokir tanggal mulai hari ini (`start_date <= today`), memaksa selalu H+1.
3. Jika shift dihapus di tengah hari kerja, tanpa sistem snapshot jadwal aktif, karyawan yang sedang berdinas akan kehilangan acuan shift dan mendadak terdisrupsi ke jadwal kantor default saat presensi/checkout.

**Solusi & Arsitektur Snapshot yang Diimplementasikan:**
1. **Pencabutan Blokir Kaku (Unblocking HRD):**
   - HRD kini diizinkan **bebas mengubah atau menghapus/mengakhiri penugasan shift kapan saja** tanpa penolakan HTTP 422.
   - `start_date` diizinkan mulai hari ini (H-0) dan hanya menolak tanggal lampau.
2. **Penguncian Detail Shift di Record Presensi (`snapshot`):**
   - Saat karyawan melakukan check-in, record `attendances` menyimpan snapshot lengkap aturan saat masuk: `snap_source = 'shift'`, `snap_work_start_time`, `snap_work_end_time`, `snap_is_cross_day`, serta `snap_shift_id` dan `snap_shift_name`.
3. **Proteksi Sesi Kerja Aktif (Active Session Protection):**
   - Karyawan yang **sudah check-in hari ini** tetap menyelesaikan tugas dan melakukan check-out berdasarkan jadwal shift yang dibekukan saat check-in (`snapshotSchedule()`).
   - Perhitungan jam kerja aktual, jam istirahat (`break_minutes`), status keterlambatan, kepulangan awal, lembur, dan auto-checkout tetap konsisten mengacu pada shift saat check-in.
   - Endpoint status aplikasi mobile (`checkStatus`) memprioritaskan jadwal dari `snapshot` selama karyawan masih berada dalam sesi aktif (belum checkout), sehingga tampilan jam kerja di HP karyawan tidak berubah mendadak di tengah hari.
4. **Momen Transisi:**
   - Jadwal baru (atau jadwal default kantor bila shift dihapus) **otomatis berlaku setelah karyawan menekan tombol Check-Out** (atau mulai hari kerja berikutnya).
   - Jika karyawan **belum check-in hari ini** dan HRD menghapus shift, jadwal hari ini langsung kembali ke default kantor.

---

### 5. SEDANG — `bulkAssign()` tidak konsisten dengan `assignShift()` ✅ SELESAI 2026-08-25

**Lokasi:** `ShiftController::bulkAssign()`

- Guard `coversToday` / `checkWithinWorkingHours` (anti-rubah jadwal di tengah jam kerja) hanya ada di `assignShift()` individual dan `updateAssignment()`.
- Bulk assign **melewatinya sepenuhnya** → HRD bisa mengubah jadwal puluhan karyawan di tengah shift hari ini lewat jalur massal.

**Fix:** replika guard coversToday per-karyawan di loop bulk (skip + laporkan seperti validasi lain).

> **✅ DIPERBAIKI 2026-08-25** — `bulkAssign()` kini punya guard jam kerja yang konsisten dengan `assignShift()` individual:
> 1. **Preload bulk (hindari N+1):** assignment shift LAMA yang sedang aktif hari ini untuk semua karyawan target diambil dalam satu query (`$activeOldByUser`, eager-load `shift`), dikelompokkan per `user_id` → ambil assignment terbaru yang aktif.
> 2. **`$coversToday` dihitung sekali** (start_date & end_date seragam untuk semua target) — assignment baru dianggap berlaku hari ini jika `start_date <= today && (end_date null || end_date >= today)`.
> 3. **Guard per-karyawan di dalam loop** (sebelum insert): bila `coversToday` dan ada shift lama aktif yang berbeda dari shift baru → panggil `checkWithinWorkingHours($activeOld)`. Bila sedang dalam jam kerja → karyawan **di-skip + dilaporkan** dengan alasan (konsisten pola validasi lain), tidak menggagalkan seluruh batch.
>
> Efisien: query aktif-lama hanya 1× untuk seluruh batch (bukan per karyawan). `php -l` lolos tanpa error.

---

### 6. RENDAH — Race condition saldo cuti ✅ SELESAI 2026-08-25

**Lokasi:** `approveLeave()` & `respondCollectiveLeave()`

- Pola cek-saldo-lalu-increment dilakukan **tanpa DB transaction / lockForUpdate**.
- Dua approval hampir bersamaan (HRD ganda, atau respond cuti bersama bersamaan) bisa membuat saldo minus.

**Fix:** bungkus dalam `DB::transaction` + `LeaveBalance::lockForUpdate()` saat cek & potong saldo.

> **✅ DIPERBAIKI 2026-08-25** — keduanya kini memakai `DB::transaction` + `lockForUpdate()`:
> 1. **`approveLeave()`:** seluruh alur (ambil leave + cek status pending + guard cuti bersama → firstOrCreate saldo → cek sisa → update status approved → increment used) dipindah ke dalam SATU transaksi.
>    - Baris `leave_requests` dikunci via `lockForUpdate()->find($id)` → HRD ganda yang approve permintaan sama secara paralel: request kedua menunggu lock, lalu membaca status terbaru (`approved`) → ditolak 403, tidak dobel-potong.
>    - Baris `leave_balances` dikunci ulang setelah `firstOrCreate` (`whereKey(...)->lockForUpdate()`, karena firstOrCreate tidak mengunci) → cek sisa & increment menjadi atomik; dua approval cuti berbeda milik karyawan yang sama tidak bisa membuat saldo minus.
>    - Guard gagal (404/403/422) kini lewat `abort()` di dalam transaksi → transaksi rollback otomatis & response JSON tetap bentuk lama `{message}` (exception handler sudah `shouldRenderJsonWhen(api/*)`).
> 2. **`respondCollectiveLeave()`:** baca-status → auto-create → cek "pilihan sama" → cek saldo (policy block) → update collective_status → increment/decrement, semuanya dalam satu transaksi dengan lock pada baris leave_request (per user+holiday) DAN baris saldo.
>    - Dobel klik accept di dua tab: respons kedua menunggu lock, baca status terbaru → tertangkap guard "pilihan sama", tanpa dobel potong.
>    - Ganti pikiran accepted→declined paralel dengan accept lainnya: serialisasi lock mencegah dobel kembalikan saldo.
>    - Closure mengembalikan array `['kind' => same|insufficient|accepted|declined, ...]`; response HTTP dibangun di luar transaksi dengan shape JSON yang persis sama seperti sebelumnya (termasuk field `remaining_quota`, `remaining`, `required`, `policy`).
>
> Urutan penguncian konsisten di kedua endpoint (leave row dulu → balance row) untuk hindari deadlock silang.
> Verifikasi: `php -l` lolos. Test suite feature AttendanceTest gagal semua karena masalah infrastruktur pre-existing (migration pakai `ALTER TABLE ... MODIFY COLUMN ENUM` MySQL-only, tidak jalan di SQLite in-memory test) — terbukti gagal juga tanpa perubahan ini (dicek via git stash).

---

### 7. RENDAH — `monthlySummary()` absent undercount ✅ SELESAI 2026-08-26

**Lokasi:** `AttendanceController::monthlySummary()`

```php
$attendanceDays = (int) array_sum($attCounts->toArray());
```

- Menjumlahkan **semua** record presensi bulan itu, termasuk presensi di hari libur nasional (yang tidak masuk `working_days`).
- Karyawan masuk di hari libur nasional → jumlah hadir naik tapi hari wajib masuk tetap → angka `absent = working_days - attendance - leave` jadi lebih rendah dari kenyataan (bisa minus, ditahan `max(0,...)`).

**Fix:** hitung `attendanceDays` hanya dari tanggal yang termasuk `working_days` (join/filter per tanggal off-map).

> **✅ DIPERBAIKI 2026-08-26** — dua perubahan di `monthlySummary()`:
> 1. **Hapus agregasi `groupBy('status')` lama** — ganti dengan query `select('date', 'status')->get()` untuk mendapat semua baris presensi raw, lalu breakdown status di-loop manual (`$attCountsBreakdown`). Breakdown ini tetap menghitung SEMUA presensi termasuk hari libur (agar angka `present`/`late`/`early_leave` tidak berubah perilakunya).
> 2. **`$attendanceDays` (total_check_in) sekarang difilter hari kerja** — dari koleksi yang sudah diambil, `pluck('date')->unique()` lalu di-`filter()` menggunakan `$offMap` dan `$regularHolidaySet`/`$acceptedCollectiveLeavesSet` yang persis sama logikanya dengan penghitung `$workingDays` di atas. Ini memastikan konsistensi penuh: presensi di hari libur nasional tidak masuk hitungan, sehingga `absent = working_days - attendanceDays - totalLeaveDays` tidak bisa undercount (negatif).
> - Bonus: duplikat baris attendance di tanggal sama (data kotor) ditangani dengan `.unique()` — tidak lagi dobel terhitung.
> - `$typeCounts` (breakdown onsite/wfh/field) dipindah setelah kalkulasi utama agar tidak dipakai sebelum didefinisikan.
> - `php -l` lolos tanpa error.

---

### 8. RENDAH — Lain-lain

| Lokasi | Masalah |
|--------|---------|
| ~~`ShiftController::store()` line ~257~~ | ~~`'is_active' => $validated['is_active'] ?? true` — field `is_active` TIDAK ada di rules validasi → tidak pernah bisa di-set saat create (selalu true). Hapus baris atau tambahkan rule.~~ ✅ SELESAI 2026-08-26 — rule `is_active` => `sometimes|boolean` sudah ditambahkan pada array `$request->validate`. |
| ~~`ShiftController::destroyAssignment()`~~ | ~~Assignment dibuat-hari-ini-lalu-diakhiri-hari-ini dapat `end_date = start_date` (= hari ini) → masih aktif hari ini, padahal pesan response mengklaim "kembali ke default mulai hari ini".~~ ✅ SELESAI 2026-08-26 — ditutup dengan validasi H+1 minimum pada `assignShift()` & `updateAssignment()` (start_date tidak bisa hari ini ke belakang). |
| ~~`approveLeave()` / `rejectLeave()`~~ | ~~Notifikasi ke karyawan hanya DB notification, tanpa push FCM — tidak konsisten dengan overtime approval yang mengirim FCM.~~ ✅ SELESAI 2026-08-26 — kedua fungsi kini mengirim push FCM (`sendFcmPush`) ke karyawan setelah `notifyUser()`, dengan pola yang persis sama seperti `approveOvertime()`/`rejectOvertime()`. Judul notifikasi: "✅ Cuti Disetujui" / "❌ Izin Ditolak" dst. |
| ~~`checkOut()`~~ | ~~Tidak memvalidasi GPS sama sekali (check-in divalidasi ketat radius; checkout koordinatnya cuma disimpan). Device binding mengurangi risiko, tapi tetap celah "checkout dari mana saja".~~ ✅ SELESAI 2026-08-26 — checkout untuk mode `onsite`/`field` kini divalidasi radius kantor terdekat (pola persis sama dengan checkIn). Mode `wfh` tetap tanpa validasi radius (konsisten dengan checkIn WFH). |

---

### 9. SEDANG — `listLeaveBalances()` fallback kuota hardcoded 12 + saldo cuti otomatis aktif ✅ SELESAI 2026-08-25

**Lokasi:** `AttendanceController::listLeaveBalances()` (line ~903) + semua titik auto-create `LeaveBalance`

```php
$defaultQuotas = ['cuti' => self::DEFAULT_LEAVE_QUOTA['cuti'] ?? 12, 'izin' => 0];
```

- Daftar Saldo Cuti milik HRD menampilkan **kuota fallback hardcoded 12** untuk karyawan yang belum punya baris `leave_balances` — mengabaikan `default_leave_quota` kantor (kontradiksi dengan keputusan 2026-08-25 di rules.md).
- Lebih dalam: 5 titik auto-create (`myLeaveBalance`, `requestLeave`, `approveLeave`, `respondCollectiveLeave`, `listCollectiveLeaves`) mengisi baris baru dengan kuota kantor penuh → karyawan baru **langsung punya saldo cuti aktif** tanpa sepengetahuan HRD.

**✅ DIPERBAIKI 2026-08-25** — dua perubahan sekaligus:

1. **Fix fallback:** baris belum-dibuat kini tampil NON-AKTIF (`quota=0`, `active=false`) — bukan lagi 12 fiktif. Kuota default kantor tetap dikirim sebagai referensi (`office_default_quota`) agar HRD tahu nilai wajar saat mengaktifkan. Preload kantor sekali (anti N+1).
2. **Kebijakan BARU — saldo cuti non-aktif secara default:**
   - Semua auto-create `cuti` kini membuat baris dengan **`quota = 0`** → karyawan baru (atau yang belum punya baris) **tidak bisa mengajukan/di-approve cuti sampai HRD mengisi kuota manual** di tab Saldo Cuti (`setLeaveBalance`).
   - Penolakan dibedakan pesannya: *"Saldo cuti belum diaktifkan oleh HRD"* (quota=0 & used=0) vs *"saldo habis/tidak cukup"*.
   - Titik yang disesuaikan: `approveLeave()`, `requestLeave()`, `myLeaveBalance()`, `respondCollectiveLeave()` (+flag `not_activated`), `listCollectiveLeaves()`, helper `reapplyLeaveDeductionAfterHolidayRemoval()`.
   - **Reset tahunan** (`attendance:reset-leave-balances`) hanya me-reset baris AKTIF (quota > 0); baris quota 0 / tidak ada dibiarkan non-aktif — reset tidak boleh mengaktifkan saldo otomatis.
   - Tanpa migration: memakai konvensi `quota = 0` = non-aktif; penegakan sudah ada lewat cek sisa saldo di semua jalur.
   - ⚠️ Konsekuensi: karyawan lama yang belum punya baris saldo juga jadi non-aktif sampai diaktifkan HRD. Baris yang sudah ada tidak tersentuh.

| ⚠️ Catatan (Fix 2026-08-28) | `setLeaveBalance()` sebelumnya masih menerima `leave_type=sakit` | ✅ SELESAI 2026-08-28 — validasi `leave_type` di `setLeaveBalance()` telah diperbaiki menjadi `required|in:cuti,izin` agar konsisten dengan tabel `leave_balances`. |

---

### 10. TINGGI — Ketidakkonsistenan Pengambilan Shift Aktif (`resolveSchedule` vs `roster` & `myScheduleCalendar`) ✅ SELESAI 2026-08-28

**Lokasi:** `ShiftController::resolveSchedule()` (line ~2098) & `AttendanceController::resolveOffDatesForUser()` (line ~2753)

```php
// Di resolveSchedule():
$userShift = UserShift::with('shift')
    ->where('user_id', $user->id)
    ->where('start_date', '<=', $date)
    ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $date))
    ->orderByDesc('start_date')
    ->first();
```

- **Cacat Logika Sebelumnya:** Query mengambil 1 baris `user_shifts` terbaru berdasarkan `start_date <= date` **tanpa memfilter `end_date` di SQL**. Kemudian di PHP dicek apakah `end_date >= date`. Jika `end_date` sudah lewat (shift sementara sudah kadaluarsa), sistem **langsung jatuh ke jam default kantor**, mengabaikan assignment shift utama/reguler yang `start_date`-nya lebih lama dan `end_date`-nya `null`.
- **Skenario Nyata:**
  1. Karyawan punya Shift Utama: `start_date: 2026-01-01`, `end_date: null` (Shift Pagi reguler).
  2. HRD memberi Shift Sementara: `start_date: 2026-08-01`, `end_date: 2026-08-10` (Shift Proyek 10 hari).
  3. Pada **15 Agustus 2026**:
     - `roster()` (Web HRD) & `myScheduleCalendar()` (Mobile) memfilter `where(end_date null or end_date >= date)` di SQL sehingga menemukan **Shift Utama (Shift Pagi)**.
     - `resolveSchedule()` (Dipakai saat Check-In/Out) mengambil baris 1 Agustus (karena start_date terbaru), melihat sudah expired di PHP, lalu **jatuh ke Default Kantor**!
- **Dampak:** Di kalender mobile karyawan melihat jadwalnya kembali ke Shift Pagi, tapi saat absen check-in/out dihitung berdasarkan Jam Kantor Default (bisa salah hitung status telat & lembur).
- **✅ DIPERBAIKI 2026-08-28:** Filter `where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $date))` ditambahkan langsung pada query SQL di `ShiftController::resolveSchedule()` dan pencarian kandidat di `AttendanceController::resolveOffDatesForUser()`. Sekarang penentuan shift aktif konsisten 100% di semua controller & tampilan.

---

### 11. SEDANG — `destroyAssignment()` Menetapkan `end_date = today` untuk Assignment Mulai Hari Ini ✅ SELESAI 2026-08-28

**Lokasi:** `ShiftController::destroyAssignment()` (line ~1399)

```php
// Jika assignment mulai hari ini -> hapus permanen agar langsung kembali ke default kantor hari ini
if ($startStr === $today) {
    $userShift->delete();
} else {
    // Jika assignment masa lalu -> soft-end: set end_date = kemarin
    $userShift->end_date = Carbon::yesterday('Asia/Jakarta')->toDateString();
    $userShift->save();
}
```

- **Cacat Logika Sebelumnya:** Jika ada assignment yang dibuat hari ini (`start_date = today`) lalu langsung dihapus/diakhiri oleh HRD hari ini juga, nilai `$yesterday < $startStr` menyebabkan `end_date` di-set menjadi `$startStr` (= hari ini).
- **Dampak:** Karena `end_date` adalah inklusif (`end_date >= today` bernilai `true` sepanjang sisa hari), assignment tersebut **masih dianggap aktif hari ini**, bertentangan dengan pesan API *"karyawan kembali ke jadwal kantor default mulai hari ini"*.
- **✅ DIPERBAIKI 2026-08-28:** Diperiksa kondisi `$startStr === $today`. Jika assignment baru dibuat hari ini (belum ada histori hari-hari kemarin), baris langsung dihapus permanen (`delete()`) sehingga karyawan seketika kembali ke jam kantor default tanpa tertahan `end_date = today`. Jika assignment sudah berjalan sejak masa lalu (`start_date < today`), sistem tetap melakukan soft-end (`end_date = yesterday`) agar histori hari kemarin tetap utuh.

---

## 👔 Bagian 2 — Kekurangan dari Sudut Pandang HRD

| # | Kekurangan | Dampak Operasional |
|---|-----------|-------------------|
| 1 | ~~**Tidak ada koreksi presensi manual** — HRD tidak bisa edit/lengkapi absen yang salah (lupa checkout sudah ter-handle auto-checkout, tapi check-in hilang/salah lokasi tidak bisa diperbaiki HRD)~~ ✅ SELESAI 2026-10-06 — `POST /api/v1/dashboard/attendance/corrections` → `AttendanceController::correctAttendance()`, tombol **Koreksi** / **Input Manual** di tab Laporan. Field turunan selalu dihitung ulang dari snapshot, periode payroll approved/paid ditolak keras, alasan koreksi wajib + masuk activity log & CSV. Lihat rules.md bagian "Koreksi Presensi Manual HRD". | Data laporan permanen salah; sudah tercatat di roadmap rules.md P3 |
| 2 | **Tidak ada batas maksimal lembur** — UU 13/2003: lembur maks 4 jam/hari & 18 jam/minggu. Sistem menghitung lembur tanpa cap | Risiko kepatuhan ketenagakerjaan |
| 3 | ~~**Tidak ada notifikasi proaktif ke HRD saat pengaturan kantor diubah** — rekomendasi strategi proteksi #3 di rules.md sendiri belum diimplement~~ ✅ SELESAI 2026-08-26 — `AttendanceController::updateSettings()` kini melooping dan mengirim `notifyUser` ke semua HRD/Admin/SuperAdmin sesudah update. | Perubahan `work_end_time`/grace mendadak bisa merusak auto-checkout hari itu; audit log ada tapi pasif (tidak ada yang membaca) |
| 4 | ~~**Tarif/rate lembur belum ada** — approval lembur menghasilkan menit, tidak ada konversi nominal~~ ✅ SELESAI | Konversi lembur ke nominal tarif payroll sudah diimplementasikan |
| 5 | **Shift swap antar karyawan belum ada** — semua perubahan harus lewat HRD manual | Beban admin tinggi untuk shift 24 jam (sudah di roadmap P1) |
| 6 | **Delegasi approver** — kalau satu-satunya admin/super_admin berhalangan (cuti), approval lembur/device-change macet | Proses berhenti saat orang kuncinya tidak ada (roadmap P0 delegasi) |
| 7 | ~~**Laporan tidak bisa dikelompokkan per shift** — filter laporan hanya department/office/status/type~~ ✅ SELESAI | HRD kini bisa memfilter dan mengelompokkan laporan presensi per shift |

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
| 3 | Bug #3 & #4 (one-line fix) | Murah, cepat, menghilangkan perilaku tak terduga — #4 DITUNDA (risiko diterima, tertutup fitur notice period) |
| 4 | Bug #7–#8 | Masuk sprint berikutnya (#5 ✅ & #6 ✅ SELESAI 2026-08-25) |
| 5 | Kekurangan HRD #2 (batas lembur UU) & #6 (delegasi) | Kepatuhan & resiliensi proses |

---
*Catatan dibuat otomatis hasil review Claude — 2026-08-25. Status: Bug #2, #3, #5, #6, #7, & #9 SELESAI (2026-08-25/26); Bug #4 DITUNDA (risiko diterima, tertutup fitur notice period); sisanya (#1, #8) masih open/belum diperbaiki.*

---

## 🏢 Bagian 4 — Audit Mendalam Khusus Sistem Shift (`ShiftManagement.tsx` & `ShiftController.php`) (2026-09-04)

> Hasil audit mendalam terhadap arsitektur, kepatuhan regulasi K3 (UU Ketenagakerjaan No. 13/2003 & PP No. 35/2021), fungsionalitas operasional, dan pengalaman pengguna (UX) pada berkas:
> - **Backend Controller**: `app/Http/Controllers/API/ShiftController.php` (±2.820 baris)
> - **Frontend Component**: `src/components/ShiftManagement.tsx` (±2.950 baris)

---

### 🔴 Kategori A — Cacat Desain Data & Regulasi K3 / Ketenagakerjaan

#### 1. KRITIS: False-Positive Batas 40 Jam/Minggu akibat Ketiadaan Pengaturan Jam Istirahat (Break Time) — ✅ SELESAI (2026-09-04)
- **Lokasi Kode:** `ShiftController::validateWeeklyHours()`, `ShiftController::resolveSchedule()`, `AttendanceController::checkOut()`, `AutoCheckoutCommand`, `SettingsManagement.tsx`, & `ShiftManagement.tsx`
- **Akar Masalah:** Model `attendance_settings` dan `shift_schedules` sebelumnya hanya memiliki `work_start_time` dan `work_end_time` tanpa pengaturan `break_minutes`.
- **Dampak Kritis Sebelumnya:** Jadwal 08:00–17:00 (5 hari kerja) dihitung 45 jam kotor/minggu sehingga false-positive ditolak aturan 40 jam Depnaker, dan jam checkout menggelembung 1 jam/hari.
- **Implementasi Solusi (2026-09-04):**
  - Migrasi `2026_09_04_000001_add_break_minutes_to_attendance_settings_and_shift_schedules.php` menambahkan kolom `break_minutes` default 60 di kedua tabel.
  - Model `AttendanceSetting` dan `ShiftSchedule` diperbarui fillable & integer cast.
  - `ShiftController::validateWeeklyHours()` dan frontend `computeWeeklyHours()` & `calculatedWeeklyHours` mengurangkan `break_minutes` dari gross time, menghasilkan tepat **40.0 jam/minggu**.
  - `AttendanceController::checkOut()` dan `AutoCheckoutCommand::processAutoCheckout()` memotong `break_minutes` saat durasi kerja kotor $\ge 5$ jam (300 menit) sesuai UU 13/2003 Pasal 79.
  - Form UI Web di `SettingsManagement.tsx` dan `ShiftManagement.tsx` telah dilengkapi input durasi jam istirahat.

---

#### 2. TINGGI: Ketiadaan Validasi Batas Hari Kerja Berturut-turut (Max Consecutive Working Days)
- **Lokasi Kode:** `ShiftRestService.php`, `ShiftController::assignShift()`, & `bulkAssign()`
- **Akar Masalah:** `ShiftRestService` sudah memvalidasi jeda istirahat antar shift (min 8 jam, disarankan 11 jam), tetapi **TIDAK ADA validasi batas berapa hari karyawan boleh bekerja berturut-turut tanpa jeda libur mingguan**.
- **Dampak Hukum & K3:**
  - Sesuai Pasal 79 UU No. 13/2003 & PP 35/2021, pengusaha wajib memberikan istirahat mingguan sekurang-kurangnya 1 hari untuk 6 hari kerja atau 2 hari untuk 5 hari kerja.
  - Saat ini, HRD dapat menugaskan karyawan shift masuk 10–14 hari berturut-turut tanpa ada peringatan atau penolakan dari sistem (pelanggaran K3 dan ketenagakerjaan).
- **Rekomendasi Solusi:**
  - Tambahkan pemeriksaan pada `assignShift()` dan `bulkAssign()`: jika penugasan menyebabkan seorang karyawan bekerja $\ge 7$ hari berturut-turut tanpa ada hari `is_off`, sistem wajib menolak (atau minimal menampilkan peringatan K3 keras di layar).

---

#### 3. [SELESAI ✅ 2026-09-04] Proteksi K3 untuk Pekerja Rentan pada Shift Malam & Form Demografi Karyawan
- **Lokasi Kode:**
  - Migrasi DB: `2026_09_04_000002_add_demographic_and_k3_fields_to_users_table.php` (`gender`, `birth_place`, `birth_date`, `is_pregnant`).
  - Model: `User.php` (`age` accessor, `birth_date`, `is_pregnant`), `ShiftSchedule.php` (`isNightSchedule`), `Shift.php` (`hasNightSchedule`).
  - Backend Controller: `UserController.php` (CRUD & Bulk Import demographic data), `ShiftController.php` (`MIN_NIGHT_SHIFT_AGE = 17`, `checkNightShiftVulnerability()`, `assignShift()`, `bulkAssign()`, `updateAssignment()`, `roster()`).
  - Frontend Web: `KaryawanManagement.tsx` (Add & Edit Form, Detail Profil), `ImportEmployeeModal.tsx` (Mapping & Excel template), `ShiftManagement.tsx` (`AssignModal`, `BulkAssignModal`, badge `🌙 Shift Malam`).
  - Automated Tests: `tests/Feature/NightShiftK3ProtectionTest.php` (7 passed tests).
- **Dasar Hukum Regulasi:**
  - UU No. 13/2003 Pasal 76 ayat (1): Pengusaha dilarang mempekerjakan pekerja di bawah umur dan pekerja perempuan hamil pada shift malam (23:00–07:00).
  - UU No. 13/2003 Pasal 76 ayat (3) & (4): Pengusaha wajib menyediakan makanan/minuman bergizi serta fasilitas angkutan antar-jemput bagi pekerja perempuan yang bekerja shift malam.
  - Keputusan Bisnis Pengguna: Batas usia minimum shift malam ditetapkan **17 tahun**.
- **Implementasi Fitur:**
  1. **Field Demografi Baru di Tabel Users**:
     - `gender` ('Laki-laki' / 'Perempuan').
     - `birth_place` (string 100, tempat/kota lahir).
     - `birth_date` (date, tanggal lahir).
     - `is_pregnant` (boolean default false, flag status kehamilan untuk pekerja perempuan).
     - Accessor `$user->age`: Menghitung umur karyawan secara dinamis berdasarkan `birth_date`.
  2. **Validasi & Proteksi K3 Shift Malam**:
     - **Definisi Shift Malam:** Jadwal kerja shift yang beririsan dengan jendela 23:00–07:00 (misal shift lintas tengah malam `is_cross_day` atau start $< \text{07:00}$ atau end $> \text{23:00}$).
     - **Hard Block Usia:** Pekerja berusia $< 17$ tahun dilarang ditugaskan shift malam (HTTP 422). Pekerja $\ge 17$ tahun diizinkan bekerja.
     - **Hard Block Kehamilan:** Pekerja perempuan yang tercatat hamil dilarang ditugaskan shift malam (HTTP 422).
     - **K3 Advisory Warning:**
       - Jika usia tepat 17 tahun: Catatan pengawasan ekstra pekerja muda pada jam malam.
       - Jika tanggal lahir belum diisi di profil: Catatan K3 agar HRD memastikan usia $\ge 17$ tahun.
       - Jika perempuan (tidak hamil): Catatan kepatuhan penyediaan makanan bergizi & fasilitas antar-jemput aman.
     - **Perilaku Bulk Assign:** Pekerja rentan ($< 17$ tahun atau hamil) dilewati secara otomatis (`$dilewati[]` / `$skipped[]`) dengan alasan K3 tanpa membatalkan penugasan pekerja dewasa lainnya.
  3. **Antarmuka Pengguna (Web Frontend)**:
     - Manajemen Karyawan: Input jenis kelamin, tempat lahir, tanggal lahir (dengan badge live kalkulasi umur), dan toggle kehamilan otomatis tampil jika gender perempuan.
     - Impor Karyawan: Mendukung mapping kolom jenis kelamin, tempat lahir, dan tanggal lahir dari file Excel/CSV beserta template resmi yang dapat diunduh.
     - Manajemen Shift & Roster: Badge `🌙 Shift Malam` pada template shift, alert blokir penugasan instan di `AssignModal`, notifikasi proteksi otomatis di `BulkAssignModal`.

---

### 🟡 Kategori B — Kekurangan Fungsional & Kebutuhan Operasional Lapangan

#### 4. TINGGI: Ketiadaan Fitur Pertukaran Shift Antar Karyawan (Shift Swap / Tukar Shift)
- **Kondisi Saat Ini:** Dalam operasional riil (rumah sakit, pabrik, hotel, security 24/7), pergantian dinas darurat paling sering dilakukan dalam bentuk **Tukar Shift Antar Karyawan** (misal: Budi shift pagi tukar hari Kamis dengan Doni shift malam).
- **Kelemahan Sistem:**
  - Karyawan tidak bisa mengajukan tukar shift melalui aplikasi mobile.
  - HRD harus menghapus/mengubah penugasan Budi dan Doni secara manual satu per satu di dashboard web.
- **Kebutuhan Fitur:**
  - Model & tabel baru `shift_swap_requests` (requester_id, target_user_id, date, requester_shift_id, target_shift_id, status: pending/approved/rejected, approved_by).
  - Alur: Karyawan A request ke Karyawan B $\rightarrow$ Karyawan B setuju di mobile $\rightarrow$ Notifikasi ke HRD/Supervisor untuk approval $\rightarrow$ Jadwal swap otomatis aktif untuk tanggal tersebut.

---

#### 5. TINGGI: Ketiadaan Pola Rotasi Shift Berulang Otomatis (Shift Pattern / Recurring Rolling Cycle) — ✅ SELESAI (2026-09-04)
- **Kondisi Sebelumnya:** Template shift terikat secara statis pada 7 hari kalender (Minggu s/d Sabtu / `day_of_week` 0–6). HRD di sektor manufaktur/lapangan harus me-reassign shift karyawan setiap minggu untuk pola rotasi (4-2, 2-2-2, 3-1, dsb.).
- **Solusi Implementasi Lengkap (2026-09-04):**
  1. **Database & Relasi:**
     - Tabel `shift_patterns` (`company_id`, `attendance_setting_id` (nullable: null = company-wide, foreign key ke `attendance_settings`), `name`, `description`, `cycle_days` (2–30), `is_active`).
     - Tabel `shift_pattern_items` (`shift_pattern_id`, `day_order` (1–cycle_days), `shift_id`, `is_off`, `work_start_time`, `work_end_time`, `break_minutes`, `is_cross_day`).
     - Relasi `user_shifts` ditambah kolom `shift_pattern_id` (foreign key) dan `anchor_day_order` (default 1).
  2. **Engine Deterministik $O(1)$ (`resolveSchedule`):**
     - Jadwal ditentukan secara deterministik melalui selisih hari dari `start_date` penugasan:
       $$\text{dayIndex} = ((\text{anchor\_day\_order} - 1) + \text{diffDays}) \pmod{\text{cycle\_days}}$$
       $$\text{dayOrder} = \text{dayIndex} + 1$$
     - Menghilangkan kebutuhan cron job, tanpa bloat baris database ke depan, serta mendukung fase tim berbeda (Tim A mulai H1, Tim B mulai H5 pada pola yang sama).
  3. **Backend API Endpoints & Proteksi Lintas Cabang:**
     - CRUD: `GET/POST /api/v1/dashboard/attendance/shift-patterns`, `GET/PUT/DELETE /api/v1/dashboard/attendance/shift-patterns/{id}` (mendukung filter dan payload `attendance_setting_id`).
     - Penugasan: `assignShift()` dan `bulkAssign()` memvalidasi kecocokan cabang (`assertPatternBranchMatch`), menolak karyawan cabang A ditugaskan pola cabang B dengan error HTTP 422, serta otomatis melewati (skip) karyawan beda cabang pada penugasan massal.
     - Validasi proteksi penghapusan pola jika sedang aktif digunakan oleh karyawan.
  4. **Frontend UI Web (`ShiftManagement.tsx`):**
     - Tab baru **"Pola Rotasi (Cycle)"** dilengkapi filter cabang di toolbar atas, visual strip urutan hari kerja vs libur, preset populer (4-2, 3-1, 5-2, 6-1), toggle kerja/libur, dan jam custom/template per hari.
     - Modal pembuatan pola menyediakan dropdown pemilihan cabang spesifik atau berlaku company-wide.
     - Modal **Assign** & **Bulk Assign** otomatis memfilter pola rotasi yang relevan sesuai cabang karyawan yang dipilih dan menampilkan label nama cabang di dropdown.
     - Roster Harian menampilkan badge pola dan fase hari berjalan (contoh: `Pola 4-2 · H3`).
  5. **Automated Test Suite:**
     - Suite uji `ShiftPatternRotationTest.php` (6 skenario, 66 assertions) lulus 100% green bersama seluruh suite Shift (22 tests, 143 assertions) dan Attendance (26 tests, 93 assertions).

---

#### 6. SEDANG: Ketiadaan Dukungan Split Shift (Shift Terbagi / Double Duty)
- **Kondisi Saat Ini:** Sektor Food & Beverage (restoran, cafe, katering) banyak menerapkan **Split Shift** (contoh: Sesi 1 Makan Siang 10:00–14:00, jeda istirahat 3 jam, Sesi 2 Makan Malam 17:00–21:00).
- **Kelemahan Sistem:** `shift_schedules` hanya memiliki 1 pasang `work_start_time` dan `work_end_time` per hari. Sistem tidak mendukung 2 sesi check-in/out di hari yang sama.

---

#### 7. [SELESAI ✅] Atribut Premi / Tunjangan Shift (Shift Differential Allowance)
- **Kondisi Saat Ini:** Kolom kompensasi/tunjangan shift (`shift_allowance_amount`) telah diintegrasikan pada tabel dan konfigurasi shift untuk mendukung insentif shift malam maupun akhir pekan ke modul Payroll.

---

### 🔵 Kategori C — Kekurangan pada Antarmuka Frontend Web (`ShiftManagement.tsx`)

#### 8. TINGGI: Ketiadaan Fitur Impor Jadwal Roster Bulanan dari Excel / CSV (Matrix Roster Import)
- **Kondisi Saat Ini:**
  - HRD biasanya menyusun jadwal shift sebulan penuh untuk 50–200 karyawan di spreadsheet Excel (format matriks: Baris = Karyawan, Kolom = Tanggal 1 s/d 31, Sel = Kode Shift `PAGI`, `SIANG`, `MALAM`, `OFF`).
  - Di `ShiftManagement.tsx`, saat ini hanya ada fitur `bulkAssign` yang hanya bisa memberikan **1 shift yang sama untuk sekelompok karyawan pada rentang tanggal yang sama**.
- **Dampak Operasional:** Untuk menyusun jadwal karyawan yang berganti-ganti shift setiap harinya (roster dinamis), HRD harus mengklik dan menugaskan manual satu per satu. Untuk 50 karyawan $\times$ 30 hari = 1.500 penugasan, proses ini membutuhkan waktu berhari-hari.
- **Rekomendasi Solusi:** Buat modal **"Import Roster Bulanan (Matrix Excel)"** yang membaca berkas spreadsheet jadwal bulanan dan memprosesnya secara batch ke backend.

---

#### 9. TINGGI: Ketiadaan Fitur Ekspor Roster Bulanan ke Excel & Cetak Dokumen PDF
- **Kondisi Saat Ini:** Di tab Kalender maupun Roster, tidak ada tombol **Export Roster Excel** atau **Cetak PDF Jadwal Shift**.
- **Dampak Operasional:** Di operasional kantor cabang, pabrik, atau toko, jadwal shift bulanan **wajib dicetak fisik** untuk ditempel di mading/papan pengumuman kantor dan dibagikan ke supervisor divisi. Saat ini HRD tidak bisa mengekspor tampilan kalender roster tersebut.
- **Rekomendasi Solusi:** Tambahkan tombol ekspor format Excel (tabel matriks karyawan vs tanggal) dan tombol cetak PDF dengan tata letak lanskap siap cetak.

---

#### 10. [SELESAI ✅ 2026-09-04] Filter Departemen / Divisi di Tab Roster dan Kalender
- **Lokasi Kode:**
  - Backend: `ShiftController::roster()` & `ShiftController::calendar()`
  - Frontend Service: `endpoints.ts` (`shiftApi.roster(filters)` & `shiftApi.calendar(...)`)
  - Frontend UI: `ShiftManagement.tsx` (`rosterDepartment`, `calDepartment`, `departments` list)
  - Automated Tests: `tests/Feature/ShiftManagementCategoryCTest.php` (`test_roster_endpoint_supports_department_filter`, `test_calendar_endpoint_supports_department_filter`)
- **Implementasi Fitur:**
  1. Backend `ShiftController::roster()` dan `ShiftController::calendar()` menerima parameter `department` opsional, melakukan filter query pada `users.department`, dan mengembalikan daftar `departments` unik perusahaan/cabang untuk mengisi opsi dropdown.
  2. Frontend `endpoints.ts` memperluas interface `RosterFilter` dan fungsi `calendar` dengan argumen `department?: string`.
  3. Frontend `ShiftManagement.tsx` menambahkan dropdown **"Semua Departemen"** yang reaktif di bilah filter toolbar Tab Roster Harian dan Tab Kalender.

---

#### 11. [SELESAI ✅ 2026-09-04] Tampilan Kalender Menampilkan Jumlah & Daftar Karyawan Libur (OFF) per Hari
- **Lokasi Kode:**
  - Backend: `ShiftController::calendar()`
  - Frontend: `ShiftManagement.tsx` (`CalDayEntry`, sel kalender bulanan, legend, & modal detail hari)
  - Automated Tests: `tests/Feature/ShiftManagementCategoryCTest.php` (`test_calendar_endpoint_reports_off_employees`)
- **Implementasi Fitur:**
  1. Backend `ShiftController::calendar()` mengevaluasi setiap karyawan per tanggal:
     - Jika karyawan ditugaskan **Pola Rotasi (Cycle)** dan tanggal tersebut berada pada siklus `is_off`, sistem menandai alasan `Pola Rotasi {name} H{day} (Libur Siklus)`.
     - Jika karyawan ditugaskan **Template Shift**, mengevaluasi apakah hari tersebut merupakan hari `is_off` pada shift schedule.
     - Jika tidak memiliki penugasan shift custom (default kantor), mengevaluasi apakah hari tersebut adalah weekend/hari non-aktif kantor (`attendance_settings`).
  2. Karyawan yang libur digabungkan ke dalam entri khusus `shift_id: 0`, `shift_name: 'Libur (OFF)'`, `is_off: true`, dan `color: '#64748b'`.
  3. Sel Kalender menampilkan badge slate `Libur (OFF) (X org) (Off)` dan legenda `Libur / Off`.
  4. Ketika sel diklik, modal detail menampilkan daftar nama karyawan yang libur beserta alasan spesifiknya (`Pola Rotasi H4 (Libur Siklus)`, `Libur Shift`, atau `Libur Kantor (Weekend/Non-aktif)`).

---

#### 12. [SELESAI ✅ 2026-09-04] Quick Filter & Indikator Khusus "Karyawan Belum Terjadwal" (Unassigned Floating Staff)
- **Lokasi Kode:**
  - Frontend: `ShiftManagement.tsx` (`rosterStatusFilter`: `'ALL' | 'ASSIGNED' | 'UNASSIGNED'`, pill counter, badge & action button)
  - Backend: `ShiftController::roster()` (`source: 'office' | 'rotation' | 'user'`)
  - Automated Tests: `tests/Feature/ShiftManagementCategoryCTest.php` (`test_roster_assignment_source_integrity`)
- **Implementasi Fitur:**
  1. Tab Roster Harian menyediakan bilah filter status cepat (*quick counter pills*):
     - **Semua Karyawan (N)**
     - **Terjadwal Shift / Rotasi (N)** — Menampilkan karyawan dengan jadwal template atau rotasi aktif
     - **Belum Terjadwal (Default Kantor) (N)** — Menyaring staf floating yang belum memiliki shift custom
  2. Baris tabel karyawan yang belum terjadwal ditandai secara visual dengan ikon gedung `🏢 Jam Kantor (Default)` dan keterangan `Belum ada shift custom`.
  3. Disediakan tombol aksi langsung berwarna amber **`+ Assign Shift`** untuk mempermudah HRD langsung membuka modal penugasan jadwal bagi karyawan yang bersangkutan tanpa harus mencari satu per satu.

---

### 🟣 Kategori D — Masalah Backend Skalabilitas & Konfigurasi Fleksibel (`ShiftController.php`)

#### 13. TINGGI: Server-Side Pagination & Skalabilitas Memori pada Endpoint `ShiftController::roster()`
- **Lokasi Kode:** `ShiftController::roster()` (line ~1898)
- **Akar Masalah:**
  - Method `roster()` memuat **seluruh karyawan perusahaan/cabang sekaligus ke memori PHP** dalam satu request tanpa pagination di tingkat database.
  - Frontend (`ShiftManagement.tsx`) yang melakukan pemotongan halaman secara client-side (`rosterPageSize = 25`).
- **Dampak Skalabilitas:** Jika sebuah perusahaan memiliki 1.000+ karyawan di satu cabang, request `GET /api/v1/dashboard/attendance/shifts/roster` dapat memakan waktu lama (*slow query*), menghabiskan kuota memori PHP (*memory limit exhausted*), dan menyebabkan dashboard web freeze saat dibuka.
- **Rekomendasi Solusi:** Tambahkan parameter `page` dan `per_page` pada query SQL di `ShiftController::roster()`, mengembalikan struktur paginasi standar Laravel (`current_page`, `last_page`, `total`, `data`).

---

#### 14. [SELESAI ✅] Pengaturan Toleransi Keterlambatan di Level Shift
- **Kondisi Saat Ini:** Kustomisasi toleransi keterlambatan (`late_tolerance_minutes`) kini dapat dikonfigurasi langsung per template `Shift`, sehingga shift khusus (misal shift malam atau darurat) dapat memiliki toleransi yang berbeda dari aturan default kantor cabang.

---

#### 15. [TIDAK DIPERLUKAN / DIBATALKAN ❌] Laporan Kepatuhan Shift (Shift Adherence / Variance Report)
- **Keputusan:** Fitur ini ditiadakan karena karyawan yang melakukan presensi di luar jadwal shift aktifnya secara alami otomatis terhitung terlambat (*late*) atau tidak hadir (*absent / alpha*) mengacu pada jadwal penugasan resminya.

---

#### 16. [SELESAI ✅ 2026-09-09] Penegasan Pola Rotasi Khusus Per-Cabang (Penghapusan Opsi 'Semua Cabang')
- **Lokasi Kode:**
  - Backend Controller: `app/Http/Controllers/API/ShiftController.php` (`patternStore()`, `patternIndex()`).
  - Frontend Web: `expenseflow-web/src/components/ShiftManagement.tsx` (`ShiftPatternFormModal`, filter shift, validasi form, preselection kantor cabang pertama).
  - Automated Tests: 
    - `tests/Feature/ShiftPatternRotationTest.php` (22 test methods, 154 assertions green).
    - `tests/Feature/ShiftRotationWfhFieldNightTest.php` (6 test methods, 52 assertions green).
- **Latar Belakang & Alasan:**
  - Opsi *"Semua Cabang (Company-wide)"* pada Pola Rotasi berpotensi menimbulkan kerancuan dan bug operasional:
    1. Pola rotasi company-wide dapat memanggil template shift yang terikat pada cabang tertentu, membingungkan radius geofencing/koordinat presensi karyawan di cabang lain.
    2. Kalender libur mingguan, batas jam kerja, dan perhitungan jeda istirahat K3 antarshift antar-cabang bisa berbeda.
    3. Pencegahan human error admin/HRD saat meng-assign pola ke karyawan lintas cabang.
- **Implementasi Fitur:**
  1. **Backend Validation Guard:**
     - `patternStore()` mengubah aturan validasi `attendance_setting_id` dari `nullable` menjadi `required|integer|exists:attendance_settings,id`.
     - Pesan kesalahan validasi bahasa Indonesia yang jelas: *"Cabang / lokasi kantor wajib dipilih untuk pola rotasi shift."*.
     - `patternIndex()` menerapkan filter ketat berdasarkan parameter `attendance_setting_id`.
  2. **Antarmuka Pengguna Frontend Web (`ShiftManagement.tsx`):**
     - Opsi `<option value="">— Semua Cabang (Company-wide) —</option>` dihapus dari dropdown modal pembuatan pola rotasi.
     - Form otomatis memilih cabang pertama yang tersedia sebagai default (`offices[0]?.id`).
     - Field ditandai wajib dengan asterisk merah `*` dan atribut HTML `required`.
     - Validasi sisi klien memastikan `attendance_setting_id` terisi sebelum payload dikirimkan.
     - Dropdown pilihan shift di dalam item siklus hanya menampilkan shift milik cabang yang dipilih atau shift global.

---

## 🚀 Bagian 5 — Evaluasi Celah Fungsional & Kebutuhan Lanjutan Sistem Presensi Modern (2026-09-16)

> Catatan evaluasi komprehensif di luar daftar bug awal dan di luar fitur yang sengaja di-keep untuk modul Payroll (seperti tarif lembur nominal, premi malam, dan pemotongan gaji).
> Merangkum kebutuhan nyata di operasional lapangan, kepatuhan regulasi ketenagakerjaan Indonesia (UU 13/2003 & PP 35/2021), integritas anti-fraud, dan pengalaman pengguna mobile/web.

---

### 🛡️ Kategori E — Keamanan Perangkat & Integritas Presensi (Anti-Fraud & Verification)

#### 1. TINGGI: Ketiadaan Kewajiban Foto Selfie Aktual Saat Check-in & Check-out (Photo Proof with Watermark)
- **Kondisi Saat Ini:**
  - Skema database tabel `attendances` sudah memiliki kolom `check_in_photo` (nullable), namun controller backend (`AttendanceController::checkIn`) maupun aplikasi Flutter mobile tidak pernah mewajibkan atau mengunggah foto selfie saat presensi.
- **Celah Operasional (Fraud Titip HP):**
  - Meskipun sistem telah dilengkapi *Device Binding* dan validasi radius GPS kantor (*geofencing*), karyawan tetap dapat melakukan kecurangan dengan **menitipkan HP-nya ke rekan kerja yang berada di kantor**. Rekan kerja tersebut dapat menekan tombol *Check-in* di HP milik rekannya tanpa terdeteksi karena koordinat dan device ID sah.
- **Rekomendasi Solusi:**
  - Wajibkan jepret foto langsung dari kamera depan perangkat (larang pemilihan berkas dari galeri).
  - Bubuhkan *watermark metadata* (tanggal & jam WIB, koordinat lat/lng, dan status kehadiran) pada berkas foto.
  - Simpan path foto ke kolom `check_in_photo` dan sediakan thumbnail foto pada tabel riwayat presensi di dashboard web HRD.

#### 2. [SELESAI ✅ 2026-09-16] Deteksi Perangkat Di-Root / Jailbreak & Emulator PC
- **Lokasi Kode:**
  - Mobile Service: `expenseflow-mobile/lib/services/device_integrity_service.dart` (`DeviceIntegrityService`, `DeviceIntegrityResult`).
  - Mobile Entrypoint: `expenseflow-mobile/lib/main.dart` (pre-cache `DeviceIntegrityService.initialize()` saat startup).
  - Mobile UI/Screen: `expenseflow-mobile/lib/screens/presensi_map_screen.dart` (banner peringatan, dialog panduan unroot/emulator, disable tombol presensi).
  - Mobile Provider: `expenseflow-mobile/lib/presensi_provider.dart` (`simpanPresensi(..., isRooted, isEmulator)`).
  - Mobile API Service: `expenseflow-mobile/lib/services/api_service.dart` (`checkIn()`, `checkOut()`).
  - Backend Controller: `expenseflow-backend/app/Http/Controllers/API/AttendanceController.php` (`checkIn()`, `checkOut()`).
  - Automated Tests: `expenseflow-backend/tests/Feature/DeviceIntegrityAttendanceTest.php` (5 test methods, 20 assertions green).
- **Latar Belakang & Celah Operasional:**
  - Sebelumnya sistem hanya mendeteksi `is_mocked` (Mock Location API). Namun pengguna tingkat lanjut pada HP di-root (Magisk/Zygisk/LSPosed) dapat menyembunyikan status mock location dari OS.
  - Karyawan juga dapat memasang aplikasi di **Emulator PC** (NoxPlayer, BlueStacks, LDPlayer) yang diletakkan di kantor untuk melakukan auto-presensi tanpa hadir fisik.
- **Implementasi Fitur & Proteksi Berlapis (Defense in Depth):**
  1. **Mobile Device Integrity Service (`device_integrity_service.dart`):**
     - Memanfaatkan package `safe_device` untuk inspeksi biner root (su binary, Superuser.apk, Magisk path) dan status hardware fisik (`SafeDevice.isRealDevice`).
     - Hasil dicache selama siklus hidup aplikasi sehingga tidak memberatkan performa UI saat layar peta presensi dibuka.
     - Dipanggil secara otomatis pada `main.dart` (pre-cache background saat cold start).
  2. **Antarmuka Pengguna Mobile (`presensi_map_screen.dart`):**
     - Banner interaktif oranye langsung muncul jika perangkat terdeteksi di-root atau berjalan di emulator.
     - Tombol "Check In" / "Check Out" otomatis **dinonaktifkan (disabled)** jika integritas perangkat bermasalah.
     - Dialog interaktif `_showDeviceIntegrityDialog()` menyediakan instruksi solutif langkah demi langkah bagi karyawan (unroot, menghapus Magisk/SuperSU, dan beralih ke HP fisik asli).
  3. **Backend Validation Guard (`AttendanceController.php`):**
     - Validasi ganda di sisi server pada endpoint `/api/v1/attendance/check-in` dan `/api/v1/attendance/check-out`.
     - Request yang membawa flag `is_rooted: true` langsung ditolak dengan **HTTP 403 Forbidden** dan payload `rooted_device_detected: true`.
     - Request yang membawa flag `is_emulator: true` langsung ditolak dengan **HTTP 403 Forbidden** dan payload `emulator_detected: true`.
  4. **Pengujian Otomatis (Automated Tests):**
     - `test_check_in_blocked_when_device_is_rooted` (HTTP 403, tidak ada row attendances dibuat).
     - `test_check_in_blocked_when_device_is_emulator` (HTTP 403, tidak ada row attendances dibuat).
     - `test_check_out_blocked_when_device_is_rooted` (HTTP 403, check_out_time tetap null).
     - `test_check_out_blocked_when_device_is_emulator` (HTTP 403, check_out_time tetap null).
     - `test_check_in_and_check_out_success_when_device_is_clean` (HTTP 201 dan 200 normal).
     - `flutter analyze` 5 files: 0 issues found.

---

### 🌐 Kategori F — Karyawan Lapangan, Roaming & Kunjungan Klien (Field Operations & Multi-Site)

#### 3. [SELESAI ✅ 2026-09-16] Mode Presensi Kunjungan Klien / Proyek Luar (Client Visit / Task Check-in) & Multi-Geofence Roaming
- **Lokasi Kode:**
  - Database Migrations:
    - `expenseflow-backend/database/migrations/2026_09_16_021500_add_dinas_luar_enabled_to_users_table.php` (tambah kolom boolean `dinas_luar_enabled` default false).
    - `expenseflow-backend/database/migrations/2026_09_16_021600_add_client_visit_fields_to_attendances_table.php` (tambah `client_name`, `client_address`, `visit_notes` pada tabel `attendances`).
  - Backend Models & Services:
    - `expenseflow-backend/app/Models/User.php` (`dinas_luar_enabled`, helper method `canDinasLuar(): bool`).
    - `expenseflow-backend/app/Models/Attendance.php` (fillable `client_name`, `client_address`, `visit_notes`).
    - `expenseflow-backend/app/Services/LocationService.php` (`reverseGeocode($lat, $lng)` dengan fallback OpenStreetMap Nominatim dan timeout resilien).
    - `expenseflow-backend/app/Http/Controllers/API/AttendanceController.php`:
      - `toggleDinasLuar(Request $request, $id)`: Endpoint HRD / Company Admin untuk mengaktifkan/menonaktifkan izin dinas luar per karyawan.
      - `checkIn(AttendanceCheckInRequest $request)`: Validasi izin dinas luar, input wajib `client_name`, catatan kunjungan opsional, reverse geocode alamat otomatis, tanpa mewajibkan foto selfie (fitur selfie presensi di-keep untuk roadmap Kategori E).
      - `checkStatus()`: Menyuplai seluruh daftar cabang kantor aktif (`offices`) dan status `dinas_luar_enabled`.
  - Backend Routes:
    - `expenseflow-backend/routes/api.php` (`POST /v1/attendance/users/{id}/toggle-dinas-luar`).
  - Web Dashboard (React / TypeScript):
    - `expenseflow-web/src/services/endpoints.ts` (`toggleDinasLuar`, `attendancePhotoUrl`).
    - `expenseflow-web/src/components/AttendanceManagement.tsx`:
      - Toggle switch interaktif "Izin Dinas Luar" pada tabel daftar karyawan (Tab Karyawan) dengan feedback toast instan.
      - Badge khusus "Dinas Luar" berikon tas kantor ungu lengkap dengan nama klien pada tabel kehadiran hari ini (Tab Hari Ini).
      - Filter dropdown "Dinas Luar" dan metric card ringkasan di tab Laporan Kehadiran.
      - Modal detail kunjungan dinas luar (`visitDetailModal`) lengkap dengan nama klien, catatan tugas, alamat lengkap reverse-geocoded, koordinat GPS, serta tombol pintas langsung ke Google Maps.
  - Mobile App (Flutter / Dart):
    - `expenseflow-mobile/lib/models/attendance_model.dart` (`clientName`, `clientAddress`, `visitNotes`, `isDinasLuar`).
    - `expenseflow-mobile/lib/services/api_service.dart` (`checkIn` mendukung parameter client visit `client_name`, `client_address`, `visit_notes`).
    - `expenseflow-mobile/lib/presensi_provider.dart` (`dinasLuarEnabled`, `canDinasLuar`, sinkronisasi status kantor & dinas luar).
    - `expenseflow-mobile/lib/screens/presensi_map_screen.dart`:
      - Mode selector dinamis: Tombol tab pill "Kantor", "WFH" (jika diizinkan), dan "Dinas Luar" (jika diaktifkan HRD).
      - Form Kunjungan Klien yang praktis: Field nama klien/tempat kunjungan (wajib) dan catatan agenda kunjungan (opsional), tanpa mewajibkan kamera selfie (presensi selfie di-keep untuk nanti).
      - Multi-geofence Roaming: Peta memvisualisasikan seluruh cabang kantor perusahaan, otomatis menghitung jarak ke kantor terdekat, dan mengizinkan presensi kantor di cabang mana pun yang terdekat tanpa false-positive geofence violation.
    - `expenseflow-mobile/lib/screens/presensi_history_screen.dart`:
      - Badge ungu "Dinas: [Nama Klien]" pada kartu riwayat kehadiran karyawan.
  - Automated Tests:
    - `expenseflow-backend/tests/Feature/FieldVisitDinasLuarAttendanceTest.php`:
      - `test_user_without_dinas_luar_permission_is_rejected` (HTTP 403 Forbidden).
      - `test_dinas_luar_check_in_requires_client_name_and_allows_without_photo` (Wajib nama klien, presensi berhasil tanpa foto).
      - `test_dinas_luar_check_in_succeeds_and_saves_client_visit_details` (Menyimpan nama klien, alamat, catatan kunjungan).
      - `test_roaming_employee_can_check_in_at_any_active_company_office` (Multi-geofence roaming validasi lolos di cabang terdekat).
      - `test_roaming_employee_is_rejected_if_outside_all_offices` (Multi-geofence di luar seluruh cabang ditolak HTTP 422).
      - `test_hrd_can_toggle_dinas_luar_permission` (HTTP 200 toggle izin on/off).
      - Hasil: **7 passed, 40 assertions green**.
      - Total Suite Attendance: **54 passed, 281 assertions green**.
    - Mobile Analyzer: `flutter analyze` -> **0 issues found**.
    - Web Build: `npm run build` -> **Built successfully 0 errors**.

---

### ⚖️ Kategori G — Kepatuhan Regulasi Izin Khusus Indonesia (Statutory Special Paid Leave)

#### 5. [SELESAI ✅] Kategori Izin Khusus Berbayar Resmi UU No. 13/2003 (Tidak Memotong Cuti)
- **Implementasi:** Kategori izin khusus berbayar sesuai ketentuan perundangan (menikah, melahirkan, duka keluarga, haid, dll.) telah diintegrasikan sehingga tidak lagi memotong kuota saldo cuti tahunan reguler karyawan.

---

#### 6. [SELESAI ✅] Validasi Wajib Berkas Surat Keterangan Dokter (SKD) untuk Izin Sakit
- **Implementasi:** Validasi unggah berkas bukti diagnosis/Surat Keterangan Dokter (SKD) telah diaktifkan dan diverifikasi pada pengajuan izin sakit agar tidak disalahgunakan tanpa bukti medis.

---

### 📅 Kategori H — Manajemen Siklus Cuti & Kebijakan Masa Kerja (Leave Lifecycle & Accrual)

#### 7. SEDANG: Ketiadaan Sistem Akrual & Prorate Cuti Otomatis Berdasarkan Masa Kerja
- **Kondisi Saat Ini:**
  - Kuota saldo cuti harus di-set manual oleh HRD (`setLeaveBalance`) atau reset tahunan sekaligus 12 hari.
- **Kebutuhan Standar HR:**
  - **Masa Tunggu (Probation):** Karyawan baru yang masa kerjanya belum melewati masa percobaan (misal 3 bulan) idealnya belum diizinkan mengambil cuti tahunan.
  - **Akrual Bulanan (Prorate):** Bagi karyawan di tahun pertama kerja, hak cuti dihitung proporsional (bertambah 1 hari per bulan masa kerja), bukan langsung diberikan 12 hari di muka.
- **Rekomendasi Solusi:**
  - Tambahkan konfigurasi kebijakan cuti di kantor: tipe akrual (tahunan di muka vs bulanan prorate) serta masa tunggu minimum hak cuti.

#### 8. SEDANG: Ketiadaan Kebijakan Masa Kedaluwarsa Saldo Cuti (Carry-Forward Expiry) + Pencairan Sisa Cuti ke Uang — ⏳ BACKEND SELESAI 2026-10-13, **MODEL DIBALIK 2026-10-14**

> **BACA INI LEBIH DULU.** Pada 2026-10-14 arah aliran uang fitur ini **DIBALIK** atas permintaan user, dan pembalikannya sudah selesai + teruji (suite **1031/1031**). Ringkasnya: hari **di atas** batas carry-over **hangus permanen** (tidak pernah jadi uang), dan hari **bawaan** adalah satu-satunya sumber hari yang bisa dicairkan — dengan `leave_carry_over_expiry_months` sebagai saklar **kapan** uangnya terbit (`0` = di tanggal reset, `N > 0` = di tanggal kedaluwarsa atas sisa yang belum terpakai, `NULL` = tidak pernah kedaluwarsa & tidak boleh dipasangkan dengan pencairan). Model lama (uang dari kelebihan batas) **sudah tidak berlaku**; poin-poin di bawah yang masih menyebutnya ditandai *[DIREVISI 2026-10-14]*. Matriks lengkap & alasannya ada di `doc/rules.md` §"Carry-Over & Pencairan Sisa Cuti Tahunan ke Uang".
- **Kondisi Saat Ini (sebelum fitur):**
  - Perintah reset cuti tahunan menghapus/me-reset kuota tanpa opsi batas bawa sisa cuti (*carry-over*).
- **Dampak Operasional:**
  - Kebijakan perusahaan di Indonesia umumnya mengizinkan sisa cuti tahun lalu dibawa maksimal N hari (misal 6 hari) dan wajib dihabiskan paling lambat tanggal 31 Maret di tahun berjalan sebelum hangus otomatis.
- **Lokasi Kode:**
  - **Baru:** `app/Services/Attendance/LeaveCarryOverCalculator.php` (kalkulator MURNI, tanpa DB & tanpa jam sistem); `app/Services/Attendance/LeavePeriodCloser.php` (pelaksana penutupan periode: menerbitkan uang & merakit kolom); migrasi `2026_10_13_000001_add_leave_carryover_encashment_policy_to_attendance_settings_table.php`, `2026_10_13_000002_add_carry_over_to_leave_balances_table.php`, `2026_10_13_000003_add_carryover_encashment_snapshot_to_leave_balance_histories_table.php`; test `tests/Unit/LeaveCarryOverCalculatorTest.php`, `tests/Feature/LeaveCarryOverEncashmentTest.php`, `tests/Feature/LeaveEncashmentPayrollTest.php`.
  - **Diubah:** `app/Models/AttendanceSetting.php` (6 kolom kebijakan fillable + cast), `app/Models/LeaveBalance.php` (`carried_over_quota`, `carry_over_expired_at`), `app/Models/LeaveBalanceHistory.php` (8 kolom snapshot), `app/Models/PayrollAdjustment.php` (konstanta `NAME_LEAVE_ENCASHMENT` + helper `isLeaveEncashment()`), `app/Models/PayslipCalculationStep.php` (`STEP_LEAVE_ENCASHMENT`); `app/Console/Commands/ResetLeaveBalancesCommand.php` (routing ke `LeavePeriodCloser` + pengguguran kedaluwarsa tiap jam); `app/Http/Controllers/API/AttendanceController.php` (validasi & **7** guard kebijakan di `updateSettings()`, gerbang konfirmasi `SIMPAN`, `resetOfficeLeaveBalances()`, `splitLeaveAroundReset()`, `listLeaveBalances()`, `listLeaveBalanceHistories()`, `leavePolicyLabel()`, `historyOutcomeLabel()`, `myLeaveBalance()`); `app/Services/Payroll/PayrollCalculator.php` (source `leave_encashment` + langkah jejak terpisah); `app/Services/Payroll/Gl/PayrollGlComposer.php` (`earningBucket()` arm baru); `config/payroll_gl.php` (pos `expense_leave_encashment`).
- **Latar Belakang / Permintaan:** User: *"tolong buatkan fitur carry over dan berapa carry over yang bisa di bawa di next seson tapi kasih chek box terlebih dahulu kantor tersebut ingin memakai fitur carry over atau tidak kalo tidak berarti tidak ada regulasi cuti yang di bawa untuk next seson, lalu buatkan juga fitur cuti tahunan yang bisa di cairkan di akhir next seson, tapi gunakan chek box juga untuk hrd ingin atau tidak untuk karyawan mengconvert sisa cuti nya menjadi uang — jika hrd tidak menchentang artinya semua sisa cuti karyawan hangus; jika menchentang berarti ada kolom input berapa nominal per hari untuk di convert ke uang dan langsung sambungkan ke payroll dan uang ini bisa cair di bulan gajian tanggal reset payroll. Lalu jika hrd menchentang ke-2 nya maka cuti yang tidak di bawa ke next seson lah yang bisa di cairkan menjadi uang. Tolong buatkan guard atau validasi yang cocok untuk fitur ini dan jika bisa atur snapshot nya yang cocok seperti apa menurutmu."*
- **Keputusan produk (sesuai permintaan user):**
  1. **Dua checkbox independen per kantor** (`attendance_settings`, bukan global): carry-over dan pencairan. Keduanya OFF = perilaku lama **persis** (sisa cuti hangus) sehingga nol regresi untuk kantor yang sudah ada.
  2. **[DIREVISI 2026-10-14 — arah aliran uang DIBALIK] Uang BERSUMBER dari hari BAWAAN, bukan dari kelebihannya.** Versi pertama (2026-10-13) menerjemahkan *"cuti yang tidak di bawa ke next seson lah yang bisa di cairkan"* secara harfiah: carry-over dulu, lalu kelebihannya dicairkan. User menolaknya dan memberi casenya sendiri: *"abet mempunyai cuti tahunan 12 hari ... terpakai 2 ... sisah 10 ... carry over ... hanya bisa carry 7 hari jadi otomatis saya mau di sistem 3 hari itu hangus dan 7 hari ini HRD bisa mengasih pilihan: jika masa berlakunya HRD input 0 ... cuti 7 hari ini langsung bisa di cairkan; namun jika HRD menginput ... 3 bulan maka cuti 7 hari ini hanya bisa cair 3 bulan setelah reset; dan jika fitur pencairan tidak di aktifkan maka cuti 7 hari setelah 3 bulan tersebut benar benar hangus."* Jadi: **hari di ATAS batas carry-over hangus permanen dan tidak pernah menjadi uang** (kantor sudah memutuskan tidak mengakuinya — membayarnya berarti membayar justru yang ia tolak bawa), sementara **hari bawaan** adalah satu-satunya sumber hari yang bisa dicairkan, dan **`leave_carry_over_expiry_months` yang menentukan KAPAN** uangnya terbit: `0` = di tanggal reset, `N > 0` = di tanggal kedaluwarsa atas hari yang belum terpakai, dan bila pencairan OFF hari bawaan itu benar-benar hangus.
  3. **Invarian tunggal yang mengikat seluruh fitur:** `remaining = carried_over_days + encashed_days + forfeited_days`. Sisa cuti tidak boleh menguap dan tidak boleh berganda. Diperiksa pada **setiap** pemanggilan `decide()` di test unit, bukan di satu tes khusus.
  4. **`carried_over_quota` adalah BAGIAN DARI `quota`, bukan tambahan di sampingnya** — `quota` periode baru = `default_leave_quota + carried`. Dengan begitu rumus `remaining = quota - used` tetap satu-satunya rumus sisa cuti di seluruh aplikasi; tiga titik clamp yang membandingkan dengan `default_leave_quota` diperluas menjadi `default_leave_quota + carried_over_quota`. Alternatifnya (kolom terpisah yang dijumlah di setiap pembaca) akan menuntut perubahan di setiap tempat yang pernah menghitung sisa cuti.
  5. **Pembayaran lewat `PayrollAdjustment` (`payroll_id` NULL + `approved` = "menunggu batch"), bukan komponen baru di `PayrollCalculator`** — batch payroll berikutnya mengklaimnya otomatis dan membayarnya pada tanggal gajian grup karyawan, persis *"cair di bulan gajian tanggal reset payroll"*. Idempotensi payroll yang sudah ada langsung berlaku: recalculate melepas lalu mengklaim ulang, hapus batch melepasnya kembali ke `approved`. Bila batch bulan ini sudah dihitung/disetujui, penyesuaiannya menggantung dan ikut batch berikutnya — tidak hilang, tidak memaksa membuka batch terkunci.
  6. **Langsung `approved`, tidak `pending`** — persetujuannya adalah tindakan HRD mencentang kebijakan + mengisi tarifnya (keduanya dijaga gerbang konfirmasi `SIMPAN`). Pada reset otomatis tidak ada manusia yang bisa menyetujuinya.
  7. **`is_taxable = true`** — pencairan cuti adalah penghasilan atas pekerjaan, bukan penggantian biaya, sehingga masuk bruto PPh 21.
  8. **`quota = 0` tetap berarti NON-AKTIF** (kebijakan 2026-08-25): reset & carry-over tidak boleh mengaktifkan saldo karyawan yang sengaja dinolkan.
- **Implementasi:**
  1. **Migrasi `attendance_settings`** — 6 kolom: `leave_carry_over_enabled` (bool, default false), `leave_carry_over_max_days` (nullable), `leave_carry_over_expiry_months` (nullable, **0-24**), `leave_encashment_enabled` (bool, default false), `leave_encashment_rate_per_day` (`decimal(12,2)` nullable), `leave_encashment_max_days` (nullable). **`NULL` vs `0` dibedakan secara sadar di DUA kolom:** pada `leave_encashment_max_days` `NULL` = tanpa pagu & `0` = tidak ada hari yang boleh dicairkan; pada `leave_carry_over_expiry_months` (setelah pembalikan 2026-10-14) `NULL` = hari bawaan tidak pernah kedaluwarsa, **`0` = takdir hari bawaan diputuskan di tanggal reset itu juga** (dicairkan bila pencairan ON, hangus bila OFF), `N > 0` = hari bawaan hidup N bulan lalu dicairkan/hangus di tanggal kedaluwarsanya. Karena itu validasinya `min:0`, **bukan** `min:1`.
  2. **Migrasi `leave_balances`** — `carried_over_quota` (default 0) & `carry_over_expired_at` (date nullable). Kolom bawaan dipisah dari `quota` **hanya untuk pelaporan & pengguguran**; angkanya sudah termasuk di `quota` (lihat keputusan #4, ditulis di docblock migrasi agar tidak "diperbaiki" menjadi penjumlahan ganda).
  3. **Migrasi `leave_balance_histories` (snapshot)** — `carried_over_days`, `carry_over_expired_at`, `encashed_days`, `encashment_rate_per_day`, `encashment_amount`, `encashment_adjustment_id` (FK ke `payroll_adjustments`), `forfeited_days`, `policy_snapshot` (JSON). **Snapshot adalah inti desainnya:** kebijakan kantor bisa berubah kapan saja, tetapi riwayat harus tetap bisa menjawab *"mengapa cuti saya hangus/dibawa/dicairkan sebesar itu pada Januari lalu"* — karena itu tarif, pagu, dan status kedua checkbox **dibekukan apa adanya** di baris riwayat, bukan dibaca ulang dari kantor saat laporan dibuka.
  4. **`LeaveCarryOverCalculator` (murni)** — satu-satunya tempat matriks kebijakan diterjemahkan: `configFromOffice()` (kantor NULL → kedua checkbox mati, perilaku lama dipertahankan), `decide()`, `newPeriodQuota()`, `isCarryOverExpired()`, dan **`MAX_POLICY_DAYS` sebagai pagu keras kelas** — kuota rusak 365 hari tidak boleh menjadi tagihan pencairan 365 hari sekaligus. Kedaluwarsa dihitung dengan `addMonthsNoOverflow()` (31 Jan + 1 bulan = 28/29 Feb, bukan 2/3 Mar), dan **tidak ada tanggal kedaluwarsa bila tidak ada hari bawaan** — tanggal tanpa saldo yang diawasinya akan membuat baris saldo terus terpilih query pengguguran tiap jam. Tarif Rp0 membuat harinya **hangus**, bukan "dicairkan Rp0" (baris slip nol rupiah hanya membingungkan). **[DIREVISI 2026-10-14] Urutan keputusan `decide()`:** (1) `carried = min(remaining, batas_bawa, MAX_POLICY_DAYS)`; (2) **bila `masa_berlaku === 0`** takdir hari bawaan selesai hari ini juga — `encashed = capEncashable(carried, …)` bila pencairan ON & tarif > 0, lalu **`carried = 0`**; (3) `forfeited = remaining − carried − encashed` (di sinilah kelebihan batas hangus); (4) `carry_over_expired_at` hanya diisi bila `carried > 0` **dan** `masa_berlaku > 0`. Langkah `carried = 0` di (2) itu wajib untuk **kedua** sub-kasusnya: tanpa itu kantor membayar hari yang karyawan masih pegang, dan hari bawaan tanpa tanggal kedaluwarsa akan menjadi **abadi** (tidak ada peristiwa yang bisa menggugurkannya) — sehingga "tanpa masa berlaku" malah berarti "berlaku selamanya". Helper **`capEncashable()`** dibuat `public static` agar `LeavePeriodCloser::expireCarryOver()` memakai pagu yang **sama persis**.
  5. **`LeavePeriodCloser` (penyentuh DB)** — pemisahan dari kalkulator disengaja: kalkulator tetap bisa diuji tanpa DB, dan kelas ini memegang satu-satunya hal yang tidak bisa diputuskan secara murni, yaitu **penjagaan anti-pencairan-ganda** berkunci alami (karyawan, tanggal reset). `expireCarryOver()` dijalankan tiap jam untuk **seluruh** kantor (bukan hanya yang sedang anniversary) karena tanggal kedaluwarsa jatuh di tengah periode — praktik umumnya 31 Maret untuk kantor yang reset 1 Januari — sehingga tidak ada reset yang bisa menanganinya; pengurangan quota dibatasi `min(carried, quota - used)` agar hari bawaan yang **sudah dipakai** tidak ditarik kembali. **[DIREVISI 2026-10-14]** `expireCarryOver()` kini menjadi **tempat utama uang diterbitkan** (bukan lagi hanya penggugur): ia mengembalikan `array{processed, encashed_days, encashed_amount, forfeited_days}` alih-alih `int`, menerbitkan `PayrollAdjustment` atas hari bawaan yang tak terpakai bila pencairan ON, dan **menulis baris `leave_balance_histories` TERSENDIRI** bertanda `policy_snapshot['event'] = LeavePeriodCloser::EVENT_CARRY_OVER_EXPIRY` — pilihan user atas pertanyaan jejak audit: peristiwa ini terjadi berbulan-bulan setelah reset, jadi ia harus punya barisnya sendiri di tab Riwayat Saldo Cuti, bukan hanya muncul sebagai angka di slip gaji. Pagu `encashment_max_days` dihitung lewat helper bersama `LeaveCarryOverCalculator::capEncashable()` agar pagu di jalur reset (`masa berlaku 0`) dan di jalur kedaluwarsa tidak bisa menyimpang. **Jalur kedaluwarsa tidak memerlukan penjagaan anti-pencairan-ganda** seperti `close()`: ia menihilkan `carried_over_quota` sehingga sapuan jam berikutnya tidak lagi memilih baris itu.
  6. **Dua jalur reset wajib lewat satu pelaksana** — `ResetLeaveBalancesCommand` (otomatis, anniversary, tiap jam) dan `AttendanceController::resetOfficeLeaveBalances()` (manual HRD) masing-masing menulis riwayatnya sendiri (label periode & catatannya berbeda), tetapi **ANGKA dan PENERBITAN UANG hanya dihasilkan `LeavePeriodCloser`**. Tanpa aturan ini kedua jalur pasti menyimpang dan karyawan yang di-reset manual mendapat perhitungan berbeda dari yang di-reset otomatis.
  7. **7 guard 422 berbahasa Indonesia di `validateLeavePolicyAmounts()`** (dipanggil `updateSettings()` dengan nilai **efektif** = payload ditimpa ke nilai tersimpan, sehingga form lama yang tidak mengirim kolom baru tidak bisa menembus guard): (a) carry-over ON tanpa batas hari > 0; (b) **pencairan ON tanpa tarif > 0** — guard terpenting, tanpanya HRD yakin sudah membuka pencairan padahal sisa cuti karyawan tetap hangus; (b2) **[BARU 2026-10-14] pencairan ON tetapi carry-over OFF → 422** pada `leave_carry_over_enabled`: setelah pembalikan, hari bawaan adalah **satu-satunya** sumber hari yang bisa dicairkan, jadi kombinasi ini adalah kebijakan pencairan yang mustahil menghasilkan uang. User memilih **menolak** konfigurasinya daripada membiarkannya tersimpan diam-diam, sehingga ada tepat SATU jalur hari-ke-uang di seluruh fitur; (b3) **[BARU 2026-10-14] pencairan ON tetapi masa berlaku kosong (NULL) → 422** pada `leave_carry_over_expiry_months` — tanpa tanggal kedaluwarsa tidak ada peristiwa yang bisa mencairkannya, uangnya tak akan pernah terbit; pesannya menyebut *"Isi 0 bulan agar langsung dicairkan pada tanggal reset"* agar HRD tahu jalan keluarnya; (c) tarif/batas pencairan terisi padahal checkbox OFF (dan kebalikannya untuk carry-over) — nominal rupiah tersimpan tanpa kebijakan yang mengaktifkannya adalah tampilan menyesatkan di form kantor; (d) batas melebihi `default_leave_quota` — sisa cuti tidak mungkin melebihi kuotanya, jadi batas di atas kuota menandakan HRD salah membaca satuan field; (e) masa kedaluwarsa terisi tanpa carry-over.
     > **Guard yang DICABUT 2026-10-14:** *"kedua checkbox ON tetapi batas carry-over ≥ kuota"*. Guard itu lahir dari model lama di mana uang berasal dari **kelebihan** batas, sehingga `batas ≥ kuota` memang mematikan pencairan tanpa tanda. Setelah pembalikan, `batas = kuota` justru konfigurasi yang **paling wajar**: seluruh sisa cuti dibawa, lalu seluruhnya dicairkan di tanggal reset/kedaluwarsa. Mempertahankan guard itu akan melarang kebijakan yang paling umum diminta HRD.
  8. **Gerbang konfirmasi `SIMPAN`** — keenam kolom masuk daftar perubahan berbahaya, dengan dua perbandingan khusus: `leave_encashment_rate_per_day` dibandingkan sebagai float (selisih sen tidak boleh lolos pembulatan integer), dan ketiga kolom batas hari dibandingkan sebagai int karena `NULL` vs `0` sama-sama berarti "tanpa batas tersimpan" dan akan memicu gerbang untuk perubahan yang sebenarnya tidak ada.
  9. **`splitLeaveAroundReset()`** — pengajuan cuti yang **melintasi tanggal reset** memproyeksikan carry-over: hari sebelum pivot memakai saldo periode berjalan, hari pada/setelah pivot memakai kuota periode baru yang diproyeksikan (`default_leave_quota + carried`), dengan `remaining - days_before` dikurangi lebih dulu sebelum `decide()` — hari bawaan tidak boleh dijanjikan kalau hari-hari pra-pivot pengajuan itu sendiri yang menghabiskannya.
  10. **Payroll & GL hilir** — `PayslipItem::source = 'leave_encashment'` adalah **satu-satunya pembeda** pencairan cuti dari koreksi gaji manual di seluruh rantai hilir; `STEP_LEAVE_ENCASHMENT` terpisah dari `STEP_ADJUSTMENT` agar karyawan melihat dasar cutinya, bukan "koreksi manual" yang tak pernah dibuat siapa pun; pos GL `expense_leave_encashment` (kode **5180**, "Beban Pencairan Cuti") dipisah dari `expense_adjustment` meski mekanismenya `PayrollAdjustment`, karena asalnya kebijakan cuti kantor bukan koreksi gaji.
  11. **Transparansi ke karyawan** — `myLeaveBalance()` mengirim `carry_over` & `encashment` (status, batas, tarif) + `remaining_leave_policy_label` berupa **kalimat siap tampil**, agar aplikasi mobile tidak menyusun ulang matriks kebijakan secara mandiri (dua implementasi matriks yang sama pasti menyimpang begitu aturannya berubah). Tanpa informasi ini satu-satunya cara karyawan mengetahui nasib sisa cutinya adalah menunggu tanggal reset dan melihat hasilnya. **[DIREVISI 2026-10-14]** `leavePolicyLabel()` sekarang punya tiga cabang masa berlaku (`0` → *"dicairkan pada tanggal reset"* dan sengaja **tidak** menyebut *"dibawa ke periode berikutnya"* karena harinya tidak pernah benar-benar dibawa; `NULL` → *"hari bawaan tidak memiliki masa kedaluwarsa"*; `N > 0` → dibawa lalu dicairkan/hangus setelah N bulan), dan `historyOutcomeLabel()` mengenali baris pengguguran lewat `policy_snapshot['event']` lalu menceritakannya sebagai kisah **hari bawaan**, bukan sebagai reset tahunan.
- **Bug korektness nyata yang ditemukan & diperbaiki saat pengujian:** penjagaan anti-pencairan-ganda di `LeavePeriodCloser::close()` memakai `where('reset_date', $resetDate->toDateString())`. Kolom `reset_date` di-cast `'date'`, sehingga Eloquent mengirimkannya ke DB sebagai `'Y-m-d H:i:s'`; pada kolom DATE MySQL nilainya **terpotong** dan perbandingan string kebetulan cocok, tetapi pada driver yang menyimpan apa adanya ia menjadi `'2027-01-01 00:00:00' ≠ '2027-01-01'` — penjagaannya **diam-diam tidak pernah menyala dan karyawan dibayar dua kali**. Diperbaiki menjadi `whereDate()` yang benar di kedua driver. Pelajaran umum: **kolom bercast `date` wajib dibandingkan dengan `whereDate()`, bukan `where()`.**
- **Tugas Frontend (BELUM):**
  1. `SettingsManagement.tsx` — 2 checkbox + 4 input kebijakan cuti di form kantor (batas hari bawa, masa berlaku bulan, nominal pencairan per hari, batas hari dicairkan), dengan penonaktifan input saat checkbox-nya mati dan penanganan **ketujuh** pesan 422 backend apa adanya (pesannya sudah menyebut angka & tindakan perbaikan).
     > **WAJIB diperbaiki setelah pembalikan 2026-10-14:** input **masa berlaku harus menerima `0`** (`min=0`, bukan `min=1`) — `0` adalah satu-satunya cara HRD meminta pencairan **di tanggal reset**, dan form yang menolaknya membuat separuh fitur tidak terjangkau dari layar. Teks bantuannya juga berubah arah: bukan lagi *"kelebihan batas yang dicairkan"* melainkan **"hari di atas batas hangus; hari yang dibawa itulah yang dicairkan"**, dengan tiga keadaan masa berlaku yang perlu dijelaskan di tempat (kosong = tidak pernah kedaluwarsa & **tidak bisa dipasangkan dengan pencairan**, `0` = dicairkan di tanggal reset, `N` = dicairkan di tanggal kedaluwarsa atas sisa yang belum terpakai). Jangan menyusun ulang matriksnya di TSX — backend sudah mengirim kalimat siap tampil `remaining_leave_policy_label`.
  2. Gerbang konfirmasi `SIMPAN` sudah berlaku untuk keenam kolom di backend — peringatan di modal perlu menyebut bahwa mengubah tarif/checkbox pencairan berdampak langsung pada **uang** yang terbit di batch payroll berikutnya.
  3. Tampilan `carried_over_quota` & `carry_over_expired_at` di tabel saldo cuti HRD (`balances[].carried_over_quota`, `office_default_quota`, `carry_over_expired_at`) — HRD harus bisa melihat mana bagian kuota yang merupakan hari bawaan dan kapan hangus.
  4. Ringkasan hasil reset manual (`carry_over_summary`: total dibawa / dicairkan / hangus + rupiah) di respons `resetOfficeLeaveBalances()`.
  5. Riwayat saldo cuti: kolom snapshot (`carried_over_days`, `encashed_days`, `encashment_amount`, `forfeited_days`, `policy_snapshot`) agar pertanyaan "mengapa sisa cuti saya hangus" bisa dijawab dari layar, bukan dari database. Pakai `remaining_outcome_label` per baris apa adanya — backend sudah membedakan baris reset tahunan dari baris **pengguguran masa berlaku hari bawaan** (`policy_snapshot.event = 'carry_over_expiry'`, `period_label` berawalan *"Masa berlaku cuti bawaan s/d …"*) dan menceritakan keduanya dengan kalimat berbeda; `stats.total_*` sudah teragregasi di respons.
  6. Mobile: banner kebijakan sisa cuti memakai `remaining_leave_policy_label` apa adanya (jangan menyusun ulang matriksnya di Dart).
- **Verifikasi:**
  - `tests/Unit/LeaveCarryOverCalculatorTest.php` → **21/21 lolos**: matriks 4 kombinasi checkbox; **case HRD apa adanya** (sisa 10, batas bawa 7 → 7 dibawa + 3 hangus + **Rp0 terbit sekarang** karena masa berlakunya masih berjalan 3 bulan); **masa berlaku 0 mencairkan 7 hari bawaan di tanggal reset** (Rp1.400.000 @200rb) dan **menghanguskan seluruh 10 hari** bila pencairan OFF; **hari di atas batas carry-over hangus, BUKAN dicairkan** — pembeda inti model ini; pencairan tanpa carry-over tidak menghasilkan uang (kalkulator tetap netral walau guard 422 melarangnya tersimpan); pagu pencairan bekerja atas hari **bawaan** (sisa 12, batas bawa 10, pagu 5 → 5 dicairkan + 7 hangus); pagu keras kelas pada data rusak 365 hari; tarif 0 → hangus; batas bawa 0 ≡ checkbox mati; sisa 0 & negatif; **`0` vs `NULL` pada pagu pencairan tidak tertukar**; kedaluwarsa dihitung dari tanggal reset & tidak meluber ke bulan berikutnya; tanpa hari bawaan → tanpa tanggal kedaluwarsa; `isCarryOverExpired()` memakai perbandingan tanggal kalender (hari terakhir masih SAH, `>` bukan `>=`); snapshot membekukan kebijakan; `newPeriodQuota()` termasuk nilai negatif data rusak; kantor NULL netral. **Invarian `remaining = dibawa + dicairkan + hangus` diperiksa pada SETIAP pemanggilan `decide()`.**
  - `tests/Feature/LeaveCarryOverEncashmentTest.php` → **38/38 lolos** (238 assertions): guard HTTP ketujuh kombinasi — termasuk **pencairan tanpa carry-over ditolak** (b2), **pencairan tanpa masa berlaku ditolak** dengan pesan yang menyebut *"0 bulan"* (b3), **`masa berlaku = 0` tersimpan sebagai `0` dan bukan `NULL`**, dan **`batas carry-over = kuota` kini konfigurasi yang SAH** (guard yang dicabut); HRD bisa mengosongkan nominal secara eksplisit; **reset menunda pencairan sampai kedaluwarsa** (sisa 10, batas 6 → dibawa 6, `encashed_days 0`, `encashment_amount` NULL, dan **nol** `PayrollAdjustment` terbit); **`masa berlaku = 0` mencairkan hari bawaan tepat di tanggal reset** (6 hari = Rp1.200.000, `quota` kembali 12, penyesuaian `is_taxable` + `payroll_id` NULL + `approved`) dan **menghanguskan seluruh 10 hari bila pencairan OFF**; **hari bawaan tak terpakai dicairkan saat masa berlakunya habis** (6 hari @150rb = Rp900.000, `quota` 18→12, `used` tetap 2, `created_by` NULL, alasan menyebut *"kedaluwarsa"*, dan sapuan kedua di hari yang sama tidak menerbitkan apa pun lagi); **pagu membatasi hari bawaan yang kedaluwarsa** (pagu 4 → 4 dicairkan + 2 hangus); reset otomatis & reset manual menghasilkan angka **identik**; `quota` periode baru = standar + bawaan; `used` **tidak tersentuh** saat pengguguran; **reset kedua pada tanggal yang sama tidak membayar dua kali** (tes yang menemukan bug `where`→`whereDate`); `quota = 0` tetap non-aktif setelah reset; **ketiga cabang `leavePolicyLabel()`** (masa berlaku berjalan / `0` → *"pada tanggal reset"* tanpa menyebut *"dibawa ke periode berikutnya"* / pencairan OFF → *"hangus"* tanpa menyebut *"dicairkan"*); snapshot riwayat + agregat; **baris riwayat pengguguran bercerita tentang hari bawaan** (*"cuti bawaan"*, *"masa berlakunya habis"*, Rp900.000); tampilan HRD & mobile; serta **3 tes pengajuan melintasi tanggal reset** — pengajuan 2 hari pasca-pivot lolos **hanya** lewat hari bawaan yang diproyeksikan (`default_leave_quota = 1`), ditolak 422 bila carry-over mati, dan ditolak 422 bila hari pra-pivot pengajuan itu sendiri menghabiskan saldonya (membuktikan pengurangan `- days_before`).
  - `tests/Feature/LeaveEncashmentPayrollTest.php` → **8/8 lolos** (59 assertions): baris slip memakai `source = 'leave_encashment'` (bukan `'adjustment'`), earning, taxable, alasan diteruskan ke `notes`, penyesuaian **diklaim** batch (`payroll_id` terisi + `applied`) sehingga tidak bisa dibayar batch lain; masuk bruto **dan** menambah dasar PPh 21; `STEP_LEAVE_ENCASHMENT` melaporkan nominal & `encashment_count` sendiri sementara `STEP_ADJUSTMENT` **tidak muncul** bila tidak ada koreksi manual; saat keduanya hadir, masing-masing melaporkan angkanya sendiri tanpa saling menjumlah (800rb vs 300rb, bruto = pokok + 1,1jt); beberapa pencairan menunggu dijumlah dalam **satu** langkah; jurnal GL mengalirkannya ke `expense_leave_encashment` (5180) sementara `expense_adjustment` **hanya** memuat koreksi manual, dan jurnalnya tetap seimbang; ekspor CSV memuat `5180` + "Beban Pencairan Cuti"; jurnal berdimensi cabang tetap seimbang dengan pos baru.
  - `tests/Feature/LeaveEncashmentPayrollTest.php` → **8/8 lolos TANPA diubah** setelah pembalikan: ia menguji hilir `PayrollAdjustment` (slip, pajak, langkah jejak, GL), dan pembalikan hanya memindahkan **kapan & dari bucket mana** penyesuaian itu terbit — mekanisme pembayarannya tidak berubah sama sekali. Ini bukti tak langsung bahwa pemisahan kalkulator/pelaksana/payroll memang bersih.
  - `php artisan test --filter="LeaveCarryOver|LeaveEncashment"` → **67/67 lolos** (394 assertions).
  - `php artisan test` (seluruh suite backend, setelah pembalikan 2026-10-14) → **1031/1031 lolos** (5.788 assertions). Dua kegagalan `HalfDayAttendanceFlowTest` yang tercatat pada 2026-10-13 adalah artefak hari kalender (berkas itu memakai `Carbon::today('Asia/Jakarta')` tanpa pin dan suite dijalankan hari **Sabtu**); dijalankan pada hari kerja keduanya lolos dan berkasnya tidak pernah disentuh.
  - Regresi terarah `php artisan test --filter="Payroll|Leave"` (seluruh suite yang menyentuh jalur diubah) → **148/148 lolos** (959 assertions).

---

### ⏰ Kategori I — Otomasi Pengingat Presensi (Smart Push Notifications)

#### 9. [SELESAI ✅ 2026-09-16] Otomasi Pengingat Presensi Cerdas (Reminder Jam Masuk, Jam Pulang, & Peringatan Cutoff)
- **Lokasi Kode:**
  - Backend Command: `expenseflow-backend/app/Console/Commands/SendAttendanceRemindersCommand.php` (`attendance:send-reminders`).
  - Scheduler: `expenseflow-backend/routes/console.php` (dijalankan terjadwal `->everyTenMinutes()->withoutOverlapping()->runInBackground()`).
  - Notification Service: `expenseflow-backend/app/Services/FcmService.php` (Push notification real-time ke perangkat Android/iOS via FCM HTTP v1 API).
  - Database Table: Tabel `notifications` (riwayat notifikasi untuk Notification Center mobile & web).
  - Automated Tests: `expenseflow-backend/tests/Feature/AttendanceReminderPushNotificationTest.php` (8 test methods, 16 assertions green).
- **Aturan Ketat Hak Akses Pengingat (Target Karyawan):**
  - Pengingat **HANYA BERLAKU** untuk karyawan yang memiliki izin presensi mobile:
    - `wfh_enabled = true` & `dinas_luar_enabled = true` ✅
    - `wfh_enabled = true` & `dinas_luar_enabled = false` ✅
    - `wfh_enabled = false` & `dinas_luar_enabled = true` ✅
  - Pengingat **TIDAK BERLAKU** jika `wfh_enabled = false` dan `dinas_luar_enabled = false` (karyawan kantor murni yang melakukan presensi di perangkat mesin fisik kantor) ❌.
  - Karyawan yang sedang cuti/izin/sakit (`LeaveRequest` approved hari ini), hari libur nasional/perusahaan, atau shift off-day otomatis diabaikan.
- **Tiga Skenario Pengingat Terjadwal:**
  1. **Reminder Jam Masuk (15 Menit Sebelum Masuk):**
     - Target: Karyawan berizin mobile yang belum check-in hari ini.
     - Window Waktu: 15 menit sebelum jam masuk shift/kantor s/d 5 menit setelah jam masuk.
     - Pesan: *"⏰ 15 menit lagi jam kerja Anda dimulai ({jam} WIB). Jangan lupa lakukan presensi masuk di aplikasi!"*
  2. **Reminder Jam Pulang (Tepat Saat Jam Kerja Selesai):**
     - Target: Karyawan berizin mobile yang sudah check-in tetapi belum check-out.
     - Window Waktu: Tepat jam pulang shift/kantor s/d 30 menit setelah jam pulang.
     - Pesan: *"🏠 Jam kerja Anda telah berakhir ({jam} WIB). Jangan lupa lakukan presensi pulang (check-out) di aplikasi!"*
  3. **Peringatan Batas Alpha (15 Menit Sebelum Cutoff Telat):**
     - Target: Karyawan yang belum check-in dan kantor memiliki konfigurasi batas telat (`late_checkin_cutoff_minutes`).
     - Window Waktu: 15 menit sebelum jam cutoff telat/alpha tiba.
     - Pesan: *"⚠️ 15 menit lagi presensi masuk hari ini akan ditutup ({jam} WIB). Segera lakukan presensi di aplikasi agar tidak tercatat Alpha!"*
- **Integritas Anti-Spam (Deduplikasi):**
  - Menggunakan Cache Key harian berdurasi 24 jam (`attendance_remind_start_{userId}_{date}`, `attendance_remind_end_{userId}_{date}`, `attendance_remind_cutoff_{userId}_{date}`) sehingga setiap jenis notifikasi dijamin hanya terkirim tepat 1 kali per hari per karyawan.
- **Hasil Pengujian Otomatis:**
  - `php artisan test --filter=AttendanceReminderPushNotificationTest` ➡️ **8 passed, 16 assertions**.
  - Seluruh rangkaian pengujian attendance: **62 passed, 257 assertions**.

---

### ⏱️ Kategori J — Disiplin Lembur, Jam Kerja Fleksibel & Aksesibilitas Kiosk

#### 10. TINGGI: Ketiadaan Pemisahan Surat Perintah Kerja Lembur (SPKL Awal) vs Klaim Lembur Aktual
- **Kondisi Saat Ini:**
  - Pengajuan lembur saat ini dilakukan setelah kejadian (*post-factum*).
- **Celah Operasional:**
  - Karyawan dapat sengaja bertahan di kantor hingga malam hari (misal jam 21:00) tanpa pekerjaan mendesak, lalu mengajukan lembur setelah pulang (*overtime padding fraud*).
- **Rekomendasi Solusi:**
  - Standar alur lembur perusahaan (SPKL):
    1. **Pre-Approval (SPKL):** Atasan menugaskan lembur terlebih dahulu sebelum jam kerja berakhir (menentukan target tugas dan estimasi durasi).
    2. **Klaim Aktual:** Saat check-out lembur, sistem mencocokkan waktu riil dengan batas SPKL yang telah disetujui atasan.

#### 11. SEDANG: Ketiadaan Dukungan Jam Kerja Fleksibel (Flexitime & Core Hours) [SELESAI ✅ 2026-09-16]
- **Kondisi Saat Ini:**
  - Jam kerja terikat kaku pada satu jam masuk tetap (`work_start_time`, misal 08:00). Masuk pukul 08:16 langsung ditandai "Late" (terlambat).
- **Kebutuhan Perusahaan Modern:**
  - Untuk staf IT/kantor back-office yang menerapkan jam kerja fleksibel:
    - Karyawan bebas datang antara pukul 07:00 s.d 10:00 asalkan memenuhi target durasi kerja harian (misal 8 jam kerja) dan wajib berada di kantor pada jam inti (*Core Hours*, misal 10:00–15:00).
    - Datang pukul 09:00 dan pulang pukul 18:00 tidak dianggap terlambat karena memenuhi target 8 jam.
- **Implementasi (Opsi 2 — User-Level Toggle & Branch Settings):**
  - **Database & Model:**
    - Migration: Tambah `flexitime_enabled` (boolean, default false) pada tabel `users`.
    - Migration: Tambah parameter flexitime kantor pada tabel `attendance_settings`: `flex_arrival_start` (07:00), `flex_arrival_end` (10:00), `flex_core_start` (10:00), `flex_core_end` (15:00), `flex_target_minutes` (480 = 8 jam kerja).
    - Migration: Tambah snapshot kolom flexitime pada tabel `attendances`: `snap_flexitime_enabled`, `snap_flex_arrival_start`, `snap_flex_arrival_end`, `snap_flex_core_start`, `snap_flex_core_end`, `snap_flex_target_minutes`.
  - **Backend Logic (AttendanceController & AutoCheckoutCommand):**
    - `buildSnapshot()`: Menyimpan konfigurasi flexitime user dan cabang saat `checkIn()` untuk dibekukan.
    - `isFlexitimeSession()`: Membaca status flexitime dari snapshot sesi presensi (fallback ke live user untuk record lama).
    - `snapshotOffice()`: Menimpa parameter flexitime instance AttendanceSetting dengan nilai yang dibekukan saat check-in.
    - `determineStatus()`: Karyawan flexitime yang check-in sebelum/pada `flex_arrival_end` (+ toleransi) ditandai `present` (tidak telat).
    - `resolveWorkStart()`: Jam masuk dihitung mulai dari check-in aktual (atau batas awal window kedatangan), bukan jam kantor fixed.
    - `checkEarlyLeave()`: Deteksi pulang cepat jika check-out sebelum `flex_core_end` atau durasi kerja riil belum memenuhi `flex_target_minutes` (membaca snapshot).
    - `calculateOvertime()`: Menghitung lembur setelah durasi kerja melampaui `flex_target_minutes` snapshot.
    - `toggleFlexitime()`: Endpoint `POST /api/v1/dashboard/attendance/users/{id}/toggle-flexitime` bagi HRD untuk mengaktifkan/menonaktifkan flexitime per karyawan (otomatis memblokir dengan status 422 jika karyawan sedang terikat penugasan shift aktif).
    - `checkStatus()`: Mengembalikan `flexitime_enabled` dan `flexitime_config` beserta target dinamis `target_checkout_time` berbasis snapshot sesi aktif.
    - `AutoCheckoutCommand`: Menghitung batas auto-checkout dinamis untuk sesi flexitime (`workStart + target_minutes + break_minutes`) serta menghitung overtime berbasis snapshot flexitime.
    - **Mutual Exclusion Shift vs Flexitime (2026-09-16)**: Karyawan berjadwal Shift atau Pola Rotasi otomatis berstatus `flexitime_enabled = false`. Saat di-assign shift via `assignShift` atau `bulkAssign`, toggle otomatis dimatikan.
    - **Proteksi Perubahan Siang Hari (Bulletproof Midday Changes)**: Perubahan aturan HRD di siang hari (seperti mengubah target jam kerja cabang atau mematikan toggle flexitime user) TIDAK mempengaruhi sesi presensi karyawan yang sudah check-in di pagi hari.
  - **Web Dashboard (Admin / HRD):**
    - `AttendanceManagement.tsx`: Kolom & tombol toggle Flexitime interaktif di tabel karyawan dengan audit log. Bagi karyawan yang memiliki jadwal shift aktif, tombol toggle otomatis terkunci (Disabled / OFF) disertai tooltip penjelas nama shift aktif.
    - `SettingsManagement.tsx`: Kartu konfigurasi parameter flexitime cabang (window kedatangan, core hours, target durasi harian).
  - **Mobile App (Flutter):**
    - `PresensiProvider`: Sinkronisasi status `flexitimeEnabled` & `flexitimeConfig` dari backend.
    - `PresensiMapScreen`: Banner indikator mode Flexitime dengan info target pulang dinamis dan modal konfirmasi anti pulang cepat saat check-out sebelum core hours atau sebelum target durasi terpenuhi.
  - **Automated Tests:**
    - `tests/Feature/FlexitimeAttendanceTest.php`: 17 pengujian fitur lengkap (check-in, check-out, early leave, overtime, update kantor, toggle API, auto-disable on assign-shift, bulk-assign, mutual exclusion guard, list-users active shift flags, snapshot storage, midday office target change resilience, midday user toggle change resilience) - 100% Passed (17 tests, 75 assertions).

#### 12. SEDANG: Ketiadaan Mode Tablet Kiosk Bersama di Lobi / Pabrik (Shared Attendance Kiosk)(skip)
- **Kondisi Saat Ini:**
  - Presensi mewajibkan setiap karyawan memiliki smartphone pribadi dengan aplikasi Flutter terinstal.
- **Kendala Lapangan:**
  - Di fasilitas pabrik, pergudangan, atau staf kebersihan (*cleaning service*), tidak semua karyawan memiliki smartphone memadai, atau terdapat aturan larangan membawa ponsel pribadi ke area steril / lini produksi.
- **Rekomendasi Solusi:**
  - Sediakan mode Kiosk pada satu tablet Android/iPad yang dipasang di lobi: karyawan melakukan presensi cepat dengan memindai **QR Code / Barcode pada ID Card** masing-masing di depan kamera tablet bersama.

---

### 📊 Kategori K — Analitik Cerdas HRD (HR Analytics & Early Warning System)

#### 13. SEDANG: Ketiadaan Deteksi Pola Ketidakhadiran & Indeks Bradford Factor
- **Kondisi Saat Ini:**
  - Laporan HRD saat ini hanya menyajikan angka total agregat (jumlah hadir, telat, dan alpha).
- **Kebutuhan Manajerial HRD:**
  - **Bradford Factor:** Rumus standar manajemen SDM internasional untuk mengukur dampak gangguan operasional akibat ketidakhadiran berulang ($B = S^2 \times D$, di mana $S$ adalah frekuensi kejadian izin dan $D$ adalah total hari).
  - Sistem dapat otomatis memberikan indikator "Lampu Kuning / Merah" kepada HRD untuk mendeteksi karyawan yang sering mengambil izin singkat di hari-hari strategis (seperti selalu izin di hari Senin atau Jumat untuk memperpanjang akhir pekan).

---

## 🔍 Bagian 6 — Temuan Audit QA & Edge Cases Fitur Koreksi Presensi Manual HRD (2026-10-06)

> Hasil audit mendalam (QA Engineering, boundary value analysis, concurrency & state machine check) terhadap implementasi endpoint `AttendanceController::correctAttendance()` dan antarmuka web `AttendanceManagement.tsx` per **2026-10-06**.
> Catatan ini mendokumentasikan skenario aksi user/HRD yang dapat memicu error, inkonsistensi status kehadiran, celah manipulasi gaji, hingga konflik sesi mobile.

---

### 🔴 Kategori L — Integritas Data, Concurrency & Validasi Bisnis Koreksi Presensi

#### 14. KRITIS: Status `present` (Hadir) Dapat Disimpan Tanpa Jam Masuk (Gaji Fiktif) — ✅ SELESAI 2026-10-07
- **Lokasi Kode:** `AttendanceController::correctAttendance()` (~line 4425) & `AttendanceManagement.tsx`
- **Skenario Aksi User:**
  - Pada baris alpha atau baris virtual, HRD memilih status *"Hadir"* (`present`), namun membiarkan jam masuk dan jam pulang kosong (`null`).
- **Akar Cacat Logika:**
  - Guard 6 hanya memeriksa:
    ```php
    if ($newCheckInStr === null && $newStatus === null) { ... }
    ```
    Karena `$newStatus` bernilai `'present'`, validasi lolos meskipun `$newCheckInStr` bernilai `null`.
- **Dampak Kritis:**
  - Database menyimpan record presensi dengan `status = 'present'`, tetapi `check_in_time = null` dan `work_minutes = null`.
  - Pada modul **Payroll**, karyawan ini dihitung hadir penuh 1 hari kerja (mendapat gaji harian/kehadiran penuh) tanpa ada jam kerja riil.
  - Pada laporan tabel dan CSV, jam masuk & pulang muncul sebagai strip (`-`) namun statusnya Hadir (anomali data).
- **Rekomendasi Solusi:**
  - Jika `$newStatus` adalah `'present'`, `'late'`, atau `'early_leave'`, wajibkan `$newCheckInStr !== null`. Status kehadiran fisik tidak boleh ada tanpa fakta waktu masuk.
- **Implementasi (2026-10-07):**
  - **Backend — `AttendanceController::correctAttendance()` (Guard 8, ~line 4643):** Guard baru dipasang **di dalam** `DB::transaction` tepat setelah blok perakitan waktu absolut (agar `$checkInCarbon` sudah ter-resolve) dan sebelum Guard Enterprise waktu-masa-depan. Dilempar sebagai `HttpResponseException` (bukan `return`) sesuai invariant file: `return` di dalam closure hanya mengakhiri closure, transaksi tetap ter-commit.
    ```php
    if ($newStatus !== null && $checkInCarbon === null) {
        $presenceStatuses = ['present', 'late', 'early_leave', 'wfh'];
        if (in_array($newStatus, $presenceStatuses, true)) {
            throw new HttpResponseException(response()->json([
                'message'           => 'Status "…" tidak bisa disimpan tanpa jam masuk — …',
                'requires_check_in' => true,
                'status'            => $newStatus,
            ], 422));
        }
    }
    ```
  - **Dua penguatan di luar rekomendasi awal dokumen:**
    1. **`'wfh'` ikut diblokir.** Verifikasi ke `PayrollCalculator::attendanceDays()` (`app/Services/Payroll/PayrollCalculator.php:865`) menunjukkan status yang dihitung sebagai hari kerja **dibayar** ada **empat**: `whereIn('status', ['present', 'late', 'wfh', 'early_leave'])` — bukan tiga. Tanpa `wfh`, celah gaji fiktif tetap terbuka lewat status WFH.
    2. **Dicek terhadap jam masuk EFEKTIF (`$checkInCarbon`), bukan hanya `$newCheckInStr`.** Ini sekaligus menutup celah kedua yang belum tercatat di temuan: HRD mengirim `check_in_time: null` secara eksplisit pada baris yang SUDAH `present` — jam masuknya terhapus sementara status berbayarnya tetap utuh. Karena `$checkInCarbon` jatuh kembali ke `$existing->check_in_time` hanya bila field-nya tidak dikirim, pengosongan eksplisit tetap tertangkap.
  - Hanya `'absent'` (Alpha) yang boleh berdiri tanpa jam masuk — ini memang kasus sah dan tidak dibayar di payroll.
  - **Web Dashboard — `AttendanceManagement.tsx`:**
    - `handleSaveCorrection()` (~line 2069): validasi pre-flight lokal tepat setelah guard "jam pulang tanpa jam masuk", memakai daftar status yang sama. Umpan balik instan tanpa request terbuang.
    - Blok *Status Kehadiran* pada modal koreksi (~line 5892): paragraf bantuan statis diganti peringatan inline kondisional (merah/rose) yang muncul begitu kombinasi status-berbayar + jam masuk kosong terdeteksi, dan kembali ke teks bantuan normal bila kombinasinya sah.
- **Verifikasi:**
  - `tests/Feature/AttendanceCorrectionTest.php` — 4 test regresi baru (bagian 18): (a) keempat status berbayar (`present`/`late`/`early_leave`/`wfh`) tanpa jam masuk → 422 + `requires_check_in: true` **dan** `assertDatabaseMissing` (bukti transaksi benar-benar rollback, tidak ada baris presensi tertulis); (b) `absent` tanpa jam masuk → tetap 200 & tersimpan dengan `work_minutes` null; (c) mengosongkan jam masuk pada baris `present` yang sudah ada → 422, baris lama utuh (jam masuk 08:00 & status tetap); (d) `present` **dengan** jam masuk → tetap 200 (tanpa regresi).
  - `php artisan test --filter=AttendanceCorrection` → **36 test, 160 assertion, 100% lulus** (32 test lama tidak ada yang pecah).
  - `php artisan test --filter=Attendance` → **139 test, 718 assertion, 100% lulus**.
  - `php -l` bersih pada `AttendanceController.php` & file test; `npx tsc --noEmit` bersih pada `expenseflow-web`.

---

#### 15. TINGGI: Koreksi Jam Pulang pada Sesi Hari Ini Memblokir Check-out Karyawan di Mobile — ✅ SELESAI 2026-10-07
- **Lokasi Kode:** `AttendanceController::correctAttendance()` (~line 4460) & `AttendanceController::checkOut()` (~line 7850)
- **Skenario Aksi User:**
  - Karyawan check-in di kantor jam 08:00 WIB hari ini.
  - Jam 11:00 WIB, HRD membuka web Laporan dan mengisikan jam pulang jam 10:30 WIB (misal salah memilih baris karyawan atau mengira karyawan sudah pulang).
- **Dampak Operasional:**
  - Sore harinya jam 17:00 WIB, karyawan yang bersangkutan menyelesaikan pekerjaan dan menekan tombol **Check Out** di aplikasi mobile.
  - Endpoint `/api/v1/attendance/check-out` mencari record dengan `whereNull("check_out_time")`. Karena jam pulangnya sudah diisi oleh HRD, backend menolak check-out karyawan dengan error HTTP 422:
    > *"Anda belum melakukan check-in hari ini atau sudah melakukan check-out."*
  - Akibatnya: Bukti GPS riil, koordinat kantor, dan jam lembur karyawan yang sebenarnya hingga jam 17:00 WIB **hangus dan tertolak**.
- **Rekomendasi Solusi:**
  - Berikan peringatan atau larangan mengoreksi jam pulang pada tanggal hari ini (`$date === $today`) jika sesi presensi karyawan masih berstatus aktif berjalan di lapangan (`existing->check_in_time && !existing->check_out_time`).
- **Implementasi (2026-10-07):**
  - **Backend — `AttendanceController::correctAttendance()` (Guard 9, ~line 4662):** Guard baru dipasang **di dalam** `DB::transaction` tepat setelah Guard 8 (status-tanpa-jam-masuk) dan sebelum Guard Enterprise waktu-masa-depan, memakai kondisi persis sesuai rekomendasi dokumen:
    ```php
    if (
        $date === $today
        && $existing
        && $existing->check_in_time
        && ! $existing->check_out_time
        && $sendsCheckOut
        && $newCheckOutStr !== null
        && ! $request->boolean('override_active_session')
    ) {
        throw new HttpResponseException(response()->json([
            'message' => 'Karyawan ini masih memiliki sesi presensi AKTIF hari ini (…). Mengisi jam pulang sekarang akan membuat karyawan TIDAK BISA check-out sendiri lewat aplikasi mobile nanti — …',
            'active_session_block'  => true,
            'requires_confirmation' => true,
            'confirmation_field'    => 'override_active_session',
            'check_in_time'         => $checkInHm,
        ], 422));
    }
    ```
  - **Sengaja bukan larangan keras, tapi gerbang konfirmasi terpisah** dari `confirm_dangerous` yang sudah ada: field baru `override_active_session` (boolean, divalidasi `nullable|boolean`) harus dikirim `true` agar koreksi tetap jalan. Dipisah dari `confirm_dangerous` karena sifat risikonya berbeda — bukan cuma dampak gaji, tapi memblokir aksi karyawan sendiri di mobile. HRD yang memang bermaksud menutup sesi (HP karyawan hilang, karyawan sudah resign) tetap bisa melanjutkan setelah membaca peringatannya secara eksplisit.
  - Guard ini hanya menembak bila **keempat** kondisi terpenuhi sekaligus: tanggal = hari ini, baris existing punya jam masuk, baris existing BELUM punya jam pulang (sesi masih "terbuka" di mata mobile `checkOut()`), dan request benar-benar mengisi jam pulang baru. Koreksi tanggal lampau, sesi yang sudah ditutup, atau koreksi yang tidak menyentuh jam pulang sama sekali tidak terpengaruh.
  - **Web Dashboard — `AttendanceManagement.tsx`:**
    - State baru `correctionActiveSessionConfirm` (terpisah dari `correctionConfirm`/`correctionConfirmText` yang dipakai gerbang `confirm_dangerous`), diisi saat response 422 membawa `active_session_block: true`, dicek **sebelum** pengecekan `requires_confirmation` generik di `handleSaveCorrection()` karena Guard 9 di backend selalu menembak lebih dulu.
    - Kotak peringatan rose/merah baru di modal koreksi (sebelum kotak amber `confirm_dangerous`) menampilkan pesan dari server + instruksi untuk mengklik tombol konfirmasi bila HRD memang yakin.
    - Tombol Simpan berubah warna (rose) & label (**"Tetap Ambil Alih & Simpan"**) saat state ini aktif; klik ulang mengirim ulang request dengan `override_active_session: true` di payload. Bila koreksi itu juga mengubah jam/gaji, gerbang `confirm_dangerous` tetap menyusul normal pada submit berikutnya (dua gerbang independen, bisa berurutan).
- **Verifikasi:**
  - `tests/Feature/AttendanceCorrectionTest.php` — 5 test regresi baru (bagian 19): (a) mengisi jam pulang hari ini pada sesi aktif tanpa konfirmasi → 422 + `active_session_block: true` + `requires_confirmation: true` + `confirmation_field: 'override_active_session'`, dan baris lama tidak berubah (`check_out_time` tetap null); (b) sama persis tapi dengan `override_active_session: true` → 200, jam pulang tersimpan; (c) koreksi jam pulang pada **tanggal lampau** (bukan hari ini) pada sesi yang terlihat "aktif" → tetap 200 tanpa guard ini (mobile `checkOut()` memang tidak pernah mencari tanggal lampau); (d) sesi hari ini yang **sudah** check-out → koreksi lanjutan tidak terkena guard ini; (e) koreksi yang hanya mengisi jam masuk (tidak menyentuh jam pulang) pada sesi aktif hari ini → tidak terkena guard ini. Test (b), (d), (e) mengunci jam sistem (`Carbon::setTestNow`) agar lolos Guard Enterprise waktu-masa-depan secara deterministik, tidak bergantung jam jalan sungguhan saat suite dieksekusi.
  - `php artisan test --filter=AttendanceCorrection` → **41 test, 176 assertion, 100% lulus** (36 test lama tidak ada yang pecah).
  - `php artisan test --filter=Attendance` → **144 test, 734 assertion, 100% lulus**.
  - `php -l` bersih pada `AttendanceController.php` & file test; `npx tsc --noEmit` bersih pada `expenseflow-web`.

---

#### 16. TINGGI: Presensi "Dinas Luar" Otomatis Berubah Diam-diam Menjadi "Onsite" Saat Dikoreksi
- **Lokasi Kode:** `AttendanceManagement.tsx` (~line 2013-2015)
- **Skenario Aksi User:**
  - Karyawan melakukan presensi dinas luar ke klien (memiliki nama klien, alamat, dan catatan agenda), namun lupa checkout. HRD membuka modal edit untuk melengkapi jam pulang.
- **Akar Masalah di Frontend:**
  - Saat modal edit dibuka, nilai form di-set dengan:
    ```ts
    check_in_type: (["onsite", "wfh", "field"].includes(row.check_in_type) ? row.check_in_type : "onsite"),
    ```
    Karena opsi `"dinas_luar"` tidak ada di dropdown, form otomatis menjatuhkannya (*fallback*) ke `"onsite"`.
- **Dampak:**
  - Saat HRD menyimpan koreksi, payload mengirimkan `check_in_type: "onsite"`.
  - Record presensi dinas luar karyawan **berubah diam-diam menjadi presensi kantor biasa (`onsite`)**. Rekapitulasi kunjungan klien dan laporan dinas luar menjadi tidak akurat.
- **Rekomendasi Solusi:**
  - Sertakan tipe `dinas_luar` pada dropdown/pilihan form koreksi atau pertahankan `row.check_in_type` asli jika pengguna tidak secara eksplisit mengubah tipe kehadiran.

---

#### 17. SEDANG: Shift Lintas Hari: Check-in Dini Hari Dihitung Mundur 22 Jam Sebelum Shift Dimulai ✅ SELESAI 2026-10-07
- **Lokasi Kode:** `AttendanceController::correctAttendance()` (~line 4632-4646)
- **Skenario Aksi User:**
  - Karyawan memiliki shift malam lintas hari (22:00 – 06:00, `is_cross_day = true`).
  - Karyawan terlambat masuk dan baru check-in jam 01:00 dini hari (hari kalender berikutnya), lalu pulang jam 06:00.
  - HRD menginput koreksi tanggal shift (misal 5 Okt): `check_in_time: 01:00`, `check_out_time: 06:00`.
- **Akar Cacat Logika:**
  ```php
  if ($checkOutCarbon->lessThanOrEqualTo($checkInCarbon)) {
      if (! empty($schedule["is_cross_day"])) {
          $checkOutCarbon->addDay();
      }
  }
  ```
  Karena jam pulang `06:00` lebih besar dari jam masuk `01:00`, kondisi `lessThanOrEqualTo` bernilai `false`. Fungsi `addDay()` tidak dipanggil, sehingga kedua waktu dianggap terjadi pada tanggal 5 Oktober (jam 01:00 pagi ke 06:00 pagi = 21 jam sebelum shift malam tanggal 5 Oktober dimulai).
- **Rekomendasi Solusi:**
  - Pada shift lintas hari (`is_cross_day`), evaluasi apakah jam masuk berada di bawah batas jam pulang shift (dini hari); jika ya, kedua jam masuk dan jam pulang harus di-offset ke hari berikutnya (+1 hari dari tanggal penugasan shift).

- **Implementasi (2026-10-07):**
  - Ditambahkan blok offset baru tepat sebelum pengecekan `lessThanOrEqualTo` lama, dieksekusi khusus untuk shift `is_cross_day = true`. Blok ini mengevaluasi **setiap** jam yang baru diketik HRD (jam masuk & jam pulang, independen satu sama lain) terhadap `work_end_time` jadwal (jam pulang shift, misal `06:00`): jika nilai `HH:MM` yang diketik ≤ `work_end_time`, nilai itu termasuk "ekor dini hari" milik shift tersebut → di-`addDay()`.
    ```php
    // ─── Shift lintas hari: offset jam dini hari ke tanggal yang benar ──
    if (! empty($schedule['is_cross_day'])) {
        $workEndHm = substr((string) ($schedule['work_end_time'] ?? ''), 0, 5);
        if ($workEndHm !== '') {
            if ($checkInCarbon && $newCheckInStr !== null && $newCheckInStr <= $workEndHm) {
                $checkInCarbon->addDay();
            }
            if ($checkOutCarbon && $newCheckOutStr !== null && $newCheckOutStr <= $workEndHm) {
                $checkOutCarbon->addDay();
            }
        }
    }
    ```
  - Hanya berlaku untuk nilai yang **baru diketik** (`$newCheckInStr`/`$newCheckOutStr`, berupa string `HH:MM` dari request) — tidak menyentuh nilai existing yang sudah tersimpan benar (datetime lama yang tidak dikirim ulang), sehingga koreksi parsial (misal HRD hanya mengoreksi jam masuk) tidak merusak jam pulang yang sudah benar.
  - Mekanisme lama (`lessThanOrEqualTo` → `addDay()` pada jam pulang saja) **tetap dipertahankan** sebagai fallback setelah blok baru ini — terbukti tidak menyebabkan regresi karena `diffInMinutes` antara jam masuk & jam pulang bersifat relatif (invariant terhadap kedua instance yang sama-sama bergeser hari).
- **Verifikasi:**
  - Regresi pada 3 test lintas-hari yang sudah ada sebelum perbaikan (`test_shift_lintas_hari_menerima_jam_pulang_lebih_kecil_dari_jam_masuk`, `test_jam_pulang_sebelum_jam_masuk_pada_shift_biasa_ditolak`, `test_durasi_presensi_melebihi_dua_puluh_jam_ditolak`) diverifikasi lolos tanpa perubahan assert.
  - 2 test baru ditambahkan di `AttendanceCorrectionTest.php`: `test_check_in_dini_hari_pada_shift_lintas_hari_dioffset_ke_hari_berikutnya` (reproduksi skenario bug persis — check-in `01:00` & check-out `06:00` keduanya ter-offset ke `$date+1`, `work_minutes = 240`) dan `test_koreksi_hanya_jam_masuk_dini_hari_shift_lintas_hari_tidak_mengganggu_jam_pulang_tersimpan` (koreksi parsial hanya jam masuk tidak merusak jam pulang existing yang sudah benar).
  - `php artisan test --filter=AttendanceCorrectionTest`: **43/43 lolos** (187 assertions).
  - `php artisan test --filter=Attendance` (suite penuh): **146/146 lolos** (745 assertions) — tidak ada regresi di luar file koreksi.
  - `php -l` bersih untuk `AttendanceController.php` dan `AttendanceCorrectionTest.php`.

---

#### 18. [SELESAI ✅] Potensi Double-Count Presensi dengan Pengajuan Cuti Berstatus `pending`
- **Lokasi Kode:** `AttendanceController::correctAttendance()` & `AttendanceController::approveLeave()`
- **Implementasi:** Validasi dua arah telah diaktifkan untuk mencegah pencatatan ganda (*double-count*): `correctAttendance()` memblokir/memperingatkan input presensi jika terdapat pengajuan cuti berstatus `pending` pada tanggal tersebut, dan `approveLeave()` menolak persetujuan cuti jika karyawan sudah memiliki catatan presensi hadir pada rentang tanggal yang diajukan.

---

#### 19. SEDANG: Race Condition Double-Click pada Baris Virtual Menyebabkan HTTP 500 (Duplicate Entry) ✅ SELESAI 2026-10-07
- **Lokasi Kode:** `AttendanceController::correctAttendance()` (~line 4440)
- **Skenario Aksi User:**
  - Saat jaringan lambat, HRD mengklik tombol Simpan dua kali secara cepat (*double-click*) pada baris virtual (input manual yang belum memiliki row di database).
- **Akar Cacat Concurrency:**
  - Tabel `attendances` memiliki unique index `unique(["user_id", "date"])`.
  - Kedua request paralel membaca `$existing = null`, lalu keduanya mengeksekusi `Attendance::updateOrCreate()`. Request kedua menabrak unique constraint database dan melempar `QueryException`.
- **Dampak:**
  - Muncul dialog crash **HTTP 500 Internal Server Error** yang membingungkan HRD.
- **Rekomendasi Solusi:**
  - Terapkan `Cache::lock("correct_attendance_{$employee->id}_{$date}", 10)` di backend (seperti yang sudah diterapkan pada `checkOut()`), atau tangani `QueryException` secara elegan.

- **Implementasi (2026-10-07):**
  - Diterapkan `Cache::lock("correct_attendance_{$employee->id}_{$date}", 10)` tepat setelah Guard 1 (begitu `$employee` & `$date` diketahui), persis pola yang sudah dipakai `checkIn()`/`checkOut()`.
  - Jika lock gagal diambil (permintaan lain untuk karyawan+tanggal yang sama masih berjalan), endpoint langsung menjawab **HTTP 409** dengan pesan ramah ("sedang diproses oleh permintaan lain"), bukan mencoba `updateOrCreate()` dan menabrak unique constraint.
  - Guard 2–7, transaksi (`DB::transaction` + `lockForUpdate()`), audit log, notifikasi, dan response akhir kini dibungkus `try { ... } finally { $lock->release(); }` — lock selalu dilepas baik pada jalur sukses, `return` awal (gagal guard), maupun saat `HttpResponseException` dilempar dari dalam transaksi.
    ```php
    $lock = Cache::lock("correct_attendance_{$employee->id}_{$date}", 10);
    if (! $lock->get()) {
        return response()->json([
            'message' => 'Koreksi presensi karyawan ini sedang diproses oleh permintaan lain. Silakan tunggu sebentar lalu coba lagi.',
        ], 409);
    }
    try {
        // Guard 2-7 + transaksi + audit + response ...
    } finally {
        $lock->release();
    }
    ```
  - `lockForUpdate()` yang sudah ada di dalam transaksi tetap dipertahankan (masih berguna untuk baris yang SUDAH ada), tapi `Cache::lock` di luar transaksi inilah yang menutup celah baris virtual (belum ada row untuk dikunci database).
- **Verifikasi:**
  - Test baru `test_double_click_pada_baris_virtual_tidak_menyebabkan_500_duplicate_entry` di `AttendanceCorrectionTest.php`: menyimulasikan "request pertama masih berjalan" dengan menahan lock yang sama lebih dulu → request kedua pada baris virtual yang sama ditolak bersih dengan **409** (bukan 500) dan tidak membuat baris apa pun; setelah lock dilepas, request susulan tetap berhasil normal dan hanya tersimpan **1 baris** (tidak ada duplikat).
  - `php artisan test --filter=AttendanceCorrectionTest`: **44/44 lolos** (193 assertions) — termasuk seluruh test koreksi lama (snapshot, shift lintas hari, guard payroll lock, dsb.) tanpa regresi akibat pembungkusan `try/finally`.
  - `php artisan test --filter=Attendance` (suite penuh): **147/147 lolos** (751 assertions).
  - `php -l` bersih untuk `AttendanceController.php` dan `AttendanceCorrectionTest.php`.

---

#### 20. RENDAH / UX: Konfirmasi `SIMPAN` Gagal / Terkunci Jika Mengetik Huruf Kecil `simpan` — ✅ SELESAI 2026-10-07
- **Lokasi Kode:** `AttendanceManagement.tsx` (`handleSaveCorrection` line ~2092 & modal input konfirmasi line ~5825)
- **Skenario Aksi User:**
  - Saat gerbang konfirmasi bahaya muncul, HRD mengetik kata `simpan` menggunakan huruf kecil (tanpa tombol CapsLock).
- **Akar Cacat UI:**
  - Elemen `<input>` memiliki kelas Tailwind `uppercase`. Kelas ini hanya memberikan transformasi visual pada tampilan layar.
  - Nilai variabel state JavaScript `correctionConfirmText` tetap bernilai huruf kecil `"simpan"`.
- **Dampak UX:**
  - Di layar HRD terlihat jelas tulisan kapital `SIMPAN`, namun saat tombol *Konfirmasi & Simpan* ditekan, sistem menolak dengan pesan *"Ketik SIMPAN dengan tepat untuk melanjutkan."* Pengguna akan mengira sistem mengalami error/macet.
- **Rekomendasi Solusi:**
  - Tambahkan transformasi eksplisit pada input event handler: `onChange={(e) => setCorrectionConfirmText(e.target.value.toUpperCase())}` dan lakukan pemangkasan spasi (`.trim()`).
- **Implementasi (2026-10-07):**
  1. Pada elemen `<input>` konfirmasi koreksi (`AttendanceManagement.tsx`):
     - Ditambahkan transformasi otomatis saat input berubah: `setCorrectionConfirmText(e.target.value.toUpperCase().trim())`.
     - Ditambahkan reset error aktif saat user mengetik: `if (correctionError) setCorrectionError(null)`.
     - Ditambahkan shortcut tombol Enter (`onKeyDown`) agar langsung memicu `handleSaveCorrection(true)` tanpa harus klik mouse.
  2. Pada fungsi validasi `handleSaveCorrection()`:
     - Dilakukan sanitasi ganda: `correctionConfirmText.trim().toUpperCase() !== correctionConfirm.phrase` sebelum pengecekan validasi, memastikan input tetap lolos meski ada spasi atau variasi casing.
- **Verifikasi:**
  - `npm run lint` (`tsc --noEmit`) pada `expenseflow-web` → Lolos tanpa error TypeScript (exit code 0).

---

#### 21. RENDAH: Perbedaan Zona Waktu Browser/Laptop HRD (WITA/WIT) Menggeser Jam Tampilan Input — ✅ SELESAI 2026-10-08
- **Lokasi Kode:** `AttendanceManagement.tsx` (`toTimeInput` & `fmtTime`)
- **Skenario Aksi User:**
  - HRD atau Admin membuka dashboard web dari cabang luar Jawa (WITA UTC+8 seperti Bali/Makassar, atau WIT UTC+9 seperti Jayapura/Papua).
- **Akar Masalah:**
  - Fungsi pembantu form menggunakan `new Date(v).getHours()`. Fungsi ini membaca jam berdasarkan zona waktu lokal perangkat/laptop pengakses, bukan acuan zona waktu operasional sistem (WIB / Asia/Jakarta).
- **Dampak:**
  - Presensi jam 08:00 WIB akan otomatis terisi dan tampil sebagai 09:00 di form laptop HRD di Bali. Jika HRD tidak teliti dan langsung menekan Simpan, jam presensi karyawan bergeser terlambat 1 jam.
- **Implementasi (2026-10-08):**
  - Mengganti `toTimeInput` dan `fmtTime` agar melakukan pemotongan string murni (`Regex` / `slice`) tanpa memanggil `new Date()`.
  - Di `openCorrectionModal`, `check_in_time` dan `check_out_time` mengutamakan `row.check_in_time_local` / `row.check_out_time_local` dari payload sebelum fallback ke `toTimeInput`.
  - Menampilkan label zona waktu cabang per baris di tabel laporan presensi dan di modal koreksi presensi.
- **Verifikasi:**
  - `npm run lint` (`tsc --noEmit`) pada `expenseflow-web` lolos tanpa error (exit code 0).

---

#### 22. KRITIS: Status `absent` (Alpha) Tidak Membersihkan Jam Masuk/Pulang yang Sudah Ada — ✅ SELESAI 2026-10-07
- **Lokasi Kode:** `AttendanceController::correctAttendance()` (blok `$dangerousChanged` ~line 4805 & blok `$facts` ~line 4844)
- **Skenario Aksi User (dikonfirmasi empiris via script standalone, lalu dihapus):**
  - Baris presensi awalnya `status=present`, `check_in_time=08:00`, `check_out_time=17:00`.
  - HRD membuka modal koreksi dan **hanya mengubah dropdown status ke "Alpha"** (tanpa menyentuh input jam), lalu klik Simpan.
  - Hasil di DB sebelum perbaikan: `status=absent` TAPI `check_in_time` & `check_out_time` **masih tersimpan utuh**.
  - Reachable langsung dari UI (bukan cuma API mentah) karena `AttendanceManagement.tsx` selalu mengirim `check_in_time`/`check_out_time` dari nilai form yang sudah pre-filled, dan form tidak auto-clear jam saat status diganti ke Alpha.
- **Akar Masalah:**
  - Blok "Status absent tidak boleh menyisakan jam kerja/lembur menggantung" (~line 4922, lihat temuan di atas) hanya me-null-kan `work_minutes`/`overtime_minutes` di `$derived` — tidak pernah men-null-kan `check_in_time`/`check_out_time`/`check_in_type` di `$facts` sebelum `updateOrCreate()`.
  - Gerbang konfirmasi SIMPAN (`$dangerousChanged`) membandingkan jam lama vs baru secara string; karena HRD tidak mengetik ulang jamnya (form mengirim nilai yang SAMA), jam tidak terdeteksi "berubah" — HRD tidak pernah diperingatkan bahwa jam tetap akan tersimpan/terhapus.
- **Dampak:**
  - Laporan Presensi menampilkan baris kontradiktif (status "Alpha" tapi kolom Jam Masuk/Jam Pulang terisi 08:00–17:00) — membingungkan HRD dan berisiko disalahartikan sebagai bug data.
  - Payroll (`PayrollCalculator::attendanceDays()`) kebetulan aman karena hanya membaca kolom `status`, bukan jam — tapi laporan & integrasi lain yang membaca jam langsung tetap berisiko.
- **Implementasi (2026-10-07):**
  1. **Blok `$facts` (sebelum `updateOrCreate()`):** ditambahkan override tanpa kondisi — begitu `$newStatus === 'absent'`, `$facts['check_in_time']`, `$facts['check_out_time']`, dan `$facts['check_in_type']` dipaksa `null`, terlepas dari apa yang dikirim request (menutup celah "field tidak dikirim sama sekali" sekaligus "dikirim dengan nilai lama").
  2. **Blok `$dangerousChanged`:** ditambahkan pengecekan baru — jika `$newStatus === 'absent'` dan baris lama punya `check_in_time`/`check_out_time` non-null, masuk daftar `dangerousChanged` dengan label `'jam masuk/pulang dihapus (status menjadi Alpha)'`, sehingga gerbang konfirmasi SIMPAN tetap menembak dengan pesan yang akurat (bukan hanya karena "status kehadiran" berubah).
- **Verifikasi:**
  - `tests/Feature/AttendanceCorrectionTest.php` — 2 test regresi baru: (a) `test_ubah_status_ke_alpha_menghapus_jam_masuk_dan_pulang_lama` — reproduksi skenario bug persis (form mengirim jam lama yang sama + status absent) → jam masuk, jam pulang, dan tipe presensi semuanya `null` setelah disimpan; (b) `test_ubah_status_ke_alpha_tanpa_konfirmasi_ditolak_dengan_alasan_jam_terhapus` — tanpa `confirm_dangerous`, request ditolak 422 dengan `dangerous_changed_fields` memuat alasan penghapusan jam, dan baris lama tidak berubah sama sekali.
  - `php artisan test --filter=AttendanceCorrectionTest` → **46/46 lolos** (206 assertions, 44 test lama tidak ada yang pecah).
  - `php artisan test --filter=Attendance` (suite penuh) → **149/149 lolos** (764 assertions).
  - `php -l` bersih pada `AttendanceController.php` dan `AttendanceCorrectionTest.php`.

---

#### 23. Guard Baru: Pengajuan Cuti/Izin/Sakit `pending` Otomatis Ditolak Saat Karyawan Check-in di Hari H — ✅ SELESAI 2026-10-07
- **Lokasi Kode:** `LeaveRequest::autoRejectPendingLeavesOnCheckIn()` (baru, `app/Models/LeaveRequest.php`) & `AttendanceController::checkIn()` (~setelah `Attendance::updateOrCreate()`).
- **Latar Belakang / Permintaan:**
  - Sebelumnya hanya ada satu mekanisme auto-reject: sweep terjadwal `LeaveRequest::autoRejectExpiredLeaves()` yang menolak pengajuan `pending` begitu lewat **H+1** (`start_date < hari ini`) — murni berbasis waktu, bukan berbasis bukti kehadiran.
  - Permintaan: tambahkan guard yang **lebih cepat** — begitu karyawan terbukti **check-in presensi** pada tanggal yang tercakup pengajuan cuti/izin/sakit miliknya yang masih `pending`, pengajuan itu otomatis ditolak saat itu juga, tidak perlu menunggu H+1.
  - Guard baru ini bersifat **tambahan**, bukan pengganti: sweep H+1 tetap dipertahankan sebagai jaring pengaman untuk kasus karyawan yang memang tidak pernah check-in sama sekali (mis. benar-benar tidak masuk tanpa approval).
- **Mengapa Aman Dipasang di `checkIn()`:**
  - `checkIn()` sudah punya guard lama (~line 7863) yang **memblokir total** proses check-in jika ada pengajuan cuti/sakit/izin berstatus **`approved`** untuk hari ini. Jadi begitu kode mencapai titik pembuatan baris `attendances`, satu-satunya pengajuan yang mungkin masih "nyangkut" untuk tanggal tersebut sudah pasti **bukan** `approved` (hanya `pending` atau sudah `rejected`) — aman untuk langsung di-reject tanpa menabrak kasus yang sudah sah disetujui.
- **Implementasi:**
  1. Method baru `LeaveRequest::autoRejectPendingLeavesOnCheckIn(int $userId, string $date): int` — mencari pengajuan `status='pending'`, `leave_type` IN `['cuti','izin','sakit']`, milik user tersebut, dengan rentang `start_date <= $date <= end_date`. Untuk setiap baris yang cocok: set `status='rejected'`, `rejection_reason` baru yang membedakan pemicunya dari sweep H+1 ("...terdeteksi melakukan presensi check-in pada tanggal pengajuan..."), `collective_status='declined'` jika cuti bersama (`holiday_id` tidak null), hapus notifikasi `leave_requested` pending milik approver, lalu kirim notifikasi `personal_leave_cancelled` + push FCM ke karyawan — meniru persis pola yang sudah dipakai `autoRejectExpiredLeaves()` agar konsisten.
  2. `leave_type = 'wfh'` **sengaja dikecualikan** — WFH adalah pengajuan *mode kerja* (dari rumah), bukan klaim tidak hadir, jadi check-in (termasuk check-in bertipe `wfh` itu sendiri) tidak membatalkannya. Ini konsisten dengan pengecualian yang sama di guard lama "Cegah presensi jika user sedang cuti/sakit/izin" (~line 7862).
  3. Dipanggil dari `checkIn()` tepat setelah `Attendance::updateOrCreate()` berhasil, dibungkus `try/catch` + `Log::warning` — kegagalan di sisi ini (mis. gagal kirim FCM) tidak boleh menggagalkan proses check-in karyawan yang sudah berhasil.
  4. Pengajuan multi-hari: karena satu baris `leave_requests` mencakup satu rentang `start_date..end_date` (bukan per-hari), check-in di **salah satu** hari dalam rentang tersebut menolak **seluruh** pengajuan — selaras dengan cara sweep H+1 bekerja (satu baris = satu keputusan).
- **Verifikasi:**
  - Test baru `tests/Feature/LeaveRejectedOnCheckInTest.php` (5 test): (a) pengajuan `izin` 1 hari otomatis `rejected` saat check-in di hari H, notifikasi approver terhapus & notifikasi karyawan terkirim; (b) pengajuan `cuti` 3 hari ikut `rejected` walau check-in hanya di hari ke-2; (c) pengajuan `wfh` **tetap `pending`** saat check-in (tidak ikut ter-reject, sesuai pengecualian); (d) pengajuan untuk tanggal lain tidak terpengaruh; (e) pengajuan yang sudah `rejected` manual sebelumnya tidak ditimpa ulang.
  - `php artisan test --filter=LeaveRejectedOnCheckInTest` → **5/5 lolos** (14 assertions).
  - `php artisan test --filter=Leave` (suite penuh pengajuan cuti) → **85/88 lolos**; 3 gagal pra-eksisting di `LeaveMultiApprovalWorkflowTest` (dikonfirmasi gagal juga di `git stash` sebelum perubahan ini — tidak terkait).
  - `php artisan test --filter=Attendance` (suite penuh presensi) → **149/149 lolos** (764 assertions), tidak ada regresi pada `checkIn()`.
  - `php artisan test` (seluruh suite backend) → **741/744 lolos** (4334 assertions); 3 gagal sama persis dengan yang pra-eksisting di atas.
  - `php -l` bersih pada `LeaveRequest.php`, `AttendanceController.php`, dan `LeaveRejectedOnCheckInTest.php`.

#### 24. Guard Baru: Role "Khusus Mobile" Otomatis Mengunci Modul Non-Cuti/Lembur di Create/Edit Role — ✅ SELESAI 2026-10-07
- **Lokasi Kode:** `Role::MOBILE_ONLY_ALLOWED_MODULES` (baru, `app/Models/Role.php`) & `RoleController::sanitizePermissionsForMobileOnly()` (baru, dipanggil dari `store()` & `update()`, `app/Http/Controllers/API/RoleController.php`); frontend `RoleFormModal.tsx` (`MOBILE_ONLY_ALLOWED_MODULES`, `sanitizePermissionsForMobileOnly()`, badge "Khusus Mobile: Terkunci").
- **Latar Belakang / Permintaan:**
  - Permintaan: pada form Create/Edit Role di web, jika platform dipilih **"Khusus Mobile"** di Tahap 1, maka di Tahap 3 (matriks izin) modul Finance otomatis off, dan di modul HRD & Kepegawaian hanya **Persetujuan Lembur** yang aktif secara default — modul lain tidak bisa di-manage lewat web, karena hanya **Persetujuan Cuti** & **Persetujuan Lembur** yang punya alur approval lewat aplikasi mobile.
  - Dua keputusan diklarifikasi ke user sebelum implementasi: (1) cakupan kunci — dipilih **HRD + Pengaturan & Sistem** (grup Pengaturan Aturan, Manajemen Role, Audit Log juga ikut dikunci ke `none`, bukan cuma sub-modul HRD); (2) lapisan penegakan — dipilih **UI + Backend** (defense-in-depth, konsisten dengan pola di item #22/#23).
  - Kedua jawaban itu disederhanakan menjadi satu desain **allow-list**: hanya modul `leave` & `overtime` yang boleh dikonfigurasi untuk `platform='mobile_only'`; seluruh modul lain (termasuk `payroll` yang bahkan tidak muncul di `MODULE_GROUPS` frontend) otomatis dipaksa `none`.
- **Implementasi:**
  1. Backend: konstanta `Role::MOBILE_ONLY_ALLOWED_MODULES = [MODULE_LEAVE, MODULE_OVERTIME]` + method privat `RoleController::sanitizePermissionsForMobileOnly(array $permissions): array` yang memaksa semua modul di luar allow-list ke `'none'`, dan men-default `overtime` ke `'manage'` jika hasilnya `'none'` (agar "Persetujuan Lembur" aktif secara default sesuai permintaan).
  2. Dipanggil di `store()` tepat setelah validasi (sebelum cek dependensi `role_management`↔`settings`), dan di `update()` dengan **fallback ke permission yang sudah tersimpan di DB** (`$role->permissions()->pluck('access_level','module')->all()`) jika request tidak mengirim ulang `permissions` sama sekali — mencegah guard ini menghapus konfigurasi `leave`/`overtime` yang sudah ada saat admin hanya mengubah field lain (mis. ganti platform ke `mobile_only` tanpa mengirim ulang matriks izin).
  3. Frontend: `sanitizePermissionsForMobileOnly()` dipanggil saat memuat role untuk edit (`useEffect`), saat klik kartu platform "Khusus Mobile", di setiap aksi massal (`setAllPermissions`/`setGroupPermissions`), dan sekali lagi tepat sebelum submit sebagai jaring pengaman terakhir. `handlePermissionChange` juga memblokir percobaan mengaktifkan modul terkunci via early-return guard.
  4. UI Tahap 3: setiap modul di luar allow-list saat `platform==='mobile_only'` mendapat badge sky "Khusus Mobile: Terkunci" + penjelasan, dan seluruh tombol kontrolnya (`disabled`) di ketiga varian UI (tri-state standar, 5-tombol overtime, segmented control receipt) — termasuk tombol "None" yang sebelumnya tidak pernah di-disable.
- **Verifikasi:**
  - Test baru `tests/Feature/RoleMobileOnlyPermissionGuardTest.php` (5 test): role mobile-only dengan payload mencoba mengaktifkan Finance/HRD/Settings/Role Management → semua dipaksa `none`, `leave`/`overtime` tetap sesuai kiriman; `overtime` otomatis `manage` saat dikirim `none`/tidak dikirim; role `platform='both'` tidak terpengaruh; update role existing ke `mobile_only` **tanpa** mengirim ulang `permissions` tetap men-sanitize data lama di DB (bukan mengosongkannya); update dengan payload baru tetap konsisten disanitasi.
  - `php artisan test --filter=RoleMobileOnlyPermissionGuardTest` → **5/5 lolos** (25 assertions).
  - `php artisan test --filter=Role` (seluruh suite role, termasuk `RoleManagementTest` yang disesuaikan 1 test-nya dari `platform='mobile_only'` ke `'both'` karena test itu sengaja menguji format array-of-objects dengan modul `attendance`, yang sekarang memang harus `none` untuk mobile-only) → **55/55 lolos** (248 assertions).
  - `php artisan test` (seluruh suite backend) → **746/749 lolos** (4359 assertions); 3 gagal sama persis dengan pra-eksisting di `LeaveMultiApprovalWorkflowTest` (dikonfirmasi ulang via `git stash` — tidak terkait sama sekali dengan perubahan role).
  - `php -l` bersih pada `Role.php`, `RoleController.php`, `RoleMobileOnlyPermissionGuardTest.php`; `npx tsc --noEmit` bersih (tanpa error) pada `RoleFormModal.tsx`.
  - UI tidak diverifikasi langsung di browser (tidak ada alat otomasi browser tersedia di sesi ini) — verifikasi mengandalkan type-check TypeScript bersih + review manual logika lock.

#### 25. Dua Bug Kecil pada `RoleController` Ditemukan Saat Mengerjakan Item #24 — ✅ SELESAI 2026-10-07
- **Lokasi Kode:** `RoleController::destroy()`, `RoleController::store()`, `RoleController::update()`, helper baru `RoleController::validModulesKeysRule()` — `app/Http/Controllers/API/RoleController.php`.
- **Latar Belakang:** ditemukan saat review kode untuk item #24 (guard role khusus mobile), bukan laporan bug dari user — dua kekurangan kecil pada controller yang sama:
  1. **Audit log penghapusan role tidak lengkap:** `destroy()` memanggil `AuditLogger::log()` hanya dengan `entityId`, tanpa `oldValues` — berbeda dengan `store()`/`update()` yang selalu menyertakan snapshot penuh. Akibatnya, begitu custom role dihapus, riwayat *modul & level akses apa saja yang pernah dimiliki role itu* serta *cabang mana yang pernah diaturnya* hilang permanen dari audit trail, meski role lain tetap tercatat lengkap.
  2. **Key permission yang tidak dikenal diam-diam diabaikan:** validasi `'permissions.*' => Rule::in([...])` hanya mengecek *value*, tidak mengecek apakah *key* (nama modul) itu benar-benar ada di `Role::AVAILABLE_MODULES`. Payload dengan key typo (mis. `'attendence'` alih-alih `'attendance'`) tetap lolos validasi 422, lalu modul "hantu" itu cuma di-skip tanpa pesan error di loop penyimpanan `store()`/`update()` — berpotensi menyembunyikan bug pengiriman payload (typo frontend atau API caller lain) tanpa jejak apa pun bagi admin.
- **Implementasi:**
  1. Sebelum `DB::transaction()` di `destroy()`, tangkap `$oldValues = $role->load(['permissions', 'branches'])->toArray()` lalu teruskan sebagai parameter `oldValues` ke `AuditLogger::log()` — konsisten dengan pola snapshot di `store()`/`update()`.
  2. Tambah helper privat `validModulesKeysRule(array $availableModules): \Closure` yang mengembalikan validation rule closure: `array_diff(array_keys($value), $availableModules)` — jika ada key asing, `$fail()` dengan pesan "Modul izin tidak dikenal: ...". Dipasang sebagai rule ketiga pada field `'permissions'` di `store()` maupun `update()` (setelah `'nullable'`, `'array'`), sehingga payload dengan key modul tidak valid langsung ditolak 422 alih-alih diam-diam di-drop.
  3. Karena `normalizePermissions()` sudah dipanggil sebelum `$request->validate()`, format array-of-objects (`[['module' => ..., 'access_level' => ...]]`) juga otomatis tervalidasi dengan benar — rule ini bekerja di atas bentuk map yang sudah dinormalisasi.
- **Verifikasi:**
  - Test baru `tests/Feature/RoleAuditAndValidationGuardTest.php` (4 test): (a) hapus role mencatat `old_values` lengkap (nama, daftar permission per modul, daftar cabang) di tabel `activity_logs`; (b) create role dengan key modul typo → 422 + `permissions` validation error, role tidak tersimpan; (c) update role dengan key modul tidak dikenal → 422; (d) create role dengan key modul valid tetap berhasil 201 (regression check).
  - `php artisan test --filter=RoleAuditAndValidationGuardTest` → **4/4 lolos** (15 assertions).
  - `php artisan test --filter=Role` (seluruh suite role) → **59/59 lolos** (263 assertions) — tidak ada regresi pada test lama (termasuk yang pakai format array-of-objects & payload permissions lain).
  - `php artisan test` (seluruh suite backend) → **750/753 lolos** (4374 assertions); 3 gagal sama persis dengan pra-eksisting di `LeaveMultiApprovalWorkflowTest` (tidak terkait).
  - `php -l` bersih pada `RoleController.php` dan `RoleAuditAndValidationGuardTest.php`.

#### 26. Fitur Baru: "Persetujuan Cuti" Lv 1 (SPV) & Lv 2 (HRD) di Matriks Izin Create/Edit Role, Disamakan dengan "Persetujuan Lembur" — ✅ SELESAI 2026-10-07
- **Lokasi Kode:** `RoleFormModal.tsx` (Tahap 3 — info dinamis per level & segmented control 5-tombol untuk `mod.key === 'leave'`, `setAllPermissions()`); `RoleController::modules()` (`app/Http/Controllers/API/RoleController.php`, deskripsi `access_levels` untuk `spv`/`hrd`); test baru `RoleManagementTest::test_can_create_role_with_leave_approval_spv_and_hrd_levels_like_overtime`.
- **Latar Belakang / Permintaan:** User: *"pada edit role dan create role tahap ke 3 tolong buatkan persetujuan cuti lv1 dan lv 2 sama seperti persetujuan lembur"*. Investigasi menunjukkan backend **sudah generik** mendukung level `spv`/`hrd` untuk modul apa pun lewat `Role::hasPermission()` (tidak ada percabangan khusus `MODULE_OVERTIME` vs `MODULE_LEAVE`), dan alur approval cuti bertingkat (SPV → HRD) di `AttendanceController`, `RoleMiddleware`, `User::hasPermission()` **sudah memakai mekanisme generik ini** untuk `MODULE_LEAVE`. Jadi gap-nya murni di frontend: Tahap 3 `RoleFormModal.tsx` hanya memberi modul `overtime` kontrol 5-tombol (None/Read/Lv1 SPV/Lv2 HRD/Penuh); modul `leave` masih memakai kontrol tri-state standar (None/Read/Manage) sehingga admin tidak bisa memilih level SPV atau HRD khusus untuk cuti dari web.
- **Implementasi:**
  1. Tambah blok keterangan dinamis khusus `mod.key === 'leave'` (mirip blok `overtime` yang sudah ada) — teks berbeda per level (`none`/`read`/`spv`: Approver Tahap 1 SPV/`hrd`: Approver Tahap 2 Final HRD + kelola kuota saldo cuti/`manage`: wewenang penuh Lv1 & 2).
  2. Tambah cabang ternary baru `mod.key === 'leave' ? (...)` pada render kontrol Tahap 3 — segmented control 5-tombol (None/Read/Lv 1: SPV/Lv 2: HRD/Lv 1 & 2: Penuh), identik strukturnya dengan kontrol `overtime` (tanpa `disabled`, karena `leave` sudah ada di `MOBILE_ONLY_ALLOWED_MODULES` sehingga `isLocked` selalu `false` untuk modul ini — sama seperti `overtime`).
  3. `setAllPermissions()`: perluas kondisi skip bulk-set spv/hrd dari `m.key === 'overtime'` menjadi `(m.key === 'overtime' || m.key === 'leave')` agar tombol "Semua Read/Manage/None" tidak pernah memaksa cuti ke level spv/hrd yang tidak relevan secara global (konsisten dengan perilaku overtime). `setGroupPermissions()` tidak disentuh karena tombol aksi grup di UI hanya pernah memanggilnya dengan `'read'/'manage'/'none'`, tidak pernah `'spv'/'hrd'` — jadi skip-logic ini secara praktik tidak pernah teraktivasi lewat UI yang ada, baik untuk `overtime` maupun `leave` (inconsistency lama dibiarkan apa adanya, di luar scope permintaan).
  4. Backend: generalisasi teks deskripsi `access_levels` `spv`/`hrd` pada `RoleController::modules()` dari *"Khusus persetujuan lembur tahap 1/2..."* menjadi *"Khusus persetujuan cuti/lembur tahap 1/2..."* agar akurat merepresentasikan kedua modul yang sekarang memakai level ini. Tidak ada perubahan logika backend lain — validasi `'permissions.*' => Rule::in(['none','read','manage','spv','hrd','finance'])` di `store()`/`update()` sudah mengizinkan `spv`/`hrd` untuk modul apa pun sejak awal.
- **Verifikasi:**
  - Test baru `RoleManagementTest::test_can_create_role_with_leave_approval_spv_and_hrd_levels_like_overtime`: create role dengan `leave: 'spv'` → tersimpan & `Role::hasPermission(MODULE_LEAVE, 'spv')` true, `'hrd'` false; update role yang sama ke `leave: 'hrd'` → tersimpan & `hasPermission('hrd')` true, `'spv'` false.
  - `php artisan test --filter=RoleManagementTest` → **10/10 lolos** (57 assertions).
  - `php artisan test --filter=Role` (seluruh suite role) → **60/60 lolos** (271 assertions) — tidak ada regresi.
  - `php artisan test` (seluruh suite backend) → **751/754 lolos** (4382 assertions); 3 gagal sama persis dengan pra-eksisting di `LeaveMultiApprovalWorkflowTest` (tidak terkait, dikonfirmasi berulang kali di item #24/#25).
  - `php -l` bersih pada `RoleController.php`; `npx tsc --noEmit` bersih (tanpa error) pada `RoleFormModal.tsx`.
  - UI tidak diverifikasi langsung di browser (tidak ada alat otomasi browser tersedia di sesi ini) — verifikasi mengandalkan type-check TypeScript bersih + review manual struktur JSX yang mem-mirror pola `overtime` yang sudah terverifikasi sebelumnya.

#### 27. Fitur Baru: Pengaturan Tanggal Gajian & Cut-off Presensi yang Fleksibel (Backend Selesai, Frontend Belum) — ⏳ BACKEND SELESAI 2026-10-09
- **Lokasi Kode:**
  - **Baru:** `app/Services/Payroll/PayrollPeriodResolver.php`; migrasi `2026_10_12_000001_add_cutoff_and_payment_day_to_payroll_groups_table.php` & `2026_10_12_000002_add_period_range_to_payrolls_table.php`; test `tests/Unit/PayrollPeriodResolverTest.php` & `tests/Feature/PayrollCutoffPeriodTest.php`.
  - **Diubah:** `app/Models/PayrollGroup.php`, `app/Models/Payroll.php` (helper baru `periodRange()`); `app/Http/Controllers/API/PayrollGroupController.php` (`store()`/`update()`), `app/Http/Controllers/API/PayrollController.php` (helper baru `periodDateRules()`/`scopePeriodQuery()`/`buildPeriodDates()`, method baru `update()` & `periodPreview()`, `store()` diperluas); `app/Services/Payroll/PayrollCalculator.php` (pemisahan `$periodStart`/`$periodEnd` vs `$onDate`, tanda tangan `overtimePay()`); `app/Services/Attendance/PayrollPeriodLock.php` (`findLocking()` ikut rentang + `lockedRanges()` baru); `app/Http/Controllers/API/AttendanceController.php` (kunci baru `locked_ranges` di `reportAttendance()`); `routes/api.php` (2 route baru); komentar penegasan di `app/Services/Payroll/Thr/ThrCalculatorService.php` & `app/Services/Payroll/Severance/SeveranceCalculatorService.php`.
- **Latar Belakang / Permintaan:** User: *"Tolong buatkan fitur pengaturan tanggal gajian dan tanggal tutup buku (cut-off) presensi di menu Payroll agar fleksibel untuk semua tipe kantor. ... Di Pengaturan Grup Payroll: Tambahkan input untuk menentukan tanggal gajian (misal: tanggal 25) dan tanggal cut-off presensi (misal: tanggal 20). Di Modal "Buat Periode Payroll": Tampilkan tanggal mulai s/d selesai cut-off presensi dan rencana tanggal gajian. Tanggal ini otomatis terisi mengikuti pengaturan grup di atas, tapi tetap bisa diedit manual oleh HRD jika sewaktu-waktu ada libur panjang atau gajian ingin dimajukan. Di Perhitungan Gaji: Pastikan data kehadiran, denda telat, alpha, dan jam lembur ditarik sesuai rentang tanggal cut-off yang dipilih di periode tersebut (jangan lagi dikunci kaku tanggal 1 sampai akhir bulan). buatkan backend-nya dahulu secara lengkap, serta pastikan tidak ada error dan bug saat dijalankan dan pastikan juga buatkan guard atau validasi yang sesuai."* — Sebelum fitur ini, tabel `payrolls` **tidak punya satu pun kolom tanggal** (hanya `period_month` + `period_year`) dan `PayrollCalculator::calculate()` menurunkan rentangnya sendiri dengan `Carbon::create($year,$month,1)` lalu `endOfMonth()`. `payroll_groups` juga belum punya pengaturan tanggal apa pun. Konsep `cutoff_day`/`payment_day` sudah dirancang di `doc/07-PAYROLL-ROADMAP.md:509-524` tapi belum pernah diimplementasikan (grep di `app/` + `database/migrations/` → nol hit).
- **Keputusan produk (dikonfirmasi user):** (1) bulan gajian bisa diatur lewat `payment_month_offset` (0 = bulan periode, 1 = bulan berikutnya); (2) batch **tanpa grup payroll jatuh ke bulan kalender penuh** — nol regresi perilaku lama; (3) `PayrollPeriodLock` ikut rentang cut-off, bukan lagi bulan kalender; (4) rentang tumpang-tindih **ditolak 422**, celah antar periode **hanya diperingatkan**.
- **Prinsip pemisahan (paling kritis, jangan dilanggar):** **agregat presensi** mengikuti rentang cut-off; **uang statutori & penanggalan efektif** tetap di bulan kalender. Ikut cut-off: hari hadir/alpha, denda telat, pulang cepat, lupa check-out, premi shift malam, insentif kehadiran penuh, cuti/izin, jam lembur, reimbursement struk, hari kerja efektif, libur resmi. **Tidak** ikut cut-off: `$onDate` untuk `activeSalaryOn`, kurs valas, komponen tetap & formula (`effective_date`/`end_date`), BPJS, PPh21 TER & rekonsiliasi Pasal 17 akhir tahun, `employee_tax_period_totals.tax_month`, akumulasi tahun sebelumnya, cicilan kasbon, `is_year_end`, `period_label`, GL `sprintf('%04d-%02d')`, THR & pesangon.
- **Implementasi:**
  1. **Migrasi `payroll_groups`:** `cutoff_day` & `payment_day` (`unsignedTinyInteger` nullable) + `payment_month_offset` (`unsignedTinyInteger` NOT NULL default 0). Konvensi nilai hari: `NULL` = belum diatur → bulan kalender penuh; `0` = akhir bulan; `1-31` = tanggal tetap (di-clamp ke panjang bulan). **Menyimpang dari roadmap secara sadar:** roadmap menulis `cutoff_day TINYINT NOT NULL DEFAULT 25`; default NOT NULL akan diam-diam menggeser jendela presensi semua grup yang sudah ada. Dipakai nullable agar grup lama tidak berubah tanpa persetujuan HRD (alasan ditulis di docblock migrasi).
  2. **Migrasi `payrolls`:** `period_start_date`, `period_end_date`, `planned_payment_date` (`date` nullable) + index `['company_id','period_start_date','period_end_date']`. `up()` melakukan **backfill** semua baris lama ke bulan kalendernya (tanggal 1 s/d akhir bulan) secara bertahap (chunk 500) sehingga kueri tumpang-tindih & `PayrollPeriodLock` tidak perlu `COALESCE` di SQL. Nama kolom mengikuti preseden `leave_balance_histories.period_start`/`period_end`.
  3. **`PayrollPeriodResolver`** — satu-satunya tempat aturan "hari → tanggal" diterjemahkan (dipakai `store()`, `update()`, endpoint pratinjau, dan test unit, agar tidak ada derivasi liar). `resolveRange()`: cut-off `d` → `(d+1 bulan lalu) s/d (d bulan ini)`, clamping wajib di **kedua** ujung (cut-off 31 di Februari berakhir 28/29, dan mulai 1 Maret bukan "32 Februari"); `resolvePaymentDate()` dengan `addMonthsNoOverflow($offset)`; `resolveForGroup()` mengembalikan `source: 'group'|'calendar'` agar modal bisa menjelaskan asal angkanya ke HRD.
  4. **`Payroll::periodRange()`** — helper tunggal pembaca rentang: mengembalikan kolom tersimpan, atau jatuh ke bulan kalender bila NULL. Ini pengaman untuk `PayrollDummyDataSeeder` yang membuat batch langsung tanpa lewat controller. Semua pembaca rentang **wajib** lewat helper ini.
  5. **`PayrollGroupController`** — tiga rule baru identik di `store()` & `update()` (`nullable|integer|min:0|max:31`, offset `max:1`). Pada `update()` dipakai `array_key_exists()` alih-alih `?? null`: form grup lama (frontend belum diperbarui) tidak mengirim ketiga kolom itu, dan `?? null` akan **diam-diam menghapus** pengaturan cut-off HRD setiap kali nama grup disunting.
  6. **`PayrollController::store()`** — auto-fill dari grup, lalu override manual HRD menimpa. Lima guard 422 berbahasa Indonesia di `buildPeriodDates()`: (a) `period_start_date`/`period_end_date` wajib **berpasangan** (mencegah campuran senyap auto + manual); (b) `end >= start`; (c) panjang rentang 1-62 hari; (d) `planned_payment_date < period_start_date` → 422, sedangkan lebih awal dari `period_end_date` **hanya peringatan** (gajian dimajukan adalah permintaan sah HRD); (e) **tumpang-tindih** pada lingkup sama (`company_id` + `run_type` + `attendance_setting_id` + `payroll_group_id`, NULL-aware) → 422 yang **menyebut id + `period_label` + rentang + status batch penghalang** agar HRD tahu mana yang harus dihapus. Celah antar periode → 201 dengan `warnings[]`. Guard duplikat bulan/lingkup yang sudah ada **tetap dipertahankan** (menjaga identitas `period_label` & unique index) — guard baru bersifat menambah. Ketiga tanggal ikut masuk payload `payrollChainLog()` sehingga terikat rantai hash SHA-256.
  7. **`PayrollController::update()` + `PUT /dashboard/payroll/runs/{payroll}`** (route baru) — sebelumnya **tidak ada endpoint update batch sama sekali**. Hanya `period_start_date`/`period_end_date`/`planned_payment_date`/`notes` yang boleh diubah; `period_month`/`period_year`/`run_type`/scope tidak (itu identitas batch & kunci pajak). Status yang diizinkan `draft`/`calculated`/`rejected` (meniru `destroy()`); `approved`/`paid` → 422. **Pengaman terpenting:** bila status `calculated` **dan rentang benar-benar berubah**, status dikembalikan ke `draft` + `calculated_at = null` — tanpa ini slip yang dihitung memakai rentang lama bisa langsung di-`submit()` (yang hanya mensyaratkan `calculated`), menerbitkan gaji dari jendela presensi yang sudah tidak berlaku. Payslip **tidak** dihapus (mungkin sedang ditinjau); `calculate()` sudah idempoten. Audit `PAYROLL_RUN_PERIOD_UPDATED` + `payrollChainLog()`.
  8. **`GET /dashboard/payroll/period-preview`** (route baru, gate `read`) — `?period_month=&period_year=&payroll_group_id=` → `{ period_start_date, period_end_date, planned_payment_date, source }`. Tanpa ini aturan resolusi harus diduplikasi di TypeScript dan akan menyimpang dari backend.
  9. **`PayrollCalculator`** — `[$periodStart, $periodEnd] = $payroll->periodRange()` menggantikan derivasi `Carbon::create(...)->endOfMonth()`, sementara `$onDate`/`$onDateCarbon` tetap akhir bulan **kalender** untuk `activeSalaryOn()`, `fixedComponents()`, komponen formula, dan `tenureMonths()`. Tanda tangan `overtimePay()` diubah dari `(User, int $year, int $month, float)` menjadi `(User, Carbon $periodStart, Carbon $periodEnd, float)` — itu derivasi bulan **kedua yang independen** dan satu-satunya yang tidak menerima rentang; `$year`/`$month` kini tidak masuk method itu sama sekali agar derivasi liar tidak muncul kembali. Helper `attendanceDays()`, `penaltyBonusAggregates()`, `approvedLeaveDays()`, `periodWorkingDays()`, `approvedReimbursements()` **sudah** menerima rentang sebagai parameter sehingga otomatis ikut. Pembagi prorata (`working_days_divisor`, default 21) sengaja **tidak** diubah.
  10. **`PayrollPeriodLock`** — bahaya korektness nyata yang diperbaiki: dengan jendela 21 Sep-20 Okt, presensi 25 September **dibayar batch Oktober**, tetapi pencocokan `period_year`+`period_month` mengaitkannya ke batch September sehingga tanggal yang slipnya sudah terbit tetap bisa dikoreksi tanpa terdeteksi. `findLocking()` kini mencocokkan rentang, dengan fallback pencocokan bulan untuk baris tanpa kolom rentang (seeder/data lama). `lockedPeriods()` (`"YYYY-MM"`) **dipertahankan apa adanya untuk kompatibilitas** tapi dihitung dari rentang: hanya bulan **terkunci penuh** yang masuk (dua batch berurutan digabung dulu agar bisa menutup satu bulan penuh). Under-report ini mode gagal yang **aman** dan persis sama dengan perilaku pra-fitur — server tetap menolak keras lewat `findLocking()`. Ditambah **`lockedRanges()`** yang mengembalikan jendela nyata per batch (`{start, end, payroll_id, status, period_label}`), diekspos sebagai kunci baru `locked_ranges` di `reportAttendance()` untuk penonaktifan tombol koreksi presisi per-hari.
  11. **THR & pesangon sengaja tidak disentuh** — `ThrCalculatorService` & `SeveranceCalculatorService` menghitung `endOfMonth()` tetapi **tidak membaca presensi sama sekali**; tanggal itu hanya untuk `activeSalaryOn()` & `monthsOfService()`, yaitu penanggalan efektif yang memang harus bulan kalender. Komentar penegasan ditambahkan di kedua tempat agar tidak "diperbaiki" orang lain menjadi rentang cut-off (itu akan menggeser gaji pokok & masa kerja THR).
- **Tugas Frontend (SELESAI):**
  1. `PayrollManagement.tsx` (`GroupFormModal` & `GrupPayrollPanel`) — input & tampilan: **tanggal cut-off presensi** (1-31, akhir bulan `0`, atau kosong/kalender), **tanggal gajian** (1-31 atau `0`), dan **pilihan bulan gajian** (offset 0 = bulan berjalan / 1 = bulan berikutnya). Disertai 4 tombol preset cepat: Standar (Cut 20, Bayar 25), Akhir Bulan (Cut 25, Bayar Akhir Bulan), Awal Bulan (Cut 25, Bayar Tgl 1 bln+1), dan Bulan Kalender Penuh, plus simulasi teks interaktif. Nilai tersimpan ditampilkan kembali saat edit dan dikirim utuh ke backend.
  2. `PayrollManagement.tsx` modal **"Buat Periode Payroll"** (`CreateRunModal`) — menampilkan jadwal cut-off presensi & rencana tanggal gajian, auto-fill dari `GET /dashboard/payroll/period-preview?period_month=&period_year=&payroll_group_id=` setiap kali bulan/tahun/grup berubah dengan indikator badge sumber (`'group'` = Sesuai Aturan Grup, `'calendar'` = Bulan Kalender Penuh), input tetap dapat diedit manual, serta validasi berpasangan (start & end wajib diisi bersamaan).
  3. `Banner` warning non-blocking & penanganan error 422: respons 201 dengan `warnings[]` (celah antar periode atau gajian dimajukan) ditampilkan sebagai banner peringatan non-blocking di detail batch, dan error 422 tumpang-tindih menampilkan pesan informatif backend yang menyebut id, label periode, rentang, dan status batch penghalang.
  4. Modal **"Ubah Periode Cut-Off & Rencana Gajian"** (`EditBatchPeriodModal`) untuk status `draft`/`calculated`/`rejected` (`PUT /dashboard/payroll/runs/{id}`) — menampilkan peringatan tegas bahwa batch `calculated` akan kembali ke status DRAFT dan perlu kalkulasi ulang jika rentang cut-off berubah. Tombol otomatis disembunyikan untuk status `approved`/`paid`.
  5. Tampilan rentang cut-off & rencana tanggal gajian di **daftar kartu batch** (`ProsesPayrollTab`) dan **detail batch payroll** (`RunDetailView` header) dengan badge tanggal yang informatif.
  6. Laporan presensi (`AttendanceManagement.tsx`): menggunakan kunci baru **`locked_ranges`** (presisi per-hari) menggantikan ketergantungan pada `locked_periods` (bulan penuh) dengan tooltip informatif berisi batch ID, label periode, dan status penguncian, serta fallback aman ke `locked_periods`.
  7. `npx tsc --noEmit` lolos bersih (exit code 0, nol error).
- **Verifikasi:**
  - Test unit baru `tests/Unit/PayrollPeriodResolverTest.php` → **19/19 lolos** (170 assertions): cut-off NULL & `0` → bulan kalender; `20` pada Okt 2026 → `2026-09-21` s/d `2026-10-20`; Januari menyeberang tahun → `2025-12-21`; clamping `31` di Februari (ujung akhir) & di Maret (ujung mulai); tahun kabisat 29 Februari; `payment_day` offset 0/1 termasuk lintas tahun & clamping; serta dua invariant penting — cut-off `1` tetap jendela penuh satu bulan (2 Sep s/d 1 Okt, **bukan** satu hari) dan **periode berurutan bersambung tanpa celah maupun tumpang-tindih** (`prevEnd + 1 hari === thisStart`) untuk cut-off 1/15/20/31, sehingga tidak ada hari presensi yang terbayar dua kali atau terlewat.
  - Test feature baru `tests/Feature/PayrollCutoffPeriodTest.php` → **36/36 lolos** (135 assertions): penyimpanan & validasi grup (`32` → 422, offset `2` → 422, update tanpa kirim tanggal **tidak menghapus** pengaturan, null eksplisit tetap bisa mengosongkan); batch tanpa grup → `2026-10-01` s/d `2026-10-31` + `source: 'calendar'` (regresi perilaku lama); batch dengan grup cut-off 20 → `2026-09-21` s/d `2026-10-20` + `planned_payment_date: 2026-10-25` + `source: 'group'`; offset 1 → gajian `2026-11-05`; override manual diterima; override separuh → 422; `end < start` → 422; rentang 123 hari → 422; gajian sebelum rentang mulai → 422; gajian dimajukan → 201 + `warnings`; tumpang-tindih → 422 menyebut id batch penghalang; rentang sama pada grup berbeda → 201; celah → 201 + `warnings` memuat kata "celah"; periode berurutan → `warnings: []`; endpoint pratinjau (grup, bulan kalender, karyawan biasa → 403); **inti permintaan** — presensi disemai di 9 tanggal (19 & 20 Sep di luar, 22/25/28 Sep & 5 Okt di dalam, 22/25/28 Okt di luar) dengan denda telat & lembur approved berbeda, batch cut-off 21 Sep-20 Okt menghasilkan tepat 3 hari hadir, 1 alpha, 2 jam lembur, dan denda telat 30.000 — plus **test cermin** memakai batch bulan kalender yang menghasilkan 2 hari hadir & denda 90.000, sehingga terbukti rentangnya benar-benar dibaca dari batch dan bukan konstanta yang kebetulan cocok; **pajak tidak bergeser** (`payslips.period_month` = 10, `employee_tax_period_totals.tax_month` = 10 dan **tidak ada** baris masa pajak September walau jendelanya menyeberang); `PUT /runs/{id}` (`draft` → 200; `calculated` + rentang berubah → 200 + status balik `draft` + `calculated_at` null + pesan "dihitung ulang"; `calculated` + hanya rencana gajian berubah → tetap `calculated`; `approved` → 422 & rentang tidak bergerak; karyawan biasa → 403; batch perusahaan lain → 403 oleh `CompanyMiddleware` sesuai preseden `PayrollPhase4Test:217-221`, guard 404 di controller = pertahanan berlapis; tumpang-tindih di `update()` → 422 sambil **mengecualikan dirinya sendiri** sehingga mengirim rentang yang sama persis tetap 200); **lock** ikut rentang (jendela 21 Ags-20 Sep: 15 Sep & **25 Ags** terkunci, 25 Sep & 20 Ags **masih bisa dikoreksi**), baris tanpa kolom rentang tetap terkunci per bulan, `lockedRanges()` melaporkan jendela nyata, `lockedPeriods()` hanya memuat bulan terkunci **penuh** (satu batch parsial → `[]`, dua batch berurutan → `['2026-09']`).
  - `php artisan test --filter="AttendanceCorrectionTest|PenaltyBonusPayroll|LateFinePayroll|OvertimeRulePayroll|PayrollGroupScopingTest"` (kelima suite yang menyentuh jalur diubah) → **95/95 lolos** (444 assertions).
  - `php artisan test` (seluruh suite backend) → **964/964 lolos** (5.394 assertions). Patokan pra-fitur 909/909; 55 test baru (19 unit + 36 feature) dan **nol regresi**. Karena semua kolom baru nullable dan periode bulan-kalender membuat `$onDate === $periodEndDate`, 14 berkas test payroll yang hanya mengirim `period_month`/`period_year` lolos **tanpa diubah sama sekali** — inilah bukti bebas regresi untuk pemisahan `$onDate` vs rentang presensi.
  - Migrasi: `php artisan migrate --pretend` → 7 statement DDL + kueri backfill; `php artisan migrate` → kedua migrasi DONE; verifikasi data nyata → 3 baris `payrolls` lama ter-backfill benar (`2026-08` → `2026-08-01` s/d `2026-08-31`, dua baris `2026-09` → `2026-09-01` s/d `2026-09-30`), `0` baris masih NULL, `0` grup lama yang `cutoff_day`-nya terisi diam-diam, `payment_month_offset` tidak ada yang NULL. `php artisan migrate:rollback --step=2` → keenam kolom benar-benar hilang bersih, lalu `migrate` ulang sukses (reversibel).
  - `php -l` bersih pada 13 berkas PHP yang disentuh + kedua berkas test baru.
