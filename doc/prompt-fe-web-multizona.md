# Prompt — Frontend Web: Presensi Multi-Zona Waktu (WIB/WITA/WIT)

> Salin seluruh blok di bawah ini ke sesi Claude Code baru dengan working directory
> `E:\koding\coba\backend-gawe\expenseflow-web`.

---

Backend presensi ExpenseFlow sudah selesai dibuat sadar zona waktu untuk ketiga zona Indonesia
(WIB/WITA/WIT) dan sudah teruji (12 test, 105 assertion, suite penuh hijau). Sekarang saya butuh
sisi **web dashboard**-nya.

**Baca dulu sebelum menulis kode:** `doc/rules.md` section **"Presensi Multi-Zona Waktu (WIB /
WITA / WIT) — 2026-10-08"** (sekitar baris 2753 sampai akhir file) dan
`doc/06-API-ENDPOINTS.md` bagian "Pengaturan Kantor", "Laporan", "Koreksi Presensi Manual HRD".
Keduanya adalah kontrak API yang mengikat — jangan menebak bentuk payload, dan jangan mengubah
backend.

## Dua aturan yang tidak boleh dilanggar

1. **Zona otoritatif adalah zona kantor cabang penempatan karyawan**, bukan zona laptop HRD dan
   bukan zona lokasi fisik karyawan. Semua jam yang dikirim backend **sudah** dalam jam lokal
   cabang.
2. **Web tidak boleh mengonversi zona sendiri.** Tidak ada `new Date(v).getHours()`, tidak ada
   `toLocaleTimeString()`, tidak ada library zona. Seluruh teks untuk pengguna berasal dari
   payload API; tugas web hanya menampilkan. Ini aturan proyek, bukan preferensi gaya.

## Pekerjaan 1 — Dropdown zona waktu di form kantor cabang

File: `src/components/SettingsManagement.tsx`

Endpoint baru yang sudah tersedia: `GET /dashboard/attendance/timezones?latitude=&longitude=`

```json
{
  "timezones": [
    { "id": "Asia/Jakarta", "label": "WIB", "name": "Waktu Indonesia Barat",
      "offset_minutes": 420, "regions": "Sumatra, Jawa, Kalimantan Barat & Tengah",
      "is_suggested": false }
  ],
  "storage": "Asia/Jakarta",
  "suggested": { "timezone": "Asia/Makassar", "label": "WITA", "source": "box" }
}
```

Yang perlu dibuat:

- Tambahkan `timezone: ''` ke state `form` (lihat pola `office_longitude` di `:122`), ke pengisian
  saat edit (`:211`, `o.timezone ?? ''`), dan ke payload submit (`:446`).
- Ambil katalog dari endpoint di atas dan render sebagai `<select>`. **Katalog harus datang dari
  server, jangan di-hardcode** — begitu `config/timezones.php` berubah, web ikut tanpa rilis ulang.
  Tampilkan `label` + `name` + `regions` di tiap opsi supaya HRD paham pilihannya.
- Komponen peta untuk memilih lokasi kantor sudah ada (lihat `lng={form.office_longitude}` di
  `:764`). Begitu HRD menjatuhkan pin / mengubah latitude-longitude, panggil ulang endpoint dengan
  koordinat itu lalu **tandai opsi `is_suggested`** (mis. badge "Disarankan") dan **isi otomatis**
  bila HRD belum memilih apa pun. Kalau HRD sudah memilih manual, jangan ditimpa.
- `source` pada `suggested` bernilai `box` | `longitude` | `default`. Nilai `box` berarti saran
  datang dari kotak pengecualian provinsi — tampilkan keterangan singkat, karena ini kasus yang
  paling sering membuat HRD ragu: **Bali di ~115°E tapi WITA**, **Maluku di ~128°E tapi WIT**,
  serta Kalimantan Selatan (WITA) vs Kalimantan Barat (WIB).
- Field `timezone` **opsional** di API. Bila dikosongkan, backend menebaknya dari koordinat.
  Jadi UI tidak boleh memaksa HRD memilih — biarkan "ikuti saran otomatis" sebagai jalur normal.
- Nilai di luar katalog ditolak **422** oleh backend (bukan di-fallback diam-diam), jadi
  `<select>` yang terkunci ke katalog server sudah cukup; tidak perlu validasi zona sendiri.

⚠️ **`timezone` adalah field berbahaya.** Tambahkan satu entri ke `DANGEROUS_LABELS`
(`:170-192`):

```ts
timezone: 'Zona Waktu Kantor',
```

Tanpa entri ini, dialog konfirmasi akan menampilkan nama field mentah `"timezone"` karena
fallback di `:1540` adalah `DANGEROUS_LABELS[f] ?? f`. Mekanisme gerbangnya sendiri
(`confirm_dangerous: "SIMPAN"` → 422 + `requires_confirmation` + `dangerous_changed_fields[]`)
sudah generik dan **tidak perlu diubah**. Catatan: backend menormalisasi dulu sebelum
membandingkan, jadi cabang lama ber-`timezone` NULL yang dikirim sebagai `'Asia/Jakarta'` **tidak**
dianggap perubahan berbahaya — jangan tambahkan deteksi sendiri yang membuatnya jadi berbahaya.

