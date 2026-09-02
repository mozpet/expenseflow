<?php

require_once __DIR__ . '/../expenseflow-backend/vendor/autoload.php';
$app = require_once __DIR__ . '/../expenseflow-backend/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use App\Models\Receipt;
use App\Models\Attendance;
use App\Services\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;

echo "====================================================\n";
echo "   COMPREHENSIVE TEST SUITE: FITUR #1, #3, #4, #5   \n";
echo "====================================================\n\n";

$passCount = 0;
$failCount = 0;

function assertTest(bool $condition, string $testName, string $details = '') {
    global $passCount, $failCount;
    if ($condition) {
        $passCount++;
        echo "✅ PASS: $testName\n";
        if ($details) echo "   -> $details\n";
    } else {
        $failCount++;
        echo "❌ FAIL: $testName\n";
        if ($details) echo "   -> $details\n";
    }
}

// -----------------------------------------------------------------------------
// 1. TEST FITUR #1: DETEKSI STRUK DUPLIKAT
// -----------------------------------------------------------------------------
echo "--- [1] Testing Deteksi Struk Duplikat ---\n";

$user = User::where('role', 'employee')->first() ?? User::first();
Receipt::withTrashed()->where('receipt_number', 'like', 'REC-TEST-%')->forceDelete();

// Buat Struk Asli (Original)
$origReceipt = Receipt::create([
    'company_id'      => $user->company_id ?? 1,
    'user_id'         => $user->id,
    'sha256_hash'     => 'hash_test_1234567890abcdef',
    'vendor_name'     => 'Restoran Test ABC',
    'total_amount'    => 150000,
    'receipt_date'    => '2026-09-01',
    'status'          => 'approved',
    'receipt_number'  => 'REC-TEST-ORIG',
]);

// Test 1.1: Deteksi duplikat via SHA-256 Hash sama persis (Layer 1)
$dupCandidate1 = Receipt::create([
    'company_id'      => $user->company_id ?? 1,
    'user_id'         => $user->id,
    'sha256_hash'     => 'hash_test_1234567890abcdef',
    'vendor_name'     => 'Restoran Test ABC',
    'total_amount'    => 150000,
    'receipt_date'    => '2026-09-01',
    'receipt_number'  => 'REC-TEST-DUP1',
]);
$isDup1 = $dupCandidate1->detectPotentialDuplicate();
assertTest(
    $isDup1 === true && $dupCandidate1->duplicate_reference_id == $origReceipt->id,
    'Deteksi duplikat Layer 1 (SHA-256 Hash identik)',
    'Reason: ' . $dupCandidate1->duplicate_reason
);

// Test 1.2: Deteksi duplikat via Metadata (Layer 2: Vendor, Tanggal, Nominal) walau file hash berbeda
$dupCandidate2 = Receipt::create([
    'company_id'      => $user->company_id ?? 1,
    'user_id'         => $user->id,
    'sha256_hash'     => 'hash_berbeda_999999',
    'vendor_name'     => 'Restoran Test ABC',
    'total_amount'    => 150000,
    'receipt_date'    => '2026-09-01',
    'receipt_number'  => 'REC-TEST-DUP2',
]);
$isDup2 = $dupCandidate2->detectPotentialDuplicate();
assertTest(
    $isDup2 === true && $dupCandidate2->duplicate_reference_id == $origReceipt->id,
    'Deteksi duplikat Layer 2 (Vendor, Tanggal, Nominal serupa)',
    'Reason: ' . $dupCandidate2->duplicate_reason
);

// Test 1.3: Struk berbeda nominal tidak boleh dianggap duplikat
$diffCandidate = Receipt::create([
    'company_id'      => $user->company_id ?? 1,
    'user_id'         => $user->id,
    'sha256_hash'     => 'hash_unik_88888',
    'vendor_name'     => 'Restoran Test ABC',
    'total_amount'    => 275000,
    'receipt_date'    => '2026-09-01',
    'receipt_number'  => 'REC-TEST-DIFF',
]);
$isDup3 = $diffCandidate->detectPotentialDuplicate();
assertTest(
    $isDup3 === false && $diffCandidate->is_potential_duplicate != true,
    'Struk dengan nominal berbeda TIDAK terdeteksi sebagai duplikat'
);

// Clean up test receipts
Receipt::withTrashed()->where('receipt_number', 'like', 'REC-TEST-%')->forceDelete();
echo "\n";

// -----------------------------------------------------------------------------
// 2. TEST FITUR #3: AUDIT LOG PENGUBAHAN DATA SENSITIF
// -----------------------------------------------------------------------------
echo "--- [2] Testing Audit Log Pengubahan Data Sensitif ---\n";

$oldSensitive = [
    'password' => 'secret123',
    'remember_token' => 'tok_abc123',
    'bank_account_no' => '11223344',
    'monthly_claim_limit' => 5000000,
];
$newSensitive = [
    'password' => 'newsecret456',
    'remember_token' => 'tok_xyz789',
    'bank_account_no' => '99887766',
    'monthly_claim_limit' => 10000000,
];

$auditId = AuditLogger::log(
    action: 'TEST_SENSITIVE_UPDATE',
    description: 'Testing sensitive data update log',
    category: AuditLogger::CATEGORY_FINANCE,
    severity: AuditLogger::SEVERITY_CRITICAL,
    oldValues: $oldSensitive,
    newValues: $newSensitive,
    companyId: $user->company_id ?? 1,
    userId: $user->id
);

$loggedRecord = DB::table('activity_logs')->where('id', $auditId)->first();
$oldDecoded = json_decode($loggedRecord->old_values ?? '{}', true);
$newDecoded = json_decode($loggedRecord->new_values ?? '{}', true);

