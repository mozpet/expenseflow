<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Template shift dimiliki oleh CABANG (attendance_settings), bukan hanya perusahaan.
     * Tiap cabang punya set shift sendiri (mis. pola shift Cabang Surabaya beda dari Jakarta).
     *
     * Nullable:
     *   - Terisi → template khusus cabang tsb.
     *   - NULL   → template company-wide (bisa dipakai lintas cabang) sebagai fallback.
     * nullOnDelete: jika cabang dihapus, template tidak ikut terhapus (jadi company-wide).
     */
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->foreignId('attendance_setting_id')
                ->nullable()
                ->after('company_id')
                ->constrained('attendance_settings')
                ->nullOnDelete();

            $table->index('attendance_setting_id');
        });
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('attendance_setting_id');
        });
    }
};
