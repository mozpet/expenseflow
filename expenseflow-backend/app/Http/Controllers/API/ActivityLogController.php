<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ActivityLogController extends Controller
{
    // ═══════════════════════════════════════════════════════════
    // index() — GET audit log sistem milik company yang login
    //    GET /api/v1/dashboard/activity-logs
    // ═══════════════════════════════════════════════════════════
    public function index(Request $request): JsonResponse|StreamedResponse
    {
        $companyId = $request->user()->company_id;

        $query = DB::table('activity_logs')
            ->leftJoin('users', 'activity_logs.user_id', '=', 'users.id')
            ->where('activity_logs.company_id', $companyId)
            ->select([
                'activity_logs.id',
                'activity_logs.action',
                'activity_logs.category',
                'activity_logs.severity',
                'activity_logs.description',
                'activity_logs.entity_type',
                'activity_logs.entity_id',
                'activity_logs.old_values',
                'activity_logs.new_values',
                'activity_logs.ip_address',
                'activity_logs.user_agent',
                'activity_logs.user_id',
                'users.name as user_name',
                'users.role as user_role',
                'activity_logs.created_at',
            ])
            ->orderByDesc('activity_logs.created_at');

        // Filter: Search keyword
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('activity_logs.description', 'like', "%{$search}%")
                  ->orWhere('activity_logs.action', 'like', "%{$search}%")
                  ->orWhere('users.name', 'like', "%{$search}%")
                  ->orWhere('activity_logs.ip_address', 'like', "%{$search}%");
            });
        }

        // Filter: Severity (critical, warning, info)
        if ($severity = $request->query('severity')) {
            if ($severity !== 'all') {
                $query->where('activity_logs.severity', $severity);
            }
        }

        // Filter: Category
        if ($category = $request->query('category')) {
            if ($category !== 'all') {
                $query->where('activity_logs.category', $category);
            }
        }

        // Filter: Action
        if ($action = $request->query('action')) {
            $query->where('activity_logs.action', $action);
        }

        // Filter: Entity Type
        if ($entityType = $request->query('entity_type')) {
            $query->where('activity_logs.entity_type', $entityType);
        }

        // Filter: Date Range
        if ($startDate = $request->query('start_date')) {
            $query->whereDate('activity_logs.created_at', '>=', $startDate);
        }

        if ($endDate = $request->query('end_date')) {
            $query->whereDate('activity_logs.created_at', '<=', $endDate);
        }

        // Handle CSV Export
        if ($request->query('export') === 'csv') {
            return $this->exportCsv($query);
        }

        $perPage = min((int) ($request->query('per_page') ?? 30), 100);
        $logs = $query->paginate($perPage);

        // Decode JSON old_values & new_values
        $logs->getCollection()->transform(function ($item) {
            if (isset($item->old_values) && is_string($item->old_values)) {
                $item->old_values = json_decode($item->old_values, true);
            }
            if (isset($item->new_values) && is_string($item->new_values)) {
                $item->new_values = json_decode($item->new_values, true);
            }
            return $item;
        });

        return response()->json($logs);
    }

    /**
     * Export log terfilter ke format CSV.
     */
    private function exportCsv($query): StreamedResponse
    {
        $fileName = 'audit_logs_' . date('Ymd_His') . '.csv';

        $headers = [
            'Content-type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename={$fileName}",
            'Pragma'              => 'no-cache',
            'Cache-Control'       => 'must-revalidate, post-check=0, pre-check=0',
            'Expires'             => '0',
        ];

        return response()->stream(function () use ($query) {
            $handle = fopen('php://output', 'w');

            // BOM UTF-8 agar karakter Indonesia / simbol terbaca baik di Excel
            fputs($handle, "\xEF\xBB\xBF");

            // Header kolom
            fputcsv($handle, [
                'ID',
                'Waktu (WIB)',
                'Pelaku',
                'Role Pelaku',
                'Kategori',
                'Tingkat Urgensi',
                'Kode Aksi',
                'Deskripsi Perubahan',
                'Nilai Sebelum',
                'Nilai Sesudah',
                'IP Address',
                'User Agent',
            ]);

            $query->chunk(200, function ($rows) use ($handle) {
                foreach ($rows as $row) {
                    $oldFormatted = '';
                    if (!empty($row->old_values)) {
                        $oldArr = is_string($row->old_values) ? json_decode($row->old_values, true) : $row->old_values;
                        if (is_array($oldArr)) {
                            $oldFormatted = implode("; ", array_map(fn($k, $v) => "$k: " . (is_array($v) ? json_encode($v) : $v), array_keys($oldArr), $oldArr));
                        }
                    }

                    $newFormatted = '';
                    if (!empty($row->new_values)) {
                        $newArr = is_string($row->new_values) ? json_decode($row->new_values, true) : $row->new_values;
                        if (is_array($newArr)) {
                            $newFormatted = implode("; ", array_map(fn($k, $v) => "$k: " . (is_array($v) ? json_encode($v) : $v), array_keys($newArr), $newArr));
                        }
                    }

                    fputcsv($handle, [
                        $row->id,
                        $row->created_at,
                        $row->user_name ?? 'Sistem / Anonim',
                        $row->user_role ?? '-',
                        $row->category ?? 'GENERAL',
                        strtoupper($row->severity ?? 'info'),
                        $row->action,
                        $row->description,
                        $oldFormatted,
                        $newFormatted,
                        $row->ip_address ?? '-',
                        $row->user_agent ?? '-',
                    ]);
                }
            });

            fclose($handle);
        }, 200, $headers);
    }
}
