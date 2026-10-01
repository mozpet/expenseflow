<?php

namespace App\Services\Payroll\Formula;

use RuntimeException;

/**
 * Kesalahan pada mesin formula DSL komponen gaji.
 *
 * Dilempar pada tahap lexing, parsing, validasi, maupun evaluasi. Pesan ditulis
 * dalam Bahasa Indonesia sehingga aman untuk langsung ditampilkan ke pengguna
 * (mis. HRD yang sedang menyusun rumus tunjangan). `position` (opsional) adalah
 * indeks karakter tempat kesalahan terdeteksi — berguna untuk menyorot di editor.
 */
class FormulaException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $position = null,
    ) {
        parent::__construct($message);
    }
}
