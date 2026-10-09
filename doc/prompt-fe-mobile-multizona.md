# Prompt — Frontend Mobile (Flutter): Presensi Multi-Zona Waktu (WIB/WITA/WIT)

> Salin seluruh blok di bawah ini ke sesi Claude Code baru dengan working directory
> `E:\koding\coba\backend-gawe\expenseflow-mobile`.

---

Backend presensi ExpenseFlow sudah selesai dibuat sadar zona waktu untuk ketiga zona Indonesia
(WIB/WITA/WIT) dan sudah teruji (12 test, 105 assertion, suite penuh hijau). Sekarang saya butuh
sisi **aplikasi Flutter**-nya.

**Baca dulu sebelum menulis kode:** `doc/rules.md` section **"Presensi Multi-Zona Waktu (WIB /
WITA / WIT) — 2026-10-08"** (sekitar baris 2753 sampai akhir file) dan `doc/06-API-ENDPOINTS.md`
bagian **"Presensi Mobile"** — khususnya sub-bagian `#### Objek timezone (multi-zona 2026-10-08)`
dan `#### Error presensi yang membawa label zona`. Keduanya kontrak API yang mengikat — jangan
menebak bentuk payload, dan jangan mengubah backend.

## Tiga aturan yang tidak boleh dilanggar

1. **Zona otoritatif adalah zona kantor cabang penempatan karyawan**, bukan zona perangkat dan
   bukan zona lokasi fisik karyawan. Status telat/hadir, jadwal, lembur, dan tanggal presensi
   semuanya diputuskan server di zona cabang. Semua jam dalam payload **sudah** jam lokal cabang.
2. **Flutter tidak boleh mengonversi zona atau membandingkan jam sendiri.** Tidak ada
   `DateTime.now()` yang dipakai untuk keputusan presensi, tidak ada aritmetika offset, tidak ada
   literal `'WIB'`. Seluruh teks untuk pengguna berasal dari payload API.
3. **Jam presensi mutlak dari jam server** (aturan Anti-Clock-Spoofing `doc/rules.md`). Jam
   perangkat tidak pernah dipercaya; zona lokasi fisik diturunkan server dari GPS, bukan dari
   jam/zona perangkat.

## Bentuk payload yang akan dipakai

`POST /attendance/check-in`, `POST /attendance/check-out`, dan `GET /attendance/status`
mengembalikan objek ini:

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

Tiga hal yang mudah salah dibaca — tolong perhatikan:

- ⚠️ **Gerbang tampilan adalah `is_cross_timezone`, BUKAN `local != null`.** Saat karyawan berada
  di zona yang sama dengan cabangnya, `local` **tetap terisi** (nilainya sama dengan `branch`) dan
  `is_cross_timezone` = `false`. `local` bernilai `null` hanya bila zona lokasi memang tidak dapat
  ditentukan. Kalau gerbangnya dibalik, badge "lokasi Anda" tidak akan pernah muncul.
- ⚠️ **Sebelum check-in, `/attendance/status` belum punya data lintas zona.** Pada cabang
  "belum check-in", backend memanggil `describe()` dengan zona lokasi `null` (belum ada GPS), jadi
  `local` = `null` dan `is_cross_timezone` selalu `false`. Banner lintas zona baru tersedia
  **setelah** check-in. Jangan membuat UI yang mengandalkan banner itu muncul di layar beranda
  sebelum presensi.
- String **`display` dibentuk backend** dan sudah final — tampilkan **apa adanya**. Jangan
  menyusun ulang dari `branch_time` + `label`; justru itu sumber bug yang ingin dihindari.

## Pekerjaan 1 — Model: dua field baru di `OfficeArea`

File: `lib/models/attendance_model.dart:162-192`

`offices[]` dan kantor utama di `/attendance/status` serta riwayat kini membawa `timezone` dan
`timezone_label`. Tambahkan dua field ke `OfficeArea` + `fromJson`, mengikuti gaya fallback yang
sudah ada di kelas itu (`json['name'] ?? json['office_name'] ?? 'Kantor'`):

```dart
final String? timezone;        // 'Asia/Makassar'
final String? timezoneLabel;   // 'WITA'
```

