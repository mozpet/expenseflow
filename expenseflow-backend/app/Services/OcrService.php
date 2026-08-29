<?php

namespace App\Services;

use App\Services\Ocr\GeminiVisionDriver;
use App\Services\Ocr\GoogleVisionDriver;
use App\Services\Ocr\OcrDriverInterface;
use Illuminate\Support\Facades\Log;

/**
 * OCR Service — memproses OCR struk pengeluaran via Gemini Vision atau Google Cloud Vision.
 *
 * Cara pakai di ProcessOcrJob:
 *   $result = app(OcrService::class)->analyze($imagePath);
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
        Log::info('OCR: menggunakan driver ' . get_class($this->driver), [
            'driver' => get_class($this->driver),
            'image'  => $imagePath,
        ]);

        return $this->driver->analyze($imagePath);
    }

    /**
     * Resolve driver OCR.
     */
    private function resolveDriver(): OcrDriverInterface
    {
        $driver = config('ocr.driver', 'gemini');

        return match ($driver) {
            'google_vision' => app(GoogleVisionDriver::class),
            'gemini', 'gemini_vision' => app(GeminiVisionDriver::class),
            default => app(GeminiVisionDriver::class),
        };
    }
}
