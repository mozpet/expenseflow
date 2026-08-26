<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Ubah ENUM status di tabel attendances agar menerima nilai 'early_leave'.
        // Nilai lama dipertahankan: present, late, absent, wfh.
        //
        // CATATAN: raw ALTER ... MODIFY hanya valid di MySQL. SQLite (dipakai test suite)
        // menyimpan enum sebagai TEXT tanpa constraint → tidak perlu alter apa pun.
        if (DB::getDriverName() === 'mysql') {
            DB::statement("
                ALTER TABLE attendances
                MODIFY COLUMN status ENUM('present','late','absent','wfh','early_leave')
                NOT NULL DEFAULT 'absent'
            ");
        }
    }

    public function down(): void
    {
        // Kembalikan ke enum tanpa early_leave.
        // Baris yang sudah 'early_leave' akan jadi string kosong (MySQL behavior saat downgrade enum).
        if (DB::getDriverName() === 'mysql') {
            DB::statement("
                ALTER TABLE attendances
                MODIFY COLUMN status ENUM('present','late','absent','wfh')
                NOT NULL DEFAULT 'absent'
            ");
        }
    }
};
