<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tambah kolom end_date ke user_shifts.
     *
     * end_date (nullable):
     *   - NULL   = shift berlaku tanpa batas waktu (perilaku lama)
     *   - Terisi = shift otomatis "berakhir" setelah tanggal ini;
     *              resolveSchedule() akan fallback ke jam default kantor
     *              untuk tanggal-tanggal setelah end_date.
     */
    public function up(): void
    {
        Schema::table('user_shifts', function (Blueprint $table) {
            // Letakkan setelah start_date agar urutan kolom logis
            $table->date('end_date')->nullable()->after('start_date');

            // Validasi: end_date harus >= start_date (cukup di layer aplikasi,
            // constraint DB tidak diperlukan karena MySQL CHECK support-nya terbatas)
        });
    }

    public function down(): void
    {
        Schema::table('user_shifts', function (Blueprint $table) {
            $table->dropColumn('end_date');
        });
    }
};
