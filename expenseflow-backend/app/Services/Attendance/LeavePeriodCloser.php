<?php

namespace App\Services\Attendance;

use App\Models\AttendanceSetting;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceHistory;
use App\Models\PayrollAdjustment;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * PELAKSANA PENUTUPAN PERIODE CUTI — carry-over, pencairan, hangus (2026-10-13).
 *
 * LeaveCarryOverCalculator memutuskan BERAPA; kelas ini yang MENJALANKANNYA:
 * menerbitkan penyesuaian payroll untuk hari yang dicairkan, dan merakit nilai
 * kolom untuk baris saldo & baris riwayat.
 *
 * Ada dua pemanggil yang menutup periode cuti, dan keduanya wajib lewat sini:
 *   - ResetLeaveBalancesCommand  → reset otomatis saat anniversary (tiap jam)
 *   - AttendanceController::resetOfficeLeaveBalances() → reset manual HRD
 * Keduanya menulis riwayatnya sendiri (label periode & catatannya berbeda),
 * tetapi ANGKA dan PENERBITAN UANG hanya dihasilkan oleh metode di kelas ini.
 *
 * Pemisahan dari kalkulator disengaja: kalkulator tetap murni dan bisa diuji
 * tanpa DB, kelas ini yang menyentuh DB dan karena itu mengandung satu-satunya
 * hal yang tidak bisa diputuskan secara murni — penjagaan anti-pencairan ganda.
 */
class LeavePeriodCloser
{
    /**
     * Penanda di policy_snapshot bahwa baris riwayat ini lahir dari pengguguran
     * masa berlaku hari bawaan, bukan dari peristiwa reset tahunan.
     *
     * Dipakai pembaca hilir (label riwayat di AttendanceController) untuk
     * membedakan dua baris yang sama-sama menyentuh cuti milik satu karyawan
     * pada satu tahun. Konstanta, bukan literal, karena dua tempat harus
     * mencocokkan nilai yang sama persis.
     */
    public const EVENT_CARRY_OVER_EXPIRY = 'carry_over_expiry';

