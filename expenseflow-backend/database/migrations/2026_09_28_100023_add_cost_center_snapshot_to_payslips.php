<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Item 4 — Snapshot cost center pada slip gaji (alokasi jurnal GL per dimensi).
 *
 * Membekukan `division_id` (divisi/departemen) DAN `attendance_setting_id` (cabang/kantor)
 * ke tiap slip saat kalkulasi, sehingga jurnal GL dapat dikelompokkan per divisi/cabang
 * secara historis-akurat walau keanggotaan master berubah kemudian. Keduanya NULLABLE &
 * backward-compatible: slip lama tetap NULL (masuk segmen "tanpa divisi"/"tanpa cabang").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            $table->foreignId('division_id')->nullable()->after('department_name')
                ->constrained('divisions')->nullOnDelete();
            $table->foreignId('attendance_setting_id')->nullable()->after('division_id')
                ->constrained('attendance_settings')->nullOnDelete();

            // Percepat pengelompokan jurnal per dimensi dalam satu run.
            $table->index(['payroll_id', 'division_id']);
            $table->index(['payroll_id', 'attendance_setting_id']);
        });
    }

    public function down(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            $table->dropForeign(['division_id']);
            $table->dropForeign(['attendance_setting_id']);
            $table->dropIndex(['payroll_id', 'division_id']);
            $table->dropIndex(['payroll_id', 'attendance_setting_id']);
            $table->dropColumn(['division_id', 'attendance_setting_id']);
        });
    }
};
