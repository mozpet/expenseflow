<?php

namespace App\Console\Commands;

use App\Models\AttendanceSetting;
use App\Models\LeaveBalance;
use App\Models\User;
use App\Services\Attendance\LeavePeriodCloser;
use App\Services\FcmService;
use App\Support\Timezone;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ResetLeaveBalances — reset otomatis saldo cuti tahunan (ulang tiap 1 tahun).
 *
 * HRD menentukan tanggal reset per kantor di attendance_settings.leave_reset_date
 * (format 'MM-DD' tanpa tahun, mis. '01-01' → reset ulang setiap 1 Januari).
 * Command ini dijalankan harian: bila anniversary sudah tiba (atau sudah lewat
 * dan belum diproses), saldo cuti TAHUN BERJALAN semua karyawan aktif kantor tsb
 * yang SUDAH AKTIF (quota > 0) dibuat ulang:
 *   quota = default_leave_quota kantor, used = 0.
 *
 * KEBIJAKAN 2026-08-25: saldo cuti karyawan NON-AKTIF secara default — reset
 * TIDAK mengaktifkan saldo yang belum pernah diaktifkan HRD (quota 0 / tanpa baris).
 * Aktivasi tetap manual oleh HRD via tab Saldo Cuti.
 *
 * CATCH-UP: pemrosesan memakai last_leave_reset_on — bila server mati beberapa hari,
 * reset tetap dieksekusi sekali saat server menyala kembali (tidak dobel, tidak terlewat).
 *
 * Jadwal: setiap jam (via routes/console.php scheduler).
 *
 * MULTI-ZONA (2026-10-08): dijadwalkan ->hourly(), bukan ->dailyAt('00:03'), dan
 * tanggal anniversary tiap kantor dinilai terhadap TANGGAL LOKAL kantor itu
 * sendiri. Hari lokal cabang WIT dimulai 2 jam lebih awal dari jam dinding server
 * (00:00 WIT = 22:00 WIB H-1), sehingga jadwal harian WIB membuat reset cabang
 * timur terlambat 2 jam. Penjagaan anti-dobel tetap last_leave_reset_on (bukan
 * jadwal), jadi berjalan tiap jam TIDAK menyebabkan reset ganda — mekanismenya
 * sudah idempoten/catch-up sejak awal.
 *
 * Kolom leave_balances.year SENGAJA tetap memakai zona penyimpanan: ia adalah
 * tahun buku perusahaan yang dipakai sebagai kunci baris bersama oleh approveLeave,
 * listAllUsers, dan saldo mobile. Hanya tanggal anniversary yang bersifat lokal.
 *
 * CARRY-OVER & PENCAIRAN (2026-10-13): sisa cuti tidak lagi otomatis hangus.
 * Takdirnya diputuskan LeaveCarryOverCalculator sesuai dua checkbox kantor dan
 * dijalankan LeavePeriodCloser (termasuk menerbitkan penyesuaian payroll untuk
 * hari yang dicairkan). Command ini TIDAK mengandung aturannya sendiri — jalur
 * reset manual HRD memakai kelas yang sama agar keduanya tidak pernah
 * menghasilkan angka berbeda untuk karyawan yang sama.
 *
 * Command ini juga menggugurkan hari bawaan yang kedaluwarsa untuk SELURUH
 * kantor setiap jam, bukan hanya kantor yang sedang anniversary: tanggal
 * kedaluwarsa carry-over jatuh di tengah periode (praktik umum "wajib
 * dihabiskan paling lambat 31 Maret"), sehingga tidak ada peristiwa reset yang
 * bisa menanganinya.
 */
class ResetLeaveBalancesCommand extends Command
{
    protected $signature   = 'attendance:reset-leave-balances';
    protected $description = 'Reset saldo cuti karyawan sesuai tanggal anniversary per kantor (1x per tahun, per zona cabang)';

    public function __construct(private FcmService $fcm)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        // Tahun buku perusahaan — kunci baris leave_balances. Zona penyimpanan,
        // bukan zona cabang (lihat docblock kelas).
        $bookYear = (int) Carbon::today(Timezone::storage())->year;

        // Kantor dengan jadwal reset aktif. Bila HRD mengisi jadwal di tengah tahun dan
        // anniversary tahun ini sudah lewat, saldo TAHUN INI langsung ikut di-reset sekali
        // (konsisten dgn semantik catch-up); tahun-tahun berikutnya reset tepat di tanggalnya.
        $offices = AttendanceSetting::whereNotNull('leave_reset_date')->get();

        // Pengguguran hari bawaan yang kedaluwarsa — dijalankan LEBIH DULU dan
        // TANPA bergantung pada ada/tidaknya kantor berjadwal reset. Kantor yang
        // mencabut leave_reset_date-nya tetap punya karyawan dengan hari bawaan
        // yang harus gugur pada tanggalnya.
        //
        // Memakai tanggal zona penyimpanan, bukan per-cabang: perbandingannya
        // "< tanggal kedaluwarsa" (bukan "<="), jadi hari bawaan tetap bisa
        // dipakai sepanjang tanggal kedaluwarsanya dan baru gugur pada hari
        // berikutnya. Selisih zona antar cabang hanya menggeser JAM penggugurannya
        // di hari berikutnya itu, tidak pernah memakan hari yang masih jadi hak.
        $expiry = LeavePeriodCloser::expireCarryOver(Carbon::today(Timezone::storage()));
        if ($expiry['processed'] > 0) {
            $this->info(sprintf(
                '%d saldo bawaan cuti ditutup masa berlakunya: %d hari dicairkan = Rp%s '
                . '(menunggu batch payroll berikutnya), %d hari hangus.',
                $expiry['processed'],
                $expiry['encashed_days'],
                number_format($expiry['encashed_amount'], 0, ',', '.'),
                $expiry['forfeited_days']
            ));
        }

        if ($offices->isEmpty()) {
            $this->info('Tidak ada kantor dengan jadwal reset saldo cuti.');
            return self::SUCCESS;
        }

        $totalOffices  = 0;
        $totalUsers    = 0;
        $totalCarried  = 0;
        $totalEncashed = 0;
        $totalCashOut  = 0.0;

        foreach ($offices as $office) {
            // Tanggal "hari ini" menurut jam dinding kantor ini sendiri.
            $tz    = $office->effectiveTimezone();
            $today = Carbon::today($tz);

            // leave_reset_date berformat 'MM-DD' (anniversary tanpa tahun).
            // Tanggal anniversary yang baru lewat / hari ini: contoh jadwal 01-01,
            // hari ini 25-08-2026 → anniversary = 01-01-2026.
            // Jika MM-DD sama dengan hari ini → anniversary = hari ini juga.
            [$mm, $dd] = explode('-', $office->leave_reset_date);
            $anniversary = Carbon::create(
                (int) $today->year, (int) $mm, (int) $dd, 0, 0, 0, $tz
            );
            if ($anniversary->greaterThan($today)) {
                $anniversary->subYear(); // anniversary tahun ini belum tiba → pakai tahun lalu
            }

            // Sudah pernah diproses untuk anniversary ini? (catch-up + anti dobel)
            // Bandingkan Carbon-to-Carbon — objek vs string di PHP 8 selalu "lebih besar".
            if ($office->last_leave_reset_on && $office->last_leave_reset_on->gte($anniversary)) {
                continue;
            }

            // Karyawan aktif milik kantor ini (attendance_setting_id). Karyawan yang belum
            // di-assign kantor tidak ikut reset otomatis (saldo mereka pakai default global).
            $userIds = User::where('company_id', $office->company_id)
                ->where('attendance_setting_id', $office->id)
                ->where('is_active', true)
                ->pluck('id');

            // Pengaturan jenis cuti tambahan yang aktif di kantor ini
            $enabledTypeSettings = \App\Models\LeaveTypeSetting::where('attendance_setting_id', $office->id)
                ->where('is_enabled', true)
                ->get()
                ->keyBy('leave_type');

            $resetCount = 0;
            foreach ($userIds as $userId) {
                // KEBIJAKAN 2026-08-25: hanya baris saldo AKTIF (quota > 0) yang di-reset.
                // Karyawan yang belum pernah diaktifkan HRD (quota 0 / belum ada baris)
                // dibiarkan non-aktif — reset tidak boleh mengaktifkan saldo otomatis.
                // $bookYear (zona penyimpanan), BUKAN $today->year: baris saldo
                // adalah kunci bersama lintas cabang. Memakai tahun lokal cabang
                // akan membuat cabang WIT mencari baris tahun berikutnya selama
                // 2 jam terakhir setiap 31 Desember.
                $existingCuti = LeaveBalance::where('user_id', $userId)
                    ->where('year', $bookYear)
                    ->where('leave_type', 'cuti')
                    ->first();

                if (! $existingCuti || (int) $existingCuti->quota <= 0) {
                    continue;
                }

                $existingIzin = LeaveBalance::where('user_id', $userId)
                    ->where('year', $bookYear)
                    ->where('leave_type', 'izin')
                    ->first();

                $otherBalances = LeaveBalance::where('user_id', $userId)
                    ->where('year', $bookYear)
                    ->whereNotIn('leave_type', ['cuti', 'izin', 'wfh'])
                    ->get()
                    ->keyBy('leave_type');

                $cutiQuota     = (int) $existingCuti->quota;
                $cutiUsed      = (int) $existingCuti->used;
                $cutiRemaining = max(0, $cutiQuota - $cutiUsed);
                $izinUsed      = (int) ($existingIzin?->used ?? 0);

                // Snapshot jenis cuti tambahan
                $typesSnapshot = [];
                foreach ($otherBalances as $typeKey => $bal) {
                    $q = (int) $bal->quota;
                    $u = (int) $bal->used;
                    $typesSnapshot[$typeKey] = [
                        'quota'     => $q,
                        'used'      => $u,
                        'remaining' => max(0, $q - $u),
                    ];
                }

                // Keputusan atas sisa cuti: dibawa / dicairkan / hangus, beserta
                // penerbitan penyesuaian payroll bila ada yang dicairkan.
                // Satu transaksi PER KARYAWAN: bila satu karyawan gagal, uang
                // yang sudah diterbitkan untuknya ikut dibatalkan, tetapi
                // karyawan lain yang sudah selesai tidak ditarik ulang.
                $closure = DB::transaction(function () use (
                    $existingCuti, $existingIzin, $otherBalances, $enabledTypeSettings,
                    $office, $anniversary, $userId, $cutiQuota, $cutiUsed, $cutiRemaining,
                    $izinUsed, $typesSnapshot
                ) {
                    $result = LeavePeriodCloser::close($existingCuti, $office, $anniversary);

                    // 1. Simpan Snapshot / Arsip ke tabel leave_balance_histories
                    \App\Models\LeaveBalanceHistory::create(array_merge([
                        'user_id'               => $userId,
                        'company_id'            => $office->company_id,
                        'attendance_setting_id' => $office->id,
                        'period_label'          => 'Periode s/d ' . $anniversary->translatedFormat('d M Y'),
                        'period_start'          => $anniversary->copy()->subYear()->addDay()->toDateString(),
                        'period_end'            => $anniversary->copy()->subDay()->toDateString(),
                        'reset_date'            => $anniversary->toDateString(),
                        'cuti_quota'            => $cutiQuota,
                        'cuti_used'             => $cutiUsed,
                        'cuti_remaining'        => $cutiRemaining,
                        'izin_sakit_used'       => $izinUsed,
                        'leave_types_snapshot'  => !empty($typesSnapshot) ? $typesSnapshot : null,
                        'notes'                 => "Reset tahunan jadwal kantor {$office->office_name}",
                    ], $result['history_fields']));

                    // 2. Reset saldo cuti tahunan — kuota baru kantor DITAMBAH hari
                    //    bawaan (bila kebijakan carry-over kantor mengizinkan).
                    $existingCuti->update($result['balance_updates']);

                    // 3. Reset saldo izin & sakit menjadi 0 untuk periode baru
                    if ($existingIzin) {
                        $existingIzin->update([
                            'used' => 0,
                        ]);
                    }

                    // 4. Reset saldo jenis cuti tambahan yang aktif di kantor ini
                    foreach ($otherBalances as $typeKey => $bal) {
                        $setting = $enabledTypeSettings->get($typeKey);
                        if ($setting) {
                            $bal->update([
                                'quota' => $setting->quota_days,
                                'used'  => 0,
                            ]);
                        }
                    }

                    return $result;
                });

                $totalCarried  += (int) $closure['decision']['carried_over_days'];
                $totalEncashed += (int) $closure['decision']['encashed_days'];
                $totalCashOut  += (float) $closure['decision']['encashment_amount'];

                $resetCount++;
            }

            // Tandai anniversary ini sudah diproses SEBELUM nilai last_leave_reset_on
            // bisa tertinggal bila command mati di tengah loop.
            $office->update(['last_leave_reset_on' => $anniversary->toDateString()]);

            $totalOffices++;
            $totalUsers += $resetCount;

            $this->info("Kantor {$office->office_name}: {$resetCount} karyawan di-reset & diarsipkan (anniversary {$anniversary->toDateString()}).");
        }

        $this->info("Selesai: {$totalOffices} kantor, {$totalUsers} saldo cuti di-reset.");

        if ($totalCarried > 0 || $totalEncashed > 0) {
            $this->info(sprintf(
                'Carry-over: %d hari dibawa. Pencairan: %d hari = Rp%s (menunggu batch payroll berikutnya).',
                $totalCarried,
                $totalEncashed,
                number_format($totalCashOut, 0, ',', '.')
            ));
        }

        return self::SUCCESS;
    }
}
