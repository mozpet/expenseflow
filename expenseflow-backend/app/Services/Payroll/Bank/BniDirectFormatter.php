<?php

namespace App\Services\Payroll\Bank;

use App\Models\PayrollPaymentBatch;

/**
 * Format BNI Direct massal (varian CSV terdokumentasi) — Fase 4.
 *
 * Tata-letak:
 *   No Urut, No Rekening Kredit, Nama Penerima, Mata Uang, Nominal, Berita
 */
class BniDirectFormatter extends AbstractCsvFormatter
{
    public function key(): string
    {
        return PayrollPaymentBatch::BANK_BNI;
    }

    public function label(): string
    {
        return 'BNI Direct (CSV)';
    }

    public function format(array $rows, array $meta): string
    {
        $period   = (string) ($meta['period'] ?? '');
        $currency = (string) ($meta['currency'] ?? 'IDR');
        $out = '';
        $out .= $this->csvLine(['No Urut', 'No Rekening Kredit', 'Nama Penerima', 'Mata Uang', 'Nominal', 'Berita']);

        foreach ($rows as $row) {
            $out .= $this->csvLine([
                $row['sequence'] ?? '',
                $row['bank_account_no'] ?? '',
                $row['bank_account_holder'] ?? ($row['employee_name'] ?? ''),
                $currency,
                $this->money((float) ($row['amount'] ?? 0)),
                'GAJI ' . $period,
            ]);
        }

        return $out;
    }
}