- Respons settings (list/show/store/update) kini memuat `timezone`, `timezone_label`
  (`WIB`/`WITA`/`WIT`), `timezone_offset_minutes` (420/480/540). Tampilkan `timezone_label`
  pada kartu daftar kantor, di sebelah koordinat (`:578`).
- ⚠️ `POST /settings` mengembalikan objek baru di key **`setting`**, bukan `data`.

File pendukung:
- `src/services/endpoints.ts` — object `settings` ada di `:519-531`. Tambahkan pemanggil
  `timezones(params?)` mengikuti pola `apiGet` yang sudah ada.
- `src/services/apiCache.ts` — katalog zona sangat jarang berubah. Tambahkan aturan cache
  mengikuti pola entri `attendance/settings` di `:26-30` (TTL 5 menit, `swr: false`), dengan tag
  tersendiri supaya tidak ikut ter-invalidasi saat kantor disimpan.

## Pekerjaan 2 — Perbaiki dua helper waktu yang rusak (utang teknis #21)

File: `src/components/AttendanceManagement.tsx`

Ini bug yang sudah tercatat di `doc/kekurangnAttendance.md:1035` dan baru sekarang bisa ditutup.
Keduanya memakai `new Date(...)` sehingga **mengikuti zona laptop HRD** — HRD yang laptopnya
UTC+8 melihat jam presensi bergeser 1 jam, dan form koreksi menyimpan jam yang salah.

`fmtTime()` di `:371-376`:
```ts
const d = new Date(v);
return d.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' });
```

`toTimeInput()` di `:2015-2020`:
```ts
const d = new Date(v);
return `${pad2(d.getHours())}:${pad2(d.getMinutes())}`;
```

Ganti keduanya menjadi **pemotongan string** dari payload (`String(v).slice(11, 16)` — pola itu
sudah ada sebagai fallback di `toTimeInput`), dan tampilkan `timezone_label` dari baris di
sebelahnya. Jam dari backend sudah jam lokal cabang; memparsenya sebagai `Date` justru merusaknya.

`toTimeInput()` dipakai mengisi form koreksi (`:2042-2043`) — di sini salah konversi bukan cuma
tampilan yang bergeser, tapi **data presensi yang tersimpan salah**. Prioritaskan yang ini.

## Pekerjaan 3 — Laporan & tabel presensi

Setiap baris dari `GET /dashboard/attendance/report` dan `/summary` kini membawa:
`check_in_time_local`, `check_out_time_local`, `timezone`, `timezone_label`.

- Tampilkan jam dari field `*_local` + `timezone_label` di sebelahnya. Satu laporan bisa memuat
  karyawan dari beberapa cabang dengan zona berbeda, jadi label **per baris**, bukan satu label
  di header tabel.
- Ekspor CSV sudah dibuat backend (kolom terakhir `Zona`) — tidak ada pekerjaan di web.
- Default `month`/`year` dasbor sengaja tetap zona penyimpanan agar satu perusahaan melihat satu
  kalender. **Jangan** diubah mengikuti zona cabang.

## Pekerjaan 4 — Modal koreksi presensi

Respons `POST /dashboard/attendance/corrections` memuat `timezone`, `timezone_label`,
`timezone_offset_minutes`, diambil dari **snapshot presensi itu** (`snap_timezone`) — jadi
mengoreksi presensi Januari memakai zona yang berlaku saat itu, bukan zona cabang hari ini.
Tampilkan labelnya di dekat input jam supaya HRD tahu jam mana yang sedang ia isi.

## Yang TIDAK perlu dikerjakan

- Jangan ubah backend apa pun.
- Jangan ubah default kalender dasbor HRD ke zona cabang (alasannya di `doc/rules.md` §2).
- Header CSV audit log sengaja `Waktu Server (WIB)` — audit lintas cabang pakai satu zona render.

Tolong kerjakan bertahap: Pekerjaan 2 lebih dulu (bug data nyata), lalu 1, 3, 4. Setelah selesai
jalankan lint/build yang biasa dipakai di repo ini dan laporkan hasilnya apa adanya.


Ya, untuk task ini sudah selesai:

- Command attendance:seed-dummy-history sudah dibuat, dijalankan, dan hasilnya diverifikasi bersih (14,162 baris presensi company 1, proporsional di semua 199 karyawan, tidak ada yang bolong).
- Tidak ada data user/payroll/payslip yang dihapus atau diubah paksa.
- File command sudah dirapikan (deskripsi stale diperbaiki).

Satu langkah manual yang masih perlu kamu lakukan: payroll run periode September 2026 (status calculated, belum paid) perlu di-recalculate ulang lewat UI/endpoint Payroll supaya slip gajinya menyerap data presensi baru ini — command seeding tidak otomatis memicu kalkulasi ulang. Payroll Agustus (status paid) sengaja tidak disentuh.

Setelah itu dicek, 4 gap integrasi payroll dari audit awal (rekrutmen→payroll, import massal tanpa gaji, laporan tanpa data biaya payroll, belum ada tunjangan shift malam) masih menunggu — tinggal bilang kalau mau lanjut ke salah satunya.