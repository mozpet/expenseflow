<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            if (!Schema::hasColumn('leave_requests', 'attendance_setting_id')) {
                $table->foreignId('attendance_setting_id')
                    ->nullable()
                    ->after('company_id')
                    ->constrained('attendance_settings')
                    ->nullOnDelete()
                    ->comment('Snapshot cabang kantor saat pengajuan cuti');
            }

            if (!Schema::hasColumn('leave_requests', 'balance_before')) {
                $table->integer('balance_before')
                    ->nullable()
                    ->after('holiday_compensated_days')
                    ->comment('Snapshot sisa saldo sebelum disetujui');
            }

            if (!Schema::hasColumn('leave_requests', 'balance_after')) {
                $table->integer('balance_after')
                    ->nullable()
                    ->after('balance_before')
                    ->comment('Snapshot sisa saldo setelah disetujui');
            }

            if (!Schema::hasColumn('leave_requests', 'leave_policy_snapshot')) {
                $table->json('leave_policy_snapshot')
                    ->nullable()
                    ->after('balance_after')
                    ->comment('Snapshot JSON aturan/kebijakan cuti saat disetujui');
            }
        });

        // Backfill data lama: isi attendance_setting_id dari data user terkait
        try {
            DB::statement("
                UPDATE leave_requests lr
                INNER JOIN users u ON lr.user_id = u.id
                SET lr.attendance_setting_id = u.attendance_setting_id
                WHERE lr.attendance_setting_id IS NULL AND u.attendance_setting_id IS NOT NULL
            ");
        } catch (\Throwable $e) {
            // Ignore on clean/sqlite test environments
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            if (Schema::hasColumn('leave_requests', 'attendance_setting_id')) {
                $table->dropForeign(['attendance_setting_id']);
                $table->dropColumn('attendance_setting_id');
            }
            if (Schema::hasColumn('leave_requests', 'balance_before')) {
                $table->dropColumn('balance_before');
            }
            if (Schema::hasColumn('leave_requests', 'balance_after')) {
                $table->dropColumn('balance_after');
            }
            if (Schema::hasColumn('leave_requests', 'leave_policy_snapshot')) {
                $table->dropColumn('leave_policy_snapshot');
            }
        });
    }
};
