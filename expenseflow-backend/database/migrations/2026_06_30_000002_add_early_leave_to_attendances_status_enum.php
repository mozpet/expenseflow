<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Ubah ENUM status di tabel attendances agar menerima nilai 'early_leave'.
        // Nilai lama dipertahankan: present, late, absent, wfh.
        DB::statement("
            ALTER TABLE attendances
            MODIFY COLUMN status ENUM('present','late','absent','wfh','early_leave')
            NOT NULL DEFAULT 'absent'
        ");
    }

    public function down(): void
    {
        // Kembalikan ke enum tanpa early_leave.
        // Baris yang sudah 'early_leave' akan jadi string kosong (MySQL behavior saat downgrade enum).
        DB::statement("
            ALTER TABLE attendances
            MODIFY COLUMN status ENUM('present','late','absent','wfh')
            NOT NULL DEFAULT 'absent'
        ");
    }
};
