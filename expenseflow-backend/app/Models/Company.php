<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    protected $fillable = [
        'name',
        'email',
        'phone',
        'address',
        'npwp',
        'logo',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            // NPWP pemberi kerja tersimpan terenkripsi di DB, otomatis didekripsi
            // saat dibaca. Jangan pernah mengirim plaintext ke response API — pakai
            // maskedNpwp() untuk JSON, nilai penuh hanya di berkas ter-stream (PDF/XML).
            'npwp'      => 'encrypted',
        ];
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }

    /**
     * NPWP perusahaan termasking untuk ditampilkan (mis. ••••••••••••1234).
     * Jangan pernah mengirim NPWP plaintext ke response API.
     */
    public function maskedNpwp(): ?string
    {
        $npwp = $this->npwp;
        if (empty($npwp)) {
            return null;
        }

        $digits = preg_replace('/\D/', '', (string) $npwp);
        if ($digits === '') {
            return null;
        }

        $last4 = substr($digits, -4);

        return str_repeat('•', max(0, strlen($digits) - 4)) . $last4;
    }
}
