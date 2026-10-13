<?php

namespace App\Services\Attendance;

use App\Models\User;

/**
 * Gate otorisasi approval 2 tahap (SPV → HRD) — satu-satunya tempat aturan ini hidup.
 *
 * Sebelumnya logika ini tersalin 4× di AttendanceController (approveLeave,
 * rejectLeave, approveOvertime, rejectOvertime). Satu edit yang lupa
 * disinkronkan di salah satu salinan = lubang otorisasi approval, dan itu
 * sudah pernah terjadi (lihat OvertimeApprovalGate::allowCompanyWideFallbackForHrd()).
 *
 * Kontrak: kedua method mengembalikan NULL bila aktor berwenang, atau
 * array{message: string, code: string} yang siap dibalut response 403 oleh
 * controller. Gate ini tidak pernah mengembalikan response HTTP sendiri dan
 * tidak pernah menyentuh DB di luar relasi model yang sudah dimuat.
 */
abstract class ApprovalGate
{
    /** Konstanta modul izin: Role::MODULE_LEAVE atau Role::MODULE_OVERTIME. */
    abstract protected function module(): string;

    /** Kata benda domain untuk pesan error, mis. 'cuti/izin' atau 'lembur'. */
    abstract protected function subject(): string;

    /**
     * Bolehkah "fallback SPV perusahaan" (karyawan tanpa manager_id DAN tanpa
     * division_id) juga berlaku untuk HRD Level 2?
     *
     * Ini SATU-SATUNYA perbedaan aturan antara domain cuti dan lembur, dan
     * perbedaannya disengaja — jangan diseragamkan. Lihat override di
     * OvertimeApprovalGate.
     */
    abstract protected function allowCompanyWideFallbackForHrd(): bool;

    /** Pesan 403 saat HRD Level 2 mencoba memproses Tahap 1 yang belum disetujui SPV. */
    abstract protected function waitingSpvMessage(string $action): string;

    /**
     * Kesempatan domain untuk menormalkan kata benda tindakan sebelum masuk pesan.
     * Default: dipakai apa adanya ('Persetujuan'/'Penolakan').
     */
    protected function normalizeAction(string $action): string
    {
        return $action;
    }

    /** Pesan 403 saat aktor bukan HRD/Admin pada Tahap 2. */
    abstract protected function hrdRequiredMessage(string $action): string;

