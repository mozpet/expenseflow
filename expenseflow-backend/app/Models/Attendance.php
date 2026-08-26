<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attendance extends Model
{
    protected $fillable = [
        'user_id',
        'company_id',
        'date',
        'check_in_time',
        'check_in_lat',
        'check_in_lng',
        'check_in_distance_meters',
        'check_in_type',
        'check_in_photo',
        'check_out_time',
        'check_out_lat',
        'check_out_lng',
        'check_out_type',
        'status',
        'work_minutes',
        'overtime_minutes',
        'is_holiday',
        'notes',
        'auto_checkout_at',
        'is_auto_checkout',

        // Snapshot pengaturan kantor saat check-in (lihat migration 2026_08_26)
        'snap_office_id',
        'snap_office_latitude',
        'snap_office_longitude',
        'snap_radius_meters',
        'snap_source',
        'snap_work_start_time',
        'snap_work_end_time',
        'snap_is_off',
        'snap_is_cross_day',
        'snap_overtime_enabled',
        'snap_min_overtime_minutes',
        'snap_early_leave_tolerance_minutes',
        'snap_reminder_minutes',
        'snap_grace_minutes',
    ];

    protected function casts(): array
    {
        return [
            'date'                     => 'date',
            'check_in_time'            => 'datetime',
            'check_out_time'           => 'datetime',
            'auto_checkout_at'         => 'datetime',
            'check_in_lat'             => 'decimal:8',
            'check_in_lng'             => 'decimal:8',
            'check_out_lat'            => 'decimal:8',
            'check_out_lng'            => 'decimal:8',
            'check_in_distance_meters' => 'integer',
            'work_minutes'             => 'integer',
            'overtime_minutes'         => 'integer',
            'is_holiday'               => 'boolean',
            'is_auto_checkout'         => 'boolean',
            'snap_office_latitude'     => 'decimal:8',
            'snap_office_longitude'    => 'decimal:11',
            'snap_radius_meters'       => 'integer',
            'snap_is_off'              => 'boolean',
            'snap_is_cross_day'        => 'boolean',
            'snap_overtime_enabled'    => 'boolean',
            'snap_min_overtime_minutes'          => 'integer',
            'snap_early_leave_tolerance_minutes' => 'integer',
            'snap_reminder_minutes'    => 'integer',
            'snap_grace_minutes'       => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function overtimeApproval()
    {
        return $this->hasOne(\App\Models\OvertimeApproval::class);
    }

    // ═══════════════════════════════════════════════════════════
    // SNAPSHOT PENGATURAN KANTOR (2026-08-26)
    //
    // captureSnapshot(): dipanggil checkIn() — menyusun kolom snap_* dari
    //   jadwal efektif & kantor acuan yang berlaku SAAT check-in.
    // snapshotSchedule(): membaca kembali snapshot sebagai array jadwal
    //   berbentuk sama dengan ShiftController::resolveSchedule() — dipakai
    //   checkOut()/checkStatus()/AutoCheckoutCommand agar perhitungan
    //   memakai aturan saat check-in, bukan setting yang diubah siang hari.
    //   Return NULL bila record tidak punya snapshot (baris lama) → caller
    //   jatuh ke perilaku lama (baca attendance_settings live).
    // ═══════════════════════════════════════════════════════════

    /**
     * Susun atribut snapshot dari jadwal efektif & kantor acuan saat check-in.
     *
     * @param array $schedule Hasil ShiftController::resolveSchedule() hari itu
     * @param string|null $jamPulang Jam pulang efektif (SUDAH termasuk fallback
     *        jam pulang kantor pada hari libur shift — logika yang sama dengan
     *        respons reminder_at/auto_checkout_at di checkIn()).
     * @param \App\Models\AttendanceSetting|null $office Kantor acuan (hasil radius
     *        check bila berjalan, selain itu kantor penempatan karyawan).
     */
    public function buildSnapshot(array $schedule, ?string $jamPulang, ?AttendanceSetting $office): array
    {
        return [
            'snap_office_id'                     => $office?->id,
            'snap_office_latitude'               => $office?->office_latitude,
            'snap_office_longitude'              => $office?->office_longitude,
            'snap_radius_meters'                 => $office?->radius_meters,
            'snap_source'                        => $schedule['source'] === 'shift' ? 'shift' : 'office',
            'snap_work_start_time'               => $schedule['work_start_time'],
            'snap_work_end_time'                 => $jamPulang,
            'snap_is_off'                        => (bool) $schedule['is_off'],
            'snap_is_cross_day'                  => (bool) $schedule['is_cross_day'],
            'snap_overtime_enabled'              => $office?->overtime_enabled,
            'snap_min_overtime_minutes'          => $office?->min_overtime_minutes,
            'snap_early_leave_tolerance_minutes' => $office?->early_leave_tolerance_minutes,
            'snap_reminder_minutes'              => $office?->checkout_reminder_minutes,
            'snap_grace_minutes'                 => $office?->auto_checkout_grace_minutes,
        ];
    }

    /**
     * Apakah record ini punya snapshot pengaturan?
     */
    public function hasSnapshot(): bool
    {
        return $this->snap_source !== null;
    }

    /**
     * Bangun ulang "jadwal" dari snapshot, berbentuk sama dengan
     * ShiftController::resolveSchedule() sehingga bisa dipakai bergantian
     * oleh semua perhitungan checkout/lembur/auto-checkout.
     * Kolom office berisi instance AttendanceSetting yang atributnya
     * SUDAH ditimpa nilai snapshot.
     */
    public function snapshotSchedule(): ?array
    {
        if (! $this->hasSnapshot()) {
            return null;
        }

        return [
            'source'          => $this->snap_source,
            'shift_id'        => null,
            'shift_name'      => null,
            'work_start_time' => $this->snap_work_start_time ? substr((string) $this->snap_work_start_time, 0, 5) : null,
            'work_end_time'   => $this->snap_work_end_time ? substr((string) $this->snap_work_end_time, 0, 5) : null,
            'is_off'          => (bool) $this->snap_is_off,
            'is_wfh'          => false,
            'is_field'        => false,
            'is_cross_day'    => (bool) $this->snap_is_cross_day,
            'office'          => $this->snapshotOffice(),
        ];
    }

    /**
     * Office "beku" dari snapshot: ambil baris AttendanceSetting asli bila masih ada
     * (untuk relasi/nama), lalu timpa atribut yang berdampak perhitungan dengan nilai
     * saat check-in. Bila baris sudah terhapus, buat instance baru dari snapshot saja.
     */
    public function snapshotOffice(): ?AttendanceSetting
    {
        if (! $this->hasSnapshot()) {
            return null;
        }

        $office = $this->snap_office_id
            ? AttendanceSetting::find($this->snap_office_id)
            : null;

        if (! $office) {
            $office = new AttendanceSetting();
            $office->id           = $this->snap_office_id;
            $office->office_name  = '(kantor saat check-in)';
        }

        if ($this->snap_office_latitude !== null) {
            $office->office_latitude = $this->snap_office_latitude;
        }
        if ($this->snap_office_longitude !== null) {
            $office->office_longitude = $this->snap_office_longitude;
        }
        if ($this->snap_radius_meters !== null) {
            $office->radius_meters = $this->snap_radius_meters;
        }
        if ($this->snap_overtime_enabled !== null) {
            $office->overtime_enabled = $this->snap_overtime_enabled;
        }
        if ($this->snap_min_overtime_minutes !== null) {
            $office->min_overtime_minutes = $this->snap_min_overtime_minutes;
        }
        if ($this->snap_early_leave_tolerance_minutes !== null) {
            $office->early_leave_tolerance_minutes = $this->snap_early_leave_tolerance_minutes;
        }
        if ($this->snap_reminder_minutes !== null) {
            $office->checkout_reminder_minutes = $this->snap_reminder_minutes;
        }
        if ($this->snap_grace_minutes !== null) {
            $office->auto_checkout_grace_minutes = $this->snap_grace_minutes;
        }

        return $office;
    }
}
