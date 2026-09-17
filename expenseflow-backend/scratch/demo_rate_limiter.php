<?php

// Autoload & bootstrap Laravel jika dijalankan langsung via `php scratch/demo_rate_limiter.php`
if (!defined('LARAVEL_START')) {
    define('LARAVEL_START', microtime(true));
    require __DIR__.'/../vendor/autoload.php';
    $app = require_once __DIR__.'/../bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
}

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Http;

$user = User::where('email', 'hendra@majubersama.co.id')->first() ?? User::first();
echo "Testing Rate Limiter Tier 1 (120 req/menit) untuk User: {$user->name} ({$user->email}, ID: {$user->id})\n";

// Buat token sementara
$token = $user->createToken('rate_limiter_demo')->plainTextToken;

// Reset rate limiter dulu agar mulai dari 0
RateLimiter::clear('api:'.$user->id);

$url = 'http://127.0.0.1:8000/api/v1/me';
$totalRequests = 125;
$startTime = microtime(true);

echo "Memulai $totalRequests request secara cepat ke $url...\n";
echo str_repeat("=", 75) . "\n";

$first429 = null;

for ($i = 1; $i <= $totalRequests; $i++) {
    $reqStart = microtime(true);
    $response = Http::withToken($token)
        ->withHeaders([
            'Accept' => 'application/json',
            'X-Platform' => 'web',
        ])
        ->get($url);
    $reqDuration = round((microtime(true) - $reqStart) * 1000, 1);

    $status = $response->status();
    $limit = $response->header('X-RateLimit-Limit');
    $remaining = $response->header('X-RateLimit-Remaining');
    $retryAfter = $response->header('Retry-After');

    // Tampilkan milestone penting
    if ($i === 1 || $i === 30 || $i === 60 || $i === 90 || $i === 118 || $i === 119 || $i === 120 || $i === 121 || $i === 122 || $i === 125) {
        $statusText = $status === 200 ? "200 OK" : "429 TOO MANY REQUESTS";
        $extra = $status === 429 ? " | Retry-After: {$retryAfter}s" : " | Remaining: {$remaining}/{$limit}";
        echo sprintf("[%03d/%03d] Status: %-23s%s (%sms)\n", $i, $totalRequests, $statusText, $extra, $reqDuration);
    }

    if ($status === 429 && $first429 === null) {
        $first429 = [
            'request_number' => $i,
            'status' => $status,
            'headers' => [
                'Retry-After' => $retryAfter,
                'X-RateLimit-Limit' => $limit,
                'X-RateLimit-Remaining' => $remaining,
            ],
            'body' => $response->json(),
        ];
    }
}

$totalDuration = round(microtime(true) - $startTime, 2);
echo str_repeat("=", 75) . "\n";
echo "Total Waktu: {$totalDuration} detik untuk {$totalRequests} request.\n\n";

if ($first429) {
    echo "Detail Response Saat Pertama Kali Terkena Rate Limit (Request #{$first429['request_number']}):\n";
    echo "HTTP Status : {$first429['status']} Too Many Requests\n";
    echo "Headers     :\n";
    foreach ($first429['headers'] as $k => $v) {
        echo "  - $k: $v\n";
    }
    echo "JSON Body   :\n";
    echo json_encode($first429['body'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
}

// Hapus token sementara
$user->tokens()->where('name', 'rate_limiter_demo')->delete();

// Kembalikan rate limiter ke keadaan bersih agar user di browser tidak terhalang
RateLimiter::clear('api:'.$user->id);
echo "\nCache RateLimiter untuk User ID {$user->id} telah di-clear kembali (Siap pakai).\n";
