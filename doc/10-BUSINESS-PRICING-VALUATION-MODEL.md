# ExpenseFlow — Model Bisnis, Skema Harga SaaS, & Valuasi Jual Putus

> **Dokumen Analisis Finansial, Penetapan Harga (Pricing Strategy), Biaya Operasional (OpEx), Break-Even Point (BEP), dan Valuasi Lisensi Perangkat Lunak ExpenseFlow**  
> Terakhir diperbarui: 31 Agustus 2026  
> Ekosistem Produk: **Backend Laravel 11 API + Web Dashboard React TS + Mobile App Flutter iOS/Android + Google Cloud Vision OCR + Firebase FCM**

---

## DAFTAR ISI
1. [Ringkasan Eksekutif & Value Proposition](#1-ringkasan-eksekutif--value-proposition)
2. [Skema Harga Berlangganan (SaaS Pricing Model)](#2-skema-harga-berlangganan-saas-pricing-model)
3. [Rincian Biaya Operasional Server & API (OpEx / COGS)](#3-rincian-biaya-operasional-server--api-opex--cogs)
4. [Analisis Titik Impas (Break-Even Point) & Proyeksi Margin Profit](#4-analisis-titik-impas-break-even-point--proyeksi-margin-profit)
5. [Skema & Valuasi Jual Putus (Buyout / On-Premise License)](#5-skema--valuasi-jual-putus-buyout--on-premise-license)
6. [Perbandingan dengan Kompetitor SaaS HRIS & Expense di Indonesia](#6-perbandingan-dengan-kompetitor-saas-hris--expense-di-indonesia)
7. [Strategi Penjualan & Kebijakan Minimum Billing](#7-strategi-penjualan--kebijakan-minimum-billing)

---

## 1. Ringkasan Eksekutif & Value Proposition

**ExpenseFlow** menggabungkan dua modul vital perusahaan dalam satu ekosistem terpadu (*All-in-One Integrated HRIS & Financial Operations*):
1. **Human Resource & Attendance**: Presensi GPS Radius Kantor & WFH, Anti-Fake GPS, Device Binding HP, Roster Shift Harian, Approval Cuti/Izin/Lembur, Kalender Jadwal, & Mesin Payroll PPh 21 TER 2024.
2. **Expense & Financial Operations**: Klaim Struk Belanja dengan OCR AI Vision, Deteksi Struk Duplikat (Anti-Fraud), Approval Invoice Vendor Bertingkat (Level 1/2/3), dan Pencairan Transfer Bank (Export CSV Internet Banking).

Integrasi ini memberikan efisiensi luar biasa bagi perusahaan karena menghilangkan kebutuhan membeli 2 software terpisah (Software Absensi + Software Finance/Expense).

---

## 2. Skema Harga Berlangganan (SaaS Pricing Model)

Model berlangganan berbasis **Per User Aktif per Bulan (Per User / Month)**:

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                          SKEMA TIERING HARGA SAAS                           │
├───────────────────┬───────────────────┬─────────────────────────────────────┤
│ Paket             │ Harga / User / Bln│ Fitur Utama                         │
├───────────────────┼───────────────────┼─────────────────────────────────────┤
│ 1. STARTER        │ Rp 12.000 – 15.000│ Presensi GPS + Shift + Cuti/Izin    │
│ 2. PRO (Populer)  │ Rp 20.000 – 25.000│ Semua Starter + OCR Struk + Invoice │
│ 3. ENTERPRISE     │ Rp 35.000 – 50.000│ Semua Pro + Full Payroll + SLA 24/7 │
└───────────────────┴───────────────────┴─────────────────────────────────────┘
```

### Rincian Fitur Tiap Paket:

#### A. Paket Starter (Presensi & Manajemen Shift) — Rp 12.000 – Rp 15.000 / user / bulan
- Aplikasi Mobile Karyawan (Flutter Android & iOS)
- Presensi GPS Radius Kantor + WFH
- Validasi Anti-Fake GPS & Device Binding (1 HP 1 Akun)
- Manajemen Shift Kantor & Roster Kustom
- Approval Pengajuan Cuti, Izin, Sakit, & Lembur
- Notifikasi Pengingat Checkout (Lokal & FCM)
- Dashboard HRD & Rekap Laporan Bulanan

#### B. Paket Pro / Business (Rekomendasi Utama) — Rp 20.000 – Rp 25.000 / user / bulan
- **Semua Fitur Paket Starter**
- Pemindaian & Ekstraksi Struk Belanja Otomatis via **Google Cloud Vision OCR**
- Sistem Validasi Selisih (*Variance Detection*) Struk vs Klaim
- **Anti-Fraud**: Deteksi Potensi Struk Duplikat Otomatis
- Modul Manajemen & Approval Invoice Vendor (Level 1, 2, dan 3)
- Modul Pencairan Transfer Bank & Export CSV Format Internet Banking (BCA, Mandiri, BRI, BNI)
- Audit Trail Log Lengkap Aktivitas Pengguna

#### C. Paket Enterprise (Full HRIS, Expense & Payroll) — Rp 35.000 – Rp 50.000 / user / bulan
- **Semua Fitur Paket Pro**
- Mesin Kalkulasi Otomatis Gaji (Payroll Engine)
- Perhitungan Pajak **PPh 21 TER 2024 (PP 58/2023 & PMK 168/2023)** & Tarif Progresif Pasal 17
- Potongan & Tunjangan BPJS Ketenagakerjaan (JKK, JKM, JHT, JP) & BPJS Kesehatan
- Integrasi Lembur Otomatis, Potongan Pulang Cepat/Terlambat, & Prorate Masuk/Resign
- Generate & Download Slip Gaji Digital (PDF) per Karyawan
- Dukungan Multi-Cabang dengan UMR & Komponen Tunjangan Terpisah
- *Dedicated Customer Success Manager* & SLA Dukungan 24/7

---

## 3. Rincian Biaya Operasional Server & API (OpEx / COGS)

Estimasi pengeluaran bulanan (*Cost of Goods Sold*) untuk melayani **100 – 500 karyawan aktif**:

| Komponen Infrastruktur | Provider / Layanan | Estimasi Biaya / Bulan |
| :--- | :--- | :--- |
| **Server VPS (4 vCPU, 8 GB RAM NVMe)** | DigitalOcean / Linode / IDCloudHost / Biznet Gio | Rp 400.000 ($25) |
| **Google Cloud Vision OCR API** | Google Cloud Platform *(1.000 hit pertama gratis/bln)* | Rp 60.000 (~$4) |
| **Cloud Object Storage (Foto Struk & Bukti)** | Cloudflare R2 *(Tanpa Egress Fee)* / AWS S3 | Rp 30.000 (~$2) |
| **Domain, SSL, & Mailer Transaksional** | Cloudflare SSL + Resend / SendGrid SMTP | Rp 70.000 (~$4.5) |
| **Push Notification Service** | Firebase Cloud Messaging (FCM HTTP v1) | **Rp 0 (Gratis)** |
| **Total Biaya Operasional Rutin** | | **± Rp 560.000 – Rp 600.000 / bln** |

---

## 4. Analisis Titik Impas (Break-Even Point) & Proyeksi Margin Profit

Perhitungan di bawah menggunakan asumsi harga rata-rata paket **Pro (Rp 20.000 / user / bulan)**:

| Jumlah User Aktif | Total Pendapatan / Bln | Biaya Server & API / Bln | Laba Bersih / Bulan | Profit Margin | Status Bisnis |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **30 User** | Rp 600.000 | Rp 600.000 | **Rp 0** | **0%** | **Titik Impas (BEP)** |
| **50 User** | Rp 1.000.000 | Rp 600.000 | **Rp 400.000** | 40.0% | Menguntungkan |
| **100 User** | Rp 2.000.000 | Rp 600.000 | **Rp 1.400.000** | 70.0% | Sehat |
| **300 User** | Rp 6.000.000 | Rp 650.000 | **Rp 5.350.000** | 89.2% | Skala Menengah |
| **500 User** | Rp 10.000.000 | Rp 750.000 | **Rp 9.250.000** | 92.5% | Sangat Kuat |
| **1.000 User** | Rp 20.000.000 | Rp 1.500.000 *(Split Server)*| **Rp 18.500.000** | 92.5% | Cash Cow |
| **3.000 User** | Rp 60.000.000 | Rp 3.500.000 *(Cluster HA)*| **Rp 56.500.000** | 94.2% | High Scale |
| **5.000 User** | Rp 100.000.000 | Rp 6.000.000 *(K8s/Auto-scale)*| **Rp 94.000.000** | **94.0%** | Enterprise Scale |

### Kesimpulan Titik Impas (BEP):
1. **Hanya butuh 30 karyawan aktif** (bisa didapat dari **1–2 perusahaan klien kecil**) untuk menutupi seluruh biaya server dan API per bulan.
2. Ketika mencapai **100 karyawan**, margin keuntungan bersih sudah mencapai **70%**.
3. Di atas **500 karyawan**, efisiensi biaya menghasilkan margin profit **di atas 90%**.

---

## 5. Skema & Valuasi Jual Putus (Buyout / On-Premise License)

Jika ada klien korporasi, instansi pemerintah (BUMN/BUMD), atau investor/software house yang ingin membeli aplikasi ExpenseFlow secara lepas (tanpa sistem langganan bulanan):

```
                      VALUASI JUAL PUTUS EXPENSEFLOW
┌─────────────────────────────────────────────────────────────────────────┐
│ 1. Lisensi On-Premise Single Client : Rp  45.000.000 – Rp  85.000.000   │
│ 2. Full Source Code Buyout          : Rp 120.000.000 – Rp 250.000.000   │
│ 3. Enterprise Custom / Tender BUMN  : Rp 250.000.000 – Rp 500.000.000+  │
└─────────────────────────────────────────────────────────────────────────┘
```

### Opsi 1: Lisensi On-Premise Single Client (Instalasi Server Mandiri)
- **Rentang Harga**: **Rp 45.000.000 – Rp 85.000.000** (Sekali bayar / *One-time License*).
- **Target Pembeli**: Perusahaan swasta dengan 100–1.000 karyawan yang mewajibkan data tersimpan di server lokal (*on-premise*) mereka sendiri karena regulasi data internal.
- **Ketentuan Hak**:
  - Klien diberikan hak memakai sistem seumur hidup (*perpetual license*) untuk perusahaannya sendiri.
  - **Dilarang** menjual ulang, membagikan, atau menyewakan *source code* ke pihak ketiga.
  - Termasuk instalasi awal ke server klien, konfigurasi database/domain/SSL, dan garansi perbaikan bug selama 3 bulan.
- **Pendapatan Berulang (Recurring Maintenance)**:
  - Terapkan kontrak *Annual Maintenance Contract (AMC)* tahunan sebesar **15% – 20% per tahun** (sekitar **Rp 10.000.000 – Rp 15.000.000 / tahun**) untuk pembaruan fitur baru, penyesuaian regulasi pajak tahunan, dan *technical support*.

---

### Opsi 2: Full Source Code Buyout (Hak Cipta & Source Code Penuh)
- **Rentang Harga**: **Rp 120.000.000 – Rp 250.000.000**
- **Target Pembeli**: *Software house*, perusahaan teknologi, startup, atau grup konglomerasi yang ingin memiliki aset perangkat lunak secara mutlak.
- **Ketentuan Hak**:
  - Penyerahan 100% *Source Code* lengkap (Laravel Backend API, React Web Frontend, Flutter Mobile iOS & Android, Skema Database, Seeder, dan Dokumentasi Teknis).
  - Hak bebas untuk memodifikasi, mengganti merek (*white-label / rebrand*), menjual kembali (*resell*), atau mendistribusikan ke anak perusahaan tanpa royalti tambahan.
- **Dasar Valuasi Biaya Produksi (*Cost-to-Build Valuation*)**:
  - Membangun software dengan 3 platform terintegrasi (Web + Mobile + API + OCR AI) dari nol di software house profesional membutuhkan:
    - 1 Project Manager + 1 Senior Backend Dev + 1 Senior Frontend Dev + 1 Mobile Flutter Dev + 1 QA Engineer.
    - Durasi pengerjaan: 4 – 6 bulan.
    - Total biaya gaji tim pengembang: **Rp 150.000.000 – Rp 240.000.000**.
  - Membeli source code yang sudah teruji bebas bug dan siap pakai jauh lebih hemat biaya dan waktu (*zero time-to-market*).

---

### Opsi 3: Proyek Custom Korporasi / Tender BUMN / Instansi Pemerintah
- **Rentang Harga**: **Rp 250.000.000 – Rp 500.000.000+**
- **Target Pembeli**: Perusahaan BUMN, Bank, Rumah Sakit, Pabrik Manufaktur, atau Dinas Pemerintahan.
- **Cakupan Pekerjaan**:
  - Kustomisasi alur kerja persetujuan sesuai SOP birokrasi klien.
  - Integrasi dengan sistem ERP yang sudah berjalan (SAP, Oracle, Microsoft Dynamics) atau mesin absensi fisik *biometric fingerprint*.
  - Penyusunan arsitektur *High Availability* & Disaster Recovery.
  - Pelatihan tatap muka (*on-site training*) untuk HRD, Finance, dan Admin IT.
  - Layanan SLA Prioritas 24/7 dan *Dedicated Support Engineer* selama 1 tahun.

---

## 6. Perbandingan dengan Kompetitor SaaS HRIS & Expense di Indonesia

| Platform | Model Modul | Estimasi Biaya Langganan Pasar | Keunggulan ExpenseFlow |
| :--- | :--- | :--- | :--- |
| **Mekari Talenta** | HRIS + Payroll + Reimbursement | Rp 25.000 – Rp 45.000 / user / bln | ExpenseFlow menyertakan OCR AI Vision struk instan, anti-fraud duplikat, dan manajemen invoice vendor di 1 platform. |
| **Hadirr & Gadjian** | Presensi GPS + Payroll | Rp 15.000 – Rp 25.000 / user / bln | UI ExpenseFlow lebih modern (React + Flutter), sub-millisecond response time (0.17 ms), dan fleksibilitas roster shift tak terbatas. |
| **Kledo / Mekari Jurnal** | Akuntansi & Expense Manajemen | Rp 150.000 – Rp 400.000 / company / bln | Terfokus pada keuangan, tidak memiliki modul presensi mobile GPS dan integrasi shift karyawan. |
| **GreatDay HR** | HRIS Mobile | Rp 20.000 – Rp 35.000 / user / bln | ExpenseFlow lebih ringan (*lightweight*), tidak ada frame drop saat memuat 1.000 data, dan biaya lebih kompetitif. |

---

## 7. Strategi Penjualan & Kebijakan Minimum Billing

Untuk memaksimalkan *Cash Flow* dan efisiensi operasional tim:

1. **Aturan Minimum Tagihan (*Minimum Billing Rule*)**:
   - Terapkan batas minimal **15 user per perusahaan** (minimal tagihan **Rp 300.000 / bulan / klien**).
   - Jika perusahaan memiliki 5 karyawan, tagihan tetap dihitung flat minimum 15 user.
2. **Diskon Pembayaran Tahunan (*Annual Upfront Plan*)**:
   - Berikan diskon 15% – 20% jika klien membayar langsung 1 tahun di depan (misal: bayar 10 bulan gratis 2 bulan).
   - Strategi ini mengamankan modal operasional server di awal tanpa risiko klien menunggak bulanan.
3. **Biaya Onboarding & Setup Awal (*One-time Setup Fee*)**:
   - Kenakan biaya setup opsional sebesar **Rp 500.000 – Rp 2.000.000 per perusahaan** untuk bantuan impor data massal karyawan dari Excel, setup radius GPS kantor, dan sesi demo/training online.
