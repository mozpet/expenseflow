<?php

namespace App\Services\Payroll\Bank;

use App\Models\PayrollPaymentBatch;

/**
 * Format BRI Cash Management System (CMS) massal (varian CSV terdokumentasi) — Fase 4.
 *
 * Tata-letak:
 *   No, No Rekening, Nama Penerima, Nominal, Keterangan
 */
class BriCmsFormatter extends AbstractCsvFormatter
{
    public function key(): string
    {
        return PayrollPaymentBatch::BANK_BRI;
    }

    public function label(): string
    {
        return 'BRI CMS (CSV)';
    }

    public function format(array $rows, array $meta): string
    {
        $period = (string) ($meta['period'] ?? '');
        $out = '';
        $out .= $this->csvLine(['No', 'No Rekening', 'Nama Penerima', 'Nominal', 'Keterangan']);

        foreach ($rows as $row) {
            $out .= $this->csvLine([
                $row['sequence'] ?? '',
                $row['bank_account_no'] ?? '',
                $row['bank_account_holder'] ?? ($row['employee_name'] ?? ''),
                $this->money((float) ($row['amount'] ?? 0)),
                'GAJI ' . $period,
            ]);
        }

        return $out;
    }
}
