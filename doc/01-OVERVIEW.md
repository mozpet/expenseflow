# 01 — Overview Sistem

## Apa itu ExpenseFlow

ExpenseFlow adalah sistem manajemen biaya operasional kantor yang mencakup dua domain besar:

1. **Expense (pengeluaran)**
   - Karyawan menscan **struk belanja/reimbursement** lewat aplikasi mobile → OCR membaca nominal & vendor → karyawan klaim → Finance approve/reject di web.
   - **Invoice vendor**: input manual atau scan, disetujui bertingkat oleh Finance/Admin.
2. **Presensi & Absensi Karyawan**
   - Check-in/check-out via mobile dengan validasi **GPS (radius kantor)** + **selfie**.
   - Mendukung mode **onsite (kantor), WFH, dan lapangan**.
   - Jadwal kerja fleksibel lewat sistem **Shift** (template mingguan + assignment per karyawan).
   - Fitur pendukung: cuti/izin/sakit, saldo cuti, hari libur nasional & cuti bersama, lembur dengan approval, auto-checkout otomatis.

## Arsitektur Tingkat Tinggi

```
┌──────────────────┐        HTTPS/JSON         ┌─────────────────────┐
│  Mobile Flutter  │ ◄──────────────────────► │                     │
│  (karyawan)      │    Bearer token Sanctum   │   Backend Laravel   │
└──────────────────┘                           │   REST API /v1      │
                                               │                     │
┌──────────────────┐        HTTPS/JSON         │  • Auth Sanctum     │
│  Web React       │ ◄──────────────────────► │  • RBAC role        │
│  (HRD/Finance/   │    Bearer token + header  │  • Scheduler        │
│   Admin/SAdmin)  │    X-Platform: web        │  • Queue + OCR      │
└──────────────────┘                           └─────────┬───────────┘
                                                         │
                                              ┌──────────▼──────────┐
                                              │   MySQL             │
                                              │   expenseflow_db    │
                                              └─────────────────────┘
                                                        ▲
                                              ┌─────────┴──────────┐
                                              │ FCM (Firebase)     │
                                              │ push notification  │
                                              └────────────────────┘
```

- **Komunikasi** semua komponen ke backend memakai REST JSON dengan prefix `/api/v1`.
- **Autentikasi**: Laravel Sanctum token. Web menyimpan token + expiry 24 jam; mobile login persisten (`expires_at = NULL`, logout manual).
- **Platform guard**: request wajib/kadang menyertakan header `X-Platform` (`web`/`mobile`) untuk membedakan asal client; beberapa endpoint hanya boleh dari platform tertentu.
- **Multi-role**: `super_admin`, `admin`, `hrd`, `finance`, `employee` (role middleware).

## Alur Data Utama

### A. Klaim Struk (Receipt)
1. Mobile: karyawan foto struk → upload ke `POST /employee/receipts` (+ data OCR hasil scan).
2. Backend: simpan receipt + image; job `ProcessOcrJob` diproses via queue (`database` driver) memakai driver Tesseract atau Google Vision (`OCR_DRIVER` di `.env`).
3. Karyawan submit klaim → status `submitted`.
4. Web Finance: inbox receipt (`GET /dashboard/receipts`) → approve/reject dengan catatan.
5. Semua aksi tercatat di `activity_logs` dan memicu row `notifications` (dan push FCM bila applicable).

### B. Presensi
1. Mobile ambil lokasi GPS (geolocator) + foto selfie.
2. `POST /attendance/check-in` → backend validasi:
   - jarak terhadap koordinat kantor (service `LocationService`, haversine vs radius),
   - apakah hari libur/cuti bersama,
   - jadwal shift efektif user (`ShiftRestService`, `UserShift`, `ShiftSchedule`),
   - window WFH check-in (setting per kantor).
3. Status hadir ditentukan: `present` / `late` / `wfh` / `field`; checkout menghasilkan `early_leave` bila lebih awal dari jadwal.
4. Lupa checkout → scheduler `attendance:auto-checkout` (tiap 5 menit) menutup absensi otomatis + catat overtime bila ada approval lembur aktif.

### C. Cuti Bersama
1. HRD buat holiday bertipe collective di kalender (`POST /dashboard/attendance/holidays`).
2. Mobile: karyawan melihat daftar (`GET /attendance/collective-leaves`) dan pilih ikut/tidak (`POST /attendance/collective-leave/{id}/respond`). Saldo tidak cukup → HTTP 422 + banner, tombol ikut dinonaktifkan.
3. Belum memilih saat tanggal tiba → command `attendance:auto-decline-collective-leave` menandai `declined`.

### D. Shift
1. HRD membuat template shift (jam kerja per hari, penanda WFH/lapangan/libur, warna) → `shifts` + `shift_schedules`.
2. Assign ke karyawan individual (`assign-shift`) atau massal (`bulk-assign`) dengan rentang tanggal → `user_shifts`.
3. Tiap jam 00:01 command `attendance:sync-shift-wfh` menyinkronkan flag `wfh_enabled`/`radius_enabled` user sesuai shift hari itu; HRD tetap bisa override siang harinya.

## Teknologi per Komponen

| Komponen | Teknologi | Peran |
|----------|-----------|-------|
| `expenseflow-backend` | Laravel ^13.8, PHP ^8.3, Sanctum, queue database, Tesseract OCR | REST API, business logic, scheduler |
| `expenseflow-web` | React 19, TypeScript, Vite 6, Tailwind 4 | Dashboard HRD/Finance/Admin |
| `expenseflow-mobile` | Flutter SDK ^3.12, Provider, geolocator, FCM | App karyawan: presensi, klaim, cuti |
| Database | MySQL `expenseflow_db` | Penyimpanan utama |
| Infra notifikasi | Firebase Cloud Messaging + local notifications | Push reminder checkout/shift/approval |

## Dokumen Terkait

- Detail backend → [02-BACKEND.md](02-BACKEND.md)
- Detail web → [03-WEB-FRONTEND.md](03-WEB-FRONTEND.md)
- Detail mobile → [04-MOBILE-FLUTTER.md](04-MOBILE-FLUTTER.md)
- Skema database → [05-DATABASE.md](05-DATABASE.md)
- Daftar API → [06-API-ENDPOINTS.md](06-API-ENDPOINTS.md)
