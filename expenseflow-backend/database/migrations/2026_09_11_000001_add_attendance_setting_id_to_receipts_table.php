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
        Schema::table('receipts', function (Blueprint $table) {
            $table->foreignId('attendance_setting_id')
                ->nullable()
                ->after('user_id')
                ->constrained('attendance_settings')
                ->nullOnDelete();

            $table->index('attendance_setting_id');
        });

        // Backfill cabang untuk struk yang sudah ada dari penempatan kantor user
        if (DB::getDriverName() === 'sqlite') {
            DB::statement("
                UPDATE receipts
                SET attendance_setting_id = (
                    SELECT users.attendance_setting_id
                    FROM users
                    WHERE users.id = receipts.user_id
                )
                WHERE attendance_setting_id IS NULL
                  AND EXISTS (
                      SELECT 1 FROM users
                      WHERE users.id = receipts.user_id AND users.attendance_setting_id IS NOT NULL
                  )
            ");
        } else {
            DB::statement("
                UPDATE receipts r
                JOIN users u ON r.user_id = u.id
                SET r.attendance_setting_id = u.attendance_setting_id
                WHERE r.attendance_setting_id IS NULL AND u.attendance_setting_id IS NOT NULL
            ");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('receipts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('attendance_setting_id');
        });
    }
};
