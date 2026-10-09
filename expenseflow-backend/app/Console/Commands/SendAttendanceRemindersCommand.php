<?php

namespace App\Console\Commands;

use App\Http\Controllers\API\ShiftController;
use App\Models\Attendance;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\FcmService;
use App\Support\Timezone;
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
 *
 * MULTI-ZONA (2026-10-08): satu eksekusi melayani SEMUA cabang, jadi karyawan
 * dikelompokkan per (zona cabang, tanggal lokal cabang) sebelum dievaluasi. Pada
 * instan yang sama cabang WIT bisa sudah berada di tanggal lokal BERIKUTNYA
 * dibanding cabang WIB (00:30 WIT = 22:30 WIB H-1), sehingga memakai satu
 * $today global akan melewatkan shift dini hari cabang timur dan membandingkan
 * "08:00" mereka terhadap jam dinding WIB.
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
        $isForce    = (bool) $this->option('force');
        $dateOption = $this->option('date');

        // Instan absolut tunggal untuk seluruh eksekusi; tiap cabang menafsirkannya
        // di zonanya sendiri.
        $nowInstant = now();

        // ─── 1. Karyawan yang berhak presensi mobile, tanpa bergantung tanggal ────
        $baseUsers = User::where('is_active', true)
            ->where('attendance_enabled', true)
            ->where(function ($q) {
                $q->where('wfh_enabled', true)
                  ->orWhere('dinas_luar_enabled', true);
            })
            ->with(['office'])
            ->get();

        // ─── 2. Tambahan: karyawan dengan pengajuan WFH approved ─────────────────
        // Jendela query sengaja melebar satu hari: tanggal lokal cabang paling timur
        // bisa sudah H+1 dibanding tanggal zona penyimpanan. Pencocokan tepatnya
        // dilakukan per karyawan terhadap tanggal lokal cabangnya sendiri di bawah.
        $storageToday = $nowInstant->copy()->setTimezone(Timezone::storage())->toDateString();
        $windowStart  = $dateOption ?: $storageToday;
        $windowEnd    = $dateOption ?: Carbon::parse($storageToday, Timezone::storage())->addDay()->toDateString();

        $wfhApprovals = LeaveRequest::where('status', 'approved')
            ->where('leave_type', 'wfh')
            ->whereDate('start_date', '<=', $windowEnd)
            ->whereDate('end_date', '>=', $windowStart)
            ->get(['user_id', 'start_date', 'end_date']);

        $extraUsers = collect();
        if ($wfhApprovals->isNotEmpty()) {
            $extraUsers = User::where('is_active', true)
                ->whereIn('id', $wfhApprovals->pluck('user_id')->unique()->all())
                ->whereNotIn('id', $baseUsers->pluck('id')->all())
                ->with(['office'])
                ->get();
        }

        // ─── 3. Kelompokkan per zona cabang penempatan ────────────────────────────
        // Tiap kelompok dievaluasi terhadap TANGGAL LOKAL cabangnya sendiri. Memakai
        // satu tanggal global akan melewatkan shift dini hari cabang timur.
        $groups = [];
        foreach ($baseUsers as $user) {
            $tz = $user->effectiveTimezone();
            $groups[$tz] ??= collect();
            $groups[$tz]->push($user);
        }

        foreach ($extraUsers as $user) {
            $tz   = $user->effectiveTimezone();
            $date = $dateOption ?: $nowInstant->copy()->setTimezone($tz)->toDateString();
            if (! $this->wfhApprovalCoversDate($wfhApprovals, $user->id, $date)) {
                continue;
            }
            $groups[$tz] ??= collect();
            $groups[$tz]->push($user);
        }

        if (empty($groups)) {
            $this->info('Tidak ada karyawan dengan izin presensi mobile (WFH / Dinas Luar) aktif.');
            return self::SUCCESS;
        }

        $counts = ['start' => 0, 'end' => 0, 'cutoff' => 0];
        $dates  = [];

        foreach ($groups as $tz => $usersInZone) {
            $date    = $dateOption ?: $nowInstant->copy()->setTimezone($tz)->toDateString();
            $dates[] = $date;

            // Mode --date menyintesis instan dari tanggal yang diminta + jam lokal
            // sekarang, agar window reminder tetap bisa diuji.
            $nowLocal = $dateOption
                ? Carbon::parse($date . ' ' . $nowInstant->copy()->setTimezone($tz)->format('H:i:s'), $tz)
                : $nowInstant;

            $this->processZone($tz, $date, $usersInZone, $nowLocal, $isForce, $counts);
        }

        $summary = "Selesai: {$counts['start']} reminder masuk, {$counts['end']} reminder pulang, {$counts['cutoff']} peringatan cutoff terkirim.";
        $this->info($summary);
        Log::info("SendAttendanceRemindersCommand: {$summary}", [
            'dates'     => array_values(array_unique($dates)),
            'timezones' => array_keys($groups),
        ]);

        return self::SUCCESS;
    }

    /**
     * Apakah salah satu pengajuan WFH approved karyawan ini mencakup tanggal lokal tsb?
     */
    private function wfhApprovalCoversDate($wfhApprovals, int $userId, string $date): bool
    {
        foreach ($wfhApprovals as $approval) {
            if ((int) $approval->user_id !== $userId) {
                continue;
            }
            $start = Carbon::parse($approval->start_date)->toDateString();
            $end   = Carbon::parse($approval->end_date)->toDateString();
            if ($date >= $start && $date <= $end) {
                return true;
            }
        }

        return false;
    }

    /**
     * Evaluasi & kirim reminder untuk satu zona cabang pada tanggal lokalnya.
     *
     * @param  string  $tz        Zona bisnis cabang — SELURUH jam dinding ("08:00")
     *                            dibangun di zona ini, dan labelnya dipakai di pesan.
     * @param  string  $today     Tanggal LOKAL cabang (bukan tanggal zona penyimpanan).
     * @param  Carbon  $nowLocal  Instan "sekarang"; perbandingannya absolut.
     * @param  array   $counts    Akumulator lintas zona (by reference).
     */
    private function processZone(string $tz, string $today, $users, Carbon $nowLocal, bool $isForce, array &$counts): void
    {
        $zona = Timezone::label($tz);

        // Karyawan yang sedang cuti/izin/sakit yang sudah disetujui (approved) hari ini
        // (CATATAN: leave_type 'wfh' dikecualikan karena WFH adalah mode kerja, bukan izin/cuti tidak masuk)
        $approvedLeaves = LeaveRequest::whereIn('user_id', $users->pluck('id'))
            ->where('status', 'approved')
            ->whereNotIn('leave_type', ['wfh'])
            ->whereDate('start_date', '<=', $today)
            ->whereDate('end_date', '>=', $today)
            ->pluck('user_id')
            ->flip();

        // Hari libur nasional/perusahaan pada tanggal ini
        $companyIds = $users->pluck('company_id')->filter()->unique();
        $holidaysToday = Holiday::with('excludedUsers:id')
            ->whereDate('date', $today)
            ->where(function ($q) use ($companyIds) {
                $q->whereNull('company_id')
                  ->orWhereIn('company_id', $companyIds);
            })
            ->get();

        // Data presensi hari ini untuk para karyawan
        $attendances = Attendance::whereIn('user_id', $users->pluck('id'))
            ->whereDate('date', $today)
            ->get()
            ->keyBy('user_id');

        // Bulk resolve jadwal kerja hari ini (efisien 3 query total tanpa N+1)
        $schedules = ShiftController::resolveSchedulesBulk($users, $today);

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

            // Jam dinding jadwal WAJIB dibangun di zona cabang: "08:00" cabang
            // Makassar adalah 07:00 WIB dalam instan absolut.
            $workStart = Carbon::parse("{$today} {$jamMasuk}", $tz);
            $workEnd   = $jamPulang ? Carbon::parse("{$today} {$jamPulang}", $tz) : null;

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

                if ($nowLocal->gte($reminderStartWindowOpen) && $nowLocal->lt($reminderStartWindowClose)) {
                    $cacheKey = "attendance_remind_start_{$user->id}_{$today}";
                    if ($isForce || ! cache()->has($cacheKey)) {
                        $title = '⏰ Pengingat Jam Masuk Kerja';
                        // Jam & label zona WAJIB sepasang: "08:00 WIB" untuk karyawan
                        // cabang Makassar tidak cocok dengan jam di ponselnya.
                        $body  = "15 menit lagi jam kerja Anda dimulai ({$jamMasuk} {$zona}). Jangan lupa lakukan presensi masuk di aplikasi!";
                        $this->sendNotification($user, 'checkin_reminder', $title, $body, [
                            'work_start_time' => $jamMasuk,
                            'date'            => $today,
                            'timezone'        => $tz,
                            'timezone_label'  => $zona,
                        ], $attendance?->id);

                        cache()->put($cacheKey, true, now()->addHours(24));
                        $counts['start']++;
                        $this->line("  [Masuk] {$user->name} ({$jamMasuk} {$zona})");
                    }
                }
            }

            // ─── B. REMINDER JAM PULANG (Tepat Saat Jam Kerja Selesai) ────────────
            // Syarat: Karyawan sudah check-in, tetapi belum check-out
            // Window: Mulai jam pulang s/d 30 menit setelah jam pulang
            if ($hasCheckedIn && ! $hasCheckedOut && $workEnd) {
                $reminderEndWindowOpen  = $workEnd->copy();
                $reminderEndWindowClose = $workEnd->copy()->addMinutes(30);

                if ($nowLocal->gte($reminderEndWindowOpen) && $nowLocal->lt($reminderEndWindowClose)) {
                    $cacheKey = "attendance_remind_end_{$user->id}_{$today}";
                    if ($isForce || ! cache()->has($cacheKey)) {
                        $title = '🏠 Jam Kerja Selesai';
                        $body  = "Jam kerja Anda telah berakhir ({$jamPulang} {$zona}). Jangan lupa lakukan presensi pulang (check-out) di aplikasi!";
                        $this->sendNotification($user, 'checkout_reminder', $title, $body, [
                            'work_end_time'  => $jamPulang,
                            'attendance_id'  => (string) $attendance->id,
                            'date'           => $today,
                            'timezone'       => $tz,
                            'timezone_label' => $zona,
                        ], $attendance->id);

                        cache()->put($cacheKey, true, now()->addHours(24));
                        $counts['end']++;
                        $this->line("  [Pulang] {$user->name} ({$jamPulang} {$zona})");
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

                if ($nowLocal->gte($cutoffWarningWindowOpen) && $nowLocal->lt($cutoffWarningWindowClose)) {
                    $cacheKey = "attendance_remind_cutoff_{$user->id}_{$today}";
                    if ($isForce || ! cache()->has($cacheKey)) {
                        // $cutoffTime sudah bernuansa $tz (turunan $workStart), jadi
                        // format('H:i') langsung menghasilkan jam lokal cabang.
                        $jamCutoff = $cutoffTime->format('H:i');
                        $title = '⚠️ Peringatan Batas Presensi!';
                        $body  = "15 menit lagi presensi masuk hari ini akan ditutup ({$jamCutoff} {$zona}). Segera lakukan presensi di aplikasi agar tidak tercatat Alpha!";
                        $this->sendNotification($user, 'cutoff_warning', $title, $body, [
                            'cutoff_time'     => $jamCutoff,
                            'cutoff_minutes'  => (string) $cutoffMinutes,
                            'work_start_time' => $jamMasuk,
                            'date'            => $today,
                            'timezone'        => $tz,
                            'timezone_label'  => $zona,
                        ], $attendance?->id);

                        cache()->put($cacheKey, true, now()->addHours(24));
                        $counts['cutoff']++;
                        $this->line("  [Cutoff] {$user->name} (Cutoff: {$jamCutoff} {$zona})");
                    }
                }
            }
        }
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
