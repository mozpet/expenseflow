<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  string  $module  Nama modul (mis. receipt, attendance, user, shift, dll.)
     * @param  string  $level   Level hak akses: 'read' atau 'manage' (default 'read')
     */
    public function handle(Request $request, Closure $next, string $module, string $level = 'read'): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        // super_admin bypass mutlak
        if ($user->role === 'super_admin') {
            return $next($request);
        }

        if (! $user->hasPermission($module, $level)) {
            $levelLabel = $level === 'manage' ? 'kelola (tambah/ubah/hapus)' : 'lihat (baca)';

            return response()->json([
                'message' => "Akses ditolak. Anda tidak memiliki izin {$levelLabel} untuk modul '{$module}'.",
            ], 403);
        }

        return $next($request);
    }
}
