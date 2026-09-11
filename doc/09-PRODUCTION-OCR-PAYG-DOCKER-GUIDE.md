# 🚀 Panduan Production: OCR Gemini Pay-As-You-Go & Docker Multi-Worker

Dokumen ini berisi panduan teknis dan checklist penting untuk mempersiapkan sistem OCR struk ExpenseFlow agar siap menangani **puluhan hingga ratusan pengguna secara bersamaan** di lingkungan *Production*.

---

## 📌 1. Masalah pada Free Tier vs Kebutuhan Production

| Aspek | Free Tier (Saat Ini) | Pay-As-You-Go (Production) |
| :--- | :--- | :--- |
| **Batas Kecepatan** | **15 RPM** (Request Per Menit) | **1.000 – 2.000 RPM** |
| **Kapasitas Beban** | Maksimal 15 scan / menit | Ratusan scan bersamaan per detik |
| **Jika Melebihi Kuota** | Error `429 Too Many Requests` | Diproses lancar tanpa limit antrean |
| **Queue Worker Default** | 1 Worker Terminal (FIFO sekuensial) | 3 – 10 Worker Paralel (Multi-threaded) |
| **Estimasi Waktu 50 Scan** | ~5 hingga 6 menit | **~20 hingga 35 detik** |

### 🧮 Simulasi Perhitungan: 10 Orang Melakukan Scan Bersamaan
Misalkan 1 orang membutuhkan waktu rata-rata **7 detik** (rentang 5–10 detik) untuk proses OCR Gemini:

#### A. Kondisi 1 Worker Tunggal (`php artisan queue:work`):
Antrean bersifat **FIFO (First In, First Out / bergantian satu per satu)**:
* **Orang ke-1:** Detik ke-0 $\rightarrow$ Selesai di detik ke-**7**
* **Orang ke-2:** Menunggu 7 detik + proses 7 detik $\rightarrow$ Selesai di detik ke-**14**
* **Orang ke-3:** Selesai di detik ke-**21**
* **Orang ke-4:** Selesai di detik ke-**28**
* **Orang ke-5:** Selesai di detik ke-**35**
* **Orang ke-7:** Selesai di detik ke-**49**
* **Orang ke-10:** Selesai di detik ke-**70 (~1 menit 10 detik!)**

> ⚠️ **Risiko:** Aplikasi mobile memiliki batas polling adaptif ~56 detik. Pengguna urutan ke-9 dan ke-10 berpotensi mengalami *timeout* jika hanya mengandalkan 1 worker tunggal saat lonjakan upload.

#### B. Kondisi 3 Worker Paralel:
Sistem memproses 3 struk secara bersamaan dalam batch:
* **Batch 1 (Orang 1, 2, 3):** Selesai di detik ke-**7**
* **Batch 2 (Orang 4, 5, 6):** Selesai di detik ke-**14**
* **Batch 3 (Orang 7, 8, 9):** Selesai di detik ke-**21**
* **Batch 4 (Orang 10):** Selesai di detik ke-**28**

> ✅ **Hasil:** Seluruh 10 orang selesai dalam waktu **hanya ~28 detik**. 10 scan dalam 1 menit juga **100% aman** di bawah kuota 15 RPM ($10 < 15$).

> **Catatan Sistem Antrean (Retry):**  
> Backend Laravel sudah dilengkapi proteksi `$tries = 3` dan `$backoff = 10` pada `ProcessOcrJob.php`. Jika terjadi error 429 sesaat, sistem akan mencoba ulang otomatis setelah 10 detik tanpa membuat user harus upload manual dari awal. Namun, untuk 50+ user simultan, **Pay-As-You-Go wajib diaktifkan**.

---

## 💳 2. Langkah Mengaktifkan Pay-As-You-Go (PAYG)

Aktifkan billing Google AI Studio saat aplikasi siap rilis ke pengguna nyata:

