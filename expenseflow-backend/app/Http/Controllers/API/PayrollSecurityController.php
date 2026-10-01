<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

/**
 * Step-up PIN keamanan untuk persetujuan final payroll (Fase 3, spec §14).
 *
 * Soft rollout: PIN hanya WAJIB saat approve/mark-paid bila approver sudah mengaturnya
 * (lihat PayrollController::verifyStepUpPin). Endpoint ini untuk mengatur/mengubah PIN
 * milik pengguna sendiri. PIN disimpan ter-hash (cast 'hashed' di User) & tak pernah
 * dikembalikan. Mengganti PIN butuh verifikasi password + PIN lama (bila sudah ada).
 */
class PayrollSecurityController extends Controller
{
    /** GET /dashboard/payroll/security/pin — status PIN pengguna saat ini. */
    public function pinStatus(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'data' => [
                'has_pin' => (bool) $user->security_pin,
                'set_at'  => $user->security_pin_set_at?->toIso8601String(),
            ],
        ]);
    }

    /** POST /dashboard/payroll/security/pin — atur atau ubah PIN keamanan pengguna sendiri. */
    public function setPin(Request $request): JsonResponse
    {
        $user = $request->user();
        $hasPin = (bool) $user->security_pin;

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            // PIN lama wajib hanya bila PIN sudah pernah diatur (ganti PIN).
            'current_pin'      => [Rule::requiredIf($hasPin), 'nullable', 'string'],
            // PIN baru 4–6 digit angka; butuh field konfirmasi `pin_confirmation`.
            'pin'              => ['required', 'string', 'confirmed', 'regex:/^\d{4,6}$/'],
        ]);

        // Verifikasi kata sandi akun (mencegah penyalahgunaan sesi yang tertinggal terbuka).
        if (! Hash::check($validated['current_password'], $user->password)) {
            return response()->json([
                'message' => 'Kata sandi salah.',
                'errors'  => ['current_password' => ['Kata sandi yang Anda masukkan salah.']],
            ], 422);
        }

        // Bila sudah punya PIN, wajib membuktikan PIN lama.
        if ($hasPin && ! Hash::check((string) ($validated['current_pin'] ?? ''), $user->security_pin)) {
            AuditLogger::log(
                action: 'PAYROLL_PIN_CHANGE_FAILED',
                description: "Gagal mengubah PIN keamanan payroll (PIN lama salah) oleh user #{$user->id}",
                category: AuditLogger::CATEGORY_SECURITY,
                severity: AuditLogger::SEVERITY_WARNING,
                entityType: 'User',
                entityId: $user->id,
            );

            return response()->json([
                'message' => 'PIN lama salah.',
                'errors'  => ['current_pin' => ['PIN lama yang Anda masukkan salah.']],
            ], 422);
        }

        // Set eksplisit (bukan mass-assignment) — cast 'hashed' meng-hash otomatis saat save.
        $user->security_pin = $validated['pin'];
        $user->security_pin_set_at = now();
        $user->save();

        AuditLogger::log(
            action: $hasPin ? 'PAYROLL_PIN_CHANGED' : 'PAYROLL_PIN_SET',
            description: ($hasPin ? 'Mengubah' : 'Mengatur') . " PIN keamanan payroll oleh user #{$user->id}",
            category: AuditLogger::CATEGORY_SECURITY,
            severity: AuditLogger::SEVERITY_INFO,
            entityType: 'User',
            entityId: $user->id,
        );

        return response()->json([
            'message' => $hasPin ? 'PIN keamanan berhasil diubah.' : 'PIN keamanan berhasil diatur.',
            'data'    => ['has_pin' => true],
        ]);
    }
}
