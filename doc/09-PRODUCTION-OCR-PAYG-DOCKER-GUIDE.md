# 🚀 Panduan Production: OCR Gemini Pay-As-You-Go & Docker Multi-Worker

Dokumen ini berisi panduan teknis dan checklist penting untuk mempersiapkan sistem OCR struk ExpenseFlow agar siap menangani **puluhan hingga ratusan pengguna secara bersamaan** di lingkungan *Production*.

---

## 📌 1. Masalah pada Free Tier vs Kebutuhan Production

| Aspek | Free Tier (Saat Ini) | Pay-As-You-Go (Production) |
| :--- | :--- | :--- |
| **Batas Kecepatan** | **15 RPM** (Request Per Menit) | **1.000 – 2.000 RPM** |
| **Kapasitas Beban** | Maksimal 15 scan / menit | Ratusan scan bersamaan per detik |
| **Jika Melebihi Kuota** | Error `429 Too Many Requests` | Diproses lancar tanpa limit antrean |
| **Queue Worker Default** | 1 Worker Terminal (FIFO sekuensial) | 5 – 10 Worker Paralel (Multi-threaded) |
| **Estimasi Waktu 50 Scan** | ~5 hingga 6 menit | **~20 hingga 35 detik** |

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

## 📱 4. Optimasi Sisi Mobile (Flutter Image Compression)

Sebelum mengirim file foto ke backend, pastikan Flutter melakukan kompresi:
- **Max Width/Height**: 1280px s/d 1600px.
- **Kualitas JPEG**: 75% – 80%.
- **Ukuran File**: Turun dari ~3-4 MB menjadi **150 KB – 300 KB**.
- **Hasil**: Waktu upload foto terpangkas dari 2 detik menjadi **0.2 detik**.

---

## ✅ 5. Checklist Sebelum Go-Live (Production)

- [ ] Akun Google AI Studio sudah diubah ke **Pay-As-You-Go** & ditautkan akun kartu.
- [ ] Google Cloud **Budget Alert** sudah disetel (misal limit Rp 50.000 / Rp 100.000).
- [ ] Multi-worker queue sudah aktif (minimal 5 worker via Docker Replicas atau Supervisor).
- [ ] `APP_DEBUG=false` dan `APP_ENV=production` di file `.env` backend.
- [ ] Perintah `php artisan queue:restart` dijalankan setiap kali ada update kode.
- [ ] Storage link terpasang (`php artisan storage:link`).
