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
 * CATCH-UP: query TIDAK dibatasi tanggal hari ini/kemarin — SEMUA presensi terbuka diproses,
 * sehingga presensi lama yang tertinggal (server mati beberapa hari) tetap ditutup saat
 * server menyala kembali, bukan menggantung selamanya.
 *
 * Jam checkout = MIN(waktu eksekusi, jam pulang + grace). Artinya bila server baru menyala
 * SETELAH batas lewat, presensi ditutup pada JADWAL targetnya (jam pulang + grace), bukan
 * jam server menyala — agar work_minutes & overtime tidak membengkak palsu dan konsisten
 * dengan isi notifikasi reminder ("sistem akan otomatis checkout pukul XX:XX").
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

        // CATCH-UP: proses SEMUA presensi terbuka tanpa batas tanggal.
        // Sebelumnya hanya melihat hari ini + kemarin dan melewatkan record kemarin
        // non-cross-day — akibatnya bila scheduler mati lintas hari, presensi lama
        // menggantung permanen (kasus: server tidak menyala saat jam pulang).
        $openAttendances = Attendance::whereNotNull('check_in_time')
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

            // Tanggal shift asli record ini (bisa kemarin/lama untuk catch-up,
            // atau kemarin untuk shift malam lintas hari)
            $attDate = Carbon::parse($attendance->date)->toDateString();

            /*
             * SNAPSHOT (2026-08-26): bila record punya snapshot pengaturan saat check-in,
             * gunakan aturan TERSEBUT (jam pulang, grace, reminder, lembur, kantor acuan).
             * HRD yang mengubah setting kantor di siang hari tidak lagi mempengaruhi
             * presensi yang sudah berjalan — konsisten dengan checkOut() manual.
             * Baris lama (tanpa snapshot) tetap pakai jalur live seperti sebelumnya.
             */
            $snapshot = $attendance->snapshotSchedule();

            if ($snapshot && $snapshot['work_end_time'] && $snapshot['office']) {
                // Jam pulang snapshot sudah termasuk fallback jam pulang kantor di hari libur shift.
                $jamPulang       = $snapshot['work_end_time'];
                $isCrossDay      = ! empty($snapshot['is_cross_day']) && ! $snapshot['is_off'];
                $graceMins       = (int) ($attendance->snap_grace_minutes ?? 60);
                $reminderMins    = (int) ($attendance->snap_reminder_minutes ?? 30);
                $scheduleForCalc = $snapshot;
                $officeForCalc   = $snapshot['office'];
            } else {
                if (! $office || ! $office->work_end_time) {
                    // Tidak ada setting kantor → tidak bisa tentukan batas waktu
                    continue;
                }

                // Ambil jadwal efektif karyawan pada tanggal shift-nya (shift aktif atau default kantor).
                // Jam pulang shift dipakai agar auto-checkout konsisten dengan checkOut manual.
                $schedule = $attendance->user
                    ? ShiftController::resolveSchedule($attendance->user, $attDate)
                    : null;

                $isCrossDay = $schedule && ! empty($schedule['is_cross_day']) && ! $schedule['is_off'];

                // Jika hari ini ditandai libur oleh shift (is_off) → jam pulang tidak relevan,
                // pakai jam pulang kantor sebagai acuan grace period auto-checkout.
                $jamPulang = ($schedule && ! $schedule['is_off'] && $schedule['work_end_time'])
                    ? $schedule['work_end_time']
                    : $office->work_end_time;

                $graceMins    = (int) ($office->auto_checkout_grace_minutes ?? 60);
                $reminderMins = (int) ($office->checkout_reminder_minutes ?? 30);
                $scheduleForCalc = $schedule;
                $officeForCalc   = $office;
            }

            // Shift lintas hari: jam pulang berada di HARI BERIKUTNYA setelah tanggal shift.
            $jamPulangDate = $isCrossDay
                ? Carbon::parse($attDate, 'Asia/Jakarta')->addDay()->toDateString()
                : $attDate;

            $workEnd         = Carbon::parse($jamPulangDate . ' ' . substr($jamPulang, 0, 5), 'Asia/Jakarta')->utc();
            $reminderTime    = $workEnd->copy()->addMinutes($reminderMins);
            $autoCheckoutTime = $workEnd->copy()->addMinutes($graceMins);

            // Sudah lewat batas auto-checkout → lakukan auto-checkout.
            // Jam checkout di-clamp ke JADWAL TARGET (jam pulang + grace), bukan jam eksekusi,
            // agar presensi basi (server sempat mati) tidak mencatat kerja/lembur palsu
            // selama rentang server mati.
            if ($nowUtc->gte($autoCheckoutTime)) {
                $effectiveCheckOut = $nowUtc->lt($autoCheckoutTime->copy()->addMinutes(5))
                    ? $nowUtc   // masih dalam interval cek normal → pakai waktu nyata
                    : $autoCheckoutTime; // presensi basi / catch-up → pakai jadwal target

                // Kasus patologis: check-in terjadi SETELAH batas auto-checkout jadwal
                // (karyawan masuk sangat larut / di luar jam kerja, mis. 22:04 padahal
                // batas checkout 18:00). Clamping ke jadwal akan menghasilkan checkout
                // SEBELUM check-in (menit kerja negatif); memakai jam eksekusi untuk
                // record basi justru menciptakan lembur ribuan menit. Solusi: tutup tepat
                // di jam check-in tanpa lembur — data tetap tertutup, tanpa angka fiktif.
                $checkInUtc = Carbon::parse($attendance->check_in_time)->utc();
                $skipOvertime = false;
                if ($effectiveCheckOut->lessThan($checkInUtc)) {
                    $effectiveCheckOut = $checkInUtc;
                    $skipOvertime      = true;
                }

                $this->doAutoCheckout($attendance, $officeForCalc, $attDate, $effectiveCheckOut, $skipOvertime, $scheduleForCalc);
                $totalAutoCheckout++;
                continue;
            }

            // Sudah lewat batas reminder (tapi belum waktunya auto-checkout) → kirim reminder.
            // Reminder untuk record basi tidak relevan lagi (batas sudah lewat) — hanya kirim
            // bila auto-checkout memang masih di depan.
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
    // $attDate = tanggal shift asli (bisa kemarin untuk shift malam lintas hari),
    //            bukan tanggal hari ini. Penting untuk resolveSchedule() yang benar.
    // $schedule = jadwal acuan perhitungan — snapshot check-in bila ada, selain itu
    //             hasil resolveSchedule() live (baris lama). Null → resolve di sini.
    private function doAutoCheckout(Attendance $attendance, AttendanceSetting $office, string $attDate, Carbon $checkOutTime, bool $skipOvertime = false, ?array $schedule = null): void
    {
        $user = $attendance->user;

        // Jadwal efektif karyawan pada tanggal shift aslinya.
        // Untuk shift malam (check-in kemarin, checkout pagi ini), $attDate = kemarin —
        // sehingga resolveSchedule() membaca jadwal shift malam, bukan jadwal hari ini.
        if ($schedule === null) {
            $schedule = $user
                ? ShiftController::resolveSchedule($user, $attDate)
                : null;
        }

        // Hitung jam kerja dari jam JADWAL masuk (bukan jam check-in).
        // Konsisten dengan manual checkOut() di AttendanceController.
        $workStart   = $this->resolveWorkStart($attendance->check_in_time, $schedule, $attDate);
        $workMinutes = max(0, (int) $workStart->diffInMinutes($checkOutTime->copy()->setTimezone('Asia/Jakarta')));

        // Tentukan hari libur/weekend menurut kalender (libur nasional/weekend).
        // Dipakai untuk field is_holiday — samakan persis dengan checkOut() manual.
        // Pass user_id agar cuti bersama yang di-decline tidak dianggap hari libur.
        $isNationalNonWorking = $this->isNonWorkingDay($attDate, $attendance->company_id, $attendance->user_id);

        // Hitung overtime (sadar shift) — angka lembur konsisten dengan checkOut manual.
        // $skipOvertime: checkout patologis (ditutup tepat di jam check-in) → tanpa lembur.
        $overtimeMinutes = $skipOvertime
            ? 0
            : $this->calculateOvertime($office, $schedule, $attDate, $checkOutTime, $workMinutes, $isNationalNonWorking);

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

        // Notifikasi ke karyawan bahwa ia sudah di-auto-checkout
        $jamOut  = $checkOutTime->copy()->setTimezone('Asia/Jakarta')->format('H:i');
        $msgUser = $overtimeMinutes > 0
            ? "Anda telah di-checkout otomatis pukul {$jamOut} WIB. Lembur {$this->formatMinutes($overtimeMinutes)} terdeteksi — silakan ajukan via aplikasi jika ingin diklaim."
            : "Anda telah di-checkout otomatis pukul {$jamOut} WIB.";
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
    // Bug #3 Fix: Cuti bersama (is_collective=true) hanya dianggap libur bagi karyawan
    // yang ACCEPTED. Karyawan yang declined tetap masuk kerja hari itu seperti biasa.
    // SHIFT-AWARE FIX: weekend global tidak otomatis libur bila jadwal shift efektif
    // karyawan menandai hari itu MASUK (resolveSchedule is_off=false) — dan sebaliknya,
    // hari kerja kantor yang libur menurut shift tetap dianggap libur.
    private function isNonWorkingDay(string $date, ?int $companyId, ?int $userId = null): bool
    {
        // Cek jadwal shift efektif dulu (sudah dipanggil caller dengan tanggal yang sama)
        if ($userId) {
            $userModel = User::find($userId);
            if ($userModel) {
                $schedule = ShiftController::resolveSchedule($userModel, $date);
                if ($schedule['source'] !== 'none') {
                    return (bool) $schedule['is_off'];
                }
                // Tanpa shift & tanpa kantor → jatuh ke cek kalender di bawah
            }
        }

        if (Carbon::parse($date)->isWeekend()) {
            return true;
        }

        $matchingHolidays = DB::table('holidays')
            ->whereDate('date', $date)
            ->where(function ($q) use ($companyId) {
                $q->whereNull('company_id')->orWhere('company_id', $companyId);
            })
            ->get(['id', 'is_collective']);

        foreach ($matchingHolidays as $holiday) {
            // Untuk cuti bersama: hanya anggap libur jika karyawan memang ACCEPTED.
            if ($holiday->is_collective && $userId) {
                $accepted = DB::table('leave_requests')
                    ->where('holiday_id', $holiday->id)
                    ->where('user_id', $userId)
                    ->where('collective_status', 'accepted')
                    ->exists();
                if ($accepted) {
                    return true;
                }
                // Declined / pending → bukan libur untuk karyawan ini
                continue;
            }

            // Libur nasional atau libur perusahaan biasa → libur untuk semua
            return true;
        }

        return false;
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

        $minOvertime = (int) ($office->min_overtime_minutes ?? 30);

        // Kasus 1: jadwal shift menandai hari ini libur → seluruh menit kerja jadi lembur,
        //          asal mencapai min_overtime_minutes (anti-noise, konsisten dgn checkOut manual)
        if ($schedule && $schedule['is_off']) {
            $full = max(0, $workMinutes);

            return $full >= $minOvertime ? $full : 0;
        }

        // Kasus 2: tanpa shift (pakai default kantor) & hari ini libur nasional/weekend
        $pakaiDefaultKantor = ! $schedule || $schedule['source'] === 'office';
        if ($pakaiDefaultKantor && $isNationalNonWorking) {
            $full = max(0, $workMinutes);

            return $full >= $minOvertime ? $full : 0;
        }

        // Kasus 3: hari kerja efektif → lembur setelah jam pulang yang berlaku
        $jamPulangStr = ($schedule && $schedule['work_end_time'])
            ? $schedule['work_end_time']
            : $office->work_end_time;

        if (! $jamPulangStr) {
            return 0;
        }

        // Shift lintas hari (cross-day): jam pulang berada di HARI BERIKUTNYA.
        $jamPulangDate = ($schedule && ! empty($schedule['is_cross_day']) && ! $schedule['is_off'])
            ? Carbon::parse($date, 'Asia/Jakarta')->addDay()->toDateString()
            : $date;

        $jamPulang = Carbon::parse($jamPulangDate . ' ' . $jamPulangStr, 'Asia/Jakarta')->utc();
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

    // ─── Helper: tentukan titik awal perhitungan jam kerja ───────────────────
    //     Sama persis dengan resolveWorkStart() di AttendanceController.
    //     Jam kerja dihitung mulai dari jam JADWAL masuk, bukan jam check-in.
    //     Jika check-in sebelum jadwal → titik awal = jadwal (tidak ada bonus jam).
    //     Jika check-in terlambat     → titik awal = jam check-in aktual.
    private function resolveWorkStart(Carbon $checkInTime, ?array $schedule, string $date): Carbon
    {
        $workStartStr = $schedule['work_start_time'] ?? null;

        if (! $workStartStr) {
            return $checkInTime->copy()->setTimezone('Asia/Jakarta');
        }

        $workStart  = Carbon::parse($date . ' ' . $workStartStr, 'Asia/Jakarta');
        $checkInWib = $checkInTime->copy()->setTimezone('Asia/Jakarta');

        return $checkInWib->greaterThan($workStart) ? $checkInWib : $workStart;
    }
}

