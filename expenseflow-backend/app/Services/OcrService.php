<?php

namespace App\Services;

use App\Services\Ocr\GoogleVisionDriver;
use App\Services\Ocr\OcrDriverInterface;
use App\Services\Ocr\TesseractDriver;
use Illuminate\Support\Facades\Log;

/**
 * OCR Service — memilih driver berdasarkan config OCR_DRIVER di .env.
 *
 * Cara pakai di ProcessOcrJob:
 *   $result = app(OcrService::class)->analyze($imagePath);
 *
 * Driver yang tersedia:
 *   - tesseract     → TesseractDriver (gratis, development)
 *   - google_vision → GoogleVisionDriver (production)
 */
class OcrService
{
    private OcrDriverInterface $driver;

    public function __construct()
    {
        $this->driver = $this->resolveDriver();
    }

    /**
     * Jalankan OCR dan kembalikan hasil parsing.
     *
     * @return array{amount: float|null, merchant: string|null, date: string|null, raw_text: string}
     */
    public function analyze(string $imagePath): array
    {
        Log::info('OCR: menggunakan driver', [
            'driver' => get_class($this->driver),
            'image'  => $imagePath,
        ]);

        return $this->driver->analyze($imagePath);
    }

    /**
     * Resolve driver berdasarkan config OCR_DRIVER.
     */
    private function resolveDriver(): OcrDriverInterface
    {
        $driver = config('ocr.driver', 'tesseract');

        return match ($driver) {
            'google_vision' => app(GoogleVisionDriver::class),
            'tesseract'     => app(TesseractDriver::class),
            default => throw new \RuntimeException(
                "OCR_DRIVER '{$driver}' tidak dikenal. Gunakan: tesseract atau google_vision."
            ),
        };
    }
}
