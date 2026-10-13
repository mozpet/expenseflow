<?php

namespace App\Services\Attendance;

use App\Models\AttendanceSetting;
use Illuminate\Support\Carbon;

/**
 * KEPUTUSAN ATAS SISA CUTI TAHUNAN SAAT PERIODE DITUTUP (2026-10-13).
 *
 * Satu-satunya tempat yang memutuskan: dari sisa cuti karyawan, berapa hari
 * DIBAWA ke periode baru, berapa hari DICAIRKAN jadi uang, dan berapa hari
 * HANGUS. Dua pemanggil wajib memakai kelas ini dan tidak boleh menduplikasi
 * aturannya — ResetLeaveBalancesCommand (reset otomatis per anniversary) dan
 * AttendanceController::resetOfficeLeaveBalances() (reset manual HRD). Dua jalur
 * yang menuliskan angka berbeda untuk karyawan yang sama adalah kelas bug yang
 * paling mahal di modul ini, dan satu-satunya pencegahnya adalah satu fungsi.
 *
 * Murni: tidak membaca DB, tidak membaca jam sistem. Tanggal anniversary &
 * konfigurasi kantor DISUNTIKKAN pemanggil, sehingga seluruh matriks kebijakan
 * bisa dibuktikan di test tanpa menyiapkan basis data.
 *
 * ── URUTAN KEPUTUSAN (bukan arbitrer, ini permintaan eksplisit HRD) ──────────
 * Hari yang TIDAK terbawa (kelebihan di atas batas kantor) HANGUS di tanggal
 * reset — hari itu tidak pernah menjadi uang. Yang bisa dicairkan justru hari
 * BAWAAN yang masa berlakunya habis tanpa terpakai: karyawan sudah diberi
 * kesempatan memakainya sebagai cuti, dan baru setelah kesempatan itu lewat
 * perusahaan menggantinya dengan uang. Pencairan karena itu TIDAK terjadi di
 * tanggal reset (kecuali masa berlaku 0 bulan, lihat di bawah), melainkan di
 * tanggal kedaluwarsa hari bawaan.
 *
 * Konsekuensi penting: pencairan HANYA bisa aktif bila carry-over aktif, karena
 * tanpa hari bawaan tidak ada sumber hari yang bisa dicairkan. Kombinasi
 * carry OFF + cair ON ditolak guard 422 di lapisan HTTP.
 *
 * ── PERAN `carry_over_expiry_months` ────────────────────────────────────────
 *   NULL → hari bawaan tidak pernah kedaluwarsa. Tidak ada pencairan yang bisa
 *          dipicu, jadi kombinasi ini pun ditolak bila pencairan aktif.
 *   0    → hari bawaan tidak diberi waktu sama sekali untuk dipakai, sehingga
 *          takdirnya diputuskan DI TANGGAL RESET juga: langsung dicairkan.
 *          Inilah jalan bagi kantor yang ingin "sisa cuti langsung jadi uang".
 *   N>0  → hari bawaan hidup N bulan sebagai cuti. Yang masih tersisa pada
 *          tanggal kedaluwarsa dicairkan (bila pencairan aktif) atau hangus.
 *          Pelaksananya LeavePeriodCloser::expireCarryOver(), bukan kelas ini.
 *
 * ── MATRIKS LENGKAP ─────────────────────────────────────────────────────────
 *   carry OFF                   → semua HANGUS       (perilaku sebelum fitur ini)
 *   carry ON, cair OFF, masa N  → maks N dibawa; kelebihan HANGUS; hari bawaan
 *                                 yang tak terpakai HANGUS saat kedaluwarsa
 *   carry ON, cair OFF, masa —  → maks N dibawa selamanya; kelebihan HANGUS
 *   carry ON, cair ON , masa 0  → maks N dibawa lalu DICAIRKAN saat itu juga;
 *                                 kelebihan HANGUS
 *   carry ON, cair ON , masa N  → maks N dibawa; kelebihan HANGUS; hari bawaan
 *                                 yang tak terpakai DICAIRKAN saat kedaluwarsa
 *
 * ── INVARIAN YANG DIJAMIN ───────────────────────────────────────────────────
 *     remaining = carried_over_days + encashed_days + forfeited_days
 * Selalu, untuk setiap kombinasi konfigurasi. Sisa cuti tidak bisa menguap dan
 * tidak bisa berganda; setiap hari punya tepat satu takdir.
 */
