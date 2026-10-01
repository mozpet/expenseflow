<?php

namespace App\Services\Payroll\Bank;

use App\Models\PayrollPaymentBatch;

/**
 * Format transfer massal BCA KlikBisnis (varian CSV terdokumentasi) — Fase 4.
 *
 * Tata-letak (tanpa baris header, sesuai gaya unggah bulk BCA):
 *   No Rekening Tujuan, Nama Penerima, Nominal, Berita
 *
 * Catatan: ini varian CSV yang dapat diganti tata-letak proprietary asli (mis.
 * berkas ".txt" lebar-tetap) kelak tanpa mengubah pemanggil.
 */
class BcaKlikBisnisFormatter extends AbstractCsvFormatter
{
    public function key(): string
    {
        return PayrollPaymentBatch::BANK_BCA;
    }

    public function label(): string
    {
        return 'BCA KlikBisnis (CSV)';
    }

    public function format(array $rows, array $meta): string
    {
        $period = (string) ($meta['period'] ?? '');
        $out = '';
        $out .= $this->csvLine(['No Rekening Tujuan', 'Nama Penerima', 'Nominal', 'Berita']);

        foreach ($rows as $row) {
            $out .= $this->csvLine([
                $row['bank_account_no'] ?? '',
                $row['bank_account_holder'] ?? ($row['employee_name'] ?? ''),
                $this->money((float) ($row['amount'] ?? 0)),
                'GAJI ' . $period,
            ]);
        }

        return $out;
    }
}
