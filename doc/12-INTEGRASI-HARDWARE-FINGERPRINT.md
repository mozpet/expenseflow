# 12 - Panduan & Arsitektur Integrasi Hardware Mesin Fingerprint Onsite

Dokumen ini memuat arsitektur teknis, topologi jaringan, kebutuhan backend, skema database, serta alur integrasi antara sistem **ExpenseFlow (Cloud VPS)** dengan **Mesin Fisik Fingerprint di Kantor (Kabel LAN)**.

---

## 1. Latar Belakang & Tujuan

ExpenseFlow saat ini mendukung pencatatan presensi mobile berbasis GPS Geofencing, verifikasi selfie, dan mode WFH. Untuk kebutuhan operasional onsite (kantor cabang, pabrik, toko ritel, atau gudang), diperlukan integrasi dengan mesin fingerprint fisik agar:
1. **Anti-Kecurangan (Anti-Fraud)**: Menghilangkan risiko Fake GPS, mock location, atau titip presensi untuk staf onsite.
2. **Kecepatan di Jam Sibuk**: Karyawan kantor/pabrik dapat presensi dalam hitungan detik secara antre tanpa membuka ponsel.
3. **Single Source of Truth**: Data tap dari mesin fisik dan presensi mobile/WFH bermuara pada **satu database, satu dashboard HRD, dan satu kalkulasi jadwal shift/toleransi keterlambatan** yang sama.

---

## 2. Topologi Jaringan (Cloud VPS vs Kabel LAN Kantor)

### Kondisi Lingkungan:
* **Backend Laravel**: Berada di server Cloud VPS (memiliki Domain Publik HTTPS, misal: `https://api.perusahaan.com`).
* **Mesin Fingerprint**: Berada di jaringan lokal kantor, terhubung melalui kabel LAN ke switch/router kantor (mendapatkan IP privat lokal seperti `192.168.1.201` di balik NAT/Firewall).

### Prinsip Komunikasi:
Server VPS di Cloud **tidak dapat dan tidak boleh** dipaksa menembus IP lokal `192.168.x.x` kantor karena memerlukan IP publik statis dan port forwarding yang berisiko keamanan. 
Oleh karena itu, arah komunikasi dilakukan secara **Outbound (Keluar)**: **Kantor yang mengirimkan data ke VPS melalui HTTPS**.

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                            KANTOR CABANG / ONSITE                           │
│                                                                             │
│   [ Karyawan Tap ]                                                          │
│          │                                                                  │
│          ▼                                                                  │
│   ┌──────────────┐                                                          │
│   │ Mesin Fisik  │                                                          │
│   │ Fingerprint  │                                                          │
│   │ 192.168.1.201│                                                          │
│   └──────┬───────┘                                                          │
│          │ Kabel LAN                                                        │
│          ▼                                                                  │
│   ┌──────────────┐       Metode A: Push ADMS Langsung                       │
│   │ Switch/Router├────────────────────────────────────────┐                 │
│   └──────┬───────┘                                        │                 │
│          │ (Jika mesin standar tanpa ADMS)                │                 │
│          ▼                                                │                 │
│   ┌──────────────┐       Metode B: Bridge Agent Service   │                 │
│   │ PC Kantor /  ├──────────────────────────────────┐     │                 │
│   │ Mini PC (LAN)│                                  │     │                 │
│   └──────────────┘                                  │     │                 │
└─────────────────────────────────────────────────────┼─────┼─────────────────┘
                                                      │     │ Internet HTTPS
                                                      ▼     ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                           CLOUD VPS (EXPENSEFLOW)                           │
│                                                                             │
│   Domain: https://api.perusahaan.com                                        │
│   Endpoint: POST /api/v1/biometric/attendance-push                          │
│                                                                             │
│   ┌─────────────────────────────────────────────────────────────────────┐   │
│   │ Laravel Backend (BiometricController)                               │   │
│   │  ├─ 1. Validasi X-Device-Token                                      │   │
│   │  ├─ 2. Mapping enroll_id -> user_id karyawan                        │   │
│   │  ├─ 3. Deduplikasi & Smart Clock In/Out Resolver                    │   │
│   │  ├─ 4. Kalkulasi Shift, Hari Libur, & Toleransi Keterlambatan       │   │
│   │  ├─ 5. Simpan ke Database `attendances` (source: 'fingerprint')     │   │
│   │  └─ 6. Kirim FCM Push Notifikasi ke Mobile App Karyawan             │   │
│   └──────────────────────────────────┬──────────────────────────────────┘   │
│                                      │                                      │
│                                      ▼                                      │
│                         ┌───────────────────────────┐                       │
│                         │   Database (PostgreSQL)   │                       │
│                         └───────────────────────────┘                       │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## 3. Pilihan Metode Integrasi Hardware

