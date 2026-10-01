<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Override pemetaan akun Jurnal / General Ledger per-perusahaan (Fase 4 lanjutan).
 *
 * Menyimpan kode & nama akun COA untuk sebuah POS jurnal kanonik (`key`) milik
 * satu perusahaan. Himpunan pos + default-nya ada di `config/payroll_gl.php`;
 * tabel ini hanya menampung override. Lihat App\Services\Payroll\Gl\PayrollGlComposer.
 */
class PayrollGlAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'key',
        'account_code',
        'account_name',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
