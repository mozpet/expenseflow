<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OvertimeApproval extends Model
{
    protected $fillable = [
        'attendance_id',
        'user_id',
        'company_id',
        'overtime_minutes',
        'status',
        'current_step',
        'spv_id',
        'spv_approved_at',
        'spv_notes',
        'reviewed_by',
        'reviewed_at',
        'notes',
        'is_auto_checkout',
        'overtime_reason',
    ];

    protected function casts(): array
    {
        return [
            'overtime_minutes' => 'integer',
            'spv_approved_at'  => 'datetime',
            'reviewed_at'      => 'datetime',
            'is_auto_checkout' => 'boolean',
        ];
    }

    /**
     * Jaring pengaman penyimpanan datetime lintas zona — lihat penjelasan lengkap
     * di App\Models\Attendance::fromDateTime().
     *
     * Ringkas: Laravel menulis datetime sebagai string jam dinding pada zona yang
     * dibawa instance Carbon-nya, dan membacanya kembali dengan zona default
     * aplikasi. Carbon bernuansa WITA/WIT yang masuk ke spv_approved_at /
     * reviewed_at akan tersimpan menjadi instan yang bergeser 1-2 jam. Override
     * ini memaksa semua penulisan ke jam dinding zona penyimpanan.
     */
    public function fromDateTime($value)
    {
        if ($value === null) {
            return null;
        }

        return parent::fromDateTime(
            $this->asDateTime($value)->copy()->setTimezone(\App\Support\Timezone::storage())
        );
    }

    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function spv(): BelongsTo
    {
        return $this->belongsTo(User::class, 'spv_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
