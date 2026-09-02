<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PasswordResetOtp extends Model
{
    protected $table = 'password_reset_otps';

    protected $fillable = [
        'email',
        'otp_hash',
        'reset_token',
        'attempts',
        'expires_at',
        'resend_available_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'resend_available_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }
}
