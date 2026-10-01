<?php

namespace App\Services\Payroll\Bank;

use App\Models\PayrollPaymentBatch;

/**
 * Format Mandiri Cash Management (MCM) massal (varian CSV terdokumentasi) — Fase 4.
 *
 * Tata-letak:
 *   Tipe, Bank, No Rekening, Nama Penerima, Nominal, Keterangan
 * Tipe "PB" = pemindahbukuan/transfer dalam bank (contoh; disesuaikan saat integrasi).
 */
class MandiriMcmFormatter extends AbstractCsvFormatter
{
    public function key(): string
    {
        return PayrollPaymentBatch::BANK_MANDIRI;
    }

    public function label(): string
    {
        return 'Mandiri Cash Management (CSV)';
    }

    public function format(array $rows, array $meta): string
    {
        $period = (string) ($meta['period'] ?? '');
        $out = '';
        $out .= $this->csvLine(['Tipe', 'Bank', 'No Rekening', 'Nama Penerima', 'Nominal', 'Keterangan']);

        foreach ($rows as $row) {
            $out .= $this->csvLine([
                'PB',
                strtoupper((string) ($row['bank_name'] ?? 'MANDIRI')),
                $row['bank_account_no'] ?? '',
                $row['bank_account_holder'] ?? ($row['employee_name'] ?? ''),
                $this->money((float) ($row['amount'] ?? 0)),
                'GAJI ' . $period,
            ]);
        }

        return $out;
    }
}
