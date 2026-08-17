<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            // Referensi ke holiday cuti bersama (null = bukan cuti bersama)
            $table->foreignId('holiday_id')
                ->nullable()
                ->after('rejection_reason')
                ->constrained()
                ->nullOnDelete();

            // Status opt-in karyawan terhadap cuti bersama:
            //   'pending'  → belum memilih (dibuat otomatis saat HRD tambah cuti bersama)
            //   'accepted' → karyawan memilih ikut, saldo terpotong
            //   'declined' → karyawan menolak (atau otomatis expired saat hari H)
            //   NULL       → bukan cuti bersama
            $table->string('collective_status')->nullable()->after('holiday_id');
        });
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropForeign(['holiday_id']);
            $table->dropColumn(['holiday_id', 'collective_status']);
        });
    }
};
