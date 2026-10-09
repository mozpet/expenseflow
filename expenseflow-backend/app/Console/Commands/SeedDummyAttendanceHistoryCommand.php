<?php

namespace App\Console\Commands;

use App\Models\AttendanceSetting;
use App\Models\Holiday;
use App\Models\User;
use App\Services\Attendance\LateFineCalculator;
use App\Support\Timezone;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SeedDummyAttendanceHistoryCommand extends Command
{
    protected $signature = 'attendance:seed-dummy-history
                            {--company=1 : ID company target}
                            {--months=4 : Jumlah bulan ke belakang (termasuk bulan ini) yang diisi}';

    protected $description = 'Isi riwayat presensi harian yang realistis untuk karyawan company tertentu agar payroll bisa ditinjau dengan data yang masuk akal';

    public function handle(): int
    {
        $companyId = (int) $this->option('company');
        $months = max(1, (int) $this->option('months'));

        // Dataset dev/test saat ini tidak memakai pola "EMP-DUMMY-%" dari
        // GenerateDummyUsersCommand — 200 karyawan company_id=1 sudah ada dengan
        // nama/email realistis (budi@majubersama.co.id, emp-0021, dst). Jadi target
        // di sini adalah SEMUA karyawan company target, bukan hasil pattern-match.
        $users = User::where('company_id', $companyId)
            ->whereNotIn('role', ['super_admin'])
            ->with('office')
            ->get();

        if ($users->isEmpty()) {
            $this->error("Tidak ada karyawan ditemukan untuk company_id={$companyId}.");
            return Command::FAILURE;
        }

        $tz = Timezone::storage();
        $today = now($tz)->startOfDay();
        $rangeStart = $today->copy()->subMonths($months - 1)->startOfMonth();
        $rangeEnd = $today->copy();

        $this->info("================================================================================");
        $this->info("        SEED RIWAYAT PRESENSI DUMMY — UNTUK KEBUTUHAN REVIEW PAYROLL            ");
        $this->info("================================================================================");
        $this->line("Target Karyawan : <comment>{$users->count()} akun dummy</comment> (company_id={$companyId})");
        $this->line("Rentang Tanggal : <comment>{$rangeStart->toDateString()} s.d. {$rangeEnd->toDateString()}</comment>");
        $this->newLine();

        // Preload libur perusahaan di rentang tanggal, dikelompokkan: null = berlaku semua cabang.
        $holidaysByBranch = [];
        Holiday::where('company_id', $companyId)
            ->whereBetween('date', [$rangeStart->toDateString(), $rangeEnd->toDateString()])
            ->get(['date', 'attendance_setting_id'])
            ->each(function ($h) use (&$holidaysByBranch) {
                $key = $h->attendance_setting_id ? (string) $h->attendance_setting_id : 'all';
                $holidaysByBranch[$key][$h->date->toDateString()] = true;
            });

        $officeCache = [];
        $statusTally = ['present' => 0, 'late' => 0, 'absent' => 0, 'wfh' => 0];

        $bar = $this->output->createProgressBar($users->count());
        $bar->start();

        $buffer = [];
        $bufferLimit = 500;

        foreach ($users as $user) {
            $office = $user->attendance_setting_id
                ? ($officeCache[$user->attendance_setting_id] ??= $user->office)
                : null;

            $workStart = $office?->work_start_time ? substr((string) $office->work_start_time, 0, 5) : '08:00';
            $workEnd = $office?->work_end_time ? substr((string) $office->work_end_time, 0, 5) : '17:00';
            $tolerance = (int) ($office?->late_tolerance_minutes ?? 15);
            $breakMinutes = (int) ($office?->break_minutes ?? 60);
            $fineConfig = LateFineCalculator::configFromOffice($office, $tolerance);

            $branchHolidayKey = $office?->id ? (string) $office->id : null;
            $holidaySet = array_merge(
                $holidaysByBranch['all'] ?? [],
                $branchHolidayKey ? ($holidaysByBranch[$branchHolidayKey] ?? []) : []
            );

            $profile = $this->resolveProfile($user->id);
            $canWfh = (bool) $user->wfh_enabled;

            $joined = $user->joined_date ? Carbon::parse($user->joined_date, $tz)->startOfDay() : null;
            $cursor = $joined && $joined->greaterThan($rangeStart) ? $joined->copy() : $rangeStart->copy();

            while ($cursor->lessThanOrEqualTo($rangeEnd)) {
                $dateStr = $cursor->toDateString();
                $dow = $cursor->dayOfWeekIso; // 1=Senin ... 7=Minggu

                if ($dow >= 6 || isset($holidaySet[$dateStr])) {
                    $cursor->addDay();
                    continue;
                }

                // RNG stabil per (user, tanggal) — hasil sama kalau command dijalankan ulang.
                $rand = $this->stableRandom($user->id, $dateStr);
                $status = $this->pickStatus($rand, $profile, $canWfh);
                $statusTally[$status]++;

                $row = $this->buildRow($user, $companyId, $dateStr, $status, $workStart, $workEnd, $tolerance, $breakMinutes, $fineConfig, $tz, $rand);
                $buffer[] = $row;

                if (count($buffer) >= $bufferLimit) {
                    $this->flushBuffer($buffer);
                    $buffer = [];
                }

                $cursor->addDay();
            }

            $bar->advance();
        }

        if (! empty($buffer)) {
            $this->flushBuffer($buffer);
        }

        $bar->finish();
        $this->newLine(2);

        $total = array_sum($statusTally);
        $this->info("================================================================================");
        $this->info("                              RINGKASAN HASIL                                   ");
        $this->info("================================================================================");
        $this->line("Total baris presensi diisi/diperbarui : <info>{$total}</info>");
        foreach ($statusTally as $status => $count) {
            $pct = $total > 0 ? round($count / $total * 100, 1) : 0;
            $this->line(sprintf("  • %-10s : %6d (%s%%)", $status, $count, $pct));
        }
        $this->newLine();
        $this->warn("Catatan: payroll run berstatus 'calculated' (belum 'paid') untuk periode yang baru diisi perlu di-hitung ulang lewat UI/endpoint Payroll agar slip gaji menyerap data presensi baru ini. Payroll run berstatus 'paid' tidak disentuh oleh command ini.");

        return Command::SUCCESS;
    }

    /**
     * Profil keberagaman karyawan: 70% rajin, 25% rata-rata, 5% bermasalah.
     * Deterministik dari user->id supaya stabil tiap kali command dijalankan ulang.
     *
     * @return array{present: float, late: float, absent: float}
     */
    private function resolveProfile(int $userId): array
    {
        $bucket = $this->stableRandom($userId, 'profile');

        if ($bucket < 0.70) {
            return ['present' => 0.96, 'late' => 0.03, 'absent' => 0.01];
        }
        if ($bucket < 0.95) {
            return ['present' => 0.85, 'late' => 0.10, 'absent' => 0.05];
        }
        return ['present' => 0.65, 'late' => 0.20, 'absent' => 0.15];
    }

    private function pickStatus(float $rand, array $profile, bool $canWfh): string
    {
        if ($rand < $profile['absent']) {
            return 'absent';
        }
        if ($rand < $profile['absent'] + $profile['late']) {
            return 'late';
        }
        // Sisa slot "present" — selipkan sebagian kecil sebagai WFH bila karyawan boleh WFH.
        if ($canWfh && $rand > (1 - 0.03)) {
            return 'wfh';
        }
        return 'present';
    }

    /**
     * Angka pseudo-random stabil [0, 1) dari kombinasi user + kunci apa pun (tanggal, 'profile', dll).
     * Memakai hash deterministik, bukan mt_rand(), agar hasil tidak berubah antar-run.
     */
    private function stableRandom(int $userId, string $key): float
    {
        $hash = crc32($userId . '|' . $key);
        return ($hash % 1000000) / 1000000;
    }

    private function buildRow(
        User $user,
        int $companyId,
        string $dateStr,
        string $status,
        string $workStart,
        string $workEnd,
        int $tolerance,
        int $breakMinutes,
        array $fineConfig,
        string $tz,
        float $rand
    ): array {
        $now = now();

        $base = [
            'user_id'            => $user->id,
            'company_id'         => $companyId,
            'date'               => $dateStr,
            'status'             => $status,
            'is_holiday'         => false,
            'is_auto_checkout'   => false,
            'is_offline_sync'    => false,
            'is_manual_entry'    => false,
            'overtime_minutes'   => 0,
            'created_at'         => $now,
            'updated_at'         => $now,
        ];

        if ($status === 'absent') {
            return $base + [
                'check_in_time'  => null,
                'check_out_time' => null,
                'check_in_type'  => null,
                'check_out_type' => null,
                'work_minutes'   => 0,
                'late_minutes'   => null,
                'late_fine_amount' => null,
                'notes'          => 'Tidak hadir tanpa keterangan (data dummy)',
            ];
        }

        $workStartAt = Carbon::parse($dateStr . ' ' . $workStart, $tz);
        $workEndAt = Carbon::parse($dateStr . ' ' . $workEnd, $tz);

        $lateMinutes = null;
        $lateFine = null;
        $checkInType = $status === 'wfh' ? 'wfh' : 'onsite';

        if ($status === 'late') {
            // Selisih acak di atas toleransi: 1..90 menit.
            $extraMinutes = 1 + (int) floor($rand * 89);
            $checkInAt = $workStartAt->copy()->addMinutes($tolerance + $extraMinutes);
            $fine = LateFineCalculator::calculate($checkInAt, $workStart, $tz, $fineConfig);
            $lateMinutes = $fine['late_minutes'];
            $lateFine = $fine['late_fine_amount'];
        } else {
            // present / wfh: datang 0-10 menit lebih awal dari jam masuk.
            $earlyMinutes = (int) floor($rand * 10);
            $checkInAt = $workStartAt->copy()->subMinutes($earlyMinutes);
        }

        // Pulang: jam pulang + 0-45 menit (variasi wajar, tidak selalu pas).
        $extraStayMinutes = (int) floor((1 - $rand) * 45);
        $checkOutAt = $workEndAt->copy()->addMinutes($extraStayMinutes);

        // Titik awal jam kerja: jam jadwal (bila datang lebih awal) atau jam check-in (bila telat).
        $effectiveStart = $checkInAt->greaterThan($workStartAt) ? $checkInAt : $workStartAt;
        $rawMinutes = max(0, (int) $effectiveStart->diffInMinutes($checkOutAt));

        // Potong jam istirahat jika bekerja >= 5 jam (300 menit) sesuai aturan UU Ketenagakerjaan.
        $workMinutes = ($rawMinutes >= 300 && $breakMinutes > 0)
            ? max(240, $rawMinutes - $breakMinutes)
            : $rawMinutes;

        return $base + [
            'check_in_time'    => $checkInAt,
            'check_out_time'   => $checkOutAt,
            'check_in_type'    => $checkInType,
            'check_out_type'   => $checkInType,
            'work_minutes'     => $workMinutes,
            'late_minutes'     => $lateMinutes,
            'late_fine_amount' => $lateFine,
            'notes'            => null,
        ];
    }

    private function flushBuffer(array $rows): void
    {
        DB::table('attendances')->upsert(
            $rows,
            ['user_id', 'date'],
            [
                'company_id', 'status', 'is_holiday', 'is_auto_checkout', 'is_offline_sync',
                'is_manual_entry', 'overtime_minutes', 'check_in_time', 'check_out_time',
                'check_in_type', 'check_out_type', 'work_minutes', 'late_minutes',
                'late_fine_amount', 'notes', 'updated_at',
            ]
        );
    }
}
