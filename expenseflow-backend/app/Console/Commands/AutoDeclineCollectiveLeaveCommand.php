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
        $today = now('Asia/Jakarta')->toDateString();

        // Cari semua leave_request cuti bersama yang masih pending dan hari ini >= tanggal cuti
        $pending = LeaveRequest::where('collective_status', 'pending')
            ->whereNotNull('holiday_id')
            ->whereDate('start_date', '<=', $today)
            ->get();

        if ($pending->isEmpty()) {
            $this->info('[auto-decline-collective] Tidak ada pending yang perlu diproses.');
            return;
        }

        $count = 0;
        foreach ($pending as $leave) {
            $leave->update([
                'collective_status' => 'declined',
                'status'            => 'rejected',
                'rejection_reason'  => 'Tidak merespons sebelum batas waktu — otomatis ditolak oleh sistem.',
            ]);
            $count++;
        }

        $this->info("[auto-decline-collective] {$count} pengajuan cuti bersama otomatis di-decline.");
    }
}
