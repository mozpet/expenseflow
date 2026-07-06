<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReceiptImage extends Model
{
    protected $fillable = [
        'receipt_id', 'file_path', 'file_name', 'file_size', 'mime_type',
    ];

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(Receipt::class);
    }
}
