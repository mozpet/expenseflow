<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('holidays', function (Blueprint $table) {
            // is_collective = true → Cuti Bersama: karyawan opt-in via mobile, saldo cuti terpotong.
            // is_collective = false (default) → Libur biasa: tidak memotong saldo cuti karyawan.
            $table->boolean('is_collective')->default(false)->after('is_national');
        });
    }

    public function down(): void
    {
        Schema::table('holidays', function (Blueprint $table) {
            $table->dropColumn('is_collective');
        });
    }
};
