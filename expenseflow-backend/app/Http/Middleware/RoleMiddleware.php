<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  string  ...$roles  Daftar role yang diizinkan (contoh: finance,admin)
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        // Cek apakah role user termasuk dalam daftar role yang diizinkan
        if (! in_array($user->role, $roles)) {
            $allowed = implode(', ', $roles);

            return response()->json([
                'message' => "Akses ditolak. Role yang diizinkan: {$allowed}.",
            ], 403);
        }

        return $next($request);
    }
}
