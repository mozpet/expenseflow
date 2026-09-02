<?php

require_once __DIR__ . '/../expenseflow-backend/vendor/autoload.php';
$app = require_once __DIR__ . '/../expenseflow-backend/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use App\Models\Attendance;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Request;

echo "=== MEMULAI TEST OFFLINE ATTENDANCE & SYNC ===\n";

$user = User::where('role', 'employee')->first();
if (!$user) {
    echo "❌ User employee tidak ditemukan.\n";
    exit(1);
}

// Enable attendance and WFH for testing
$user->attendance_enabled = true;
$user->wfh_enabled = true;
$user->save();

$testDate = '2026-09-02';
// Bersihkan attendance hari ini untuk test
Attendance::where('user_id', $user->id)->whereDate('date', $testDate)->delete();

$controller = app(\App\Http\Controllers\API\AttendanceController::class);

// Test 1: Batch Sync Endpoint (Check-in & Check-out offline)
$offlineCheckInTime = '2026-09-02T07:45:00+07:00';
$offlineCheckOutTime = '2026-09-02T17:15:00+07:00';

$request = \Illuminate\Http\Request::create('/api/attendance/sync-offline', 'POST', [
    'items' => [
        [
            'id' => 'offline-test-checkin-1',
            'type' => 'check_in',
            'latitude' => -6.2088,
            'longitude' => 106.8456,
            'recorded_at' => $offlineCheckInTime,
            'is_mocked' => false,
        ],
        [
            'id' => 'offline-test-checkout-1',
            'type' => 'check_out',
            'latitude' => -6.2088,
            'longitude' => 106.8456,
            'recorded_at' => $offlineCheckOutTime,
            'is_mocked' => false,
        ],
    ]
]);
$request->headers->set('Accept', 'application/json');
$request->headers->set('X-Platform', 'mobile');
$request->setUserResolver(fn() => $user);

$response = $controller->syncOffline($request);
$data = $response->getData(true);

echo "Sync Response Message: " . ($data['message'] ?? 'N/A') . "\n";
echo "Synced count: " . ($data['synced'] ?? 0) . "\n";
echo "Failed count: " . ($data['failed'] ?? 0) . "\n";

$attendance = Attendance::where('user_id', $user->id)->whereDate('date', $testDate)->first();
if ($attendance && $attendance->is_offline_sync) {
    echo "✅ SUCCESS: Attendance recorded with is_offline_sync = true!\n";
    echo "Check-in time: " . $attendance->check_in_time . "\n";
    echo "Check-out time: " . $attendance->check_out_time . "\n";
    echo "Offline recorded at: " . $attendance->offline_recorded_at . "\n";
} else {
    echo "❌ FAILED: Attendance record mismatch.\n";
    exit(1);
}

echo "=== TEST SELESAI DENGAN SUKSES ===\n";
