<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Bersihkan dan gabungkan divisi duplikat per company
        $companies = DB::table('divisions')->select('company_id')->distinct()->pluck('company_id');

        foreach ($companies as $companyId) {
            $duplicateCodes = DB::table('divisions')
                ->where('company_id', $companyId)
                ->whereNotNull('code')
                ->select('code', DB::raw('COUNT(*) as total'))
                ->groupBy('code')
                ->having('total', '>', 1)
                ->get();

            foreach ($duplicateCodes as $dup) {
                $records = DB::table('divisions')
                    ->where('company_id', $companyId)
                    ->where('code', $dup->code)
                    ->orderBy('id')
                    ->get();

                $candidates = [];
                foreach ($records as $rec) {
                    $posCount = DB::table('positions')->where('division_id', $rec->id)->count();
                    $userCount = DB::table('users')->where('division_id', $rec->id)->count();
                    $score = ($posCount * 100) + $userCount;
                    $candidates[] = [
                        'division'   => $rec,
                        'score'      => $score,
                        'pos_count'  => $posCount,
                        'user_count' => $userCount,
                    ];
                }

                // Urutkan kandidat: yang memiliki jabatan/staf terbanyak menjadi prioritas utama
                usort($candidates, function ($a, $b) {
                    if ($b['score'] !== $a['score']) {
                        return $b['score'] <=> $a['score'];
                    }
                    return $a['division']->id <=> $b['division']->id;
                });

                $primaryItem = $candidates[0]['division'];
                $bestDesc = $primaryItem->description;
                $bestName = $primaryItem->name;

                // Ambil deskripsi dan nama yang lebih lengkap dari baris duplikat
                foreach ($candidates as $idx => $cand) {
                    if ($idx === 0) {
                        continue;
                    }
                    $sec = $cand['division'];
                    if (empty($bestDesc) && ! empty($sec->description)) {
                        $bestDesc = $sec->description;
                    }
                    if (strlen((string) $sec->name) > strlen((string) $bestName)) {
                        $bestName = $sec->name;
                    }

                    // Pindahkan keterikatan posisi dan user ke divisi utama
                    DB::table('positions')->where('division_id', $sec->id)->update(['division_id' => $primaryItem->id]);
                    DB::table('users')->where('division_id', $sec->id)->update(['division_id' => $primaryItem->id]);

                    // Hapus duplikat kosong
                    DB::table('divisions')->where('id', $sec->id)->delete();
                }

                // Update primary division dengan nama formal dan deskripsi
                DB::table('divisions')->where('id', $primaryItem->id)->update([
                    'name'        => $bestName,
                    'description' => $bestDesc,
                    'updated_at'  => now(),
                ]);

                // Sinkronkan string department pada users
                DB::table('users')->where('division_id', $primaryItem->id)->update([
                    'department' => $bestName,
                ]);
            }
        }

        // 2. Tambahkan unique constraint pada (company_id, code) agar tidak bisa terjadi kode ganda
        Schema::table('divisions', function (Blueprint $table) {
            $table->unique(['company_id', 'code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('divisions', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'code']);
        });
    }
};