    /**
     * Tutup periode cuti satu karyawan.
     *
     * TIDAK menulis apa pun ke leave_balances maupun leave_balance_histories —
     * itu tugas pemanggil (yang tahu label periode & catatannya). Yang dilakukan
     * di sini hanyalah menghitung keputusan dan, bila ada hari yang dicairkan,
     * MENERBITKAN PayrollAdjustment-nya.
     *
     * Dipanggil DI DALAM transaksi oleh pemanggil manual; penerbitan penyesuaian
     * ikut dibatalkan bila transaksi itu gagal.
     *
     * @param  LeaveBalance $cuti   Baris saldo cuti periode yang DITUTUP (belum di-reset).
     * @param  Carbon       $resetDate Tanggal reset menurut jam dinding kantor.
     * @param  User|null    $actor  HRD pemicu reset manual; null untuk reset otomatis.
     * @return array{
     *     decision: array<string, mixed>,
     *     balance_updates: array<string, mixed>,
     *     history_fields: array<string, mixed>
     * }
     */
    public static function close(
        LeaveBalance $cuti,
        AttendanceSetting $office,
        Carbon $resetDate,
        ?User $actor = null
    ): array {
        $remaining = max(0, (int) $cuti->quota - (int) $cuti->used);

        $config   = LeaveCarryOverCalculator::configFromOffice($office);
        $decision = LeaveCarryOverCalculator::decide($remaining, $config, $resetDate);

        // ── Penerbitan uang ──────────────────────────────────────────────────
        // Penjagaan anti-dobel. Reset manual bisa diklik dua kali pada tanggal
        // yang sama (dan reset otomatis bisa menyusul di jam berikutnya bila
        // last_leave_reset_on sempat tidak tertulis). Tanpa penjagaan ini,
        // klik kedua menerbitkan penyesuaian kedua dan karyawan dibayar dua
        // kali untuk hari cuti yang sama. Kunci alaminya adalah (karyawan,
        // tanggal reset): satu periode hanya boleh dicairkan sekali.
        $adjustmentId = null;
        if ($decision['encashed_days'] > 0 && $decision['encashment_amount'] > 0) {
            // whereDate(), BUKAN where(): reset_date di-cast 'date' sehingga
            // Eloquent mengirimkannya ke DB sebagai 'Y-m-d H:i:s'. Pada kolom
            // DATE MySQL nilainya terpotong dan perbandingan string kebetulan
            // cocok, tetapi pada driver yang menyimpan apa adanya ia menjadi
            // '2027-01-01 00:00:00' ≠ '2027-01-01' — penjagaan diam-diam tidak
            // pernah menyala dan karyawan dibayar dua kali. whereDate() benar
            // di kedua driver.
            $alreadyEncashed = LeaveBalanceHistory::where('user_id', $cuti->user_id)
                ->whereDate('reset_date', $resetDate->toDateString())
                ->where('encashed_days', '>', 0)
                ->exists();

            if ($alreadyEncashed) {
                // Periode ini sudah dicairkan. Hari-harinya TIDAK dicairkan ulang
                // dan juga tidak boleh berpindah ke kolom lain seolah hangus —
                // riwayat pertama yang memegang angkanya. Baris kedua mencatat
                // nol pencairan agar invarian penjumlahan tetap utuh.
                $decision['forfeited_days']          += $decision['encashed_days'];
                $decision['encashed_days']            = 0;
                $decision['encashment_amount']        = 0.0;
                $decision['encashment_rate_per_day']  = 0.0;
                $decision['policy_snapshot']['duplicate_encashment_blocked'] = true;
            } else {
                $adjustmentId = self::issueEncashmentAdjustment($cuti, $office, $decision, $resetDate, $actor);
            }
        }

        // ── Nilai kolom untuk baris saldo periode BARU ────────────────────────
        $carried = (int) $decision['carried_over_days'];

        return [
            'decision' => $decision,

            // quota periode baru = standar kantor + hari bawaan (carried adalah
            // BAGIAN dari quota, lihat migrasi 2026_10_13_000002), used kembali 0.
            'balance_updates' => [
                'quota'                 => LeaveCarryOverCalculator::newPeriodQuota(
                    (int) $office->default_leave_quota,
                    $carried
                ),
                'used'                  => 0,
                'carried_over_quota'    => $carried,
                'carry_over_expired_at' => $decision['carry_over_expired_at'],
            ],

            // Kolom snapshot untuk leave_balance_histories. Pemanggil menggabung
            // ini dengan field periode & catatannya sendiri.
            'history_fields' => [
                'carried_over_days'        => $carried,
                'carry_over_expired_at'    => $decision['carry_over_expired_at'],
                'encashed_days'            => (int) $decision['encashed_days'],
                'encashment_rate_per_day'  => $decision['encashed_days'] > 0
                    ? $decision['encashment_rate_per_day']
                    : null,
                'encashment_amount'        => $decision['encashed_days'] > 0
                    ? $decision['encashment_amount']
                    : null,
                'encashment_adjustment_id' => $adjustmentId,
                'forfeited_days'           => (int) $decision['forfeited_days'],
                'policy_snapshot'          => $decision['policy_snapshot'],
            ],
        ];
    }

