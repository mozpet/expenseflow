<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SettingsController extends Controller
{
    // Nilai default jika perusahaan belum pernah menyimpan pengaturan.
    private const DEFAULTS = [
        'variance_limit'   => '10',
        'max_claim_limit'  => '2000000',
        'threshold_single' => '< Rp 10.000.000',
        'threshold_two'    => 'Rp 10 jt — Rp 50 jt',
        'threshold_three'  => '> Rp 50.000.000',
    ];

    // ─── Helper: catat aktivitas ──────────────────────────────
    private function logActivity(int $userId, int $companyId, string $action, string $description): void
    {
        DB::table('activity_logs')->insert([
            'company_id'  => $companyId,
            'user_id'     => $userId,
            'action'      => $action,
            'description' => $description,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    // ─── Helper: ambil semua setting company sebagai array key-value ──
    private function fetchSettings(int $companyId): array
    {
        $stored = DB::table('company_settings')
            ->where('company_id', $companyId)
            ->pluck('value', 'key')
            ->toArray();

        return array_merge(self::DEFAULTS, $stored);
    }

    // ═══════════════════════════════════════════════════════════
    // index() — GET pengaturan threshold & batas klaim perusahaan
    //    GET /api/v1/dashboard/settings
    // ═══════════════════════════════════════════════════════════
    public function index(Request $request): JsonResponse
    {
        $settings = $this->fetchSettings($request->user()->company_id);

        return response()->json([
            'settings' => [
                'variance_limit'   => (int) $settings['variance_limit'],
                'max_claim_limit'  => (int) $settings['max_claim_limit'],
                'threshold_single' => $settings['threshold_single'],
                'threshold_two'    => $settings['threshold_two'],
                'threshold_three'  => $settings['threshold_three'],
            ],
        ]);
    }

    // ═══════════════════════════════════════════════════════════
    // update() — PUT simpan pengaturan (upsert per key)
    //    PUT /api/v1/dashboard/settings
    // ═══════════════════════════════════════════════════════════
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'variance_limit'   => 'required|numeric|min:0|max:100',
            'max_claim_limit'  => 'required|numeric|min:0',
            'threshold_single' => 'required|string|max:255',
            'threshold_two'    => 'required|string|max:255',
            'threshold_three'  => 'required|string|max:255',
        ]);

        $companyId = $request->user()->company_id;

        // Upsert tiap key ke company_settings
        foreach ($validated as $key => $value) {
            DB::table('company_settings')->updateOrInsert(
                ['company_id' => $companyId, 'key' => $key],
                ['value' => (string) $value, 'updated_at' => now(), 'created_at' => now()]
            );
        }

        $this->logActivity(
            $request->user()->id,
            $companyId,
            'settings_updated',
            'Update pengaturan threshold & batas klaim'
        );

        return $this->index($request);
    }
}
