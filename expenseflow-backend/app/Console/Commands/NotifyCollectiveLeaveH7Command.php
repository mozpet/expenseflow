<?php

namespace App\Console\Commands;

use App\Http\Controllers\API\AttendanceController;
use Illuminate\Console\Command;

class NotifyCollectiveLeaveH7Command extends Command
{
    protected $signature   = 'attendance:notify-collective-leaves';
    protected $description = 'Kirim notifikasi FCM cuti bersama saat memasuki H-7 s/d Hari H (berbarengan dengan munculnya kartu di tombol dering).';

    public function handle(): void
    {
        $ctrl = app(AttendanceController::class);
        $count = $ctrl->dispatchH7CollectiveLeaveNotifications();

        $this->info("[notify-collective-leaves] Selesai. {$count} notifikasi FCM cuti bersama H-7 terkirim.");
    }
}
