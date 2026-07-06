<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // SQLite tidak mendukung MODIFY COLUMN / ENUM — lewati, data tetap valid (TEXT)
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE leave_balances MODIFY COLUMN leave_type ENUM('cuti', 'sakit', 'izin') NOT NULL");
        }

        // Pindahkan baris sakit → izin, kuota 0 = tidak ada batas
        DB::table('leave_balances')
            ->where('leave_type', 'sakit')
            ->update(['leave_type' => 'izin', 'quota' => 0]);

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE leave_balances MODIFY COLUMN leave_type ENUM('cuti', 'izin') NOT NULL");
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE leave_balances MODIFY COLUMN leave_type ENUM('cuti', 'izin', 'sakit') NOT NULL");
        }

        DB::table('leave_balances')
            ->where('leave_type', 'izin')
            ->update(['leave_type' => 'sakit', 'quota' => 12]);

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE leave_balances MODIFY COLUMN leave_type ENUM('cuti', 'sakit') NOT NULL");
        }
    }
};