    /**
     * Terbitkan pencairan sisa cuti sebagai penyesuaian payroll.
     *
     * Mekanisme pembayaran dipilih SENGAJA memakai PayrollAdjustment, bukan
     * komponen baru di PayrollCalculator:
     *
     *  - payroll_id NULL + status approved = "menunggu batch". Batch payroll
     *    berikutnya mengklaimnya otomatis (status→applied) dan membayarnya pada
     *    tanggal gajian grup karyawan itu — persis "cair di bulan gajian".
     *  - Idempotensi payroll yang sudah ada langsung berlaku: recalculate
     *    melepas lalu mengklaim ulang, hapus batch melepasnya kembali ke
     *    approved. Tidak ada satu pun jalur baru yang perlu dibuat untuk itu.
     *  - Bila batch bulan ini sudah dihitung/disetujui, penyesuaian tetap
     *    menggantung dan ikut batch berikutnya — tidak hilang, tidak memaksa
     *    membuka batch yang sudah terkunci.
     *
     * Kena pajak (is_taxable = true): pencairan cuti adalah penghasilan atas
     * pekerjaan, bukan penggantian biaya, sehingga masuk bruto PPh 21.
     */
    private static function issueEncashmentAdjustment(
        LeaveBalance $cuti,
        AttendanceSetting $office,
        array $decision,
        Carbon $resetDate,
        ?User $actor
    ): int {
        $days = (int) $decision['encashed_days'];
        $rate = (float) $decision['encashment_rate_per_day'];

        $reason = sprintf(
            'Pencairan %d hari sisa cuti tahunan periode s/d %s @ Rp%s/hari '
            . '(kebijakan kantor %s). %s',
            $days,
            $resetDate->format('d-m-Y'),
            number_format($rate, 0, ',', '.'),
            $office->office_name,
            $actor
                ? "Reset manual oleh {$actor->name}."
                : 'Reset otomatis sesuai jadwal anniversary kantor.'
        );

        $adjustment = PayrollAdjustment::create([
            'company_id' => $cuti->company_id ?? $office->company_id,
            'user_id'    => $cuti->user_id,
            // NULL = belum diklaim batch mana pun; inilah yang membuatnya
            // otomatis masuk ke batch payroll berikutnya.
            'payroll_id' => null,
            'type'       => PayrollAdjustment::TYPE_EARNING,
            // Konstanta, bukan literal: PayrollCalculator mencocokkan nama ini
            // untuk memberi jejak perhitungan STEP_LEAVE_ENCASHMENT.
            'name'       => PayrollAdjustment::NAME_LEAVE_ENCASHMENT,
            'amount'     => $decision['encashment_amount'],
            'is_taxable' => true,
            'reason'     => $reason,
            // Langsung approved: persetujuannya adalah tindakan HRD mencentang
            // kebijakan pencairan dan mengisi tarifnya (kedua field itu dijaga
            // konfirmasi 'SIMPAN' di updateSettings). Menyisakannya pending akan
            // menuntut persetujuan kedua atas keputusan yang sudah diambil, dan
            // pada reset otomatis tidak ada manusia yang bisa menyetujuinya.
            'status'      => PayrollAdjustment::STATUS_APPROVED,
            'created_by'  => $actor?->id,
            'approved_by' => $actor?->id,
            'approved_at' => now(),
        ]);

        return (int) $adjustment->id;
    }

