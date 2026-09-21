<?php

namespace App\Console\Commands;

use App\Models\LeaveRequest;
use Illuminate\Console\Command;

class AutoRejectExpiredLeavesCommand extends Command
{
    protected $signature   = 'attendance:auto-reject-expired-leaves';
    protected $description = 'Otomatis menolak semua jenis permohonan izin/cuti yang belum diproses HRD saat hari H tiba.';

    public function handle(): void
    {
        $count = LeaveRequest::autoRejectExpiredLeaves();

        if ($count === 0) {
            $this->info('[auto-reject-leaves] Tidak ada pengajuan izin kadaluarsa yang perlu diproses.');
            return;
        }

        $this->info("[auto-reject-leaves] {$count} pengajuan izin otomatis ditolak karena sudah memasuki hari H.");
    }
}
