# 13 - Panduan & Arsitektur Fitur Face Recognition & Liveness Detection

Dokumen ini memuat analisis arsitektur, cara kerja, daftar komparasi layanan pihak ketiga (dari yang gratis/open-source sampai berbayar/enterprise), serta panduan implementasi teknis fitur **Face Recognition & Liveness Detection** untuk sistem presensi **ExpenseFlow** (Flutter Mobile + Laravel Backend).

---

## 1. Latar Belakang & Urgensi Kebutuhan

Saat ini, ExpenseFlow memiliki aturan presensi:
- Validasi radius GPS kantor (*Geofencing*).
- Validasi waktu server mutlak (*Anti-Clock Spoofing*).
- Opsi `require_selfie: true` pada pengaturan cabang kantor (`attendance_settings`).

Namun, foto selfie saat ini masih bersifat **statis** (disimpan ke disk storage tanpa diverifikasi kecocokan wajahnya dengan karyawan yang bersangkutan). Kondisi ini membuka celah kecurangan:
1. **Titip Presensi**: Karyawan meminjamkan akun/ponsel kepada rekan kerja yang berada di kantor.
2. **Photo Spoofing (Display Spoofing)**: Mengarahkan kamera depan ke foto karyawan lain yang ditampilkan di layar HP/tablet/laptop.
3. **Print Spoofing**: Mengarahkan kamera ke lembaran foto kertas fisik atau ID card.

Oleh karena itu, dibutuhkan sistem **Face Recognition (1:1 Matching)** yang dilengkapi dengan **Liveness Detection (Anti-Spoofing)**.

---

