<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$user = \App\Models\User::where('name', 'like', '%Ricardo%')->first();
$att = \App\Models\Attendance::where('user_id', $user->id)->whereDate('date', '2026-07-20')->first();
echo "App Timezone: " . config('app.timezone') . "\n";
echo "Checkout Time Raw: " . $att->check_out_time . "\n";
echo "Checkout Date Formatted: " . \Carbon\Carbon::parse($att->check_out_time)->format('Y-m-d') . "\n";
echo "DateStr (att->date): " . \Carbon\Carbon::parse($att->date)->format('Y-m-d') . "\n";
