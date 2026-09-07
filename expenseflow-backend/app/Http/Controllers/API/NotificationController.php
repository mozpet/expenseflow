<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NotificationController extends Controller
{
    // ═══════════════════════════════════════════════════════════
    // index() — GET notifikasi milik user yang login (terbaru dulu)
    //    GET /api/v1/dashboard/notifications?only_unread=1
    // ═══════════════════════════════════════════════════════════
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        // Self-healing: bersihkan notifikasi permohonan approval yang sudah disetujui / ditolak / tidak valid
        // 1. Pindah perangkat yang sudah tidak pending (atau sudah dihapus)
        DB::table('notifications')
            ->where('user_id', $userId)
            ->where('entity_type', 'device_change_request')
            ->where('type', 'device_change_pending')
            ->whereNotIn('entity_id', function ($q) {
                $q->select('id')->from('device_change_requests')->where('status', 'pending');
            })
            ->delete();

        // 2. Lembur yang sudah tidak pending (atau sudah dihapus)
        DB::table('notifications')
            ->where('user_id', $userId)
            ->where('entity_type', 'overtime_approval')
            ->where('type', 'overtime_pending')
            ->whereNotIn('entity_id', function ($q) {
                $q->select('id')->from('overtime_approvals')->where('status', 'pending');
            })
            ->delete();

        // 3. Cuti / izin yang sudah tidak pending (atau sudah dihapus)
        DB::table('notifications')
            ->where('user_id', $userId)
            ->where('entity_type', 'leave_request')
            ->where('type', 'leave_requested')
            ->whereNotIn('entity_id', function ($q) {
                $q->select('id')->from('leave_requests')->where('status', 'pending');
            })
            ->delete();

        // 4. Invoice yang sudah tidak pending (atau sudah dihapus) atau invoice yang sudah disetujui oleh user ini
        DB::table('notifications')
            ->where('user_id', $userId)
            ->where('entity_type', 'invoice')
            ->where('type', 'invoice_awaiting_approval')
            ->where(function ($q) use ($userId) {
                $q->whereNotIn('entity_id', function ($sub) {
                    $sub->select('id')->from('invoices')->whereIn('status', ['pending', 'Pending']);
                })
                ->orWhereExists(function ($sub) use ($userId) {
                    $sub->select(DB::raw(1))
                        ->from('invoice_approvals')
                        ->whereColumn('invoice_approvals.invoice_id', 'notifications.entity_id')
                        ->where('invoice_approvals.user_id', $userId);
                });
            })
            ->delete();

        // 5. Struk yang sudah tidak berstatus submitted/pending (atau sudah dihapus)
        DB::table('notifications')
            ->where('user_id', $userId)
            ->where('entity_type', 'receipt')
            ->whereIn('type', ['receipt_submitted', 'receipt_pending'])
            ->whereNotIn('entity_id', function ($q) {
                $q->select('id')->from('receipts')->whereIn('status', ['submitted', 'pending']);
            })
            ->delete();

        $query = DB::table('notifications')
            ->where('user_id', $userId)
            ->orderByDesc('created_at');

        // Filter hanya yang belum dibaca jika diminta
        if ($request->boolean('only_unread')) {
            $query->whereNull('read_at');
        }

        $notifications = $query->paginate(30);

        // Decode kolom JSON 'data' agar siap dipakai frontend
        $notifications->getCollection()->transform(function ($n) {
            $n->data = json_decode($n->data, true);
            return $n;
        });

        // Jumlah notifikasi belum dibaca (untuk badge)
        $unreadCount = DB::table('notifications')
            ->where('user_id', $userId)
            ->whereNull('read_at')
            ->count();

        return response()->json([
            'unread_count'  => $unreadCount,
            'notifications' => $notifications,
        ]);
    }

    // ═══════════════════════════════════════════════════════════
    // markAllRead() — POST tandai semua notifikasi user sebagai dibaca
    //    POST /api/v1/dashboard/notifications/read-all
    // ═══════════════════════════════════════════════════════════
    public function markAllRead(Request $request): JsonResponse
    {
        DB::table('notifications')
            ->where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->update([
                'read_at'    => now(),
                'updated_at' => now(),
            ]);

        return response()->json(['message' => 'Semua notifikasi ditandai sudah dibaca.']);
    }

    // ═══════════════════════════════════════════════════════════
    // markRead() — POST tandai satu notifikasi sebagai dibaca
    //    POST /api/v1/dashboard/notifications/{id}/read
    // ═══════════════════════════════════════════════════════════
    public function markRead(Request $request, string $id): JsonResponse
    {
        $affected = DB::table('notifications')
            ->where('id', $id)
            ->where('user_id', $request->user()->id)
            ->update([
                'read_at'    => now(),
                'updated_at' => now(),
            ]);

        if ($affected === 0) {
            return response()->json(['message' => 'Notifikasi tidak ditemukan.'], 404);
        }

        return response()->json(['message' => 'Notifikasi ditandai sudah dibaca.']);
    }

    // ═══════════════════════════════════════════════════════════
    // destroy() — DELETE hapus satu notifikasi milik user
    //    DELETE /api/v1/dashboard/notifications/{id}
    // ═══════════════════════════════════════════════════════════
    public function destroy(Request $request, string $id): JsonResponse
    {
        $deleted = DB::table('notifications')
            ->where('id', $id)
            ->where('user_id', $request->user()->id)
            ->delete();

        if ($deleted === 0) {
            return response()->json(['message' => 'Notifikasi tidak ditemukan.'], 404);
        }

        return response()->json(['message' => 'Notifikasi berhasil dihapus.']);
    }
}
