# ExpenseFlow — Roadmap & Arsitektur Fitur Payroll (Penggajian Komprehensif)

> **Dokumen Spesifikasi Teknis & Bisnis Modul Payroll HRIS ExpenseFlow**  
> Terakhir diperbarui: 2026-09-01 (Revisi V3 — Master Spesifikasi Lengkap: Fleksibilitas Multi-Perusahaan, Kepatuhan Regulasi PPh 21/BPJS, Kontrol Anti-Fraud, & Keamanan Finansial)  
> Standar Regulasi: **PP No. 58 Tahun 2023 & PMK No. 168 Tahun 2023 (PPh 21 TER 2024)**, **UU No. 7 Tahun 2021 (UU HPP - Pasal 17)**, **PP No. 35 Tahun 2021 (Lembur, PKWT, & PHK)**, **Permenaker No. 6 Tahun 2016 (THR Keagamaan)**, **UU BPJS Kesehatan & Ketenagakerjaan**, **PP No. 6 Tahun 2025 (JKP)**, **PMK No. 66 Tahun 2023 (Natura & Kenikmatan)**, **PMK No. 81 Tahun 2024 / PER-11/PJ/2025 (Coretax)**, dan **UU No. 27 Tahun 2022 (Pelindungan Data Pribadi)**.

---

## 🚀 TITIK MASUK CEPAT (baca ini dulu — untuk developer & AI agent)

