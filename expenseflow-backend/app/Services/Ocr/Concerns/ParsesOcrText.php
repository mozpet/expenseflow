<?php

namespace App\Services\Ocr\Concerns;

/**
 * Shared OCR text parsing logic — dioptimalkan untuk struk Indonesia.
 *
 * Aturan parsing:
 * 1. Amount: cari "Total Harga", "Grand Total", "Jumlah", "Total"
 *    → skip baris dengan "No Trans", "Shift", "No Nota"
 * 2. Merchant: baris pertama/kedua, skip tanggal/angka-only
 * 3. Date: cari "Waktu", "Tanggal", "Date" → DD/MM/YYYY atau YYYY-MM-DD
 */
trait ParsesOcrText
{
    // ═══════════════════════════════════════════════════════════
    // Mapping nama bulan (EN + ID) → nomor bulan
    // ═══════════════════════════════════════════════════════════
    private const MONTH_MAP = [
        // English full names
        'january' => 1, 'february' => 2, 'march' => 3, 'april' => 4,
        'may' => 5, 'june' => 6, 'july' => 7, 'august' => 8,
        'september' => 9, 'october' => 10, 'november' => 11, 'december' => 12,
        // English abbreviated
        'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4,
        'jun' => 6, 'jul' => 7, 'aug' => 8,
        'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
        // Indonesian full names
        'januari' => 1, 'februari' => 2, 'maret' => 3, 'april_id' => 4,
        'mei' => 5, 'juni' => 6, 'juli' => 7, 'agustus' => 8,
        'september_id' => 9, 'oktober' => 10, 'november_id' => 11, 'desember' => 12,
        // Indonesian abbreviated
        'agt' => 8, 'ags' => 8, 'okt' => 10, 'des' => 12,
    ];

    /**
     * Build regex pattern untuk match nama bulan.
     */
    private function monthPattern(): string
    {
        $names = array_keys(self::MONTH_MAP);
        // Sort by length descending agar "january" matched sebelum "jan"
        usort($names, fn ($a, $b) => strlen($b) - strlen($a));
        return '(' . implode('|', array_map('preg_quote', $names)) . ')';
    }

    /**
     * Konversi nama bulan ke nomor (1–12).
     */
    private function monthToNumber(string $name): ?int
    {
        $key = strtolower(trim($name));

        // Handle ID aliases that conflict with EN
        $aliases = [
            'april' => 4,        // same in EN and ID
            'september' => 9,    // same in EN and ID
            'november' => 11,    // same in EN and ID
        ];

        return $aliases[$key]
            ?? self::MONTH_MAP[$key]
            ?? self::MONTH_MAP[$key . '_id']
            ?? null;
    }
    // ═══════════════════════════════════════════════════════════
    // 1. EXTRACT AMOUNT — nominal struk
    // ═══════════════════════════════════════════════════════════

    /**
     * Ekstrak nominal dari teks struk.
     * Strategi: per-baris, cari kata kunci "Total" / "Grand Total" / "Jumlah",
     * lalu ambil angka yang muncul di baris yang sama.
     * Skip baris yang mengandung "No Trans", "Shift", "No Nota" karena
     * angka di sana adalah nomor transaksi, bukan nominal.
     */
    protected function extractAmount(string $text): ?float
    {
        // Kata kunci yang menandakan baris berisi nominal
        $amountKeywords = [
            '/grand\s*total/i',
            '/total\s*harga/i',
            '/total\s*pembayaran/i',
            '/total\s*belanja/i',
            '/jumlah\s*total/i',
            '/jumlah\s*bayar/i',
            '/jumlah\b/i',
            '/total\b/i',
        ];

        // Baris yang harus di-skip (bukan nominal, tapi nomor transaksi)
        $skipPatterns = '/no\s*trans|no\s*nota|shift|operator|kasir|mesin|no\.|struk\s*no/i';

        $lines = explode("\n", $text);

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip baris yang mengandung skip patterns
            if (preg_match($skipPatterns, $line)) {
                continue;
            }

            // Cek apakah baris mengandung kata kunci amount
            foreach ($amountKeywords as $keyword) {
                if (preg_match($keyword, $line)) {
                    // Ekstrak angka dari baris ini
                    // Handle: "Total Harga : Rp. 30,000" atau "Total: 30.000"
                    $amount = $this->extractNumberFromLine($line);
                    if ($amount !== null && $amount > 0) {
                        return $amount;
                    }
                }
            }
        }