### Langkah-langkah:
1. Buka [Google AI Studio](https://aistudio.google.com/).
2. Di menu navigasi kiri, pilih **Get API key**.
3. Pilih Google Cloud Project yang Anda gunakan, lalu klik **"Set up billing"** atau **"Enable Pay-as-you-go"**.
4. Tautkan **Google Cloud Billing Account** (dapat menggunakan kartu debit/kredit seperti Bank Jago, Jenius BTPN, Mandiri, BCA Mastercard, dll).
5. **Tidak perlu mengubah kode / file `.env`**: API Key yang sama akan langsung ter-upgrade ke tier kuota 1.000+ RPM.

### 💰 Estimasi Biaya (Sangat Ekonomis):
- Model `gemini-2.5-flash` / `gemini-1.5-flash` bertarif ~$0.0001 per struk.
- **100 scan struk** = ± Rp 200,-
- **1.000 scan struk** = ± Rp 2.000,-
- **10.000 scan struk** = ± Rp 20.000,-

### 🛡️ Pasang Pengaman Batas Anggaran (Budget Alert):
1. Buka [Google Cloud Console Billing](https://console.cloud.google.com/billing).
2. Pilih menu **Budgets & alerts** di panel kiri.
3. Buat anggaran misalnya **Rp 50.000 / bulan** dengan notifikasi email di 50%, 90%, dan 100%.

---

## 🐳 3. Setup Docker Multi-Worker (Queue Concurrency)

Agar antrean 50+ scan selesai dalam hitungan detik, backend harus menjalankan beberapa worker secara paralel.

### A. Contoh `docker-compose.yml` untuk Production:

```yaml
version: '3.8'

services:
  # 🌐 Laravel Web / API Service
  app:
    build:
      context: ./expenseflow-backend
      dockerfile: Dockerfile
    container_name: expenseflow_api
    restart: unless-stopped
    ports:
      - "8000:80"
    environment:
      - APP_ENV=production
      - DB_CONNECTION=mysql
      - QUEUE_CONNECTION=database # atau redis
    volumes:
      - ./expenseflow-backend/storage:/var/www/html/storage

  # ⚡ Background OCR Queue Workers (5 - 10 Worker Paralel)
  queue-worker:
    build:
      context: ./expenseflow-backend
      dockerfile: Dockerfile
    container_name: expenseflow_queue_worker
    restart: unless-stopped
    command: php artisan queue:work --sleep=2 --tries=3 --timeout=90
    deploy:
      replicas: 5 # Menjalankan 5 worker container sekaligus!
    environment:
      - APP_ENV=production
      - DB_CONNECTION=mysql
      - QUEUE_CONNECTION=database
    volumes:
      - ./expenseflow-backend/storage:/var/www/html/storage
```

---

### B. Opsi Alternatif: Konfigurasi Supervisor di Server Linux (VPS/VM)

Jika menggunakan VPS Linux (Ubuntu / Debian) tanpa Docker replicas, gunakan **Supervisor**:

Buat file `/etc/supervisor/conf.d/expenseflow-worker.conf`:

```ini
[program:expenseflow-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/expenseflow-backend/artisan queue:work database --sleep=2 --tries=3 --timeout=90
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=8 # 🚀 8 worker berjalan bersamaan
redirect_stderr=true
stdout_logfile=/var/www/expenseflow-backend/storage/logs/worker.log
stopwaitsecs=3600
```

Jalankan perintah:
```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start expenseflow-worker:*
```

---

## ⚙️ 4. Cara Kerja Multi-Worker & Pengaturan di Komputer Lokal (Development)

### A. Apakah Perlu Mengubah Kode PHP / Job?
**TIDAK PERLU.** Sistem tetap 100% menggunakan file job yang sama (`ProcessOcrJob.php`) dan perintah CLI standar Laravel (`php artisan queue:work`).
* **Mekanisme Keamanan (Atomic Lock):**
  Laravel Queue driver `database` menggunakan query penguncian baris (`SELECT ... FOR UPDATE`).
  Ketika terdapat 3 worker aktif memantau tabel `jobs`:
  1. Worker 1 menyambar job #1 dan menguncinya (*locked*).
  2. Worker 2 otomatis menyambar job #2 tanpa bentrok.
  3. Worker 3 otomatis menyambar job #3.
  4. Siapa pun worker yang selesai lebih dahulu akan langsung menyambar job berikutnya yang belum terkunci.
  5. **Tidak akan pernah ada 2 worker yang memproses struk yang sama.**

### B. Menjalankan Multi-Worker di Windows (Local Development):
Cukup buka beberapa tab terminal terpisah di folder `expenseflow-backend`, lalu jalankan `php artisan queue:work` pada masing-masing tab.

Atau buat script batch praktis `start-workers.bat` di folder backend:
```bat
@echo off
echo Menjalankan 3 Worker Antrean OCR ExpenseFlow...
start "Worker OCR 1" cmd /k "php artisan queue:work"
start "Worker OCR 2" cmd /k "php artisan queue:work"
start "Worker OCR 3" cmd /k "php artisan queue:work"
echo 3 Worker berhasil diaktifkan di jendela terpisah!
```

---

## ⚖️ 5. Analisis Dampak Menjalankan Banyak Worker (Development vs Production)

| Aspek | Dampak di Development (Lokal) | Dampak di Production (Server Live) |
| :--- | :--- | :--- |
| **Kecepatan / Throughput** | Antrean selesai lebih cepat, pengujian multi-user lebih realistis. | Menghilangkan antrean leher botol (*bottleneck*); 50 scan selesai dalam hitungan detik. |
| **Penggunaan RAM / Memori** | Tiap worker memakan ~30–60 MB RAM di Windows. 3 worker hanya ~150 MB (sangat aman). | VPS 1–2 GB RAM harus dibatasi (3–5 worker = ~250 MB RAM). Hindari kehabisan RAM agar proses MySQL tidak *killed*. |
| **Beban Database MySQL** | Ringan karena traffic di lokal rendah. | Setiap worker terus-menerus polling ke tabel `jobs`. Jika worker > 10 pada MySQL yang sama, beban I/O bertambah. Disarankan migrasi ke **Redis** untuk skala enterprise. |
| **Batas Kuota API Gemini** | Aman jika tester lokal sedikit. | **KRUSIAL:** Jika masih Free Tier (15 RPM), jangan pasang > 3 worker agar tidak terkena error `429 Too Many Requests`. Pasang 5–10 worker hanya jika sudah PAYG. |
| **Kenyamanan Update Kode** | ⚠️ Setiap mengedit kode PHP, semua jendela terminal worker harus di-restart manual agar membaca kode baru. | Otomatis di-restart oleh deployment script via `php artisan queue:restart`. |

### 🎯 Rekomendasi Jumlah Worker Sesuai Kebutuhan:
1. **Development (Lokal Windows):** **1 – 2 Worker** (Cukup untuk testing, tidak membuat taskbar penuh terminal & mudah di-restart saat koding).
2. **Production (Free Tier Gemini 15 RPM):** **2 – 3 Worker** (Antrean 10 user selesai ~28 detik, aman dari kuota 15 RPM, dan hemat RAM).
3. **Production Skala Menengah/Besar (Gemini PAYG):** **5 – 10 Worker** (Dipadukan dengan Redis & Supervisor/Docker untuk ratusan user).

---

## 📱 6. Optimasi Sisi Mobile (Flutter Image Compression)

Sebelum mengirim file foto ke backend, pastikan Flutter melakukan kompresi:
- **Max Width/Height**: 1280px s/d 1600px.
- **Kualitas JPEG**: 75% – 80%.
- **Ukuran File**: Turun dari ~3-4 MB menjadi **150 KB – 300 KB**.
- **Hasil**: Waktu upload foto terpangkas dari 2 detik menjadi **0.2 detik**.

---

## ✅ 7. Checklist Sebelum Go-Live (Production)

- [ ] Akun Google AI Studio sudah diubah ke **Pay-As-You-Go** & ditautkan akun kartu.
- [ ] Google Cloud **Budget Alert** sudah disetel (misal limit Rp 50.000 / Rp 100.000).
- [ ] Multi-worker queue sudah aktif (minimal 3–5 worker via Docker Replicas atau Supervisor).
- [ ] `APP_DEBUG=false` dan `APP_ENV=production` di file `.env` backend.
- [ ] Perintah `php artisan queue:restart` dijalankan setiap kali ada update kode.
- [ ] Storage link terpasang (`php artisan storage:link`).

