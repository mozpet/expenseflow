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
