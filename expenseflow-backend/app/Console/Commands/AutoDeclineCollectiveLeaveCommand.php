<?php

namespace App\Console\Commands;

use App\Models\LeaveRequest;
use Carbon\Carbon;
use Illuminate\Console\Command;

class AutoDeclineCollectiveLeaveCommand extends Command
{
    protected $signature   = 'attendance:auto-decline-collective-leave';
    protected $description = 'Otomatis decline karyawan yang belum memilih (pending) saat hari H cuti bersama tiba.';

    public function handle(): void
    {
        $count = LeaveRequest::autoDeclineExpiredCollectiveLeaves();

        if ($count === 0) {
            $this->info('[auto-decline-collective] Tidak ada pending yang perlu diproses.');
            return;
        }

        $this->info("[auto-decline-collective] {$count} pengajuan cuti bersama otomatis di-decline.");
    }
}
