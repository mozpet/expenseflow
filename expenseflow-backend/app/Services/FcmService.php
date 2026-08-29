<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * FcmService — kirim push notification ke Flutter via Firebase Cloud Messaging (FCM HTTP v1 API).
 *
 * Menggunakan Service Account JSON dari Firebase Console:
 *   storage/app/expenseflow-e5296-firebase-adminsdk-*.json
 *
 * Menggunakan autentikasi Google OAuth2 (RS256 JWT assertion).
 */
class FcmService
{
    /**
     * Cari dan muat kredensial Service Account JSON.
     */
    private function getServiceAccountCredentials(): ?array
    {
        // 1. Cek konfigurasi spesifik jika ada
        $configPath = config('services.fcm.credentials_path');
        if ($configPath && file_exists($configPath)) {
            $data = json_decode(file_get_contents($configPath), true);
            if (is_array($data) && ! empty($data['private_key']) && ! empty($data['client_email'])) {
                return $data;
            }
        }

        // 2. Cari otomatis file JSON firebase di storage/app/
        $files = glob(storage_path('app/*firebase-adminsdk*.json'));
        if (! empty($files) && file_exists($files[0])) {
            $data = json_decode(file_get_contents($files[0]), true);
            if (is_array($data) && ! empty($data['private_key']) && ! empty($data['client_email'])) {
                return $data;
            }
        }

        // 3. Fallback file credentials standar
        $fallback = storage_path('app/firebase-credentials.json');
        if (file_exists($fallback)) {
            $data = json_decode(file_get_contents($fallback), true);
            if (is_array($data) && ! empty($data['private_key']) && ! empty($data['client_email'])) {
                return $data;
            }
        }

        return null;
    }

    /**
     * Dapatkan OAuth2 Access Token untuk Google Firebase Cloud Messaging API.
     */
    private function getAccessToken(): ?string
    {
        return Cache::remember('fcm_http_v1_access_token', 3300, function () {
            $credentials = $this->getServiceAccountCredentials();
            if (! $credentials) {
                Log::debug('FCM v1: Service account credentials tidak ditemukan di storage/app/');
                return null;
            }

            try {
                $now = time();
                $header = json_encode(['alg' => 'RS256', 'typ' => 'JWT']);
                $claims = json_encode([
                    'iss'   => $credentials['client_email'],
                    'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                    'aud'   => 'https://oauth2.googleapis.com/token',
                    'iat'   => $now,
                    'exp'   => $now + 3600,
                ]);

                $base64UrlHeader = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
                $base64UrlClaims = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($claims));
                $signatureInput  = $base64UrlHeader . '.' . $base64UrlClaims;

                $privateKey = $credentials['private_key'];
                $binarySignature = '';
                $success = openssl_sign($signatureInput, $binarySignature, $privateKey, OPENSSL_ALGO_SHA256);
                if (! $success) {
                    Log::error('FCM v1: Gagal menandatangani JWT dengan private key.');
                    return null;
                }

                $base64UrlSignature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($binarySignature));
                $jwt = $signatureInput . '.' . $base64UrlSignature;

                $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion'  => $jwt,
                ]);

                if (! $response->successful()) {
                    Log::error('FCM v1: Gagal mendapatkan OAuth2 access token: ' . $response->body());
                    return null;
                }

                return $response->json('access_token');
            } catch (\Throwable $e) {
                Log::error('FCM v1: Exception saat mengambil access token: ' . $e->getMessage());
                return null;
            }
        });
    }

    /**
     * Kirim push notification ke satu device via FCM HTTP v1 API.
     */
    public function send(string $fcmToken, string $title, string $body, array $data = []): bool
    {
        $credentials = $this->getServiceAccountCredentials();
        if (! $credentials) {
            Log::debug('FCM v1: Service account credentials tidak ditemukan, notifikasi dilewati.');
            return false;
        }

        $projectId   = $credentials['project_id'] ?? 'expenseflow-e5296';
        $accessToken = $this->getAccessToken();
        if (! $accessToken) {
            return false;
        }

        try {
            // Format semua data key-value menjadi string (wajib di FCM v1)
            $stringData = [];
            foreach ($data as $k => $v) {
                $stringData[(string) $k] = is_array($v) ? json_encode($v) : (string) $v;
            }
            $stringData['click_action'] = 'FLUTTER_NOTIFICATION_CLICK';

            $payload = [
                'message' => [
                    'token'        => $fcmToken,
                    'notification' => [
                        'title' => $title,
                        'body'  => $body,
                    ],
                    'data'         => (object) $stringData,
                    'android'      => [
                        'priority'     => 'high',
                        'notification' => [
                            'sound'      => 'default',
                            'channel_id' => 'high_importance_channel',
                        ],
                    ],
                ],
            ];

            $response = Http::withHeaders([
                'Authorization' => "Bearer {$accessToken}",
                'Content-Type'  => 'application/json',
            ])->post("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send", $payload);

            if (! $response->successful()) {
                Log::warning('FCM v1: Gagal kirim notifikasi.', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                    'title'  => $title,
                ]);
                return false;
            }

            Log::info("FCM v1: Sukses kirim notifikasi '{$title}' ke token " . substr($fcmToken, 0, 15) . '...');
            return true;
        } catch (\Throwable $e) {
            Log::error('FCM v1: Exception saat kirim notifikasi: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Kirim push notification ke banyak device.
     */
    public function sendMulticast(array $tokens, string $title, string $body, array $data = []): int
    {
        $sent = 0;
        foreach ($tokens as $token) {
            if ($this->send($token, $title, $body, $data)) {
                $sent++;
            }
        }
        return $sent;
    }
}