Beri default aman bila field tidak ada (API lama / endpoint yang belum mengirim) — jangan sampai
`fromJson` melempar.

Sekalian tambahkan representasi objek `timezone` di atas sebagai model/kelas kecil tersendiri
supaya tidak diakses sebagai `Map` mentah di seluruh layar.

## Pekerjaan 2 — Hapus 13 literal `'WIB'` hardcode

Semua ini harus memakai label dari API (`timezone.branch.label`, `timezone_label`, atau
`flexitime_config.timezone_label`). Karyawan cabang Makassar saat ini melihat "WITA" ditulis
"WIB" — salah di mata pengguna, dan menyesatkan bagi karyawan yang sedang dinas.

| File | Baris | Konteks |
|---|---|---|
| `lib/utils.dart` | `:59` (komentar doc), `:93` | `formatDateTimeIndonesian()` — `return '... $hour:$min WIB';` |
| `lib/screens/home_screen.dart` | `:1362-1363` (komentar), `:1370`, `:1372` | label jadwal kerja `'08:00 — 17:00 WIB'` |
| `lib/screens/presensi_map_screen.dart` | `:548-549` | pesan gerbang pulang cepat flexitime |
| `lib/services/notification_service.dart` | `:162`, `:208` | body notifikasi auto-checkout |
| `lib/widgets/flexitime_progress_widget.dart` | `:241`, `:542` | `'Target Pulang: $targetCheckout WIB'` |

⚠️ `lib/utils.dart:60-94` (`formatDateTimeIndonesian`) melakukan `dt = parsed.toLocal()` — ini
**mengikuti zona perangkat**, jadi bukan hanya labelnya yang salah tapi jamnya juga bergeser bagi
karyawan yang perangkatnya tidak UTC+7. Untuk nilai yang sudah jam lokal cabang, format dari
string payload tanpa `toLocal()`. Teruskan label zona sebagai parameter, jangan dipanggil dari
state global.

⚠️ Khusus `notification_service.dart`: **logika penjadwalannya sudah benar** —
`tz.TZDateTime.from(...)` + `absoluteTime` menangani ISO8601 beroffset cabang dengan tepat.
**Jangan diubah**; cukup ganti literal label di teks notifikasi.

Soal label untuk notifikasi: `attendance:send-reminders` **mengirim** `timezone` dan
`timezone_label` sebagai data field FCM (ketiga jenis: `checkin_reminder`, `checkout_reminder`,
`cutoff_warning`) — pakai itu. Tetapi `attendance:auto-checkout` **tidak** mengirim keduanya;
payloadnya hanya `type`, `attendance_id`, dan `auto_checkout_at`, dengan label zona ada di dalam
**teks body** yang sudah dibentuk server. Jadi untuk notifikasi auto-checkout, tampilkan body dari
server apa adanya — jangan membaca key `timezone_label` yang tidak ada di sana.

## Pekerjaan 3 — Hentikan perbandingan jam memakai jam perangkat

Ini bagian paling penting karena menyangkut **keputusan**, bukan tampilan.

### 3a. Gerbang pulang cepat flexitime

File: `lib/screens/presensi_map_screen.dart:530-549`

```dart
final now = DateTime.now();
final nowTimeStr = '${now.hour.toString().padLeft(2,'0')}:${now.minute...}';
final isBeforeCore = nowTimeStr.compareTo(coreEndStr) < 0;
final isBeforeTarget = targetCheckoutStr != null && nowTimeStr.compareTo(targetCheckoutStr) < 0;
```

`coreEndStr` dan `targetCheckoutStr` adalah jam lokal **cabang**, sedangkan `now` jam **perangkat**.
Untuk karyawan yang dinas lintas zona, perbandingan ini bergeser 1–2 jam — dialog peringatan
muncul/tidak muncul pada waktu yang salah. Ganti sumber "sekarang" dengan
`timezone.branch_time` dari respons `/attendance/status` (sudah format `HH:mm`, langsung bisa
di-`compareTo`).

Catat bahwa ini **hanya peringatan UX**; server tetap memutuskan dan menolak sendiri lewat 422.
Jadi jangan menambah logika kompensasi di klien — cukup pakai jam server.

### 3b. `effectiveCheckInTime` → `flexElapsedGrossMinutes`

