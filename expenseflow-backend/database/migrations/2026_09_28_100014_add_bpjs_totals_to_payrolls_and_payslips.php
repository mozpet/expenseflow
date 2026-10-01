<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kolom ringkasan iuran BPJS (Fase 2).
     *
     * - Di payrolls: total iuran ditanggung PERUSAHAAN & KARYAWAN sepanjang batch
     *   (visibilitas beban pemberi kerja untuk finance).
     * - Di payslips: iuran perusahaan & karyawan per slip (baris informasi slip;
     *   iuran perusahaan BUKAN pengurang take-home pay, hanya info).
     */
    public function up(): void
    {
        Schema::table('payrolls', function (Blueprint $table) {
            $table->decimal('total_bpjs_company', 18, 2)->default(0)->after('total_tax');
            $table->decimal('total_bpjs_employee', 18, 2)->default(0)->after('total_bpjs_company');
        });

        Schema::table('payslips', function (Blueprint $table) {
            $table->decimal('bpjs_company_total', 15, 2)->default(0)->after('pph21');
            $table->decimal('bpjs_employee_total', 15, 2)->default(0)->after('bpjs_company_total');
        });
    }

    public function down(): void
    {
        Schema::table('payrolls', function (Blueprint $table) {
            $table->dropColumn(['total_bpjs_company', 'total_bpjs_employee']);
        });

        Schema::table('payslips', function (Blueprint $table) {
            $table->dropColumn(['bpjs_company_total', 'bpjs_employee_total']);
        });
    }
};
