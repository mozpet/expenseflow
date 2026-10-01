<?php

namespace App\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Jejak audit tamper-evident khusus payroll — hash-chain SHA-256 (Fase 3, spec §10.H & §15).
 *
 * Berbeda dari AuditLogger (feed audit terpadu ke activity_logs), service ini menulis
 * rantai per-perusahaan ke payroll_logs: setiap baris menyimpan prev_hash (record_hash
 * baris sebelumnya) + record_hash (SHA-256 dari kanonik baris). Mengubah / menyisipkan /
 * menghapus satu baris secara retroaktif memutus rantai, dan verifyChain() mendeteksi
 * posisi (sequence) pertama yang rusak.
 *
 * Seperti AuditLogger, kegagalan pencatatan TIDAK PERNAH menggagalkan alur bisnis
 * (dibungkus try/catch + Log::error).
 */
class PayrollAuditLogger
{
    /**
     * Catat satu event ke rantai audit payroll perusahaan.
     *
     * $opts: company_id, payroll_id, user_id, entity_type, entity_id (int),
     *        notes (string), before (array), after (array).
     * Mengembalikan record_hash yang tercatat, atau null bila gagal/di-skip.
     */
    public static function log(string $action, array $opts = []): ?string
    {
        try {
            $authUser = Auth::user();

            $companyId = $opts['company_id'] ?? $authUser?->company_id;
            if ($companyId === null) {
                Log::warning('PayrollAuditLogger dilewati: company_id tidak diketahui.', ['action' => $action]);
                return null;
            }
            $companyId = (int) $companyId;

            $userId = array_key_exists('user_id', $opts) ? $opts['user_id'] : $authUser?->id;

            $request   = request();
            $ipAddress = $request ? $request->ip() : null;
            $userAgent = $request ? substr((string) $request->userAgent(), 0, 255) : null;

            // Sanitasi payload (defense-in-depth: jangan pernah simpan rahasia mentah).
            $beforeJson = isset($opts['before']) && $opts['before'] !== null
                ? json_encode(AuditLogger::sanitizeValues($opts['before']))
                : null;
            $afterJson = isset($opts['after']) && $opts['after'] !== null
                ? json_encode(AuditLogger::sanitizeValues($opts['after']))
                : null;

            return DB::transaction(function () use (
                $companyId, $userId, $action, $opts, $beforeJson, $afterJson, $ipAddress, $userAgent
            ) {
                // Kunci baris terakhir chain perusahaan → serialisasi penulis (anti-race).
                $last = DB::table('payroll_logs')
                    ->where('company_id', $companyId)
                    ->orderByDesc('sequence')
                    ->lockForUpdate()
                    ->first();

                $prevHash = $last->record_hash ?? null;          // null = genesis
                $sequence = (int) ($last->sequence ?? 0) + 1;
                $createdAt = now()->format('Y-m-d H:i:s');

                $row = [
                    'company_id'   => $companyId,
                    'payroll_id'   => $opts['payroll_id'] ?? null,
                    'user_id'      => $userId,
                    'action'       => $action,
                    'entity_type'  => $opts['entity_type'] ?? null,
                    'entity_id'    => $opts['entity_id'] ?? null,
                    'before_state' => $beforeJson,
                    'after_state'  => $afterJson,
                    'ip_address'   => $ipAddress,
                    'user_agent'   => $userAgent,
                    'created_at'   => $createdAt,
                    'sequence'     => $sequence,
                    'prev_hash'    => $prevHash,
                ];

                $recordHash = hash('sha256', self::canonical($row));

                $row['record_hash'] = $recordHash;
                $row['notes']       = $opts['notes'] ?? null; // deskriptif, di luar hash

                DB::table('payroll_logs')->insert($row);

                return $recordHash;
            });
        } catch (\Throwable $e) {
            Log::error('Gagal mencatat PayrollLog (hash-chain): ' . $e->getMessage(), [
                'action' => $action,
                'error'  => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    /**
     * Verifikasi integritas rantai audit satu perusahaan.
     * @return array{ok:bool, broken_at:?int, reason:?string, count:int}
     */
    public static function verifyChain(int $companyId): array
    {
        $rows = DB::table('payroll_logs')
            ->where('company_id', $companyId)
            ->orderBy('sequence')
            ->get();

        $prev = null;
        foreach ($rows as $r) {
            // 1) Konsistensi isi baris: hash ulang dari kolom tersimpan.
            $recompute = hash('sha256', self::canonical([
                'company_id'   => $r->company_id,
                'payroll_id'   => $r->payroll_id,
                'user_id'      => $r->user_id,
                'action'       => $r->action,
                'entity_type'  => $r->entity_type,
                'entity_id'    => $r->entity_id,
                'before_state' => $r->before_state,
                'after_state'  => $r->after_state,
                'ip_address'   => $r->ip_address,
                'user_agent'   => $r->user_agent,
                'created_at'   => $r->created_at,
                'sequence'     => $r->sequence,
                'prev_hash'    => $r->prev_hash,
            ]));

            if (! hash_equals($recompute, (string) $r->record_hash)) {
                return ['ok' => false, 'broken_at' => (int) $r->sequence, 'reason' => 'record_hash_mismatch', 'count' => $rows->count()];
            }

            // 2) Keterkaitan rantai: prev_hash harus = record_hash baris sebelumnya.
            $expectedPrev = $prev->record_hash ?? null;
            if (($r->prev_hash ?? null) !== $expectedPrev) {
                return ['ok' => false, 'broken_at' => (int) $r->sequence, 'reason' => 'prev_hash_mismatch', 'count' => $rows->count()];
            }

            $prev = $r;
        }

        return ['ok' => true, 'broken_at' => null, 'reason' => null, 'count' => $rows->count()];
    }

    /**
     * Bentuk string kanonik deterministik dari sebuah baris (dipakai log & verify).
     * Nilai null → string kosong; urutan field tetap.
     */
    private static function canonical(array $r): string
    {
        return implode('|', [
            (string) $r['company_id'],
            $r['payroll_id'] ?? '',
            $r['user_id'] ?? '',
            (string) $r['action'],
            $r['entity_type'] ?? '',
            $r['entity_id'] ?? '',
            $r['before_state'] ?? '',
            $r['after_state'] ?? '',
            $r['ip_address'] ?? '',
            $r['user_agent'] ?? '',
            (string) $r['created_at'],
            (string) $r['sequence'],
            $r['prev_hash'] ?? '',
        ]);
    }
}
