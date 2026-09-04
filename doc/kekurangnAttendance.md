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
| 1 | **Tidak ada koreksi presensi manual** — HRD tidak bisa edit/lengkapi absen yang salah (lupa checkout sudah ter-handle auto-checkout, tapi check-in hilang/salah lokasi tidak bisa diperbaiki HRD) | Data laporan permanen salah; sudah tercatat di roadmap rules.md P3 |
| 2 | **Tidak ada batas maksimal lembur** — UU 13/2003: lembur maks 4 jam/hari & 18 jam/minggu. Sistem menghitung lembur tanpa cap | Risiko kepatuhan ketenagakerjaan |
| 3 | ~~**Tidak ada notifikasi proaktif ke HRD saat pengaturan kantor diubah** — rekomendasi strategi proteksi #3 di rules.md sendiri belum diimplement~~ ✅ SELESAI 2026-08-26 — `AttendanceController::updateSettings()` kini melooping dan mengirim `notifyUser` ke semua HRD/Admin/SuperAdmin sesudah update. | Perubahan `work_end_time`/grace mendadak bisa merusak auto-checkout hari itu; audit log ada tapi pasif (tidak ada yang membaca) |
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

#### 7. SEDANG: Ketiadaan Atribut Premi / Tunjangan Shift (Shift Differential Allowance)
- **Kondisi Saat Ini:** Shift malam (22:00–06:00) atau shift akhir pekan umumnya memiliki insentif tunjangan shift (misal: Uang Makan/Premi Malam Rp 25.000 s/d Rp 50.000 per kehadiran shift malam).
- **Kelemahan Sistem:** Tabel `shifts` tidak memiliki kolom tunjangan nominal (`shift_allowance_amount`) atau penggali lembur. Saat modul Payroll dibangun nanti, sistem tidak memiliki data dasar untuk menghitung kompensasi shift secara otomatis.

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

#### 10. SEDANG: Ketiadaan Filter Departemen / Divisi di Tab Roster dan Kalender
- **Kondisi Saat Ini:** Di tab Roster dan Kalender hanya terdapat filter Kantor/Cabang (`rosterBranch` / `calBranch`) dan pencarian nama.
- **Dampak Operasional:** Jika satu kantor cabang memiliki ratusan karyawan dari berbagai divisi (misal: Security, Front Office, Housekeeping, IT, F&B), daftar roster bercampur baur dan supervisor departemen kesulitan mengawasi jadwal timnya sendiri.
- **Rekomendasi Solusi:** Tambahkan dropdown filter `Departemen` di sebelah filter kantor pada tab Roster dan Kalender.

---

#### 11. SEDANG: Tampilan Kalender Belum Menampilkan Jumlah Total Karyawan Libur (OFF) per Hari
- **Kondisi Saat Ini:** Pada sel kalender bulanan (`calData`), hanya ditampilkan badge shift yang masuk (misal: *Shift Pagi: 8 org, Shift Malam: 4 org*).
- **Dampak Operasional:** HRD tidak dapat melihat berapa jumlah total karyawan yang sedang **LIBUR (OFF)** pada tanggal tersebut, sehingga sulit mendeteksi potensi kekurangan tenaga kerja (*understaffing*) pada hari tertentu.
- **Rekomendasi Solusi:** Tambahkan indikator ringkas di sel kalender: misal `Libur (OFF): 5 org`.

---

#### 12. RENDAH: Ketiadaan Tab / Filter Khusus "Karyawan Belum Terjadwal" (Unassigned Floating Staff)
- **Kondisi Saat Ini:** Karyawan yang tidak memiliki shift custom diberi label `source: office` (mengikuti jadwal default kantor).
- **Dampak Operasional:** HRD kesulitan melacak karyawan operasional mana saja yang belum memiliki penugasan shift untuk bulan depan, rawan ada karyawan yang terlewat tidak dijadwalkan.

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

#### 14. SEDANG: Pengaturan Toleransi Keterlambatan Masih Terkunci di Level Kantor, Bukan Level Shift
- **Kondisi Saat Ini:** Kolom toleransi telat (`late_tolerance_minutes`) dan jam cutoff telat hanya ada di tabel `attendance_settings` (kantor).
- **Dampak:** Shift malam (misal 23:00–07:00) atau shift akhir pekan darurat seringkali membutuhkan aturan toleransi yang berbeda dari jam kerja kantor normal. Saat ini sistem tidak mengizinkan kustomisasi toleransi keterlambatan pada tingkat template `Shift`.
- **Rekomendasi Solusi:** Tambahkan kolom opsional `late_tolerance_minutes` di tabel `shifts`. Jika bernilai `null`, gunakan nilai default dari kantor (`attendance_settings`).

---

#### 15. SEDANG: Ketiadaan Laporan Kepatuhan Shift (Shift Adherence / Variance Report)
- **Kondisi Saat Ini:** Tidak ada endpoint analitik yang membandingkan antara **Shift yang Ditugaskan** vs **Presensi Nyata di Lapangan**.
- **Dampak Operasional:** HRD tidak dapat mendeteksi karyawan yang melakukan presensi di luar jadwal shift-nya (misal: dijadwalkan Shift 1 Pagi tapi masuk di Shift 2 Siang tanpa izin tukar dinas).

