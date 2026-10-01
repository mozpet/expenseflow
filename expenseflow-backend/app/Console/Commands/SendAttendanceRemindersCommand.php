<?php

namespace App\Console\Commands;

use App\Http\Controllers\API\ShiftController;
use App\Models\Attendance;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\FcmService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * SendAttendanceRemindersCommand — Pengingat presensi cerdas via FCM & Notification Center.
 *
 * Sesuai Kategori I (Smart Push Notifications):
 * 1. Reminder Jam Masuk: Kirim FCM 15 menit sebelum shift/jam kerja dimulai bagi karyawan yang belum check-in.
 * 2. Reminder Jam Pulang: Kirim FCM tepat pada saat jam kerja berakhir bagi karyawan yang belum check-out.
 * 3. Peringatan Batas Alpha: Kirim FCM 15 menit sebelum jam cutoff batas telat/alpha agar karyawan segera check-in.
 *
 * Aturan Khusus:
 * HANYA berlaku jika wfh_enabled on dan/atau dinas_luar_enabled on (bisa presensi lewat HP).
 * TIDAK berlaku jika wfh_enabled off DAN dinas_luar_enabled off (karyawan kantor murni absen di mesin fisik).
 *
 * Jadwal: Dijalankan setiap 10 menit via routes/console.php scheduler.
 */
class SendAttendanceRemindersCommand extends Command
{
    protected $signature = 'attendance:send-reminders {--date= : Tanggal evaluasi (format Y-m-d, default hari ini)} {--force : Abaikan cache deduplikasi untuk keperluan testing}';
    protected $description = 'Kirim pengingat presensi cerdas (jam masuk, jam pulang, batas cutoff alpha) via FCM & Notification Center';

    public function __construct(private FcmService $fcm)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $today = $this->option('date') ?: now('Asia/Jakarta')->toDateString();
        $isForce = (bool) $this->option('force');

        // Tentukan waktu evaluasi saat ini (WIB)
        $nowWib = $this->option('date')
            ? Carbon::parse($today . ' ' . now('Asia/Jakarta')->format('H:i:s'), 'Asia/Jakarta')
            : now('Asia/Jakarta');

        // 1. Ambil ID karyawan yang memiliki pengajuan WFH disetujui (approved) untuk hari ini
        $approvedWfhUserIds = LeaveRequest::where('status', 'approved')
            ->where('leave_type', 'wfh')
            ->whereDate('start_date', '<=', $today)
            ->whereDate('end_date', '>=', $today)
            ->pluck('user_id')
            ->toArray();

        // Ambil seluruh karyawan aktif yang BERHAK presensi mobile
        // (wfh_enabled = true ATAU dinas_luar_enabled = true ATAU memiliki pengajuan WFH approved hari ini)
        $users = User::where('is_active', true)
            ->where(function ($q) use ($approvedWfhUserIds) {
                $q->where(function ($qq) {
                    $qq->where('attendance_enabled', true)
                       ->where(function ($qqq) {
                           $qqq->where('wfh_enabled', true)
                               ->orWhere('dinas_luar_enabled', true);
                       });
                });
                if (! empty($approvedWfhUserIds)) {
                    $q->orWhereIn('id', $approvedWfhUserIds);
                }
            })
            ->with(['office'])
            ->get();

        if ($users->isEmpty()) {
            $this->info('Tidak ada karyawan dengan izin presensi mobile (WFH / Dinas Luar) aktif.');
            return self::SUCCESS;
        }

        // 2. Karyawan yang sedang cuti/izin/sakit yang sudah disetujui (approved) hari ini
        // (CATATAN: leave_type 'wfh' dikecualikan karena WFH adalah mode kerja, bukan izin/cuti tidak masuk)
        $approvedLeaves = LeaveRequest::whereIn('user_id', $users->pluck('id'))
            ->where('status', 'approved')
            ->whereNotIn('leave_type', ['wfh'])
            ->whereDate('start_date', '<=', $today)
            ->whereDate('end_date', '>=', $today)
            ->pluck('user_id')
            ->flip();

        // 3. Hari libur nasional/perusahaan pada tanggal ini
        $companyIds = $users->pluck('company_id')->filter()->unique();
        $holidaysToday = Holiday::with('excludedUsers:id')
            ->whereDate('date', $today)
            ->where(function ($q) use ($companyIds) {
                $q->whereNull('company_id')
                  ->orWhereIn('company_id', $companyIds);
            })
            ->get();

