# 📚 Dokumentasi ExpenseFlow

Dokumentasi teknis sistem **ExpenseFlow** — platform manajemen expense ( reimbursement struk & invoice vendor ) yang terintegrasi dengan **sistem presensi WFH/onsite karyawan** (check-in GPS + selfie, shift, cuti, lembur, auto-checkout).

## Struktur Monorepo

```
backend-gawe/
├── expenseflow-backend/    → Backend API  (Laravel 13 / PHP 8.3)
├── expenseflow-web/        → Frontend Web Dashboard  (React 19 + TypeScript + Vite)
├── expenseflow-mobile/     → Aplikasi Mobile Karyawan  (Flutter / Dart 3.12)
├── asset/
└── doc/                    → Folder dokumentasi (folder ini)
```

## Daftar Dokumen

| File | Isi |
|------|-----|
| [01-OVERVIEW.md](01-OVERVIEW.md) | Gambaran umum sistem, arsitektur, alur data antar komponen |
| [02-BACKEND.md](02-BACKEND.md) | Backend Laravel: struktur folder, controller, model, service, job, scheduler |
| [03-WEB-FRONTEND.md](03-WEB-FRONTEND.md) | Web dashboard React: komponen, API layer, auth |
| [04-MOBILE-FLUTTER.md](04-MOBILE-FLUTTER.md) | Aplikasi Flutter: screens, provider, service, notifikasi |
| [05-DATABASE.md](05-DATABASE.md) | Database MySQL: semua tabel migrasi, relasi, seeder |
| [06-API-ENDPOINTS.md](06-API-ENDPOINTS.md) | Referensi lengkap seluruh endpoint REST API |

## Ringkasan Cepat

| Aspek | Detail |
|-------|--------|
| Backend | Laravel ^13.8, PHP ^8.3, Sanctum (token API), queue `database`, OCR Tesseract/Google Vision |
| Database | MySQL (`expenseflow_db`) |
| Web | React 19, TypeScript, Vite 6, Tailwind CSS 4, Recharts, Leaflet, Lucide icons |
| Mobile | Flutter SDK ^3.12, Provider, geolocator, flutter_map, FCM (firebase_messaging), local notifications |
| Auth | Token Bearer (Sanctum). Web token berlaku 24 jam; mobile `expires_at = NULL` (login persisten sampai logout) |
| Platform guard | Header `X-Platform: web` / `mobile` — beberapa route dibatasi per platform |

## Fitur Utama

1. **Expense Management**
   - Scan struk via mobile (OCR → klaim otomatis), approval oleh Finance di web
   - Invoice vendor: input manual atau scan, approval multi-level
   - Master vendor, audit log, notifikasi
2. **Presensi (Attendance)**
   - Check-in/check-out dari mobile dengan **GPS + radius kantor** dan **selfie**
   - Mode WFH / onsite / lapangan per karyawan
   - **Shift** custom: template jadwal mingguan, assignment individual/massal, roster harian, kalender bulanan
   - **Auto-checkout** terjadwal (scheduler tiap 5 menit) + reminder push notification
   - Lembur: approval HRD, terhubung auto-checkout
3. **Cuti & Izin**
   - Pengajuan izin/sakit/cuti/WFH dari mobile, approval HRD
   - Saldo cuti per tahun, hari libur nasional, **cuti bersama** (opt-in karyawan + auto-decline)
4. **Keamanan**
   - Device binding (1 akun = 1 device, pindah device butuh approval HRD — cegah titip absen)
   - Rate limit login (5/menit per email, 120/menit per IP)
