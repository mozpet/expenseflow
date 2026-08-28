# 📋 Roadmap & Arsitektur Modul Rekrutmen ExpenseFlow (HRIS & ATS)

Dokumen ini merangkum arsitektur, alur kerja, fitur yang telah diimplementasikan, serta backlog fitur rekrutmen lanjutan berstandar *Enterprise ATS (Applicant Tracking System)* untuk pertimbangan pengembangan di masa depan.

---

## 🏗️ 1. Arsitektur & Alur Kerja Saat Ini

### A. Alur Publik (Portal Karir Pelamar)
1. **Pencarian Lowongan**:
   - Pelamar mengakses portal karir publik (`expenseflow-public/index.html`).
   - Filter real-time berdasarkan kata kunci (judul, departemen, lokasi) dan tipe pekerjaan (*Full Time, Kontrak, Magang*).
2. **Formulir 2-Langkah (Responsive Mobile & Desktop)**:
   - **Langkah 1 (Data Diri & Profil)**:
     - Nama Lengkap & Jenis Kelamin (*Laki-laki / Perempuan*)
     - Tempat & Tanggal Lahir (dengan hitungan Usia otomatis)
     - Kewarganegaraan (*WNI / WNA*)
     - Alamat Email (Wajib Aktif)
     - No. WhatsApp / HP
     - Jenjang Pendidikan Terakhir & Nama Sekolah / Universitas Terakhir
     - Pengalaman Kerja (Tahun)
     - Ketersediaan Mulai Bekerja (*Notice Period*): *Langsung (Immediate), 1 Minggu, 2 Minggu, 1 Bulan, > 1 Bulan*.
     - **Alamat Domisili Terstruktur & Auto-Detect Kode Pos**:
       - **Kode Pos** di posisi paling atas bagian alamat.
       - Sistem otomatis mendeteksi dan mengisikan Provinsi, Kota/Kabupaten, Kecamatan, dan Kelurahan saat kode pos diketik.
       - Input Alamat Lengkap / Nama Jalan, RT/RW, No. Rumah.

   - **Langkah 2 (Berkas & CV)**:
     - Upload CV PDF (Maks. 5MB, dropzone touch-friendly untuk smartphone/desktop).
     - Surat Lamaran Singkat (*Cover Letter*).
     - Ringkasan profil otomatis sebelum submit.
3. **Proteksi Kuota & Status Lowongan**:
   - Sistem auto-close jika batas maksimal pelamar (`max_applicants`) tercapai atau telah melewati batas akhir (*deadline*).

---

### B. Alur Internal HRD (Dashboard Rekrutmen)
1. **Manajemen Lowongan (`JobPosting`)**:
   - Pembuatan, pengeditan, penutupan, publikasi, dan penghapusan lowongan.
   - Konfigurasi kisaran gaji & visibilitas publik.
   - Konfigurasi email kontak HRD (`contact_email`).
   - Batas kuota pelamar (`max_applicants` nullable / unlimited).
2. **Pipeline Status Seleksi Otomatis (`JobApplication`)**:
   - **1. Baru (`new`)**: Status awal saat pelamar mendaftar.
   - **2. Ditinjau (`reviewed`)**: Otomatis berpindah ke status ini ketika HRD mengklik tombol **"Buka Berkas CV"** untuk pertama kali.
   - **3. Shortlist (`shortlisted`)**: Tahap kandidat lolos seleksi berkas.
   - **4. Ditolak (`rejected`)**: Kandidat tidak memenuhi kualifikasi (tersedia fitur kirim *Rejection Letter* dan hapus berkas fisik).
   - **5. Diterima (`hired`)**: Kandidat resmi diterima bekerja.
3. **Komunikasi & Dokumen**:
   - **Undangan Interview (Email)**: Live preview generator dengan integrasi langsung **Gmail Web (1-Click Compose)** & desktop mailto.
   - **Surat Penolakan Sopan (*Rejection Letter*)**: Template penolakan profesional untuk menjaga *employer branding*.
   - **Surat Penawaran Kerja (*Offering Letter Generator*)**: Generator draft surat penawaran kompensasi, join date, dan benefit dengan fitur Cetak PDF.

---

## 🚀 2. Backlog Fitur Lanjutan untuk Pertimbangan Mendatang (*Enterprise ATS Roadmap*)

Berikut adalah daftar fitur yang disiapkan untuk fase pengembangan berikutnya:

### 1. 🔄 1-Click Convert to Employee (*Onboarding Integration*)
* **Deskripsi**: Ketika status pelamar menjadi **Diterima (`hired`)**, muncul tombol **"Onboarding Karyawan Baru"**.
* **Manfaat**: Sistem secara otomatis mengekstrak Nama, Email, NIK, No. HP, Domisili, Pendidikan, dan Gaji yang disepakati dari data lamaran langsung ke form pendaftaran **Manajemen Karyawan (`users` & kontrak kerja)** tanpa perlu HRD mengetik ulang secara manual.

### 2. 💬 Direct WhatsApp Recruitment Chat
* **Deskripsi**: Tombol integrasi WhatsApp Web / WhatsApp App pada setiap pelamar.
* **Manfaat**: Membuka obrolan WA langsung dengan pesan pembuka ramah untuk konfirmasi cepat kehadiran interview dan pengingat jadwal wawancara.

### 3. ⭐ Scorecard & Assessment Rating (1-5 Bintang)
* **Deskripsi**: Formulir evaluasi wawancara untuk HRD dan User/Manajer divisi.
* **Fitur**:
  - Rating bintang 1 s/d 5.
  - Parameter penilaian: *Skill Teknis, Kemampuan Komunikasi, Sikap/Attitude, Pengalaman Relevan*.
  - Rekomendasi akhir (*Strong Yes, Yes, Neutral, No*).

### 4. 👥 Multi-Interviewer & User Collaboration (HOD Access)
* **Deskripsi**: Akses khusus bagi Kepala Divisi / Manajer (*Head of Department*) untuk melihat berkas pelamar di departemennya, memberikan catatan wawancara, dan merekomendasikan kandidat ke HRD.

### 5. 📊 Analytics Funnel & Recruitment Metrics
* **Deskripsi**: Dashboard statistik performa rekrutmen:
  - **Funnel Conversion Rate**: Rasio persentase dari Total Pelamar $\rightarrow$ Ditinjau $\rightarrow$ Shortlist $\rightarrow$ Interview $\rightarrow$ Diterima.
  - **Time-to-Hire**: Rata-rata durasi waktu dari lowongan dibuka hingga posisi terisi.
  - **Source Tracking**: Sumber kedatangan pelamar (Website Karir, LinkedIn, Jobstreet, Rekomendasi Karyawan).

### 6. 📧 Auto-Responder Email (SMTP Integration)
* **Deskripsi**: Pengiriman email otomatis langsung dari server backend Laravel saat pelamar submit formulir (*"Konfirmasi Lamaran Diterima"*).

---

## 🗄️ 3. Skema Basis Data Terkait

### Tabel `job_postings`
* `id`, `company_id`, `created_by`, `title`, `department`, `location`, `employment_type`, `description`, `requirements`, `salary_min`, `salary_max`, `show_salary`, `max_applicants`, `contact_email`, `status`, `deadline`, `published_at`.

### Tabel `job_applications`
* `id`, `job_posting_id`, `company_id`, `full_name`, `email`, `phone`, `address`, `education`, `institution_name`, `experience_years`, `notice_period`, `expected_salary`, `portfolio_url`, `cover_letter`, `resume_path`, `status`, `notes`, `offering_details`, `reviewed_by`, `reviewed_at`.
