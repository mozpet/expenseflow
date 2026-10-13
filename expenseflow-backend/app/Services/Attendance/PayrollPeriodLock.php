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
        $d = Carbon::parse($date)->startOfDay();

        return Payroll::where('company_id', $employee->company_id)
            // PENCOCOKAN IKUT RENTANG CUT-OFF (2026-10-12).
            //
            // Dulu dicocokkan period_year + period_month. Itu salah begitu kantor
            // memakai cut-off menyeberang bulan: dengan jendela 21 Sep-20 Okt,
            // presensi 25 September DIBAYAR oleh batch Oktober, tetapi pencocokan
            // bulan mengaitkannya ke batch September — sehingga tanggal yang
            // slipnya sudah terbit tetap bisa dikoreksi tanpa terdeteksi.
            ->where(function ($q) use ($d) {
                // Batch ber-rentang: tanggal harus berada di dalam jendela.
                $q->where(fn ($qq) => $qq
                    ->whereNotNull('period_start_date')
                    ->whereNotNull('period_end_date')
                    ->whereDate('period_start_date', '<=', $d->toDateString())
                    ->whereDate('period_end_date', '>=', $d->toDateString()))
                    // Pengaman baris tanpa rentang (seeder & data yang dibuat
                    // langsung tanpa controller): jatuh ke pencocokan bulan lama.
                    ->orWhere(fn ($qq) => $qq
                        ->whereNull('period_start_date')
                        ->where('period_year', $d->year)
                        ->where('period_month', $d->month));
            })
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
     * DIPERTAHANKAN APA ADANYA UNTUK KOMPATIBILITAS (2026-10-12), tetapi kini
     * dihitung dari rentang cut-off: HANYA bulan yang TERKUNCI PENUH yang masuk.
     * Rentang parsial (mis. 21 Sep-20 Okt mengunci Oktober hanya separuh) tidak
     * bisa diwakili string "YYYY-MM", jadi bulan seperti itu sengaja TIDAK
     * dilaporkan di sini. Under-report ini mode gagal yang AMAN dan persis sama
     * dengan perilaku pra-fitur: tombol koreksi tetap aktif, lalu server menolak
     * keras lewat findLocking(). Frontend presisi per-hari memakai lockedRanges().
     *
     * @return array<int, string>
     */
    public function lockedPeriods(?int $companyId, string $startDate, string $endDate, ?array $allowedBranches = null): array
    {
        $ranges = $this->lockedRanges($companyId, $startDate, $endDate, $allowedBranches);
        if ($ranges === []) {
            return [];
        }

        // Gabungkan interval yang bersinggungan agar dua batch berurutan
        // (mis. 21 Ags-20 Sep dan 21 Sep-20 Okt) bisa menutup September penuh.
        $intervals = [];
        foreach ($ranges as $range) {
            $intervals[] = [
                Carbon::parse($range['start'])->startOfDay(),
                Carbon::parse($range['end'])->startOfDay(),
            ];
        }
        usort($intervals, fn ($a, $b) => $a[0]->getTimestamp() <=> $b[0]->getTimestamp());

        $merged = [];
        foreach ($intervals as [$s, $e]) {
            $last = $merged === [] ? null : $merged[count($merged) - 1];
            // "Bersinggungan" termasuk berurutan tanpa celah (end + 1 hari = start).
            if ($last !== null && $s->lte($last[1]->copy()->addDay())) {
                if ($e->gt($last[1])) {
                    $merged[count($merged) - 1][1] = $e;
                }
                continue;
            }
            $merged[] = [$s, $e];
        }

        $months = [];
        $cursor = Carbon::parse($startDate)->startOfMonth();
        $limit  = Carbon::parse($endDate)->startOfMonth();
        for (; $cursor->lte($limit); $cursor->addMonth()) {
            $monthStart = $cursor->copy()->startOfMonth();
            $monthEnd   = $cursor->copy()->endOfMonth()->startOfDay();

            foreach ($merged as [$s, $e]) {
                if ($s->lte($monthStart) && $e->gte($monthEnd)) {
                    $months[] = $monthStart->format('Y-m');
                    break;
                }
            }
        }

        return array_values(array_unique($months));
    }

    /**
     * RENTANG TERKUNCI PRESISI PER-HARI (2026-10-12).
     *
     * Pengganti lockedPeriods() untuk frontend: mengembalikan jendela nyata
     * setiap batch terkunci sehingga tombol koreksi bisa non-aktif tepat
     * per-tanggal, bukan per-bulan, saat kantor memakai cut-off yang
     * menyeberang batas bulan.
     *
     * Hanya batch yang beririsan dengan rentang laporan yang dikembalikan.
     * Baris tanpa kolom rentang (seeder / data lama) diturunkan ke bulan
     * kalendernya agar tidak hilang dari daftar.
     *
     * @return array<int, array{start: string, end: string, payroll_id: int, status: string, period_label: ?string}>
     */
    public function lockedRanges(?int $companyId, string $startDate, string $endDate, ?array $allowedBranches = null): array
    {
        if (! $companyId) {
            return [];
        }

        $from = Carbon::parse($startDate)->startOfDay();
        $to   = Carbon::parse($endDate)->startOfDay();

        // Diperlebar satu bulan ke kedua arah: batch dengan cut-off menyeberang
        // bulan bisa dimulai sebelum rentang laporan namun tetap menguncinya.
        $scanFrom = $from->copy()->subMonthNoOverflow()->startOfMonth();
        $scanTo   = $to->copy()->addMonthNoOverflow()->endOfMonth();

        // Kunci bulan sebagai bilangan (YYYYMM) agar perbandingan rentang bulan
        // bisa dilakukan satu kali, portabel MySQL maupun SQLite.
        $scanFromKey = $scanFrom->year * 100 + $scanFrom->month;
        $scanToKey   = $scanTo->year * 100 + $scanTo->month;

        $rows = Payroll::where('company_id', $companyId)
            ->whereIn('status', [Payroll::STATUS_APPROVED, Payroll::STATUS_PAID])
            ->where(function ($q) use ($from, $to, $scanFromKey, $scanToKey) {
                // Batch ber-rentang: beririsan dengan rentang laporan.
                $q->where(fn ($qq) => $qq
                    ->whereNotNull('period_start_date')
                    ->whereNotNull('period_end_date')
                    ->whereDate('period_start_date', '<=', $to->toDateString())
                    ->whereDate('period_end_date', '>=', $from->toDateString()))
                    // Baris tanpa rentang: saring kasar per bulan, dipersempit di PHP.
                    ->orWhere(fn ($qq) => $qq
                        ->whereNull('period_start_date')
                        ->whereRaw('(period_year * 100 + period_month) between ? and ?', [$scanFromKey, $scanToKey]));
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
            ->orderBy('period_year')
            ->orderBy('period_month')
            ->get(['id', 'period_year', 'period_month', 'period_start_date', 'period_end_date', 'period_label', 'status']);

        $ranges = [];
        foreach ($rows as $row) {
            [$start, $end] = $row->periodRange();

            // Baris tanpa rentang tersaring kasar di SQL — pastikan di sini.
            if ($end->lt($from) || $start->gt($to)) {
                continue;
            }

            $ranges[] = [
                'start'        => $start->toDateString(),
                'end'          => $end->toDateString(),
                'payroll_id'   => (int) $row->id,
                'status'       => (string) $row->status,
                'period_label' => $row->period_label !== null ? (string) $row->period_label : null,
            ];
        }

        return $ranges;
    }
}
