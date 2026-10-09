<?php

namespace App\Services\Attendance;

use App\Models\Attendance;
use App\Models\User;
use App\Support\Timezone;
use Illuminate\Support\Carbon;

/**
 * SUMBER TUNGGAL PERHITUNGAN TURUNAN PRESENSI (2026-10-06)
 *
 * Sebelum service ini, blok "hitung jam kerja → potong istirahat → lembur →
 * pulang awal" ditulis dua kali: AttendanceController::checkOut() dan
 * AutoCheckoutCommand. Dengan masuknya fitur koreksi presensi manual HRD,
 * salinan ketiga sudah pasti akan drift — jadi logikanya dipusatkan di sini.
 *
 * ATURAN YANG TIDAK BOLEH DIUBAH:
 * 1. Perhitungan WAJIB memakai jadwal hasil Attendance::snapshotSchedule()
 *    bila ada, bukan attendance_settings live. Tanpa ini, mengoreksi presensi
 *    bulan Januari akan dihitung dengan aturan kantor bulan Oktober.
 * 2. URUTAN recalculate() wajib dipertahankan: work_minutes → potong istirahat
 *    → is_holiday → lembur → early_leave. early_leave menimpa status di akhir;
 *    kalau hanya determineStatus() yang dijalankan, early_leave yang sah hilang.
 * 3. SELURUH perbandingan jam WAJIB memakai $schedule['timezone'] (zona kantor
 *    cabang), bukan konstanta. Tanpa ini cabang Makassar/Jayapura dinilai
 *    terhadap jam dinding WIB dan telat/lembur/early-leave bergeser 1-2 jam.
 *    $schedule tanpa key 'timezone' (baris presensi lama) jatuh ke zona
 *    penyimpanan sehingga hasilnya identik dengan sebelum fitur multi-zona.
 */
class AttendanceRecalculator
{
    /**
     * Hitung ulang seluruh field turunan satu sesi presensi.
     *
     * Parameter $nonWorking dipasok caller (hasil
     * AttendanceController::isNonWorkingDay()) agar service ini tetap bebas
     * dependensi kalender libur / cuti bersama.
     *
     * @param  string  $scheduleDate  Tanggal JADWAL (bisa = kemarin untuk shift lintas hari)
     * @param  array   $schedule      Hasil snapshotSchedule() atau resolveSchedule()
     * @return array{work_minutes:int,overtime_minutes:int,is_holiday:bool,is_early_leave:bool,work_start:Carbon}
     */
    public function recalculate(
        User $user,
        Attendance $attendance,
        string $scheduleDate,
        Carbon $checkOutTime,
        bool $nonWorking,
        array $schedule
    ): array {
        $tz = $this->timezoneOf($schedule);

        $checkOutLocal = $checkOutTime->copy()->setTimezone($tz);

        // 1. Titik awal jam kerja (jadwal vs check-in aktual; flexitime aware)
        $workStart = $this->resolveWorkStart(
            $attendance->check_in_time,
            $schedule,
            $scheduleDate,
            $user,
            $attendance
        );

        $workMinutes = max(0, (int) $workStart->diffInMinutes($checkOutLocal));

        // 2. Potong jam istirahat jika bekerja >= 5 jam (300 menit) sesuai UU 13/2003
        $breakMinutes = (int) ($schedule['break_minutes'] ?? 60);
        if ($workMinutes >= 300 && $breakMinutes > 0) {
            $workMinutes = max(240, $workMinutes - $breakMinutes);
        }

        // 3. Lembur & pulang awal (cross-day aware, memakai $schedule yang sama)
        $overtimeMinutes = $this->calculateOvertime(
            $user, $scheduleDate, $checkOutTime, $workMinutes, $nonWorking, $schedule, $attendance
        );
        $isEarlyLeave = $this->checkEarlyLeave(
            $user, $scheduleDate, $checkOutTime, $nonWorking, $schedule, $workMinutes, $attendance
        );

        return [
            'work_minutes'     => $workMinutes,
            'overtime_minutes' => $overtimeMinutes,
            'is_holiday'       => $nonWorking,
            'is_early_leave'   => $isEarlyLeave,
            'work_start'       => $workStart,
        ];
    }