        // Fallback: cari "Rp" / "IDR" di baris manapun
        foreach ($lines as $line) {
            $line = trim($line);
            if (preg_match($skipPatterns, $line)) {
                continue;
            }
            if (preg_match('/(?:rp\.?|idr)[\s:]*([\d.,]+)/i', $line, $m)) {
                $amount = $this->normalizeNumber($m[1]);
                if ($amount > 0) {
                    return $amount;
                }
            }
        }

        // Fallback terakhir: cari angka terbesar (abaikan baris skip)
        $max = 0;
        foreach ($lines as $line) {
            $line = trim($line);
            if (preg_match($skipPatterns, $line)) {
                continue;
            }
            if (preg_match_all('/[\d.,]+/', $line, $matches)) {
                foreach ($matches[0] as $num) {
                    $val = $this->normalizeNumber($num);
                    if ($val > $max) {
                        $max = $val;
                    }
                }
            }
        }

        return $max > 0 ? $max : null;
    }

    /**
     * Ekstrak angka dari sebuah baris.
     * Contoh: "Total Harga : Rp. 30,000" → 30000.00
     */
    private function extractNumberFromLine(string $line): ?float
    {
        // Hapus semua sebelum tanda ':' atau '=' jika ada
        if (preg_match('/[:=]\s*([\d.,]+)/', $line, $m)) {
            return $this->normalizeNumber($m[1]);
        }

        // Hapus kata kunci, ambil angka yang tersisa
        $cleaned = preg_replace('/total\s*harga|grand\s*total|total|jumlah|rp\.?|idr|bayar|belanja|pembayaran/i', '', $line);
        $cleaned = trim($cleaned);

        if (preg_match('/([\d.,]+)/', $cleaned, $m)) {
            return $this->normalizeNumber($m[1]);
        }

        return null;
    }

    // ═══════════════════════════════════════════════════════════
    // 2. EXTRACT MERCHANT — nama toko
    // ═══════════════════════════════════════════════════════════

    /**
     * Ekstrak nama merchant dari struk.
     * Ambil baris pertama atau kedua. Skip baris yang hanya berisi
     * angka, tanggal, atau kosong.
     */
    protected function extractMerchant(string $text): ?string
    {
        $lines = explode("\n", $text);

        // Pola yang bukan merchant: tanggal, angka, alamat, header
        $skipMerchant = [
            '/^\s*$/',                                            // baris kosong
            '/^\d{1,2}[\/\-\.]\d{1,2}[\/\-\.]\d{2,4}\s*\d{0,2}/',// tanggal
            '/^\d{4}[\/\-\.]\d{2}[\/\-\.]\d{2}/',               // yyyy-mm-dd
            '/^[\d.,:\s]+$/',                                     // hanya angka
            '/^(no\s*trans|struk\s*no|no\s*nota)/i',             // nomor transaksi
            '/^(alamat|address)\s*:/i',                           // alamat
            '/^(telp|telepon|phone)\s*:/i',                       // telepon
            '/^(kasir|operator|shift)/i',                         // metadata
            '/^---+$/',                                            // garis pemisah
            '/^\*+$/',                                             // garis bintang
        ];

        foreach (array_slice($lines, 0, 5) as $line) {
            $line = trim($line);

            $isSkip = false;
            foreach ($skipMerchant as $pattern) {
                if (preg_match($pattern, $line)) {
                    $isSkip = true;
                    break;
                }
            }

            if ($isSkip) {
                continue;
            }

            // Merchant harus berupa teks (3–80 karakter, minimal 1 huruf)
            if (strlen($line) >= 3 && strlen($line) <= 80 && preg_match('/[a-zA-Z]/', $line)) {
                return $line;
            }
        }

        return null;
    }

    // ═══════════════════════════════════════════════════════════
    // 3. EXTRACT DATE — tanggal struk
    // ═══════════════════════════════════════════════════════════

    /**
     * Ekstrak tanggal dari teks struk.
     * Prioritas: cari "Waktu", "Tanggal", "Date" → ambil tanggal setelahnya.
     * Format yang didukung: DD/MM/YYYY, DD-MM-YYYY, YYYY-MM-DD.
     */
    protected function extractDate(string $text): ?string
    {
        $lines = explode("\n", $text);
        $month = $this->monthPattern();

        // Step 1: Cari tanggal dengan nama bulan setelah keyword (prioritas tertinggi)
        // Format: "Tanggal: 10 Mei 2023", "Date: 10-May-2023"
        $textDateKeywords = '/(?:waktu|tanggal|date|jam)\s*[:=]?\s*(\d{1,2})[\s\-\.]+' . $month . '[\s\-\.]+(\d{2,4})/i';

        foreach ($lines as $line) {
            if (preg_match($textDateKeywords, $line, $m)) {
                $parsed = $this->parseTextDate((int) $m[1], $m[2], $m[3]);
                if ($parsed !== null) {
                    return $parsed;
                }
            }
        }

        // Step 2: Cari tanggal dengan nama bulan setelah keyword (US-style: "May 10, 2023")
        $textDateUS = '/(?:waktu|tanggal|date|jam)\s*[:=]?\s*' . $month . '[\s\-\.]+(\d{1,2}),?\s+(\d{2,4})/i';

        foreach ($lines as $line) {
            if (preg_match($textDateUS, $line, $m)) {
                $parsed = $this->parseTextDate((int) $m[2], $m[1], $m[3]);
                if ($parsed !== null) {
                    return $parsed;
                }
            }
        }

        // Step 3: Cari tanggal numeric setelah keyword (prioritas tertinggi)
        $dateKeywords = '/(?:waktu|tanggal|date|jam)\s*[:=]?\s*(\d{1,2}[\/\-\.]\d{1,2}[\/\-\.]\d{2,4})/i';

        foreach ($lines as $line) {
            if (preg_match($dateKeywords, $line, $m)) {
                return $this->formatDate($m[1]);
            }
        }

        // Step 4: Cari keyword + tanggal di baris yang sama (loose match)
        $dateKeywordsLoose = '/(?:waktu|tanggal|date)\s*[:=]?\s*/i';
        $datePattern       = '/(\d{1,2}[\/\-\.]\d{1,2}[\/\-\.]\d{2,4})/';

        foreach ($lines as $line) {
            if (preg_match($dateKeywordsLoose, $line) && preg_match($datePattern, $line, $m)) {
                return $this->formatDate($m[1]);
            }
        }

        // Step 5: Tanggal dengan nama bulan TANPA keyword (fallback)
        // Format: "10 Mei 2023", "10-May-2023", "10.May.2023"
        $textDateAny = '/(\d{1,2})[\s\-\.]+' . $month . '[\s\-\.]+(\d{2,4})/i';

        foreach ($lines as $line) {
            if (preg_match($textDateAny, $line, $m)) {
                $parsed = $this->parseTextDate((int) $m[1], $m[2], $m[3]);
                if ($parsed !== null) {
                    return $parsed;
                }
            }
        }

        // Step 5b: US-style tanpa keyword: "May 10, 2023"
        $textDateUSAny = '/' . $month . '[\s\-\.]+(\d{1,2}),?\s+(\d{2,4})/i';

        foreach ($lines as $line) {
            if (preg_match($textDateUSAny, $line, $m)) {
                $parsed = $this->parseTextDate((int) $m[2], $m[1], $m[3]);
                if ($parsed !== null) {
                    return $parsed;
                }
            }
        }

        // Step 6: Fallback — cari YYYY-MM-DD dulu (lebih spesifik)
        foreach ($lines as $line) {
            if (preg_match('/(\d{4})[\/\-\.](\d{2})[\/\-\.](\d{2})/', $line, $m)) {
                return "{$m[1]}-{$m[2]}-{$m[3]}";
            }
        }

        // Step 7: DD/MM/YYYY atau DD-MM-YYYY
        foreach ($lines as $line) {
            if (preg_match('/(\d{1,2})[\/\-\.](\d{1,2})[\/\-\.](\d{4})/', $line, $m)) {
                return $this->formatDate($m[0]);
            }
        }

        return null;
    }

    /**
     * Parse tanggal dengan nama bulan → YYYY-MM-DD.
     * Contoh: parseTextDate(10, 'Mei', '2023') → '2023-05-10'
     */
    private function parseTextDate(int $day, string $monthName, string $yearStr): ?string
    {
        $monthNum = $this->monthToNumber($monthName);
        if ($monthNum === null) {
            return null;
        }

        $year = (int) $yearStr;
        // Handle 2-digit year: 23 → 2023, 99 → 1999
        if ($year < 100) {
            $year += ($year > 50) ? 1900 : 2000;
        }

        // Validasi tanggal
        if ($day < 1 || $day > 31 || $monthNum < 1 || $monthNum > 12) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $monthNum, $day);
    }

    /**
     * Format DD/MM/YYYY atau DD-MM-YYYY menjadi YYYY-MM-DD.
     */
    private function formatDate(string $raw): string
    {
        $parts = preg_split('/[\/\-\.]/', $raw);

        if (count($parts) === 3) {
            // yyyy-mm-dd sudah
            if (strlen($parts[0]) === 4) {
                return "{$parts[0]}-{$parts[1]}-{$parts[2]}";
            }
            // dd/mm/yyyy
            return "{$parts[2]}-{$parts[1]}-{$parts[0]}";
        }

        return $raw;
    }

    // ═══════════════════════════════════════════════════════════
    // 4. NUMBER NORMALIZATION — format ID & EN
    // ═══════════════════════════════════════════════════════════

    /**
     * Normalisasi string angka ke float — handle format ID (1.000,00) & EN (1,000.00).
     */
    protected function normalizeNumber(string $raw): float
    {
        $cleaned = str_replace(' ', '', $raw);

        if (str_contains($cleaned, ',') && str_contains($cleaned, '.')) {
            $lastDot   = strrpos($cleaned, '.');
            $lastComma = strrpos($cleaned, ',');

            if ($lastDot > $lastComma) {
                $cleaned = str_replace(',', '', $cleaned);             // EN: 1,000.00
            } else {
                $cleaned = str_replace('.', '', $cleaned);             // ID: 1.000,00
                $cleaned = str_replace(',', '.', $cleaned);
            }
        } elseif (str_contains($cleaned, ',')) {
            if (preg_match('/^\d{1,3},\d{3}$/', $cleaned)) {
                $cleaned = str_replace(',', '', $cleaned);             // ribuan: 30,000
            } else {
                $cleaned = str_replace(',', '.', $cleaned);            // desimal: 3,5
            }
        } elseif (str_contains($cleaned, '.')) {
            // Multiple dots = ribuan ID: 1.500.000 or 1.500
            if (substr_count($cleaned, '.') >= 1 && preg_match('/^\d{1,3}(\.\d{3})+$/', $cleaned)) {
                $cleaned = str_replace('.', '', $cleaned);
            } else {
                // Single dot with short decimal part = desimal
                // Multiple dots not matching pattern above = strip all dots
                $cleaned = str_replace('.', '', $cleaned);
            }
        }

        return (float) $cleaned;
    }
}
