<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modul Exit Settlement (Fase 6, roadmap §9) — berkas perhitungan akhir per karyawan.
 *
 * Satu baris = satu kasus pengakhiran hubungan kerja (PHK / berakhirnya PKWT /
 * pengunduran diri / pensiun / meninggal). Berisi PARAMETER yang tidak dapat
 * disimpulkan otomatis dari data kepegawaian:
 *  - faktor pengali Uang Pesangon (UP) & UPMK sesuai alasan PHK (PP 35/2021 Pasal 40–59),
 *  - sisa hak cuti tahunan yang belum gugur (komponen UPH),
 *  - uang pisah & penggantian hak lain (biaya pulang, dsb. sesuai PP/PKB),
 *  - potongan pengembalian aset serta opsi pelunasan sisa kasbon.
 *
 * `user_id` memakai restrictOnDelete (FK finansial) agar riwayat pesangon tidak
 * hilang diam-diam. `payroll_id` terisi saat kasus ikut sebuah batch severance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('severance_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('payroll_id')->nullable()->constrained('payrolls')->nullOnDelete();

            // Dasar pengakhiran hubungan kerja.
            $table->string('termination_type', 40);           // lihat SeveranceCase::TYPE_*
            $table->text('termination_reason')->nullable();
            $table->date('termination_date');                 // tanggal efektif berakhirnya hubungan kerja
            $table->date('last_working_date')->nullable();    // hari kerja aktif terakhir (basis prorate)
            $table->string('employment_type', 30)->nullable(); // snapshot PKWTT/PKWT/Probation/Internship
            $table->date('contract_start_date')->nullable();  // PKWT: awal kontrak (basis uang kompensasi)
            $table->date('contract_end_date')->nullable();

            // Faktor pengali kompensasi (PP 35/2021).
            $table->decimal('up_multiplier', 5, 2)->default(1);    // 0 / 0.5 / 1 / 1.75 / 2 × tabel UP
            $table->decimal('upmk_multiplier', 5, 2)->default(1);  // umumnya 1× tabel UPMK
            $table->boolean('include_uph')->default(true);         // hak atas uang penggantian hak

            // Komponen uang penggantian hak (UPH) & lain-lain.
            $table->decimal('annual_leave_balance_days', 6, 2)->default(0); // sisa cuti belum gugur (hari)
            $table->decimal('relocation_cost', 15, 2)->default(0);          // biaya pulang ke tempat penerimaan
            $table->decimal('other_compensation', 15, 2)->default(0);       // penggantian hak lain (PP/PKB)
            $table->decimal('separation_pay', 15, 2)->default(0);           // uang pisah (bukan objek UP)

            // Potongan akhir.
            $table->decimal('asset_deduction', 15, 2)->default(0); // pengembalian aset yang tidak dikembalikan
            $table->boolean('settle_loans')->default(true);        // lunasi sisa kasbon dari pembayaran akhir

            $table->string('status', 20)->default('draft'); // draft | calculated | closed
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'user_id']);
            $table->index(['company_id', 'termination_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('severance_cases');
    }
};
