/**
 * ExpenseFlow Career Portal - Configuration
 * Ubah konfigurasi ini sesuai endpoint backend dan ID perusahaan Anda.
 */
const CONFIG = {
  // URL backend API Laravel
  API_BASE_URL: 'http://localhost:8000/api/v1',

  // ID perusahaan (sesuai company_id di tabel companies)
  COMPANY_ID: 1,

  // Nama perusahaan default jika belum termuat dari backend
  COMPANY_NAME: 'ExpenseFlow',

  // Maksimal ukuran file CV (dalam bytes: 5MB = 5 * 1024 * 1024)
  MAX_FILE_SIZE: 5 * 1024 * 1024,
};

window.CONFIG = CONFIG;
