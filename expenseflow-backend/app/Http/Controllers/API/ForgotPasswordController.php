<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Mail\SendPasswordResetOtpMail;
use App\Models\PasswordResetOtp;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ForgotPasswordController extends Controller
{
    /**
     * POST /api/v1/auth/forgot-password/send-otp
     * Mengirim kode OTP 6-digit ke email pengguna dengan proteksi anti-spam & cooldown.
     */
    public function sendOtp(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email|max:255',
        ]);

        $email = strtolower(trim($request->email));
        $user = User::where('email', $email)->first();

        // Demi keamanan, jika email tidak ditemukan, kita berikan response sukses generik atau pesan informatif
        if (! $user) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Email tidak terdaftar di sistem kami.',
            ], 404);
        }

        if (! $user->is_active) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Akun Anda berstatus nonaktif. Silakan hubungi HRD atau Admin.',
            ], 403);
        }

        // ─── 1. Proteksi Anti-Spam: Cooldown 60 Detik ───
        $existingOtp = PasswordResetOtp::where('email', $email)->first();
        if ($existingOtp && $existingOtp->resend_available_at && Carbon::now()->lessThan($existingOtp->resend_available_at)) {
            $secondsLeft = Carbon::now()->diffInSeconds($existingOtp->resend_available_at, false);
            $secondsLeft = max(1, (int) ceil($secondsLeft));

            return response()->json([
                'status'      => 'error',
                'message'     => "Mohon tunggu {$secondsLeft} detik sebelum meminta kode OTP kembali.",
                'retry_after' => $secondsLeft,
            ], 429);
        }

        // ─── 2. Proteksi Anti-Spam: Batas Maksimal 5x per 24 Jam ───
        // Cek log permintaan OTP pada email ini dalam 24 jam terakhir
        $recentRequestsCount = DB::table('activity_logs')
            ->where('entity_type', 'user')
            ->where('entity_id', $user->id)
            ->where('action', 'otp_requested')
            ->where('created_at', '>=', Carbon::now()->subDay())
            ->count();

        if ($recentRequestsCount >= 5) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Batas maksimal permintaan OTP (5 kali / 24 jam) telah tercapai. Silakan hubungi Admin atau coba lagi besok.',
            ], 429);
        }

        // ─── 3. Generate Kode OTP 6 Digit ───
        $otp = str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
        $otpHash = Hash::make($otp);

        // ─── 4. Simpan ke Database ───
        PasswordResetOtp::updateOrCreate(
            ['email' => $email],
            [
                'otp_hash'            => $otpHash,
                'reset_token'         => null,
                'attempts'            => 0,
                'expires_at'          => Carbon::now()->addMinutes(5),
                'resend_available_at' => Carbon::now()->addSeconds(60),
            ]
        );

        // ─── 5. Kirim Email OTP Langsung via Gmail SMTP ───
        try {
            Mail::to($user->email)->send(new SendPasswordResetOtpMail($otp, $user->name));
        } catch (\Throwable $e) {
            Log::error("Gagal mengirim email OTP ke {$user->email}: " . $e->getMessage());
        }

        // ─── 6. Catat ke Activity Log ───
        DB::table('activity_logs')->insert([
            'company_id'  => $user->company_id,
            'user_id'     => $user->id,
            'action'      => 'otp_requested',
            'description' => "Kode OTP reset password diminta untuk {$user->email}.",
            'entity_type' => 'user',
            'entity_id'   => $user->id,
            'created_at'  => Carbon::now(),
            'updated_at'  => Carbon::now(),
        ]);

        $response = [
            'status'             => 'success',
            'message'            => "Kode OTP telah dikirim ke {$user->email}. Silakan periksa kotak masuk atau spam.",
            'expires_in_seconds' => 300,
            'cooldown_seconds'   => 60,
        ];

        // Jika mode debug/local, sertakan debug_otp agar mudah dites tanpa buka email
        if (config('app.debug')) {
            $response['debug_otp'] = $otp;
        }

        return response()->json($response);
    }

    /**
     * POST /api/v1/auth/forgot-password/verify-otp
     * Memverifikasi kode OTP 6-digit & menghasilkan single-use reset_token.
     */
    public function verifyOtp(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email|max:255',
            'otp'   => 'required|string|size:6',
        ]);

        $email = strtolower(trim($request->email));
        $otp = trim($request->otp);

        $record = PasswordResetOtp::where('email', $email)->first();

        if (! $record) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Permintaan OTP tidak ditemukan. Silakan minta kode baru.',
            ], 422);
        }

        // Cek kedaluwarsa
        if (Carbon::now()->greaterThan($record->expires_at)) {
            $record->delete();
            return response()->json([
                'status'  => 'error',
                'message' => 'Kode OTP telah kedaluwarsa. Silakan minta kode baru.',
            ], 422);
        }

        // Proteksi Brute-Force: Maksimal 5 kali salah
        if ($record->attempts >= 5) {
            $record->delete();
            return response()->json([
                'status'  => 'error',
                'message' => 'Batas percobaan salah telah terlampaui (maks. 5 kali). Kode OTP ini hangus demi keamanan. Silakan minta kode baru.',
            ], 422);
        }

        // Verifikasi Hash OTP
        if (! Hash::check($otp, $record->otp_hash)) {
            $record->increment('attempts');
            $attemptsLeft = 5 - $record->attempts;

            return response()->json([
                'status'  => 'error',
                'message' => "Kode OTP yang Anda masukkan salah. Sisa percobaan: {$attemptsLeft} kali.",
                'attempts_left' => $attemptsLeft,
            ], 422);
        }

        // OTP Cocok! Buat single-use reset token yang berlaku 15 menit
        $resetToken = Str::random(64);
        $record->update([
            'reset_token' => $resetToken,
            'expires_at'  => Carbon::now()->addMinutes(15),
            'attempts'    => 0,
        ]);

        return response()->json([
            'status'      => 'success',
            'message'     => 'Kode OTP berhasil diverifikasi.',
            'reset_token' => $resetToken,
        ]);
    }

    /**
     * POST /api/v1/auth/forgot-password/reset
     * Memperbarui password user dan mencabut semua token login lama.
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'email'                 => 'required|email|max:255',
            'reset_token'           => 'required|string|min:32|max:100',
            'password'              => 'required|string|min:8|max:255|confirmed',
        ]);

        $email = strtolower(trim($request->email));
        $resetToken = trim($request->reset_token);

        $record = PasswordResetOtp::where('email', $email)
            ->where('reset_token', $resetToken)
            ->first();

        if (! $record || Carbon::now()->greaterThan($record->expires_at)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Sesi reset password tidak valid atau telah kedaluwarsa. Silakan ulangi proses dari awal.',
            ], 422);
        }

        $user = User::where('email', $email)->first();
        if (! $user) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Pengguna tidak ditemukan.',
            ], 404);
        }

        // 1. Update Password Baru
        $user->update([
            'password' => Hash::make($request->password),
        ]);

        // 2. Revoke semua Sanctum tokens (paksa logout dari seluruh perangkat lama)
        $user->tokens()->delete();

        // 3. Hapus record OTP (Single Use)
        $record->delete();

        // 4. Catat ke Activity Log
        DB::table('activity_logs')->insert([
            'company_id'  => $user->company_id,
            'user_id'     => $user->id,
            'action'      => 'password_reset_via_otp',
            'description' => "Pengguna {$user->name} ({$user->email}) berhasil mereset password via OTP email.",
            'entity_type' => 'user',
            'entity_id'   => $user->id,
            'created_at'  => Carbon::now(),
            'updated_at'  => Carbon::now(),
        ]);

        return response()->json([
            'status'  => 'success',
            'message' => 'Password Anda berhasil diperbarui. Silakan login kembali dengan password baru Anda.',
        ]);
    }
}
