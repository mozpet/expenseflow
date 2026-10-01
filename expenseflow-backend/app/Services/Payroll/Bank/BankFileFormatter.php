<?php

namespace App\Services\Payroll\Bank;

/**
 * Kontrak driver format berkas transfer bank (Fase 4).
 *
 * Setiap bank punya tata-letak berkas bulk-transfer sendiri. Antarmuka ini
 * memisahkan "cara menyusun berkas" dari controller sehingga menambah bank baru
 * = menambah satu implementasi + mendaftarkannya di BankFileFactory (tanpa
 * menyentuh controller). Implementasi awal berupa varian CSV terdokumentasi;
 * tata-letak proprietary asli dapat menggantikannya kelak tanpa mengubah pemanggil.
 *
 * Bentuk baris ($rows[i]) — nomor rekening di sini PENUH (berkas privat):
 *   [
 *     'sequence'            => int,
 *     'employee_name'       => string,
 *     'employee_code'       => ?string,
 *     'bank_name'           => ?string,
 *     'bank_account_no'     => string,   // nomor rekening PENUH
 *     'bank_account_holder' => ?string,
 *     'amount'              => float,
 *   ]
 *
 * Bentuk meta:
 *   [
 *     'batch_reference' => string,
 *     'company_name'    => string,
 *     'period'          => string,       // mis. "2026-06"
 *     'value_date'      => string,       // Y-m-d (tanggal efektif transfer)
 *     'total_records'   => int,
 *     'total_amount'    => float,
 *     'currency'        => string,       // mis. "IDR"
 *   ]
 */
interface BankFileFormatter
{
    /** Kunci format (harus cocok dengan enum bank_format & PayrollPaymentBatch::BANK_*). */
    public function key(): string;

    /** Label ramah-pengguna untuk dropdown FE. */
    public function label(): string;

    /** Ekstensi berkas tanpa titik (mis. "csv"). */
    public function extension(): string;

    /** MIME type berkas (mis. "text/csv"). */
    public function mimeType(): string;

    /**
     * Susun isi berkas transfer.
     *
     * @param  array<int,array<string,mixed>>  $rows
     * @param  array<string,mixed>             $meta
     */
    public function format(array $rows, array $meta): string;
}
