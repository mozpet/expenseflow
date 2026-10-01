<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PPh 26 Tenaga Kerja Ekspatriat (Fase 6) — atribut subjek pajak pada profil pajak.
 *
 * - `tax_subject_type` : 'domestic' (Subjek Pajak Dalam Negeri → PPh 21) atau
 *                        'foreign'  (Subjek Pajak Luar Negeri → PPh 26 final).
 * - `treaty_country`   : kode negara mitra P3B (tax treaty) bila tarif khusus dipakai.
 * - `treaty_rate`      : tarif P3B (mis. 0.1000 = 10%). NULL → tarif umum 20% (Pasal 26 UU PPh).
 * - `foreign_tax_id`   : TIN/NPWP negara asal — PII, disimpan TERENKRIPSI (UU PDP 27/2022).
 *
 * Non-destruktif: seluruh kolom nullable / berdefault; baris lama otomatis 'domestic'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_tax_profiles', function (Blueprint $table) {
            $table->string('tax_subject_type', 20)->default('domestic')->after('ptkp_status');
            $table->string('treaty_country', 60)->nullable()->after('tax_subject_type');
            $table->decimal('treaty_rate', 6, 4)->nullable()->after('treaty_country');
            $table->text('foreign_tax_id')->nullable()->after('treaty_rate'); // terenkripsi
        });
    }

    public function down(): void
    {
        Schema::table('employee_tax_profiles', function (Blueprint $table) {
            $table->dropColumn(['tax_subject_type', 'treaty_country', 'treaty_rate', 'foreign_tax_id']);
        });
    }
};
