<?php

require __DIR__ . '/../expenseflow-backend/vendor/autoload.php';
$app = require_once __DIR__ . '/../expenseflow-backend/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use App\Models\Holiday;
use App\Services\IndonesianHolidayService;
use Illuminate\Support\Facades\Request;

echo "=======================================================\n";
echo "TEST SUITE: KALENDER LIBUR NASIONAL OTOMATIS (FITUR #8)\n";
echo "=======================================================\n\n";

$pass = 0;
$fail = 0;

function assertTest(bool $condition, string $title, ?string $details = null) {
    global $pass, $fail;
    if ($condition) {
        echo " [PASS] $title\n";
        $pass++;
    } else {
        echo " [FAIL] $title" . ($details ? " -> $details" : "") . "\n";
        $fail++;
    }
}

// 1. Test IndonesianHolidayService Live / Fallback
echo "--- 1. Testing IndonesianHolidayService ---\n";
$service = new IndonesianHolidayService();
$holidays2026 = $service->getHolidays(2026);
assertTest(is_array($holidays2026) && count($holidays2026) >= 15, "getHolidays(2026) mengembalikan array hari libur (Total: " . count($holidays2026) . ")");
assertTest(!empty($holidays2026[0]['date']) && !empty($holidays2026[0]['name']), "Struktur data holiday memiliki field date dan name");

$holidays2025 = $service->getHolidays(2025);
assertTest(is_array($holidays2025) && count($holidays2025) >= 15, "getHolidays(2025) mengembalikan array hari libur (Total: " . count($holidays2025) . ")");

// 2. Test Preview Controller Endpoint via Request
echo "\n--- 2. Testing Preview National Holidays Endpoint ---\n";
$hrd = User::where('role', 'hrd')->first() ?? User::where('role', 'admin')->first() ?? User::first();
if ($hrd) {
    $request = Request::create('/api/v1/dashboard/attendance/holidays/preview-national?year=2026', 'GET');
    $request->setUserResolver(fn() => $hrd);

    $controller = app(\App\Http\Controllers\API\AttendanceController::class);
    $response = $controller->previewNationalHolidays($request);
    $data = json_decode($response->getContent(), true);

    assertTest($response->status() === 200, "previewNationalHolidays HTTP 200 OK");
    assertTest(isset($data['holidays']) && is_array($data['holidays']), "Response memuat array 'holidays'");
    assertTest(isset($data['total_national']) && isset($data['total_collective']), "Response memuat total_national (" . ($data['total_national'] ?? 0) . ") dan total_collective (" . ($data['total_collective'] ?? 0) . ")");
    assertTest(isset($data['total_already_exists']), "Response memuat total_already_exists (" . ($data['total_already_exists'] ?? 0) . ")");
} else {
    echo " [SKIP] Tidak ada user HRD/Admin di database untuk tes HTTP controller\n";
}

// 3. Test Sync National Holidays Endpoint
echo "\n--- 3. Testing Sync National Holidays Endpoint ---\n";
if ($hrd) {
    $syncPayload = [
        'year'                 => 2027,
        'collective_treatment' => 'nasional',
        'holidays'             => [
            [
                'date'          => '2027-01-01',
                'name'          => 'Tahun Baru 2027 Masehi',
                'is_collective' => false,
            ],
            [
                'date'          => '2027-02-06',
                'name'          => 'Tahun Baru Imlek 2578 Kongzili',
                'is_collective' => false,
            ],
            [
                'date'          => '2027-08-17',
                'name'          => 'Hari Kemerdekaan Republik Indonesia ke-82',
                'is_collective' => false,
            ]
        ]
    ];

    $postRequest = Request::create('/api/v1/dashboard/attendance/holidays/sync-national', 'POST', $syncPayload);
    $postRequest->setUserResolver(fn() => $hrd);

    $syncResponse = $controller->syncNationalHolidays($postRequest);
    $syncData = json_decode($syncResponse->getContent(), true);

    assertTest($syncResponse->status() === 200, "syncNationalHolidays HTTP 200 OK");
    assertTest(($syncData['synced_count'] ?? 0) >= 1 || ($syncData['skipped_count'] ?? 0) >= 1, "Sync berhasil memproses/skip item");

    // Verifikasi keberadaan di database
    $checkHoliday = Holiday::whereDate('date', '2027-08-17')->first();
    assertTest($checkHoliday !== null && $checkHoliday->is_national == true, "Holiday 2027-08-17 tersimpan di database sebagai libur nasional");
}

echo "\n=======================================================\n";
echo "HASIL AKHIR: $pass LULUS, $fail GAGAL\n";
echo "=======================================================\n";
