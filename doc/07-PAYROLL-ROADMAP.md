# ExpenseFlow — Roadmap & Arsitektur Fitur Payroll (Penggajian Komprehensif)

> **Dokumen Spesifikasi Teknis & Bisnis Modul Payroll HRIS ExpenseFlow**  
> Terakhir diperbarui: 2026-09-01 (Revisi V3 — Master Spesifikasi Lengkap: Fleksibilitas Multi-Perusahaan, Kepatuhan Regulasi PPh 21/BPJS, Kontrol Anti-Fraud, & Keamanan Finansial)  
> Standar Regulasi: **PP No. 58 Tahun 2023 & PMK No. 168 Tahun 2023 (PPh 21 TER 2024)**, **UU No. 7 Tahun 2021 (UU HPP - Pasal 17)**, **PP No. 35 Tahun 2021 (Lembur, PKWT, & PHK)**, **Permenaker No. 6 Tahun 2016 (THR Keagamaan)**, **UU BPJS Kesehatan & Ketenagakerjaan**, **PP No. 6 Tahun 2025 (JKP)**, **PMK No. 66 Tahun 2023 (Natura & Kenikmatan)**, **PMK No. 81 Tahun 2024 / PER-11/PJ/2025 (Coretax)**, dan **UU No. 27 Tahun 2022 (Pelindungan Data Pribadi)**.

---

