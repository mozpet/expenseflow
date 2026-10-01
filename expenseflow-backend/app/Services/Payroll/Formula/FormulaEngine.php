<?php

namespace App\Services\Payroll\Formula;

/**
 * Fasad mesin formula DSL komponen gaji (Fase 6 §6.A).
 *
 * Menyatukan {@see FormulaLexer} → {@see FormulaParser} → {@see FormulaEvaluator}
 * menjadi satu API sederhana untuk: memvalidasi rumus yang disusun HRD,
 * meng-evaluasinya dengan konteks nilai, mendeteksi referensi melingkar antar
 * komponen, dan menyusun urutan hitung (topological order).
 *
 * ATURAN KEAMANAN (§6.A) — dipatuhi ketat:
 *   1. TIDAK memakai `eval()` PHP di mana pun.
 *   2. TIDAK mengeksekusi SQL mentah.
 *   3. Variabel dibatasi whitelist ({@see SYSTEM_VARIABLES}) + kode komponen lain
 *      yang di-whitelist pemanggil (extraVariables) → variabel di luar itu ditolak
 *      saat validasi.
 *   4. Fungsi dibatasi whitelist ({@see FUNCTIONS}) beserta jumlah argumennya.
 *   5. Ketergantungan melingkar antar komponen dideteksi ({@see detectCycles})
 *      SEBELUM sebuah formula boleh diaktifkan.
 *   6. Panjang formula dibatasi ({@see MAX_LENGTH}) & kedalaman rekursi dibatasi
 *      di parser → mencegah DoS.
 */
class FormulaEngine
{
    /** Batas panjang string formula (karakter) — cegah input raksasa / DoS. */
    public const MAX_LENGTH = 500;

    /**
     * Variabel sistem yang boleh dirujuk formula (nama HURUF BESAR → deskripsi).
     * Deskripsi dipakai untuk dokumentasi/UI editor formula.
     */
    public const SYSTEM_VARIABLES = [
        'BASIC_SALARY'         => 'Gaji pokok efektif karyawan pada periode.',
        'FIXED_ALLOWANCE'      => 'Total tunjangan tetap (komponen earning fixed) selain gaji pokok.',
        'FIXED_ALLOWANCE_ALL'  => 'Total seluruh tunjangan tetap termasuk yang non-pajak.',
        'GROSS_TAXABLE'        => 'Penghasilan bruto kena pajak berjalan sebelum komponen ini.',
        'PRESENT_DAYS'         => 'Jumlah hari hadir pada periode.',
        'ABSENT_DAYS'          => 'Jumlah hari alpa/tidak hadir pada periode.',
        'WORKING_DAYS'         => 'Jumlah hari kerja seharusnya pada periode.',
        'OVERTIME_HOURS'       => 'Total jam lembur disetujui pada periode.',
        'TENURE_MONTHS'        => 'Masa kerja karyawan dalam bulan.',
        'UMR_AMOUNT'           => 'Upah minimum regional yang berlaku.',
    ];

    /**
     * Whitelist fungsi → [arity minimum, arity maksimum|null].
     * null pada maksimum berarti variadic (tak terbatas, mis. MIN/MAX/AND/OR).
     */
    public const FUNCTIONS = [
        'MIN'   => [1, null],
        'MAX'   => [1, null],
        'ABS'   => [1, 1],
        'ROUND' => [1, 2],
        'FLOOR' => [1, 1],
        'CEIL'  => [1, 1],
        'IF'    => [3, 3],
        'AND'   => [1, null],
        'OR'    => [1, null],
        'NOT'   => [1, 1],
        'CLAMP' => [3, 3],
    ];

    /**
     * Urai formula menjadi AST. Melempar {@see FormulaException} bila sintaks salah
     * atau panjang melebihi {@see MAX_LENGTH}.
     *
     * @return array<string, mixed>
     */
    public function parse(string $formula): array
    {
        if (strlen($formula) > self::MAX_LENGTH) {
            throw new FormulaException('Formula terlalu panjang (maksimum ' . self::MAX_LENGTH . ' karakter).');
        }

        $tokens = (new FormulaLexer())->tokenize($formula);

        return (new FormulaParser($tokens))->parse();
    }

