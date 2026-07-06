<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AttendanceAccessMiddleware
{
    /**
     * Cek apakah user memiliki akses fitur presensi (attendance).
     * Semua role boleh akses, tapi harus memiliki attendance_enabled = true.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if (! $user->canAccessAttendance()) {
            return response()->json([
                'message' => 'Fitur presensi belum diaktifkan oleh HRD.',
            ], 403);
        }

        return $next($request);
    }
}
