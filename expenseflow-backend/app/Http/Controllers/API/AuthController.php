<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\DeviceChangeRequest;
use App\Models\LoginAttempt;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Handle user login dengan validasi platform.
     * Role guard: employee hanya mobile; role lain boleh mobile maupun web.
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email'       => 'required|email|max:255',
            'password'    => 'required|string|min:8|max:255',
            // Identitas device — wajib untuk mobile agar bisa device binding (cegah titip absen).
            'device_id'   => 'nullable|string|max:255',
            'device_name' => 'nullable|string|max:255',
        ]);

        // Validasi X-Platform header
        $platform = $request->header('X-Platform', 'web');
        if (! in_array($platform, ['mobile', 'web'])) {
            $platform = 'web';
        }

        $user = User::where('email', $request->email)->first();

        // Cek kredensial — jika gagal
        if (! $user || ! Hash::check($request->password, $user->password)) {
            $this->logAttempt($user, $request, 'failed');

            throw ValidationException::withMessages([
                'email' => ['Email atau Password Salah.'],
            ]);
        }

        // Cek akun aktif
        if (! $user->is_active) {
            $this->logAttempt($user, $request, 'failed');

            return response()->json([
                'message' => 'Akun telah dinonaktifkan.',
            ], 403);
        }

        // Validasi role vs platform
        $role = $user->role;

        // Employee TIDAK boleh login via web
        if ($platform === 'web' && $role === 'employee') {
            $this->logAttempt($user, $request, 'failed');

            return response()->json([
                'message' => 'Karyawan hanya bisa login di aplikasi mobile.',
            ], 403);
        }

        // ─── DEVICE BINDING (mobile, role employee) — cegah "titip absen" ───
        // 1 akun karyawan terikat 1 device. Pindah device wajib approval HR.
        //   - Device pertama         → auto-bind (trust-on-first-use).
        //   - Device sama            → lolos.
        //   - Device beda            → login DITOLAK + buat permintaan pindah
        //                              device (pending) untuk di-approve HR.
        if ($platform === 'mobile' && in_array($role, ['employee', 'hrd', 'finance', 'admin', 'super_admin']) && config('app.device_binding_enabled', true)) {
            $deviceId   = $request->input('device_id');
            $deviceName = $request->input('device_name');

            // device_id wajib ada agar binding bisa ditegakkan.
            if (! $deviceId) {
                $this->logAttempt($user, $request, 'failed');

                return response()->json([
                    'message' => 'Identitas perangkat tidak terdeteksi. Perbarui aplikasi Anda.',
                ], 422);
            }

            if (! $user->device_id) {
                // Belum pernah bind → ikat device ini ke akun (trust-on-first-use).
                $user->forceFill([
                    'device_id'       => $deviceId,
                    'device_name'     => $deviceName,
                    'device_bound_at' => now(),
                ])->save();
            } elseif ($user->device_id !== $deviceId) {
                // Device berbeda → tolak login & catat permintaan pindah device.
                $this->requestDeviceChange($user, $deviceId, $deviceName);
                $this->logAttempt($user, $request, 'failed');

                return response()->json([
                    'message'          => 'Login ditolak. Akun ini terikat pada perangkat lain. '
                        . 'Permintaan pindah perangkat telah dikirim ke HR untuk persetujuan.',
                    'device_mismatch'  => true,
                    'pending_approval' => true,
                ], 403);
            }
            // else: device sama → lanjut normal.
        }

        // Single-session untuk MOBILE: 1 akun hanya boleh aktif di 1 device.
        // Login mobile baru akan menghapus token mobile lama → device lama
        // otomatis ter-logout (token-nya jadi 401 pada request berikutnya).
        // Web dashboard TIDAK terpengaruh (tetap boleh multi-device/tab).
        if ($platform === 'mobile') {
            $user->tokens()->where('name', 'auth-token-mobile')->delete();
        }

        // Lolos — buat Sanctum token dengan expiration 24 jam.
        // Nama token dibedakan per-platform agar bisa ditarget saat single-session.
        $token = $user->createToken(
            "auth-token-{$platform}",
            ['*'],
            now()->addHours(24)
        )->plainTextToken;

        $this->logAttempt($user, $request, 'success');

        return response()->json([
            'message' => 'Login berhasil.',
            'user'    => $this->userPayload($user),
            'token'   => $token,
        ]);
    }

    /**
     * Susun data user beserta flag kapabilitas untuk konsumsi Flutter,
     * sehingga client bisa menampilkan/menyembunyikan menu tanpa request ulang.
     *
     * @return array<string, mixed>
     */
    private function userPayload(User $user): array
    {
        return [
            'id'                    => $user->id,
            'name'                  => $user->name,
            'email'                 => $user->email,
            'role'                  => $user->role,
            'department'            => $user->department,
            'company_id'            => $user->company_id,
            'attendance_enabled'    => $user->canAccessAttendance(),
            'wfh_enabled'           => $user->canWfh(),
            'radius_enabled'        => $user->hasRadiusEnabled(),
            'can_access_receipts'   => $user->canAccessReceipts(),
            'can_access_attendance' => $user->canAccessAttendance(),
        ];
    }

    /**
     * Catat setiap percobaan login ke tabel login_attempts.
     */
    private function logAttempt(?User $user, Request $request, string $status): void
    {
        LoginAttempt::create([
            'user_id'     => $user?->id,
            'ip_address'  => substr($request->ip() ?? '0.0.0.0', 0, 45),
            'user_agent'  => substr($request->userAgent() ?? 'unknown', 0, 500),
            'status'      => $status,
            'attempted_at' => now(),
        ]);
    }

    /**
     * Buat/refresh permintaan pindah device (pending) & notifikasi ke HR.
     * Dipakai saat karyawan login dari device yang tidak cocok dengan binding.
     */
    private function requestDeviceChange(User $user, string $newDeviceId, ?string $newDeviceName): void
    {
        // Jika sudah ada permintaan pending untuk device baru yang SAMA, jangan gandakan.
        $existing = DeviceChangeRequest::where('user_id', $user->id)
            ->where('status', 'pending')
            ->where('new_device_id', $newDeviceId)
            ->first();

        if ($existing) {
            return;
        }

        // Batalkan permintaan pending lama (device baru yang berbeda) agar hanya
        // ada satu permintaan aktif per karyawan.
        DeviceChangeRequest::where('user_id', $user->id)
            ->where('status', 'pending')
            ->update([
                'status'      => 'rejected',
                'notes'       => 'Otomatis dibatalkan karena ada permintaan device baru.',
                'reviewed_at' => now(),
            ]);

        $reqRow = DeviceChangeRequest::create([
            'user_id'         => $user->id,
            'company_id'      => $user->company_id,
            'old_device_id'   => $user->device_id,
            'old_device_name' => $user->device_name,
            'new_device_id'   => $newDeviceId,
            'new_device_name' => $newDeviceName,
            'status'          => 'pending',
        ]);

        // Notifikasi ke HR / admin / super_admin di perusahaan yang sama.
        $approvers = DB::table('users')
            ->where('company_id', $user->company_id)
            ->whereIn('role', ['hrd', 'admin', 'super_admin'])
            ->where('is_active', true)
            ->pluck('id');

        foreach ($approvers as $approverId) {
            DB::table('notifications')->insert([
                'id'              => Str::uuid()->toString(),
                'type'            => 'device_change_pending',
                'notifiable_type' => 'App\\Models\\User',
                'notifiable_id'   => $approverId,
                'user_id'         => $approverId,
                'data'            => json_encode([
                    'message'         => "{$user->name} mencoba login dari perangkat baru"
                        . ($newDeviceName ? " ({$newDeviceName})" : '')
                        . '. Perlu persetujuan pindah perangkat.',
                    'request_id'      => $reqRow->id,
                    'user_id'         => $user->id,
                    'user_name'       => $user->name,
                    'new_device_name' => $newDeviceName,
                ]),
                'entity_type' => 'device_change_request',
                'entity_id'   => $reqRow->id,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }
    }

    /**
     * Logout — hapus token yang sedang aktif.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logout berhasil.',
        ]);
    }

    /**
     * Ambil data user yang sedang login.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load('company');

        return response()->json([
            'user' => [
                ...$this->userPayload($user),
                'is_active'  => $user->is_active,
                'company'    => $user->company,
                'created_at' => $user->created_at,
            ],
        ]);
    }
}
