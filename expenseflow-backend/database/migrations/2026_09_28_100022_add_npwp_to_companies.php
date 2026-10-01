<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NPWP Pemberi Kerja (perusahaan) — item lanjutan Payroll.
 *
 * Sebelumnya NPWP pemberi kerja pada Draf 1721-A1 selalu "-" (placeholder) karena
 * model `Company` tak punya kolom NPWP. Kolom ini menyimpan NPWP perusahaan
 * TERENKRIPSI (cast `encrypted` di model Company, mirror EmployeeTaxProfile) →
 * nilai penuh HANYA tampil di berkas ter-stream (PDF 1721-A1, ekspor e-Bupot draf)
 * yang di-gate izin `manage` + audit; response JSON hanya mengirim versi termasking.
 *
 * `text` dipakai (bukan `string`) karena ciphertext Laravel jauh lebih panjang dari
 * 15–16 digit NPWP plaintext. JANGAN sentuh migrasi dasar pembuat tabel companies
 * (0001_01_01_000000_create_users_table.php).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->text('npwp')->nullable()->after('address');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('npwp');
        });
    }
};