        // 4. Data presensi hari ini untuk para karyawan
        $attendances = Attendance::whereIn('user_id', $users->pluck('id'))
            ->whereDate('date', $today)
            ->get()
            ->keyBy('user_id');

        // 5. Bulk resolve jadwal kerja hari ini (efisien 3 query total tanpa N+1)
        $schedules = ShiftController::resolveSchedulesBulk($users, $today);

        $countStartReminders  = 0;
        $countEndReminders    = 0;
        $countCutoffWarnings  = 0;

        foreach ($users as $user) {
            // Abaikan jika karyawan sedang cuti/izin/sakit
            if ($approvedLeaves->has($user->id)) {
                continue;
            }

            // Abaikan jika hari ini adalah hari libur untuk karyawan tersebut
            if ($this->isUserHoliday($user, $holidaysToday)) {
                continue;
            }

            $schedule = $schedules[$user->id] ?? null;
            if (! $schedule || ! empty($schedule['is_off']) || empty($schedule['work_start_time'])) {
                continue; // Hari libur shift / off-day / tanpa jadwal
            }

            $jamMasuk = substr((string) $schedule['work_start_time'], 0, 5);
            $jamPulang = ! empty($schedule['work_end_time']) ? substr((string) $schedule['work_end_time'], 0, 5) : null;

            $workStart = Carbon::parse("{$today} {$jamMasuk}", 'Asia/Jakarta');
            $workEnd   = $jamPulang ? Carbon::parse("{$today} {$jamPulang}", 'Asia/Jakarta') : null;

            if ($workEnd && ! empty($schedule['is_cross_day']) && $workEnd->lte($workStart)) {
                $workEnd->addDay();
            }

            $office = $schedule['office'] ?? $user->office;
            $cutoffMinutes = $office?->late_checkin_cutoff_minutes;

            $attendance    = $attendances->get($user->id);
            $hasCheckedIn  = $attendance && ! empty($attendance->check_in_time);
            $hasCheckedOut = $attendance && ! empty($attendance->check_out_time);

            // ─── A. REMINDER JAM MASUK (15 Menit Sebelum Shift Dimulai) ───────────
            // Syarat: Karyawan belum check-in
            // Window: Mulai 15 menit sebelum masuk s/d 5 menit setelah jam masuk
            if (! $hasCheckedIn) {
                $reminderStartWindowOpen = $workStart->copy()->subMinutes(15);
                $reminderStartWindowClose = $workStart->copy()->addMinutes(5);

                if ($nowWib->gte($reminderStartWindowOpen) && $nowWib->lt($reminderStartWindowClose)) {
                    $cacheKey = "attendance_remind_start_{$user->id}_{$today}";
                    if ($isForce || ! cache()->has($cacheKey)) {
                        $title = '⏰ Pengingat Jam Masuk Kerja';
                        $body  = "15 menit lagi jam kerja Anda dimulai ({$jamMasuk} WIB). Jangan lupa lakukan presensi masuk di aplikasi!";
                        $this->sendNotification($user, 'checkin_reminder', $title, $body, [
                            'work_start_time' => $jamMasuk,
                            'date'            => $today,
                        ], $attendance?->id);

                        cache()->put($cacheKey, true, now()->addHours(24));
                        $countStartReminders++;
                        $this->line("  [Masuk] {$user->name} ({$jamMasuk} WIB)");
                    }
                }
            }

            // ─── B. REMINDER JAM PULANG (Tepat Saat Jam Kerja Selesai) ────────────
            // Syarat: Karyawan sudah check-in, tetapi belum check-out
            // Window: Mulai jam pulang s/d 30 menit setelah jam pulang
            if ($hasCheckedIn && ! $hasCheckedOut && $workEnd) {
                $reminderEndWindowOpen  = $workEnd->copy();
                $reminderEndWindowClose = $workEnd->copy()->addMinutes(30);

                if ($nowWib->gte($reminderEndWindowOpen) && $nowWib->lt($reminderEndWindowClose)) {
                    $cacheKey = "attendance_remind_end_{$user->id}_{$today}";
                    if ($isForce || ! cache()->has($cacheKey)) {
                        $title = '🏠 Jam Kerja Selesai';
                        $body  = "Jam kerja Anda telah berakhir ({$jamPulang} WIB). Jangan lupa lakukan presensi pulang (check-out) di aplikasi!";
                        $this->sendNotification($user, 'checkout_reminder', $title, $body, [
                            'work_end_time' => $jamPulang,
                            'attendance_id' => (string) $attendance->id,
                            'date'          => $today,
                        ], $attendance->id);

                        cache()->put($cacheKey, true, now()->addHours(24));
                        $countEndReminders++;
                        $this->line("  [Pulang] {$user->name} ({$jamPulang} WIB)");
                    }
                }
            }

            // ─── C. PERINGATAN BATAS ALPHA (15 Menit Sebelum Cutoff Telat) ─────────
            // Syarat: Karyawan belum check-in & kantor memiliki setting late_checkin_cutoff_minutes
            // Window: 15 menit sebelum cutoff s/d waktu cutoff tercapai
            if (! $hasCheckedIn && $cutoffMinutes !== null && $cutoffMinutes > 0) {
                $cutoffTime = $workStart->copy()->addMinutes($cutoffMinutes);
                $cutoffWarningWindowOpen  = $cutoffTime->copy()->subMinutes(15);
                $cutoffWarningWindowClose = $cutoffTime->copy();

                if ($nowWib->gte($cutoffWarningWindowOpen) && $nowWib->lt($cutoffWarningWindowClose)) {
                    $cacheKey = "attendance_remind_cutoff_{$user->id}_{$today}";
                    if ($isForce || ! cache()->has($cacheKey)) {
                        $jamCutoff = $cutoffTime->format('H:i');
                        $title = '⚠️ Peringatan Batas Presensi!';
                        $body  = "15 menit lagi presensi masuk hari ini akan ditutup ({$jamCutoff} WIB). Segera lakukan presensi di aplikasi agar tidak tercatat Alpha!";
                        $this->sendNotification($user, 'cutoff_warning', $title, $body, [
                            'cutoff_time'     => $jamCutoff,
                            'cutoff_minutes'  => (string) $cutoffMinutes,
                            'work_start_time' => $jamMasuk,
                            'date'            => $today,
                        ], $attendance?->id);

                        cache()->put($cacheKey, true, now()->addHours(24));
                        $countCutoffWarnings++;
                        $this->line("  [Cutoff] {$user->name} (Cutoff: {$jamCutoff} WIB)");
                    }
                }
            }
        }

