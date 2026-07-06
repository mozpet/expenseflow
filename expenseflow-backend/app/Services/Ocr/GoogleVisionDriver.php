<?php

namespace App\Services\Ocr;

use App\Services\Ocr\Concerns\ParsesOcrText;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Google Cloud Vision API Driver — untuk production.
 *
 * Memerlukan API key di .env: GOOGLE_CLOUD_API_KEY
 */
class GoogleVisionDriver implements OcrDriverInterface
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

        $imageContent = base64_encode(file_get_contents($fullPath));

        // ─── Google Cloud Vision REST API ────────────────────
        // Docs: https://cloud.google.com/vision/docs/ocr
        $apiKey = config('services.google_cloud.api_key');

        if (empty($apiKey)) {
            throw new \RuntimeException(
                'GOOGLE_CLOUD_API_KEY belum diset di .env. ' .
                'Gunakan OCR_DRIVER=tesseract untuk development.'
            );
        }

        $url = "https://vision.googleapis.com/v1/images:annotate?key={$apiKey}";

        $payload = [
            'requests' => [
                [
                    'image'    => ['content' => $imageContent],
                    'features' => [
                        ['type' => 'TEXT_DETECTION', 'maxResults' => 1],
                    ],
                ],
            ],
        ];

        $response = Http::timeout(30)->post($url, $payload);

        if (! $response->successful()) {
            Log::error('Google Vision API error', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            throw new \RuntimeException('Google Vision API error: ' . $response->status());
        }

        $data     = $response->json();
        $rawText  = $data['responses'][0]['textAnnotations'][0]['description'] ?? '';
        $fullText = $data['responses'][0]['fullTextAnnotation']['text'] ?? $rawText;

        Log::info('Google Vision OCR raw text', ['text' => $fullText]);

        return [
            'amount'   => $this->extractAmount($fullText),
            'merchant' => $this->extractMerchant($fullText),
            'date'     => $this->extractDate($fullText),
            'raw_text' => $fullText,
        ];
    }
}
