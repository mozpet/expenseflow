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

### 4. SEDANG — Guard jam kerja membandingkan kolom yang salah **⏸ DITUNDA**

**Lokasi:** `ShiftController::assignShift()` (~line 927)

```php
->where('id', '!=', $validated['shift_id'] ?? 0) // ← PK user_shifts vs shift_id input
```

- Membandingkan primary key `user_shifts.id` dengan `shift_id` dari input request — dua entitas berbeda.
- Guard anti-"ubah shift di tengah jam kerja" jadi salah mengecualikan/menyasar assignment.

**Fix:** bandingkan dengan assignment lama secara eksplisit, mis. simpan `$activeOld` dulu lalu exclude by id assignment itu, atau filter `where('shift_id', '!=', ...)` jika maksudnya shift baru.

> **⏸ DITUNDA — risiko diterima (keputusan 2026-08-25):** TIDAK diperbaiki untuk saat ini karena secara praktis sudah tertutup fitur **Minimum Notice Perubahan Shift (H-N Hari)** di form Edit Kantor (`attendance_settings.shift_notice_days`):
> - `checkNoticeError()` dieksekusi **SEBELUM** guard coversToday di `assignShift()` (line ~908 vs ~913). Bila notice ≥ 1 hari, start_date hari ini/mundur langsung ditolak 422 → `$coversToday` tidak mungkin true → query yang salah tidak pernah tereksekusi (dead path).
> - Bila pun guard itu jalan (kantor tanpa notice), dampaknya sangat sempit: cek `duplicateActive` sudah menolak kombinasi shift-sama-masih-aktif lebih awal, jadi kegagalan hanya terjadi bila PK assignment lama kebetulan bernilai sama dengan shift_id baru (kasus langka).
> - Lapisan lain tetap sehat: `updateAssignment()` & `destroyAssignment()` memakai objek `$userShift` nyata (tanpa kesalahan kolom), dan `bulkAssign()` sudah difix benar via Bug #5.
>
> **Syarat penerimaan risiko:** semua kantor sebaiknya mengisi `shift_notice_days` ≥ 1. Bila kelak ada kantor yang membiarkan notice kosong/0, fix-nya satu baris saja: ganti `where('id', '!=', $validated['shift_id'] ?? 0)` menjadi `where('shift_id', '!=', $validated['shift_id'])` (atau exclude by PK assignment lama secara eksplisit).

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