class LeaveCarryOverCalculator
{
    /**
     * Pagu keras hari yang boleh dibawa/dicairkan dalam satu periode.
     *
     * Bukan aturan HR, melainkan rem terhadap data rusak: kuota 365 hari
     * (batas atas default_leave_quota) yang tak pernah dipakai akan menjadi
     * tagihan pencairan 365 hari sekaligus. Validasi di settingRules()
     * memakai batas yang sama agar HRD tidak bisa menyimpan nilai di atasnya.
     */
    public const MAX_POLICY_DAYS = 60;

    /**
     * Konfigurasi kedua kebijakan untuk satu cabang.
     *
     * Kantor NULL (karyawan tanpa penempatan) jatuh ke default netral: kedua
     * checkbox OFF, sehingga sisa cutinya hangus seperti sebelum fitur ini ada.
     *
     * @return array{
     *     carry_over_enabled: bool, carry_over_max_days: int, carry_over_expiry_months: int|null,
     *     encashment_enabled: bool, encashment_rate_per_day: float, encashment_max_days: int|null
     * }
     */
    public static function configFromOffice(?AttendanceSetting $office): array
    {
        $carryEnabled = (bool) ($office?->leave_carry_over_enabled ?? false);
        $cashEnabled  = (bool) ($office?->leave_encashment_enabled ?? false);

        // Batas carry-over 0/NULL saat checkbox aktif TIDAK dipaksa ke nilai
        // default di sini: 0 hari berarti nol hari dibawa, dan itu diperlakukan
        // sama seperti checkbox OFF oleh decide(). Guard di lapisan HTTP yang
        // mencegah kombinasi itu tersimpan; kalkulator tidak menebak maksud HRD.
        //
        // Masa berlaku: NULL dan 0 adalah DUA ARTI BERBEDA dan tidak boleh
        // disamakan. NULL = hari bawaan tidak pernah kedaluwarsa. 0 = hari
        // bawaan tidak diberi waktu untuk dipakai, sehingga takdirnya langsung
        // diputuskan di tanggal reset (dicairkan bila pencairan aktif). Versi
        // sebelumnya memetakan <= 0 menjadi NULL dan karena itu tidak bisa
        // menyatakan "cairkan segera".
        $expiry = $office?->leave_carry_over_expiry_months;

        return [
            'carry_over_enabled'       => $carryEnabled,
            'carry_over_max_days'      => max(0, (int) ($office?->leave_carry_over_max_days ?? 0)),
            'carry_over_expiry_months' => $expiry === null ? null : max(0, (int) $expiry),
            'encashment_enabled'       => $cashEnabled,
            'encashment_rate_per_day'  => max(0.0, (float) ($office?->leave_encashment_rate_per_day ?? 0)),
            'encashment_max_days'      => $office?->leave_encashment_max_days === null
                ? null
                : max(0, (int) $office->leave_encashment_max_days),
        ];
    }

