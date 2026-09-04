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
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'gender')) {
                $table->string('gender', 20)->nullable()->after('phone')->comment('Jenis Kelamin: Laki-laki / Perempuan');
            }
            if (!Schema::hasColumn('users', 'birth_place')) {
                $table->string('birth_place', 100)->nullable()->after('gender')->comment('Tempat Lahir');
            }
            if (!Schema::hasColumn('users', 'birth_date')) {
                $table->date('birth_date')->nullable()->after('birth_place')->comment('Tanggal Lahir');
            }
            if (!Schema::hasColumn('users', 'is_pregnant')) {
                $table->boolean('is_pregnant')->default(false)->after('birth_date')->comment('Status kehamilan pekerja perempuan (K3 Shift Malam)');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $columns = ['gender', 'birth_place', 'birth_date', 'is_pregnant'];
            foreach ($columns as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