    // ─── Helper: tentukan titik awal perhitungan jam kerja ────────────
    //     Jika karyawan check-in lebih awal dari jadwal → titik awal = jadwal.
    //     Jika karyawan check-in terlambat → titik awal = jam check-in aktual.
    //     Khusus flexitime: titik awal = jam check-in aktual (dibatasi paling awal
    //     jam jendela kedatangan).
    //
    //     Juga mempertimbangkan shift lintas hari (cross-day): jam mulai shift
    //     malam bisa berada di hari sebelumnya.
    public function resolveWorkStart(Carbon $checkInTime, array $schedule, string $date, ?User $user = null, ?Attendance $attendance = null): Carbon
    {
        $tz = $this->timezoneOf($schedule);

        $checkInLocal = $checkInTime->copy()->setTimezone($tz);

        $isFlex = $attendance ? $attendance->isFlexitimeSession($user) : (bool) ($user && $user->flexitime_enabled);
        if ($isFlex) {
            $office = $schedule['office'] ?? $user?->office;
            $arrivalStartStr = ! empty($office?->flex_arrival_start) ? substr((string) $office->flex_arrival_start, 0, 5) : '07:00';
            $arrivalStart = Carbon::parse($date . ' ' . $arrivalStartStr, $tz);

            return $checkInLocal->lessThan($arrivalStart) ? $arrivalStart : $checkInLocal;
        }

        $workStartStr = $schedule['work_start_time'];

        // Tidak ada jadwal → pakai waktu check-in aktual sebagai titik awal
        if (! $workStartStr) {
            return $checkInTime->copy();
        }

        // Shift lintas hari (cross-day, mis. 22:00 malam): jam mulai ada di hari $date,
        // bukan hari berikutnya. Gunakan $date langsung.
        $workStart = Carbon::parse($date . ' ' . $workStartStr, $tz);

        // Titik awal = jadwal ATAU check-in aktual — mana yang lebih lambat
        // (karyawan terlambat → hitung dari check-in; datang awal → hitung dari jadwal)
        return $checkInLocal->greaterThan($workStart) ? $checkInLocal : $workStart;
    }

    /**
     * Zona bisnis yang dipakai seluruh perbandingan jam di service ini.
     *
     * $schedule selalu membawa key 'timezone' sejak resolveSchedule() dan
     * snapshotSchedule() menyertakannya. Fallback ke zona penyimpanan menjaga
     * jalur lama (baris presensi tanpa snapshot) tetap berperilaku identik.
     */
    private function timezoneOf(?array $schedule): string
    {
        return Timezone::normalize($schedule['timezone'] ?? null);
    }

    // ─── Helper: hitung menit lembur ──────────────────────────────────
    //     - Hari libur per jadwal shift (is_off) → seluruh jam kerja = lembur.
    //     - Tanpa shift & hari libur nasional/weekend → seluruh jam kerja = lembur.
    //     - Flexitime → selisih jam kerja di atas target harian.
    //     - Hari kerja normal → menit setelah jam pulang (cross-day aware).
    //     Semua kasus tetap tunduk pada min_overtime_minutes kantor.
    //
    //     $schedule (opsional): jadwal yang sudah di-resolve caller — bila diisi,
    //     helper TIDAK me-resolve ulang (dipakai jalur snapshot checkout).
    public function calculateOvertime(User $user, string $date, Carbon $checkOutTime, int $workMinutes, bool $isNationalNonWorking, ?array $schedule = null, ?Attendance $attendance = null): int
    {
        $schedule = $schedule ?? \App\Http\Controllers\API\ShiftController::resolveSchedule($user, $date);
        $office   = $schedule['office'];
        $tz       = $this->timezoneOf($schedule);

        // Cek pengaturan karyawan (utama).
        // Jika karyawan OFF, tidak bisa lembur.
        if (isset($user->overtime_enabled) && ! $user->overtime_enabled) {
            return 0;
        }

        // Karyawan di-set ON, maka abaikan $office->overtime_enabled.
        // Tapi tetap butuh data office untuk batas minimum lembur.
        if (! $office) {
            return 0;
        }

        $minOvertime = (int) $office->min_overtime_minutes;

        // Kasus 1: jadwal shift menandai hari ini libur (is_off=true)
        //          → seluruh menit kerja dianggap lembur (bekerja di luar jadwal),
        //            asal mencapai min_overtime_minutes.
        if ($schedule['is_off']) {
            $full = max(0, $workMinutes);

            return $full >= $minOvertime ? $full : 0;
        }

        // Kasus 2: tidak ada shift, dan hari ini libur nasional/weekend
        //          → seluruh menit kerja dianggap lembur, dengan minimum yang sama
        if ($schedule['source'] === 'office' && $isNationalNonWorking) {
            $full = max(0, $workMinutes);

            return $full >= $minOvertime ? $full : 0;
        }

        // Kasus 2.5: Karyawan dengan jam kerja fleksibel (flexitime)
        //            Lembur dihitung dari akumulasi jam kerja bersih yang melebihi target harian
        $isFlex = $attendance ? $attendance->isFlexitimeSession($user) : (bool) $user->flexitime_enabled;
        if ($isFlex) {
            $targetMins = (int) ($office->flex_target_minutes ?? 480);
            $lewat = max(0, $workMinutes - $targetMins);

            return $lewat >= $minOvertime ? $lewat : 0;
        }

        // Kasus 3: hari kerja efektif (dari shift atau default kantor)
        //          → hitung lembur setelah jam pulang yang berlaku
        $jamPulangStr = $schedule['work_end_time'];
        if (! $jamPulangStr) {
            return 0;
        }

        // Shift lintas hari (cross-day, mis. 22:00-06:00): jam pulang berada di HARI BERIKUTNYA.
        $jamPulangDate = ! empty($schedule['is_cross_day'])
            ? Carbon::parse($date, $tz)->addDay()->toDateString()
            : $date;

        $jamPulang     = Carbon::parse($jamPulangDate . ' ' . $jamPulangStr, $tz);
        $checkOutLocal = $checkOutTime->copy()->setTimezone($tz);

        $lewat = $checkOutLocal->greaterThan($jamPulang)
            ? (int) $jamPulang->diffInMinutes($checkOutLocal)
            : 0;

        return $lewat >= (int) $office->min_overtime_minutes ? $lewat : 0;
    }

