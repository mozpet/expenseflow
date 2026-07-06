<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // SQLite tidak mendukung MODIFY COLUMN / ENUM — kolom TEXT di SQLite sudah menerima nilai apapun
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE attendances MODIFY check_in_type ENUM('onsite','wfh','field') NULL");
            DB::statement("ALTER TABLE attendances MODIFY check_out_type ENUM('onsite','wfh','field') NULL");
        }
    }

    public function down(): void
    {
        DB::table('attendances')->where('check_in_type', 'field')->update(['check_in_type' => 'onsite']);
        DB::table('attendances')->where('check_out_type', 'field')->update(['check_out_type' => 'onsite']);

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE attendances MODIFY check_in_type ENUM('onsite','wfh') NULL");
            DB::statement("ALTER TABLE attendances MODIFY check_out_type ENUM('onsite','wfh') NULL");
        }
    }
};
