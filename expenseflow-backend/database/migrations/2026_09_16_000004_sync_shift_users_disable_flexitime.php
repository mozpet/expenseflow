<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Menegakkan aturan: Karyawan yang berjadwal shift atau pola rotasi aktif
     * TIDAK BOLEH mengaktifkan jam kerja fleksibel (flexitime_enabled = false).
     */
    public function up(): void
    {
        $today = Carbon::now('Asia/Jakarta')->toDateString();

        $activeShiftUserIds = DB::table('user_shifts')
            ->where(function ($q) {
                $q->whereNotNull('shift_id')->orWhereNotNull('shift_pattern_id');
            })
            ->whereDate('start_date', '<=', $today)
            ->where(function ($q) use ($today) {
                $q->whereNull('end_date')->orWhereDate('end_date', '>=', $today);
            })
            ->pluck('user_id')
            ->unique();

        if ($activeShiftUserIds->isNotEmpty()) {
            DB::table('users')
                ->whereIn('id', $activeShiftUserIds)
                ->where('flexitime_enabled', true)
                ->update(['flexitime_enabled' => false]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // One-way data sanitization migration, no rollback needed
    }
};