    /**
     * Tahap 1 — SPV/Atasan Langsung.
     *
     * @param  string  $action  Kata benda tindakan untuk pesan error: 'Persetujuan' atau 'Penolakan'.
     * @return array{message: string, code: string}|null  NULL = berwenang.
     */
    public function authorizeSpvStep(User $actor, ?User $targetUser, string $action): ?array
    {
        $action = $this->normalizeAction($action);

        $isAdminBypass   = $this->isAdminBypass($actor);
        $isHrdLevelOnly  = $this->isHrdLevelOnly($actor);
        $isDirectManager = $this->isDirectManager($actor, $targetUser);

        $hasSpvPermission      = $actor->hasPermission($this->module(), 'spv');
        $hasSupervisorPosition = $this->hasStructuralSpvAuthority($actor);

        // Override darurat Tahap 1 hanya untuk Admin & Super Admin.
        // HRD (Level 2) WAJIB menunggu SPV, kecuali ia sendiri SPV struktural karyawan tsb.
        $isSpvOrAbove = $isHrdLevelOnly
            ? ($isDirectManager || $hasSupervisorPosition)
            : ($isDirectManager
                || $hasSupervisorPosition
                || $hasSpvPermission
                || $actor->isSupervisor()
                || $isAdminBypass);

        if (! $isSpvOrAbove && $isHrdLevelOnly) {
            return [
                'message' => $this->waitingSpvMessage($action),
                'code'    => 'WAITING_SPV_APPROVAL',
            ];
        }

        if (! $isSpvOrAbove) {
            return [
                'message' => "{$action} tahap pertama {$this->subject()} harus dilakukan oleh SPV/Atasan (memerlukan wewenang Level 1: SPV).",
                'code'    => 'SPV_REQUIRED',
            ];
        }

        // Jika karyawan belum memiliki manager_id yang ditunjuk:
        // 1. Jika karyawan memiliki divisi, izinkan SPV dari divisi yang sama.
        // 2. Jika karyawan belum punya divisi DAN belum punya manager_id, izinkan
        //    SPV perusahaan — berlaku/tidaknya untuk HRD ditentukan per domain.
        $isSameDivisionSpv = false;
        if (! $targetUser?->manager_id) {
            if ($targetUser?->division_id) {
                $isSameDivisionSpv = ((int) $actor->division_id === (int) $targetUser->division_id);
            } elseif ($this->allowCompanyWideFallbackForHrd() || ! $isHrdLevelOnly) {
                $isSameDivisionSpv = true;
            }
        }

        if ($isDirectManager || $isSameDivisionSpv || $isAdminBypass) {
            return null;
        }

        if ($isHrdLevelOnly) {
            return [
                'message' => $this->waitingSpvMessage($action),
                'code'    => 'WAITING_SPV_APPROVAL',
            ];
        }

        if ($targetUser?->manager_id) {
            $managerName = $targetUser->manager?->name ?? 'Atasan Langsung';

            return [
                'message' => "{$action} tahap 1 {$this->subject()} harus dilakukan oleh Atasan Langsung ({$managerName}) dari divisi karyawan bersangkutan.",
                'code'    => 'DIRECT_SUPERVISOR_REQUIRED',
            ];
        }

        $divisionName = $targetUser?->division?->name ?? 'divisi terkait';

        return [
            'message' => "{$action} tahap 1 {$this->subject()} harus dilakukan oleh Atasan Langsung (SPV) dari {$divisionName}.",
            'code'    => 'SPV_DIVISION_REQUIRED',
        ];
    }

    /**
     * Tahap 2 — HRD final.
     *
     * Pencocokan peran di sini WAJIB eksak (in_array), bukan substring: 'hr'
     * cocok dengan 'HRGA Support' dan akan memberi wewenang tahap akhir ke
     * peran yang tidak punya izin modul ini sama sekali.
     *
     * @return array{message: string, code: string}|null  NULL = berwenang.
     */
    public function authorizeHrdStep(User $actor, string $action): ?array
    {
        $action = $this->normalizeAction($action);

        $isHrdOrAdmin = in_array($this->roleCode($actor), ['super_admin', 'admin', 'hrd'], true)
            || $actor->hasPermission($this->module(), 'hrd')
            || $actor->hasPermission($this->module(), 'manage');

        if ($isHrdOrAdmin) {
            return null;
        }

        return [
            'message' => $this->hrdRequiredMessage($action),
            'code'    => 'HRD_REQUIRED',
        ];
    }

    /**
     * Approver Level 2 (HRD) murni — role hrd atau custom role dengan izin
     * 'hrd'/'manage' pada modul ini. Admin & Super Admin TIDAK termasuk
     * (mereka tetap punya override darurat Tahap 1).
     */
    public function isHrdLevelOnly(User $actor): bool
    {
        if ($this->isAdminBypass($actor)) {
            return false;
        }

        return $this->roleCode($actor) === 'hrd'
            || $actor->hasPermission($this->module(), 'hrd')
            || $actor->hasPermission($this->module(), 'manage');
    }

    /** Wewenang SPV struktural (tanpa bypass role): jabatan supervisor atau punya bawahan langsung. */
    public function hasStructuralSpvAuthority(User $actor): bool
    {
        return ($actor->position && $actor->position->is_supervisor)
            || $actor->subordinates()->exists();
    }

    protected function isAdminBypass(User $actor): bool
    {
        return in_array($this->roleCode($actor), ['super_admin', 'admin'], true);
    }

    protected function isDirectManager(User $actor, ?User $targetUser): bool
    {
        return $targetUser
            && $targetUser->manager_id
            && (int) $targetUser->manager_id === (int) $actor->id;
    }

    protected function roleCode(User $actor): string
    {
        return strtolower($actor->roleRelation?->slug ?? $actor->role ?? '');
    }
}
