<?php

namespace App\Services\Payroll\Bank;

/**
 * Basis umum untuk formatter berbasis CSV (Fase 4).
 *
 * Menyediakan util penulisan baris CSV yang aman (escaping RFC-4180: bungkus tanda
 * kutip bila mengandung koma/kutip/newline) dan pemformatan nominal tanpa pemisah
 * ribuan (titik desimal, 2 angka di belakang koma) agar mudah diparse mesin bank.
 */
abstract class AbstractCsvFormatter implements BankFileFormatter
{
    public function extension(): string
    {
        return 'csv';
    }

    public function mimeType(): string
    {
        return 'text/csv';
    }

    /**
     * Susun satu baris CSV (CRLF sesuai konvensi berkas bank).
     *
     * @param  array<int,int|float|string|null>  $fields
     */
    protected function csvLine(array $fields): string
    {
        $escaped = array_map(function ($value): string {
            $s = (string) $value;
            if (preg_match('/[",\r\n]/', $s) === 1) {
                $s = '"' . str_replace('"', '""', $s) . '"';
            }

            return $s;
        }, $fields);

        return implode(',', $escaped) . "\r\n";
    }

    /** Nominal untuk berkas bank: tanpa pemisah ribuan, 2 desimal (mis. 5000000.00). */
    protected function money(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
