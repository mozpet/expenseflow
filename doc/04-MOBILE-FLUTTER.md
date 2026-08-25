# 04 — Mobile App (Flutter)

Path project: `expenseflow-mobile/` (nama package: `cobain`)

## Tech Stack

| Aspek | Nilai |
|-------|-------|
| Framework | Flutter (Dart SDK ^3.12) |
| State management | Provider 6 |
| HTTP | http ^1.2 |
| GPS | geolocator ^13 + permission_handler |
| Peta | flutter_map ^8 (OpenStreetMap) + latlong2 |
| Kamera/gambar | image_picker (selfie & foto struk), file_picker (lampiran izin) |
| Notifikasi push | firebase_core + firebase_messaging (FCM) |
| Notifikasi lokal | flutter_local_notifications + timezone (reminder checkout) |
| Penyimpanan lokal | shared_preferences (token, user, FCM token) |
| Format tanggal | intl |

## Struktur Folder `lib/`

```
lib/
├── main.dart                    → entry point, init Firebase, MultiProvider
├── config/api_config.dart       → BASE_URL API (hardcode, ganti sesuai host test)
├── services/
│   ├── api_service.dart         → seluruh pemanggilan REST (auth, presensi, cuti)
│   └── notification_service.dart→ FCM + local notification scheduling
├── providers/
│   ├── auth_provider.dart       → state login/logout, device binding
│   ├── receipt_provider.dart    → state klaim struk mobile
│   └── shift_provider.dart      → jadwal shift karyawan
├── presensi_provider.dart       → state check-in/out, lokasi, status hari ini
├── photo_provider.dart          → penyimpanan foto selfie/struk antar screen
├── image_service.dart           → kompresi/encode gambar sebelum upload
├── utils.dart                   → helper format
└── screens/
    ├── login_screen.dart            → login karyawan
    ├── home_screen.dart             → bottom-nav utama (beranda, riwayat, izin, profil)
    ├── presensi_map_screen.dart     → peta OSM + tombol check-in/out (GPS + radius)
    ├── presensi_history_screen.dart → riwayat presensi pribadi
    ├── riwayat_screen.dart          → riwayat aktivitas umum
    ├── status_screen.dart           → status presensi hari ini
    ├── izin_cuti_screen.dart        → daftar pengajuan izin/cuti/saldo
    ├── ajukan_izin_screen.dart      → form pengajuan izin/sakit/cuti/WFH (+ lampiran)
    ├── detail_pengajuan_screen.dart → detail & status pengajuan
    ├── jadwal_shift_screen.dart     → kalender jadwal kerja bulanan + banner perubahan shift
    ├── notification_screen.dart     → notifikasi push
    ├── profile_screen.dart          → profil & logout
    ├── submit_step1_screen.dart     → klaim struk langkah 1 (foto + OCR review)
    └── submit_step2_screen.dart     → klaim struk langkah 2 (data klaim & submit)
```

## Alur Utama di Mobile

### Presensi Check-in/out
1. `presensi_map_screen` mengambil posisi GPS (`geolocator`) dan menampilkan marker kantor + jarak pada peta OSM (`flutter_map`).
2. Foto selfie via `image_picker` → dikompres `image_service.dart`.
3. `PresensiProvider` memanggil `POST /attendance/check-in` / `/check-out` dengan koordinat + foto; backend memvalidasi radius & jadwal.
4. Reminder checkout terjadwal lewat `flutter_local_notifications`; jika ada approval lembur, window checkout diperpanjang.

### Klaim Struk
- `submit_step1` → foto struk, preview hasil OCR.
- `submit_step2` → isi nominal/vendor/klaim, submit ke `POST /employee/receipts`, lalu `POST .../submit`.

### Cuti Bersama
- Banner opt-in dari `GET /attendance/collective-leaves`; jika saldo tidak cukup backend balas **422** dan UI menonaktifkan tombol "ikut".

## Auth Handling

- Token & user disimpan di `shared_preferences` — login persisten: token dari backend punya `expires_at = NULL`, jadi aplikasi tetap masuk sampai user menekan Logout.
- Device id dikirim saat login untuk device binding; ganti perangkat membuat permintaan approval ke HRD.

## Konfigurasi API

`lib/config/api_config.dart`:

```dart
static const String baseUrl = 'http://127.0.0.1:8000/api/v1';
// alternatif LAN di-comment di file yang sama
```

> Ganti sesuai IP host saat test di device fisik (emulator Android memakai `10.0.2.2` untuk localhost PC).

Endpoint yang dipanggil mobile (lihat juga [06-API-ENDPOINTS.md](06-API-ENDPOINTS.md)):

```
POST /login · POST /logout · GET /me
POST /attendance/check-in|check-out · GET /attendance/status · GET /attendance/my
GET  /attendance/my-leaves · POST /attendance/leave-request · GET /attendance/leave-balance
GET  /attendance/my-overtime · POST /attendance/fcm-token
GET  /attendance/collective-leaves · POST /attendance/collective-leave/{id}/respond
GET  /attendance/shift-updates · POST /attendance/dismiss-shift-update
GET  /attendance/my-schedule-calendar
GET  /employee/my-schedule · CRUD /employee/receipts (+submit/claim)
```

## Menjalankan Mobile

```bash
cd expenseflow-mobile
flutter pub get
# konfigurasi firebase (google-services.json / GoogleService-Info.plist) bila pakai FCM
flutter run
```