### Metode A: Cloud Push Protocol / ADMS (Rekomendasi Utama)
* **Kesesuaian**: Mesin Solution, ZKTeco, Fingerspot, atau BioTime yang memiliki fitur menu **"ADMS" / "Cloud Server" / "Web Server Setting"**.
* **Kebutuhan Hardware Tambahan**: **Tidak ada**. Cukup mesin fingerprint dicolok kabel LAN yang memiliki akses internet.
* **Alur**:
  1. Pada menu mesin: aktifkan Cloud Server, isi URL Server `api.perusahaan.com` dan Port `443` (SSL).
  2. Saat karyawan tap jari, firmware mesin langsung membuat HTTP POST request ke backend Laravel.
  3. Respon backend mengembalikan status acknowledgement (`OK`).

### Metode B: Local Bridge Agent (Untuk Mesin Standar Port 4370 TCP/UDP)
* **Kesesuaian**: Mesin fingerprint model lama/standar yang tidak memiliki fitur cloud push, hanya bisa dihubungi via SDK/port 4370 pada jaringan LAN lokal.
* **Kebutuhan Hardware Tambahan**: 1 PC/laptop kantor yang selalu hidup pada jam kerja (PC resepsionis, admin HRD, PC kasir, atau mini PC/STB Linux hemat daya).
* **Alur**:
  1. Service ringan (Node.js, Python dengan `pyzk`, atau PHP CLI daemon) berjalan di PC kantor.
  2. Script secara terjadwal (tiap 1–2 menit) atau realtime stream menarik log presensi dari IP mesin (`192.168.1.201:4370`).
  3. Script mem-forward payload JSON ke endpoint REST API ExpenseFlow di Cloud VPS.

### Metode C: Hikvision / Dahua MinMoe Terminal (HTTP Webhook)
* **Kesesuaian**: Face Recognition & Fingerprint Access Control Terminal modern.
* **Alur**: Terminal memiliki menu "HTTP Event Listening / Webhook", langsung mengirimkan JSON payload setiap kali ada event identifikasi berhasil.

---

## 4. Kebutuhan di Sisi Backend (Laravel di VPS)

### 4.1. Perubahan Skema Database

#### 1. Modifikasi Tabel `users`:
Menambahkan kolom pemetaan ID jari mesin ke user:
```php
Schema::table('users', function (Blueprint $table) {
    $table->string('biometric_id', 50)->nullable()->unique()->after('attendance_setting_id');
    $table->index('biometric_id');
});
```

#### 2. Tabel Baru `biometric_devices`:
Mencatat mesin-mesin fisik yang terdaftar per kantor cabang:
```php
Schema::create('biometric_devices', function (Blueprint $table) {
    $table->id();
    $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
    $table->foreignId('attendance_setting_id')->nullable()->constrained('attendance_settings')->onDelete('set null');
    $table->string('device_name');            // Contoh: "Fingerprint Pintu Masuk Lt 1"
    $table->string('device_sn')->unique();    // Serial Number hardware mesin
    $table->string('ip_address')->nullable(); // IP lokal (informasi)
    $table->string('secret_token', 64);       // Token otentikasi API
    $table->string('brand')->default('zkteco'); // zkteco, solution, fingerspot, hikvision
    $table->timestamp('last_synced_at')->nullable();
    $table->boolean('is_active')->default(true);
    $table->timestamps();
});
```

#### 3. Modifikasi Tabel `attendances`:
Menandai bahwa presensi dilakukan lewat mesin fisik:
```php
Schema::table('attendances', function (Blueprint $table) {
    $table->string('source')->default('mobile')->after('status'); // 'mobile' | 'fingerprint' | 'web'
    $table->foreignId('biometric_device_id')->nullable()->constrained('biometric_devices')->nullOnDelete();
});
```

---

### 4.2. Spesifikasi Endpoint API

#### Endpoint: `POST /api/v1/biometric/attendance-push`

* **Headers**:
  ```http
  Content-Type: application/json
  X-Device-Token: sec_dev_8899aabbccddeeff
  X-Device-SN: ZK-JKT-01
  ```

* **Payload Kontrak (Support Single maupun Batch Log)**:
  ```json
  {
    "device_sn": "ZK-JKT-01",
    "logs": [
      {
        "enroll_id": "101",
        "timestamp": "2026-09-08 07:58:22",
        "verify_type": 1
      },
      {
        "enroll_id": "105",
        "timestamp": "2026-09-08 08:02:11",
        "verify_type": 1
      }
    ]
  }
  ```

* **Respon Sukses (`200 OK`)**:
  ```json
  {
    "success": true,
    "received": 2,
    "processed": 2,
    "ignored": 0,
    "message": "Data presensi berhasil disinkronkan."
  }
  ```

