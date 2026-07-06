<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * FcmService — kirim push notification ke Flutter via Firebase Cloud Messaging (FCM v1 API).
 *
 * Konfigurasi .env yang dibutuhkan:
 *   FCM_PROJECT_ID=your-firebase-project-id
 *   FCM_SERVER_KEY=your-server-key   (Legacy HTTP API, untuk fallback)
 *
 * Jika tidak dikonfigurasi, service ini diam-diam melewati pengiriman
 * (agar sistem tetap berfungsi walau FCM belum disetup).
 */
class FcmService
{
    /**
     * Kirim push notification ke satu device via FCM Legacy HTTP API.
     *
     * @param  string  $fcmToken  Token FCM device tujuan
     * @param  string  $title     Judul notifikasi
     * @param  string  $body      Isi pesan notifikasi
     * @param  array   $data      Data tambahan (dikirim sebagai payload data)
     */
    public function send(string $fcmToken, string $title, string $body, array $data = []): bool
    {
        $serverKey = config('services.fcm.server_key');

        if (! $serverKey) {
            // FCM belum dikonfigurasi — lewati tanpa error
            Log::debug('FCM: server_key tidak dikonfigurasi, notifikasi dilewati.', [
                'title' => $title,
                'token' => substr($fcmToken, 0, 10) . '...',
            ]);
            return false;
        }

        try {
            $payload = [
                'to'           => $fcmToken,
                'notification' => [
                    'title' => $title,
                    'body'  => $body,
                    'sound' => 'default',
                ],
                'data'         => array_merge($data, ['click_action' => 'FLUTTER_NOTIFICATION_CLICK']),
                'priority'     => 'high',
            ];

            $response = Http::withHeaders([
                'Authorization' => "key={$serverKey}",
                'Content-Type'  => 'application/json',
            ])->post('https://fcm.googleapis.com/fcm/send', $payload);

            if (! $response->successful()) {
                Log::warning('FCM: gagal kirim notifikasi.', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                    'title'  => $title,
                ]);
                return false;
            }

            $result = $response->json();
            // success=0 berarti token tidak valid / device tidak terdaftar
            if (isset($result['success']) && $result['success'] === 0) {
                Log::warning('FCM: token tidak valid atau device tidak terdaftar.', [
                    'failure' => $result,
                    'token'   => substr($fcmToken, 0, 10) . '...',
                ]);
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::error('FCM: exception saat kirim notifikasi.', [
                'error' => $e->getMessage(),
                'title' => $title,
            ]);
            return false;
        }
    }

    /**
     * Kirim push notification ke banyak device (multicast).
     *
     * @param  array<string>  $tokens  Daftar FCM token tujuan
     */
    public function sendMulticast(array $tokens, string $title, string $body, array $data = []): int
    {
        $sent = 0;
        foreach (array_chunk($tokens, 500) as $chunk) {
            if ($this->sendToGroup($chunk, $title, $body, $data)) {
                $sent += count($chunk);
            }
        }
        return $sent;
    }

    private function sendToGroup(array $tokens, string $title, string $body, array $data): bool
    {
        $serverKey = config('services.fcm.server_key');
        if (! $serverKey || empty($tokens)) {
            return false;
        }

        try {
            $payload = [
                'registration_ids' => $tokens,
                'notification'     => [
                    'title' => $title,
                    'body'  => $body,
                    'sound' => 'default',
                ],
                'data'             => array_merge($data, ['click_action' => 'FLUTTER_NOTIFICATION_CLICK']),
                'priority'         => 'high',
            ];

            $response = Http::withHeaders([
                'Authorization' => "key={$serverKey}",
                'Content-Type'  => 'application/json',
            ])->post('https://fcm.googleapis.com/fcm/send', $payload);

            return $response->successful();
        } catch (\Throwable $e) {
            Log::error('FCM multicast exception: ' . $e->getMessage());
            return false;
        }
    }
}
