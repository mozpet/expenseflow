<?php

namespace App\Console\Commands;

use App\Models\AttendanceSetting;
use App\Models\LeaveBalance;
use App\Models\User;
use App\Services\FcmService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * ResetLeaveBalances — reset otomatis saldo cuti tahunan (ulang tiap 1 tahun).
 *
 * HRD menentukan tanggal reset per kantor di attendance_settings.leave_reset_date
 * (format 'MM-DD' tanpa tahun, mis. '01-01' → reset ulang setiap 1 Januari).
 * Command ini dijalankan harian: bila anniversary sudah tiba (atau sudah lewat
 * dan belum diproses), saldo cuti TAHUN BERJALAN semua karyawan aktif kantor tsb
 * yang SUDAH AKTIF (quota > 0) dibuat ulang:
 *   quota = default_leave_quota kantor, used = 0.
 *
 * KEBIJAKAN 2026-08-25: saldo cuti karyawan NON-AKTIF secara default — reset
 * TIDAK mengaktifkan saldo yang belum pernah diaktifkan HRD (quota 0 / tanpa baris).
 * Aktivasi tetap manual oleh HRD via tab Saldo Cuti.
 *
 * CATCH-UP: pemrosesan memakai last_leave_reset_on — bila server mati beberapa hari,
 * reset tetap dieksekusi sekali saat server menyala kembali (tidak dobel, tidak terlewat).
 *
 * Jadwal: setiap hari jam 00:03 (via routes/console.php scheduler).
 */
class ResetLeaveBalancesCommand extends Command
{
    protected $signature   = 'attendance:reset-leave-balances';
    protected $description = 'Reset saldo cuti karyawan sesuai tanggal anniversary per kantor (1x per tahun)';

    public function __construct(private FcmService $fcm)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $today = Carbon::today('Asia/Jakarta');

        // Kantor dengan jadwal reset aktif. Bila HRD mengisi jadwal di tengah tahun dan
        // anniversary tahun ini sudah lewat, saldo TAHUN INI langsung ikut di-reset sekali
        // (konsisten dgn semantik catch-up); tahun-tahun berikutnya reset tepat di tanggalnya.
        $offices = AttendanceSetting::whereNotNull('leave_reset_date')->get();

        if ($offices->isEmpty()) {
            $this->info('Tidak ada kantor dengan jadwal reset saldo cuti.');
            return self::SUCCESS;
        }

        $totalOffices = 0;
        $totalUsers   = 0;

        foreach ($offices as $office) {
            // leave_reset_date berformat 'MM-DD' (anniversary tanpa tahun).
            // Tanggal anniversary yang baru lewat / hari ini: contoh jadwal 01-01,
            // hari ini 25-08-2026 → anniversary = 01-01-2026.
            // Jika MM-DD sama dengan hari ini → anniversary = hari ini juga.
            [$mm, $dd] = explode('-', $office->leave_reset_date);
            $anniversary = Carbon::create(
                (int) $today->year, (int) $mm, (int) $dd, 0, 0, 0, 'Asia/Jakarta'
            );
            if ($anniversary->greaterThan($today)) {
                $anniversary->subYear(); // anniversary tahun ini belum tiba → pakai tahun lalu
            }

            // Sudah pernah diproses untuk anniversary ini? (catch-up + anti dobel)
            // Bandingkan Carbon-to-Carbon — objek vs string di PHP 8 selalu "lebih besar".
            if ($office->last_leave_reset_on && $office->last_leave_reset_on->gte($anniversary)) {
                continue;
            }

            // Karyawan aktif milik kantor ini (attendance_setting_id). Karyawan yang belum
            // di-assign kantor tidak ikut reset otomatis (saldo mereka pakai default global).
            $userIds = User::where('company_id', $office->company_id)
                ->where('attendance_setting_id', $office->id)
                ->where('is_active', true)
                ->pluck('id');

            $resetCount = 0;
            foreach ($userIds as $userId) {
                // KEBIJAKAN 2026-08-25: hanya baris saldo AKTIF (quota > 0) yang di-reset.
                // Karyawan yang belum pernah diaktifkan HRD (quota 0 / belum ada baris)
                // dibiarkan non-aktif — reset tidak boleh mengaktifkan saldo otomatis.
                $existingCuti = LeaveBalance::where('user_id', $userId)
                    ->where('year', $today->year)
                    ->where('leave_type', 'cuti')
                    ->first();

                if (! $existingCuti || (int) $existingCuti->quota <= 0) {
                    continue;
                }

                $existingIzin = LeaveBalance::where('user_id', $userId)
                    ->where('year', $today->year)
                    ->where('leave_type', 'izin')
                    ->first();

                $cutiQuota     = (int) $existingCuti->quota;
                $cutiUsed      = (int) $existingCuti->used;
                $cutiRemaining = max(0, $cutiQuota - $cutiUsed);
                $izinUsed      = (int) ($existingIzin?->used ?? 0);

                // 1. Simpan Snapshot / Arsip ke tabel leave_balance_histories
                \App\Models\LeaveBalanceHistory::create([
                    'user_id'               => $userId,
                    'company_id'            => $office->company_id,
                    'attendance_setting_id' => $office->id,
                    'period_label'          => 'Periode s/d ' . $anniversary->translatedFormat('d M Y'),
                    'period_start'          => $anniversary->copy()->subYear()->addDay()->toDateString(),
                    'period_end'            => $anniversary->copy()->subDay()->toDateString(),
                    'reset_date'            => $anniversary->toDateString(),
                    'cuti_quota'            => $cutiQuota,
                    'cuti_used'             => $cutiUsed,
                    'cuti_remaining'        => $cutiRemaining,
                    'izin_sakit_used'       => $izinUsed,
                    'notes'                 => "Reset tahunan jadwal kantor {$office->office_name}",
                ]);

                // 2. Reset saldo cuti tahunan (kuota baru dari kantor, pemakaian 0)
                $existingCuti->update([
                    'quota' => $office->default_leave_quota,
                    'used'  => 0,
                ]);

                // 3. Reset saldo izin & sakit menjadi 0 untuk periode baru
                if ($existingIzin) {
                    $existingIzin->update([
                        'used' => 0,
                    ]);
                }

                $resetCount++;
            }

            // Tandai anniversary ini sudah diproses SEBELUM nilai last_leave_reset_on
            // bisa tertinggal bila command mati di tengah loop.
            $office->update(['last_leave_reset_on' => $anniversary->toDateString()]);

            $totalOffices++;
            $totalUsers += $resetCount;

            $this->info("Kantor {$office->office_name}: {$resetCount} karyawan di-reset & diarsipkan (anniversary {$anniversary->toDateString()}).");
        }

        $this->info("Selesai: {$totalOffices} kantor, {$totalUsers} saldo cuti di-reset.");
        return self::SUCCESS;
    }
}