    /**
     * Validasi lengkap sebuah formula terhadap whitelist.
     *
     * @param  list<string>  $extraVariables  Nama variabel tambahan yang diizinkan
     *                                         (mis. KODE komponen lain yang dirujuk).
     * @return array{ok: bool, error: ?string, position: ?int, variables: list<string>, functions: list<string>}
     */
    public function validate(string $formula, array $extraVariables = []): array
    {
        try {
            $ast = $this->parse($formula);

            $variables = [];
            $functions = [];
            $this->collect($ast, $variables, $functions);

            $allowedVars = array_merge(
                array_keys(self::SYSTEM_VARIABLES),
                array_map('strtoupper', $extraVariables),
            );
            foreach ($variables as $var) {
                if (! in_array($var, $allowedVars, true)) {
                    throw new FormulaException("Variabel tidak dikenal / tidak diizinkan: {$var}.");
                }
            }

            // Fungsi & arity: parsePrimary sudah menerima fungsi apa pun sebagai
            // 'call'; di sini kita tolak yang di luar whitelist + cek arity.
            $this->assertFunctions($ast);

            return [
                'ok'        => true,
                'error'     => null,
                'position'  => null,
                'variables' => array_values(array_unique($variables)),
                'functions' => array_values(array_unique($functions)),
            ];
        } catch (FormulaException $e) {
            return [
                'ok'        => false,
                'error'     => $e->getMessage(),
                'position'  => $e->position,
                'variables' => [],
                'functions' => [],
            ];
        }
    }

    /**
     * Evaluasi formula menjadi satu nilai numerik.
     *
     * @param  array<string, float|int>  $context  Peta nama variabel → nilai
     *                                              (case-insensitive, dinormalisasi HURUF BESAR).
     */
    public function evaluate(string $formula, array $context = []): float
    {
        $ast = $this->parse($formula);

        $normalized = [];
        foreach ($context as $key => $value) {
            $normalized[strtoupper((string) $key)] = $value;
        }

        return (new FormulaEvaluator($normalized))->evaluate($ast);
    }

    /**
     * Kumpulkan nama variabel yang dirujuk sebuah formula (tanpa evaluasi).
     *
     * @return list<string>
     */
    public function referencedVariables(string $formula): array
    {
        $variables = [];
        $functions = [];
        $this->collect($this->parse($formula), $variables, $functions);

        return array_values(array_unique($variables));
    }

    /**
     * Deteksi ketergantungan melingkar (circular reference) antar formula komponen.
     *
     * Setiap formula boleh merujuk KODE komponen lain sebagai variabel. Fungsi ini
     * membangun graf ketergantungan lalu mencari siklus via DFS (termasuk self-ref).
     *
     * @param  array<string, string>  $formulas  Peta KODE komponen → string formula.
     * @return array{has_cycle: bool, cycle: list<string>}  `cycle` = jalur siklus pertama yang ditemukan.
     */
    public function detectCycles(array $formulas): array
    {
        $graph = $this->buildDependencyGraph($formulas);

        $state = [];   // white(0)/grey(1)/black(2)
        $stack = [];

        foreach (array_keys($graph) as $node) {
            $cycle = $this->dfsFindCycle($node, $graph, $state, $stack);
            if ($cycle !== null) {
                return ['has_cycle' => true, 'cycle' => $cycle];
            }
        }

        return ['has_cycle' => false, 'cycle' => []];
    }

    /**
     * Susun urutan evaluasi komponen (topological sort) berdasarkan ketergantungan.
     * Komponen yang dirujuk selalu berada SEBELUM yang merujuk.
     *
     * @param  array<string, string>  $formulas  Peta KODE komponen → string formula.
     * @return list<string>  Urutan KODE komponen yang aman untuk dievaluasi berurutan.
     *
     * @throws FormulaException bila terdapat siklus.
     */
    public function dependencyOrder(array $formulas): array
    {
        $cycle = $this->detectCycles($formulas);
        if ($cycle['has_cycle']) {
            $path = implode(' → ', $cycle['cycle']);
            throw new FormulaException("Ketergantungan melingkar terdeteksi: {$path}.");
        }

        $graph = $this->buildDependencyGraph($formulas);
        $ordered = [];
        $visited = [];

        $visit = function (string $node) use (&$visit, &$graph, &$ordered, &$visited): void {
            if (isset($visited[$node])) {
                return;
            }
            $visited[$node] = true;
            foreach ($graph[$node] as $dep) {
                $visit($dep);
            }
            $ordered[] = $node;
        };

        foreach (array_keys($graph) as $node) {
            $visit($node);
        }

        return $ordered;
    }

