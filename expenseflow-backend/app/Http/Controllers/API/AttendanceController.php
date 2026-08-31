<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessAttendanceBackgroundJob;
use App\Models\Attendance;
use App\Models\AttendanceSetting;
use App\Models\DeviceChangeRequest;
use App\Models\Holiday;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\OvertimeApproval;
use App\Models\ShiftSchedule;
use App\Models\User;
use App\Models\UserShift;
use App\Services\FcmService;
use App\Services\LocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttendanceController extends Controller
{
    // ─── Helper: catat aktivitas ──────────────────────────────
    private function logActivity(int $userId, ?int $companyId, string $action, string $description, ?string $entityType = null, ?int $entityId = null): void
    {
        DB::table('activity_logs')->insert([
            'company_id'  => $companyId,
            'user_id'     => $userId,
            'action'      => $action,
            'description' => $description,
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    // ─── Helper: kirim notifikasi ke user (DB + FCM) ─────────
    private function notifyUser(int $userId, string $type, array $data, ?string $entityType = null, ?int $entityId = null): void
    {
        DB::table('notifications')->insert([
            'id'              => Str::uuid()->toString(),
            'type'            => $type,
            'notifiable_type' => 'App\\Models\\User',
            'notifiable_id'   => $userId,
            'user_id'         => $userId,
            'data'            => json_encode($data),
            'entity_type'     => $entityType,
            'entity_id'       => $entityId,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        // Push FCM bila user memiliki token perangkat
        try {
            $user = User::find($userId);
            if ($user && $user->fcm_token) {
                $title = $this->resolveNotificationTitle($type, $data);
                $body  = $data['message'] ?? 'Ada pemberitahuan baru.';
                app(FcmService::class)->send($user->fcm_token, $title, $body, [
                    'type'        => $type,
                    'entity_type' => (string) ($entityType ?? ''),
                    'entity_id'   => (string) ($entityId ?? ''),
                    'holiday_id'  => (string) ($data['holiday_id'] ?? ''),
                    'date'        => (string) ($data['date'] ?? ''),
                ]);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning("Gagal kirim FCM notif ke user #{$userId}: {$e->getMessage()}");
        }
    }

    private function resolveNotificationTitle(string $type, array $data): string
    {
        if (! empty($data['title'])) {
            return $data['title'];
        }

        return match ($type) {
            'collective_leave_announced' => '🏖️ Pengumuman Cuti Bersama',
            'collective_leave_cancelled' => '❌ Cuti Bersama Dibatalkan',
            'overtime_pending'           => '⏰ Pengajuan Lembur Masuk',
            'overtime_approved'          => '✅ Lembur Disetujui',
            'overtime_rejected'          => '❌ Lembur Ditolak',
            'leave_approved'             => '✅ Pengajuan Cuti Disetujui',
            'leave_rejected'             => '❌ Pengajuan Cuti Ditolak',
            'device_change_approved'     => '📱 Pindah Perangkat Disetujui',
            'device_change_rejected'     => '❌ Pindah Perangkat Ditolak',
            default                      => '🔔 Pemberitahuan',
        };
    }

    // ─── Helper: tanggal hari ini dalam zona waktu WIB (Asia/Jakarta) ────
    //     Presensi mengacu waktu lokal karyawan, bukan UTC.
    //     Tanpa ini, antara 00:00–06:59 WIB (= 17:00–23:59 UTC hari sebelumnya)
    //     `now()->toDateString()` mengembalikan tanggal kemarin sehingga sistem
    //     menemukan record check-in kemarin dan menolak check-in hari ini.
    private function todayDate(): string
    {
        return now('Asia/Jakarta')->toDateString();
    }

    // ─── Helper: ambil jadwal kerja efektif karyawan pada tanggal tertentu ────
    //     Mempertimbangkan shift khusus jika ada; fallback ke attendance_settings kantor.
    //     Delegasi ke ShiftController::resolveSchedule() agar logika terpusat.
    private function getWorkSchedule(User $user, string $date): array
    {
        return \App\Http\Controllers\API\ShiftController::resolveSchedule($user, $date);
    }

    // ─── Helper: tentukan titik awal perhitungan jam kerja ──────────────────
    //     Jam kerja dihitung mulai dari jam JADWAL masuk (bukan jam check-in).
    //     Jika karyawan check-in lebih awal dari jadwal → titik awal = jadwal.
    //     Jika karyawan check-in terlambat → titik awal = jam check-in aktual.
    //
    //     Juga mempertimbangkan shift lintas hari (cross-day): jam mulai shift
    //     malam bisa berada di hari sebelumnya.
    private function resolveWorkStart(Carbon $checkInTime, array $schedule, string $date): Carbon
    {
        $workStartStr = $schedule['work_start_time'];

        // Tidak ada jadwal → pakai waktu check-in aktual sebagai titik awal
        if (! $workStartStr) {
            return $checkInTime->copy();
        }

        // Shift lintas hari (cross-day, mis. 22:00 malam): jam mulai ada di hari $date,
        // bukan hari berikutnya. Gunakan $date langsung.
        $workStart = Carbon::parse($date . ' ' . $workStartStr, 'Asia/Jakarta');

        // Titik awal = jadwal ATAU check-in aktual — mana yang lebih lambat
        // (karyawan terlambat → hitung dari check-in; datang awal → hitung dari jadwal)
        $checkInWib = $checkInTime->copy()->setTimezone('Asia/Jakarta');

        return $checkInWib->greaterThan($workStart) ? $checkInWib : $workStart;
    }

    // ─── Helper: tentukan status hadir/telat berdasarkan jam kerja ────
    //     Mempertimbangkan shift aktif karyawan; fallback ke kantor default.
    private function determineStatus(User $user, Carbon $checkInTime, string $date): string
    {
        $schedule = $this->getWorkSchedule($user, $date);

        $workStartTime = $schedule['work_start_time'];
        $office        = $schedule['office'];

        // Tanpa jam masuk sebagai acuan → anggap hadir
        if (! $workStartTime || ! $office) {
            return 'present';
        }

        $tanggalWib = $checkInTime->copy()->setTimezone('Asia/Jakarta')->toDateString();
        $batasTelat = Carbon::parse($tanggalWib . ' ' . $workStartTime, 'Asia/Jakarta')
            ->addMinutes((int) $office->late_tolerance_minutes);

        return $checkInTime->copy()->setTimezone('Asia/Jakarta')->greaterThan($batasTelat) ? 'late' : 'present';
    }

    // Hanya cuti yang punya kuota. Izin & sakit tidak terbatas, hanya dihitung.
    private const DEFAULT_LEAVE_QUOTA = ['cuti' => 12];

    // ANTI N+1 (2026-08-25): cache hasil workingDatesBetween() per instance controller
    // (umur = satu request). Saat generate cuti bersama, countWorkingDays() dipanggil
    // per karyawan dengan rentang & filter identik → query libur/off-map hanya 1x.
    private array $workingDatesCache = [];

    // ─── Helper: kuota cuti default berdasarkan kantor user ────
    //     Kuota diambil dari attendance_settings.default_leave_quota kantor karyawan
    //     (attendance_setting_id); fallback ke kantor pertama perusahaan, lalu ke 12
    //     (nilai lama) bila perusahaan belum punya kantor sama sekali.
    public static function defaultLeaveQuota(?int $companyId, ?int $userId = null): int
    {
        $office = null;
        if ($userId && $companyId) {
            $office = AttendanceSetting::where('company_id', $companyId)
                ->whereIn('id', function ($q) use ($userId) {
                    $q->select('attendance_setting_id')->from('users')->where('id', $userId);
                })
                ->first();
        }
        if (! $office && $companyId) {
            $office = AttendanceSetting::where('company_id', $companyId)->orderBy('id')->first();
        }

        return $office?->default_leave_quota ?? self::DEFAULT_LEAVE_QUOTA['cuti'];
    }

    // ─── Helper: apakah tanggal bukan hari kerja (weekend atau libur) ────
    //     Dipakai untuk perhitungan lembur & total hari cuti.
    //     Libur cocok bila libur nasional (company_id NULL) ATAU milik company yang sama
    //     DAN (attendance_setting_id NULL [semua cabang] ATAU cocok dengan cabang user).
    //
    //     Bug #3 Fix: Cuti bersama (is_collective=true) bukan libur kalender biasa.
    //     Hanya dianggap sebagai hari libur bagi karyawan yang memang ACCEPTED.
    //     Karyawan yang declined tetap masuk kerja → hari itu adalah hari kerja normal.
    //     Jika $userId diisi, cuti bersama yang declined akan dilewati.
    private function isNonWorkingDay(string $date, ?int $companyId, ?int $officeId = null, ?int $userId = null): bool
    {
        // SHIFT-AWARE: weekend global TIDAK otomatis libur. Karyawan ber-shift yang
        // justru masuk Sabtu/Minggu (kasus: shift "shif minggu" budi@majubersama.co.id)
        // tidak boleh tertandai is_holiday=true — lembur hari kerjanya bisa salah hitung
        // jadi "seluruh jam kerja = lembur". Jadwal off mengikuti resolveSchedule():
        // shift efektif per tanggal, fallback work_days kantor.
        // CATATAN: hanya menggantikan cek WEEKEND — libur nasional/perusahaan tetap
        // dicek di blok di bawah.
        $shiftAwareChecked = false;
        if ($userId) {
            $userModel = User::find($userId);
            if ($userModel) {
                $shiftAwareChecked = true;
                if ($this->resolveOffDatesForUser($userModel, [$date])[$date] ?? false) {
                    return true;
                }
            }
        }

        if (! $shiftAwareChecked && Carbon::parse($date)->isWeekend()) {
            return true;
        }

        // Ambil semua holiday yang cocok untuk tanggal & perusahaan/cabang ini
        $matchingHolidays = Holiday::with('excludedUsers:id')
            ->whereDate('date', $date)
            ->where(function ($q) use ($companyId) {
                $q->whereNull('company_id')->orWhere('company_id', $companyId);
            })
            ->where(function ($q) use ($officeId) {
                $q->whereNull('attendance_setting_id');
                if ($officeId) {
                    $q->orWhere('attendance_setting_id', $officeId);
                }
            })
            ->get(['id', 'is_collective']);

        foreach ($matchingHolidays as $holiday) {
            // Cek pengecualian: Jika karyawan ini ada di daftar exclusions, abaikan holiday ini.
            if ($userId && $holiday->excludedUsers->contains('id', $userId)) {
                continue;
            }

            // Untuk cuti bersama: hanya anggap libur jika karyawan memang ACCEPTED.
            // Karyawan yang declined tetap masuk kerja sesuai jadwal normal.
            if ($holiday->is_collective && $userId) {
                $accepted = \App\Models\LeaveRequest::where('holiday_id', $holiday->id)
                    ->where('user_id', $userId)
                    ->where('collective_status', 'accepted')
                    ->exists();
                if ($accepted) {
                    return true;
                }
                // Declined / pending → bukan libur untuk karyawan ini, lanjut cek holiday lain
                continue;
            }

            // Libur nasional atau libur perusahaan biasa (bukan cuti bersama) → libur untuk semua
            return true;
        }

        return false;
    }

    // ─── Helper: hitung jumlah HARI KERJA dalam rentang (inklusif) ────
    //     Lewati weekend & libur. Dipakai saat pengajuan cuti agar kuota adil.
    private function countWorkingDays(Carbon $start, Carbon $end, ?int $companyId, ?int $officeId = null, ?int $userId = null, ?User $userModel = null): int
    {
        return count($this->workingDatesBetween($start, $end, $companyId, $officeId, $userId, $userModel));
    }

    // ─── Helper: DAFTAR tanggal hari kerja dalam rentang (inklusif) ────
    //     Versi array dari countWorkingDays() — dipakai untuk memecah pengajuan cuti
    //     di sekitar tanggal reset kantor (splitLeaveAroundReset) dan hitungan total.
    //     Shift-aware: off-day mengikuti jadwal shift efektif per tanggal; fallback
    //     isWeekend() bila user tak diketahui. Libur reguler & cuti bersama yang
    //     di-accept user juga dilewati. Hasil di-cache per request (anti N+1).
    private function workingDatesBetween(Carbon $start, Carbon $end, ?int $companyId, ?int $officeId = null, ?int $userId = null, ?User $userModel = null): array
    {
        $cacheKey = $start->toDateString() . '|' . $end->toDateString() . '|' . ($companyId ?? 0)
            . '|' . ($officeId ?? 0) . '|' . ($userId ?? 0);
        if (isset($this->workingDatesCache[$cacheKey])) {
            return $this->workingDatesCache[$cacheKey];
        }

        // Ambil daftar libur dalam rentang sekali query (hindari N+1).
        $holidays = Holiday::with('excludedUsers:id')
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->where(function ($q) use ($companyId) {
                $q->whereNull('company_id')->orWhere('company_id', $companyId);
            })
            ->where(function ($q) use ($officeId) {
                $q->whereNull('attendance_setting_id');
                if ($officeId) {
                    $q->orWhere('attendance_setting_id', $officeId);
                }
            })
            ->get(['id', 'date', 'is_collective']);

        // Saring holiday di mana user_id ini DIKECUALIKAN
        $effectiveHolidays = $holidays->reject(function ($holiday) use ($userId) {
            return $userId && $holiday->excludedUsers->contains('id', $userId);
        });

        $regularHolidays = $effectiveHolidays->where('is_collective', false)
            ->pluck('date')
            ->map(fn ($d) => Carbon::parse($d)->toDateString())
            ->flip();

        $acceptedCollectiveLeaves = collect();
        if ($userId && $effectiveHolidays->where('is_collective', true)->isNotEmpty()) {
            $acceptedCollectiveLeaves = \App\Models\LeaveRequest::where('user_id', $userId)
                ->where('collective_status', 'accepted')
                ->whereIn('holiday_id', $effectiveHolidays->where('is_collective', true)->pluck('id'))
                ->join('holidays', 'leave_requests.holiday_id', '=', 'holidays.id')
                ->pluck('holidays.date')
                ->map(fn ($d) => Carbon::parse($d)->toDateString())
                ->flip();
        }

        // Bangun daftar tanggal dalam rentang.
        $dates = [];
        for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
            $dates[] = $day->toDateString();
        }

        // Tentukan hari LIBUR/OFF per tanggal.
        // Jika user diketahui → gunakan resolveOffDatesForUser() yang SHIFT-AWARE
        // (membaca jadwal shift is_off, dengan fallback ke work_days kantor).
        // Ini penting agar karyawan ber-shift yang justru MASUK di Sabtu/Minggu
        // tidak salah dianggap libur (bug: kantor default libur Minggu, tapi shift
        // user menjadwalkan Minggu masuk). Jika user tak diketahui → fallback isWeekend().
        $offMap = [];
        if ($userId) {
            // ANTI N+1: model boleh di-pass dari pemanggil yang sudah prefetch (loop kolektif)
            $userModel ??= User::find($userId);
            if ($userModel) {
                $offMap = $this->resolveOffDatesForUser($userModel, $dates);
            }
        }
        $useShiftAware = $userId && ! empty($offMap);

        $working = [];
        foreach ($dates as $ds) {
            $isOff = $useShiftAware
                ? (bool) ($offMap[$ds] ?? false)
                : Carbon::parse($ds)->isWeekend();
            if ($isOff) {
                continue;
            }
            if ($regularHolidays->has($ds) || $acceptedCollectiveLeaves->has($ds)) {
                continue;
            }
            $working[] = $ds;
        }

        return $this->workingDatesCache[$cacheKey] = $working;
    }

    // ─── Helper: pecah pengajuan cuti di sekitar TANGGAL RESET kantor ────
    //     Reset saldo memakai anniversary per kantor (attendance_settings.leave_reset_date),
    //     BUKAN akhir tahun kalender. Pengajuan yang RENTANGNYA MELINTASI tanggal reset
    //     valid diperiksa dua alokasi:
    //       - hari kerja SEBELUM tanggal reset  → dibatasi sisa saldo berjalan (quota - used)
    //       - hari kerja PADA/SETELAH reset     → dibatasi kuota baru hasil reset
    //         (= default_leave_quota kantor, karena reset menset used = 0)
    //     Contoh: reset 10 Juni, sisa saldo 2 hari, ajukan 8–11 Juni (4 hari kerja):
    //     8–9 butuh 2 ≤ sisa 2 ✓ dan 10–11 butuh 2 ≤ kuota baru ✓ → DIPERBOLEHKAN.
    //
    //     Return: ['anniversary' => string|null, 'fresh_quota' => int,
    //              'days_before' => int, 'days_after' => int]
    //     anniversary NULL berarti tidak ada reset dalam rentang (atau kantor tanpa
    //     jadwal reset) → pemanggil memakai days_before = total hari (perilaku lama).
    //     CATATAN edge: rentang > 1 tahun hanya memakai anniversary PERTAMA (konservatif).
    //
    //     $effectiveDates (2026-08-26): pemanggil BOLEH menyuntikkan daftar tanggal
    //     efektif hasil filter requestLeave (sudah termasuk skip personal leave yang
    //     diajukan). Tanpa ini days_before/days_after bisa ≠ total_days karena
    //     workingDatesBetween() tidak mengetahui skip personal leave. approveLeave()
    //     tetap memanggil tanpa argumen ini (fallback hitung sendiri).
    private function splitLeaveAroundReset(User $user, Carbon $start, Carbon $end, ?int $companyId, ?array $effectiveDates = null): array
    {
        $result = ['anniversary' => null, 'fresh_quota' => 0, 'days_before' => 0, 'days_after' => 0];

        // Kantor karyawan → fallback kantor pertama perusahaan (pola sama dgn myLeaveBalance)
        $office = $user->office
            ?? ($companyId ? AttendanceSetting::where('company_id', $companyId)->orderBy('id')->first() : null);

        if ($effectiveDates !== null) {
            // Daftar efektif disuntik pemanggil — pakai apa adanya (konsisten dgn total_days)
            $workingDates = $effectiveDates;
        } else {
            // Hitung daftar hari kerja sekali (cache membuat pemanggilan ulang murah)
            $workingDates = $this->workingDatesBetween(
                $start, $end, $companyId,
                $office?->id ?? $user->attendance_setting_id,
                $user->id,
                $user
            );
        }

        if (! $office || empty($office->leave_reset_date)) {
            $result['days_before'] = count($workingDates);
            return $result;
        }

        [$mm, $dd] = explode('-', $office->leave_reset_date);

        // Cari anniversary PERTAMA yang jatuh dalam rentang [start, end]
        $pivot = null;
        for ($y = $start->year; $y <= $end->year && ! $pivot; $y++) {
            try {
                $candidate = Carbon::create($y, (int) $mm, (int) $dd, 0, 0, 0, 'Asia/Jakarta');
            } catch (\Throwable) {
                break; // format MM-DD tak valid → perlakukan tanpa pivot
            }
            if ($candidate->between($start->copy()->startOfDay(), $end->copy()->endOfDay())) {
                $pivot = $candidate;
            }
        }

        if (! $pivot) {
            $result['days_before'] = count($workingDates);
            return $result;
        }

        $pivotStr = $pivot->toDateString();
        foreach ($workingDates as $ds) {
            if ($ds < $pivotStr) {
                $result['days_before']++;
            } else {
                $result['days_after']++;
            }
        }
        $result['anniversary'] = $pivotStr;
        $result['fresh_quota'] = (int) $office->default_leave_quota;

        return $result;
    }

    // ─── Helper: format menit → "Xj Ym" ──────────────────────────
    private function formatMinutes(?int $minutes): string
    {
        $m = (int) $minutes;
        if ($m <= 0) {
            return '0j';
        }
        $jam = intdiv($m, 60);
        $sisa = $m % 60;

        return $sisa === 0 ? "{$jam}j" : "{$jam}j {$sisa}m";
    }

    // ═══════════════════════════════════════════════════════════
    // BAGIAN A — HRD / Admin / Super Admin (prefix dashboard)
    // ═══════════════════════════════════════════════════════════

    // 1. toggleAttendance() — aktif/nonaktifkan fitur presensi user
    public function toggleAttendance(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();

        $target = User::when(
            $actor->role !== 'super_admin',
            fn ($q) => $q->where('company_id', $actor->company_id)
        )->find($id);

        if (! $target) {
            return response()->json(['message' => 'User tidak ditemukan di perusahaan Anda.'], 404);
        }

        $target->attendance_enabled = ! $target->attendance_enabled;
        $target->save();

        $this->logActivity(
            $actor->id,
            $target->company_id,
            'attendance_toggled',
            ($target->attendance_enabled ? 'Mengaktifkan' : 'Menonaktifkan') . ' presensi untuk ' . $target->name,
            'user',
            $target->id
        );

        return response()->json([
            'message' => 'Status presensi user berhasil diperbarui.',
            'user'    => [
                'id'                 => $target->id,
                'name'               => $target->name,
                'attendance_enabled' => $target->attendance_enabled,
            ],
        ]);
    }

    // 1b. toggleWfh() — aktif/nonaktifkan mode WFH user (tombol di web HRD)
    //     true  → karyawan presensi dari rumah
    //     false → karyawan presensi dari kantor (cek lokasi)
    public function toggleWfh(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();

        $target = User::when(
            $actor->role !== 'super_admin',
            fn ($q) => $q->where('company_id', $actor->company_id)
        )->find($id);

        if (! $target) {
            return response()->json(['message' => 'User tidak ditemukan di perusahaan Anda.'], 404);
        }

        $newWfhState = ! $target->wfh_enabled;

        // Guard: jika HRD ingin mematikan WFH, cek apakah karyawan sedang
        // aktif check-in (belum checkout) hari ini. Jika ya, tetap simpan
        // perubahan (agar check-in baru besok terblokir) tapi attendance_enabled
        // TIDAK dimatikan supaya karyawan bisa menyelesaikan checkout.
        $today = now('Asia/Jakarta')->toDateString();
        $hasActiveCheckIn = \App\Models\Attendance::where('user_id', $target->id)
            ->whereDate('date', $today)
            ->whereNotNull('check_in_time')
            ->whereNull('check_out_time')
            ->exists();

        $target->wfh_enabled = $newWfhState;

        // attendance_enabled hanya dimatikan jika karyawan TIDAK sedang aktif check-in.
        // Jika sedang aktif, biarkan attendance_enabled tetap true agar checkout bisa dilakukan.
        if ($newWfhState || ! $hasActiveCheckIn) {
            $target->attendance_enabled = $newWfhState;
        }

        $target->save();

        $this->logActivity(
            $actor->id,
            $target->company_id,
            'wfh_toggled',
            ($target->wfh_enabled ? 'Mengaktifkan' : 'Menonaktifkan') . ' mode WFH untuk ' . $target->name,
            'user',
            $target->id
        );

        $warningMsg = ! $newWfhState && $hasActiveCheckIn
            ? ' Catatan: karyawan sedang aktif check-in, mode presensi mobile tetap aktif hingga karyawan checkout.'
            : '';

        return response()->json([
            'message' => ($target->wfh_enabled
                ? 'Mode WFH diaktifkan — karyawan bisa presensi dari rumah lewat aplikasi.'
                : 'Mode WFH dinonaktifkan — presensi mobile dimatikan, presensi kantor lewat perangkat presensi.')
                . $warningMsg,
            'user' => [
                'id'                 => $target->id,
                'name'               => $target->name,
                'wfh_enabled'        => $target->wfh_enabled,
                'attendance_enabled' => $target->attendance_enabled,
            ],
        ]);
    }

    // 1c. toggleRadius() — aktif/nonaktifkan validasi radius untuk karyawan lapangan
    //     true  → presensi mobile wajib berada dalam radius lokasi kerja
    //     false → presensi mobile bebas (WFH dari rumah, tanpa cek lokasi)
    //     Catatan: radius hanya berlaku jika wfh_enabled = true (mobile aktif).
    public function toggleRadius(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();

        $target = User::when(
            $actor->role !== 'super_admin',
            fn ($q) => $q->where('company_id', $actor->company_id)
        )->find($id);

        if (! $target) {
            return response()->json(['message' => 'User tidak ditemukan di perusahaan Anda.'], 404);
        }

        $target->radius_enabled = ! $target->radius_enabled;
        $target->save();

        $this->logActivity(
            $actor->id,
            $target->company_id,
            'radius_toggled',
            ($target->radius_enabled ? 'Mengaktifkan' : 'Menonaktifkan') . ' validasi radius untuk ' . $target->name,
            'user',
            $target->id
        );

        return response()->json([
            'message' => $target->radius_enabled
                ? 'Validasi radius diaktifkan — karyawan harus presensi di sekitar area kerja.'
                : 'Validasi radius dinonaktifkan — karyawan bisa presensi dari mana saja (WFH).',
            'user' => [
                'id'             => $target->id,
                'name'           => $target->name,
                'wfh_enabled'    => $target->wfh_enabled,
                'radius_enabled' => $target->radius_enabled,
            ],
        ]);
    }

    // 2. listUsers() — daftar user + status attendance_enabled
    public function listUsers(Request $request): JsonResponse
    {
        $actor   = $request->user();
        $filter  = $request->query('filter'); // enabled | disabled
        $perPage = $request->query('per_page');

        $query = User::query()
            ->with('office:id,office_name')
            ->when(
                $actor->role !== 'super_admin',
                fn ($q) => $q->where('company_id', $actor->company_id)
            )
            ->select(['id', 'name', 'email', 'role', 'department', 'employee_code', 'attendance_setting_id', 'attendance_enabled', 'wfh_enabled', 'radius_enabled', 'is_active']);

        if ($filter === 'enabled') {
            $query->where('attendance_enabled', true);
        } elseif ($filter === 'disabled') {
            $query->where('attendance_enabled', false);
        }

        if ($perPage === 'all' || $request->boolean('all')) {
            return response()->json(['data' => $query->orderBy('name')->get()]);
        }

        $limit = $perPage ? (int) $perPage : 2000;
        return response()->json($query->orderBy('name')->paginate($limit));
    }

    // listAllUsers() — daftar SEMUA karyawan aktif (tanpa pagination)
    // Dipakai untuk dropdown pengecualian karyawan pada form libur/cuti.
    public function listAllUsers(Request $request): JsonResponse
    {
        $actor = $request->user();
        $year  = (int) now('Asia/Jakarta')->year;

        $users = User::query()
            ->with('office:id,office_name')
            ->when(
                $actor->role !== 'super_admin',
                fn ($q) => $q->where('company_id', $actor->company_id)
            )
            ->where('is_active', true)
            ->select(['id', 'name', 'email', 'role', 'department', 'employee_code', 'attendance_setting_id', 'is_active'])
            ->orderBy('name')
            ->get();

        $leaveBalances = \App\Models\LeaveBalance::where('year', $year)
            ->where('leave_type', 'cuti')
            ->whereIn('user_id', $users->pluck('id'))
            ->get()
            ->keyBy('user_id');

        $users->transform(function ($u) use ($leaveBalances) {
            $lb = $leaveBalances->get($u->id);
            $quota = $lb ? (int) $lb->quota : 0;
            $used  = $lb ? (int) $lb->used : 0;
            $u->leave_active = ($quota > 0);
            $u->leave_quota  = $quota;
            return $u;
        });

        return response()->json(['users' => $users]);
    }

    // 3. approveLeave() — setujui permintaan cuti/izin
    public function approveLeave(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();

        // FIX BUG #6 (race condition): seluruh cek-saldo + update status + potong saldo
        // dibungkus DB::transaction + lockForUpdate agar dua approval hampir bersamaan
        // (HRD ganda) tidak bisa sama-sama lolos cek saldo lalu membuat saldo minus.
        // Baris leave_request & leave_balance dikunci; transaksi lain yang mengunci
        // baris yang sama akan menunggu sampai transaksi ini commit.
        $leave = DB::transaction(function () use ($id, $actor) {
            $leave = LeaveRequest::when(
                $actor->role !== 'super_admin',
                fn ($q) => $q->where('company_id', $actor->company_id)
            )->lockForUpdate()->find($id);

            if (! $leave) {
                abort(404, 'Permintaan tidak ditemukan.');
            }

            if ($leave->status !== 'pending') {
                abort(403, 'Permintaan sudah diproses sebelumnya.');
            }

            // Guard: cuti bersama (holiday_id != null) tidak bisa di-approve oleh HRD secara manual.
            // Karyawan yang memutuskan sendiri via aplikasi mobile (accept/decline).
            if ($leave->holiday_id !== null) {
                abort(403, 'Cuti bersama tidak bisa disetujui secara manual. Karyawan memilih sendiri via aplikasi mobile.');
            }

            $balance = null;
            $year    = Carbon::parse($leave->start_date)->year;

            if ($leave->leave_type === 'cuti') {
                // KEBIJAKAN 2026-08-25: saldo cuti karyawan NON-AKTIF secara default —
                // baris dibuat dengan quota 0 dan hanya HRD yang mengisi kuota manual
                // via tab Saldo Cuti (setLeaveBalance). Kuota kantor TIDAK lagi otomatis
                // dipakai di sini (defaultLeaveQuota hanya jadi referensi tampilan HRD).
                $balance = LeaveBalance::firstOrCreate(
                    ['user_id' => $leave->user_id, 'year' => $year, 'leave_type' => 'cuti'],
                    ['company_id' => $leave->company_id, 'quota' => 0, 'used' => 0]
                );
                // Kunci baris saldo SEBELUM baca used agar cek & increment atomik
                $balance = LeaveBalance::whereKey($balance->id)->lockForUpdate()->first();
                // Belum pernah diaktifkan HRD (kuota masih 0 & belum ada pemakaian)
                if ((int) $balance->quota <= 0 && (int) $balance->used === 0) {
                    abort(422, 'Saldo cuti karyawan ini belum diaktifkan oleh HRD. Aktifkan lewat menu Saldo Cuti dengan mengisi kuota cuti.');
                }
                $remaining = $balance->quota - $balance->used;

                // KEBIJAKAN ANNIVERSARY SPLIT (2026-08-25): validasi dua alokasi bila
                // rentang cuti melintasi tanggal reset kantor — hari sebelum reset vs
                // sisa saldo berjalan; hari pada/setelah reset vs kuota baru.
                $targetUser = User::find($leave->user_id);
                if (! $targetUser) {
                    abort(404, 'Karyawan tidak ditemukan.');
                }
                $split      = $this->splitLeaveAroundReset($targetUser, Carbon::parse($leave->start_date), Carbon::parse($leave->end_date), $leave->company_id);
                $hasPivot   = $split['anniversary'] !== null;
                $daysBefore = $hasPivot ? $split['days_before'] : (int) $leave->total_days;
                $daysAfter  = $hasPivot ? $split['days_after'] : 0;

                if ($daysBefore > $remaining) {
                    abort(422, $hasPivot
                        ? "Saldo cuti tidak cukup untuk hari sebelum tanggal reset ({$split['anniversary']}). Sisa {$remaining} hari, dibutuhkan {$daysBefore} hari."
                        : "Saldo cuti tidak cukup. Sisa {$remaining} hari, diminta {$leave->total_days} hari.");
                }
                if ($daysAfter > $split['fresh_quota']) {
                    abort(422, "Kuota cuti baru setelah tanggal reset ({$split['anniversary']}) tidak cukup. Tersedia {$split['fresh_quota']} hari, dibutuhkan {$daysAfter} hari.");
                }
                // CATATAN deduksi: potongan tetap penuh (total_days) ke saldo berjalan.
                // Bila approval terjadi SEBELUM anniversary dan rentang melintasinya,
                // sisa saldo bisa tampil minus sesaat — anniversary me-reset used=0
                // sehingga kondisi akhir konsisten (hari setelah reset memang pakai alokasi baru).
            } elseif (in_array($leave->leave_type, ['izin', 'sakit'])) {
                // Izin & sakit: tidak ada batas kuota, hanya dihitung di kolom 'izin'
                $balance = LeaveBalance::firstOrCreate(
                    ['user_id' => $leave->user_id, 'year' => $year, 'leave_type' => 'izin'],
                    ['company_id' => $leave->company_id, 'quota' => 0, 'used' => 0]
                );
                $balance = LeaveBalance::whereKey($balance->id)->lockForUpdate()->first();
            }

            $leave->update([
                'status'      => 'approved',
                'approved_by' => $actor->id,
                'approved_at' => now(),
            ]);

            if ($balance) {
                $balance->increment('used', $leave->total_days);
            }

            return $leave;
        });

        $this->logActivity(
            $actor->id,
            $leave->company_id,
            'leave_approved',
            "Approve {$leave->leave_type} #{$leave->id}",
            'leave_request',
            $leave->id
        );

        $this->notifyUser($leave->user_id, 'leave_approved', [
            'message'         => "Permintaan {$leave->leave_type} Anda telah disetujui.",
            'leave_id'        => $leave->id,
            'leave_type'      => $leave->leave_type,
            'status'          => 'approved',
        ], 'leave_request', $leave->id);

        // Kirim push notification FCM ke karyawan (konsisten dengan overtime approval)
        $employee = User::find($leave->user_id);
        if ($employee && $employee->fcm_token) {
            $leaveLabel = match ($leave->leave_type) {
                'cuti'  => 'Cuti',
                'izin'  => 'Izin',
                'sakit' => 'Sakit',
                'wfh'   => 'WFH',
                default => ucfirst($leave->leave_type),
            };
            $this->sendFcmPush(
                $employee->fcm_token,
                "✅ {$leaveLabel} Disetujui",
                "Permintaan {$leave->leave_type} Anda (#{$leave->id}) telah disetujui oleh HRD.",
                ['type' => 'leave_approved', 'leave_id' => (string) $leave->id]
            );
        }

        return response()->json([
            'message' => 'Permintaan berhasil disetujui.',
            'leave'   => $leave->only(['id', 'leave_type', 'status', 'approved_by', 'approved_at']),
        ]);
    }

    // 4. rejectLeave() — tolak permintaan (wajib rejection_reason)
    public function rejectLeave(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'rejection_reason' => 'required|string|max:1000',
        ]);

        $actor = $request->user();

        $leave = LeaveRequest::when(
            $actor->role !== 'super_admin',
            fn ($q) => $q->where('company_id', $actor->company_id)
        )->find($id);

        if (! $leave) {
            return response()->json(['message' => 'Permintaan tidak ditemukan.'], 404);
        }

        if ($leave->status !== 'pending') {
            return response()->json(['message' => 'Permintaan sudah diproses sebelumnya.'], 403);
        }

        // Guard: cuti bersama tidak bisa ditolak oleh HRD secara manual.
        if ($leave->holiday_id !== null) {
            return response()->json([
                'message' => 'Cuti bersama tidak bisa ditolak secara manual. Karyawan memilih sendiri via aplikasi mobile.',
            ], 403);
        }

        $leave->update([
            'status'           => 'rejected',
            'approved_by'      => $actor->id,
            'approved_at'      => now(),
            'rejection_reason' => $request->rejection_reason,
        ]);

        $this->logActivity(
            $actor->id,
            $leave->company_id,
            'leave_rejected',
            "Reject {$leave->leave_type} #{$leave->id}: {$request->rejection_reason}",
            'leave_request',
            $leave->id
        );

        $leaveTypeLabel = match ($leave->leave_type) {
            'cuti'  => 'Cuti Tahunan',
            'izin'  => 'Izin',
            'sakit' => 'Sakit',
            'wfh'   => 'WFH',
            default => ucfirst($leave->leave_type),
        };
        $dateFormatted = Carbon::parse($leave->start_date)->translatedFormat('d M Y');

        $this->notifyUser($leave->user_id, 'personal_leave_cancelled', [
            'title'            => "Pengajuan {$leaveTypeLabel} Ditolak",
            'name'             => $leaveTypeLabel,
            'date'             => (string) $leave->start_date,
            'date_label'       => $dateFormatted,
            'message'          => "Pengajuan {$leaveTypeLabel} Anda pada {$dateFormatted} telah ditolak oleh HRD. Alasan: {$request->rejection_reason}" . ($leave->leave_type === 'cuti' ? '. Saldo cuti tidak terpotong.' : ''),
            'leave_id'         => $leave->id,
            'leave_type'       => $leave->leave_type,
            'status'           => 'rejected',
            'rejection_reason' => $request->rejection_reason,
        ], 'leave_request', $leave->id);

        // Kirim push notification FCM ke karyawan (konsisten dengan overtime rejection)
        $employee = User::find($leave->user_id);
        if ($employee && $employee->fcm_token) {
            $leaveLabel = match ($leave->leave_type) {
                'cuti'  => 'Cuti',
                'izin'  => 'Izin',
                'sakit' => 'Sakit',
                'wfh'   => 'WFH',
                default => ucfirst($leave->leave_type),
            };
            $this->sendFcmPush(
                $employee->fcm_token,
                "❌ {$leaveLabel} Ditolak",
                "Permintaan {$leave->leave_type} Anda ditolak. Alasan: {$request->rejection_reason}",
                ['type' => 'leave_rejected', 'leave_id' => (string) $leave->id]
            );
        }

        return response()->json([
            'message' => 'Permintaan berhasil ditolak.',
            'leave'   => $leave->only(['id', 'leave_type', 'status', 'rejection_reason']),
        ]);
    }

    // 4b. listLeaves() — daftar pengajuan izin/cuti untuk HRD (filter status/tipe/user)
    public function listLeaves(Request $request): JsonResponse
    {
        $actor = $request->user();

        $validated = $request->validate([
            'status'     => 'nullable|in:pending,approved,rejected',
            'leave_type' => 'nullable|in:wfh,izin,sakit,cuti',
            'user_id'    => 'nullable|integer',
            'per_page'   => 'nullable|integer|min:1|max:2000',
        ]);

        $limit = $request->query('per_page') ? (int) $request->query('per_page') : 2000;

        $leaves = LeaveRequest::query()
            ->join('users', 'leave_requests.user_id', '=', 'users.id')
            ->when(
                $actor->role !== 'super_admin',
                fn ($q) => $q->where('leave_requests.company_id', $actor->company_id)
            )
            ->when($validated['status'] ?? null, fn ($q, $s) => $q->where('leave_requests.status', $s))
            ->when($validated['leave_type'] ?? null, fn ($q, $t) => $q->where('leave_requests.leave_type', $t))
            ->when($validated['user_id'] ?? null, fn ($q, $u) => $q->where('leave_requests.user_id', $u))
            ->select([
                'leave_requests.id', 'leave_requests.user_id', 'users.name as user_name',
                'users.department', 'users.attendance_setting_id',
                'leave_requests.leave_type', 'leave_requests.start_date',
                'leave_requests.end_date', 'leave_requests.total_days', 'leave_requests.reason',
                'leave_requests.document_path',
                'leave_requests.status', 'leave_requests.rejection_reason',
                'leave_requests.approved_by', 'leave_requests.approved_at', 'leave_requests.created_at',
                // Kolom pembeda sumber cuti: NULL = cuti mandiri (karyawan via mobile), NOT NULL = cuti bersama (HR via kalender)
                'leave_requests.holiday_id',
                'leave_requests.collective_status',
            ])
            ->orderByDesc('leave_requests.created_at')
            ->paginate($limit);

        // Sertakan flag has_document agar web tahu kapan menampilkan tombol surat dokter
        $leaves->getCollection()->transform(function ($l) {
            $l->has_document = ! empty($l->document_path);
            unset($l->document_path); // path internal tidak perlu bocor ke client
            return $l;
        });

        return response()->json($leaves);
    }

    // 4b-2. leaveDocument() — sajikan surat dokter (privat).
    //       Boleh diakses HRD/admin/super_admin sekompanyi, atau pemilik pengajuan.
    public function leaveDocument(Request $request, LeaveRequest $leave)
    {
        $user = $request->user();

        // Pemilik pengajuan selalu boleh; selain itu harus sekompanyi (super_admin bebas).
        $isOwner    = $leave->user_id === $user->id;
        $sameCompany = $user->role === 'super_admin' || $leave->company_id === $user->company_id;

        if (! $isOwner && ! $sameCompany) {
            return response()->json(['message' => 'Anda tidak berhak mengakses dokumen ini.'], 403);
        }

        if (! $leave->document_path || ! Storage::disk('local')->exists($leave->document_path)) {
            return response()->json(['message' => 'Dokumen tidak ditemukan.'], 404);
        }

        $fullPath = Storage::disk('local')->path($leave->document_path);
        $mime     = str_ends_with(strtolower($leave->document_path), '.pdf')
            ? 'application/pdf'
            : (mime_content_type($fullPath) ?: 'application/octet-stream');

        return response()->file($fullPath, ['Content-Type' => $mime]);
    }

    // 4c. today() — dashboard presensi hari ini untuk HRD
    public function today(Request $request): JsonResponse
    {
        $actor = $request->user();
        $today = $this->todayDate();

        // Semua karyawan aktif (WFH maupun onsite)
        $employees = User::query()
            ->when($actor->role !== 'super_admin', fn ($q) => $q->where('company_id', $actor->company_id))
            ->where('is_active', true)
            ->whereIn('role', ['employee', 'finance', 'hrd', 'admin'])
            ->select(['id', 'name', 'department', 'employee_code', 'attendance_setting_id', 'wfh_enabled', 'radius_enabled', 'company_id'])
            ->orderBy('name')
            ->get();

        // Presensi hari ini, di-index per user_id
        // where() (bukan whereDate) agar index('date') terpakai — kolom sudah bertipe DATE
        $yesterday = Carbon::parse($today)->subDay()->toDateString();

        // Presensi hari ini
        $attendancesToday = Attendance::where('date', $today)
            ->when($actor->role !== 'super_admin', fn ($q) => $q->where('company_id', $actor->company_id))
            ->get()->keyBy('user_id');

        // Presensi shift malam kemarin yang belum checkout ATAU sudah checkout hari ini (cross-day)
        $attendancesYesterday = Attendance::where('date', $yesterday)
            ->when($actor->role !== 'super_admin', fn ($q) => $q->where('company_id', $actor->company_id))
            ->get()
            ->filter(function ($att) use ($today) {
                if (is_null($att->check_out_time)) return true;
                
                $checkoutCarbon = \Carbon\Carbon::parse($att->check_out_time)->timezone('Asia/Jakarta');
                $checkoutDateLocal = $checkoutCarbon->format('Y-m-d');
                
                if ($checkoutDateLocal !== $today) return false;

                // Threshold auto-update: jika sudah lewat 4 jam sejak check-out, 
                // data shift malam ini tidak lagi masuk ke "Sudah Check-In" 
                // agar karyawan bisa masuk ke status jadwal barunya di hari ini (Belum Check-In / Libur).
                $hoursSinceCheckout = $checkoutCarbon->diffInHours(\Carbon\Carbon::now('Asia/Jakarta'));
                return $hoursSinceCheckout < 4;
            })
            ->keyBy('user_id');

        // Gabungkan: record hari ini mengalahkan kemarin untuk user yang sama
        $attendances = $attendancesYesterday->replace($attendancesToday);

        // Izin/cuti disetujui yang mencakup hari ini, di-index per user_id
        $onLeave = LeaveRequest::where('status', 'approved')
            ->where('start_date', '<=', $today)
            ->where('end_date', '>=', $today)
            ->when($actor->role !== 'super_admin', fn ($q) => $q->where('company_id', $actor->company_id))
            ->get()->keyBy('user_id');

        $checkedIn = [];
        $notCheckedIn = [];
        $leaveList = [];

        // Cek apakah hari ini libur nasional/perusahaan/cabang beserta pengecualian karyawan (holiday_exclusions)
        $holidaysToday = \App\Models\Holiday::with('excludedUsers:id')
            ->whereDate('date', $today)
            ->where(function ($q) use ($actor) {
                $q->whereNull('company_id')
                  ->orWhere('company_id', $actor->company_id);
            })->get();

        $collectiveHolidayIds = $holidaysToday->where('is_collective', true)->pluck('id');

        $acceptedCollectiveLeaveUserIds = [];
        if ($collectiveHolidayIds->isNotEmpty()) {
            $acceptedCollectiveLeaveUserIds = \App\Models\LeaveRequest::whereIn('holiday_id', $collectiveHolidayIds)
                ->where('collective_status', 'accepted')
                ->pluck('user_id')
                ->toArray();
        }

        // PRELOAD BULK: Selesaikan jadwal semua karyawan sekaligus dalam 3 query (Anti N+1)
        $schedulesByUser = \App\Http\Controllers\API\ShiftController::resolveSchedulesBulk($employees, $today);

        foreach ($employees as $emp) {
            $att = $attendances[$emp->id] ?? null;

            if ($att && $att->check_in_time) {
                // Shift malam kemarin (cross-day)
                $isCrossDay = \Carbon\Carbon::parse($att->date)->format('Y-m-d') === $yesterday;
                $checkedIn[] = [
                    'user_id'               => $emp->id,
                    'name'                  => $emp->name,
                    'department'            => $emp->department,
                    'employee_code'         => $emp->employee_code,
                    'attendance_setting_id' => $emp->attendance_setting_id,
                    'check_in_time'         => $att->check_in_time,
                    'check_out_time'        => $att->check_out_time,
                    'check_in_type'         => $att->check_in_type,
                    'status'                => $att->status,
                    'shift_date'            => Carbon::parse($att->date)->format('Y-m-d'),
                    'checkout_date'         => $isCrossDay ? $today : null,
                    'is_cross_day'          => $isCrossDay,
                ];
            } elseif (isset($onLeave[$emp->id])) {
                $leaveList[] = [
                    'user_id'               => $emp->id,
                    'name'                  => $emp->name,
                    'department'            => $emp->department,
                    'employee_code'         => $emp->employee_code,
                    'attendance_setting_id' => $emp->attendance_setting_id,
                    'leave_type'            => $onLeave[$emp->id]->leave_type,
                ];
            } else {
                // Cek apakah hari ini hari libur sesuai jadwal karyawan (in-memory lookup)
                $empId = $emp->id;

                // Libur reguler (non-collective) yang berlaku untuk karyawan ini:
                // 1. Berlaku untuk semua cabang (attendance_setting_id null) ATAU cabang karyawan cocok
                // 2. Karyawan TIDAK dikecualikan dari libur ini (holiday_exclusions)
                $regularHolidayForEmp = $holidaysToday->first(function ($h) use ($emp) {
                    if ($h->is_collective) return false;
                    if ($h->attendance_setting_id && $h->attendance_setting_id !== $emp->attendance_setting_id) {
                        return false;
                    }
                    if ($h->excludedUsers->contains('id', $emp->id)) {
                        return false;
                    }
                    return true;
                }) !== null;

                $isHolidayForUser = $regularHolidayForEmp || in_array($empId, $acceptedCollectiveLeaveUserIds);
                if ($isHolidayForUser) {
                    $isOff = true;
                } else {
                    $schedule = $schedulesByUser[$empId] ?? null;
                    $isOff    = (bool) ($schedule['is_off'] ?? false);
                }

                $notCheckedIn[] = [
                    'user_id'               => $emp->id,
                    'name'                  => $emp->name,
                    'department'            => $emp->department,
                    'employee_code'         => $emp->employee_code,
                    'attendance_setting_id' => $emp->attendance_setting_id,
                    'is_off'                => $isOff,
                ];
            }
        }

        return response()->json([
            'date'    => $today,
            'summary' => [
                'total_employees' => $employees->count(),
                'checked_in'      => count($checkedIn),
                'not_checked_in'  => count($notCheckedIn),
                'on_leave'        => count($leaveList),
            ],
            'checked_in'     => $checkedIn,
            'not_checked_in' => $notCheckedIn,
            'on_leave'       => $leaveList,
        ]);
    }

    // 4d. listLeaveBalances() — saldo cuti karyawan (HRD)
    public function listLeaveBalances(Request $request): JsonResponse
    {
        $actor = $request->user();

        $validated = $request->validate([
            'user_id' => 'nullable|integer',
            'year'    => 'nullable|integer',
        ]);
        $year = $validated['year'] ?? now()->year;

        $usersQuery = User::where('is_active', true)
            ->when(
                $actor->role !== 'super_admin',
                fn ($q) => $q->where('company_id', $actor->company_id)
            )
            ->when($validated['user_id'] ?? null, fn ($q, $u) => $q->where('id', $u))
            ->orderBy('name');

        $users = $usersQuery->get(['id', 'name', 'company_id', 'employee_code', 'attendance_setting_id']);

        $existingBalances = LeaveBalance::where('year', $year)
            ->whereIn('user_id', $users->pluck('id'))
            ->get()
            ->groupBy('user_id');

        // Nama kantor per user (untuk filter kantor di UI Saldo Cuti)
        $officeNames = AttendanceSetting::whereIn('id', $users->pluck('attendance_setting_id')->filter()->unique())
            ->pluck('office_name', 'id');

        $balances = collect();
        $leaveTypes = ['cuti', 'izin'];
        // FIX BUG #1 (2026-08-25): TIDAK ada lagi fallback hardcoded 12. Baris saldo yang
        // belum dibuat ditampilkan sebagai NON-AKTIF (quota 0) sesuai kebijakan karyawan
        // baru cutinya non-aktif. Kuota default kantor dikirim sebagai REFERENSI
        // (office_default_quota) agar HRD tahu nilai wajar saat mau mengaktifkan.
        // Preload kantor sekali untuk menghitung kuota referensi tanpa N+1
        // (meniru logika defaultLeaveQuota: kantor milik user → fallback kantor pertama).
        $officesForQuota = AttendanceSetting::whereIn('company_id', $users->pluck('company_id')->filter()->unique())
            ->orderBy('id')
            ->get(['id', 'company_id', 'default_leave_quota']);
        $officeById        = $officesForQuota->keyBy('id');
        $firstOfficeByCo   = $officesForQuota->groupBy('company_id')->map(fn ($g) => $g->first());

        foreach ($users as $user) {
            $userBalances = $existingBalances->get($user->id, collect());

            // Kuota referensi: kantor milik user → fallback kantor pertama perusahaan → 12
            $refOffice = ($user->attendance_setting_id && $officeById->has($user->attendance_setting_id))
                ? $officeById->get($user->attendance_setting_id)
                : ($firstOfficeByCo->get($user->company_id));
            $officeDefaultQuota = $refOffice?->default_leave_quota
                ?? self::DEFAULT_LEAVE_QUOTA['cuti'];

            foreach ($leaveTypes as $type) {
                $common = [
                    'user_id'           => $user->id,
                    'user_name'         => $user->name,
                    'employee_code'     => $user->employee_code,
                    'office_id'         => $user->attendance_setting_id,
                    'office_name'       => $officeNames[$user->attendance_setting_id] ?? null,
                    'year'              => $year,
                    'leave_type'        => $type,
                ];

                if ($existing = $userBalances->firstWhere('leave_type', $type)) {
                    $balances->push($common + [
                        'id'                   => $existing->id,
                        'quota'                => $existing->quota,
                        'used'                 => $existing->used,
                        'remaining'            => $existing->quota - $existing->used,
                        'active'               => (int) $existing->quota > 0 || (int) $existing->used > 0,
                        'office_default_quota' => $officeDefaultQuota,
                    ]);
                } else {
                    $balances->push($common + [
                        'id'                   => null,
                        'quota'                => 0, // belum diaktifkan HRD
                        'used'                 => 0,
                        'remaining'            => 0,
                        'active'               => false,
                        'office_default_quota' => $officeDefaultQuota,
                    ]);
                }
            }
        }

        return response()->json(['year' => $year, 'balances' => $balances->values()]);
    }

    // 4e. setLeaveBalance() — atur kuota cuti/sakit karyawan (HRD)
    public function setLeaveBalance(Request $request): JsonResponse
    {
        $actor = $request->user();

        $validated = $request->validate([
            'user_id'    => 'required|integer',
            'leave_type' => 'required|in:cuti,izin',
            'year'       => 'nullable|integer',
            'quota'      => 'required|integer|min:0',
        ]);
        $year = $validated['year'] ?? now()->year;

        $target = User::when(
            $actor->role !== 'super_admin',
            fn ($q) => $q->where('company_id', $actor->company_id)
        )->find($validated['user_id']);

        if (! $target) {
            return response()->json(['message' => 'User tidak ditemukan di perusahaan Anda.'], 404);
        }

        $balance = LeaveBalance::updateOrCreate(
            ['user_id' => $target->id, 'year' => $year, 'leave_type' => $validated['leave_type']],
            ['company_id' => $target->company_id, 'quota' => $validated['quota']]
        );

        // Jika kuota cuti tahunan diset 0 (dinonaktifkan):
        // Batalkan request cuti bersama yang masih pending & exclude user dari holiday terkait
        if ($validated['leave_type'] === 'cuti' && (int) $validated['quota'] === 0) {
            $pendingCollectiveLeaves = \App\Models\LeaveRequest::where('user_id', $target->id)
                ->where('status', 'pending_cuti_bersama')
                ->whereNotNull('holiday_id')
                ->get();

            foreach ($pendingCollectiveLeaves as $clr) {
                if ($clr->holiday) {
                    $clr->holiday->excludedUsers()->syncWithoutDetaching([$target->id]);
                }
                $clr->delete();
            }
        }

        $this->logActivity(
            $actor->id,
            $target->company_id,
            'leave_balance_set',
            "Set kuota {$validated['leave_type']} {$target->name} = {$validated['quota']} hari ({$year})",
            'leave_balance',
            $balance->id
        );

        return response()->json([
            'message' => 'Kuota cuti berhasil diperbarui.',
            'balance' => [
                'user_id'    => $balance->user_id,
                'year'       => $balance->year,
                'leave_type' => $balance->leave_type,
                'quota'      => $balance->quota,
                'used'       => $balance->used,
                'remaining'  => $balance->quota - $balance->used,
            ],
        ]);
    }

    // 4f. listLeaveBalanceHistories() — riwayat saldo cuti & izin/sakit setelah reset (HRD)
    //     GET /api/v1/dashboard/attendance/leave-balance-history
    public function listLeaveBalanceHistories(Request $request): JsonResponse
    {
        $actor = $request->user();

        $validated = $request->validate([
            'office_id' => 'nullable|string',
            'year'      => 'nullable|integer',
            'search'    => 'nullable|string',
        ]);

        $query = \App\Models\LeaveBalanceHistory::with(['user:id,name,employee_code,department,attendance_setting_id', 'office:id,office_name'])
            ->when(
                $actor->role !== 'super_admin',
                fn ($q) => $q->where('company_id', $actor->company_id)
            )
            ->when($validated['office_id'] ?? null, function ($q, $off) {
                if ($off === 'none') {
                    $q->whereNull('attendance_setting_id');
                } else {
                    $q->where('attendance_setting_id', $off);
                }
            })
            ->when($validated['year'] ?? null, fn ($q, $y) => $q->whereYear('reset_date', $y))
            ->when($validated['search'] ?? null, function ($q, $search) {
                $q->whereHas('user', function ($uq) use ($search) {
                    $uq->where('name', 'like', "%{$search}%")
                       ->orWhere('employee_code', 'like', "%{$search}%");
                });
            })
            ->orderByDesc('reset_date')
            ->orderByDesc('id');

        $histories = $query->get()->map(function ($h) {
            return [
                'id'                    => $h->id,
                'user_id'               => $h->user_id,
                'user_name'             => $h->user?->name ?? '—',
                'employee_code'         => $h->user?->employee_code ?? '',
                'department'            => $h->user?->department ?? '—',
                'attendance_setting_id' => $h->attendance_setting_id,
                'office_name'           => $h->office?->office_name ?? ($h->attendance_setting_id ? 'Kantor' : 'Semua Kantor / Tanpa Kantor'),
                'period_label'          => $h->period_label,
                'period_start'          => $h->period_start?->toDateString(),
                'period_end'            => $h->period_end?->toDateString(),
                'reset_date'            => $h->reset_date->toDateString(),
                'reset_date_formatted'  => $h->reset_date->translatedFormat('d M Y'),
                'cuti_quota'            => (int) $h->cuti_quota,
                'cuti_used'             => (int) $h->cuti_used,
                'cuti_remaining'        => (int) $h->cuti_remaining,
                'izin_sakit_used'       => (int) $h->izin_sakit_used,
                'notes'                 => $h->notes,
                'created_at'            => $h->created_at?->toDateTimeString(),
            ];
        });

        // Summary stats
        $stats = [
            'total_records'          => $histories->count(),
            'total_cuti_used'        => $histories->sum('cuti_used'),
            'total_cuti_remaining'   => $histories->sum('cuti_remaining'),
            'total_izin_sakit_used'  => $histories->sum('izin_sakit_used'),
        ];

        return response()->json([
            'histories' => $histories->values(),
            'stats'     => $stats,
        ]);
    }

    // 4g. resetOfficeLeaveBalances() — reset saldo cuti & izin/sakit kantor manual (HRD)
    //     POST /api/v1/dashboard/attendance/settings/{id}/reset-leave-balances
    public function resetOfficeLeaveBalances(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();

        $office = AttendanceSetting::when(
            $actor->role !== 'super_admin',
            fn ($q) => $q->where('company_id', $actor->company_id)
        )->findOrFail($id);

        $today = Carbon::today('Asia/Jakarta');
        $year  = $today->year;

        $userIds = User::where('company_id', $office->company_id)
            ->where('attendance_setting_id', $office->id)
            ->where('is_active', true)
            ->pluck('id');

        if ($userIds->isEmpty()) {
            return response()->json(['message' => 'Tidak ada karyawan aktif yang terdaftar di kantor ini.'], 422);
        }

        $resetCount = 0;
        foreach ($userIds as $userId) {
            $existingCuti = LeaveBalance::where('user_id', $userId)
                ->where('year', $year)
                ->where('leave_type', 'cuti')
                ->first();

            if (! $existingCuti || (int) $existingCuti->quota <= 0) {
                continue;
            }

            $existingIzin = LeaveBalance::where('user_id', $userId)
                ->where('year', $year)
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
                'period_label'          => 'Periode s/d ' . $today->translatedFormat('d M Y'),
                'period_start'          => $today->copy()->subYear()->addDay()->toDateString(),
                'period_end'            => $today->toDateString(),
                'reset_date'            => $today->toDateString(),
                'cuti_quota'            => $cutiQuota,
                'cuti_used'             => $cutiUsed,
                'cuti_remaining'        => $cutiRemaining,
                'izin_sakit_used'       => $izinUsed,
                'notes'                 => "Reset manual oleh {$actor->name}",
            ]);

            // 2. Reset saldo cuti tahunan
            $existingCuti->update([
                'quota' => $office->default_leave_quota,
                'used'  => 0,
            ]);

            // 3. Reset saldo izin & sakit
            if ($existingIzin) {
                $existingIzin->update([
                    'used' => 0,
                ]);
            }

            $resetCount++;
        }

        $office->update(['last_leave_reset_on' => $today->toDateString()]);

        $this->logActivity(
            $actor->id,
            $office->company_id,
            'leave_balances_reset',
            "Reset saldo cuti & izin/sakit kantor {$office->office_name} ({$resetCount} karyawan)",
            'attendance_setting',
            $office->id
        );

        return response()->json([
            'message'     => "Berhasil me-reset dan mengarsipkan saldo {$resetCount} karyawan kantor {$office->office_name}.",
            'reset_count' => $resetCount,
        ]);
    }

    // 5b. monthlySummary() — rekap bulanan satu karyawan (fondasi payroll)
    public function monthlySummary(Request $request): JsonResponse
    {
        $actor = $request->user();

        $validated = $request->validate([
            'user_id' => 'required|integer',
            'month'   => 'nullable|integer|between:1,12',
            'year'    => 'nullable|integer',
        ]);
        $month = $validated['month'] ?? now()->month;
        $year  = $validated['year'] ?? now()->year;

        $target = User::when(
            $actor->role !== 'super_admin',
            fn ($q) => $q->where('company_id', $actor->company_id)
        )->find($validated['user_id']);

        if (! $target) {
            return response()->json(['message' => 'User tidak ditemukan di perusahaan Anda.'], 404);
        }

        $start      = Carbon::create($year, $month, 1)->startOfMonth();
        $end        = (clone $start)->endOfMonth();
        $rangeStart = $start->toDateString();
        $rangeEnd   = $end->toDateString();

        // Hitung hanya sampai hari ini agar hari depan tidak dihitung absen
        $countUntil = Carbon::parse($rangeEnd)->greaterThan(now())
            ? now()->toDateString()
            : $rangeEnd;

        // Hari libur nasional + perusahaan dalam bulan ini
        $holidays = Holiday::where(function ($q) use ($target) {
                $q->whereNull('company_id')->orWhere('company_id', $target->company_id);
            })
            ->whereBetween('date', [$rangeStart, $rangeEnd])
            ->get(['id', 'date', 'is_collective']);

        $regularHolidaySet = $holidays->where('is_collective', false)
            ->pluck('date')
            ->map(fn ($d) => Carbon::parse($d)->format('Y-m-d'))
            ->flip()
            ->all();

        $collectiveHolidays = $holidays->where('is_collective', true);
        $acceptedCollectiveLeavesSet = [];
        if ($collectiveHolidays->isNotEmpty()) {
            $acceptedLeaves = \App\Models\LeaveRequest::whereIn('holiday_id', $collectiveHolidays->pluck('id'))
                ->where('user_id', $target->id)
                ->where('collective_status', 'accepted')
                ->join('holidays', 'leave_requests.holiday_id', '=', 'holidays.id')
                ->select('holidays.date')
                ->get();
            
            foreach ($acceptedLeaves as $leave) {
                $dateStr = Carbon::parse($leave->date)->format('Y-m-d');
                $acceptedCollectiveLeavesSet[$dateStr] = true;
            }
        }

        // Hitung hari kerja — SHIFT-AWARE.
        // Sebelumnya memakai isWeekend() global (work_days kantor default): karyawan ber-shift
        // yang justru masuk Sabtu/Minggu & libur Senin-Jumat dihitung total salah
        // (kasus: budi@majubersama.co.id, shift #31 "shif minggu").
        // resolveOffDatesForUser() meniru persis logika ShiftController::resolveSchedule()
        // sehingga off-day mengikuti jadwal shift efektif per tanggal.
        $workingDays  = 0;
        $cur          = Carbon::parse($rangeStart);
        $until        = Carbon::parse($countUntil);

        // Bangun daftar tanggal sampai countUntil → ambil peta off-day shift-aware sekali query
        $periodDates = [];
        for ($d = $cur->copy(); $d->lte($until); $d->addDay()) {
            $periodDates[] = $d->toDateString();
        }
        $offMap = empty($periodDates) ? [] : $this->resolveOffDatesForUser($target, $periodDates);

        while ($cur->lte($until)) {
            $ds = $cur->format('Y-m-d');
            $isHolidayForUser = isset($regularHolidaySet[$ds]) || isset($acceptedCollectiveLeavesSet[$ds]);
            $isOffDay         = $offMap[$ds] ?? $cur->isWeekend(); // fallback weekend bila shift tak terdeteksi
            if (! $isOffDay && ! $isHolidayForUser) {
                $workingDays++;
            }
            $cur->addDay();
        }

        // Data attendance nyata (dari DB)
        // Ambil tanggal-tanggal presensi raw (tidak diagregasi) agar bisa difilter per hari kerja.
        // Ini menghindari bug undercount absent: karyawan masuk di hari libur nasional sebelumnya
        // membuat $attendanceDays naik sedangkan $workingDays tidak — absent jadi minus/undercount.
        $attendanceRows = Attendance::where('user_id', $target->id)
            ->whereBetween('date', [$rangeStart, $countUntil])
            ->select('date', 'status')
            ->get();

        // Breakdown per status (untuk response present/late/early_leave) — semua presensi, termasuk
        // yang jatuh di hari libur (agar angka ini tidak berubah dari perilaku lama).
        $attCountsBreakdown = ['present' => 0, 'late' => 0, 'early_leave' => 0];
        foreach ($attendanceRows as $row) {
            $s = $row->status ?? '';
            if (isset($attCountsBreakdown[$s])) {
                $attCountsBreakdown[$s]++;
            }
        }

        // total_check_in = jumlah hari hadir UNIK yang jatuh pada HARI KERJA (bukan libur/off-day).
        // "distinct" tanggal diperlukan agar data kotor (dua baris di tanggal sama) tidak dobel.
        $attendanceDays = $attendanceRows
            ->pluck('date')
            ->unique()
            ->filter(function ($dateRaw) use ($regularHolidaySet, $acceptedCollectiveLeavesSet, $offMap) {
                $ds = Carbon::parse($dateRaw)->format('Y-m-d');
                $isHolidayForUser = isset($regularHolidaySet[$ds]) || isset($acceptedCollectiveLeavesSet[$ds]);
                $isOffDay = array_key_exists($ds, $offMap)
                    ? (bool) $offMap[$ds]
                    : Carbon::parse($ds)->isWeekend();
                return ! $isOffDay && ! $isHolidayForUser;
            })
            ->count();

        // Hari-hari leave yang disetujui (per tanggal unik agar tidak dobel)
        $leaveRequests = LeaveRequest::where('user_id', $target->id)
            ->where('status', 'approved')
            ->where('start_date', '<=', $countUntil)
            ->where('end_date', '>=', $rangeStart)
            ->get(['leave_type', 'start_date', 'end_date']);

        $leaveDatesByType = [];
        foreach ($leaveRequests as $lr) {
            $lCur = Carbon::parse($lr->start_date);
            $lEnd = Carbon::parse($lr->end_date);
            while ($lCur->lte($lEnd) && $lCur->lte($until)) {
                $ds = $lCur->format('Y-m-d');
                $isHolidayForUser = isset($regularHolidaySet[$ds]) || isset($acceptedCollectiveLeavesSet[$ds]);
                // Konsisten dengan working_days: pakai off-day shift-aware, bukan isWeekend()
                $isOffDay = array_key_exists($ds, $offMap)
                    ? (bool) $offMap[$ds]
                    : $lCur->isWeekend();
                if (! $isOffDay && ! $isHolidayForUser) {
                    $leaveDatesByType[$lr->leave_type][$ds] = true;
                }
                $lCur->addDay();
            }
        }
        $leaveDayCounts = array_map('count', $leaveDatesByType);
        $totalLeaveDays = array_sum($leaveDayCounts);

        // Absen = hari kerja − (hari dengan presensi) − (hari izin/cuti disetujui)
        $absentDays = max(0, $workingDays - $attendanceDays - $totalLeaveDays);

        // Total menit lembur bulan ini
        $overtimeMinutes = (int) Attendance::where('user_id', $target->id)
            ->whereBetween('date', [$rangeStart, $rangeEnd])
            ->sum('overtime_minutes');

        // Breakdown check-in by type (onsite/wfh/field) — tidak perlu difilter hari kerja,
        // hanya untuk info statistik jenis presensi.
        $typeCounts = Attendance::where('user_id', $target->id)
            ->whereBetween('date', [$rangeStart, $rangeEnd])
            ->select('check_in_type', DB::raw('COUNT(*) as total'))
            ->groupBy('check_in_type')->pluck('total', 'check_in_type');

        return response()->json([
            'user'   => ['id' => $target->id, 'name' => $target->name, 'department' => $target->department],
            'period' => ['month' => (int) $month, 'year' => (int) $year],
            'attendance' => [
                'present'        => (int) ($attCountsBreakdown['present'] ?? 0),
                'late'           => (int) ($attCountsBreakdown['late'] ?? 0),
                'early_leave'    => (int) ($attCountsBreakdown['early_leave'] ?? 0),
                'absent'         => $absentDays,
                'total_check_in' => $attendanceDays,
                'working_days'   => $workingDays,
            ],
            'overtime' => [
                'minutes' => $overtimeMinutes,
                'hours'   => $this->formatMinutes($overtimeMinutes),
            ],
            'by_type' => [
                'onsite' => (int) ($typeCounts['onsite'] ?? 0),
                'wfh'    => (int) ($typeCounts['wfh'] ?? 0),
                'field'  => (int) ($typeCounts['field'] ?? 0),
            ],
            'leave' => [
                'izin'  => $leaveDayCounts['izin']  ?? 0,
                'sakit' => $leaveDayCounts['sakit']  ?? 0,
                'cuti'  => $leaveDayCounts['cuti']   ?? 0,
                'wfh'   => $leaveDayCounts['wfh']    ?? 0,
            ],
        ]);
    }

    // ─── Helper: bangun semua baris laporan (attendance nyata + virtual absent/izin/cuti)
    // Menggabungkan 4 sumber: attendance records, leave requests, holiday calendar, user list.
    // Karyawan yang tidak check-in di hari kerja → muncul sebagai 'absent' atau leave type-nya.
    private function buildFullRows(
        ?int $companyId,
        string $startDate,
        string $endDate,
        ?string $department = null,
        ?int $officeId = null,
        array $filters = [],
        ?int $page = null,
        ?int $perPage = null
    ): array {
        // 1. Ambil daftar karyawan
        $users = User::where(function ($q) use ($companyId) {
                if ($companyId) $q->where('company_id', $companyId);
            })
            ->when($department, fn ($q) => $q->where('department', $department))
            ->when($officeId, fn ($q) => $q->where('attendance_setting_id', $officeId))
            ->where('role', '!=', 'super_admin')
            ->orderBy('name')
            ->get(['id', 'name', 'department', 'employee_code', 'company_id', 'attendance_setting_id']);

        if ($users->isEmpty()) {
            return ($page !== null) ? [
                'summary'   => ['present' => 0, 'late' => 0, 'absent' => 0, 'early_leave' => 0, 'cuti' => 0, 'izin' => 0, 'sakit' => 0, 'total_working_minutes' => 0, 'total_overtime_minutes' => 0],
                'by_type'   => ['onsite' => 0, 'wfh' => 0, 'field' => 0],
                'data'      => [],
                'total'     => 0,
                'last_page' => 1,
            ] : [];
        }

        // Cache lowercase nama & kode karyawan untuk pencarian cepat O(1)
        foreach ($users as $u) {
            $u->search_name = strtolower($u->name ?? '');
            $u->search_code = strtolower($u->employee_code ?? '');
        }

        // 2. Query semua record presensi nyata dalam range
        $attendances = Attendance::join('users', 'attendances.user_id', '=', 'users.id')
            ->where(function ($q) use ($companyId) {
                if ($companyId) $q->where('users.company_id', $companyId);
            })
            ->when($department, fn ($q) => $q->where('users.department', $department))
            ->when($officeId, fn ($q) => $q->where('users.attendance_setting_id', $officeId))
            ->whereBetween('attendances.date', [$startDate, $endDate])
            ->select([
                'attendances.id', 'attendances.user_id',
                'users.name as user_name', 'users.department', 'users.employee_code',
                'attendances.date', 'attendances.check_in_time', 'attendances.check_out_time',
                'attendances.check_in_type', 'attendances.status',
                'attendances.overtime_minutes', 'attendances.is_holiday',
                'attendances.check_in_lat', 'attendances.check_in_lng',
                'attendances.work_minutes as working_minutes',
            ])
            ->get();

        $attendancesByUserAndDate = [];
        foreach ($attendances as $a) {
            $dStr = is_string($a->date) ? substr($a->date, 0, 10) : Carbon::parse($a->date)->format('Y-m-d');
            $attendancesByUserAndDate[$a->user_id][$dStr] = $a;
        }

        // 3. Approved leave dalam range → lookup [user_id][date] = leave_type
        $leaves = LeaveRequest::when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->where('status', 'approved')
            ->where('start_date', '<=', $endDate)
            ->where('end_date', '>=', $startDate)
            ->get(['user_id', 'leave_type', 'start_date', 'end_date']);

        $leaveLookup = [];
        foreach ($leaves as $lr) {
            $lCur = Carbon::parse($lr->start_date);
            $lEnd = Carbon::parse($lr->end_date);
            while ($lCur->lte($lEnd)) {
                $leaveLookup[$lr->user_id][$lCur->format('Y-m-d')] = $lr->leave_type;
                $lCur->addDay();
            }
        }

        // 4. Set tanggal libur (nasional + perusahaan) dalam range
        $holidays = Holiday::where(function ($q) use ($companyId) {
                $q->whereNull('company_id')->orWhere('company_id', $companyId);
            })
            ->whereBetween('date', [$startDate, $endDate])
            ->get(['id', 'date', 'is_collective']);

        $regularHolidaySet = $holidays->where('is_collective', false)
            ->pluck('date')
            ->map(fn ($d) => is_string($d) ? substr($d, 0, 10) : Carbon::parse($d)->format('Y-m-d'))
            ->flip()
            ->all();

        $collectiveHolidays = $holidays->where('is_collective', true);
        $acceptedCollectiveLeaves = [];
        if ($collectiveHolidays->isNotEmpty()) {
            $acceptedLeaves = \App\Models\LeaveRequest::whereIn('holiday_id', $collectiveHolidays->pluck('id'))
                ->where('collective_status', 'accepted')
                ->join('holidays', 'leave_requests.holiday_id', '=', 'holidays.id')
                ->select('leave_requests.user_id', 'holidays.date')
                ->get();
            
            foreach ($acceptedLeaves as $leave) {
                $dateStr = is_string($leave->date) ? substr($leave->date, 0, 10) : Carbon::parse($leave->date)->format('Y-m-d');
                $acceptedCollectiveLeaves[$leave->user_id][$dateStr] = true;
            }
        }

        // 5. Pre-load UserShift untuk semua user dalam satu query
        $userIds   = $users->pluck('id')->all();
        $userShifts = \App\Models\UserShift::with('shift')
            ->whereIn('user_id', $userIds)
            ->where('start_date', '<=', $endDate)
            ->orderByDesc('start_date')
            ->get()
            ->groupBy('user_id');

        // Pre-load AttendanceSetting (offices) untuk semua company yang relevan (1 query)
        $companyIds = $users->pluck('company_id')->filter()->unique()->values();
        $allOffices = AttendanceSetting::where(function ($q) use ($companyIds, $companyId) {
                if ($companyId) $q->where('company_id', $companyId);
                elseif ($companyIds->isNotEmpty()) $q->whereIn('company_id', $companyIds);
            })
            ->get()
            ->keyBy('id');
        $fallbackOffice = $allOffices->first();
        $fallbackOffId = $fallbackOffice ? $fallbackOffice->id : 0;

        // Precompute jadwal office per hari (0..6) agar O(1) array lookup tanpa array_map berulang
        $officeScheduleByDow = [];
        foreach ($allOffices as $offId => $off) {
            $wDays = array_map('intval', (array) ($off->work_days ?? [1, 2, 3, 4, 5]));
            for ($d = 0; $d <= 6; $d++) {
                $isOff = !in_array($d, $wDays, true);
                $custStart = (!$isOff && !empty($off->custom_schedules[$d]['start'])) ? $off->custom_schedules[$d]['start'] : null;
                $officeScheduleByDow[$offId][$d] = [
                    'is_off' => $isOff,
                    'start'  => $isOff ? null : ($custStart ?? $off->work_start_time),
                ];
            }
        }
        $fallbackOffId = $fallbackOffice ? $fallbackOffice->id : 0;

        $shiftIds = $userShifts->flatten()->pluck('shift_id')->filter()->unique()->values()->all();
        $shiftScheduleCache = [];
        if (!empty($shiftIds)) {
            $rawSchedules = \App\Models\ShiftSchedule::whereIn('shift_id', $shiftIds)
                ->orderByDesc('effective_date')
                ->get();
            foreach ($rawSchedules as $s) {
                $k = $s->shift_id . '_' . $s->day_of_week;
                $s->effective_date_str = is_string($s->effective_date)
                    ? substr($s->effective_date, 0, 10)
                    : $s->effective_date->toDateString();
                $shiftScheduleCache[$k][] = $s;
            }
        }

        $userShiftsArray = [];
        foreach ($userShifts as $uId => $assignments) {
            foreach ($assignments as $a) {
                $userShiftsArray[$uId][] = (object) [
                    'shift_id'   => $a->shift_id,
                    'is_active'  => (bool) optional($a->shift)->is_active,
                    'start_date' => is_string($a->start_date) ? substr($a->start_date, 0, 10) : $a->start_date->toDateString(),
                    'end_date'   => $a->end_date ? (is_string($a->end_date) ? substr($a->end_date, 0, 10) : $a->end_date->toDateString()) : null,
                ];
            }
        }

        $dates = [];
        $curDate = Carbon::parse($endDate);
        $firstDate = Carbon::parse($startDate);
        $today = now()->toDateString();
        while ($curDate->gte($firstDate)) {
            $dStr = $curDate->format('Y-m-d');
            $dates[] = [
                'date'             => $dStr,
                'dow'              => $curDate->dayOfWeek,
                'is_holiday'       => isset($regularHolidaySet[$dStr]),
                'is_future'        => $dStr > $today,
            ];
            $curDate->subDay();
        }

        $filterStatus = $filters['status'] ?? null;
        $filterType   = $filters['type'] ?? null;
        $filterSearch = !empty($filters['search']) ? strtolower($filters['search']) : null;

        $isPaginated   = ($page !== null && $perPage !== null);
        $offsetStart   = $isPaginated ? ($page - 1) * $perPage : 0;
        $offsetEnd     = $isPaginated ? $offsetStart + $perPage : PHP_INT_MAX;

        $totalFiltered        = 0;
        $pageItems            = [];
        $statusCounts         = [];
        $typeCounts           = [];
        $totalWorkingMinutes  = 0;
        $totalOvertimeMinutes = 0;

        foreach ($dates as $dInfo) {
            $dateStr          = $dInfo['date'];
            $dayOfWeek        = $dInfo['dow'];
            $isRegularHoliday = $dInfo['is_holiday'];
            $isFuture         = $dInfo['is_future'];

            foreach ($users as $user) {
                $key = $user->id . '_' . $dateStr;

                $shiftAssignment = null;
                if (isset($userShiftsArray[$user->id])) {
                    foreach ($userShiftsArray[$user->id] as $us) {
                        if ($us->start_date <= $dateStr && ($us->end_date === null || $us->end_date >= $dateStr)) {
                            $shiftAssignment = $us;
                            break;
                        }
                    }
                }

                $workStartTime = null;
                $isOff = false;

                if ($shiftAssignment && $shiftAssignment->shift_id && $shiftAssignment->is_active) {
                    $cacheKey = $shiftAssignment->shift_id . '_' . $dayOfWeek;
                    if (isset($shiftScheduleCache[$cacheKey])) {
                        $candidates = $shiftScheduleCache[$cacheKey];
                        $shiftSched = null;
                        foreach ($candidates as $s) {
                            if ($s->effective_date_str <= $dateStr) {
                                $shiftSched = $s;
                                break;
                            }
                        }
                        $shiftSched = $shiftSched ?? ($candidates[0] ?? null);
                        if ($shiftSched) {
                            $workStartTime = $shiftSched->work_start_time;
                            $isOff = (bool) $shiftSched->is_off;
                        }
                    }
                } else {
                    $offId = $user->attendance_setting_id ?? $fallbackOffId;
                    if (isset($officeScheduleByDow[$offId][$dayOfWeek])) {
                        $isOff         = $officeScheduleByDow[$offId][$dayOfWeek]['is_off'];
                        $workStartTime = $officeScheduleByDow[$offId][$dayOfWeek]['start'];
                    } else {
                        $isOff = ! in_array($dayOfWeek, [1, 2, 3, 4, 5], true);
                    }
                }

                $att = $attendancesByUserAndDate[$user->id][$dateStr] ?? null;
                if ($att) {
                    $status = $att->status;
                    $type   = $att->check_in_type;

                    if ($filterStatus && $status !== $filterStatus) continue;
                    if ($filterType && $type !== $filterType) continue;
                    if ($filterSearch) {
                        if (!str_contains($user->search_name, $filterSearch) && !str_contains($user->search_code, $filterSearch)) {
                            continue;
                        }
                    }

                    $totalFiltered++;
                    $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
                    if ($type) {
                        $typeCounts[$type] = ($typeCounts[$type] ?? 0) + 1;
                    }
                    if ($att->working_minutes) {
                        $totalWorkingMinutes += (int) $att->working_minutes;
                    }
                    if ($att->overtime_minutes) {
                        $totalOvertimeMinutes += (int) $att->overtime_minutes;
                    }

                    if (!$isPaginated || ($totalFiltered > $offsetStart && $totalFiltered <= $offsetEnd)) {
                        $checkoutDate = $att->check_out_time ? substr($att->check_out_time, 0, 10) : null;
                        $isCrossDay = $checkoutDate && $checkoutDate > $dateStr;
                        $lateMinutes = null;
                        if ($status === 'late' && $att->check_in_time && $workStartTime) {
                            $inTimeStr = strlen($att->check_in_time) >= 16 ? substr($att->check_in_time, 11, 5) : null;
                            if ($inTimeStr) {
                                $schedMins = (int) substr($workStartTime, 0, 2) * 60 + (int) substr($workStartTime, 3, 2);
                                $inMins    = (int) substr($inTimeStr, 0, 2) * 60 + (int) substr($inTimeStr, 3, 2);
                                if ($inMins > $schedMins) {
                                    $lateMinutes = $inMins - $schedMins;
                                }
                            }
                        }

                        $pageItems[] = [
                            'id'               => $att->id,
                            'user_id'          => $att->user_id,
                            'user_name'        => $att->user_name,
                            'employee_code'    => $att->employee_code,
                            'department'       => $att->department,
                            'date'             => $dateStr,
                            'checkout_date'    => $checkoutDate,
                            'is_cross_day'     => $isCrossDay,
                            'check_in_time'    => $att->check_in_time,
                            'check_out_time'   => $att->check_out_time,
                            'check_in_type'    => $att->check_in_type,
                            'check_in_lat'     => $att->check_in_lat,
                            'check_in_lng'     => $att->check_in_lng,
                            'status'           => $status,
                            'late_minutes'     => $lateMinutes,
                            'overtime_minutes' => (int) ($att->overtime_minutes ?? 0),
                            'is_holiday'       => (bool) $att->is_holiday,
                            'working_minutes'  => $att->working_minutes,
                        ];
                    }
                } elseif (! $isFuture) {
                    $isHolidayForUser = $isRegularHoliday || isset($acceptedCollectiveLeaves[$user->id][$dateStr]);
                    if ($isHolidayForUser) {
                        $isOff = true;
                    }

                    $status = $isOff ? 'libur' : ($leaveLookup[$user->id][$dateStr] ?? 'absent');

                    if ($filterStatus && $status !== $filterStatus) continue;
                    if ($filterType) continue;
                    if ($filterSearch) {
                        if (!str_contains($user->search_name, $filterSearch) && !str_contains($user->search_code, $filterSearch)) {
                            continue;
                        }
                    }

                    $totalFiltered++;
                    $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;

                    if (!$isPaginated || ($totalFiltered > $offsetStart && $totalFiltered <= $offsetEnd)) {
                        $pageItems[] = [
                            'id'               => null,
                            'user_id'          => $user->id,
                            'user_name'        => $user->name,
                            'employee_code'    => $user->employee_code,
                            'department'       => $user->department,
                            'date'             => $dateStr,
                            'checkout_date'    => null,
                            'is_cross_day'     => false,
                            'check_in_time'    => null,
                            'check_out_time'   => null,
                            'check_in_type'    => null,
                            'check_in_lat'     => null,
                            'check_in_lng'     => null,
                            'status'           => $status,
                            'late_minutes'     => null,
                            'overtime_minutes' => 0,
                            'is_holiday'       => $isOff ? $isHolidayForUser : false,
                            'working_minutes'  => null,
                        ];
                    }
                }
            }
        }

        if ($isPaginated) {
            return [
                'summary' => [
                    'present'                => $statusCounts['present']     ?? 0,
                    'late'                   => $statusCounts['late']        ?? 0,
                    'absent'                 => $statusCounts['absent']      ?? 0,
                    'early_leave'            => $statusCounts['early_leave'] ?? 0,
                    'cuti'                   => $statusCounts['cuti']        ?? 0,
                    'izin'                   => $statusCounts['izin']        ?? 0,
                    'sakit'                  => $statusCounts['sakit']       ?? 0,
                    'total_working_minutes'  => $totalWorkingMinutes,
                    'total_overtime_minutes' => $totalOvertimeMinutes,
                ],
                'by_type' => [
                    'onsite' => $typeCounts['onsite'] ?? 0,
                    'wfh'    => $typeCounts['wfh']    ?? 0,
                    'field'  => $typeCounts['field']  ?? 0,
                ],
                'data'         => $pageItems,
                'total'        => $totalFiltered,
                'current_page' => $page,
                'per_page'     => $perPage,
                'last_page'    => (int) ceil($totalFiltered / max(1, $perPage)),
            ];
        }

        return $pageItems;
    }

    // 5. reportAttendance() — rekap presensi per periode (semua karyawan)
    public function reportAttendance(Request $request): JsonResponse
    {
        $actor = $request->user();

        $validated = $request->validate([
            'start_date' => 'nullable|date',
            'end_date'   => 'nullable|date|after_or_equal:start_date',
            'department' => 'nullable|string|max:100',
            'status'     => 'nullable|in:present,late,absent,early_leave,cuti,izin,sakit,wfh,libur',
            'type'       => 'nullable|in:onsite,wfh,field',
            'search'     => 'nullable|string|max:100',
            'office_id'  => 'nullable|integer',
        ]);

        $companyId = $actor->role === 'super_admin' ? null : $actor->company_id;
        $startDate = $validated['start_date'] ?? now()->startOfMonth()->toDateString();
        $endDate   = $validated['end_date']   ?? now()->toDateString();

        if (Carbon::parse($startDate)->diffInDays(Carbon::parse($endDate)) > 62) {
            return response()->json([
                'message' => 'Rentang tanggal maksimal 62 hari (2 bulan). Gunakan filter yang lebih sempit atau export CSV untuk data lebih lama.',
            ], 422);
        }

        $page    = max(1, (int) $request->query('page', 1));
        $perPage = 30;

        $result = $this->buildFullRows(
            $companyId,
            $startDate,
            $endDate,
            $validated['department'] ?? null,
            $validated['office_id'] ?? null,
            $validated,
            $page,
            $perPage
        );

        return response()->json([
            'summary' => $result['summary'],
            'by_type' => $result['by_type'],
            'report'  => [
                'data'         => $result['data'],
                'current_page' => $result['current_page'],
                'per_page'     => $result['per_page'],
                'total'        => $result['total'],
                'last_page'    => $result['last_page'],
            ],
        ]);
    }

    // 5c. exportReport() — export laporan presensi ke CSV
    public function exportReport(Request $request): StreamedResponse
    {
        $actor = $request->user();

        $validated = $request->validate([
            'start_date' => 'nullable|date',
            'end_date'   => 'nullable|date|after_or_equal:start_date',
            'department' => 'nullable|string|max:100',
            'status'     => 'nullable|in:present,late,absent,early_leave,cuti,izin,sakit,wfh,libur',
            'type'       => 'nullable|in:onsite,wfh,field',
            'search'     => 'nullable|string|max:100',
            'office_id'  => 'nullable|integer',
        ]);

        $companyId  = $actor->role === 'super_admin' ? null : $actor->company_id;
        $startDate  = $validated['start_date'] ?? now()->startOfMonth()->toDateString();
        $endDate    = $validated['end_date']   ?? now()->toDateString();

        if (Carbon::parse($startDate)->diffInDays(Carbon::parse($endDate)) > 62) {
            return response()->streamDownload(function () {
                echo 'Rentang tanggal maksimal 62 hari (2 bulan) per export.';
            }, 'error.txt', ['Content-Type' => 'text/plain']);
        }

        $rows = $this->buildFullRows(
            $companyId,
            $startDate,
            $endDate,
            $validated['department'] ?? null,
            $validated['office_id'] ?? null,
            $validated
        );

        $filename = 'laporan-presensi-' . now()->format('Ymd-His') . '.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['NIK', 'Nama', 'Departemen', 'Tanggal', 'Check In', 'Check Out', 'Tipe', 'Status', 'Telat (Menit)', 'Jam Kerja', 'Lembur', 'Hari Libur']);
            foreach ($rows as $r) {
                $mins     = $r['working_minutes'];
                $jamKerja = $mins !== null
                    ? floor($mins / 60) . 'j ' . ($mins % 60) . 'm'
                    : '-';
                $lembur = $this->formatMinutes((int) ($r['overtime_minutes'] ?? 0));
                $dateExport = $r['is_cross_day'] && $r['checkout_date']
                    ? Carbon::parse($r['date'])->format('d M') . ' - ' . Carbon::parse($r['checkout_date'])->format('d M Y')
                    : Carbon::parse($r['date'])->format('d M Y');

                fputcsv($out, [
                    $r['employee_code'] ?? '-',
                    $r['user_name'],
                    $r['department'] ?? '-',
                    $dateExport,
                    $r['check_in_time']  ? Carbon::parse($r['check_in_time'])->timezone('Asia/Jakarta')->format('H:i')  : '-',
                    $r['check_out_time'] ? Carbon::parse($r['check_out_time'])->timezone('Asia/Jakarta')->format('H:i') : '-',
                    $r['check_in_type'] ?? '-',
                    $r['status'],
                    $r['late_minutes'] !== null ? $r['late_minutes'] : '-',
                    $jamKerja,
                    $lembur,
                    $r['is_holiday'] ? 'Ya' : 'Tidak',
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    // ═══════════════════════════════════════════════════════════
    // BAGIAN A2 — CRUD pengaturan kantor (attendance_settings)
    //             HRD bisa punya >1 kantor per perusahaan
    // ═══════════════════════════════════════════════════════════

    // ─── Aturan validasi setting (dipakai store & update) ──────
    private function settingRules(bool $forUpdate = false): array
    {
        $req = $forUpdate ? 'sometimes' : 'required';

        return [
            'office_name'            => "{$req}|string|max:255",
            'office_latitude'        => "{$req}|numeric|between:-90,90",
            'office_longitude'       => "{$req}|numeric|between:-180,180",
            'radius_meters'          => 'sometimes|integer|min:1',
            'work_start_time'        => 'sometimes|date_format:H:i:s,H:i',
            'work_end_time'          => 'sometimes|date_format:H:i:s,H:i',
            // Maks 6 hari kerja — karyawan wajib mendapat min 1 hari libur/minggu (UU 13/2003 Pasal 79)
            'work_days'              => 'sometimes|array|min:1|max:6',
            'work_days.*'            => 'integer|between:0,6|distinct',
            'late_tolerance_minutes'         => 'sometimes|integer|min:0',
            'late_checkin_cutoff_minutes'     => 'sometimes|nullable|integer|min:0|max:1440',
            'require_selfie'             => 'sometimes|boolean',
            'allow_wfh'                  => 'sometimes|boolean',
            'wfh_checkin_window_minutes' => 'sometimes|nullable|integer|min:0|max:720',
            'overtime_enabled'               => 'sometimes|boolean',
            'min_overtime_minutes'           => 'sometimes|integer|min:0|max:480',
            'early_leave_tolerance_minutes'  => 'sometimes|nullable|integer|min:0|max:480',
            'min_checkout_interval_minutes'  => 'sometimes|nullable|integer|min:0|max:480',
            'checkout_reminder_minutes'      => 'sometimes|integer|min:5|max:120',
            'auto_checkout_grace_minutes'    => 'sometimes|integer|min:30|max:240',
            // Validasi jam kerja mingguan (opsional, bisa di-toggle per kantor)
            'enforce_weekly_hours'           => 'sometimes|boolean',
            'max_weekly_hours'               => 'sometimes|nullable|integer|min:40|max:168',
            'shift_notice_days'              => 'sometimes|integer|min:0|max:14',
            // Kebijakan saldo cuti per kantor: kuota default & tanggal reset tahunan.
            // leave_reset_date = anniversary 'MM-DD' (tanpa tahun, ulang tiap tahun).
            'default_leave_quota'            => "{$req}|integer|min:0|max:365",
            'leave_reset_date'               => 'sometimes|nullable|date_format:m-d',
            // Kebijakan saldo cuti bersama TIDAK DIPAKAI lagi (di-hardcode 'block' sejak 2026-08-20)
            // Validasi custom_schedules (override per hari)
            'custom_schedules'               => 'sometimes|nullable|array',
            'custom_schedules.*.start'       => 'required_with:custom_schedules|date_format:H:i',
            'custom_schedules.*.end'         => 'required_with:custom_schedules|date_format:H:i',
        ];
    }

    // Pesan validasi kustom untuk settingRules()
    private function settingMessages(): array
    {
        return [
            'work_days.max'        => 'Hari kerja maksimal 6 hari per minggu. Karyawan wajib mendapat minimal 1 hari libur per minggu (UU No. 13/2003 Pasal 79).',
            'work_days.min'        => 'Hari kerja minimal 1 hari per minggu.',
            'work_days.*.distinct' => 'Setiap hari kerja hanya boleh dipilih satu kali.',
            'work_days.*.between'  => 'Nilai hari kerja tidak valid (0=Minggu hingga 6=Sabtu).',
            'custom_schedules.*.start.date_format' => 'Format jam masuk khusus harus HH:MM.',
            'custom_schedules.*.end.date_format'   => 'Format jam pulang khusus harus HH:MM.',
            'default_leave_quota.max' => 'Saldo cuti default maksimal 365 hari.',
            'leave_reset_date.date_format' => 'Format tanggal reset saldo cuti harus MM-DD (contoh: 01-01).',
        ];
    }

    // 10. listSettings() — daftar kantor perusahaan
    public function listSettings(Request $request): JsonResponse
    {
        $actor = $request->user();

        $settings = AttendanceSetting::when(
            $actor->role !== 'super_admin',
            fn ($q) => $q->where('company_id', $actor->company_id)
        )->orderBy('office_name')->get();

        return response()->json(['settings' => $settings]);
    }

    // 11. storeSettings() — tambah kantor baru
    public function storeSettings(Request $request): JsonResponse
    {
        $validated = $request->validate($this->settingRules(), $this->settingMessages());

        // Aturan Emas: grace > reminder (sama seperti updateSettings)
        $effReminder = $validated['checkout_reminder_minutes'] ?? 30;
        $effGrace    = $validated['auto_checkout_grace_minutes'] ?? 60;
        if ((int) $effGrace <= (int) $effReminder) {
            return response()->json([
                'message' => 'Menit auto-checkout (grace) harus LEBIH BESAR dari menit reminder checkout. ' .
                    "Reminder {$effReminder} mnt, auto-checkout {$effGrace} mnt setelah jam pulang.",
            ], 422);
        }

        // Validasi toleransi telat tidak boleh lebih besar dari batas waktu presensi telat
        $effLateTolerance = $validated['late_tolerance_minutes'] ?? 15;
        $effCutoff        = array_key_exists('late_checkin_cutoff_minutes', $validated) ? $validated['late_checkin_cutoff_minutes'] : null;
        if ($effCutoff !== null && (int) $effLateTolerance > (int) $effCutoff) {
            return response()->json([
                'message' => 'Toleransi telat tidak boleh lebih besar dari batas waktu presensi telat (cutoff). ' .
                    "Toleransi telat {$effLateTolerance} mnt, batas waktu presensi {$effCutoff} mnt.",
            ], 422);
        }

        $actor = $request->user();
        // super_admin boleh menentukan company_id; lainnya pakai milik sendiri
        $companyId = $actor->company_id;
        if ($actor->role === 'super_admin' && $request->filled('company_id')) {
            $request->validate(['company_id' => 'integer|exists:companies,id']);
            $companyId = (int) $request->company_id;
        }

        if (! $companyId) {
            return response()->json(['message' => 'company_id wajib ditentukan.'], 422);
        }

        // Buang nilai null agar default kolom tetap berlaku.
        // Khusus leave_reset_date: null eksplisit boleh (berarti tanpa reset otomatis).
        $data = array_filter($validated, fn ($v) => $v !== null);
        if ($validated['leave_reset_date'] ?? null) {
            $data['leave_reset_date'] = $validated['leave_reset_date'];
        }
        $data['company_id'] = $companyId;

        $setting = AttendanceSetting::create($data);

        $this->logActivity($actor->id, $companyId, 'attendance_setting_created', "Tambah kantor {$setting->office_name}", 'attendance_setting', $setting->id);

        return response()->json([
            'message' => 'Pengaturan kantor berhasil dibuat.',
            'setting' => $setting->fresh(), // muat default kolom (radius, jam kerja, dll.)
        ], 201);
    }

    // 12. showSettings() — detail satu kantor
    //     (CompanyMiddleware sudah memvalidasi company_id model binding)
    public function showSettings(AttendanceSetting $attendanceSetting): JsonResponse
    {
        return response()->json(['setting' => $attendanceSetting]);
    }

    // 13. updateSettings() — ubah kantor
    //
    // PENGAMAN PERUBAHAN MENDADAK (2026-08-26, lihat doc/rules.md):
    // Bila HRD mengubah FIELD BERBAHAYA (jam kerja, GPS/radius, toleransi,
    // auto-checkout, lembur), request WAJIB menyertakan confirm_dangerous = "SIMPAN".
    // Frontend wajib menampilkan dialog peringatan + input ketik "SIMPAN" sebelum
    // mengirim ulang payload. Tujuan: mencegah salah tekan/salah paham dampak —
    // kerusakan data hari ini sendiri sudah dicegah oleh snapshot check-in.
    public function updateSettings(Request $request, AttendanceSetting $attendanceSetting): JsonResponse
    {
        $validated = $request->validate($this->settingRules(forUpdate: true), $this->settingMessages());

        // Buang nilai null agar field yang tidak dikirim tidak ikut ter-update.
        // Khusus leave_reset_date: null eksplisit berarti HRD menghapus jadwal reset otomatis.
        $data = array_filter($validated, fn ($v) => $v !== null);
        if (array_key_exists('leave_reset_date', $validated) && $validated['leave_reset_date'] === null) {
            $data['leave_reset_date']      = null;
            $data['last_leave_reset_on']   = null; // reset ulang riwayat agar jadwal baru bisa diproses
        }

        // ─── Deteksi perubahan field berbahaya & gerbang konfirmasi ───
        $changedDangerous = [];
        foreach ($this->dangerousSettingFields() as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }
            // Pakai accessor ter-cast agar array/bool/angka dibandingkan konsisten
            if (! $this->settingValueEquals($attendanceSetting->{$field}, $data[$field])) {
                $changedDangerous[] = $field;
            }
        }

        if ($changedDangerous && $request->input('confirm_dangerous') !== 'SIMPAN') {
            return response()->json([
                'message' => 'Perubahan aturan presensi terdeteksi pada: ' . implode(', ', $changedDangerous) . '. ' .
                    'Perubahan ini mempengaruhi karyawan yang BELUM check-in hari ini dan seluruh presensi esok hari. ' .
                    "Kirim ulang dengan confirm_dangerous = \"SIMPAN\" untuk melanjutkan.",
                'requires_confirmation'   => true,
                'confirmation_phrase'     => 'SIMPAN',
                'dangerous_changed_fields' => $changedDangerous,
            ], 422);
        }

        // Aturan Emas (lihat doc/rules.md): grace period HARUS lebih besar dari reminder,
        // agar karyawan sempat menerima pengingat sebelum sistem menutup presensinya.
        // Nilai efektif = input baru, fallback ke nilai tersimpan bila field tidak dikirim.
        $effReminder = $validated['checkout_reminder_minutes']
            ?? ($attendanceSetting->checkout_reminder_minutes ?? 30);
        $effGrace    = $validated['auto_checkout_grace_minutes']
            ?? ($attendanceSetting->auto_checkout_grace_minutes ?? 60);
        if ((int) $effGrace <= (int) $effReminder) {
            return response()->json([
                'message' => 'Menit auto-checkout (grace) harus LEBIH BESAR dari menit reminder checkout. ' .
                    "Reminder {$effReminder} mnt, auto-checkout {$effGrace} mnt setelah jam pulang.",
            ], 422);
        }

        // Validasi toleransi telat tidak boleh lebih besar dari batas waktu presensi telat
        $effLateTolerance = $validated['late_tolerance_minutes']
            ?? ($attendanceSetting->late_tolerance_minutes ?? 15);
        $effCutoff = array_key_exists('late_checkin_cutoff_minutes', $validated)
            ? $validated['late_checkin_cutoff_minutes']
            : $attendanceSetting->late_checkin_cutoff_minutes;
        if ($effCutoff !== null && (int) $effLateTolerance > (int) $effCutoff) {
            return response()->json([
                'message' => 'Toleransi telat tidak boleh lebih besar dari batas waktu presensi telat (cutoff). ' .
                    "Toleransi telat {$effLateTolerance} mnt, batas waktu presensi {$effCutoff} mnt.",
            ], 422);
        }

        $attendanceSetting->update($data);

        $this->logActivity(
            $request->user()->id,
            $attendanceSetting->company_id,
            'attendance_setting_updated',
            "Update kantor {$attendanceSetting->office_name}"
                . ($changedDangerous ? ' [aturan presensi: ' . implode(', ', $changedDangerous) . ']' : ''),
            'attendance_setting',
            $attendanceSetting->id
        );

        // Notifikasi ke semua HRD/admin/super_admin perusahaan agar sadar ada perubahan aturan
        $hrds = DB::table('users')
            ->where('company_id', $attendanceSetting->company_id)
            ->whereIn('role', ['hrd', 'admin', 'super_admin'])
            ->where('is_active', true)
            ->pluck('id');

        $pesanEfektif = $changedDangerous
            ? ' Berlaku bagi presensi berikutnya (presensi yang sudah check-in tetap memakai aturan saat check-in).'
            : '';
        foreach ($hrds as $hrdId) {
            $this->notifyUser($hrdId, 'attendance_setting_updated', [
                'message'      => "Pengaturan kantor '{$attendanceSetting->office_name}' telah diubah oleh {$request->user()->name}.{$pesanEfektif}",
                'office_name'  => $attendanceSetting->office_name,
                'office_id'    => $attendanceSetting->id,
                'updated_by'   => $request->user()->name,
                'dangerous_changed_fields' => $changedDangerous,
            ], 'attendance_setting', $attendanceSetting->id);
        }

        return response()->json([
            'message' => $changedDangerous
                ? 'Pengaturan kantor berhasil diperbarui. Perubahan aturan presensi (' . implode(', ', $changedDangerous) . ') berlaku bagi karyawan yang belum check-in & seluruh presensi esok hari.'
                : 'Pengaturan kantor berhasil diperbarui.',
            'setting'                  => $attendanceSetting->fresh(),
            'dangerous_changed_fields' => $changedDangerous,
        ]);
    }

    // ─── Daftar field pengaturan yang berdampak langsung ke presensi berjalan ────
    private function dangerousSettingFields(): array
    {
        return [
            'work_start_time',
            'work_end_time',
            'work_days',
            'custom_schedules',
            'office_latitude',
            'office_longitude',
            'radius_meters',
            'late_tolerance_minutes',
            'late_checkin_cutoff_minutes',
            'early_leave_tolerance_minutes',
            'min_checkout_interval_minutes',
            'overtime_enabled',
            'min_overtime_minutes',
            'checkout_reminder_minutes',
            'auto_checkout_grace_minutes',
        ];
    }

    // ─── Bandingkan nilai lama vs baru secara normalisasi ────
    // Jam '08:00' vs '08:00:00' dianggap sama; angka dibandingkan numerik;
    // array (work_days / custom_schedules) dibandingkan setelah dinormalisasi.
    private function settingValueEquals(mixed $old, mixed $new): bool
    {
        // Normalisasi jam (H:i atau H:i:s)
        $asTime = function ($v): ?string {
            if ($v === null || ! is_string($v) || ! preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $v)) {
                return null;
            }
            return Carbon::parse($v)->format('H:i');
        };

        if (($t = $asTime($old)) !== null && ($t2 = $asTime($new)) !== null) {
            return $t === $t2;
        }

        // Angka
        if (is_numeric($old) && is_numeric($new)) {
            return (int) $old === (int) $new || (float) $old === (float) $new;
        }

        // Boolean (termasuk campuran bool vs 0/1)
        if (is_bool($old) || is_bool($new)) {
            return (bool) $old === (bool) $new;
        }

        // Array (work_days, custom_schedules)
        if (is_array($old) || is_array($new)) {
            $normalize = function ($arr) use (&$normalize) {
                if (! is_array($arr)) {
                    // Normalisasi string berformat jam ke H:i agar '08:00' == '08:00:00'
                    return is_string($arr) && preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $arr)
                        ? Carbon::parse($arr)->format('H:i')
                        : $arr;
                }
                $out = [];
                foreach ($arr as $k => $v) {
                    $out[$k] = $normalize($v);
                }
                if (array_is_list($out)) {
                    sort($out);
                } else {
                    ksort($out);
                }
                return $out;
            };
            return json_encode($normalize(is_array($old) ? $old : [$old]))
                === json_encode($normalize(is_array($new) ? $new : [$new]));
        }

        return (string) $old === (string) $new;
    }

    // 14. destroySettings() — hapus kantor
    public function destroySettings(Request $request, AttendanceSetting $attendanceSetting): JsonResponse
    {
        $companyId = $attendanceSetting->company_id;
        $name      = $attendanceSetting->office_name;
        $id        = $attendanceSetting->id;

        $attendanceSetting->delete();

        $this->logActivity($request->user()->id, $companyId, 'attendance_setting_deleted', "Hapus kantor {$name}", 'attendance_setting', $id);

        return response()->json(['message' => 'Pengaturan kantor berhasil dihapus.']);
    }

    // ═══════════════════════════════════════════════════════════
    // BAGIAN A3 — Kalender libur nasional (holidays)
    // ═══════════════════════════════════════════════════════════

    // listHolidays() — daftar libur (nasional + milik perusahaan), filter tahun opsional
    public function listHolidays(Request $request): JsonResponse
    {
        $user      = $request->user();
        $companyId = $user->company_id;
        $year      = $request->query('year', now('Asia/Jakarta')->year);

        $holidays = Holiday::with(['office:id,office_name', 'excludedUsers:id,name,employee_code,attendance_setting_id'])
            ->where(function ($q) use ($companyId) {
                $q->whereNull('company_id')->orWhere('company_id', $companyId);
            })
            ->whereYear('date', $year)
            ->orderBy('date')
            ->get(['id', 'company_id', 'attendance_setting_id', 'date', 'name', 'is_national', 'is_collective'])
            ->map(function ($h) use ($companyId) {
                $item = [
                    'id'                    => $h->id,
                    'company_id'            => $h->company_id,
                    'date'                  => $h->date->toDateString(),
                    'name'                  => $h->name,
                    'is_national'           => $h->is_national,
                    'is_collective'         => (bool) $h->is_collective,
                    'attendance_setting_id' => $h->attendance_setting_id,
                    'office_name'           => $h->office?->office_name,
                    'scope'                 => $h->company_id ? ($h->attendance_setting_id ? 'cabang' : 'perusahaan') : 'nasional',
                    'excluded_users'        => $h->excludedUsers->map(function ($u) use ($h) {
                        $isManual = true;
                        $reasonType = 'manual';
                        $reasonLabel = 'Pengecualian Manual HRD';
                        $reasonDetail = 'Dikecualikan manual oleh HRD';

                        if ($h->is_collective) {
                            $hYear = (int) $h->date->year;
                            $hDateStr = $h->date->toDateString();

                            $lb = \App\Models\LeaveBalance::where('user_id', $u->id)
                                ->where('year', $hYear)
                                ->where('leave_type', 'cuti')
                                ->first();

                            $quota = $lb ? (int) $lb->quota : 0;
                            $used = $lb ? (int) $lb->used : 0;
                            $remaining = $quota - $used;

                            if ($quota <= 0) {
                                $isManual = false;
                                $reasonType = 'inactive_leave';
                                $reasonLabel = 'Cuti Nonaktif (0 Hari)';
                                $reasonDetail = 'Kuota cuti tahunan belum diaktifkan (0 hari)';
                            } elseif ($remaining <= 0) {
                                $isManual = false;
                                $reasonType = 'quota_exhausted';
                                $reasonLabel = 'Sisa Kuota Habis';
                                $reasonDetail = "Sisa kuota {$remaining} hari";
                            } else {
                                $hasLeave = \App\Models\LeaveRequest::where('user_id', $u->id)
                                    ->where('status', 'approved')
                                    ->whereNull('holiday_id')
                                    ->where('start_date', '<=', $hDateStr)
                                    ->where('end_date', '>=', $hDateStr)
                                    ->exists();
                                if ($hasLeave) {
                                    $isManual = false;
                                    $reasonType = 'existing_leave';
                                    $reasonLabel = 'Sudah Cuti/Izin';
                                    $reasonDetail = 'Sudah memiliki cuti/izin/sakit yang disetujui';
                                } else {
                                    $uModel = User::find($u->id);
                                    $userDays = $uModel ? $this->countWorkingDays($h->date, $h->date, $h->company_id, $h->attendance_setting_id, $u->id, $uModel) : 1;
                                    if ($userDays < 1) {
                                        $isManual = false;
                                        $reasonType = 'shift_off';
                                        $reasonLabel = 'Libur Shift';
                                        $reasonDetail = 'Hari libur menurut jadwal shift kerja';
                                    }
                                }
                            }
                        }

                        return [
                            'id'                    => $u->id,
                            'name'                  => $u->name,
                            'employee_code'         => $u->employee_code,
                            'attendance_setting_id' => $u->attendance_setting_id,
                            'is_manual'             => $isManual,
                            'reason_type'           => $reasonType,
                            'reason_label'          => $reasonLabel,
                            'reason_detail'         => $reasonDetail,
                        ];
                    }),
                ];

                // Untuk cuti bersama: sertakan rekap status opt-in karyawan.
                // FIX (2026-08-25): angka diturunkan dari leave_requests NYATA yang dibuat
                // createCollectiveLeaveRequests() — yang hanya untuk karyawan dijadwalkan
                // KERJA pada tanggal tsb (shift-aware). Sebelumnya "pending" dihitung dari
                // total karyawan aktif cabang → karyawan yang hari itu libur shift tetap
                // terhitung pending, tidak sinkron dengan banner mobile (kasus: cuti bersama
                // Minggu #81, pending=3 padahal hanya Budi yang jadwalnya masuk).
                if ($h->is_collective && $h->company_id) {
                    $counts = \App\Models\LeaveRequest::where('holiday_id', $h->id)
                        ->selectRaw('collective_status, count(*) as total')
                        ->groupBy('collective_status')
                        ->pluck('total', 'collective_status');

                    $accepted   = (int) ($counts['accepted'] ?? 0);
                    $declined   = (int) ($counts['declined'] ?? 0);
                    $totalRekap = array_sum($counts->toArray());

                    $item['collective_summary'] = [
                        'accepted' => $accepted,
                        'declined' => $declined,
                        'pending'  => (int) ($counts['pending'] ?? 0),
                        'total'    => $totalRekap,
                    ];
                }

                return $item;
            });

        return response()->json(['year' => (int) $year, 'holidays' => $holidays]);
    }

    // storeHolidays() — tambah libur, mendukung 3 tipe:
    //   nasional    → berlaku semua perusahaan (company_id = NULL, is_national = true)
    //   collective  → cuti bersama (potong saldo, karyawan pilih ikut/tidak)
    //   perusahaan  → khusus perusahaan/cabang (tanpa potong saldo)
    public function storeHolidays(Request $request): JsonResponse
    {
        $user      = $request->user();
        $companyId = $user->company_id;

        $validated = $request->validate([
            'date'                  => 'required|date',
            'name'                  => 'required|string|max:255',
            'type'                  => 'nullable|in:nasional,collective,perusahaan',
            'is_collective'         => 'boolean', // backward compat: form lama hanya kirim boolean ini
            'attendance_setting_id' => 'nullable|integer|exists:attendance_settings,id',
            'excluded_user_ids'     => 'nullable|array',
            'excluded_user_ids.*'   => 'integer|exists:users,id',
        ]);

        $date = Carbon::parse($validated['date'])->toDateString();
        $type = $validated['type'] ?? null;

        // Backward compat: jika type tidak dikirim, turunkan dari is_collective (form lama).
        $isNational   = $type === 'nasional';
        $isCollective = $type !== null ? $type === 'collective' : (bool) ($validated['is_collective'] ?? false);

        // 1. Guard hak akses:
        // - Libur nasional: hanya super_admin
        if ($isNational && $user->role !== 'super_admin') {
            return response()->json(['message' => 'Hanya super admin yang berwenang menambahkan hari libur nasional.'], 403);
        }

        // - Libur / Cuti Bersama untuk SEMUA cabang (attendance_setting_id kosong): hanya super_admin
        if (! $isNational && empty($validated['attendance_setting_id']) && $user->role !== 'super_admin') {
            return response()->json(['message' => 'Hanya Super Admin yang berwenang mengatur libur / cuti bersama untuk semua cabang.'], 403);
        }

        // Libur nasional tidak terikat perusahaan/cabang → company_id NULL.
        $holidayCompanyId = $isNational ? null : $companyId;
        $officeId         = $isNational
            ? null
            : (! empty($validated['attendance_setting_id']) ? (int) $validated['attendance_setting_id'] : null);

        // Jika cabang diset, pastikan cabang milik perusahaan user
        if ($officeId && $user->role !== 'super_admin') {
            $validOffice = \App\Models\AttendanceSetting::where('id', $officeId)
                ->where('company_id', $companyId)
                ->exists();
            if (! $validOffice) {
                return response()->json(['message' => 'Cabang kantor tidak ditemukan di perusahaan Anda.'], 404);
            }
        }

        // Validasi: Tidak bisa tambah libur nasional jika ada cuti bersama di tanggal tersebut
        if ($isNational) {
            $conflictingHolidays = Holiday::whereDate('date', $date)
                ->where('is_collective', true)
                ->with('office')
                ->get();

            if ($conflictingHolidays->isNotEmpty()) {
                $officeNames = $conflictingHolidays->map(function($h) {
                    return $h->office ? $h->office->office_name : 'Semua Cabang (Kantor Pusat)';
                })->unique()->implode(', ');

                $formattedDate = \Carbon\Carbon::parse($date)->format('d-m-Y');
                return response()->json([
                    'message' => "Tidak bisa menambahkan libur nasional pada tanggal {$formattedDate}, di karenakan kantor {$officeNames} sedang mengadakan cuti bersama, hubungi pihak kantor agar menghapus cuti bersama pada tanggal tersebut"
                ], 422);
            }
        }

        // Cegah duplikat pada scope yang sama (nasional vs perusahaan/cabang).
        $exists = Holiday::whereDate('date', $date)
            ->where('company_id', $holidayCompanyId)
            ->where(fn ($q) => $q->where('attendance_setting_id', $officeId))
            ->exists();

        if ($exists) {
            return response()->json(['message' => 'Tanggal libur tersebut sudah terdaftar pada scope yang dipilih.'], 422);
        }

        // Jika menambah libur nasional, timpa/hapus libur perusahaan biasa (is_collective = false) HANYA untuk libur nasional lama/perusahaan aktor
        // (PERBAIKAN MULTI-TENANT LEAK: jangan hapus libur perusahaan tenant lain)
        if ($isNational) {
            Holiday::whereDate('date', $date)
                ->where('is_collective', false)
                ->where(function ($q) use ($companyId) {
                    $q->whereNull('company_id')
                        ->orWhere('company_id', $companyId);
                })
                ->delete();
        }

        $holiday = Holiday::create([
            'company_id'            => $holidayCompanyId,
            'attendance_setting_id' => $officeId,
            'date'                  => $date,
            'name'                  => $validated['name'],
            'is_national'           => $isNational,
            'is_collective'         => $isCollective,
        ]);

        $manualExcludedIds = ! empty($validated['excluded_user_ids']) ? array_map('intval', $validated['excluded_user_ids']) : [];
        if (! empty($manualExcludedIds)) {
            $holiday->excludedUsers()->sync($manualExcludedIds);
        }

        // Jika cuti bersama: buat leave_request pending untuk karyawan aktif di cabang/perusahaan ini.
        // Karyawan yang sudah punya leave approved overlap akan otomatis dikecualikan (dikembalikan sebagai warning).
        $autoExcluded = [];
        if ($isCollective && ! $isNational) {
            $autoExcluded = $this->createCollectiveLeaveRequests($holiday, $companyId, $manualExcludedIds);
        }

        // Jika libur nasional / libur cabang (non-collective): kembalikan saldo cuti karyawan
        // yang sudah punya cuti approved di tanggal ini (mereka jadi libur gratis).
        $compensated = [];
        if (! $isCollective) {
            $compensated = $this->compensateApprovedLeavesForHoliday($holiday, $companyId, $isNational);
        }

        $logDesc = $isCollective
            ? "Tambah cuti bersama {$holiday->name} ({$date})"
            : ($isNational
                ? "Tambah libur nasional {$holiday->name} ({$date})"
                : "Tambah libur {$holiday->name} ({$date})");
        $this->logActivity($user->id, $companyId, 'holiday_created', $logDesc, 'holiday', $holiday->id);

        return response()->json([
            'message' => $isCollective
                ? 'Cuti bersama berhasil ditambahkan. Karyawan akan menerima notifikasi.'
                : ($isNational
                    ? 'Libur nasional berhasil ditambahkan.'
                    : 'Hari libur berhasil ditambahkan.'),
            'holiday' => [
                'id'                    => $holiday->id,
                'date'                  => $date,
                'name'                  => $holiday->name,
                'is_national'           => $isNational,
                'is_collective'         => $isCollective,
                'attendance_setting_id' => $holiday->attendance_setting_id,
                'office_name'           => $holiday->office?->office_name,
                'scope'                 => $holiday->company_id ? ($holiday->attendance_setting_id ? 'cabang' : 'perusahaan') : 'nasional',
            ],
            'warnings' => [
                // Karyawan yang dikecualikan otomatis dari cuti bersama (sudah punya leave approved)
                'auto_excluded'       => $autoExcluded,
                // Karyawan yang saldo cutinya dikembalikan karena libur nasional/cabang
                'balance_restored'    => $compensated,
            ],
        ], 201);
    }

    // updateHolidays() — ubah libur (nasional & perusahaan)
    public function updateHolidays(Request $request, Holiday $holiday): JsonResponse
    {
        $user = $request->user();

        // 1. Guard hak akses edit libur nasional: hanya super_admin yang berwenang mengubah libur nasional
        if (($holiday->company_id === null || $holiday->is_national) && $user->role !== 'super_admin') {
            return response()->json(['message' => 'Hanya super admin yang berwenang mengubah hari libur nasional.'], 403);
        }

        // Libur perusahaan lain tidak boleh diubah
        if ($holiday->company_id !== null && $holiday->company_id !== $user->company_id) {
            return response()->json(['message' => 'Libur tidak ditemukan di perusahaan Anda.'], 403);
        }

        $validated = $request->validate([
            'date'                  => 'required|date',
            'name'                  => 'required|string|max:255',
            'type'                  => 'nullable|in:nasional,collective,perusahaan',
            'attendance_setting_id' => 'nullable|integer|exists:attendance_settings,id',
            'excluded_user_ids'     => 'nullable|array',
            'excluded_user_ids.*'   => 'integer|exists:users,id',
        ]);

        $newDate = Carbon::parse($validated['date'])->toDateString();
        $type    = $validated['type'] ?? null;

        // Tipe tujuan (backward compat: tanpa type, pertahankan tipe yang sudah ada).
        $isNational   = $type !== null ? $type === 'nasional' : ($holiday->is_national ?? false);
        $isCollective = $type !== null ? $type === 'collective' : (bool) ($holiday->is_collective ?? false);

        // Hindari perubahan yang melintasi scope nasional ↔ perusahaan/cabang.
        // Libur nasional adalah data global (company_id NULL) milik semua perusahaan;
        // mengubahnya lewat edit berisiko salah ubah data lintas-perusahaan.
        // Solusi yang aman: hapus & buat ulang.
        if ($isNational !== (bool) $holiday->is_national) {
            return response()->json(['message' => 'Tipe libur tidak dapat diubah antara libur nasional dan libur biasa. Hapus lalu buat ulang.'], 422);
        }

        // 1. Guard hak akses edit:
        if ($isNational && $user->role !== 'super_admin') {
            return response()->json(['message' => 'Hanya Super Admin yang berwenang mengubah hari libur nasional.'], 403);
        }

        // Libur / Cuti Bersama untuk SEMUA cabang (attendance_setting_id kosong): hanya super_admin
        if (! $isNational && empty($validated['attendance_setting_id']) && $user->role !== 'super_admin') {
            return response()->json(['message' => 'Hanya Super Admin yang berwenang mengatur libur / cuti bersama untuk semua cabang.'], 403);
        }

        // Libur nasional tidak terikat cabang; perusahaan/collective memakai company user.
        $holidayCompanyId = $isNational ? null : $user->company_id;
        $officeId = $isNational
            ? null
            : (! empty($validated['attendance_setting_id']) ? (int) $validated['attendance_setting_id'] : null);

        if ($officeId && $user->role !== 'super_admin') {
            $validOffice = \App\Models\AttendanceSetting::where('id', $officeId)
                ->where('company_id', $user->company_id)
                ->exists();
            if (! $validOffice) {
                return response()->json(['message' => 'Cabang kantor tidak ditemukan di perusahaan Anda.'], 404);
            }
        }

        // Validasi: Tidak bisa ubah tanggal libur nasional jika ada cuti bersama di tanggal tujuan
        if ($isNational && $newDate !== $holiday->date->toDateString()) {
            $conflictingHolidays = Holiday::whereDate('date', $newDate)
                ->where('is_collective', true)
                ->where('id', '!=', $holiday->id)
                ->with('office')
                ->get();

            if ($conflictingHolidays->isNotEmpty()) {
                $officeNames = $conflictingHolidays->map(function($h) {
                    return $h->office ? $h->office->office_name : 'Semua Cabang (Kantor Pusat)';
                })->unique()->implode(', ');
                
                $formattedDate = \Carbon\Carbon::parse($newDate)->format('d-m-Y');
                return response()->json([
                    'message' => "Tidak bisa menambahkan libur nasional pada tanggal {$formattedDate}, di karenakan kantor {$officeNames} sedang mengadakan cuti bersama, hubungi pihak kantor agar menghapus cuti bersama pada tanggal tersebut"
                ], 422);
            }
        }

        // Cegah duplikat: jika mengubah tanggal / tipe / cabang
        $oldOfficeId = $holiday->attendance_setting_id;
        $oldIsCollective = (bool) $holiday->is_collective;
        if ($newDate !== $holiday->date->toDateString() || $officeId !== $oldOfficeId || $isNational !== (bool) $holiday->is_national) {
            $exists = Holiday::whereDate('date', $newDate)
                ->where('company_id', $holidayCompanyId)
                ->where(fn ($q) => $q->where('attendance_setting_id', $officeId))
                ->where('id', '!=', $holiday->id)
                ->exists();

            if ($exists) {
                return response()->json(['message' => 'Tanggal libur tersebut sudah terdaftar pada scope yang dipilih.'], 422);
            }
        }

        $oldName = $holiday->name;
        $oldDate = $holiday->date->toDateString();

        // Jika mengupdate libur nasional, timpa/hapus libur perusahaan biasa (is_collective = false) di tanggal tujuan
        // HANYA untuk libur nasional lama atau libur perusahaan aktor (mencegah leak multi-tenant)
        if ($isNational) {
            Holiday::whereDate('date', $newDate)
                ->where('is_collective', false)
                ->where(function ($q) use ($user) {
                    $q->whereNull('company_id')
                        ->orWhere('company_id', $user->company_id);
                })
                ->where('id', '!=', $holiday->id)
                ->delete();
        }

        $holiday->update([
            'company_id'            => $holidayCompanyId,
            'attendance_setting_id' => $officeId,
            'date'                  => $newDate,
            'name'                  => $validated['name'],
            'is_national'           => $isNational,
            'is_collective'         => $isCollective,
        ]);

        $manualExcludedIds = $validated['excluded_user_ids'] ?? [];

        // Re-sync cuti bersama jika tipe/cabang/tanggal berubah (termasuk berubah menjadi/keluar dari cuti bersama)
        $scopeChanged = $officeId !== $oldOfficeId || $newDate !== $oldDate || $isCollective !== $oldIsCollective;
        if ($scopeChanged) {
            $this->cancelAllCollectiveRequests($holiday);
            if ($isCollective && ! $isNational) {
                $holiday->excludedUsers()->sync($manualExcludedIds);
                $this->createCollectiveLeaveRequests($holiday, $user->company_id, $manualExcludedIds);
            } else {
                // Berubah menjadi libur biasa (non-collective / nasional): sync exclusion murni dari input manual HRD
                $holiday->excludedUsers()->sync($manualExcludedIds);
            }
        } elseif ($isCollective && ! $isNational) {
            // Scope tanggal & cabang tidak berubah: sync exclusion manual vs auto
            $this->syncCollectiveLeaveExclusions($holiday, $user->company_id, $manualExcludedIds);
        } else {
            if (array_key_exists('excluded_user_ids', $validated)) {
                $holiday->excludedUsers()->sync($manualExcludedIds);
            }
        }

        $scope = $holiday->company_id ? $user->company_id : null;
        $this->logActivity(
            $user->id,
            $scope,
            'holiday_updated',
            "Update libur dari {$oldName} ({$oldDate}) ke {$holiday->name} ({$newDate})",
            'holiday',
            $holiday->id
        );

        return response()->json([
            'message' => 'Hari libur berhasil diubah.',
            'holiday' => [
                'id'                    => $holiday->id,
                'date'                  => $newDate,
                'name'                  => $holiday->name,
                'is_national'           => $holiday->is_national,
                'is_collective'         => (bool) $holiday->is_collective,
                'attendance_setting_id' => $holiday->attendance_setting_id,
                'office_name'           => $holiday->office?->office_name,
                'scope'                 => $holiday->company_id ? ($holiday->attendance_setting_id ? 'cabang' : 'perusahaan') : 'nasional',
            ],
        ]);
    }

    // destroyHolidays() — hapus libur nasional & libur perusahaan.
    public function destroyHolidays(Request $request, Holiday $holiday): JsonResponse
    {
        $user = $request->user();

        // 1. Guard hak akses hapus libur nasional: hanya super_admin yang berwenang
        if (($holiday->company_id === null || $holiday->is_national) && $user->role !== 'super_admin') {
            return response()->json(['message' => 'Hanya super admin yang berwenang menghapus hari libur nasional.'], 403);
        }

        // 2. Guard hak akses hapus libur semua cabang: hanya super_admin yang berwenang
        if ($holiday->attendance_setting_id === null && $user->role !== 'super_admin') {
            return response()->json(['message' => 'Hanya Super Admin yang berwenang menghapus libur / cuti bersama untuk semua cabang.'], 403);
        }

        // Libur perusahaan lain tidak boleh dihapus
        if ($holiday->company_id !== null && $holiday->company_id !== $user->company_id) {
            return response()->json(['message' => 'Libur tidak ditemukan di perusahaan Anda.'], 403);
        }

        $name = $holiday->name;
        $date = $holiday->date->toDateString();
        $id   = $holiday->id;

        // Jika ini cuti bersama: batalkan semua leave_request terkait & kembalikan saldo
        if ($holiday->is_collective) {
            $this->cancelAllCollectiveRequests($holiday);
        }

        // Untuk libur nasional/cabang (non-collective): kumpulkan cuti approved yang
        // saldo-nya PERNAH dikembalikan akibat libur ini (holiday_compensated_days > 0
        // dan overlap tanggal libur). Setelah libur dihapus, saldo mereka dipotong kembali.
        $affectedLeaveIds = [];
        if (! $holiday->is_collective) {
            $affectedLeaveIds = \App\Models\LeaveRequest::where('status', 'approved')
                ->where('leave_type', 'cuti')
                ->whereNull('holiday_id')
                ->where('holiday_compensated_days', '>', 0)
                ->where('start_date', '<=', $date)
                ->where('end_date', '>=', $date)
                ->pluck('id')
                ->toArray();
        }

        $holiday->delete();

        // Potong ulang saldo SETELAH holiday dihapus agar countWorkingDays tidak lagi
        // mengecualikan tanggal libur tersebut.
        if (! empty($affectedLeaveIds)) {
            $this->reapplyLeaveDeductionAfterHolidayRemoval($affectedLeaveIds, $user->company_id, $name, $date, $id);
        }

        $this->logActivity($user->id, $user->company_id, 'holiday_deleted', "Hapus libur {$name} ({$date})", 'holiday', $id);

        return response()->json(['message' => 'Hari libur berhasil dihapus.']);
    }

    // ═══════════════════════════════════════════════════════════
    // BAGIAN A4 — Cuti Bersama (Collective Leave)
    // ═══════════════════════════════════════════════════════════

    // previewCollectiveLeave() — kalkulasi preview & daftar pengecualian sebelum Cuti Bersama disimpan (HRD modal konfirmasi)
    //   POST /api/v1/dashboard/attendance/holidays/collective-preview
    public function previewCollectiveLeave(Request $request): JsonResponse
    {
        $actor = $request->user();
        $companyId = $actor->company_id;

        $validated = $request->validate([
            'holiday_id'            => 'nullable|integer|exists:holidays,id',
            'date'                  => 'required|date',
            'name'                  => 'required|string|max:255',
            'attendance_setting_id' => 'nullable|integer',
            'excluded_user_ids'     => 'nullable|array',
            'excluded_user_ids.*'   => 'integer',
        ]);

        $holidayId = $validated['holiday_id'] ?? null;
        $date = Carbon::parse($validated['date'])->toDateString();
        $officeId = ! empty($validated['attendance_setting_id']) ? (int) $validated['attendance_setting_id'] : null;
        $manualExcludedIds = $validated['excluded_user_ids'] ?? [];

        // 1. Ambil seluruh karyawan aktif dalam scope cabang/perusahaan
        $query = User::where('company_id', $companyId)
            ->where('is_active', true);

        if ($officeId) {
            $query->where('attendance_setting_id', $officeId);
        }

        $candidates = $query->with('office:id,office_name')->get(['id', 'name', 'department', 'attendance_setting_id', 'employee_code']);

        // 2. Ambil saldo cuti tahun ini
        $year = (int) Carbon::parse($date)->year;
        $leaveBalances = \App\Models\LeaveBalance::where('year', $year)
            ->where('leave_type', 'cuti')
            ->whereIn('user_id', $candidates->pluck('id'))
            ->get()
            ->keyBy('user_id');

        // 3. Ambil pengajuan cuti/izin pribadi yang sudah approved di tanggal tersebut
        $existingApproved = \App\Models\LeaveRequest::whereIn('user_id', $candidates->pluck('id'))
            ->where('status', 'approved')
            ->whereNull('holiday_id')
            ->where('start_date', '<=', $date)
            ->where('end_date', '>=', $date)
            ->get()
            ->keyBy('user_id');

        // Ambil data keikutsertaan yang sudah diterima di libur ini (jika sedang mengedit libur yang sudah ada)
        $thisHolidayAcceptedUsers = collect();
        if ($holidayId) {
            $thisHolidayAcceptedUsers = \App\Models\LeaveRequest::where('holiday_id', $holidayId)
                ->where('collective_status', 'accepted')
                ->pluck('total_days', 'user_id');
        }

        $typeLabels = ['wfh' => 'WFH', 'izin' => 'Izin', 'sakit' => 'Sakit', 'cuti' => 'Cuti Mandiri'];

        // 4. Prefetch model User penuh untuk countWorkingDays (shift-aware)
        $fullUsers = User::whereIn('id', $candidates->pluck('id'))->get()->keyBy('id');

        $excludedList = [];
        $eligibleList = [];

        foreach ($candidates as $u) {
            // A. Cuti Nonaktif / Kuota Cuti Habis
            $lb = $leaveBalances->get($u->id);
            $quota = $lb ? (int) $lb->quota : 0;
            $used  = $lb ? (int) $lb->used : 0;
            $holidayDeduction = (int) ($thisHolidayAcceptedUsers->get($u->id) ?? 0);
            $remaining = ($quota - $used) + $holidayDeduction;

            if ($quota <= 0) {
                $excludedList[] = [
                    'id'            => $u->id,
                    'name'          => $u->name,
                    'department'    => $u->department,
                    'employee_code' => $u->employee_code,
                    'office_name'   => $u->office?->office_name ?? 'Kantor Pusat',
                    'is_manual'     => false,
                    'reason_type'   => 'inactive_leave',
                    'reason_label'  => 'Cuti Nonaktif (0 Hari)',
                    'reason_detail' => 'Kuota cuti tahunan belum diaktifkan (0 hari)',
                ];
                continue;
            }

            if ($remaining <= 0) {
                $excludedList[] = [
                    'id'            => $u->id,
                    'name'          => $u->name,
                    'department'    => $u->department,
                    'employee_code' => $u->employee_code,
                    'office_name'   => $u->office?->office_name ?? 'Kantor Pusat',
                    'is_manual'     => false,
                    'reason_type'   => 'quota_exhausted',
                    'reason_label'  => 'Sisa Kuota Cuti Habis',
                    'reason_detail' => "Sisa kuota {$remaining} hari (kuota: {$quota}, terpakai: {$used})",
                ];
                continue;
            }

            // B. Sudah ada Cuti Mandiri / Izin / Sakit / WFH yang disetujui di tanggal tsb
            if ($existingApproved->has($u->id)) {
                $lr = $existingApproved->get($u->id);
                $label = $typeLabels[$lr->leave_type] ?? ucfirst($lr->leave_type);
                $startFmt = Carbon::parse($lr->start_date)->translatedFormat('d M Y');
                $endFmt = Carbon::parse($lr->end_date)->translatedFormat('d M Y');
                $dateRange = ($startFmt === $endFmt) ? $startFmt : "{$startFmt} – {$endFmt}";

                $excludedList[] = [
                    'id'            => $u->id,
                    'name'          => $u->name,
                    'department'    => $u->department,
                    'employee_code' => $u->employee_code,
                    'office_name'   => $u->office?->office_name ?? 'Kantor Pusat',
                    'is_manual'     => false,
                    'reason_type'   => 'existing_leave',
                    'reason_label'  => "Sudah {$label}",
                    'reason_detail' => "Memiliki {$label} yang disetujui ({$dateRange})",
                ];
                continue;
            }

            // C. Libur Shift / Jadwal Kerja OFF di tanggal tsb
            $userDays = $this->countWorkingDays(
                Carbon::parse($date),
                Carbon::parse($date),
                $companyId,
                $officeId,
                $u->id,
                $fullUsers->get($u->id)
            );

            if ($userDays < 1) {
                $excludedList[] = [
                    'id'            => $u->id,
                    'name'          => $u->name,
                    'department'    => $u->department,
                    'employee_code' => $u->employee_code,
                    'office_name'   => $u->office?->office_name ?? 'Kantor Pusat',
                    'is_manual'     => false,
                    'reason_type'   => 'shift_off',
                    'reason_label'  => 'Libur Shift (Jadwal OFF)',
                    'reason_detail' => 'Tanggal ini merupakan hari libur/OFF menurut jadwal shift kerja',
                ];
                continue;
            }

            // D. Pengecualian Manual oleh HR (Hanya berlaku untuk karyawan yang memenuhi syarat di atas)
            if (in_array($u->id, $manualExcludedIds)) {
                $excludedList[] = [
                    'id'            => $u->id,
                    'name'          => $u->name,
                    'department'    => $u->department,
                    'employee_code' => $u->employee_code,
                    'office_name'   => $u->office?->office_name ?? 'Kantor Pusat',
                    'is_manual'     => true,
                    'reason_type'   => 'manual',
                    'reason_label'  => 'Pengecualian Manual HRD',
                    'reason_detail' => 'Dikecualikan manual oleh HRD melalui form',
                ];
                continue;
            }

            // E. Eligible (Ikut Cuti Bersama)
            $eligibleList[] = [
                'id'              => $u->id,
                'name'            => $u->name,
                'department'      => $u->department,
                'employee_code'   => $u->employee_code,
                'office_name'     => $u->office?->office_name ?? 'Kantor Pusat',
                'remaining_quota' => $remaining,
            ];
        }

        $officeName = 'Semua Kantor (Semua Cabang)';
        if ($officeId) {
            $off = \App\Models\AttendanceSetting::find($officeId);
            if ($off) $officeName = $off->office_name;
        }

        return response()->json([
            'holiday' => [
                'name'        => $validated['name'],
                'date'        => $date,
                'office_name' => $officeName,
            ],
            'summary' => [
                'total_candidates' => count($candidates),
                'total_eligible'   => count($eligibleList),
                'total_excluded'   => count($excludedList),
            ],
            'excluded_users' => $excludedList,
            'eligible_users' => $eligibleList,
        ]);
    }

    // collectiveLeaveDetail() — rekap opt-in karyawan per cuti bersama (HRD)
    //   GET /api/v1/dashboard/attendance/collective-leaves/{holidayId}/detail
    public function collectiveLeaveDetail(Request $request, Holiday $holiday): JsonResponse
    {
        $user = $request->user();

        if (! $holiday->is_collective) {
            return response()->json(['message' => 'Ini bukan cuti bersama.'], 422);
        }

        if ($user->role !== 'super_admin' && $holiday->company_id !== $user->company_id) {
            return response()->json(['message' => 'Tidak ditemukan.'], 404);
        }

        $excludedUserIds = $holiday->excludedUsers()->pluck('users.id');
        $rows = \App\Models\LeaveRequest::where('holiday_id', $holiday->id)
            ->whereNotIn('leave_requests.user_id', $excludedUserIds)
            ->join('users', 'leave_requests.user_id', '=', 'users.id')
            ->leftJoin('leave_balances', function ($join) {
                $join->on('leave_balances.user_id', '=', 'users.id')
                    ->whereColumn('leave_balances.year', \DB::raw('YEAR(leave_requests.start_date)'))
                    ->where('leave_balances.leave_type', 'cuti');
            })
            ->select([
                'leave_requests.id',
                'leave_requests.user_id',
                'users.name as user_name',
                'users.department',
                'leave_requests.total_days',
                'leave_requests.collective_status',
                \DB::raw('COALESCE(leave_balances.quota, 12) as quota'),
                \DB::raw('COALESCE(leave_balances.used, 0) as used'),
            ])
            ->orderBy('users.name')
            ->get()
            ->map(fn ($r) => [
                'leave_request_id'  => $r->id,
                'user_id'           => $r->user_id,
                'user_name'         => $r->user_name,
                'department'        => $r->department,
                'total_days'        => $r->total_days,
                'collective_status' => $r->collective_status,
                'quota'             => (int) $r->quota,
                'used'              => (int) $r->used,
                'remaining'         => (int) $r->quota - (int) $r->used,
            ]);

        return response()->json([
            'holiday' => [
                'id'   => $holiday->id,
                'date' => $holiday->date->toDateString(),
                'name' => $holiday->name,
            ],
            'summary' => [
                'accepted' => $rows->where('collective_status', 'accepted')->count(),
                'declined' => $rows->where('collective_status', 'declined')->count(),
                'pending'  => $rows->where('collective_status', 'pending')->count(),
            ],
            'employees' => $rows->values(),
        ]);
    }

    // listCollectiveLeaves() — daftar cuti bersama mendatang + status opt-in user (mobile)
    //   GET /api/v1/attendance/collective-leaves
    public function listCollectiveLeaves(Request $request): JsonResponse
    {
        $user      = $request->user();
        $companyId = $user->company_id;
        $today     = now('Asia/Jakarta')->toDateString();

        // Ambil semua cuti bersama milik perusahaan yang belum lewat + H-7 ke depan
        // Dan cocok dengan cabang karyawan (NULL = semua cabang, ATAU attendance_setting_id sama dengan kantor user)
        $holidays = Holiday::where('company_id', $companyId)
            ->where('is_collective', true)
            ->where('date', '>=', $today)
            ->where(function ($q) use ($user) {
                $q->whereNull('attendance_setting_id');
                if ($user->attendance_setting_id) {
                    $q->orWhere('attendance_setting_id', $user->attendance_setting_id);
                }
            })
            ->whereDoesntHave('excludedUsers', function ($q) use ($user) {
                $q->where('users.id', $user->id);
            })
            ->orderBy('date')
            ->get();

        // Ambil leave_request karyawan ini untuk cuti bersama di atas
        $myRequests = \App\Models\LeaveRequest::where('user_id', $user->id)
            ->whereIn('holiday_id', $holidays->pluck('id'))
            ->pluck('collective_status', 'holiday_id');

        // Cuti pribadi (bukan cuti bersama) yang sudah disetujui HRD & mencakup rentang
        // tanggal cuti bersama yang ditampilkan. Dipakai untuk menekan banner:
        // jika karyawan SUDAH libur via cuti pribadi di tanggal tsb, cuti bersama
        // tidak perlu ditampilkan (agar saldo tidak terpotong ganda).
        $holidayDates = $holidays->map(fn ($h) => $h->date->toDateString());
        $approvedPersonalLeaves = \App\Models\LeaveRequest::where('user_id', $user->id)
            ->whereNull('holiday_id') // bukan cuti bersama
            ->where('status', 'approved')
            ->where(function ($q) use ($holidayDates) {
                if ($holidayDates->isEmpty()) return;
                $q->where(function ($qq) use ($holidayDates) {
                    foreach ($holidayDates as $date) {
                        $qq->orWhere(fn ($q2) => $q2->where('start_date', '<=', $date)->where('end_date', '>=', $date));
                    }
                });
            })
            ->get(['id', 'start_date', 'end_date']);

        // Map tanggal → apakah karyawan punya cuti pribadi approved di tanggal itu
        $approvedPersonalDateSet = collect();
        foreach ($holidayDates as $date) {
            $has = $approvedPersonalLeaves->contains(fn ($l) => $l->start_date->toDateString() <= $date && $l->end_date->toDateString() >= $date);
            $approvedPersonalDateSet[$date] = $has;
        }

        // Saldo cuti tahun ini
        // KEBIJAKAN 2026-08-25: auto-create selalu NON-AKTIF (quota 0) — banner cuti
        // bersama otomatis menolak (policy block) sampai HRD mengisi kuota manual.
        $year    = (int) now('Asia/Jakarta')->year;
        $balance = \App\Models\LeaveBalance::firstOrCreate(
            ['user_id' => $user->id, 'year' => $year, 'leave_type' => 'cuti'],
            ['company_id' => $companyId, 'quota' => 0, 'used' => 0]
        );

        // Ambil notifikasi pembatalan cuti bersama & cuti mandiri yang belum dibaca (read_at is null)
        $cancellations = DB::table('notifications')
            ->where('user_id', $user->id)
            ->whereIn('type', ['collective_leave_cancelled', 'personal_leave_cancelled'])
            ->whereNull('read_at')
            ->orderByDesc('created_at')
            ->get()
            ->map(function ($notif) {
                $data = json_decode($notif->data, true) ?: [];
                $rawDate = $data['date'] ?? '';
                $fmtDate = $data['date_label'] ?? ($rawDate ? Carbon::parse($rawDate)->translatedFormat('d M Y') : '');
                return [
                    'id'            => $notif->id,
                    'type'          => $notif->type,
                    'title'         => $data['title'] ?? ($notif->type === 'collective_leave_cancelled' ? 'Cuti Bersama Dibatalkan' : 'Pengajuan Cuti Dibatalkan'),
                    'name'          => $data['name'] ?? '',
                    'date'          => $rawDate,
                    'date_label'    => $fmtDate,
                    'message'       => $data['message'] ?? '',
                    'refunded_days' => (int) ($data['refunded_days'] ?? 0),
                    'created_at'    => $notif->created_at,
                ];
            });

        // Jika kuota cuti nonaktif (quota <= 0), karyawan tidak diikutsertakan dalam cuti bersama sama sekali
        if ($balance->quota <= 0) {
            return response()->json([
                'collective_leaves' => [],
                'cancellations'     => $cancellations->values(),
            ]);
        }

        $remaining = $balance->quota - $balance->used;

        // Kebijakan saldo cuti bersama: selalu 'block' (keputusan 2026-08-20).
        // User hanya boleh ikut cuti bersama jika sisa saldo cukup.
        $policy = 'block';

        // 1) Pre-komputasi "libur dari jadwal shift" untuk SEMUA tanggal cuti bersama
        //    sekaligus (bulk) — menggantikan panggilan resolveSchedule() per tanggal
        //    (N query → 1). Logika di dalam helper meniru resolveSchedule() persis.
        $offFromScheduleSet = $this->resolveOffDatesForUser($user, $holidayDates);

        $result = $holidays->map(function ($h) use ($user, $approvedPersonalDateSet, $offFromScheduleSet, $companyId, $remaining, $myRequests, $policy) {
            $dateStr = $h->date->toDateString();

            // 1) Cek apakah karyawan SUDAH libur dari jadwal shift di tanggal cuti bersama.
            //    Jika iya → banner TIDAK ditampilkan (tidak perlu ikut cuti bersama,
            //    karena hari itu memang sudah libur / jadwal OFF-nya).
            $isOffFromSchedule = (bool) ($offFromScheduleSet[$dateStr] ?? false);

            // 2) Cek apakah karyawan punya CUTI PRIBADI approved yang mencakup tanggal tsb.
            //    Jika iya → banner TIDAK ditampilkan (sudah libur via cuti resmi).
            $isOnPersonalLeave = (bool) ($approvedPersonalDateSet[$dateStr] ?? false);

            return [
                'id'                => $h->id,
                'date'              => $dateStr,
                'name'              => $h->name,
                'total_days'        => $this->countWorkingDays(
                    Carbon::parse($dateStr),
                    Carbon::parse($dateStr),
                    $companyId,
                    $user->attendance_setting_id,
                    $user->id
                ),
                'collective_status' => $myRequests->get($h->id) ?? 'pending',
                'remaining_quota'   => $remaining,
                'policy'            => $policy,
                // Apakah banner harus muncul: H-7 s/d hari H, BELUM memilih,
                // DAN karyawan TIDAK libur dari jadwal shift / cuti pribadi di hari itu.
                'show_banner'       => $dateStr <= now('Asia/Jakarta')->addDays(7)->toDateString()
                                    && ($myRequests->get($h->id) ?? 'pending') === 'pending'
                                    && ! $isOffFromSchedule
                                    && ! $isOnPersonalLeave,
            ];
        });

        return response()->json([
            'collective_leaves' => $result->values(),
            'cancellations'     => $cancellations->values(),
        ]);
    }

    // dismissCancellation() — tandai notifikasi pembatalan sebagai dibaca (mobile)
    //   POST /api/v1/attendance/dismiss-cancellation/{id}
    public function dismissCancellation(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        DB::table('notifications')
            ->where('id', $id)
            ->where('user_id', $user->id)
            ->update([
                'read_at'    => now(),
                'updated_at' => now(),
            ]);

        return response()->json(['message' => 'Pemberitahuan berhasil ditutup.']);
    }

    // ─── Helper bulk: tentukan "libur dari jadwal shift" per tanggal ───
    // Memetakan: tanggal (Y-m-d) → bool is_off.
    // Menggantikan panggilan ShiftController::resolveSchedule() per tanggal
    // (yang memicu 3-4 query per tanggal) menjadi FEW query bulk, namun
    // MENIRU PERSIS logika resolveSchedule():
    //   1. UserShift terbaru (start_date <= tanggal) yang masih aktif
    //      (end_date null ATAU end_date >= tanggal) dengan shift_id terisi
    //      & shift template aktif → ShiftSchedule hari tsb (efektif).
    //   2. Jika tidak ada / belum ada versi → fallback ke jam default kantor
    //      (libur jika hari di luar work_days).
    private function resolveOffDatesForUser(User $user, iterable $dates): array
    {
        $result = [];
        $dateList = collect($dates)->values();

        if ($dateList->isEmpty()) {
            return $result;
        }

        $office = $user->office;
        if ($office && ! array_key_exists('work_start_time', $office->getAttributes())) {
            $office = AttendanceSetting::find($office->id);
        }
        if (! $office && $user->company_id) {
            $office = AttendanceSetting::where('company_id', $user->company_id)
                ->orderBy('id')
                ->first();
        }
        $workDays = $office->work_days ?? [1, 2, 3, 4, 5];

        // Semua assignment user (termasuk yang sudah berakhir) — filter aktif di PHP
        // agar setara dengan query "start_date <= tanggal" + end_date check.
        $userShifts = UserShift::with('shift:id,name,is_active')
            ->where('user_id', $user->id)
            ->orderBy('start_date')
            ->get();

        // Kumpulkan semua shift_id yang relevan
        $shiftIds = $userShifts->pluck('shift_id')->filter()->unique()->values();
        $schedulesByShift = [];
        if ($shiftIds->isNotEmpty()) {
            // Ambil SEMUA ShiftSchedule untuk shift-shift tsb (1 query bulk)
            $allSchedules = \App\Models\ShiftSchedule::whereIn('shift_id', $shiftIds)
                ->orderBy('effective_date')
                ->get(['shift_id', 'day_of_week', 'effective_date', 'is_off', 'work_start_time', 'work_end_time', 'is_wfh', 'is_field', 'is_cross_day']);

            foreach ($allSchedules->groupBy('shift_id') as $shiftId => $schedules) {
                foreach ($schedules->groupBy('day_of_week') as $dow => $versions) {
                    // Versi berlaku = effective_date terbesar yang <= tanggal.
                    // Dikumpulkan per (shift_id, day_of_week) sebagai array efektif_date asc
                    foreach ($versions as $sv) {
                        $schedulesByShift[$shiftId][$dow][] = $sv;
                    }
                    usort($schedulesByShift[$shiftId][$dow], fn ($a, $b) => $a->effective_date->toDateString() <=> $b->effective_date->toDateString());
                }
            }
        }

        // Preload shift aktif status
        $activeShifts = $userShifts->map(fn ($us) => $us->shift)->filter()->mapWithKeys(fn ($s) => [$s->id => (bool) $s->is_active]);

        foreach ($dateList as $date) {
            $dayOfWeek = Carbon::parse($date)->dayOfWeek;

            // 1) Cari UserShift terbaru yang AKTIF pada $date (start_date <= date DAN (end_date null || end_date >= date))
            //    Sama persis dengan logika ShiftController::resolveSchedule()
            $candidates = $userShifts->filter(function ($us) use ($date) {
                return $us->start_date->toDateString() <= $date
                    && ($us->end_date === null || $us->end_date->toDateString() >= $date);
            });
            $userShift = $candidates->sortByDesc(fn ($us) => $us->start_date->toDateString())->first();

            if ($userShift && $userShift->shift_id && ($activeShifts[$userShift->shift_id] ?? false)) {
                $versions = $schedulesByShift[$userShift->shift_id][$dayOfWeek] ?? [];
                $schedule = null;
                if ($versions) {
                    // Versi berlaku <= tanggal
                    $applicable = null;
                    foreach ($versions as $v) {
                        if ($v->effective_date->toDateString() <= $date) {
                            $applicable = $v;
                        } else {
                            break;
                        }
                    }
                    // Fallback resolveSchedule: jika tanggal sebelum versi pertama → pakai versi terbaru
                    $schedule = $applicable ?: $versions[count($versions) - 1];
                }

                if ($schedule) {
                    $result[$date] = (bool) $schedule->is_off;
                    continue;
                }
            }

            // 2) Fallback ke jadwal default kantor
            if ($office) {
                $result[$date] = ! in_array($dayOfWeek, $workDays);
                continue;
            }

            $result[$date] = false;
        }

        return $result;
    }

    // respondCollectiveLeave() — karyawan pilih ikut/tidak cuti bersama (mobile)
    //   POST /api/v1/attendance/collective-leave/{holidayId}/respond
    //   Body: { "response": "accepted" | "declined" }
    public function respondCollectiveLeave(Request $request, Holiday $holiday): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'response' => 'required|in:accepted,declined',
        ]);

        // Validasi holiday
        if (! $holiday->is_collective) {
            return response()->json(['message' => 'Ini bukan cuti bersama.'], 422);
        }
        if ($holiday->company_id !== $user->company_id) {
            return response()->json(['message' => 'Cuti bersama tidak ditemukan.'], 404);
        }
        if ($holiday->excludedUsers()->where('users.id', $user->id)->exists()) {
            return response()->json(['message' => 'Anda dikecualikan dari cuti bersama ini.'], 403);
        }

        // Guard dobel-potong: karyawan yang sudah punya CUTI PRIBADI approved di tanggal
        // cuti bersama tidak boleh ikut — hari itu dia sudah libur & saldo sudah terpotong.
        // (Lapis kedua setelah auto-exclude persisten; melindungi data lama sebelum fitur ini.)
        $hasApprovedPersonal = \App\Models\LeaveRequest::where('user_id', $user->id)
            ->whereNull('holiday_id')
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $holiday->date->toDateString())
            ->whereDate('end_date', '>=', $holiday->date->toDateString())
            ->exists();
        if ($hasApprovedPersonal) {
            return response()->json([
                'message' => 'Anda sudah memiliki pengajuan cuti yang disetujui pada tanggal cuti bersama ini, sehingga tidak perlu ikut cuti bersama.',
            ], 422);
        }

        // Validasi cabang: cuti bersama hanya berlaku utk karyawan cabang tsb
        // (atau company-wide jika attendance_setting_id NULL).
        if ($holiday->attendance_setting_id
            && (int) $holiday->attendance_setting_id !== (int) $user->attendance_setting_id) {
            return response()->json([
                'message' => 'Cuti bersama ini hanya berlaku untuk karyawan kantor cabang lain.',
            ], 403);
        }

        // Tidak bisa ubah pilihan setelah hari H lewat
        $today = now('Asia/Jakarta')->toDateString();
        if ($holiday->date->toDateString() < $today) {
            return response()->json(['message' => 'Batas waktu memilih telah lewat.'], 422);
        }

        // FIX BUG #6 (race condition): seluruh baca-status → cek saldo → update →
        // potong/kembalikan saldo dibungkus SATU transaksi dengan lockForUpdate pada
        // baris leave_request & leave_balance. Dua respons paralel (dobel klik / dua tab)
        // akan diserialisasi: respons kedua membaca status TERBARU setelah lock dilepas,
        // sehingga tidak ada dobel potong (saldo minus) maupun dobel kembalikan.
        $result = DB::transaction(function () use ($user, $holiday, $validated) {
            // Kunci baris leave_request milik karyawan ini utk cuti bersama tsb
            $leave = \App\Models\LeaveRequest::where('user_id', $user->id)
                ->where('holiday_id', $holiday->id)
                ->lockForUpdate()
                ->first();

            // Auto-create leave_request bila belum ada (mis. user dibuat/assigned cabang
            // SETELAH cuti bersama di-generate, atau generate ter-skip krn kondisi tertentu).
            // Karyawan tetap boleh memilih ikut/tidak tanpa harus menunggu re-generate HRD.
            if (! $leave) {
                $totalDays = $this->countWorkingDays(
                    Carbon::parse($holiday->date),
                    Carbon::parse($holiday->date),
                    $holiday->company_id,
                    $holiday->attendance_setting_id,
                    $user->id
                );

                $leave = \App\Models\LeaveRequest::create([
                    'user_id'           => $user->id,
                    'company_id'        => $holiday->company_id,
                    'holiday_id'        => $holiday->id,
                    'leave_type'        => 'cuti',
                    'start_date'        => $holiday->date->toDateString(),
                    'end_date'          => $holiday->date->toDateString(),
                    'total_days'        => $totalDays,
                    'reason'            => "Cuti bersama: {$holiday->name}",
                    'status'            => 'pending',
                    'collective_status' => 'pending',
                ]);
            }

            // Jika pilihan sama, tidak perlu proses ulang (dicek DI DALAM lock agar
            // respons duplikat paralel juga tertangkap)
            if ($leave->collective_status === $validated['response']) {
                return ['kind' => 'same', 'leave' => $leave];
            }

            $year = Carbon::parse($leave->start_date)->year;
            // KEBIJAKAN 2026-08-25: auto-create saldo cuti selalu NON-AKTIF (quota 0);
            // aktivasi hanya lewat HRD di tab Saldo Cuti.
            $balance = \App\Models\LeaveBalance::firstOrCreate(
                ['user_id' => $user->id, 'year' => $year, 'leave_type' => 'cuti'],
                ['company_id' => $user->company_id, 'quota' => 0, 'used' => 0]
            );
            // Kunci baris saldo SEBELUM baca used agar cek & mutasi atomik
            $balance = \App\Models\LeaveBalance::whereKey($balance->id)->lockForUpdate()->first();

            if ($validated['response'] === 'accepted') {
                $remaining = $balance->quota - $balance->used;

                // Sesuai keputusan 2026-08-20: kebijakan saldo cuti bersama SELALU 'block'.
                // Jika saldo cuti tidak cukup → user TIDAK bisa ikut cuti bersama.
                if ($remaining < $leave->total_days) {
                    return [
                        'kind'          => 'insufficient',
                        'leave'         => $leave,
                        'remaining'     => $remaining,
                        'required'      => $leave->total_days,
                        // Bedakan "belum pernah diaktifkan HRD" dari "saldo habis"
                        'not_activated' => (int) $balance->quota <= 0 && (int) $balance->used === 0,
                    ];
                }

                // Jika sebelumnya sudah accepted (ganti dari declined → accepted), jangan double potong
                $wasPreviouslyAccepted = $leave->collective_status === 'accepted';

                $leave->update([
                    'collective_status' => 'accepted',
                    'status'            => 'approved',
                    'approved_by'       => null,
                    'approved_at'       => now(),
                ]);

                // Potong saldo cuti (selalu potong kecuali sudah pernah dipotong sebelumnya)
                if (! $wasPreviouslyAccepted) {
                    $balance->increment('used', $leave->total_days);
                    $balance->refresh();
                }

                return [
                    'kind'      => 'accepted',
                    'leave'     => $leave,
                    'remaining' => $balance->quota - $balance->used,
                ];
            }

            // Response = 'declined'
            $wasAccepted = $leave->collective_status === 'accepted';

            $leave->update([
                'collective_status' => 'declined',
                'status'            => 'rejected',
            ]);

            // Kembalikan saldo jika sebelumnya sudah accepted
            if ($wasAccepted) {
                $balance->decrement('used', $leave->total_days);
                $balance->refresh();
            }

            return [
                'kind'      => 'declined',
                'leave'     => $leave,
                'remaining' => $balance->quota - $balance->used,
            ];
        });

        $leave = $result['leave'];

        // Jika pilihan sama, tidak perlu proses ulang
        if ($result['kind'] === 'same') {
            return response()->json(['message' => 'Pilihan Anda sudah tersimpan.', 'collective_status' => $leave->collective_status]);
        }

        if ($result['kind'] === 'insufficient') {
            return response()->json([
                'message'        => ! empty($result['not_activated'])
                    ? "Saldo cuti Anda belum diaktifkan oleh HRD. Hubungi HRD untuk mengaktifkan saldo cuti terlebih dahulu."
                    : "Saldo cuti Anda tidak cukup. Sisa {$result['remaining']} hari, dibutuhkan {$result['required']} hari. Hubungi HRD untuk informasi lebih lanjut.",
                'remaining'      => $result['remaining'],
                'required'       => $result['required'],
                'policy'         => 'block',
            ], 422);
        }

        if ($result['kind'] === 'accepted') {
            $this->logActivity($user->id, $user->company_id, 'leave_approved',
                "Ikut cuti bersama #{$holiday->id} {$holiday->name}", 'leave_request', $leave->id);

            return response()->json([
                'message'           => "Anda terdaftar ikut cuti bersama \"{$holiday->name}\".",
                'collective_status' => 'accepted',
                'remaining_quota'   => $result['remaining'],
                'policy'            => 'block',
            ]);
        }

        // kind = 'declined'
        $this->logActivity($user->id, $user->company_id, 'leave_rejected',
            "Tidak ikut cuti bersama #{$holiday->id} {$holiday->name}", 'leave_request', $leave->id);

        return response()->json([
            'message'           => "Pilihan Anda tersimpan. Anda tidak ikut cuti bersama \"{$holiday->name}\".",
            'collective_status' => 'declined',
            'remaining_quota'   => $result['remaining'],
        ]);
    }

    // ─── Helper: buat leave_request batch untuk semua karyawan aktif (cuti bersama) ────
    // FIX BUG #2 (2026-08-25): total_days dihitung PER-KARYAWAN (dengan $userId) sehingga
    // jadwal shift masing-masing diperhitungkan. Sebelumnya dihitung sekali tanpa userId
    // (fallback isWeekend global) → cuti bersama di Sabtu/Minggu menghasilkan total_days=0
    // untuk SEMUA karyawan → tidak ada leave_request dibuat, padahal karyawan shift
    // weekend justru dijadwalkan masuk. Kini hanya karyawan yang dijadwalkan KERJA pada
    // tanggal tsb yang mendapat leave_request + notifikasi (konsisten dengan banner mobile).
    private function createCollectiveLeaveRequests(Holiday $holiday, int $companyId, array $manualExcludedIds = []): array
    {
        $date = $holiday->date->toDateString();

        // Karyawan aktif SEMUA ROLE (employee/finance/hrd/admin/super_admin).
        $query = \App\Models\User::where('company_id', $companyId)
            ->where('is_active', true);

        if ($holiday->attendance_setting_id) {
            $query->where('attendance_setting_id', $holiday->attendance_setting_id);
        }

        // Ambil daftar user yang DIKECUALIKAN dari libur ini (exclusion manual oleh HR + pivot DB)
        $dbExcludedIds = DB::table('holiday_exclusions')
            ->where('holiday_id', $holiday->id)
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $excludedUserIds = array_unique(array_merge(
            array_map('intval', $manualExcludedIds),
            $dbExcludedIds
        ));

        $candidates = $query->get(['id', 'name']);

        // Auto-exclude: karyawan yang SUDAH punya leave approved yang overlap dengan
        // tanggal cuti bersama tidak perlu dibuatkan leave_request cuti bersama.
        // Mereka sudah "libur" pada hari itu; masukkan ke daftar warning.
        $existingApproved = \App\Models\LeaveRequest::whereIn('user_id', $candidates->pluck('id'))
            ->where('status', 'approved')
            ->whereNull('holiday_id') // cuti pribadi, bukan cuti bersama lain
            ->where('start_date', '<=', $date)
            ->where('end_date', '>=', $date)
            ->get()
            ->keyBy('user_id');

        $year = (int) Carbon::parse($date)->year;
        $leaveBalances = \App\Models\LeaveBalance::where('year', $year)
            ->where('leave_type', 'cuti')
            ->whereIn('user_id', $candidates->pluck('id'))
            ->get()
            ->keyBy('user_id');

        $autoExcluded = [];
        $typeLabels   = ['wfh' => 'WFH', 'izin' => 'izin', 'sakit' => 'sakit', 'cuti' => 'cuti'];

        // Kandidat setelah exclusion manual + auto-exclude (cuti nonaktif / cuti overlap)
        $users = $candidates->reject(function ($u) use ($excludedUserIds, $existingApproved, $leaveBalances, &$autoExcluded, $typeLabels, $date) {
            // Dikecualikan manual oleh HR
            if (in_array((int) $u->id, $excludedUserIds, true)) {
                return true;
            }
            // Auto-exclude karena kuota cuti nonaktif (belum diaktifkan HRD atau quota <= 0)
            $lb = $leaveBalances->get($u->id);
            $isLeaveActive = $lb ? ((int) $lb->quota > 0) : false;
            if (! $isLeaveActive) {
                $autoExcluded[] = [
                    'user_id'    => $u->id,
                    'name'       => $u->name,
                    'leave_type' => 'Cuti Nonaktif',
                    'start_date' => $date,
                    'end_date'   => $date,
                ];
                return true;
            }
            // Auto-exclude karena sudah punya leave approved yang overlap
            if ($existingApproved->has($u->id)) {
                $lr = $existingApproved->get($u->id);
                $autoExcluded[] = [
                    'user_id'    => $u->id,
                    'name'       => $u->name,
                    'leave_type' => $typeLabels[$lr->leave_type] ?? $lr->leave_type,
                    'start_date' => Carbon::parse($lr->start_date)->toDateString(),
                    'end_date'   => Carbon::parse($lr->end_date)->toDateString(),
                ];
                return true;
            }
            return false;
        });

        // Persistenkan auto-exclude ke holiday_exclusions (PENGECUALIAN PERMANEN)
        if (! empty($autoExcluded)) {
            $alreadyAttached = DB::table('holiday_exclusions')
                ->where('holiday_id', $holiday->id)
                ->pluck('user_id')
                ->map(fn ($id) => (int) $id)
                ->all();
            $toAttach = array_diff(array_column($autoExcluded, 'user_id'), $alreadyAttached);
            if (! empty($toAttach)) {
                $holiday->excludedUsers()->attach($toAttach);
            }
        }

        // Hitung total_days PER KARYAWAN — hanya yang dijadwalkan KERJA pada tanggal
        // cuti bersama yang mendapat leave_request & notifikasi.
        $existingUserIds = \App\Models\LeaveRequest::where('holiday_id', $holiday->id)
            ->pluck('user_id')
            ->flip();

        $now = now();
        $rows = [];

        $fullUsers = User::whereIn('id', $users->pluck('id'))->get()->keyBy('id');

        foreach ($users as $u) {
            if ($existingUserIds->has($u->id)) {
                continue; // sudah punya leave_request cuti bersama ini
            }

            $userDays = $this->countWorkingDays(
                Carbon::parse($date),
                Carbon::parse($date),
                $companyId,
                $holiday->attendance_setting_id,
                $u->id,
                $fullUsers->get($u->id)
            );

            if ($userDays < 1) {
                continue; // libur dari jadwal shift / weekend menurut jadwalnya → skip
            }

            $rows[] = [
                'user_id'           => $u->id,
                'company_id'        => $companyId,
                'holiday_id'        => $holiday->id,
                'leave_type'        => 'cuti',
                'start_date'        => $date,
                'end_date'          => $date,
                'total_days'        => $userDays,
                'reason'            => "Cuti bersama: {$holiday->name}",
                'status'            => 'pending',
                'collective_status' => 'pending',
                'created_at'        => $now,
                'updated_at'        => $now,
            ];
        }

        // Bulk insert (lebih efisien dari looping create())
        if (! empty($rows)) {
            \App\Models\LeaveRequest::insert($rows);

            // Ambil seluruh ID yang dikecualikan (manual + auto) untuk memastikan TIDAK ADA YANG BOCOR
            $allExcludedIds = DB::table('holiday_exclusions')
                ->where('holiday_id', $holiday->id)
                ->pluck('user_id')
                ->map(fn ($id) => (int) $id)
                ->all();
            $allExcludedIds = array_unique(array_merge($excludedUserIds, $allExcludedIds));

            // Notifikasi HANYA ke karyawan yang terdampak (dan PASTI tidak ada di daftar pengecualian)
            foreach ($rows as $row) {
                if (in_array((int) $row['user_id'], $allExcludedIds, true)) {
                    continue;
                }

                $this->notifyUser($row['user_id'], 'collective_leave_announced', [
                    'message'    => "Cuti bersama \"{$holiday->name}\" pada {$date} telah dijadwalkan. Silakan pilih ikut atau tidak di aplikasi.",
                    'holiday_id' => $holiday->id,
                    'date'       => $date,
                    'name'       => $holiday->name,
                ], 'holiday', $holiday->id);
            }
        }

        return $autoExcluded;
    }

    // ─── Helper: sinkronisasi exclusion manual vs auto saat Cuti Bersama di-edit ────
    private function syncCollectiveLeaveExclusions(Holiday $holiday, int $companyId, array $manualExcludedIds): array
    {
        $date = $holiday->date->toDateString();

        $query = \App\Models\User::where('company_id', $companyId)
            ->where('is_active', true);

        if ($holiday->attendance_setting_id) {
            $query->where('attendance_setting_id', $holiday->attendance_setting_id);
        }

        $candidates = $query->get(['id', 'name']);

        $year = (int) Carbon::parse($date)->year;
        $leaveBalances = \App\Models\LeaveBalance::where('year', $year)
            ->where('leave_type', 'cuti')
            ->whereIn('user_id', $candidates->pluck('id'))
            ->get()
            ->keyBy('user_id');

        $existingApproved = \App\Models\LeaveRequest::whereIn('user_id', $candidates->pluck('id'))
            ->where('status', 'approved')
            ->whereNull('holiday_id')
            ->where('start_date', '<=', $date)
            ->where('end_date', '>=', $date)
            ->get()
            ->keyBy('user_id');

        $fullUsers = User::whereIn('id', $candidates->pluck('id'))->get()->keyBy('id');

        // Ambil data keikutsertaan yang sudah diterima di libur ini
        $thisHolidayAccepted = \App\Models\LeaveRequest::where('holiday_id', $holiday->id)
            ->where('collective_status', 'accepted')
            ->get()
            ->keyBy('user_id');

        $finalExcludedIds = [];
        $eligibleUsers = [];

        foreach ($candidates as $u) {
            $lb = $leaveBalances->get($u->id);
            $quota = $lb ? (int) $lb->quota : 0;
            $used  = $lb ? (int) $lb->used : 0;
            $holidayDeduction = $thisHolidayAccepted->has($u->id) ? (int) $thisHolidayAccepted->get($u->id)->total_days : 0;
            $effectiveRemaining = ($quota - $used) + $holidayDeduction;

            // 1. Cuti nonaktif / kuota habis (Permanen Tidak Ikut)
            if ($quota <= 0 || $effectiveRemaining <= 0) {
                $finalExcludedIds[] = $u->id;
                continue;
            }

            // 2. Sudah ada cuti mandiri/izin/sakit approved (Permanen Tidak Ikut)
            if ($existingApproved->has($u->id)) {
                $finalExcludedIds[] = $u->id;
                continue;
            }

            // 3. Libur shift / jadwal OFF (Permanen Tidak Ikut)
            $userDays = $this->countWorkingDays(
                Carbon::parse($date),
                Carbon::parse($date),
                $companyId,
                $holiday->attendance_setting_id,
                $u->id,
                $fullUsers->get($u->id)
            );
            if ($userDays < 1) {
                $finalExcludedIds[] = $u->id;
                continue;
            }

            // 4. Pengecualian manual oleh HR (Hanya yang eligible yang bisa manual dikecualikan/dikembalikan)
            if (in_array($u->id, $manualExcludedIds)) {
                $finalExcludedIds[] = $u->id;
                continue;
            }

            // 5. Eligible (Ikut Cuti Bersama)
            $eligibleUsers[] = $u;
        }

        // Sync ke pivot table holiday_exclusions
        $holiday->excludedUsers()->sync($finalExcludedIds);

        // Hapus leave_request untuk karyawan yang masuk daftar pengecualian & kembalikan saldo cuti
        $toCancel = \App\Models\LeaveRequest::where('holiday_id', $holiday->id)
            ->whereIn('user_id', $finalExcludedIds)
            ->get();

        foreach ($toCancel as $lr) {
            if ($lr->collective_status === 'accepted') {
                $year = (int) Carbon::parse($date)->year;
                $balance = \App\Models\LeaveBalance::where('user_id', $lr->user_id)
                    ->where('year', $year)
                    ->where('leave_type', 'cuti')
                    ->first();

                if ($balance && $lr->total_days > 0) {
                    $balance->decrement('used', min($lr->total_days, $balance->used));
                }

                $dateFormatted = Carbon::parse($date)->translatedFormat('d M Y');
                $this->notifyUser($lr->user_id, 'collective_leave_cancelled', [
                    'title'         => 'Cuti Bersama Dibatalkan',
                    'name'          => $holiday->name,
                    'date'          => $date,
                    'date_label'    => $dateFormatted,
                    'message'       => "Cuti bersama \"{$holiday->name}\" pada {$dateFormatted} telah dibatalkan oleh HRD. Anda dijadwalkan masuk kerja dan saldo cuti Anda telah dikembalikan.",
                    'refunded_days' => (int) $lr->total_days,
                    'holiday_id'    => $holiday->id,
                ], 'holiday', $holiday->id);
            }
            $lr->delete();
        }

        // Buat leave_request untuk karyawan eligible yang belum punya leave_request
        $existingLeaveUserIds = \App\Models\LeaveRequest::where('holiday_id', $holiday->id)
            ->pluck('user_id')
            ->flip();

        $now = now();
        $newRows = [];
        foreach ($eligibleUsers as $u) {
            if ($existingLeaveUserIds->has($u->id)) {
                continue;
            }
            $newRows[] = [
                'user_id'           => $u->id,
                'company_id'        => $companyId,
                'holiday_id'        => $holiday->id,
                'leave_type'        => 'cuti',
                'start_date'        => $date,
                'end_date'          => $date,
                'total_days'        => 1,
                'reason'            => "Cuti bersama: {$holiday->name}",
                'status'            => 'pending',
                'collective_status' => 'pending',
                'created_at'        => $now,
                'updated_at'        => $now,
            ];
        }

        if (! empty($newRows)) {
            \App\Models\LeaveRequest::insert($newRows);
        }

        return $finalExcludedIds;
    }

    // ─── Helper: batalkan semua leave_request cuti bersama & kembalikan saldo ────
    private function cancelAllCollectiveRequests(Holiday $holiday): void
    {
        $accepted = \App\Models\LeaveRequest::where('holiday_id', $holiday->id)
            ->where('collective_status', 'accepted')
            ->get();

        foreach ($accepted as $leave) {
            $year    = Carbon::parse($leave->start_date)->year;
            $balance = \App\Models\LeaveBalance::where('user_id', $leave->user_id)
                ->where('year', $year)
                ->where('leave_type', 'cuti')
                ->first();

            if ($balance && $leave->total_days > 0) {
                // Kembalikan saldo yang sudah dipotong
                $balance->decrement('used', min($leave->total_days, $balance->used));
            }

            // Notifikasi ke karyawan bahwa cuti bersama dibatalkan
            $dateFormatted = Carbon::parse($holiday->date)->translatedFormat('d M Y');
            $this->notifyUser($leave->user_id, 'collective_leave_cancelled', [
                'title'         => 'Cuti Bersama Dibatalkan',
                'name'          => $holiday->name,
                'date'          => $holiday->date->toDateString(),
                'date_label'    => $dateFormatted,
                'message'       => "Cuti bersama \"{$holiday->name}\" pada {$dateFormatted} telah dibatalkan oleh HRD. Saldo cuti Anda telah dikembalikan.",
                'refunded_days' => (int) $leave->total_days,
                'holiday_id'    => $holiday->id,
            ], 'holiday', $holiday->id);
        }

        // Notifikasi ke karyawan yang masih pending (belum merespons)
        $pending = \App\Models\LeaveRequest::where('holiday_id', $holiday->id)
            ->where('collective_status', 'pending')
            ->get();

        foreach ($pending as $leave) {
            $dateFormatted = Carbon::parse($holiday->date)->translatedFormat('d M Y');
            $this->notifyUser($leave->user_id, 'collective_leave_cancelled', [
                'title'      => 'Cuti Bersama Dibatalkan',
                'name'       => $holiday->name,
                'date'       => $holiday->date->toDateString(),
                'date_label' => $dateFormatted,
                'message'    => "Cuti bersama \"{$holiday->name}\" pada {$dateFormatted} telah dibatalkan oleh HRD.",
                'holiday_id' => $holiday->id,
            ], 'holiday', $holiday->id);
        }

        // Hapus semua leave_request terkait cuti bersama ini (baik via holiday_id maupun nama/tanggal)
        \App\Models\LeaveRequest::where('holiday_id', $holiday->id)
            ->orWhere(function ($q) use ($holiday) {
                $q->where('reason', "Cuti bersama: {$holiday->name}")
                    ->whereDate('start_date', $holiday->date);
            })
            ->delete();
    }

    // ─── Helper: kembalikan saldo cuti approved saat HR menambah libur nasional/cabang ───
    // Saat HR menambah hari libur (non-collective) di tanggal yang overlap dengan cuti
    // pribadi karyawan yang SUDAH di-approve, hari itu jadi "libur gratis" — saldo cuti
    // yang terpotong dikembalikan. Jumlah hari yang dikembalikan disimpan di
    // leave_requests.holiday_compensated_days agar bisa dipotong kembali jika libur dihapus.
    // Hanya berlaku untuk leave_type = 'cuti' (izin & sakit tidak memakai saldo).
    private function compensateApprovedLeavesForHoliday(Holiday $holiday, int $companyId, bool $isNational): array
    {
        $date = $holiday->date->toDateString();

        // Kandidat karyawan sesuai scope libur:
        //   nasional → seluruh karyawan perusahaan
        //   cabang   → hanya karyawan di attendance_setting_id tersebut
        // Konsisten dengan createCollectiveLeaveRequests: semua role aktif (tanpa
        // filter attendance_enabled) agar saldo cuti HRD/finance/admin juga ikut dikoreksi.
        $userQuery = \App\Models\User::where('company_id', $companyId)
            ->where('is_active', true);
        if (! $isNational && $holiday->attendance_setting_id) {
            $userQuery->where('attendance_setting_id', $holiday->attendance_setting_id);
        }
        $userIds = $userQuery->pluck('id');

        if ($userIds->isEmpty()) {
            return [];
        }

        // Cuti pribadi approved yang overlap tanggal libur (hanya 'cuti' yang pakai saldo).
        $leaves = \App\Models\LeaveRequest::whereIn('user_id', $userIds)
            ->where('status', 'approved')
            ->where('leave_type', 'cuti')
            ->whereNull('holiday_id') // cuti pribadi, bukan cuti bersama
            ->where('start_date', '<=', $date)
            ->where('end_date', '>=', $date)
            ->with('user:id,name,attendance_setting_id')
            ->get();

        $compensated = [];
        foreach ($leaves as $leave) {
            // Hitung ulang hari kerja SEKARANG (libur baru sudah dibuat → otomatis dikecualikan).
            // Pakai cabang milik karyawan agar libur cabang lain ikut diperhitungkan dengan benar.
            $newTotal = $this->countWorkingDays(
                Carbon::parse($leave->start_date),
                Carbon::parse($leave->end_date),
                $companyId,
                $leave->user->attendance_setting_id ?? null,
                $leave->user_id
            );

            $restored = $leave->total_days - $newTotal;
            if ($restored <= 0) {
                continue; // libur ini tidak mengurangi hari kerja cuti (mis. weekend)
            }

            $year    = Carbon::parse($leave->start_date)->year;
            $balance = \App\Models\LeaveBalance::where('user_id', $leave->user_id)
                ->where('year', $year)
                ->where('leave_type', 'cuti')
                ->first();
            if ($balance) {
                $balance->decrement('used', min($restored, $balance->used));
            }

            $leave->total_days               = $newTotal;
            $leave->holiday_compensated_days = ($leave->holiday_compensated_days ?? 0) + $restored;
            $leave->save();

            $compensated[] = [
                'user_id'       => $leave->user_id,
                'name'          => $leave->user->name ?? null,
                'restored_days' => $restored,
                'start_date'    => Carbon::parse($leave->start_date)->toDateString(),
                'end_date'      => Carbon::parse($leave->end_date)->toDateString(),
            ];

            $dateFormatted = Carbon::parse($date)->translatedFormat('d M Y');
            $this->notifyUser($leave->user_id, 'personal_leave_cancelled', [
                'title'         => 'Pengajuan Cuti Dibatalkan / Dikompensasi',
                'name'          => 'Cuti Mandiri',
                'date'          => $date,
                'date_label'    => $dateFormatted,
                'message'       => "Pengajuan cuti Anda pada {$dateFormatted} telah dibatalkan karena ada hari libur \"{$holiday->name}\". Saldo cuti Anda telah dikembalikan {$restored} hari.",
                'refunded_days' => $restored,
                'leave_id'      => $leave->id,
            ], 'leave_request', $leave->id);
        }

        return $compensated;
    }

    // ─── Helper: potong ulang saldo cuti saat HR menghapus libur nasional/cabang ───
    // Kebalikan dari compensateApprovedLeavesForHoliday(): saat libur yang tadi membuat
    // cuti "gratis" dihapus, hari kerja cuti bertambah lagi sehingga saldo dipotong kembali.
    // Dipanggil SETELAH holiday dihapus agar countWorkingDays tidak lagi mengecualikan tanggal itu.
    private function reapplyLeaveDeductionAfterHolidayRemoval(array $leaveIds, int $companyId, string $holidayName, string $date, int $holidayId): void
    {
        if (empty($leaveIds)) {
            return;
        }

        $leaves = \App\Models\LeaveRequest::whereIn('id', $leaveIds)
            ->with('user:id,attendance_setting_id')
            ->get();

        foreach ($leaves as $leave) {
            // Libur sudah dihapus → hari kerja bertambah lagi.
            $newTotal = $this->countWorkingDays(
                Carbon::parse($leave->start_date),
                Carbon::parse($leave->end_date),
                $companyId,
                $leave->user->attendance_setting_id ?? null,
                $leave->user_id
            );

            $deduct = $newTotal - $leave->total_days;
            if ($deduct <= 0) {
                continue;
            }
            // Jangan potong lebih dari yang pernah dikembalikan akibat libur.
            $deduct = min($deduct, (int) $leave->holiday_compensated_days);
            if ($deduct <= 0) {
                continue;
            }

            $year    = Carbon::parse($leave->start_date)->year;
            // Fallback create NON-AKTIF (quota 0) — jangan pernah auto-aktifkan saldo.
            // Normalnya baris sudah ada karena cuti-nya approved (saldo sempat dipotong).
            $balance = \App\Models\LeaveBalance::firstOrCreate(
                ['user_id' => $leave->user_id, 'year' => $year, 'leave_type' => 'cuti'],
                ['company_id' => $companyId, 'quota' => 0, 'used' => 0]
            );
            $balance->increment('used', $deduct);

            $leave->total_days               = $leave->total_days + $deduct;
            $leave->holiday_compensated_days = max(0, (int) $leave->holiday_compensated_days - $deduct);
            $leave->save();

            $this->notifyUser($leave->user_id, 'leave_balance_deducted', [
                'message'    => "Hari libur \"{$holidayName}\" ({$date}) dihapus. Saldo cuti Anda dipotong kembali {$deduct} hari.",
                'holiday_id' => $holidayId,
                'date'       => $date,
            ], 'holiday', $holidayId);
        }
    }

    // ═══════════════════════════════════════════════════════════
    // BAGIAN B — Semua user (prefix attendance)
    // ═══════════════════════════════════════════════════════════

    // 6. checkIn() — presensi masuk via mobile
    //    Tiga mode berdasarkan flag HRD di tabel users:
    //    a) wfh_enabled = false             → 403, harus pakai perangkat presensi kantor
    //    b) wfh_enabled = true, radius = false → WFH bebas, tanpa cek lokasi
    //    c) wfh_enabled = true, radius = true  → Lapangan, wajib dalam radius lokasi kerja
    public function checkIn(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'latitude'  => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
        ]);

        $user = $request->user();

        // ─── Validasi Device Binding (anti titip absen lewat HP rekan) ───────
        if ($request->header('X-Platform') === 'mobile' && config('app.device_binding_enabled', true)) {
            $reqDeviceId   = $request->header('X-Device-Id') ?? $request->input('device_id');
            $reqDeviceName = $request->header('X-Device-Name') ?? $request->input('device_name');

            if (! $user->device_id && $reqDeviceId) {
                // Auto-bind device pertama kali
                $user->forceFill([
                    'device_id'       => $reqDeviceId,
                    'device_name'     => $reqDeviceName,
                    'device_bound_at' => now(),
                ])->save();
            } elseif ($user->device_id && $reqDeviceId && $user->device_id !== $reqDeviceId) {
                return response()->json([
                    'message'         => 'Presensi ditolak. Akun Anda terikat pada perangkat lain'
                        . ($user->device_name ? " ({$user->device_name})" : '')
                        . '. Anda tidak dapat presensi menggunakan perangkat ini.',
                    'device_mismatch' => true,
                ], 403);
            }
        }

        $today = $this->todayDate();
        $jadwalHariIni = $this->getWorkSchedule($user, $today);
        $isWfhScheduled = ! empty($jadwalHariIni['is_wfh']);
        $isFieldScheduled = ! empty($jadwalHariIni['is_field']);

        // Mode (a): mobile diblokir → gunakan perangkat presensi kantor (kecuali WFH global / shift WFH terjadwal)
        if (! $user->canWfh() && ! $isWfhScheduled) {
            return response()->json([
                'message' => 'Presensi aplikasi hanya untuk karyawan WFH atau lapangan. Presensi di kantor dilakukan melalui perangkat presensi.',
            ], 403);
        }

        // Cegah presensi jika user sedang cuti, sakit, atau izin hari ini
        // (leave_type 'wfh' dikecualikan — itu mode kerja dari rumah, bukan izin tidak masuk)
        $activeLeave = LeaveRequest::where('user_id', $user->id)
            ->where('status', 'approved')
            ->where('start_date', '<=', $today)
            ->where('end_date', '>=', $today)
            ->whereIn('leave_type', ['cuti', 'sakit', 'izin'])
            ->first();

        if ($activeLeave) {
            $leaveLabel = match ($activeLeave->leave_type) {
                'cuti'  => 'cuti',
                'sakit' => 'sakit',
                'izin'  => 'izin',
                default => $activeLeave->leave_type,
            };
            return response()->json([
                'message'    => "Anda tidak dapat melakukan presensi karena sedang dalam status {$leaveLabel} hari ini.",
                'leave_type' => $activeLeave->leave_type,
                'start_date' => $activeLeave->start_date,
                'end_date'   => $activeLeave->end_date,
            ], 403);
        }

        // Cegah double check-in
        $existing = Attendance::where('user_id', $user->id)->whereDate('date', $today)->first();
        if ($existing && $existing->check_in_time) {
            return response()->json(['message' => 'Anda sudah check-in hari ini.'], 409);
        }

        // Validasi batas waktu presensi telat (late_checkin_cutoff_minutes)
        // Jika kantor mengatur batas maks telat, presensi ditolak setelah jam batas tersebut.
        // Contoh: jam masuk 08:00, cutoff 120 menit → presensi ditutup setelah 10:00 WIB.
        // Berlaku untuk semua mode (WFH, lapangan, dll.) kecuali jika cutoff = null (tidak dibatasi).
        $officeForCutoff  = $jadwalHariIni['office'];
        $jamMasukCutoff   = $jadwalHariIni['work_start_time'];
        if (
            $officeForCutoff
            && $officeForCutoff->late_checkin_cutoff_minutes !== null
            && $jamMasukCutoff
        ) {
            $nowWib       = now('Asia/Jakarta');
            $tanggalWib   = $nowWib->toDateString();
            $workStartCutoff = Carbon::parse("{$tanggalWib} {$jamMasukCutoff}", 'Asia/Jakarta');
            $cutoffTime      = $workStartCutoff->copy()->addMinutes($officeForCutoff->late_checkin_cutoff_minutes);

            if ($nowWib->gt($cutoffTime)) {
                return response()->json([
                    'message'         => "Presensi sudah ditutup. Batas presensi masuk hari ini sampai jam {$cutoffTime->format('H:i')} WIB ({$officeForCutoff->late_checkin_cutoff_minutes} menit setelah jam masuk).",
                    'cutoff_at'       => $cutoffTime->format('H:i'),
                    'work_start_time' => $workStartCutoff->format('H:i'),
                    'cutoff_minutes'  => $officeForCutoff->late_checkin_cutoff_minutes,
                ], 403);
            }
        }

        // Cegah tumpang tindih shift: bila ada shift LINTAS HARI dari KEMARIN yang belum
        // di-checkout, karyawan wajib menyelesaikan (check-out) shift malam itu dulu.
        // Menjamin aturan "satu shift per hari" untuk sistem shift 24 jam.
        $crossDayKemarin = \App\Http\Controllers\API\ShiftController::resolveYesterdayCrossDay($user, $today);
        if ($crossDayKemarin) {
            $yesterday   = Carbon::parse($today)->subDay()->toDateString();
            $shiftTerbuka = Attendance::where('user_id', $user->id)
                ->whereDate('date', $yesterday)
                ->whereNotNull('check_in_time')
                ->whereNull('check_out_time')
                ->first();

            if ($shiftTerbuka) {
                return response()->json([
                    'message' => 'Anda masih memiliki shift malam kemarin yang belum di-checkout. Silakan check-out terlebih dahulu sebelum memulai presensi baru.',
                    'pending_attendance_id' => $shiftTerbuka->id,
                    'pending_shift_date'    => $yesterday,
                ], 409);
            }
        }

        $distanceMeters = null;
        $checkInType    = 'wfh';

        // Mode WFH / Shift WFH: validasi window waktu presensi
        // Cegah check-in terlalu dini (mis. subuh/malam setelah tengah malam reset).
        // Gunakan jam masuk dari shift aktif jika ada; fallback ke kantor.
        if (! $user->hasRadiusEnabled() || $isWfhScheduled) {
            $officeRef = $jadwalHariIni['office'];
            $jamMasuk  = $jadwalHariIni['work_start_time'];

            if ($officeRef
                && $officeRef->wfh_checkin_window_minutes !== null
                && $jamMasuk
            ) {
                $nowWib      = now('Asia/Jakarta');
                $tanggalWib  = $nowWib->toDateString();
                $workStart   = Carbon::parse("{$tanggalWib} {$jamMasuk}", 'Asia/Jakarta');
                $windowOpens = $workStart->copy()->subMinutes($officeRef->wfh_checkin_window_minutes);

                if ($nowWib->lt($windowOpens)) {
                    return response()->json([
                        'message'         => "Presensi WFH belum bisa dilakukan. Silakan presensi mulai jam {$windowOpens->format('H:i')} WIB.",
                        'window_open_at'  => $windowOpens->format('H:i'),
                        'work_start_time' => $workStart->format('H:i'),
                    ], 403);
                }
            }
        }

        // Mode (c): lapangan — validasi radius terhadap lokasi kantor terdekat
        // Berjalan jika: (1) user memiliki radius_enabled=true & bukan hari WFH murni, ATAU (2) hari ini adalah shift Lapangan terjadwal (is_field = true)
        $needRadiusCheck = $isFieldScheduled || ($user->hasRadiusEnabled() && ! $isWfhScheduled);

        // Kantor acuan presensi hari ini — dipakai untuk validasi radius & snapshot.
        // Default: kantor penempatan karyawan; ditimpa kantor TERDEKAT bila radius check berjalan.
        $acuanOffice = $jadwalHariIni['office'];

        if ($needRadiusCheck) {
            $offices = AttendanceSetting::where('company_id', $user->company_id)->get();

            if ($offices->isEmpty()) {
                return response()->json([
                    'message' => 'Validasi radius tidak bisa dilakukan: belum ada pengaturan lokasi kantor. Hubungi HRD.',
                ], 422);
            }

            $locationService = app(LocationService::class);
            $lat             = (float) $validated['latitude'];
            $lng             = (float) $validated['longitude'];

            // Cari kantor terdekat dari posisi karyawan
            $nearest  = null;
            $minDist  = PHP_FLOAT_MAX;
            foreach ($offices as $office) {
                $dist = $locationService->calculateDistance($lat, $lng, (float) $office->office_latitude, (float) $office->office_longitude);
                if ($dist < $minDist) {
                    $minDist = $dist;
                    $nearest = $office;
                }
            }

            $distanceMeters = (int) round($minDist);

            // Kantor acuan = kantor terdekat (acuan radius check-in & checkout)
            $acuanOffice = $nearest;

            if ($minDist > $nearest->radius_meters) {
                return response()->json([
                    'message'          => "Anda berada di luar area kerja. Jarak Anda {$distanceMeters} meter, batas radius {$nearest->radius_meters} meter dari {$nearest->office_name}.",
                    'distance_meters'  => $distanceMeters,
                    'radius_meters'    => $nearest->radius_meters,
                    'office_name'      => $nearest->office_name,
                ], 403);
            }

            $checkInType = $isFieldScheduled ? 'onsite' : 'field';
        }

        // Ambil jadwal efektif untuk menentukan status (hadir/telat) & reminder
        $jadwalHariIni = $this->getWorkSchedule($user, $today);
        $status        = $this->determineStatus($user, now(), $today);

        // Jam pulang efektif hari ini (shift aktif; fallback jam pulang kantor pada
        // hari libur shift). Dipakai untuk snapshot & jadwal notifikasi Flutter.
        $office    = $jadwalHariIni['office'];
        $jamPulang = $jadwalHariIni['work_end_time'];
        if ($office && ! $jamPulang && ! empty($office->work_end_time)) {
            $jamPulang = $office->work_end_time;
        }

        $attendance = Attendance::updateOrCreate(
            ['user_id' => $user->id, 'date' => $today],
            [
                'company_id'               => $user->company_id,
                'check_in_time'            => now(),
                'check_in_lat'             => $validated['latitude'],
                'check_in_lng'             => $validated['longitude'],
                'check_in_distance_meters' => $distanceMeters,
                'check_in_type'            => $checkInType,
                'status'                   => $status,
                // SNAPSHOT: bekukan aturan yang berlaku saat check-in (jam kerja,
                // kantor acuan, lembur, toleransi, auto-checkout). Perubahan setting
                // HRD di siang hari tidak lagi mempengaruhi record ini — lihat
                // doc/rules.md "Snapshot Acuan Jam Kerja".
            ] + Attendance::make()->buildSnapshot($jadwalHariIni, $jamPulang, $acuanOffice)
        );

        // ─── Queue Job: activity log & notifikasi di background ─────────
        // Operasi ini dilempar ke antrean agar response check-in tetap instan
        // saat peak hour (50+ karyawan bersamaan).
        ProcessAttendanceBackgroundJob::dispatch(
            $user->id,
            $user->company_id,
            'attendance_check_in',
            "Check-in ({$checkInType}) status {$status}",
            'attendance',
            $attendance->id,
        );

        // Hitung jadwal reminder & auto-checkout untuk Flutter (scheduling notif lokal).
        $reminderAt        = null;
        $autoCheckoutAt    = null;

        if ($office && $jamPulang) {
            $graceMinutes    = (int) ($office->auto_checkout_grace_minutes ?? 60);
            $reminderMinutes = (int) ($office->checkout_reminder_minutes ?? 30);
            // Shift lintas hari (cross-day): jam pulang berada di hari BERIKUTNYA setelah tanggal check-in.
            // Tanpa ini, shift malam 22:00–06:00 akan menjadwalkan reminder/auto-checkout ke 06:00 hari ini (salah).
            $isCrossDay  = ! empty($jadwalHariIni['is_cross_day']);
            $workEndDate = $isCrossDay
                ? Carbon::parse($today, 'Asia/Jakarta')->addDay()->toDateString()
                : $today;
            $workEnd         = Carbon::parse($workEndDate . ' ' . $jamPulang, 'Asia/Jakarta');
            $reminderAt      = $workEnd->copy()->addMinutes($reminderMinutes)->toIso8601String();
            $autoCheckoutAt  = $workEnd->copy()->addMinutes($graceMinutes)->toIso8601String();
        }

        return response()->json([
            'message'    => 'Check-in berhasil.',
            'attendance' => $attendance->only([
                'id', 'date', 'check_in_time', 'check_in_type',
                'check_in_distance_meters', 'status',
            ]),
            // Jadwal shift aktif yang berlaku hari ini (untuk tampilan di Flutter)
            'active_shift' => $jadwalHariIni['source'] === 'shift' ? [
                'shift_id'        => $jadwalHariIni['shift_id'],
                'shift_name'      => $jadwalHariIni['shift_name'],
                'work_start_time' => $jadwalHariIni['work_start_time'],
                'work_end_time'   => $jadwalHariIni['work_end_time'],
            ] : null,
            // Info untuk Flutter menjadwalkan notifikasi lokal
            'reminder_at'      => $reminderAt,
            'auto_checkout_at' => $autoCheckoutAt,
        ], 201);
    }

    // 7. checkOut() — presensi pulang
    public function checkOut(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'latitude'  => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
        ]);

        $user  = $request->user();

        // ─── Validasi Device Binding (anti titip absen lewat HP rekan) ───────
        if ($request->header('X-Platform') === 'mobile' && config('app.device_binding_enabled', true)) {
            $reqDeviceId = $request->header('X-Device-Id') ?? $request->input('device_id');
            if ($user->device_id && $reqDeviceId && $user->device_id !== $reqDeviceId) {
                return response()->json([
                    'message'         => 'Check-out ditolak. Akun Anda terikat pada perangkat lain'
                        . ($user->device_name ? " ({$user->device_name})" : '')
                        . '.',
                    'device_mismatch' => true,
                ], 403);
            }
        }

        $today = $this->todayDate();

        // 1. Cari record hari ini yang belum di-checkout (shift normal)
        $attendance = Attendance::where('user_id', $user->id)
            ->whereDate('date', $today)
            ->whereNull('check_out_time')
            ->first();

        // 2. Jika tidak ada, cek shift lintas hari (cross-day) dari KEMARIN yang masih terbuka.
        //    Shift malam 22:00 (Jumat) -> check-out 06:00 (Sabtu): record ada di tanggal Jumat.
        //    scheduleDate menyimpan tanggal shift asli untuk perhitungan lembur.
        $scheduleDate = (string) $today;
        if (! $attendance) {
            $crossDay = \App\Http\Controllers\API\ShiftController::resolveYesterdayCrossDay($user, (string) $today);
            if ($crossDay) {
                $yesterday  = Carbon::parse($today)->subDay()->toDateString();
                $attendance = Attendance::where('user_id', $user->id)
                    ->whereDate('date', $yesterday)
                    ->whereNull('check_out_time')
                    ->first();
                if ($attendance) {
                    $scheduleDate = $yesterday;
                }
            }
        }

        // 3. Belum ada record terbuka -> tentukan pesan yang tepat
        if (! $attendance || ! $attendance->check_in_time) {
            $already = Attendance::where('user_id', $user->id)->whereDate('date', $today)->first();
            if ($already && $already->check_out_time) {
                return response()->json(['message' => 'Anda sudah check-out hari ini.'], 409);
            }
            return response()->json(['message' => 'Anda belum check-in hari ini.'], 403);
        }

        // ─── Validasi Jeda Minimal Check-out (Cooldown Buffer) ───────────────
        // Mencegah accidental tap / presensi kilat yang merusak rekap jam kerja.
        // Batas menit diambil dari snapshot attendance, fallback setting kantor, default 10 mnt.
        $snapOffice = $attendance->hasSnapshot() ? $attendance->snapshotOffice() : null;
        $minIntervalMinutes = $attendance->snap_min_checkout_interval_minutes
            ?? $snapOffice?->min_checkout_interval_minutes
            ?? ($user->attendance_setting_id ? \App\Models\AttendanceSetting::find($user->attendance_setting_id)?->min_checkout_interval_minutes : null)
            ?? 10;

        if ($minIntervalMinutes > 0 && $attendance->check_in_time) {
            $checkInCarbon = Carbon::parse($attendance->check_in_time)->setTimezone('Asia/Jakarta');
            $nowWib        = now('Asia/Jakarta');
            $diffSeconds   = $checkInCarbon->diffInSeconds($nowWib, false);
            $requiredSecs  = $minIntervalMinutes * 60;

            if ($diffSeconds < $requiredSecs) {
                $earliestCheckoutTime = $checkInCarbon->copy()->addMinutes($minIntervalMinutes);
                $remainingMinutes     = max(1, (int) ceil(($requiredSecs - $diffSeconds) / 60));

                return response()->json([
                    'message'              => "Check-out belum dapat dilakukan. Minimal durasi kehadiran adalah {$minIntervalMinutes} menit setelah check-in. Silakan coba lagi pukul {$earliestCheckoutTime->format('H:i')} WIB (sisa {$remainingMinutes} menit).",
                    'min_checkout_minutes' => $minIntervalMinutes,
                    'check_in_time'        => $checkInCarbon->format('H:i:s'),
                    'earliest_checkout_at' => $earliestCheckoutTime->format('H:i'),
                    'remaining_minutes'    => $remainingMinutes,
                    'is_cooldown'          => true,
                ], 422);
            }
        }

        $checkOutTime = now();

        // ─── Validasi GPS radius checkout (konsisten dengan check-in) ────────
        // Jika karyawan check-in sebagai 'onsite' atau 'field' (bukan WFH),
        // checkout juga harus berada dalam radius kantor terdekat.
        // Device binding sudah mengurangi risiko, tetapi tanpa validasi ini
        // karyawan bisa checkout dari lokasi mana saja.
        //
        // SNAPSHOT (2026-08-26): bila record punya snapshot, validasi memakai
        // koordinat & radius kantor acuan SAAT CHECK-IN — HRD yang memindah/
        // memperkecil radius kantor di siang hari tidak menolak checkout
        // karyawan yang masuk pagi dengan aturan lama.
        $checkInType = $attendance->check_in_type;
        if (in_array($checkInType, ['onsite', 'field'])) {
            $lat = (float) $validated['latitude'];
            $lng = (float) $validated['longitude'];
            $locationService = app(LocationService::class);

            if ($attendance->hasSnapshot()) {
                // Jalur snapshot: satu kantor acuan yang dibekukan saat check-in
                $snapOffice = $attendance->snapshotOffice();

                if ($snapOffice && $attendance->snap_office_latitude !== null) {
                    $distanceMeters = (int) round($locationService->calculateDistance(
                        $lat,
                        $lng,
                        (float) $attendance->snap_office_latitude,
                        (float) $attendance->snap_office_longitude
                    ));

                    $radiusMeters = (int) ($attendance->snap_radius_meters ?? 0);

                    if ($distanceMeters > $radiusMeters) {
                        return response()->json([
                            'message'          => "Check-out ditolak: Anda berada di luar area kerja. Jarak Anda {$distanceMeters} meter, batas radius {$radiusMeters} meter dari {$snapOffice->office_name}.",
                            'distance_meters'  => $distanceMeters,
                            'radius_meters'    => $radiusMeters,
                            'office_name'      => $snapOffice->office_name,
                        ], 403);
                    }
                }
                // Snapshot tanpa koordinat kantor (kasus langka: setting lama kosong)
                // → lewati validasi radius, jangan blokir checkout.
            } else {
                // Baris lama (sebelum fitur snapshot) → perilaku lama: cari kantor terdekat live
                $offices = AttendanceSetting::where('company_id', $user->company_id)->get();

                if ($offices->isNotEmpty()) {
                    $nearest  = null;
                    $minDist  = PHP_FLOAT_MAX;
                    foreach ($offices as $office) {
                        $dist = $locationService->calculateDistance(
                            $lat, $lng,
                            (float) $office->office_latitude,
                            (float) $office->office_longitude
                        );
                        if ($dist < $minDist) {
                            $minDist = $dist;
                            $nearest = $office;
                        }
                    }

                    $distanceMeters = (int) round($minDist);

                    if ($minDist > $nearest->radius_meters) {
                        return response()->json([
                            'message'          => "Check-out ditolak: Anda berada di luar area kerja. Jarak Anda {$distanceMeters} meter, batas radius {$nearest->radius_meters} meter dari {$nearest->office_name}.",
                            'distance_meters'  => $distanceMeters,
                            'radius_meters'    => $nearest->radius_meters,
                            'office_name'      => $nearest->office_name,
                        ], 403);
                    }
                }
            }
        }

        // ─── Hitung jam kerja & lembur otomatis ──────────────────
        // Titik awal jam kerja = jam jadwal masuk (bukan jam check-in).
        // Jika karyawan terlambat → titik awal = jam check-in aktual.
        // Jika karyawan datang sebelum jadwal → titik awal = jam jadwal.
        //
        // SNAPSHOT (2026-08-26): pakai aturan yang dibekukan saat check-in bila ada,
        // agar edit setting HRD di siang hari tidak mengubah hasil checkout.
        $schedule       = $attendance->snapshotSchedule() ?? $this->getWorkSchedule($user, $scheduleDate);
        $workStart      = $this->resolveWorkStart($attendance->check_in_time, $schedule, $scheduleDate);
        $workMinutes    = (int) $workStart->diffInMinutes($checkOutTime->copy()->setTimezone('Asia/Jakarta'));

        // isNonWorkingDay: apakah tanggal shift libur nasional/weekend secara kalender.
        // Pass $user->id agar cuti bersama yang di-decline tidak dianggap hari libur karyawan ini.
        $nonWorking      = $this->isNonWorkingDay($scheduleDate, $user->company_id, null, $user->id);
        // calculateOvertime & checkEarlyLeave sudah mempertimbangkan shift aktif karyawan (cross-day aware)
        $overtimeMinutes = $this->calculateOvertime($user, $scheduleDate, $checkOutTime, $workMinutes, $nonWorking, $schedule);
        $isEarlyLeave    = $this->checkEarlyLeave($user, $scheduleDate, $checkOutTime, $nonWorking, $schedule);

        $updateData = [
            'check_out_time'   => $checkOutTime,
            'check_out_lat'    => $validated['latitude'],
            'check_out_lng'    => $validated['longitude'],
            'check_out_type'   => $attendance->check_in_type,
            'work_minutes'     => $workMinutes,
            'overtime_minutes' => $overtimeMinutes,
            'is_holiday'       => $nonWorking,
        ];

        // Tandai early leave — tidak berlaku di hari libur/weekend
        if ($isEarlyLeave) {
            $updateData['status'] = 'early_leave';
        }

        $attendance->update($updateData);
        $attendance->refresh();

        // ─── Queue Job: activity log di background ─────────────────────
        ProcessAttendanceBackgroundJob::dispatch(
            $user->id,
            $user->company_id,
            'attendance_check_out',
            'Check-out',
            'attendance',
            $attendance->id,
        );

        return response()->json([
            'message'    => 'Check-out berhasil.',
            'attendance' => $attendance->only([
                'id', 'date', 'check_in_time', 'check_out_time', 'status',
                'work_minutes', 'overtime_minutes', 'is_holiday',
            ]),
        ]);
    }

    // ─── Helper: hitung menit lembur saat check-out ──────────────
    //     Mempertimbangkan shift aktif karyawan:
    //     - Jika shift menandai hari ini is_off=true  → seluruh menit kerja jadi lembur,
    //       TAPI tetap harus mencapai min_overtime_minutes (anti-noise: masuk sebentar
    //       di hari libur tidak langsung menjadi pengajuan lembur).
    //     - Jika shift menandai hari ini is_off=false → lembur dihitung setelah jam pulang SHIFT
    //       (berlaku meski hari ini adalah weekend/libur nasional — karyawan memang dijadwalkan masuk).
    //     - Fallback ke perilaku lama jika tidak ada shift: hari libur/weekend → full lembur
    //       (dengan minimum yang sama); hari kerja → selisih menit melewati work_end_time kantor.
    //
    //     $schedule (opsional): jadwal yang sudah di-resolve caller — bila diisi,
    //     helper TIDAK me-resolve ulang (dipakai jalur snapshot checkout).
    private function calculateOvertime(User $user, string $date, Carbon $checkOutTime, int $workMinutes, bool $isNationalNonWorking, ?array $schedule = null): int
    {
        $schedule = $schedule ?? $this->getWorkSchedule($user, $date);
        $office   = $schedule['office'];

        // Tanpa setting kantor atau lembur dimatikan → tidak ada lembur.
        if (! $office || ! $office->overtime_enabled) {
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

        // Kasus 3: hari kerja efektif (dari shift atau default kantor)
        //          → hitung lembur setelah jam pulang yang berlaku
        $jamPulangStr = $schedule['work_end_time'];
        if (! $jamPulangStr) {
            return 0;
        }

        // Shift lintas hari (cross-day, mis. 22:00–06:00): jam pulang berada di HARI BERIKUTNYA.
        $jamPulangDate = ! empty($schedule['is_cross_day'])
            ? Carbon::parse($date, 'Asia/Jakarta')->addDay()->toDateString()
            : $date;

        $jamPulang   = Carbon::parse($jamPulangDate . ' ' . $jamPulangStr, 'Asia/Jakarta');
        $checkOutWib = $checkOutTime->copy()->setTimezone('Asia/Jakarta');

        $lewat = $checkOutWib->greaterThan($jamPulang)
            ? (int) $jamPulang->diffInMinutes($checkOutWib)
            : 0;

        return $lewat >= (int) $office->min_overtime_minutes ? $lewat : 0;
    }

    // ─── Helper: deteksi pulang lebih awal (early leave) ────────
    //     Mempertimbangkan shift aktif: pakai jam pulang shift jika ada.
    //     Tidak berlaku di hari libur (per jadwal shift atau kalender).
    //     $schedule (opsional): jadwal dari caller (jalur snapshot checkout).
    private function checkEarlyLeave(User $user, string $date, Carbon $checkOutTime, bool $isNationalNonWorking, ?array $schedule = null): bool
    {
        $schedule = $schedule ?? $this->getWorkSchedule($user, $date);
        $office   = $schedule['office'];

        // Hari libur per jadwal shift → tidak ada konsep pulang awal
        if ($schedule['is_off']) {
            return false;
        }

        // Hari libur nasional/weekend tanpa shift → tidak ada konsep pulang awal
        if ($schedule['source'] === 'office' && $isNationalNonWorking) {
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
            ? Carbon::parse($date, 'Asia/Jakarta')->addDay()->toDateString()
            : $date;

        $toleransi   = (int) $office->early_leave_tolerance_minutes;
        $jamPulang   = Carbon::parse($jamPulangDate . ' ' . $jamPulangStr, 'Asia/Jakarta');
        $batasAwal   = $jamPulang->copy()->subMinutes($toleransi);
        $checkOutWib = $checkOutTime->copy()->setTimezone('Asia/Jakarta');

        return $checkOutWib->lessThan($batasAwal);
    }

    // ─── Helper: buat record OvertimeApproval & notifikasi HRD ──────────────
    //     Dipanggil saat checkout (manual/auto) jika ada overtime_minutes > 0.
    private function createOvertimeApproval(Attendance $attendance, bool $isAutoCheckout = false): void
    {
        // Jangan duplikat jika sudah ada
        if (OvertimeApproval::where('attendance_id', $attendance->id)->exists()) {
            return;
        }

        $overtimeReason = $this->resolveOvertimeReason($attendance, $isAutoCheckout);

        $approval = OvertimeApproval::create([
            'attendance_id'   => $attendance->id,
            'user_id'         => $attendance->user_id,
            'company_id'      => $attendance->company_id,
            'overtime_minutes'=> $attendance->overtime_minutes,
            'status'          => 'pending',
            'is_auto_checkout'=> $isAutoCheckout,
            'overtime_reason' => $overtimeReason,
        ]);

        // Cari info user karyawan
        $employee = User::find($attendance->user_id);
        $overtimeFormatted = $this->formatMinutes($attendance->overtime_minutes);
        $tanggal = Carbon::parse($attendance->date)->format('d/m/Y');

        // Notifikasi ke semua HRD/admin/super_admin perusahaan
        $approvers = DB::table('users')
            ->where('company_id', $attendance->company_id)
            ->whereIn('role', ['hrd', 'admin', 'super_admin'])
            ->where('is_active', true)
            ->pluck('id');

        foreach ($approvers as $approverId) {
            $this->notifyUser($approverId, 'overtime_pending', [
                'message'          => ($employee ? $employee->name : 'Karyawan') . " mengajukan lembur {$overtimeFormatted} ({$tanggal})." . ($isAutoCheckout ? ' [Auto-Checkout]' : ''),
                'overtime_id'      => $approval->id,
                'attendance_id'    => $attendance->id,
                'user_id'          => $attendance->user_id,
                'user_name'        => $employee ? $employee->name : null,
                'overtime_minutes' => $attendance->overtime_minutes,
                'is_auto_checkout' => $isAutoCheckout,
                'overtime_reason'  => $overtimeReason,
                'date'             => $tanggal,
            ], 'overtime_approval', $approval->id);
        }
    }

    // ─── Helper: tentukan alasan lembur otomatis untuk info HRD ─────────────
    private function resolveOvertimeReason(Attendance $attendance, bool $isAutoCheckout): ?string
    {
        if ($isAutoCheckout) {
            return 'Lupa checkout (auto-checkout oleh sistem)';
        }

        $user = User::find($attendance->user_id);
        $date = (string) $attendance->date;

        if ($user) {
            $schedule = $this->getWorkSchedule($user, $date);

            // Shift menandai hari ini libur (is_off)
            if ($schedule['is_off']) {
                return 'Masuk di hari libur (jadwal shift libur)';
            }
        }

        // Hari libur nasional/weekend
        if ($attendance->is_holiday) {
            return 'Masuk di hari libur';
        }

        return null;
    }

    // ─── Helper: kirim FCM push notification (fire-and-forget) ─────────────
    //     Jika FIREBASE_SERVER_KEY tidak dikonfigurasi, langkah ini dilewati.
    private function sendFcmPush(string $fcmToken, string $title, string $body, array $data = []): void
    {
        app(FcmService::class)->send($fcmToken, $title, $body, $data);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // BAGIAN B2 — Endpoint tambahan untuk sistem auto-checkout & overtime
    // ─────────────────────────────────────────────────────────────────────────

    // registerFcmToken() — simpan FCM token device karyawan (dipanggil saat login/buka app)
    public function registerFcmToken(Request $request): JsonResponse
    {
        $request->validate(['fcm_token' => 'required|string|max:512']);
        $token = $request->fcm_token;
        $currentUser = $request->user();

        // Lepaskan token ini dari semua akun lain yang sebelumnya login di HP ini
        User::where('id', '!=', $currentUser->id)
            ->where('fcm_token', $token)
            ->update(['fcm_token' => null]);

        $currentUser->update(['fcm_token' => $token]);

        return response()->json(['message' => 'FCM token berhasil disimpan.']);
    }

    // checkStatus() — cek status presensi hari ini (dipakai Flutter untuk polling)
    //     Mengembalikan info checkout, overtime, dan jadwal auto-checkout.
    public function checkStatus(Request $request): JsonResponse
    {
        $user  = $request->user();
        $today = $this->todayDate();

        $attendance = Attendance::where('user_id', $user->id)
            ->whereDate('date', $today)
            ->first();

        // Jika tidak ada presensi hari ini, cek apakah ada presensi KEMARIN yang masih terbuka (belum checkout) 
        // khusus untuk shift lintas hari (cross-day).
        if (! $attendance) {
            $crossDay = \App\Http\Controllers\API\ShiftController::resolveYesterdayCrossDay($user, (string) $today);
            if ($crossDay) {
                $yesterday = Carbon::parse($today)->subDay()->toDateString();
                $attendance = Attendance::where('user_id', $user->id)
                    ->whereDate('date', $yesterday)
                    ->whereNull('check_out_time')
                    ->first();
            }
        }

        // Kantor cabang tempat karyawan ditempatkan:
        // 1. Prioritas utama: $user->office (dari attendance_setting_id)
        // 2. Jika belum di-set: jadwal shift aktif karyawan pada tanggal terkait
        // 3. Fallback: kantor default pertama milik perusahaan
        $primaryOffice = $user->office;
        if (! $primaryOffice && $user->attendance_setting_id) {
            $primaryOffice = AttendanceSetting::find($user->attendance_setting_id);
        }
        if (! $primaryOffice) {
            $jadwalHariIni  = $this->getWorkSchedule($user, (string) ($attendance ? $attendance->date : $today));
            $primaryOffice  = $jadwalHariIni['office'] ?? null;
        }
        if (! $primaryOffice && $user->company_id) {
            $primaryOffice = AttendanceSetting::where('company_id', $user->company_id)->orderBy('id')->first();
        }

        $officeData = ($primaryOffice && $primaryOffice->office_latitude !== null && $primaryOffice->office_longitude !== null) ? [
            'id'             => $primaryOffice->id,
            'name'           => $primaryOffice->office_name,
            'latitude'       => (float) $primaryOffice->office_latitude,
            'longitude'      => (float) $primaryOffice->office_longitude,
            'radius_meters'  => (int) $primaryOffice->radius_meters,
            'require_selfie' => (bool) $primaryOffice->require_selfie,
        ] : null;

        $userOffices = $officeData ? [$officeData] : [];

        if (! $attendance || ! $attendance->check_in_time) {
            $jadwalHariIni = $this->getWorkSchedule($user, (string) $today);

            return response()->json([
                'checked_in'          => false,
                'checked_out'         => false,
                'attendance'          => null,
                'overtime_approval'   => null,
                'scheduled_auto_checkout_at' => null,
                // Flag WFH karyawan — dipakai Flutter untuk menampilkan/menyembunyikan
                // tombol "Catat Presensi" saat tombol Refresh ditekan.
                'wfh_enabled'         => (bool) $user->wfh_enabled,
                'radius_enabled'      => (bool) $user->radius_enabled,
                'office'              => $officeData,
                'offices'             => $userOffices,
                'active_shift'        => $jadwalHariIni['source'] === 'shift' ? [
                    'shift_id'        => $jadwalHariIni['shift_id'],
                    'shift_name'      => $jadwalHariIni['shift_name'],
                    'work_start_time' => $jadwalHariIni['work_start_time'],
                    'work_end_time'   => $jadwalHariIni['work_end_time'],
                ] : null,
            ]);
        }

        // Ambil jadwal efektif saat karyawan check-in.
        // SNAPSHOT (2026-08-26): bila record punya snapshot, PERHITUNGAN auto-checkout
        // memakai aturan saat check-in (tidak digeser edit setting siang hari);
        // tampilan shift aktif tetap dari resolve live agar info terkini.
        $scheduleDate   = $attendance->date;
        $jadwalHariIni  = $this->getWorkSchedule($user, $scheduleDate);
        $jadwalSnapshot = $attendance->snapshotSchedule();

        // Hitung waktu auto-checkout yang dijadwalkan (untuk Flutter scheduling notif lokal).
        $scheduledAutoCheckout = null;
        if (! $attendance->check_out_time) {
            $jadwalAcuan = $jadwalSnapshot ?? $jadwalHariIni;
            $office      = $jadwalAcuan['office'];
            $jamPulang   = $jadwalAcuan['work_end_time'];

            // Shift libur (is_off) → pakai jam pulang kantor, konsisten dengan AutoCheckoutCommand.
            // (Snapshot sudah memuat fallback ini, tapi tetap aman untuk jalur live.)
            if ($office && ! $jamPulang && ! empty($office->work_end_time)) {
                $jamPulang = $office->work_end_time;
            }

            if ($office && $jamPulang) {
                $graceMinutes = (int) ($office->auto_checkout_grace_minutes ?? 60);
                // Shift lintas hari: jam pulang berada di hari BERIKUTNYA setelah tanggal check-in.
                $isCrossDay  = ! empty($jadwalAcuan['is_cross_day']);
                $workEndDate = $isCrossDay
                    ? Carbon::parse($scheduleDate, 'Asia/Jakarta')->addDay()->toDateString()
                    : $scheduleDate;
                $workEnd               = Carbon::parse($workEndDate . ' ' . $jamPulang, 'Asia/Jakarta');
                $scheduledAutoCheckout = $workEnd->copy()->addMinutes($graceMinutes)->toIso8601String();
            }
        }

        $overtimeApproval = OvertimeApproval::where('attendance_id', $attendance->id)->first();

        // Jika karyawan sudah check-in tapi belum checkout, wfh_enabled selalu true
        // agar tombol checkout tetap muncul di Flutter meskipun HRD mematikan toggle WFH.
        // Toggle WFH yang dimatikan hanya berlaku untuk MENCEGAH check-in baru, bukan memblokir checkout.
        $isActiveSession = $attendance->check_in_time && ! $attendance->check_out_time;
        $wfhEnabledForResponse = $isActiveSession ? true : (bool) $user->wfh_enabled;

        return response()->json([
            'checked_in'  => true,
            'checked_out' => (bool) $attendance->check_out_time,
            'attendance'  => $attendance->only([
                'id', 'date', 'check_in_time', 'check_out_time',
                'status', 'work_minutes', 'overtime_minutes',
                'is_auto_checkout', 'auto_checkout_at',
            ]),
            // Flag WFH karyawan — dipakai Flutter untuk menampilkan/menyembunyikan
            // tombol "Catat Presensi" saat tombol Refresh ditekan.
            // Jika user sedang aktif (checked-in, belum checkout), paksa true.
            'wfh_enabled'    => $wfhEnabledForResponse,
            'radius_enabled' => (bool) $user->radius_enabled,
            'office'         => $officeData,
            'offices'        => $userOffices,
            // Jadwal shift aktif hari ini (untuk tampilan di Flutter)
            'active_shift' => $jadwalHariIni['source'] === 'shift' ? [
                'shift_id'        => $jadwalHariIni['shift_id'],
                'shift_name'      => $jadwalHariIni['shift_name'],
                'work_start_time' => $jadwalHariIni['work_start_time'],
                'work_end_time'   => $jadwalHariIni['work_end_time'],
            ] : null,
            'scheduled_auto_checkout_at' => $scheduledAutoCheckout,
            'overtime_approval' => $overtimeApproval ? $overtimeApproval->only([
                'id', 'overtime_minutes', 'status', 'reviewed_at', 'notes',
            ]) : null,
        ]);
    }

    // ═══════════════════════════════════════════════════════════
    // BAGIAN C — HRD: manajemen approval lembur
    // ═══════════════════════════════════════════════════════════

    // listOvertimeApprovals() — daftar pengajuan lembur untuk HRD (filter status/user/tanggal)
    public function listOvertimeApprovals(Request $request): JsonResponse
    {
        $actor = $request->user();

        $validated = $request->validate([
            'status'     => 'nullable|in:pending,approved,rejected',
            'user_id'    => 'nullable|integer',
            'start_date' => 'nullable|date',
            'end_date'   => 'nullable|date|after_or_equal:start_date',
            'per_page'   => 'nullable|integer|min:1|max:2000',
        ]);

        $limit = $request->query('per_page') ? (int) $request->query('per_page') : 2000;

        $approvals = OvertimeApproval::query()
            ->join('users', 'overtime_approvals.user_id', '=', 'users.id')
            ->join('attendances', 'overtime_approvals.attendance_id', '=', 'attendances.id')
            ->when(
                $actor->role !== 'super_admin',
                fn ($q) => $q->where('overtime_approvals.company_id', $actor->company_id)
            )
            ->when($validated['status'] ?? null, fn ($q, $s) => $q->where('overtime_approvals.status', $s))
            ->when($validated['user_id'] ?? null, fn ($q, $u) => $q->where('overtime_approvals.user_id', $u))
            ->when($validated['start_date'] ?? null, fn ($q, $d) => $q->where('attendances.date', '>=', $d))
            ->when($validated['end_date']   ?? null, fn ($q, $d) => $q->where('attendances.date', '<=', $d))
            ->select([
                'overtime_approvals.id',
                'overtime_approvals.attendance_id',
                'overtime_approvals.user_id',
                'users.name as user_name',
                'users.department',
                'attendances.date as attendance_date',
                'attendances.check_in_time',
                'attendances.check_out_time',
                'overtime_approvals.overtime_minutes',
                'overtime_approvals.status',
                'overtime_approvals.is_auto_checkout',
                'overtime_approvals.overtime_reason',
                'overtime_approvals.reviewed_at',
                'overtime_approvals.notes',
                'overtime_approvals.created_at',
            ])
            ->orderByDesc('attendances.date')
            ->paginate($limit);

        // Tambahkan format jam untuk kemudahan tampilan
        $approvals->getCollection()->transform(function ($a) {
            $a->overtime_formatted = $this->formatMinutes((int) $a->overtime_minutes);

            if ($a->check_in_time) {
                $a->check_in_time = \Carbon\Carbon::parse($a->check_in_time)->toJSON();
            }
            if ($a->check_out_time) {
                $a->check_out_time = \Carbon\Carbon::parse($a->check_out_time)->toJSON();
            }

            return $a;
        });

        return response()->json($approvals);
    }

    // approveOvertime() — HRD setujui lembur (overtime_minutes dikonfirmasi)
    public function approveOvertime(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();

        $validated = $request->validate([
            'notes' => 'nullable|string|max:1000',
        ]);

        $approval = OvertimeApproval::when(
            $actor->role !== 'super_admin',
            fn ($q) => $q->where('company_id', $actor->company_id)
        )->find($id);

        if (! $approval) {
            return response()->json(['message' => 'Data lembur tidak ditemukan.'], 404);
        }

        if ($approval->status !== 'pending') {
            return response()->json(['message' => 'Pengajuan lembur sudah diproses sebelumnya.'], 403);
        }

        $approval->update([
            'status'      => 'approved',
            'reviewed_by' => $actor->id,
            'reviewed_at' => now(),
            'notes'       => $validated['notes'] ?? null,
        ]);

        // overtime_minutes di attendances TETAP sesuai hitungan (sudah disetujui)
        $this->logActivity(
            $actor->id,
            $approval->company_id,
            'overtime_approved',
            "Approve lembur #{$approval->id} ({$this->formatMinutes($approval->overtime_minutes)}) karyawan #{$approval->user_id}",
            'overtime_approval',
            $approval->id
        );

        // Notifikasi ke karyawan
        $employee = User::find($approval->user_id);
        $tanggal  = Carbon::parse($approval->attendance->date)->format('d/m/Y');
        $this->notifyUser($approval->user_id, 'overtime_approved', [
            'message'          => "Lembur Anda ({$this->formatMinutes($approval->overtime_minutes)}) pada {$tanggal} telah disetujui.",
            'overtime_id'      => $approval->id,
            'overtime_minutes' => $approval->overtime_minutes,
            'status'           => 'approved',
        ], 'overtime_approval', $approval->id);

        // Kirim push notification FCM ke karyawan
        if ($employee && $employee->fcm_token) {
            $this->sendFcmPush(
                $employee->fcm_token,
                '✅ Lembur Disetujui',
                "Lembur {$this->formatMinutes($approval->overtime_minutes)} pada {$tanggal} telah disetujui oleh HRD.",
                ['type' => 'overtime_approved', 'overtime_id' => (string) $approval->id]
            );
        }

        return response()->json([
            'message'  => 'Lembur berhasil disetujui.',
            'approval' => $approval->only(['id', 'status', 'overtime_minutes', 'reviewed_at', 'notes']),
        ]);
    }

    // rejectOvertime() — HRD tolak lembur (overtime_minutes di attendance di-set 0)
    public function rejectOvertime(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'notes' => 'required|string|max:1000',
        ]);

        $actor = $request->user();

        $approval = OvertimeApproval::when(
            $actor->role !== 'super_admin',
            fn ($q) => $q->where('company_id', $actor->company_id)
        )->find($id);

        if (! $approval) {
            return response()->json(['message' => 'Data lembur tidak ditemukan.'], 404);
        }

        if ($approval->status !== 'pending') {
            return response()->json(['message' => 'Pengajuan lembur sudah diproses sebelumnya.'], 403);
        }

        $approval->update([
            'status'      => 'rejected',
            'reviewed_by' => $actor->id,
            'reviewed_at' => now(),
            'notes'       => $request->notes,
        ]);

        // Jika ditolak → reset overtime_minutes ke 0 di tabel attendances
        Attendance::where('id', $approval->attendance_id)
            ->update(['overtime_minutes' => 0]);

        $this->logActivity(
            $actor->id,
            $approval->company_id,
            'overtime_rejected',
            "Reject lembur #{$approval->id} karyawan #{$approval->user_id}: {$request->notes}",
            'overtime_approval',
            $approval->id
        );

        // Notifikasi ke karyawan
        $employee = User::find($approval->user_id);
        $tanggal  = Carbon::parse($approval->attendance->date)->format('d/m/Y');
        $this->notifyUser($approval->user_id, 'overtime_rejected', [
            'message'          => "Lembur Anda pada {$tanggal} tidak disetujui.",
            'overtime_id'      => $approval->id,
            'status'           => 'rejected',
            'notes'            => $request->notes,
        ], 'overtime_approval', $approval->id);

        // Kirim push notification FCM ke karyawan
        if ($employee && $employee->fcm_token) {
            $this->sendFcmPush(
                $employee->fcm_token,
                '❌ Lembur Ditolak',
                "Lembur pada {$tanggal} tidak disetujui. Alasan: {$request->notes}",
                ['type' => 'overtime_rejected', 'overtime_id' => (string) $approval->id]
            );
        }

        return response()->json([
            'message'  => 'Lembur ditolak. Jam lembur karyawan di-reset ke 0.',
            'approval' => $approval->only(['id', 'status', 'reviewed_at', 'notes']),
        ]);
    }

    // ─── DEVICE BINDING — approval pindah perangkat (cegah titip absen) ────────

    // listDeviceChanges() — daftar permintaan pindah device untuk HR.
    public function listDeviceChanges(Request $request): JsonResponse
    {
        $actor = $request->user();

        $status = $request->query('status'); // pending|approved|rejected|null(semua)
        $limit = $request->query('per_page') ? (int) $request->query('per_page') : 2000;

        $query = DeviceChangeRequest::with([
                'user:id,name,email,employee_code,department',
                'reviewer:id,name',
            ])
            ->when(
                $actor->role !== 'super_admin',
                fn ($q) => $q->where('company_id', $actor->company_id)
            )
            ->when(
                in_array($status, ['pending', 'approved', 'rejected'], true),
                fn ($q) => $q->where('status', $status)
            )
            ->orderByRaw("FIELD(status, 'pending', 'approved', 'rejected')")
            ->orderByDesc('created_at');

        return response()->json($query->paginate($limit));
    }

    // approveDeviceChange() — HR setujui pindah device: device baru GANTIKAN lama.
    public function approveDeviceChange(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();

        $validated = $request->validate([
            'notes' => 'nullable|string|max:1000',
        ]);

        $req = DeviceChangeRequest::when(
            $actor->role !== 'super_admin',
            fn ($q) => $q->where('company_id', $actor->company_id)
        )->find($id);

        if (! $req) {
            return response()->json(['message' => 'Permintaan pindah perangkat tidak ditemukan.'], 404);
        }

        if ($req->status !== 'pending') {
            return response()->json(['message' => 'Permintaan sudah diproses sebelumnya.'], 403);
        }

        // Ganti binding: device baru menggantikan device lama (1 akun = 1 device).
        $employee = User::find($req->user_id);
        if ($employee) {
            $employee->forceFill([
                'device_id'       => $req->new_device_id,
                'device_name'     => $req->new_device_name,
                'device_bound_at' => now(),
            ])->save();

            // Amankan: hapus token mobile lama agar device lama tak bisa dipakai lagi.
            $employee->tokens()->where('name', 'auth-token-mobile')->delete();
        }

        $req->update([
            'status'      => 'approved',
            'reviewed_by' => $actor->id,
            'reviewed_at' => now(),
            'notes'       => $validated['notes'] ?? null,
        ]);

        $this->logActivity(
            $actor->id,
            $req->company_id,
            'device_change_approved',
            "Approve pindah perangkat #{$req->id} karyawan #{$req->user_id} → {$req->new_device_name}",
            'device_change_request',
            $req->id
        );

        // Notifikasi ke karyawan.
        $this->notifyUser($req->user_id, 'device_change_approved', [
            'message'    => 'Permintaan pindah perangkat Anda telah disetujui. '
                . 'Silakan login kembali di perangkat baru.',
            'request_id' => $req->id,
            'status'     => 'approved',
        ], 'device_change_request', $req->id);

        return response()->json([
            'message' => 'Pindah perangkat disetujui. Perangkat lama tidak lagi bisa digunakan.',
            'request' => $req->only(['id', 'status', 'reviewed_at', 'notes']),
        ]);
    }

    // rejectDeviceChange() — HR tolak pindah device: binding lama tetap.
    public function rejectDeviceChange(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'notes' => 'required|string|max:1000',
        ]);

        $actor = $request->user();

        $req = DeviceChangeRequest::when(
            $actor->role !== 'super_admin',
            fn ($q) => $q->where('company_id', $actor->company_id)
        )->find($id);

        if (! $req) {
            return response()->json(['message' => 'Permintaan pindah perangkat tidak ditemukan.'], 404);
        }

        if ($req->status !== 'pending') {
            return response()->json(['message' => 'Permintaan sudah diproses sebelumnya.'], 403);
        }

        $req->update([
            'status'      => 'rejected',
            'reviewed_by' => $actor->id,
            'reviewed_at' => now(),
            'notes'       => $request->notes,
        ]);

        $this->logActivity(
            $actor->id,
            $req->company_id,
            'device_change_rejected',
            "Reject pindah perangkat #{$req->id} karyawan #{$req->user_id}: {$request->notes}",
            'device_change_request',
            $req->id
        );

        // Notifikasi ke karyawan.
        $this->notifyUser($req->user_id, 'device_change_rejected', [
            'message'    => 'Permintaan pindah perangkat Anda ditolak. '
                . 'Hubungi HR untuk informasi lebih lanjut.',
            'request_id' => $req->id,
            'status'     => 'rejected',
            'notes'      => $request->notes,
        ], 'device_change_request', $req->id);

        return response()->json([
            'message' => 'Permintaan pindah perangkat ditolak.',
            'request' => $req->only(['id', 'status', 'reviewed_at', 'notes']),
        ]);
    }

    // 8. myAttendance() — riwayat presensi user yang login
    public function myAttendance(Request $request): JsonResponse
    {
        $attendances = Attendance::where('user_id', $request->user()->id)
            ->with(['overtimeApproval:id,attendance_id,status,overtime_minutes,notes,reviewed_at,is_auto_checkout,overtime_reason'])
            ->select([
                'id', 'date', 'check_in_time', 'check_in_type', 'check_in_distance_meters',
                'check_out_time', 'check_out_type', 'status', 'notes',
                'work_minutes', 'overtime_minutes', 'is_holiday', 'is_auto_checkout',
            ])
            ->orderByDesc('date')
            ->paginate(30);

        // Tambahkan field overtime_approval ke setiap record:
        //   - null  : tidak ada lembur (overtime_minutes = 0 atau belum checkout)
        //   - object: status pending/approved/rejected beserta detailnya
        $attendances->getCollection()->transform(function ($att) {
            $oa = $att->overtimeApproval;
            $att->overtime_approval = $oa ? [
                'id'               => $oa->id,
                'status'           => $oa->status,           // pending | approved | rejected
                'overtime_minutes' => $oa->overtime_minutes,
                'notes'            => $oa->notes,
                'reviewed_at'      => $oa->reviewed_at,
                'is_auto_checkout' => $oa->is_auto_checkout,
                'overtime_reason'  => $oa->overtime_reason,
            ] : null;

            // Hapus relasi Eloquent dari payload (sudah dimap ke array di atas)
            unset($att->overtimeApproval);

            return $att;
        });

        $user = $request->user();
        $primaryOffice = $user->office;
        if (! $primaryOffice && $user->attendance_setting_id) {
            $primaryOffice = AttendanceSetting::find($user->attendance_setting_id);
        }
        if (! $primaryOffice && $user->company_id) {
            $primaryOffice = AttendanceSetting::where('company_id', $user->company_id)->orderBy('id')->first();
        }

        $officeData = ($primaryOffice && $primaryOffice->office_latitude !== null && $primaryOffice->office_longitude !== null) ? [
            'id'             => $primaryOffice->id,
            'name'           => $primaryOffice->office_name,
            'latitude'       => (float) $primaryOffice->office_latitude,
            'longitude'      => (float) $primaryOffice->office_longitude,
            'radius_meters'  => (int) $primaryOffice->radius_meters,
            'require_selfie' => (bool) $primaryOffice->require_selfie,
        ] : null;

        $responseData = $attendances->toArray();
        $responseData['wfh_enabled']    = (bool) $user->wfh_enabled;
        $responseData['radius_enabled'] = (bool) $user->radius_enabled;
        $responseData['office']         = $officeData;
        $responseData['offices']        = $officeData ? [$officeData] : [];

        return response()->json($responseData);
    }

    // myOvertimeApprovals() — riwayat pengajuan lembur milik karyawan yang login
    public function myOvertimeApprovals(Request $request): JsonResponse
    {
        $user = $request->user();

        $approvals = OvertimeApproval::where('overtime_approvals.user_id', $user->id)
            ->join('attendances', 'overtime_approvals.attendance_id', '=', 'attendances.id')
            ->select([
                'overtime_approvals.id',
                'overtime_approvals.attendance_id',
                'attendances.date as attendance_date',
                'attendances.check_in_time',
                'attendances.check_out_time',
                'overtime_approvals.overtime_minutes',
                'overtime_approvals.status',
                'overtime_approvals.is_auto_checkout',
                'overtime_approvals.overtime_reason',
                'overtime_approvals.reviewed_at',
                'overtime_approvals.notes',
                'overtime_approvals.created_at',
            ])
            ->orderByDesc('attendances.date')
            ->paginate(20);

        $approvals->getCollection()->transform(function ($a) {
            $a->overtime_formatted = $this->formatMinutes((int) $a->overtime_minutes);

            if ($a->check_in_time) {
                $a->check_in_time = \Carbon\Carbon::parse($a->check_in_time)->toJSON();
            }
            if ($a->check_out_time) {
                $a->check_out_time = \Carbon\Carbon::parse($a->check_out_time)->toJSON();
            }

            return $a;
        });

        return response()->json($approvals);
    }

    // ─── claimOvertime() — Karyawan mengajukan lembur dengan deskripsi ───────
    public function claimOvertime(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'required|string|max:1000',
        ], [
            'reason.required' => 'Deskripsi atau penjelasan lembur wajib diisi.',
        ]);

        $user = $request->user();
        $attendance = Attendance::where('id', $id)
            ->where('user_id', $user->id)
            ->first();

        if (! $attendance) {
            return response()->json(['message' => 'Data presensi tidak ditemukan.'], 404);
        }

        if ($attendance->overtime_minutes <= 0) {
            return response()->json(['message' => 'Presensi pada hari ini tidak memiliki jam lembur.'], 422);
        }

        // Cek apakah sudah ada OvertimeApproval
        $approval = OvertimeApproval::where('attendance_id', $attendance->id)->first();
        if ($approval && $approval->status === 'approved') {
            return response()->json(['message' => 'Lembur ini sudah disetujui sebelumnya.'], 422);
        }

        if ($approval) {
            $approval->update([
                'overtime_minutes' => $attendance->overtime_minutes,
                'status'           => 'pending',
                'overtime_reason'  => $validated['reason'],
                'notes'            => null,
                'reviewed_by'      => null,
                'reviewed_at'      => null,
            ]);
        } else {
            $approval = OvertimeApproval::create([
                'attendance_id'    => $attendance->id,
                'user_id'          => $attendance->user_id,
                'company_id'       => $attendance->company_id,
                'overtime_minutes' => $attendance->overtime_minutes,
                'status'           => 'pending',
                'is_auto_checkout' => (bool) $attendance->is_auto_checkout,
                'overtime_reason'  => $validated['reason'],
            ]);
        }

        $this->logActivity(
            $user->id,
            $user->company_id,
            'overtime_claimed',
            "Karyawan {$user->name} mengajukan lembur {$this->formatMinutes($attendance->overtime_minutes)}: {$validated['reason']}",
            'overtime_approval',
            $approval->id
        );

        // Notifikasi ke semua HRD/admin
        $overtimeFormatted = $this->formatMinutes($attendance->overtime_minutes);
        $tanggal = Carbon::parse($attendance->date)->format('d/m/Y');

        $approvers = DB::table('users')
            ->where('company_id', $attendance->company_id)
            ->whereIn('role', ['hrd', 'admin', 'super_admin'])
            ->where('is_active', true)
            ->pluck('id');

        foreach ($approvers as $approverId) {
            $this->notifyUser($approverId, 'overtime_pending', [
                'message'          => "{$user->name} mengajukan lembur {$overtimeFormatted} ({$tanggal}).",
                'overtime_id'      => $approval->id,
                'attendance_id'    => $attendance->id,
                'user_id'          => $attendance->user_id,
                'user_name'        => $user->name,
                'overtime_minutes' => $attendance->overtime_minutes,
                'is_auto_checkout' => (bool) $attendance->is_auto_checkout,
                'overtime_reason'  => $validated['reason'],
                'date'             => $tanggal,
            ], 'overtime_approval', $approval->id);
        }

        return response()->json([
            'message'  => 'Pengajuan lembur berhasil dikirim ke HRD.',
            'approval' => [
                'id'               => $approval->id,
                'status'           => $approval->status,
                'overtime_minutes' => $approval->overtime_minutes,
                'overtime_reason'  => $approval->overtime_reason,
            ],
        ]);
    }

    // ─── declineOvertime() — Karyawan menolak/membatalkan lembur ──────────────
    public function declineOvertime(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $attendance = Attendance::where('id', $id)
            ->where('user_id', $user->id)
            ->first();

        if (! $attendance) {
            return response()->json(['message' => 'Data presensi tidak ditemukan.'], 404);
        }

        // Reset lembur di attendances
        $attendance->update(['overtime_minutes' => 0]);

        // Hapus approval jika ada
        OvertimeApproval::where('attendance_id', $attendance->id)->delete();

        $this->logActivity(
            $user->id,
            $user->company_id,
            'overtime_declined',
            "Karyawan {$user->name} membatalkan lembur pada tanggal {$attendance->date}",
            'attendance',
            $attendance->id
        );

        return response()->json([
            'message' => 'Lembur pada hari tersebut telah dibatalkan.',
        ]);
    }

    // 8b. myLeaveBalance() — saldo cuti milik karyawan yang login
    public function myLeaveBalance(Request $request): JsonResponse
    {
        $user = $request->user();
        $year = (int) $request->query('year', now()->year);

        // Pastikan baris saldo ada: cuti (NON-AKTIF, quota 0 sampai HRD isi kuota manual
        // via tab Saldo Cuti) dan izin (tanpa batas, quota=0)
        LeaveBalance::firstOrCreate(
            ['user_id' => $user->id, 'year' => $year, 'leave_type' => 'cuti'],
            ['company_id' => $user->company_id, 'quota' => 0, 'used' => 0]
        );
        LeaveBalance::firstOrCreate(
            ['user_id' => $user->id, 'year' => $year, 'leave_type' => 'izin'],
            ['company_id' => $user->company_id, 'quota' => 0, 'used' => 0]
        );

        $balances = LeaveBalance::where('user_id', $user->id)
            ->where('year', $year)
            ->get()
            ->map(fn ($b) => [
                'leave_type' => $b->leave_type,
                'quota'      => $b->quota,
                'used'       => $b->used,
                'remaining'  => $b->quota - $b->used,
            ]);

        $office = null;
        if ($user->company_id) {
            $office = AttendanceSetting::where('company_id', $user->company_id)
                ->where('id', $user->attendance_setting_id)
                ->first();
                
            if (! $office) {
                $office = AttendanceSetting::where('company_id', $user->company_id)->orderBy('id')->first();
            }
        }

        $resetInfo = null;
        if ($office && $office->leave_reset_date) {
            $resetInfo = [
                'leave_reset_date'    => $office->leave_reset_date,
                'default_leave_quota' => $office->default_leave_quota,
            ];
        }

        return response()->json([
            'year'       => $year, 
            'balances'   => $balances,
            'reset_info' => $resetInfo
        ]);
    }

    // 8c. myLeaves() — riwayat pengajuan izin/cuti milik karyawan yang login
    public function myLeaves(Request $request): JsonResponse
    {
        $user = $request->user();

        $leaves = LeaveRequest::where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($l) => [
                'id'               => $l->id,
                'leave_type'       => $l->leave_type,
                'start_date'       => $l->start_date,
                'end_date'         => $l->end_date,
                'total_days'       => $l->total_days,
                'reason'           => $l->reason,
                'status'           => $l->status,
                'rejection_reason' => $l->rejection_reason,
                'has_document'     => ! empty($l->document_path),
                'created_at'       => $l->created_at,
            ]);

        return response()->json(['leaves' => $leaves]);
    }

    // ─── Helper: hitung hari EFEKTIF pengajuan cuti + alasan skip per tanggal ────
    //     DIPAKAI BERSAMA oleh leavePreview() (mobile) & requestLeave().
    //     Sumber skip (konsisten dgn KEBIJAKAN EFEKTIF-HARI 2026-08-26):
    //       - holiday_or_off_day : libur nasional/perusahaan/cabang, cuti bersama
    //         yang di-accept, libur mingguan kantor default, off-day shift efektif
    //       - already_requested  : cuti pribadi sendiri yang sudah diajukan
    //         (pending/approved), dengan detail jenis pengajuan lama
    //     Return: [
    //       'total_days'     => int,
    //       'effective_dates'=> string[],   // tanggal yang terhitung
    //       'skipped'        => [ ['date'=>'Y-m-d','reason'=>..,'detail'=>..], .. ],
    //     ]
    private function effectiveLeaveDays(User $user, Carbon $start, Carbon $end): array
    {
        // Daftar tanggal kalender dalam rentang (inklusif)
        $requestedDates = [];
        for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
            $requestedDates[] = $d->toDateString();
        }

        // Tanggal efektif dasar: workingDatesBetween() menangani libur
        // nasional/perusahaan/cabang, cuti bersama accepted, libur mingguan
        // kantor default & off-day shift (shift-aware per tanggal).
        $effectiveDates = $this->workingDatesBetween(
            $start,
            $end,
            $user->company_id,
            $user->attendance_setting_id,
            $user->id,
            $user
        );

        // Skip tanggal personal leave sendiri yang SUDAH diajukan (pending/approved),
        // per tanggal. whereNull('holiday_id') → cuti bersama tidak memblokir.
        $existingLeaves = LeaveRequest::where('user_id', $user->id)
            ->whereIn('status', ['pending', 'approved'])
            ->whereNull('holiday_id')
            ->where('start_date', '<=', $end->toDateString())
            ->where('end_date', '>=', $start->toDateString())
            ->get(['leave_type', 'start_date', 'end_date']);

        $takenMap = [];
        foreach ($existingLeaves as $el) {
            for ($d = Carbon::parse($el->start_date); $d->lte(Carbon::parse($el->end_date)); $d->addDay()) {
                $takenMap[$d->toDateString()] = $el->leave_type;
            }
        }

        $finalDates = ! empty($takenMap)
            ? array_values(array_diff($effectiveDates, array_keys($takenMap)))
            : $effectiveDates;

        // Bangun daftar skip + penyebabnya untuk transparansi mobile.
        // Detail libur (nama) diambil dari holidays bila ada — dipakai mobile utk pesan.
        $skipped = [];
        foreach ($requestedDates as $ds) {
            if (in_array($ds, $finalDates, true)) {
                continue; // hari efektif
            }
            if (isset($takenMap[$ds])) {
                $skipped[] = ['date' => $ds, 'reason' => 'already_requested', 'detail' => $takenMap[$ds]];
            } else {
                $skipped[] = ['date' => $ds, 'reason' => 'holiday_or_off_day', 'detail' => null];
            }
        }

        return [
            'total_days'      => count($finalDates),
            'effective_dates' => $finalDates,
            'requested_dates' => $requestedDates,
            'skipped'         => $skipped,
            'taken_map'       => $takenMap,
        ];
    }

    // 8b. leavePreview() — preview hitungan hari EFEKTIF sebelum submit pengajuan.
    //     GET /api/v1/attendance/leave-preview?start_date=&end_date=
    //     Dipakai Flutter agar badge "Total N hari" menampilkan hitungan backend
    //     (skip libur/off-day/bentrok) beserta pemberitahuan per tanggal.
    public function leavePreview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start_date' => 'required|date',
            'end_date'   => 'required|date|after_or_equal:start_date',
        ]);

        $user  = $request->user();
        $start = Carbon::parse($validated['start_date']);
        $end   = Carbon::parse($validated['end_date']);

        // Guard ringan: rentang maksimal 1 tahun (sama seperti date picker mobile)
        if ($start->diffInDays($end) > 365) {
            return response()->json(['message' => 'Rentang tanggal maksimal 365 hari.'], 422);
        }

        $result = $this->effectiveLeaveDays($user, $start, $end);

        // Perkaya skipped dgn NAMA libur (untuk pesan "tanggal X adalah <nama>")
        // & label ramah utk off-day shift/kantor.
        $holidayNames = Holiday::whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->where(function ($q) use ($user) {
                $q->whereNull('company_id')->orWhere('company_id', $user->company_id);
            })
            ->where(fn ($q) => $q->whereNull('attendance_setting_id')
                ->when($user->attendance_setting_id, fn ($qq) => $qq->orWhere('attendance_setting_id', $user->attendance_setting_id)))
            ->orderByDesc('is_national') // nasional menang bila dobel di tanggal sama
            ->get(['date', 'name', 'is_collective'])
            ->keyBy(fn ($h) => $h->date->toDateString());

        // Off-day shift per tanggal (utk membedakan "libur mingguan/off shift")
        $offFromSchedule = $this->resolveOffDatesForUser($user, $result['requested_dates']);

        $typeLabels = ['wfh' => 'WFH', 'izin' => 'izin', 'sakit' => 'sakit', 'cuti' => 'cuti'];

        $skipped = array_map(function ($item) use ($holidayNames, $offFromSchedule, $typeLabels) {
            $ds = $item['date'];
            if ($item['reason'] === 'already_requested') {
                $label = $typeLabels[$item['detail']] ?? $item['detail'];
                $item['label'] = "Sudah ada pengajuan {$label} di tanggal ini";
            } elseif ($name = ($holidayNames[$ds]->name ?? null)) {
                // Libur dari kalender holidays (nasional/perusahaan/cabang/cuti bersama)
                $isCollective = (bool) ($holidayNames[$ds]->is_collective ?? false);
                $jenis = $isCollective ? 'Cuti Bersama' : 'Hari Libur';
                $item['label'] = "{$jenis}: {$name}";
            } elseif (! empty($offFromSchedule[$ds])) {
                $dow = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'][(int) Carbon::parse($ds)->dayOfWeek];
                $item['label'] = "Libur jadwal kerja ({$dow})";
            } else {
                $item['label'] = 'Hari libur';
            }

            return [
                'date'  => $ds,
                'reason'=> $item['reason'],
                'detail'=> $item['detail'],
                'label' => $item['label'],
            ];
        }, $result['skipped']);

        return response()->json([
            'total_days'      => $result['total_days'],
            'calendar_days'   => count($result['requested_dates']),
            'effective_dates' => $result['effective_dates'],
            'skipped_dates'   => $skipped,
        ]);
    }

    // 9. requestLeave() — ajukan WFH/izin/sakit/cuti
    public function requestLeave(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'leave_type' => 'required|in:wfh,izin,sakit,cuti',
            'start_date' => 'required|date',
            'end_date'   => 'required|date|after_or_equal:start_date',
            'reason'     => 'required|string|max:1000',
            // Surat dokter WAJIB untuk jenis 'sakit' — foto/gambar atau PDF, maks 10 MB.
            'document'   => 'required_if:leave_type,sakit|file|mimes:jpeg,jpg,png,webp,gif,pdf|max:10240',
        ], [
            'document.required_if' => 'Surat dokter wajib dilampirkan untuk pengajuan sakit.',
            'document.mimes'       => 'Surat dokter harus berupa gambar (JPG/PNG/WEBP) atau PDF.',
            'document.max'         => 'Ukuran surat dokter maksimal 10 MB.',
        ]);

        $user = $request->user();

        // Pengajuan hanya dapat dilakukan untuk besok atau tanggal setelahnya (hari ini & tanggal lalu dilarang).
        // FIX Bug #3 (2026-08-25): pakai todayDate() WIB — now() UTC membuat jam 00:00–06:59 WIB
        // dianggap masih "kemarin", sehingga pengajuan utk besok bisa ditolak keliru.
        $startDateStr = Carbon::parse($validated['start_date'])->toDateString();
        $todayStr     = $this->todayDate();
        if ($startDateStr <= $todayStr) {
            return response()->json([
                'message' => 'Pengajuan hanya dapat dilakukan untuk besok atau tanggal setelahnya (hari ini & tanggal lalu tidak diperbolehkan).',
            ], 422);
        }

        // KEBIJAKAN EFEKTIF-HARI (2026-08-26): pengajuan TETAP TERKIRIM ke dashboard
        // HRD dengan start/end ASLI, tetapi total_days hanya menghitung tanggal
        // efektif — skip libur nasional/perusahaan/cabang, cuti bersama accepted,
        // cuti pribadi sendiri yang sudah diajukan (anti dobel), libur mingguan
        // kantor default & off-day shift efektif. Hitungan dipusatkan di helper
        // effectiveLeaveDays() agar konsisten dgn leavePreview() di mobile.
        $calc = $this->effectiveLeaveDays(
            $user,
            Carbon::parse($validated['start_date']),
            Carbon::parse($validated['end_date'])
        );
        $finalDates     = $calc['effective_dates'];
        $requestedDates = $calc['requested_dates'];
        $takenMap       = $calc['taken_map'];

        // Tolak HANYA bila tidak ada satu pun tanggal efektif tersisa.
        if ($calc['total_days'] < 1) {
            return response()->json([
                'message' => 'Rentang tanggal tidak mengandung hari kerja efektif (semua hari adalah weekend/libur/off-day shift atau sudah diajukan).',
            ], 422);
        }
        $totalDays = $calc['total_days'];

        // Cek saldo cuti sebelum membuat pengajuan agar karyawan langsung tahu di awal
        if ($validated['leave_type'] === 'cuti') {
            $year    = Carbon::parse($validated['start_date'])->year;
            // KEBIJAKAN 2026-08-25: auto-create saldo cuti NON-AKTIF (quota 0) —
            // aktivasi hanya oleh HRD via tab Saldo Cuti.
            $balance = LeaveBalance::firstOrCreate(
                ['user_id' => $user->id, 'year' => $year, 'leave_type' => 'cuti'],
                ['company_id' => $user->company_id, 'quota' => 0, 'used' => 0]
            );
            $remaining = $balance->quota - $balance->used;
            // KEBIJAKAN 2026-08-28: quota=0 = saldo cuti NON-AKTIF → karyawan tidak bisa
            // mengajukan cuti sama sekali, berapapun nilai used. Kondisi sebelumnya
            // (quota<=0 && used===0) salah: karyawan dgn quota=0 & used>0 bisa lolos.
            if ((int) $balance->quota <= 0) {
                return response()->json([
                    'message'          => 'Saldo cuti Anda belum diaktifkan oleh HRD. Silakan hubungi HRD untuk mengaktifkan saldo cuti Anda.',
                    'remaining_quota'  => 0,
                ], 422);
            }

            // KEBIJAKAN ANNIVERSARY SPLIT (2026-08-25): bila rentang cuti MELINTASI
            // tanggal reset kantor (leave_reset_date), validasi dipecah dua alokasi:
            // hari sebelum reset pakai saldo berjalan, hari pada/setelah reset pakai
            // kuota baru hasil reset (default_leave_quota). Contoh: reset 10 Juni,
            // sisa 2 hari, ajukan 8–11 Juni → 2 hari pertama vs sisa lama ✓,
            // 2 hari terakhir vs kuota baru ✓ → diperbolehkan.
            // Suntikkan $finalDates agar days_before/days_after konsisten dgn total_days.
            $split      = $this->splitLeaveAroundReset($user, Carbon::parse($validated['start_date']), Carbon::parse($validated['end_date']), $user->company_id, $finalDates);
            $hasPivot   = $split['anniversary'] !== null;
            $daysBefore = $hasPivot ? $split['days_before'] : $totalDays;
            $daysAfter  = $hasPivot ? $split['days_after'] : 0;

            if ($daysBefore > $remaining) {
                return response()->json([
                    'message'          => $hasPivot
                        ? "Saldo cuti tidak cukup untuk hari sebelum tanggal reset ({$split['anniversary']}). Sisa {$remaining} hari, dibutuhkan {$daysBefore} hari."
                        : "Saldo cuti Anda sudah habis. Tidak dapat mengajukan cuti.",
                    'remaining_quota'  => max(0, $remaining),
                ], 422);
            }
            if ($daysAfter > $split['fresh_quota']) {
                return response()->json([
                    'message'          => "Kuota cuti baru setelah tanggal reset ({$split['anniversary']}) tidak cukup. Tersedia {$split['fresh_quota']} hari, dibutuhkan {$daysAfter} hari.",
                    'remaining_quota'  => max(0, $remaining),
                ], 422);
            }
        }

        // Simpan surat dokter bila dilampirkan (disk privat 'local')
        $documentPath = null;
        if ($request->hasFile('document')) {
            $documentPath = $request->file('document')->store('leave_documents');
        }

        $leave = LeaveRequest::create([
            'user_id'       => $user->id,
            'company_id'    => $user->company_id,
            'leave_type'    => $validated['leave_type'],
            'start_date'    => $validated['start_date'],
            'end_date'      => $validated['end_date'],
            'total_days'    => $totalDays,
            'reason'        => $validated['reason'],
            'document_path' => $documentPath,
            'status'        => 'pending',
        ]);

        $this->logActivity($user->id, $user->company_id, 'leave_requested', "Ajukan {$leave->leave_type} ({$totalDays} hari)", 'leave_request', $leave->id);

        // Notifikasi ke HRD / admin perusahaan yang sama
        $approvers = DB::table('users')
            ->where('company_id', $user->company_id)
            ->whereIn('role', ['hrd', 'admin', 'super_admin'])
            ->where('is_active', true)
            ->pluck('id');

        foreach ($approvers as $approverId) {
            $this->notifyUser($approverId, 'leave_requested', [
                'message'    => "{$user->name} mengajukan {$leave->leave_type} ({$totalDays} hari).",
                'leave_id'   => $leave->id,
                'leave_type' => $leave->leave_type,
                'user_name'  => $user->name,
            ], 'leave_request', $leave->id);
        }

        // Transparansi mobile: laporkan tanggal yang di-skip dari total_days
        // beserta penyebabnya (hasil hitungan effectiveLeaveDays()).
        return response()->json([
            'message' => 'Permintaan berhasil diajukan.',
            'leave'   => $leave->only([
                'id', 'leave_type', 'start_date', 'end_date', 'total_days', 'status',
            ]),
            'skipped_dates' => $calc['skipped'],
        ], 201);
    }
}
