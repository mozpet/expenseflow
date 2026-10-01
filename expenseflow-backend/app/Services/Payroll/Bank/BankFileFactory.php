<?php

namespace App\Services\Payroll\Bank;

use App\Models\PayrollPaymentBatch;

/**
 * Pabrik driver format berkas bank (Fase 4).
 *
 * Menambah bank baru = tambah implementasi BankFileFormatter + daftarkan di sini
 * (map() & all()). Controller tetap tak berubah.
 */
class BankFileFactory
{
    /** Buat formatter sesuai kunci format; fallback ke CSV generik bila tak dikenal. */
    public static function make(string $format): BankFileFormatter
    {
        return match ($format) {
            PayrollPaymentBatch::BANK_BCA     => new BcaKlikBisnisFormatter(),
            PayrollPaymentBatch::BANK_MANDIRI => new MandiriMcmFormatter(),
            PayrollPaymentBatch::BANK_BRI     => new BriCmsFormatter(),
            PayrollPaymentBatch::BANK_BNI     => new BniDirectFormatter(),
            default                           => new GenericCsvFormatter(),
        };
    }

    /** @return array<int,BankFileFormatter> semua driver terdaftar. */
    public static function all(): array
    {
        return [
            new GenericCsvFormatter(),
            new BcaKlikBisnisFormatter(),
            new MandiriMcmFormatter(),
            new BriCmsFormatter(),
            new BniDirectFormatter(),
        ];
    }

    /** Kunci format yang valid (untuk aturan validasi). */
    public static function keys(): array
    {
        return array_map(fn (BankFileFormatter $f) => $f->key(), self::all());
    }

    /**
     * Opsi format untuk dropdown FE.
     *
     * @return array<int,array{key:string,label:string,extension:string}>
     */
    public static function options(): array
    {
        return array_map(fn (BankFileFormatter $f) => [
            'key'       => $f->key(),
            'label'     => $f->label(),
            'extension' => $f->extension(),
        ], self::all());
    }
}
