<?php

use App\Http\Middleware\AttendanceAccessMiddleware;
use App\Http\Middleware\CompanyMiddleware;
use App\Http\Middleware\ReceiptAccessMiddleware;
use App\Http\Middleware\RoleMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');
        $middleware->alias([
            'role'              => RoleMiddleware::class,
            'company'           => CompanyMiddleware::class,
            'receipt_access'    => ReceiptAccessMiddleware::class,
            'attendance_access' => AttendanceAccessMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            $raw = (int) ($e->getHeaders()['Retry-After'] ?? 60);
            $seconds = max(1, min($raw > 0 ? $raw : 60, 60));
            $headers = $e->getHeaders();
            $headers['Retry-After'] = (string) $seconds;

            return response()->json([
                'message'             => 'Terlalu banyak permintaan. Silakan tunggu beberapa saat sebelum mencoba kembali.',
                'retry_after_seconds' => $seconds,
                'retry_after'         => $seconds,
                'rate_limit'          => true,
            ], 429, $headers);
        });
    })->create();