---

### 4.3. Logika Pemrosesan Cerdas (Smart Resolver)

Di lapangan, karyawan sering menempelkan jari tanpa memilih status "Masuk" atau "Pulang" di tombol mesin. Backend mengolahnya secara otomatis dengan logika berikut:

1. **Deduplikasi (Anti-Double Tap)**:
   - Jika karyawan yang sama tap jarinya berulang kali dalam kurun waktu kurang dari 3–5 menit, hanya tap pertama yang disimpan, sisanya diabaikan sebagai duplikat.

2. **Deteksi Otomatis Clock In vs Clock Out**:
   - Cari data presensi tanggal tersebut (`date(timestamp)`) untuk user terkait.
   - **Jika belum ada record presensi hari itu**:
     - Tap tersebut dicatat sebagai **Jam Masuk (`clock_in`)**.
     - `source` diatur menjadi `'fingerprint'`.
   - **Jika sudah ada Jam Masuk (`clock_in`)**:
     - Jika jeda waktu dari `clock_in` sudah lebih dari batas minimal (misal > 30 menit):
       - Tap dicatat/diperbarui sebagai **Jam Pulang (`clock_out`)**.
       - Hitung durasi jam kerja (`total_work_minutes`).

3. **Kalkulasi Jadwal Shift & Toleransi Keterlambatan Otomatis**:
   - Backend memanggil `ShiftController::resolveSchedule($user, $today)`.
   - Membandingkan jam tap masuk dengan `work_start_time` (jadwal shift atau kantor cabang).
   - Memperhitungkan batas `late_tolerance_minutes` (dari shift khusus atau default kantor cabang).
   - Status ditentukan secara otomatis: `present` (tepat waktu) atau `late` (terlambat) beserta jumlah menit keterlambatannya.

4. **Realtime Push Notification ke Mobile App**:
   - Setelah record tersimpan, backend memicu `FcmService`:
     - Judul: *"Presensi Masuk Berhasil"*
     - Pesan: *"Tercatat pukul 07:58 WIB via Mesin Kantor Jakarta"*
   - Layar Beranda aplikasi Flutter karyawan otomatis berganti status menjadi **"Aktif Bekerja"**.

---

## 5. Kebutuhan di Sisi Web Dashboard & Mobile App

### 5.1. Web Dashboard (React / TSX)
1. **Manajemen Karyawan**:
   - Input field tambahan pada form Tambah/Ubah Karyawan: **"PIN / ID Fingerprint"**.
2. **Manajemen Mesin Hardware** (menu baru atau sub-menu Pengaturan Cabang):
   - Daftar mesin fisik per cabang kantor.
   - Status koneksi mesin (*Online*, *Last Ping*, *Serial Number*).
   - Tombol regenerasi `Device Secret Token`.
3. **Laporan & Log Presensi**:
   - Menampilkan ikon pembeda pada riwayat presensi: ikon sidik jari hardware (`Icons.fingerprint`) vs ikon smartphone (`Icons.phone_android`).

### 5.2. Mobile App (Flutter)
1. **Status di Beranda**:
   - Kartu presensi langsung memperbarui `todayMasuk` / `todayPulang` saat sync realtime.
2. **Riwayat Presensi**:
   - Menampilkan label asal presensi: *"Mesin Kantor"* atau *"Aplikasi Mobile"*.

---

## 6. Tahapan Implementasi Praktis (Roadmap)

| Tahap | Aktivitas | Output |
| :--- | :--- | :--- |
| **Fase 1: Database & Model** | Membuat migrasi kolom `biometric_id` pada `users`, tabel `biometric_devices`, dan kolom `source` pada `attendances`. | Skema DB siap menampung data mesin. |
| **Fase 2: Controller & Endpoint** | Membangun `BiometricAttendanceController` dengan validasi secret token, smart tap resolver, deduplikasi, dan kalkulasi shift. | Endpoint API aktif di Cloud VPS. |
| **Fase 3: Pengujian Simulator** | Menguji endpoint menggunakan script simulator HTTP POST (dummy tap massal) untuk memvalidasi performa dan akurasi logika shift. | Log presensi dan shift teruji valid. |
| **Fase 4: Setup Hardware Kantor** | Konfigurasi IP LAN mesin kantor, memasukkan URL VPS ke menu ADMS mesin (atau setup script Bridge Agent). | Mesin fisik live terhubung ke Cloud. |
| **Fase 5: UI Web & Mobile** | Menambahkan kolom input ID fingerprint di web admin dan indikator sumber presensi di mobile app. | Tampilan siap digunakan oleh HRD dan karyawan. |
