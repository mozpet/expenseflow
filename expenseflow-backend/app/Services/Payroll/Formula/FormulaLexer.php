<?php

namespace App\Services\Payroll\Formula;

/**
 * Pemecah token (tokenizer) DSL formula komponen gaji.
 *
 * Memindai string formula karakter-per-karakter menjadi deret token yang aman
 * untuk diurai {@see FormulaParser}. TIDAK memakai `eval()`, tidak mengeksekusi
 * regex dinamis, tidak menyentuh SQL — murni pemindaian manual. Identifier
 * dinormalisasi ke HURUF BESAR agar penulisan variabel bebas kapitalisasi
 * (`basic_salary` == `BASIC_SALARY`).
 *
 * Angka memakai notasi pemrograman standar: titik `.` adalah pemisah desimal,
 * TANPA pemisah ribuan. Jadi dua puluh lima ribu ditulis `25000`, bukan `25.000`.
 *
 * @return array<int, array{kind: string, value?: float|string, pos: int}>
 */
class FormulaLexer
{
    /** Operator dua karakter → jenis token. */
    private const TWO_CHAR = [
        '<=' => 'lte',
        '>=' => 'gte',
        '==' => 'eq',
        '!=' => 'neq',
    ];

    /** Operator/tanda satu karakter → jenis token. */
    private const ONE_CHAR = [
        '+' => 'plus',
        '-' => 'minus',
        '*' => 'star',
        '/' => 'slash',
        '(' => 'lparen',
        ')' => 'rparen',
        ',' => 'comma',
        '%' => 'percent',
        '<' => 'lt',
        '>' => 'gt',
    ];

    /**
     * @return array<int, array{kind: string, value?: float|string, pos: int}>
     */
    public function tokenize(string $formula): array
    {
        $tokens = [];
        $len = strlen($formula);
        $i = 0;

        while ($i < $len) {
            $ch = $formula[$i];

            // Lewati spasi / tab / newline.
            if ($ch === ' ' || $ch === "\t" || $ch === "\n" || $ch === "\r") {
                $i++;
                continue;
            }

            // Angka: 123, 12.5, .5
            if (ctype_digit($ch) || ($ch === '.' && $i + 1 < $len && ctype_digit($formula[$i + 1]))) {
                $start = $i;
                $hasDot = false;
                while ($i < $len && (ctype_digit($formula[$i]) || $formula[$i] === '.')) {
                    if ($formula[$i] === '.') {
                        if ($hasDot) {
                            throw new FormulaException('Angka tidak valid: titik desimal ganda.', $i);
                        }
                        $hasDot = true;
                    }
                    $i++;
                }
                $lexeme = substr($formula, $start, $i - $start);
                $tokens[] = ['kind' => 'number', 'value' => (float) $lexeme, 'pos' => $start];
                continue;
            }

            // Identifier: huruf/underscore diikuti huruf/angka/underscore.
            if (ctype_alpha($ch) || $ch === '_') {
                $start = $i;
                while ($i < $len && (ctype_alnum($formula[$i]) || $formula[$i] === '_')) {
                    $i++;
                }
                $lexeme = strtoupper(substr($formula, $start, $i - $start));
                $tokens[] = ['kind' => 'ident', 'value' => $lexeme, 'pos' => $start];
                continue;
            }

            // Operator dua karakter.
            $two = substr($formula, $i, 2);
            if (isset(self::TWO_CHAR[$two])) {
                $tokens[] = ['kind' => self::TWO_CHAR[$two], 'pos' => $i];
                $i += 2;
                continue;
            }

            // Operator satu karakter.
            if (isset(self::ONE_CHAR[$ch])) {
                $tokens[] = ['kind' => self::ONE_CHAR[$ch], 'pos' => $i];
                $i++;
                continue;
            }

            // Karakter '=' atau '!' yang berdiri sendiri → pesan bantu spesifik.
            if ($ch === '=') {
                throw new FormulaException("Operator '=' tidak dikenal. Gunakan '==' untuk perbandingan.", $i);
            }
            if ($ch === '!') {
                throw new FormulaException("Operator '!' tidak dikenal. Gunakan '!=' atau fungsi NOT().", $i);
            }

            throw new FormulaException("Karakter tidak dikenal: '{$ch}'.", $i);
        }

        $tokens[] = ['kind' => 'eof', 'pos' => $len];

        return $tokens;
    }
}
