<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\EmployeeBankAccount;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\FcmService;
use App\Services\PayrollAuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Proteksi perubahan rekening bank karyawan (Fase 3, spec §3.A.3 & §3.B).
 *
 * Setiap perubahan rekening = pengajuan baru berstatus pending_verification, WAJIB
 * diverifikasi orang berbeda (maker-checker). Nomor rekening disimpan terenkripsi &
 * hanya ditampilkan termasking. Saat diverifikasi: rekening aktif lama → superseded,
 * dan kolom users.bank_* disinkronkan agar snapshot payslip (PayrollCalculator) tetap
 * berjalan tanpa perubahan. Karyawan menerima notifikasi keamanan pada setiap perubahan.
 */
class EmployeeBankAccountController extends Controller
{
    private function deny(): JsonResponse
    {
        return response()->json(['message' => 'Akses ditolak. Anda tidak memiliki wewenang mengelola rekening bank karyawan.'], 403);
    }

    /** Karyawan target harus satu perusahaan dengan pengguna dashboard. */
    private function findEmployee(int $userId, int $companyId): ?User
    {
        return User::where('id', $userId)->where('company_id', $companyId)->first();
    }

    /** Notifikasi keamanan ke karyawan (in-app + FCM bila ada token). Aman tanpa kredensial FCM. */
    private function notifyBankChange(int $userId, string $type, array $data): void
    {
        DB::table('notifications')->insert([
            'id'              => Str::uuid()->toString(),
            'type'            => $type,
            'notifiable_type' => 'App\\Models\\User',
            'notifiable_id'   => $userId,
            'user_id'         => $userId,
            'data'            => json_encode($data),
            'entity_type'     => 'EmployeeBankAccount',
            'entity_id'       => $data['bank_account_id'] ?? null,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        try {
            $user = User::find($userId);
            if ($user && $user->fcm_token) {
                app(FcmService::class)->send(
                    $user->fcm_token,
                    $data['title'] ?? 'Perubahan Rekening Bank',
                    $data['message'] ?? 'Ada perubahan pada rekening bank Anda.',
                    ['type' => $type, 'entity_type' => 'EmployeeBankAccount', 'entity_id' => (string) ($data['bank_account_id'] ?? '')],
                );
            }
        } catch (\Throwable $e) {
            Log::warning("Gagal kirim FCM notif rekening ke user #{$userId}: {$e->getMessage()}");
        }
    }

    /** GET /dashboard/payroll/employees/{userId}/bank-accounts */
    public function index(Request $request, int $userId): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPermission(Role::MODULE_PAYROLL, 'read')) {
            return $this->deny();
        }
        if (! $this->findEmployee($userId, (int) $user->company_id)) {
            return response()->json(['message' => 'Karyawan tidak ditemukan.'], 404);
        }

        $accounts = EmployeeBankAccount::with(['requestedBy:id,name', 'verifiedBy:id,name'])
            ->where('company_id', $user->company_id)
            ->where('user_id', $userId)
            ->orderByDesc('id')
            ->get(); // nomor otomatis termasking (hidden + append di model)

        return response()->json(['data' => $accounts]);
    }

