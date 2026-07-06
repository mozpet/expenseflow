# ExpenseFlow

ExpenseFlow adalah sistem manajemen pengeluaran dan presensi perusahaan terintegrasi. Proyek ini dibangun untuk memudahkan pelacakan operasional perusahaan, mulai dari klaim pengeluaran karyawan hingga manajemen kehadiran.

Proyek ini terdiri dari tiga komponen utama: aplikasi mobile untuk karyawan, dashboard web untuk manajemen (Finance/HRD/Admin), dan backend API yang solid.

## 🚀 Fitur Utama

- **Manajemen Struk (Receipt) & OCR**: Karyawan dapat memfoto struk pengeluaran via aplikasi mobile. Sistem akan otomatis mengekstrak nominal, nama merchant, dan tanggal menggunakan teknologi OCR (Google Cloud Vision / Tesseract) untuk mencegah manipulasi angka.
- **Persetujuan Invoice Multi-Level**: Sistem persetujuan berjenjang (Level 1: Finance Manager ➔ Level 2: Direksi ➔ Level 3: Komisaris) berdasarkan nominal tagihan secara otomatis.
- **Presensi Berbasis GPS & Shift**: Absensi dengan validasi lokasi (radius kantor), dukungan Work From Home (WFH), manajemen shift/roster karyawan, perhitungan jam kerja, dan sistem auto-checkout.
- **Manajemen Cuti & Lembur**: Alur pengajuan cuti dan lembur yang terintegrasi dengan saldo cuti dan kalender libur perusahaan.
- **Device Binding (Anti Kecurangan)**: Keamanan login yang mengikat 1 akun karyawan dengan 1 perangkat *smartphone* (device) secara spesifik untuk mencegah kecurangan penitipan absen.

## 🏗️ Arsitektur Proyek (Monorepo)

Proyek ini terbagi menjadi 3 direktori utama:

### 1. `expenseflow-backend/` (Laravel 11)
- Bertindak sebagai penyedia REST API.
- Menggunakan Laravel Sanctum untuk sistem otentikasi.
- Menangani proses OCR di *background* menggunakan Laravel Queue (asynchronous).
- Sistem otorisasi RBAC (Role-Based Access Control) dan isolasi data per perusahaan (`company_id`).

### 2. `expenseflow-mobile/` (Flutter)
- Aplikasi khusus untuk karyawan (Role: `employee`).
- Digunakan untuk memindai struk pengeluaran (scan OCR), presensi masuk/pulang, dan pengajuan cuti/izin.

### 3. `expenseflow-web/` (Web Frontend)
- Dashboard sentral untuk manajemen (Role: `finance`, `hrd`, `admin`, `super_admin`).
- Digunakan untuk melakukan *approval* pengeluaran (struk dan invoice), manajemen data master karyawan, pengaturan jadwal shift kantor, serta pelaporan bulanan kehadiran.

## 🛠️ Tech Stack

- **Backend API**: PHP 8.2+, Laravel 11, MySQL, Database Queue.
- **Frontend Web**: Node.js, Alpine.js, Tailwind CSS.
- **Mobile App**: Flutter, Dart, Dio, Firebase Cloud Messaging (FCM).
- **Machine Learning / OCR**: Tesseract OCR (Local Development) & Google Cloud Vision API (Production).

## 🏃‍♂️ Cara Menjalankan Proyek (Local Development)

### 1. Menjalankan Backend (Laravel)
Pastikan Anda sudah menginstal PHP, Composer, dan MySQL.
```bash
cd expenseflow-backend
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve
```
*Sangat penting: Jalankan queue worker di terminal terpisah agar proses scan OCR struk dapat berjalan:*
```bash
php artisan queue:work
```

### 2. Menjalankan Frontend Web
Pastikan Anda sudah menginstal Node.js dan npm.
```bash
cd expenseflow-web
npm install
npm run dev
```

### 3. Menjalankan Aplikasi Mobile (Flutter)
Pastikan Anda sudah menginstal Flutter SDK dan menyalakan Emulator / menyambungkan Device.
```bash
cd expenseflow-mobile
flutter pub get
flutter run
```

## 🔐 Role dan Hak Akses

| Role | Platform Utama | Deskripsi Singkat |
|------|----------------|-------------------|
| `employee` | Mobile | Hanya bisa memindai struk miliknya, absensi, dan mengajukan cuti. |
| `finance` | Web | Fokus pada alur keuangan: Approval struk karyawan dan invoice vendor. |
| `hrd` | Web | Manajemen sumber daya manusia: Approval cuti/lembur, manajemen shift, dan laporan. |
| `admin` | Web | Akses gabungan HRD & Finance (memiliki Approval Level 2). |
| `super_admin`| Web | Akses tertinggi tanpa batas (termasuk Approval Level 3). |

---
*Catatan: Dokumentasi mendetail mengenai alur logika bisnis (business rules), middleware, pipeline OCR, dan struktur arsitektur database dapat dilihat secara lengkap di dalam file `expenseflow-backend/rules.md`.*