    /**
     * Bagi sisa cuti menjadi dibawa / dicairkan / hangus.
     *
     * @param  int    $remaining    Sisa cuti periode yang ditutup (quota - used).
     * @param  array  $config       Hasil configFromOffice().
     * @param  Carbon $resetDate    Tanggal reset (anniversary) — basis kedaluwarsa.
     * @return array{
     *     remaining: int,
     *     carried_over_days: int,
     *     carry_over_expired_at: string|null,
     *     encashed_days: int,
     *     encashment_rate_per_day: float,
     *     encashment_amount: float,
     *     forfeited_days: int,
     *     policy_snapshot: array<string, mixed>
     * }
     */
    public static function decide(int $remaining, array $config, Carbon $resetDate): array
    {
        $remaining = max(0, $remaining);

        $carryEnabled = (bool) ($config['carry_over_enabled'] ?? false);
        $cashEnabled  = (bool) ($config['encashment_enabled'] ?? false);
        $rate         = max(0.0, (float) ($config['encashment_rate_per_day'] ?? 0));

        // 1) Carry-over lebih dulu. Dibatasi tiga hal sekaligus: sisa yang ada,
        //    batas kantor, dan pagu keras kelas ini.
        $carried = 0;
        if ($carryEnabled) {
            $carried = min(
                $remaining,
                max(0, (int) ($config['carry_over_max_days'] ?? 0)),
                self::MAX_POLICY_DAYS
            );
        }

        // 2) Hari di ATAS batas carry-over HANGUS di sini, dan tidak pernah
        //    menjadi uang. Hari-hari itu melampaui apa yang kantor bersedia
        //    bawa ke periode berikutnya; membayarnya berarti kantor membayar
        //    justru hari yang ia putuskan tidak diakui.
        $notCarried = $remaining - $carried;

        // 3) Pencairan mengambil dari hari BAWAAN, bukan dari kelebihan di atas.
        //    Di tanggal reset itu hanya terjadi bila masa berlakunya 0 bulan —
        //    hari bawaan tidak diberi waktu untuk dipakai, jadi takdirnya sudah
        //    final sekarang. Untuk masa berlaku N > 0 bulan, pencairan ditunda
        //    sampai tanggal kedaluwarsa dan dikerjakan
        //    LeavePeriodCloser::expireCarryOver(); di sini ia tetap 0 karena
        //    karyawan masih memegang hari-hari itu sebagai hak cuti.
        //
        //    Tarif 0 berarti tidak ada uang yang bisa diterbitkan, jadi harinya
        //    hangus, bukan dicairkan senilai Rp0: baris penyesuaian Rp0 di slip
        //    gaji hanya membingungkan.
        $expiryMonths = $config['carry_over_expiry_months'] ?? null;
        $encashed     = 0;
        if ($expiryMonths === 0 && $carried > 0) {
            $encashed = ($cashEnabled && $rate > 0)
                ? self::capEncashable($carried, $config)
                : 0;

            // 4) Masa berlaku 0 bulan menghabiskan SELURUH hari bawaan hari ini:
            //    yang tidak tertampung pagu pencairan (atau seluruhnya, bila
            //    pencairan mati) hangus bersamaan. Menyisakannya sebagai hari
            //    bawaan akan membuatnya abadi — tidak ada tanggal kedaluwarsa
            //    yang bisa menggugurkannya, sehingga "tanpa masa berlaku" justru
            //    menjadi masa berlaku selamanya.
            $carried = 0;
        }

        // 5) Sisanya hangus. Dihitung sebagai selisih (bukan dihitung ulang dari
        //    aturan) sehingga invarian penjumlahan tidak mungkin dilanggar oleh
        //    perubahan aturan di masa depan.
        $forfeited = $remaining - $carried - $encashed;

        // Kedaluwarsa hari bawaan. Tanpa hari bawaan tidak ada yang kedaluwarsa,
        // jadi NULL — bukan tanggal yang menggantung tanpa saldo yang diawasinya.
        // Masa berlaku 0 bulan juga tidak menyisakan tanggal: harinya sudah
        // dicairkan di atas, dan bila pencairan mati harinya hangus sekarang.
        $expiredAt = null;
        if ($carried > 0 && $expiryMonths !== null && $expiryMonths > 0) {
            $expiredAt = $resetDate->copy()
                ->addMonthsNoOverflow($expiryMonths)
                ->toDateString();
        }

        return [
            'remaining'               => $remaining,
            'carried_over_days'       => $carried,
            'carry_over_expired_at'   => $expiredAt,
            'encashed_days'           => $encashed,
            'encashment_rate_per_day' => $encashed > 0 ? $rate : 0.0,
            'encashment_amount'       => round($encashed * $rate, 2),
            'forfeited_days'          => $forfeited,
            // Konteks kebijakan DIBEKUKAN bersama hasilnya: laporan tahun depan
            // harus bisa menjelaskan "kenapa hanya 6 hari yang dibawa" tanpa
            // bergantung pada konfigurasi kantor yang berlaku saat itu.
            'policy_snapshot'         => [
                'carry_over_enabled'       => $carryEnabled,
                'carry_over_max_days'      => (int) ($config['carry_over_max_days'] ?? 0),
                'carry_over_expiry_months' => $config['carry_over_expiry_months'] ?? null,
                'encashment_enabled'       => $cashEnabled,
                'encashment_rate_per_day'  => $rate,
                'encashment_max_days'      => $config['encashment_max_days'] ?? null,
                'hard_cap_days'            => self::MAX_POLICY_DAYS,
            ],
        ];
    }