    /**
     * Tutup masa berlaku hari bawaan: CAIRKAN bila kebijakan kantor mengizinkan,
     * sisanya gugurkan.
     *
     * Dijalankan tiap jam oleh ResetLeaveBalancesCommand untuk SELURUH kantor
     * (bukan hanya yang sedang anniversary): tanggal kedaluwarsa carry-over
     * jatuh di tengah periode — praktik umumnya 3 bulan setelah reset — jadi
     * tidak ada peristiwa reset yang bisa menanganinya.
     *
     * INILAH TEMPAT UANG PENCAIRAN TERBIT untuk kantor dengan masa berlaku
     * N > 0 bulan. Urutannya disengaja: karyawan lebih dulu diberi N bulan untuk
     * memakai hari bawaannya sebagai CUTI, dan hanya hari yang tidak terpakai
     * sampai kesempatan itu habis yang ditukar menjadi uang. Hari di atas batas
     * carry-over tidak pernah sampai ke sini — ia sudah hangus di tanggal reset
     * oleh LeaveCarryOverCalculator::decide().
     *
     * Yang diproses hanya hari bawaan yang BELUM TERPAKAI. Hari bawaan yang
     * sudah dipakai karyawan tidak bisa ditarik kembali maupun dibayar ulang,
     * karena cutinya sudah diambil; karena itu dibatasi oleh (quota - used).
     *
     * Satu transaksi PER KARYAWAN: kegagalan di tengah tidak menyisakan uang
     * yang sudah terbit tanpa pengurangan saldo yang mempertanggungjawabkannya.
     *
     * @return array{processed: int, encashed_days: int, encashed_amount: float, forfeited_days: int}
     */
    public static function expireCarryOver(Carbon $today): array
    {
        $expired = LeaveBalance::with('user.office')
            ->where('leave_type', 'cuti')
            ->where('carried_over_quota', '>', 0)
            ->whereNotNull('carry_over_expired_at')
            ->whereDate('carry_over_expired_at', '<', $today->toDateString())
            ->get();

        $summary = [
            'processed'       => 0,
            'encashed_days'   => 0,
            'encashed_amount' => 0.0,
            'forfeited_days'  => 0,
        ];

        foreach ($expired as $balance) {
            $carried   = (int) $balance->carried_over_quota;
            $remaining = max(0, (int) $balance->quota - (int) $balance->used);

            // Hari bawaan yang masih tersisa. Bila karyawan sudah memakai cuti
            // lebih banyak dari kuota dasarnya, sebagian hari bawaan sudah
            // terpakai dan hanya selebihnya yang punya takdir di sini.
            $unused = min($carried, $remaining);

            // Kebijakan kantor karyawan ini. Kantor NULL (tanpa penempatan)
            // jatuh ke default netral: tidak ada pencairan, seluruhnya hangus.
            $office = $balance->user?->office;
            $config = LeaveCarryOverCalculator::configFromOffice($office);

            $rate     = (float) $config['encashment_rate_per_day'];
            $encashed = 0;
            if ($config['encashment_enabled'] && $rate > 0 && $unused > 0) {
                $encashed = LeaveCarryOverCalculator::capEncashable($unused, $config);
            }
            $forfeited = $unused - $encashed;

            DB::transaction(function () use (
                $balance, $office, $config, $today, $carried, $unused,
                $encashed, $forfeited, $rate
            ) {
                $adjustmentId = null;
                if ($encashed > 0 && $office) {
                    $adjustmentId = self::issueExpiryEncashmentAdjustment(
                        $balance, $office, $encashed, $rate, $today
                    );
                }

                // Hari bawaan dikosongkan SELURUHNYA dari quota — baik yang
                // dicairkan maupun yang hangus. Hari yang sudah ditukar uang
                // tidak boleh tetap dipegang sebagai hak cuti (dibayar dua
                // kali untuk satu hari).
                $balance->update([
                    'quota'                 => max(0, (int) $balance->quota - $unused),
                    'carried_over_quota'    => 0,
                    // Dinolkan bersamaan: tanggal kedaluwarsa tanpa saldo bawaan
                    // yang diawasinya membuat baris ini terus terpilih query di
                    // atas setiap jam.
                    'carry_over_expired_at' => null,
                ]);

                // Baris riwayat TERSENDIRI, bukan menumpang baris reset: ini
                // peristiwa berbeda pada tanggal berbeda (3 bulan setelah
                // reset), dan HRD harus bisa melihat kapan uangnya terbit tanpa
                // membaca slip gaji. cuti_remaining di sini adalah hari bawaan
                // yang belum terpakai, sehingga invarian
                // remaining = carried + encashed + forfeited tetap utuh dengan
                // carried = 0 (tidak ada lagi yang dibawa setelah ini).
                LeaveBalanceHistory::create([
                    'user_id'                  => $balance->user_id,
                    'company_id'               => $balance->company_id ?? $office?->company_id,
                    'attendance_setting_id'    => $office?->id,
                    'period_label'             => 'Masa berlaku cuti bawaan s/d '
                        . $today->copy()->subDay()->translatedFormat('d M Y'),
                    'period_end'               => $today->copy()->subDay()->toDateString(),
                    'reset_date'               => $today->toDateString(),
                    'cuti_quota'               => $carried,
                    'cuti_used'                => $carried - $unused,
                    'cuti_remaining'           => $unused,
                    'carried_over_days'        => 0,
                    'carry_over_expired_at'    => null,
                    'encashed_days'            => $encashed,
                    'encashment_rate_per_day'  => $encashed > 0 ? $rate : null,
                    'encashment_amount'        => $encashed > 0 ? round($encashed * $rate, 2) : null,
                    'encashment_adjustment_id' => $adjustmentId,
                    'forfeited_days'           => $forfeited,
                    'policy_snapshot'          => [
                        'event'                    => self::EVENT_CARRY_OVER_EXPIRY,
                        'carry_over_enabled'       => $config['carry_over_enabled'],
                        'carry_over_max_days'      => $config['carry_over_max_days'],
                        'carry_over_expiry_months' => $config['carry_over_expiry_months'],
                        'encashment_enabled'       => $config['encashment_enabled'],
                        'encashment_rate_per_day'  => $rate,
                        'encashment_max_days'      => $config['encashment_max_days'],
                        'hard_cap_days'            => LeaveCarryOverCalculator::MAX_POLICY_DAYS,
                    ],
                    'notes'                    => $encashed > 0
                        ? 'Pencairan hari bawaan cuti yang kedaluwarsa.'
                        : 'Hari bawaan cuti hangus karena melewati masa berlaku.',
                ]);
            });

            $summary['processed']++;
            $summary['encashed_days']   += $encashed;
            $summary['encashed_amount'] += round($encashed * $rate, 2);
            $summary['forfeited_days']  += $forfeited;
        }

        return $summary;
    }

