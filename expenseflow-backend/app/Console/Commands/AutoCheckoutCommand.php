<?php

namespace App\Console\Commands;

use App\Http\Controllers\API\ShiftController;
use App\Models\Attendance;
use App\Models\AttendanceSetting;
use App\Models\OvertimeApproval;
use App\Models\User;
use App\Services\FcmService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * AutoCheckout — command yang dijalankan terjadwal setiap 5 menit.
 *
 * Fungsi:
 * 1. Kirim REMINDER FCM ke karyawan yang belum checkout ≥ checkout_reminder_minutes setelah jam pulang.
 * 2. AUTO-CHECKOUT karyawan yang masih belum checkout ≥ auto_checkout_grace_minutes setelah jam pulang.
 * 3. Hitung overtime_minutes otomatis.
 * 4. Buat record overtime_approval (status pending) dan notifikasi HRD.
 *
 * Jadwal: setiap 5 menit (via routes/console.php scheduler).
 */
class AutoCheckoutCommand extends Command
{
    protected $signature   = 'attendance:auto-checkout';
    protected $description = 'Kirim reminder checkout & auto-checkout karyawan WFH yang lupa checkout';

    private FcmService $fcm;

    public function __construct(FcmService $fcm)
    {
        parent::__construct();
        $this->fcm = $fcm;
    }

    public function handle(): int
    {
        $today  = now('Asia/Jakarta')->toDateString();
        $nowUtc = now(); // waktu UTC untuk perbandingan dengan datetime di DB

        // Ambil semua karyawan yang:
        //   - check-in hari ini
        //   - belum check-out
        //   - attendance_enabled = true (WFH / lapangan)
        $openAttendances = Attendance::whereDate('date', $today)
            ->whereNotNull('check_in_time')
            ->whereNull('check_out_time')
            ->with('user')
            ->get();

        if ($openAttendances->isEmpty()) {
            $this->info('Tidak ada presensi yang masih terbuka.');
            return self::SUCCESS;
        }

        // Kelompokkan setting kantor per company untuk hindari N+1 query
        $companyIds      = $openAttendances->pluck('company_id')->unique()->toArray();
        $officeSettings  = AttendanceSetting::whereIn('company_id', $companyIds)
            ->orderBy('id')
            ->get()
            ->groupBy('company_id');

        $totalReminder    = 0;
        $totalAutoCheckout = 0;

        foreach ($openAttendances as $attendance) {
            $companyId = $attendance->company_id;
            $office    = ($officeSettings[$companyId] ?? collect())->first();

            if (! $office || ! $office->work_end_time) {
                // Tidak ada setting kantor → tidak bisa tentukan batas waktu
                continue;
            }

            // Ambil jadwal efektif karyawan hari ini (shift aktif atau default kantor).
            // Jam pulang shift dipakai agar auto-checkout konsisten dengan checkOut manual.
            $schedule  = $attendance->user
                ? ShiftController::resolveSchedule($attendance->user, $today)
                : null;

            // Jika hari ini ditandai libur oleh shift (is_off) → jam pulang tidak relevan,
            // pakai jam pulang kantor sebagai acuan grace period auto-checkout.
            $jamPulang = ($schedule && ! $schedule['is_off'] && $schedule['work_end_time'])
                ? $schedule['work_end_time']
                : $office->work_end_time;

            $graceMins    = (int) ($office->auto_checkout_grace_minutes ?? 60);
            $reminderMins = (int) ($office->checkout_reminder_minutes ?? 30);

            $workEnd         = Carbon::parse($today . ' ' . $jamPulang, 'Asia/Jakarta')->utc();
            $reminderTime    = $workEnd->copy()->addMinutes($reminderMins);
            $autoCheckoutTime = $workEnd->copy()->addMinutes($graceMins);

            // Sudah lewat batas auto-checkout → lakukan auto-checkout
            if ($nowUtc->gte($autoCheckoutTime)) {
                $this->doAutoCheckout($attendance, $office, $today, $nowUtc);
                $totalAutoCheckout++;
                continue;
            }

            // Sudah lewat batas reminder (tapi belum waktunya auto-checkout) → kirim reminder
            if ($nowUtc->gte($reminderTime)) {
                $this->sendCheckoutReminder($attendance, $autoCheckoutTime, $graceMins);
                $totalReminder++;
            }
        }

        $this->info("Selesai: {$totalReminder} reminder dikirim, {$totalAutoCheckout} auto-checkout dilakukan.");
        Log::info("AutoCheckout: {$totalReminder} reminder, {$totalAutoCheckout} checkout.", ['date' => $today]);

        return self::SUCCESS;
    }

