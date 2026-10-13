<?php

namespace App\Services\Attendance;

use App\Http\Controllers\API\ShiftController;
use App\Models\Attendance;
use App\Models\AttendanceSetting;
use App\Models\User;
use App\Support\Timezone;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * SEMANTIK PERHITUNGAN KHUSUS AUTO-CHECKOUT (2026-10-12).
 *
 * Sebelum service ini, AutoCheckoutCommand menyimpan salinan PRIVAT dari helper
 * perhitungan presensi (resolveWorkStart, calculateOvertime, isNonWorkingDay,
 * formatMinutes). Akibatnya rumus jam kerja & lembur hidup di tiga tempat:
 * AttendanceRecalculator, wrapper tipis di AttendanceController, dan salinan AKTIF
 * di command. Salinan di command itulah yang menutup presensi karyawan tiap malam,
 * jadi ia bukan kode mati -- ia jalur produksi yang tidak punya nama.
 *
 * ---------------------------------------------------------------------------
 * MENGAPA TIDAK DIGABUNG KE AttendanceRecalculator
 * ---------------------------------------------------------------------------
 * Perbedaannya SENGAJA (aturan 2026-10-06 di doAutoCheckout()), bukan sisa
 * copy-paste. Mengalihkan command ke AttendanceRecalculator = mengubah nilai
 * lembur, karenanya gaji -- itu perubahan perilaku, bukan refactor. Empat
 * perbedaan nyata yang sudah diverifikasi:
 *
 *   1. GERBANG LEMBUR. Di sini: $office->overtime_enabled (kolom
 *      attendance_settings). Di Recalculator: $user->overtime_enabled lebih dulu
 *      (kolom users, migrasi 2026_09_12_190300) -- dua kolom BERBEDA.
 *   2. $schedule BOLEH NULL di sini (karyawan terhapus / tanpa kantor).
 *      Recalculator mewajibkan array.
 *   3. TIDAK PERNAH menilai early_leave. Sistem tidak boleh menuduh karyawan
 *      pulang cepat saat ia justru LUPA check-out. Pengecualian tunggal
 *      diputuskan di command lewat MissedCheckoutFineCalculator (mode
 *      'mark_early_leave'), yaitu ketika HRD sendiri memilih perlakuan itu.
 *   4. resolveWorkStart() pada jadwal tanpa work_start_time mengembalikan Carbon
 *      BERZONA CABANG; Recalculator mengembalikan salinan zona asal check-in.
 *      Instan absolutnya sama (work_minutes identik), tapi sebagai batas jam
 *      dinding keduanya berbeda.
 *
 * Bila blok hitung di bawah diubah, cermin perubahannya di
 * AttendanceRecalculator::recalculate() agar keduanya tidak menyimpang lebih jauh.
 */
class AutoCheckoutCalculator
{
    /**
     * Titik awal perhitungan jam kerja.
     *
     * Jam kerja dihitung mulai dari jam JADWAL masuk, bukan jam check-in:
     * check-in sebelum jadwal -> titik awal = jadwal (tidak ada bonus jam);
     * check-in terlambat      -> titik awal = jam check-in aktual.
     * Khusus flexitime: titik awal = check-in aktual, dibatasi paling awal pada
     * jam jendela kedatangan kantor (flex_arrival_start).
     *
     * @param  ?array  $schedule  Hasil ShiftController::resolveSchedule() atau snapshot check-in.
     * @param  ?string $tz        Zona bisnis cabang record ini. NULL -> diturunkan dari
     *                            $schedule/kantor agar method tetap aman dipanggil mandiri.
     */
    public function resolveWorkStart(
        Carbon $checkInTime,
        ?array $schedule,
        string $date,
        ?User $user = null,
        ?Attendance $attendance = null,
        ?string $tz = null
    ): Carbon {
        $tz = Timezone::normalize(
            $tz ?? $schedule['timezone'] ?? ($schedule['office'] ?? $user?->office)?->timezone
        );

        // Carbon yang dikembalikan bernuansa zona cabang: pemanggil memakainya untuk
        // diff menit kerja (instan absolut, aman) DAN sebagai batas jam dinding
        // ($arrivalStart/$workStart) yang WAJIB dibangun di zona cabang.
        $checkInLocal = $checkInTime->copy()->setTimezone($tz);

        $isFlex = $attendance ? $attendance->isFlexitimeSession($user) : (bool) ($user && $user->flexitime_enabled);
        if ($isFlex) {
            $office = $schedule['office'] ?? $user?->office;
            $arrivalStartStr = ! empty($office?->flex_arrival_start) ? substr((string) $office->flex_arrival_start, 0, 5) : '07:00';
            $arrivalStart = Carbon::parse($date . ' ' . $arrivalStartStr, $tz);

            return $checkInLocal->lessThan($arrivalStart) ? $arrivalStart : $checkInLocal;
        }

        $workStartStr = $schedule['work_start_time'] ?? null;

        if (! $workStartStr) {
            return $checkInLocal;
        }

        $workStart = Carbon::parse($date . ' ' . $workStartStr, $tz);

        return $checkInLocal->greaterThan($workStart) ? $checkInLocal : $workStart;
    }

