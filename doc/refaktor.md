# Analisis Refaktor — AttendanceController.php

**Tanggal analisis:** 2026-10-12
**Berkas utama:** `expenseflow-backend/app/Http/Controllers/API/AttendanceController.php`
**Metode:** pembacaan penuh 13.024 baris + grep lintas `app/`, `routes/`, `tests/`, `database/`, `config/`, `expenseflow-web/src/`, `expenseflow-mobile/lib/`

Dokumen ini adalah hasil audit **read-only**. Progres pengerjaan dicatat di [§6 Status Pengerjaan](#6-status-pengerjaan).

---

## Ringkasan Eksekutif

| Pertanyaan | Jawaban |
|---|---|
| Spaghetti code? | **Ya, secara struktural** — God Object 13.024 baris / ±130 method / 10 domain. Kualitas tulisan per-baris baik; strukturnya yang kolaps |
| Bisa direfaktor? | **Bisa, risiko rendah** — pola service sudah dipakai di 9 berkas `app/Services/Attendance/`. Pecah jadi ±11 controller + ±8 service + 2 Form Request + 2 gate class |
| Ada double function? | **Ya — 9 kelompok di dalam berkas + 5 kelompok lintas-berkas.** Paling berbahaya: gate otorisasi digandakan di 2 domain, dan `AutoCheckoutCommand` menyimpan 4 salinan privat helper perhitungan lembur |
| Ada fungsi tak berguna? | **4 mati total**, **1 wrapper merugikan** (penyebab push ganda), **2 endpoint tanpa klien** |

---

## 1. Verdict: Spaghetti Code

Ini **bukan** spaghetti "kode kotor". Kualitas per-baris di atas rata-rata: komentar menjelaskan *alasan* bukan *apa*, invarian penting ditulis eksplisit, ada pembelaan terhadap bug nyata (race condition, zona WITA/WIT, snapshot kebijakan).

Masalahnya **skala dan tanggung jawab**:

| Ukuran | Nilai |
|---|---|
| Jumlah baris | **13.024** (controller terbesar kedua, ShiftController, hanya 5.008) |
| Jumlah method dalam 1 class | **±130** |
| Domain bisnis dalam 1 class | **10** |
| Method terpanjang | `buildFullRows()` & `correctAttendance()` masing-masing ±717 baris |

Pembanding: ShiftController 5.008 · ReceiptController 1.583 · UserController 1.338 · PayrollController 1.186. Total seluruh `app/Http/Controllers/API/` = 31.317 baris, berarti **satu berkas ini 42% dari seluruh lapisan controller API**.

Sepuluh domain dalam satu class: presensi check-in/out · cuti & izin 2-level approval · saldo cuti & kuota · carry-over/pencairan cuti · cuti bersama · hari libur · lembur · approval ganti perangkat · pengaturan presensi (CRUD) · laporan & export CSV.

Pola: **God Object / Fat Controller**.

### Tiga bukti berkas sudah melewati batas kemampuan manusia melacaknya

1. **Baris 11444** — komentar `// Bentuk office/offices[] WAJIB identik dengan checkStatus() (:8963)`, padahal `checkStatus()` sekarang di **baris 10228**. Referensi dalam komentar sendiri sudah usang ±1.300 baris.
2. **Baris 2350** — komentar `// Guard harus IDENTIK dengan approveLeave()`. Pengakuan bahwa ada duplikasi yang hanya dijaga disiplin manual. Satu sisi diubah tanpa sisi lain = lubang otorisasi.
3. **Dua idiom transaksi hidup berdampingan** — hampir semua pakai `DB::transaction()` closure, tapi `syncNationalHolidays()` (baris 7351) pakai `DB::beginTransaction()/commit()/rollBack()` manual.

### Method terpanjang (target refaktor utama)

| Method | Perkiraan panjang |
|---|---|
| `buildFullRows()` | ±717 |
| `correctAttendance()` | ±717 |
| `checkIn()` | ±460 |
| `approveLeave()` | ±440 |
| `updateSettings()` | ±367 |
| `checkOut()` | ±330 |
| `today()` | ±295 |
| `setLeaveBalance()` | ±294 |
| `checkStatus()` | ±275 |
| `listLeaveBalances()` | ±270 |
| `approveOvertime()` | ±230 |
| `rejectLeave()` | ±216 |
| `previewCollectiveLeave()` | ±195 |
| `listOvertimeApprovals()` | ±193 |
| `rejectOvertime()` | ±190 |
| `createCollectiveLeaveRequests()` | ±176 |
| `updateHolidays()` | ±169 |
| `storeHolidays()` | ±148 |

---

## 2. Kelayakan Refaktor

**Bisa, risiko rendah** — polanya sudah dibuktikan sendiri. Folder `app/Services/Attendance/` sudah berisi 9 service hasil ekstraksi:

`AttendancePolicyCalculator` · `AttendanceRecalculator` · `LateFineCalculator` · `EarlyLeaveFineCalculator` · `MissedCheckoutFineCalculator` · `NightShiftBonusCalculator` · `LeaveCarryOverCalculator` · `LeavePeriodCloser` · `PayrollPeriodLock`

Jadi pertanyaannya bukan "bisakah", tapi "kenapa berhenti di tengah jalan" — logika denda & carry-over sudah keluar, logika cuti/libur/lembur belum.

### 2.1 Usulan pemecahan controller (per domain, bukan per ukuran)

| Controller baru | Isi |
|---|---|
| `AttendancePresenceController` | `checkIn` `checkOut` `checkStatus` `myAttendance` `photo` `registerFcmToken` |
| `LeaveRequestController` | `requestLeave` `leavePreview` `myLeaves` `myLeaveBalance` |
| `LeaveApprovalController` | `approveLeave` `rejectLeave` `listLeaves` `leaveDocument` |
| `LeaveBalanceController` | `listLeaveBalances` `setLeaveBalance` `listLeaveBalanceHistories` `resetOfficeLeaveBalances` |
| `HolidayController` | `listHolidays` `storeHolidays` `updateHolidays` `destroyHolidays` `previewNationalHolidays` `syncNationalHolidays` |
| `CollectiveLeaveController` | `previewCollectiveLeave` `collectiveLeaveDetail` `listCollectiveLeaves` `respondCollectiveLeave` `dismissCancellation` |
| `OvertimeApprovalController` | `listOvertimeApprovals` `approveOvertime` `rejectOvertime` `claimOvertime` `declineOvertime` `myOvertimeApprovals` |
| `DeviceChangeController` | `listDeviceChanges` `approveDeviceChange` `rejectDeviceChange` |
| `AttendanceReportController` | `reportAttendance` `exportReport` `monthlySummary` `today` `correctAttendance` |
| `AttendanceSettingController` | `listSettings` `storeSettings` `showSettings` `updateSettings` `destroySettings` `listTimezones` `listLeaveTypeSettings` `updateLeaveTypeSettings` |
| `SpvApprovalController` | `spvListOvertimeApprovals` `spvPendingOvertimeCount` `spvListLeaveApprovals` `spvPendingLeaveCount` |
| `AttendanceUserPolicyController` | `listUsers` `listAllUsers` + 5 toggle + `updateMobilePolicy` |

### 2.2 Usulan service baru

| Service | Isi yang dipindahkan |
|---|---|
| `LeaveEffectiveDaysCalculator` | `workingDatesBetween` `countWorkingDays` `effectiveLeaveDays` `isNonWorkingDay` `splitLeaveAroundReset` `effectiveDatesForExistingLeave` |
| `LeaveExclusionResolver` | taksonomi alasan pengecualian — lihat §3.3 (saat ini 3 implementasi terpisah) |
| `AttendanceRowBuilder` | pecahan `buildFullRows()` |
| `CollectiveLeaveSynchronizer` | `createCollectiveLeaveRequests` `syncCollectiveLeaveExclusions` `cancelAllCollectiveRequests` `compensateApprovedLeavesForHoliday` `reapplyLeaveDeductionAfterHolidayRemoval` |
| `LeaveTypeService` | `seedDefaultLeaveTypeSettings` `getLeaveTypeSettingForOffice` `getLeaveTypeSettingForUser` `enabledLeaveTypesFor` `leaveTypeRequiresDocument` `checkLeaveTypeEligibility` `leaveTypeCatalog` |
| `CollectiveLeaveNotifier` | `dispatchH7CollectiveLeaveNotifications` |
| `LeaveApprovalGate` + `OvertimeApprovalGate` | gate otorisasi yang kini digandakan — lihat §3.1 |
| `OffDateResolver` | `resolveOffDatesForUser` — digabung dengan `ShiftController::resolveSchedule` |

### 2.3 Form Request

`settingRules()` (5672) + `settingMessages()` (5771) = ±175 baris yang **sudah** berbentuk Form Request, hanya kebetulan tinggal di controller. Pindahkan apa adanya ke `StoreAttendanceSettingRequest` / `UpdateAttendanceSettingRequest`.

### 2.4 ⚠️ TUJUH KERUMITAN YANG WAJIB TIDAK DIRATAKAN

Semua ini lahir dari bug nyata dan sudah didokumentasikan di dalam berkas. **Jangan "disederhanakan" saat refaktor.**

1. **Baris 5574** — di dalam closure `correctAttendance()`, kegagalan validasi WAJIB di-`throw` sebagai `HttpResponseException`, **bukan** `return`. Alasannya tertulis: return hanya mengakhiri closure sementara transaksi tetap ter-commit.
2. **Baris 5014** — `Cache::lock("correct_attendance_{id}_{date}")` + `finally { $lock->release(); }` diperlukan karena `lockForUpdate()` tidak bisa mengunci baris yang **belum ada** (unique index `user_id,date`). Pola sama untuk `checkin_{id}` / `checkout_{id}`.
3. **Pemisahan 3 zona waktu adalah SENGAJA, bukan inkonsistensi:**
   - `Timezone::storage()` → konteks kalender perusahaan/HRD lintas cabang
   - `User::effectiveTimezone()` / `todayDateForUser()` / `todayDatesForUsers()` → jam dinding cabang karyawan
   - `Attendance::snap_timezone` → zona saat check-in, otoritatif untuk baris itu (lihat 9842–9844)
4. **Baris 9650** — argumen ke-5 `buildSnapshot()` sengaja `$tz` (cabang penempatan), **bukan** zona `$acuanOffice` hasil geofence.
5. **Baris 4865** — urutan kolom CSV export dibekukan: kolom koreksi DITAMBAHKAN DI BELAKANG, jangan ubah urutan kolom lama.
6. **Baris 8547** — satu transaksi dengan `lockForUpdate` ganda (leave_request + leave_balance) di `respondCollectiveLeave()` adalah perbaikan race condition.
7. **Rekalkulasi memakai `Attendance::snapshotSchedule()`, bukan setting live** — agar baris historis dinilai dengan aturan yang berlaku saat check-in. Plus: periode payroll approved/paid diblokir keras via `PayrollPeriodLock`.

**Catatan tambahan:** semua pesan user-facing harus datang dari payload API dalam Bahasa Indonesia; frontend tidak boleh me-remap label (komentar 204–207). `editable` / `block_reason` adalah satu-satunya sumber kebenaran untuk tombol koreksi di web.

### 2.5 Pola BAGUS yang layak ditiru

Return *discriminated union* dari closure transaksi di `respondCollectiveLeave()`:
`['kind' => 'same'|'insufficient'|'accepted'|'declined']` — pertahankan dan tiru di tempat lain.

---

## 3. Double Function

### 3.1 🔴 Gate otorisasi approve/reject — digandakan di DUA domain

Pelanggaran paling berbahaya: yang digandakan adalah **logika keamanan**.

| Domain | Approve | Reject | Isi yang digandakan |
|---|---|---|---|
| Cuti | `approveLeave()` **1847–1920** | `rejectLeave()` **2271–2336** | rantai `$hasSpvPermission` / `$isDirectManager` / `$hasSupervisorPosition` / `$isAdminBypass` / `$isHrdLevelOnly` / `$isSpvOrAbove` / `$isSameDivisionSpv` + 4 error code `WAITING_SPV_APPROVAL` `SPV_REQUIRED` `DIRECT_SUPERVISOR_REQUIRED` `SPV_DIVISION_REQUIRED`. Hanya kata "Persetujuan"/"Penolakan" yang beda |
| Lembur | `approveOvertime()` **10824–10897** | `rejectOvertime()` **11058–11125** | ±90 baris **identik — pesan error pun sama kata demi kata**, tidak diubah untuk penolakan |

Gate HRD tahap 2 juga digandakan: **10949–10958** vs **11137–11146**, pesan `HRD_REQUIRED` identik.

Idiom berikut muncul **7×**:

```php
$isHrdOrAdmin = in_array($userRoleCode, ['super_admin','admin','hrd'], true)
    || $actor->hasPermission(Role::MODULE_X, 'hrd')
    || $actor->hasPermission(Role::MODULE_X, 'manage');
```

- `MODULE_LEAVE`: baris **2003, 2353, 2474, 7001**
- `MODULE_OVERTIME`: baris **10629, 10949, 11137**

**Perbaikan:** ekstrak `LeaveApprovalGate` + `OvertimeApprovalGate`.

### 3.2 Closure scoping bawahan SPV — 9 titik

Blok `manager_id = actor OR (division_id sama AND manager_id NULL AND id != actor)`:

| Lokasi | Baris |
|---|---|
| `listLeaves()` — **2× dalam method yang sama** | 2479, 2531 |
| `listOvertimeApprovals()` — **2× dalam method yang sama**, byte-identical termasuk komentar `// Pengaju tidak boleh menyetujui pengajuannya sendiri` | 10640, 10728 |
| `spvListOvertimeApprovals()` | 11573, 11676 |
| `spvListLeaveApprovals()` | 11776, 11868 |
| `spvPendingOvertimeCount()` | 11722 |
| `spvPendingLeaveCount()` | 11915 |

Di `listOvertimeApprovals()` blok branch-scoping ikut digandakan (10620 vs 10716) dan `scopeOvertimeForHrdLevel()` dipanggil dua kali (10636, 10724).

### 3.3 🔴 Taksonomi alasan pengecualian cuti bersama — 3 implementasi independen

Aturan yang sama (`inactive_leave` → kuota ≤ 0 · `quota_exhausted` → sisa ≤ 0 · `existing_leave` → cuti pribadi bertumpuk · `shift_off` → `countWorkingDays() < 1` · `manual` → dikecualikan HRD) ditulis ulang di:

| Lokasi | Baris | Bentuk |
|---|---|---|
| `listHolidays()` | ±7150–7225 | menghasilkan `reason_type` / `reason_label` / `reason_detail` |
| `previewCollectiveLeave()` | 7888–7997 | **5 literal array 9-key di-copy-paste** (7897, 7912, 7934, 7959, 7975) |
| `syncCollectiveLeaveExclusions()` | 8949–8990 | lima cek sama, dikumpulkan ke `$finalExcludedIds[]` |

**Konsekuensi:** jika satu salinan berubah, **preview HRD akan berbeda dari yang benar-benar disimpan.**

### 3.4 CRUD hari libur — trio yang mengulang 6 blok

`storeHolidays()` (7442) · `updateHolidays()` (7593) · `destroyHolidays()` (7764) sama-sama mengulang:

1. 403 super-admin-only untuk libur nasional
2. 403 `'Hanya Super Admin yang berwenang mengatur libur / cuti bersama untuk semua cabang.'`
3. 404 `'Cabang kantor tidak ditemukan di perusahaan Anda.'`
4. 422 `'Tanggal libur tersebut sudah terdaftar pada scope yang dipilih.'`
5. blok hapus-timpa libur nasional (7522 vs 7696)
6. payload `holiday` + ternary `scope` (7573 vs 7750)

Pesan konflik cuti bersama yang panjang digandakan **verbatim** di **7505** dan **7671**:

> `"Tidak bisa menambahkan libur nasional pada tanggal {$formattedDate}, di karenakan kantor {$officeNames} sedang mengadakan cuti bersama, hubungi pihak kantor agar menghapus cuti bersama pada tanggal tersebut"`

Tambahan: `syncNationalHolidays()` (7351) memakai idiom transaksi manual — berbeda dari seluruh berkas.

### 3.5 Laporan & pengaturan

- `reportAttendance()` (4745) vs `exportReport()` (4822): rules sama (export menambah `dinas_luar`), derivasi `$companyId` sama, default rentang tanggal sama, guard 62 hari sama, panggilan `buildFullRows()` 9-argumen sama.
- `storeSettings()` (6281) vs `updateSettings()` (6405): **5 guard bisnis digandakan** — koordinat (0,0) ditolak · `work_start_time === work_end_time` · `auto_checkout_grace_minutes > checkout_reminder_minutes` · `late_tolerance_minutes <= late_checkin_cutoff_minutes` · `overtime_rule === 'custom'` wajib `overtime_rate_amount > 0`. Dua implementasi, dua string pesan.
- `updateSettings()` punya **4 blok `foreach` pemulihan null terpisah** (6418, 6430, 6439, 6448, 6466) yang mengerjakan hal sama: "array_filter menghapus null eksplisit saya, kembalikan".
- `listLeaveTypeSettings()` / `updateLeaveTypeSettings()` menggandakan cek akses kantor 403.

### 3.6 `buildFullRows()` — duplikasi di dalam dirinya sendiri

| Masalah | Baris |
|---|---|
| Literal `$shiftStats` 14-key muncul **4×** | 4300, 4318, 4447, 4620 |
| Dua payload baris ±35 key nyaris identik (nyata vs virtual) | 4502–4553 vs 4648–4702 |
| Blok filter di-copy-paste verbatim | 4415–4430 dan 4597–4612 |

Plus: menerima **9 parameter posisional**, dan return-nya **dua bentuk berbeda** tergantung `$page !== null` (array terpaginasi vs list rata).

### 3.7 checkIn / checkOut / checkStatus / myAttendance

| Duplikasi | Lokasi |
|---|---|
| 3 guard integritas perangkat (`is_mocked` / `is_rooted` / `is_emulator`) | `checkIn()` 9300–9319 **dan** `checkOut()` 9745–9764 |
| Blok device-binding mismatch | 9337–9356 vs 9776–9786 |
| Loop Haversine kantor terdekat — **3×** | 9554, 9916, 9943 |
| `$officeData` dibangun identik | `checkStatus()` 10268–10277 vs `myAttendance()` 11452–11463 |
| Rantai fallback `$primaryOffice` identik | 10256–10266 vs 11444–11450 |
| ⚠️ Flag `attendance_enabled`/`wfh_enabled`/`radius_enabled`/`dinas_luar_enabled`/`is_wfh_approved` — **ekspresi sedikit BERBEDA** | 10362–10366 vs 11478–11482 → bukan sekadar duplikasi, sudah mulai menyimpang |
| Perhitungan reminder/auto-checkout | `checkIn()` 9684–9699 vs `checkStatus()` 10394–10419 |
| `getWorkSchedule($user, $today)` dipanggil **2× dalam `checkIn()`** — resolve mubazir | 9367 dan 9580 |
| Closure normalisasi zona `getCollection()->transform()` verbatim | 10687–10707 vs 11520–11540 |
| Pembersihan notifikasi lembur `whereIn('type', [...])->delete()` | 10977, 11172, 12095 |
| `approveDeviceChange()` / `rejectDeviceChange()` menggandakan ±40 baris | 11258 / 11335 |

### 3.8 🔴 LINTAS CONTROLLER & COMMAND

#### (1) `AutoCheckoutCommand.php` menyimpan 4 salinan privat helper AttendanceController

| Helper | Baris di Command | Pengakuan di komentar Command sendiri |
|---|---|---|
| `isNonWorkingDay()` | **435** | — |
| `calculateOvertime()` | **487** | "sadar shift, sama dengan AttendanceController" |
| `formatMinutes()` | **557** | — |
| `resolveWorkStart()` | **575** | baris 568: *"Sama persis dengan resolveWorkStart() di AttendanceController."* |

**Keseriusan:** aturan jam kerja & lembur sekarang hidup di **tiga tempat** — `AttendanceRecalculator` (implementasi asli), wrapper mati di AttendanceController (§4.1), dan **salinan penuh yang masih AKTIF di AutoCheckoutCommand**. Auto-checkout tengah malam bisa menghitung lembur berbeda dari checkout manual tanpa ada yang sadar.

#### (2) `resolveOffDatesForUser()` menyalin `ShiftController::resolveSchedule()`

`resolveOffDatesForUser()` (8379–8489, ±110 baris) — komentarnya sendiri mengaku:

- baris 8204: *"Logika di dalam helper meniru resolveSchedule() persis"*
- baris 3887: *"meniru persis logika ShiftController::resolveSchedule()"*

Matematika rotasi pola `(($anchor - 1 + $diffDays) % $cycleDays) + 1` ada di kedua berkas.

**Temuan bonus:** formula rotasi itu juga diulang **3× di dalam ShiftController sendiri** (baris **2356, 2696, 2948**). Total **4 salinan** rumus siklus shift di codebase.

#### (3) `logActivity()` + `notifyUser()` digandakan privat di 8 controller

Padahal `App\Services\AuditLogger` sudah ada.

| Controller | `logActivity` | `notifyUser` | Catatan |
|---|---|---|---|
| AttendanceController | 50 | 74 | `?int $companyId` |
| ExpenseReportController | 23 | 39 | pakai `$subjectId` |
| InvoiceController | 18 | 33 | |
| ReceiptController | 20 | 37 | pakai `$subjectId` |
| RecruitmentController | 20 | — | |
| SettingsController | 27 | — | hanya 4 parameter |
| ShiftController | 30 | — | |
| VendorController | 14 | — | |

#### (4) `UserController::update()` menggandakan cascade flag presensi

`UserController.php` **580–635** mengulang rantai `allow_attendance → allow_wfh → allow_radius` beserta cek `getActiveShiftRequirements()` dan pesan 422-nya — logika yang juga ada di 5 endpoint toggle AttendanceController (`getActiveShiftRequirements()` dipanggil di 1163, 1240, 1341, 1445).

Dua jalur berbeda bisa mengubah flag yang sama dengan aturan yang ditulis dua kali.

#### (5) `GenerateDummyUsersCommand.php` memanggil method controller langsung

Baris **273** `listUsers` · **281** `today` · **289** `listLeaveBalances` · **308** `reportAttendance` · **316** `listDeviceChanges` — membuat controller jadi dependensi command, bukan sebaliknya.

#### (6) Kopling statis controller → controller

`ShiftController::resolveSchedule()` (292, 2689) · `resolveSchedulesBulk()` (2775) · `calculateHalfDayMidpoint()` (349, 2860) · `resolveYesterdayCrossDay()` (9462, 9810, 10242).

### 3.9 🔴 BUG NYATA dari duplikasi: FCM push ganda

`notifyUser()` (baris 74–105) **sudah mengirim FCM di dalamnya** (baris 89–100: `app(FcmService::class)->send(...)`), tapi kode masih memanggil `sendFcmPush()` untuk **penerima yang sama** di **8 titik**:

| Baris | Konteks | Tipe notifyUser sebelumnya | Penerima |
|---|---|---|---|
| 1965 | `approveLeave()` tahap 1 SPV → notif HRD | `leave_pending_hrd` | HRD/admin |
| 1983 | `approveLeave()` tahap 1 SPV → notif karyawan | `leave_spv_approved` | karyawan |
| 2195 | `approveLeave()` tahap 2 HRD final | `leave_approved` | karyawan |
| 2414 | `rejectLeave()` | `personal_leave_cancelled` | karyawan |
| 10995 | `approveOvertime()` HRD final | `overtime_approved` | karyawan |
| 11190 | `rejectOvertime()` | `overtime_rejected` | karyawan |
| 12972 | `requestLeave()` → notif SPV | `leave_requested_spv` | manager |
| 12999 | `requestLeave()` → notif HRD | `leave_requested` | HRD/admin |

**Dampak:** karyawan/approver menerima **dua push notification** untuk satu kejadian. Ini bukan smell — ini bug yang bisa diverifikasi di perangkat.

**Catatan penting untuk perbaikan:** `resolveNotificationTitle()` (108–130) belum punya case untuk `leave_pending_hrd`, `leave_spv_approved`, `leave_requested`, `leave_requested_spv`, `personal_leave_cancelled` — kelima tipe itu jatuh ke default `'Pemberitahuan'`. Jadi menghapus 8 blok push secara mentah akan **menurunkan kualitas judul notifikasi**. Judul ber-emoji harus dipindahkan dulu (lewat key `'title'` di array data `notifyUser()`, atau menambah case di `match`) sebelum blok push dihapus.

Verifikasi keamanan: `expenseflow-mobile/lib/services/notification_service.dart` (`_onFcmForeground`, 240–291) hanya membaca `msg.data['type']` + title/body. Tidak ada deep-link yang bergantung pada `leave_id`/`overtime_id`, dan `_onTap()` hanya `debugPrint`. Penghapusan push duplikat **aman**.

### 3.10 Duplikasi kecil lainnya

- Blok if/else `company_id` di `autoRejectExpiredLeaves` diulang di **1782, 2230, 2444, 2750**
- Lookup user scoped-company + 404 `'User tidak ditemukan di perusahaan Anda.'` muncul **8+ kali**: 1144, 1221, 1293, 1392, 1487, 1554, 3234, 3829
- Payload `'user' => [...]` 10-flag identik di 5 endpoint toggle + `updateMobilePolicy`
- Loop pembersihan pending cuti bersama **2× dalam `setLeaveBalance()`** (3431–3443, 3450–3460)
- `match` label jenis cuti di-inline di ±2123, ±2188, ±2389, ±3046, ±3343

---

## 4. Fungsi Tidak Berguna

Diverifikasi dengan grep seluruh `app/`, `routes/`, `tests/`, `database/`, `config/`, plus frontend web & mobile.

### 4.1 Mati total — 0 pemanggil (aman dihapus)

| Method | Baris | Bukti |
|---|---|---|
| `defaultLeaveQuota()` | **500** `public static` | Nol pemanggil di seluruh codebase. Yang ada hanya komentar di 2995 *"meniru logika defaultLeaveQuota"* — logikanya di-copy ke tempat lain dan aslinya ditinggalkan |
| `resolveWorkStart()` | **377** `private` | Nol pemanggil. Body hanya delegasi ke `AttendanceRecalculator`. AutoCheckoutCommand punya salinan penuhnya sendiri (§3.8.1) |
| `calculateOvertime()` | **10079** `private` | Nol pemanggil `$this->calculateOvertime`. Body: satu baris delegasi ke `AttendanceRecalculator` |
| `checkEarlyLeave()` | **10091** `private` | Nol pemanggil `$this->checkEarlyLeave`. Body: satu baris delegasi |

Keempatnya sisa migrasi ke service yang lupa dibersihkan.

### 4.2 Hidup tapi tidak menambah nilai

**`sendFcmPush()` (10200–10203)** — wrapper satu baris atas `app(FcmService::class)->send()`. Delapan pemanggilnya justru penyebab bug push ganda (§3.9). Hapus wrapper + 8 pemanggilnya.

### 4.3 Endpoint tanpa klien

| Endpoint | Route | Status |
|---|---|---|
| `POST /users/{id}/toggle-attendance` | `api.php:522` | terdaftar di `endpoints.ts:370`, **tidak ada satu komponen pun memanggilnya**; mobile juga tidak |
| `PUT /users/{id}/mobile-policy` | `api.php:523` | terdaftar di `endpoints.ts:372`, **tidak ada pemanggil** |

`updateMobilePolicy()` secara fungsional menggantikan `toggleAttendance` + `toggleWfh` + `toggleRadius` (mengubah 3 flag yang sama persis) — jadi **dua generasi API hidup berdampingan**, dan generasi barunya belum dipakai sementara yang lama sudah mati. Perlu keputusan produk: mana yang dipertahankan.

**Masih dipakai — jangan disentuh:** `toggleWfh` (`AttendanceManagement.tsx:1910`) · `toggleRadius` (1951) · `toggleDinasLuar` (1976) · `toggleFlexitime` (1992).

### 4.4 Dugaan yang ternyata SALAH — ini HIDUP, jangan dihapus

| Method | Baris | Pemanggil |
|---|---|---|
| `leavePolicyLabel()` | 6083 | `myLeaveBalance()` baris **12357** |
| `historyOutcomeLabel()` | 6139 | baris **3583** |
| `countWorkingDays()` | 857 | 7205, 7949, 8223, 8563, 8846, 8969, 9159, 9224 — **salah satu helper tersibuk** |
| `isNonWorkingDay()` | 790 | 5377, 9980 |
| `formatMinutes()` | — | 13 titik pemanggilan |
| `statusLabelId()` | 208 | 4700, 5075, 5178, 5620, 5641 |
| `leaveTypeCatalog()` · `halfDayLabel()` · `normalizeHalfDaySession()` · `todayDate*()` · `resolveAutoCheckInType()` | — | semua aktif |

### 4.5 Bukan "tak berguna" tapi salah tempat

Method `public` yang **bukan route** — controller dipakai sebagai service container:

- `seedDefaultLeaveTypeSettings()` · `getLeaveTypeSettingForOffice()` · `getLeaveTypeSettingForUser()` · `enabledLeaveTypesFor()` · `leaveTypeRequiresDocument()` · `checkLeaveTypeEligibility()` → milik `LeaveTypeService`
  (dipanggil juga dari test: `LeaveTypeExpansionTest.php` baris 53, 814, 901)
- `dispatchH7CollectiveLeaveNotifications()` → dipanggil dari `NotifyCollectiveLeaveH7Command.php:16` dan 4 test di `CollectiveLeaveH7NotificationTest.php` → milik `CollectiveLeaveNotifier`
- `public static` `normalizeHalfDaySession()` · `halfDayLabel()` · `leaveTypeCatalog()` · `defaultLeaveQuota()` → milik value object / enum

### 4.6 N+1 query yang ditemukan sambil jalan

| Lokasi | Masalah |
|---|---|
| `listHolidays()` baris **7204** | `User::find($u->id)` di dalam closure `->map()` |
| `updateLeaveTypeSettings()` **7036** & **7079** | `User::where('attendance_setting_id',...)->pluck('id')` dipanggil dua kali, yang kedua di dalam `foreach` |
| `updateLeaveTypeSettings()` **7037–7044** & **7091–7098** | loop prune kuota berlebih digandakan |

---

## 5. Prioritas Pengerjaan

Diurutkan berdasarkan **risiko**, bukan ukuran.

| # | Item | Alasan prioritas | Referensi |
|---|---|---|---|
| **1** | **FCM push ganda** (8 titik) — ✅ **SELESAI** | Bug yang sudah terasa oleh karyawan hari ini | §3.9, §7 |
| **2** | **Gate otorisasi digandakan 4×** | Satu edit yang lupa disinkronkan = lubang otorisasi approval | §3.1 |
| **3** | **`AutoCheckoutCommand` punya salinan hidup perhitungan lembur** | Auto-checkout bisa diam-diam berbeda hasil dari checkout manual | §3.8.1 |
| 4 | Hapus 4 fungsi mati + wrapper `sendFcmPush` | Pembersihan tanpa risiko | §4.1, §4.2 |
| 5 | Taksonomi pengecualian cuti bersama → 1 service | Preview HRD bisa berbeda dari hasil tersimpan | §3.3 |
| 6 | Closure scoping SPV → 1 query scope | 9 titik, rawan menyimpang | §3.2 |
| 7 | Flag `checkStatus` vs `myAttendance` yang sudah menyimpang | Sudah berbeda ekspresi, bukan cuma duplikat | §3.7 |
| 8 | `settingRules`/`settingMessages` → Form Request | Pemindahan mekanis | §2.3 |
| 9 | Pecah `buildFullRows()` & `correctAttendance()` | Masing-masing ±717 baris | §3.6, §1 |
| 10 | Pemecahan controller per domain | Pekerjaan besar, lakukan setelah 1–9 | §2.1 |
| 11 | `logActivity`/`notifyUser` → `AuditLogger` | Lintas 8 controller | §3.8.3 |
| 12 | Perbaiki 3 N+1 query | Performa | §4.6 |
| 13 | Putuskan nasib `toggle-attendance` & `mobile-policy` | Butuh keputusan produk | §4.3 |

---

## 6. Status Pengerjaan

| # | Item | Status | Tanggal |
|---|---|---|---|
| 1 | FCM push ganda | ✅ **SELESAI** — lihat §7 | 2026-10-12 |
| 2 | Gate otorisasi digandakan | ✅ **SELESAI** — lihat §8 | 2026-10-12 |
| 3 | Salinan helper di AutoCheckoutCommand | ✅ **SELESAI** — lihat §9 | 2026-10-12 |
| 4 | Hapus 4 fungsi mati (`defaultLeaveQuota` `resolveWorkStart` `calculateOvertime` `checkEarlyLeave`) | ✅ **SELESAI** — lihat §9; wrapper `sendFcmPush` memang sudah terhapus di #1 | 2026-10-12 |
| 5–13 | lihat §5 | ⬜ belum | — |

---

## 7. Catatan Pengerjaan #1 — FCM Push Ganda (SELESAI 2026-10-12)

### Akar masalah

`notifyUser()` (74–105) sudah memanggil `app(FcmService::class)->send()` di dalamnya. Setiap `sendFcmPush()` yang berdiri tepat setelah `notifyUser()` untuk penerima yang sama = **push kedua**.

### Verifikasi keamanan sebelum menghapus

| Konsumen | Yang dibaca | Kesimpulan |
|---|---|---|
| Mobile `notification_service.dart` `_onFcmForeground` (240–291) | hanya `msg.data['type']` + `notification.title/body`; `_onTap()` hanya `debugPrint` | tidak ada deep-link bergantung `leave_id`/`overtime_id` → **aman** |
| Web `mappers.ts:447` | `data.title ?? data.message ?? rawType` | menambah key `'title'` di data notifikasi **memperbaiki** judul di web, bukan merusaknya |
| `resolveNotificationTitle()` (108) | `$data['title']` diprioritaskan sebelum `match` | jalur migrasi judul sudah tersedia tanpa ubah kontrak |

### Perubahan yang dilakukan

**1. Judul diselamatkan lebih dulu** — `resolveNotificationTitle()` diperluas 7 case (5 tipe sebelumnya jatuh ke `'Pemberitahuan'`):

| Tipe | Judul baru |
|---|---|
| `overtime_approved` | `✅ Lembur Disetujui` (ditambah emoji, sebelumnya tanpa) |
| `overtime_rejected` | `❌ Lembur Ditolak` (ditambah emoji) |
| `leave_requested` | `📋 Pengajuan Izin/Cuti Baru` (**baru**) |
| `leave_requested_spv` | `📋 Pengajuan Izin/Cuti Baru` (**baru**) |
| `leave_pending_hrd` | `📋 Pengajuan Izin/Cuti Menunggu Persetujuan HRD` (**baru**) |
| `leave_spv_approved` | `⏳ Tahap 1 Disetujui (SPV)` (**baru**) |
| `personal_leave_cancelled` | `Pengajuan Cuti Dibatalkan` (**baru**, fallback — pemanggilnya sudah kirim `'title'` sendiri) |

**2. `approveLeave()` tahap 2 (HRD final)** — `match` `$leaveLabel` dikeluarkan dari dalam `if ($employee->fcm_token)` dan judulnya dipindah ke key `'title'` milik `notifyUser('leave_approved')`, sehingga judul dinamis per jenis cuti (`✅ Cuti Disetujui`, `✅ Izin Disetujui`, …) tetap utuh dan kini **juga tampil di web**, bukan hanya di push.

**3. Delapan blok push duplikat dihapus** beserta pembungkus `if (…->fcm_token)` dan lookup `User::find()` yang jadi tak terpakai:

| Lokasi lama | Konteks | Judul yang dipertahankan lewat |
|---|---|---|
| 1962–1971 | `approveLeave()` SPV → HRD (+ `$hrdUser = User::find()`) | case `leave_pending_hrd` |
| 1981–1989 | `approveLeave()` SPV → karyawan | case `leave_spv_approved` |
| 2185–2201 | `approveLeave()` HRD final (+ `$employee = User::find()`) | key `'title'` dinamis |
| 2411–2420 | `rejectLeave()` (+ `$employee`) | `'title'` yang **sudah ada** sejak awal |
| 10992–11001 | `approveOvertime()` HRD final (+ `$employee`) | case `overtime_approved` |
| 11188–11196 | `rejectOvertime()` (+ `$employee`) | case `overtime_rejected` |
| 12969–12978 | `requestLeave()` → SPV (+ `$manager = User::find()`) | case `leave_requested_spv` |
| 12996–13005 | `requestLeave()` → HRD (+ `$hrdUser = User::find()`) | case `leave_requested` |

**4. Helper `sendFcmPush()` (10197–10203) dihapus** — §4.2 tuntas. `use App\Services\FcmService;` tetap dipakai oleh `notifyUser()`.

### Dampak samping yang menguntungkan

- **Query `User::find()` hilang dari dalam loop `foreach`** di tiga tempat (notifikasi HRD di `approveLeave()` dan `requestLeave()`) — ini juga memperbaiki N+1 kecil yang belum tercatat di §4.6.
- Judul notifikasi sekarang punya **satu sumber kebenaran** (`resolveNotificationTitle()`), bukan tersebar di 8 literal.
- `rejectLeave()` sebelumnya mengirim push dengan `'type' => 'leave_rejected'` padahal baris notifikasinya `'personal_leave_cancelled'` — **inkonsistensi tipe antara baris DB dan payload push kini hilang**.

### Hasil

| Pemeriksaan | Hasil |
|---|---|
| `php -l` | No syntax errors |
| `grep -rn 'sendFcmPush' app/ routes/ tests/` | 0 sisa |
| Test terkait (`MultiApproval\|Overtime\|Leave\|Fcm\|Notification`) | 230/230 lulus, 1.464 asersi |
| **Suite penuh** | **1031/1031 lulus, 5.788 asersi** |
| Ukuran berkas | 13.024 → **12.944** baris (−81) |

---

## 8. Catatan Pengerjaan #2 — Gate Otorisasi Digandakan 4x (SELESAI 2026-10-12)

### Akar masalah

Aturan otorisasi approval 2 tahap (SPV → HRD) tersalin **empat kali**: `approveLeave()`, `rejectLeave()`,
`approveOvertime()`, `rejectOvertime()`. Masing-masing berisi gate Tahap 1 (~70 baris) + gate Tahap 2 (~10 baris).
Komentar di `rejectLeave()` sendiri sudah mengakui bahayanya: *"Guard harus IDENTIK dengan approveLeave()"* —
pengingat manual adalah satu-satunya yang menjaga keempat salinan tetap sinkron.

Dan pengingat itu memang pernah gagal. Dua bukti nyata yang ditemukan saat membaca keempatnya:

1. **Asimetri fallback divisi** — domain cuti memakai `else { $isSameDivisionSpv = true; }` tanpa syarat,
   sedangkan domain lembur memakai `elseif (! $isHrdLevelOnly)`. Ini **bukan** kelalaian: itu perbaikan bug
   2026-10-07 (HRD Level 2 bisa melewati Tahap 1 untuk karyawan tanpa `manager_id` maupun `division_id`).
   Perbaikan itu diterapkan ke dua salinan lembur, **tidak** ke dua salinan cuti.
2. **Pesan `rejectOvertime()` masih berbunyi "Persetujuan"** padahal itu jalur penolakan — sisa copy-paste
   dari `approveOvertime()` yang lupa disesuaikan.

### Verifikasi sebelum ekstraksi

| Yang diperiksa | Temuan | Konsekuensi desain |
|---|---|---|
| Transaksi DB di sekitar gate | Satu-satunya `DB::transaction` dibuka **setelah** gate HRD selesai | Gate boleh `return` array biasa; `HttpResponseException` tidak diperlukan |
| Variabel gate dipakai setelah gate? | Tidak ada — `$targetUser`, `$isDirectManager`, `$isSpvOrAbove` dll. hanya hidup di dalam gate | Ekstraksi tidak meninggalkan variabel menggantung |
| Yang diasersi test | Hanya nilai `code`, **tidak pernah** string `message` | Kata benda tindakan boleh diparameterkan |
| Perbedaan pesan approve vs reject | Domain cuti: beda ("Persetujuan"/"Penolakan"). Domain lembur: identik | Perlu hook per domain, bukan satu template global |
| Pemanggil lain gate ini | grep kode error → hanya AttendanceController | Tidak ada controller lain yang ikut terpengaruh |

### Perubahan yang dilakukan

**1. Tiga berkas baru di `app/Services/Attendance/`**

| Berkas | Isi |
|---|---|
| `ApprovalGate.php` (abstract) | Seluruh alur keputusan: `authorizeSpvStep()`, `authorizeHrdStep()`, `isHrdLevelOnly()`, `hasStructuralSpvAuthority()` |
| `LeaveApprovalGate.php` | Modul `MODULE_LEAVE`, subjek "cuti/izin", fallback perusahaan **berlaku** untuk HRD |
| `OvertimeApprovalGate.php` | Modul `MODULE_OVERTIME`, subjek "lembur", fallback perusahaan **tidak** berlaku untuk HRD |

Kontrak: kedua method mengembalikan `null` bila berwenang, atau `array{message, code}` untuk dibalut 403 oleh
controller. Gate tidak pernah membuat response HTTP sendiri — itu tetap tugas controller.

**2. Asimetri yang disengaja dijadikan eksplisit, bukan diratakan**

Perbedaan satu-satunya antara dua domain kini menjadi satu method abstrak yang **wajib** dijawab
setiap subclass, sehingga tidak bisa lagi tidak sengaja ikut berubah:

```php
abstract protected function allowCompanyWideFallbackForHrd(): bool;

// LeaveApprovalGate    → true   (perilaku lama domain cuti)
// OvertimeApprovalGate → false  (perbaikan bug 2026-10-07, jangan diseragamkan)
```

Keanehan "Persetujuan" di jalur penolakan lembur **sengaja dipertahankan** lewat hook
`normalizeAction()` dan diberi komentar, agar ekstraksi ini murni refactor dan tidak mengubah
response yang mungkin sudah dipakai klien. Memperbaiki kata itu adalah keputusan terpisah.

**3. Delapan call site diganti delegasi**

| Method | Gate Tahap 1 | Gate Tahap 2 |
|---|---|---|
| `approveLeave()` | 73 baris → 3 baris | 13 baris → 3 baris |
| `rejectLeave()` | 67 baris → 3 baris | 14 baris → 3 baris |
| `approveOvertime()` | 73 baris → 3 baris | 10 baris → 3 baris |
| `rejectOvertime()` | 67 baris → 3 baris | 10 baris → 3 baris |

Bentuk barunya seragam di keempat tempat:

```php
if ($denial = app(LeaveApprovalGate::class)->authorizeSpvStep($actor, $leave->user, 'Persetujuan')) {
    return response()->json($denial, 403);
}
```

**4. Dua helper lama dialihkan ke gate** — `isOvertimeHrdLevelOnly()` dan
`hasStructuralOvertimeSpvAuthority()` tetap ada (3 pemanggil lain: `listOvertimeApprovals()`,
`scopeOvertimeForHrdLevel()`, `show_spv_step`) tapi isinya kini satu baris yang memanggil gate —
aturannya benar-benar hidup di satu tempat saja.

### Verifikasi kesetaraan perilaku

Karena test hanya mengasersi `code`, string pesan diverifikasi terpisah: kedua gate diinstansiasi
langsung dan **ke-12 pesan 403** dibandingkan byte-per-byte dengan literal versi lama.

| Pemeriksaan | Hasil |
|---|---|
| `php -l` (4 berkas) | No syntax errors |
| 8 pesan `WAITING_SPV_APPROVAL` + `HRD_REQUIRED` | ✅ identik byte-per-byte |
| 4 template `SPV_REQUIRED` (termasuk keanehan "Persetujuan" lembur) | ✅ identik |
| Sisa kode gate lama (`isSpvOrAbove`, `isSameDivisionSpv`, 5 kode error) | 0 sisa di controller |
| Delegasi gate di controller | 8 titik, sesuai harapan |
| Test approval terkait | 52/52 lulus, 307 asersi |
| **Suite penuh** | **1031/1031 lulus, 5.788 asersi** |
| Ukuran berkas | 12.944 → **12.635** baris (−309) |

> Catatan: pada eksekusi suite pertama, `ReceiptBranchFilterTest` gagal karena timeout jaringan 10s
> ke Gemini Vision API — tidak berkaitan dengan perubahan ini. Terbukti: test itu lulus saat
> dijalankan ulang **dengan** perubahan aktif, dan suite penuh berikutnya 1031/1031.

### Dampak samping yang menguntungkan

- **Asimetri cuti vs lembur kini terdokumentasi di kode**, bukan tersembunyi sebagai `else` vs
  `elseif` yang mudah "dirapikan" orang berikutnya. Method abstrak memaksa keputusan sadar.
- **Komentar "Guard harus IDENTIK dengan approveLeave()" tidak lagi diperlukan** — kewajiban itu
  sekarang ditegakkan struktur kode, bukan ingatan pengembang.
- Controller susut **309 baris** — pengurangan terbesar dari satu perubahan sejauh ini.

### Yang TIDAK diubah (sengaja)

| Hal | Alasan |
|---|---|
| Kata "Persetujuan" di pesan `rejectOvertime()` | Perbaikan perilaku, bukan refactor. Perlu keputusan terpisah agar klien tidak kaget |
| Perbedaan fallback cuti vs lembur | Domain cuti belum tentu punya bug yang sama; menyeragamkan tanpa analisis = mengubah otorisasi |
| Gate dibuat `static` seperti `LateFineCalculator` | Gate perlu `abstract`/override; `app()` juga memudahkan mock di test nanti |


---

## 9. Catatan Pengerjaan #3 & #4 — Salinan Hidup Perhitungan Lembur + 4 Fungsi Mati (SELESAI 2026-10-12)

Dua prioritas dikerjakan sekaligus karena menyentuh rumus yang sama dari dua sisi: #3 mengurus
salinan yang **masih hidup**, #4 membuang wrapper yang sudah **mati**.

### Akar masalah

Rumus jam kerja & lembur hidup di **tiga** tempat sekaligus:

| Tempat | Status | Siapa yang memakainya |
|---|---|---|
| `AttendanceRecalculator` | aktif | `checkOut()` manual & koreksi manual HRD |
| 4 wrapper di `AttendanceController` | **mati** | tidak ada pemanggil sama sekali |
| 4 salinan privat di `AutoCheckoutCommand` | **aktif** | penutupan presensi otomatis tiap malam |

Yang berbahaya bukan wrapper matinya — itu sekadar sampah. Yang berbahaya adalah salinan di
command: ia **jalur produksi yang tidak punya nama**. Setiap malam ia yang menghitung
`work_minutes` & `overtime_minutes` ratusan baris presensi, tapi rumusnya tersembunyi sebagai
method `private` di sebuah console command — bukan tempat orang mencari aturan lembur.

### Verifikasi sebelum menyentuh apa pun

Prioritas #3 di §3.8.1 berasumsi keempat salinan itu *drift* yang harus digabung ke
`AttendanceRecalculator`. **Asumsi itu salah**, dan kodenya sendiri mengatakannya. Di
`doAutoCheckout()` ada blok komentar bertanggal **2026-10-06** berjudul
*"ATURAN DUPLIKASI SENGAJA — JANGAN ganti dengan AttendanceRecalculator tanpa PR khusus"*.

Ketiga alasan yang disebutnya **terverifikasi benar**, bukan rasionalisasi:

1. **Dua kolom DB yang berbeda.** Command membaca `attendance_settings.overtime_enabled`
   (migrasi `2026_06_26_000003`); service membaca `users.overtime_enabled` lebih dulu
   (migrasi `2026_09_12_190300`). Karyawan yang lemburnya di-OFF per-orang **tetap**
   dapat lembur dari auto-checkout. Itu perilaku berbeda, bukan bug penulisan.
2. **`$schedule` boleh NULL** di command (karyawan terhapus / tanpa kantor); service mewajibkan array.
3. **Command tidak pernah menilai `early_leave`** atas inisiatif sendiri — sistem tidak boleh
   menuduh karyawan pulang cepat saat ia justru *lupa* check-out. Pengecualian tunggalnya
   diputuskan HRD lewat `missed_checkout_penalty_mode = 'mark_early_leave'`.

**Konsekuensinya:** menggabungkan salinan command ke `AttendanceRecalculator` akan mengubah
nilai `overtime_minutes` — dan karena lembur masuk payroll, mengubah **gaji**. Itu perubahan
perilaku, bukan refactor. Jadi #3 dikerjakan sebagai **ekstraksi yang mempertahankan semantik**,
bukan penggabungan.

Satu divergensi **baru** juga ditemukan, yang belum tercatat di §3.8.1 dan **tidak** disebut
blok komentar 2026-10-06 — lihat "Temuan baru" di bawah.

### Temuan baru — `isNonWorkingDay()` memang TIDAK identik

Komentar lama di command menuliskan *"sama dengan AttendanceController"*, dan §3.8.1 ikut
mengasumsikannya. Pembacaan baris-per-baris membuktikan keduanya **berbeda perilaku**:

| Aspek | `AttendanceController::isNonWorkingDay()` | salinan di command |
|---|---|---|
| Parameter | `($date, $companyId, $officeId, $userId)` | `($date, $companyId, $userId)` — **tanpa `$officeId`** |
| Penyaringan cabang | query libur di-scope `attendance_setting_id` | **tidak** — libur satu cabang ikut berlaku di cabang lain |
| `Holiday::excludedUsers` | libur dilewati bila karyawan dikecualikan | **tidak ada** pengecualian itu |
| Sumber hari off | `resolveOffDatesForUser()` (batch, ter-cache) | `ShiftController::resolveSchedule()` langsung |

Dua baris pertama berdampak nyata ke nilai `is_holiday` dan `overtime_minutes`: libur khusus
cabang A bisa membuat presensi cabang B dihitung full lembur, dan karyawan yang dikecualikan
dari suatu libur tetap dihitung libur saat auto-checkout.

Perbedaan ini **TIDAK diperbaiki di sini**. Menyeragamkannya mengubah angka lembur presensi
nyata, jadi ia butuh keputusan HRD — sama kelasnya dengan tiga perbedaan di blok 2026-10-06.
Yang dilakukan hanyalah **mendokumentasikannya di tempat ia hidup** (docblock
`AutoCheckoutCalculator::isNonWorkingDay()`) agar tidak lagi ditemukan ulang dari nol, dan
mengganti komentar lama yang keliru mengaku "sama dengan controller".

### Perubahan yang dilakukan

**#3 — Ekstraksi salinan hidup**

1. **`app/Support/Duration.php`** (baru, 1 method). `formatMinutes` sebelumnya ditulis **dua kali**
   dengan signature berbeda: `AttendanceController::formatMinutes(?int)` dan
   `AutoCheckoutCommand::formatMinutes(int)`. Isinya identik, jadi notifikasi auto-checkout dan
   payload API presensi memformat angka yang sama lewat dua jalur kode. Ini satu-satunya dari
   keempat helper yang **murni** (tanpa DB, tanpa jam sistem) sehingga aman digabung utuh.
   16 pemanggil dialihkan: 14 di controller, 2 di command.
2. **`app/Services/Attendance/AutoCheckoutCalculator.php`** (baru, 256 baris, 3 method).
   Menampung `resolveWorkStart()`, `calculateOvertime()`, dan `isNonWorkingDay()` **apa adanya** —
   rumusnya tidak diubah satu baris pun. Yang berubah hanya *tempat*nya: dari method `private`
   di console command menjadi service ber-nama dengan docblock yang menjelaskan **mengapa** ia
   bukan `AttendanceRecalculator`.
3. **`AutoCheckoutCommand`** menerima service itu lewat constructor injection
   (`__construct(FcmService $fcm, AutoCheckoutCalculator $calc)`), 6 pemanggilan diarahkan ke
   `$this->calc->…`, dan 169 baris salinan dibuang (606 → 437 baris).
4. Import mati `use App\Models\OvertimeApproval;` ikut dihapus (tidak dipakai di mana pun).
5. Blok "ATURAN DUPLIKASI SENGAJA" **dipertahankan** di `doAutoCheckout()`, hanya diperbarui agar
   menunjuk ke service alih-alih "salinannya sendiri".

**#4 — Hapus fungsi mati**

| Fungsi | Lokasi lama | Isi |
|---|---|---|
| `resolveWorkStart()` | 381–388 | wrapper 2 baris → `AttendanceRecalculator` |
| `defaultLeaveQuota()` | 503–522 | `public static`, query `AttendanceSetting` |
| `calculateOvertime()` | 9884–9901 | wrapper → `AttendanceRecalculator` |
| `checkEarlyLeave()` | 9903–9913 | wrapper → `AttendanceRecalculator` |

Total 61 baris (termasuk blok komentarnya) + 13 baris `formatMinutes` = **74 baris** hilang dari
controller (12.635 → 12.562).

`private const DEFAULT_LEAVE_QUOTA = ['cuti' => 12];` **DIPERTAHANKAN** — `defaultLeaveQuota()`
memang mati, tapi konstantanya masih dipakai 3 pemanggil lain (baris 2785, 3103, 11819).

### `sendFcmPush` — sudah tidak ada

Prioritas #4 menyebut wrapper `sendFcmPush` sebagai sasaran kelima. `grep -rn 'sendFcmPush'
app/ routes/ tests/` mengembalikan **nol** hasil: wrapper itu sudah ikut terhapus saat
pengerjaan #1 (lihat §7), dan §6 baris 4 memang sudah mencatatnya. Jadi tidak ada yang tersisa
untuk dihapus di sini — bukan dikerjakan, melainkan sudah selesai sebelumnya.

### Verifikasi

| Pemeriksaan | Hasil |
|---|---|
| `php -l` 4 berkas tersentuh | bersih |
| `grep` pemanggil sisa (`defaultLeaveQuota` `->checkEarlyLeave` `this->calculateOvertime` `this->resolveWorkStart` `this->formatMinutes` `sendFcmPush`) di `app/ routes/ tests/ database/ config/` | nol, selain internal `AttendanceRecalculator` sendiri |
| Konstanta `DEFAULT_LEAVE_QUOTA` masih ada & 3 pemanggilnya utuh | ✅ |
| Test bertarget (`MultiTimezoneAttendance` `PenaltyBonusAttendance` `SettingSnapshot` `ShiftCrossDayAttendance`) | 60/60, 372 asersi |
| **Suite penuh** | **1031/1031, 5.788 asersi** — identik dengan baseline sebelum refaktor |

Angka suite yang sama persis adalah buktinya: perilakunya tidak bergeser sedikit pun, hanya
tempat tinggal rumusnya yang berpindah.

### Dampak samping

- `AutoCheckoutCommand` sekarang punya dependensi constructor kedua. Enam pemanggil
  `attendance:auto-checkout` (scheduler `routes/console.php:13`, `AttendanceController:243`, dan
  4 berkas test) memakai `Artisan::call()` / `$this->artisan()` sehingga service-nya di-resolve
  container otomatis — tidak ada yang perlu diubah.
- `AutoCheckoutCalculator` kini **bisa di-test langsung** tanpa menjalankan seluruh command.
  Belum ada test unit khususnya; perilakunya masih dijaga lewat test command yang ada.
- `Duration::formatMinutes()` dipakai payload API & notifikasi push. Bentuk `"2j 15m"` / `"2j"` /
  `"0j"` **tidak boleh diubah** tanpa menyesuaikan klien mobile & web.

### Yang TIDAK diubah (sengaja)

| Hal | Alasan |
|---|---|
| Rumus command **tidak** digabung ke `AttendanceRecalculator` | 3 perbedaan semantik terverifikasi (kolom `overtime_enabled` kantor vs user, `$schedule` nullable, tidak pernah `early_leave`). Menggabungkan = mengubah nilai lembur → gaji. Butuh PR khusus + keputusan HRD, persis seperti yang diminta blok komentar 2026-10-06 |
| Divergensi `isNonWorkingDay()` (tanpa `$officeId`, tanpa `excludedUsers`) | Memperbaikinya mengubah `is_holiday` & lembur baris presensi nyata. Didokumentasikan, bukan ditambal diam-diam |
| `resolveWorkStart()` command mengembalikan Carbon berzona cabang (service: zona asal check-in) | Instan absolutnya sama → `work_minutes` identik. Dibiarkan sebagai perbedaan tercatat, bukan diseragamkan sambil lalu |
| `AttendanceRecalculator::recalculate()` tetap mengembalikan `work_start` | Tidak ada pemanggil yang memakainya, tapi membuang kunci dari array hasil di luar lingkup #3/#4 |
| Blok "ATURAN DUPLIKASI SENGAJA" tetap di `doAutoCheckout()` | Komentarnya justru jadi lebih berguna sekarang: ia menunjuk ke service ber-nama, bukan ke salinan tanpa nama |
