<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Http\Controllers\API\ShiftController;
use App\Support\Timezone;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class SyncShiftWfhStatus extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'attendance:sync-shift-wfh
                            {--force : Abaikan penanda "sudah diproses" dan jalankan untuk semua zona}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync user WFH and Radius status based on their shift for today (per zona cabang).';

    /**
     * Execute the console command.
     *
     * MULTI-ZONA (2026-10-08): dijadwalkan ->hourly(), bukan ->dailyAt('00:01').
     * Penjagaan "sekali per hari" dipindah ke sini dan dihitung PER ZONA, karena
     * hari lokal cabang WIT dimulai 2 jam lebih awal dari jam dinding server
     * (00:00 WIT = 22:00 WIB H-1). Dengan jadwal harian WIB, cabang timur baru
     * tersinkron 2 jam setelah harinya berjalan.
     *
     * Sinkronisasi tetap HANYA sekali di awal hari lokal tiap zona — penanda cache
     * per (zona, tanggal lokal) menjaga agar override manual HRD di tengah hari
     * tidak tertimpa oleh tick jam berikutnya.
     */
    public function handle()
    {
        $isForce = (bool) $this->option('force');

        // Instan absolut tunggal; tiap zona menafsirkannya di jam dindingnya sendiri.
        $nowInstant = now();

        $users = User::where('is_active', true)
            ->where('role', '!=', 'super_admin')
            ->get();

        // Kelompokkan per zona cabang penempatan — tiap kelompok memakai TANGGAL
        // LOKAL cabangnya sendiri saat me-resolve jadwal shift.
        $groups = [];
        foreach ($users as $user) {
            if (! $user->canAccessAttendance()) continue;
            $tz = $user->effectiveTimezone();
            $groups[$tz] ??= [];
            $groups[$tz][] = $user;
        }

        if (empty($groups)) {
            $this->info('Tidak ada karyawan aktif dengan akses presensi.');
            return;
        }

        $totalUpdated = 0;
        $totalSkipped = 0;

        foreach ($groups as $tz => $usersInZone) {
            $localNow = $nowInstant->copy()->setTimezone($tz);
            $today    = $localNow->toDateString();

            // Penanda sekali-per-hari-lokal. TTL 36 jam > 24 jam agar penanda belum
            // kedaluwarsa ketika tick jam terakhir hari itu berjalan.
            $cacheKey = "sync_shift_wfh_{$tz}_{$today}";
            if (! $isForce && cache()->has($cacheKey)) {
                continue;
            }
            cache()->put($cacheKey, true, now()->addHours(36));

            $zona = Timezone::label($tz);
            $this->info("Starting shift WFH sync for {$today} ({$zona})");

            [$updated, $skipped] = $this->syncZone($usersInZone, $today, $tz);
            $totalUpdated += $updated;
            $totalSkipped += $skipped;

            Log::info("attendance:sync-shift-wfh completed for {$today} ({$zona}). Updated {$updated}, skipped {$skipped}.");
        }

        $this->info("Completed shift WFH sync. {$totalUpdated} users updated, {$totalSkipped} skipped (cross-day session).");
    }

    /**
     * Sinkronkan satu kelompok karyawan terhadap tanggal lokal cabangnya.
     *
     * @param  string  $today  Tanggal LOKAL cabang (bukan tanggal zona penyimpanan).
     * @return array{0:int,1:int}  [jumlah diperbarui, jumlah dilewati]
     */
    private function syncZone(array $users, string $today, string $tz): array
    {
        $updatedCount = 0;
        $skippedCount = 0;
        $yesterday    = Carbon::parse($today, $tz)->subDay()->toDateString();

        foreach ($users as $user) {
            $schedule = ShiftController::resolveSchedule($user, $today);

            if ($schedule['source'] === 'shift') {
                $isWfh    = ! empty($schedule['is_wfh']);
                $isField  = ! empty($schedule['is_field']);
                $newWfh    = $isWfh || $isField;
                $newRadius = $isField;

                // Guard: jika user sedang aktif check-in lintas hari (shift malam kemarin,
                // belum checkout pagi ini), JANGAN ubah wfh_enabled ke false.
                // Cron akan mencoba lagi saat user sudah checkout.
                if (! $newWfh) {
                    $hasCrossDaySession = \App\Models\Attendance::where('user_id', $user->id)
                        ->whereDate('date', $yesterday)
                        ->whereNotNull('check_in_time')
                        ->whereNull('check_out_time')
                        ->exists();

                    if ($hasCrossDaySession) {
                        $skippedCount++;
                        $this->line("SKIPPED user {$user->id} ({$user->name}) — active cross-day session, WFH not disabled yet.");
                        continue;
                    }
                }

                if ($user->wfh_enabled != $newWfh || $user->radius_enabled != $newRadius) {
                    $user->update([
                        'wfh_enabled'    => $newWfh,
                        'radius_enabled' => $newRadius,
                    ]);
                    $updatedCount++;
                    $this->line("Updated user {$user->id} ({$user->name}) - WFH: {$newWfh}, Radius: {$newRadius}");
                }
            }
        }

        return [$updatedCount, $skippedCount];
    }
}