    // ─── Kirim push notification reminder checkout ────────────────────────────
    private function sendCheckoutReminder(Attendance $attendance, Carbon $autoCheckoutTime, int $graceMins): void
    {
        $user    = $attendance->user;
        $waktu   = $autoCheckoutTime->copy()->setTimezone('Asia/Jakarta')->format('H:i');

        // Cegah kirim reminder lebih dari sekali (cek di cache/DB)
        $cacheKey = "checkout_reminder_{$attendance->id}";
        if (cache()->has($cacheKey)) {
            return;
        }
        // Tandai sudah dikirim selama 25 menit (lebih pendek dari interval cek)
        cache()->put($cacheKey, true, now()->addMinutes(25));

        $title = '⏰ Jangan Lupa Checkout!';
        $body  = "Kamu belum checkout. Sistem akan otomatis checkout pukul {$waktu} WIB jika tidak segera checkout.";

        // Push notification FCM
        if ($user && $user->fcm_token) {
            $this->fcm->send($user->fcm_token, $title, $body, [
                'type'             => 'checkout_reminder',
                'attendance_id'    => (string) $attendance->id,
                'auto_checkout_at' => $autoCheckoutTime->toIso8601String(),
            ]);
        }

        // Simpan juga ke tabel notifications (untuk web dashboard & mobile notification center)
        if ($user) {
            DB::table('notifications')->insert([
                'id'              => Str::uuid()->toString(),
                'type'            => 'checkout_reminder',
                'notifiable_type' => 'App\\Models\\User',
                'notifiable_id'   => $user->id,
                'user_id'         => $user->id,
                'data'            => json_encode([
                    'message'          => $body,
                    'attendance_id'    => $attendance->id,
                    'auto_checkout_at' => $autoCheckoutTime->toIso8601String(),
                ]),
                'entity_type' => 'attendance',
                'entity_id'   => $attendance->id,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }

        $this->line("  Reminder → {$user?->name} (attendance #{$attendance->id})");
    }

    // ─── Lakukan auto-checkout ────────────────────────────────────────────────
    private function doAutoCheckout(Attendance $attendance, AttendanceSetting $office, string $today, Carbon $nowUtc): void
    {
        $user = $attendance->user;

        // Hitung jam kerja
        $checkOutTime = $nowUtc;
        $workMinutes  = (int) $attendance->check_in_time->diffInMinutes($checkOutTime);

        // Ambil jadwal efektif karyawan (shift aktif atau default kantor)
        $schedule = $user
            ? ShiftController::resolveSchedule($user, $today)
            : null;

        // Tentukan hari libur/weekend menurut kalender (libur nasional/weekend).
        // Dipakai untuk field is_holiday — samakan persis dengan checkOut() manual.
        $isNationalNonWorking = $this->isNonWorkingDay($today, $attendance->company_id);

        // Hitung overtime (sadar shift) — angka lembur konsisten dengan checkOut manual
        $overtimeMinutes = $this->calculateOvertime($office, $schedule, $today, $checkOutTime, $workMinutes, $isNationalNonWorking);

        $attendance->update([
            'check_out_time'   => $checkOutTime,
            'check_out_lat'    => $attendance->check_in_lat,    // pakai lokasi check-in karena tidak ada GPS
            'check_out_lng'    => $attendance->check_in_lng,
            'check_out_type'   => $attendance->check_in_type,
            'work_minutes'     => $workMinutes,
            'overtime_minutes' => $overtimeMinutes,
            'is_holiday'       => $isNationalNonWorking,
            'auto_checkout_at' => $checkOutTime,
            'is_auto_checkout' => true,
        ]);

        $attendance->refresh();

        // Log aktivitas
        DB::table('activity_logs')->insert([
            'company_id'  => $attendance->company_id,
            'user_id'     => $attendance->user_id,
            'action'      => 'attendance_auto_checkout',
            'description' => 'Auto-checkout oleh sistem (karyawan lupa checkout)',
            'entity_type' => 'attendance',
            'entity_id'   => $attendance->id,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        // Buat overtime_approval jika ada lembur
        if ($overtimeMinutes > 0 && ! OvertimeApproval::where('attendance_id', $attendance->id)->exists()) {
            $approval = OvertimeApproval::create([
                'attendance_id'    => $attendance->id,
                'user_id'          => $attendance->user_id,
                'company_id'       => $attendance->company_id,
                'overtime_minutes' => $overtimeMinutes,
                'status'           => 'pending',
                'is_auto_checkout' => true,
            ]);

            // Notifikasi ke HRD
            $overtimeFmt = $this->formatMinutes($overtimeMinutes);
            $tanggal     = Carbon::parse($today)->format('d/m/Y');

            $approvers = DB::table('users')
                ->where('company_id', $attendance->company_id)
                ->whereIn('role', ['hrd', 'admin', 'super_admin'])
                ->where('is_active', true)
                ->pluck('id');

            foreach ($approvers as $approverId) {
                DB::table('notifications')->insert([
                    'id'              => Str::uuid()->toString(),
                    'type'            => 'overtime_pending',
                    'notifiable_type' => 'App\\Models\\User',
                    'notifiable_id'   => $approverId,
                    'user_id'         => $approverId,
                    'data'            => json_encode([
                        'message'          => ($user ? $user->name : 'Karyawan') . " lembur {$overtimeFmt} ({$tanggal}) [Auto-Checkout]. Perlu persetujuan.",
                        'overtime_id'      => $approval->id,
                        'attendance_id'    => $attendance->id,
                        'user_id'          => $attendance->user_id,
                        'user_name'        => $user ? $user->name : null,
                        'overtime_minutes' => $overtimeMinutes,
                        'is_auto_checkout' => true,
                        'date'             => $tanggal,
                    ]),
                    'entity_type' => 'overtime_approval',
                    'entity_id'   => $approval->id,
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ]);
            }
        }

        // Notifikasi ke karyawan bahwa ia sudah di-auto-checkout
        $jamOut  = $checkOutTime->copy()->setTimezone('Asia/Jakarta')->format('H:i');
        $msgUser = "Anda telah di-checkout otomatis pukul {$jamOut} WIB. Lembur Anda menunggu persetujuan HRD.";
        if ($user && $user->fcm_token) {
            $this->fcm->send($user->fcm_token, '🔔 Auto-Checkout', $msgUser, [
                'type'          => 'auto_checkout',
                'attendance_id' => (string) $attendance->id,
            ]);
        }

        if ($user) {
            DB::table('notifications')->insert([
                'id'              => Str::uuid()->toString(),
                'type'            => 'auto_checkout',
                'notifiable_type' => 'App\\Models\\User',
                'notifiable_id'   => $user->id,
                'user_id'         => $user->id,
                'data'            => json_encode([
                    'message'       => $msgUser,
                    'attendance_id' => $attendance->id,
                    'check_out_at'  => $jamOut,
                ]),
                'entity_type' => 'attendance',
                'entity_id'   => $attendance->id,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }

        $this->line("  Auto-Checkout → {$user?->name} (attendance #{$attendance->id}) | lembur: {$this->formatMinutes($overtimeMinutes)}");
    }

    // ─── Helper: apakah tanggal hari libur / weekend ──────────────────────────
    private function isNonWorkingDay(string $date, ?int $companyId): bool
    {
        if (Carbon::parse($date)->isWeekend()) {
            return true;
        }
        return DB::table('holidays')
            ->whereDate('date', $date)
            ->where(function ($q) use ($companyId) {
                $q->whereNull('company_id')->orWhere('company_id', $companyId);
            })
            ->exists();
    }

    // ─── Helper: hitung menit lembur (sadar shift, sama dengan AttendanceController) ───────
    //     $schedule = hasil ShiftController::resolveSchedule() atau null (fallback kantor).
    //     - Shift menandai hari ini libur (is_off) → seluruh menit kerja jadi lembur.
    //     - Tanpa shift & hari libur nasional/weekend → seluruh menit kerja jadi lembur.
    //     - Hari kerja efektif → lembur dihitung setelah jam pulang yang berlaku (shift/kantor).
    private function calculateOvertime(AttendanceSetting $office, ?array $schedule, string $date, Carbon $checkOutTime, int $workMinutes, bool $isNationalNonWorking): int
    {
        if (! $office->overtime_enabled) {
            return 0;
        }

        // Kasus 1: jadwal shift menandai hari ini libur → seluruh menit kerja jadi lembur
        if ($schedule && $schedule['is_off']) {
            return max(0, $workMinutes);
        }

        // Kasus 2: tanpa shift (pakai default kantor) & hari ini libur nasional/weekend
        $pakaiDefaultKantor = ! $schedule || $schedule['source'] === 'office';
        if ($pakaiDefaultKantor && $isNationalNonWorking) {
            return max(0, $workMinutes);
        }

        // Kasus 3: hari kerja efektif → lembur setelah jam pulang yang berlaku
        $jamPulangStr = ($schedule && $schedule['work_end_time'])
            ? $schedule['work_end_time']
            : $office->work_end_time;

        if (! $jamPulangStr) {
            return 0;
        }

        $jamPulang = Carbon::parse($date . ' ' . $jamPulangStr, 'Asia/Jakarta')->utc();
        $lewat     = $checkOutTime->greaterThan($jamPulang)
            ? (int) $jamPulang->diffInMinutes($checkOutTime)
            : 0;

        return $lewat >= (int) $office->min_overtime_minutes ? $lewat : 0;
    }

    // ─── Helper: format menit → "Xj Ym" ──────────────────────────────────────
    private function formatMinutes(int $minutes): string
    {
        if ($minutes <= 0) {
            return '0j';
        }
        $jam  = intdiv($minutes, 60);
        $sisa = $minutes % 60;
        return $sisa === 0 ? "{$jam}j" : "{$jam}j {$sisa}m";
    }
}
