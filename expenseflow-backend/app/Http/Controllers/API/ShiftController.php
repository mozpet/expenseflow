<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\AttendanceSetting;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\User;
use App\Models\UserShift;
use App\Services\FcmService;
use App\Services\ShiftRestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ShiftController extends Controller
{
    // ─── Helper: catat aktivitas ke activity_logs ──────────────────
    private function logActivity(int $userId, ?int $companyId, string $action, string $description, ?string $entityType = null, ?int $entityId = null): void
    {
        DB::table('activity_logs')->insert([
            'company_id'  => $companyId,
            'user_id'     => $userId,
            'action'      => $action,
            'description' => $description,
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    // ─── Helper: kirim notifikasi ke karyawan (DB + FCM jika ada token) ──────────
    private function notifyEmployee(User $employee, string $type, string $message, ?int $entityId = null): void
    {
        DB::table('notifications')->insert([
            'id'              => Str::uuid()->toString(),
            'type'            => $type,
            'notifiable_type' => 'App\\Models\\User',
            'notifiable_id'   => $employee->id,
            'user_id'         => $employee->id,
            'data'            => json_encode(['message' => $message, 'shift_assignment_id' => $entityId]),
            'entity_type'     => 'user_shift',
            'entity_id'       => $entityId,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        // Push FCM bila karyawan punya token perangkat
        if ($employee->fcm_token) {
            app(FcmService::class)->send($employee->fcm_token, '📅 Perubahan Jadwal Kerja', $message, [
                'type' => $type,
            ]);
        }
    }

    // ─── Helper: simpan 7 jadwal harian sebuah shift dengan VERSIONING ─────
    //     Dipakai store() & update().
    //     - store()  : effective_date = hari ini (versi pertama).
    //     - update() : membuat VERSI BARU (baris baru) dengan effective_date yang
    //       ditentukan (misal besok / sesuai notice). Versi lama TIDAK dihapus —
    //       tetap dipakai untuk tanggal sebelum effective_date.
    private function syncSchedules(Shift $shift, array $schedules, string $effectiveDate): void
    {
        foreach ($schedules as $sch) {
            $isOff = (bool) ($sch['is_off'] ?? false);
            $isWfh = $isOff ? false : (bool) ($sch['is_wfh'] ?? false);
            $isField = ($isOff || ! $isWfh) ? false : (bool) ($sch['is_field'] ?? false);

            ShiftSchedule::updateOrCreate(
                [
                    'shift_id'       => $shift->id,
                    'day_of_week'    => $sch['day_of_week'],
                    'effective_date' => $effectiveDate,
                ],
                [
                    'work_start_time' => $isOff ? null : ($sch['work_start_time'] ?? null),
                    'work_end_time'   => $isOff ? null : ($sch['work_end_time'] ?? null),
                    'is_off'          => $isOff,
                    'is_wfh'          => $isWfh,
                    'is_field'        => $isField,
                    'is_cross_day'    => $sch['is_cross_day'] ?? false,
                ]
            );
        }
    }

    // ─── Helper: validasi cabang milik perusahaan aktor ──────────────
    //     Return AttendanceSetting bila valid, null bila tidak ditemukan/lintas perusahaan.
    private function resolveBranch(User $actor, ?int $branchId): ?AttendanceSetting
    {
        if ($branchId === null) {
            return null;
        }

        return AttendanceSetting::when(
            $actor->role !== 'super_admin',
            fn ($q) => $q->where('company_id', $actor->company_id)
        )->find($branchId);
    }

    // ─── Helper: cek apakah warna sudah dipakai template shift lain ─────────
    //     Warna bersifat unik PER KANTOR (attendance_setting_id), bukan per
    //     perusahaan. Dua kantor boleh memakai warna yang sama, TAPI dalam satu
    //     kantor tidak boleh ada dua shift dengan warna yang sama.
    //
    //     Aturan bentrok:
    //     - Shift cabang X  → bentrok dengan shift cabang X + shift company-wide
    //                         (karena company-wide berlaku di semua kantor).
    //     - Shift company-wide (attendance_setting_id null) → bentrok dengan
    //                         SEMUA shift (tampil di setiap kantor).
    //
    //     Perbandingan warna case-insensitive (#E53E3E == #e53e3e).
    //
    //     Return Shift pemilik warna bila sudah dipakai, null bila warna tersedia.
    private function colorAlreadyUsed(User $actor, string $color, ?int $exceptShiftId = null, ?int $attendanceSettingId = null): ?Shift
    {
        $colorLower = strtolower($color);

        $query = Shift::query()
            ->when(
                $actor->role !== 'super_admin',
                fn ($q) => $q->where('company_id', $actor->company_id)
            )
            ->when(
                $exceptShiftId !== null,
                fn ($q) => $q->where('id', '!=', $exceptShiftId)
            )
            ->whereNotNull('color')
            ->whereRaw('LOWER(color) = ?', [$colorLower]);

        // Shift company-wide → bentrok dengan semua shift lain
        if ($attendanceSettingId === null) {
            return $query->first();
        }

        // Shift cabang → bentrok dengan shift cabang yang sama + shift company-wide
        return $query
            ->where(function ($q) use ($attendanceSettingId) {
                $q->where('attendance_setting_id', $attendanceSettingId)
                    ->orWhereNull('attendance_setting_id');
            })
            ->first();
    }

    // ═══════════════════════════════════════════════════════════
    // 1. index() — daftar template shift perusahaan
    //    GET /api/v1/dashboard/attendance/shifts
    // ═══════════════════════════════════════════════════════════
    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();

        $validated = $request->validate([
            'is_active'             => 'nullable|boolean',
            'attendance_setting_id' => 'nullable|integer', // filter per cabang
        ]);

        $shifts = Shift::with(['schedules', 'office:id,office_name'])
            ->when(
                $actor->role !== 'super_admin',
                fn ($q) => $q->where('company_id', $actor->company_id)
            )
            ->when(
                isset($validated['is_active']),
                fn ($q) => $q->where('is_active', $validated['is_active'])
            )
            ->when(
                isset($validated['attendance_setting_id']),
                fn ($q) => $q->where('attendance_setting_id', $validated['attendance_setting_id'])
            )
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $shifts]);
    }

    // ═══════════════════════════════════════════════════════════
    // 2. store() — buat template shift baru + detail 7 hari
    //    POST /api/v1/dashboard/attendance/shifts
    //
    //    Body contoh:
    //    {
    //      "name": "Shift Sabtu",
    //      "description": "Masuk Sabtu 09:00–15:00",
    //      "schedules": [
    //        {"day_of_week": 0, "is_off": true},
    //        {"day_of_week": 1, "is_off": false, "work_start_time": "08:00", "work_end_time": "17:00"},
    //        ...
    //        {"day_of_week": 6, "is_off": false, "work_start_time": "09:00", "work_end_time": "15:00"}
    //      ]
    //    }
    // ═══════════════════════════════════════════════════════════
    public function store(Request $request): JsonResponse
    {
        $actor = $request->user();

        $validated = $request->validate([
            'name'                         => 'required|string|max:100',
            'description'                  => 'nullable|string|max:500',
            'color'                        => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
            // Cabang pemilik shift (wajib). Null hanya boleh untuk super_admin (company-wide).
            'attendance_setting_id'        => 'required|integer',
            'schedules'                    => 'required|array|size:7',
            'schedules.*.day_of_week'      => 'required|integer|between:0,6',
            'schedules.*.work_start_time'  => 'nullable|date_format:H:i',
            'schedules.*.work_end_time'    => 'nullable|date_format:H:i',
            'schedules.*.is_off'           => 'required|boolean',
            'schedules.*.is_wfh'           => 'sometimes|boolean',
            'schedules.*.is_field'         => 'sometimes|boolean',
            'schedules.*.is_cross_day'     => 'sometimes|boolean',
            'is_active'                    => 'sometimes|boolean',
        ]);

        // Validasi 7 hari unik + jam kerja terisi untuk hari non-libur + jeda K3
        $validation = $this->validateSchedules($validated['schedules']);
        if ($validation['error']) {
            return response()->json(['message' => $validation['error']], 422);
        }
        $k3Warnings = $validation['warnings'];

        // Pastikan cabang milik perusahaan aktor
        $branch = $this->resolveBranch($actor, $validated['attendance_setting_id']);
        if (! $branch) {
            return response()->json(['message' => 'Cabang tidak ditemukan di perusahaan Anda.'], 404);
        }

        // Warna unik PER KANTOR — shift di kantor yang sama (atau company-wide)
        // tidak boleh memakai warna yang sama. Dua kantor berbeda boleh sama.
        if (! empty($validated['color'])) {
            $owner = $this->colorAlreadyUsed($actor, $validated['color'], null, $branch->id);
            if ($owner) {
                $scope = $owner->attendance_setting_id === null
                    ? ' (berlaku semua kantor)'
                    : ' (kantor yang sama)';
                return response()->json([
                    'message' => "Warna {$validated['color']} sudah dipakai oleh shift '{$owner->name}'{$scope}. Pilih warna yang berbeda dalam kantor ini.",
                ], 422);
            }
        }

        // P0 #1 — validasi batas jam kerja per minggu (opsional, toggle per kantor)
        $weeklyCheck = $this->validateWeeklyHours($validated['schedules'], $branch);
        if ($weeklyCheck['error']) {
            return response()->json(['message' => $weeklyCheck['error']], 422);
        }
        $k3Warnings = array_merge($k3Warnings, $weeklyCheck['warnings']);

        $shift = DB::transaction(function () use ($validated, $actor, $branch) {
            $shift = Shift::create([
                'company_id'            => $actor->company_id,
                'attendance_setting_id' => $branch->id,
                'name'                  => $validated['name'],
                'description'           => $validated['description'] ?? null,
                'is_active'             => $validated['is_active'] ?? true,
                'color'                 => isset($validated['color']) ? strtolower($validated['color']) : null,
            ]);

            // Versi pertama: berlaku sejak hari ini
            $this->syncSchedules($shift, $validated['schedules'], now('Asia/Jakarta')->toDateString());

            return $shift;
        });

        $this->logActivity(
            $actor->id,
            $actor->company_id,
            'shift_created',
            "Membuat template shift: {$shift->name} (cabang {$branch->office_name})",
            'shift',
            $shift->id
        );

        return response()->json([
            'message'  => 'Shift berhasil dibuat.',
            'warnings' => $k3Warnings,
            'data'     => $shift->load(['schedules', 'office:id,office_name']),
        ], 201);
    }

    // ─── Helper: validasi array 7 jadwal harian ──────────────────────────
    //     Return ['error' => string|null, 'warnings' => string[]].
    //     Otomatis set is_cross_day=true jika jam pulang <= jam masuk (shift lintas tengah malam).
    //     Setelah validasi struktural, cek jeda istirahat K3 antar hari berurutan.
    private function validateSchedules(array &$schedules): array
    {
        // Harus tepat 7 hari unik (0–6)
        $hari = collect($schedules)->pluck('day_of_week')->sort()->values()->toArray();
        if ($hari !== [0, 1, 2, 3, 4, 5, 6]) {
            return ['error' => 'Jadwal harus mencakup tepat 7 hari unik (0=Minggu hingga 6=Sabtu).', 'warnings' => []];
        }

        // P0 #2 — min 1 hari libur per minggu (UU No. 13/2003 Pasal 79)
        $hariKerja = count(array_filter($schedules, fn ($s) => empty($s['is_off'])));
        if ($hariKerja > 6) {
            return ['error' => 'Template shift tidak boleh memiliki 7 hari kerja penuh. Karyawan wajib mendapat minimal 1 hari libur per minggu (UU No. 13/2003 Pasal 79).', 'warnings' => []];
        }

        foreach ($schedules as &$sch) {
            if (! empty($sch['is_off'])) {
                $sch['is_cross_day'] = false;
                continue;
            }

            if (empty($sch['work_start_time']) || empty($sch['work_end_time'])) {
                return ['error' => "Hari kerja (day_of_week {$sch['day_of_week']}) wajib mengisi jam masuk & jam pulang.", 'warnings' => []];
            }

            // Otomatis deteksi shift lintas tengah malam
            if ($sch['work_end_time'] <= $sch['work_start_time']) {
                $sch['is_cross_day'] = true;
            } else {
                $sch['is_cross_day'] = $sch['is_cross_day'] ?? false;
            }
        }
        unset($sch);

        // Validasi jeda istirahat K3 antar hari berurutan dalam template
        $k3 = app(ShiftRestService::class)->validateTemplateGaps($schedules);
        if (! empty($k3['errors'])) {
            return ['error' => implode(' ', $k3['errors']), 'warnings' => []];
        }

        return ['error' => null, 'warnings' => $k3['warnings']];
    }

    // ═══════════════════════════════════════════════════════════
    // 2b. update() — ubah template shift + jadwal harian
    //     PUT/PATCH /api/v1/dashboard/attendance/shifts/{id}
    // ═══════════════════════════════════════════════════════════
    public function update(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();

        $shift = Shift::when(
            $actor->role !== 'super_admin',
            fn ($q) => $q->where('company_id', $actor->company_id)
        )->find($id);

        if (! $shift) {
            return response()->json(['message' => 'Shift tidak ditemukan di perusahaan Anda.'], 404);
        }

        $validated = $request->validate([
            'name'                         => 'sometimes|required|string|max:100',
            'description'                  => 'nullable|string|max:500',
            'color'                        => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'attendance_setting_id'        => 'sometimes|required|integer',
            'schedules'                    => 'sometimes|required|array|size:7',
            'schedules.*.day_of_week'      => 'required_with:schedules|integer|between:0,6',
            'schedules.*.work_start_time'  => 'nullable|date_format:H:i',
            'schedules.*.work_end_time'    => 'nullable|date_format:H:i',
            'schedules.*.is_off'           => 'required_with:schedules|boolean',
            'schedules.*.is_wfh'           => 'sometimes|boolean',
            'schedules.*.is_field'         => 'sometimes|boolean',
            'schedules.*.is_cross_day'     => 'sometimes|boolean',
        ]);

        // Validasi jadwal jika dikirim
        $k3Warnings = [];
        if (isset($validated['schedules'])) {
            $validation = $this->validateSchedules($validated['schedules']);
            if ($validation['error']) {
                return response()->json(['message' => $validation['error']], 422);
            }
            $k3Warnings = $validation['warnings'];
        }

        // Cabang kantor template shift bersifat permanen (hanya ditentukan saat store).
        if (array_key_exists('attendance_setting_id', $validated)
            && (int) $validated['attendance_setting_id'] !== (int) $shift->attendance_setting_id
        ) {
            return response()->json([
                'message' => 'Cabang kantor template shift bersifat permanen dan tidak dapat diubah setelah shift dibuat. Buat template shift baru jika ingin membuat shift untuk cabang lain.',
            ], 422);
        }

        $effectiveBranchId = $shift->attendance_setting_id; // cabang permanen shift

        // Warna unik PER KANTOR. Shift itu sendiri dikecualikan (exceptShiftId).
        // Shift company-wide (null) bentrok dengan semua; shift cabang bentrok
        // dengan cabang yang sama + shift company-wide.
        if (! empty($validated['color'])) {
            $owner = $this->colorAlreadyUsed($actor, $validated['color'], $shift->id, $effectiveBranchId);
            if ($owner) {
                $scope = $owner->attendance_setting_id === null
                    ? ' (berlaku semua kantor)'
                    : ' (kantor yang sama)';
                return response()->json([
                    'message' => "Warna {$validated['color']} sudah dipakai oleh shift '{$owner->name}'{$scope}. Pilih warna yang berbeda dalam kantor ini.",
                ], 422);
            }
        }

        // P0 #1 — validasi batas jam kerja per minggu jika jadwal dikirim
        if (isset($validated['schedules'])) {
            $effectiveBranch = $shift->office
                ?? AttendanceSetting::find($shift->attendance_setting_id);
            if ($effectiveBranch) {
                $weeklyCheck = $this->validateWeeklyHours($validated['schedules'], $effectiveBranch);
                if ($weeklyCheck['error']) {
                    return response()->json(['message' => $weeklyCheck['error']], 422);
                }
                $k3Warnings = array_merge($k3Warnings, $weeklyCheck['warnings']);
            }
        }

        // ── VERSIONING JAM KERJA ────────────────────────────────────────────
        // Periksa apakah isi jadwal (schedules) benar-benar BERUBAH vs jadwal saat ini.
        // Jika HANYA nama, warna, atau deskripsi yang diubah (jadwal tidak berubah),
        // update dilakukan LANGSUNG tanpa membuat versi baru & tanpa minimum notice.
        $hasScheduleChanges = false;
        $scheduleEffectiveDate = null;
        $liveAssignments = collect();

        if (isset($validated['schedules'])) {
            $todayStr = now('Asia/Jakarta')->toDateString();

            foreach ($validated['schedules'] as $schInput) {
                $day  = (int) $schInput['day_of_week'];
                $curr = self::scheduleForDate($shift->id, $day, $todayStr);

                $newOff   = (bool) ($schInput['is_off'] ?? false);
                $newWfh   = $newOff ? false : (bool) ($schInput['is_wfh'] ?? false);
                $newField = ($newOff || ! $newWfh) ? false : (bool) ($schInput['is_field'] ?? false);
                $newStart = $newOff ? null : ($schInput['work_start_time'] ?? null);
                $newEnd   = $newOff ? null : ($schInput['work_end_time'] ?? null);

                $currOff   = $curr ? (bool) $curr->is_off : true;
                $currWfh   = $curr ? (bool) $curr->is_wfh : false;
                $currField = $curr ? (bool) $curr->is_field : false;
                // Normalisasi HH:MM untuk perbandingan aman
                $currStart = ($curr && ! $currOff && $curr->work_start_time) ? substr($curr->work_start_time, 0, 5) : null;
                $currEnd   = ($curr && ! $currOff && $curr->work_end_time)   ? substr($curr->work_end_time, 0, 5)   : null;
                $newStartNorm = $newStart ? substr($newStart, 0, 5) : null;
                $newEndNorm   = $newEnd   ? substr($newEnd, 0, 5)   : null;

                if (
                    $newOff !== $currOff ||
                    $newWfh !== $currWfh ||
                    $newField !== $currField ||
                    $newStartNorm !== $currStart ||
                    $newEndNorm !== $currEnd
                ) {
                    $hasScheduleChanges = true;
                    break;
                }
            }

            if ($hasScheduleChanges) {
                $branchForNotice = $shift->office
                    ?? AttendanceSetting::find($shift->attendance_setting_id)
                    ?? AttendanceSetting::where('company_id', $actor->company_id)->orderBy('id')->first();

                $noticeDays = (int) ($branchForNotice?->shift_notice_days ?? 0);
                if ($noticeDays < 1) {
                    $noticeDays = 1; // default aman: berlaku besok
                }

                $scheduleEffectiveDate = Carbon::now('Asia/Jakarta')->startOfDay()
                    ->addDays($noticeDays)
                    ->toDateString();

                // Pre-load assignment aktif SEKALI — dipakai untuk count + notifikasi sekaligus
                $liveAssignments = $this->liveAssignmentsForShift($shift->id);
            }
        }

        DB::transaction(function () use ($shift, $validated, $scheduleEffectiveDate, $hasScheduleChanges) {
            // Nama/deskripsi/warna → langsung (tidak memengaruhi jadwal)
            $data = collect($validated)->only(['name', 'description', 'color'])->toArray();
            if (isset($data['color'])) {
                $data['color'] = strtolower($data['color']);
            }
            $shift->fill($data);
            $shift->save();

            // Jam kerja (schedules) → versi baru HANYA jika jadwal benar-benar berubah
            if (isset($validated['schedules']) && $hasScheduleChanges && $scheduleEffectiveDate) {
                $this->syncSchedules($shift, $validated['schedules'], $scheduleEffectiveDate);
            }
        });

        // Kirim notifikasi ke karyawan yang ter-assign shift ini (DB + FCM)
        // bahwa jam kerja shift akan berubah mulai tanggal efektif.
        // Menggunakan $liveAssignments yang sudah di-load di atas (hindari double query).
        if ($scheduleEffectiveDate) {
            $tglEfektif = Carbon::parse($scheduleEffectiveDate)->translatedFormat('d F Y');

            foreach ($liveAssignments as $assignment) {
                if ($assignment->user) {
                    $this->notifyEmployee(
                        $assignment->user,
                        'shift_schedule_changed',
                        "Jam kerja shift '{$shift->name}' diperbarui HRD dan akan berlaku mulai {$tglEfektif}.",
                        $shift->id
                    );
                }
            }
        }

        $this->logActivity(
            $actor->id,
            $actor->company_id,
            'shift_updated',
            "Mengubah template shift: {$shift->name}" . ($scheduleEffectiveDate ? " (jam kerja baru efektif {$scheduleEffectiveDate})" : ''),
            'shift',
            $shift->id
        );

        return response()->json([
            'message'  => $scheduleEffectiveDate
                ? "Shift berhasil diperbarui. Jam kerja baru berlaku mulai " . Carbon::parse($scheduleEffectiveDate)->translatedFormat('d F Y') . "."
                : 'Shift berhasil diperbarui.',
            'warnings'       => $k3Warnings,
            'effective_date' => $scheduleEffectiveDate,
            'notified_users' => $liveAssignments->count(),
            'data'           => $shift->refresh()->load(['schedules', 'office:id,office_name']),
        ]);
    }

    // ═══════════════════════════════════════════════════════════
    // 2c. destroy() — hapus template shift
    //     DELETE /api/v1/dashboard/attendance/shifts/{id}
    //
    //     DIBLOKIR jika masih ada karyawan yang menggunakan shift ini
    //     (assignment yang masih aktif/sebelumnya). HRD harus terlebih dahulu:
    //       1) memindahkan karyawan ke shift lain, atau
    //       2) menghapus/ mengakhiri assignment karyawan dari shift ini.
    //     Setelah tidak ada assignment tersisa, shift baru bisa dihapus.
    // ═══════════════════════════════════════════════════════════
    public function destroy(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();

        $shift = Shift::when(
            $actor->role !== 'super_admin',
            fn ($q) => $q->where('company_id', $actor->company_id)
        )->find($id);

        if (! $shift) {
            return response()->json(['message' => 'Shift tidak ditemukan di perusahaan Anda.'], 404);
        }

        // Assignment yang MASIH BERLAKU (aktif hari ini atau akan datang).
        // Assignment yang sudah berakhir (end_date < hari ini) tidak memblokir hapus.
        $liveAssignments = $this->liveAssignmentsForShift($shift->id);

        if ($liveAssignments->isNotEmpty()) {
            $names = $liveAssignments->take(5)->map(fn ($a) => $a->user->name ?? 'Karyawan')->join(', ');
            $more  = $liveAssignments->count() > 5 ? ', dan lainnya.' : '.';

            return response()->json([
                'message'      => "Shift tidak bisa dihapus karena masih digunakan oleh " . $liveAssignments->count() . " karyawan. Pindahkan karyawan ke shift lain atau hapus assignment karyawan dari shift ini terlebih dahulu.",
                'affected'     => $liveAssignments->map(fn ($a) => [
                    'user_id'   => $a->user_id,
                    'user_name' => $a->user->name ?? 'Karyawan',
                    'start_date' => $a->start_date->toDateString(),
                    'end_date'   => $a->end_date?->toDateString(),
                ])->values(),
                'affected_names' => $names . $more,
            ], 409);
        }

        $nama = $shift->name;
        $shift->delete(); // shift_schedules ikut terhapus (cascade)

        $this->logActivity(
            $actor->id,
            $actor->company_id,
            'shift_deleted',
            "Menghapus template shift: {$nama}",
            'shift',
            $id
        );

        return response()->json(['message' => "Shift '{$nama}' berhasil dihapus."]);
    }

    // ═══════════════════════════════════════════════════════════
    // 2d. toggleActive() — aktifkan / nonaktifkan template shift
    //     POST /api/v1/dashboard/attendance/shifts/{id}/toggle-active
    //
    //     PERUBAHAN (2026-08-08):
    //     Menonaktifkan shift DIBLOKIR selama masih ada karyawan yang
    //     menggunakan shift ini (assignment aktif/sebelumnya). HRD harus
    //     memindahkan karyawan ke shift lain atau mengakhiri assignment-nya
    //     terlebih dahulu sebelum shift bisa dinonaktifkan.
    //     Hal ini mencegah karyawan "tiba-tiba" jatuh ke jadwal default
    //     tanpa sepengetahuan HRD.
    // ═══════════════════════════════════════════════════════════
    public function toggleActive(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();

        $shift = Shift::when(
            $actor->role !== 'super_admin',
            fn ($q) => $q->where('company_id', $actor->company_id)
        )->find($id);

        if (! $shift) {
            return response()->json(['message' => 'Shift tidak ditemukan di perusahaan Anda.'], 404);
        }

        $willBeActive = ! $shift->is_active;

        // Saat menonaktifkan: blokir jika masih ada assignment yang berlaku
        // (aktif hari ini atau akan datang) untuk shift ini.
        if (! $willBeActive) {
            $liveAssignments = $this->liveAssignmentsForShift($shift->id);

            if ($liveAssignments->isNotEmpty()) {
                $names = $liveAssignments->take(5)->map(fn ($a) => $a->user->name ?? 'Karyawan')->join(', ');
                $more  = $liveAssignments->count() > 5 ? ', dan lainnya.' : '.';

                return response()->json([
                    'message'      => "Shift tidak bisa dinonaktifkan karena masih digunakan oleh " . $liveAssignments->count() . " karyawan. Pindahkan karyawan ke shift lain atau hapus assignment karyawan dari shift ini terlebih dahulu.",
                    'affected'     => $liveAssignments->map(fn ($a) => [
                        'user_id'   => $a->user_id,
                        'user_name' => $a->user->name ?? 'Karyawan',
                        'start_date' => $a->start_date->toDateString(),
                        'end_date'   => $a->end_date?->toDateString(),
                    ])->values(),
                    'affected_names' => $names . $more,
                ], 409);
            }
        }

        $shift->is_active = $willBeActive;
        $shift->save();

        $status = $shift->is_active ? 'diaktifkan' : 'dinonaktifkan';

        $this->logActivity(
            $actor->id,
            $actor->company_id,
            'shift_toggled',
            "Template shift {$status}: {$shift->name}",
            'shift',
            $shift->id
        );

        // Jika shift DINONAKTIFKAN, kirim notifikasi ke karyawan yang assignment-nya
        // masih aktif agar mereka tahu jadwalnya sementara kembali ke default kantor.
        // Data assignment (user_shifts) TIDAK dihapus — shift bisa diaktifkan kembali kapan saja.
        if (! $willBeActive) {
            $today = now('Asia/Jakarta')->toDateString();

            $terdampak = UserShift::with('user')
                ->where('shift_id', $shift->id)
                ->where('start_date', '<=', $today)
                ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $today))
                ->get();

            foreach ($terdampak as $assignment) {
                if ($assignment->user) {
                    $this->notifyEmployee(
                        $assignment->user,
                        'shift_deactivated',
                        "Shift '{$shift->name}' dinonaktifkan oleh HRD. Jadwal kerja Anda sementara kembali ke jam kantor default.",
                        $assignment->id
                    );
                }
            }
        }

        return response()->json([
            'message' => "Shift '{$shift->name}' berhasil {$status}.",
            'data'    => $shift->load(['schedules', 'office:id,office_name']),
        ]);
    }

    // ─── Helper: assignment yang MASIH BERLAKU untuk sebuah shift ─────────────
    //     "Masih berlaku" = start_date <= hari ini (aktif) ATAU start_date > hari ini
    //     (akan datang), DAN (end_date null ATAU end_date >= hari ini).
    //     Assignment yang sudah berakhir (end_date < hari ini) TIDAK termasuk,
    //     sehingga tidak memblokir hapus/nonaktifkan shift.
    private function liveAssignmentsForShift(int $shiftId)
    {
        $today = now('Asia/Jakarta')->toDateString();

        return UserShift::with('user:id,name')
            ->where('shift_id', $shiftId)
            ->where(function ($q) use ($today) {
                $q->where('start_date', '<=', $today)
                    ->where(fn ($q2) => $q2->whereNull('end_date')->orWhere('end_date', '>=', $today))
                    ->orWhere('start_date', '>', $today);
            })
            ->orderBy('start_date')
            ->get();
    }

    // ═══════════════════════════════════════════════════════════
    // 3. shiftHistory() — riwayat shift assignment seorang karyawan
    //    GET /api/v1/dashboard/attendance/users/{id}/shift-history
    // ═══════════════════════════════════════════════════════════
    public function shiftHistory(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();

        $user = User::when(
            $actor->role !== 'super_admin',
            fn ($q) => $q->where('company_id', $actor->company_id)
        )->find($id);

        if (! $user) {
            return response()->json(['message' => 'Karyawan tidak ditemukan.'], 404);
        }

        $history = UserShift::with('shift.schedules')
            ->where('user_id', $id)
            ->orderByDesc('start_date')
            ->paginate(20);

        // Tambahkan status setiap assignment berdasarkan tanggal hari ini:
        //   active   → shift sedang berlaku hari ini (start_date <= hari ini,
        //               dan (end_date null ATAU end_date >= hari ini))
        //   upcoming → shift belum dimulai (start_date > hari ini)
        //   expired  → shift sudah berakhir (end_date < hari ini)
        // Ini dipakai frontend untuk menampilkan badge status pada tiap assignment
        // dan agar HRD tahu bahwa assignment yang aktif adalah yang sedang berlaku.
        $today = now('Asia/Jakarta')->toDateString();

        $history->getCollection()->transform(function (UserShift $us) use ($today) {
            $startDate = $us->start_date->toDateString();
            $endDate   = $us->end_date?->toDateString();

            if ($startDate <= $today && ($endDate === null || $endDate >= $today)) {
                $status = 'active';
            } elseif ($startDate > $today) {
                $status = 'upcoming';
            } else {
                $status = 'expired';
            }

            $us->setAttribute('status', $status);

            return $us;
        });

        return response()->json($history);
    }

    // ─── Daftar karyawan yang terkait dengan sebuah shift ─────────────
    //     GET /api/v1/dashboard/attendance/shifts/{id}/users
    //
    //     Menampilkan semua assignment yang memakai shift ini (masa lalu, aktif,
    //     dan mendatang) beserta statusnya. Dipakai UI template shift (web) agar
    //     HRD bisa melihat siapa saja yang terpasang pada suatu shift.
    public function shiftUsers(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();

        $shift = Shift::when(
            $actor->role !== 'super_admin',
            fn ($q) => $q->where('company_id', $actor->company_id)
        )->find($id);

        if (! $shift) {
            return response()->json(['message' => 'Shift tidak ditemukan.'], 404);
        }

        $today = now('Asia/Jakarta')->toDateString();

        $assignments = UserShift::with(['user:id,name,department,attendance_setting_id', 'user.office:id,office_name', 'shift:id,name'])
            ->where('shift_id', $id)
            ->orderBy('start_date')
            ->get();

        $rows = $assignments->map(function (UserShift $us) use ($today) {
            $user = $us->user;

            // null guard — assignment dengan user terhapus (soft delete)
            if (! $user) {
                return null;
            }

            $startDate = $us->start_date->toDateString();
            $endDate   = $us->end_date?->toDateString();

            if ($startDate <= $today && ($endDate === null || $endDate >= $today)) {
                $status = 'active';
            } elseif ($startDate > $today) {
                $status = 'upcoming';
            } else {
                $status = 'expired';
            }

            return [
                'user_id'               => $user->id,
                'name'                  => $user->name,
                'department'            => $user->department,
                'branch'                => optional($user->office)->office_name,
                'assignment_id'         => $us->id,
                'status'                => $status,
                'start_date'            => $startDate,
                'end_date'              => $endDate,
            ];
        })->filter()->values();

        return response()->json([
            'shift_id'      => (int) $shift->id,
            'shift_name'    => $shift->name,
            'is_active'     => (bool) $shift->is_active,
            'total'         => $rows->count(),
            'active_count'  => $rows->where('status', 'active')->count(),
            'data'          => $rows,
        ]);
    }

    // ═══════════════════════════════════════════════════════════
    // 4. assignShift() — assign shift ke karyawan (atau hapus shift → default kantor)
    //    POST /api/v1/dashboard/attendance/assign-shift
    //
    //    Body:
    //    {
    //      "user_id": 5,
    //      "shift_id": 3,        ← null = hapus shift (kembali ke default kantor)
    //      "start_date": "2026-07-06",
    //      "notes": "Jadwal sabtu untuk proyek X"
    //    }
    // ═══════════════════════════════════════════════════════════
    public function assignShift(Request $request): JsonResponse
    {
        $actor = $request->user();

        $validated = $request->validate([
            'user_id'    => 'required|integer|exists:users,id',
            'shift_id'   => 'nullable|integer|exists:shifts,id',
            'start_date' => 'required|date',
            // end_date opsional — tanggal karyawan otomatis kembali ke default kantor.
            // Harus > start_date (minimal H+1). Null = shift berlaku tanpa batas.
            'end_date'   => 'nullable|date|after:start_date',
            'notes'      => 'nullable|string|max:500',
        ]);

        // Pastikan karyawan milik perusahaan aktor
        $targetUser = User::when(
            $actor->role !== 'super_admin',
            fn ($q) => $q->where('company_id', $actor->company_id)
        )->find($validated['user_id']);

        if (! $targetUser) {
            return response()->json(['message' => 'Karyawan tidak ditemukan di perusahaan Anda.'], 404);
        }

        // Jika shift_id diisi, pastikan shift milik perusahaan yang sama
        // DAN cabangnya cocok dengan cabang karyawan (cegah salah assign lintas cabang).
        $shift = null;
        if (! empty($validated['shift_id'])) {
            $shift = Shift::when(
                $actor->role !== 'super_admin',
                fn ($q) => $q->where('company_id', $actor->company_id)
            )->find($validated['shift_id']);

            if (! $shift) {
                return response()->json(['message' => 'Shift tidak ditemukan di perusahaan Anda.'], 404);
            }

            // Cegah assign ke shift yang sudah dinonaktifkan
            if (! $shift->is_active) {
                return response()->json(['message' => "Shift '{$shift->name}' sudah dinonaktifkan. Aktifkan kembali terlebih dahulu atau pilih shift lain."], 422);
            }

            if ($err = $this->assertBranchMatch($shift, $targetUser)) {
                return response()->json(['message' => $err], 422);
            }

            // Cegah assign shift yang sedang AKTIF atau SEGERA (belum mulai) untuk
            // karyawan yang sama (duplikat) — akhiri/hapus assignment dulu.
            $todayStr = now('Asia/Jakarta')->toDateString();
            $duplicateActive = UserShift::where('user_id', $validated['user_id'])
                ->where('shift_id', $validated['shift_id'])
                ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $todayStr))
                ->exists();

            if ($duplicateActive) {
                return response()->json([
                    'message' => "Karyawan ini sudah memiliki assignment aktif untuk shift '{$shift->name}'. Akhiri assignment yang sedang berjalan terlebih dahulu sebelum menetapkan shift yang sama.",
                ], 422);
            }
        }

        // Cegah duplikat start_date untuk karyawan yang sama
        $existing = UserShift::where('user_id', $validated['user_id'])
            ->where('start_date', $validated['start_date'])
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'Sudah ada assignment shift dengan tanggal mulai yang sama untuk karyawan ini. Ubah assignment tersebut atau gunakan tanggal mulai yang berbeda.',
            ], 422);
        }

        // Validasi jeda istirahat K3 antara shift lama dan shift baru
        $k3 = $this->checkAssignRestGap($targetUser, $shift, $validated['start_date']);
        if ($k3['error']) {
            return response()->json([
                'message'        => $k3['error'],
                'prev_end_at'    => $k3['prev_end_at'],
                'new_start_at'   => $k3['new_start_at'],
                'earliest_start' => $k3['earliest_start'],
            ], 422);
        }

        // Guard minimum H+1: start_date tidak boleh hari ini atau mundur.
        // Ini berlaku terlepas dari shift_notice_days, dan mencegah bug pada destroyAssignment:
        // jika assignment dibuat hari ini lalu langsung dihapus hari ini, maka
        // end_date = start_date = hari ini → assignment masih aktif hari itu.
        $todayForGuard = Carbon::now('Asia/Jakarta')->toDateString();
        if ($validated['start_date'] <= $todayForGuard) {
            $minStart = Carbon::tomorrow('Asia/Jakarta')->translatedFormat('d F Y');
            return response()->json([
                'message' => "Tanggal mulai shift harus minimal besok ({$minStart}). Tidak bisa menetapkan shift berlaku hari ini atau tanggal yang sudah lewat.",
            ], 422);
        }

        // Validasi minimum notice period (HARD ERROR: blokir jika kurang dari N hari notice)
        if ($noticeErr = $this->checkNoticeError($targetUser, $validated['start_date'])) {
            return response()->json(['message' => $noticeErr], 422);
        }

        // CEGAH BUG: jika assignment baru mulai HARI INI (atau berlaku hari ini),
        // pastikan karyawan tidak sedang dalam jam kerja shift yang akan digantikan.
        // Ini mencegah jadwal hari ini berubah di tengah jam kerja.
        $newStartDate = $validated['start_date'];
        $newEndDate   = $validated['end_date'] ?? null;
        $todayStr     = now('Asia/Jakarta')->toDateString();

        $coversToday = $newStartDate <= $todayStr
            && ($newEndDate === null || $newEndDate >= $todayStr);

        if ($coversToday) {
            // Assignment shift lama yang sedang aktif hari ini
            $activeOld = UserShift::with('shift')
                ->where('user_id', $targetUser->id)
                ->when(
                    ! empty($validated['shift_id']),
                    fn ($q) => $q->where('shift_id', '!=', $validated['shift_id'])
                )
                ->where('start_date', '<=', $todayStr)
                ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $todayStr))
                ->orderByDesc('start_date')
                ->first();

            // Khusus: jika start_date = hari ini, assignment baru akan menggantikan
            // assignment lama yang aktif — cek jam kerja assignment lama.
            if ($activeOld && $activeOld->shift_id) {
                $blocked = $this->checkWithinWorkingHours($activeOld);
                if ($blocked) {
                    return response()->json([
                        'message' => "Tidak bisa mengubah shift karyawan sekarang. " . $blocked,
                    ], 422);
                }
            }
        }

        $userShift = UserShift::create([
            'user_id'    => $validated['user_id'],
            'shift_id'   => $validated['shift_id'],
            'start_date' => $validated['start_date'],
            'end_date'   => $validated['end_date'] ?? null,
            'notes'      => $validated['notes'] ?? null,
        ]);

        // Catat aktivitas ke log
        $action = $validated['shift_id'] ? 'shift_assigned' : 'shift_removed';
        $shiftName = $shift ? $shift->name : 'Default Kantor';
        $endDateStr = isset($validated['end_date']) ? $validated['end_date'] : null;
        $descEnd    = $endDateStr ? " s.d. {$endDateStr}" : '';
        $description = $validated['shift_id']
            ? "Assign shift '{$shiftName}' ke {$targetUser->name} mulai {$validated['start_date']}{$descEnd}"
            : "Hapus shift khusus {$targetUser->name} mulai {$validated['start_date']} (kembali ke default kantor)";

        $this->logActivity($actor->id, $actor->company_id, $action, $description, 'user', $targetUser->id);

        // Beri tahu karyawan bahwa jadwalnya berubah
        $tglMulai = Carbon::parse($validated['start_date'])->translatedFormat('d F Y');
        $tglAkhir = $endDateStr ? ' hingga ' . Carbon::parse($endDateStr)->translatedFormat('d F Y') : '';
        $this->notifyEmployee(
            $targetUser,
            'shift_assigned',
            $validated['shift_id']
                ? "Jadwal kerja Anda diubah ke '{$shiftName}' mulai {$tglMulai}{$tglAkhir}."
                : "Jadwal kerja Anda dikembalikan ke jam kantor default mulai {$tglMulai}.",
            $userShift->id
        );

        return response()->json([
            'message'  => $validated['shift_id']
                ? "Shift '{$shiftName}' berhasil di-assign ke {$targetUser->name}."
                : "Shift karyawan dikembalikan ke default kantor mulai {$validated['start_date']}.",
            'warnings' => $k3['warnings'],
            'data'     => $userShift->load('shift.schedules'),
        ], 201);
    }

    // ─── Helper: cek jeda K3 antara shift lama karyawan dan shift baru yang di-assign ─
    //     Bandingkan: akhir shift hari sebelum start_date (jadwal lama) vs
    //                 mulai shift pada start_date (jadwal baru).
    //
    //     Return: ['error' => string|null, 'warnings' => string[],
    //              'prev_end_at' => string|null, 'new_start_at' => string|null,
    //              'earliest_start' => string|null]
    private function checkAssignRestGap(User $user, ?Shift $newShift, string $startDate): array
    {
        $restSvc  = app(ShiftRestService::class);
        $dayBefore = Carbon::parse($startDate, 'Asia/Jakarta')->subDay()->toDateString();

        // Jadwal karyawan pada hari sebelum assignment baru mulai (pakai shift lama)
        $prevSchedule = self::resolveSchedule($user, $dayBefore);
        if ($prevSchedule['is_off'] || empty($prevSchedule['work_end_time'])) {
            // Hari libur / tidak kerja sebelum shift baru → tidak ada risiko jeda pendek
            return ['error' => null, 'warnings' => [], 'prev_end_at' => null, 'new_start_at' => null, 'earliest_start' => null];
        }

        // Jadwal efektif hari pertama shift baru
        // Sementara ganti shift user ke newShift agar resolveSchedule membaca template baru
        // — cara termudah: langsung ambil jadwal dari template shift baru
        $newStartTime = null;
        $newIsOff     = false;
        if ($newShift) {
            $dayOfWeek    = Carbon::parse($startDate)->dayOfWeek;
            // Versi jadwal yang berlaku pada startDate (effective_date <= startDate)
            $newSchedule  = self::scheduleForDate($newShift->id, $dayOfWeek, $startDate);
            $newIsOff     = $newSchedule ? (bool) $newSchedule->is_off : false;
            $newStartTime = ($newSchedule && ! $newSchedule->is_off) ? $newSchedule->work_start_time : null;
        } else {
            // Shift baru = default kantor
            $officeSchedule = self::resolveSchedule($user, $startDate);
            // Paksa resolveSchedule pakai office (hapus shift sementara tidak mungkin tanpa mutasi)
            // Ambil langsung dari object office
            $office       = $user->office
                ?? \App\Models\AttendanceSetting::where('company_id', $user->company_id)->orderBy('id')->first();
            if ($office) {
                $dayOfWeek = Carbon::parse($startDate)->dayOfWeek;
                $workDays  = $office->work_days ?? [1, 2, 3, 4, 5];
                $newIsOff  = ! in_array($dayOfWeek, $workDays);
                $newStartTime = $newIsOff ? null : $office->work_start_time;
            }
        }

        if ($newIsOff || ! $newStartTime) {
            // Hari pertama shift baru adalah libur → tidak ada risiko
            return ['error' => null, 'warnings' => [], 'prev_end_at' => null, 'new_start_at' => null, 'earliest_start' => null];
        }

        // Hitung datetime akhir shift lama
        $prevEndTime  = $prevSchedule['work_end_time'];
        $isCrossDay   = (bool) ($prevSchedule['is_cross_day'] ?? false);
        $prevEndDt    = Carbon::parse($dayBefore . ' ' . $prevEndTime, 'Asia/Jakarta');
        if ($isCrossDay) {
            $prevEndDt->addDay(); // jam pulang jatuh di hari mulai shift baru
        }

        // Hitung datetime mulai shift baru
        $newStartDt = Carbon::parse($startDate . ' ' . $newStartTime, 'Asia/Jakarta');

        $result = $restSvc->checkGapBetweenDatetimes($prevEndDt, $newStartDt);

        $prevEndStr      = $prevEndDt->format('d M Y H:i');
        $newStartStr     = $newStartDt->format('d M Y H:i');
        $earliestStartDt = $prevEndDt->copy()->addHours(ShiftRestService::MIN_REST_HOURS);
        $earliestStr     = $earliestStartDt->format('d M Y H:i');

        if ($result['status'] === 'error') {
            return [
                'error'          => "Penugasan ditolak. Shift lama berakhir pada {$prevEndStr}, sedangkan shift baru dimulai pada {$newStartStr}. Karyawan harus beristirahat minimal " . ShiftRestService::MIN_REST_HOURS . " jam. Shift baru paling cepat dapat dimulai pada {$earliestStr}.",
                'warnings'       => [],
                'prev_end_at'    => $prevEndDt->toIso8601String(),
                'new_start_at'   => $newStartDt->toIso8601String(),
                'earliest_start' => $earliestStartDt->toIso8601String(),
            ];
        }

        if ($result['status'] === 'warning') {
            return [
                'error'          => null,
                'warnings'       => ["Jeda istirahat {$user->name} hanya {$result['hours']} jam ({$prevEndStr} → {$newStartStr}). Disarankan minimal " . ShiftRestService::RECOMMENDED_REST_HOURS . " jam."],
                'prev_end_at'    => $prevEndDt->toIso8601String(),
                'new_start_at'   => $newStartDt->toIso8601String(),
                'earliest_start' => null,
            ];
        }

        return ['error' => null, 'warnings' => [], 'prev_end_at' => null, 'new_start_at' => null, 'earliest_start' => null];
    }

    // ─── Helper: validasi total jam kerja per minggu (P0 #1) ─────────────────
    //     Hanya aktif jika branch->enforce_weekly_hours = true.
    //     Return ['error' => string|null, 'warnings' => string[], 'total_hours' => float]
    private function validateWeeklyHours(array $schedules, AttendanceSetting $branch): array
    {
        $totalMins = 0;

        foreach ($schedules as $sch) {
            if (! empty($sch['is_off']) || empty($sch['work_start_time']) || empty($sch['work_end_time'])) {
                continue;
            }

            $startMins = $this->timeToMinutes($sch['work_start_time']);
            $endMins   = $this->timeToMinutes($sch['work_end_time']);
            $isCross   = (bool) ($sch['is_cross_day'] ?? ($endMins <= $startMins));

            $totalMins += $isCross ? (1440 - $startMins + $endMins) : ($endMins - $startMins);
        }

        $totalHours = round($totalMins / 60, 1);

        if (! $branch->enforce_weekly_hours) {
            return ['error' => null, 'warnings' => [], 'total_hours' => $totalHours];
        }

        $max = $branch->max_weekly_hours ?? 40;

        if ($totalHours > $max) {
            return [
                'error'       => "Total jam kerja template ini {$totalHours} jam/minggu, melebihi batas kantor {$max} jam/minggu. Kurangi jam kerja atau nonaktifkan batas jam mingguan di pengaturan kantor.",
                'warnings'    => [],
                'total_hours' => $totalHours,
            ];
        }

        if ($totalHours > $max * 0.9) {
            return [
                'error'       => null,
                'warnings'    => ["Total jam kerja {$totalHours} jam/minggu mendekati batas {$max} jam/minggu yang ditetapkan kantor."],
                'total_hours' => $totalHours,
            ];
        }

        return ['error' => null, 'warnings' => [], 'total_hours' => $totalHours];
    }

    // ─── Helper: konversi "HH:MM" atau "HH:MM:SS" ke total menit ─────────────
    private function timeToMinutes(string $time): int
    {
        $parts = explode(':', $time);
        return ((int) $parts[0]) * 60 + ((int) ($parts[1] ?? 0));
    }

    //     Return string error bila berbeda cabang; null bila cocok / boleh.
    //     Shift company-wide (attendance_setting_id null) boleh untuk siapa saja.
    private function assertBranchMatch(Shift $shift, User $user): ?string
    {
        if ($shift->attendance_setting_id === null) {
            return null; // template company-wide → boleh
        }

        if ($user->attendance_setting_id !== null
            && $shift->attendance_setting_id !== $user->attendance_setting_id
        ) {
            return "Shift '{$shift->name}' milik cabang lain, tidak cocok dengan cabang karyawan. Pilih shift dari cabang yang sama.";
        }

        return null;
    }

    // ─── Helper: cek minimum notice period perubahan jadwal shift ─────────
    //     Memeriksa apakah start_date memenuhi minimum notice N hari (shift_notice_days).
    //     Return string error (422) jika kurang dari N hari, atau null jika valid/fitur off.
    private function checkNoticeError(User $user, string $startDate): ?string
    {
        $office = $user->office
            ?? AttendanceSetting::where('company_id', $user->company_id)
                ->orderBy('id')
                ->first();

        if (! $office || empty($office->shift_notice_days) || $office->shift_notice_days <= 0) {
            return null;
        }

        $noticeDays = (int) $office->shift_notice_days;
        $today = Carbon::now('Asia/Jakarta')->startOfDay();
        $start = Carbon::parse($startDate, 'Asia/Jakarta')->startOfDay();

        $diffDays = (int) $today->diffInDays($start, false);

        if ($diffDays < $noticeDays) {
            $safeDateStr = $today->copy()->addDays($noticeDays)->translatedFormat('d F Y');
            if ($diffDays < 0) {
                return "Tanggal mulai shift tidak boleh berlaku mundur ({$start->translatedFormat('d F Y')}). Perusahaan menetapkan minimum notice {$noticeDays} hari (seharusnya mulai tanggal {$safeDateStr} atau setelahnya).";
            }
            return "Tanggal mulai shift minimal harus {$noticeDays} hari dari hari ini (mulai tanggal {$safeDateStr} atau setelahnya).";
        }

        return null;
    }

    // ═══════════════════════════════════════════════════════════
    // 4b. updateAssignment() — ubah assignment shift karyawan
    //     PUT/PATCH /api/v1/dashboard/attendance/assignments/{id}
    // ═══════════════════════════════════════════════════════════
    public function updateAssignment(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();

        // Ambil assignment + pastikan karyawannya milik perusahaan aktor
        $userShift = UserShift::whereHas('user', function ($q) use ($actor) {
            $q->when($actor->role !== 'super_admin', fn ($qq) => $qq->where('company_id', $actor->company_id));
        })->find($id);

        if (! $userShift) {
            return response()->json(['message' => 'Assignment tidak ditemukan di perusahaan Anda.'], 404);
        }

        $validated = $request->validate([
            'shift_id'   => 'nullable|integer|exists:shifts,id',
            'start_date' => 'sometimes|required|date',
            // end_date opsional; jika dikirim null secara eksplisit → hapus batas waktu
            'end_date'   => 'sometimes|nullable|date',
            'notes'      => 'nullable|string|max:500',
        ]);

        // Validasi silang: end_date > start_date (minimal H+1)
        $effectiveStart = $validated['start_date'] ?? $userShift->start_date->toDateString();
        if (! empty($validated['end_date']) && $validated['end_date'] <= $effectiveStart) {
            return response()->json(['message' => 'end_date harus setelah start_date (minimal 1 hari setelah tanggal mulai).'], 422);
        }

        $targetUser = $userShift->user;

        // Validasi shift baru bila dikirim
        if (array_key_exists('shift_id', $validated) && $validated['shift_id'] !== null) {
            $shift = Shift::when(
                $actor->role !== 'super_admin',
                fn ($q) => $q->where('company_id', $actor->company_id)
            )->find($validated['shift_id']);

            if (! $shift) {
                return response()->json(['message' => 'Shift tidak ditemukan di perusahaan Anda.'], 404);
            }
            if ($err = $this->assertBranchMatch($shift, $targetUser)) {
                return response()->json(['message' => $err], 422);
            }

            // Cegah assign shift yang sedang AKTIF atau SEGERA (belum mulai) untuk
            // karyawan yang sama (duplikat), kecuali assignment itu sendiri
            // (no-op self-update tidak diblokir).
            $todayStr = now('Asia/Jakarta')->toDateString();
            $duplicateActive = UserShift::where('user_id', $userShift->user_id)
                ->where('shift_id', $validated['shift_id'])
                ->where('id', '!=', $userShift->id)
                ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $todayStr))
                ->exists();

            if ($duplicateActive) {
                return response()->json([
                    'message' => "Karyawan ini sudah memiliki assignment aktif atau segera untuk shift '{$shift->name}'. Hapus/akhiri assignment tersebut terlebih dahulu.",
                ], 422);
            }
        }

        // Cek duplikat start_date (kecuali dirinya sendiri)
        $newStart = $validated['start_date'] ?? $userShift->start_date->toDateString();
        $bentrok = UserShift::where('user_id', $userShift->user_id)
            ->where('start_date', $newStart)
            ->where('id', '!=', $userShift->id)
            ->exists();

        if ($bentrok) {
            return response()->json([
                'message' => 'Sudah ada assignment lain dengan tanggal mulai yang sama untuk karyawan ini.',
            ], 422);
        }

        // Validasi jeda istirahat K3 jika shift atau start_date berubah
        $k3Warnings = [];
        if (array_key_exists('shift_id', $validated) || array_key_exists('start_date', $validated)) {
            $newShiftForK3 = array_key_exists('shift_id', $validated)
                ? ($validated['shift_id'] ? Shift::find($validated['shift_id']) : null)
                : $userShift->shift;
            $k3 = $this->checkAssignRestGap($targetUser, $newShiftForK3, $newStart);
            if ($k3['error']) {
                return response()->json([
                    'message'        => $k3['error'],
                    'prev_end_at'    => $k3['prev_end_at'],
                    'new_start_at'   => $k3['new_start_at'],
                    'earliest_start' => $k3['earliest_start'],
                ], 422);
            }
            $k3Warnings = $k3['warnings'];
        }

        // Guard minimum H+1 (konsisten dengan assignShift): hanya berlaku jika
        // HRD mengubah start_date ke nilai baru (bukan sekadar update notes/end_date).
        // Assignment yang start_date-nya sudah di masa lalu (sudah berlaku) tidak diblokir
        // saat update bidang lain — hanya perubahan ke start_date baru yang dicek.
        if (array_key_exists('start_date', $validated)) {
            $todayForGuard = Carbon::now('Asia/Jakarta')->toDateString();
            if ($validated['start_date'] <= $todayForGuard) {
                $minStart = Carbon::tomorrow('Asia/Jakarta')->translatedFormat('d F Y');
                return response()->json([
                    'message' => "Tanggal mulai shift baru harus minimal besok ({$minStart}). Tidak bisa mengubah start_date ke hari ini atau tanggal yang sudah lewat.",
                ], 422);
            }
        }

        // Validasi minimum notice period (HARD ERROR)
        if ($noticeErr = $this->checkNoticeError($targetUser, $newStart)) {
            return response()->json(['message' => $noticeErr], 422);
        }

        // CEGAH BUG: jika assignment ini sedang berlaku HARI INI dan diubah
        // (shift_id / start_date / end_date) saat karyawan sedang dalam jam kerja,
        // blokir — agar jadwal hari ini tidak berubah di tengah jam kerja.
        $todayStr = now('Asia/Jakarta')->toDateString();
        $effectiveEnd = $validated['end_date']
            ?? $userShift->end_date?->toDateString()
            ?? $todayStr; // end_date null = berlaku tanpa batas → aktif hari ini

        $isActiveToday = $newStart <= $todayStr && $effectiveEnd >= $todayStr;
        $shiftChanged  = array_key_exists('shift_id', $validated)
            || array_key_exists('start_date', $validated)
            || array_key_exists('end_date', $validated);

        if ($isActiveToday && $shiftChanged && $userShift->shift_id) {
            $blocked = $this->checkWithinWorkingHours($userShift);
            if ($blocked) {
                return response()->json([
                    'message' => "Tidak bisa mengubah assignment shift sekarang. " . $blocked,
                ], 422);
            }
        }

        $userShift->fill(collect($validated)->only(['shift_id', 'start_date', 'end_date', 'notes'])->toArray());
        $userShift->save();

        $this->logActivity(
            $actor->id,
            $actor->company_id,
            'shift_assignment_updated',
            "Mengubah assignment shift {$targetUser->name} (mulai {$newStart})",
            'user',
            $targetUser->id
        );

        $this->notifyEmployee(
            $targetUser,
            'shift_assigned',
            "Jadwal kerja Anda diperbarui (berlaku mulai " . Carbon::parse($newStart)->translatedFormat('d F Y') . ").",
            $userShift->id
        );

        return response()->json([
            'message'  => 'Assignment berhasil diperbarui.',
            'warnings' => $k3Warnings,
            'data'     => $userShift->fresh()->load('shift.schedules'),
        ]);
    }

    // ═══════════════════════════════════════════════════════════
    // 4c. destroyAssignment() — hapus / akhiri assignment shift karyawan
    //     DELETE /api/v1/dashboard/attendance/assignments/{id}
    //
    //    Perilaku berdasarkan status assignment (lihat shiftHistory):
    //    - ACTIVE   (sedang berlaku hari ini) → SOFT-END: set end_date = kemarin.
    //      Histori tetap tersimpan, karyawan otomatis pindah ke jadwal kantor default.
    //    - UPCOMING (belum dimulai, start_date > hari ini) → HAPUS PERMANEN.
    //      Belum pernah dipakai, tidak merusak histori apa pun.
    //    - EXPIRED  (sudah berakhir, end_date < hari ini) → HAPUS PERMANEN.
    //      Tidak berpengaruh pada jadwal yang sedang berlaku.
    //
    //    Ini sesuai aturan: "Jangan merusak histori jadwal". Menghapus assignment
    //    yang sedang aktif secara permanen akan kehilangan jejak histori shift.
    // ═══════════════════════════════════════════════════════════
    public function destroyAssignment(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();

        $userShift = UserShift::whereHas('user', function ($q) use ($actor) {
            $q->when($actor->role !== 'super_admin', fn ($qq) => $qq->where('company_id', $actor->company_id));
        })->find($id);

        if (! $userShift) {
            return response()->json(['message' => 'Assignment tidak ditemukan di perusahaan Anda.'], 404);
        }

        $targetUser = $userShift->user;
        $tglMulai   = $userShift->start_date->toDateString();
        $today      = now('Asia/Jakarta')->toDateString();

        // Apakah assignment ini MASIH AKTIF hari ini?
        $isActive = $userShift->start_date->toDateString() <= $today
            && ($userShift->end_date === null || $userShift->end_date->toDateString() >= $today);

        // CEGAH BUG: jangan izinkan hapus/akhiri assignment saat karyawan sedang
        // dalam jam kerja shift-nya (lihat checkWithinWorkingHours).
        if ($isActive) {
            $blocked = $this->checkWithinWorkingHours($userShift);
            if ($blocked) {
                return response()->json(['message' => $blocked], 422);
            }
        }

        if ($isActive) {
            $startStr = $userShift->start_date->toDateString();

            // Jika assignment baru dimulai HARI INI (start_date == today), belum ada
            // histori hari-hari kemarin yang perlu dipertahankan -> hapus permanen
            // agar karyawan langsung kembali ke default kantor hari ini tanpa tertahan end_date = today.
            if ($startStr === $today) {
                $userShift->delete();

                $this->logActivity(
                    $actor->id,
                    $actor->company_id,
                    'shift_assignment_deleted',
                    "Menghapus assignment shift {$targetUser->name} (mulai {$tglMulai}) — karyawan kembali ke jadwal kantor default",
                    'user',
                    $targetUser->id
                );

                $this->notifyEmployee(
                    $targetUser,
                    'shift_removed',
                    "Jadwal shift Anda (mulai " . Carbon::parse($tglMulai)->translatedFormat('d F Y') . ") telah dihapus HRD. Anda kembali ke jadwal kantor default mulai hari ini.",
                    null
                );

                return response()->json([
                    'message' => "Assignment shift dihapus. {$targetUser->name} kembali ke jadwal kantor default mulai hari ini.",
                    'soft_end' => false,
                ]);
            }

            // Jika assignment sudah berjalan sejak masa lalu (start_date < today) -> SOFT-END:
            // set end_date = kemarin. Histori masa lalu tetap tersimpan utuh,
            // dan karyawan otomatis kembali ke jadwal kantor default mulai hari ini.
            $yesterday = Carbon::yesterday('Asia/Jakarta')->toDateString();
            $userShift->end_date = $yesterday;
            $userShift->save();

            $this->logActivity(
                $actor->id,
                $actor->company_id,
                'shift_assignment_ended',
                "Mengakhiri assignment shift {$targetUser->name} (mulai {$tglMulai}) — karyawan kembali ke jadwal kantor default",
                'user',
                $targetUser->id
            );

            $this->notifyEmployee(
                $targetUser,
                'shift_removed',
                "Jadwal shift Anda (mulai " . Carbon::parse($tglMulai)->translatedFormat('d F Y') . ") telah diakhiri HRD. Anda kembali ke jadwal kantor default mulai hari ini.",
                $userShift->id
            );

            return response()->json([
                'message' => "Assignment shift diakhiri. {$targetUser->name} kembali ke jadwal kantor default mulai hari ini.",
                'soft_end' => true,
                'data'     => $userShift->fresh()->load('shift.schedules'),
            ]);
        }

        // UPCOMING atau EXPIRED → hapus permanen (tidak memengaruhi jadwal aktif)
        $userShift->delete();

        $this->logActivity(
            $actor->id,
            $actor->company_id,
            'shift_assignment_deleted',
            "Menghapus assignment shift {$targetUser->name} (mulai {$tglMulai})",
            'user',
            $targetUser->id
        );

        $this->notifyEmployee(
            $targetUser,
            'shift_removed',
            "Salah satu jadwal shift Anda (mulai " . Carbon::parse($tglMulai)->translatedFormat('d F Y') . ") telah dihapus HRD.",
            null
        );

        return response()->json(['message' => 'Assignment shift berhasil dihapus.']);
    }

    // ─── Helper: cek apakah "sekarang" berada dalam jam kerja shift aktif ────
    //     Mencegah bug: HRD menghapus/akhiri assignment di tengah jam kerja shift,
    //     sehingga jadwal hari itu berubah ke default kantor (jam checkout lebih lama)
    //     dan karyawan yang checkout sesuai shift aslinya terdeteksi pulang cepat.
    //
    //     Menangani shift normal & shift malam (cross-day / lintas tengah malam):
    //     - Shift normal 08:00–15:00 → dalam jam kerja jika 08:00 <= now <= 15:00
    //     - Shift malam 22:00–05:00 (cross-day) → dalam jam kerja jika
    //       (22:00 <= now <= 24:00) ATAU (00:00 <= now <= 05:00)
    //
    //     Return null bila tidak dalam jam kerja / hari libur; return string pesan
    //     blokir (dengan rentang jam shift) bila sekarang sedang jam kerja shift.
    private function checkWithinWorkingHours(UserShift $userShift): ?string
    {
        // Assignment harus sedang berlaku HARI INI dan memakai template shift
        if (! $userShift->shift_id) {
            return null;
        }

        $today  = now('Asia/Jakarta');
        $dateStr = $today->toDateString();

        // Shift tidak berlaku hari ini
        if ($userShift->start_date->toDateString() > $dateStr) {
            return null;
        }
        if ($userShift->end_date !== null && $userShift->end_date->toDateString() < $dateStr) {
            return null;
        }

        $shift = $userShift->shift;
        if (! $shift || ! $shift->is_active) {
            return null;
        }

        $dayOfWeek = $today->dayOfWeek; // 0=Minggu ... 6=Sabtu
        $schedule  = self::scheduleForDate($shift->id, $dayOfWeek, $today->toDateString());

        if (! $schedule || $schedule->is_off || empty($schedule->work_start_time) || empty($schedule->work_end_time)) {
            return null; // hari libur shift → tidak ada jam kerja
        }

        $nowMinutes = $today->hour * 60 + $today->minute;
        $startMinutes = $this->timeToMinutes($schedule->work_start_time);
        $endMinutes   = $this->timeToMinutes($schedule->work_end_time);
        $isCrossDay   = (bool) $schedule->is_cross_day;

        $inHours = $isCrossDay
            ? ($nowMinutes >= $startMinutes || $nowMinutes <= $endMinutes)
            : ($nowMinutes >= $startMinutes && $nowMinutes <= $endMinutes);

        if (! $inHours) {
            return null;
        }

        $range = $isCrossDay
            ? "{$schedule->work_start_time} sampai {$schedule->work_end_time} (+1 hari)"
            : "{$schedule->work_start_time} sampai {$schedule->work_end_time}";

        return "Karyawan sedang dalam jam kerja shift '{$shift->name}' ({$range}). Assignment tidak bisa dihapus/diakhiri di tengah jam kerja. Tunggu sampai jam kerja selesai, atau gunakan assignment baru (shift lain) mulai tanggal berikutnya.";
    }

    // ═══════════════════════════════════════════════════════════
    // 4d. bulkAssign() — assign satu shift ke BANYAK karyawan sekaligus
    //     POST /api/v1/dashboard/attendance/bulk-assign
    //
    //     Body:
    //     {
    //       "user_ids": [5, 8, 12],
    //       "shift_id": 3,          ← null = kembalikan ke default kantor
    //       "start_date": "2026-07-06",
    //       "notes": "Shift gudang proyek X"
    //     }
    //
    //     Karyawan yang bermasalah (bukan milik perusahaan, beda cabang, atau
    //     start_date bentrok) DILEWATI dan dilaporkan; sisanya tetap diproses.
    // ═══════════════════════════════════════════════════════════
    public function bulkAssign(Request $request): JsonResponse
    {
        $actor = $request->user();

        $validated = $request->validate([
            'user_ids'   => 'required|array|min:1',
            'user_ids.*' => 'integer|distinct',
            'shift_id'   => 'nullable|integer|exists:shifts,id',
            'start_date' => 'required|date',
            // end_date opsional — semua karyawan target akan mendapat end_date yang sama.
            // Setelah end_date, masing-masing karyawan otomatis kembali ke default kantor.
            'end_date'   => 'nullable|date|after:start_date',
            'notes'      => 'nullable|string|max:500',
        ]);

        // Validasi shift sekali di depan (bukan per karyawan)
        $shift = null;
        if (! empty($validated['shift_id'])) {
            $shift = Shift::when(
                $actor->role !== 'super_admin',
                fn ($q) => $q->where('company_id', $actor->company_id)
            )->find($validated['shift_id']);

            if (! $shift) {
                return response()->json(['message' => 'Shift tidak ditemukan di perusahaan Anda.'], 404);
            }

            // Cegah bulk-assign ke shift yang sudah dinonaktifkan
            if (! $shift->is_active) {
                return response()->json(['message' => "Shift '{$shift->name}' sudah dinonaktifkan. Aktifkan kembali terlebih dahulu atau pilih shift lain."], 422);
            }
        }

        // Ambil semua karyawan target sekaligus (hindari N+1)
        $targets = User::with('office')
            ->when(
                $actor->role !== 'super_admin',
                fn ($q) => $q->where('company_id', $actor->company_id)
            )->whereIn('id', $validated['user_ids'])->get()->keyBy('id');

        // Ambil start_date yang sudah terpakai untuk cegah bentrok (satu query)
        $tanggalTerpakai = UserShift::whereIn('user_id', $validated['user_ids'])
            ->where('start_date', $validated['start_date'])
            ->pluck('user_id')
            ->flip();

        // Karyawan yang sudah punya assignment AKTIF atau SEGERA untuk shift yang
        // sama (satu query) — akhiri/hapus assignment dulu sebelum assign ulang.
        $todayStr = now('Asia/Jakarta')->toDateString();
        $alreadyActive = $validated['shift_id'] !== null
            ? UserShift::whereIn('user_id', $validated['user_ids'])
                ->where('shift_id', $validated['shift_id'])
                ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $todayStr))
                ->pluck('user_id')
                ->flip()
            : collect();

        // Preload assignment shift LAMA yang sedang AKTIF hari ini untuk semua
        // karyawan target (satu query, hindari N+1). Dipakai untuk guard anti
        // "ubah jadwal di tengah jam kerja" — sama seperti assignShift() individual.
        $activeOldByUser = UserShift::with('shift')
            ->whereIn('user_id', $validated['user_ids'])
            ->whereNotNull('shift_id')
            ->where('start_date', '<=', $todayStr)
            ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $todayStr))
            ->orderByDesc('start_date')
            ->get()
            ->groupBy('user_id')
            ->map(fn ($rows) => $rows->first()); // assignment terbaru yang aktif

        // Apakah assignment BARU ini berlaku HARI INI? (sama untuk semua karyawan
        // karena start_date & end_date seragam). Jika ya, perlu guard jam kerja.
        $newStartDate = $validated['start_date'];
        $newEndDate   = $validated['end_date'] ?? null;
        $coversToday  = $newStartDate <= $todayStr
            && ($newEndDate === null || $newEndDate >= $todayStr);

        $shiftName = $shift ? $shift->name : 'Default Kantor';
        $tglMulai  = Carbon::parse($validated['start_date'])->translatedFormat('d F Y');

        $berhasil = [];
        $dilewati = [];

        DB::transaction(function () use ($validated, $targets, $shift, $tanggalTerpakai, $alreadyActive, $activeOldByUser, $coversToday, $shiftName, $tglMulai, $actor, &$berhasil, &$dilewati) {
            foreach ($validated['user_ids'] as $uid) {
                $user = $targets->get($uid);

                // Karyawan tidak ada / bukan milik perusahaan aktor
                if (! $user) {
                    $dilewati[] = ['user_id' => $uid, 'reason' => 'Bukan karyawan perusahaan Anda.'];
                    continue;
                }

                // Cabang shift tidak cocok dengan cabang karyawan
                if ($shift && ($err = $this->assertBranchMatch($shift, $user))) {
                    $dilewati[] = ['user_id' => $uid, 'name' => $user->name, 'reason' => $err];
                    continue;
                }

                // Karyawan sudah punya assignment AKTIF atau SEGERA untuk shift yang sama
                if ($alreadyActive->has($uid)) {
                    $dilewati[] = ['user_id' => $uid, 'name' => $user->name, 'reason' => "Karyawan ini sudah memiliki assignment aktif atau segera untuk shift '{$shiftName}'."];
                    continue;
                }

                // Bentrok start_date
                if ($tanggalTerpakai->has($uid)) {
                    $dilewati[] = ['user_id' => $uid, 'name' => $user->name, 'reason' => 'Sudah ada assignment di tanggal mulai yang sama.'];
                    continue;
                }

                // Validasi jeda istirahat K3
                $k3 = $this->checkAssignRestGap($user, $shift, $validated['start_date']);
                if ($k3['error']) {
                    $dilewati[] = ['user_id' => $uid, 'name' => $user->name, 'reason' => $k3['error']];
                    continue;
                }

                // Validasi minimum notice period — HARUS sebelum insert agar
                // data tidak masuk DB jika notice period tidak terpenuhi.
                if ($noticeErr = $this->checkNoticeError($user, $validated['start_date'])) {
                    $dilewati[] = ['user_id' => $uid, 'name' => $user->name, 'reason' => $noticeErr];
                    continue;
                }

                // CEGAH BUG (konsisten dengan assignShift individual): jika assignment
                // baru mulai/berlaku HARI INI, pastikan karyawan tidak sedang dalam jam
                // kerja shift LAMA yang akan digantikan. Lewati & laporkan bila sedang
                // jam kerja agar jadwal hari ini tidak berubah di tengah shift.
                if ($coversToday) {
                    $activeOld = $activeOldByUser->get($uid);
                    if ($activeOld && $activeOld->shift_id && $activeOld->shift_id !== $validated['shift_id']) {
                        $blocked = $this->checkWithinWorkingHours($activeOld);
                        if ($blocked) {
                            $dilewati[] = ['user_id' => $uid, 'name' => $user->name, 'reason' => "Tidak bisa mengubah shift karyawan sekarang. " . $blocked];
                            continue;
                        }
                    }
                }

                // Semua validasi lolos → simpan assignment ke DB
                $userShift = UserShift::create([
                    'user_id'    => $uid,
                    'shift_id'   => $validated['shift_id'],
                    'start_date' => $validated['start_date'],
                    'end_date'   => $validated['end_date'] ?? null,
                    'notes'      => $validated['notes'] ?? null,
                ]);

                $tglAkhirBulk = isset($validated['end_date'])
                    ? ' hingga ' . Carbon::parse($validated['end_date'])->translatedFormat('d F Y')
                    : '';
                $this->notifyEmployee(
                    $user,
                    ! empty($validated['shift_id']) ? 'shift_assigned' : 'shift_removed',
                    ! empty($validated['shift_id'])
                        ? "Jadwal kerja Anda diubah ke '{$shiftName}' mulai {$tglMulai}{$tglAkhirBulk}."
                        : "Jadwal kerja Anda dikembalikan ke jam kantor default mulai {$tglMulai}.",
                    $userShift->id
                );

                $entry = ['user_id' => $uid, 'name' => $user->name, 'assignment_id' => $userShift->id];
                if (! empty($k3['warnings'])) {
                    $entry['warnings'] = $k3['warnings'];
                    $entry['warning']  = $k3['warnings'][0];
                }
                $berhasil[] = $entry;
            }
        });

        $this->logActivity(
            $actor->id,
            $actor->company_id,
            ! empty($validated['shift_id']) ? 'shift_assigned' : 'shift_removed',
            "Bulk assign shift '{$shiftName}' ke " . count($berhasil) . " karyawan mulai {$validated['start_date']}",
            'shift',
            $shift?->id
        );

        return response()->json([
            'message'       => count($berhasil) . ' karyawan berhasil di-assign, ' . count($dilewati) . ' dilewati.',
            'assigned'      => $berhasil,
            'skipped'       => $dilewati,
            'total_success' => count($berhasil),
            'total_skipped' => count($dilewati),
        ], 201);
    }

    // ═══════════════════════════════════════════════════════════
    // 5. effectiveSchedule() — preview jadwal efektif untuk user + tanggal
    //    GET /api/v1/dashboard/attendance/effective-schedule?user_id=&date=
    //
    //    Dipakai frontend untuk menampilkan preview sebelum submit assign.
    // ═══════════════════════════════════════════════════════════
    public function effectiveSchedule(Request $request): JsonResponse
    {
        $actor = $request->user();

        $validated = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'date'    => 'required|date',
        ]);

        $targetUser = User::when(
            $actor->role !== 'super_admin',
            fn ($q) => $q->where('company_id', $actor->company_id)
        )->find($validated['user_id']);

        if (! $targetUser) {
            return response()->json(['message' => 'Karyawan tidak ditemukan.'], 404);
        }

        $date     = Carbon::parse($validated['date'])->toDateString();
        $schedule = self::resolveSchedule($targetUser, $date);

        return response()->json([
            'user_id'   => $targetUser->id,
            'user_name' => $targetUser->name,
            'date'      => $date,
            'day_name'  => Carbon::parse($date)->translatedFormat('l'), // nama hari bahasa lokal
            'schedule'  => $schedule,
        ]);
    }

    // ═══════════════════════════════════════════════════════════
    // 5b. calendar() — kalender shift bulanan
    //     GET /api/v1/dashboard/attendance/shifts/calendar?month=&year=&attendance_setting_id=
    //
    //     Mengembalikan data per-hari untuk satu bulan: pada setiap tanggal,
    //     shift apa yang aktif + siapa saja karyawannya (untuk tampilan kalender).
    //     Satu query bulk (tidak per-hari) agar efisien.
    // ═══════════════════════════════════════════════════════════
    public function calendar(Request $request): JsonResponse
    {
        $actor = $request->user();

        $validated = $request->validate([
            'month'                 => 'nullable|integer|between:1,12',
            'year'                  => 'nullable|integer|min:2020|max:2100',
            'attendance_setting_id' => 'nullable|integer',
        ]);

        $month    = $validated['month'] ?? now('Asia/Jakarta')->month;
        $year     = $validated['year']  ?? now('Asia/Jakarta')->year;
        $branchId = $validated['attendance_setting_id'] ?? null;

        $startOfMonth = Carbon::create($year, $month, 1)->startOfDay();
        $endOfMonth   = $startOfMonth->copy()->endOfMonth();

        // Semua karyawan aktif perusahaan (atau filter per cabang)
        $users = User::query()
            ->when(
                $actor->role !== 'super_admin',
                fn ($q) => $q->where('company_id', $actor->company_id)
            )
            ->when(
                $branchId,
                fn ($q) => $q->where('attendance_setting_id', $branchId)
            )
            ->where('is_active', true)
            ->get(['id', 'name', 'department']);

        if ($users->isEmpty()) {
            return response()->json([
                'month' => $month,
                'year'  => $year,
                'days'  => (object) [],
            ]);
        }

        // Semua assignment hingga akhir bulan (bisa mulai bulan-bulan sebelumnya)
        // Diurutkan desc agar pencarian "shift terbaru ≤ tanggal" cukup ambil first()
        $assignments = UserShift::with('shift:id,name,color,is_active')
            ->whereIn('user_id', $users->pluck('id'))
            ->where('start_date', '<=', $endOfMonth->toDateString())
            ->orderBy('user_id')
            ->orderByDesc('start_date')
            ->get();

        // Kelompokkan per user agar lookup O(1) per iterasi
        $byUser = $assignments->groupBy('user_id');

        $days = [];
        $current = $startOfMonth->copy();

        while ($current->lte($endOfMonth)) {
            $dateStr  = $current->toDateString();
            $shiftMap = []; // shift_id → entry

            foreach ($users as $user) {
                $userAssignments = $byUser->get($user->id, collect());
                // Assignment aktif = start_date terbaru yang ≤ tanggal ini DAN (end_date === null || end_date >= tanggal ini)
                $active = $userAssignments->first(
                    fn ($a) => $a->start_date->toDateString() <= $dateStr
                        && ($a->end_date === null || $a->end_date->toDateString() >= $dateStr)
                );

                // Tampilkan hanya jika template shift masih AKTIF (is_active = true)
                if ($active && $active->shift_id && $active->shift && $active->shift->is_active) {
                    $sid = $active->shift_id;
                    if (! isset($shiftMap[$sid])) {
                        $shiftMap[$sid] = [
                            'shift_id'   => $sid,
                            'shift_name' => $active->shift->name,
                            'color'      => $active->shift->color ?? '#6366f1',
                            'user_count' => 0,
                            'users'      => [],
                        ];
                    }
                    $shiftMap[$sid]['user_count']++;
                    $shiftMap[$sid]['users'][] = [
                        'user_id'    => $user->id,
                        'name'       => $user->name,
                        'department' => $user->department,
                    ];
                }
            }

            if (! empty($shiftMap)) {
                $days[$dateStr] = array_values($shiftMap);
            }

            $current->addDay();
        }

        return response()->json([
            'month' => (int) $month,
            'year'  => (int) $year,
            'days'  => empty($days) ? (object) [] : $days,
        ]);
    }

    // ═══════════════════════════════════════════════════════════
    // 6. roster() — daftar karyawan + shift efektif hari ini
    //    GET /api/v1/dashboard/attendance/shifts/roster?date=&attendance_setting_id=
    //
    //    Menampilkan siapa masuk shift apa pada tanggal tertentu (default hari ini),
    //    bisa difilter per cabang (attendance_setting_id).
    // ═══════════════════════════════════════════════════════════
    public function roster(Request $request): JsonResponse
    {
        $actor = $request->user();

        $validated = $request->validate([
            'date'                  => 'nullable|date',
            'attendance_setting_id' => 'nullable|integer', // filter cabang
            'search'                => 'nullable|string|max:100',
        ]);

        $date = isset($validated['date'])
            ? Carbon::parse($validated['date'])->toDateString()
            : now('Asia/Jakarta')->toDateString();

        $users = User::query()
            ->when(
                $actor->role !== 'super_admin',
                fn ($q) => $q->where('company_id', $actor->company_id)
            )
            ->when(
                isset($validated['attendance_setting_id']),
                fn ($q) => $q->where('attendance_setting_id', $validated['attendance_setting_id'])
            )
            ->when(
                isset($validated['search']),
                fn ($q) => $q->where('name', 'like', '%' . $validated['search'] . '%')
            )
            ->where('is_active', true)
            ->with('office')
            ->orderBy('name')
            ->get();

        $userIds   = $users->pluck('id');
        $dayOfWeek = Carbon::parse($date)->dayOfWeek; // 0=Minggu … 6=Sabtu

        // ── PRE-LOAD BULK (hindari N+1 dari resolveSchedule per user) ────────
        //
        // 1. Semua assignment AKTIF pada tanggal roster (1 query + eager load shift)
        $activeAssignments = UserShift::with('shift:id,name,color,is_active')
            ->whereIn('user_id', $userIds)
            ->where('start_date', '<=', $date)
            ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $date))
            ->orderByDesc('start_date')
            ->get()
            ->groupBy('user_id');

        // 2. Kumpulkan shift_id aktif yang perlu dicari jadwalnya
        $relevantShiftIds = $activeAssignments->flatten()
            ->filter(fn ($a) => $a->shift_id && optional($a->shift)->is_active)
            ->pluck('shift_id')
            ->unique()
            ->values();

        // 3. Pre-load jadwal shift untuk day_of_week pada tanggal roster (1 query)
        //    Ambil SEMUA versi lalu pilih versi terbaru yang effective_date <= date di memory
        $schedulesByShift = collect();
        if ($relevantShiftIds->isNotEmpty()) {
            $schedulesByShift = ShiftSchedule::whereIn('shift_id', $relevantShiftIds)
                ->where('day_of_week', $dayOfWeek)
                ->orderByDesc('effective_date')
                ->get()
                ->groupBy('shift_id')
                ->map(function ($schedules) use ($date) {
                    // Versi terbaru yang sudah efektif (effective_date <= date)
                    $match = $schedules->first(fn ($s) => $s->effective_date->toDateString() <= $date);
                    // Fallback: versi terbaru secara global (untuk tanggal sebelum versi pertama)
                    return $match ?? $schedules->first();
                });
        }

        // 4. Fallback kantor untuk user yang belum punya attendance_setting_id
        $fallbackOffice = AttendanceSetting::where('company_id', $actor->company_id)
            ->orderBy('id')
            ->first();

        // 5. Pre-load assignment shift MASA DEPAN (start_date > tanggal roster)
        //    — satu query untuk semua user (sudah benar sebelumnya)
        $upcomingShifts = UserShift::with('shift:id,name,color,is_active')
            ->whereIn('user_id', $userIds)
            ->where('start_date', '>', $date)
            ->orderBy('start_date')
            ->get()
            ->groupBy('user_id');

        // ── RESOLVE JADWAL DI MEMORY (tanpa query tambahan per user) ─────────
        $roster = $users->map(function (User $user) use ($date, $dayOfWeek, $activeAssignments, $schedulesByShift, $upcomingShifts, $fallbackOffice) {
            // Cari assignment aktif dari data yang sudah di-preload
            $active = $activeAssignments->get($user->id, collect())->first();

            $source    = 'office';
            $shiftName = null;
            $workStart = null;
            $workEnd   = null;
            $isOff     = false;
            $isWfh     = false;
            $isField   = false;
            $isCrossDay = false;

            // Prioritas 1: shift aktif dengan template yang masih aktif
            if ($active && $active->shift_id && optional($active->shift)->is_active) {
                $schedule = $schedulesByShift->get($active->shift_id);
                if ($schedule) {
                    $source    = 'shift';
                    $shiftName = $active->shift->name;
                    $isOff     = (bool) $schedule->is_off;
                    $workStart = $isOff ? null : $schedule->work_start_time;
                    $workEnd   = $isOff ? null : $schedule->work_end_time;
                    $isWfh     = $isOff ? false : (bool) $schedule->is_wfh;
                    $isField   = ($isOff || ! $isWfh) ? false : (bool) $schedule->is_field;
                    $isCrossDay = (bool) $schedule->is_cross_day;
                }
            }

            // Prioritas 2: fallback ke jadwal default kantor
            if ($source === 'office') {
                $office = $user->office ?? $fallbackOffice;
                if ($office) {
                    $workDays = $office->work_days ?? [1, 2, 3, 4, 5];
                    $isOff    = ! in_array($dayOfWeek, $workDays);
                    if (! $isOff) {
                        $customStart = null;
                        $customEnd   = null;
                        if (! empty($office->custom_schedules[$dayOfWeek])) {
                            $customStart = $office->custom_schedules[$dayOfWeek]['start'] ?? null;
                            $customEnd   = $office->custom_schedules[$dayOfWeek]['end'] ?? null;
                        }
                        $workStart = $customStart ?? $office->work_start_time;
                        $workEnd   = $customEnd ?? $office->work_end_time;
                    }
                } else {
                    $source = 'none';
                }
            }

            // Cari shift berikutnya yang belum aktif (coming soon):
            // - shift_id terisi (bukan kembali ke default kantor)
            // - template shift masih aktif
            // - end_date (jika ada) masih >= start_date
            $upcoming = null;
            foreach ($upcomingShifts->get($user->id, collect()) as $us) {
                if (! $us->shift_id || ! optional($us->shift)->is_active) {
                    continue;
                }
                if ($us->end_date && $us->end_date->lt($us->start_date)) {
                    continue;
                }
                $upcoming = [
                    'shift_id'   => $us->shift_id,
                    'shift_name' => $us->shift->name,
                    'color'      => $us->shift->color,
                    'start_date' => $us->start_date->toDateString(),
                ];
                break; // ambil yang paling dekat
            }

            return [
                'user_id'                => $user->id,
                'attendance_setting_id'  => $user->attendance_setting_id,
                'name'                   => $user->name,
                'department'             => $user->department,
                'branch'                 => optional($user->office)->office_name,
                'source'                 => $source,
                'shift_name'             => $shiftName,
                'work_start_time'        => $workStart,
                'work_end_time'          => $workEnd,
                'is_off'                 => $isOff,
                'is_wfh'                 => $isWfh,
                'is_field'               => $isField,
                'is_cross_day'           => $isCrossDay,
                'upcoming_shift'         => $upcoming,
            ];
        });

        return response()->json([
            'date'     => $date,
            'day_name' => Carbon::parse($date)->translatedFormat('l'),
            'total'    => $roster->count(),
            'data'     => $roster,
        ]);
    }

    // ─── Static helper: versi jadwal yang berlaku pada tanggal tertentu ─────
    //     Karena shift_schedules kini punya banyak versi (versioning dengan
    //     effective_date), ambil versi TERBARU yang sudah efektif pada $date
    //     (effective_date <= $date). Jika tidak ada versi, fallback ke versi
    //     terbaru secara global (MAX id) — misal untuk tanggal sebelum versi pertama.
    public static function scheduleForDate(int $shiftId, int $dayOfWeek, string $date): ?ShiftSchedule
    {
        $schedule = ShiftSchedule::where('shift_id', $shiftId)
            ->where('day_of_week', $dayOfWeek)
            ->where('effective_date', '<=', $date)
            ->orderByDesc('effective_date')
            ->first();

        if ($schedule) {
            return $schedule;
        }

        // Fallback: tanggal sebelum versi pertama → pakai versi terbaru
        return ShiftSchedule::where('shift_id', $shiftId)
            ->where('day_of_week', $dayOfWeek)
            ->orderByDesc('effective_date')
            ->first();
    }

    // ═══════════════════════════════════════════════════════════
    // Static helper: resolveSchedule(User, date)
    //
    // Digunakan oleh AttendanceController untuk mendapatkan jadwal
    // kerja efektif karyawan pada tanggal tertentu.
    //
    // Urutan prioritas:
    //   1. UserShift aktif dengan shift_id terisi → ShiftSchedule hari tsb
    //   2. UserShift aktif dengan shift_id = null → kembali ke office default
    //   3. Tidak ada UserShift                    → office default
    //
    // Return array:
    //   source          : 'shift' | 'office' | 'none'
    //   shift_id        : int|null
    //   shift_name      : string|null
    //   work_start_time : string|null  (format "H:i:s")
    //   work_end_time   : string|null
    //   is_off          : bool
    //   is_cross_day    : bool
    //   office          : AttendanceSetting|null (untuk late_tolerance, overtime settings)
    // ═══════════════════════════════════════════════════════════
    // Static helper: resolveSchedulesBulk(Collection|array $users, string $date)
    // BULK RESOLVER untuk ratusan/ribuan user sekaligus (Hanya 3 query total).
    // Menghindari N+1 query yang terjadi jika memanggil resolveSchedule() berulang.
    // ═══════════════════════════════════════════════════════════
    public static function resolveSchedulesBulk($users, string $date): array
    {
        $usersCollection = collect($users);
        if ($usersCollection->isEmpty()) {
            return [];
        }

        $userIds = $usersCollection->pluck('id')->filter()->unique()->values();
        $dayOfWeek = Carbon::parse($date)->dayOfWeek;

        // 1. Preload semua UserShift aktif pada tanggal $date (1 query)
        $activeAssignments = UserShift::with('shift:id,name,color,is_active')
            ->whereIn('user_id', $userIds)
            ->where('start_date', '<=', $date)
            ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $date))
            ->orderByDesc('start_date')
            ->get()
            ->groupBy('user_id');

        // 2. Kumpulkan shift_id unik yang aktif
        $relevantShiftIds = $activeAssignments->flatten()
            ->filter(fn ($a) => $a->shift_id && optional($a->shift)->is_active)
            ->pluck('shift_id')
            ->unique()
            ->values();

        // 3. Preload jadwal shift untuk day_of_week pada tanggal $date (1 query)
        $schedulesByShift = collect();
        if ($relevantShiftIds->isNotEmpty()) {
            $schedulesByShift = ShiftSchedule::whereIn('shift_id', $relevantShiftIds)
                ->where('day_of_week', $dayOfWeek)
                ->orderByDesc('effective_date')
                ->get()
                ->groupBy('shift_id')
                ->map(function ($schedules) use ($date) {
                    $match = $schedules->first(fn ($s) => $s->effective_date->toDateString() <= $date);
                    return $match ?? $schedules->first();
                });
        }

        // 4. Preload semua kantor (AttendanceSetting) yang relevan (1 query)
        $companyIds = $usersCollection->pluck('company_id')->filter()->unique()->values();
        $allOffices = AttendanceSetting::whereIn('company_id', $companyIds)->get()->keyBy('id');
        $fallbackOffices = $allOffices->groupBy('company_id')->map(fn ($g) => $g->first());

        // 5. Bangun jadwal in-memory untuk setiap user
        $results = [];
        foreach ($usersCollection as $user) {
            $userShift = $activeAssignments->get($user->id)?->first();

            $office = ($user->attendance_setting_id && $allOffices->has($user->attendance_setting_id))
                ? $allOffices->get($user->attendance_setting_id)
                : ($fallbackOffices->get($user->company_id) ?? (isset($user->office) ? $user->office : null));

            // Jika ada shift aktif & template aktif
            if ($userShift && $userShift->shift_id && optional($userShift->shift)->is_active) {
                $shiftSchedule = $schedulesByShift->get($userShift->shift_id);
                if ($shiftSchedule) {
                    $results[$user->id] = [
                        'source'          => 'shift',
                        'shift_id'        => $userShift->shift_id,
                        'shift_name'      => optional($userShift->shift)->name,
                        'work_start_time' => $shiftSchedule->work_start_time,
                        'work_end_time'   => $shiftSchedule->work_end_time,
                        'is_off'          => (bool) $shiftSchedule->is_off,
                        'is_wfh'          => (bool) $shiftSchedule->is_wfh,
                        'is_field'        => (bool) $shiftSchedule->is_field,
                        'is_cross_day'    => (bool) $shiftSchedule->is_cross_day,
                        'office'          => $office,
                    ];
                    continue;
                }
            }

            // Fallback kantor
            if ($office) {
                $workDays = $office->work_days ?? [1, 2, 3, 4, 5];
                $isOff = ! in_array($dayOfWeek, array_map('intval', (array) $workDays));

                $customStart = null;
                $customEnd = null;
                if (! $isOff && ! empty($office->custom_schedules[$dayOfWeek])) {
                    $customStart = $office->custom_schedules[$dayOfWeek]['start'] ?? null;
                    $customEnd = $office->custom_schedules[$dayOfWeek]['end'] ?? null;
                }

                $results[$user->id] = [
                    'source'          => 'office',
                    'shift_id'        => null,
                    'shift_name'      => null,
                    'work_start_time' => $isOff ? null : ($customStart ?? $office->work_start_time),
                    'work_end_time'   => $isOff ? null : ($customEnd ?? $office->work_end_time),
                    'is_off'          => (bool) $isOff,
                    'is_wfh'          => false,
                    'is_field'        => false,
                    'is_cross_day'    => false,
                    'office'          => $office,
                ];
                continue;
            }

            // Tidak ada setting
            $results[$user->id] = [
                'source'          => 'none',
                'shift_id'        => null,
                'shift_name'      => null,
                'work_start_time' => null,
                'work_end_time'   => null,
                'is_off'          => false,
                'is_wfh'          => false,
                'is_field'        => false,
                'is_cross_day'    => false,
                'office'          => null,
            ];
        }

        return $results;
    }

    // ═══════════════════════════════════════════════════════════
    // Static helper: resolveSchedule(User, date)
    // ═══════════════════════════════════════════════════════════
    public static function resolveSchedule(User $user, string $date): array
    {
        $dayOfWeek = Carbon::parse($date)->dayOfWeek; // 0=Minggu … 6=Sabtu

        // Cari shift aktif pada tanggal $date:
        // - start_date <= $date (sudah mulai)
        // - DAN (end_date is null ATAU end_date >= $date) (belum berakhir)
        // Urutkan DESC start_date untuk mengambil assignment yang paling baru yang mencakup tanggal ini.
        $userShift = UserShift::with('shift')
            ->where('user_id', $user->id)
            ->where('start_date', '<=', $date)
            ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $date))
            ->orderByDesc('start_date')
            ->first();

        // Kantor penempatan (cabang) karyawan → sumber late_tolerance, overtime, jam kerja default.
        // Prioritas: cabang yang di-assign ke karyawan (attendance_setting_id).
        // Fallback: cabang pertama perusahaan (untuk karyawan yang belum di-set cabangnya).
        $office = $user->office;
        if ($office && ! array_key_exists('work_start_time', $office->getAttributes())) {
            $office = AttendanceSetting::find($office->id);
        }
        if (! $office && $user->company_id) {
            $office = AttendanceSetting::where('company_id', $user->company_id)
                ->orderBy('id')
                ->first();
        }

        // Jika ada shift aktif dengan shift_id terisi DAN template masih aktif → gunakan jadwal shift
        if ($userShift && $userShift->shift_id && optional($userShift->shift)->is_active) {
            // Versi jadwal yang berlaku pada tanggal tsb (effective_date <= tanggal)
            $shiftSchedule = self::scheduleForDate($userShift->shift_id, $dayOfWeek, $date);

            if ($shiftSchedule) {
                return [
                    'source'          => 'shift',
                    'shift_id'        => $userShift->shift_id,
                    'shift_name'      => optional($userShift->shift)->name,
                    'work_start_time' => $shiftSchedule->work_start_time,
                    'work_end_time'   => $shiftSchedule->work_end_time,
                    'is_off'          => $shiftSchedule->is_off,
                    'is_wfh'          => (bool) $shiftSchedule->is_wfh,
                    'is_field'        => (bool) $shiftSchedule->is_field,
                    'is_cross_day'    => (bool) $shiftSchedule->is_cross_day,
                    'office'          => $office,
                ];
            }
        }

        // Fallback ke jadwal default kantor
        if ($office) {
            $workDays = $office->work_days ?? [1, 2, 3, 4, 5]; // default Sen-Jum
            $isOff = ! in_array($dayOfWeek, $workDays);

            $customStart = null;
            $customEnd = null;
            if (! $isOff && ! empty($office->custom_schedules[$dayOfWeek])) {
                $customStart = $office->custom_schedules[$dayOfWeek]['start'] ?? null;
                $customEnd = $office->custom_schedules[$dayOfWeek]['end'] ?? null;
            }

            return [
                'source'          => 'office',
                'shift_id'        => null,
                'shift_name'      => null,
                'work_start_time' => $isOff ? null : ($customStart ?? $office->work_start_time),
                'work_end_time'   => $isOff ? null : ($customEnd ?? $office->work_end_time),
                'is_off'          => $isOff,
                'is_wfh'          => false,
                'is_field'        => false,
                'is_cross_day'    => false,
                'office'          => $office,
            ];
        }

        // Tidak ada pengaturan sama sekali
        return [
            'source'          => 'none',
            'shift_id'        => null,
            'shift_name'      => null,
            'work_start_time' => null,
            'work_end_time'   => null,
            'is_off'          => false,
            'is_wfh'          => false,
            'is_field'        => false,
            'is_cross_day'    => false,
            'office'          => null,
        ];
    }

    // ═══════════════════════════════════════════════════════════
    // Static helper: resolveScheduleForCheckout(User, date)
    //
    // Saat karyawan check-out di pagi hari (misal 06:00), periksa apakah
    // ada shift cross-day dari hari KEMARIN yang masih berlaku.
    // Dipakai oleh AttendanceController::checkOut() dan AutoCheckoutCommand.
    //
    // Return null jika tidak ada shift cross-day kemarin yang cocok.
    // ═══════════════════════════════════════════════════════════
    public static function resolveYesterdayCrossDay(User $user, string $date): ?array
    {
        $yesterday = Carbon::parse($date)->subDay()->toDateString();
        $schedule = self::resolveSchedule($user, $yesterday);

        if ($schedule['source'] === 'shift' && $schedule['is_cross_day'] && ! $schedule['is_off']) {
            return $schedule;
        }

        return null;
    }

    // ═══════════════════════════════════════════════════════════
    // 7. mySchedule() — jadwal shift karyawan yang sedang login (ringkasan "hari ini")
    //    GET /api/v1/attendance/my-schedule
    //
    //    Return shift yang berlaku HARI INI beserta template 7 hari (Senin–Minggu).
    //    Jika tidak ada shift khusus → fallback ke jam kantor default.
    //
    //    PERBAIKAN (2026-08-07):
    //    Menambahkan cek end_date agar shift yang sudah kadaluarsa tidak tampil.
    //    Untuk kalender bulanan per-hari, gunakan endpoint myScheduleCalendar().
    // ═══════════════════════════════════════════════════════════
    public function mySchedule(Request $request): JsonResponse
    {
        $user = $request->user();
        $today = now('Asia/Jakarta')->toDateString();

        // Cari assignment shift yang berlaku hari ini:
        // - start_date <= hari ini
        // - end_date null (tanpa batas) ATAU end_date >= hari ini (belum kadaluarsa)
        $userShift = UserShift::with('shift.schedules', 'shift.office:id,office_name')
            ->where('user_id', $user->id)
            ->where('start_date', '<=', $today)
            ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $today))
            ->orderByDesc('start_date')
            ->first();

        $office = $user->office
            ?? AttendanceSetting::where('company_id', $user->company_id)
                ->orderBy('id')
                ->first();

        // Jika ada shift khusus dengan template DAN template masih aktif
        // (konsisten dengan resolveSchedule() yang juga cek is_active)
        if ($userShift && $userShift->shift_id && $userShift->shift && $userShift->shift->is_active) {
            $shift = $userShift->shift;
            // Versi jadwal yang berlaku HARI INI (effective_date <= hari ini)
            $schedules = collect($shift->schedulesForDate($today))->map(fn (ShiftSchedule $s) => [
                'day_of_week'     => $s->day_of_week,
                'day_name'        => $s->day_name,
                'work_start_time' => $s->is_off ? null : $s->work_start_time,
                'work_end_time'   => $s->is_off ? null : $s->work_end_time,
                'is_off'          => $s->is_off,
                'is_wfh'          => $s->is_off ? false : (bool) $s->is_wfh,
                'is_field'        => ($s->is_off || ! $s->is_wfh) ? false : (bool) $s->is_field,
                'is_cross_day'    => (bool) $s->is_cross_day,
            ])->sortBy('day_of_week')->values();

            return response()->json([
                'source' => 'shift',
                'shift'  => [
                    'name'        => $shift->name,
                    'color'       => $shift->color ?? '#6366f1',
                    'start_date'  => $userShift->start_date->toDateString(),
                    'end_date'    => $userShift->end_date?->toDateString(),
                    'office_name' => optional($shift->office)->office_name ?? optional($office)->office_name,
                ],
                'schedules' => $schedules,
            ]);
        }

        // Fallback: jadwal default kantor
        if ($office) {
            $hari = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
            $workDays = $office->work_days ?? [1, 2, 3, 4, 5];
            $schedules = collect(range(0, 6))->map(function (int $d) use ($hari, $workDays, $office) {
                $isOff = ! in_array($d, $workDays);
                $customStart = null;
                $customEnd = null;
                if (! $isOff && ! empty($office->custom_schedules[$d])) {
                    $customStart = $office->custom_schedules[$d]['start'] ?? null;
                    $customEnd = $office->custom_schedules[$d]['end'] ?? null;
                }

                return [
                    'day_of_week'     => $d,
                    'day_name'        => $hari[$d],
                    'work_start_time' => $isOff ? null : ($customStart ?? $office->work_start_time),
                    'work_end_time'   => $isOff ? null : ($customEnd ?? $office->work_end_time),
                    'is_off'          => $isOff,
                    'is_wfh'          => false,
                    'is_field'        => false,
                    'is_cross_day'    => false,
                ];
            });

            return response()->json([
                'source'    => 'office',
                'shift'     => [
                    'name'        => 'Jam Kantor Default',
                    'color'       => '#9CA3AF',
                    'start_date'  => null,
                    'end_date'    => null,
                    'office_name' => $office->office_name,
                ],
                'schedules' => $schedules,
            ]);
        }

        return response()->json([
            'source'    => 'none',
            'shift'     => null,
            'schedules' => [],
        ]);
    }

    // ═══════════════════════════════════════════════════════════
    // 8. myScheduleCalendar() — kalender jadwal kerja bulanan karyawan
    //    GET /api/v1/attendance/my-schedule-calendar?month=&year=
    //
    //    SOLUSI masalah "Jadwal Kerja Saya" di Flutter:
    //    Endpoint ini mengembalikan jadwal PER HARI selama satu bulan,
    //    ditentukan berdasarkan tanggal yang dilihat (bukan "shift aktif hari ini").
    //
    //    Logika:
    //    - Untuk setiap tanggal, cari assignment yang berlaku pada tanggal tersebut
    //      menggunakan resolveSchedule($user, $date).
    //    - resolveSchedule() sudah benar: memakai start_date <= tanggal DAN
    //      (end_date null ATAU end_date >= tanggal).
    //    - Perubahan jadwal HRD (misal: Shift B mulai 9 Agustus) langsung terlihat
    //      di kalender tanpa harus menunggu tanggal tersebut tiba.
    //
    //    Contoh response:
    //    {
    //      "month": 8, "year": 2026,
    //      "days": {
    //        "2026-08-07": {"source":"shift","shift_name":"Shift A",...},
    //        "2026-08-08": {"source":"shift","shift_name":"Shift A",...},
    //        "2026-08-09": {"source":"shift","shift_name":"Shift B",...},
    //        ...
    //      }
    //    }
    // ═══════════════════════════════════════════════════════════
    public function myScheduleCalendar(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'month' => 'nullable|integer|between:1,12',
            'year'  => 'nullable|integer|min:2020|max:2100',
        ]);

        $month = $validated['month'] ?? (int) now('Asia/Jakarta')->format('n');
        $year  = $validated['year']  ?? (int) now('Asia/Jakarta')->format('Y');

        $startOfMonth = Carbon::create($year, $month, 1)->startOfDay();
        $endOfMonth   = $startOfMonth->copy()->endOfMonth();

        // Pre-load SEMUA assignment karyawan yang berpotensi berlaku dalam bulan ini:
        // - assignment yang sudah mulai sebelum/pada akhir bulan
        // - DAN (end_date null ATAU end_date >= awal bulan) — masih berlaku di bulan ini
        // Satu query untuk seluruh bulan, bukan N query per hari (hindari N+1).
        // Eager-load shift.schedules agar loop per-hari tidak memicu N+1 query
        $assignments = UserShift::with(['shift:id,name,color,is_active', 'shift.schedules'])
            ->where('user_id', $user->id)
            ->where('start_date', '<=', $endOfMonth->toDateString())
            ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $startOfMonth->toDateString()))
            ->orderByDesc('start_date')
            ->get();

        // Kantor karyawan untuk fallback jadwal default
        $office = $user->office
            ?? AttendanceSetting::where('company_id', $user->company_id)
                ->orderBy('id')
                ->first();

        // Ambil hari libur nasional & perusahaan untuk bulan ini.
        // Libur khusus cabang hanya berlaku untuk user di cabang tsb → filter attendance_setting_id.
        $holidayCollection = \App\Models\Holiday::with('excludedUsers:id')
            ->where(function ($q) use ($user) {
                $q->whereNull('company_id')->orWhere('company_id', $user->company_id);
            })
            ->where(fn ($q) => $q->whereNull('attendance_setting_id')
                ->when($user->attendance_setting_id, fn ($qq) => $qq->orWhere('attendance_setting_id', $user->attendance_setting_id)))
            ->whereBetween('date', [$startOfMonth->toDateString(), $endOfMonth->toDateString()])
            ->orderBy('date')
            ->get(['id', 'date', 'name', 'is_national', 'is_collective', 'company_id', 'attendance_setting_id'])
            // User yang DIKECUALIKAN dari sebuah libur tidak melihat libur itu di kalender.
            ->filter(fn ($h) => ! $h->excludedUsers->contains('id', $user->id))
            ->values();

        // Map holiday per date untuk lookup O(1) — jika ada beberapa libur di tanggal sama, ambil yang nasional dulu
        $holidayByDate = $holidayCollection
            ->sortByDesc('is_national')
            ->keyBy(fn ($h) => $h->date->toDateString());

        // Format holidays untuk response root (simple, tanpa collective_summary)
        $holidaysList = $holidayCollection->map(fn ($h) => [
            'id'           => $h->id,
            'date'         => $h->date->toDateString(),
            'name'         => $h->name,
            'is_national'  => (bool) $h->is_national,
            'is_collective'=> (bool) $h->is_collective,
            'scope'        => $h->company_id ? ($h->attendance_setting_id ? 'cabang' : 'perusahaan') : 'nasional',
        ])->values();

        // Ambil cuti bersama yang sudah di-accept bulan ini
        $collectiveLeaves = \App\Models\LeaveRequest::where('user_id', $user->id)
            ->whereNotNull('holiday_id')
            ->where('collective_status', 'accepted')
            ->join('holidays', 'leave_requests.holiday_id', '=', 'holidays.id')
            ->whereBetween('holidays.date', [$startOfMonth->toDateString(), $endOfMonth->toDateString()])
            ->pluck('holidays.date')
            ->map(fn ($d) => \Carbon\Carbon::parse($d)->toDateString())
            ->flip();

        // CUTI MANDIRI (pribadi) yang sudah di-approve & memotong bulan ini.
        // Ditandai per-tanggal agar kalender mobile bisa menampilkan "CUTI MANDIRI"
        // (warna sama dgn cuti bersama, hanya label berbeda).
        $personalLeaveDates = [];
        $personalLeaves = \App\Models\LeaveRequest::where('user_id', $user->id)
            ->whereNull('holiday_id')          // bukan cuti bersama
            ->whereIn('leave_type', ['cuti', 'izin', 'sakit'])
            ->where('status', 'approved')      // hanya yang sudah disetujui HRD
            ->where('start_date', '<=', $endOfMonth->toDateString())
            ->where('end_date', '>=', $startOfMonth->toDateString())
            ->get(['start_date', 'end_date']);
        foreach ($personalLeaves as $pl) {
            for ($d = \Carbon\Carbon::parse($pl->start_date); $d->lte(\Carbon\Carbon::parse($pl->end_date)); $d->addDay()) {
                $ds = $d->toDateString();
                if ($ds >= $startOfMonth->toDateString() && $ds <= $endOfMonth->toDateString()) {
                    $personalLeaveDates[$ds] = true;
                }
            }
        }

        // WFH LEAVE yang sudah di-approve oleh HRD bulan ini.
        $wfhApprovedDates = [];
        $wfhLeaves = \App\Models\LeaveRequest::where('user_id', $user->id)
            ->where('leave_type', 'wfh')
            ->where('status', 'approved')
            ->where('start_date', '<=', $endOfMonth->toDateString())
            ->where('end_date', '>=', $startOfMonth->toDateString())
            ->get(['start_date', 'end_date']);
        foreach ($wfhLeaves as $wl) {
            for ($d = \Carbon\Carbon::parse($wl->start_date); $d->lte(\Carbon\Carbon::parse($wl->end_date)); $d->addDay()) {
                $ds = $d->toDateString();
                if ($ds >= $startOfMonth->toDateString() && $ds <= $endOfMonth->toDateString()) {
                    $wfhApprovedDates[$ds] = true;
                }
            }
        }

        $days = [];
        $current = $startOfMonth->copy();

        while ($current->lte($endOfMonth)) {
            $dateStr   = $current->toDateString();
            $dayOfWeek = $current->dayOfWeek; // 0=Minggu ... 6=Sabtu

            // Cuti bersama HANYA dianggap libur jika user sudah memilih "ikut" (accepted).
            // Jika masih pending/declined → tanggal bukan hari libur, tetap ikuti jadwal normal.
            // Konsisten dengan isNonWorkingDay() di AttendanceController (Bug #3 fix).
            $isCollectiveLeave = $collectiveLeaves->has($dateStr);
            $isPersonalLeave   = ! $isCollectiveLeave && isset($personalLeaveDates[$dateStr]);
            $isWfhApprovedDay  = isset($wfhApprovedDates[$dateStr]);

            // Cek apakah tanggal ini merupakan hari libur yang BERLAKU untuk user ini.
            $holidayObj = $holidayByDate->get($dateStr);
            if ($holidayObj && $holidayObj->is_collective && ! $isCollectiveLeave) {
                // Cuti bersama yang belum di-accept bukan hari libur bagi user ini.
                $holidayObj = null;
            }
            $holidayInfo = $holidayObj ? [
                'id'            => $holidayObj->id,
                'name'          => $holidayObj->name,
                'is_national'   => (bool) $holidayObj->is_national,
                'is_collective' => (bool) $holidayObj->is_collective,
                'scope'         => $holidayObj->company_id ? ($holidayObj->attendance_setting_id ? 'cabang' : 'perusahaan') : 'nasional',
            ] : null;
            $isHoliday = $holidayInfo !== null;

            // Warna sel kalender sesuai jenis libur:
            //   - libur nasional    → merah (#EF4444)
            //   - cuti bersama       → kuning (#FACC15)
            //   - libur perusahaan/cabang → biru (#3B82F6)
            $holidayColor = $holidayObj
                ? ($holidayObj->is_collective ? '#FACC15' : ($holidayObj->is_national ? '#EF4444' : '#3B82F6'))
                : null;

            // Cari assignment yang berlaku pada tanggal ini:
            // - start_date <= dateStr (sudah mulai)
            // - AND (end_date null OR end_date >= dateStr) (belum berakhir)
            // Collection sudah diurutkan DESC start_date → first() = assignment terbaru yang berlaku
            $active = $assignments->first(
                fn ($a) => $a->start_date->toDateString() <= $dateStr
                    && ($a->end_date === null || $a->end_date->toDateString() >= $dateStr)
            );

            $overrideColor = $holidayColor;

            // Tentukan jadwal untuk tanggal ini berdasarkan assignment yang berlaku
            if ($active && $active->shift_id && $active->shift && $active->shift->is_active) {
                // Cari jadwal dari collection schedules yang sudah di-eager-load (hindari N+1).
                // Filter: day_of_week cocok, lalu cari versi terbaru yang effective_date <= dateStr.
                $matchingSchedules = $active->shift->schedules
                    ->where('day_of_week', $dayOfWeek)
                    ->sortByDesc(fn ($s) => $s->effective_date->toDateString());

                $shiftSchedule = $matchingSchedules->first(fn ($s) => $s->effective_date->toDateString() <= $dateStr)
                    ?? $matchingSchedules->first(); // fallback: versi terbaru secara global

                if ($shiftSchedule) {
                    $isOff = (bool) $shiftSchedule->is_off;
                    $isWfh = $isOff ? false : ((bool) $shiftSchedule->is_wfh || $isWfhApprovedDay);
                    $isField = ($isOff || ! $isWfh) ? false : (bool) $shiftSchedule->is_field;
                    // Cuti mandiri approved juga memaksa hari tsb libur (sama seperti cuti bersama),
                    // dengan label & flag berbeda agar UI bisa menampilkannya sebagai "CUTI MANDIRI".
                    $forceOff = $isCollectiveLeave || $isHoliday || $isPersonalLeave;
                    // Warna cuti mandiri = kuning sama dgn cuti bersama (#FACC15)
                    $dayColor = $forceOff
                        ? ($isPersonalLeave ? '#FACC15' : ($overrideColor ?? '#EF4444'))
                        : ($active->shift->color ?? '#6366f1');
                    $days[$dateStr] = [
                        'source'          => 'shift',
                        'shift_id'        => $active->shift_id,
                        'shift_name'      => $active->shift->name,
                        'color'           => $dayColor,
                        'start_date'      => $active->start_date->toDateString(),
                        'end_date'        => $active->end_date?->toDateString(),
                        'work_start_time' => ($isOff || $forceOff) ? null : $shiftSchedule->work_start_time,
                        'work_end_time'   => ($isOff || $forceOff) ? null : $shiftSchedule->work_end_time,
                        'is_off'          => $forceOff ? true : $isOff,
                        'is_wfh'          => $forceOff ? false : $isWfh,
                        'is_field'        => $forceOff ? false : $isField,
                        'is_cross_day'    => (bool) $shiftSchedule->is_cross_day,
                        'holiday'         => $holidayInfo,
                        'personal_leave'  => $isPersonalLeave,
                        'wfh_approved'    => $isWfhApprovedDay,
                    ];
                    $current->addDay();
                    continue;
                }
            }

            // Fallback: jadwal default kantor
            if ($office) {
                $workDays = $office->work_days ?? [1, 2, 3, 4, 5];
                $isOff = ! in_array($dayOfWeek, $workDays);

                $customStart = null;
                $customEnd = null;
                if (! $isOff && ! empty($office->custom_schedules[$dayOfWeek])) {
                    $customStart = $office->custom_schedules[$dayOfWeek]['start'] ?? null;
                    $customEnd = $office->custom_schedules[$dayOfWeek]['end'] ?? null;
                }

                // Cuti mandiri approved juga memaksa hari tsb libur (sama seperti cuti bersama)
                $forceOff = $isCollectiveLeave || $isHoliday || $isPersonalLeave;
                $days[$dateStr] = [
                    'source'          => 'office',
                    'shift_id'        => null,
                    'shift_name'      => null,
                    'color'           => $forceOff
                        ? ($isPersonalLeave ? '#FACC15' : ($overrideColor ?? '#EF4444'))
                        : $overrideColor,
                    'work_start_time' => ($isOff || $forceOff) ? null : ($customStart ?? $office->work_start_time),
                    'work_end_time'   => ($isOff || $forceOff) ? null : ($customEnd ?? $office->work_end_time),
                    'is_off'          => $forceOff ? true : $isOff,
                    'is_wfh'          => $forceOff ? false : $isWfhApprovedDay,
                    'is_field'        => false,
                    'is_cross_day'    => false,
                    'holiday'         => $holidayInfo,
                    'personal_leave'  => $isPersonalLeave,
                    'wfh_approved'    => $isWfhApprovedDay,
                ];
            } else {
                // Tidak ada pengaturan kantor sama sekali
                $forceOff = $isCollectiveLeave || $isHoliday || $isPersonalLeave;
                $days[$dateStr] = [
                    'source'          => 'none',
                    'shift_id'        => null,
                    'shift_name'      => $isCollectiveLeave ? 'Cuti Bersama'
                        : ($isPersonalLeave ? 'Cuti Mandiri'
                            : ($isHoliday ? $holidayInfo['name'] : null)),
                    'color'           => $forceOff
                        ? ($isPersonalLeave ? '#FACC15' : ($overrideColor ?? '#EF4444'))
                        : $overrideColor,
                    'work_start_time' => null,
                    'work_end_time'   => null,
                    'is_off'          => $forceOff,
                    'is_wfh'          => $forceOff ? false : $isWfhApprovedDay,
                    'is_field'        => false,
                    'is_cross_day'    => false,
                    'holiday'         => $holidayInfo,
                    'personal_leave'  => $isPersonalLeave,
                    'wfh_approved'    => $isWfhApprovedDay,
                ];
            }

            $current->addDay();
        }

        return response()->json([
            'month'    => $month,
            'year'     => $year,
            'holidays' => $holidaysList,
            'days'     => $days,
        ]);
    }

    // ─── Helper: tipe notifikasi yang menandakan "jadwal shift berubah" ─────
    private static function shiftNotificationTypes(): array
    {
        return [
            'shift_assigned',
            'shift_removed',
            'shift_schedule_changed', // jam kerja template shift diubah HRD
        ];
    }

    // ═══════════════════════════════════════════════════════════
    // shiftUpdates() — cek notifikasi shift-assigned terbaru
    //   GET /api/v1/attendance/shift-updates
    //   Dipanggil Flutter untuk banner "Shift Diperbarui" di beranda.
    //   Cari notifikasi shift_assigned / shift_removed / shift_schedule_changed
    //   7 hari terakhir yang BELUM dibaca (read_at null).
    // ═══════════════════════════════════════════════════════════
    public function shiftUpdates(Request $request): JsonResponse
    {
        $user   = $request->user();
        $cutoff = now()->subDays(7);

        $notif = DB::table('notifications')
            ->where('user_id', $user->id)
            ->whereNull('read_at')
            ->whereIn('type', self::shiftNotificationTypes())
            ->where('created_at', '>=', $cutoff)
            ->orderByDesc('created_at')
            ->first();

        if (! $notif) {
            return response()->json(['has_update' => false, 'latest' => null]);
        }

        $data = json_decode($notif->data, true);

        return response()->json([
            'has_update' => true,
            'latest'     => [
                'id'         => $notif->id,
                'type'       => $notif->type,
                'note'       => $data['message'] ?? '',
                'created_at' => $notif->created_at,
            ],
        ]);
    }

    // ═══════════════════════════════════════════════════════════
    // dismissShiftUpdate() — tandai notifikasi shift sudah dibaca.
    //   POST /api/v1/attendance/dismiss-shift-update
    //   Flutter panggil ini saat user klik "OK, Saya Lihat".
    // ═══════════════════════════════════════════════════════════
    public function dismissShiftUpdate(Request $request): JsonResponse
    {
        $user = $request->user();

        DB::table('notifications')
            ->where('user_id', $user->id)
            ->whereNull('read_at')
            ->whereIn('type', self::shiftNotificationTypes())
            ->update(['read_at' => now()]);

        return response()->json(['message' => 'Notifikasi shift telah ditandai dibaca.']);
    }
}
