# ExpenseFlow — Roadmap & Arsitektur Fitur Payroll (Penggajian Komprehensif)

> **Dokumen Spesifikasi Teknis & Bisnis Modul Payroll HRIS ExpenseFlow**  
> Terakhir diperbarui: 2026-08-28 (Revisi V2 — Penambahan Koreksi Retroaktif, Audit Trail, Enkripsi PII, Prorate Resign, & 1721-A1)  
> Standar Regulasi: **PP No. 58 Tahun 2023 & PMK No. 168 Tahun 2023 (PPh 21 TER 2024)**, **UU No. 7 Tahun 2021 (UU HPP - Pasal 17)**, **PP No. 35 Tahun 2021 (Lembur & Ketenagakerjaan)**, **Permenaker No. 6 Tahun 2016 (THR Keagamaan)**, **UU BPJS Kesehatan & Ketenagakerjaan**.

---

## DAFTAR ISI
1. [Latar Belakang & Ruang Lingkup](#1-latar-belakang--ruang-lingkup)
2. [Analisis Kebutuhan Data & Keamanan PII](#2-analisis-kebutuhan-data--keamanan-pii)
3. [Standar Pajak PPh 21 Indonesia (TER 2024 & UU HPP)](#3-standar-pajak-pph-21-indonesia-ter-2024--uu-hpp)
4. [Standar BPJS Ketenagakerjaan & BPJS Kesehatan](#4-standar-bpjs-ketenagakerjaan--bpjs-kesehatan)
5. [Struktur Komponen Gaji, Tunjangan, Potongan, & Penyesuaian](#5-struktur-komponen-gaji-tunjangan-potongan--penyesuaian)
6. [Sistem Perhitungan Lembur (Overtime) & Integrasi Presensi](#6-sistem-perhitungan-lembur-overtime--integrasi-presensi)
7. [Sistem Perhitungan Tunjangan Hari Raya (THR Keagamaan)](#7-sistem-perhitungan-tunjangan-hari-raya-thr-keagamaan)
8. [Pengaturan Multi-Cabang & UMR Regional](#8-pengaturan-multi-cabang--umr-regional)
9. [Perancangan Skema Database (Database Schema Design)](#9-perancangan-skema-database-database-schema-design)
10. [Alur Logika Bisnis & Mesin Kalkulasi (Payroll Calculation Engine)](#10-alur-logika-bisnis--mesin-kalkulasi-payroll-calculation-engine)
11. [Rancangan RESTful API Endpoints & Role Permissions](#11-rancangan-restful-api-endpoints--role-permissions)
12. [Fase & Tahapan Implementasi (Implementation Roadmap)](#12-fase--tahapan-implementasi-implementation-roadmap)
13. [Strategi Pengujian & Validasi Perhitungan (Testing & QA)](#13-strategi-pengujian--validasi-perhitungan-testing--qa)
14. [Keputusan Bisnis Terkonfirmasi (Business Decisions Log)](#14-keputusan-bisnis-terkonfirmasi-business-decisions-log)

---

## 1. Latar Belakang & Ruang Lingkup

Modul Payroll ExpenseFlow berfungsi sebagai mesin kalkulasi otomatis gaji bulanan karyawan berbasis integrasi data presensi GPS/WFH, jam kerja shift, persetujuan lembur (*overtime approval*), pemakaian cuti/izin, reimbursement klaim struk pengeluaran yang telah disetujui, cicilan pinjaman kasbon, serta penyesuaian (*adjustments*) manual/retroaktif.

### Prinsip Utama Sistem Payroll:
1. **Multi-Cabang (Multi-Branch)**: Setiap cabang dapat memiliki UMR berbeda, komponen tunjangan makan/transport berbeda, dan tanggal *cut-off* berbeda.
2. **Kepatuhan Pajak & Ketenagakerjaan Indonesia**: Menerapkan tarif PPh 21 TER (Tarif Efektif Rata-rata) bulanan + penyesuaian akhir tahun (Desember) / masa pajak akhir saat karyawan resign sesuai Pasal 17 UU HPP, skema BPJS Kesehatan & Ketenagakerjaan akurat, serta formula lembur PP 35/2021.
3. **Snapshot Data Historis (Immutable)**: Payslip yang sudah disetujui (*approved*) atau dibayarkan (*paid*) membekukan data nominal, komponen, dan pajak pada saat periode tersebut ditutup, tidak terpengaruh oleh perubahan master gaji di masa depan.
4. **Separation of Duty (Approval Bertingkat)**: HRD meng-generate dan mereview draf payroll, Finance & Direksi melakukan verifikasi dan approval pencairan (*disbursement*).
5. **Koreksi & Retroaktif Terkendali**: Perubahan atas payroll yang sudah dibayarkan tidak mengedit data lama, melainkan disalurkan melalui mekanisme *Payroll Adjustment* pada periode berjalan.
6. **Keamanan Data Pribadi Finansial (PII Protection)**: Seluruh data rekening bank, NPWP, dan nomor BPJS karyawan dienkripsi di level basis data.
7. **Transparansi Karyawan**: Karyawan dapat melihat dan mengunduh slip gaji (*payslip*) resmi berformat PDF/mobile view melalui aplikasi Flutter.

---

## 2. Analisis Kebutuhan Data & Keamanan PII

Berdasarkan audit menyeluruh terhadap migrasi database per Agustus 2026, berikut adalah rincian kolom dan master data yang wajib ditambahkan serta status data eksisting:

### A. Kebutuhan pada Tabel `users` (Data Profil Finansial Karyawan)

**Kolom yang Eksisting:**
- `id`, `company_id`, `employee_code`, `name`, `identity_number` (NIK KTP 16 digit), `email`, `phone`, `role`, `department`, `attendance_setting_id`, `monthly_claim_limit`, `is_active`, `attendance_enabled`, `wfh_enabled`, `radius_enabled`, `fcm_token`, `device_id`, `employment_type` (PKWTT/PKWT/Probation/Internship), `joined_date`, `contract_start_date`, `contract_end_date`.

**Kolom yang WAJIB Ditambahkan ke Tabel `users`:**
| Nama Kolom | Tipe Data | Keterangan & Aturan Bisnis |
|---|---|---|
| `resigned_date` | `date` (nullable) | Tanggal efektif karyawan keluar/resign. Menjadi pemicu perhitungan Prorate Resign dan PPh 21 Masa Terakhir (Pasal 17). |
| `bank_name` | `varchar(50)` | Nama bank payroll karyawan (BCA, Mandiri, BRI, BNI, CIMB, dll). |
| `bank_account_no` | `text` *(Encrypted)* | Nomor rekening bank tujuan transfer gaji (dienkripsi AES-256). |
| `bank_account_holder` | `varchar(150)`| Nama lengkap pemilik rekening (harus sesuai buku tabungan). |
| `npwp` | `text` *(Encrypted)* | NPWP (15 atau 16 digit NIK-NPWP). Jika `NULL`, tarif PPh 21 dikenakan 20% lebih tinggi. |
| `ptkp_status` | `enum('TK/0', 'TK/1', 'TK/2', 'TK/3', 'K/0', 'K/1', 'K/2', 'K/3', 'K/I/0', 'K/I/1', 'K/I/2', 'K/I/3')` | Status PTKP untuk menentukan Kategori TER PPh 21 (A, B, atau C). Default: `'TK/0'`. |
| `tax_method` | `enum('gross', 'gross_up', 'nett')` | Metode perhitungan pajak: `gross` (potong gaji karyawan), `gross_up` (tunjangan pajak), `nett` (pajak ditanggung perusahaan). Default: `'gross'`. |
| `bpjs_kesehatan_no` | `text` *(Encrypted)* | Nomor kartu BPJS Kesehatan (13 digit, dienkripsi). |
| `bpjs_ketenagakerjaan_no` | `text` *(Encrypted)* | Nomor KPJ BPJS Ketenagakerjaan (11 digit, dienkripsi). |
| `bpjs_kesehatan_enabled` | `boolean` | Status keikutsertaan BPJS Kesehatan (default: `true`). |
| `bpjs_ketenagakerjaan_enabled` | `boolean` | Status keikutsertaan BPJS TK (default: `true`). |
| `has_jht` | `boolean` | Ikut Jaminan Hari Tua (default: `true`). |
| `has_jp` | `boolean` | Ikut Jaminan Pensiun (default: `true`, non-aktif untuk pekerja magang/kontrak pendek). |
| `salary_type` | `enum('monthly', 'daily', 'hourly')` | Tipe basis penggajian. Default: `'monthly'`. |
| `overtime_eligible` | `boolean` | Hak lembur berbayar. Karyawan level Manajerial/Head biasanya `false`. Default: `true`. |
| `prorate_type` | `enum('working_days', 'calendar_days', 'none')` | Aturan prorate gaji saat karyawan masuk/keluar di tengah bulan. Default: `'working_days'`. |

---

### B. Standar Enkripsi Data Sensitif Finansial (PII Compliance)

> [!IMPORTANT]
> **Data Finansial Karyawan Bersifat Rahasia (Confidential)**  
> Kolom rekening bank, NPWP, dan BPJS wajib dienkripsi di level basis data untuk mencegah kebocoran data jika terjadi *database dump leak*.

Implementasi menggunakan fitur bawaan **Laravel Eloquent Encrypted Casting**:
```php
// app/Models/User.php
protected function casts(): array
{
    return [
        'bank_account_no'         => 'encrypted',
        'npwp'                    => 'encrypted',
        'bpjs_kesehatan_no'       => 'encrypted',
        'bpjs_ketenagakerjaan_no' => 'encrypted',
        'is_active'               => 'boolean',
        'resigned_date'           => 'date',
        // ... casts lainnya
    ];
}
```
- **Mekanisme**: Menggunakan algoritma **AES-256-CBC** yang diikat dengan `APP_KEY` aplikasi.
- **Transparansi**: Di level kode/controller/service, atribut dapat diakses seperti string biasa (`$user->bank_account_no`), namun di database MySQL tersimpan sebagai ciphertext `eyJpdiI6...`.

---

### C. Kebutuhan pada Tabel `attendance_settings` (Pengaturan Payroll Per Cabang)

**Kolom yang WAJIB Ditambahkan ke Tabel `attendance_settings`:**
| Nama Kolom | Tipe Data | Default | Keterangan & Aturan Bisnis |
|---|---|---|---|
| `umr_amount` | `decimal(15,2)` | `0.00` | Nilai UMR/UMK kota/kabupaten lokasi kantor cabang berada (sebagai dasar batas bawah pengupahan & perhitungan lembur/BPJS). |
| `payroll_cutoff_date` | `tinyint` | `25` | Tanggal *cut-off* presensi bulanan (misal 25 = periode 26 bulan lalu s.d. 25 bulan ini). `0` = akhir bulan kalender (1 - akhir bulan). |
| `payroll_payment_date` | `tinyint` | `1` | Tanggal pembayaran/gajian resmi (misal tanggal 28 atau tanggal 1 bulan berikutnya). |
| `prorate_formula` | `enum('working_days', 'calendar_days', 'fixed_21', 'fixed_25')` | `'working_days'` | Formula pembagi hitungan prorate karyawan baru/resign di cabang ini. |
| `late_deduction_type` | `enum('none', 'flat_nominal', 'per_minute', 'tiered')` | `'none'` | Kebijakan sanksi potongan keterlambatan di cabang ini. |
| `late_deduction_amount` | `decimal(15,2)` | `0.00` | Nilai potongan flat atau per menit jika opsi di atas aktif. |
| `overtime_rate_type` | `enum('depnaker', 'flat_per_hour')` | `'depnaker'` | `'depnaker'` = rumus UU Cipta Kerja (1/173), `'flat_per_hour'` = tarif tetap per jam. |
| `overtime_flat_rate` | `decimal(15,2)` | `0.00` | Tarif lembur flat per jam jika memilih `flat_per_hour`. |
| `jkk_tier` | `enum('very_low', 'low', 'medium', 'high', 'very_high')` | `'low'` | Tingkat risiko JKK BPJS TK cabang (Sangat Rendah: 0.24%, Rendah: 0.54%, Sedang: 0.89%, Tinggi: 1.27%, Sangat Tinggi: 1.74%). |

---

### D. Kebutuhan pada Tabel `companies` (Pengaturan Induk Perusahaan)

**Kolom yang WAJIB Ditambahkan ke Tabel `companies`:**
| Nama Kolom | Tipe Data | Keterangan |
|---|---|---|
| `tax_id` | `varchar(30)` | NPWP Perusahaan (15/16 digit). |
| `tax_signatory_name` | `varchar(150)` | Nama pimpinan / penandatangan bukti potong PPh 21 (Form 1721-A1). |
| `tax_signatory_npwp` | `varchar(25)` | NPWP penandatangan bukti potong pajak. |
| `bpjs_kes_company_code` | `varchar(30)` | Kode Badan Usaha BPJS Kesehatan. |
| `bpjs_tk_company_code` | `varchar(30)` | Nomor Pendaftaran Perusahaan (NPP) BPJS Ketenagakerjaan. |
| `default_payroll_cutoff` | `tinyint` | Default tanggal cutoff jika cabang tidak mengisinya (default: 25). |

---

## 3. Standar Pajak PPh 21 Indonesia (TER 2024 & UU HPP)

Sesuai **PP 58/2023** dan **PMK 168/2023**, pemotongan PPh Pasal 21 atas penghasilan pegawai tetap menggunakan mekanisme dua tahap:

```
[Bulan Januari s.d. November]     → Menggunakan Tarif Efektif Rata-Rata (TER) Bulanan
[Bulan Desember / Masa Terakhir]  → Menggunakan Tarif Progresif Pasal 17 UU HPP (Tahunan) - Total TER Sebelumnya
```

---

### A. Pengelompokan Kategori TER Berdasarkan PTKP

| Kategori TER | Status PTKP Karyawan | Batasan Penghasilan Tidak Kena Pajak (PTKP) |
|---|---|---|
| **Kategori A** | `TK/0`, `TK/1`, `K/0` | TK/0: Rp 54.000.000, TK/1 & K/0: Rp 58.500.000 |
| **Kategori B** | `TK/2`, `TK/3`, `K/1`, `K/2` | TK/2 & K/1: Rp 63.000.000, TK/3 & K/2: Rp 67.500.000 |
| **Kategori C** | `K/3`, `K/I/0`, `K/I/1`, `K/I/2`, `K/I/3` | K/3: Rp 72.000.000 dst. |

---

### B. Master Tabel Tarif Efektif Bulanan (TER) PMK 168/2023 (`tax_ter_rates`)

Tabel TER Kategori A, B, dan C disimpan ke tabel master `tax_ter_rates`. Contoh rentang TER Kategori A:
- Bruto Rp 0 s.d. 5.400.000 $\rightarrow$ **0.00%**
- Bruto > Rp 5.400.000 s.d. 5.650.000 $\rightarrow$ **0.25%**
- Bruto > Rp 5.650.000 s.d. 5.950.000 $\rightarrow$ **0.50%**
- ... s.d. > Rp 1.400.000.000 $\rightarrow$ **34.00%**

---

### C. Rumus PPh 21 Masa Terakhir (Desember ATAU Bulan Karyawan Resign)

> [!IMPORTANT]
> **Aturan Masa Pajak Terakhir**: Berlaku pada **Bulan Desember** untuk seluruh pegawai aktif, ATAU pada **Bulan Berhentinya Karyawan (`resigned_date`)** jika karyawan berhenti bekerja di tengah tahun kalender.

1. **Penghasilan Bruto Disetahunkan/Aktual**:
   - Untuk Karyawan Aktif di Des: $\sum \text{Bruto Jan s.d. Des}$.
   - Untuk Karyawan Resign di Bulan $m$: $\sum \text{Bruto Bulan 1 s.d. } m$.
2. **Pengurang Penghasilan Bruto**:
   - **Biaya Jabatan**: 5% dari Bruto, maksimal **Rp 500.000/bulan** (maksimal Rp 6.000.000 per tahun atau Rp $500.000 \times m$ bulan kerja).
   - **Iuran JHT Karyawan**: 2% dari upah sebulan $\times$ bulan bekerja.
   - **Iuran JP Karyawan**: 1% dari upah sebulan $\times$ bulan bekerja.
3. **Penghasilan Neto**: $\text{Penghasilan Bruto} - \text{Total Pengurang}$.
4. **Penghasilan Kena Pajak (PKP)**: $\text{Penghasilan Neto Disetahunkan} - \text{Nilai PTKP}$ (dibulatkan ke bawah dalam ribuan penuh).
5. **Tarif Progresif Pasal 17 ayat (1) huruf a UU HPP (`tax_progressive_rates`)**:
   - Lapisan I: $0 \text{ s.d. } 60.000.000 \rightarrow \mathbf{5\%}$
   - Lapisan II: $> 60.000.000 \text{ s.d. } 250.000.000 \rightarrow \mathbf{15\%}$
   - Lapisan III: $> 250.000.000 \text{ s.d. } 500.000.000 \rightarrow \mathbf{25\%}$
   - Lapisan IV: $> 500.000.000 \text{ s.d. } 5.000.000.000 \rightarrow \mathbf{30\%}$
   - Lapisan V: $> 5.000.000.000 \rightarrow \mathbf{35\%}$
6. **PPh 21 Terutang Masa Terakhir**:
   $$\text{PPh 21 Masa Terakhir} = \text{Total PPh 21 Terutang} - \sum \text{PPh 21 TER yang telah dipotong pada bulan-bulan sebelumnya}$$
   *(Jika bernilai minus/lebih bayar, perusahaan mengembalikan kelebihan potong ke karyawan dan mengkompensasikan di SPT Masa perusahaan).*

---

### D. Skema Pajak Pegawai Harian Lepas / Freelance / Magang

1. **Pegawai Tidak Tetap / Harian Lepas**:
   - Upah $\le$ Rp 450.000/hari dan kumulatif sebulan $\le$ Rp 4.500.000 $\rightarrow$ **PPh 21 = Rp 0 (0%)**.
   - Upah > Rp 450.000/hari $\rightarrow$ PPh 21 = $0.5\% \times \text{Bruto}$ (sesuai PP 58/2023).
   - Kumulatif sebulan > Rp 4.500.000 s.d. Rp 10.000.000 $\rightarrow$ Dikenakan tarif TER harian/Pasal 17.
2. **Tenaga Ahli / Freelance (Bukan Pegawai)**:
   - PPh 21 = $50\% \times \text{Bruto Kumulatif} \times \text{Tarif Pasal 17}$.
3. **Peserta Magang (Internship)**:
   - Jika menerima uang saku harian/bulanan, diperlakukan sesuai ketentuan pegawai tidak tetap atau dipotong TER kategori PTKP-nya.

---

## 4. Standar BPJS Ketenagakerjaan & BPJS Kesehatan

Perhitungan iuran BPJS mengacu pada Upah Pokok + Tunjangan Tetap:

```
+-------------------------------------------------------------------------------+
| PROGRAM BPJS                | DIBAYAR PERUSAHAAN | DIBAYAR KARYAWAN | TOTAL   |
+-----------------------------+--------------------+------------------+---------+
| BPJS Kesehatan              | 4.0% (Maks cap)    | 1.0% (Maks cap)  | 5.0%    |
| JKK (Kecelakaan Kerja)      | 0.24% - 1.74%      | 0.0%             | 0.24%+  |
| JKM (Kematian)              | 0.30%              | 0.0%             | 0.30%   |
| JHT (Hari Tua)              | 3.70%              | 2.00%            | 5.70%   |
| JP (Pensiun)                | 2.00% (Maks cap)   | 1.00% (Maks cap) | 3.00%   |
+-------------------------------------------------------------------------------+
```

### Master Batas Upah (Ceiling/Cap) Dinamis:
Batas atas upah disimpan pada tabel master `bpjs_configs` agar dapat diperbarui tahunan tanpa deploy ulang kode:
- **Batas Atas BPJS Kesehatan**: Rp 12.000.000 (Maks iuran perusahaan Rp 480.000, karyawan Rp 120.000).
- **Batas Atas BPJS JP (Jaminan Pensiun)**: Sesuai regulasi tahun berjalan (misal 2024: Rp 10.042.300 / 2026: mengikuti indeks inflasi BPS).

---

## 5. Struktur Komponen Gaji, Tunjangan, Potongan, & Penyesuaian

ExpenseFlow membagi struktur penggajian menjadi komponen **Earnings (Penghasilan)**, **Deductions (Potongan)**, dan **Adjustments (Penyesuaian Ad-Hoc / Retroaktif)**:

```
                            PENGHASILAN BRUTO (GROSS)
                                        │
       ┌────────────────────────────────┼────────────────────────────────┐
       ▼                                ▼                                ▼
   GAJI DASAR                   TUNJANGAN TETAP                 TUNJANGAN TIDAK TETAP
   • Gaji Pokok (Monthly/Daily) • Tunjangan Jabatan             • Uang Transport (Per Hadir)
   • Prorate (Masuk/Resign)     • Tunjangan Fungsional          • Uang Makan (Per Hadir)
                                • Tunjangan Keluarga            • Lembur (Overtime Pay)
                                • Tunjangan Lokasi              • Insentif / Bonus / THR
                                                                • Klaim Struk (Reimbursement)
                                                                • Manual Earning Adjustment

                                        │
                                        ▼ KURANGI
                                POTONGAN (DEDUCTIONS)
                                        │
       ┌────────────────────────────────┼────────────────────────────────┐
       ▼                                ▼                                ▼
   POTONGAN PRESENSI             POTONGAN STATUTORI              POTONGAN LAINNYA
   • Potongan Terlambat          • PPh 21 Bulanan (TER)          • Kasbon / Pinjaman Kantor
   • Pulang Cepat                • BPJS Ketenagakerjaan (3%)     • Potongan Koperasi / Aset
   • Potongan Alpha / Mangkir    • BPJS Kesehatan (1%)           • Potongan Unpaid Leave
   • Potongan Izin Non-Berbayar                                  • Retroactive / Manual Deduction

                                        │
                                        ▼ HASIL AKHIR
                          GAJI BERSIH (TAKE HOME PAY / NETT)
```

---

### A. Formula Prorate Karyawan Masuk & Resign di Tengah Bulan

Jika karyawan baru mulai bekerja (`joined_date`) atau berhenti bekerja (`resigned_date`) di tengah periode *cut-off*:

1. **Metode Hari Kerja (`working_days` — Default Depnaker)**:
   $$\text{Gaji Prorate} = \frac{\text{Hari Kerja Aktual Karyawan dalam Periode}}{\text{Total Hari Kerja Seharusnya dalam Periode}} \times (\text{Gaji Pokok} + \text{Tunjangan Tetap})$$
2. **Metode Hari Kalender (`calendar_days`)**:
   $$\text{Gaji Prorate} = \frac{\text{Hari Kalender Aktif}}{\text{Jumlah Hari Kalender Bulan Tersebut (28-31)}} \times (\text{Gaji Pokok} + \text{Tunjangan Tetap})$$

---

### B. Formula Karyawan Harian (`salary_type = 'daily'`) & Per Jam (`hourly`)

1. **Gaji Harian**: $\text{Earning} = \text{daily_rate} \times \text{present_days}$.
2. **Gaji Per Jam**: $\text{Earning} = \text{hourly_rate} \times \left( \frac{\text{total_work_minutes}}{60} \right)$.

---

### C. Mekanisme Koreksi Retroaktif & Manual Adjustment (`payroll_adjustments`)

Koreksi atas periode yang telah dibayarkan (*paid*) diproses melalui entri **Adjustment**:
- **Earning Adjustment**: Bonus prestasi dadakan, kekurangan bayar lembur bulan lalu, kompensasi khusus.
- **Deduction Adjustment**: Ganti rugi kerusakan inventaris/laptop, kelebihan bayar gaji bulan lalu, denda administratif.
- **Atribut**: Memiliki relasi ke `retroactive_payroll_id` (periode asal kesalahan) untuk transparansi audit.

---

## 6. Sistem Perhitungan Lembur (Overtime) & Integrasi Presensi

Perhitungan upah lembur mengacu pada **PP No. 35 Tahun 2021**:

### A. Upah Sejam Dasar Lembur
$$\text{Upah Sejam} = \frac{1}{173} \times (\text{Gaji Pokok} + \text{Tunjangan Tetap})$$

### B. Koefisien Pengali Jam Lembur
1. **Lembur di Hari Kerja Normal**:
   - Jam ke-1: **1.5 $\times$ Upah Sejam**
   - Jam ke-2 dan seterusnya: **2.0 $\times$ Upah Sejam**
2. **Lembur di Hari Libur / Istirahat Mingguan (Skema 5 Hari Kerja)**:
   - Jam ke-1 s.d. Jam ke-8: **2.0 $\times$ Upah Sejam**
   - Jam ke-9: **3.0 $\times$ Upah Sejam**
   - Jam ke-10 s.d. Jam ke-12: **4.0 $\times$ Upah Sejam**
3. **Lembur di Hari Libur / Istirahat Mingguan (Skema 6 Hari Kerja)**:
   - Jam ke-1 s.d. Jam ke-7: **2.0 $\times$ Upah Sejam**
   - Jam ke-8: **3.0 $\times$ Upah Sejam**
   - Jam ke-9 s.d. Jam ke-10: **4.0 $\times$ Upah Sejam**

### C. Syarat Validasi Pembayaran Lembur:
1. `attendances.overtime_minutes > 0`.
2. Record terkait di `overtime_approvals` berstatus **`approved`**.
3. Karyawan memiliki status `users.overtime_eligible = true`.

---

## 7. Sistem Perhitungan Tunjangan Hari Raya (THR Keagamaan)

Berdasarkan **Permenaker No. 6 Tahun 2016**:

### A. Syarat & Besaran THR
1. **Masa Kerja $\ge$ 12 Bulan Terus Menerus**:
   $$\text{THR} = 1 \times (\text{Gaji Pokok} + \text{Tunjangan Tetap})$$
2. **Masa Kerja 1 Bulan s.d. < 12 Bulan (Prorate)**:
   $$\text{THR} = \frac{\text{Masa Kerja (Bulan)}}{12} \times (\text{Gaji Pokok} + \text{Tunjangan Tetap})$$
3. **Masa Kerja < 1 Bulan**: Tidak berhak menerima THR.

### B. Siklus Khusus `payroll_type = 'thr'`
- Payroll THR dapat dibuat terpisah tanpa mengganggu batch gaji bulanan reguler.
- Pajak PPh 21 atas THR dihitung secara terisolasi sesuai ketentuan PMK 168/2023.

---

## 8. Pengaturan Multi-Cabang & UMR Regional

1. **UMR Regional**: Validasi otomatis jika Gaji Pokok karyawan berada di bawah `attendance_settings.umr_amount`.
2. **Tarif Fleksibel**: Komponen tunjangan makan/transport dan toleransi potongan telat diisolasi per cabang.
3. **Cut-Off Mandiri**: Tiap cabang bebas menentukan tanggal cutoff masing-masing.

---

## 9. Perancangan Skema Database (Database Schema Design)

Berikut adalah diagram relasi entitas payroll komprehensif (14 tabel):

```
                                ┌────────────────────────┐
                                │       companies        │
                                └───────────┬────────────┘
                                            │ 1:N
                     ┌──────────────────────┴──────────────────────┐
                     │                                             │
          ┌──────────┴───────────┐                      ┌──────────┴───────────┐
          │  attendance_settings │                      │     bpjs_configs     │
          └──────────┬───────────┘                      │ (Master Cap Tahunan) │
                     │ 1:N                              └──────────────────────┘
          ┌──────────┴───────────┐
          │         users        │ (Karyawan)
          └──────────┬───────────┘
                     │
    ┌────────────────┼────────────────┬────────────────┬────────────────┐
    │ 1:N            │ 1:N            │ 1:N            │ 1:N            │ 1:N
┌───┴──────────┐ ┌───┴──────────┐ ┌───┴──────────┐ ┌───┴──────────┐ ┌───┴──────────┐
│employee_     │ │employee_     │ │employee_loans│ │payroll_      │ │tax_annual_   │
│salaries      │ │salary_       │ │(Pinjaman/    │ │adjustments   │ │summaries     │
│(Histori Gaji)│ │components    │ │ Kasbon)      │ │(Koreksi/AdHoc│ │(1721-A1 Rekap│
└──────────────┘ └──────────────┘ └──────────────┘ └───────┬──────┘ └──────────────┘
                                                           │
          ┌────────────────────────────────────────────────┼────────────────┐
          │                                                │                │
┌─────────┴────────┐                              ┌────────┴────────┐┌──────┴────────┐
│salary_components │                              │    payrolls     ││ payroll_logs  │
│(Master Komponen) │                              │(Header Periode) ││ (Audit Trail) │
└──────────────────┘                              └────────┬────────┘└───────────────┘
                                                           │ 1:N
┌──────────────────┐                              ┌────────┴────────┐
│  tax_ter_rates   │                              │    payslips     │
│ (Tabel TER A/B/C)│                              │ (Slip Karyawan) │
└──────────────────┘                              └────────┬────────┘
                                                           │ 1:N
┌──────────────────┐                              ┌────────┴────────┐
│tax_progressive_  │                              │  payslip_items  │
│rates (Pasal 17)  │                              │ (Rincian Baris) │
└──────────────────┘                              └─────────────────┘
```

---

### A. Tabel Master: `salary_components`
```sql
CREATE TABLE salary_components (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    attendance_setting_id BIGINT UNSIGNED NULL COMMENT 'NULL = berlaku semua cabang',
    code VARCHAR(50) NOT NULL COMMENT 'BASIC, TRANSPORT, MEAL, POSITION, OVERTIME, BPJS_KES_EMP, etc.',
    name VARCHAR(100) NOT NULL,
    category ENUM('earning', 'deduction') NOT NULL,
    type ENUM('fixed', 'attendance_based', 'variable', 'formula', 'statutory') NOT NULL,
    is_taxable BOOLEAN DEFAULT TRUE COMMENT 'Masuk objek bruto PPh21',
    is_bpjs_factor BOOLEAN DEFAULT FALSE COMMENT 'Faktor pengali upah dasar BPJS',
    default_amount DECIMAL(15,2) DEFAULT 0.00,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    FOREIGN KEY (attendance_setting_id) REFERENCES attendance_settings(id) ON DELETE CASCADE,
    UNIQUE KEY uq_company_comp_code (company_id, code, attendance_setting_id)
);
```

---

### B. Tabel Master: `employee_salaries`
```sql
CREATE TABLE employee_salaries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    basic_salary DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    effective_date DATE NOT NULL,
    end_date DATE NULL,
    notes VARCHAR(255) NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    INDEX idx_user_effective (user_id, effective_date)
);
```

---

### C. Tabel Master: `employee_salary_components`
```sql
CREATE TABLE employee_salary_components (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    salary_component_id BIGINT UNSIGNED NOT NULL,
    amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    effective_date DATE NOT NULL,
    end_date DATE NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (salary_component_id) REFERENCES salary_components(id) ON DELETE CASCADE,
    INDEX idx_user_comp (user_id, salary_component_id, effective_date)
);
```

---

### D. Tabel Master: `tax_ter_rates` & `tax_progressive_rates`
```sql
-- Tarif Efektif Rata-rata Bulanan (PMK 168/2023)
CREATE TABLE tax_ter_rates (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category ENUM('A', 'B', 'C') NOT NULL,
    min_gross DECIMAL(15,2) NOT NULL,
    max_gross DECIMAL(15,2) NULL,
    rate_percent DECIMAL(5,2) NOT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    INDEX idx_category_gross (category, min_gross, max_gross)
);

-- Tarif Progresif Pasal 17 UU HPP (Tahunan / Masa Pajak Terakhir)
CREATE TABLE tax_progressive_rates (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tier_order TINYINT NOT NULL COMMENT '1 s.d 5',
    min_pkp DECIMAL(15,2) NOT NULL,
    max_pkp DECIMAL(15,2) NULL COMMENT 'NULL = tak terhingga (> 5 Miliar)',
    rate_percent DECIMAL(5,2) NOT NULL COMMENT '5, 15, 25, 30, 35',
    description VARCHAR(100) NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL
);
```

---

### E. Tabel Master: `bpjs_configs` (Cap / Ceiling Tahunan)
```sql
CREATE TABLE bpjs_configs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    year SMALLINT NOT NULL UNIQUE,
    bpjs_kes_max_cap DECIMAL(15,2) NOT NULL DEFAULT 12000000.00,
    bpjs_jp_max_cap DECIMAL(15,2) NOT NULL DEFAULT 10042300.00,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL
);
```

---

### F. Tabel Transaksi: `payrolls`
```sql
CREATE TABLE payrolls (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    attendance_setting_id BIGINT UNSIGNED NULL,
    batch_number VARCHAR(50) NOT NULL UNIQUE COMMENT 'PR-YYYYMM-CAB-XXXX',
    payroll_type ENUM('regular', 'thr', 'bonus', 'severance') DEFAULT 'regular',
    period_month TINYINT NOT NULL,
    period_year SMALLINT NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    payment_date DATE NOT NULL,
    total_employees INT NOT NULL DEFAULT 0,
    total_gross DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    total_tax DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    total_bpjs_company DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    total_bpjs_employee DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    total_reimbursement DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    total_adjustments DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    total_deductions DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    total_take_home_pay DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    status ENUM('draft', 'calculated', 'submitted', 'approved', 'paid', 'cancelled') DEFAULT 'draft',
    generated_by BIGINT UNSIGNED NULL,
    approved_by BIGINT UNSIGNED NULL,
    approved_at TIMESTAMP NULL,
    paid_at TIMESTAMP NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    FOREIGN KEY (attendance_setting_id) REFERENCES attendance_settings(id) ON DELETE SET NULL,
    FOREIGN KEY (generated_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_company_period (company_id, period_year, period_month, payroll_type)
);
```

---

### G. Tabel Transaksi: `payslips` & `payslip_items`
```sql
CREATE TABLE payslips (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payroll_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    attendance_setting_id BIGINT UNSIGNED NULL,
    payslip_number VARCHAR(60) NOT NULL UNIQUE,
    
    -- Snapshot Profil Finansial Karyawan
    employee_name VARCHAR(150) NOT NULL,
    employee_code VARCHAR(50) NULL,
    department VARCHAR(100) NULL,
    designation VARCHAR(100) NULL,
    bank_name VARCHAR(50) NULL,
    bank_account_no VARCHAR(50) NULL,
    bank_account_holder VARCHAR(150) NULL,
    npwp VARCHAR(30) NULL,
    ptkp_status VARCHAR(10) NOT NULL,
    tax_method ENUM('gross', 'gross_up', 'nett') NOT NULL,
    
    -- Snapshot Rekap Kehadiran
    scheduled_working_days TINYINT DEFAULT 0,
    present_days TINYINT DEFAULT 0,
    late_days TINYINT DEFAULT 0,
    early_leave_days TINYINT DEFAULT 0,
    absent_days TINYINT DEFAULT 0,
    leave_days TINYINT DEFAULT 0,
    holiday_days TINYINT DEFAULT 0,
    overtime_minutes INT DEFAULT 0,
    
    -- Snapshot Nilai Finansial
    basic_salary DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    total_fixed_allowances DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    total_variable_allowances DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    total_overtime_pay DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    total_reimbursement DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    total_adjustments DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    gross_salary DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    
    -- Pajak & BPJS
    tax_ter_percentage DECIMAL(5,2) DEFAULT 0.00,
    tax_pph21 DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    tax_allowance DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    bpjs_kes_company DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    bpjs_kes_employee DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    bpjs_tk_jkk_company DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    bpjs_tk_jkm_company DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    bpjs_tk_jht_company DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    bpjs_tk_jht_employee DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    bpjs_tk_jp_company DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    bpjs_tk_jp_employee DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    
    -- Potongan & Take Home Pay
    attendance_deductions DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    loan_deductions DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    other_deductions DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    total_deductions DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    take_home_pay DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    
    status ENUM('draft', 'approved', 'paid', 'cancelled') DEFAULT 'draft',
    paid_at TIMESTAMP NULL,
    pdf_path VARCHAR(255) NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    FOREIGN KEY (payroll_id) REFERENCES payrolls(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    INDEX idx_user_payroll (user_id, payroll_id)
);

CREATE TABLE payslip_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payslip_id BIGINT UNSIGNED NOT NULL,
    salary_component_id BIGINT UNSIGNED NULL,
    code VARCHAR(50) NOT NULL,
    name VARCHAR(100) NOT NULL,
    category ENUM('earning', 'deduction', 'company_expense') NOT NULL,
    amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    description VARCHAR(255) NULL,
    order_index TINYINT DEFAULT 0,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    FOREIGN KEY (payslip_id) REFERENCES payslips(id) ON DELETE CASCADE,
    FOREIGN KEY (salary_component_id) REFERENCES salary_components(id) ON DELETE SET NULL,
    INDEX idx_payslip_category (payslip_id, category)
);
```

---

### H. Tabel Tambahan: `payroll_adjustments` (Manual Ad-Hoc & Koreksi Retroaktif)
```sql
CREATE TABLE payroll_adjustments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    payroll_id BIGINT UNSIGNED NULL COMMENT 'NULL = antrian untuk payroll berikutnya, terisi = sudah masuk batch ini',
    retroactive_payroll_id BIGINT UNSIGNED NULL COMMENT 'Referensi batch masa lalu jika berupa koreksi/kekurangan gaji',
    type ENUM('earning', 'deduction') NOT NULL,
    name VARCHAR(150) NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    is_taxable BOOLEAN DEFAULT TRUE,
    reason TEXT NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (payroll_id) REFERENCES payrolls(id) ON DELETE SET NULL,
    FOREIGN KEY (retroactive_payroll_id) REFERENCES payrolls(id) ON DELETE SET NULL
);
```

---

### I. Tabel Tambahan: `payroll_logs` (Audit Trail Aktivitas Payroll)
```sql
CREATE TABLE payroll_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payroll_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL COMMENT 'Pelaku aksi (HRD / Finance / Admin)',
    action VARCHAR(50) NOT NULL COMMENT 'generate, recalculate, submit, approve, pay, cancel, export_bank, export_pdf',
    notes TEXT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    created_at TIMESTAMP NULL,
    FOREIGN KEY (payroll_id) REFERENCES payrolls(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
```

---

### J. Tabel Tambahan: `tax_annual_summaries` (Akumulasi Bukti Potong 1721-A1)
```sql
CREATE TABLE tax_annual_summaries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    tax_year SMALLINT NOT NULL,
    total_gross DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    total_position_deduction DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    total_jht_deduction DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    total_jp_deduction DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    total_netto DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    ptkp_status VARCHAR(10) NOT NULL,
    ptkp_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    pkp_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    pph21_payable DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    pph21_paid DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    pph21_under_over_paid DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    form_1721_a1_number VARCHAR(100) NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uq_user_tax_year (user_id, tax_year)
);
```

---

## 10. Alur Logika Bisnis & Mesin Kalkulasi (Payroll Calculation Engine)

Alur eksekusi saat HRD mengeksekusi `POST /api/v1/dashboard/payroll/generate`:

```
1. Validasi Pre-Generate:
   - Cek kelengkapan Master Gaji (employee_salaries)
   - Cek kelengkapan Profil Pajak (PTKP Status)
   - Cek nilai UMR Cabang
   │
2. Tarik Daftar Karyawan yang Berhak Diproses:
   - Karyawan aktif (is_active = true) ATAU
   - Karyawan resign pada periode ini (resigned_date berada di rentang start_date s.d. end_date)
   │
3. Loop Setiap Karyawan:
   │
   ├── A. Tarik Data Gaji Pokok & Cek Prorate:
   │      - Jika joined_date atau resigned_date berada di tengah periode:
   │        Hitung Prorate Basic Salary = (Hari Kerja Aktif / Total Hari Kerja) * Basic
   │
   ├── B. Tarik Data Presensi & Hari Libur:
   │      - present_days, late_days, early_leave_days, absent_days (alpha)
   │      - Total approved overtime_minutes dari overtime_approvals
   │
   ├── C. Hitung Tunjangan Tidak Tetap:
   │      - Transport & Makan = present_days * tarif harian
   │      - Lembur = PP 35 koefisien * Upah Sejam
   │      - Approved Reimbursement dari tabel receipts
   │
   ├── D. Tarik Manual & Retroactive Adjustments (payroll_adjustments)
   │
   ├── E. Hitung Potongan Presensi & Pinjaman (employee_loans)
   │
   ├── F. Hitung Iuran BPJS Kes & BPJS TK (Terapkan Cap dari bpjs_configs)
   │
   ├── G. Hitung Pajak PPh 21:
   │      - JIKA Periode = Desember ATAU karyawan resign pada periode ini:
   │        Hitung PPh 21 Pasal 17 Tahunan (Neto Setahun - PTKP * Tarif Progresif) - Total PPh21 Jan..(N-1)
   │      - SELAIN ITU:
   │        Hitung PPh 21 TER Bulanan = Bruto Kena Pajak * % TER (tax_ter_rates)
   │      - Terapkan Denda 20% jika NPWP null
   │      - Terapkan Tunjangan Pajak jika Gross-Up
   │
   ├── H. Hitung Nilai Akhir Take Home Pay
   │
   └── I. Simpan Snapshot ke payslips & payslip_items
   │
4. Kalkulasi Total Header Batch di payrolls (status: 'calculated')
5. Catat Log ke payroll_logs (action: 'generate')
```

---

## 11. Rancangan RESTful API Endpoints & Role Permissions

Semua endpoint dilindungi `auth:sanctum` dan isolasi `company_id`.

### Matriks Hak Akses (Separation of Duties):
| Modul / Tindakan | HRD | Finance | Admin / Super Admin | Employee |
|---|:---:|:---:|:---:|:---:|
| Master Komponen & Gaji Karyawan | ✅ Read/Write | 👁️ Read | ✅ Read/Write | ❌ |
| Generate & Recalculate Payroll Draft | ✅ Execute | ❌ | ✅ Execute | ❌ |
| Input Adjustment / Koreksi Retroaktif | ✅ Input | 👁️ Review | ✅ Manage | ❌ |
| Submit Draft for Approval | ✅ Submit | ❌ | ✅ Submit | ❌ |
| Approve / Reject Batch Payroll | ❌ | ✅ Approve | ✅ Approve | ❌ |
| Mark as Paid & Export Bank CSV | ❌ | ✅ Execute | ✅ Execute | ❌ |
| Download Slip Gaji PDF Karyawan | 👁️ All | 👁️ All | 👁️ All | 👁️ Milik Sendiri |
| Akses Dashboard Analytics Payroll | 👁️ Cabang | 👁️ Konsol | 👁️ Full Konsol | ❌ |

---

### A. Pengaturan & Master Data
- `GET    /api/v1/dashboard/payroll/components` $\rightarrow$ List master komponen
- `POST   /api/v1/dashboard/payroll/components` $\rightarrow$ Tambah komponen
- `PUT    /api/v1/dashboard/payroll/components/{id}` $\rightarrow$ Update komponen
- `GET    /api/v1/dashboard/payroll/employees/{id}/salary` $\rightarrow$ Detail gaji karyawan
- `POST   /api/v1/dashboard/payroll/employees/{id}/salary` $\rightarrow$ Set gaji pokok baru (versioning)
- `POST   /api/v1/dashboard/payroll/employees/{id}/components` $\rightarrow$ Assign tunjangan tetap karyawan

### B. Adjustments & Koreksi Retroaktif
- `GET    /api/v1/dashboard/payroll/adjustments` $\rightarrow$ List penyesuaian/koreksi pending & applied
- `POST   /api/v1/dashboard/payroll/adjustments` $\rightarrow$ Input bonus ad-hoc, denda, atau koreksi retroaktif
- `DELETE /api/v1/dashboard/payroll/adjustments/{id}` $\rightarrow$ Hapus adjustment sebelum payroll di-generate

### C. Pemrosesan Batch Payroll
- `GET    /api/v1/dashboard/payroll` $\rightarrow$ List riwayat batch penggajian
- `POST   /api/v1/dashboard/payroll/generate` $\rightarrow$ Generate draf batch baru
- `GET    /api/v1/dashboard/payroll/{id}` $\rightarrow$ Detail summary batch & daftar payslip
- `POST   /api/v1/dashboard/payroll/{id}/recalculate` $\rightarrow$ Hitung ulang draf
- `POST   /api/v1/dashboard/payroll/{id}/submit` $\rightarrow$ HRD mengajukan ke Finance
- `POST   /api/v1/dashboard/payroll/{id}/approve` $\rightarrow$ Finance/Director menyetujui batch
- `POST   /api/v1/dashboard/payroll/{id}/pay` $\rightarrow$ Tandai dibayarkan (*Paid*) & disbursement
- `DELETE /api/v1/dashboard/payroll/{id}` $\rightarrow$ Batalkan draf batch

### D. Export, Laporan, & Bukti Potong Pajak
- `GET    /api/v1/dashboard/payroll/payslips/{id}/pdf` $\rightarrow$ Download PDF slip gaji
- `GET    /api/v1/dashboard/payroll/{id}/export/bank-csv` $\rightarrow$ Download CSV format transfer bank
- `GET    /api/v1/dashboard/payroll/{id}/export/summary-excel` $\rightarrow$ Download rekap gaji excel
- `GET    /api/v1/dashboard/payroll/{id}/export/bpjs-csv` $\rightarrow$ Download CSV format e-Dabu & SIPP
- `GET    /api/v1/dashboard/payroll/tax/1721-a1/{userId}/{year}` $\rightarrow$ Download Formulir 1721-A1 Bukti Potong Pajak
- `GET    /api/v1/dashboard/payroll/analytics/summary` $\rightarrow$ Data ringkasan biaya payroll & tren bulanan

### E. Akses Karyawan Mobile (Flutter)
- `GET    /api/v1/payroll/my-payslips` $\rightarrow$ Daftar riwayat slip gaji milik sendiri
- `GET    /api/v1/payroll/my-payslips/{id}` $\rightarrow$ Detail slip gaji
- `GET    /api/v1/payroll/my-payslips/{id}/download` $\rightarrow$ Unduh PDF slip gaji

---

## 12. Fase & Tahapan Implementasi (Implementation Roadmap)

```
┌─────────────────────────────────────────────────────────────────────────────┐
│ FASE 1: Fondasi Master Data, Database Migration, & Enkripsi                 │
│ • Tambah field gaji, bank, pajak, resigned_date, & casts encrypted di User │
│ • Tambah field payroll & UMR di attendance_settings & companies             │
│ • Migrasi: salary_components, employee_salaries, employee_salary_components,│
│   tax_ter_rates, tax_progressive_rates, bpjs_configs, employee_loans,       │
│   payroll_adjustments, payroll_logs, tax_annual_summaries                   │
│ • Seeder: Tarif TER PMK 168/2023, Tarif Pasal 17 UU HPP, & Cap BPJS 2024-26 │
└──────────────────────────────────────┬──────────────────────────────────────┘
                                       │
┌──────────────────────────────────────▼──────────────────────────────────────┐
│ FASE 2: Master Controller, Adjustment Module, & UI Pengaturan (Web HRD)     │
│ • CRUD Master Salary Components & Cap BPJS                                  │
│ • Form Pengaturan Finansial Karyawan (Gaji, PTKP, NPWP, Bank, Resigned Date)│
│ • Modul Input Payroll Adjustments (Bonus Ad-Hoc & Koreksi Retroaktif)       │
│ • Pengaturan UMR, Cut-off, & Skema Potongan per Cabang                      │
└──────────────────────────────────────┬──────────────────────────────────────┘
                                       │
┌──────────────────────────────────────▼──────────────────────────────────────┐
│ FASE 3: Payroll Calculation Core Service & Unit Testing                     │
│ • `PayrollCalculationService`: Engine utama kalkulasi batch & prorate       │
│ • `Pph21CalculatorService`: Engine TER Bulanan + Pasal 17 Akhir/Resign      │
│ • `BpjsCalculatorService`: Engine BPJS Kes & TK dengan Cap Dinamis          │
│ • Integrasi Presensi, Lembur Approved, Reimbursement, Pinjaman, Adjustments │
│ • Automated Unit Test: Memastikan akurasi matematis vs Kalkulator Resmi DJP │
└──────────────────────────────────────┬──────────────────────────────────────┘
                                       │
┌──────────────────────────────────────▼──────────────────────────────────────┐
│ FASE 4: Batch Workflow, Multi-Level Approval, & Audit Logs                  │
│ • Alur: Draft → Calculated → Submitted → Approved → Paid                    │
│ • Separation of Duties: HRD generate & submit, Finance approve & pay        │
│ • Audit Log: Pencatatan seluruh aksi di `payroll_logs`                      │
│ • Integrasi Notifikasi Approval (Email & FCM)                               │
└──────────────────────────────────────┬──────────────────────────────────────┘
                                       │
┌──────────────────────────────────────▼──────────────────────────────────────┐
│ FASE 5: Generator PDF Slip Gaji, Bank Export, & Laporan Pajak 1721-A1       │
│ • Generator PDF Slip Gaji Karyawan (Desain Resmi + Watermark Status)        │
│ • Export Format Bank Transfer (BCA Payroll, Mandiri MCM, Generic CSV)       │
│ • Export Laporan Bulanan BPJS (e-Dabu & SIPP)                               │
│ • Generator Bukti Potong PPh 21 Tahunan (Formulir 1721-A1)                  │
│ • Dashboard Ringkasan & Tren Pengeluaran Gaji                               │
└──────────────────────────────────────┬──────────────────────────────────────┘
                                       │
┌──────────────────────────────────────▼──────────────────────────────────────┐
│ FASE 6: Integrasi Mobile Flutter                                            │
│ • Menu "Slip Gaji" di Tab Profil / Beranda Mobile                           │
│ • Tampilan Interaktif Detail Rincian Penghasilan & Potongan                 │
│ • Fitur Download & Share PDF Slip Gaji ke Storage HP                        │
│ • Push Notification (FCM) otomatis saat Gaji Resmi Dibayarkan (Paid)        │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## 13. Strategi Pengujian & Validasi Perhitungan (Testing & QA)

Untuk menjamin keakuratan 100% pada aspek finansial dan kepatuhan hukum:

1. **Unit Test Kalkulator PPh 21**:
   - Uji TER Kategori A, B, C pada rentang batas minimum, tengah, dan maksimum.
   - Uji PPh 21 Masa Terakhir (Desember) membandingkan total TER Jan-Nov dengan Pasal 17 setahun.
   - Uji PPh 21 untuk Karyawan Resign di Bulan Juni (Pasal 17 masa 6 bulan).
   - Uji Metode Gross-Up (Konvergensi nilai tunjangan pajak ekuivalen).
   - Uji Penalti 20% tanpa NPWP.
2. **Unit Test BPJS**:
   - Uji pemotongan gaji di bawah cap vs di atas cap (misal gaji Rp 25.000.000).
3. **Unit Test Lembur PP 35/2021**:
   - Uji lembur hari kerja normal (1.5x jam pertama, 2x jam berikutnya).
   - Uji lembur hari libur skema 5 hari dan 6 hari kerja.
4. **Unit Test Prorate**:
   - Uji karyawan masuk tanggal 15 dengan cut-off 25.
   - Uji karyawan resign tanggal 10.
5. **Security & PII Test**:
   - Verifikasi kolom `bank_account_no`, `npwp`, `bpjs_kesehatan_no` terenkripsi di MySQL dan dapat didekripsi via model.

---

## 14. Keputusan Bisnis Terkonfirmasi (Business Decisions Log)

| No | Topik Keputusan | Hasil Konfirmasi | Implementasi Teknis |
|---|---|---|---|
| 1 | **Format Export Bank** | Generic CSV + Template Khusus (BCA Payroll & Mandiri) | Disediakan endpoint download CSV serbaguna yang kompatibel dengan internet banking korporat. |
| 2 | **Tracking Resign** | **Wajib Ada** kolom tanggal keluar terpisah dari status aktif | Ditambahkan `users.resigned_date` untuk dasar prorate resign dan pemicu kalkulasi PPh 21 masa akhir. |
| 3 | **Karyawan Harian / Freelance** | Menggunakan modul `salary_type` harian atau melalui penyesuaian ad-hoc | Tersedia pilihan `salary_type = 'daily'/'hourly'` dan modul `payroll_adjustments`. |
| 4 | **Koreksi Retroaktif** | **Wajib Ada** untuk penyesuaian payroll masa lalu yang sudah *paid* | Dibuat tabel `payroll_adjustments` dengan atribut `retroactive_payroll_id`. |
| 5 | **Enkripsi PII Finansial** | Diimplementasikan dengan standar keamanan industri | Menggunakan Laravel Eloquent `encrypted` casting (AES-256-CBC). |
