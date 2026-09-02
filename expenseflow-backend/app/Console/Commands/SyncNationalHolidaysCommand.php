<?php

namespace App\Console\Commands;

use App\Models\Holiday;
use App\Services\IndonesianHolidayService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class SyncNationalHolidaysCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'holidays:sync-national
                            {year? : Tahun yang ingin disinkronkan (default tahun saat ini)}
                            {--overwrite : Timpa hari libur yang sudah ada sebelumnya}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sinkronkan hari libur nasional resmi Indonesia dari API Hari Libur / dataset kurasi SKB 3 Menteri';

    /**
     * Execute the console command.
     */
    public function handle(IndonesianHolidayService $service): int
    {
        $year = (int) ($this->argument('year') ?: now('Asia/Jakarta')->year);
        $overwrite = (bool) $this->option('overwrite');

        $this->info("Menghubungi API Hari Libur Indonesia untuk tahun {$year}...");

        $holidays = $service->getHolidays($year);

        if (empty($holidays)) {
            $this->error("Tidak ada data hari libur ditemukan untuk tahun {$year}.");
            return self::FAILURE;
        }

        $this->info("Ditemukan " . count($holidays) . " hari libur (Sumber: {$holidays[0]['source']}).");

        $synced = 0;
        $skipped = 0;

        foreach ($holidays as $item) {
            $date = $item['date'];
            $name = $item['name'];
            $isCollective = $item['is_collective'];
            $isNational = $item['is_national'];

            $existing = Holiday::whereDate('date', $date)
                ->whereNull('company_id')
                ->first();

            if ($existing) {
                if ($overwrite) {
                    $existing->update([
                        'name'          => $name,
                        'is_national'   => $isNational,
                        'is_collective' => $isCollective,
                    ]);
                    $this->line(" [UPDATE] {$date} - {$name}");
                    $synced++;
                } else {
                    $this->line(" [SKIP]   {$date} - {$name} (Sudah terdaftar)");
                    $skipped++;
                }
            } else {
                Holiday::create([
                    'company_id'            => null,
                    'attendance_setting_id' => null,
                    'date'                  => $date,
                    'name'                  => $name,
                    'is_national'           => $isNational,
                    'is_collective'         => $isCollective,
                ]);
                $this->info(" [CREATE] {$date} - {$name} (" . ($isCollective ? 'Cuti Bersama' : 'Libur Nasional') . ")");
                $synced++;
            }
        }

        $this->newLine();
        $this->info("Sinkronisasi selesai: {$synced} diproses, {$skipped} dilewati.");

        return self::SUCCESS;
    }
}
