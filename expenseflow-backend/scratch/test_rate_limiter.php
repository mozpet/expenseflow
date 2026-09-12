<?php

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

// Get a user to login
$user = User::first();
if (!$user) {
    echo "No user found.\n";
    exit(1);
}

// Clear the rate limiter just in case
RateLimiter::clear('api:'.$user->id);

$successCount = 0;
$tooManyCount = 0;
$firstRetryAfter = null;

for ($i = 0; $i < 125; $i++) {
    $request = Request::create('/api/v1/me', 'GET');
    $request->headers->set('Accept', 'application/json');
    
    // Simulate auth
    $app['auth']->guard('sanctum')->setUser($user);
    $request->setUserResolver(function () use ($user) {
        return $user;
    });

    $response = $kernel->handle($request);
    
    if ($response->getStatusCode() == 200) {
        $successCount++;
    } elseif ($response->getStatusCode() == 429) {
        $tooManyCount++;
        if ($firstRetryAfter === null) {
            $firstRetryAfter = $response->headers->get('Retry-After');
            echo "Body: " . $response->getContent() . "\n";
        }
    }
}

echo "Success: $successCount\n";
echo "Too Many: $tooManyCount\n";
echo "First Retry-After: $firstRetryAfter\n";