    /**
     * Batasi hari yang boleh dicairkan oleh pagu kantor & pagu keras kelas ini.
     *
     * Dipisah sebagai fungsi karena DUA peristiwa mencairkan hari bawaan dan
     * keduanya wajib memakai pagu yang sama: decide() saat masa berlaku 0 bulan,
     * dan LeavePeriodCloser::expireCarryOver() saat hari bawaan kedaluwarsa.
     * Pagu yang berbeda antar dua jalur berarti karyawan menerima nominal
     * berbeda hanya karena kantornya memilih "cair segera" atau "cair nanti".
     *
     * NULL pada encashment_max_days = tanpa pagu kantor; 0 = tidak ada hari yang
     * boleh dicairkan. Dua arti berbeda, dan karena itu tidak boleh disamakan.
     */
    public static function capEncashable(int $days, array $config): int
    {
        $days = max(0, $days);
        $pagu = $config['encashment_max_days'] ?? null;

        return $pagu === null
            ? min($days, self::MAX_POLICY_DAYS)
            : min($days, max(0, (int) $pagu), self::MAX_POLICY_DAYS);
    }

    /**
     * Kuota periode baru untuk karyawan ini.
     *
     * INVARIAN (lihat migrasi 2026_10_13_000002): carried_over_quota adalah
     * BAGIAN DARI quota, bukan saldo di sebelahnya. Dengan begitu seluruh
     * pembaca saldo yang sudah ada terus memakai (quota - used) tanpa perlu
     * tahu soal carry-over — termasuk jalur yang menolak pengajuan cuti karena
     * saldo tidak cukup. Jika carry-over disimpan terpisah, setiap pembaca
     * harus diubah dan yang terlewat akan menolak cuti yang masih ada haknya.
     */
    public static function newPeriodQuota(int $defaultQuota, int $carriedOverDays): int
    {
        return max(0, $defaultQuota) + max(0, $carriedOverDays);
    }

    /**
     * Apakah hari bawaan karyawan sudah kedaluwarsa pada tanggal $today?
     *
     * Dipisah sebagai fungsi agar pengguguran hari bawaan (dijalankan tiap jam
     * oleh ResetLeaveBalancesCommand) memakai perbandingan yang sama persis
     * dengan yang dipakai saat menetapkan tanggalnya. Perbandingan string
     * 'Y-m-d' sengaja dipakai: keduanya tanggal kalender tanpa jam, dan
     * membandingkan objek Carbon berzona berbeda akan menggeser batas sehari.
     */
    public static function isCarryOverExpired(?string $expiredAt, Carbon $today): bool
    {
        return $expiredAt !== null && $today->toDateString() > $expiredAt;
    }
}