## DAFTAR ISI
1. [Latar Belakang & Ruang Lingkup Sistem](#1-latar-belakang--ruang-lingkup-sistem)
2. [Arsitektur Organisasi: Legal Entity, Payroll Group, & Kantor Presensi](#2-arsitektur-organisasi-legal-entity-payroll-group--kantor-presensi)
3. [Manajemen Profil Finansial Karyawan & Keamanan PII](#3-manajemen-profil-finansial-karyawan--keamanan-pii)
4. [Standar Pajak PPh 21 Indonesia (TER 2024, UU HPP, & PMK 168/2023)](#4-standar-pajak-pph-21-indonesia-ter-2024-uu-hpp--pmk-1682023)
5. [Standar BPJS Kesehatan & Ketenagakerjaan (Termasuk JKP & Dynamic Cap)](#5-standar-bpjs-kesehatan--ketenagakerjaan-termasuk-jkp--dynamic-cap)
6. [Struktur Komponen Gaji, Mesin Formula (DSL), & Penyesuaian](#6-struktur-komponen-gaji-mesin-formula-dsl--penyesuaian)
7. [Sistem Lembur PP 35/2021 & Preservasi Presensi](#7-sistem-lembur-pp-352021--preservasi-presensi)
8. [Sistem Tunjangan Hari Raya (THR Keagamaan) Permenaker 6/2016](#8-sistem-tunjangan-hari-raya-thr-keagamaan-permenaker-62016)
9. [Sistem Kompensasi PKWT & Exit Settlement PHK/Resign](#9-sistem-kompensasi-pkwt--exit-settlement-phkresign)
10. [Perancangan Skema Database (Database Schema Design)](#10-perancangan-skema-database-database-schema-design)
11. [Alur Logika Bisnis & Mesin Kalkulasi (Calculation Engine)](#11-alur-logika-bisnis--mesin-kalkulasi-calculation-engine)
12. [Siklus Pencairan Dana (Disbursement) & Rekonsiliasi Bank](#12-siklus-pencairan-dana-disbursement--rekonsiliasi-bank)
13. [Integrasi Akuntansi (General Ledger) & Alokasi Biaya](#13-integrasi-akuntansi-general-ledger--alokasi-biaya)
14. [Kontrol Internal, Separation of Duties, & Hak Akses API](#14-kontrol-internal-separation-of-duties--hak-akses-api)
15. [Strategi Keamanan Finansial, Pelindungan Data Pribadi (PDP), & Audit Trail](#15-strategi-keamanan-finansial-pelindungan-data-pribadi-pdp--audit-trail)
16. [Fase Implementasi & Prioritas Roadmap (P0, P1, P2)](#16-fase-implementasi--prioritas-roadmap-p0-p1-p2)
17. [Strategi Pengujian, Quality Gates, & Parallel Run](#17-strategi-pengujian-quality-gates--parallel-run)
18. [Referensi Regulasi Resmi](#18-referensi-regulasi-resmi)

---

## 1. Latar Belakang & Ruang Lingkup Sistem

Modul Payroll ExpenseFlow dirancang sebagai mesin kalkulasi otomatis penggajian yang patuh hukum, fleksibel untuk berbagai model bisnis/industri (korporat, manufaktur, ritel, agensi, remote/WFH), serta memiliki pertahanan keamanan data finansial setara standar perbankan.

### Prinsip Utama Sistem Payroll:
1. **Multi-Entity & Multi-Group**: Mendukung pemisahan badan hukum (PT/CV), unit pendaftaran pajak/BPJS terpisah, dan kelompok payroll (bulanan, mingguan, harian) tanpa terikat kaku pada lokasi fisik kantor presensi.
2. **Kepatuhan Regulasi Indonesia**:
   - PPh 21 TER bulanan (Kategori A, B, C PMK 168/2023) + rekonsiliasi masa pajak terakhir (Pasal 17 UU HPP).
   - Konsolidasi pajak per masa pajak bulanan (pembayaran gaji reguler, THR, bonus, dan off-cycle dalam bulan yang sama dikonsolidasikan pajaknya).
   - 5 Program BPJS Ketenagakerjaan (JKK, JKM, JHT, JP, JKP) & BPJS Kesehatan dengan cap upah dinamis dan berversi tanggal efektif (*effective-dated*).
   - Formula lembur PP 35/2021, THR Permenaker 6/2016, Kompensasi PKWT, serta modul *Exit Settlement* pesangon/PHK.
3. **Data Historis Kekal & Reprodusibel (*Immutable & Traceable*)**:
   - Payslip yang telah disetujui (*approved*) atau dibayar (*paid*) membekukan snapshot seluruh angka, komponen, dan pajak.
   - Dilengkapi *Calculation Trace* rincian langkah formula sehingga setiap rupiah dapat diaudit dan direproduksi kembali persis.
4. **Pemisahan Wewenang (*Separation of Duties & Maker-Checker*)**:
   - Tidak ada satu user yang dapat mengubah data gaji/rekening, meng-generate draf, menyetujui, dan mencairkan pembayaran sekaligus.
   - Larangan *self-approval* dan proteksi khusus perubahan rekening bank karyawan.
5. **Keamanan & Pelindungan Data Pribadi (UU PDP No. 27/2022)**:
   - Data sensitif finansial (rekening, NPWP, BPJS, nominal) dienkripsi di level basis data (baik data master maupun snapshot payslip).
   - Penghapusan user tidak menghapus data transaksi finansial (*No Cascade Delete*, wajib `RESTRICT`).
   - Audit log append-only dengan hash rantai perubahan.

---

## 2. Arsitektur Organisasi: Legal Entity, Payroll Group, & Kantor Presensi

Untuk menjamin sistem dapat digunakan oleh hampir semua jenis perusahaan (termasuk holding company, multi-cabang, atau multi-skema gaji), struktur organisasi dipisahkan menjadi 3 domain independen:

```
┌─────────────────────────────────────────────────────────────────────────────┐
│ 1. LEGAL ENTITY (Badan Hukum / Perusahaan Penggaji)                          │
│    • NPWP Perusahaan, NPP BPJS TK, Kode Badan Usaha BPJS Kesehatan          │
│    • Penandatangan Bukti Potong Pajak (Coretax / 1721-A1)                   │
└──────────────────────────────────────┬──────────────────────────────────────┘
                                       │ 1:N
┌──────────────────────────────────────▼──────────────────────────────────────┐
│ 2. PAYROLL GROUP (Kelompok Skema Payroll)                                   │
│    • Tanggal Cut-off Presensi & Tanggal Pembayaran Gaji                     │
│    • Frekuensi (Bulanan, 2-Mingguan, Mingguan, Harian)                      │
│    • Mata Uang (IDR, USD, dll) & Aturan Kebijakan Prorate                   │
└──────────────────────────────────────┬──────────────────────────────────────┘
                                       │ 1:N
┌──────────────────────────────────────▼──────────────────────────────────────┐
│ 3. EMPLOYEE ASSIGNMENT (Penugasan Karyawan — Effective Dated)                │
│    • Relasi Karyawan ke Legal Entity, Payroll Group, Cost Center, & Jabatan │
│    • Berelasi secara modular ke ATTENDANCE OFFICE (Lokasi GPS & Shift Kerja)│
└─────────────────────────────────────────────────────────────────────────────┘
```

### Keunggulan Pemisahan Domain:
- **Karyawan Pindah Cabang / Mutasi**: Cukup membuat record baru di `employee_assignments` dengan `effective_from`. Riwayat payroll di cabang/entitas lama tetap utuh.
- **Kantor Fisik vs Entitas Pajak**: Satu kantor fisik (misal Head Office di Jakarta) dapat menampung karyawan dari 2 legal entity berbeda.
- **Skema Penggajian Beragam**: Staf kantor ikut Payroll Group Bulanan (Cut-off 25), sedangkan teknisi lapangan ikut Payroll Group 2-Mingguan, di bawah legal entity yang sama.

---

## 3. Manajemen Profil Finansial Karyawan & Keamanan PII

Profil finansial karyawan disimpan menggunakan model **Effective-Dated History** (bukan hanya menimpa nilai kolom di tabel `users`). Ini menjamin bahwa perhitungan payroll masa lalu tetap dapat direproduksi sesuai kondisi data saat itu.

### A. Tabel Master Profil Finansial Karyawan

1. **`employee_tax_profiles` (Histori Status Pajak)**:
   - `user_id`, `company_id`, `effective_from`, `effective_to`
   - `tax_subject_type`: `'permanent_employee'`, `'non_permanent_daily'`, `'non_permanent_monthly'`, `'non_employee'`, `'commissioner'`, `'former_employee'`, `'expatriate'`
   - `tax_method`: `'gross'` (potong gaji), `'gross_up'` (tunjangan pajak ekuivalen), `'nett'` (pajak ditanggung perusahaan)
   - `ptkp_status`: `'TK/0'`, `'TK/1'`, `'TK/2'`, `'TK/3'`, `'K/0'`, `'K/1'`, `'K/2'`, `'K/3'`, `'K/I/0'` s.d. `'K/I/3'`
   - `marital_status`: `'single'`, `'married'`, `'widowed'`
   - `dependents_count`: `tinyint` (0 s.d. 3 tanggungan)
   - `spouse_has_separate_tax`: `boolean` (status pisah harta / NPWP terpisah)
   - `npwp_number`: `text` *(Encrypted AES-256)*
   - `tax_identity_status`: `'valid_npwp'`, `'valid_nik_npwp'`, `'pending_validation'`, `'invalid'`, `'foreign_tax_id'`
   - `has_npwp`: `boolean` (tervalidasi NIK-NPWP aktif)
   - `approved_by`, `created_at`

2. **`employee_bpjs_profiles` (Histori Kepesertaan BPJS)**:
   - `user_id`, `company_id`, `effective_from`, `effective_to`
   - `bpjs_kes_no`: `text` *(Encrypted AES-256)*
   - `bpjs_tk_no`: `text` *(Encrypted AES-256)*
   - `has_bpjs_kes`: `boolean` (default: `true`)
   - `has_jkk`: `boolean` (default: `true`)
   - `has_jkm`: `boolean` (default: `true`)
   - `has_jht`: `boolean` (default: `true`)
   - `has_jp`: `boolean` (default: `true`, non-aktif untuk pekerja asing/kontrak tertentu)
   - `has_jkp`: `boolean` (default: `true` untuk PKWTT & PKWT terdaftar)
   - `approved_by`, `created_at`

3. **`employee_bank_accounts` (Histori & Verifikasi Rekening Bank)**:
   - `user_id`, `company_id`, `bank_name`, `bank_account_no` *(Encrypted AES-256)*, `bank_account_holder`, `bank_branch`, `swift_code`
   - `status`: `'pending_verification'`, `'active'`, `'superseded'`, `'rejected'`
   - `is_primary`: `boolean`
   - `verified_by`, `verified_at`, `notes`

### B. Proteksi Perubahan Rekening Bank (Maker-Checker & Anti-Fraud)
Untuk mencegah pengalihan transfer gaji secara ilegal:
- Karyawan/HRD mengajukan perubahan rekening $\rightarrow$ status `pending_verification`.
- Verifikasi wajib dilakukan oleh pejabat berwenang (Finance Manager / Admin) terpisah dari pembuat pengajuan.
- Sistem otomatis mengirimkan notifikasi peringatan keamanan ke email dan aplikasi mobile karyawan saat rekening diubah.
- Payroll yang sudah berstatus `approved` membekukan snapshot rekening bank saat approval, tidak terpengaruh oleh rekening baru yang diajukan setelahnya.

### C. Standar Enkripsi PII Finansial & Manajemen Kunci
- **Cakupan Enkripsi**: Seluruh data rekening bank, NPWP, BPJS, serta snapshot identitas finansial pada tabel `payslips` wajib dienkripsi.
- **Tampilan Antarmuka (Masking)**: Data rekening dan NPWP ditampilkan termasking di frontend (contoh: `BCA •••• 5678`, `NPWP •••.•••.789-012.000`). Data penuh hanya dapat di-decrypt oleh user yang memiliki permission eksplisit `payroll.pii.view_full` dan tercatat di audit log.
- **KMS / Key Versioning**: Mendukung pemisahan key enkripsi finansial dari `APP_KEY` dan menyediakan field `key_version` untuk prosedur rotasi kunci (*key rotation*) tanpa merusak data lama.

---

## 4. Standar Pajak PPh 21 Indonesia (TER 2024, UU HPP, & PMK 168/2023)

Sesuai **PP 58/2023** dan **PMK 168/2023**, pemotongan PPh Pasal 21 atas penghasilan pegawai tetap menggunakan mekanisme dua tahap:

```
[Bulan Januari s.d. November]     → Menggunakan Tarif Efektif Rata-Rata (TER) Bulanan
[Bulan Desember / Masa Terakhir]  → Menggunakan Tarif Progresif Pasal 17 UU HPP (Tahunan) - Total TER Sebelumnya
```

---

### A. Konsolidasi Masa Pajak Bulanan (*Tax Period vs Payroll Batch*)

> [!IMPORTANT]
> **Prinsip Konsolidasi Masa Pajak**:  
> Perusahaan dapat menjalankan beberapa batch pembayaran dalam 1 bulan kalender (contoh: Batch Gaji Reguler tanggal 25, Batch THR tanggal 10, Batch Bonus tanggal 28). Namun, untuk perhitungan PPh 21 bulanan, seluruh penghasilan bruto kena pajak dalam masa pajak tersebut **wajib dikonsolidasikan**.

Mesin pajak mengelola tabel konsolidasi: `employee_tax_period_totals`:
$$\text{Bruto Masa Pajak} = \sum \text{Bruto Reguler} + \sum \text{Bruto Tidak Teratur (THR/Bonus)} + \sum \text{Natura Kena Pajak}$$
$$\text{PPh 21 Batch Terakhir} = \text{Total PPh 21 TER atas Bruto Konsolidasi} - \sum \text{PPh 21 yang telah dipotong pada batch sebelumnya di bulan yang sama}$$

Dengan formula ini, THR atau bonus yang dibayarkan di batch terpisah tidak akan salah menerapkan tarif pajak.

---

### B. Pengelompokan Kategori TER Berdasarkan PTKP

| Kategori TER | Status PTKP Karyawan | Batasan Penghasilan Tidak Kena Pajak (PTKP Setahun) |
|---|---|---|
| **Kategori A** | `TK/0`, `TK/1`, `K/0` | TK/0: Rp 54.000.000, TK/1 & K/0: Rp 58.500.000 |
| **Kategori B** | `TK/2`, `TK/3`, `K/1`, `K/2` | TK/2 & K/1: Rp 63.000.000, TK/3 & K/2: Rp 67.500.000 |
| **Kategori C** | `K/3` | K/3: Rp 72.000.000 |

*(Untuk status istri bekerja dengan penghasilan digabung / pisah harta, penentuan tarif TER dan PTKP tahunan mengikuti rule engine `employee_tax_profiles`).*

Tabel master tarif TER disimpan dalam `tax_ter_rates` (Kategori A, B, C) dengan rentang nominal dan tarif persentase resmi PMK 168/2023.

---

### C. Rumus PPh 21 Masa Pajak Terakhir (Desember ATAU Masa Berhenti Kerja)

Masa pajak terakhir berlaku pada:
1. **Bulan Desember** untuk seluruh pegawai tetap yang masih aktif.
2. **Bulan Karyawan Berhenti Bekerja** (`resigned_date` / PHK) di tengah tahun berjalan.

#### Tahapan Perhitungan:
1. **Penghasilan Bruto Aktual / Disetahunkan**:
   - Pegawai aktif di bulan Desember atau berhenti normal: $\text{Bruto Aktual Masa Bekerja} = \sum_{m=1}^{N} \text{Bruto Masa } m$.
   - Pegawai kewajiban pajak subjektifnya dimulai/berakhir di tengah tahun (misal ekspatriat pindah ke luar negeri selamanya): Bruto disetahunkan sesuai ketentuan perundang-undangan.
2. **Pengurang Penghasilan Bruto**:
   - **Biaya Jabatan**: 5% dari Bruto, maksimal **Rp 500.000/bulan** (maksimal Rp 6.000.000/tahun atau Rp $500.000 \times \text{bulan bekerja}$).
   - **Iuran JHT Karyawan**: 2% dari upah $\times$ bulan bekerja.
   - **Iuran JP Karyawan**: 1% dari upah $\times$ bulan bekerja.
3. **Penghasilan Neto**: $\text{Penghasilan Bruto} - \text{Total Pengurang}$.
4. **Penghasilan Kena Pajak (PKP)**: $\text{Penghasilan Neto} - \text{Nilai PTKP Tahunan}$ (dibulatkan ke bawah dalam ribuan penuh / *floor* 1.000).
5. **Tarif Progresif Pasal 17 ayat (1) huruf a UU HPP (`tax_progressive_rates`)**:
   - Lapisan I: $0 \text{ s.d. } 60.000.000 \rightarrow \mathbf{5\%}$
   - Lapisan II: $> 60.000.000 \text{ s.d. } 250.000.000 \rightarrow \mathbf{15\%}$
   - Lapisan III: $> 250.000.000 \text{ s.d. } 500.000.000 \rightarrow \mathbf{25\%}$
   - Lapisan IV: $> 500.000.000 \text{ s.d. } 5.000.000.000 \rightarrow \mathbf{30\%}$
   - Lapisan V: $> 5.000.000.000 \rightarrow \mathbf{35\%}$
6. **PPh 21 Terutang Masa Terakhir**:
   $$\text{PPh 21 Masa Terakhir} = \text{Total PPh 21 Terutang Setahun} - \sum_{m=1}^{N-1} \text{PPh 21 TER yang telah dipotong sebelumnya}$$
   *(Jika bernilai minus/lebih bayar, perusahaan mengembalikan kelebihan potong kepada karyawan pada slip gaji masa tersebut dan mengkompensasikan pada SPT Masa PPh 21 perusahaan).*

---

### D. Skema Pajak Pegawai Tidak Tetap, Bukan Pegawai, & Natura (PMK 66/2023)

1. **Pegawai Tidak Tetap / Harian Lepas (PMK 168/2023)**:
   - Upah harian $\le$ Rp 2.500.000/hari dan kumulatif sebulan $\le$ Rp 2.500.000: Dikenakan TER Harian yang berlaku.
   - Upah dibayar bulanan: Dikenakan TER Bulanan sesuai kategori PTKP-nya.
2. **Bukan Pegawai / Tenaga Ahli / Freelancer**:
   - $\text{PPh 21} = 50\% \times \text{Penghasilan Bruto Kumulatif} \times \text{Tarif Progresif Pasal 17}$.
3. **Natura & Kenikmatan (PMK 66/2023)**:
   - Komponen benefit berupa natura yang melebihi batas pengecualian undang-undang (misal fasilitas tempat tinggal/kendaraan non-operasional) dimasukkan sebagai penambah penghasilan bruto kena pajak (*benefit-in-kind*), tanpa menambah nominal uang tunai yang ditransfer.
4. **Reimbursement Biaya Operasional**:
   - Penggantian biaya operasional kantor berbasis struk valid (*accountable business expense*) ditetapkan sebagai **Non-Taxable** (bukan objek PPh 21).

---

### E. Dokumen Pajak & Integrasi Coretax DJP

Sistem mengelola tabel generik `tax_documents` untuk menghasilkan:
- **Bukti Potong PPh 21 Bulanan (Formulir 1721-VIII / format Coretax DJP)**.
- **Bukti Potong PPh 21 Tahunan (Formulir 1721-A1 / A2)** yang diterbitkan otomatis di akhir tahun atau saat karyawan resign.
- File ekspor XML/CSV terstandarisasi untuk impor ke sistem DJP Coretax / e-Bupot.

---

## 5. Standar BPJS Kesehatan & Ketenagakerjaan (Termasuk JKP & Dynamic Cap)

Perhitungan iuran BPJS mengacu pada Upah Pokok + Tunjangan Tetap (dengan batas upah minimum UMR dan batas upah maksimum regulasi):

```
+-----------------------------------------------------------------------------------------+
| PROGRAM BPJS                | DIBAYAR PERUSAHAAN | DIBAYAR KARYAWAN | TOTAL   | DASAR UPAH       |
+-----------------------------+--------------------+------------------+---------+------------------+
| BPJS Kesehatan              | 4.0%               | 1.0%             | 5.0%    | Upah (Max Cap)   |
| JKK (Kecelakaan Kerja)      | 0.24% - 1.74%      | 0.0%             | 0.24%+  | Upah Riil        |
| JKM (Kematian)              | 0.30%              | 0.0%             | 0.30%   | Upah Riil        |
| JHT (Hari Tua)              | 3.70%              | 2.00%            | 5.70%   | Upah Riil        |
| JP (Jaminan Pensiun)        | 2.00%              | 1.00%            | 3.00%   | Upah (Max Cap)   |
| JKP (Kehilangan Pekerjaan)  | Rekomposisi APBN   | 0.0%             | -       | Upah (Max Cap)   |
+-----------------------------------------------------------------------------------------+
```

### A. Dynamic Statutory Rule Versioning (`statutory_rule_versions`)
Batas atas (*ceiling cap*) dan tarif BPJS disimpan berversi tanggal efektif agar jika pemerintah memperbarui batas upah di awal tahun kalender, sistem langsung menggunakan aturan baru tanpa perlu mengubah kode atau merusak riwayat masa lalu:
- **Batas Atas Upah BPJS Kesehatan**: Rp 12.000.000 (Maks iuran perusahaan Rp 480.000, karyawan Rp 120.000).
- **Batas Atas Upah BPJS JP**: Disimpan per periode efektif (misal 2024: Rp 10.042.300 / 2026: mengikuti Keputusan BPJS Ketenagakerjaan).
- **Program JKP (PP No. 37/2021 & PP No. 6/2025)**: Status kepesertaan JKP dicatat otomatis untuk seluruh pekerja PKWTT dan PKWT yang memenuhi kriteria eligibilitas.

### B. Pemetaan Komponen ke Upah Dasar BPJS (`component_statutory_treatments`)
Setiap komponen penghasilan dapat diatur secara granular apakah masuk dalam dasar upah program tertentu (BPJS Kesehatan, JHT, JP, JKK, JKM) atau dikecualikan.

---

## 6. Struktur Komponen Gaji, Mesin Formula (DSL), & Penyesuaian

ExpenseFlow menyediakan struktur komponen gaji fleksibel yang mendukung berbagai variasi industri:

```
                            PENGHASILAN BRUTO (GROSS EARNINGS)
                                            │
       ┌────────────────────────────────────┼────────────────────────────────────┐
       ▼                                    ▼                                    ▼
   GAJI DASAR & TETAP              TUNJANGAN TIDAK TETAP & VARIABEL      BENEFIT & PENYESUAIAN
   • Gaji Pokok (Monthly/Daily)    • Uang Kehadiran (Transport/Makan)    • Natura / Benefit-in-Kind
   • Tunjangan Jabatan / Fungsional• Upah Lembur (Overtime Pay)          • Klaim Struk (Reimbursement)
   • Tunjangan Lokasi / Proyek     • Insentif / Komisi Penjualan         • Bonus Ad-Hoc / Rapel
   • Tunjangan Keluarga            • Tunjangan Hari Raya (THR)           • Earning Adjustment
                                   • Kompensasi PKWT / Pesangon

                                            │
                                            ▼ KURANGI
                                POTONGAN (TOTAL DEDUCTIONS)
                                            │
       ┌────────────────────────────────────┼────────────────────────────────────┐
       ▼                                    ▼                                    ▼
   POTONGAN PRESENSI                POTONGAN STATUTORI                   POTONGAN LAINNYA
   • Potongan Telat / Pulang Cepat  • PPh 21 Bulanan (TER / Pasal 17)    • Cicilan Kasbon (Loans)
   • Potongan Alpha / Mangkir       • BPJS Ketenagakerjaan (JHT & JP)    • Potongan Unpaid Leave
   • Sanksi Disiplin                • BPJS Kesehatan (1%)                • Ganti Rugi / Denda Aset
                                                                         • Deduction Adjustment

                                            │
                                            ▼ HASIL AKHIR
                              GAJI BERSIH (TAKE HOME PAY / NETT)
```

---

### A. Mesin Formula Komponen Gaji Aman (Safe Formula Engine)
Untuk mendukung komponen dinamis (misal: `Transport = Hadir * 25.000`, `Bonus = 5% * Penjualan`), sistem menyediakan mesin formula berbasis **Domain-Specific Language (DSL)** yang aman:
- **Aturan Keamanan**: **Dilarang keras menggunakan `eval()` PHP atau eksekusi raw SQL**.
- **Whitelist Variabel**: `BASIC_SALARY`, `PRESENT_DAYS`, `LATE_MINUTES`, `OVERTIME_HOURS`, `UMR_AMOUNT`, `TENURE_MONTHS`, dll.
- **Validasi Ketergantungan**: Deteksi otomatis siklus ketergantungan (*circular dependency check*) sebelum formula diaktifkan.

---

### B. Formula Prorate Karyawan Masuk & Resign di Tengah Periode
Jika karyawan baru bergabung (`joined_date`) atau berhenti (`resigned_date`) di tengah periode cut-off:

1. **Metode Hari Kerja (`working_days` — Default Depnaker)**:
   $$\text{Gaji Prorate} = \frac{\text{Hari Kerja Efektif Karyawan}}{\text{Total Hari Kerja Seharusnya dalam Periode}} \times (\text{Gaji Pokok} + \text{Tunjangan Tetap})$$
2. **Metode Hari Kalender (`calendar_days`)**:
   $$\text{Gaji Prorate} = \frac{\text{Hari Kalender Aktif}}{\text{Jumlah Hari Kalender Periode Tersebut (28–31)}} \times (\text{Gaji Pokok} + \text{Tunjangan Tetap})$$
3. **Metode Pembagi Tetap (`fixed_21` / `fixed_25`)**:
   $$\text{Gaji Prorate} = \frac{\text{Hari Kerja Aktual}}{\text{21 atau 25}} \times (\text{Gaji Pokok} + \text{Tunjangan Tetap})$$

---

### C. Modul Koreksi Retroaktif & Penyesuaian (`payroll_adjustments`)
Penyesuaian manual (bonus dadakan, ganti rugi aset, atau koreksi kekurangan/kelebihan bayar payroll bulan lalu) dikelola dengan tata kelola ketat:
- **Tipe**: `earning` (penambah) atau `deduction` (pengurang).
- **Atribut**: `is_taxable` (objek pajak), `reason`, `source_document_path`, serta relasi `retroactive_payroll_id` (periode asal kesalahan).
- **Maker-Checker**: Dibuat oleh HRD/Staf $\rightarrow$ Wajib disetujui Finance Manager sebelum masuk ke batch payroll. Tidak dapat dihapus fisik jika sudah diproses (hanya bisa di-*void* dengan pencatatan alasan).

---

### D. Kebijakan Gaji Bersih Negatif (*Negative Net Pay Policy*)
Jika total potongan (alpha + denda + cicilan kasbon) melebihi total penghasilan:
- Sistem mengunci take home pay minimum menjadi **Rp 0.00**.
- Sisa potongan yang belum tertagih otomatis dicatat sebagai **Carry-Forward Balance** untuk dipotongkan pada siklus payroll bulan berikutnya.

---

## 7. Sistem Lembur PP 35/2021 & Preservasi Presensi

Perhitungan lembur mengacu pada **PP No. 35 Tahun 2021**:

### A. Upah Sejam Dasar Lembur
$$\text{Upah Sejam} = \frac{1}{173} \times (\text{Gaji Pokok} + \text{Tunjangan Tetap})$$
*(Jika komponen upah terdiri dari upah pokok + tunjangan tidak tetap, maka upah pokok minimal 75% dari total upah).*

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
   - *Hari libur resmi terpendek (misal Jumat/Sabtu pada shift tertentu)*: Jam ke-1 s.d. Jam ke-5 = 2.0x, Jam ke-6 = 3.0x, Jam ke-7 s.d. 8 = 4.0x.

### C. Preservasi Data Presensi & Batasan K3
- **Preservasi Jam Kerja Aktual**: Jika HRD menolak pengajuan lembur, data jam lembur aktual di `attendances` **TIDAK DIHAPUS** (tetap tercatat untuk keperluan audit K3). Sistem memisahkan kolom `actual_overtime_minutes` dan `approved_payable_overtime_minutes`.
- **Batas K3 Lembur**: Peringatan otomatis jika lembur melebihi batas legal PP 35/2021 (maksimal 4 jam/hari atau 18 jam/minggu).

---

## 8. Sistem Tunjangan Hari Raya (THR Keagamaan) Permenaker 6/2016

Berdasarkan **Permenaker No. 6 Tahun 2016**:

### A. Syarat & Formula Besaran THR
1. **Masa Kerja $\ge$ 12 Bulan Terus Menerus**:
   $$\text{THR} = 1 \times (\text{Gaji Pokok} + \text{Tunjangan Tetap})$$
2. **Masa Kerja 1 Bulan s.d. < 12 Bulan (Prorate)**:
   $$\text{THR} = \frac{\text{Masa Kerja (Bulan)}}{12} \times (\text{Gaji Pokok} + \text{Tunjangan Tetap})$$
3. **Pekerja Harian Lepas**:
   - Masa kerja $\ge$ 12 bulan: Upah 1 bulan dihitung dari rata-rata upah yang diterima dalam 12 bulan terakhir.
   - Masa kerja < 12 bulan: Upah 1 bulan dihitung dari rata-rata upah yang diterima selama masa kerja.
4. **Ketentuan Resign/PHK Mendekati Hari Raya**:
   - Karyawan **PKWTT** yang hubungan kerjanya berakhir terhitung sejak **H-30 hari sebelum Hari Raya Keagamaan** tetap berhak menerima THR penuh.
   - Karyawan **PKWT** yang kontraknya berakhir sebelum Hari Raya tidak berhak atas THR.

### B. Siklus Khusus & Pajak THR
- Batch THR dijalankan melalui `payroll_type = 'thr'`.
- Pajak PPh 21 atas THR dikonsolidasikan otomatis bersama penghasilan masa pajak berjalan (lihat Bagian 4.A).

---

## 9. Sistem Kompensasi PKWT & Exit Settlement PHK/Resign

Untuk mengelola pengakhiran hubungan kerja secara lengkap dan patuh hukum:

### A. Uang Kompensasi PKWT (Pasal 15–17 PP 35/2021)
Karyawan kontrak (PKWT) yang telah bekerja minimal 1 bulan berhak atas uang kompensasi saat masa kontrak berakhir:
$$\text{Uang Kompensasi PKWT} = \frac{\text{Masa Kerja Kontrak (Bulan)}}{12} \times 1 \text{ Bulan Upah (Gaji Pokok + Tunjangan Tetap)}$$

### B. Modul Exit Settlement (Pesangon, PMTK, & UPH)
Dijalankan melalui `payroll_type = 'severance'` untuk menghitung:
1. **Gaji Terakhir & Prorate Hari Kerja Aktif**.
2. **Kompensasi Sisa Hak Cuti Tahunan yang Belum Gugur**.
3. **Uang Pesangon (UP)** sesuai masa kerja (Pasal 40 ayat 2 PP 35/2021).
4. **Uang Penghargaan Masa Kerja (UPMK)** (Pasal 40 ayat 3 PP 35/2021).
5. **Uang Penggantian Hak (UPH)** dan Uang Pisah (sesuai PP/PKB).
6. **Pemotongan Pajak PPh 21 Final atas Uang Pesangon** (PP 68/2009: 0% s.d. 50jt, 5% s.d. 100jt, 15% s.d. 500jt, 25% > 500jt).
7. **Pengembalian Aset & Pelunasan Sisa Kasbon Kantor**.

---

## 10. Perancangan Skema Database (Database Schema Design)

Berikut adalah diagram relasi entitas payroll komprehensif:

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                             companies                                       │
│                       (Legal Entity / Tenant)                               │
└──────────────────────┬───────────────────────────────┬──────────────────────┘
                       │ 1:N                           │ 1:N
┌──────────────────────▼───────┐        ┌──────────────▼──────────────────────┐
│       payroll_groups         │        │       statutory_rule_versions       │
│  (Kelompok & Kalender Gaji)  │        │ (Tarif TER, Pasal 17, BPJS, & Cap)  │
└──────────────┬───────────────┘        └─────────────────────────────────────┘
               │ 1:N
┌──────────────▼───────────────┐
│     employee_assignments     │ (Histori Penugasan Karyawan)
└──────────────┬───────────────┘
               │
   ┌───────────┼───────────┬───────────┬───────────┐
   │ 1:N       │ 1:N       │ 1:N       │ 1:N       │ 1:N
┌──▼───────┐┌──▼───────┐┌──▼───────┐┌──▼───────┐┌──▼──────────────┐
│employee_ ││employee_ ││employee_ ││employee_ ││employee_tax_    │
│salaries  ││salary_   ││tax_      ││bank_     ││period_totals    │
│(Histori) ││components││profiles  ││accounts  ││(Konsolidasi PPh)│
└──┬───────┘└──────────┘└──────────┘└──────────┘└─────────────────┘
   │
   │ 1:N
┌──▼───────────────┐        ┌──────────────────┐        ┌─────────────────────┐
│salary_components │        │ payroll_cycles   │        │ payroll_payment_    │
│(Master Komponen) │        │ (Siklus Penggajian)│       │ batches (Disburse)  │
└──┬───────────────┘        └──┬───────────────┘        └──┬──────────────────┘
   │                           │ 1:N                       │ 1:N
   │                           │                           │
   │                ┌──────────▼───────────┐    ┌──────────▼──────────┐
   │                │       payrolls       │    │ payroll_payment_    │
   │                │   (Header Batch)     │    │ items (Status Bayar)│
   │                └──────────┬───────────┘    └─────────────────────┘
   │                           │ 1:N
   │                ┌──────────▼───────────┐
   │                │       payslips       │
   │                │  (Snapshot Gaji)     │
   │                └──────────┬───────────┘
   │                           │
   │ 1:N                       ├──────────────────────────┐ 1:N
┌──▼───────────────┐┌──────────▼───────────┐   ┌──────────▼──────────┐
│payslip_items     ││payslip_calculation_  │   │ payroll_adjustments │
│(Rincian Komponen)││steps (Audit Trace)   │   │ (Koreksi & Bonus)   │
└──────────────────┘└──────────────────────┘   └─────────────────────┘
```

> [!IMPORTANT]
> **Integritas Referensial & Proteksi Arsip Finansial**:  
> Seluruh Foreign Key pada tabel transaksi penggajian (`payrolls`, `payslips`, `payslip_items`, `payslip_calculation_steps`, `payroll_payment_items`, `payroll_logs`) dikonfigurasi menggunakan **`ON DELETE RESTRICT`**. Data finansial, pajak, dan slip gaji tidak boleh hilang jika user dinonaktifkan/dihapus dari sistem.

---

### A. Tabel Organisasi & Aturan: `payroll_groups` & `statutory_rule_versions`
```sql
CREATE TABLE payroll_groups (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(50) NOT NULL,
    name VARCHAR(100) NOT NULL,
    currency VARCHAR(10) DEFAULT 'IDR',
    payroll_frequency ENUM('monthly', 'semi_monthly', 'bi_weekly', 'weekly', 'daily') DEFAULT 'monthly',
    cutoff_day TINYINT NOT NULL DEFAULT 25 COMMENT '25 = tanggal 26 bln lalu s.d. 25 bln ini; 0 = akhir bulan',
    payment_day TINYINT NOT NULL DEFAULT 1,
    prorate_formula ENUM('working_days', 'calendar_days', 'fixed_21', 'fixed_25') DEFAULT 'working_days',
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE RESTRICT,
    UNIQUE KEY uq_group_code (company_id, code)
);

CREATE TABLE statutory_rule_versions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    rule_type ENUM('tax_ter', 'tax_progressive', 'bpjs_kes', 'bpjs_tk', 'minimum_wage') NOT NULL,
    code VARCHAR(50) NOT NULL,
    category VARCHAR(50) NULL COMMENT 'Kategori A/B/C untuk TER, Program BPJS, atau Kode Daerah UMR',
    effective_from DATE NOT NULL,
    effective_to DATE NULL,
    min_value DECIMAL(15,2) DEFAULT 0.00,
    max_value DECIMAL(15,2) NULL,
    rate_percent_employee DECIMAL(6,3) DEFAULT 0.000,
    rate_percent_employer DECIMAL(6,3) DEFAULT 0.000,
    fixed_amount DECIMAL(15,2) DEFAULT 0.00,
    legal_reference VARCHAR(255) NULL COMMENT 'PP 58/2023, PMK 168/2023, dll',
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    INDEX idx_rule_effective (rule_type, category, effective_from, effective_to)
);
```

---

### B. Tabel Master: `salary_components`
```sql
CREATE TABLE salary_components (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    payroll_group_id BIGINT UNSIGNED NULL COMMENT 'NULL = berlaku global entitas',
    code VARCHAR(50) NOT NULL COMMENT 'BASIC, TRANSPORT, MEAL, POSITION, OVERTIME, THR, BONUS, etc.',
    name VARCHAR(100) NOT NULL,
    category ENUM('earning', 'deduction', 'benefit') NOT NULL,
    type ENUM('fixed', 'attendance_based', 'variable', 'formula', 'statutory') NOT NULL,
    frequency ENUM('monthly', 'daily', 'hourly', 'one_time', 'annual') DEFAULT 'monthly',
    regularity ENUM('regular', 'irregular') DEFAULT 'regular',
    tax_treatment ENUM('taxable', 'non_taxable', 'taxable_with_exemption', 'reimbursement', 'benefit_in_kind') DEFAULT 'taxable',
    is_bpjs_kes_base BOOLEAN DEFAULT FALSE,
    is_bpjs_tk_base BOOLEAN DEFAULT FALSE,
    is_overtime_base BOOLEAN DEFAULT FALSE,
    is_thr_base BOOLEAN DEFAULT FALSE,
    formula_dsl TEXT NULL COMMENT 'DSL formula aman tanpa eval()',
    calculation_order TINYINT DEFAULT 10,
    gl_debit_account VARCHAR(50) NULL,
    gl_credit_account VARCHAR(50) NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE RESTRICT,
    FOREIGN KEY (payroll_group_id) REFERENCES payroll_groups(id) ON DELETE RESTRICT,
    UNIQUE KEY uq_company_component (company_id, code, payroll_group_id)
);
```

---

### C. Tabel Penugasan & Histori Karyawan
```sql
CREATE TABLE employee_assignments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    payroll_group_id BIGINT UNSIGNED NOT NULL,
    attendance_setting_id BIGINT UNSIGNED NULL,
    cost_center_code VARCHAR(50) NULL,
    job_position VARCHAR(100) NULL,
    employment_type ENUM('pkwtt', 'pkwt', 'probation', 'internship', 'freelance') NOT NULL,
    effective_from DATE NOT NULL,
    effective_to DATE NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE RESTRICT,
    FOREIGN KEY (payroll_group_id) REFERENCES payroll_groups(id) ON DELETE RESTRICT,
    INDEX idx_user_assignment (user_id, effective_from, effective_to)
);

CREATE TABLE employee_salaries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    basic_salary DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    salary_type ENUM('monthly', 'daily', 'hourly') DEFAULT 'monthly',
    effective_date DATE NOT NULL,
    end_date DATE NULL,
    notes VARCHAR(255) NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE RESTRICT,
    INDEX idx_user_salary (user_id, effective_date)
);

CREATE TABLE employee_salary_components (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    salary_component_id BIGINT UNSIGNED NOT NULL,
    amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    effective_date DATE NOT NULL,
    end_date DATE NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (salary_component_id) REFERENCES salary_components(id) ON DELETE RESTRICT,
    INDEX idx_user_comp_date (user_id, salary_component_id, effective_date)
);
```

---

### D. Tabel Transaksi: `payrolls`, `payslips`, `payslip_items`, & `payslip_calculation_steps`
```sql
CREATE TABLE payrolls (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    payroll_group_id BIGINT UNSIGNED NOT NULL,
    batch_number VARCHAR(60) NOT NULL UNIQUE COMMENT 'PR-YYYYMM-GRP-XXXX',
    payroll_type ENUM('regular', 'thr', 'bonus', 'severance', 'off_cycle') DEFAULT 'regular',
    period_month TINYINT NOT NULL,
    period_year SMALLINT NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    payment_date DATE NOT NULL,
    idempotency_key VARCHAR(100) NOT NULL UNIQUE,
    calculation_hash VARCHAR(64) NULL COMMENT 'SHA-256 seluruh total batch',
    total_employees INT NOT NULL DEFAULT 0,
    total_gross DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    total_tax DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    total_bpjs_company DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    total_bpjs_employee DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    total_reimbursement DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    total_adjustments DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    total_deductions DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    total_take_home_pay DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    status ENUM('draft', 'calculated', 'submitted', 'approved', 'payment_prepared', 'paid', 'cancelled') DEFAULT 'draft',
    generated_by BIGINT UNSIGNED NULL,
    submitted_by BIGINT UNSIGNED NULL,
    approved_by BIGINT UNSIGNED NULL,
    approved_at TIMESTAMP NULL,
    paid_at TIMESTAMP NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE RESTRICT,
    FOREIGN KEY (payroll_group_id) REFERENCES payroll_groups(id) ON DELETE RESTRICT,
    FOREIGN KEY (generated_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_company_batch (company_id, period_year, period_month, payroll_type)
);

CREATE TABLE payslips (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payroll_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    payslip_number VARCHAR(60) NOT NULL UNIQUE,
    
    -- Snapshot Identitas Finansial (Terenkripsi & Masked)
    employee_name VARCHAR(150) NOT NULL,
    employee_code VARCHAR(50) NULL,
    department VARCHAR(100) NULL,
    designation VARCHAR(100) NULL,
    bank_name VARCHAR(50) NULL,
    bank_account_no_encrypted TEXT NOT NULL,
    bank_account_no_masked VARCHAR(30) NOT NULL,
    bank_account_holder VARCHAR(150) NULL,
    npwp_encrypted TEXT NULL,
    npwp_masked VARCHAR(30) NULL,
    ptkp_status VARCHAR(10) NOT NULL,
    tax_method ENUM('gross', 'gross_up', 'nett') NOT NULL,
    
    -- Snapshot Kehadiran & Lembur
    scheduled_working_days TINYINT DEFAULT 0,
    present_days TINYINT DEFAULT 0,
    late_days TINYINT DEFAULT 0,
    early_leave_days TINYINT DEFAULT 0,
    absent_days TINYINT DEFAULT 0,
    leave_days TINYINT DEFAULT 0,
    holiday_days TINYINT DEFAULT 0,
    actual_overtime_minutes INT DEFAULT 0,
    approved_overtime_minutes INT DEFAULT 0,
    
    -- Nilai Finansial Snapshot
    basic_salary DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    total_fixed_allowances DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    total_variable_allowances DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    total_overtime_pay DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    total_reimbursement DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    total_adjustments DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    gross_salary DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    
    -- Pajak & BPJS Snapshot
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
    file_hash VARCHAR(64) NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    FOREIGN KEY (payroll_id) REFERENCES payrolls(id) ON DELETE RESTRICT,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE RESTRICT,
    INDEX idx_user_payslip (user_id, payroll_id)
);

CREATE TABLE payslip_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payslip_id BIGINT UNSIGNED NOT NULL,
    salary_component_id BIGINT UNSIGNED NULL,
    code VARCHAR(50) NOT NULL,
    name VARCHAR(100) NOT NULL,
    category ENUM('earning', 'deduction', 'company_expense', 'benefit') NOT NULL,
    amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    description VARCHAR(255) NULL,
    order_index TINYINT DEFAULT 0,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    FOREIGN KEY (payslip_id) REFERENCES payslips(id) ON DELETE RESTRICT,
    FOREIGN KEY (salary_component_id) REFERENCES salary_components(id) ON DELETE SET NULL,
    INDEX idx_payslip_items (payslip_id, category)
);

-- Jejak Audit Langkah Perhitungan Matematis (Calculation Trace)
CREATE TABLE payslip_calculation_steps (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payslip_id BIGINT UNSIGNED NOT NULL,
    step_code VARCHAR(50) NOT NULL COMMENT 'PRORATE, OVERTIME, BPJS_KES, PPH21_TER, PPH21_PASAL17, NETT',
    step_sequence TINYINT NOT NULL,
    formula_version VARCHAR(50) NOT NULL,
    input_payload JSON NOT NULL,
    raw_result DECIMAL(15,2) NOT NULL,
    rounding_diff DECIMAL(8,2) DEFAULT 0.00,
    final_result DECIMAL(15,2) NOT NULL,
    rule_reference VARCHAR(100) NULL,
    created_at TIMESTAMP NULL,
    FOREIGN KEY (payslip_id) REFERENCES payslips(id) ON DELETE RESTRICT,
    INDEX idx_step_payslip (payslip_id, step_sequence)
);
```

---

### E. Tabel Konsolidasi Pajak: `employee_tax_period_totals`
```sql
CREATE TABLE employee_tax_period_totals (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    tax_year SMALLINT NOT NULL,
    tax_month TINYINT NOT NULL,
    regular_gross DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    irregular_gross DECIMAL(15,2) NOT NULL DEFAULT 0.00 COMMENT 'THR / Bonus',
    taxable_benefit_gross DECIMAL(15,2) NOT NULL DEFAULT 0.00 COMMENT 'Natura PMK 66',
    total_taxable_gross DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    total_pph21_withheld DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE RESTRICT,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
    UNIQUE KEY uq_user_tax_period (user_id, tax_year, tax_month)
);
```

---

### F. Tabel Penyesuaian: `payroll_adjustments`
```sql
CREATE TABLE payroll_adjustments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    payroll_id BIGINT UNSIGNED NULL COMMENT 'NULL = antrian, terisi = sudah masuk batch',
    retroactive_payroll_id BIGINT UNSIGNED NULL COMMENT 'Referensi batch masa lalu jika berupa koreksi',
    type ENUM('earning', 'deduction') NOT NULL,
    name VARCHAR(150) NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    is_taxable BOOLEAN DEFAULT TRUE,
    reason TEXT NULL,
    source_document_path VARCHAR(255) NULL,
    status ENUM('pending', 'approved', 'applied', 'voided') DEFAULT 'pending',
    created_by BIGINT UNSIGNED NOT NULL,
    approved_by BIGINT UNSIGNED NULL,
    approved_at TIMESTAMP NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE RESTRICT,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (payroll_id) REFERENCES payrolls(id) ON DELETE SET NULL,
    FOREIGN KEY (retroactive_payroll_id) REFERENCES payrolls(id) ON DELETE SET NULL
);
```

---

### G. Tabel Pencairan: `payroll_payment_batches` & `payroll_payment_items`
```sql
CREATE TABLE payroll_payment_batches (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payroll_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    batch_reference VARCHAR(60) NOT NULL UNIQUE,
    bank_format ENUM('bca_klikbisnis', 'mandiri_mcm', 'bri_cms', 'bni_direct', 'generic_csv') NOT NULL,
    total_records INT NOT NULL DEFAULT 0,
    total_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    file_path VARCHAR(255) NULL,
    file_checksum VARCHAR(64) NULL COMMENT 'SHA-256 file export',
    status ENUM('prepared', 'file_generated', 'uploaded', 'partially_settled', 'settled', 'reconciled') DEFAULT 'prepared',
    generated_by BIGINT UNSIGNED NOT NULL,
    reconciled_by BIGINT UNSIGNED NULL,
    reconciled_at TIMESTAMP NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    FOREIGN KEY (payroll_id) REFERENCES payrolls(id) ON DELETE RESTRICT,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE RESTRICT
);

CREATE TABLE payroll_payment_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payment_batch_id BIGINT UNSIGNED NOT NULL,
    payslip_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    bank_name VARCHAR(50) NOT NULL,
    bank_account_no_masked VARCHAR(30) NOT NULL,
    bank_account_holder VARCHAR(150) NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    status ENUM('pending', 'success', 'failed', 'rejected_by_bank') DEFAULT 'pending',
    bank_reference_no VARCHAR(100) NULL,
    failure_reason VARCHAR(255) NULL,
    settled_at TIMESTAMP NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    FOREIGN KEY (payment_batch_id) REFERENCES payroll_payment_batches(id) ON DELETE RESTRICT,
    FOREIGN KEY (payslip_id) REFERENCES payslips(id) ON DELETE RESTRICT,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
);
```

---

### H. Tabel Audit Trail: `payroll_logs`
```sql
CREATE TABLE payroll_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payroll_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL COMMENT 'Pelaku aksi',
    action VARCHAR(60) NOT NULL COMMENT 'generate, recalculate, submit, approve, export_bank, disburse, reconcile, void',
    notes TEXT NULL,
    before_state JSON NULL,
    after_state JSON NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    record_hash VARCHAR(64) NOT NULL COMMENT 'SHA-256 integritas log',
    created_at TIMESTAMP NOT NULL,
    FOREIGN KEY (payroll_id) REFERENCES payrolls(id) ON DELETE RESTRICT,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_payroll_action (payroll_id, action)
);
```

---

## 11. Alur Logika Bisnis & Mesin Kalkulasi (Calculation Engine)

Alur eksekusi saat HRD memanggil `POST /api/v1/dashboard/payroll/generate`:

```
1. Validasi Pra-Kalkulasi (Pre-Flight Checks):
   - Cek apakah ada batch aktif dengan periode & payroll_group yang sama (cegah double run).
   - Validasi kelengkapan data penugasan (employee_assignments) & profil pajak aktif.
   - Kunci idempotency_key menggunakan database lock.
   │
2. Tarik Daftar Karyawan yang Berhak Diproses:
   - Karyawan aktif dalam payroll_group yang dituju pada rentang start_date s.d. end_date.
   - Karyawan yang resign pada periode ini (resigned_date berada dalam rentang cutoff).
   │
3. Loop Evaluasi Setiap Karyawan:
   │
   ├── A. Resolusi Profil Finansial Efektif:
   │      - Ambil basic_salary, profil PTKP, tarif BPJS, & rekening aktif pada rentang tanggal.
   │      - Hitung Prorate jika ada joined_date atau resigned_date di tengah periode.
   │
   ├── B. Agregasi Presensi & Lembur:
   │      - Ambil present_days, late_days, absent_days, unpaid_leave_days dari tabel attendances.
   │      - Ambil approved_overtime_minutes dari overtime_approvals (status = 'approved').
   │
   ├── C. Evaluasi Seluruh Komponen Penghasilan:
   │      - Hitung Fixed Allowances (Tunjangan Jabatan, Keluarga, dll).
   │      - Hitung Variable Allowances (Uang Makan/Transport = present_days * tarif).
   │      - Hitung Overtime Pay menggunakan formula PP 35/2021.
   │      - Evaluasi Formula DSL untuk komponen khusus.
   │      - Tarik Approved Reimbursements dari tabel receipts.
   │      - Tarik Approved Adjustments (payroll_adjustments) tipe earning.
   │
   ├── D. Evaluasi Potongan & Pinjaman:
   │      - Hitung potongan telat / pulang cepat / alpha.
   │      - Tarik cicilan pinjaman aktif dari employee_loans.
   │      - Tarik Approved Adjustments tipe deduction.
   │
   ├── E. Hitung Iuran BPJS Ketenagakerjaan & Kesehatan:
   │      - Terapkan batas upah minimum UMR & maksimum cap dari statutory_rule_versions.
   │      - Hitung iuran BPJS Kes (4% persh, 1% kar), JHT (3.7% persh, 2% kar), JP (2% persh, 1% kar), JKK, JKM.
   │
   ├── F. Hitung Pajak PPh 21 (Konsolidasi Masa Pajak):
   │      - Konsolidasi seluruh bruto kena pajak bulan berjalan pada employee_tax_period_totals.
   │      - JIKA Periode = Desember ATAU Karyawan Resign:
   │        Jalankan PPh 21 Pasal 17 Tahunan (Neto Setahun - PTKP * Tarif Progresif) - PPh21 yang telah dipotong.
   │      - SELAIN ITU:
   │        Hitung PPh 21 TER Bulanan berdasarkan kategori PTKP & tax_ter_rates.
   │      - Terapkan penyesuaian Gross-Up (tunjangan pajak) jika metode = 'gross_up'.
   │
   ├── G. Kalkulasi Take Home Pay & Proteksi Negatif:
   │      - Net Pay = (Gross + Reimbursement) - (Total Potongan + BPJS Karyawan + PPh 21).
   │      - Jika Net Pay < 0: Set Net Pay = 0, catat selisih ke Carry-Forward Balance.
   │
   └── H. Tulis Snapshot & Calculation Trace:
          - Simpan ke payslips (dengan PII terenkripsi) & payslip_items.
          - Tulis setiap tahapan matematis ke payslip_calculation_steps.
   │
4. Kalkulasi Total Header Batch di payrolls (status: 'calculated').
5. Buat SHA-256 checksum batch dan catat log ke payroll_logs (action: 'generate').
```

---

## 12. Siklus Pencairan Dana (Disbursement) & Rekonsiliasi Bank

Sistem tidak langsung menandai payroll lunas saat tombol bayar ditekan, melainkan menerapkan alur pencairan perbankan terstruktur:

```
┌──────────┐     ┌────────────┐     ┌───────────┐     ┌───────────┐     ┌────────────┐
│ DRAFT /  │     │ SUBMITTED  │     │ APPROVED  │     │ PAYMENT   │     │ SETTLED &  │
│CALCULATED├────►│  (By HRD)  ├────►│ (By Fin/  ├────►│ PREPARED  ├────►│ RECONCILED │
└──────────┘     └────────────┘     │  Director)│     │(Bank File)│     └────────────┘
                                    └───────────┘     └───────────┘
```

### Tahapan Pencairan:
1. **Approval Batch (`approved`)**: Finance Manager & Direksi menyetujui total anggaran payroll.
2. **Generate File Transfer Bank (`payment_prepared` / `payroll_payment_batches`)**:
   - Sistem membuat file batch transfer terenkripsi sesuai format bank (BCA KlikBisnis, Mandiri MCM, BRI CMS, BNI Direct, Generic CSV).
   - Menghasilkan SHA-256 checksum file untuk mencegah manipulasi file sebelum diunggah ke internet banking.
   - Membuat record `payroll_payment_items` untuk setiap karyawan dengan status `pending`.
3. **Pencairan & Rekonsiliasi Bank (`settled` & `reconciled`)**:
   - Setelah proses transfer bank selesai, Finance mengunggah file laporan hasil transfer (*bank statement/report*).
   - Sistem mencocokkan status per karyawan (sukses / gagal karena rekening tutup / salah nama).
   - Karyawan yang berhasil ditransfer berubah menjadi `success`, payslip berstatus `paid`, dan notifikasi FCM dikirim ke aplikasi mobile karyawan.
   - Rekening yang gagal ditandai `failed` agar Finance dapat melakukan transfer ulang manual tanpa mengulang seluruh batch.

---

## 13. Integrasi Akuntansi (General Ledger) & Alokasi Biaya

Setiap kali payroll berstatus `approved` atau `paid`, sistem dapat menghasilkan draf jurnal akuntansi otomatis (*double-entry bookkeeping*):

```text
[DEBIT]   Beban Gaji Pokok & Tunjangan Tetap (Cost Center Cabang / Divisi)
[DEBIT]   Beban Upah Lembur (Cost Center Divisi)
[DEBIT]   Beban BPJS Ketenagakerjaan Perusahaan (JKK, JKM, JHT, JP)
[DEBIT]   Beban BPJS Kesehatan Perusahaan
[DEBIT]   Beban Reimbursement Operasional
[CREDIT]  Utang Gaji Karyawan (Payroll Payable / Net Take Home Pay)
[CREDIT]  Utang PPh Pasal 21 (Tax Payable)
[CREDIT]  Utang BPJS Ketenagakerjaan (Iuran Perusahaan + Potongan Karyawan)
[CREDIT]  Utang BPJS Kesehatan (Iuran Perusahaan + Potongan Karyawan)
[CREDIT]  Piutang Kasbon Karyawan (Pengurang Saldo Pinjaman)
```

Saat pencairan dana via bank selesai:
```text
[DEBIT]   Utang Gaji Karyawan (Payroll Payable)
[CREDIT]  Kas / Rekening Bank Operasional
```

Sistem menyediakan antarmuka ekspor data jurnal ke software akuntansi populer (Jurnal.id, Accurate Online, SAP, Xero, NetSuite, CSV Generic).

---

## 14. Kontrol Internal, Separation of Duties, & Hak Akses API

### Matriks Hak Akses Granular (Pemisahan Tugas):
| Aksi / Modul | HRD Officer | Finance Staff | Finance Manager | Direksi / Super Admin | Karyawan |
|---|:---:|:---:|:---:|:---:|:---:|
| Edit Master Gaji & Komponen | ✅ Input Draft | ❌ | 👁️ Review | ✅ Approve | ❌ |
| Pengajuan Rekening Bank Baru | ✅ Input | ❌ | ✅ Verifikasi | ✅ Manage | ❌ |
| Input Adjustment / Koreksi | ✅ Input | ❌ | ✅ Approve | ✅ Manage | ❌ |
| Generate & Hitung Ulang Draft | ✅ Execute | ❌ | ❌ | ✅ Execute | ❌ |
| Submit Draft ke Finance | ✅ Submit | ❌ | ❌ | ✅ Submit | ❌ |
| Approve / Reject Batch Payroll | ❌ | ❌ | ✅ Approve L1 | ✅ Final Approve | ❌ |
| Export File Transfer Bank | ❌ | ✅ Execute | ✅ Execute | ✅ Execute | ❌ |
| Rekonsiliasi Pembayaran Bank | ❌ | ✅ Execute | ✅ Approve | ✅ Manage | ❌ |
| Lihat PII Penuh (Rekening/NPWP) | ❌ (Masked) | ❌ (Masked) | 👁️ Full (Audit) | 👁️ Full (Audit) | 👁️ Milik Sendiri |
| Unduh Slip Gaji PDF | ❌ | ❌ | 👁️ All | 👁️ All | 👁️ Milik Sendiri |

### Aturan Keamanan Wajib (*Hard Security Rules*):
1. **Larangan Self-Approval**: Pengguna tidak dapat menyetujui perubahan gaji, adjustment, atau batch payroll milik dirinya sendiri.
2. **Step-Up Authentication (MFA / PIN)**: Tindakan final approval dan ekspor file transfer bank wajib meminta konfirmasi PIN keamanan / OTP.
3. **Locking Snapshot**: Payroll berstatus `approved` membekukan seluruh master data. Perubahan data karyawan setelah tanggal approval tidak akan mengubah nilai snapshot.

---

### Rancangan RESTful API Endpoints:

#### A. Master Data & Penugasan Finansial
- `GET    /api/v1/dashboard/payroll/groups` $\rightarrow$ Daftar payroll groups
- `POST   /api/v1/dashboard/payroll/groups` $\rightarrow$ Tambah payroll group
- `GET    /api/v1/dashboard/payroll/components` $\rightarrow$ Master komponen gaji
- `POST   /api/v1/dashboard/payroll/components` $\rightarrow$ Tambah komponen gaji
- `PUT    /api/v1/dashboard/payroll/components/{id}` $\rightarrow$ Update komponen gaji
- `GET    /api/v1/dashboard/payroll/employees/{id}/profile` $\rightarrow$ Detail profil finansial, pajak, & BPJS
- `POST   /api/v1/dashboard/payroll/employees/{id}/salary` $\rightarrow$ Set gaji pokok baru (effective-dated)
- `POST   /api/v1/dashboard/payroll/employees/{id}/bank-account` $\rightarrow$ Pengajuan rekening bank baru
- `POST   /api/v1/dashboard/payroll/bank-accounts/{id}/verify` $\rightarrow$ Verifikasi rekening bank (Maker-Checker)

#### B. Adjustments & Koreksi Retroaktif
- `GET    /api/v1/dashboard/payroll/adjustments` $\rightarrow$ Daftar penyesuaian/koreksi
- `POST   /api/v1/dashboard/payroll/adjustments` $\rightarrow$ Input penyesuaian / bonus / denda
- `POST   /api/v1/dashboard/payroll/adjustments/{id}/approve` $\rightarrow$ Persetujuan adjustment oleh Finance
- `POST   /api/v1/dashboard/payroll/adjustments/{id}/void` $\rightarrow$ Batalkan adjustment (alasan tercatat)

#### C. Pemrosesan Batch Payroll
- `GET    /api/v1/dashboard/payroll` $\rightarrow$ Daftar riwayat batch penggajian
- `POST   /api/v1/dashboard/payroll/generate` $\rightarrow$ Generate draf batch payroll
- `GET    /api/v1/dashboard/payroll/{id}` $\rightarrow$ Detail ringkasan batch & daftar payslip
- `POST   /api/v1/dashboard/payroll/{id}/recalculate` $\rightarrow$ Hitung ulang batch draf
- `POST   /api/v1/dashboard/payroll/{id}/submit` $\rightarrow$ HRD mengajukan draf ke Finance
- `POST   /api/v1/dashboard/payroll/{id}/approve` $\rightarrow$ Finance/Direksi menyetujui batch
- `DELETE /api/v1/dashboard/payroll/{id}` $\rightarrow$ Batalkan draf batch (hanya saat status draft)

#### D. Pencairan Bank & Rekonsiliasi
- `POST   /api/v1/dashboard/payroll/{id}/payment-batches` $\rightarrow$ Generate file transfer bank (BCA/Mandiri/BRI/CSV)
- `GET    /api/v1/dashboard/payroll/payment-batches/{id}/download` $\rightarrow$ Unduh file batch transfer bank
- `POST   /api/v1/dashboard/payroll/payment-batches/{id}/reconcile` $\rightarrow$ Unggah hasil transfer & rekonsiliasi

#### E. Laporan, Dokumen Pajak, & Slip Gaji
- `GET    /api/v1/dashboard/payroll/payslips/{id}/pdf` $\rightarrow$ Unduh PDF slip gaji resmi (Auth check)
- `GET    /api/v1/dashboard/payroll/payslips/{id}/trace` $\rightarrow$ Rincian calculation trace audit
- `GET    /api/v1/dashboard/payroll/tax/summary/{year}` $\rightarrow$ Rekap pajak tahunan
- `GET    /api/v1/dashboard/payroll/tax/1721-a1/{userId}/{year}` $\rightarrow$ Unduh Formulir 1721-A1 Bukti Potong
- `GET    /api/v1/dashboard/payroll/gl-export/{id}` $\rightarrow$ Ekspor draf jurnal akuntansi

#### F. Mobile Karyawan (Flutter)
- `GET    /api/v1/payroll/my-payslips` $\rightarrow$ Riwayat slip gaji milik sendiri
- `GET    /api/v1/payroll/my-payslips/{id}` $\rightarrow$ Rincian interaktif slip gaji
- `GET    /api/v1/payroll/my-payslips/{id}/download` $\rightarrow$ Unduh PDF slip gaji bertandatangan digital

---

## 15. Strategi Keamanan Finansial, Pelindungan Data Pribadi (PDP), & Audit Trail

Untuk memenuhi **UU No. 27 Tahun 2022 (Pelindungan Data Pribadi)** dan standar audit finansial:

1. **Enkripsi Data Sensitif**:
   - Kolom `bank_account_no`, `npwp`, `bpjs_kesehatan_no`, `bpjs_ketenagakerjaan_no` dienkripsi AES-256 pada level database.
   - Snapshot data finansial di `payslips` turut dienkripsi; tampilan antarmuka selalu menggunakan versi termasking.
2. **Private File Storage**:
   - File PDF slip gaji dan file ekspor bank disimpan di private storage (`storage/app/private/payroll/`), tidak dapat diakses publik.
   - Pengunduhan PDF menggunakan endpoint terproteksi dengan verifikasi kepemilikan user dan token sesi singkat (*short-lived signed URL*).
3. **Integritas Log (*Tamper-Resistant Audit Trail*)**:
   - Setiap mutasi finansial dicatat di `payroll_logs` dengan menyimpan `before_state`, `after_state`, IP address, user agent, dan `record_hash` SHA-256.
4. **Isolasi Multi-Tenant**:
   - Seluruh query database dibatasi scope `company_id` dan validasi policy otorisasi untuk mencegah akses data antar-perusahaan (*cross-tenant / IDOR protection*).

---

## 16. Fase Implementasi & Prioritas Roadmap (P0, P1, P2)

```
┌─────────────────────────────────────────────────────────────────────────────┐
│ FASE 1 (P0 — Fondasi Master Data, Database Migration, & Enkripsi PII)       │
│ • Migrasi tabel: payroll_groups, statutory_rule_versions, employee_         │
│   assignments, employee_tax_profiles, employee_bpjs_profiles, employee_    │
│   bank_accounts, employee_salaries, salary_components                      │
│ • Implementasi Laravel Eloquent Encrypted Casting & Masking Helper          │
│ • Seeder: Tarif TER PMK 168/2023, Tarif Pasal 17 UU HPP, & Cap BPJS 2024-26 │
│ • CRUD Master Komponen Gaji, Payroll Groups, & Pengaturan Cabang            │
└──────────────────────────────────────┬──────────────────────────────────────┘
                                       │
┌──────────────────────────────────────▼──────────────────────────────────────┐
│ FASE 2 (P0 — Core Calculation Engine & Unit Testing)                        │
│ • `PayrollCalculationService`: Engine utama batch & prorate                │
│ • `Pph21CalculatorService`: Konsolidasi masa pajak bulanan, TER & Pasal 17  │
│ • `BpjsCalculatorService`: BPJS Kes & TK (JKK, JKM, JHT, JP, JKP) + Cap     │
│ • `OvertimeCalculatorService`: Lembur PP 35/2021 & Preservasi Presensi      │
│ • Migrasi & Pencatatan Snapshot: `payrolls`, `payslips`, `payslip_items`,   │
│   `payslip_calculation_steps`, `employee_tax_period_totals`                 │
│ • Unit Testing Matematis vs Kalkulator Resmi DJP                            │
└──────────────────────────────────────┬──────────────────────────────────────┘
                                       │
┌──────────────────────────────────────▼──────────────────────────────────────┐
│ FASE 3 (P1 — Workflow Approval, Kontrol Anti-Fraud, & Adjustments)          │
│ • Modul Adjustments & Koreksi Retroaktif dengan Maker-Checker               │
│ • Alur Approval Bertingkat: Draft → Calculated → Submitted → Approved       │
│ • Proteksi Perubahan Rekening Bank (Maker-Checker & Notifikasi)             │
│ • Audit Trail Lengkap di `payroll_logs`                                     │
└──────────────────────────────────────┬──────────────────────────────────────┘
                                       │
┌──────────────────────────────────────▼──────────────────────────────────────┐
│ FASE 4 (P1 — Pencairan Dana Bank, PDF Slip Gaji, & Laporan Coretax/1721-A1) │
│ • Generator Ekspor File Bank (BCA, Mandiri, BRI, Generic CSV) + Checksum    │
│ • Modul Rekonsiliasi Pembayaran Bank (`payroll_payment_items`)              │
│ • Generator PDF Slip Gaji Karyawan (Desain Resmi + Watermark Status)        │
│ • Generator Bukti Potong PPh 21 Tahunan (1721-A1) & Ekspor Coretax          │
│ • Ekspor Jurnal Akuntansi (GL) & Cost Center Allocation                     │
└──────────────────────────────────────┬──────────────────────────────────────┘
                                       │
┌──────────────────────────────────────▼──────────────────────────────────────┐
│ FASE 5 (P1 — Integrasi Mobile Flutter & Notifikasi)                         │
│ • Menu "Slip Gaji" di Tab Profil Mobile Karyawan                            │
│ • Tampilan Interaktif Rincian Penghasilan, Potongan, & Lembur               │
│ • Fitur Download & Share PDF Slip Gaji Terproteksi                          │
│ • Push Notification (FCM) otomatis saat Gaji Berhasil Dibayarkan (Paid)     │
└──────────────────────────────────────┬──────────────────────────────────────┘
                                       │
┌──────────────────────────────────────▼──────────────────────────────────────┐
│ FASE 6 (P2 — Fleksibilitas Lanjutan & Enterprise Modules)                   │
│ • Mesin Formula DSL Custom untuk Tunjangan Khusus                           │
│ • Modul Exit Settlement Pesangon/PHK & Kompensasi PKWT                      │
│ • Struktur & Skala Upah (Salary Grades & Job Levels)                        │
│ • Penggajian Multi-Mata Uang (Multi-Currency) & PPh 26 Ekspatriat           │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## 17. Strategi Pengujian, Quality Gates, & Parallel Run

Untuk menjamin keandalan 100% sebelum sistem digunakan untuk pembayaran uang nyata:

### A. Pengujian Otomatis (Automated Testing Suite):
1. **Unit Test Kalkulator PPh 21**:
   - Uji TER Kategori A, B, C pada batas minimum, tengah, dan maksimum nominal.
   - Uji konsolidasi masa pajak: Gaji Reguler + THR di batch terpisah dalam bulan yang sama.
   - Uji PPh 21 Masa Terakhir (Desember) membandingkan total TER Jan–Nov dengan Pasal 17 setahun.
   - Uji PPh 21 Masa Terakhir untuk Karyawan Resign di tengah tahun (berbagai alasan terminasi).
   - Uji Metode Gross-Up (konvergensi nilai tunjangan pajak).
2. **Unit Test BPJS & Lembur**:
   - Uji batas upah di bawah cap vs di atas cap (misal gaji Rp 30.000.000).
   - Uji lembur hari kerja normal (1.5x & 2.0x) dan hari libur skema 5 & 6 hari kerja.
3. **Integritas Finansial (*Invariant Test*)**:
   $$\text{Total Gross} - \text{Total Potongan} + \text{Reimbursement} = \text{Total Take Home Pay}$$
   $$\sum \text{Payslip Net Pay} = \text{Total Batch Header} = \sum \text{Payment Batch Items}$$
4. **Security & Authorization Test**:
   - Uji pencegahan IDOR (karyawan tidak bisa melihat payslip karyawan lain).
   - Uji isolasi multi-tenant (data perusahaan A tidak bocor ke perusahaan B).
   - Uji pencegahan self-approval pada maker-checker.

### B. Prosedur Parallel Run Pra-Produksi:
1. Jalankan sistem baru berdampingan (*parallel run*) dengan sistem payroll lama minimal **2–3 siklus penggajian**.
2. Lakukan rekonsiliasi otomatis antara hasil sistem baru vs sistem lama untuk mendeteksi selisih rupiah.
3. Seluruh selisih wajib diverifikasi bersama tim HR, Finance, dan Pajak.
4. Fitur pencairan uang nyata (*bank disbursement*) hanya diaktifkan setelah penandatanganan berita acara persetujuan (*sign-off*) dari manajemen.

---

## 18. Referensi Regulasi Resmi

1. [PMK No. 168 Tahun 2023 — Petunjuk Pelaksanaan Pemotongan PPh Pasal 21/26](https://jdih.kemenkeu.go.id/dok/pmk-168-tahun-2023)
2. [PP No. 58 Tahun 2023 — Tarif Pemotongan PPh Pasal 21 (TER)](https://peraturan.bpk.go.id/Details/273677/pp-no-58-tahun-2023)
3. [PMK No. 66 Tahun 2023 — Perlakuan Pajak atas Natura dan/atau Kenikmatan](https://jdih.kemenkeu.go.id/dok/pmk-66-tahun-2023)
4. [UU No. 7 Tahun 2021 — Harmonisasi Peraturan Perpajakan (UU HPP)](https://peraturan.bpk.go.id/Details/185162/uu-no-7-tahun-2021)
5. [PP No. 35 Tahun 2021 — PKWT, Alih Daya, Waktu Kerja, Lembur, & PHK](https://peraturan.bpk.go.id/Details/161904/pp-no-35-tahun-2021)
6. [Permenaker No. 6 Tahun 2016 — Tunjangan Hari Raya (THR) Keagamaan](https://peraturan.bpk.go.id/Details/146100/permenaker-no-6-tahun-2016)
7. [PP No. 36 Tahun 2021 & PP No. 51 Tahun 2023 — Pengupahan & Batas Upah Minimum](https://peraturan.bpk.go.id/Details/161909/pp-no-36-tahun-2021)
8. [PP No. 37 Tahun 2021 & PP No. 6 Tahun 2025 — Penyelenggaraan Program JKP](https://peraturan.go.id/id/pp-no-6-tahun-2025)
9. [UU No. 27 Tahun 2022 — Pelindungan Data Pribadi (PDP)](https://peraturan.bpk.go.id/Details/229798/uu-no-27-tahun-2022)
10. [PMK No. 81 Tahun 2024 & PER-11/PJ/2025 — Ketentuan Sistem Inti Administrasi Perpajakan (Coretax DJP)](https://jdih.kemenkeu.go.id/dok/pmk-81-tahun-2024)
