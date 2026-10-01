<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fase 6 §6.A — Mesin Formula DSL komponen gaji.
     *
     * Menambah kolom `formula_dsl` (rumus aman yang dievaluasi FormulaEngine, BUKAN
     * eval PHP) dan memperluas `calc_type` agar menerima nilai 'formula'.
     *
     * `calc_type` diubah dari ENUM('fixed','manual','auto') menjadi string(20) supaya
     * PORTABEL lintas driver: MySQL menyimpan enum, sedangkan sqlite (dipakai test)
     * membuat CHECK constraint — keduanya sama-sama menolak nilai baru bila enum
     * dipertahankan. Dengan string(20), penambahan 'formula' tidak perlu mengutak-atik
     * constraint DB; validasi nilai tetap dijaga di level aplikasi (Rule::in pada
     * PayrollComponentController). Non-destruktif: nilai lama tetap.
     */
    public function up(): void
    {
        Schema::table('salary_components', function (Blueprint $table) {
            // Rumus DSL — hanya terisi bila calc_type = 'formula'.
            $table->text('formula_dsl')->nullable()->after('calc_type');
        });

        Schema::table('salary_components', function (Blueprint $table) {
            $table->string('calc_type', 20)->default('fixed')->change();
        });
    }

    public function down(): void
    {
        // Bersihkan baris formula agar tidak menyalahi enum saat dikembalikan.
        Schema::table('salary_components', function (Blueprint $table) {
            $table->dropColumn('formula_dsl');
        });

        Schema::table('salary_components', function (Blueprint $table) {
            $table->enum('calc_type', ['fixed', 'manual', 'auto'])->default('fixed')->change();
        });
    }
};
