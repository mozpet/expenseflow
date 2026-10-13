<?php

namespace App\Services\Attendance;

use App\Models\Role;

/**
 * Gate otorisasi approval lembur 2 tahap. Dipakai approveOvertime() & rejectOvertime().
 */
class OvertimeApprovalGate extends ApprovalGate
{
    protected function module(): string
    {
        return Role::MODULE_OVERTIME;
    }

    protected function subject(): string
    {
        return 'lembur';
    }

    /**
     * TIDAK, dan ini disengaja (perbaikan bug 2026-10-07 — jangan diseragamkan
     * dengan domain cuti). Fallback "SPV perusahaan" harus membuktikan wewenang
     * struktural atas karyawan spesifik ybs, bukan sekadar "punya bawahan di tim
     * lain"; tanpa pengecualian ini, HRD yang juga menjabat posisi supervisor di
     * tim manapun bisa melewati Tahap 1 untuk karyawan tanpa manager_id maupun
     * division_id (lihat WAITING_SPV_APPROVAL).
     */
    protected function allowCompanyWideFallbackForHrd(): bool
    {
        return false;
    }

    /**
     * Domain lembur memakai kata "Persetujuan" di seluruh pesan gate, termasuk
     * pada jalur penolakan — perilaku lama yang dipertahankan apa adanya agar
     * ekstraksi ini tidak mengubah response yang sudah dipakai klien.
     */
    protected function normalizeAction(string $action): string
    {
        return 'Persetujuan';
    }

    protected function waitingSpvMessage(string $action): string
    {
        return 'Pengajuan lembur ini masih menunggu persetujuan Tahap 1 (SPV/Atasan Langsung). HRD baru dapat memproses setelah SPV menyetujui.';
    }

    protected function hrdRequiredMessage(string $action): string
    {
        return 'Persetujuan tahap akhir lembur hanya dapat dilakukan oleh HRD atau Admin.';
    }
}