    /**
     * Terbitkan pencairan hari bawaan yang kedaluwarsa.
     *
     * Memakai mekanisme yang sama dengan pencairan di tanggal reset
     * (PayrollAdjustment menggantung, diklaim batch payroll berikutnya), karena
     * pertimbangannya identik — lihat issueEncashmentAdjustment() di atas.
     * Dibedakan hanya pada teks `reason`, yang harus menyebut peristiwa
     * kedaluwarsa agar karyawan bisa mencocokkannya dengan masa berlaku yang
     * diumumkan kantor.
     */
    private static function issueExpiryEncashmentAdjustment(
        LeaveBalance $balance,
        AttendanceSetting $office,
        int $days,
        float $rate,
        Carbon $today
    ): int {
        $reason = sprintf(
            'Pencairan %d hari cuti bawaan yang kedaluwarsa pada %s @ Rp%s/hari '
            . '(kebijakan kantor %s: masa berlaku %s bulan setelah reset).',
            $days,
            $today->copy()->subDay()->format('d-m-Y'),
            number_format($rate, 0, ',', '.'),
            $office->office_name,
            $office->leave_carry_over_expiry_months ?? '-'
        );

        $adjustment = PayrollAdjustment::create([
            'company_id' => $balance->company_id ?? $office->company_id,
            'user_id'    => $balance->user_id,
            'payroll_id' => null,
            'type'       => PayrollAdjustment::TYPE_EARNING,
            'name'       => PayrollAdjustment::NAME_LEAVE_ENCASHMENT,
            'amount'     => round($days * $rate, 2),
            'is_taxable' => true,
            'reason'     => $reason,
            // Tidak ada aktor manusia: pengguguran berjalan otomatis tiap jam.
            // Persetujuannya adalah tindakan HRD mencentang kebijakan pencairan
            // dan mengisi tarifnya (dijaga gerbang konfirmasi 'SIMPAN').
            'status'      => PayrollAdjustment::STATUS_APPROVED,
            'created_by'  => null,
            'approved_by' => null,
            'approved_at' => now(),
        ]);

        return (int) $adjustment->id;
    }
}
