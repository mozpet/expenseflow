<?php

require __DIR__ . '/../expenseflow-backend/vendor/autoload.php';
$app = require_once __DIR__ . '/../expenseflow-backend/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

echo "=== MEMULAI TEST AUDIT LOGGER ===\n";

$admin = User::where('role', 'super_admin')->first() ?? User::first();
Auth::login($admin);

// 1. Test basic AuditLogger with sensitive data masking
$logId = AuditLogger::log(
    action: 'TEST_SENSITIVE_CHANGE',
    description: 'Mengubah kata sandi dan nomor rekening karyawan untuk pengujian',
    category: AuditLogger::CATEGORY_FINANCE,
    severity: AuditLogger::SEVERITY_CRITICAL,
    entityType: 'User',
    entityId: $admin->id,
    oldValues: [
        'bank_name' => 'BCA',
        'bank_account_no' => '1234567890',
        'password' => 'secret123',
    ],
    newValues: [
        'bank_name' => 'Mandiri',
        'bank_account_no' => '9876543210',
        'password' => 'newsecret456',
    ]
);

$savedLog = DB::table('activity_logs')->where('id', $logId)->first();
echo "Log ID: " . $savedLog->id . "\n";
echo "Category: " . $savedLog->category . "\n";
echo "Severity: " . $savedLog->severity . "\n";
echo "Old Values: " . $savedLog->old_values . "\n";
echo "New Values: " . $savedLog->new_values . "\n";

// Verify password is masked as ********
$oldDecoded = json_decode($savedLog->old_values, true);
if ($oldDecoded['password'] === '********' && $oldDecoded['bank_account_no'] === '1234567890') {
    echo "✅ SUCCESS: Password masked and bank account logged properly!\n";
} else {
    echo "❌ FAILED: Masking issue!\n";
}

echo "=== TEST SELESAI DENGAN SUKSES ===\n";
