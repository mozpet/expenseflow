<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keanggotaan grup payroll pada karyawan (MVP) — item lanjutan Payroll.
 *
 * NULL = karyawan belum masuk grup mana pun (perilaku lama: ikut batch reguler
 * seluruh perusahaan/cabang). Bila diisi, karyawan hanya ikut batch yang
 * `payroll_group_id`-nya sama. `nullOnDelete` → penghapusan grup melepas keanggotaan
 * tanpa menghapus user. JANGAN sentuh migrasi dasar create_users_table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('payroll_group_id')->nullable()->after('attendance_setting_id')
                ->constrained('payroll_groups')->nullOnDelete();
            $table->index(['company_id', 'payroll_group_id']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'payroll_group_id']);
            $table->dropConstrainedForeignId('payroll_group_id');
        });
    }
};
