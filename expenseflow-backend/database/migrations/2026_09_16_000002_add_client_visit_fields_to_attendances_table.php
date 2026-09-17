<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE attendances MODIFY check_in_type ENUM('onsite','wfh','field','dinas_luar') NULL");
            DB::statement("ALTER TABLE attendances MODIFY check_out_type ENUM('onsite','wfh','field','dinas_luar') NULL");
        }

        Schema::table('attendances', function (Blueprint $table) {
            $table->string('client_name')->nullable()->after('check_in_photo');
            $table->text('client_address')->nullable()->after('client_name');
            $table->text('visit_notes')->nullable()->after('client_address');
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropColumn(['client_name', 'client_address', 'visit_notes']);
        });

        DB::table('attendances')->where('check_in_type', 'dinas_luar')->update(['check_in_type' => 'field']);
        DB::table('attendances')->where('check_out_type', 'dinas_luar')->update(['check_out_type' => 'field']);

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE attendances MODIFY check_in_type ENUM('onsite','wfh','field') NULL");
            DB::statement("ALTER TABLE attendances MODIFY check_out_type ENUM('onsite','wfh','field') NULL");
        }
    }
};