> **Agar tidak perlu scanning folder.** Urutan baca yang disarankan saat melanjutkan Payroll:
> 1. **[§16 Checklist Status Rinci](#16-fase-implementasi--prioritas-roadmap-p0-p1-p2)** — apa yang ✅ selesai / 🟡 sebagian / ⬜ belum, per fase. **Ini sumber kebenaran progres.**
> 2. **[§22 Fase 4 (Lanjutan) — Pajak 1721-A1, Ekspor GL & Calculation-Trace](#22-fase-4-lanjutan--pajak-1721-a1-ekspor-gl--calculation-trace--kontrak-api--tugas-frontend)** — kontrak API 1721-A1/Coretax + jurnal GL + calc-trace + pekerjaan web yang tersisa.
> 3. **[§21 Fase 4 (Disbursement) — Kontrak API Baru & Tugas Frontend](#21-fase-4-disbursement--kontrak-api-baru--tugas-frontend)** — kontrak API ekspor bank/rekonsiliasi + pekerjaan web/mobile yang tersisa.
> 4. **[§20 Fase 3 — Kontrak API Baru & Tugas Frontend](#20-fase-3--kontrak-api-baru--tugas-frontend)** — kontrak API adjustments/bank/PIN/logs + pekerjaan web/mobile yang tersisa.
> 5. **[§19 Tugas Frontend Fase 2](#19-tugas-frontend-yang-harus-dikerjakan-fase-2)** — kontrak API BPJS + pekerjaan web/mobile yang tersisa.
> 6. **[§14 Rancangan Endpoint](#14-kontrol-internal-separation-of-duties--hak-akses-api)** — daftar endpoint nyata (penanda ✅/⬜ per-endpoint).
>
> **Peta berkas kode** (path pasti service/controller/model/migrasi/web/mobile) ada di memory `payroll-file-map.md` — pakai itu untuk membuka file langsung, **jangan grep/scan**.
>
> **Status singkat:** Fase 1 (MVP) + Fase 2 (BPJS) + **Fase 3 (Workflow/Anti-Fraud)** + **Fase 4 lengkap (inti disbursement + Pajak 1721-A1/draf Coretax + ekspor Jurnal GL + calculation-trace)** — **backend SELESAI & teruji** (`PayrollTest` + `PayrollPhase3Test` + `PayrollPhase4Test` + `PayrollPhase4TaxGlTraceTest`). Frontend Fase 2/3/4 belum (lihat §19, §20, §21, §22). **Tidak ada lagi item backend Fase 4 yang ditunda.**
>
> **Aturan wajib:** Bahasa Indonesia (kode/UI/komentar); kolom uang `decimal:2` → JSON **string** (parse defensif); maker-checker (`approved_by ≠ prepared_by` → 403); `approved`/`paid` immutable (→422); FK finansial `restrictOnDelete()`; PII (NPWP/BPJS) `encrypted` + masking; PDF di disk privat; tanpa eval/raw SQL di engine.

---

## ✅ STATUS IMPLEMENTASI (Diperbarui: 2026-09-29)

> Dokumen ini adalah **spesifikasi visi enterprise lengkap**. Implementasi nyata dikerjakan bertahap dengan pendekatan **MVP fungsional dulu**. Bagian ini menandai mana yang **sudah selesai** dan mana yang **belum**, agar mudah dibedakan. Rincian per-item ada di setiap fase (lihat [Bagian 16](#16-fase-implementasi--prioritas-roadmap-p0-p1-p2)) dan pada penanda ✅/🟡/⬜ yang tersebar di dokumen.

**Legenda:** ✅ Selesai · 🟡 Sebagian (MVP) · ⬜ Belum dikerjakan

| Fase | Cakupan | Status | Keterangan Singkat |
|---|---|:---:|---|
| **Fase 1** | Master Data, Migrasi DB, Enkripsi PII | ✅ | Migrasi MVP + enkripsi NPWP + masking + seeder TER/PTKP/Pasal 17 + CRUD komponen gaji. *(`payroll_groups` + CRUD-nya kini SELESAI di [Fase 4+ / Bagian 23](#23-enam-fitur-lanjutan-pasca-mvp--kontrak-api--tugas-frontend); keanggotaan grup memakai kolom `users.payroll_group_id`, bukan tabel `employee_assignments` terpisah)* |
| **Fase 2** | Core Calculation Engine | ✅ | **Backend & Frontend Web SELESAI.** Backend: `PayrollCalculator`, PPh21 TER + Pasal 17, integrasi Presensi/Lembur/Struk/Kasbon, `BpjsCalculatorService`, jejak calculation steps. **Frontend Web (`PayrollManagement.tsx`):** sub-tab profil BPJS karyawan (toggles Kes/TK/JKP, kelas risiko JKK 1–5, masked number), opsi kalkulasi BPJS, ringkasan iuran perusahaan vs karyawan, badge statutori BPJS, kartu beban BPJS perusahaan terpisah, & akordeon calculation steps di slip gaji. *(Lihat [Bagian 19](#19-tugas-frontend-yang-harus-dikerjakan-fase-2))* |
| **Fase 3** | Workflow Approval & Anti-Fraud | ✅ | **Backend & Frontend Web SELESAI.** Backend: Alur approval bertingkat, maker-checker, adjustments retroaktif, proteksi rekening bank, hash-chain audit log SHA-256, step-up PIN. **Frontend Web (`PayrollManagement.tsx`):** sub-tab Penyesuaian (filter status, form buat, maker-checker approve, modal void ber-alasan), sub-tab Rekening Bank (maker-checker verifikasi/tolak, masked account), panel Jejak Audit & Integritas Hash-Chain SHA-256 (`ok`/`broken_at`), dialog otorisasi PIN approve/mark-paid & modal PIN Keamanan. *(Lihat [Bagian 20](#20-fase-3--kontrak-api-baru--tugas-frontend))* |
| **Fase 4** | Bank, PDF, Pajak, GL | ✅ | **Backend SELESAI seluruhnya.** PDF Slip Gaji (dompdf) + inti disbursement: ekspor berkas transfer bank (5 format via driver, disk privat + checksum SHA-256) & rekonsiliasi per-item (`payroll_payment_batches`/`payroll_payment_items`) — teruji (`PayrollPhase4Test`, 14 uji hijau). **+ Lanjutan (baru):** Bukti Potong PPh 21 **1721-A1** (PDF dompdf) + **draf CSV/JSON siap-Coretax** (bukan e-Bupot XML), **ekspor Jurnal Akuntansi/GL** (pemetaan akun per-perusahaan editable `payroll_gl_accounts` + `config/payroll_gl.php`, jurnal seimbang), dan **endpoint calculation-trace** — teruji (`PayrollPhase4TaxGlTraceTest`, 16 uji hijau). Frontend Fase 4 → lihat [Bagian 21](#21-fase-4-disbursement--kontrak-api-baru--tugas-frontend) & [Bagian 22](#22-fase-4-lanjutan--pajak-1721-a1-ekspor-gl--calculation-trace--kontrak-api--tugas-frontend). |
| **Fase 4+** | Enam Fitur Lanjutan Pasca-MVP | ✅ | **Backend & Frontend Web SELESAI seluruhnya.** Backend teruji (`PayrollThrRunTest`, `CompanyNpwpTest`, `PayslipBpjsBlockTest`, `EbupotXsdXmlTest` — menggantikan `EbupotDraftXmlTest` sejak Fase 6, `PayrollGlGroupedTest`, `PayrollGroupScopingTest`). Enam item: (1) **Run THR terpisah** (Permenaker 6/2016 + PPh21 TER marginal PMK 168/2023), (2) **Grup Payroll & scoping** (`payroll_groups` + CRUD + keanggotaan `users.payroll_group_id`), (3) **NPWP Perusahaan** (terenkripsi + masking), (4) **Rincian BPJS di PDF slip** (blok informasional), (5) **e-Bupot 21/26 XML** (semula `resmi="false"` & belum tervalidasi XSD DJP; **disempurnakan di Fase 6** menjadi wajib tervalidasi XSD), (6) **Alokasi Cost Center GL** (`?group_by=none\|division\|branch`). Frontend web SELESAI (tab Pengaturan Payroll: CRUD Grup & Profil Pajak NPWP, opsi Run THR & dropdown Grup di modal run, tombol Ekspor e-Bupot XML di 1721-A1, GL berdimensi). *(Lihat [Bagian 23](#23-enam-fitur-lanjutan-pasca-mvp--kontrak-api--tugas-frontend))* |
| **Fase 5** | Mobile Flutter & Notifikasi | ✅ | Menu Slip Gaji, rincian penghasilan/potongan, **Unduh PDF**, **Share PDF** (`share_plus`), dan **push notif FCM saat `paid`** (in-app + FCM, mengikuti pola `notifyDisbursement()`) — SELESAI. |
| **Fase 6** | Enterprise Lanjutan | 🟡 | **SELURUH BACKEND SELESAI & teruji; sisa = UI Web.** (1) **Mesin Formula DSL custom** (Stage 1 & 2 — `FormulaLexer/Parser/Evaluator/Engine`, tanpa `eval()`/raw SQL). (2) **Exit Settlement** pesangon PHK & kompensasi PKWT (PP 35/2021 UP/UPMK/UPH + **PPh 21 Final** PP 68/2009, run `run_type='severance'`) — `PayrollSeveranceRunTest` 10 uji. (3) **Struktur & Skala Upah** (`job_levels` + `salary_grades` min–mid–maks, gaji di luar rentang → 422) — `SalaryGradeStructureTest` 7 uji. (4) **Multi-Mata Uang & PPh 26** (master `currency_rates` + **rate lock** per batch, kurs absen → 422, ekspatriat 20%/tarif P3B) — `PayrollMultiCurrencyPph26Test` 14 uji. (5) **e-Bupot XML tervalidasi XSD** (naik dari status *draf*: `DOMDocument::schemaValidate()` wajib lolos, gagal → 422; XSD resmi DJP → `resmi="true"`, internal → `resmi="false"`; `GET /tax/ebupot/schema`) — `EbupotXsdXmlTest` 6 uji. Kontrak API & tugas frontend → [Bagian 24](#24-fase-6-enterprise-lanjutan--kontrak-api--tugas-frontend). |

### Ringkasan yang SUDAH bisa dipakai (MVP end-to-end)
- **Backend:** master komponen gaji, gaji pokok effective-dated, profil pajak (NPWP terenkripsi), batch payroll (buat → kalkulasi → submit → approve → tandai dibayar), PPh21 TER 2024, integrasi otomatis Presensi (potongan absen) + Lembur (PP 35/2021) + Struk (reimburse non-pajak) + Kasbon/cicilan, PDF slip di disk privat, uji fungsional (`tests/Feature/PayrollTest.php`), workflow approval Fase 3, dan disbursement & tax GL Fase 4.
- **Web (dashboard):** tab **Penggajian** (`PayrollManagement.tsx`) — komponen gaji, gaji karyawan (gaji pokok, tunjangan tetap, profil pajak, profil BPJS, & rekening bank ber-maker-checker), sub-tab Penyesuaian gaji & koreksi retroaktif (maker-checker), proses payroll bertingkat dengan dialog otorisasi PIN step-up, detail slip gaji lengkap dengan badge statutori BPJS, kartu beban perusahaan, dan Calculation Steps trace, panel Jejak Audit & Integritas Kriptografi Hash-Chain SHA-256, modal PIN Keamanan, serta kasbon/pinjaman karyawan.
- **Mobile (karyawan):** menu **Slip Gaji** di Profil → daftar slip → rincian → **Unduh PDF** (buka di viewer sistem) & **Bagikan PDF** (share ke WhatsApp/email/dll.); **notifikasi push (FCM)** otomatis diterima saat gaji ditandai `paid` oleh HRD.

### Catatan Endpoint (implementasi nyata vs rencana dokumen)
- Endpoint mobile **nyata** yang dipakai: `GET /api/v1/employee/payslips`, `GET /api/v1/employee/payslips/{id}`, `GET /api/v1/employee/payslips/{id}/pdf` — **bukan** `/api/v1/payroll/my-payslips` seperti tertulis di [Bagian 14.F](#f-mobile-karyawan-flutter) (rencana awal).
- Endpoint dashboard **nyata**: `/api/v1/dashboard/payroll/components*`, `/api/v1/dashboard/payroll/runs*` (`{id}/calculate|submit|approve|reject|mark-paid`), `/api/v1/dashboard/payroll/runs/{id}` (detail). Penamaan `runs` dipakai menggantikan `/generate` & `/recalculate` pada rencana [Bagian 14.C](#c-pemrosesan-batch-payroll).

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
19. [Tugas Frontend yang Harus Dikerjakan (Fase 2)](#19-tugas-frontend-yang-harus-dikerjakan-fase-2)
20. [Fase 3 — Kontrak API Baru & Tugas Frontend](#20-fase-3--kontrak-api-baru--tugas-frontend)
21. [Fase 4 (Disbursement) — Kontrak API Baru & Tugas Frontend](#21-fase-4-disbursement--kontrak-api-baru--tugas-frontend)
22. [Fase 4 (Lanjutan) — Pajak 1721-A1, Ekspor GL & Calculation-Trace — Kontrak API & Tugas Frontend](#22-fase-4-lanjutan--pajak-1721-a1-ekspor-gl--calculation-trace--kontrak-api--tugas-frontend)
23. [Enam Fitur Lanjutan (Pasca-MVP) — Kontrak API & Tugas Frontend](#23-enam-fitur-lanjutan-pasca-mvp--kontrak-api--tugas-frontend)

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

### A. Mesin Formula Komponen Gaji Aman (Safe Formula Engine) — ✅ Selesai (Backend Stage 1 & 2)
Untuk mendukung komponen dinamis (misal: `Transport = Hadir * 25.000`, `Bonus = 5% * Penjualan`), sistem menyediakan mesin formula berbasis **Domain-Specific Language (DSL)** yang aman:
- **Aturan Keamanan**: **Dilarang keras menggunakan `eval()` PHP atau eksekusi raw SQL**.
- **Whitelist Variabel**: `BASIC_SALARY`, `PRESENT_DAYS`, `LATE_MINUTES`, `OVERTIME_HOURS`, `UMR_AMOUNT`, `TENURE_MONTHS`, dll.
- **Validasi Ketergantungan**: Deteksi otomatis siklus ketergantungan (*circular dependency check*) sebelum formula diaktifkan.
- **Status Implementasi (Backend SELESAI & teruji):**
  - **Stage 1 Engine (`app/Services/Payroll/Formula/`):** `FormulaLexer` → `FormulaParser` (recursive descent, max depth 64) → `FormulaEvaluator` → `FormulaEngine` (fasad). Whitelist variabel & fungsi (`MIN`, `MAX`, `ABS`, `ROUND`, `FLOOR`, `CEIL`, `IF`, `AND`, `OR`, `NOT`, `CLAMP`), operator persen postfix (`x% = x/100`), perbandingan numerik, deteksi siklus (DFS coloring), dan topological sort (`dependencyOrder`).
  - **Stage 2 Persistensi & Wiring:** Migrasi `100028` (`salary_components.formula_dsl`, `calc_type='formula'`), endpoint `POST /api/v1/dashboard/payroll/components/validate-formula`, dan integrasi evaluasi otomatis di `PayrollCalculator::applyFormulaComponents()` (non-fatal error, step trace `STEP_FORMULA`). Teruji di `FormulaEngineTest` (46 unit test) dan `PayrollFormulaComponentTest` (11 feature test). *(UI Editor Web belum)*

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

#### A. Master Data & Penugasan Finansial — ✅ Selesai (Backend & DB)
- [x] `GET    /api/v1/dashboard/payroll/groups` $\rightarrow$ Daftar payroll groups *(Fase 4+ / Bagian 23 — SELESAI)*
- [x] `POST   /api/v1/dashboard/payroll/groups` (+ `PUT/DELETE /{group}`) $\rightarrow$ CRUD payroll group *(Fase 4+ / Bagian 23 — SELESAI)*
- [x] `GET/PUT /api/v1/dashboard/payroll/company-tax-profile` $\rightarrow$ Profil pajak perusahaan (NPWP terenkripsi) *(Fase 4+ / Bagian 23 — SELESAI)*
- [x] `POST   /api/v1/dashboard/payroll/components/validate-formula` $\rightarrow$ Validasi rumus formula DSL komponen gaji *(Fase 6 §6.A — SELESAI)*
- [x] `GET|POST /api/v1/dashboard/payroll/job-levels` (+ `PUT/DELETE /{jobLevel}`) $\rightarrow$ CRUD jenjang jabatan *(Fase 6 / Bagian 24 — SELESAI)*
- [x] `GET|POST /api/v1/dashboard/payroll/salary-grades` (+ `PUT/DELETE /{salaryGrade}`) $\rightarrow$ CRUD golongan upah (rentang min–mid–maks) *(Fase 6 / Bagian 24 — SELESAI)*
- [x] `GET|POST /api/v1/dashboard/payroll/currency-rates` (+ `PUT/DELETE /{currencyRate}`) $\rightarrow$ Master kurs valas bertanggal-efektif *(Fase 6 / Bagian 24 — SELESAI)*
- [x] `GET    /api/v1/dashboard/payroll/components` $\rightarrow$ Master komponen gaji
- [x] `POST   /api/v1/dashboard/payroll/components` $\rightarrow$ Tambah komponen gaji
- [x] `PUT    /api/v1/dashboard/payroll/components/{id}` $\rightarrow$ Update komponen gaji
- [x] `GET    /api/v1/dashboard/payroll/salaries/{userId}` $\rightarrow$ Detail gaji, komponen tetap & profil pajak (NPWP termasking) *(BPJS Fase 2 — SELESAI)*
- [x] `POST   /api/v1/dashboard/payroll/salaries/{userId}` $\rightarrow$ Set gaji pokok baru (effective-dated); `+/components`, `+/tax-profile`, `+/bpjs-profile`. *(Fase 6: menerima `salary_grade_id`/`job_level_id` — di luar rentang golongan → **422**; `currency` valas — kurs belum ada → **422**)*
- [x] `GET    /api/v1/dashboard/payroll/employees/{userId}/bank-accounts` $\rightarrow$ Daftar rekening bank karyawan (termasking) *(Fase 3, §20)*
- [x] `POST   /api/v1/dashboard/payroll/employees/{userId}/bank-account` $\rightarrow$ Pengajuan rekening bank baru → `pending_verification` *(Fase 3)*
- [x] `POST   /api/v1/dashboard/payroll/bank-accounts/{id}/verify` $\rightarrow$ Verifikasi rekening bank (Maker-Checker; sinkron ke `users.bank_*`) *(Fase 3)*
- [x] `POST   /api/v1/dashboard/payroll/bank-accounts/{id}/reject` $\rightarrow$ Tolak pengajuan rekening (alasan tercatat) *(Fase 3)*
- [x] `GET/POST /api/v1/dashboard/payroll/loans` (+ `{id}/approve`, `{id}/cancel`) $\rightarrow$ Kasbon/pinjaman karyawan *(bonus di luar rencana awal)*

#### B. Adjustments & Koreksi Retroaktif — ✅ Selesai *(Fase 3, §20)*
> **Catatan:** modul adjustment umum kini SELESAI (backend) — melengkapi Kasbon/Pinjaman (`employee_loans`) yang sudah ada. Model konsumsi: **klaim-saat-calculate** (adjustment `approved` di-set `payroll_id`+`applied` saat kalkulasi) + **release-saat-recalc/hapus batch**, beku setelah approve. Void-only (tanpa hapus fisik); void terkunci bila sudah masuk batch `approved`/`paid` (→422).
- [x] `GET    /api/v1/dashboard/payroll/adjustments` $\rightarrow$ Daftar penyesuaian/koreksi (filter `status`/`user_id`/`payroll_id`)
- [x] `POST   /api/v1/dashboard/payroll/adjustments` $\rightarrow$ Input penyesuaian / bonus / denda (`type`, `amount`, `is_taxable`, `retroactive_payroll_id?`)
- [x] `POST   /api/v1/dashboard/payroll/adjustments/{id}/approve` $\rightarrow$ Persetujuan adjustment oleh Finance *(larangan self-approval)*
- [x] `POST   /api/v1/dashboard/payroll/adjustments/{id}/void` $\rightarrow$ Batalkan adjustment (alasan wajib; terkunci bila batch final)

#### C. Pemrosesan Batch Payroll — ✅ Selesai
> **Catatan:** path nyata memakai sub-resource `runs` (mis. `/dashboard/payroll/runs`) dan aksi `calculate`/`mark-paid`.
- [x] `GET    /api/v1/dashboard/payroll/runs` $\rightarrow$ Daftar riwayat batch penggajian
- [x] `POST   /api/v1/dashboard/payroll/runs` $\rightarrow$ Buat draf batch payroll *(rencana: `/generate`)*. Body `run_type` = `regular` \| `thr` \| **`severance`** *(Fase 6 exit settlement — SELESAI)*
- [x] `GET    /api/v1/dashboard/payroll/runs/{id}` $\rightarrow$ Detail ringkasan batch & daftar payslip
- [x] `POST   /api/v1/dashboard/payroll/runs/{id}/calculate` $\rightarrow$ Hitung/hitung ulang batch draf *(rencana: `/recalculate`)*
- [x] `POST   /api/v1/dashboard/payroll/runs/{id}/submit` $\rightarrow$ HRD mengajukan draf ke Finance
- [x] `POST   /api/v1/dashboard/payroll/runs/{id}/approve` $\rightarrow$ Finance/Direksi menyetujui batch *(larangan self-approval)*
- [x] `POST   /api/v1/dashboard/payroll/runs/{id}/reject` $\rightarrow$ Tolak batch (alasan tercatat)
- [x] `POST   /api/v1/dashboard/payroll/runs/{id}/mark-paid` $\rightarrow$ Tandai batch sudah dibayar (kunci final)
- [x] `DELETE /api/v1/dashboard/payroll/runs/{id}` $\rightarrow$ Batalkan draf batch (hanya saat status draft)

#### D. Pencairan Bank & Rekonsiliasi — ✅ Selesai (backend) *(Fase 4, §21)*
> **Catatan:** path nyata memakai sub-resource `runs/{payroll}/payment-batches` untuk daftar/generate, dan `payment-batches/{id}` untuk detail/unduh/rekonsiliasi. Lapisan ini **decoupled** dari `mark-paid` (rekonsiliasi TIDAK memajukan status payslip/payroll & TIDAK meng-advance kasbon).
- [x] `GET    /api/v1/dashboard/payroll/runs/{id}/payment-batches` $\rightarrow$ Daftar batch pencairan + opsi format bank
- [x] `POST   /api/v1/dashboard/payroll/runs/{id}/payment-batches` $\rightarrow$ Generate file transfer bank (BCA/Mandiri/BRI/BNI/CSV) — status payroll wajib `approved`/`paid`; step-up PIN (soft)
- [x] `GET    /api/v1/dashboard/payroll/payment-batches/{id}` $\rightarrow$ Detail batch + item (termasking)
- [x] `GET    /api/v1/dashboard/payroll/payment-batches/{id}/download` $\rightarrow$ Unduh file batch transfer bank (nomor rekening PENUH; ter-audit)
- [x] `POST   /api/v1/dashboard/payroll/payment-batches/{id}/reconcile` $\rightarrow$ Unggah hasil transfer & rekonsiliasi per-item

#### E. Laporan, Dokumen Pajak, & Slip Gaji — ✅ Selesai (backend)
- [x] `GET    ` PDF slip gaji resmi (Auth check) *(dashboard + `/employee/payslips/{id}/pdf` untuk karyawan)*
- [x] `GET    /api/v1/dashboard/payroll/runs/{id}/logs` $\rightarrow$ Jejak audit tamper-evident batch + hasil `verifyChain` (integritas hash-chain SHA-256) *(Fase 3, §20)*
- [x] `GET    /api/v1/dashboard/payroll/payslips/{payslip}/calculation-trace` $\rightarrow$ Rincian calculation trace audit *(§22.J; `PayrollTraceController`)*
- [x] `GET    /api/v1/dashboard/payroll/tax/1721a1` $\rightarrow$ Rekap pajak tahunan 1721-A1 (daftar, termasking) *(§22.F)*
- [x] `GET    /api/v1/dashboard/payroll/tax/1721a1/{userId}[/pdf]` + `/tax/1721a1/export` $\rightarrow$ Detail/Unduh Formulir 1721-A1 & draf Coretax (CSV/JSON) *(§22.G–I; `PayrollTaxController`)*
- [x] `GET    /api/v1/dashboard/payroll/tax/ebupot/schema` $\rightarrow$ Status skema XSD e-Bupot aktif (resmi DJP vs internal) *(Fase 6 / Bagian 24 — SELESAI)*
- [x] `GET    /api/v1/dashboard/payroll/tax/ebupot/export` $\rightarrow$ Ekspor e-Bupot 21/26 **XML tervalidasi XSD** (gagal skema → 422) *(Fase 6 / Bagian 24 — SELESAI)*
- [x] `GET|POST /api/v1/dashboard/payroll/severance-cases` (+ `GET /{id}`, `GET /{id}/preview`, `PUT/DELETE /{id}`) $\rightarrow$ Berkas Exit Settlement & simulasi pesangon murni-baca *(Fase 6 / Bagian 24 — SELESAI)*
- [x] `GET    /api/v1/dashboard/payroll/runs/{payroll}/gl-preview` + `/gl-export` & `GET|PUT|POST /gl-accounts[/reset]` $\rightarrow$ Pratinjau/Ekspor jurnal akuntansi + pemetaan akun editable *(§22.A–E; `PayrollGlController`)*

#### F. Mobile Karyawan (Flutter) — ✅ Selesai
> **Catatan:** path nyata memakai prefix `/employee/payslips` (bukan `/payroll/my-payslips` seperti rencana awal).
- [x] `GET /api/v1/employee/payslips` $\rightarrow$ Riwayat slip gaji milik sendiri *(hanya batch approved/paid)*
- [x] `GET /api/v1/employee/payslips/{id}` $\rightarrow$ Rincian interaktif slip gaji
- [x] `GET /api/v1/employee/payslips/{id}/pdf` $\rightarrow$ Unduh PDF slip gaji *(tanda tangan digital → belum)*

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

> **Legenda:** ✅ Selesai · 🟡 Sebagian · ⬜ Belum. Checklist di bawah adalah **status nyata** per 2026-09-28; diagram kotak setelahnya adalah **visi lengkap** rencana awal.

### 📋 Checklist Status Rinci

**FASE 1 (P0 — Master Data, Migrasi DB, & Enkripsi PII) — ✅ Selesai (subset MVP)**
- [x] Migrasi tabel MVP: `salary_components`, `employee_salaries`, `employee_salary_components`, `employee_tax_profiles`, `statutory_rule_versions`, `payrolls`, `payslips`, `payslip_items`, `employee_loans`
- [x] Enkripsi PII: cast `encrypted` native Laravel untuk NPWP + helper masking di response
- [x] Seeder tarif TER PMK 168/2023, Pasal 17 UU HPP, PTKP (`PayrollStatutorySeeder`)
- [x] CRUD Master Komponen Gaji
- [x] Tabel `payroll_groups` (+ CRUD & keanggotaan `users.payroll_group_id`), `employee_bpjs_profiles` (Fase 2), `employee_bank_accounts` (Fase 3) — **SELESAI**. *(`employee_assignments` terpisah diganti pendekatan MVP kolom `users.payroll_group_id`; grup payroll → [Fase 4+ / Bagian 23](#23-enam-fitur-lanjutan-pasca-mvp--kontrak-api--tugas-frontend))*
- [ ] Seeder Cap BPJS 2024–26 *(ditunda ke Fase 2 — cap efektif tunggal sudah di `PayrollStatutorySeeder`; historisisasi multi-tahun belum)*
- [x] CRUD Payroll Groups (`PayrollGroupController`) & scoping batch per-grup/cabang — **SELESAI** ([Fase 4+ / Bagian 23](#23-enam-fitur-lanjutan-pasca-mvp--kontrak-api--tugas-frontend)). *(hierarki cabang multi-level lanjutan tetap Fase 6)*

**FASE 2 (P0 — Core Calculation Engine & Unit Testing) — ✅ Selesai (backend)**
- [x] `PayrollCalculationService` (`app/Services/Payroll/PayrollCalculator.php`): engine batch utama
- [x] `Pph21TerCalculator`: TER bulanan (Kategori A/B/C) + Pasal 17 masa terakhir
- [x] Integrasi Lembur PP 35/2021 (dari `OvertimeApproval` yang sudah disetujui)
- [x] Integrasi Presensi (potongan absen) & Struk (reimburse non-pajak) & Kasbon (cicilan)
- [x] Snapshot `payrolls`, `payslips`, `payslip_items`
- [x] Unit testing matematis (`tests/Feature/PayrollTest.php` — 9 uji, termasuk BPJS + cap)
- [x] **`BpjsCalculatorService`** (`app/Services/Payroll/BpjsCalculatorService.php`) — Kesehatan (4% perusahaan / 1% karyawan, cap upah Rp 12jt); Ketenagakerjaan: **JKK** per kelas risiko (0,24%–1,74%), **JKM** 0,3%, **JHT** 3,7%/2% (upah riil), **JP** 2%/1% (cap Rp 10.042.300), **JKP** rekomposisi (tanpa potongan/beban baru). Semua tarif effective-dated via `statutory_rule_versions` (di-seed `PayrollStatutorySeeder`), tanpa eval/raw SQL.
- [x] Basis upah BPJS = gaji pokok + seluruh tunjangan tetap; iuran **perusahaan** = info total (bukan pengurang neto), iuran **karyawan** (Kes 1% + JHT 2% + JP 1%) = baris potongan slip (`source='bpjs'`, `code=BPJS_*_EMP`)
- [x] Iuran pensiun/JHT+JP karyawan menjadi **pengurang PPh21 hanya pada rekonsiliasi tahunan Pasal 17** (bukan TER bulanan) — `yearEndReconciliation()` + `priorPensionAccumulation()`
- [x] **`payslip_calculation_steps`** (calculation trace: GROSS/OVERTIME/ATTENDANCE/BPJS_*/PPH21_*/NETT) — jejak audit per slip
- [x] **`employee_tax_period_totals`** (konsolidasi masa pajak per karyawan per bulan, upsert idempoten saat kalkulasi)
- [x] Kolom ringkasan iuran: `payrolls.total_bpjs_company/employee`, `payslips.bpjs_company_total/employee_total`; profil `employee_bpjs_profiles` (nomor kepesertaan terenkripsi + masking)
- [x] **Frontend Web SELESAI** (`PayrollManagement.tsx`): profil BPJS karyawan (toggles Kes/TK/JKP, kelas risiko JKK 1–5, masked nomor), opsi kalkulasi `bpjs: true`, kartu beban BPJS perusahaan terpisah di slip, badge statutori potongan BPJS, akordeon Calculation Steps trace, total BPJS perusahaan & karyawan di ringkasan batch. → **lihat [Bagian 19](#19-tugas-frontend-yang-harus-dikerjakan-fase-2)**

**FASE 3 (P1 — Workflow Approval, Anti-Fraud, & Adjustments) — ✅ Selesai (backend & web)**
- [x] Alur approval: Draft → Calculated → Submitted → Approved → Paid
- [x] Maker-checker: larangan self-approval (`approved_by ≠ prepared_by` → 403)
- [x] Immutability snapshot pasca-approve (hitung ulang/hapus → 422)
- [x] Audit trail via `AuditLogger`
- [x] **Modul Adjustments & koreksi retroaktif** (`payroll_adjustments` + `PayrollAdjustmentController`): earning/deduction, `is_taxable`, `retroactive_payroll_id`, maker-checker approve, void-only + guard batch final; klaim-saat-calculate + release-saat-recalc/hapus di `PayrollCalculator` (item `source='adjustment'`, calc-step `ADJUSTMENT`)
- [x] **Proteksi khusus perubahan rekening bank** (`employee_bank_accounts` + `EmployeeBankAccountController`): ajukan→verifikasi maker-checker→aktif (rekening lama `superseded`, sinkron `users.bank_*`)/tolak; nomor terenkripsi + termasking; notifikasi keamanan in-app + FCM ke karyawan
- [x] **`payroll_logs` hash-chain SHA-256** (`PayrollAuditLogger`): rantai per-company (`prev_hash`→`record_hash`, `sequence`), `verifyChain()` deteksi tamper; berdampingan dengan `AuditLogger`; endpoint `GET /runs/{id}/logs`
- [x] **Step-up PIN saat final approval** (`PayrollSecurityController` + `User.security_pin` hashed): soft rollout — `approve`/`mark-paid` menerima `pin`, WAJIB hanya bila approver sudah mengatur PIN; tanpa lockout kustom (andalkan `throttle:actions`)
- [x] **Frontend Web SELESAI** (`PayrollManagement.tsx`): sub-tab Penyesuaian (tabel, filter status, form buat earning/deduction/pajak/retroaktif, maker-checker approve, void modal ber-alasan), sub-tab Rekening Bank (maker-checker verifikasi/tolak, masked account), tab Jejak Audit & Integritas Kriptografi Hash-Chain SHA-256 (`ok`/`broken_at`), dialog otorisasi PIN approve/mark-paid & modal manajemen PIN Keamanan. → **lihat [Bagian 20](#20-fase-3--kontrak-api-baru--tugas-frontend)**

**FASE 4 (P1 — Pencairan Bank, PDF, Coretax/1721-A1, GL) — ✅ Selesai (backend & web)**
- [x] Generator PDF Slip Gaji (`barryvdh/laravel-dompdf`) + simpan di disk privat
- [x] **Ekspor file transfer bank** (`payroll_payment_batches` + arsitektur driver `BankFileFormatter`): 5 format (BCA KlikBisnis, Mandiri MCM, BRI CMS, BNI Direct, Generic CSV) sebagai varian CSV terdokumentasi + checksum SHA-256, disimpan di disk privat `storage/app/private/payroll/payment-batches/`. Nomor rekening PENUH hanya di berkas; item DB termasking. Step-up PIN (soft) saat generate.
- [x] **Rekonsiliasi pembayaran bank** (`payroll_payment_items` + `PayrollPaymentController::reconcile`): status per-item `success`/`failed`/`rejected_by_bank`, transisi batch `file_generated`→`partially_settled`→`reconciled`, notifikasi FCM/in-app untuk item yang baru sukses. **Decoupled** dari `mark-paid` (tak mengubah payslip/payroll, tak advance kasbon). Teruji (`tests/Feature/PayrollPhase4Test.php`, 14 uji hijau).
- [x] **Bukti Potong PPh21 tahunan (1721-A1) & ekspor Coretax** (`PayrollTaxController` + `AnnualTaxAggregator` + view `payroll/tax/1721a1.blade.php`): **PDF 1721-A1 (dompdf) + draf CSV/JSON siap-Coretax** (label DRAF — bukan e-Bupot XML resmi). Angka dirangkai on-demand dari `employee_tax_period_totals` + iuran pensiun slip; NPWP penuh HANYA di berkas (gated `manage` + audit SECURITY/WARNING), termasking di JSON. Endpoint: `/tax/1721a1` (list/detail/pdf/export). **Kontrak & tugas FE → [Bagian 22](#22-fase-4-lanjutan--pajak-1721-a1-ekspor-gl--calculation-trace--kontrak-api--tugas-frontend).**
- [x] **Ekspor jurnal akuntansi (GL)** (`PayrollGlController` + `PayrollGlComposer` + `config/payroll_gl.php` + tabel `payroll_gl_accounts`): pemetaan 11 pos akun kanonik editable per-perusahaan (default fallback dari config), jurnal debit/kredit per run **dijamin seimbang**, pratinjau + ekspor CSV/JSON (audit FINANCE). *(Alokasi Cost Center via `?group_by=none|division|branch` kini **SELESAI** → [Fase 4+ / Bagian 23](#23-enam-fitur-lanjutan-pasca-mvp--kontrak-api--tugas-frontend).)* **Kontrak & tugas FE → [Bagian 22](#22-fase-4-lanjutan--pajak-1721-a1-ekspor-gl--calculation-trace--kontrak-api--tugas-frontend).**
- [x] **Endpoint calculation-trace** `GET /payslips/{payslip}/calculation-trace` (`PayrollTraceController`): memaparkan `payslip_calculation_steps` (urut) + rincian earnings/deductions; murni-baca, read-gated, scoped company. **Kontrak & tugas FE → [Bagian 22](#22-fase-4-lanjutan--pajak-1721-a1-ekspor-gl--calculation-trace--kontrak-api--tugas-frontend).**
- [x] **Frontend Web SELESAI** (`expenseflow-web/`):
  - **Modul A — Pembayaran Bank / Disbursement** (`PayrollDisbursement.tsx`): pemilih run payroll berstatus approved/paid, pembuatan batch dengan format bank dinamis (BCA KlikBisnis, Mandiri MCM, BRI CMS, BNI Direct, Generic CSV), otentikasi step-up PIN approver, unduh berkas transfer aman (nomor rekening penuh hanya di berkas privat), rekonsiliasi dirty-state bertahap (`partially_settled`→`reconciled`), peringatan `skipped_no_bank`, checksum SHA-256.
  - **Modul B — Akuntansi Buku Besar / General Ledger** (`PayrollGlAccounts.tsx` & `PayrollGlJournal.tsx`): pemetaan 11 pos akun kanonik (sisi Debit & Kredit) dengan modal override kode & nama akun, lencana `is_overridden`, aksi reset ke default standar; pratinjau jurnal debit/kredit seimbang per run (flat) dan multi-dimensi cost center (divisi/cabang) dengan segmen sentinel unallocated di akhir; ekspor CSV & JSON.
  - **Modul C — PPh 21 Formulir 1721-A1 & Coretax** (`PayrollTax1721A1.tsx`): pemilih tahun pajak, rekapitulasi komprehensif penghasilan bruto, neto, PTKP, PKP, PPh 21 terutang/dipotong/selisih kurang bayar, NPWP termasking di layar, banner draf dinamis tahun berjalan, modal detail formulir resmi 1721-A1 (Bagian A s.d. E), unduh bukti potong PDF resmi, ekspor CSV format Coretax DJP & JSON.
  - **Modul D — Jejak Perhitungan Calculation Trace** (`PayslipDetailModal` upgrade di `PayrollManagement.tsx`): pengayaan langkah kalkulasi dengan versi formula (`formula_version`), referensi aturan hukum (`rule_reference`), hasil mentah (`raw_result`), selisih pembulatan desimal (`rounding_diff`), dan inspeksi parameter input langkah (`input_payload`).

**FASE 4+ (P1 — Enam Fitur Lanjutan Pasca-MVP) — ✅ Selesai (backend, teruji)**
- [x] **(1) Run THR terpisah** (`app/Services/Payroll/Thr/ThrCalculatorService.php` + cabang `PayrollController::calculate()`): jenis batch `run_type='thr'`; slip THR = **1 baris pendapatan `source='thr'`** (+ potongan `source='tax'` bila PPh21 terutang), tanpa gaji pokok/tunjangan/lembur/absen/kasbon/BPJS (`bpjs_*_total=0`); nominal Permenaker 6/2016 (≥12 bln → 1× upah; 1–11 bln → pro-rata `bulan/12`; <1 bln → tak berhak); PPh21 **TER marginal** PMK 168/2023 (fallback THR-only) + konsolidasi masa pajak **tak-teratur tanpa clobber** kolom reguler; self-correcting pada rekonsiliasi Pasal-17 run reguler Desember. Teruji (`tests/Feature/PayrollThrRunTest.php`, 6 uji).
- [x] **(2) Grup Payroll & scoping MVP** (`app/Models/PayrollGroup.php` + `PayrollGroupController` + migrasi `payroll_groups`, `payrolls.payroll_group_id`, `users.payroll_group_id`): CRUD grup (read/manage, scoped `company_id`, guard hapus bila masih beranggota/terpakai) + keanggotaan via kolom `users.payroll_group_id` + `eligibleUsers()` menyaring per-grup; unique index run di-rebuild → `payrolls_period_scope_unique` (jenis+grup+cabang+periode), guard aplikatif `store()` otoritatif (NULL-distinct). Teruji (`tests/Feature/PayrollGroupScopingTest.php`).
- [x] **(3) NPWP Perusahaan terenkripsi** (`companies.npwp` cast `encrypted` + `CompanyTaxProfileController`): `GET/PUT /company-tax-profile` — JSON hanya `npwp_masked` (`read`); simpan (`manage`) normalisasi 15/16 digit + audit `COMPANY_TAX_PROFILE_UPDATED`; NPWP **penuh** HANYA di berkas (PDF 1721-A1 & e-Bupot XML), tak pernah di JSON. Teruji (`tests/Feature/CompanyNpwpTest.php`).
- [x] **(4) Rincian BPJS di PDF slip** (`app/Services/Payroll/Bpjs/EmployerBpjsBreakdown.php` + `PayslipController::streamPdf` + blade slip): blok informasional "Iuran BPJS Ditanggung Perusahaan" diturunkan dari `payslip_calculation_steps` yang sudah persist — **tanpa perubahan skema/kalkulasi**; tidak memengaruhi neto/ringkasan. Teruji (`tests/Feature/PayslipBpjsBlockTest.php`).
- [x] **(5) e-Bupot 21/26 XML** *(status draf di bawah ini sudah **DIGANTI** oleh Fase 6 — lihat §16 FASE 6 & §24.D)* (`app/Services/Payroll/Tax/EbupotDraftXmlBuilder.php` + `PayrollTaxController::exportEbupot`, `GET /tax/ebupot/export`): XML well-formed via `DOMDocument` (tanpa dependency), root `<eBupotDraf resmi="false">` + komentar non-resmi; 1 `<BuktiPotong>` per karyawan dari `AnnualTaxAggregator`; NPWP pemberi kerja & karyawan **penuh** hanya di berkas; audit `PAYROLL_EBUPOT_EXPORTED` SECURITY/WARNING. ~~**DRAF — belum tervalidasi XSD DJP.**~~ (sejak Fase 6: **wajib lolos XSD**, gagal → 422). Teruji — berkas uji kini `tests/Feature/EbupotXsdXmlTest.php` (menggantikan `EbupotDraftXmlTest.php`).
- [x] **(6) Alokasi Cost Center GL** (snapshot `payslips.division_id` + `payslips.attendance_setting_id` + `PayrollGlComposer::composeGrouped()`): `GET .../gl-preview|gl-export?group_by=none|division|branch` (default `none` **byte-identik** dgn `compose()` lama); tiap segmen seimbang & **Σ segmen == jurnal flat** (BPJS/net diturunkan per-segmen dari payslip, bukan skalar run). Teruji (`tests/Feature/PayrollGlGroupedTest.php`).
- **Migrasi baru `100022`–`100027`** (forward-only, non-destruktif): `add_npwp_to_companies`, `add_cost_center_snapshot_to_payslips`, `create_payroll_groups_table`, `add_run_discriminators_to_payrolls`, `add_payroll_group_id_to_users`, `add_irregular_pph21_columns_to_employee_tax_period_totals`. **Kontrak API & tugas Frontend (web React) → [Bagian 23](#23-enam-fitur-lanjutan-pasca-mvp--kontrak-api--tugas-frontend). Mobile: tidak ada.**

**FASE 5 (P1 — Integrasi Mobile Flutter & Notifikasi) — ✅ Selesai**
- [x] Menu "Slip Gaji" di Tab Profil mobile karyawan
- [x] Tampilan rincian penghasilan, potongan, & ringkasan kehadiran/lembur
- [x] Fitur Unduh PDF slip gaji (buka di viewer sistem via `open_filex`)
- [x] Fitur Share PDF slip gaji (`share_plus`, tombol di `AppBar` layar rincian slip)
- [x] Push Notification (FCM) otomatis saat gaji berstatus `paid` (`PayrollController::notifyPayrollPaid()`, meniru pola `notifyDisbursement()`: insert `notifications` in-app + kirim FCM per-payslip, dibungkus try/catch; teruji di `tests/Feature/PayrollTest.php`)

**FASE 6 (P2 — Fleksibilitas Lanjutan & Enterprise Modules) — 🟡 Sebagian (seluruh item BACKEND selesai; sisa = UI Web)**
- [x] **Mesin Formula DSL custom (Stage 1 & Stage 2 Backend SELESAI & teruji):** `FormulaLexer` → `FormulaParser` → `FormulaEvaluator` → `FormulaEngine` di `app/Services/Payroll/Formula/`. Tanpa `eval()`/raw SQL, whitelist variabel sistem & fungsi matematika/logika, deteksi siklus (DFS coloring), dan topological sort (`dependencyOrder`). Persistensi migrasi `100028` (`salary_components.formula_dsl`, `calc_type='formula'`), endpoint validasi `POST /components/validate-formula`, dan integrasi evaluasi otomatis di `PayrollCalculator::applyFormulaComponents()`. Teruji (`FormulaEngineTest` 46 unit test + `PayrollFormulaComponentTest` 11 feature test hijau). *(UI Editor Web belum)*
- [x] `GET|POST /api/v1/dashboard/payroll/job-levels` (+ `PUT/DELETE /{jobLevel}`) $\rightarrow$ CRUD jenjang jabatan *(Fase 6 / Bagian 24 — SELESAI)*
- [x] `GET|POST /api/v1/dashboard/payroll/salary-grades` (+ `PUT/DELETE /{salaryGrade}`) $\rightarrow$ CRUD golongan upah (rentang min–mid–maks) *(Fase 6 / Bagian 24 — SELESAI)*
- [x] `GET|POST /api/v1/dashboard/payroll/currency-rates` (+ `PUT/DELETE /{currencyRate}`) $\rightarrow$ Master kurs valas bertanggal-efektif *(Fase 6 / Bagian 24 — SELESAI)*
- [x] **Modul Exit Settlement pesangon/PHK & kompensasi PKWT (Backend SELESAI & teruji):** `SeveranceCase` + `SeveranceCalculatorService` (PP 35/2021 — UP Pasal 40(2), UPMK Pasal 40(3), UPH Pasal 40(4), uang kompensasi PKWT Pasal 15–17) dijalankan via batch bertipe `payrolls.run_type='severance'`. **PPh 21 Final** pesangon (PP 68/2009: 0%/5%/15%/25%) lewat `FinalTaxCalculator::pesangonFinal()` — `is_taxable=false`, **tidak** direkonsiliasi Pasal 17 & tidak dikreditkan di 1721-A1. Endpoint CRUD + `/preview` murni-baca (`SeveranceCaseController`), GL memakai bucket `expense_severance`. Migrasi `100032`. Teruji (`PayrollSeveranceRunTest`, 10 uji hijau). *(UI Web belum — §24)*
- [x] **Struktur & skala upah (salary grades & job levels) — Backend SELESAI & teruji:** `job_levels` + `salary_grades` (min–mid–maks per golongan, Permenaker 1/2017) + CRUD (`JobLevelController`, `SalaryGradeController`); penetapan gaji karyawan divalidasi terhadap rentang golongan (di luar rentang → **422** pada `basic_salary`), `job_level_id` diturunkan otomatis dari golongan. Golongan yang sudah dipakai riwayat gaji **tidak dapat dihapus** (→422). Migrasi `100029`–`100031`. Teruji (`SalaryGradeStructureTest`, 7 uji hijau). *(UI Web belum — §24)*
- [x] **Penggajian multi-mata uang & PPh 26 ekspatriat (Backend SELESAI & teruji):** master kurs bertanggal-efektif (`currency_rates` + `CurrencyRateController`), `CurrencyConverter` dengan **rate lock** per batch (`payrolls.exchange_rates` JSON) sehingga angka slip tidak bergeser saat master kurs berubah; kurs tidak tersedia → **422** (sistem **tidak pernah** mengasumsikan 1:1). Semua kolom uang payslip tetap **IDR**; valas disimpan sebagai `currency`/`exchange_rate`/`gross_currency`/`net_currency`. Subjek pajak luar negeri (`employee_tax_profiles.tax_subject_type='foreign'`) dipotong **PPh 26** 20% × bruto (Pasal 26 UU PPh) atau tarif **P3B** bila `treaty_country` terisi — tanpa PTKP/biaya jabatan/TER. Migrasi `100033`–`100035`. Teruji (`PayrollMultiCurrencyPph26Test`, 14 uji hijau). *(UI Web belum — §24)*
- [x] **Validasi Skema Resmi XSD e-Bupot DJP (Backend SELESAI & teruji):** `EbupotDraftXmlBuilder` **digantikan** `EbupotXmlBuilder` — setiap dokumen **wajib** lolos `DOMDocument::schemaValidate()` sebelum dikirim; gagal ⇒ **422** + daftar galat & berkas tidak pernah terkirim (`EbupotSchemaException`, audit `PAYROLL_EBUPOT_SCHEMA_FAILED`). Dua tingkat skema (`config/payroll_ebupot.php`): XSD **resmi DJP** bila dipasang admin → `resmi="true"`, jika tidak XSD **internal** bundled (`resources/schemas/ebupot/`) → `resmi="false"` — label `resmi` karenanya tidak pernah bohong. Endpoint status `GET /tax/ebupot/schema`. Teruji (`EbupotXsdXmlTest`, 6 uji hijau). *(UI Web belum — §24)*

> **Bonus di luar rencana awal (sudah selesai):** modul **Kasbon/Pinjaman + cicilan otomatis** (`employee_loans`) dan **dashboard Web Penggajian** (`PayrollManagement.tsx`).

---

### 🗺️ Diagram Visi Lengkap (rencana awal)

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

---

## 19. Tugas Frontend yang Harus Dikerjakan (Fase 2)

> **Konteks.** Seluruh **backend + logika Fase 2 (BPJS) sudah selesai & lulus uji** (`PayrollTest` 12 kasus hijau). Bagian ini adalah daftar **pekerjaan frontend (web React + mobile Flutter)** yang harus Anda kerjakan agar fitur BPJS terlihat & dapat dikelola pengguna. Backend **tidak** menyediakan UI — hanya kontrak API di bawah ini.
>
> **Prasyarat data.** Pastikan sudah menjalankan `php artisan migrate` (migrasi `100010`–`100014`) dan `php artisan db:seed --class=Database\\Seeders\\PayrollStatutorySeeder` agar tarif BPJS (`bpjs_kes`, `bpjs_tk`) tersedia di `statutory_rule_versions`. Tanpa seed ini, kalkulasi BPJS menghasilkan iuran 0.

### 19.1. Kontrak API yang Dikonsumsi Frontend

Semua endpoint dashboard berada di grup `/api/v1/dashboard/payroll/*` (butuh izin modul **Payroll**: `read` untuk lihat, `manage` untuk ubah). Endpoint karyawan di `/api/v1/employee/payslips/*`.

#### A. Simpan / Ubah Profil BPJS Karyawan — **BARU**
`PUT /api/v1/dashboard/payroll/salaries/{userId}/bpjs-profile` (izin `manage`)

Request body:
```jsonc
{
  "has_bpjs_kes": true,        // wajib (boolean) — peserta BPJS Kesehatan
  "has_bpjs_tk": true,         // wajib (boolean) — peserta BPJS Ketenagakerjaan
  "has_jkp": true,             // opsional (boolean) — peserta JKP
  "jkk_risk_class": 3,         // opsional (integer 1..5) — kelas risiko JKK
  "bpjs_kes_no": "0001234567890", // opsional — nomor kepesertaan Kesehatan
  "bpjs_tk_no": "9876543210001"   // opsional — nomor kepesertaan Ketenagakerjaan
}
```
Aturan nomor kepesertaan (sama seperti NPWP): **field tidak dikirim (absen) = tidak diubah**; kirim **string kosong `""` = menghapus** nomor tersimpan. Non-digit otomatis dibersihkan.

Response `200`:
```jsonc
{
  "message": "Profil BPJS berhasil disimpan.",
  "data": {
    "has_bpjs_kes": true,
    "has_bpjs_tk": true,
    "has_jkp": true,
    "jkk_risk_class": 3,
    "bpjs_kes_no_masked": "•••••••••7890", // SELALU termasking (4 digit terakhir)
    "bpjs_tk_no_masked": "•••••••••0001"
  }
}
```
> ⚠️ Nomor kepesertaan **tidak pernah** dikirim dalam bentuk plaintext. FE hanya menerima versi termasking (`*_masked`). Untuk mengubah, kirim nomor baru penuh; untuk membiarkan, jangan sertakan field-nya.

#### B. Detail Gaji Karyawan (kini memuat `bpjs_profile`) — **DIPERLUAS**
`GET /api/v1/dashboard/payroll/salaries/{userId}` (izin `read`) — objek `data` kini menambahkan:
```jsonc
{
  "data": {
    "employee": { "...": "..." },
    "active_salary": { "...": "..." },
    "salaries": [],
    "components": [],
    "tax_profile": { "...": "..." },
    "bpjs_profile": {              // null bila belum pernah diatur
      "has_bpjs_kes": true,
      "has_bpjs_tk": true,
      "has_jkp": true,
      "jkk_risk_class": 3,
      "bpjs_kes_no_masked": "•••••••••7890",
      "bpjs_tk_no_masked": "•••••••••0001"
    }
  }
}
```

#### C. Opsi Kalkulasi BPJS — **DIPERLUAS**
`POST /api/v1/dashboard/payroll/runs/{id}/calculate` (izin `manage`) — kini menerima flag `bpjs` (boolean) bersama opsi lain:
```jsonc
{ "pph21": true, "bpjs": true, "overtime": true, "attendance_deduction": true, "loan_installment": true }
```
`bpjs` **default `true`** bila tidak dikirim. Hanya karyawan yang punya `employee_bpjs_profiles` dengan flag `has_bpjs_kes`/`has_bpjs_tk` aktif yang dikenai iuran.

#### D. Detail Slip Gaji (kini memuat header BPJS + jejak perhitungan) — **DIPERLUAS**
Dashboard: `GET /api/v1/dashboard/payroll/payslips/{id}` (izin `read`).
Karyawan: `GET /api/v1/employee/payslips/{id}` (hanya slip milik sendiri & batch sudah `approved`/`paid`).

Struktur `data`:
```jsonc
{
  "data": {
    "payslip": {
      "gross": "15000000.00",
      "total_deduction": "...",
      "pph21": "...",
      "bpjs_company_total": "...",   // BARU — iuran ditanggung PERUSAHAAN (info, bukan pengurang neto)
      "bpjs_employee_total": "520423.00", // BARU — total potongan BPJS bagian KARYAWAN
      "net": "...",
      "...": "..."
    },
    "period": "Juni 2026",
    "earnings":  [ /* item type=earning */ ],
    "deductions": [
      // Potongan BPJS karyawan tampil di sini dengan source='bpjs' & is_statutory=true:
      { "label": "BPJS Kesehatan (1%)", "code": "BPJS_KES_EMP", "amount": "120000.00", "source": "bpjs", "is_statutory": true },
      { "label": "BPJS JHT (2%)",       "code": "BPJS_JHT_EMP", "amount": "300000.00", "source": "bpjs", "is_statutory": true },
      { "label": "BPJS JP (1%)",        "code": "BPJS_JP_EMP",  "amount": "100423.00", "source": "bpjs", "is_statutory": true }
    ],
    "calculation_steps": [           // BARU — jejak perhitungan transparan
      { "step_code": "GROSS",     "step_sequence": 1, "input_payload": { "...": "..." }, "final_result": "15000000.00", "rule_reference": null },
      { "step_code": "BPJS_KES",  "step_sequence": 2, "input_payload": { "wage_base": 15000000, "capped_base": 12000000, "employee_rate": 0.01, "wage_cap": 12000000 }, "final_result": "600000.00", "rule_reference": "statutory_rule_versions#..." },
      { "step_code": "BPJS_JKK",  "step_sequence": 3, "input_payload": { "...": "..." }, "final_result": "36000.00" },
      { "step_code": "BPJS_JHT",  "step_sequence": 4, "input_payload": { "...": "..." }, "final_result": "855000.00" },
      { "step_code": "BPJS_JP",   "step_sequence": 5, "input_payload": { "...": "..." }, "final_result": "301269.00" },
      { "step_code": "PPH21_TER", "step_sequence": 6, "input_payload": { "...": "..." }, "final_result": "..." },
      { "step_code": "NETT",      "step_sequence": 7, "input_payload": { "...": "..." }, "final_result": "..." }
    ]
  }
}
```
> **Catatan tampilan:** `bpjs_company_total` adalah iuran yang **ditanggung perusahaan** — **jangan** dijumlahkan ke potongan karyawan / mengurangi take-home. Tampilkan terpisah sebagai informasi. Yang mengurangi neto hanya item di `deductions` (termasuk BPJS bagian karyawan).

#### E. Header Batch Payroll (kini memuat total BPJS) — **DIPERLUAS**
`GET /api/v1/dashboard/payroll/runs` dan `GET /api/v1/dashboard/payroll/runs/{id}` — objek `payroll` kini punya `total_bpjs_company` dan `total_bpjs_employee` (string desimal). Tampilkan di ringkasan batch.

> **Nilai desimal.** Semua kolom uang dikirim sebagai **string** (mis. `"520423.00"`) karena cast `decimal:2`. Parse dengan `parseFloat`/`double.parse` sebelum diformat `Intl.NumberFormat('id-ID')` (web) atau `formatCurrency` (mobile).

### 19.2. Web — `expenseflow-web/src/`

**1) `services/endpoints.ts` — tambah fungsi pada grup `payrollApi`:**
- `saveBpjsProfile(userId, payload)` → `PUT /dashboard/payroll/salaries/{userId}/bpjs-profile`.
- Pastikan `getEmployeeSalary(userId)` sudah membaca `data.bpjs_profile` (respons sudah berisi, tinggal dipakai).
- Pastikan `calculateRun(runId, options)` mengirim `bpjs: boolean` di body opsi.
- `getPayslip(id)` sudah mengembalikan `calculation_steps` & header BPJS — tidak perlu endpoint baru.

**2) Tipe (`auth/AuthContext.tsx` / tipe payroll):**
- Tambah tipe `BpjsProfile { has_bpjs_kes; has_bpjs_tk; has_jkp; jkk_risk_class; bpjs_kes_no_masked; bpjs_tk_no_masked }`.
- Perluas tipe `Payslip` dengan `bpjs_company_total`, `bpjs_employee_total`; tipe detail dengan `calculation_steps[]`.
- Perluas tipe `Payroll` (header) dengan `total_bpjs_company`, `total_bpjs_employee`.

**3) `components/PayrollManagement.tsx`:**
- **Sub-tab "Gaji Karyawan"** — tambah kartu **"Profil BPJS"** di bawah "Profil Pajak":
  - Toggle **Peserta BPJS Kesehatan** (`has_bpjs_kes`).
  - Toggle **Peserta BPJS Ketenagakerjaan** (`has_bpjs_tk`).
  - Toggle **Peserta JKP** (`has_jkp`).
  - Dropdown **Kelas Risiko JKK (1–5)** (`jkk_risk_class`) — tampil hanya bila `has_bpjs_tk` aktif; beri keterangan singkat (1=risiko sangat rendah … 5=risiko sangat tinggi, memengaruhi tarif JKK yang ditanggung perusahaan).
  - Input **Nomor BPJS Kesehatan** & **Nomor BPJS Ketenagakerjaan** — tampilkan nilai `*_masked` sebagai placeholder/hint; beri teks bantu: *"Kosongkan untuk tidak mengubah."* Kirim field hanya bila diedit pengguna.
  - Tombol **Simpan Profil BPJS** → `saveBpjsProfile`; tampilkan banner sukses; panggil `onAddAuditLog`.
- **Sub-tab "Proses Payroll"** — pada dialog/form **Kalkulasi**, tambah checkbox **"Hitung Iuran BPJS"** (`bpjs`), default tercentang, sejajar dengan opsi PPh21/Lembur/Absen.
- **Detail Slip (modal/panel):**
  - Di daftar **Potongan**, item dengan `source==='bpjs'` diberi badge **"BPJS"/"Statutori"** agar dibedakan dari potongan manual.
  - Tambah kartu info **"Iuran Ditanggung Perusahaan"** menampilkan `bpjs_company_total` (jelas ditandai *bukan* pengurang gaji).
  - Tambah bagian **"Rincian Perhitungan"** yang dapat di-expand: render `calculation_steps` berurutan (`step_code` → label ramah + `final_result` diformat rupiah). Pemetaan label yang disarankan: `GROSS`→"Penghasilan Bruto", `OVERTIME`→"Lembur", `ATTENDANCE`→"Potongan Absen", `BPJS_KES`→"BPJS Kesehatan", `BPJS_JKK`→"JKK", `BPJS_JKM`→"JKM", `BPJS_JHT`→"JHT", `BPJS_JP`→"Jaminan Pensiun", `PPH21_TER`→"PPh21 (TER)", `PPH21_PASAL17`→"PPh21 (Pasal 17)", `NETT`→"Penghasilan Neto".
- **Ringkasan Batch (daftar/detail run):** tampilkan kolom/kartu **Total Iuran BPJS Perusahaan** (`total_bpjs_company`) dan **Total Potongan BPJS Karyawan** (`total_bpjs_employee`).

### 19.3. Mobile — `expenseflow-mobile/` (Flutter)

> Karyawan **hanya melihat** hasil BPJS di slip; pengelolaan profil BPJS adalah kewenangan HR/Finance (web). **Tidak ada form BPJS di mobile.**

**1) `models/payslip_model.dart` — perluas `PayslipDetail` (`fromJson` defensif):**
- Tambah `bpjsEmployeeTotal`, `bpjsCompanyTotal` (parse dari `data.payslip.*`, `double.tryParse` karena string).
- Tambah parsing `calculation_steps` → `List<CalculationStep>` (`stepCode`, `stepSequence`, `finalResult`, `ruleReference`).
- Item potongan BPJS sudah masuk daftar `deductions` (bawa `source` & `code`); simpan field `source` agar bisa diberi badge.

**2) `screens/detail_slip_gaji_screen.dart`:**
- Di daftar **Potongan**, tandai item `source=='bpjs'` dengan chip kecil **"BPJS"**.
- Tambah kartu informasi **"Iuran BPJS Ditanggung Perusahaan"** = `bpjsCompanyTotal` (teks jelas: tidak memotong gaji, hanya informasi).
- Tambah **ExpansionTile "Rincian Perhitungan"** yang menampilkan `calculation_steps` (label ramah sama seperti web + nominal `formatCurrency`).
- Tombol **Unduh PDF** existing tetap dipakai (PDF slip belum menampilkan BPJS — lihat catatan di bawah).

**3) `services/api_service.dart` / `providers/payslip_provider.dart`:** tidak perlu endpoint baru — cukup pastikan model detail memetakan field baru dari `getPayslipDetail(id)`.

### 19.4. Catatan & Batasan (yang belum dikerjakan backend)
- **PDF slip (`resources/views/payroll/payslip.blade.php`)** belum menampilkan rincian BPJS & jejak perhitungan. Bila diperlukan di slip cetak, ini pekerjaan backend terpisah (belum masuk Fase 2) — beri tahu bila ingin ditambahkan.
- **Snapshot nomor BPJS pada slip** tidak ditampilkan (menghindari paparan PII pada dokumen). Nomor hanya bisa dilihat termasking di layar profil (web).
- Endpoint daftar slip karyawan (`GET /employee/payslips`) sengaja **tidak** memuat total BPJS (ringkas); nilai lengkap ada di **detail** slip.

### 19.5. Checklist Penerimaan (Definition of Done Frontend Fase 2) — ✅ SELESAI
- [x] HR dapat mengisi & menyimpan Profil BPJS karyawan (flag, kelas JKK, nomor) via web; nomor tampil termasking setelah simpan.
- [x] Opsi **Hitung Iuran BPJS** tersedia & default aktif saat kalkulasi batch (`bpjs: true`).
- [x] Detail slip (web & mobile) menampilkan potongan BPJS karyawan (badge BPJS), iuran perusahaan (info terpisah), dan **Rincian Perhitungan (Calculation Steps trace)**.
- [x] Ringkasan batch menampilkan Total BPJS Perusahaan & Karyawan.
- [x] Semua nominal desimal (string) diparse & diformat rupiah `id-ID` dengan benar.
- [x] Karyawan tanpa profil BPJS tetap menampilkan slip normal tanpa baris BPJS (tidak error).

---

## 20. Fase 3 — Kontrak API Baru & Tugas Frontend

> **Konteks.** Seluruh **backend Fase 3 (Workflow Approval, Anti-Fraud, Adjustments) sudah selesai & lulus uji** (`tests/Feature/PayrollPhase3Test.php` — 21 kasus hijau, 151 asersi). Bagian ini adalah **kontrak API** empat modul baru + daftar **pekerjaan frontend (web React + mobile Flutter)** yang **belum** dikerjakan. Backend tidak menyediakan UI.
>
> **Prasyarat data.** Jalankan `php artisan migrate` (migrasi `100015`–`100018`: `payroll_adjustments`, `employee_bank_accounts`, `payroll_logs`, kolom `users.security_pin`/`security_pin_set_at`). Seeder tambahan tidak diperlukan.
>
> **Prinsip lintas modul:** semua endpoint di grup `/api/v1/dashboard/payroll/*` (izin modul **Payroll**: `read` untuk lihat, `manage` untuk ubah); scope `company_id`; nominal uang = **string** desimal (`decimal:2`); nomor rekening = **terenkripsi + termasking** (plaintext tak pernah dikirim); larangan self-approval/self-verify (→403); aksi ubah di-`throttle:actions`.

### 20.1. Modul A — Adjustments & Koreksi Retroaktif

**Model konsumsi (penting untuk FE):** adjustment berstatus `approved` **otomatis** dikonsumsi saat batch payroll **dihitung** (`calculate`) — statusnya menjadi `applied` dan `payroll_id` terisi. Menghitung ulang (recalc) atau menghapus batch **melepas** kembali klaim (`applied`→`approved`, `payroll_id`→null). Setelah batch `approved`/`paid`, adjustment **beku** (tak bisa di-void). FE tidak perlu "menempelkan" adjustment ke batch secara manual — cukup pastikan statusnya `approved` sebelum kalkulasi.

#### A. Daftar Penyesuaian
`GET /api/v1/dashboard/payroll/adjustments` (izin `read`) — query opsional: `status` (`pending`/`approved`/`applied`/`voided`), `user_id`, `payroll_id`.
```jsonc
{ "data": [ {
  "id": 12, "user_id": 5, "type": "earning", "name": "Bonus proyek Q2",
  "amount": "1000000.00", "is_taxable": true, "reason": "…",
  "status": "approved", "payroll_id": null, "retroactive_payroll_id": null,
  "created_by": 3, "approved_by": 4, "approved_at": "2026-06-10T…",
  "user": { "id": 5, "name": "Budi", "employee_code": "…" },
  "created_by_user": { "id": 3, "name": "…" }, "approved_by_user": { "id": 4, "name": "…" }
} ] }
```
> Relasi di-eager dengan nama `createdBy`/`approvedBy` (camelCase → key snake_case `created_by`/`approved_by` sebagai FK + objek relasi). Cek bentuk aktual saat integrasi.

#### B. Buat Penyesuaian
`POST /api/v1/dashboard/payroll/adjustments` (izin `manage`, `throttle:actions`) → **201**.
```jsonc
{
  "user_id": 5,                 // wajib — harus karyawan di company yang sama
  "type": "earning",            // wajib — "earning" | "deduction"
  "name": "Bonus proyek Q2",    // wajib (maks 150)
  "amount": 1000000,            // wajib — > 0
  "is_taxable": true,           // opsional — HANYA berlaku utk earning; deduction DIPAKSA false
  "reason": "…",                // opsional (maks 1000)
  "source_document_path": null, // opsional (maks 255)
  "retroactive_payroll_id": 8   // opsional — batch masa lalu asal koreksi (harus di company sama)
}
```
Dibuat `status=pending`, `created_by`=pengguna. Response: `{ "message": "…", "data": { …adjustment } }`.

#### C. Setujui / Batalkan
- `POST /adjustments/{id}/approve` (izin `manage`) — hanya `pending` (→422 jika bukan); **maker-checker**: penyetuju ≠ pembuat (→403); lintas-company →404. Set `approved` + `approved_by/at`.
- `POST /adjustments/{id}/void` (izin `manage`) — body `{ "reason": "…" }` **wajib**. Boleh dari `pending`/`approved`/`applied`. **Terkunci (→422)** bila `payroll_id` menunjuk batch `approved`/`paid`. Sudah `voided` →422. Set `voided` + `voided_by/at` + `void_reason`, lepas `payroll_id`.

### 20.2. Modul B — Proteksi Perubahan Rekening Bank

Setiap perubahan rekening = **pengajuan baru** (`pending_verification`) yang **wajib diverifikasi orang berbeda** (maker-checker). Nomor rekening **terenkripsi**; respons hanya memuat versi **termasking** (`bank_account_no_masked`, mis. `••••••7890`); `bank_account_no` mentah **tidak** dikirim. Saat diverifikasi: rekening aktif lama → `superseded`, dan kolom `users.bank_name/bank_account_no/bank_account_holder` disinkronkan (agar snapshot payslip existing tetap jalan). Karyawan menerima **notifikasi keamanan** (in-app + FCM) pada setiap perubahan.

#### A. Daftar Rekening Karyawan
`GET /api/v1/dashboard/payroll/employees/{userId}/bank-accounts` (izin `read`) → `{ "data": [ { "id", "bank_name", "bank_account_no_masked", "bank_account_holder", "status", "is_primary", "requested_by", "verified_by", "verified_at", "requested_by_user":{…}, "verified_by_user":{…} } ] }`. Status: `pending_verification` / `active` / `superseded` / `rejected`.

#### B. Ajukan Rekening Baru
`POST /api/v1/dashboard/payroll/employees/{userId}/bank-account` (izin `manage`, `throttle:actions`) → **201**.
```jsonc
{
  "bank_name": "BCA",              // wajib (maks 100)
  "bank_account_no": "1234567890", // wajib (maks 50) — akan dienkripsi
  "bank_account_holder": "Budi",   // wajib (maks 150)
  "bank_branch": null,             // opsional (maks 100)
  "swift_code": null,              // opsional (maks 20)
  "notes": null                    // opsional (maks 1000)
}
```
Dibuat `pending_verification`, `is_primary=false`, `requested_by`=pengguna. **Tidak** mengubah `users.*`. Response memuat rekening termasking.

#### C. Verifikasi / Tolak
- `POST /bank-accounts/{id}/verify` (izin `manage`) — hanya `pending_verification` (→422); **maker-checker**: pemverifikasi ≠ pengaju (→403); lintas-company →404. Set `active` + `is_primary=true` + `verified_by/at`; rekening `active` lama user → `superseded`; sinkron `users.bank_*`; notifikasi + audit `SECURITY/CRITICAL`.
- `POST /bank-accounts/{id}/reject` (izin `manage`) — body `{ "reason": "…" }` **wajib**; maker-checker; hanya `pending_verification` (→422). Set `rejected` + `reject_reason` + `verified_by/at`; notifikasi + audit `SECURITY/WARNING`.

### 20.3. Modul C — `payroll_logs` Hash-Chain (Tamper-Evident)

Rantai audit SHA-256 **per-perusahaan** (`prev_hash`→`record_hash`, `sequence` berurut) yang dicatat berdampingan dengan `AuditLogger` di seluruh lifecycle batch (store/calculate/submit/approve/reject/mark-paid/destroy) + adjustment (create/approve/void) + rekening bank (request/verify/reject). Pencatatan tak pernah menggagalkan alur bisnis (dibungkus try/catch).

`GET /api/v1/dashboard/payroll/runs/{id}/logs` (izin `read`) →
```jsonc
{
  "data": {
    "logs": [ {
      "id", "action", "user_id", "notes",
      "before_state": {…}|null, "after_state": {…}|null,
      "ip_address", "sequence", "prev_hash", "record_hash", "created_at"
    } ],
    "integrity": { "ok": true, "broken_at": null }  // broken_at = sequence pertama yang gagal bila ok=false
  }
}
```
> `integrity` diverifikasi ulang atas **seluruh rantai company** (bukan hanya batch ini) karena rantai bersifat global per-perusahaan. `logs` yang ditampilkan hanya event batch tsb (urut `sequence`).

### 20.4. Modul D — Step-up PIN (Soft Rollout)

**Kebijakan (keputusan produk): PIN WAJIB hanya jika approver SUDAH mengaturnya.** Bila belum, `approve`/`mark-paid` berjalan normal tanpa `pin` (non-breaking untuk FE lama & tes lama). Tidak ada lockout kustom — andalkan `throttle:actions`.

#### A. Status & Pengaturan PIN Milik Sendiri
- `GET /api/v1/dashboard/payroll/security/pin` → `{ "data": { "has_pin": true|false, "set_at": "…"|null } }`.
- `POST /api/v1/dashboard/payroll/security/pin` (`throttle:actions`):
```jsonc
{
  "current_password": "…",  // wajib — diverifikasi ke password akun
  "current_pin": "1234",    // wajib HANYA bila sudah punya PIN (ganti PIN)
  "pin": "5678",            // wajib — 4–6 digit angka
  "pin_confirmation": "5678"// wajib — konfirmasi `pin`
}
```
Gagal password →422 `errors.current_password`; gagal PIN lama →422 `errors.current_pin`; sukses → `{ "message": "…", "data": { "has_pin": true } }`. PIN disimpan **ter-hash** & tak pernah dikembalikan.

#### B. Penerapan pada Aksi Final (DIPERLUAS)
`POST /runs/{id}/approve` **dan** `POST /runs/{id}/mark-paid` kini menerima field opsional `pin`:
```jsonc
{ "pin": "5678" }  // hanya diperlukan bila approver sudah mengatur PIN
```
Bila approver punya PIN & `pin` salah/kosong →**422** `{ "message": "PIN keamanan salah atau belum diisi.", "errors": { "pin": [...] } }` (diaudit `SECURITY/WARNING`). Bila approver belum punya PIN → field diabaikan, aksi lanjut normal.

### 20.5. Tugas Frontend — ✅ SELESAI (Web Dashboard)

> Seluruh pengelolaan Fase 3 adalah kewenangan **HR/Finance (web)**. Mobile karyawan hanya menerima **notifikasi keamanan** perubahan rekening (opsional, sudah ada infrastruktur in-app + FCM).

**Web — `expenseflow-web/src/` (SELESAI):**
1. **`services/endpoints.ts`** — ditambahkan di `payrollApi`: `listAdjustments(params)`, `createAdjustment(payload)`, `approveAdjustment(id)`, `voidAdjustment(id, reason)`; `listBankAccounts(userId)`, `submitBankAccount(userId, payload)`, `verifyBankAccount(id)`, `rejectBankAccount(id, reason)`; `getPinStatus()`, `setPin(payload)`; `getRunLogs(runId)`. Ditambahkan field opsional `pin` pada `approveRun`/`markPaidRun`.
2. **Tipe (`types.ts`)** — ditambahkan `PayrollAdjustment`, `EmployeeBankAccount` (nomor termasking `*_masked`), `PayrollLog` + `{ ok, broken_at }`, `PinStatus`.
3. **`components/PayrollManagement.tsx`:**
   - **Sub-tab "Penyesuaian"** (baru): tabel adjustment (filter status `all`/`pending`/`approved`/`applied`/`voided`, search karyawan/nama), form buat (`CreateAdjustmentModal`: earning/deduction, `is_taxable`, alasan audit, retroaktif batch lampau), tombol Setujui (terkunci maker-checker untuk pembuat sendiri) & Batalkan (`VoidAdjustmentModal`: wajib isi alasan pembatalan).
   - **Sub-tab "Gaji Karyawan"** → sub-tab **"Rekening Bank"**: daftar rekening berstatus (`active`, `pending_verification`, `superseded`, `rejected`), form ajukan rekening baru terenkripsi, tombol Verifikasi & Tolak ber-maker-checker (dinonaktifkan jika pengaju sama dengan user login). Nomor rekening termasking aman.
   - **Detail Batch** → tab **"Jejak Audit & Integritas Hash-Chain"**: render tabel chained `logs` (urutan sequence, prev_hash, record_hash) + banner status integritas kriptografi SHA-256 (`integrity.ok` hijau atau peringatan merah jika broken).
   - **Dialog Approve & Mark-Paid**: dialog otorisasi PIN step-up (`ActionWithPinModal`), meminta PIN 4–6 digit jika user telah menyetel PIN, atau lanjut normal tanpa memblokir operasional (soft rollout).
   - **Bilah Header / Menu PIN**: tombol status **"PIN Keamanan"** (badge hijau Aktif / kuning Belum Diatur) + modal atur/ubah PIN (`SecurityPinModal`: verifikasi password login, validasi PIN lama, input PIN baru 4–6 digit + konfirmasi).

**Mobile — `expenseflow-mobile/` (Flutter):**
- Tidak ada form pengelolaan. **Opsional**: tampilkan notifikasi keamanan perubahan rekening (`bank_account_change_requested`/`_verified`/`_rejected`) di daftar notifikasi in-app; teks sudah disediakan backend pada `data.title`/`data.message`.

### 20.6. Checklist Penerimaan (Definition of Done Frontend Fase 3) — ✅ SELESAI
- [x] HR dapat membuat, menyetujui (bukan miliknya), & membatalkan adjustment; adjustment `approved` ikut terhitung otomatis saat kalkulasi batch.
- [x] HR dapat mengajukan rekening bank; pejabat berbeda memverifikasi/menolak; nomor selalu tampil termasking; rekening lama jadi `superseded`.
- [x] Approver dapat mengatur PIN; setelah PIN diset, dialog approve/mark-paid meminta PIN & menolak PIN salah (422); approver tanpa PIN tetap bisa approve.
- [x] Detail batch menampilkan jejak audit + indikator integritas hash-chain (`ok`/`broken_at`).
- [x] Semua nominal desimal (string) diparse & diformat rupiah `id-ID`.

---

## 21. Fase 4 (Disbursement) — Kontrak API Baru & Tugas Frontend

> **Konteks.** **Inti disbursement Fase 4 (ekspor berkas transfer bank + rekonsiliasi) sudah selesai & lulus uji** (`tests/Feature/PayrollPhase4Test.php` — 14 kasus hijau, 131 asersi; seluruh suite 476/477 hijau, 1 gagal hanyalah flake OCR Gemini yang tak terkait). Bagian ini adalah **kontrak API** modul disbursement + daftar **pekerjaan frontend (web React + mobile Flutter)** yang **belum** dikerjakan. Backend tidak menyediakan UI.
>
> **Cakupan sesi ini (keputusan produk "Inti disbursement dulu").** Hanya ekspor berkas bank + rekonsiliasi (`payroll_payment_batches` + `payroll_payment_items`). Bukti Potong 1721-A1/Coretax (**PDF 1721-A1 via dompdf + draf CSV/JSON siap-Coretax**, bukan XML resmi), ekspor GL, dan endpoint calculation-trace **dikerjakan pada sesi lanjutan → lihat [Bagian 22](#22-fase-4-lanjutan--pajak-1721-a1-ekspor-gl--calculation-trace--kontrak-api--tugas-frontend) (SELESAI backend).**
>
> **Prasyarat data.** Jalankan `php artisan migrate` (migrasi `100019` `payroll_payment_batches`, `100020` `payroll_payment_items`). Tidak ada seeder baru.
>
> **Keputusan produk "CSV + arsitektur driver".** 5 format bank tersedia melalui antarmuka driver `App\Services\Payroll\Bank\BankFileFormatter` (pabrik `BankFileFactory`): `generic_csv` (CSV generik penuh) + 4 varian CSV bank terdokumentasi (`bca_klikbisnis`, `mandiri_mcm`, `bri_cms`, `bni_direct`). Menambah layout proprietary bank nyata = tambah 1 kelas formatter + daftarkan di factory; controller & FE tidak berubah (FE cukup membaca daftar `bank_formats` dinamis dari API).

### 21.1. Prinsip Lintas Modul (penting untuk FE)

- Semua endpoint di grup `/api/v1/dashboard/payroll/*` (izin modul **Payroll**: `read` untuk lihat, `manage` untuk ubah); scope `company_id` (lintas-company diblokir **403** oleh `CompanyMiddleware`, bukan 404).
- Nominal uang = **string** desimal (`decimal:2`, mis. `"18000000.00"`) — parse `parseFloat`/`double.parse` sebelum format `id-ID`.
- **Nomor rekening tak pernah dikirim penuh ke FE.** Respons item hanya memuat `bank_account_no_masked` (mis. `••••••7890`). Nomor **penuh** hanya ada di dalam **berkas transfer** yang diunduh (endpoint download). Kolom `file_path` di batch **disembunyikan** dari JSON (`$hidden`) — FE tidak pernah melihat path privat.
- **Decoupling (WAJIB dipahami FE):** endpoint `reconcile` **BUKAN** `mark-paid`. Rekonsiliasi memperbarui status pembayaran per-karyawan & status batch **saja** — ia **tidak** mengubah status payslip/payroll dan **tidak** memajukan cicilan kasbon. Pelunasan batch (status payroll → `paid`, advance kasbon) tetap lewat `POST /runs/{id}/mark-paid` (Fase 3, §14.C). Urutan operasional yang disarankan: `approve` → (opsional `mark-paid`) → generate payment-batch → download → upload ke internet banking → `reconcile`.
- **Step-up PIN (soft).** `generate` menghasilkan berkas berisi PII rekening → bila pengguna sudah mengatur PIN (§20.4), body **wajib** menyertakan `pin` yang benar; bila belum, field diabaikan. PIN salah/kosong → **422** `{ "errors": { "pin": [...] } }`.
- Aksi tulis di-throttle: `generate` & `download` = `throttle:heavy`; `reconcile` = `throttle:actions`.

### 21.2. Kontrak API

#### A. Daftar Batch Pencairan sebuah Run
`GET /api/v1/dashboard/payroll/runs/{payroll}/payment-batches` (izin `read`) →
```jsonc
{
  "data": [ {
    "id": 3,
    "payroll_id": 8,
    "company_id": 1,
    "batch_reference": "PB-1-8-20260928T…-A1B2",
    "bank_format": "bca_klikbisnis",
    "total_records": 12,
    "total_amount": "84500000.00",
    "file_checksum": "9f2c…",          // SHA-256 berkas
    "status": "file_generated",         // prepared|file_generated|uploaded|partially_settled|settled|reconciled
    "generated_by": 4,
    "reconciled_by": null,
    "reconciled_at": null,
    "items_count": 12,                  // withCount('items')
    "generated_by_user": { "id": 4, "name": "Finance A" },  // relasi generatedBy
    "reconciled_by_user": null,         // relasi reconciledBy
    "created_at": "2026-09-28T…"
    // CATATAN: file_path TIDAK ada di JSON (disembunyikan)
  } ],
  "bank_formats": [                     // opsi untuk dropdown FE (dinamis dari driver)
    { "key": "generic_csv",    "label": "CSV Generik",                   "extension": "csv" },
    { "key": "bca_klikbisnis", "label": "BCA KlikBisnis (CSV)",          "extension": "csv" },
    { "key": "mandiri_mcm",    "label": "Mandiri Cash Management (CSV)",  "extension": "csv" },
    { "key": "bri_cms",        "label": "BRI CMS (CSV)",                  "extension": "csv" },
    { "key": "bni_direct",     "label": "BNI Direct (CSV)",              "extension": "csv" }
  ]
}
```
> FE harus **merender dropdown format dari `bank_formats`** (jangan hardcode) agar format bank baru otomatis muncul.

#### B. Generate Berkas Transfer Bank
`POST /api/v1/dashboard/payroll/runs/{payroll}/payment-batches` (izin `manage`, `throttle:heavy`) → **201**.
```jsonc
{
  "bank_format": "bca_klikbisnis", // wajib — salah satu key dari bank_formats
  "value_date": "2026-06-25",      // opsional (date) — tanggal efektif transfer; default = hari ini
  "pin": "5678"                    // wajib HANYA bila pembuat sudah mengatur PIN keamanan
}
```
**Aturan:**
- Status payroll **wajib** `approved` atau `paid`, selain itu → **422** (`"Berkas transfer hanya dapat dibuat untuk payroll yang sudah disetujui atau dibayar."`).
- Hanya slip dengan **neto > 0** dan **nomor rekening terisi** yang masuk berkas. Slip tanpa rekening dilewati & dihitung di `skipped_no_bank`. Bila tak ada satu pun slip valid → **422**.
- Berkas ditulis ke disk **privat** + checksum SHA-256; item DB dibuat `status=pending` (termasking).

Response `201`:
```jsonc
{
  "message": "Berkas transfer bank berhasil dibuat.",
  "skipped_no_bank": 1,   // jumlah slip dilewati karena tak ada nomor rekening
  "data": { /* batch + items[] (termasking) + generated_by_user */ }
}
```

#### C. Detail Batch
`GET /api/v1/dashboard/payroll/payment-batches/{id}` (izin `read`) → `{ "data": { …batch, "items": [ {
  "id", "payslip_id", "user_id", "bank_name", "bank_account_no_masked", "bank_account_holder",
  "amount": "7500000.00", "status": "pending", "bank_reference_no": null, "failure_reason": null,
  "settled_at": null, "user": { "id", "name" }
} ], "generated_by_user": {…}, "reconciled_by_user": {…}, "payroll": { "id", "period_month", "period_year", "status" } } }`.

#### D. Unduh Berkas Transfer
`GET /api/v1/dashboard/payroll/payment-batches/{id}/download` (izin `manage`, `throttle:heavy`) → **file stream** (mis. `text/csv`, `Content-Disposition: attachment`). Berisi **nomor rekening PENUH**. Setiap unduhan diaudit `SECURITY/WARNING` (`PAYROLL_BANK_FILE_DOWNLOADED`). Bila berkas belum ada → 404; tanpa izin `manage` → 403.
> **Catatan FE:** ini bukan JSON — picu unduhan biner (mis. buka di tab baru dengan header Authorization, atau `blob` download). Jangan mencoba mem-parse sebagai JSON.

#### E. Rekonsiliasi Hasil Transfer
`POST /api/v1/dashboard/payroll/payment-batches/{id}/reconcile` (izin `manage`, `throttle:actions`).
```jsonc
{
  "results": [
    { "payment_item_id": 21, "status": "success", "bank_reference_no": "TRX-001" },
    { "payment_item_id": 22, "status": "failed",  "failure_reason": "Rekening tutup" },
    { "payment_item_id": 23, "status": "rejected_by_bank", "failure_reason": "Nama tidak cocok" }
  ]
}
```
**Aturan:**
- Status batch **wajib** `file_generated`/`uploaded`/`partially_settled`, selain itu → **422** (batch belum siap atau sudah selesai).
- Tiap `payment_item_id` **wajib milik batch ini** (divalidasi `exists` ter-scope); item dari batch lain → **422**.
- `status` per item: `success` | `failed` | `rejected_by_bank`. `success` mengisi `bank_reference_no` + `settled_at`; selain itu mengisi `failure_reason` & mengosongkan `settled_at`.
- Bila **semua** item sudah berstatus akhir (tak ada `pending`) → batch `reconciled` (+`reconciled_by`/`reconciled_at`); bila masih ada `pending` → `partially_settled` (boleh direkonsiliasi bertahap).
- **Notifikasi** (`payroll_disbursed`, in-app + FCM) dikirim hanya untuk item yang **baru** menjadi `success` (anti-notif-ganda pada rekonsiliasi berulang).

Response: `{ "message": "Rekonsiliasi pembayaran tersimpan.", "data": { …batch, "items": [...], "reconciled_by_user": {…} } }`.

### 21.3. Tugas Frontend (BELUM dikerjakan)

> Seluruh pengelolaan disbursement adalah kewenangan **Finance (web)**. Mobile karyawan hanya **menerima notifikasi** `payroll_disbursed` saat gajinya sukses ditransfer.

**Web — `expenseflow-web/src/`:**
1. **`services/endpoints.ts`** — tambah di `payrollApi`: `listPaymentBatches(runId)`, `generatePaymentBatch(runId, { bank_format, value_date?, pin? })`, `getPaymentBatch(batchId)`, `reconcilePaymentBatch(batchId, { results })`, dan **unduhan berkas** `downloadPaymentBatchFile(batchId)` (tangani sebagai **blob/stream**, bukan JSON — sertakan header Authorization).
2. **Tipe** — `PaymentBatch` (mis. `id, batch_reference, bank_format, total_records, total_amount, file_checksum, status, items_count, generated_by_user, reconciled_by_user`; **tanpa `file_path`**), `PaymentItem` (`bank_account_no_masked`, `amount`, `status`, `bank_reference_no`, `failure_reason`, `settled_at`, `user`), `BankFormatOption` (`key, label, extension`).
3. **`components/PayrollManagement.tsx` — Detail Batch (run yang sudah `approved`/`paid`):** tambah panel **"Pencairan Bank"**:
   - **Daftar batch** (badge status: `file_generated`/`partially_settled`/`reconciled`, `total_records`, `total_amount`, `file_checksum` ringkas).
   - **Tombol "Buat Berkas Transfer"** → dialog: dropdown **Format Bank** (render dari `bank_formats` API), input **Tanggal Efektif** (opsional), input **PIN** (tampil hanya bila `getPinStatus().has_pin` true). Tampilkan `skipped_no_bank` sebagai peringatan bila > 0 ("N karyawan dilewati karena belum ada rekening").
   - **Tombol "Unduh Berkas"** per batch → picu unduhan blob. Beri catatan keamanan ("berisi nomor rekening penuh").
   - **Panel "Rekonsiliasi"**: tabel item (nama, rekening termasking, nominal, status), tiap baris dapat diset `success`/`failed`/`rejected_by_bank` + input `bank_reference_no`/`failure_reason`; submit `results[]`. Dukung rekonsiliasi bertahap (partial). Setelah `reconciled`, kunci form.
   - **Pengingat UX:** tampilkan hint bahwa rekonsiliasi **tidak** menandai payroll lunas — tombol **Mark-Paid** (Fase 3) tetap terpisah.
4. **Dropdown format bank** wajib dari `bank_formats` (dinamis) — jangan hardcode 5 nilai.

**Mobile — `expenseflow-mobile/` (Flutter):**
- Tidak ada form pengelolaan. **Opsional:** tampilkan notifikasi `payroll_disbursed` di daftar notifikasi in-app (teks siap di `data.title`/`data.message`: *"Gaji Anda sebesar Rp … telah ditransfer ke rekening ••••1234."*). Push FCM sudah dikirim backend bila `fcm_token` ada.

### 21.4. Catatan & Batasan (yang belum dikerjakan backend)
- **1721-A1 / Coretax, ekspor GL, dan endpoint calculation-trace** kini **SELESAI (backend)** — lihat **[Bagian 22](#22-fase-4-lanjutan--pajak-1721-a1-ekspor-gl--calculation-trace--kontrak-api--tugas-frontend)** untuk kontrak API & tugas frontend. Fidelity pajak: **PDF 1721-A1 (dompdf) + draf CSV/JSON siap-Coretax**, bukan XML resmi e-Bupot.
- **Snapshot rekening di `payslips`/`users` masih plaintext** (gap pra-ada, di luar cakupan Fase 4). Disbursement hanya menambah masking di `payroll_payment_items`; enkripsi kolom snapshot slip adalah pekerjaan hardening terpisah.
- **Berkas bank = varian CSV terdokumentasi**, belum layout proprietary biner/positional bank sungguhan. Antarmuka driver sudah siap untuk itu (tambah formatter baru tanpa ubah controller/FE).

### 21.5. Checklist Penerimaan (Definition of Done Frontend Fase 4)
- [x] Finance dapat membuat berkas transfer dari run `approved`/`paid`, memilih format bank (dropdown dinamis), & (bila punya PIN) memasukkan PIN.
- [x] Peringatan `skipped_no_bank` tampil bila ada karyawan tanpa rekening.
- [x] Berkas dapat diunduh (blob) dengan aman; nomor rekening penuh hanya di berkas, layar selalu termasking.
- [x] Rekonsiliasi per-item (success/failed/rejected) tersimpan; status batch berubah `partially_settled`→`reconciled`; rekonsiliasi bertahap didukung.
- [x] UI menegaskan rekonsiliasi ≠ mark-paid (pelunasan payroll tetap tombol terpisah).
- [x] Semua nominal desimal (string) diparse & diformat rupiah `id-ID`.
- [x] (Opsional mobile) Notifikasi `payroll_disbursed` tampil di daftar notifikasi karyawan.

---

## 22. Fase 4 (Lanjutan) — Pajak 1721-A1, Ekspor GL & Calculation-Trace — Kontrak API & Tugas Frontend

> **Konteks.** Tiga item Fase 4 yang sebelumnya ditunda kini **selesai & lulus uji di backend** (`tests/Feature/PayrollPhase4TaxGlTraceTest.php` — 16 kasus hijau, 130 asersi). Bagian ini adalah **kontrak API** ketiga modul + **pekerjaan frontend (web React)** yang **belum** dikerjakan. Backend tidak menyediakan UI. Mobile: **tidak ada** tugas.
>
> **Cakupan.** (A) **Ekspor Jurnal Akuntansi / General Ledger** per run; (B) **Bukti Potong PPh 21 Formulir 1721-A1 + draf ekspor Coretax**; (C) **Endpoint Calculation-Trace** yang memaparkan `payslip_calculation_steps`.
>
> **Fidelity pajak (disepakati).** Keluaran 1721-A1 = **PDF (dompdf) + draf CSV/JSON siap-Coretax**. Ini **DRAF internal — BUKAN** Bukti Potong pajak resmi DJP dan **BUKAN** berkas e-Bupot **XML**. Nilai wajib diverifikasi sebelum dilaporkan. FE **wajib** menampilkan label draf ini di layar & tombol ekspor.
>
> **Keputusan produk.** (1) **Pemetaan akun GL = tabel per-perusahaan (editable)** — himpunan pos jurnal kanonik + kode/nama **default** ada di `config/payroll_gl.php`; override per-perusahaan disimpan di tabel `payroll_gl_accounts` (migrasi `100021`). (2) **Dokumen 1721-A1 & jurnal GL = on-demand, tanpa tabel dokumen** — dirangkai saat diminta dari data yang sudah immutable pasca-approve; tiap pembuatan berkas diaudit.
>
> **Prasyarat data.** Jalankan `php artisan migrate` (satu migrasi baru `100021_create_payroll_gl_accounts_table`). Tidak ada seeder baru — default akun berasal dari config, angka pajak dari `employee_tax_period_totals` yang sudah dikonsolidasi PayrollCalculator.

### 22.1. Prinsip Lintas Modul (penting untuk FE)

- Semua endpoint di grup `/api/v1/dashboard/payroll/*` (izin modul **Payroll**: `read` untuk lihat/JSON, `manage` untuk simpan pemetaan & unduh/ekspor berkas). Scope `company_id` dijaga: rute ber-route-model (`{payroll}`, `{payslip}`) lintas-company diblokir **403** oleh `CompanyMiddleware`; rute pajak ber-`{userId}` (skalar) lintas-company ditolak **404** oleh controller.
- Nominal uang = **string** desimal (`decimal:2`, mis. `"850000.00"`) — parse `parseFloat` sebelum format `id-ID`.
- **NPWP tak pernah dikirim penuh ke FE.** Respons JSON hanya memuat `npwp_masked` (mis. `•••••••••••2345`); field `npwp` (penuh) **dihapus** dari semua payload JSON. NPWP **penuh** hanya muncul di dalam **berkas** yang diunduh (PDF 1721-A1 & ekspor CSV/JSON), yang **wajib** izin `manage` dan **diaudit** `SECURITY/WARNING`.
- **Jurnal GL DIJAMIN seimbang** (`total_debit == total_credit`, field `balanced: true`) secara aljabar: `Debit = Σ pendapatan slip + BPJS perusahaan = total_gross + total_bpjs_company`, `Credit = Σ potongan + BPJS perusahaan + gaji bersih = total_deduction + total_bpjs_company + total_net`, dan `total_net = total_gross − total_deduction`. FE cukup menampilkan `balanced` sebagai lencana (harusnya selalu hijau).
- Aksi tulis/ekspor di-throttle: `saveAccounts`/`resetAccounts` = `throttle:actions`; `gl-export`, `1721a1/pdf`, `1721a1/export` = `throttle:heavy`.

### 22.2. Kontrak API

#### A. Pemetaan Akun GL — Baca
`GET /api/v1/dashboard/payroll/gl-accounts` (izin `read`) →
```jsonc
{
  "data": [
    {
      "key": "expense_salary",          // key pos jurnal kanonik (tetap)
      "label": "Beban Gaji Pokok & Tunjangan",
      "side": "debit",                   // debit | credit
      "account_code": "5100",            // efektif (override bila ada, else default)
      "account_name": "Beban Gaji",
      "default_code": "5100",            // default config (untuk tombol "reset ke default")
      "default_name": "Beban Gaji",
      "is_overridden": false             // true bila perusahaan sudah menimpa
    }
    // … 11 pos: 5 debit (expense_salary/overtime/reimbursement/adjustment/bpjs_company)
    //           + 6 kredit (payable_pph21/bpjs_employee/bpjs_company, receivable_loan, payable_other, payable_net)
  ]
}
```

#### B. Pemetaan Akun GL — Simpan (upsert)
`PUT /api/v1/dashboard/payroll/gl-accounts` (izin `manage`, `throttle:actions`) →
```jsonc
{
  "accounts": [
    { "key": "payable_net", "account_code": "1102", "account_name": "Bank BCA Operasional" }
    // kirim hanya pos yang diubah; key WAJIB salah satu key kanonik (else 422 accounts.N.key)
  ]
}
```
Response `200`: `{ "message": "Pemetaan akun jurnal tersimpan.", "data": [ …seperti A ] }`. Divalidasi: `account_code` ≤ 40 char, `account_name` ≤ 150 char. Teraudit `PAYROLL_GL_ACCOUNTS_SAVED` (FINANCE/INFO).

#### C. Pemetaan Akun GL — Reset ke Default
`POST /api/v1/dashboard/payroll/gl-accounts/reset` (izin `manage`, `throttle:actions`) → hapus **semua** override perusahaan (kembali ke default config). Response `200`: `{ "message": "Pemetaan akun jurnal dikembalikan ke default.", "data": [ … ] }`. Teraudit `PAYROLL_GL_ACCOUNTS_RESET`.

#### D. Pratinjau Jurnal sebuah Run
`GET /api/v1/dashboard/payroll/runs/{payroll}/gl-preview` (izin `read`) →
```jsonc
{
  "data": {
    "lines": [
      { "key": "expense_salary", "account_code": "5100", "account_name": "Beban Gaji",
        "description": "Beban Gaji Pokok & Tunjangan", "debit": 18000000, "credit": 0 },
      { "key": "payable_pph21", "account_code": "2101", "account_name": "Utang PPh 21",
        "description": "Utang PPh 21 (disetor ke negara)", "debit": 0, "credit": 250000 }
      // … baris nol dibuang
    ],
    "total_debit": 19500000,
    "total_credit": 19500000,
    "balanced": true,
    "payroll": { "id": 8, "period_month": 6, "period_year": 2026, "period_label": "Juni 2026",
                 "status": "approved", "total_gross": "18000000.00", "total_deduction": "…",
                 "total_bpjs_company": "…", "total_net": "…" }
  }
}
```
> Nilai `debit`/`credit` per baris berupa **number** (hasil `round(…,2)`), bukan string; `total_*` juga number. Nominal ringkasan `payroll.*` tetap string `decimal:2`.

#### E. Ekspor Jurnal (unduhan)
`GET /api/v1/dashboard/payroll/runs/{payroll}/gl-export?format=csv|json` (izin `manage`, `throttle:heavy`) → **file stream**. `format=csv` (default) → `text/csv`, header `Periode,Kode Akun,Nama Akun,Keterangan,Debit,Kredit` + baris + `TOTAL` (nama berkas `jurnal-gaji-{companyId}-{YYYY-MM}.csv`). `format=json` → `application/json` (jurnal + meta payroll). Format lain → **422**. Teraudit `PAYROLL_GL_EXPORTED` (FINANCE/INFO, memuat `balanced`).
> **Catatan FE:** ini bukan JSON untuk dirender — picu unduhan blob (sertakan header Authorization).

#### F. Daftar 1721-A1 (ringkas, termasking)
`GET /api/v1/dashboard/payroll/tax/1721a1?tax_year=2026` (izin `read`) →
```jsonc
{
  "tax_year": 2026,
  "data": [
    {
      "user_id": 12, "employee_name": "Budi Karyawan", "employee_code": "EMP-001",
      "position_name": "Staff", "npwp_masked": "•••••••••••2345", "has_npwp": true,
      "ptkp_status": "TK/0", "tax_year": 2026, "months_count": 12,
      "bruto": 120000000, "bruto_regular": 120000000, "bruto_irregular": 0, "bruto_benefit": 0,
      "biaya_jabatan": 6000000, "iuran_pensiun": 3600000, "neto": 110400000,
      "ptkp": 54000000, "pkp": 56400000, "pph21_terutang": 3960000,
      "pph21_dipotong": 3960000, "selisih": 0
      // CATATAN: TIDAK ada field `npwp` (penuh) — hanya `npwp_masked`
    }
  ]
}
```
`tax_year` default = tahun berjalan bila diabaikan/di luar 2000–2100.

#### G. Detail 1721-A1 satu Karyawan (termasking)
`GET /api/v1/dashboard/payroll/tax/1721a1/{userId}?tax_year=2026` (izin `read`) → `{ "data": { …satu baris seperti F } }`. Karyawan bukan milik perusahaan pemanggil / tak punya masa pajak di tahun tsb → **404**.

#### H. Unduh PDF 1721-A1
`GET /api/v1/dashboard/payroll/tax/1721a1/{userId}/pdf?tax_year=2026` (izin `manage`, `throttle:heavy`) → **PDF stream** (`application/pdf`, nama `1721A1-{kode}-{tahun}.pdf`). Memuat **NPWP penuh** + label **"DRAF — bukan Bukti Potong resmi / bukan e-Bupot XML"**. Teraudit `PAYROLL_1721A1_DOWNLOADED` (**SECURITY/WARNING**). Data tak ada → 404; tanpa `manage` → 403.

#### I. Ekspor Draf Coretax
`GET /api/v1/dashboard/payroll/tax/1721a1/export?tax_year=2026&format=csv|json` (izin `manage`, `throttle:heavy`) → **file stream** draf siap-impor. `csv` → satu baris per karyawan + `TOTAL` (kolom: Tahun Pajak, NPWP, Nama, Kode/NIK, Status PTKP, Jumlah Masa, Bruto, Biaya Jabatan, Iuran Pensiun, Neto, PTKP, PKP, PPh21 Terutang, PPh21 Dipotong, Selisih). `json` → objek berisi `catatan` (label draf) + `data[]`. **Memuat NPWP penuh.** Format lain → **422**. Teraudit `PAYROLL_1721A1_EXPORTED` (**SECURITY/WARNING**).

#### J. Calculation-Trace sebuah Slip
`GET /api/v1/dashboard/payroll/payslips/{payslip}/calculation-trace` (izin `read`) →
```jsonc
{
  "data": {
    "payslip": { "id": 55, "payroll_id": 8, "employee_name": "Budi Karyawan", "employee_code": "EMP-001",
                 "period_month": 6, "period_year": 2026, "ptkp_status": "TK/0",
                 "gross": "10000000.00", "taxable_income": "…", "pph21": "…",
                 "total_deduction": "…", "net": "…", "status": "approved" },
    "steps": [
      { "step_code": "GROSS", "step_sequence": 1, "formula_version": "…",
        "input_payload": { /* konteks input langkah (array) */ },
        "raw_result": "10000000.00", "rounding_diff": "0.00", "final_result": "10000000.00",
        "rule_reference": "…" }
      // … urut menaik berdasarkan step_sequence; minimal ada GROSS & NETT
    ],
    "earnings":   [ { "label", "code", "amount", "is_taxable", "is_statutory", "source", "notes" } ],
    "deductions": [ { "label", "code", "amount", "is_taxable", "is_statutory", "source", "notes" } ]
  }
}
```
Read-only. Slip lintas-perusahaan → **403** (`CompanyMiddleware`). NPWP tak pernah muncul (slip hanya menyimpan termasking).

### 22.3. Tugas Frontend (BELUM dikerjakan)

> Seluruh modul ini kewenangan **Finance/HRD (web)**. **Mobile: tidak ada tugas.**

**Web — `expenseflow-web/src/`:**
1. **`services/endpoints.ts`** — tambah di `payrollApi`:
   - GL: `getGlAccounts()`, `saveGlAccounts({ accounts })`, `resetGlAccounts()`, `previewGl(runId)`, dan **unduhan** `exportGl(runId, format)` (blob).
   - Pajak: `list1721a1(taxYear)`, `show1721a1(userId, taxYear)`, **unduhan** `download1721a1Pdf(userId, taxYear)` (blob) & `export1721a1(taxYear, format)` (blob).
   - Trace: `getCalculationTrace(payslipId)`.
   - Semua unduhan (`exportGl`, `download1721a1Pdf`, `export1721a1`) ditangani **blob/stream** + header Authorization, **bukan** JSON.
2. **Tipe** — `GlAccount` (`key, label, side, account_code, account_name, default_code, default_name, is_overridden`), `GlJournal` (`lines[], total_debit, total_credit, balanced, payroll`), `GlLine`, `Tax1721A1Row` (**tanpa `npwp`; ada `npwp_masked`**), `CalculationStep`, `TraceItem`.
3. **`components/PayrollManagement.tsx`:**
   - **Panel "Pemetaan Akun Jurnal (GL)"** (setelan perusahaan): tabel editable 11 pos (label, sisi, input kode & nama akun), lencana `is_overridden`, tombol **Simpan** (`saveGlAccounts`) & **Reset ke Default** (`resetGlAccounts`). Validasi panjang kode/nama.
   - **Pada Detail Run (`approved`/`paid`) — "Jurnal Akuntansi":** tombol **Pratinjau Jurnal** (`previewGl` → tabel debit/kredit + lencana **Seimbang** dari `balanced`) & **Unduh Jurnal** (dropdown CSV/JSON → blob).
   - **Menu "Bukti Potong PPh 21 (1721-A1)"** (pilih tahun pajak): tabel dari `list1721a1` (nama, NPWP **termasking**, PTKP, jumlah masa, bruto, PPh21 dipotong, selisih). Per baris: **Unduh PDF** & tombol ekspor draf Coretax (CSV/JSON) di tingkat tahun. **Wajib tampilkan banner "DRAF — bukan Bukti Potong resmi / bukan e-Bupot XML".**
   - **Drawer "Jejak Perhitungan"** dari detail slip: render `steps[]` (urut, tampilkan `input_payload`→`raw_result`→`rounding_diff`→`final_result`, `rule_reference`) + tabel `earnings`/`deductions`.
4. **Keamanan UI:** jangan pernah menampilkan/menyimpan NPWP penuh dari respons JSON (tidak ada); NPWP penuh hanya terbawa di berkas unduhan. Tombol unduh/ekspor hanya untuk pengguna ber-izin `manage`.

**Mobile — `expenseflow-mobile/` (Flutter):** tidak ada. (Slip gaji & PDF pribadi karyawan sudah ada di endpoint `/employee/payslips`.)

### 22.4. Catatan & Batasan
- **Draf, bukan resmi (khusus 1721-A1/Coretax).** 1721-A1 PDF & ekspor Coretax adalah draf internal untuk verifikasi/impor — **bukan** Bukti Potong resmi DJP. **Ekspor e-Bupot XML sendiri TIDAK LAGI berstatus draf** (Fase 6): setiap dokumen **wajib lolos validasi skema XSD** sebelum dikirim (`GET /tax/ebupot/export`; gagal skema → **422**, berkas tidak terkirim), dan `resmi="true"` hanya bila XSD **resmi DJP** terpasang di server (cek `GET /tax/ebupot/schema`). → [Bagian 24](#24-fase-6-enterprise-lanjutan--kontrak-api--tugas-frontend).
- **NPWP pemberi kerja** kini tersedia (kolom `companies.npwp` terenkripsi, dikelola via `GET/PUT /company-tax-profile`) → ditampilkan **penuh** di PDF 1721-A1 & berkas e-Bupot XML (tervalidasi XSD), **termasking** di JSON. → [Bagian 23](#23-enam-fitur-lanjutan-pasca-mvp--kontrak-api--tugas-frontend).
- **NPWP karyawan snapshot.** `employee_tax_profiles.npwp` terenkripsi; masking konsisten di seluruh JSON. Angka pajak dirangkai dari `employee_tax_period_totals` (sumber tunggal) + iuran pensiun dari `payslip_items` (JHT+JP karyawan).
- **GL alokasi cost center** kini tersedia via `?group_by=none|division|branch` (default `none` = agregat per run seperti semula; `division`/`branch` memecah jurnal per divisi/kantor presensi, tiap segmen seimbang & Σ segmen == jurnal flat). → [Bagian 23](#23-enam-fitur-lanjutan-pasca-mvp--kontrak-api--tugas-frontend).

### 22.5. Checklist Penerimaan (Definition of Done Frontend)
- [x] Finance dapat melihat & menyunting pemetaan akun GL (11 pos), menyimpan override, & mereset ke default; lencana `is_overridden` akurat.
- [x] Pratinjau jurnal menampilkan baris debit/kredit + lencana **Seimbang** (`balanced`) yang selalu hijau; unduhan CSV/JSON berhasil (blob).
- [x] Daftar/detail 1721-A1 per tahun tampil dengan **NPWP termasking** (tak pernah penuh di layar); banner **DRAF** selalu tampil.
- [x] Unduh **PDF 1721-A1** & ekspor **draf Coretax** (CSV/JSON) berhasil sebagai blob; hanya untuk pengguna ber-izin `manage`.
- [x] Drawer jejak perhitungan menampilkan langkah **urut** + rincian earnings/deductions.
- [x] Semua nominal desimal (string) diparse & diformat rupiah `id-ID`; nilai jurnal (number) diformat konsisten.

---

## 23. Enam Fitur Lanjutan (Pasca-MVP) — Kontrak API & Tugas Frontend

> **Konteks.** Enam fitur yang sebelumnya **sengaja ditunda untuk MVP** kini **selesai & lulus uji di backend**. Bagian ini adalah **kontrak API** keenamnya + **pekerjaan frontend (web React)** yang **belum** dikerjakan. Backend tidak menyediakan UI. **Mobile: tidak ada tugas** (opsional: notifikasi FCM saat batch THR `paid`, mengikuti mekanisme Fase 5 yang sudah ada).
>
> **Cakupan & berkas uji (semua hijau).**
> 1. **Run THR terpisah** — `tests/Feature/PayrollThrRunTest.php` (6 uji).
> 2. **Grup Payroll & scoping MVP** — `tests/Feature/PayrollGroupScopingTest.php`.
> 3. **NPWP Perusahaan terenkripsi** — `tests/Feature/CompanyNpwpTest.php`.
> 4. **Rincian BPJS di PDF slip** — `tests/Feature/PayslipBpjsBlockTest.php`.
> 5. **e-Bupot 21/26 XML** — dahulu DRAF; **kini tervalidasi skema XSD** di [Bagian 24](#24-fase-6-enterprise-lanjutan--kontrak-api--tugas-frontend) (`EbupotDraftXmlBuilder` → `EbupotXmlBuilder`, uji `tests/Feature/EbupotXsdXmlTest.php` menggantikan `EbupotDraftXmlTest.php`).
> 6. **Alokasi Cost Center GL** — `tests/Feature/PayrollGlGroupedTest.php`.
>
> **Prasyarat data.** Jalankan `php artisan migrate` (enam migrasi baru `2026_09_28_100022`–`100027`, forward-only & non-destruktif). Tidak ada seeder baru. Baris `payrolls` & `employee_tax_period_totals` lama otomatis di-backfill (`run_type='regular'`, `payroll_group_id=NULL`, `regular_pph21=total_pph21_withheld`) sehingga scope & angka lama **identik** dengan sebelumnya.
>
> **Keputusan produk yang dikunci.** (1) THR = **run terpisah** (bukan komponen di run reguler). (2) Keanggotaan grup = kolom `users.payroll_group_id` (bukan tabel `employee_assignments`). (3) NPWP perusahaan = pola terenkripsi + masking seperti NPWP karyawan. (4) Rincian BPJS di PDF = **turunan read-only** dari calculation-steps, tanpa perubahan skema. (5) e-Bupot XML = dahulu **DRAF non-resmi** (`resmi="false"`, belum XSD DJP) — **disempurnakan di Fase 6** menjadi **wajib tervalidasi XSD** (§24.D). (6) GL cost center = snapshot `division_id` **dan** `attendance_setting_id` di payslip; `group_by=none` **byte-identik** dengan perilaku lama.

### 23.1. Prinsip Lintas Modul (penting untuk FE)

- Semua endpoint di grup `/api/v1/dashboard/payroll/*` (izin modul **Payroll**: `read` untuk lihat/JSON, `manage` untuk tulis/unduh berkas). Scope `company_id` dijaga: rute ber-route-model (`{payroll}`, `{payslip}`, `{group}`) lintas-company diblokir **403** oleh `CompanyMiddleware` (fallback controller **404** utk `{group}`); rute pajak ber-`{userId}` (skalar) lintas-company ditolak **404**.
- Nominal uang = **string** desimal (`decimal:2`) — kecuali nilai jurnal GL per baris & `total_*`/`grand_total_*` yang berupa **number** (hasil `round(…,2)`), konsisten dengan [Bagian 22](#22-fase-4-lanjutan--pajak-1721-a1-ekspor-gl--calculation-trace--kontrak-api--tugas-frontend).
- **NPWP tak pernah dikirim penuh ke FE.** Respons JSON hanya memuat `npwp_masked`; nilai penuh **hanya** di berkas ter-stream (PDF 1721-A1 & **e-Bupot XML**), yang **wajib** `manage` dan **diaudit** `SECURITY/WARNING`.
- Aksi tulis/ekspor di-throttle: CRUD grup & `company-tax-profile` PUT = `throttle:actions`; `tax/ebupot/export` = `throttle:heavy`; `gl-export?group_by=…` = `throttle:heavy`.

### 23.2. Kontrak API

#### A. Grup Payroll — CRUD (grouping + scoping MVP)
`GET /api/v1/dashboard/payroll/groups` (izin `read`) →
```jsonc
{
  "data": [
    { "id": 3, "company_id": 1, "name": "Karyawan Tetap", "code": "TETAP",
      "description": null, "is_active": true, "users_count": 24,
      "created_at": "…", "updated_at": "…" }
  ]
}
```
`POST /api/v1/dashboard/payroll/groups` (izin `manage`, `throttle:actions`) — body `{ name (wajib, ≤120, unik per-company), code (opsional, ≤20, unik per-company, di-UPPERCASE), description (opsional, ≤2000), is_active (bool) }`. Response **201** `{ "message": "Grup payroll berhasil dibuat.", "data": { …grup } }`. Nama/kode duplikat → **422** (validasi). Audit `PAYROLL_GROUP_CREATED`.
`PUT /api/v1/dashboard/payroll/groups/{group}` (izin `manage`, `throttle:actions`) — sama, unik meng-`ignore` diri sendiri; lintas-company → **404**. Audit `PAYROLL_GROUP_UPDATED`.
`DELETE /api/v1/dashboard/payroll/groups/{group}` (izin `manage`, `throttle:actions`) — **422** bila grup masih beranggota (`users.payroll_group_id`) atau pernah dipakai batch (`payrolls.payroll_group_id`) → sarankan **nonaktifkan** (`is_active=false`) alih-alih hapus. Sukses → **200** `{ "message": "…" }`. Audit `PAYROLL_GROUP_DELETED` (WARNING).

> **Scoping run:** kirim `payroll_group_id` opsional saat membuat run (lihat C). Batch ber-grup **hanya menghitung** karyawan dengan `users.payroll_group_id` sama (via `eligibleUsers()`); tanpa grup (NULL) → seluruh karyawan perusahaan (perilaku lama, tak berubah).

#### B. Profil Pajak Perusahaan — NPWP Pemberi Kerja (terenkripsi)
`GET /api/v1/dashboard/payroll/company-tax-profile` (izin `read`) →
```jsonc
{ "data": { "name": "PT Maju Bersama", "address": "Jl. …",
            "has_npwp": true, "npwp_masked": "•••••••••••2345" } }
```
`PUT /api/v1/dashboard/payroll/company-tax-profile` (izin `manage`, `throttle:actions`) — body `{ "npwp": "09.254.294.3-407.000" | "" | null }`. Aturan: field **absen** → tidak mengubah; **`""`** → menghapus NPWP; string berisi → dinormalisasi ke digit, **wajib 15 (lama) / 16 (baru) digit**, else **422**. Response **200** `{ "message": "Profil pajak perusahaan berhasil disimpan.", "data": { …termasking } }`. Audit `COMPANY_TAX_PROFILE_UPDATED` (FINANCE/INFO). **JSON hanya `npwp_masked`** — nilai penuh hanya muncul di PDF 1721-A1 & e-Bupot XML.

#### C. Run THR Terpisah (reuse endpoint run, tanpa rute baru)
`POST /api/v1/dashboard/payroll/runs` (izin `manage`, `throttle:actions`) — tambah field opsional pada body run:
```jsonc
{
  "period_month": 6, "period_year": 2026,
  "run_type": "thr",                 // "regular" (default) | "thr"
  "payroll_group_id": 3,             // opsional; batasi ke anggota grup
  "attendance_setting_id": null      // opsional; batasi ke cabang
  // is_year_end DIPAKSA false bila run_type=thr
}
```
Response **201** `{ "data": { …payroll, "run_type": "thr", "payroll_group_id": 3 } }`. Duplikat **ruang-lingkup sama** (jenis+grup+cabang+periode) → **422** `"Batch payroll untuk periode, jenis, grup & cabang tersebut sudah ada."` — namun **run reguler + run THR periode sama SAMA-SAMA boleh** (ruang-lingkup jenis berbeda).
Lalu `POST /api/v1/dashboard/payroll/runs/{payroll}/calculate` (izin `manage`, `throttle:heavy`) — controller otomatis mem-branch ke `ThrCalculatorService` saat `run_type='thr'`. **Slip THR:** `basic_salary=0`, `bpjs_*_total=0`, tepat **1 pendapatan `source='thr'`** (+ potongan `source='tax'` bila PPh21 terutang), `gross=taxable_income=THR`, `net=THR−PPh21`; calculation-steps `THR` + `PPH21_THR`. Alur `submit`→`approve`→`mark-paid` **identik** run reguler (maker-checker, immutability). Slip THR di calculation-trace ([Bagian 22.J](#j-calculation-trace-sebuah-slip)) tampil apa adanya.

> **Aturan nominal (Permenaker 6/2016):** masa kerja ≥12 bln → 1× upah sebulan; 1–11 bln → pro-rata `(masa/12)×upah`; <1 bln → tak berhak (tak dibuatkan slip). Masa kerja dihitung dari `users.joined_date` (NULL → diasumsikan ≥12 bln). **PPh21 THR (PMK 168/2023, TER marginal):** `max(0, TER(bruto_reguler+THR) − PPh21_reguler)`; bila run reguler bulan itu belum ada → fallback `TER(THR)`. Selisih tahunan otomatis dinormalkan pada rekonsiliasi Pasal-17 run reguler Desember.

#### D. e-Bupot 21/26 — Ekspor XML *(status draf ini sudah DIGANTI — lihat [§24.D](#d-e-bupot-2126--ekspor-xml-tervalidasi-skema-xsd))*
~~`GET …/tax/ebupot/export` → nama berkas `ebupot-draf-{companyId}-{taxYear}.xml`, root `<eBupotDraf resmi="false">`~~ — **SUDAH TIDAK BERLAKU sejak Fase 6.** Endpoint & semantiknya kini: root `<eBupot21>`, nama berkas `ebupot-{companyId}-{taxYear}.xml` (XSD resmi) atau `ebupot-internal-{companyId}-{taxYear}.xml` (XSD internal), dan dokumen **wajib lolos XSD** (gagal → **422**). Kontrak berlaku → [§24.D](#d-e-bupot-2126--ekspor-xml-tervalidasi-skema-xsd).
- ~~Root `<eBupotDraf resmi="false">` + komentar "DRAF — bukan e-Bupot resmi, belum tervalidasi XSD DJP."~~ → **Fase 6: root `<eBupot21 resmi="true|false" skema="…" tahunPajak="…">`, wajib lolos XSD**; blok **pemberi kerja** (NPWP dari fitur B) + satu `<BuktiPotong>` per karyawan dari `AnnualTaxAggregator` (NPWP penuh, bruto, bruto_irregular, PPh21, PTKP, biaya jabatan, iuran pensiun).
- `tax_year` default = tahun berjalan bila diabaikan/di luar 2000–2100. **Memuat NPWP penuh** (pemberi kerja & karyawan). Audit `PAYROLL_EBUPOT_EXPORTED` (**SECURITY/WARNING**).
> **Catatan FE (diperbarui Fase 6):** picu **unduhan blob** (sertakan header Authorization) — bukan JSON untuk dirender. Label statis "DRAF" **tidak lagi tepat**: baca `GET /tax/ebupot/schema` dan tampilkan status **dinamis** ("Tervalidasi XSD resmi DJP" vs "Tervalidasi XSD internal — pasang XSD resmi DJP agar ditandai resmi"). Lihat [§24.D](#d-e-bupot-2126--ekspor-xml-tervalidasi-skema-xsd).

#### E. Alokasi Cost Center — GL `?group_by=none|division|branch`
`GET /api/v1/dashboard/payroll/runs/{payroll}/gl-preview?group_by=none|division|branch` (izin `read`).
- `none` (default) → jurnal **flat** (envelope **byte-identik** [Bagian 22.D](#d-pratinjau-jurnal-sebuah-run)).
- `division` / `branch` → jurnal **berdimensi**:
```jsonc
{
  "data": {
    "group_by": "division", "dimension": "division",
    "segments": [
      { "key": "div-5", "segment_id": 5, "label": "Teknologi (TECH)",
        "lines": [ { "key": "expense_salary", "account_code": "5100", "account_name": "…",
                     "description": "…", "debit": 18000000, "credit": 0 } /* … */ ],
        "total_debit": 19500000, "total_credit": 19500000, "balanced": true },
      { "key": "tanpa_divisi", "segment_id": null, "label": "Tanpa Divisi", "lines": [ /* … */ ],
        "total_debit": 0, "total_credit": 0, "balanced": true }
    ],
    "grand_total_debit": 19500000, "grand_total_credit": 19500000, "balanced": true,
    "payroll": { "id": 8, "period_month": 6, "period_year": 2026, "period_label": "Juni 2026",
                 "status": "approved", "total_gross": "…", "total_deduction": "…",
                 "total_bpjs_company": "…", "total_net": "…" }
  }
}
```
`group_by` tak dikenal → **422** `"Parameter group_by tidak valid. Gunakan none, division, atau branch."`. Segmen ber-label diurut A→Z; sentinel **Tanpa Divisi/Tanpa Cabang** (payslip lama dgn snapshot NULL) selalu di akhir. **Tiap segmen wajib `balanced`** & **Σ segmen == jurnal flat**.
`GET /api/v1/dashboard/payroll/runs/{payroll}/gl-export?format=csv|json&group_by=…` (izin `manage`, `throttle:heavy`) → **file stream**. CSV berdimensi menambah kolom label segmen + baris `SUBTOTAL` per segmen + `TOTAL` (nama berkas `jurnal-gaji-{companyId}-{YYYY-MM}-{groupBy}.csv`). JSON berdimensi = objek `segments[]` + `grand_total_*`. Format lain → **422**. Audit `PAYROLL_GL_EXPORTED` (memuat `group_by` & `balanced`).

#### F. Rincian BPJS Perusahaan di PDF Slip (tanpa endpoint baru)
Tidak ada endpoint JSON baru. `GET /api/v1/dashboard/payroll/payslips/{payslip}/pdf` (& PDF karyawan `/employee/payslips/{id}/pdf`) kini memuat **blok informasional** "Iuran BPJS Ditanggung Perusahaan (informasi, bukan pengurang neto)" — rincian per-komponen (Kesehatan/JKK/JKM/JHT/JP) + total, diturunkan dari `payslip_calculation_steps` yang sudah persist (helper `EmployerBpjsBreakdown::fromSteps()`). Total blok == `payslips.bpjs_company_total`. Bila BPJS tidak dihitung untuk slip itu → blok tidak muncul & neto tak berubah. **FE tidak perlu perubahan** (blok tercetak di dalam PDF).

### 23.3. Tugas Frontend (Status: ✅ SELESAI Penuh)

> Seluruh modul ini kewenangan **Finance/HRD (web)**. **Mobile: tidak ada tugas.**

**Web — `expenseflow-web/src/`:**
1. **`services/endpoints.ts`** — di `payrollApi`:
   - Grup: `listGroups()`, `createGroup(payload)`, `updateGroup(id, payload)`, `deleteGroup(id)` — **✅ SELESAI**.
   - Profil pajak perusahaan: `getCompanyTaxProfile()`, `saveCompanyTaxProfile({ npwp })` — **✅ SELESAI**.
   - Run THR: **perluas** `createRun(payload)` agar menerima `run_type` & `payroll_group_id` — **✅ SELESAI**.
   - e-Bupot: `exportEbupotDraft(taxYear)` — **unduhan blob** + header Authorization — **✅ SELESAI**. *(Fase 6: endpoint sama, kini tervalidasi XSD; tambahkan pembacaan `GET /tax/ebupot/schema` & penanganan **422** galat skema — lihat §24.)*
   - GL berdimensi: `previewGlJournal(payrollId, groupBy?)` & `exportGlJournal(payrollId, format, groupBy?)` dengan query `group_by` (default `none`) — **✅ SELESAI**.
2. **Tipe** — `GlSegment`, `GlGroupedJournal` (`types.ts`) — **✅ SELESAI**. `PayrollGroup`, `CompanyTaxProfile`, perluasan payload createRun dengan `run_type` & `payroll_group_id` — **✅ SELESAI**.
3. **Komponen Web (`expenseflow-web/src/components/`):**
   - **Jurnal Akuntansi (`PayrollGlJournal.tsx`):** pemilih `group_by` (Flat / Per Divisi / Per Cabang), tabel per-segmen dengan subtotal + lencana **Seimbang** per segmen & grand-total, unduhan CSV/JSON mewariskan `group_by` terpilih (blob) — **✅ SELESAI**.
   - **Tab "Pengaturan Payroll" (`PayrollManagement.tsx`):**
     - **Panel "Grup Payroll"**: tabel grup + jumlah anggota, form modal buat/ubah, tombol hapus dengan penanganan 422 (masih ada anggota/dipakai batch) via dialog penawaran nonaktifkan grup — **✅ SELESAI**.
     - **Panel "Profil Pajak Perusahaan (NPWP)"**: tampil `name`, `has_npwp`, `npwp_masked` terenkripsi; form input NPWP 15/16 digit & hapus; validasi digit klien & backend; NPWP penuh tidak pernah kembali ke layar — **✅ SELESAI**.
   - **Buat Run (`CreateRunModal` di `PayrollManagement.tsx`):** pilihan segmented **Jenis Batch** (Reguler / **THR**), box aturan THR Permenaker 6/2016, otomatis disable akhir tahun saat THR, & dropdown pemilihan **Grup Payroll** aktif — **✅ SELESAI**.
   - **Badge THR (`PayrollManagement.tsx`):** lencana kuning THR pada kartu ringkas batch & header detail batch — **✅ SELESAI**.
   - **Menu 1721-A1 (`PayrollTax1721A1.tsx`):** tombol **Ekspor e-Bupot (XML)** di tingkat tahun (blob) — **✅ SELESAI**. *(Fase 6: label statis DRAF perlu **diganti** badge status skema dinamis dari `GET /tax/ebupot/schema` — tugas FE di §24.)*
4. **Keamanan UI:** NPWP penuh tak pernah di JSON — jangan render/simpan; hanya terbawa di berkas unduhan (`manage`). Tombol unduh/ekspor & tulis hanya untuk pengguna ber-izin `manage`.

**Mobile — `expenseflow-mobile/` (Flutter):** tidak ada tugas wajib. Slip THR otomatis tampil di `/employee/payslips` (memakai tampilan slip yang sudah ada). *Opsional:* notifikasi FCM saat batch THR `paid` (ikut mekanisme Fase 5).

### 23.4. Catatan & Batasan
- **THR self-correcting.** PPh21 THR bulanan bersifat estimasi TER marginal; nilai tahunan dinormalkan otomatis oleh rekonsiliasi Pasal-17 run reguler Desember (payslip THR yang sudah `approved`/`paid` ikut ke `priorYearAccumulation()`). Bila run THR dijalankan **sebelum** run reguler bulan itu → fallback `TER(THR)`; tetap ternormalisasi tahunan.
- ~~**e-Bupot XML = DRAF.**~~ **Sudah diselesaikan di Fase 6:** validasi XSD kini **wajib** untuk setiap ekspor (gagal → 422), dengan dua tingkat skema (resmi DJP → `resmi="true"`; internal → `resmi="false"`). Yang **masih** di luar lingkup: penyetoran/transmisi langsung ke sistem DJP. → [§24.D](#d-e-bupot-2126--ekspor-xml-tervalidasi-skema-xsd).
- **Snapshot cost center mulai sekarang.** `payslips.division_id` & `attendance_setting_id` di-snapshot sejak fitur ini; payslip lama (NULL) masuk segmen **Tanpa Divisi/Tanpa Cabang** pada GL berdimensi — sesuai semantik snapshot, tanpa backfill.
- **NULL-distinct unique index.** Untuk `payroll_group_id`/`attendance_setting_id` NULL, DB tak dapat menegakkan keunikan → **guard aplikatif `PayrollController::store()` tetap otoritatif**. Insert massal di luar `store()` wajib mereplikasi guard.
- **Bucket `expense_thr`.** Perusahaan lama yang sudah punya override `payroll_gl_accounts` tetap aman (fallback default config utk pos baru); finance dapat menimpa kode akun THR via panel Pemetaan Akun GL ([Bagian 22.B](#b-pemetaan-akun-gl--simpan-upsert)).

### 23.5. Checklist Penerimaan (Definition of Done Frontend)
- [x] Finance dapat CRUD **Grup Payroll** (buat/ubah/hapus), lihat jumlah anggota, & menangani 422 hapus (grup terpakai) dengan opsi nonaktifkan — **SELESAI** (`GrupPayrollPanel` di `PayrollManagement.tsx`).
- [x] Finance dapat mengelola **NPWP Perusahaan** (tampil termasking, simpan 15/16 digit, hapus); NPWP penuh **tak pernah** tampil di layar — **SELESAI** (`ProfilPajakPerusahaanPanel` di `PayrollManagement.tsx`).
- [x] Dialog buat run mendukung **Jenis Batch THR** & **Grup Payroll**; run reguler + THR periode sama sama-sama bisa dibuat; slip THR tampil benar (1 baris THR + PPh21 THR, tanpa BPJS) — **SELESAI** (`CreateRunModal` di `PayrollManagement.tsx`).
- [x] Menu 1721-A1 punya tombol **Ekspor e-Bupot (XML)** (blob) — **SELESAI** (`PayrollTax1721A1.tsx`). *(Fase 6 menambah tugas: ganti banner DRAF dengan badge status skema dari `GET /tax/ebupot/schema` + tangani 422 galat skema — §24.)*
- [x] Jurnal GL punya pemilih **`group_by`**; mode berdimensi menampilkan segmen + subtotal + lencana **Seimbang** per segmen & grand-total; unduhan CSV/JSON mewariskan `group_by` (blob) — **SELESAI** (`PayrollGlJournal.tsx`).
- [x] PDF slip menampilkan blok **Rincian BPJS Perusahaan** (informasi, bukan pengurang neto) — tanpa perubahan FE (tercetak di PDF) — **SELESAI** (`EmployerBpjsBreakdown.php`).
- [x] Unduhan GL berdimensi ditangani **blob/stream** + header Authorization; hanya untuk pengguna ber-izin `manage` — **SELESAI** (`endpoints.ts` & `PayrollGlJournal.tsx`). *(e-Bupot XML juga SELESAI)*

---

## 24. Fase 6 (Enterprise Lanjutan) — Kontrak API & Tugas Frontend

> **Konteks.** Empat modul enterprise Fase 6 kini **selesai & lulus uji di backend**. Bagian ini adalah **kontrak API** keempatnya + **pekerjaan frontend (web React)** yang **belum** dikerjakan. Backend tidak menyediakan UI. **Mobile: tidak ada tugas wajib** — slip pesangon & slip valas otomatis tampil lewat `/employee/payslips` memakai tampilan slip yang sudah ada.
>
> **Cakupan & berkas uji (semua hijau; suite penuh 658 uji / 3.868 asersi).**
> 1. **Exit Settlement — Pesangon PHK & Kompensasi PKWT** — `tests/Feature/PayrollSeveranceRunTest.php` (10 uji).
> 2. **Struktur & Skala Upah** — `tests/Feature/SalaryGradeStructureTest.php` (7 uji).
> 3. **Multi-Mata Uang & PPh 26 Ekspatriat** — `tests/Feature/PayrollMultiCurrencyPph26Test.php` (14 uji).
> 4. **e-Bupot XML Tervalidasi Skema XSD DJP** — `tests/Feature/EbupotXsdXmlTest.php` (6 uji).
>
> **Prasyarat data.** Jalankan `php artisan migrate` (migrasi `2026_09_28_100029`–`100035`, forward-only & non-destruktif) **dan** `php artisan db:seed --class=PayrollStatutorySeeder` (idempoten) — seeder ini menambah aturan statutori baru: **tabel UP/UPMK PP 35/2021**, **bracket PPh 21 Final PP 68/2009**, `uph_housing_medical_rate` (15%), dan **tarif PPh 26** (20%). Tanpa seeder, batch pesangon & pemotongan PPh 26 tidak dapat dihitung. Baris lama tidak berubah: `employee_salaries` di-backfill `currency='IDR'` dengan `salary_grade_id`/`job_level_id` = `NULL`; `payslips` di-backfill `currency='IDR'`, `exchange_rate=1` — sehingga angka lama **identik** dengan sebelumnya.
>
> **Keputusan produk yang dikunci.**
> 1. **Pesangon = run terpisah** `payrolls.run_type='severance'` (bukan komponen di run reguler) — konsisten dengan keputusan THR di [Bagian 23](#23-enam-fitur-lanjutan-pasca-mvp--kontrak-api--tugas-frontend).
> 2. **UP/UPMK/UPH dipotong PPh 21 FINAL** (PP 68/2009) — `is_taxable=false`, **tidak** masuk rekonsiliasi Pasal 17 Desember & **tidak** dikreditkan di 1721-A1. Sebaliknya **uang kompensasi PKWT** & **uang pisah** = penghasilan **tidak teratur non-final** (`is_taxable=true`) yang **ikut** rekonsiliasi tahunan.
> 3. **Semua kolom uang `payslips` tetap RUPIAH.** Valas disimpan terpisah (`currency`, `exchange_rate`, `gross_currency`, `net_currency`) — laporan/GL/ekspor bank lama tidak perlu tahu-menahu soal valas.
> 4. **Rate lock per batch.** Kurs yang dipakai di-snapshot ke `payrolls.exchange_rates` (JSON) saat kalkulasi; hitung ulang batch yang sama memakai kurs terkunci itu, **bukan** kurs master terbaru.
> 5. **Kurs tidak tersedia ⇒ batch DITOLAK (422).** Sistem **tidak pernah** mengasumsikan 1:1 — diam-diam salah nominal jauh lebih berbahaya daripada gagal terang-terangan.
> 6. **e-Bupot wajib lolos XSD.** Label `resmi` bernilai `true` hanya bila XSD **resmi DJP** yang meloloskan — label tidak pernah bohong.

### 24.1. Prinsip Lintas Modul (penting untuk FE)

- Semua endpoint berada di grup `/api/v1/dashboard/payroll/*` (izin modul **Payroll**: `read` untuk lihat/JSON, `manage` untuk tulis & unduh berkas). Rute ber-route-model (`{jobLevel}`, `{salaryGrade}`, `{currencyRate}`, `{severanceCase}`) lintas-company diblokir **403** oleh `CompanyMiddleware`; guard controller menutup dengan **404** sebagai lapis kedua. **FE harus menangani keduanya sebagai "tidak ditemukan / bukan milik Anda".**
- **Nominal uang = string desimal** (`decimal:2`) — parse defensif di FE (`Number(x)` / `parseFloat`), jangan diperlakukan sebagai number bawaan. Pengecualian yang sudah ada tetap berlaku (nilai jurnal GL per baris = number).
- `exchange_rate` & `rate_to_idr` adalah **`decimal:6`** (string 6 desimal) — bukan 2. Jangan dibulatkan ke 2 desimal saat ditampilkan.
- **NPWP & TIN asing tak pernah dikirim penuh ke FE.** JSON hanya `npwp_masked`; `foreign_tax_id` tersimpan **terenkripsi** di basis data. Nilai NPWP **penuh** hanya ada di berkas ter-stream (PDF 1721-A1 & **e-Bupot XML**) yang wajib `manage` + diaudit `SECURITY/WARNING`.
- Aksi tulis di-throttle `throttle:actions`; ekspor berkas `throttle:heavy`.
- **Seluruh modul ini hanya master data + pratinjau.** Tidak ada satupun yang memindahkan status batch — perubahan angka baru terwujud saat batch **dihitung** (`POST /runs/{id}/calculate`).

### 24.2. Kontrak API

#### A. Struktur & Skala Upah — Jenjang Jabatan (`job_levels`)

`GET /api/v1/dashboard/payroll/job-levels` (izin `read`) →
```jsonc
{
  "data": [
    { "id": 1, "company_id": 1, "name": "Staf", "code": "L1", "rank": 1,
      "description": null, "is_active": true,
      "grades_count": 2,                 // jumlah golongan upah yang memakai jenjang ini
      "created_at": "…", "updated_at": "…" }
  ]
}
```
Terurut `rank` lalu `name`; hanya jenjang milik company sendiri.

`POST /api/v1/dashboard/payroll/job-levels` (izin `manage`, `throttle:actions`) — body:
```jsonc
{
  "name": "Supervisor",     // wajib, ≤120, UNIK per-company
  "code": "L2",             // opsional, ≤20, UNIK per-company
  "rank": 2,                // opsional, 1–999 — dasar pengurutan hierarki
  "description": "…",       // opsional, ≤2000
  "is_active": true         // opsional, default true
}
```
**201** `{ "message": "…", "data": { …jenjang } }`. Nama/kode duplikat → **422**. Audit `JOB_LEVEL_CREATED`.

`PUT /…/job-levels/{jobLevel}` (izin `manage`) — body sama; aturan unik meng-`ignore` baris sendiri. Lintas-company → **403/404**.

`DELETE /…/job-levels/{jobLevel}` (izin `manage`) — **422** bila jenjang masih dipakai golongan upah **atau** melekat pada riwayat gaji karyawan; pesan menyarankan **nonaktifkan** (`is_active=false`) alih-alih hapus. Audit `JOB_LEVEL_DELETED` (WARNING).

#### B. Struktur & Skala Upah — Golongan Upah (`salary_grades`)

`GET /api/v1/dashboard/payroll/salary-grades?job_level_id=` (izin `read`) →
```jsonc
{
  "data": [
    { "id": 5, "company_id": 1, "job_level_id": 2,
      "name": "Golongan 2A", "code": "G2A",
      "min_salary": "6000000.00",      // string decimal:2
      "mid_salary": "7500000.00",      // boleh null
      "max_salary": "9000000.00",
      "currency": "IDR",
      "description": null, "is_active": true,
      "job_level": { "id": 2, "name": "Supervisor", "rank": 2 },
      "created_at": "…", "updated_at": "…" }
  ]
}
```
Terurut `min_salary` lalu `name`.

`POST /api/v1/dashboard/payroll/salary-grades` (izin `manage`, `throttle:actions`) — body:
```jsonc
{
  "name": "Golongan 2A",    // wajib, ≤120, UNIK per-company
  "code": "g2a",            // opsional, ≤20, UNIK per-company — DI-UPPERCASE backend
  "job_level_id": 2,        // opsional; wajib milik company yang sama (else 422)
  "min_salary": 6000000,    // wajib
  "mid_salary": 7500000,    // opsional (titik tengah / midpoint)
  "max_salary": 9000000,    // wajib
  "currency": "IDR",        // opsional, 3 huruf, default IDR
  "description": "…",       // opsional, ≤2000
  "is_active": true
}
```
**Validasi rentang (422, bukan 500):**

| Kondisi | Field error | Pesan |
|---|---|---|
| `max_salary` < `min_salary` | `max_salary` | "Upah maksimum tidak boleh lebih kecil dari upah minimum." |
| `mid_salary` di luar `[min, max]` | `mid_salary` | "Titik tengah harus berada di antara upah minimum dan maksimum." |

**201** `{ "message": "…", "data": { …golongan } }`. Audit `SALARY_GRADE_CREATED`.

`PUT /…/salary-grades/{salaryGrade}` (izin `manage`) — body sama; unik meng-`ignore` baris sendiri.

`DELETE /…/salary-grades/{salaryGrade}` (izin `manage`) — **422** bila golongan melekat pada riwayat gaji karyawan (`employee_salaries.salary_grade_id`) → sarankan nonaktifkan. Audit `SALARY_GRADE_DELETED` (WARNING).

#### C. Penetapan Gaji dengan Rambu Golongan & Valas *(endpoint LAMA, body diperluas)*

`POST /api/v1/dashboard/payroll/salaries/{userId}` (izin `manage`, `throttle:actions`) — field **tambahan** Fase 6:
```jsonc
{
  "basic_salary": 7500000,
  "effective_date": "2026-01-01",
  "salary_grade_id": 5,     // opsional — bila diisi, basic_salary DIVALIDASI terhadap rentangnya
  "job_level_id": null,     // opsional — bila kosong, DITURUNKAN OTOMATIS dari golongan
  "currency": "usd"         // opsional, 3 huruf, default "IDR"; di-UPPERCASE backend
}
```
**Penolakan yang wajib ditangani FE (semua 422):**

| Kondisi | Field error | Pesan (contoh) |
|---|---|---|
| Nominal di luar rentang golongan | `basic_salary` | "Gaji pokok di luar rentang golongan Golongan 2A (6.000.000,00 – 9.000.000,00)." |
| Mata uang non-IDR tanpa kurs pada `effective_date` | `currency` | "Kurs USD → IDR pada 2026-01-01 belum tersedia. Isi master kurs terlebih dahulu." |
| `salary_grade_id`/`job_level_id` milik company lain | field terkait | validasi `exists` gagal |

Respons **201** memuat `currency`, `salary_grade_id`, `job_level_id` hasil akhir. **Catatan penting:** tanpa `salary_grade_id`, nominal **tidak** dicek rentang — golongan bersifat opt-in per-karyawan.

`GET /api/v1/dashboard/payroll/salaries/{userId}` kini mengembalikan `tax_profile` yang diperluas (lihat [§24.2.E](#e-profil-pajak-ekspatriat-endpoint-lama-body-diperluas)).

#### D. e-Bupot 21/26 — Ekspor XML Tervalidasi Skema XSD

*Menggantikan kontrak draf di §23.2.D (Bagian 23).*

`GET /api/v1/dashboard/payroll/tax/ebupot/schema` (izin `read`) — status skema aktif, **tanpa** membocorkan isi berkas XSD →
```jsonc
{
  "data": {
    "official_schema_installed": false,   // true bila XSD resmi DJP terpasang di server
    "schema_label": "internal-v2",        // atau "djp-resmi"
    "validated": true,                    // SELALU true: setiap ekspor divalidasi
    "resmi": false,
    "expected_path": "…/storage/app/private/pajak/ebupot/xsd/ebupot.xsd",
    "message": "Ekspor e-Bupot divalidasi terhadap skema XSD internal. Pasang XSD resmi DJP pada path yang tertera agar berkas ditandai resmi."
  }
}
```

`GET /api/v1/dashboard/payroll/tax/ebupot/export?tax_year=2026` (izin `manage`, `throttle:heavy`) → **file stream** `application/xml; charset=UTF-8`.
- Nama berkas: **`ebupot-{companyId}-{taxYear}.xml`** bila divalidasi XSD resmi DJP, **`ebupot-internal-{companyId}-{taxYear}.xml`** bila XSD internal. *(Nama lama `ebupot-draf-…` sudah tidak dipakai.)*
- Root: `<eBupot21 resmi="true|false" skema="djp-resmi|internal-v2" tahunPajak="2026" dibuat="…" jumlahBuktiPotong="N">`, berisi `<Pemotong>` (nama, **NPWP penuh**, alamat) + `<DaftarBuktiPotong>` dengan satu `<BuktiPotong jenis="PPh21|PPh26">` per karyawan. Subjek luar negeri otomatis bertanda `jenis="PPh26"`.
- Tiap `<BuktiPotong>` memuat `<Penerima>` (`Nama`, `NPWP` penuh, `BerNPWP`, `KodePegawai`, `Jabatan`, `StatusPTKP`), `<MasaPerolehan tahun jumlahMasa>`, `<Penghasilan>` (`Bruto`, `BrutoTeratur`, `BrutoTidakTeratur`, `BiayaJabatan`, `IuranPensiun`, `Neto`, `PTKP`, `PKP`) dan `<PPh>` (`Terutang`, `Dipotong`, `Selisih`) — semua uang berformat `decimal:2`.
- **Dokumen SELALU divalidasi `DOMDocument::schemaValidate()` sebelum dikirim.** Gagal ⇒ **422**:
```jsonc
{
  "message": "Ekspor dibatalkan: dokumen e-Bupot tidak lolos validasi skema XSD.",
  "errors": { "schema": ["Baris 12: Element 'NPWP': …", "…"] }   // maksimum 20 galat
}
```
  Berkas cacat **tidak pernah** sampai ke pengguna; kegagalan diaudit `PAYROLL_EBUPOT_SCHEMA_FAILED` (SECURITY/WARNING) karena menandakan data master yang perlu diperbaiki sebelum lapor.
- Ekspor sukses diaudit `PAYROLL_EBUPOT_EXPORTED` (SECURITY/WARNING).
- **NPWP/PTKP yang tidak memenuhi pola skema dikosongkan**, bukan membatalkan ekspor — satu baris master kotor tidak boleh menggagalkan seluruh berkas, dan bidang kosong langsung terlihat saat pemeriksaan.

> **Memasang XSD resmi DJP.** Administrator menempatkan berkas pada path `payroll_ebupot.schema.official` (lihat `expected_path` di atas; berada di **disk privat**). XSD resmi **tidak** didistribusikan bersama aplikasi. Begitu terpasang, ekspor otomatis divalidasi terhadapnya dan ditandai `resmi="true"` — **tanpa** perubahan kode maupun FE.

#### E. Profil Pajak Ekspatriat *(endpoint LAMA, body diperluas)*

`PUT /api/v1/dashboard/payroll/salaries/{userId}/tax-profile` (izin `manage`, `throttle:actions`) — field **tambahan** Fase 6:
```jsonc
{
  "ptkp_status": "TK/0",           // tetap wajib
  "tax_subject_type": "foreign",   // "domestic" (default) | "foreign" → dipotong PPh 26
  "treaty_country": "sg",          // 2 huruf negara mitra P3B — DI-UPPERCASE backend; null = tanpa P3B
  "treaty_rate": 0.10,             // 0–0.4 (DESIMAL, bukan persen). 10% → 0.10
  "foreign_tax_id": "SG-TIN-0099"  // TIN/NPWP negara asal, ≤40 — tersimpan TERENKRIPSI
}
```
**Aturan penting:**
- Field yang **tidak dikirim tidak diubah** — menyimpan profil domestik biasa **tidak** menghapus data ekspatriat yang sudah ada.
- **`treaty_rate` tanpa `treaty_country` → 422** (`errors.treaty_country`): *"Tarif P3B wajib disertai negara mitra (dasar SKD/DGT)."* Tarif istimewa tanpa dasar dokumen ditolak di muka, **bukan** diam-diam diturunkan ke 20%.
- Respons **200** `data` memuat `tax_subject_type`, `treaty_country`, `treaty_rate`, `foreign_tax_id`, plus `npwp_masked` seperti sebelumnya. Audit `TAX_PROFILE_SAVED`.

**Dampak pada kalkulasi (informasi FE):** karyawan `foreign` dipotong **PPh 26** sebesar **20% × bruto** (Pasal 26 UU PPh) — **tanpa** PTKP, **tanpa** biaya jabatan, **tanpa** TER — atau **tarif P3B** bila `treaty_country` terisi. Baris potongan pada slip berlabel **"PPh 26"**, dan jejaknya tercatat sebagai calculation step `PPH26` (bukan `PPH21_TER`/`PPH21_PASAL17`).

#### F. Master Kurs Valuta Asing (`currency_rates`)

`GET /api/v1/dashboard/payroll/currency-rates?currency=USD` (izin `read`) →
```jsonc
{
  "data": [
    { "id": 12, "company_id": 1, "currency": "USD",
      "rate_to_idr": "16000.000000",   // string decimal:6
      "effective_date": "2026-01-01", "source": "KMK",
      "is_editable": true, "scope": "company" },
    { "id": 3, "company_id": null, "currency": "SGD",
      "rate_to_idr": "12000.000000", "effective_date": "2026-01-01", "source": "BI Tengah",
      "is_editable": false, "scope": "global" }   // baris acuan global: TAMPIL tapi READ-ONLY
  ]
}
```
Terurut `currency` lalu `effective_date` menurun, maksimum 500 baris. **FE wajib menyembunyikan aksi ubah/hapus saat `is_editable=false`** — menekannya tetap ditolak **404** oleh backend.

`POST /api/v1/dashboard/payroll/currency-rates` (izin `manage`, `throttle:actions`) — body `{ currency (3 huruf, di-UPPERCASE), rate_to_idr (0,000001–999.999.999, dibulatkan 6 desimal), effective_date, source (opsional ≤60) }`.

| Kondisi | HTTP | Pesan |
|---|---|---|
| `currency = "IDR"` | **422** | IDR adalah mata uang basis — kursnya selalu 1 dan tidak perlu diisi. |
| Duplikat `[company, currency, effective_date]` | **422** | Kurs untuk mata uang & tanggal berlaku tersebut sudah ada. |

**201** `{ "message": "…", "data": { …kurs } }`. Audit `CURRENCY_RATE_CREATED`.

`PUT /…/currency-rates/{currencyRate}` (izin `manage`) — hanya `rate_to_idr` & `source` dapat diubah (mata uang & tanggal berlaku **tidak** — buat baris baru). Baris **global** atau milik company lain → **404/403**.
`DELETE /…/currency-rates/{currencyRate}` (izin `manage`) — idem.

> **Semantik resolusi kurs.** `CurrencyRate::resolve(currency, onDate, companyId)` mengambil baris **berlaku terakhir ≤ `onDate`**, mendahulukan baris milik company di atas baris global. Menambah kurs bertanggal lebih baru **tidak** mengubah batch yang sudah dihitung (rate lock).

#### G. Exit Settlement — Berkas Pesangon (`severance_cases`)

`GET /api/v1/dashboard/payroll/severance-cases?payroll_id=&status=&termination_type=` (izin `read`) →
```jsonc
{
  "data": [
    { "id": 7, "company_id": 1, "user_id": 42, "payroll_id": 19,
      "termination_type": "phk",          // phk | pkwt_end | resign | retirement | death
      "termination_reason": "Efisiensi",
      "termination_date": "2026-06-30", "last_working_date": "2026-06-30",
      "employment_type": "pkwtt",         // pkwt | pkwtt
      "contract_start_date": null, "contract_end_date": null,
      "up_multiplier": "1.00",            // faktor PP 35/2021 — rentang 0–2
      "upmk_multiplier": "1.00",
      "include_uph": true,
      "annual_leave_balance_days": "10.00",   // ≤365
      "relocation_cost": "0.00", "other_compensation": "0.00",
      "separation_pay": "0.00",           // uang pisah — NON-final (ikut rekonsiliasi)
      "asset_deduction": "0.00", "settle_loans": true,
      "status": "draft",                  // draft | calculated | closed
      "notes": null,
      "user": { "id": 42, "name": "Budi", "employee_id": "EMP-001", "position_id": 3 },
      "payroll": { "id": 19, "period_month": 6, "period_year": 2026,
                   "run_type": "severance", "status": "draft" } }
  ]
}
```
Maksimum 500 baris, terurut `termination_date` menurun. `GET /…/severance-cases/{severanceCase}` mengembalikan satu baris dengan bentuk sama.

`POST /api/v1/dashboard/payroll/severance-cases` (izin `manage`, `throttle:actions`) — body sama seperti di atas tanpa `id`/`status`. Aturan kunci:

| Kondisi | HTTP | Field error |
|---|---|---|
| `user_id` bukan karyawan company ini | **422** | `user_id` |
| `payroll_id` bukan batch bertipe `severance` | **422** | `payroll_id` — "Batch bukan bertipe pesangon." |
| Batch tujuan sudah `approved`/`paid` | **422** | `payroll_id` — batch sudah final |
| Karyawan sudah punya berkas di batch yang sama | **422** | `user_id` — mencegah slip ganda |
| `up_multiplier`/`upmk_multiplier` di luar 0–2 | **422** | field terkait |

**201** `{ "message": "Berkas pesangon berhasil dibuat.", "data": { … } }`. Audit `SEVERANCE_CASE_CREATED`.

`PUT /…/severance-cases/{severanceCase}` (izin `manage`) — **422** bila batch-nya sudah `approved`/`paid`. Setiap perubahan parameter **mengembalikan `status` ke `draft`** — angka lama dianggap usang sampai batch dihitung ulang.
`DELETE /…/severance-cases/{severanceCase}` (izin `manage`) — guard immutable yang sama. Audit `SEVERANCE_CASE_DELETED` (WARNING).

#### H. Exit Settlement — Simulasi Murni-Baca (`/preview`)

`GET /api/v1/dashboard/payroll/severance-cases/{severanceCase}/preview` (izin `read`) — **tidak menulis apa pun** ke basis data; dipakai HR memeriksa angka **sebelum** batch dihitung/disetujui →
```jsonc
{
  "data": {
    "severance_case_id": 7, "user_id": 42, "employee_name": "Budi",
    "termination_type": "phk", "termination_date": "2026-06-30",
    "monthly_wage": "10000000.00",
    "tenure_months": 101, "tenure_years": 8.42,     // tenure_years = number
    "up_months": 9, "upmk_months": 3,               // hasil pembacaan tabel PP 35/2021
    "up":   "90000000.00",                          // upah × up_months × up_multiplier
    "upmk": "30000000.00",
    "uph":  "22000000.00",
    "uph_breakdown": {
      "leave_compensation": "4000000.00",           // upah / 25 × sisa hari cuti
      "housing_medical":    "18000000.00",          // 15% × (UP + UPMK)
      "housing_medical_rate": 0.15,                 // number, bukan string
      "relocation_cost":    "0.00",
      "other_compensation": "0.00"
    },
    "pkwt_compensation": "0.00", "pkwt_months": 0,  // terisi hanya bila termination_type=pkwt_end
    "separation_pay": "0.00",
    "final_tax_base": "142000000.00",               // UP + UPMK + UPH = DASAR PPh 21 FINAL
    "pph21_final":    "8800000.00",
    "pph21_final_effective_rate": 0.062,            // number
    "loan_settlement": "0.00",                      // pelunasan sisa kasbon bila settle_loans=true
    "asset_deduction": "0.00",
    "total_gross": "142000000.00",
    "total_deduction": "8800000.00",
    "total_net": "133200000.00"
  }
}
```
Gagal bila upah karyawan pada tanggal pengakhiran belum ada → **422** ("Simulasi tidak dapat dibuat: data upah karyawan pada tanggal pengakhiran belum tersedia.").

> **Cara membaca angka contoh** (masa kerja 8 th 5 bln, upah 10 jt): **UP** = 9 bulan upah (PP 35/2021 Pasal 40 ayat 2 — masa kerja ≥ 8 th); **UPMK** = 3 bulan upah (ayat 3 — masa kerja 6–9 th); **UPH** = kompensasi sisa cuti 10 hari (10 jt ÷ 25 × 10 = 4 jt) + 15% × (UP + UPMK) = 18 jt. **PPh 21 Final** PP 68/2009 atas 142 jt = 0% × 50 jt + 5% × 50 jt + 15% × 42 jt = **8,8 jt** (tarif efektif 6,2%).

#### I. Menjalankan Batch Pesangon

`POST /api/v1/dashboard/payroll/runs` (izin `manage`) — body `{ "period_month": 6, "period_year": 2026, "run_type": "severance" }`. Batch pesangon **tidak pernah** menjadi batch akhir tahun (`is_year_end` dipaksa `false`) karena PPh 21 Final tidak direkonsiliasi. Alur selanjutnya **identik** dengan run reguler: `calculate` → `submit` → `approve` → `mark-paid`, dengan maker-checker & immutability yang sama.

**Bentuk slip pesangon (berbeda dari slip reguler — FE perlu sadar):**
- **Tidak ada** gaji pokok, **tidak ada** BPJS, **tidak ada** potongan absensi/lembur.
- Baris penghasilan: `Uang Pesangon (UP)`, `Uang Penghargaan Masa Kerja (UPMK)`, `Uang Penggantian Hak (UPH)` — semuanya `is_taxable=false` (**final**); `Uang Kompensasi PKWT` & `Uang Pisah` — `is_taxable=true` (**non-final**).
- Baris potongan: `PPh 21 Final` (atas UP+UPMK+UPH), pelunasan kasbon, potongan aset.
- Calculation steps: `SEVERANCE_PRORATE`, `SEVERANCE_UP`, `SEVERANCE_UPMK`, `SEVERANCE_UPH`, `PKWT_COMPENSATION`, `PPH21_FINAL` — semuanya terbaca via endpoint calculation-trace yang sudah ada ([§22.2.J](#j-calculation-trace-sebuah-slip)).
- **GL**: beban masuk bucket `expense_severance` (bukan `expense_salary`), pajaknya ke `payable_pph21`. Perusahaan dengan override `payroll_gl_accounts` tetap aman (pos baru jatuh ke default config).

#### J. Slip Gaji Valuta Asing *(bentuk respons slip yang diperluas)*

Payslip kini membawa empat kolom tambahan. **Semua kolom uang lain tetap Rupiah** — jangan dikonversi ulang di FE.
```jsonc
{
  "id": 88,
  "basic_salary": "80000000.00",   // ← IDR hasil konversi (5.000 USD × 16.000)
  "gross_salary": "80000000.00", "net_salary": "…",
  "currency": "USD",               // mata uang kontrak; "IDR" untuk karyawan domestik
  "exchange_rate": "16000.000000", // decimal:6 — kurs TERKUNCI saat batch dihitung
  "gross_currency": "5000.00",     // bruto dalam mata uang kontrak (informasional)
  "net_currency": "4850.00"        // neto dalam mata uang kontrak (dasar transfer valas)
}
```
Batch menyimpan snapshot seluruh kurs yang dipakai di `payrolls.exchange_rates` (JSON, mis. `{ "USD": 16000 }`). Karyawan domestik: `currency="IDR"`, `exchange_rate="1.000000"`, `gross_currency`/`net_currency` = `null`, dan **tanpa** calculation step `CURRENCY`.

**Kegagalan yang wajib ditangani FE:** `POST /runs/{id}/calculate` → **422** dengan pesan mengandung `"Kurs USD"` bila ada karyawan bergaji valas tanpa kurs berlaku. **Seluruh batch gagal** — tidak ada slip yang dibuat dengan asumsi 1:1. FE sebaiknya menautkan pesan ini ke menu Master Kurs.

### 24.3. Tugas Frontend yang Harus Dikerjakan (Fase 6)

**Web — `expenseflow-web/` (React + TypeScript).** Semua di bawah ini **BELUM** dikerjakan.

1. **`src/services/endpoints.ts` — tambahkan pemanggil API:**
   - `listJobLevels()`, `createJobLevel(body)`, `updateJobLevel(id, body)`, `deleteJobLevel(id)`
   - `listSalaryGrades(params?)`, `createSalaryGrade(body)`, `updateSalaryGrade(id, body)`, `deleteSalaryGrade(id)`
   - `listCurrencyRates(params?)`, `createCurrencyRate(body)`, `updateCurrencyRate(id, body)`, `deleteCurrencyRate(id)`
   - `listSeveranceCases(params?)`, `getSeveranceCase(id)`, `previewSeveranceCase(id)`, `createSeveranceCase(body)`, `updateSeveranceCase(id, body)`, `deleteSeveranceCase(id)`
   - `getEbupotSchemaStatus()` — **JSON** (bukan blob).
   - Perluas `setEmployeeSalary()` dengan `salary_grade_id`/`job_level_id`/`currency`, dan `saveTaxProfile()` dengan `tax_subject_type`/`treaty_country`/`treaty_rate`/`foreign_tax_id`.

2. **`src/types.ts` — tipe baru:** `JobLevel`, `SalaryGrade`, `CurrencyRate` (termasuk `is_editable` & `scope`), `SeveranceCase`, `SeverancePreview` (bentuk §24.2.H), `EbupotSchemaStatus`; perluas `Payslip` (`currency`, `exchange_rate`, `gross_currency`, `net_currency`) & `TaxProfile`. **Semua field uang bertipe `string`.**

3. **Panel "Struktur & Skala Upah"** (sub-tab baru di `PayrollManagement.tsx`, atau komponen `StrukturSkalaUpah.tsx`):
   - Tabel **Jenjang Jabatan** terurut `rank` (kolom: rank, nama, kode, jumlah golongan, status aktif) + form buat/ubah + hapus.
   - Tabel **Golongan Upah** terurut `min_salary` (kolom: nama, kode, jenjang, min–mid–maks, mata uang, aktif) + form buat/ubah + hapus.
   - **Visualisasi rentang** (bar min→mid→maks per golongan) membantu HR melihat tumpang-tindih antar-golongan — opsional tapi direkomendasikan.
   - **Wajib**: tangani **422** hapus (dipakai riwayat gaji / golongan) dengan menawarkan **nonaktifkan** alih-alih hapus; tampilkan `errors.max_salary` & `errors.mid_salary` pada field yang tepat.

4. **Form Gaji Karyawan — dropdown golongan + rambu rentang:**
   - Tambah pemilih **Golongan Upah** (opsional) & **Jenjang Jabatan** (terisi otomatis dari golongan, boleh ditimpa).
   - Saat golongan dipilih, tampilkan **hint rentang** (`min – maks`) di bawah input gaji pokok dan beri peringatan **sebelum** submit bila nominal di luar rentang — backend tetap menolak **422**, tapi pencegahan di muka jauh lebih ramah.
   - Tambah pemilih **Mata Uang** (default IDR). Bila non-IDR, tampilkan kurs berlaku + tanggal berlakunya; tangani **422** `errors.currency` dengan tautan ke menu Master Kurs.

5. **Panel "Master Kurs Valas"** (sub-tab/komponen `MasterKursValas.tsx`):
   - Tabel kurs (mata uang, kurs→IDR **6 desimal**, tanggal berlaku, sumber, lencana **Global**/**Perusahaan**) + filter mata uang.
   - Form tambah kurs; **ubah hanya `rate_to_idr` & `source`** — mata uang/tanggal tidak dapat diubah, arahkan pengguna membuat baris baru.
   - **Wajib**: sembunyikan aksi ubah/hapus saat `is_editable=false`; tangani **422** IDR & **422** duplikat dengan pesan yang jelas.
   - Beri catatan UI: *"Menambah kurs baru tidak mengubah batch yang sudah dihitung (kurs terkunci per batch)."*

6. **Profil Pajak Karyawan — blok Ekspatriat:**
   - Toggle **Subjek Pajak**: *Dalam Negeri* / *Luar Negeri (PPh 26)*. Bila *Luar Negeri*, tampilkan `treaty_country` (2 huruf), `treaty_rate` (**input persen di UI, kirim desimal** — 10% → `0.10`), `foreign_tax_id`.
   - **Wajib**: tangani **422** `errors.treaty_country` (tarif P3B tanpa negara mitra). Tampilkan penjelasan singkat: *PPh 26 = 20% × bruto, tanpa PTKP/biaya jabatan/TER; tarif P3B memerlukan bukti SKD/DGT.*
   - Beri **lencana "PPh 26"** pada daftar karyawan bersubjek luar negeri agar tidak tertukar dengan karyawan domestik dalam satu batch.

7. **Modul "Exit Settlement / Pesangon"** (menu baru `ExitSettlement.tsx`):
   - Daftar berkas pesangon dengan filter `payroll_id`/`status`/`termination_type`; lencana warna per jenis pengakhiran.
   - **Form berkas**: karyawan, jenis pengakhiran, tanggal pengakhiran & hari kerja terakhir, jenis hubungan kerja, tanggal kontrak (untuk PKWT), faktor **UP**/**UPMK** (0–2, default 1), toggle **Sertakan UPH**, sisa hari cuti, biaya relokasi & kompensasi lain, **uang pisah**, potongan aset, toggle **lunasi kasbon**, catatan.
   - **Panel Simulasi (`/preview`)** — inti modul ini: tampilkan rincian **UP / UPMK / UPH** (dengan sub-rincian `uph_breakdown`), kompensasi PKWT, **dasar PPh 21 Final** & **PPh 21 Final** beserta *tarif efektif*, pelunasan kasbon, dan **total bruto/potongan/neto**. Panggil ulang setiap kali berkas disimpan.
   - **Wajib**: tampilkan keterangan **"PPh 21 FINAL — tidak dikreditkan pada 1721-A1"** di baris pajak pesangon, dan pisahkan visual antara komponen **final** (UP/UPMK/UPH) dan **non-final** (kompensasi PKWT, uang pisah). Ini pembeda kepatuhan yang paling mudah salah dibaca.
   - Tangani **422** immutability (batch sudah `approved`/`paid`) dengan **menonaktifkan** tombol simpan/hapus, bukan sekadar menampilkan galat setelah ditekan.

8. **Dialog Buat Run — opsi jenis batch `severance`:** tambahkan pilihan **Pesangon (Exit Settlement)** di samping *Reguler* & *THR*. Setelah batch dibuat, arahkan pengguna ke modul Exit Settlement untuk melampirkan berkas **sebelum** menekan *Hitung*; tampilkan peringatan bila batch pesangon dihitung tanpa satu pun berkas terlampir.

9. **Tampilan Slip Gaji — dua penyesuaian:**
   - **Slip pesangon** (`run_type='severance'`): sembunyikan blok BPJS/kehadiran/lembur yang memang kosong; tampilkan kelompok UP/UPMK/UPH dengan label **Final**.
   - **Slip valas**: bila `currency !== 'IDR'`, tampilkan baris informasi **"5.000,00 USD @ 16.000,000000 = Rp 80.000.000,00"** (kurs terkunci) dan tegaskan bahwa seluruh nominal lain sudah dalam Rupiah.

10. **Menu 1721-A1 — ganti banner DRAF e-Bupot dengan status skema dinamis:**
    - Panggil `getEbupotSchemaStatus()` saat menu dibuka. Tampilkan lencana **"Tervalidasi XSD resmi DJP"** (hijau) bila `resmi=true`, atau **"Tervalidasi XSD internal"** (kuning) + `message` bila `false`. **Hapus** label statis *"DRAF — belum tervalidasi XSD DJP"* yang sekarang terpasang di `PayrollTax1721A1.tsx`: label itu sudah tidak benar.
    - Tangani **422** galat skema pada tombol ekspor: tampilkan daftar `errors.schema` (maks 20 baris) dalam modal "Perbaiki data master sebelum lapor" — **jangan** memicu unduhan.
    - Ekspor tetap **unduhan blob** + header Authorization; nama berkas ikut dari header `Content-Disposition` (`ebupot-…` vs `ebupot-internal-…`).

11. **Hak akses & guard UI:** seluruh aksi tulis di atas butuh izin `manage` — sembunyikan/nonaktifkan tombolnya untuk pengguna ber-izin `read` saja (backend tetap menolak **403**). Tangani **403** lintas-company sebagai "data tidak ditemukan", bukan sebagai galat sistem.

**Mobile — `expenseflow-mobile/` (Flutter):** tidak ada tugas wajib. Slip pesangon & slip valas otomatis tampil di `/employee/payslips`. *Opsional:* pada layar rincian slip, tampilkan baris kurs bila `currency != 'IDR'` dan label **Final** pada pajak pesangon.

### 24.4. Catatan & Batasan

- **PPh 21 Final tidak direkonsiliasi — dan itu memang benar.** UP/UPMK/UPH bersifat final (PP 68/2009): tidak masuk `employee_tax_period_totals` sebagai penghasilan kena pajak Pasal 17, tidak dikreditkan di 1721-A1, dan tidak ikut penyetahunan Desember. Pelaporannya lewat formulir **1721-VII**, terpisah. Yang **ikut** rekonsiliasi hanyalah uang kompensasi PKWT & uang pisah (non-final, penghasilan tidak teratur).
- **Masa kerja dihitung bulan penuh, tidak dibulatkan ke atas.** `tenure_months` memakai selisih bulan penuh (Carbon `diffInMonths`); tabel UP/UPMK dibaca dari `tenure_years = tenure_months / 12` dengan batas bawah **inklusif** & batas atas **eksklusif**. Kompensasi sisa cuti memakai pembagi **25 hari kerja** per bulan (`LEAVE_DAY_DIVISOR`).
- **Rentang golongan bersifat opt-in.** Gaji yang ditetapkan **tanpa** `salary_grade_id` tidak dicek rentang sama sekali — ini disengaja agar struktur upah dapat diadopsi bertahap tanpa memblokir data lama. FE sebaiknya menandai karyawan yang belum bergolongan.
- **Kurs terkunci per batch, bukan per slip.** Hitung ulang batch yang sama **selalu** memakai kurs di `payrolls.exchange_rates`. Untuk memakai kurs baru, buat batch baru — ini mencegah nominal historis bergeser diam-diam setelah disetujui.
- **PPh 26 tidak mengenal PTKP maupun TER.** Cabang PPh 26 mendahului seluruh logika PPh 21; karyawan bersubjek luar negeri **tidak** memunculkan step `PPH21_TER`/`PPH21_PASAL17`. Tarif P3B hanya berlaku bila `treaty_country` terisi — tarif tanpa negara mitra ditolak di muka (**422**), bukan diturunkan diam-diam ke 20%.
- **`resmi="false"` bukan berarti "belum divalidasi".** Dokumen selalu lolos sebuah XSD; atribut `resmi` hanya menyatakan **XSD siapa** yang meloloskannya. Selama XSD resmi DJP belum dipasang administrator, berkas tetap wajib diverifikasi sebelum dilaporkan.
- **Di luar lingkup Fase 6:** penyetoran/transmisi langsung ke sistem DJP (e-Bupot *submission*), pembayaran valas ke bank luar negeri (ekspor bank tetap format lokal dalam Rupiah), dan **UI Editor Formula DSL** (backend mesin formula sudah selesai).

### 24.5. Checklist Penerimaan (Definition of Done Frontend)

- [ ] HR dapat CRUD **Jenjang Jabatan** & **Golongan Upah**, melihat rentang min–mid–maks, dan menangani **422** hapus (terpakai) dengan opsi **nonaktifkan**.
- [ ] Form gaji karyawan menampilkan **hint rentang golongan**, mencegah submit di luar rentang, dan menampilkan `errors.basic_salary` dari backend bila tetap lolos.
- [ ] Finance dapat CRUD **Master Kurs**; baris **global** tampil tapi **tidak dapat** diubah/dihapus dari UI; **422** IDR & **422** duplikat tertangani.
- [ ] Penetapan gaji valas **tanpa kurs** menampilkan galat yang menautkan ke menu Master Kurs (bukan galat mentah).
- [ ] Profil pajak mendukung **subjek luar negeri** + P3B; tarif P3B tanpa negara mitra tertahan **422** dengan pesan jelas; daftar karyawan menandai subjek **PPh 26**.
- [ ] HR dapat membuat **berkas pesangon**, menjalankan **simulasi `/preview`**, dan melihat rincian **UP/UPMK/UPH + PPh 21 Final** dengan pemisahan visual **final vs non-final**.
- [ ] Berkas pada batch `approved`/`paid` **tidak dapat** diubah/dihapus dari UI (tombol nonaktif), sejalan dengan **422** backend.
- [ ] Dialog buat run mendukung **jenis batch Pesangon**; alur "buat batch → lampirkan berkas → hitung" terpandu.
- [ ] Slip valas menampilkan **kurs terkunci** & menegaskan nominal lain dalam Rupiah; slip pesangon menampilkan label **Final** pada pajaknya.
- [ ] Kalkulasi batch yang gagal karena **kurs tidak tersedia (422)** ditampilkan sebagai galat yang dapat ditindaklanjuti — bukan kegagalan senyap.
- [ ] Menu 1721-A1 menampilkan **status skema XSD dinamis** (bukan banner DRAF statis) dan menangani **422 galat skema** dalam modal tanpa memicu unduhan.
- [ ] Seluruh aksi tulis disembunyikan/dinonaktifkan untuk pengguna tanpa izin `manage`; **403** lintas-company ditampilkan sebagai "tidak ditemukan".
