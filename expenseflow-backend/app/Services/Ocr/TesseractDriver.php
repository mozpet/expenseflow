<?php

namespace App\Services\Ocr;

use App\Services\Ocr\Concerns\ParsesOcrText;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Tesseract OCR Driver — gratis, untuk development/testing.
 *
 * Dependency: composer require thiagoalessio/tesseract_ocr
 * System requirement: tesseract-ocr terinstall di OS.
 */
class TesseractDriver implements OcrDriverInterface
{
    use ParsesOcrText;

    /**
     * @inheritDoc
     */
    public function analyze(string $imagePath): array
    {
        $fullPath = Storage::disk('local')->path($imagePath);

        if (! file_exists($fullPath)) {
            throw new \RuntimeException('File gambar tidak ditemukan: ' . $imagePath);
        }

        // ─── Jalankan Tesseract OCR ──────────────────────────
        // Package: thiagoalessio/tesseract_ocr
        // Docs: https://github.com/thiagoalessio/tesseract-ocr-for-php

        if (! class_exists(\thiagoalessio\TesseractOCR\TesseractOCR::class)) {
            throw new \RuntimeException(
                'Package thiagoalessio/tesseract_ocr belum terinstall. ' .
                'Jalankan: composer require thiagoalessio/tesseract_ocr'
            );
        }

        // Gunakan path eksplisit dari .env — jangan andalkan PATH sistem
        // karena Laravel queue worker berjalan di environment berbeda.
        $tesseractPath = env('TESSERACT_PATH', 'tesseract');

        // Validasi: jika bukan string default 'tesseract' dan file tidak ada
        if ($tesseractPath !== 'tesseract' && ! file_exists($tesseractPath)) {
            throw new \RuntimeException(
                "Tesseract tidak ditemukan di: {$tesseractPath}\n" .
                'Pastikan TESSERACT_PATH di file .env sudah benar. ' .
                'Contoh: TESSERACT_PATH="C:/Program Files/Tesseract-OCR/tesseract.exe"'
            );
        }

        // Fallback: jika hanya 'tesseract' (default), verifikasi bisa dijalankan
        if ($tesseractPath === 'tesseract') {
            $check = shell_exec('tesseract --version 2>&1');
            if ($check === null || str_contains($check, 'not found') || str_contains($check, 'not recognized')) {
                throw new \RuntimeException(
                    "Tesseract tidak ditemukan di PATH sistem.\n" .
                    'Install Tesseract dari https://github.com/UB-Mannheim/tesseract/wiki ' .
                    'lalu tambahkan TESSERACT_PATH di file .env. ' .
                    'Contoh: TESSERACT_PATH="C:/Program Files/Tesseract-OCR/tesseract.exe"'
                );
            }
        }

        // Auto-rotate berdasarkan EXIF — penting untuk foto horizontal dari HP.
        // HP modern menyimpan orientasi di EXIF tag, bukan di piksel.
        $tempPath = null;
        $ocrPath  = $fullPath;

        $orientation = $this->detectOrientation($fullPath);
        if ($orientation !== null && $orientation !== 1) {
            $corrected = $this->correctOrientation($fullPath, $orientation);
            if ($corrected !== null) {
                $tempPath = $corrected;
                $ocrPath  = $corrected;
            }
        }

        try {
            $rawText = (new \thiagoalessio\TesseractOCR\TesseractOCR($ocrPath))
                ->executable($tesseractPath)
                ->lang('eng+ind')   // English + Bahasa Indonesia
                ->psm(6)            // Assume uniform block of text
                ->run();
        } finally {
            // Hapus temp file rotasi setelah OCR selesai (berhasil maupun gagal)
            if ($tempPath !== null && file_exists($tempPath)) {
                @unlink($tempPath);
            }
        }

        Log::info('Tesseract OCR raw text', ['text' => $rawText]);

        return [
            'amount'   => $this->extractAmount($rawText),
            'merchant' => $this->extractMerchant($rawText),
            'date'     => $this->extractDate($rawText),
            'raw_text' => $rawText,
        ];
    }

    /**
     * Deteksi orientasi gambar dari EXIF (JPEG) atau fallback dimensi.
     *
     * Return int|null:
     *   1 = normal (tegak), 3 = 180°, 6 = landscape kanan, 8 = landscape kiri
     *   null = tidak bisa dideteksi
     */
    private function detectOrientation(string $imagePath): ?int
    {
        // 1. Coba baca EXIF (hanya JPEG yang punya EXIF orientation)
        if (function_exists('exif_read_data')) {
            $exif = @exif_read_data($imagePath, 'IFD0', true);

            // Beberapa kamera simpan di root, beberapa di IFD0
            $orientation = $exif['IFD0']['Orientation']
                ?? $exif['Orientation']
                ?? null;

            if ($orientation !== null) {
                $orientation = (int) $orientation;
                if ($orientation >= 1 && $orientation <= 8) {
                    Log::info('OCR: EXIF orientation terdeteksi', ['orientation' => $orientation]);
                    return $orientation;
                }
            }
        }

        // 2. Fallback: deteksi dari dimensi gambar.
        //    Jika lebar > tinggi DAN file adalah JPEG dari HP → kemungkinan landscape.
        //    Tanpa EXIF kita tidak tahu arah rotasi, tapi kita bisa coba orientation 6
        //    (landscape kanan, paling umum di HP Android/iOS).
        $info = @getimagesize($imagePath);
        if ($info && $info[0] > $info[1]) {
            $mime = $info['mime'] ?? '';
            // Hanya lakukan fallback untuk JPEG dari HP (PNG/WebP biasanya screenshot = sudah benar)
            if ($mime === 'image/jpeg') {
                Log::info('OCR: Fallback landscape detection (width > height, no EXIF)', [
                    'width' => $info[0], 'height' => $info[1],
                ]);
                return 6; // asumsikan landscape kanan
            }
        }

        return null;
    }

    /**
     * Putar gambar sesuai EXIF orientation, simpan ke temp file.
     * Return path temp file, atau null jika tidak perlu/gagal.
     *
     * EXIF orientation → derajat rotasi PHP imagerotate():
     *   3 → 180° (terbalik)
     *   6 → -90° (landscape kanan, common saat foto sambil pegang HP landscape)
     *   8 → 90°  (landscape kiri)
     */
    private function correctOrientation(string $imagePath, int $orientation): ?string
    {
        $degrees = match ($orientation) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        if ($degrees === 0) {
            return null;
        }

        $info = @getimagesize($imagePath);
        if (! $info) {
            return null;
        }

        $image = match ($info['mime']) {
            'image/jpeg' => @imagecreatefromjpeg($imagePath),
            'image/png'  => @imagecreatefrompng($imagePath),
            'image/webp' => @imagecreatefromwebp($imagePath),
            default      => null,
        };

        if (! $image) {
            return null;
        }

        $rotated = @imagerotate($image, $degrees, 0);
        imagedestroy($image);

        if (! $rotated) {
            return null;
        }

        $tempPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ocr_' . uniqid() . '.jpg';
        $saved    = @imagejpeg($rotated, $tempPath, 90);
        imagedestroy($rotated);

        return $saved ? $tempPath : null;
    }
}
