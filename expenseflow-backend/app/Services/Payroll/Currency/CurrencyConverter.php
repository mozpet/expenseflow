<?php

namespace App\Services\Payroll\Currency;

use App\Models\CurrencyRate;
use App\Models\Payroll;
use RuntimeException;

/**
 * Resolusi & penguncian kurs untuk penggajian valuta asing (Fase 6).
 *
 * ATURAN DASAR: seluruh kolom uang pada `payslips` tetap dalam RUPIAH (mata uang
 * basis) karena PPh 21/26, iuran BPJS, jurnal GL, dan Bukti Potong 1721-A1 wajib
 * disajikan dalam Rupiah. Nominal valas disimpan sebagai kolom TAMBAHAN
 * (`currency`, `exchange_rate`, `gross_currency`, `net_currency`).
 *
 * PENGUNCIAN KURS: kurs yang dipakai saat kalkulasi disimpan ke
 * `payrolls.exchange_rates` (snapshot JSON). Selama batch belum dihitung ulang,
 * snapshot itu yang dipakai — sehingga angka slip tidak berubah diam-diam saat
 * master kurs di-update. Rekalkulasi membangun ulang snapshot dari master.
 *
 * TIDAK ADA ASUMSI 1:1. Bila kurs tidak ditemukan, konversi GAGAL (exception)
 * agar batch ditolak, bukan menghasilkan angka yang salah senyap.
 */
class CurrencyConverter
{
    public const BASE = CurrencyRate::BASE_CURRENCY; // 'IDR'

    /** Kurs yang terkunci selama satu proses kalkulasi: [KODE => rate]. */
    private array $locked = [];

    /** Mulai sesi konversi untuk sebuah batch (memuat snapshot kurs yang ada). */
    public function beginRun(Payroll $payroll): void
    {
        $this->locked = [];
        foreach ((array) ($payroll->exchange_rates ?? []) as $code => $rate) {
            $code = strtoupper((string) $code);
            $rate = (float) $rate;
            if ($code !== '' && $rate > 0) {
                $this->locked[$code] = $rate;
            }
        }
    }

    /**
     * Kurs 1 unit mata uang → Rupiah pada tanggal tertentu.
     *
     * @throws RuntimeException bila mata uang non-Rupiah tidak punya kurs berlaku.
     */
    public function rate(string $currency, string $onDate, ?int $companyId = null): float
    {
        $code = strtoupper(trim($currency)) ?: self::BASE;
        if ($code === self::BASE) {
            return 1.0;
        }

        if (isset($this->locked[$code])) {
            return $this->locked[$code];
        }

        $rate = CurrencyRate::resolve($code, $onDate, $companyId);
        if ($rate === null || $rate <= 0) {
            throw new RuntimeException(
                "Kurs {$code} → IDR pada {$onDate} belum tersedia. Isi master kurs terlebih dahulu."
            );
        }

        return $this->locked[$code] = $rate;
    }

    /** Konversi nominal valas → Rupiah (dibulatkan ke rupiah penuh). */
    public function toIdr(float $amount, string $currency, string $onDate, ?int $companyId = null): float
    {
        $rate = $this->rate($currency, $onDate, $companyId);

        return $rate === 1.0 ? round($amount, 2) : round($amount * $rate);
    }

    /** True bila mata uang bukan Rupiah (perlu konversi & baris jejak CURRENCY). */
    public function isForeign(?string $currency): bool
    {
        return strtoupper(trim((string) ($currency ?: self::BASE))) !== self::BASE;
    }

    /** Normalisasi kode mata uang (kosong → IDR, selalu huruf besar). */
    public function normalize(?string $currency): string
    {
        return strtoupper(trim((string) ($currency ?: self::BASE))) ?: self::BASE;
    }

    /**
     * Snapshot kurs yang benar-benar dipakai batch ini (untuk disimpan ke
     * `payrolls.exchange_rates`). NULL bila batch murni Rupiah.
     *
     * @return array<string, float>|null
     */
    public function lockedRates(): ?array
    {
        return $this->locked === [] ? null : $this->locked;
    }
}
