# 11 - Frontend Caching Strategy & Page Audit

> **Dokumen Panduan Strategi Caching Frontend (ExpenseFlow Web)**  
> **Tanggal:** 3 September 2026  
> **Tujuan:** Memetakan tingkat keamanan caching pada seluruh halaman web, mencegah bug *stale data* (data basi), serta merancang arsitektur *Smart In-Memory Caching* untuk sesi pengembangan berikutnya.

---

## 1. Filosofi Dasar Caching Frontend

Tujuan utama caching frontend adalah:
1. **User Experience Instan:** Perpindahan antar-tab terjadi dalam **0.001 detik** tanpa layar berkedip (*no flicker*) dan tanpa *loading spinner* berulang-ulang.
2. **Efisiensi Server & Database:** Menghilangkan 60%–80% request `GET` yang meminta data yang sama persis dalam hitungan detik.
3. **Zero Stale Data (Bebas Bug):** Data harus **selalu akurat** saat terjadi perubahan data (*mutation*), seperti klik *Simpan*, *Approve*, *Reject*, atau *Delete*.

---

## 2. Matriks Evaluasi Caching Seluruh Halaman (17 Halaman)

| No | Kode Halaman (`activePage`) | Nama Halaman | Kategori Data | Tingkat Keamanan | Rekomendasi TTL | Kapan Cache Wajib Dihapus (*Invalidated*)? |
|:---|:---|:---|:---|:---:|:---:|:---|
| 1 | `setting` | **Pengaturan Aturan & Kantor** | Data Master | 🟢 **Sangat Aman** | 5 – 10 Menit | Setelah simpan/update kantor, jadwal, atau toleransi |
| 2 | `shift` | **Shift & Jadwal Kerja** | Data Master / Jadwal | 🟢 **Sangat Aman** | 5 – 10 Menit | Setelah tambah/edit/hapus shift atau roster mingguan |
| 3 | `master-vendor` | **Master Vendor** | Data Master | 🟢 **Sangat Aman** | 5 – 10 Menit | Setelah tambah/edit/hapus data vendor |
| 4 | `karyawan` | **Manajemen Karyawan** | Data Referensi | 🟢 **Sangat Aman** | 3 – 5 Menit | Setelah tambah karyawan, ubah jabatan/divisi, atau resign |
| 5 | `rekrutmen` | **Rekrutmen & Lowongan** | Data Administratif | 🟢 **Sangat Aman** | 3 – 5 Menit | Setelah posting lowongan atau update status pelamar |
| 6 | `laporan` | **Laporan Gabungan & Grafik** | Data Analitik / Agregat | 🟢 **Sangat Aman** | 5 – 15 Menit | Saat user mengganti filter tanggal atau menekan tombol Refresh |
| 7 | `riwayat-struk` | **Riwayat Struk Reimbursement** | Data Arsip / Historis | 🟢 **Sangat Aman** | 3 – 5 Menit | Saat ada pembayaran/reimburse baru yang diselesaikan |
| 8 | `riwayat-invoice` | **Riwayat Invoice** | Data Arsip / Historis | 🟢 **Sangat Aman** | 3 – 5 Menit | Saat ada pelunasan invoice baru |
| 9 | `auditlog` | **Audit Log Sistem** | Data Historis (Append-Only) | 🟢 **Sangat Aman** | 2 – 5 Menit | Saat user mengganti filter severity, tanggal, atau klik Refresh |
| 10 | `presensi` | **Presensi & Cuti Karyawan** | Data Operasional Harian | 🟡 **Aman Bersyarat** | 30 – 60 Detik | Gunakan *Stale-While-Revalidate* (SWR); hapus saat approve cuti |
| 11 | `inbox` | **Inbox Struk Reimbursement** | Antrean Approval | 🟡 **Aman Bersyarat** | 15 – 30 Detik | Wajib dibuang seketika setelah klik *Approve*, *Reject*, atau *Pay* |
| 12 | `invoice-inbox` | **Inbox Invoice Masuk** | Antrean Approval | 🟡 **Aman Bersyarat** | 15 – 30 Detik | Wajib dibuang seketika setelah klik *Approve*, *Reject*, atau *Pay* |
| 13 | `overtime` | **Approval Lembur** | Antrean Approval | 🟡 **Aman Bersyarat** | 15 – 30 Detik | Wajib dibuang seketika setelah HRD *Approve* / *Reject* lembur |
| 14 | `device-changes` | **Pindah Perangkat** | Antrean Keamanan | 🟡 **Aman Bersyarat** | 15 – 30 Detik | Wajib dibuang seketika setelah approve/reject reset device |
| 15 | `notif` | **Notifikasi Sistem** | Notifikasi Interaktif | 🟡 **Aman Bersyarat** | 15 – 30 Detik | Wajib dibuang saat klik *Tandai Sudah Dibaca* atau ada push notif |
| 16 | `input-invoice` | **Input Invoice Manual** | Form Entry | 🔴 **Dilarang Cache** | 0 Detik (No Cache) | Form input murni. Jangan pernah meng-cache state form yang belum disubmit |
| 17 | `scan-invoice` | **Scan OCR Invoice** | Pemrosesan File / AI | 🔴 **Dilarang Cache** | 0 Detik (No Cache) | Hasil scan bersifat unik per file struk/invoice |

