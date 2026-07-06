<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ParsesOcrTextTest extends TestCase
{
    use \App\Services\Ocr\Concerns\ParsesOcrText {
        extractAmount as public runExtractAmount;
        extractMerchant as public runExtractMerchant;
        extractDate as public runExtractDate;
        normalizeNumber as public runNormalizeNumber;
    }

    private function pertaminaReceipt(): string
    {
        return <<<'TEXT'
SPBU Pertamina
Jl. Sudirman No. 10
No Trans : 2315892
Shift : 2
Operator : Budi
Waktu : 24/06/2023
Total Harga : Rp. 30,000
TEXT;
    }

    private function cafeReceipt(): string
    {
        return <<<'TEXT'
Kopi Kenangan
Grand Indonesia Mall
Telp: 021-5555-1234
Tanggal: 15/03/2024
Kasir: Siti
Struk No: 0045

Kopi Susu Gula Aren x1  25,000
Roti Bakar Coklat x1     15,000

Total Harga : Rp. 40,000
Terima Kasih
TEXT;
    }

    private function warungReceipt(): string
    {
        return <<<'TEXT'
WARUNG PADANG SEDERHANA
Jl. Gatot Subroto No. 45

Jam: 12/01/2025 13:30
No Nota: 12345

Nasi Goreng x2      30,000
Es Teh x2           10,000

Jumlah: 40,000
TEXT;
    }

    // ─── AMOUNT ──────────────────────────────────────────────

    public function test_extract_total_harga_with_rp(): void
    {
        $this->assertEquals(30000.00, $this->runExtractAmount($this->pertaminaReceipt()));
    }

    public function test_skip_no_trans_shift_no_nota_for_amount(): void
    {
        $text = "No Trans : 2315892\nShift : 2\nTotal Harga : Rp. 30,000";
        $this->assertEquals(30000.00, $this->runExtractAmount($text));
    }

    public function test_extract_amount_from_cafe_receipt(): void
    {
        $this->assertEquals(40000.00, $this->runExtractAmount($this->cafeReceipt()));
    }

    public function test_extract_jumlah_from_warung_receipt(): void
    {
        $this->assertEquals(40000.00, $this->runExtractAmount($this->warungReceipt()));
    }

    public function test_extract_amount_returns_null_for_empty_text(): void
    {
        $this->assertNull($this->runExtractAmount(''));
    }

    // ─── MERCHANT ────────────────────────────────────────────

    public function test_extract_first_line_as_merchant(): void
    {
        $this->assertEquals('SPBU Pertamina', $this->runExtractMerchant($this->pertaminaReceipt()));
    }

    public function test_extract_cafe_name_as_merchant(): void
    {
        $this->assertEquals('Kopi Kenangan', $this->runExtractMerchant($this->cafeReceipt()));
    }

    public function test_extract_warung_name_as_merchant(): void
    {
        $this->assertEquals('WARUNG PADANG SEDERHANA', $this->runExtractMerchant($this->warungReceipt()));
    }

    public function test_skip_date_and_number_only_lines_for_merchant(): void
    {
        $this->assertEquals('TOKO ABC', $this->runExtractMerchant("\n\n2024-01-15\n12345\nTOKO ABC"));
    }

    public function test_extract_merchant_returns_null_for_empty(): void
    {
        $this->assertNull($this->runExtractMerchant("\n\n12345\n"));
    }

    // ─── DATE ────────────────────────────────────────────────

    public function test_extract_date_after_waktu_keyword(): void
    {
        $this->assertEquals('2023-06-24', $this->runExtractDate($this->pertaminaReceipt()));
    }

    public function test_extract_date_after_tanggal_keyword(): void
    {
        $this->assertEquals('2024-03-15', $this->runExtractDate($this->cafeReceipt()));
    }

    public function test_extract_date_after_jam_keyword(): void
    {
        $this->assertEquals('2025-01-12', $this->runExtractDate($this->warungReceipt()));
    }

    public function test_handle_yyyy_mm_dd_format(): void
    {
        $this->assertEquals('2024-03-15', $this->runExtractDate("Transaksi: 2024-03-15 14:30"));
    }

    public function test_extract_date_returns_null_when_none(): void
    {
        $this->assertNull($this->runExtractDate("Hello world\nNo date here"));
    }

    // ─── TEXT-BASED DATE FORMATS ────────────────────────────

    public function test_extract_date_indonesian_mei(): void
    {
        $this->assertEquals('2023-05-10', $this->runExtractDate("Tanggal: 10 Mei 2023"));
    }

    public function test_extract_date_english_may_dash(): void
    {
        $this->assertEquals('2023-05-10', $this->runExtractDate("Date: 10-May-2023"));
    }

    public function test_extract_date_indonesian_desember(): void
    {
        $this->assertEquals('2024-12-25', $this->runExtractDate("Tanggal: 25 Desember 2024"));
    }

    public function test_extract_date_english_february(): void
    {
        $this->assertEquals('2024-02-14', $this->runExtractDate("Date: 14 February 2024"));
    }

    public function test_extract_date_us_style_month_first(): void
    {
        $this->assertEquals('2023-03-15', $this->runExtractDate("Date: March 15, 2023"));
    }

    public function test_extract_date_abbreviated_month(): void
    {
        $this->assertEquals('2023-08-20', $this->runExtractDate("Tanggal: 20 Agt 2023"));
    }

    public function test_extract_date_indonesian_maret(): void
    {
        $this->assertEquals('2024-03-01', $this->runExtractDate("1 Maret 2024"));
    }

    public function test_extract_date_2_digit_year(): void
    {
        $this->assertEquals('2023-07-04', $this->runExtractDate("Date: 04-Jul-23"));
    }

    public function test_extract_date_without_keyword(): void
    {
        $this->assertEquals('2023-10-31', $this->runExtractDate("Some text\n31 Oktober 2023\nMore text"));
    }

    public function test_extract_date_januari_indonesian(): void
    {
        $this->assertEquals('2025-01-15', $this->runExtractDate("Waktu: 15 Januari 2025"));
    }

    // ─── NUMBER NORMALIZATION ────────────────────────────────

    public function test_normalize_indonesian_thousands(): void
    {
        $this->assertEquals(30000.00, $this->runNormalizeNumber('30,000'));
    }

    public function test_normalize_id_dot_separator(): void
    {
        $this->assertEquals(1500000.00, $this->runNormalizeNumber('1.500.000'));
    }

    public function test_normalize_id_comma_decimal(): void
    {
        $this->assertEquals(1500.50, $this->runNormalizeNumber('1.500,50'));
    }

    public function test_normalize_english_format(): void
    {
        $this->assertEquals(1500.50, $this->runNormalizeNumber('1,500.50'));
    }

    public function test_normalize_plain_number(): void
    {
        $this->assertEquals(40000.00, $this->runNormalizeNumber('40000'));
    }

    // ─── FULL RECEIPT ────────────────────────────────────────

    public function test_parse_pertamina_receipt(): void
    {
        $r = $this->pertaminaReceipt();
        $this->assertEquals(30000.00, $this->runExtractAmount($r));
        $this->assertEquals('SPBU Pertamina', $this->runExtractMerchant($r));
        $this->assertEquals('2023-06-24', $this->runExtractDate($r));
    }

    public function test_parse_cafe_receipt(): void
    {
        $r = $this->cafeReceipt();
        $this->assertEquals(40000.00, $this->runExtractAmount($r));
        $this->assertEquals('Kopi Kenangan', $this->runExtractMerchant($r));
        $this->assertEquals('2024-03-15', $this->runExtractDate($r));
    }

    public function test_parse_warung_receipt(): void
    {
        $r = $this->warungReceipt();
        $this->assertEquals(40000.00, $this->runExtractAmount($r));
        $this->assertEquals('WARUNG PADANG SEDERHANA', $this->runExtractMerchant($r));
        $this->assertEquals('2025-01-12', $this->runExtractDate($r));
    }
}