    /**
     * Menit lembur (sadar shift).
     *
     *  - Shift menandai hari ini libur (is_off) -> seluruh menit kerja jadi lembur.
     *  - Tanpa shift & hari libur nasional/weekend -> seluruh menit kerja jadi lembur.
     *  - Flexitime -> selisih menit kerja di atas target harian kantor.
     *  - Hari kerja efektif -> lembur dihitung setelah jam pulang yang berlaku.
     *
     * Semua kasus tunduk pada min_overtime_minutes kantor (anti-noise: masuk
     * sebentar di hari libur tidak otomatis menjadi pengajuan lembur).
     *
     * Gerbangnya $office->overtime_enabled, BUKAN $user->overtime_enabled --
     * lihat perbedaan #1 di komentar class sebelum menyeragamkannya.
     *
     * @param  ?array  $schedule  NULL -> fallback jam kantor.
     */
    public function calculateOvertime(
        AttendanceSetting $office,
        ?array $schedule,
        string $date,
        Carbon $checkOutTime,
        int $workMinutes,
        bool $isNationalNonWorking,
        ?User $user = null,
        ?Attendance $attendance = null,
        ?string $tz = null
    ): int {
        $tz = Timezone::normalize($tz ?? $schedule['timezone'] ?? $office->timezone);

        if (! $office->overtime_enabled) {
            return 0;
        }

        $minOvertime = (int) ($office->min_overtime_minutes ?? 30);

        // Kasus 1: jadwal shift menandai hari ini libur -> seluruh menit kerja jadi lembur,
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

        // Kasus 2.5: Karyawan dengan jam kerja fleksibel (flexitime)
        $isFlex = $attendance ? $attendance->isFlexitimeSession($user) : (bool) ($user && $user->flexitime_enabled);
        if ($isFlex) {
            $targetMins = (int) ($office->flex_target_minutes ?? 480);
            $lewat = max(0, $workMinutes - $targetMins);

            return $lewat >= $minOvertime ? $lewat : 0;
        }

        // Kasus 3: hari kerja efektif -> lembur setelah jam pulang yang berlaku
        $jamPulangStr = ($schedule && $schedule['work_end_time'])
            ? $schedule['work_end_time']
            : $office->work_end_time;

        if (! $jamPulangStr) {
            return 0;
        }

        // Shift lintas hari (cross-day): jam pulang berada di HARI BERIKUTNYA.
        $jamPulangDate = ($schedule && ! empty($schedule['is_cross_day']) && ! $schedule['is_off'])
            ? Carbon::parse($date, $tz)->addDay()->toDateString()
            : $date;

        // "17:00" di sini berarti 17:00 JAM CABANG. greaterThan()/diffInMinutes()
        // membandingkan instan absolut, jadi zona $checkOutTime tidak perlu diubah --
        // yang menentukan hasil hanyalah zona pembangunan $jamPulang ini.
        $jamPulang = Carbon::parse($jamPulangDate . ' ' . $jamPulangStr, $tz);
        $lewat     = $checkOutTime->greaterThan($jamPulang)
            ? (int) $jamPulang->diffInMinutes($checkOutTime)
            : 0;

        return $lewat >= (int) $office->min_overtime_minutes ? $lewat : 0;
    }

    /**
     * Apakah tanggal ini hari libur / weekend bagi karyawan tersebut.
     *
     * Cuti bersama (is_collective=true) hanya dianggap libur bagi karyawan yang
     * ACCEPTED; yang declined tetap masuk kerja hari itu seperti biasa.
     *
     * SHIFT-AWARE: weekend global tidak otomatis libur bila jadwal shift efektif
     * karyawan menandai hari itu MASUK (resolveSchedule is_off=false) -- dan
     * sebaliknya, hari kerja kantor yang libur menurut shift tetap dianggap libur.
     *
     * PERINGATAN -- TIDAK SAMA dengan AttendanceController::isNonWorkingDay()
     * (diverifikasi 2026-10-12, perbedaan ini BELUM diputuskan untuk diseragamkan
     * karena mengubah nilai is_holiday & lembur baris presensi nyata):
     *
     *   a. Versi controller menerima $officeId dan menyaring libur per cabang
     *      (attendance_setting_id). Versi ini TIDAK -- libur perusahaan berlaku
     *      ke semua cabang, jadi libur khusus satu cabang ikut terpakai di cabang lain.
     *   b. Versi controller melewati libur yang karyawannya ada di
     *      Holiday::excludedUsers. Versi ini TIDAK punya pengecualian itu, jadi
     *      karyawan yang dikecualikan dari suatu libur tetap dihitung libur di sini.
     *   c. Versi controller memakai resolveOffDatesForUser() (batch, ter-cache);
     *      versi ini memanggil ShiftController::resolveSchedule() langsung.
     *
     * Menyeragamkannya butuh keputusan HRD, bukan refactor -- jangan diubah diam-diam.
     */
    public function isNonWorkingDay(string $date, ?int $companyId, ?int $userId = null): bool
    {
        // Cek jadwal shift efektif dulu (sudah dipanggil caller dengan tanggal yang sama)
        if ($userId) {
            $userModel = User::find($userId);
            if ($userModel) {
                $schedule = ShiftController::resolveSchedule($userModel, $date);
                if ($schedule['source'] !== 'none') {
                    return (bool) $schedule['is_off'];
                }
                // Tanpa shift & tanpa kantor -> jatuh ke cek kalender di bawah
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
                // Declined / pending -> bukan libur untuk karyawan ini
                continue;
            }

            // Libur nasional atau libur perusahaan biasa -> libur untuk semua
            return true;
        }

        return false;
    }
}
