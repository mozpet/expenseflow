<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Tipe hubungan kerja
            $table->enum('employment_type', ['PKWTT', 'PKWT', 'Probation', 'Internship'])
                ->nullable()
                ->after('phone');

            // Tanggal bergabung (field terpisah — bisa berbeda dari created_at untuk karyawan lama)
            $table->date('joined_date')
                ->nullable()
                ->after('employment_type');

            // Durasi kontrak — hanya relevan untuk PKWT
            $table->date('contract_start_date')
                ->nullable()
                ->after('joined_date');

            $table->date('contract_end_date')
                ->nullable()
                ->after('contract_start_date');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'employment_type',
                'joined_date',
                'contract_start_date',
                'contract_end_date',
            ]);
        });
    }
};