    // ─── Helper: deteksi pulang lebih awal (early leave) ──────────────
    //     Mempertimbangkan shift aktif: pakai jam pulang shift jika ada.
    //     Tidak berlaku di hari libur (per jadwal shift atau kalender).
    //     $schedule (opsional): jadwal dari caller (jalur snapshot checkout).
    //     Mendukung jam kerja fleksibel (flexitime) dengan validasi jam inti & durasi target.
    public function checkEarlyLeave(User $user, string $date, Carbon $checkOutTime, bool $isNationalNonWorking, ?array $schedule = null, ?int $workMinutes = null, ?Attendance $attendance = null): bool
    {
        $schedule = $schedule ?? \App\Http\Controllers\API\ShiftController::resolveSchedule($user, $date);
        $office   = $schedule['office'] ?? $user->office;
        $tz       = $this->timezoneOf($schedule);

        // Hari libur per jadwal shift → tidak ada konsep pulang awal
        if (! empty($schedule['is_off'])) {
            return false;
        }

        // Hari libur nasional/weekend tanpa shift → tidak ada konsep pulang awal
        if (($schedule['source'] ?? 'office') === 'office' && $isNationalNonWorking) {
            return false;
        }

        // Karyawan dengan jam kerja fleksibel (flexitime):
        $isFlex = $attendance ? $attendance->isFlexitimeSession($user) : (bool) $user->flexitime_enabled;
        if ($isFlex) {
            // Syarat 1: Karyawan flexitime wajib standby sampai jam inti berakhir (flex_core_end, default 15:00)
            $coreEndStr    = ! empty($office?->flex_core_end) ? substr((string) $office->flex_core_end, 0, 5) : '15:00';
            $coreEnd       = Carbon::parse($date . ' ' . $coreEndStr, $tz);
            $checkOutLocal = $checkOutTime->copy()->setTimezone($tz);

            if ($checkOutLocal->lessThan($coreEnd)) {
                return true; // Pulang mendahului batas jam inti
            }

            // Syarat 2: Karyawan flexitime wajib memenuhi target durasi kerja harian (default 480 menit = 8 jam)
            $targetMinutes = (int) ($office?->flex_target_minutes ?? 480);
            $toleransi     = (int) ($office?->early_leave_tolerance_minutes ?? 0);
            $minRequired   = max(0, $targetMinutes - $toleransi);

            if ($workMinutes !== null && $workMinutes < $minRequired) {
                return true; // Durasi kerja belum memenuhi target harian
            }

            return false;
        }

        // Tanpa setting kantor atau fitur dimatikan (NULL) → tidak dianggap early leave
        if (! $office || is_null($office->early_leave_tolerance_minutes)) {
            return false;
        }

        $jamPulangStr = $schedule['work_end_time'];
        if (! $jamPulangStr) {
            return false;
        }

        // Shift lintas hari (cross-day): jam pulang berada di HARI BERIKUTNYA.
        $jamPulangDate = ! empty($schedule['is_cross_day'])
            ? Carbon::parse($date, $tz)->addDay()->toDateString()
            : $date;

        $toleransi     = (int) $office->early_leave_tolerance_minutes;
        $jamPulang     = Carbon::parse($jamPulangDate . ' ' . $jamPulangStr, $tz);
        $batasAwal     = $jamPulang->copy()->subMinutes($toleransi);
        $checkOutLocal = $checkOutTime->copy()->setTimezone($tz);

        return $checkOutLocal->lessThan($batasAwal);
    }
}
