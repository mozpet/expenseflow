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
Anda adalah sistem AI pemeriksa dan verifikasi struk belanja/nota pengeluaran perusahaan yang sangat ketat dan akurat.
Tugas utama Anda adalah memastikan integritas data keuangan dan mencegah manipulasi nominal akibat gambar yang buram, bergoyang, atau tidak jelas.

LANGKAH 1 — VALIDASI KUALITAS & KELAYAKAN GAMBAR (SANGAT KRUSIAL):
Periksa foto struk secara teliti:
- Apakah foto mengalami motion blur (bergoyang), out of focus (buram/kabur), resolusi terlalu rendah, pencahayaan terlalu gelap/silau, atau terpotong?
- Khusus untuk bagian NOMINAL ANGKA (total bayar / grand total / subtotal / rincian harga): Apakah angka terbaca dengan 100% jelas dan tegas tanpa keraguan?
- ATURAN KETAT ANTI-HALUSINASI: DILARANG KERAS MENEBAK, MEMPERKIRAKAN, ATAU MENGARANG ANGKA jika angka terlihat samar, kabur, berbayang, atau tidak terbaca pasti!
- JIKA FOTO BURAM / BERGOYANG / ANGKA TIDAK DAPAT DIBACA DENGAN JELAS DAN PASTI:
  Anda WAJIB MENOLAK struk ini dengan menetapkan output JSON:
  {
    "is_clear": false,
    "rejection_reason": "Foto struk buram atau bergoyang sehingga angka tidak dapat dibaca dengan jelas. Harap ambil foto ulang struk fisik Anda dengan posisi kamera stabil dan pencahayaan yang cukup.",
    "merchant": null,
    "date": null,
    "items": [],
    "subtotal": null,
    "discount": null,
    "tax": null,
    "amount": null,
    "raw_text": null
  }

LANGKAH 2 — EKSTRAKSI DATA (Hanya jika "is_clear" bernilai true):
Jika dan hanya jika foto tajam, jelas, dan seluruh teks serta angka terbaca tanpa keraguan:
1. "is_clear": true,
2. "rejection_reason": null,
3. "merchant": Nama toko / restoran / merchant (contoh: "Domino's Pizza", "INDOMARET", "ALFAMART", "SPBU PERTAMINA", "KFC", dll). Jika tidak tertera, null.
4. "date": Tanggal transaksi dalam format standar YYYY-MM-DD (contoh: "2026-08-26"). Jika tidak ditemukan, null.
5. "items": Daftar rincian barang/makanan/produk yang dibeli dalam bentuk array objek:
   [
     {
       "name": "Nama barang / produk",
       "qty": 1,
       "price": 45455,
       "total": 45455
     }
   ]
6. "subtotal": Total belanjaan sebelum pajak dan diskon sebagai angka murni (contoh: 100000). Jika tidak tertera, null.
7. "discount": Nilai diskon / potongan harga / promo sebagai angka positif murni (contoh: 10000). Jika tidak ada diskon, null.
8. "tax": Nilai pajak / PPN / PB1 / Service Charge sebagai angka murni (contoh: 10000). Jika tidak ada pajak, null.
9. "amount": Total nominal pembayaran akhir / Grand Total yang dibayarkan pelanggan dalam bentuk angka murni tanpa simbol (contoh: 110000).
10. "raw_text": Seluruh teks yang terbaca pada struk dari atas ke bawah.

Kembalikan HANYA format JSON valid tanpa tanda markdown tambahan:
{
  "is_clear": true,
  "rejection_reason": null,
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

        $primaryModel = config('services.gemini.model') ?? env('GEMINI_MODEL', 'gemini-3.5-flash-lite');
        $candidateModels = array_values(array_unique(array_filter([
            $primaryModel,
            'gemini-3.5-flash-lite',
            'gemini-3.1-flash-lite',
            'gemini-3.5-flash',
        ])));

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

        $response = null;
        $lastException = null;

        foreach ($candidateModels as $index => $model) {
            $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

            try {
                $response = Http::timeout(10)
                    ->withHeaders(['Content-Type' => 'application/json'])
                    ->post($url, $payload);

                if ($response->successful()) {
                    break;
                }

                $status = $response->status();
                $body   = $response->body();

                if (isset($candidateModels[$index + 1])) {
                    Log::warning("Gemini Vision OCR: Model {$model} error ({$status}), mencoba fallback ke model {$candidateModels[$index + 1]}", [
                        'status' => $status,
                        'body'   => $body,
                    ]);
                    continue;
                }

                break;
            } catch (\Exception $e) {
                $lastException = $e;
                Log::warning("Gemini Vision OCR connection error with model {$model}", ['error' => $e->getMessage()]);
                if (isset($candidateModels[$index + 1])) {
                    Log::info("Gemini Vision OCR: Fallback dari {$model} ke {$candidateModels[$index + 1]} setelah timeout/exception");
                    continue;
                }
                throw new \RuntimeException('Gagal terhubung ke Gemini Vision API: ' . $e->getMessage());
            }
        }

        if (! $response && $lastException) {
            throw new \RuntimeException('Gagal terhubung ke Gemini Vision API: ' . $lastException->getMessage());
        }

        if (! $response || ! $response->successful()) {
            $status = $response ? $response->status() : 500;
            $body   = $response ? $response->body() : 'No response from Gemini API';
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
            $extractedAmount = $this->extractAmount($textOutput);
            if ($extractedAmount === null) {
                throw new \RuntimeException('Foto struk buram atau teks tidak terbaca jelas. Harap ambil foto ulang.');
            }

            return [
                'amount'   => $extractedAmount,
                'subtotal' => null,
                'tax'      => null,
                'discount' => null,
                'items'    => null,
                'merchant' => $this->extractMerchant($textOutput),
                'date'     => $this->extractDate($textOutput),
                'raw_text' => $textOutput,
            ];
        }

        // ─── Validasi Kualitas & Ketertelisikan Gambar ───────────────────
        if (isset($parsed['is_clear']) && $parsed['is_clear'] === false) {
            $reason = ! empty($parsed['rejection_reason'])
                ? (string) $parsed['rejection_reason']
                : 'Foto struk buram atau bergoyang sehingga angka tidak dapat dibaca jelas. Harap ambil foto ulang.';
            throw new \RuntimeException($reason);
        }

        if (! empty($parsed['rejection_reason'])) {
            throw new \RuntimeException((string) $parsed['rejection_reason']);
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

        $finalAmount = isset($parsed['amount']) && is_numeric($parsed['amount'])
            ? (float) $parsed['amount']
            : $this->extractAmount($rawText);

        if ($finalAmount === null) {
            throw new \RuntimeException('Foto struk buram atau angka total tidak terdeteksi dengan pasti. Harap ambil foto ulang.');
        }

        return [
            'amount'   => $finalAmount,
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