    // ─── Internal ───────────────────────────────────────────────────────

    /**
     * Bangun graf ketergantungan: KODE komponen → daftar KODE komponen lain yang dirujuk.
     * Hanya variabel yang JUGA merupakan kode komponen dianggap sebagai edge; variabel
     * sistem diabaikan (bukan node yang perlu diurutkan).
     *
     * @param  array<string, string>  $formulas
     * @return array<string, list<string>>
     */
    private function buildDependencyGraph(array $formulas): array
    {
        $normalized = [];
        foreach ($formulas as $code => $formula) {
            $normalized[strtoupper((string) $code)] = $formula;
        }
        $codes = array_keys($normalized);

        $graph = [];
        foreach ($normalized as $code => $formula) {
            $refs = [];
            foreach ($this->referencedVariables($formula) as $var) {
                if (in_array($var, $codes, true)) {
                    $refs[] = $var;
                }
            }
            $graph[$code] = array_values(array_unique($refs));
        }

        return $graph;
    }

    /**
     * DFS pencari siklus dengan pewarnaan. Mengembalikan jalur siklus (list kode)
     * bila ditemukan, atau null bila cabang ini bersih.
     *
     * @param  array<string, list<string>>  $graph
     * @param  array<string, int>  $state
     * @param  list<string>  $stack
     * @return list<string>|null
     */
    private function dfsFindCycle(string $node, array $graph, array &$state, array &$stack): ?array
    {
        $current = $state[$node] ?? 0;
        if ($current === 2) {
            return null;
        }
        if ($current === 1) {
            // Node ini sedang di jalur aktif → siklus. Potong stack dari kemunculannya.
            $idx = array_search($node, $stack, true);
            $cycle = array_slice($stack, $idx === false ? 0 : $idx);
            $cycle[] = $node; // tutup lingkaran

            return $cycle;
        }

        $state[$node] = 1;
        $stack[] = $node;

        foreach ($graph[$node] ?? [] as $dep) {
            $found = $this->dfsFindCycle($dep, $graph, $state, $stack);
            if ($found !== null) {
                return $found;
            }
        }

        array_pop($stack);
        $state[$node] = 2;

        return null;
    }

    /**
     * Kumpulkan nama variabel & fungsi dari AST secara rekursif.
     *
     * @param  array<string, mixed>  $node
     * @param  list<string>  $variables
     * @param  list<string>  $functions
     */
    private function collect(array $node, array &$variables, array &$functions): void
    {
        switch ($node['type']) {
            case 'num':
                return;

            case 'var':
                $variables[] = $node['name'];

                return;

            case 'neg':
            case 'percent':
                $this->collect($node['operand'], $variables, $functions);

                return;

            case 'bin':
            case 'cmp':
                $this->collect($node['left'], $variables, $functions);
                $this->collect($node['right'], $variables, $functions);

                return;

            case 'call':
                $functions[] = $node['name'];
                foreach ($node['args'] as $arg) {
                    $this->collect($arg, $variables, $functions);
                }

                return;
        }
    }

    /**
     * Pastikan setiap pemanggilan fungsi ada di whitelist & jumlah argumennya sah.
     *
     * @param  array<string, mixed>  $node
     */
    private function assertFunctions(array $node): void
    {
        switch ($node['type']) {
            case 'num':
            case 'var':
                return;

            case 'neg':
            case 'percent':
                $this->assertFunctions($node['operand']);

                return;

            case 'bin':
            case 'cmp':
                $this->assertFunctions($node['left']);
                $this->assertFunctions($node['right']);

                return;

            case 'call':
                $name = $node['name'];
                $spec = self::FUNCTIONS[$name] ?? null;
                if ($spec === null) {
                    throw new FormulaException("Fungsi tidak dikenal / tidak diizinkan: {$name}().");
                }
                [$min, $max] = $spec;
                $n = count($node['args']);
                if ($n < $min || ($max !== null && $n > $max)) {
                    $expected = $max === null ? "minimal {$min}" : ($min === $max ? "tepat {$min}" : "{$min}–{$max}");
                    throw new FormulaException("Fungsi {$name}() membutuhkan {$expected} argumen, diberikan {$n}.");
                }
                foreach ($node['args'] as $arg) {
                    $this->assertFunctions($arg);
                }

                return;
        }
    }
}
