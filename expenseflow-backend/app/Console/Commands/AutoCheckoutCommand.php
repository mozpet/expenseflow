<?php

namespace App\Console\Commands;

use App\Http\Controllers\API\ShiftController;
use App\Models\Attendance;
use App\Models\AttendanceSetting;
use App\Services\Attendance\AutoCheckoutCalculator;
use App\Services\Attendance\MissedCheckoutFineCalculator;
use App\Services\FcmService;
use App\Support\Duration;
use App\Support\Timezone;
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
 * MULTI-ZONA (2026-10-08): command ini memproses presensi SEMUA cabang dalam satu
 * eksekusi, jadi zonanya diambil PER RECORD dari snap_timezone (lewat
 * Attendance::effectiveTimezone() / $snapshot['timezone']) — bukan satu konstanta.
 * "$jamPulang 17:00" berarti 17:00 WITA untuk cabang Makassar dan 17:00 WIT untuk
 * cabang Jayapura; memakai jam dinding WIB akan menutup presensi cabang timur 1-2
 * jam terlambat dan menambahkan lembur palsu sebesar selisih zonanya.
 *
 * Perbandingan "$now sudah lewat batas?" sendiri tetap aman lintas zona karena Carbon
 * membandingkan INSTAN ABSOLUT — yang penting hanyalah $workEnd dibangun di zona yang
 * benar.
 *
 * Jadwal: setiap 5 menit (via routes/console.php scheduler).
 */
class AutoCheckoutCommand extends Command
{
    protected $signature   = 'attendance:auto-checkout';
    protected $description = 'Kirim reminder checkout & auto-checkout karyawan WFH yang lupa checkout';

    private FcmService $fcm;

    // Rumus jam kerja & lembur versi auto-checkout. Semantiknya SENGAJA berbeda
    // dari AttendanceRecalculator (lihat docblock service-nya) -- karena itu ia
    // punya service sendiri, bukan salinan privat di command ini seperti dulu.
    private AutoCheckoutCalculator $calc;

    public function __construct(FcmService $fcm, AutoCheckoutCalculator $calc)
    {
        parent::__construct();
        $this->fcm  = $fcm;
        $this->calc = $calc;
    }

    public function handle(): int
    {
        $today = now()->toDateString(); // hanya untuk baris log ringkasan
        // Instan "sekarang" tunggal untuk seluruh iterasi. Perbandingan gte()/lt()
        // di bawah membandingkan instan absolut, jadi zona variabel ini tidak
        // memengaruhi hasil — ia hanya perlu konsisten agar satu eksekusi tidak
        // memakai dua titik waktu berbeda.
        $nowInstant = now();

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

            // ─── Zona bisnis record ini ───────────────────────────────────────
            // Prioritas: snapshot check-in → jadwal live → kantor acuan → zona
            // penyimpanan. Baris presensi lama (snap_timezone NULL) jatuh ke zona
            // penyimpanan sehingga hasilnya identik dengan sebelum multi-zona.
            $tz = Timezone::normalize(
                $scheduleForCalc['timezone']
                    ?? $attendance->snap_timezone
                    ?? $officeForCalc?->timezone
            );

            // Shift lintas hari: jam pulang berada di HARI BERIKUTNYA setelah tanggal shift.
            $jamPulangDate = $isCrossDay
                ? Carbon::parse($attDate, $tz)->addDay()->toDateString()
                : $attDate;

            $workEnd = Carbon::parse($jamPulangDate . ' ' . substr($jamPulang, 0, 5), $tz);

            // Khusus flexitime: sesuaikan batas jam pulang dengan jam check-in aktual & snapshot
            $isFlexSession = $attendance->isFlexitimeSession($attendance->user);
            if ($isFlexSession && $officeForCalc) {
                $targetMins    = (int) ($officeForCalc->flex_target_minutes ?? 480);
                $breakMins     = (int) ($scheduleForCalc['break_minutes'] ?? $officeForCalc->break_minutes ?? 60);
                $checkInLocal  = Carbon::parse($attendance->check_in_time)->setTimezone($tz);
                $flexWorkStart = $this->calc->resolveWorkStart($checkInLocal, $scheduleForCalc, $attDate, $attendance->user, $attendance, $tz);
                $flexTargetEnd = $flexWorkStart->copy()->addMinutes($targetMins + $breakMins);

                if ($flexTargetEnd->greaterThan($workEnd)) {
                    $workEnd = $flexTargetEnd;
                }
            }

            $reminderTime     = $workEnd->copy()->addMinutes($reminderMins);
            $autoCheckoutTime = $workEnd->copy()->addMinutes($graceMins);

            // Sudah lewat batas auto-checkout → lakukan auto-checkout.
            // Jam checkout di-clamp ke JADWAL TARGET (jam pulang + grace), bukan jam eksekusi,
            // agar presensi basi (server sempat mati) tidak mencatat kerja/lembur palsu
            // selama rentang server mati.
            if ($nowInstant->gte($autoCheckoutTime)) {
                $effectiveCheckOut = $nowInstant->lt($autoCheckoutTime->copy()->addMinutes(5))
                    ? $nowInstant   // masih dalam interval cek normal → pakai waktu nyata
                    : $autoCheckoutTime; // presensi basi / catch-up → pakai jadwal target

                // Kasus patologis: check-in terjadi SETELAH batas auto-checkout jadwal
                // (karyawan masuk sangat larut / di luar jam kerja, mis. 22:04 padahal
                // batas checkout 18:00). Clamping ke jadwal akan menghasilkan checkout
                // SEBELUM check-in (menit kerja negatif); memakai jam eksekusi untuk
                // record basi justru menciptakan lembur ribuan menit. Solusi: tutup tepat
                // di jam check-in tanpa lembur — data tetap tertutup, tanpa angka fiktif.
                //
                // setTimezone($tz) di sini TIDAK mengubah hasil perbandingan
                // (lessThan membandingkan instan absolut), tapi membuat zona
                // $effectiveCheckOut konsisten dengan $workEnd — sebelumnya baris
                // ini satu-satunya yang mem-parse check_in_time tanpa setTimezone,
                // berbeda dari baris flexitime di atas.
                $checkInLocal = Carbon::parse($attendance->check_in_time)->setTimezone($tz);
                $skipOvertime = false;
                if ($effectiveCheckOut->lessThan($checkInLocal)) {
                    $effectiveCheckOut = $checkInLocal;
                    $skipOvertime      = true;
                }

                $this->doAutoCheckout($attendance, $officeForCalc, $attDate, $effectiveCheckOut, $skipOvertime, $scheduleForCalc, $tz);
                $totalAutoCheckout++;
                continue;
            }

            // Sudah lewat batas reminder (tapi belum waktunya auto-checkout) → kirim reminder.
            // Reminder untuk record basi tidak relevan lagi (batas sudah lewat) — hanya kirim
            // bila auto-checkout memang masih di depan.
            if ($nowInstant->gte($reminderTime)) {
                $this->sendCheckoutReminder($attendance, $autoCheckoutTime, $graceMins, $tz);
                $totalReminder++;
            }
        }

