# ExpenseFlow — Public Career Portal (Static HTML/CSS/JS + Tailwind)

Portal karir publik statis berbasis **HTML5**, **Vanilla JavaScript**, dan **Tailwind CSS (CDN)**.
Dirancang sangat ringan, tanpa *build step*, tanpa *node_modules*, sehingga **sangat fleksibel untuk langsung dibuka, di-host, atau digabungkan ke dalam website Company Profile apa pun** (WordPress, Laravel Blade, PHP native, atau static landing page).

---

## 📁 Struktur File

```
expenseflow-public/
├── index.html        ← Halaman daftar lowongan kerja (Hero, Filter, Search, Grid Lowongan, Culture)
├── detail.html       ← Halaman detail lowongan + Form lamaran kerja 3-step (Upload PDF CV)
├── css/
│   └── style.css     ← Styling kustom, glassmorphism, & animasi pelengkap Tailwind
├── js/
│   ├── config.js     ← Konfigurasi API Base URL dan Company ID
│   ├── api.js        ← Wrapper fetch API & helper fungsi (formatting rupiah, tanggal, toast)
│   ├── jobs.js       ← Controller untuk index.html (search, filter tipe kerja, render kartu, pagination)
│   └── apply.js      ← Controller untuk detail.html (load detail, multi-step form, drag-drop CV, submit)
└── README.md
```

---

## ⚙️ Cara Konfigurasi

Buka file [`js/config.js`](js/config.js) dan sesuaikan pengaturannya:

```javascript
const CONFIG = {
  // URL backend Laravel API
  API_BASE_URL: 'http://localhost:8000/api/v1',

  // ID perusahaan (sesuai company_id di database)
  COMPANY_ID: 1,

  // Nama perusahaan default
  COMPANY_NAME: 'ExpenseFlow',

  // Maksimal ukuran file CV (dalam bytes, default 5MB)
  MAX_FILE_SIZE: 5 * 1024 * 1024,
};
```

---

## 🚀 Cara Menjalankan

### Opsi 1: Buka Langsung di Browser
Cukup *double click* file `index.html` atau buka via browser Anda!

### Opsi 2: Menggunakan Live Server / Python / Nginx / Apache
```bash
# Contoh menggunakan python simple server di folder expenseflow-public:
python -m http.server 8080

# Atau menggunakan PHP built-in server:
php -S localhost:8080
```
Buka browser di: `http://localhost:8080`

---

## 🧩 Cara Menggabungkan ke Website Company Profile

### 1. Menjadikan Subfolder di Website yang Ada
Cukup salin folder `expenseflow-public` ke folder publik website Anda (misalnya dinamai `/karir/`):
- `https://namaperusahaan.com/karir/` → akan menampilkan `index.html`
- `https://namaperusahaan.com/karir/detail.html?id=1` → menampilkan halaman detail & form lamaran

### 2. Mengintegrasikan ke Laravel Blade / WordPress
- Salin elemen `<div id="jobs-container">` dan filter dari `index.html` ke file view/blade Anda.
- Sertakan script:
  ```html
  <script src="/karir/js/config.js"></script>
  <script src="/karir/js/api.js"></script>
  <script src="/karir/js/jobs.js"></script>
  ```
- Endpoint backend otomatis terhubung dan menampilkan lowongan kerja yang di-publish oleh tim HRD!

---

## ✨ Fitur Utama
1. **Daftar Lowongan Dinamis**: Real-time memuat posisi yang berstatus `open` dari backend.
2. **Pencarian Cepat & Filter**: Filter berdasarkan Tipe Kerja (*Full Time*, *Part Time*, *Kontrak*, *Magang*) dan pencarian kata kunci dengan *debouncing*.
3. **Form Lamaran 3-Tahap**:
   - **Tahap 1**: Data Diri (Nama, Email, No. HP, Domisili) dengan validasi instan.
   - **Tahap 2**: Kualifikasi (Pendidikan, Pengalaman Kerja, Cover Letter).
   - **Tahap 3**: Upload Berkas CV (PDF max 5MB dengan drag-and-drop) & Ringkasan Data.
4. **Desain Modern & Responsif**: Tampilan gelap (*Dark Mode*) dengan Glassmorphism, Tailwind CSS, dan Lucide Icons.