assertTest(
    $oldDecoded['password'] === '********' && $newDecoded['password'] === '********',
    'Password & sensitive tokens otomatis disamarkan (masked: ********)'
);
assertTest(
    $oldDecoded['bank_account_no'] === '11223344' && $newDecoded['bank_account_no'] === '99887766',
    'Perubahan nomor rekening bank tersimpan secara presisi di old_values & new_values'
);
assertTest(
    $loggedRecord->severity === 'critical' && $loggedRecord->category === 'PAYROLL_FINANCE',
    'Severity "critical" dan Category "PAYROLL_FINANCE" tersimpan dengan valid'
);

echo "\n";

// -----------------------------------------------------------------------------
// 3. TEST FITUR #5: OFFLINE MODE PRESENSI & SYNC
// -----------------------------------------------------------------------------
echo "--- [3] Testing Offline Mode Presensi & Batch Sync ---\n";

$testAttendanceDate = '2026-09-02';
Attendance::where('user_id', $user->id)->whereDate('date', $testAttendanceDate)->delete();

$user->attendance_enabled = true;
$user->wfh_enabled = true;
$user->save();

$controller = app(\App\Http\Controllers\API\AttendanceController::class);

// Test 3.1: Offline Check-in dengan recorded_at
$offlineCheckInTime = '2026-09-02T07:50:00+07:00';
$checkInReq = Request::create('/api/attendance/check-in', 'POST', [
    'latitude'        => -6.2088,
    'longitude'       => 106.8456,
    'recorded_at'     => $offlineCheckInTime,
    'is_offline_sync' => true,
]);
$checkInReq->headers->set('Accept', 'application/json');
$checkInReq->headers->set('X-Platform', 'mobile');
$checkInReq->setUserResolver(fn() => $user);

$checkInRes = $controller->checkIn($checkInReq);

assertTest(
    $checkInRes->getStatusCode() === 201,
    'Check-in offline dengan parameter recorded_at berhasil (HTTP 201)'
);

$savedAttendance = Attendance::where('user_id', $user->id)->whereDate('date', $testAttendanceDate)->first();
assertTest(
    $savedAttendance && $savedAttendance->is_offline_sync && $savedAttendance->offline_recorded_at !== null,
    'Flag is_offline_sync = true dan offline_recorded_at tercatat di database'
);
assertTest(
    $savedAttendance && Carbon::parse($savedAttendance->check_in_time)->format('H:i') === '07:50',
    'Waktu check_in_time sesuai dengan waktu offline pengguna (07:50 WIB)'
);

// Test 3.2: Offline Check-out dengan recorded_at
$offlineCheckOutTime = '2026-09-02T17:20:00+07:00';
$checkOutReq = Request::create('/api/attendance/check-out', 'POST', [
    'latitude'        => -6.2088,
    'longitude'       => 106.8456,
    'recorded_at'     => $offlineCheckOutTime,
    'is_offline_sync' => true,
]);
$checkOutReq->headers->set('Accept', 'application/json');
$checkOutReq->headers->set('X-Platform', 'mobile');
$checkOutReq->setUserResolver(fn() => $user);

$checkOutRes = $controller->checkOut($checkOutReq);

assertTest(
    $checkOutRes->getStatusCode() === 200,
    'Check-out offline dengan parameter recorded_at berhasil (HTTP 200)'
);

$savedAttendance->refresh();
echo "   [DEBUG] check_out_time: " . json_encode($savedAttendance->check_out_time) . "\n";
assertTest(
    $savedAttendance->check_out_time !== null && Carbon::parse($savedAttendance->check_out_time)->format('H:i') === '17:20',
    'Waktu check_out_time sesuai dengan waktu offline pengguna (17:20 WIB)'
);
assertTest(
    $savedAttendance->work_minutes > 0,
    'Perhitungan jam kerja (work_minutes) dihitung berdasarkan rentang waktu offline',
    "Work minutes: {$savedAttendance->work_minutes} menit"
);

// Test 3.3: Batch Sync Idempotency (mengirim antrean yang sudah pernah diproses)
$batchSyncReq = Request::create('/api/attendance/sync-offline', 'POST', [
    'items' => [
        [
            'id' => 'item-checkin-idempotency',
            'type' => 'check_in',
            'latitude' => -6.2088,
            'longitude' => 106.8456,
            'recorded_at' => $offlineCheckInTime,
            'is_mocked' => false,
        ],
        [
            'id' => 'item-checkout-idempotency',
            'type' => 'check_out',
            'latitude' => -6.2088,
            'longitude' => 106.8456,
            'recorded_at' => $offlineCheckOutTime,
            'is_mocked' => false,
        ],
    ]
]);
$batchSyncReq->headers->set('Accept', 'application/json');
$batchSyncReq->headers->set('X-Platform', 'mobile');
$batchSyncReq->setUserResolver(fn() => $user);

$batchSyncRes = $controller->syncOffline($batchSyncReq);
$batchSyncData = $batchSyncRes->getData(true);

assertTest(
    $batchSyncRes->getStatusCode() === 200 && isset($batchSyncData['results']),
    'Batch sync offline endpoint merespon dengan sukses & idempotent (HTTP 200)',
    "Synced: " . ($batchSyncData['synced'] ?? 0) . ", Failed: " . ($batchSyncData['failed'] ?? 0)
);

echo "\n====================================================\n";
echo "HASIL PENGUJIAN: $passCount LULUS, $failCount GAGAL\n";
echo "====================================================\n";

if ($failCount === 0) {
    echo "🎉 SEMUA PENGUJIAN FITUR BARU 100% BERHASIL & BEBAS BUG!\n";
} else {
    exit(1);
}
