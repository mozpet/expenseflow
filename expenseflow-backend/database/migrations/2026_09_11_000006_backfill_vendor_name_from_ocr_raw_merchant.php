<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('receipts')
            ->where(function ($q) {
                $q->whereNull('vendor_name')->orWhere('vendor_name', '');
            })
            ->whereNotNull('ocr_raw_merchant')
            ->where('ocr_raw_merchant', '!=', '')
            ->update(['vendor_name' => DB::raw('ocr_raw_merchant')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No-op: Data update does not need reversal
    }
};