        $summary = "Selesai: {$countStartReminders} reminder masuk, {$countEndReminders} reminder pulang, {$countCutoffWarnings} peringatan cutoff terkirim.";
        $this->info($summary);
        Log::info("SendAttendanceRemindersCommand: {$summary}", ['date' => $today]);

        return self::SUCCESS;
    }

    /**
     * Periksa apakah hari ini merupakan hari libur yang berlaku bagi karyawan.
     */
    private function isUserHoliday(User $user, $holidaysToday): bool
    {
        foreach ($holidaysToday as $holiday) {
            if ($holiday->company_id && $holiday->company_id !== $user->company_id) {
                continue;
            }
            if ($holiday->attendance_setting_id && $holiday->attendance_setting_id !== $user->attendance_setting_id) {
                continue;
            }
            // Jika karyawan dikecualikan dari hari libur ini (harus tetap kerja)
            $isExcluded = $holiday->excludedUsers->contains('id', $user->id);
            if (! $isExcluded) {
                return true; // Hari libur berlaku untuk user ini
            }
        }
        return false;
    }

    /**
     * Kirim notifikasi ke User (DB Notification Center + FCM push notification).
     */
    private function sendNotification(User $user, string $type, string $title, string $body, array $payloadData = [], ?int $attendanceId = null): void
    {
        // 1. Simpan ke tabel notifications untuk Web & Mobile Notification Center
        try {
            DB::table('notifications')->insert([
                'id'              => Str::uuid()->toString(),
                'type'            => $type,
                'notifiable_type' => 'App\\Models\\User',
                'notifiable_id'   => $user->id,
                'user_id'         => $user->id,
                'data'            => json_encode(array_merge([
                    'title'   => $title,
                    'message' => $body,
                    'type'    => $type,
                ], $payloadData)),
                'entity_type'     => 'attendance',
                'entity_id'       => $attendanceId,
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning("SendAttendanceRemindersCommand: Gagal menyimpan notification DB untuk user #{$user->id}: {$e->getMessage()}");
        }

        // 2. Kirim Push Notification FCM jika user memiliki fcm_token
        if ($user->fcm_token) {
            try {
                $fcmPayload = array_merge([
                    'type' => $type,
                ], array_map('strval', $payloadData));

                $this->fcm->send($user->fcm_token, $title, $body, $fcmPayload);
            } catch (\Throwable $e) {
                Log::warning("SendAttendanceRemindersCommand: Gagal kirim FCM ke user #{$user->id}: {$e->getMessage()}");
            }
        }
    }
}
