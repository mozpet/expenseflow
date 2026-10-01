<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('allow_leave')->default(true)->after('allow_radius');
        });

        // Sinkronisasi: jika ada user yang kuota cuti tahunannya (cuti) pada tahun berjalan diset 0 oleh HRD,
        // set allow_leave = false agar status nonaktif langsung konsisten.
        $usersWithZeroCuti = DB::table('leave_balances')
            ->where('leave_type', 'cuti')
            ->where('quota', '<=', 0)
            ->where('year', now()->year)
            ->pluck('user_id');

        if ($usersWithZeroCuti->isNotEmpty()) {
            DB::table('users')
                ->whereIn('id', $usersWithZeroCuti)
                ->update(['allow_leave' => false]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('allow_leave');
        });
    }
};
