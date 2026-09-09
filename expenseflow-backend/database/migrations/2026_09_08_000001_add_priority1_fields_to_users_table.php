<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Prioritas 1 — Field krusial Legal, K3, & Operasional Dasar.
     * Ref: doc/rules.md § Seksi 10
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // ─── Kontak Darurat ──────────────────────────────────
            if (!Schema::hasColumn('users', 'emergency_contact_name')) {
                $table->string('emergency_contact_name', 150)->nullable()->after('is_pregnant')
                    ->comment('Nama kontak darurat');
            }
            if (!Schema::hasColumn('users', 'emergency_contact_relation')) {
                $table->string('emergency_contact_relation', 50)->nullable()->after('emergency_contact_name')
                    ->comment('Hubungan dgn kontak darurat: Orang Tua, Suami/Istri, Saudara, dll');
            }
            if (!Schema::hasColumn('users', 'emergency_contact_phone')) {
                $table->string('emergency_contact_phone', 20)->nullable()->after('emergency_contact_relation')
                    ->comment('No HP kontak darurat');
            }
            if (!Schema::hasColumn('users', 'emergency_contact_address')) {
                $table->text('emergency_contact_address')->nullable()->after('emergency_contact_phone')
                    ->comment('Alamat kontak darurat');
            }

            // ─── Alamat KTP ─────────────────────────────────────
            if (!Schema::hasColumn('users', 'ktp_address')) {
                $table->text('ktp_address')->nullable()->after('emergency_contact_address')
                    ->comment('Alamat sesuai KTP');
            }
            if (!Schema::hasColumn('users', 'ktp_postal_code')) {
                $table->string('ktp_postal_code', 10)->nullable()->after('ktp_address')
                    ->comment('Kode pos KTP');
            }
            if (!Schema::hasColumn('users', 'ktp_city')) {
                $table->string('ktp_city', 100)->nullable()->after('ktp_postal_code')
                    ->comment('Kota/Kabupaten sesuai KTP');
            }
            if (!Schema::hasColumn('users', 'ktp_province')) {
                $table->string('ktp_province', 100)->nullable()->after('ktp_city')
                    ->comment('Provinsi sesuai KTP');
            }

            // ─── Alamat Domisili ────────────────────────────────
            if (!Schema::hasColumn('users', 'domicile_address')) {
                $table->text('domicile_address')->nullable()->after('ktp_province')
                    ->comment('Alamat domisili tinggal saat ini');
            }
            if (!Schema::hasColumn('users', 'is_domicile_same_as_ktp')) {
                $table->boolean('is_domicile_same_as_ktp')->default(false)->after('domicile_address')
                    ->comment('True jika domisili = alamat KTP');
            }

            // ─── Agama ──────────────────────────────────────────
            if (!Schema::hasColumn('users', 'religion')) {
                $table->string('religion', 20)->nullable()->after('is_domicile_same_as_ktp')
                    ->comment('Agama: Islam, Kristen, Katolik, Hindu, Buddha, Konghucu');
            }

            // ─── Status Sipil ───────────────────────────────────
            if (!Schema::hasColumn('users', 'marital_status')) {
                $table->string('marital_status', 20)->nullable()->after('religion')
                    ->comment('Status perkawinan: single, married, divorced, widowed');
            }
            if (!Schema::hasColumn('users', 'number_of_dependents')) {
                $table->tinyInteger('number_of_dependents')->default(0)->after('marital_status')
                    ->comment('Jumlah tanggungan (anak/PTKP)');
            }

            // ─── K3 & Medis ─────────────────────────────────────
            if (!Schema::hasColumn('users', 'blood_type')) {
                $table->string('blood_type', 5)->nullable()->after('number_of_dependents')
                    ->comment('Golongan darah: A, B, AB, O (opsional +/-)');
            }
            if (!Schema::hasColumn('users', 'medical_conditions')) {
                $table->text('medical_conditions')->nullable()->after('blood_type')
                    ->comment('Riwayat alergi / kondisi medis khusus untuk K3');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $columns = [
                'emergency_contact_name', 'emergency_contact_relation',
                'emergency_contact_phone', 'emergency_contact_address',
                'ktp_address', 'ktp_postal_code', 'ktp_city', 'ktp_province',
                'domicile_address', 'is_domicile_same_as_ktp',
                'religion', 'marital_status', 'number_of_dependents',
                'blood_type', 'medical_conditions',
            ];
            foreach ($columns as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
