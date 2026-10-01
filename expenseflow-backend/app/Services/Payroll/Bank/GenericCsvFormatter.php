<?php

namespace App\Services\Payroll\Bank;

use App\Models\PayrollPaymentBatch;

/**
 * Format CSV generik (default) — Fase 4.
 *
 * Tata-letak lengkap & netral-bank (baris header + satu baris per karyawan +
 * baris TOTAL). Cocok sebagai berkas kerja internal atau untuk bank yang menerima
 * CSV bebas. Kolom:
 *   No, Nama Karyawan, Kode Karyawan, Bank, No Rekening, Nama Pemilik, Jumlah
 */
class GenericCsvFormatter extends AbstractCsvFormatter
{
    public function key(): string
    {
        return PayrollPaymentBatch::BANK_GENERIC;
    }

    public function label(): string
    {
        return 'CSV Generik';
    }

    public function format(array $rows, array $meta): string
    {
        $out = '';
        // Baris metadata (komentar informatif; diawali '#').
        $out .= $this->csvLine(['# Referensi', $meta['batch_reference'] ?? '']);
        $out .= $this->csvLine(['# Perusahaan', $meta['company_name'] ?? '']);
        $out .= $this->csvLine(['# Periode', $meta['period'] ?? '']);
        $out .= $this->csvLine(['# Tanggal Efektif', $meta['value_date'] ?? '']);

        // Header kolom.
        $out .= $this->csvLine([
            'No', 'Nama Karyawan', 'Kode Karyawan', 'Bank', 'No Rekening', 'Nama Pemilik', 'Jumlah',
        ]);

        foreach ($rows as $row) {
            $out .= $this->csvLine([
                $row['sequence'] ?? '',
                $row['employee_name'] ?? '',
                $row['employee_code'] ?? '',
                $row['bank_name'] ?? '',
                $row['bank_account_no'] ?? '',
                $row['bank_account_holder'] ?? '',
                $this->money((float) ($row['amount'] ?? 0)),
            ]);
        }

        // Baris total (jumlah record + total nominal).
        $out .= $this->csvLine([
            'TOTAL', (int) ($meta['total_records'] ?? count($rows)), '', '', '', '',
            $this->money((float) ($meta['total_amount'] ?? 0)),
        ]);

        return $out;
    }
}
