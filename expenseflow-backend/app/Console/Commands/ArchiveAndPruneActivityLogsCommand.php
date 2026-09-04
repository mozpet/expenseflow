<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class ArchiveAndPruneActivityLogsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'logs:archive-and-prune 
                            {--days-info=90 : Umur log info/rutin dalam hari sebelum diarsipkan (default: 90)}
                            {--days-critical=365 : Umur log critical & warning dalam hari sebelum diarsipkan (default: 365)}
                            {--chunk=500 : Ukuran batch per pengambilan data}
                            {--dry-run : Simulasi hitung data tanpa mengubah database}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Mengarsipkan log audit lama ke file terkompresi (.json.gz) lalu membersihkannya dari database agar database tetap cepat dan ringan.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $daysInfo     = max(7, (int) $this->option('days-info'));
        $daysCritical = max(30, (int) $this->option('days-critical'));
        $chunkSize    = max(50, min(2000, (int) $this->option('chunk')));
        $dryRun       = (bool) $this->option('dry-run');

        $cutoffInfo     = Carbon::now()->subDays($daysInfo)->toDateTimeString();
        $cutoffCritical = Carbon::now()->subDays($daysCritical)->toDateTimeString();

        $this->info("═══════════════════════════════════════════════════════════════");
        $this->info("  AUDIT LOG RETENTION & ARCHIVING SYSTEM");
        $this->info("═══════════════════════════════════════════════════════════════");
        $this->line("  • Batas umur log INFO/rutin  : {$daysInfo} hari (sebelum {$cutoffInfo})");
        $this->line("  • Batas umur log KRITIS      : {$daysCritical} hari (sebelum {$cutoffCritical})");
        $this->line("  • Mode Operasi               : " . ($dryRun ? "<comment>DRY-RUN (Simulasi)</comment>" : "<info>LIVE ARCHIVE & PRUNE</info>"));
        $this->newLine();

        // 1. Query kriteria log yang memenuhi syarat rotasi
        // Log info: umur > daysInfo
        // Log warning/critical: umur > daysCritical
        $buildPrunableQuery = function () use ($cutoffInfo, $cutoffCritical) {
            return DB::table('activity_logs')
                ->where(function ($q) use ($cutoffInfo, $cutoffCritical) {
                    $q->where(function ($qInfo) use ($cutoffInfo) {
                        $qInfo->where(function ($sev) {
                            $sev->whereNull('severity')
                                ->orWhere('severity', 'info');
                        })->where('created_at', '<', $cutoffInfo);
                    })->orWhere(function ($qCrit) use ($cutoffCritical) {
                        $qCrit->whereIn('severity', ['warning', 'critical'])
                              ->where('created_at', '<', $cutoffCritical);
                    });
                });
        };

        $totalCount = $buildPrunableQuery()->count();

        if ($totalCount === 0) {
            $this->info("✅ Tidak ada log audit lama yang memenuhi batas rotasi. Database dalam kondisi prima!");
            return Command::SUCCESS;
        }

        $infoCount = $buildPrunableQuery()->where(function ($sev) {
            $sev->whereNull('severity')->orWhere('severity', 'info');
        })->count();

        $critCount = $buildPrunableQuery()->whereIn('severity', ['warning', 'critical'])->count();

        $this->table(
            ['Kategori Log', 'Kriteria Umur', 'Jumlah Baris Ditemukan'],
            [
                ['Info / Rutin', "> {$daysInfo} hari", number_format($infoCount)],
                ['Warning / Kritis', "> {$daysCritical} hari", number_format($critCount)],
                ['TOTAL MEMENUHI SYARAT', '-', number_format($totalCount)],
            ]
        );

        if ($dryRun) {
            $this->warn("⚠️ Mode --dry-run aktif: Tidak ada file yang ditulis dan tidak ada baris yang dihapus dari database.");
            return Command::SUCCESS;
        }

        // 2. Siapkan direktori penyimpanan arsip
        $now = Carbon::now();
        $archiveDir = storage_path('app/audit_archives/' . $now->format('Y-m'));
        if (! File::exists($archiveDir)) {
            File::makeDirectory($archiveDir, 0755, true);
        }

        $this->info("Memulai proses kompresi arsip dan pembersihan berkala...");

        $archivedTotal = 0;
        $deletedTotal  = 0;
        $partIndex     = 1;

        // Ambil data dalam chunk bertahap agar hemat memori RAM
        while (true) {
            $records = $buildPrunableQuery()
                ->orderBy('id', 'asc')
                ->limit($chunkSize)
                ->get();

            if ($records->isEmpty()) {
                break;
            }

            $ids = $records->pluck('id')->all();
            $jsonData = json_encode($records, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            // Kompresi data menggunakan gzip
            $gzData = gzencode($jsonData, 9);
            if ($gzData === false) {
                $this->error("❌ Gagal mengompresi batch ID: " . min($ids) . " - " . max($ids));
                Log::error("ArchiveAndPruneActivityLogs: Gzencode failed on batch", ['min_id' => min($ids), 'max_id' => max($ids)]);
                break;
            }

            $archiveFilename = sprintf(
                'audit_archive_%s_part%03d_%d_to_%d.json.gz',
                $now->format('Ymd_His'),
                $partIndex,
                min($ids),
                max($ids)
            );
            $archivePath = $archiveDir . DIRECTORY_SEPARATOR . $archiveFilename;

            // Tulis file arsip ke disk
            $bytesWritten = file_put_contents($archivePath, $gzData);

            // Verifikasi integritas file sebelum menghapus dari database
            if ($bytesWritten === false || ! file_exists($archivePath) || filesize($archivePath) === 0) {
                $this->error("❌ Verifikasi file arsip gagal: {$archiveFilename}. Pembersihan dibatalkan untuk keamanan data.");
                Log::critical("ArchiveAndPruneActivityLogs: Archive verification failed, aborting deletion", ['path' => $archivePath]);
                break;
            }

            // Hapus baris yang SUDAH terarsip dengan aman menggunakan whereIn ID
            $deletedCount = DB::table('activity_logs')->whereIn('id', $ids)->delete();

            $archivedTotal += count($ids);
            $deletedTotal  += $deletedCount;

            $this->line("  [Part {$partIndex}] Tersimpan: {$archiveFilename} (" . round($bytesWritten / 1024, 1) . " KB) | {$deletedCount} baris dibersihkan.");
            $partIndex++;
        }

        $this->newLine();
        $this->info("═══════════════════════════════════════════════════════════════");
        $this->info("  PEMBERSIHAN SELESAI");
        $this->info("═══════════════════════════════════════════════════════════════");
        $this->info("  • Total log berhasil diarsipkan : " . number_format($archivedTotal));
        $this->info("  • Total log dihapus dari DB     : " . number_format($deletedTotal));
        $this->info("  • Lokasi File Arsip             : {$archiveDir}");

        // Catat aktivitas arsip ke log server
        Log::info("ArchiveAndPruneActivityLogs: Successfully archived {$archivedTotal} logs and pruned {$deletedTotal} rows from activity_logs.", [
            'archive_dir' => $archiveDir,
            'days_info'   => $daysInfo,
            'days_crit'   => $daysCritical,
        ]);

        return Command::SUCCESS;
    }
}
