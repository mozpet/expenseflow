<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Http\Controllers\API\ShiftController;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncShiftWfhStatus extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'attendance:sync-shift-wfh';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync user WFH and Radius status based on their shift for today.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $today = now('Asia/Jakarta')->toDateString();
        $this->info("Starting shift WFH sync for {$today}");

        $users = User::where('is_active', true)
            ->whereIn('role', ['employee', 'admin', 'hrd', 'finance', 'super_admin'])
            ->get();

        $updatedCount = 0;
        $skippedCount = 0;
        $yesterday     = now('Asia/Jakarta')->subDay()->toDateString();

        foreach ($users as $user) {
            if (! $user->canAccessAttendance()) continue;

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

        $this->info("Completed shift WFH sync. {$updatedCount} users updated, {$skippedCount} skipped (cross-day session).");
        Log::info("attendance:sync-shift-wfh completed for {$today}. Updated {$updatedCount}, skipped {$skippedCount}.");
    }
}

