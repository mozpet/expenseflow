<?php

namespace App\Services\Attendance;

use App\Models\Role;

/**
 * Gate otorisasi approval cuti/izin 2 tahap. Dipakai approveLeave() & rejectLeave().
 *
 * Perbedaan dari OvertimeApprovalGate: fallback "SPV perusahaan" untuk karyawan
 * tanpa manager_id DAN tanpa division_id di sini berlaku juga bagi HRD Level 2.
 */
class LeaveApprovalGate extends ApprovalGate
{
    protected function module(): string
    {
        return Role::MODULE_LEAVE;
    }

    protected function subject(): string
    {
        return 'cuti/izin';
    }

    protected function allowCompanyWideFallbackForHrd(): bool
    {
        return true;
    }

    protected function waitingSpvMessage(string $action): string
    {
        // Jalur persetujuan & penolakan memang berbeda kalimatnya di domain cuti.
        return $action === 'Penolakan'
            ? 'Penolakan tahap pertama cuti/izin harus dilakukan oleh SPV/Atasan Langsung. HRD baru dapat memproses setelah SPV menyetujui.'
            : 'Pengajuan cuti/izin ini masih menunggu persetujuan Tahap 1 (SPV/Atasan Langsung). HRD baru dapat memproses setelah SPV menyetujui.';
    }

    protected function hrdRequiredMessage(string $action): string
    {
        return "{$action} tahap akhir cuti/izin hanya dapat dilakukan oleh HRD atau Admin (memerlukan wewenang Level 2: HRD).";
    }
}
