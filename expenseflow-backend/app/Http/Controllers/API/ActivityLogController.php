<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ActivityLogController extends Controller
{
    // ═══════════════════════════════════════════════════════════
    // index() — GET audit log (activity_logs) milik company yang login
    //    GET /api/v1/dashboard/activity-logs?action=&entity_type=
    //    Join ke users agar nama pelaku ikut terbawa.
    // ═══════════════════════════════════════════════════════════
    public function index(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;

        $query = DB::table('activity_logs')
            ->leftJoin('users', 'activity_logs.user_id', '=', 'users.id')
            ->where('activity_logs.company_id', $companyId)
            ->select([
                'activity_logs.id',
                'activity_logs.action',
                'activity_logs.description',
                'activity_logs.entity_type',
                'activity_logs.entity_id',
                'activity_logs.user_id',
                'users.name as user_name',
                'users.role as user_role',
                'activity_logs.created_at',
            ])
            ->orderByDesc('activity_logs.created_at');

        // Filter opsional berdasarkan action atau entity_type
        if ($action = $request->query('action')) {
            $query->where('activity_logs.action', $action);
        }

        if ($entityType = $request->query('entity_type')) {
            $query->where('activity_logs.entity_type', $entityType);
        }

        $logs = $query->paginate(30);

        return response()->json($logs);
    }
}
