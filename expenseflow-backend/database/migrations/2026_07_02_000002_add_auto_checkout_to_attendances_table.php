<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            // Waktu auto checkout oleh sistem (bukan checkout manual karyawan)
            $table->timestamp('auto_checkout_at')->nullable()->after('check_out_type');
            // Apakah checkout ini dilakukan otomatis oleh sistem
            $table->boolean('is_auto_checkout')->default(false)->after('auto_checkout_at');
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropColumn(['auto_checkout_at', 'is_auto_checkout']);
        });
    }
};
