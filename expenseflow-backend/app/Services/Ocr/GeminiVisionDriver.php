<?php

namespace App\Services\Ocr;

use App\Services\Ocr\Concerns\ParsesOcrText;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Gemini Flash Vision OCR Driver — Gratis via Google AI Studio.
 *
 * Menggunakan Google Gemini 1.5 Flash untuk ekstraksi teks struk belanja,
 * nama merchant, total nominal (amount), dan tanggal transaksi secara cerdas.
 */
class GeminiVisionDriver implements OcrDriverInterface
{
    use ParsesOcrText;

    /**
     * @inheritDoc
     */
    public function analyze(string $imagePath): array
    {
        $fullPath = Storage::disk('local')->path($imagePath);

        if (! file_exists($fullPath)) {
            throw new \RuntimeException('File gambar struk tidak ditemukan: ' . $imagePath);
        }

        $apiKey = config('services.gemini.api_key') ?? config('services.google_cloud.api_key') ?? env('GEMINI_API_KEY');

        if (empty($apiKey)) {
            throw new \RuntimeException(
                'GEMINI_API_KEY belum diset di .env. ' .
                'Dapatkan API Key gratis di https://aistudio.google.com/'
            );
        }

        $mimeType = mime_content_type($fullPath) ?: 'image/jpeg';
        $base64Image = base64_encode(file_get_contents($fullPath));

        $prompt = <<<PROMPT
Anda adalah asisten OCR ahli dalam menganalisis struk pembayaran/nota belanja Indonesia.
Analisis foto struk ini dan ekstrak informasi berikut secara mendalam dan akurat:
1. "merchant": Nama toko / restoran / merchant (contoh: "Domino's Pizza", "INDOMARET", "ALFAMART", "SPBU PERTAMINA", "KFC", dll). Jika tidak ditemukan, isi null.
2. "date": Tanggal transaksi dalam format standar YYYY-MM-DD (contoh: "2026-08-26"). Jika tidak ditemukan, isi null.
3. "items": Daftar rincian barang/makanan/produk yang dibeli dalam bentuk array objek:
   [
     {
       "name": "Nama barang / produk",
       "qty": 1,
       "price": 45455,
       "total": 45455
     }
   ]
   (Ekstrak seluruh item yang tertera pada struk secara terpisah beserta qty dan harganya).
4. "subtotal": Total harga belanjaan sebelum pajak dan diskon sebagai angka murni (contoh: 100000). Jika tidak tertera, isi null.
5. "discount": Nilai diskon / potongan harga / promo sebagai angka positif murni (contoh: 10000). Jika tidak ada diskon, isi null.
6. "tax": Nilai pajak / PPN / PB1 / Service Charge sebagai angka murni (contoh: 10000). Jika tidak ada pajak, isi null.
7. "amount": Total nominal pembayaran akhir / Grand Total yang dibayarkan pelanggan dalam bentuk angka murni tanpa simbol (contoh: 110000).
8. "raw_text": Seluruh teks yang terbaca pada struk dari atas ke bawah.

Kembalikan HANYA format JSON valid tanpa tanda markdown tambahan:
{
  "merchant": "Domino's Pizza Ramayana Semper",
  "date": "2026-08-26",
  "items": [
    {
      "name": "HT VOLCANO MEAT",
      "qty": 1,
      "price": 45455,
      "total": 45455
    }
  ],
  "subtotal": 100000,
  "discount": null,
  "tax": 10000,
  "amount": 110000,
  "raw_text": "Teks lengkap struk..."
}
PROMPT;

        $model = env('GEMINI_MODEL', 'gemini-1.5-flash');
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

        $payload = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt],
                        [
                            'inline_data' => [
                                'mime_type' => $mimeType,
                                'data'      => $base64Image,
                            ],
                        ],
                    ],
                ],
            ],
            'generationConfig' => [
                'response_mime_type' => 'application/json',
                'temperature'        => 0.1,
            ],
        ];

        try {
            $response = Http::timeout(30)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post($url, $payload);
        } catch (\Exception $e) {
            Log::error('Gemini Vision OCR connection error', ['error' => $e->getMessage()]);
            throw new \RuntimeException('Gagal terhubung ke Gemini Vision API: ' . $e->getMessage());
        }

        if (! $response->successful()) {
            $status = $response->status();
            $body   = $response->body();
            Log::error('Gemini Vision API response error', [
                'status' => $status,
                'body'   => $body,
            ]);

            if ($status === 429 || str_contains($body, 'RESOURCE_EXHAUSTED') || str_contains(strtolower($body), 'quota')) {
                throw new \RuntimeException('Layanan OCR sedang sibuk (kuota batas 15 RPM menit ini penuh / 429 Too Many Requests). Silakan tunggu 1 menit lalu coba scan kembali.');
            }

            throw new \RuntimeException('Gemini Vision API error (' . $status . '): ' . $body);
        }

        $resultJson = $response->json();
        $textOutput = $resultJson['candidates'][0]['content']['parts'][0]['text'] ?? '';

        Log::info('Gemini Vision OCR Response', ['output' => $textOutput]);

        // Parse JSON dari Gemini output
        $cleanJson = trim($textOutput);
        if (str_starts_with($cleanJson, '```json')) {
            $cleanJson = trim(substr($cleanJson, 7));
        }
        if (str_ends_with($cleanJson, '```')) {
            $cleanJson = trim(substr($cleanJson, 0, -3));
        }

        $parsed = json_decode($cleanJson, true);

        if (! is_array($parsed)) {
            // Fallback parsing menggunakan Concerns ParsesOcrText
            return [
                'amount'   => $this->extractAmount($textOutput),
                'subtotal' => null,
                'tax'      => null,
                'discount' => null,
                'items'    => null,
                'merchant' => $this->extractMerchant($textOutput),
                'date'     => $this->extractDate($textOutput),
                'raw_text' => $textOutput,
            ];
        }

        $rawText = $parsed['raw_text'] ?? $textOutput;

        $items = [];
        if (isset($parsed['items']) && is_array($parsed['items'])) {
            foreach ($parsed['items'] as $item) {
                if (is_array($item) && ! empty($item['name'])) {
                    $items[] = [
                        'name'  => (string) $item['name'],
                        'qty'   => isset($item['qty']) && is_numeric($item['qty']) ? (int) $item['qty'] : 1,
                        'price' => isset($item['price']) && is_numeric($item['price']) ? (float) $item['price'] : 0.0,
                        'total' => isset($item['total']) && is_numeric($item['total']) ? (float) $item['total'] : (float) ($item['price'] ?? 0),
                    ];
                }
            }
        }

        return [
            'amount'   => isset($parsed['amount']) && is_numeric($parsed['amount']) ? (float) $parsed['amount'] : $this->extractAmount($rawText),
            'subtotal' => isset($parsed['subtotal']) && is_numeric($parsed['subtotal']) ? (float) $parsed['subtotal'] : null,
            'tax'      => isset($parsed['tax']) && is_numeric($parsed['tax']) ? (float) $parsed['tax'] : null,
            'discount' => isset($parsed['discount']) && is_numeric($parsed['discount']) ? (float) $parsed['discount'] : null,
            'items'    => ! empty($items) ? $items : null,
            'merchant' => ! empty($parsed['merchant']) ? (string) $parsed['merchant'] : $this->extractMerchant($rawText),
            'date'     => ! empty($parsed['date']) ? (string) $parsed['date'] : $this->extractDate($rawText),
            'raw_text' => (string) $rawText,
        ];
    }
}
