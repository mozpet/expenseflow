<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('attendance_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('variance_limit')
                ->nullable()
                ->default(null)
                ->comment('Toleransi variansi (%) klaim struk khusus cabang. NULL = ikuti default perusahaan.');

            $table->decimal('max_claim_limit', 15, 2)
                ->nullable()
                ->default(null)
                ->comment('Batas maksimum per klaim (Rp) khusus cabang. NULL = ikuti default perusahaan.');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendance_settings', function (Blueprint $table) {
            $table->dropColumn(['variance_limit', 'max_claim_limit']);
        });
    }
};