---

## 3. Rincian & Analisis Mendalam Tiap Halaman

---

### KELOMPOK 1: 🟢 SANGAT AMAN DI-CACHE (Prioritas Caching Utama)

Halaman di kelompok ini berisi data yang **sangat jarang berubah** dalam operasional menit-ke-menit. Meng-cache halaman ini memberikan peningkatan performa paling drastis tanpa risiko merusak data.

#### 1. Pengaturan Aturan & Kantor (`setting` / `SettingsManagement.tsx`)
* **Karakteristik Data:** Konfigurasi kantor cabang, titik koordinat GPS, radius presensi, jam kerja default, toleransi keterlambatan, dan cuti bersama.
* **Tingkat Keamanan:** 🟢 **Sangat Aman (100%)**
* **Rekomendasi TTL:** **5 – 10 Menit**.
* **Mekanisme Invalidation:**
  * Buang cache saat tombol *"Simpan Perubahan"* berhasil dieksekusi (`PUT /api/v1/attendance-settings/{id}`).
  * Buang cache saat kantor cabang baru ditambahkan (`POST /api/v1/attendance-settings`).

#### 2. Shift & Jadwal Kerja (`shift` / `ShiftManagement.tsx`)
* **Karakteristik Data:** Master nama shift (Pagi, Siang, Malam), jam masuk/pulang per shift, penugasan karyawan ke shift.
* **Tingkat Keamanan:** 🟢 **Sangat Aman**
* **Rekomendasi TTL:** **5 – 10 Menit**.
* **Mekanisme Invalidation:**
  * Buang cache saat admin membuat/mengedit master shift (`POST/PUT /api/v1/shifts`).
  * Buang cache saat ada perubahan jadwal shift mingguan/bulanan.

#### 3. Master Vendor (`master-vendor` / `MasterVendor.tsx`)
* **Karakteristik Data:** Daftar supplier/vendor, nomor rekening, kontak PIC, NPWP, kategori vendor.
* **Tingkat Keamanan:** 🟢 **Sangat Aman**
* **Rekomendasi TTL:** **5 – 10 Menit**.
* **Mekanisme Invalidation:**
  * Buang cache saat vendor baru ditambahkan, diedit, atau dihapus (`POST/PUT/DELETE /api/v1/vendors`).

#### 4. Manajemen Karyawan (`karyawan` / `KaryawanManagement.tsx`)
* **Karakteristik Data:** Profil karyawan, NIK, jabatan, divisi, kantor penempatan, status aktif.
* **Tingkat Keamanan:** 🟢 **Sangat Aman**
* **Rekomendasi TTL:** **3 – 5 Menit**.
* **Mekanisme Invalidation:**
  * Buang cache saat ada karyawan baru ditambahkan, dinonaktifkan (resign), atau diubah kantor/jabatannya.

#### 5. Rekrutmen & Lowongan (`rekrutmen` / `RecruitmentManagement.tsx`)
* **Karakteristik Data:** Daftar posisi lowongan kerja, kriteria pekerjaan, daftar pelamar, pipeline tahap wawancara.
* **Tingkat Keamanan:** 🟢 **Sangat Aman**
* **Rekomendasi TTL:** **3 – 5 Menit**.
* **Mekanisme Invalidation:**
  * Buang cache saat status pelamar dipindahkan (misal: *Screening* $\rightarrow$ *Interview*).

#### 6. Laporan Gabungan & Grafik (`laporan` / `Reports.tsx`)
* **Karakteristik Data:** Ringkasan biaya reimburse bulanan, grafik pengeluaran per kategori, rekap absensi bulanan.
* **Tingkat Keamanan:** 🟢 **Sangat Aman**
* **Rekomendasi TTL:** **5 – 15 Menit** (per rentang filter tanggal).
* **Catatan Khusus:** Kunci cache (*cache key*) wajib menyertakan parameter filter (contoh: `reports_2026-08_dept-finance`). Sediakan tombol *"Refresh Laporan"* manual.

