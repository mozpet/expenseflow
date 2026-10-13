<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PERATURAN DENDA & BONUS KANTOR CABANG — 6 KEBIJAKAN BARU (2026-10-11)
 *
 * Melengkapi denda keterlambatan (2026_10_09_000001) & aturan upah lembur
 * (2026_10_10_000001) dengan enam kebijakan yang sebelumnya hanya ada di form
 * web tanpa kolom penyimpanan:
 *
 * 1. early_leave_*            — sanksi pulang cepat (per menit / flat / prorata).
 * 2. alpha_*                  — sanksi mangkir (prorata harian / + denda flat / bebas).
 *                               alpha_penalty_mode JUGA menjadi gerbang potongan
 *                               ketidakhadiran di PayrollCalculator: 'none' =
 *                               gaji pokok tidak dipotong sama sekali.
 * 3. unpaid_leave_*           — potongan izin pribadi (flat per izin / prorata harian).
 * 4. missed_checkout_*        — denda lupa check-out saat kena auto-checkout malam.
 * 5. perfect_attendance_*     — insentif kehadiran penuh (uang kerajinan) bulanan.
 * 6. night_shift_bonus_*      — premi shift malam per sesi presensi shift malam.
 *
 * Semua nominal NULL/0 berarti "tanpa denda/bonus" sehingga kantor lama
 * berperilaku persis seperti sebelum fitur ini ada. Default mode dipilih agar
 * netral: tidak ada rupiah yang bergerak tanpa HRD mengisi nominalnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_settings', function (Blueprint $table) {
            // 1) Sanksi pulang cepat — 'none' | 'per_minute' | 'flat' | 'prorata'
            $table->string('early_leave_fine_mode', 20)->default('none');
            $table->decimal('early_leave_fine_amount', 12, 2)->nullable();
            $table->decimal('early_leave_fine_max', 12, 2)->nullable();

            // 2) Sanksi mangkir/alpha — 'prorata' (perilaku lama) | 'prorata_plus_fine' | 'none'
            $table->string('alpha_penalty_mode', 25)->default('prorata');
            $table->decimal('alpha_fine_flat', 12, 2)->nullable();
            // Ambang jumlah hari alpha sebelum sistem menyarankan Surat Peringatan.
            // Informatif/pelaporan — tidak memotong rupiah.
            $table->unsignedSmallInteger('alpha_sp_threshold')->default(3);

            // 3) Potongan izin pribadi — 'flat' (per izin) | 'prorata' (per hari) | 'none'
            $table->string('unpaid_leave_penalty_mode', 20)->default('none');
            $table->decimal('unpaid_leave_fine_flat', 12, 2)->nullable();

            // 4) Denda lupa check-out — 'none' | 'flat_fine' | 'mark_early_leave'
            $table->string('missed_checkout_penalty_mode', 25)->default('none');
            $table->decimal('missed_checkout_fine_amount', 12, 2)->nullable();

            // 5) Insentif kehadiran penuh (uang kerajinan) per periode payroll
            $table->boolean('perfect_attendance_bonus_enabled')->default(false);
            $table->decimal('perfect_attendance_bonus_amount', 12, 2)->nullable();
            // true = cuti tahunan resmi TIDAK menggugurkan bonus.
            $table->boolean('perfect_attendance_allow_leave')->default(true);

            // 6) Premi shift malam per sesi presensi
            $table->boolean('night_shift_bonus_enabled')->default(false);
            $table->decimal('night_shift_bonus_amount', 12, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('attendance_settings', function (Blueprint $table) {
            $table->dropColumn([
                'early_leave_fine_mode',
                'early_leave_fine_amount',
                'early_leave_fine_max',
                'alpha_penalty_mode',
                'alpha_fine_flat',
                'alpha_sp_threshold',
                'unpaid_leave_penalty_mode',
                'unpaid_leave_fine_flat',
                'missed_checkout_penalty_mode',
                'missed_checkout_fine_amount',
                'perfect_attendance_bonus_enabled',
                'perfect_attendance_bonus_amount',
                'perfect_attendance_allow_leave',
                'night_shift_bonus_enabled',
                'night_shift_bonus_amount',
            ]);
        });
    }
};
