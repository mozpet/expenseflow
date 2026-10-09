<?php

namespace App\Services\Attendance;

use App\Models\Payroll;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * PENJAGA PERIODE GAJI TERKUNCI (2026-10-06)
 *
 * PayrollCalculator membaca attendances.status, attendances.is_holiday dan
 * overtime_approvals.overtime_minutes. Artinya mengoreksi presensi pada bulan
 * yang slip gajinya SUDAH disetujui/dibayarkan akan membuat slip yang sudah
 * terbit tidak sinkron dengan data presensi — selisih yang tidak akan pernah
 * terdeteksi sistem.
 *
 * Karena itu koreksi pada periode terkunci adalah HARD BLOCK, bukan peringatan,
 * dan TIDAK bisa dilewati dengan gerbang konfirmasi 'SIMPAN' (beda kelas: SIMPAN
 * untuk "yakin angkanya benar?", ini untuk "tidak boleh sama sekali").
 *
 * Model Payroll::isLocked() sudah ada sejak fitur payroll dibuat tapi belum
 * pernah dipakai; kelas ini adalah call site pertamanya.
 */
class PayrollPeriodLock
{
    /**
     * Cari batch payroll terkunci (approved/paid) yang mencakup presensi
     * karyawan ini pada tanggal tersebut. NULL = aman untuk dikoreksi.
     */
    public function findLocking(User $employee, string $date): ?Payroll
    {
        $d = Carbon::parse($date);

        return Payroll::where('company_id', $employee->company_id)
            ->where('period_year', $d->year)
            ->where('period_month', $d->month)
            ->whereIn('status', [Payroll::STATUS_APPROVED, Payroll::STATUS_PAID])
            // Batch seluruh perusahaan (attendance_setting_id NULL) mengunci semua
            // cabang; batch per-cabang hanya mengunci karyawan di cabang itu.
            ->where(function ($q) use ($employee) {
                $q->whereNull('attendance_setting_id');
                if ($employee->attendance_setting_id) {
                    $q->orWhere('attendance_setting_id', $employee->attendance_setting_id);
                }
            })
            // Batch yang dibatasi payroll_group hanya mengunci anggota grup itu.
            ->where(function ($q) use ($employee) {
                $q->whereNull('payroll_group_id');
                if ($employee->payroll_group_id) {
                    $q->orWhere('payroll_group_id', $employee->payroll_group_id);
                }
            })
            // run_type SENGAJA tidak difilter: batch THR maupun pesangon yang sudah
            // approved/paid juga membaca presensi periode tersebut.
            // Urutkan agar status terburuk (paid) yang dilaporkan ke HRD.
            ->orderByRaw("CASE WHEN status = 'paid' THEN 0 ELSE 1 END")
            ->first();
    }

    /**
     * Daftar periode terkunci ("YYYY-MM") dalam rentang laporan — dipakai
     * reportAttendance() agar tombol koreksi di web bisa langsung non-aktif
     * tanpa harus gagal dulu di server.
     *
     * @return array<int, string>
     */
    public function lockedPeriods(?int $companyId, string $startDate, string $endDate, ?array $allowedBranches = null): array
    {
        if (! $companyId) {
            return [];
        }

        $start = Carbon::parse($startDate)->startOfMonth();
        $end   = Carbon::parse($endDate)->startOfMonth();

        $pairs = [];
        for ($c = $start->copy(); $c->lte($end); $c->addMonth()) {
            $pairs[] = [$c->year, $c->month];
        }

        $rows = Payroll::where('company_id', $companyId)
            ->whereIn('status', [Payroll::STATUS_APPROVED, Payroll::STATUS_PAID])
            ->where(function ($q) use ($pairs) {
                foreach ($pairs as [$y, $m]) {
                    $q->orWhere(fn ($qq) => $qq->where('period_year', $y)->where('period_month', $m));
                }
            })
            // Cabang di luar wewenang pemanggil tidak perlu ikut mengunci tampilannya.
            ->when(is_array($allowedBranches), function ($q) use ($allowedBranches) {
                $q->where(function ($qq) use ($allowedBranches) {
                    $qq->whereNull('attendance_setting_id');
                    if (! empty($allowedBranches)) {
                        $qq->orWhereIn('attendance_setting_id', $allowedBranches);
                    }
                });
            })
            ->get(['period_year', 'period_month']);

        return $rows
            ->map(fn ($r) => sprintf('%04d-%02d', (int) $r->period_year, (int) $r->period_month))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }
}
