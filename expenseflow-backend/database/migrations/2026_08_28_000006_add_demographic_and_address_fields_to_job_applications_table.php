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
        Schema::table('job_applications', function (Blueprint $table) {
            if (!Schema::hasColumn('job_applications', 'gender')) {
                $table->string('gender', 20)->nullable()->after('full_name')->comment('Jenis Kelamin: Laki-laki / Perempuan');
            }
            if (!Schema::hasColumn('job_applications', 'birth_place')) {
                $table->string('birth_place', 100)->nullable()->after('gender')->comment('Tempat Lahir');
            }
            if (!Schema::hasColumn('job_applications', 'birth_date')) {
                $table->date('birth_date')->nullable()->after('birth_place')->comment('Tanggal Lahir');
            }
            if (!Schema::hasColumn('job_applications', 'nationality')) {
                $table->string('nationality', 50)->nullable()->default('WNI (Indonesia)')->after('birth_date')->comment('Kewarganegaraan');
            }
            if (!Schema::hasColumn('job_applications', 'postal_code')) {
                $table->string('postal_code', 10)->nullable()->after('address')->comment('Kode Pos');
            }
            if (!Schema::hasColumn('job_applications', 'province')) {
                $table->string('province', 100)->nullable()->after('postal_code')->comment('Provinsi');
            }
            if (!Schema::hasColumn('job_applications', 'city')) {
                $table->string('city', 100)->nullable()->after('province')->comment('Kota / Kabupaten');
            }
            if (!Schema::hasColumn('job_applications', 'district')) {
                $table->string('district', 100)->nullable()->after('city')->comment('Kecamatan');
            }
            if (!Schema::hasColumn('job_applications', 'subdistrict')) {
                $table->string('subdistrict', 100)->nullable()->after('district')->comment('Kelurahan / Desa');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('job_applications', function (Blueprint $table) {
            $columns = ['gender', 'birth_place', 'birth_date', 'nationality', 'postal_code', 'province', 'city', 'district', 'subdistrict'];
            foreach ($columns as $column) {
                if (Schema::hasColumn('job_applications', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
