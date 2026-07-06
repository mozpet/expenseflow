<?php

namespace App\Services\Ocr;

interface OcrDriverInterface
{
    /**
     * Jalankan OCR pada gambar dan kembalikan hasil parsing.
     *
     * @param  string  $imagePath  Path relatif dari Storage::disk('local')
     * @return array{amount: float|null, merchant: string|null, date: string|null, raw_text: string}
     *
     * @throws \RuntimeException  Jika file tidak ditemukan atau OCR gagal
     */
    public function analyze(string $imagePath): array;
}
