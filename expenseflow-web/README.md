<div align="center">
<img width="1200" height="475" alt="GHBanner" src="https://ai.google.dev/static/site-assets/images/share-ais-513315318.png" />
</div>

# Run and deploy your AI Studio app

This contains everything you need to run your app locally.

View your app in AI Studio: https://ai.studio/apps/311ae0e0-6acb-476a-9bad-565f79b61250

## Run Locally (terhubung ke backend Laravel)

**Prasyarat:** Node.js + backend `expenseflow-backend` berjalan.

### 1. Jalankan backend (Laravel)
Di folder `expenseflow-backend`:
```bash
php artisan serve            # default http://localhost:8000
php artisan queue:work       # wajib agar OCR struk diproses
```

### 2. Konfigurasi & jalankan frontend
1. Install dependencies: `npm install`
2. Atur base URL API di `.env` (sudah dibuat):
   ```
   VITE_API_BASE_URL=http://localhost:8000/api/v1
   ```
3. Jalankan: `npm run dev` (port 3000)

### 3. Login
Gunakan akun seed backend (role non-employee), contoh:
- Finance : `andi@majubersama.co.id` / `password`
- HRD     : `dewi@majubersama.co.id` / `password`
- Admin   : `hendra@majubersama.co.id` / `password`
- Super   : `super@majubersama.co.id` / `password`

> Catatan: role `employee` tidak bisa login di web (khusus aplikasi mobile).

## Integrasi API
- Lapisan API: `src/services/api.ts` (fetch + token Bearer + header `X-Platform: web`).
- Endpoint per resource: `src/services/endpoints.ts`.
- Konversi data backend→frontend: `src/services/mappers.ts`.
- Autentikasi: `src/auth/AuthContext.tsx` + `src/components/LoginPage.tsx` (token disimpan di `localStorage`).