## 2. Cara Kerja Sistem Biometrik Wajah Presensi

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                          TAHAPAN BIOMETRIK PRESENSI                         │
│                                                                             │
│   [ Karyawan Baru ]                                                         │
│          │                                                                  │
│          ▼                                                                  │
│   ┌──────────────┐     Deteksi Landmark Wajah & Ekstraksi Vektor            │
│   │ 1. ENROLMENT ├─────────────────────────────────────────┐                │
│   │ Wajah Master │                                         ▼                │
│   └──────────────┘                               ┌──────────────────┐       │
│                                                  │ Database Users   │       │
│   [ Saat Check-In Harian ]                       │ - face_embedding │       │
│          │                                       │ - face_photo_url │       │
│          ▼                                       └─────────▲────────┘       │
│   ┌──────────────┐                                         │                │
│   │ 2. LIVENESS  │ Cek kedipan/senyum (Active)             │                │
│   │  DETECTION   │ atau analisis tekstur/kedalaman (Passive│                │
│   └──────┬───────┘                                         │                │
│          │ Lulus Uji Spoofing                              │                │
│          ▼                                                 │                │
│   ┌──────────────┐                                         │                │
│   │ 3. 1:1 FACE  ├─────────────────────────────────────────┘                │
│   │   MATCHING   │ Hitung Kemiripan (Cosine Similarity)                     │
│   └──────┬───────┘                                                          │
│          │ Similarity >= 85% (Sah) / < 85% (Ditolak)                        │
│          ▼                                                                  │
│   ┌──────────────┐                                                          │
│   │ 4. PRESENSI  │ Simpan Record: Jam Server + GPS + Foto +                 │
│   │    SUKSES    │ Nilai Similarity Score (Audit Trail)                     │
│   └──────────────┘                                                          │
└─────────────────────────────────────────────────────────────────────────────┘
```

### Penjelasan 3 Tahap Inti:
1. **Enrollment (Pendaftaran Wajah Acuan)**:
   - Dilakukan 1 kali saat onboarding karyawan melalui aplikasi mobile atau diapprove HRD via web.
   - AI mendeteksi titik-titik landmark wajah (mata, hidung, kontur rahang) dan mengubahnya menjadi larik numerik unik (**Face Embedding** berdimensi 128 atau 512 angka float).
2. **Liveness Detection (Uji Keaslian Wajah / Anti-Spoofing)**:
   - **Active Liveness**: Sistem menantang user melakukan aksi acak (misal: "Kedipkan mata", "Tersenyum", atau "Tengok ke kanan").
   - **Passive Liveness**: AI menganalisis secara instan satu frame foto untuk mendeteksi distorsi piksel layar monitor (Moiré pattern), pantulan kaca HP, kedalaman visual (*depth*), dan tekstur pori-pori kulit.
3. **Face Matching 1:1 (Verifikasi Identitas)**:
   - Foto selfie saat check-in diubah menjadi vektor baru, kemudian dihitung jarak kedekatannya dengan vektor wajah master menggunakan rumus **Cosine Similarity** atau **Euclidean Distance**.
   - Menghasilkan nilai kecocokan (misal: `94.2%`). Jika nilai $\ge$ batas ambang batas (*threshold*, misal `85%`), presensi dinyatakan valid.

---

## 3. Peta Pilihan Solusi Pihak Ketiga (Dari Gratis s/d Berbayar)

Berikut daftar lengkap teknologi dan layanan pihak ke-3 yang dapat digunakan:

### Kategori A: 100% Gratis & Open-Source (Self-Hosted / On-Device)

| Nama Solusi | Tipe Arsitektur | Biaya Lisensi & API | Kelebihan | Kekurangan |
|---|---|---|---|---|
| **Google ML Kit + TFLite (MobileFaceNet)** | Client-Side (Flutter Mobile) | **Gratis Rp 0 (Apache 2.0)** | - 100% offline, tanpa kuota API.<br>- Respon instan (< 300 ms).<br>- Privasi tinggi (data di HP). | - Akurasi bergantung kualitas kamera & chipset HP karyawan.<br>- Perlu integrasi active liveness manual. |
| **InsightFace (ArcFace / RetinaFace) + FastAPI** | Server-Side Microservice (Python) | **Gratis Rp 0 (MIT / Open-Source)** | - Akurasi sangat tinggi (> 99.5% LFW benchmark).<br>- State-of-the-art di industri AI.<br>- Kontrol data mandiri 100%. | - Membutuhkan sewa server VPS tersendiri (disarankan minimal 4–8 GB RAM / GPU opsional). |
| **DeepFace (Python Framework)** | Server-Side Microservice (Python) | **Gratis Rp 0 (MIT License)** | - Sangat mudah dibungkus dengan Flask/FastAPI.<br>- Mendukung multiple backend (VGG-Face, Facenet, OpenFace). | - Model VGG-Face bawaan relatif berat dan lambat jika tanpa akselerasi hardware. |

---

### Kategori B: Cloud API Global (Freemium & Pay-As-You-Go Murah)

| Nama Layanan | Kuota Gratis (Free Tier) | Tarif Berbayar | Fitur Unggulan |
|---|---|---|---|
| **Microsoft Azure Face API** | **30.000 transaksi/bulan GRATIS** (Tier F0) | $\approx \$1.00$ per 1.000 transaksi (~Rp 16/panggilan) | - Verifikasi 1:1 sangat akurat.<br>- Deteksi landmark & atribut.<br>- Liveness detection terintegrasi.<br>- Kuota free tier terbesar di dunia cloud. |
| **AWS Rekognition (Amazon)** | 1.000 transaksi/bulan selama 12 bulan pertama | - `CompareFaces`: $\$0.001$ per image (~Rp 16/call).<br>- `Face Liveness API`: $\$0.015$ per verifikasi. | - Sangat stabil dan scalable.<br>- SDK resmi PHP/Laravel (`aws/aws-sdk-php`).<br>- Deteksi anti-spoofing cloud kelas enterprise. |
| **FaceIO** | 500 transaksi autentikasi/bulan gratis | Mulai dari $\$49$/bulan | - Web & App Biometric SDK mandiri.<br>- Enkripsi data biometrik bawaan.<br>- Integrasi sangat instan tanpa logika backend rumit. |

---

### Kategori C: Spesialis Biometrik & KYC Lokal Indonesia (Berbayar Enterprise)

Cocok digunakan jika perusahaan Anda membutuhkan sertifikasi anti-spoofing internasional formal (**iBeta ISO 30107-3 Level 1 & Level 2**) dan audit kepatuhan perbankan/fintech:

| Nama Layanan | Asal Vendor | Model Harga | Keunggulan Spesifik |
|---|---|---|---|
| **Verihubs** | Indonesia (Y Combinator) | Pay-per-hit / Deposit Kuota (kisaran Rp 300 – Rp 800 per verifikasi) | - Server lokal di Indonesia (aman regulasi data biometrik).<br>- Algoritma dilatih dengan wajah Asia/Indonesia.<br>- Liveness detection bersertifikat iBeta Level 2.<br>- Opsi koneksi ke verifikasi KTP/Dukcapil. |
| **VIDA / Nodeflux** | Indonesia (Penyelenggara Sertifikasi Elektronik / PSrE) | Berlangganan / Kuota Korporasi | - Sertifikasi legalitas tertinggi di Indonesia.<br>- Anti-spoofing kelas perbankan nasional. |
| **FaceTec** | Amerika Serikat (Global Enterprise) | Lisensi enterprise tahunan per user | - Standar emas biometrik 3D dunia.<br>- Tidak dapat dibobol oleh topeng silikon 3D atau video deepfake. |

---

## 4. Matriks Perbandingan Detail

| Parameter Evaluasi | Google ML Kit (Mobile) | InsightFace (VPS Sendiri) | Azure Face API | Verihubs (Lokal ID) |
|---|---|---|---|---|
| **Biaya API** | **Rp 0** | **Rp 0** | **Rp 0 (≤ 30k hit/bln)** | Rp 300 – Rp 800 / hit |
| **Biaya Server Tambahan** | Tidak ada | VPS 4GB (~Rp 150rb/bln) | Tidak ada | Tidak ada |
| **Tingkat Akurasi** | 88% – 93% | 99.2% | 99.7% | 99.8% |
| **Liveness Anti-Spoofing** | Active (Kedip/Senyum) | Active & Passive | Passive (SDK) | Passive (iBeta Level 2) |
| **Beban di Smartphone** | Sedang | Sangat Ringan | Sangat Ringan | Sangat Ringan |
| **Waktu Implementasi** | 3–5 hari kerja | 2–3 hari kerja | **1–2 hari kerja** | 2–3 hari kerja |
| **Vendor Lock-in** | Tidak ada | Tidak ada | Ya (Microsoft) | Ya (Verihubs) |

---

## 5. Rekomendasi Arsitektur untuk ExpenseFlow

Berdasarkan arsitektur ExpenseFlow saat ini (Flutter Mobile + Laravel API di Cloud VPS):

### Pilihan Rekomendasi 1: "Paling Cepat & Gratis" (Microsoft Azure Face API)
* **Alasan**: Azure menyediakan **30.000 panggilan gratis per bulan**. Untuk perusahaan dengan 100 karyawan yang check-in 2x sehari (check-in & check-out = 200 call/hari $\times$ 22 hari kerja = 4.400 call/bulan), kuota ini **100% GRATIS tanpa perlu membayar sepeser pun**.
* **Alur**:
  1. Flutter mengambil foto selfie saat tombol Check-In ditekan.
  2. Foto dikirim ke Laravel endpoint `POST /api/v1/attendance/check-in`.
  3. Laravel memanggil API `verify` Azure dengan foto selfie dan foto master karyawan.
  4. Jika similarity $\ge$ 85%, simpan presensi; jika tidak, return `422 Unprocessable Entity ("Wajah tidak cocok")`.

### Pilihan Rekomendasi 2: "100% Mandiri Tanpa Ketergantungan Cloud Eksternal" (Python FastAPI + InsightFace)
* **Alasan**: Jika perusahaan menolak mengirim foto biometrik ke pihak ketiga demi kepatuhan **UU Perlindungan Data Pribadi (UU PDP No. 27 Tahun 2022)**.
* **Alur**:
  1. Buat 1 container Docker Python kecil dengan FastAPI dan library `insightface`.
  2. Jalankan di VPS yang sama dengan backend Laravel (komunikasi via internal `http://localhost:5000/verify`).
  3. Biometrik dihitung di server internal sendiri tanpa biaya API per-hit.

---

## 6. Desain Database & Skema Tambahan (Laravel Backend)

Jika fitur ini diaktifkan, berikut perubahan skema yang dibutuhkan:

### A. Tabel `users` (Menyimpan Foto Acuan & Embedding)
```sql
ALTER TABLE users 
ADD COLUMN face_photo_path VARCHAR(255) NULL AFTER avatar,
ADD COLUMN face_embedding JSON NULL AFTER face_photo_path,
ADD COLUMN face_enrolled_at TIMESTAMP NULL AFTER face_embedding;
```

### B. Tabel `attendance_settings` (Pengaturan per Cabang Kantor)
```sql
ALTER TABLE attendance_settings
ADD COLUMN require_face_recognition BOOLEAN DEFAULT FALSE AFTER require_selfie,
ADD COLUMN min_face_similarity_pct INT DEFAULT 85 AFTER require_face_recognition;
```
*(Catatan: Mendukung aturan multi-cabang ExpenseFlow: cabang kantor operasional/gudang bisa mewajibkan Face Recognition, sedangkan kantor pusat fleksibel).*

### C. Tabel `attendances` (Audit Log Presensi)
```sql
ALTER TABLE attendances
ADD COLUMN face_similarity_score DECIMAL(5,2) NULL AFTER check_in_distance_meters,
ADD COLUMN is_face_verified BOOLEAN DEFAULT FALSE AFTER face_similarity_score;
```

---

## 7. Contoh Implementasi Kode

### A. Flutter Mobile (Active Liveness: Wajib Berkedip Sebelum Foto Diambil)
Menggunakan `google_mlkit_face_detection`:
```dart
import 'package:google_mlkit_face_detection/google_mlkit_face_detection.dart';

class LivenessDetectionHelper {
  final FaceDetector _faceDetector = FaceDetector(
    options: FaceDetectorOptions(
      enableClassification: true, // Wajib true untuk mendeteksi mata terbuka/tertutup
      performanceMode: FaceDetectorMode.fast,
    ),
  );

  bool checkBlink(Face face) {
    final double? leftEyeOpen = face.leftEyeOpenProbability;
    final double? rightEyeOpen = face.rightEyeOpenProbability;

    // Jika probabilitas kedua mata tertutup < 0.25, terdeteksi kedipan
    if (leftEyeOpen != null && rightEyeOpen != null) {
      return leftEyeOpen < 0.25 && rightEyeOpen < 0.25;
    }
    return false;
  }
}
```

### B. Laravel Backend Service (Azure Face API / Multi-Driver)
```php
namespace App\Services;

use Illuminate\Support\Facades\Http;

class FaceRecognitionService
{
    protected string $endpoint;
    protected string $apiKey;

    public function __construct()
    {
        $this->endpoint = config('services.azure_face.endpoint');
        $this->apiKey = config('services.azure_face.key');
    }

    public function verifyFaces(string $photoPathA, string $photoPathB): array
    {
        // 1. Deteksi FaceId foto A
        $faceIdA = $this->detectFaceId($photoPathA);
        // 2. Deteksi FaceId foto B
        $faceIdB = $this->detectFaceId($photoPathB);

        if (!$faceIdA || !$faceIdB) {
            return ['verified' => false, 'confidence' => 0, 'error' => 'Wajah tidak terdeteksi dengan jelas'];
        }

        // 3. Verifikasi kemiripan (1:1 Verification)
        $response = Http::withHeaders([
            'Ocp-Apim-Subscription-Key' => $this->apiKey,
            'Content-Type' => 'application/json',
        ])->post("{$this->endpoint}/face/v1.0/verify", [
            'faceId1' => $faceIdA,
            'faceId2' => $faceIdB,
        ]);

        $result = $response->json();

        return [
            'verified' => $result['isIdentical'] ?? false,
            'confidence' => ($result['confidence'] ?? 0) * 100, // Format persentase (e.g. 92.5%)
        ];
    }
}
```

---

## 8. Kepatuhan Hukum & Keamanan Data (UU PDP Indonesia)

Sesuai **Undang-Undang Perlindungan Data Pribadi (UU PDP No. 27 Tahun 2022)**, data biometrik wajah termasuk dalam kategori **Data Pribadi yang Bersifat Spesifik**.

Kewajiban Pengembang:
1. **Informed Consent**: Aplikasi wajib menampilkan lembar persetujuan eksplisit kepada karyawan sebelum mengambil foto master biometrik.
2. **Data Minimization**: Disarankan hanya menyimpan nilai hash atau vektor embedding (`JSON`), bukan menyimpan gambar raw beresolusi tinggi secara terbuka.
3. **Storage Encryption**: Direktori foto wajah wajib disimpan dalam storage privat non-public (`storage/app/private/face_masters/`) dan diakses melalui temporary signed URL.
