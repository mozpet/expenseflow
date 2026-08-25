# 03 — Web Frontend (React Dashboard)

Path project: `expenseflow-web/`

## Tech Stack

| Aspek | Nilai |
|-------|-------|
| Framework | React 19 + TypeScript 5.8 |
| Build tool | Vite 6 (`npm run dev` → port 3000, host 0.0.0.0) |
| Styling | Tailwind CSS 4 (`@tailwindcss/vite`), `lucide-react` untuk ikon, `motion` untuk animasi |
| Chart | Recharts 3 |
| Peta | Leaflet 1.9 + react-leaflet 5 |
| Lainnya | express (preview server), dotenv |

> Catatan: aplikasi **tidak memakai router library** — navigasi memakai state `activeView` dengan `switch-case` di `src/App.tsx`, komponen di-*lazy load* untuk code splitting.

## Struktur Folder

```
expenseflow-web/
├── index.html                  → entry HTML
├── vite.config.ts              → konfigurasi Vite + plugin React + Tailwind
├── src/
│   ├── main.tsx                → bootstrap React
│   ├── App.tsx                 → layout shell, sidebar, switch antar view
│   ├── types.ts                → tipe TS frontend
│   ├── data.ts                 → data default/mock lokal
│   ├── auth/AuthContext.tsx    → context auth global (token + user)
│   ├── components/             → 19 komponen halaman & dialog
│   └── services/
│       ├── api.ts              → wrapper fetch: BASE_URL, header token & X-Platform, download blob
│       ├── endpoints.ts        → fungsi pemanggil API per resource (authApi, receiptApi, dst.)
│       └── mappers.ts          → transformasi JSON backend → tipe frontend
└── dist/                       → hasil build produksi
```

## Komponen Halaman (`src/components/`)

| File | View key | Fungsi |
|------|----------|--------|
| `LoginPage.tsx` | — | Form login (simpan token + expiry) |
| `ReceiptInbox.tsx` | `inbox` | Approval struk masuk (Finance) |
| `ReceiptHistory.tsx` | `riwayat-struk` | Riwayat semua struk + filter status + gambar asli |
| `InvoiceInbox.tsx` | `invoice-inbox` | Invoice menunggu approval |
| `InvoiceInput.tsx` | `input-invoice` | Input invoice manual + item rincian |
| `InvoiceScan.tsx` | `scan-invoice` | Scan invoice (OCR) |
| `InvoiceHistory.tsx` | `riwayat-invoice` | Riwayat invoice |
| `Reports.tsx` | `laporan` | Laporan presensi + export CSV + chart (Recharts) |
| `AuditLogView.tsx` | `auditlog` | Audit log aktivitas |
| `NotificationsView.tsx` | `notif` | Notifikasi (mark read / delete) |
| `SettingsView.tsx` / `SettingsManagement.tsx` | `setting` | Threshold klaim & pengaturan presensi kantor |
| `KaryawanManagement.tsx` | `karyawan` | CRUD karyawan, aktif/nonaktif, reset password |
| `MasterVendor.tsx` | `master-vendor` | Master vendor + toggle aktif |
| `AttendanceManagement.tsx` | `presensi` | Dashboard presensi: hari ini, WFH/radius toggle, cuti approval, saldo cuti, kalender libur/cuti bersama, pengaturan kantor |
| `ShiftManagement.tsx` | `shift` | Template shift, assignment, roster, kalender shift bulanan |
| `OvertimeApprovalView.tsx` | `overtime` | Approval lembur |
| `DeviceChangeApprovalView.tsx` | `device-changes` | Approval pindah perangkat |
| `AnalyticsCharts.tsx` | — | Komponen grafik analitik |
| `ConfirmationDialog.tsx` | — | Dialog konfirmasi generik |

Role yang dapat mengakses dashboard: `finance`, `hrd`, `admin`, `super_admin` (divalidasi backend; menu disesuaikan role di shell).

## Auth Handling

- `AuthContext.tsx`: menyimpan token, expiry, dan user di localStorage; auto-logout saat token kedaluwarsa (24 jam).
- Setiap request membawa `Authorization: Bearer <token>` + header `X-Platform: web` (lihat `services/api.ts`).
- Tidak ada route guard terpisah — `App.tsx` merender `LoginPage` bila belum ada user.

## API Layer (`src/services/endpoints.ts`)

Semua endpoint di-prefix `BASE_URL/api/v1`. Ringkasan per objek:

- `authApi` — login, me, logout
- `receiptApi` — inbox `/dashboard/receipts`, all, show, approve, reject, fetch image blob
- `invoiceApi` — list/show/create/approve/reject `/dashboard/invoices*`
- `vendorApi` — list/create/update/toggle `/dashboard/vendors*`
- `userApi` — list/create/update/deactivate/activate/reset-password `/admin/users*`
- `notificationApi` — list/markAllRead/markRead/destroy `/dashboard/notifications*`
- `activityLogApi` — list `/dashboard/activity-logs`
- `attendanceApi` — today, users(+all), toggle-wfh/radius, leaves (+approve/reject/document), leave-balances, report/export CSV, summary, settings CRUD, holidays CRUD, collective-leave detail
- `shiftApi` — template shift CRUD + toggle-active, users-per-shift, roster, history, assign/bulk-assign, update/destroy assignment, effective-schedule, calendar
- `overtimeApi` — list/approve/reject overtime approvals
- `deviceChangeApi` — list/approve/reject device change requests
- `settingsApi` — get/update threshold perusahaan

Daftar lengkap method+path ada di [06-API-ENDPOINTS.md](06-API-ENDPOINTS.md).

## Menjalankan Web

```bash
cd expenseflow-web
npm install
npm run dev        # http://localhost:3000 (LAN: http://<ip>:3000)
npm run build      # produksi ke dist/
npm run lint       # type-check tsc --noEmit
```

Base URL API dikonfigurasi di `src/services/api.ts` (`BASE_URL`) — arahkan ke host backend Laravel.
