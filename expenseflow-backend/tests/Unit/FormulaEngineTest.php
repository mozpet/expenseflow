<?php

namespace Tests\Unit;

use App\Services\Payroll\Formula\FormulaEngine;
use App\Services\Payroll\Formula\FormulaException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Uji mesin formula DSL komponen gaji (Fase 6 §6.A).
 *
 * Fokus: kebenaran aritmetika & presedensi, whitelist variabel/fungsi, deteksi
 * ketergantungan melingkar, dan — paling penting — JAMINAN KEAMANAN: string
 * berbahaya harus menghasilkan FormulaException, TIDAK PERNAH dieksekusi.
 */
class FormulaEngineTest extends TestCase
{
    private FormulaEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new FormulaEngine();
    }

    private function eval(string $formula, array $context = []): float
    {
        return $this->engine->evaluate($formula, $context);
    }

    // ─── Aritmetika & presedensi ─────────────────────────────────────────

    public function test_penjumlahan_dan_perkalian_menghormati_presedensi(): void
    {
        $this->assertEqualsWithDelta(14.0, $this->eval('2 + 3 * 4'), 1e-9);
        $this->assertEqualsWithDelta(20.0, $this->eval('(2 + 3) * 4'), 1e-9);
        // 10 - ((2 * 4) / 2) = 10 - 4 = 6
        $this->assertEqualsWithDelta(6.0, $this->eval('10 - 2 * 4 / 2'), 1e-9);
    }

    public function test_pengurangan_dan_pembagian(): void
    {
        $this->assertEqualsWithDelta(2.5, $this->eval('10 / 4'), 1e-9);
        $this->assertEqualsWithDelta(-5.0, $this->eval('5 - 10'), 1e-9);
    }

    public function test_unary_minus_dan_plus(): void
    {
        $this->assertEqualsWithDelta(-3.0, $this->eval('-3'), 1e-9);
        $this->assertEqualsWithDelta(7.0, $this->eval('10 + -3'), 1e-9);
        $this->assertEqualsWithDelta(5.0, $this->eval('+5'), 1e-9);
        $this->assertEqualsWithDelta(3.0, $this->eval('- -3'), 1e-9);
    }

    public function test_desimal_notasi_pemrograman(): void
    {
        // Titik = desimal, TANPA pemisah ribuan. 25000 (bukan 25.000).
        $this->assertEqualsWithDelta(25000.0, $this->eval('25000'), 1e-9);
        $this->assertEqualsWithDelta(0.5, $this->eval('.5'), 1e-9);
        $this->assertEqualsWithDelta(12.5, $this->eval('12.5'), 1e-9);
    }

    // ─── Persen (postfix, ÷100) ──────────────────────────────────────────

    public function test_persen_adalah_postfix_bagi_seratus(): void
    {
        $this->assertEqualsWithDelta(0.05, $this->eval('5%'), 1e-9);
        $this->assertEqualsWithDelta(500000.0, $this->eval('BASIC_SALARY * 5%', ['BASIC_SALARY' => 10000000]), 1e-9);
    }

    // ─── Variabel & konteks ──────────────────────────────────────────────

    public function test_variabel_sistem_dari_konteks(): void
    {
        $this->assertEqualsWithDelta(250000.0, $this->eval('PRESENT_DAYS * 25000', ['PRESENT_DAYS' => 10]), 1e-9);
    }

    public function test_nama_variabel_tidak_peka_kapitalisasi(): void
    {
        // basic_salary == BASIC_SALARY, konteks juga dinormalisasi.
        $this->assertEqualsWithDelta(1000.0, $this->eval('basic_salary', ['basic_salary' => 1000]), 1e-9);
        $this->assertEqualsWithDelta(1000.0, $this->eval('BASIC_SALARY', ['basic_salary' => 1000]), 1e-9);
    }

    public function test_variabel_tambahan_kode_komponen_lain(): void
    {
        // 5% dari komponen PENJUALAN (kode komponen lain sebagai variabel).
        $this->assertEqualsWithDelta(50000.0, $this->eval('5% * PENJUALAN', ['PENJUALAN' => 1000000]), 1e-9);
    }

    // ─── Fungsi whitelist ────────────────────────────────────────────────

    public function test_fungsi_min_max(): void
    {
        $this->assertEqualsWithDelta(2.0, $this->eval('MIN(3, 7, 2)'), 1e-9);
        $this->assertEqualsWithDelta(7.0, $this->eval('MAX(3, 7, 2)'), 1e-9);
    }

    public function test_fungsi_round_floor_ceil_abs(): void
    {
        $this->assertEqualsWithDelta(3.14, $this->eval('ROUND(3.14159, 2)'), 1e-9);
        $this->assertEqualsWithDelta(3.0, $this->eval('ROUND(3.4)'), 1e-9);
        $this->assertEqualsWithDelta(3.0, $this->eval('FLOOR(3.9)'), 1e-9);
        $this->assertEqualsWithDelta(4.0, $this->eval('CEIL(3.1)'), 1e-9);
        $this->assertEqualsWithDelta(5.0, $this->eval('ABS(-5)'), 1e-9);
    }

    public function test_fungsi_clamp(): void
    {
        $this->assertEqualsWithDelta(10.0, $this->eval('CLAMP(15, 0, 10)'), 1e-9);
        $this->assertEqualsWithDelta(0.0, $this->eval('CLAMP(-5, 0, 10)'), 1e-9);
        $this->assertEqualsWithDelta(7.0, $this->eval('CLAMP(7, 0, 10)'), 1e-9);
    }

    public function test_fungsi_logika_and_or_not(): void
    {
        $this->assertEqualsWithDelta(1.0, $this->eval('AND(1, 1)'), 1e-9);
        $this->assertEqualsWithDelta(0.0, $this->eval('AND(1, 0)'), 1e-9);
        $this->assertEqualsWithDelta(1.0, $this->eval('OR(0, 1)'), 1e-9);
        $this->assertEqualsWithDelta(0.0, $this->eval('OR(0, 0)'), 1e-9);
        $this->assertEqualsWithDelta(1.0, $this->eval('NOT(0)'), 1e-9);
        $this->assertEqualsWithDelta(0.0, $this->eval('NOT(5)'), 1e-9);
    }

    // ─── Perbandingan & IF (short-circuit) ───────────────────────────────

    public function test_perbandingan_menghasilkan_satu_atau_nol(): void
    {
        $this->assertEqualsWithDelta(1.0, $this->eval('5 > 3'), 1e-9);
        $this->assertEqualsWithDelta(0.0, $this->eval('5 < 3'), 1e-9);
        $this->assertEqualsWithDelta(1.0, $this->eval('5 == 5'), 1e-9);
        $this->assertEqualsWithDelta(1.0, $this->eval('5 != 3'), 1e-9);
        $this->assertEqualsWithDelta(1.0, $this->eval('5 >= 5'), 1e-9);
        $this->assertEqualsWithDelta(1.0, $this->eval('3 <= 5'), 1e-9);
    }

    public function test_if_memilih_cabang_sesuai_kondisi(): void
    {
        $this->assertEqualsWithDelta(100000.0, $this->eval('IF(BASIC_SALARY > 5000000, 100000, 50000)', ['BASIC_SALARY' => 10000000]), 1e-9);
        $this->assertEqualsWithDelta(50000.0, $this->eval('IF(BASIC_SALARY > 5000000, 100000, 50000)', ['BASIC_SALARY' => 3000000]), 1e-9);
    }

    public function test_if_short_circuit_tidak_evaluasi_cabang_tak_terpilih(): void
    {
        // Cabang 'true' berisi pembagian nol; karena kondisi false, TIDAK dievaluasi.
        $this->assertEqualsWithDelta(42.0, $this->eval('IF(1 == 0, 10 / 0, 42)'), 1e-9);
    }

    // ─── Kesalahan evaluasi ──────────────────────────────────────────────

    public function test_pembagian_nol_melempar_exception(): void
    {
        $this->expectException(FormulaException::class);
        $this->eval('10 / 0');
    }

    public function test_variabel_tak_ada_di_konteks_melempar_exception(): void
    {
        $this->expectException(FormulaException::class);
        $this->eval('BASIC_SALARY + 100'); // konteks kosong
    }

    // ─── validate() ──────────────────────────────────────────────────────

    public function test_validate_formula_benar(): void
    {
        $result = $this->engine->validate('BASIC_SALARY * 5% + PRESENT_DAYS * 25000');
        $this->assertTrue($result['ok']);
        $this->assertNull($result['error']);
        $this->assertContains('BASIC_SALARY', $result['variables']);
        $this->assertContains('PRESENT_DAYS', $result['variables']);
    }

    public function test_validate_menolak_variabel_tak_dikenal(): void
    {
        $result = $this->engine->validate('BASIC_SALARY + BONUS_MISTERIUS');
        $this->assertFalse($result['ok']);
        $this->assertNotNull($result['error']);
    }

    public function test_validate_menerima_variabel_tambahan_yang_diizinkan(): void
    {
        $result = $this->engine->validate('BASIC_SALARY + BONUS', ['BONUS']);
        $this->assertTrue($result['ok']);
        $this->assertContains('BONUS', $result['variables']);
    }

    public function test_validate_menolak_fungsi_tak_dikenal(): void
    {
        $result = $this->engine->validate('FOOBAR(1)');
        $this->assertFalse($result['ok']);
    }

    public function test_validate_menolak_arity_salah(): void
    {
        $result = $this->engine->validate('ABS(1, 2)'); // ABS hanya 1 argumen
        $this->assertFalse($result['ok']);
    }

    public function test_validate_menolak_sintaks_salah(): void
    {
        foreach (['2 +', '(2 + 3', '* 5', '2 3', ''] as $bad) {
            $result = $this->engine->validate($bad);
            $this->assertFalse($result['ok'], "Seharusnya invalid: '{$bad}'");
        }
    }

    public function test_validate_melaporkan_fungsi_yang_dipakai(): void
    {
        $result = $this->engine->validate('MIN(BASIC_SALARY, 5000000)');
        $this->assertTrue($result['ok']);
        $this->assertContains('MIN', $result['functions']);
    }

    // ─── KEAMANAN (§6.A) — string berbahaya TIDAK boleh dieksekusi ────────

    #[DataProvider('maliciousProvider')]
    public function test_input_berbahaya_melempar_exception_bukan_eksekusi(string $evil): void
    {
        // Apa pun yang terjadi, HARUS FormulaException — tidak ada eksekusi kode,
        // tidak ada error PHP lain yang bocor.
        $this->expectException(FormulaException::class);

        // parse + evaluate: kalau lolos parse, evaluate menolak fungsi non-whitelist.
        $this->eval($evil);
    }

    public static function maliciousProvider(): array
    {
        return [
            'panggil system'      => ["system('id')"],
            'exec'                => ["exec('ls')"],
            'shell_exec backtick' => ['`whoami`'],
            'phpinfo'             => ['phpinfo()'],
            'eval literal'        => ["eval('1')"],
            'halt compiler'       => ['__halt_compiler()'],
            'titik koma'          => ['1; 2'],
            'string kutip'        => ["'malicious'"],
            'akses properti'      => ['obj->prop'],
            'scope resolution'    => ['Foo::bar'],
            'dollar var'          => ['$x + 1'],
            'kurung kurawal'      => ['{1}'],
            'file get contents'   => ["file_get_contents('/etc/passwd')"],
        ];
    }

    public function test_fungsi_php_non_whitelist_ditolak_saat_validate(): void
    {
        foreach (['SYSTEM(1)', 'EXEC(1)', 'PHPINFO()', 'FILE_GET_CONTENTS(1)'] as $evil) {
            $result = $this->engine->validate($evil);
            $this->assertFalse($result['ok'], "Fungsi berbahaya harus ditolak: {$evil}");
        }
    }

    // ─── Batas panjang & kedalaman (anti-DoS) ────────────────────────────

    public function test_formula_terlalu_panjang_ditolak(): void
    {
        $huge = str_repeat('1+', 300) . '1'; // 601 karakter > MAX_LENGTH
        $this->expectException(FormulaException::class);
        $this->eval($huge);
    }

    public function test_formula_terlalu_dalam_ditolak(): void
    {
        // 80 kurung bersarang melebihi MAX_DEPTH parser (64).
        $deep = str_repeat('(', 80) . '1' . str_repeat(')', 80);
        $result = $this->engine->validate($deep);
        $this->assertFalse($result['ok']);
    }

    // ─── Deteksi ketergantungan melingkar ────────────────────────────────

    public function test_deteksi_siklus_dua_komponen(): void
    {
        $result = $this->engine->detectCycles([
            'A' => 'B + 1',
            'B' => 'A + 1',
        ]);
        $this->assertTrue($result['has_cycle']);
        $this->assertNotEmpty($result['cycle']);
    }

    public function test_deteksi_self_reference(): void
    {
        $result = $this->engine->detectCycles(['A' => 'A + 1']);
        $this->assertTrue($result['has_cycle']);
    }

    public function test_graf_asiklik_tidak_terdeteksi_siklus(): void
    {
        $result = $this->engine->detectCycles([
            'A' => 'BASIC_SALARY * 5%',
            'B' => 'A + 100000',
            'C' => 'B * 2',
        ]);
        $this->assertFalse($result['has_cycle']);
    }

    public function test_dependency_order_menyusun_urutan_benar(): void
    {
        $order = $this->engine->dependencyOrder([
            'C' => 'B * 2',
            'B' => 'A + 100000',
            'A' => 'BASIC_SALARY * 5%',
        ]);

        $posA = array_search('A', $order, true);
        $posB = array_search('B', $order, true);
        $posC = array_search('C', $order, true);

        // Yang dirujuk harus lebih dulu: A sebelum B sebelum C.
        $this->assertLessThan($posB, $posA);
        $this->assertLessThan($posC, $posB);
    }

    public function test_dependency_order_melempar_pada_siklus(): void
    {
        $this->expectException(FormulaException::class);
        $this->engine->dependencyOrder([
            'A' => 'B + 1',
            'B' => 'A + 1',
        ]);
    }

    public function test_evaluasi_berantai_dengan_dependency_order(): void
    {
        // Simulasi wiring Stage 2: hitung komponen berurutan, hasil jadi konteks berikutnya.
        $formulas = [
            'TUNJANGAN' => 'BASIC_SALARY * 10%',
            'TOTAL'     => 'BASIC_SALARY + TUNJANGAN',
        ];
        $order = $this->engine->dependencyOrder($formulas);

        $context = ['BASIC_SALARY' => 5000000];
        foreach ($order as $code) {
            $context[$code] = $this->engine->evaluate($formulas[$code], $context);
        }

        $this->assertEqualsWithDelta(500000.0, $context['TUNJANGAN'], 1e-9);
        $this->assertEqualsWithDelta(5500000.0, $context['TOTAL'], 1e-9);
    }
}