#### 7. Riwayat Struk Reimbursement (`riwayat-struk` / `ReceiptHistory.tsx`)
* **Karakteristik Data:** Data struk yang sudah berstatus final (*Paid* atau *Rejected*). Data masa lalu tidak akan diedit lagi.
* **Tingkat Keamanan:** 🟢 **Sangat Aman**
* **Rekomendasi TTL:** **3 – 5 Menit**.

#### 8. Riwayat Invoice (`riwayat-invoice` / `InvoiceHistory.tsx`)
* **Karakteristik Data:** Invoice masa lalu yang sudah berstatus lunas (*Paid*) atau dibatalkan.
* **Tingkat Keamanan:** 🟢 **Sangat Aman**
* **Rekomendasi TTL:** **3 – 5 Menit**.

#### 9. Audit Log Sistem (`auditlog` / `AuditLogView.tsx`)
* **Karakteristik Data:** Log aktivitas bersifat *append-only* (hanya bertambah ke belakang, tidak ada edit pada baris log lama).
* **Tingkat Keamanan:** 🟢 **Sangat Aman**
* **Rekomendasi TTL:** **2 – 5 Menit**.
* **Mekanisme Invalidation:**
  * Cache per kombinasi filter (`search`, `severity`, `category`, `date`).
  * Sediakan tombol *"Refresh"* yang me-reset cache saat auditor ingin melihat log detik terakhir.

---

### KELOMPOK 2: 🟡 AMAN BERSYARAT (Wajib Pola SWR / Cache Singkat)

Halaman pada kelompok ini merupakan **antrean kerja harian (approval inbox)** dan **pencatatan real-time**. Jika di-cache terlalu lama tanpa invalidation, pengguna akan melihat data usang (*stale data*) yang memicu kebingungan.

#### 10. Presensi & Cuti Karyawan (`presensi` / `AttendanceManagement.tsx`)
* **Karakteristik Data:** Karyawan bisa melakukan check-in/out setiap saat dari HP. Status cuti menunggu persetujuan HRD.
* **Tingkat Keamanan:** 🟡 **Aman Bersyarat (Gunakan SWR)**
* **Rekomendasi TTL:** **30 – 60 Detik**.
* **Strategi:**
  * **Stale-While-Revalidate (SWR):** Saat tab dibuka, tampilkan seketika data dari memori (0ms), lalu di latar belakang jalankan re-fetch otomatis untuk memperbarui status terbaru.
  * Hapus cache seketika jika HRD melakukan klik *Approve Cuti* atau *Reject Cuti*.

#### 11. Inbox Struk Reimbursement (`inbox` / `ReceiptInbox.tsx`)
* **Karakteristik Data:** Antrean struk karyawan yang menunggu approval Finance.
* **Tingkat Keamanan:** 🟡 **Aman Bersyarat**
* **Rekomendasi TTL:** **15 – 30 Detik**.
* **Syarat Mutlak Invalidation:**
  * Begitu Finance klik **Approve**, **Reject**, atau **Pay** $\rightarrow$ **HAPUS CACHE INBOX SEKETIKA**. Jangan sampai struk yang baru di-approve masih muncul di layar.

#### 12. Inbox Invoice Masuk (`invoice-inbox` / `InvoiceInbox.tsx`)
* **Karakteristik Data:** Antrean invoice vendor yang menunggu persetujuan Direktur/Finance.
* **Tingkat Keamanan:** 🟡 **Aman Bersyarat**
* **Rekomendasi TTL:** **15 – 30 Detik**.
* **Syarat Mutlak Invalidation:**
  * Hapus cache seketika saat status invoice berubah (di-approve/di-bayar).

#### 13. Approval Lembur (`overtime` / `OvertimeApprovalView.tsx`)
* **Karakteristik Data:** Pengajuan lembur karyawan yang menunggu keputusan atasan.
* **Tingkat Keamanan:** 🟡 **Aman Bersyarat**
* **Rekomendasi TTL:** **15 – 30 Detik**.
* **Syarat Mutlak Invalidation:**
  * Hapus cache saat tombol *Approve Lembur* atau *Reject Lembur* ditekan.

#### 14. Pindah Perangkat / Device Binding (`device-changes` / `DeviceChangeApprovalView.tsx`)
* **Karakteristik Data:** Permohonan reset IMEI / Device ID dari karyawan yang ganti HP baru.
* **Tingkat Keamanan:** 🟡 **Aman Bersyarat**
* **Rekomendasi TTL:** **15 – 30 Detik**.
* **Syarat Mutlak Invalidation:**
  * Hapus cache saat permohonan disetujui atau ditolak.

