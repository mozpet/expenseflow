<?php

namespace App\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AuditLogger
{
    /**
     * Kategori Audit Log.
     */
    public const CATEGORY_HR          = 'HR_EMPLOYEE';
    public const CATEGORY_FINANCE     = 'PAYROLL_FINANCE';
    public const CATEGORY_EXPENSE     = 'EXPENSE_CLAIM';
    public const CATEGORY_ATTENDANCE  = 'ATTENDANCE_OFFICE';
    public const CATEGORY_SECURITY    = 'SECURITY_AUTH';
    public const CATEGORY_SETTINGS    = 'COMPANY_SETTINGS';

    /**
     * Level Keparahan / Urgensi Audit Log.
     */
    public const SEVERITY_INFO     = 'info';
    public const SEVERITY_WARNING  = 'warning';
    public const SEVERITY_CRITICAL = 'critical';

    /**
     * Key yang wajib dimasking agar tidak terekspos dalam plaintext log.
     */
    private const SENSITIVE_KEYS = [
        'password',
        'password_confirmation',
        'token',
        'remember_token',
        'api_key',
        'secret',
        'otp',
    ];

    /**
     * Catat entri audit log ke database.
     */
    public static function log(
        string $action,
        string $description,
        string $category = self::CATEGORY_HR,
        string $severity = self::SEVERITY_INFO,
        ?string $entityType = null,
        ?int $entityId = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?int $companyId = null,
        ?int $userId = null
    ): ?int {
        try {
            $user = Auth::user();

            $finalCompanyId = $companyId ?? $user?->company_id;
            $finalUserId    = $userId ?? $user?->id;

            $request = request();
            $ipAddress = $request ? $request->ip() : null;
            $userAgent = $request ? substr((string) $request->userAgent(), 0, 500) : null;

            // Bersihkan data rahasia
            $sanitizedOld = $oldValues !== null ? self::sanitizeValues($oldValues) : null;
            $sanitizedNew = $newValues !== null ? self::sanitizeValues($newValues) : null;

            $id = DB::table('activity_logs')->insertGetId([
                'company_id'  => $finalCompanyId,
                'user_id'     => $finalUserId,
                'action'      => $action,
                'category'    => $category,
                'severity'    => $severity,
                'description' => $description,
                'entity_type' => $entityType,
                'entity_id'   => $entityId,
                'old_values'  => $sanitizedOld ? json_encode($sanitizedOld) : null,
                'new_values'  => $sanitizedNew ? json_encode($sanitizedNew) : null,
                'ip_address'  => $ipAddress,
                'user_agent'  => $userAgent,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);

            return $id;
        } catch (\Throwable $e) {
            Log::error('Gagal mencatat AuditLog: ' . $e->getMessage(), [
                'action' => $action,
                'error'  => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    /**
     * Helper mencatat perubahan sensitif dengan diff perbandingan old vs new.
     */
    public static function logModelDiff(
        string $action,
        string $description,
        string $category,
        string $severity,
        string $entityType,
        int $entityId,
        array $original,
        array $updated,
        array $trackedKeys = []
    ): ?int {
        $oldValues = [];
        $newValues = [];

        // Jika trackedKeys kosong, periksa semua key yang ada di $updated
        $keysToCheck = !empty($trackedKeys) ? $trackedKeys : array_keys($updated);

        foreach ($keysToCheck as $key) {
            $oldVal = $original[$key] ?? null;
            $newVal = $updated[$key] ?? null;

            // Jika ada perubahan nilai
            if ($oldVal != $newVal) {
                $oldValues[$key] = $oldVal;
                $newValues[$key] = $newVal;
            }
        }

        // Hanya catat jika memang ada perubahan nilai atau jika description penting
        return self::log(
            action: $action,
            description: $description,
            category: $category,
            severity: $severity,
            entityType: $entityType,
            entityId: $entityId,
            oldValues: !empty($oldValues) ? $oldValues : null,
            newValues: !empty($newValues) ? $newValues : null
        );
    }

    /**
     * Sanitasi array untuk menyamarkan field rahasia.
     */
    public static function sanitizeValues(array $values): array
    {
        $sanitized = [];
        foreach ($values as $key => $value) {
            if (in_array(strtolower((string) $key), self::SENSITIVE_KEYS, true)) {
                $sanitized[$key] = '********';
            } elseif (is_array($value)) {
                $sanitized[$key] = self::sanitizeValues($value);
            } else {
                $sanitized[$key] = $value;
            }
        }
        return $sanitized;
    }
}
