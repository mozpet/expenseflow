<?php

namespace App\Services\Payroll\Formula;

/**
 * Mengevaluasi AST formula menjadi satu nilai numerik (float).
 *
 * KEAMANAN: evaluasi hanya berupa `switch` atas jenis node yang sudah dikenal
 * dan pemanggilan fungsi dari whitelist {@see FormulaEngine::FUNCTIONS}. Tidak
 * ada `eval()`, tidak ada pemanggilan fungsi PHP dinamis (`call_user_func`,
 * variabel-fungsi, dll), tidak ada akses ke objek/kelas. Nilai variabel diambil
 * dari konteks yang diberikan pemanggil (peta nama HURUF BESAR → angka).
 */
class FormulaEvaluator
{
    /** Toleransi perbandingan kesamaan float. */
    private const EPSILON = 1e-9;

    /** @param array<string, float|int> $context Peta nama variabel (HURUF BESAR) → nilai. */
    public function __construct(private readonly array $context)
    {
    }

    /**
     * @param array<string, mixed> $node
     */
    public function evaluate(array $node): float
    {
        switch ($node['type']) {
            case 'num':
                return (float) $node['value'];

            case 'var':
                $name = $node['name'];
                if (! array_key_exists($name, $this->context)) {
                    throw new FormulaException("Variabel {$name} tidak tersedia dalam konteks perhitungan.");
                }

                return (float) $this->context[$name];

            case 'neg':
                return -$this->evaluate($node['operand']);

            case 'percent':
                return $this->evaluate($node['operand']) / 100.0;

            case 'bin':
                return $this->binary($node['op'], $node['left'], $node['right']);

            case 'cmp':
                return $this->compare($node['op'], $node['left'], $node['right']);

            case 'call':
                return $this->call($node['name'], $node['args']);

            default:
                throw new FormulaException("Jenis node formula tidak dikenal: {$node['type']}.");
        }
    }

    /**
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     */
    private function binary(string $op, array $left, array $right): float
    {
        $l = $this->evaluate($left);
        $r = $this->evaluate($right);

        return match ($op) {
            '+' => $l + $r,
            '-' => $l - $r,
            '*' => $l * $r,
            '/' => $this->divide($l, $r),
            default => throw new FormulaException("Operator tidak dikenal: {$op}."),
        };
    }

    private function divide(float $l, float $r): float
    {
        if (abs($r) <= self::EPSILON) {
            throw new FormulaException('Pembagian dengan nol pada formula.');
        }

        return $l / $r;
    }

    /**
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     */
    private function compare(string $op, array $left, array $right): float
    {
        $l = $this->evaluate($left);
        $r = $this->evaluate($right);

        $result = match ($op) {
            '==' => abs($l - $r) <= self::EPSILON,
            '!=' => abs($l - $r) > self::EPSILON,
            '<'  => $l < $r,
            '<=' => $l <= $r,
            '>'  => $l > $r,
            '>=' => $l >= $r,
            default => throw new FormulaException("Operator perbandingan tidak dikenal: {$op}."),
        };

        return $result ? 1.0 : 0.0;
    }

    /**
     * @param array<int, array<string, mixed>> $args
     */
    private function call(string $name, array $args): float
    {
        $spec = FormulaEngine::FUNCTIONS[$name] ?? null;
        if ($spec === null) {
            throw new FormulaException("Fungsi tidak dikenal: {$name}().");
        }
        [$min, $max] = $spec;
        $n = count($args);
        if ($n < $min || ($max !== null && $n > $max)) {
            throw new FormulaException("Fungsi {$name}() dipanggil dengan jumlah argumen yang salah.");
        }

        // IF dievaluasi lazy (short-circuit): hanya cabang terpilih dihitung,
        // sehingga cabang yang tak terpakai boleh mengandung ekspresi berisiko
        // (mis. pembagian yang divisornya nol pada kondisi lain).
        if ($name === 'IF') {
            $cond = $this->evaluate($args[0]);

            return abs($cond) > self::EPSILON
                ? $this->evaluate($args[1])
                : $this->evaluate($args[2]);
        }

        $vals = array_map(fn (array $a): float => $this->evaluate($a), $args);

        return match ($name) {
            'MIN'   => (float) min($vals),
            'MAX'   => (float) max($vals),
            'ABS'   => abs($vals[0]),
            'ROUND' => isset($vals[1]) ? round($vals[0], (int) $vals[1]) : round($vals[0]),
            'FLOOR' => floor($vals[0]),
            'CEIL'  => ceil($vals[0]),
            'AND'   => $this->all($vals) ? 1.0 : 0.0,
            'OR'    => $this->any($vals) ? 1.0 : 0.0,
            'NOT'   => abs($vals[0]) <= self::EPSILON ? 1.0 : 0.0,
            'CLAMP' => max($vals[1], min($vals[2], $vals[0])),
            default => throw new FormulaException("Fungsi {$name}() belum diimplementasikan."),
        };
    }

    /** @param array<int, float> $vals */
    private function all(array $vals): bool
    {
        foreach ($vals as $v) {
            if (abs($v) <= self::EPSILON) {
                return false;
            }
        }

        return true;
    }

    /** @param array<int, float> $vals */
    private function any(array $vals): bool
    {
        foreach ($vals as $v) {
            if (abs($v) > self::EPSILON) {
                return true;
            }
        }

        return false;
    }
}