File: **`lib/presensi_provider.dart:86-100`** (perhatikan: file ini ada di `lib/`, **bukan**
`lib/providers/`)

```dart
final now = DateTime.now();
var dt = DateTime(now.year, now.month, now.day, h, m);
```

Fungsi ini merekonstruksi instan check-in dari string `HH:mm` jam lokal cabang menggunakan
tanggal & zona **perangkat**. Hasilnya mengalir ke `flexElapsedGrossMinutes` (`:103` dst), jadi
karyawan lintas zona melihat durasi kerja yang salah — dan progress flexitime-nya ikut salah.

Perbaiki dengan memakai jam cabang dari server sebagai acuan "sekarang" (`timezone.branch_time`)
sehingga selisihnya dihitung antar dua nilai dalam **satu** zona yang sama. Jangan menambal
dengan offset manual dari `offset_minutes`; selisih dua jam dinding di zona yang sama sudah
cukup, dan tidak punya cabang kasus yang bisa salah.

Tambahkan juga getter label zona di provider ini (pola getter flexitime yang sudah ada di
`:64-76`) supaya widget tidak membongkar `Map` mentah.

## Pekerjaan 4 — Tampilkan konteks lintas zona

- Pada kartu hasil check-in / check-out dan di beranda setelah presensi: tampilkan
  `timezone.display` apa adanya. Saat `is_cross_timezone == true`, string itu sudah berbentuk
  `"08:20 WIB (10:20 WIT lokasi Anda)"` — tampilkan menonjol, karena di sinilah karyawan yang
  sedang dinas perlu paham **mengapa** ia dinilai telat padahal jam di ponselnya masih pagi.
  Pertimbangkan satu baris penjelas (mis. "Dinilai dengan jam kantor
  {timezone.branch.office_name}") supaya tidak terasa seperti bug aplikasi.
- `flexitime_config` kini membawa `timezone_label`; nilai `arrival_start`, `core_end`,
  `target_checkout_time` sudah jam lokal cabang. Pakai label itu di
  `flexitime_progress_widget.dart`.
- Error presensi membawa `timezone_label` — pakai di pesan, jangan tempel `'WIB'`:
  - **403** check-in lewat cutoff: `cutoff_at`, `work_start_time`, `cutoff_minutes`,
    `timezone_label`
  - **422** `time_inversion`: `check_in_time`, `check_out_time`, `timezone_label`
  - **422** `is_cooldown`: `min_checkout_minutes`, `check_in_time`, `earliest_checkout_at`,
    `remaining_minutes`, `timezone_label`
  - **422** jendela WFH juga membawa `timezone_label`
- `reminder_at`, `auto_checkout_at`, `scheduled_auto_checkout_at` tetap ISO8601 **dengan offset**
  (kini offset cabang: `+07:00`/`+08:00`/`+09:00`). Parsing yang ada sudah benar.

## Pekerjaan 5 (opsional) — Kirim audit zona perangkat

`POST /attendance/check-in` menerima dua field **opsional**:

- `device_timezone` — IANA, mis. `"Asia/Jayapura"` (`nullable|string|timezone`)
- `device_utc_offset_minutes` — integer, `nullable|integer|between:-840,840`

Keduanya **murni audit** dan tidak pernah memengaruhi perhitungan apa pun. Gunanya: selisih antara
klaim perangkat dan zona hasil GPS menjadi sinyal kecurangan jam. Kirim bila mudah
(`DateTime.now().timeZoneOffset.inMinutes` dan nama zona dari paket `timezone` yang sudah ada di
proyek). Kalau menyulitkan, lewati saja — backend tidak mewajibkannya.

## Yang TIDAK perlu dikerjakan

- Jangan ubah backend apa pun.
- Jangan ubah logika penjadwalan notifikasi lokal (`tz.TZDateTime.from` + `absoluteTime`) — sudah
  benar.
- Jangan menambahkan konversi zona di klien dalam bentuk apa pun.

Tolong kerjakan bertahap: Pekerjaan 1 lalu 3 (keduanya menyangkut kebenaran data), kemudian 2 dan
4, lalu 5 bila sempat. Setelah selesai jalankan `flutter analyze` (dan test bila ada) lalu
laporkan hasilnya apa adanya.