        $this->info("Selesai: {$totalReminder} reminder dikirim, {$totalAutoCheckout} auto-checkout dilakukan.");
        Log::info("AutoCheckout: {$totalReminder} reminder, {$totalAutoCheckout} checkout.", ['date' => $today]);

        return self::SUCCESS;
    }

    // ─── Kirim push notification reminder checkout ────────────────────────────
    //     $tz = zona cabang record ini. Jam & labelnya WAJIB sepasang: menulis jam
    //     WITA dengan label "WIB" lebih menyesatkan daripada tidak ada label.
    private function sendCheckoutReminder(Attendance $attendance, Carbon $autoCheckoutTime, int $graceMins, ?string $tz = null): void
    {
        $user    = $attendance->user;
        $tz      = Timezone::normalize($tz ?? $attendance->snap_timezone);
        $waktu   = $autoCheckoutTime->copy()->setTimezone($tz)->format('H:i');
        $zona    = Timezone::label($tz);

        // Cegah kirim reminder lebih dari sekali (cek di cache/DB)
        $cacheKey = "checkout_reminder_{$attendance->id}";
        if (cache()->has($cacheKey)) {
            return;
        }
        // Tandai sudah dikirim selama 25 menit (lebih pendek dari interval cek)
        cache()->put($cacheKey, true, now()->addMinutes(25));

        $title = '⏰ Jangan Lupa Checkout!';
        $body  = "Kamu belum checkout. Sistem akan otomatis checkout pukul {$waktu} {$zona} jika tidak segera checkout.";

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
    // $tz       = zona bisnis cabang record ini (dari handle()). Null → diturunkan
    //             ulang di sini agar method tetap bisa dipanggil mandiri.
    private function doAutoCheckout(Attendance $attendance, AttendanceSetting $office, string $attDate, Carbon $checkOutTime, bool $skipOvertime = false, ?array $schedule = null, ?string $tz = null): void
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

        $tz = Timezone::normalize(
            $tz ?? $schedule['timezone'] ?? $attendance->snap_timezone ?? $office->timezone
        );

        // ATURAN DUPLIKASI SENGAJA (2026-10-06, direfaktor 2026-10-12) — JANGAN ganti
        // dengan App\Services\Attendance\AttendanceRecalculator tanpa PR khusus.
        //
        // checkOut() manual & koreksi manual HRD memakai AttendanceRecalculator.
        // Command ini memakai AutoCheckoutCalculator (dulu salinan privat di berkas
        // ini sendiri) karena semantiknya beda:
        //   1. calculateOvertime() di sini membaca $office->overtime_enabled,
        //      sedangkan service membaca $user->overtime_enabled lebih dulu.
        //   2. $schedule di sini boleh NULL (karyawan terhapus) — service butuh array.
        //   3. Command ini TIDAK PERNAH memasang status 'early_leave' atas inisiatif
        //      sendiri; sistem tidak boleh menuduh karyawan pulang awal saat justru
        //      lupa checkout. SATU-SATUNYA pengecualian (2026-10-11): kantor yang
        //      secara eksplisit memilih missed_checkout_penalty_mode =
        //      'mark_early_leave' — di situ HRD sendiri yang memutuskan bahwa lupa
        //      check-out diperlakukan sebagai pulang cepat.
        // Mengalihkannya ke AttendanceRecalculator = perubahan perilaku (nilai
        // lembur/gaji), bukan refactor. Bila rumus di AutoCheckoutCalculator diubah,
        // cermin perubahannya di AttendanceRecalculator::recalculate() agar tidak drift.

        // Hitung jam kerja dari jam JADWAL masuk (bukan jam check-in).
        // Konsisten dengan manual checkOut() di AttendanceController.
        $workStart   = $this->calc->resolveWorkStart($attendance->check_in_time, $schedule, $attDate, $attendance->user, $attendance, $tz);
        $workMinutes = max(0, (int) $workStart->diffInMinutes($checkOutTime->copy()->setTimezone($tz)));

        // Potong jam istirahat jika bekerja >= 5 jam (300 menit) sesuai UU 13/2003
        $breakMinutes = (int) ($schedule['break_minutes'] ?? 60);
        if ($workMinutes >= 300 && $breakMinutes > 0) {
            $workMinutes = max(240, $workMinutes - $breakMinutes);
        }

        // Tentukan hari libur/weekend menurut kalender (libur nasional/weekend).
        // Dipakai untuk field is_holiday — samakan persis dengan checkOut() manual.
        // Pass user_id agar cuti bersama yang di-decline tidak dianggap hari libur.
        $isNationalNonWorking = $this->calc->isNonWorkingDay($attDate, $attendance->company_id, $attendance->user_id);

        // Hitung overtime (sadar shift) — angka lembur konsisten dengan checkOut manual.
        // $skipOvertime: checkout patologis (ditutup tepat di jam check-in) → tanpa lembur.
        $overtimeMinutes = $skipOvertime
            ? 0
            : $this->calc->calculateOvertime($office, $schedule, $attDate, $checkOutTime, $workMinutes, $isNationalNonWorking, $attendance->user, $attendance, $tz);

        // Denda lupa check-out (2026-10-11) — konfigurasi dari snapshot saat check-in.
        // Baris tanpa snapshot (sebelum fitur) → mode 'none', tanpa denda.
        $missed = MissedCheckoutFineCalculator::calculate($attendance->missedCheckoutFineConfig());

        $updateData = [
            'check_out_time'   => $checkOutTime,
            'check_out_lat'    => $attendance->check_in_lat,    // pakai lokasi check-in karena tidak ada GPS
            'check_out_lng'    => $attendance->check_in_lng,
            'check_out_type'   => $attendance->check_in_type,
            'work_minutes'     => $workMinutes,
            'overtime_minutes' => $overtimeMinutes,
            'is_holiday'       => $isNationalNonWorking,
            'auto_checkout_at' => $checkOutTime,
            'is_auto_checkout' => true,
            'missed_checkout_fine_amount' => $missed['fine_amount'] > 0 ? $missed['fine_amount'] : null,
        ];

        // Mode 'mark_early_leave': tandai pulang cepat TANPA denda rupiah, dan hanya
        // di hari kerja efektif — sama seperti checkOut() manual, hari libur/shift off
        // tidak mengenal konsep pulang cepat. Status 'absent' (ditetapkan HRD) tidak
        // ditimpa; status yang lebih berat tidak boleh diringankan oleh perintah ini.
        if (
            $missed['mark_early_leave']
            && ! $isNationalNonWorking
            && empty($schedule['is_off'])
            && $attendance->status !== 'absent'
        ) {
            $updateData['status'] = 'early_leave';
        }

        $attendance->update($updateData);

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
        // Jam & label zona WAJIB sepasang — karyawan cabang Jayapura harus melihat
        // jam WIT-nya sendiri, bukan jam WIB yang tidak cocok dengan jam di ponselnya.
        $jamOut  = $checkOutTime->copy()->setTimezone($tz)->format('H:i');
        $zona    = Timezone::label($tz);
        // Format "Xj Ym" dipakai dua kali di bawah (notifikasi karyawan & baris log);
        // formatnya hidup di App\\Support\\Duration agar sama dengan payload API presensi.
        $fmtLembur = Duration::formatMinutes($overtimeMinutes);
        $msgUser = $overtimeMinutes > 0
            ? "Anda telah di-checkout otomatis pukul {$jamOut} {$zona}. Lembur {$fmtLembur} terdeteksi — silakan ajukan via aplikasi jika ingin diklaim."
            : "Anda telah di-checkout otomatis pukul {$jamOut} {$zona}.";
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

        $this->line("  Auto-Checkout → {$user?->name} (attendance #{$attendance->id}) | lembur: {$fmtLembur}");
    }
}
