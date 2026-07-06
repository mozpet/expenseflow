<?php

namespace Database\Seeders;

use App\Models\Holiday;
use Illuminate\Database\Seeder;

class HolidaySeeder extends Seeder
{
    /**
     * Libur nasional 2026 (company_id = NULL → berlaku semua perusahaan).
     * Idempotent: bisa dijalankan ulang tanpa menduplikasi.
     *
     * Catatan: tanggal hari raya berbasis kalender bulan (Islam/Imlek/Nyepi/Waisak)
     * bersifat PERKIRAAN dan dapat berubah sesuai keputusan pemerintah — HRD dapat
     * menyesuaikan via menu Libur Nasional.
     */
    public function run(): void
    {
        $holidays = [
            ['2026-01-01', 'Tahun Baru Masehi'],
            ['2026-02-17', 'Tahun Baru Imlek 2577'],
            ['2026-03-19', 'Hari Suci Nyepi (Tahun Baru Saka 1948)'],
            ['2026-03-20', 'Hari Raya Idul Fitri 1447 H'],
            ['2026-03-21', 'Hari Raya Idul Fitri 1447 H'],
            ['2026-04-03', 'Wafat Isa Almasih'],
            ['2026-05-01', 'Hari Buruh Internasional'],
            ['2026-05-14', 'Kenaikan Isa Almasih'],
            ['2026-05-27', 'Hari Raya Idul Adha 1447 H'],
            ['2026-05-31', 'Hari Raya Waisak 2570'],
            ['2026-06-01', 'Hari Lahir Pancasila'],
            ['2026-06-16', 'Tahun Baru Islam 1448 H'],
            ['2026-08-17', 'Hari Kemerdekaan Republik Indonesia'],
            ['2026-08-25', 'Maulid Nabi Muhammad SAW'],
            ['2026-12-25', 'Hari Raya Natal'],
        ];

        foreach ($holidays as [$date, $name]) {
            $exists = Holiday::whereNull('company_id')
                ->whereDate('date', $date)
                ->exists();

            if (! $exists) {
                Holiday::create([
                    'company_id'  => null,
                    'date'        => $date,
                    'name'        => $name,
                    'is_national' => true,
                ]);
            }
        }
    }
}