#### 15. Notifikasi Sistem (`notif` / `NotificationsView.tsx`)
* **Karakteristik Data:** Lonceng notifikasi badge angka unread.
* **Tingkat Keamanan:** 🟡 **Aman Bersyarat**
* **Rekomendasi TTL:** **15 – 30 Detik**.
* **Syarat Mutlak Invalidation:**
  * Hapus cache saat tombol *"Tandai Semua Dibaca"* ditekan.

---

### KELOMPOK 3: 🔴 DILARANG DI-CACHE (No Cache)

Halaman di kelompok ini melibatkan form input baru atau pemrosesan media/file.

#### 16. Input Invoice Manual (`input-invoice` / `InvoiceInput.tsx`)
* **Alasan:** Ini adalah form pengisian data baru. Meng-cache state form yang belum dikirim bisa menyebabkan isian form sebelumnya tidak sengaja tertinggal untuk invoice berikutnya.

#### 17. Scan OCR Invoice (`scan-invoice` / `InvoiceScan.tsx`)
* **Alasan:** Melibatkan upload berkas PDF/gambar ke AI OCR. Setiap file memiliki nomor invoice, tanggal, dan nominal yang unik.

---

## 4. Rencana Arsitektur untuk Sesi Baru (Smart In-Memory Cache)

Untuk sesi berikutnya, kita disarankan membangun modul cache sederhana di lapisan [src/services/api.ts](file:///e:/koding/coba/backend-gawe/expenseflow-web/src/services/api.ts) dengan spesifikasi teknis berikut:

### Desain Struktur Cache (`apiCache.ts`)

```typescript
interface CacheEntry<T> {
  data: T;
  timestamp: number;
  ttl: number; // Durasi berlaku dalam milidetik
}

// In-Memory Map (Tersimpan di RAM browser, hilang saat refresh / logout)
const cacheStore = new Map<string, CacheEntry<any>>();

// Helper Invalidation Berdasarkan Tag/Prefix
export function invalidateCache(prefix: string) {
  for (const key of cacheStore.keys()) {
    if (key.startsWith(prefix)) {
      cacheStore.delete(key);
    }
  }
}
```

### Aturan Auto-Invalidation pada Mutation:
* Saat memanggil `POST/PUT/DELETE /api/v1/attendance-settings*` $\rightarrow$ Otomatis panggil `invalidateCache('attendance-settings')`.
* Saat memanggil `POST/PUT/DELETE /api/v1/shifts*` $\rightarrow$ Otomatis panggil `invalidateCache('shifts')`.
* Saat memanggil `POST /api/v1/receipts/*/approve` $\rightarrow$ Otomatis panggil `invalidateCache('receipts')`.
* Saat `logout()` $\rightarrow$ Panggil `cacheStore.clear()` (Pembersihan total).

---

## 5. Status Implementasi (*Action Plan*)

1. ✅ **Fase 1 (SELESAI — 2026-09-03):** Penerapan Smart Caching pada **Kelompok 1 (Data Master: Setting Kantor, Shift, Vendor, Karyawan, Rekrutmen, Laporan, Riwayat Struk, Audit Log)** via [apiCache.ts](file:///e:/koding/coba/backend-gawe/expenseflow-web/src/services/apiCache.ts).
   * *Hasil:* Menu setting dan master data langsung terbuka instan 0ms tanpa loading spinner dan tanpa network request berulang.
2. ✅ **Fase 2 (SELESAI — 2026-09-03):** Menambahkan *Mutation Invalidation Listener* di [api.ts](file:///e:/koding/coba/backend-gawe/expenseflow-web/src/services/api.ts) (`handleMutationInvalidation`).
   * *Hasil:* Setiap tombol Simpan, Ubah, Hapus, Approve, Reject, Pay, atau Reset Password otomatis membersihkan cache terkait (zero stale data).
3. ✅ **Fase 3 (SELESAI — 2026-09-03):** Penerapan pola *Stale-While-Revalidate (SWR)* pada **Kelompok 2 (Presensi & Inbox Approval)** + Request Deduplication in-flight di [apiCache.ts](file:///e:/koding/coba/backend-gawe/expenseflow-web/src/services/apiCache.ts).
   * *Hasil:* Tab presensi dan approval inbox terbuka instan 0ms menampilkan data cache sembari memperbarui data secara halus di latar belakang, serta membersihkan seluruh cache saat `logout()`.

---
*Dokumen ini telah diimplementasikan penuh pada modul `apiCache.ts`, `api.ts`, dan `endpoints.ts`.*

