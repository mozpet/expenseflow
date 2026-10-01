<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Diskriminator batch payroll — item lanjutan Payroll (Grup + THR).
 *
 * Menambah dua kolom pembeda ruang-lingkup batch:
 *  - `run_type`        : 'regular' (default) | 'thr' — memisahkan batch reguler dari batch THR.
 *  - `payroll_group_id`: opsional; membatasi batch ke anggota satu grup payroll.
 *
 * HAZARD 1 — REBUILD UNIQUE INDEX:
 * Unique lama `payrolls_period_branch_unique(company_id, attendance_setting_id, period_year,
 * period_month)` DIGANTI agar batch reguler & THR (dan antar-grup) pada periode+cabang yang
 * sama tidak saling menabrak. Baris lama otomatis backfill `run_type='regular'`,
 * `payroll_group_id=NULL` → ruang-lingkup identik dengan sebelumnya (tak ada regresi).
 *
 * Catatan NULL-distinct: pada MySQL nilai NULL dianggap distinct sehingga unique index ini
 * TIDAK bisa mencegah duplikat saat attendance_setting_id/payroll_group_id NULL — guard
 * aplikatif di PayrollController::store() tetap otoritatif (lihat hazard 3 pada rencana).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payrolls', function (Blueprint $table) {
            $table->string('run_type', 20)->default('regular')->after('attendance_setting_id');
            $table->foreignId('payroll_group_id')->nullable()->after('run_type')
                ->constrained('payroll_groups')->nullOnDelete();

            // Ganti unique index periode+cabang lama dengan versi ber-scope lengkap.
            $table->dropUnique('payrolls_period_branch_unique');
            $table->unique(
                ['company_id', 'run_type', 'payroll_group_id', 'attendance_setting_id', 'period_year', 'period_month'],
                'payrolls_period_scope_unique',
            );
            $table->index(['company_id', 'run_type']);
        });
    }

    public function down(): void
    {
        Schema::table('payrolls', function (Blueprint $table) {
            $table->dropUnique('payrolls_period_scope_unique');
            $table->dropIndex(['company_id', 'run_type']);
            $table->dropConstrainedForeignId('payroll_group_id');
            $table->dropColumn('run_type');

            // Pulihkan unique index lama agar down() benar-benar reversibel.
            $table->unique(
                ['company_id', 'attendance_setting_id', 'period_year', 'period_month'],
                'payrolls_period_branch_unique',
            );
        });
    }
};
