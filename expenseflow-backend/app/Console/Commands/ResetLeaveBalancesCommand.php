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
 * dan belum diproses), saldo cuti semua karyawan aktif kantor tsb untuk TAHUN
 * BERJALAN dibuat ulang:
 *   quota = default_leave_quota kantor, used = 0.
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
                // Buat-ulang saldo cuti TAHUN BERJALAN: kuota baru dari kantor, pemakaian nol.
                // Baris tahun sebelumnya dibiarkan utk riwayat/audit.
                LeaveBalance::updateOrCreate(
                    ['user_id' => $userId, 'year' => $today->year, 'leave_type' => 'cuti'],
                    ['company_id' => $office->company_id, 'quota' => $office->default_leave_quota, 'used' => 0]
                );
                $resetCount++;
            }

            // Tandai anniversary ini sudah diproses SEBELUM nilai last_leave_reset_on
            // bisa tertinggal bila command mati di tengah loop.
            $office->update(['last_leave_reset_on' => $anniversary->toDateString()]);

            $totalOffices++;
            $totalUsers += $resetCount;

            $this->info("Kantor {$office->office_name}: {$resetCount} karyawan di-reset (anniversary {$anniversary->toDateString()}).");
        }

        $this->info("Selesai: {$totalOffices} kantor, {$totalUsers} saldo cuti di-reset.");
        return self::SUCCESS;
    }
}