    /** POST /dashboard/payroll/employees/{userId}/bank-account — ajukan rekening baru. */
    public function store(Request $request, int $userId): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPermission(Role::MODULE_PAYROLL, 'manage')) {
            return $this->deny();
        }
        $companyId = (int) $user->company_id;
        $employee = $this->findEmployee($userId, $companyId);
        if (! $employee) {
            return response()->json(['message' => 'Karyawan tidak ditemukan.'], 404);
        }

        $validated = $request->validate([
            'bank_name'           => 'required|string|max:100',
            'bank_account_no'     => 'required|string|max:50',
            'bank_account_holder' => 'required|string|max:150',
            'bank_branch'         => 'nullable|string|max:100',
            'swift_code'          => 'nullable|string|max:20',
            'notes'               => 'nullable|string|max:1000',
        ]);

        $account = EmployeeBankAccount::create([
            'company_id'          => $companyId,
            'user_id'             => $userId,
            'bank_name'           => $validated['bank_name'],
            'bank_account_no'     => $validated['bank_account_no'], // terenkripsi via cast
            'bank_account_holder' => $validated['bank_account_holder'],
            'bank_branch'         => $validated['bank_branch'] ?? null,
            'swift_code'          => $validated['swift_code'] ?? null,
            'status'              => EmployeeBankAccount::STATUS_PENDING,
            'is_primary'          => false,
            'requested_by'        => $user->id,
            'notes'               => $validated['notes'] ?? null,
        ]);

        // Audit keamanan (CRITICAL) — perubahan rekening adalah vektor fraud utama.
        AuditLogger::log(
            action: 'BANK_ACCOUNT_CHANGE_REQUESTED',
            description: "Pengajuan perubahan rekening bank untuk user #{$userId} (bank {$account->bank_name}, {$account->maskedAccountNo()})",
            category: AuditLogger::CATEGORY_SECURITY,
            severity: AuditLogger::SEVERITY_CRITICAL,
            entityType: 'EmployeeBankAccount',
            entityId: $account->id,
        );

        PayrollAuditLogger::log('BANK_ACCOUNT_CHANGE_REQUESTED', [
            'company_id'  => $companyId,
            'entity_type' => 'EmployeeBankAccount',
            'entity_id'   => (int) $account->id,
            'after'       => [
                'user_id'         => $userId,
                'bank_name'       => $account->bank_name,
                'account_masked'  => $account->maskedAccountNo(),
                'status'          => $account->status,
            ],
        ]);

        // Peringatkan karyawan bahwa rekeningnya diajukan berubah (deteksi dini fraud).
        $this->notifyBankChange($userId, 'bank_account_change_requested', [
            'title'           => 'Pengajuan Perubahan Rekening',
            'message'         => "Rekening bank Anda diajukan berubah ke {$account->bank_name} ({$account->maskedAccountNo()}). Menunggu verifikasi.",
            'bank_account_id' => $account->id,
        ]);

        return response()->json([
            'message' => 'Rekening diajukan & menunggu verifikasi oleh petugas berbeda.',
            'data'    => $account,
        ], 201);
    }

    /** POST /dashboard/payroll/bank-accounts/{bankAccount}/verify */
    public function verify(Request $request, EmployeeBankAccount $bankAccount): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPermission(Role::MODULE_PAYROLL, 'manage')) {
            return $this->deny();
        }
        if ((int) $bankAccount->company_id !== (int) $user->company_id) {
            return response()->json(['message' => 'Rekening tidak ditemukan.'], 404);
        }
        if ($bankAccount->status !== EmployeeBankAccount::STATUS_PENDING) {
            return response()->json(['message' => 'Hanya rekening berstatus menunggu verifikasi yang dapat diverifikasi.'], 422);
        }
        // Maker-checker: pemverifikasi tidak boleh pengaju.
        if ((int) $bankAccount->requested_by === (int) $user->id) {
            return response()->json([
                'message' => 'Anda tidak dapat memverifikasi rekening yang Anda ajukan sendiri (maker-checker).',
            ], 403);
        }

        DB::transaction(function () use ($bankAccount, $user) {
            // Rekening aktif lama milik karyawan yang sama → superseded.
            EmployeeBankAccount::where('company_id', $bankAccount->company_id)
                ->where('user_id', $bankAccount->user_id)
                ->where('status', EmployeeBankAccount::STATUS_ACTIVE)
                ->where('id', '!=', $bankAccount->id)
                ->update(['status' => EmployeeBankAccount::STATUS_SUPERSEDED, 'is_primary' => false]);

            $bankAccount->update([
                'status'      => EmployeeBankAccount::STATUS_ACTIVE,
                'is_primary'  => true,
                'verified_by' => $user->id,
                'verified_at' => now(),
            ]);

            // Sinkron ke users.bank_* (snapshot payslip existing tetap jalan tanpa ubah kalkulator).
            $employee = User::find($bankAccount->user_id);
            if ($employee) {
                $employee->update([
                    'bank_name'           => $bankAccount->bank_name,
                    'bank_account_no'     => $bankAccount->bank_account_no, // didekripsi via cast rekening
                    'bank_account_holder' => $bankAccount->bank_account_holder,
                ]);
            }
        });

        AuditLogger::log(
            action: 'BANK_ACCOUNT_VERIFIED',
            description: "Memverifikasi rekening bank #{$bankAccount->id} (user #{$bankAccount->user_id}, {$bankAccount->bank_name} {$bankAccount->maskedAccountNo()})",
            category: AuditLogger::CATEGORY_SECURITY,
            severity: AuditLogger::SEVERITY_CRITICAL,
            entityType: 'EmployeeBankAccount',
            entityId: $bankAccount->id,
        );

        PayrollAuditLogger::log('BANK_ACCOUNT_VERIFIED', [
            'company_id'  => (int) $bankAccount->company_id,
            'entity_type' => 'EmployeeBankAccount',
            'entity_id'   => (int) $bankAccount->id,
            'after'       => [
                'user_id'        => (int) $bankAccount->user_id,
                'bank_name'      => $bankAccount->bank_name,
                'account_masked' => $bankAccount->maskedAccountNo(),
                'status'         => $bankAccount->status,
                'verified_by'    => (int) $bankAccount->verified_by,
            ],
        ]);

        $this->notifyBankChange((int) $bankAccount->user_id, 'bank_account_verified', [
            'title'           => 'Rekening Bank Diverifikasi',
            'message'         => "Rekening bank Anda kini aktif: {$bankAccount->bank_name} ({$bankAccount->maskedAccountNo()}).",
            'bank_account_id' => $bankAccount->id,
        ]);

        return response()->json(['message' => 'Rekening diverifikasi & menjadi rekening aktif.', 'data' => $bankAccount]);
    }

    /** POST /dashboard/payroll/bank-accounts/{bankAccount}/reject */
    public function reject(Request $request, EmployeeBankAccount $bankAccount): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPermission(Role::MODULE_PAYROLL, 'manage')) {
            return $this->deny();
        }
        if ((int) $bankAccount->company_id !== (int) $user->company_id) {
            return response()->json(['message' => 'Rekening tidak ditemukan.'], 404);
        }
        if ($bankAccount->status !== EmployeeBankAccount::STATUS_PENDING) {
            return response()->json(['message' => 'Hanya rekening berstatus menunggu verifikasi yang dapat ditolak.'], 422);
        }
        // Maker-checker: penolak tidak boleh pengaju.
        if ((int) $bankAccount->requested_by === (int) $user->id) {
            return response()->json([
                'message' => 'Anda tidak dapat menolak rekening yang Anda ajukan sendiri (maker-checker).',
            ], 403);
        }

        $validated = $request->validate([
            'reason' => 'required|string|max:1000',
        ]);

        $bankAccount->update([
            'status'        => EmployeeBankAccount::STATUS_REJECTED,
            'reject_reason' => $validated['reason'],
            'verified_by'   => $user->id,
            'verified_at'   => now(),
        ]);

        AuditLogger::log(
            action: 'BANK_ACCOUNT_REJECTED',
            description: "Menolak rekening bank #{$bankAccount->id} (user #{$bankAccount->user_id}): {$validated['reason']}",
            category: AuditLogger::CATEGORY_SECURITY,
            severity: AuditLogger::SEVERITY_WARNING,
            entityType: 'EmployeeBankAccount',
            entityId: $bankAccount->id,
        );

        PayrollAuditLogger::log('BANK_ACCOUNT_REJECTED', [
            'company_id'  => (int) $bankAccount->company_id,
            'entity_type' => 'EmployeeBankAccount',
            'entity_id'   => (int) $bankAccount->id,
            'notes'       => $validated['reason'],
            'after'       => ['user_id' => (int) $bankAccount->user_id, 'status' => $bankAccount->status],
        ]);

        $this->notifyBankChange((int) $bankAccount->user_id, 'bank_account_rejected', [
            'title'           => 'Pengajuan Rekening Ditolak',
            'message'         => "Pengajuan perubahan rekening bank Anda ditolak: {$validated['reason']}",
            'bank_account_id' => $bankAccount->id,
        ]);

        return response()->json(['message' => 'Pengajuan rekening ditolak.', 'data' => $bankAccount]);
    }
}
