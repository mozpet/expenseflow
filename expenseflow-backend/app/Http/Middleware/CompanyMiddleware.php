<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CompanyMiddleware
{
    /**
     * Memastikan semua data yang diakses user hanya milik company_id
     * yang sama dengan user yang sedang login.
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

        // super_admin bisa mengakses semua perusahaan
        if ($user->role === 'super_admin') {
            return $next($request);
        }

        // User harus terdaftar di sebuah perusahaan
        if (! $user->company_id) {
            return response()->json([
                'message' => 'User tidak terdaftar di perusahaan manapun.',
            ], 403);
        }

        // Validasi route model bindings — pastikan company_id cocok
        $route = $request->route();
        if ($route) {
            foreach ($route->parameters() as $value) {
                if (is_object($value) && method_exists($value, 'getAttribute')) {
                    $modelCompanyId = $value->getAttribute('company_id');

                    // Hanya cek jika model punya kolom company_id
                    if ($modelCompanyId !== null
                        && (int) $modelCompanyId !== (int) $user->company_id) {
                        return response()->json([
                            'message' => 'Data tidak termasuk dalam perusahaan Anda.',
                        ], 403);
                    }
                }
            }
        }

        return $next($request);
    }
}
