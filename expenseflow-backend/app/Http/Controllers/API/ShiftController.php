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

    // ─── Helper: simpan/replace 7 jadwal harian sebuah shift ──────────────
    //     Dipakai store() & update(). Selalu menimpa jadwal lama.
    private function syncSchedules(Shift $shift, array $schedules): void
    {
        $shift->schedules()->delete();

        foreach ($schedules as $sch) {
            ShiftSchedule::create([
                'shift_id'        => $shift->id,
                'day_of_week'     => $sch['day_of_week'],
                'work_start_time' => $sch['is_off'] ? null : ($sch['work_start_time'] ?? null),
                'work_end_time'   => $sch['is_off'] ? null : ($sch['work_end_time'] ?? null),
                'is_off'          => $sch['is_off'],
                'is_cross_day'    => $sch['is_cross_day'] ?? false,
            ]);
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
            'schedules.*.is_cross_day'     => 'sometimes|boolean',
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
                'color'                 => $validated['color'] ?? null,
            ]);

            $this->syncSchedules($shift, $validated['schedules']);

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

        // Validasi pindah cabang jika dikirim
        if (array_key_exists('attendance_setting_id', $validated)) {
            $branch = $this->resolveBranch($actor, $validated['attendance_setting_id']);
            if (! $branch) {
                return response()->json(['message' => 'Cabang tidak ditemukan di perusahaan Anda.'], 404);
            }
            $shift->attendance_setting_id = $branch->id;
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

        DB::transaction(function () use ($shift, $validated) {
            $shift->fill(collect($validated)->only(['name', 'description', 'color'])->toArray());
            $shift->save();

            if (isset($validated['schedules'])) {
                $this->syncSchedules($shift, $validated['schedules']);
            }
        });

        $this->logActivity(
            $actor->id,
            $actor->company_id,
            'shift_updated',
            "Mengubah template shift: {$shift->name}",
            'shift',
            $shift->id
        );

        return response()->json([
            'message'  => 'Shift berhasil diperbarui.',
            'warnings' => $k3Warnings,
            'data'     => $shift->fresh()->load(['schedules', 'office:id,office_name']),
        ]);
    }

    // ═══════════════════════════════════════════════════════════
    // 2c. destroy() — hapus template shift
    //     DELETE /api/v1/dashboard/attendance/shifts/{id}
    //     Diblokir jika masih dipakai assignment agar jadwal karyawan tidak
    //     berubah diam-diam. Sarankan nonaktifkan (is_active=false) sebagai gantinya.
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

        $dipakai = UserShift::where('shift_id', $shift->id)->count();
        if ($dipakai > 0) {
            return response()->json([
                'message' => "Shift tidak bisa dihapus karena masih dipakai {$dipakai} assignment karyawan. Nonaktifkan shift (is_active=false) jika tidak ingin dipakai lagi.",
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

        return response()->json([
            'message' => "Shift '{$shift->name}' berhasil {$status}.",
            'data'    => $shift->load(['schedules', 'office:id,office_name']),
        ]);
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

        return response()->json($history);
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
            // Harus >= start_date. Null = shift berlaku tanpa batas.
            'end_date'   => 'nullable|date|after_or_equal:start_date',
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
        if ($validated['shift_id']) {
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
            $newSchedule  = ShiftSchedule::where('shift_id', $newShift->id)
                ->where('day_of_week', $dayOfWeek)->first();
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

        // Validasi silang: end_date >= start_date
        $effectiveStart = $validated['start_date'] ?? $userShift->start_date->toDateString();
        if (! empty($validated['end_date']) && $validated['end_date'] < $effectiveStart) {
            return response()->json(['message' => 'end_date harus sama dengan atau setelah start_date.'], 422);
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
    // 4c. destroyAssignment() — hapus assignment shift karyawan
    //     DELETE /api/v1/dashboard/attendance/assignments/{id}
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
            'end_date'   => 'nullable|date|after_or_equal:start_date',
            'notes'      => 'nullable|string|max:500',
        ]);

        // Validasi shift sekali di depan (bukan per karyawan)
        $shift = null;
        if ($validated['shift_id']) {
            $shift = Shift::when(
                $actor->role !== 'super_admin',
                fn ($q) => $q->where('company_id', $actor->company_id)
            )->find($validated['shift_id']);

            if (! $shift) {
                return response()->json(['message' => 'Shift tidak ditemukan di perusahaan Anda.'], 404);
            }
        }

        // Ambil semua karyawan target sekaligus (hindari N+1)
        $targets = User::when(
            $actor->role !== 'super_admin',
            fn ($q) => $q->where('company_id', $actor->company_id)
        )->whereIn('id', $validated['user_ids'])->get()->keyBy('id');

        // Ambil start_date yang sudah terpakai untuk cegah bentrok (satu query)
        $tanggalTerpakai = UserShift::whereIn('user_id', $validated['user_ids'])
            ->where('start_date', $validated['start_date'])
            ->pluck('user_id')
            ->flip();

        $shiftName = $shift ? $shift->name : 'Default Kantor';
        $tglMulai  = Carbon::parse($validated['start_date'])->translatedFormat('d F Y');

        $berhasil = [];
        $dilewati = [];

        DB::transaction(function () use ($validated, $targets, $shift, $tanggalTerpakai, $shiftName, $tglMulai, $actor, &$berhasil, &$dilewati) {
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
                // $k3['warnings'] disertakan ke entry berhasil setelah assignment dibuat

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
                    $validated['shift_id'] ? 'shift_assigned' : 'shift_removed',
                    $validated['shift_id']
                        ? "Jadwal kerja Anda diubah ke '{$shiftName}' mulai {$tglMulai}{$tglAkhirBulk}."
                        : "Jadwal kerja Anda dikembalikan ke jam kantor default mulai {$tglMulai}.",
                    $userShift->id
                );

                $entry = ['user_id' => $uid, 'name' => $user->name, 'assignment_id' => $userShift->id];
                if (! empty($k3['warnings'])) {
                    $entry['warning'] = $k3['warnings'][0];
                }
                $berhasil[] = $entry;
            }
        });

        $this->logActivity(
            $actor->id,
            $actor->company_id,
            $validated['shift_id'] ? 'shift_assigned' : 'shift_removed',
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
        $assignments = UserShift::with('shift:id,name,color')
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
                // Assignment aktif = start_date terbaru yang ≤ tanggal ini
                $active = $userAssignments->first(
                    fn ($a) => $a->start_date->toDateString() <= $dateStr
                );

                if ($active && $active->shift_id && $active->shift) {
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
            ->with('office:id,office_name')
            ->orderBy('name')
            ->get();

        // Susun baris roster: identitas karyawan + jadwal efektif tanggal tsb
        $roster = $users->map(function (User $user) use ($date) {
            $schedule = self::resolveSchedule($user, $date);

            return [
                'user_id'                => $user->id,
                'attendance_setting_id'  => $user->attendance_setting_id,
                'name'                   => $user->name,
                'department'             => $user->department,
                'branch'                 => optional($user->office)->office_name,
                'source'                 => $schedule['source'],   // 'shift' | 'office' | 'none'
                'shift_name'             => $schedule['shift_name'],
                'work_start_time'        => $schedule['work_start_time'],
                'work_end_time'          => $schedule['work_end_time'],
                'is_off'                 => $schedule['is_off'],
                'is_cross_day'           => $schedule['is_cross_day'] ?? false,
            ];
        });

        return response()->json([
            'date'     => $date,
            'day_name' => Carbon::parse($date)->translatedFormat('l'),
            'total'    => $roster->count(),
            'data'     => $roster,
        ]);
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
    public static function resolveSchedule(User $user, string $date): array
    {
        $dayOfWeek = Carbon::parse($date)->dayOfWeek; // 0=Minggu … 6=Sabtu

        // Cari shift aktif: start_date terbaru yang sudah <= tanggal ini
        $userShift = UserShift::with('shift')
            ->where('user_id', $user->id)
            ->where('start_date', '<=', $date)
            ->orderByDesc('start_date')
            ->first();

        // Kantor penempatan (cabang) karyawan → sumber late_tolerance, overtime, jam kerja default.
        // Prioritas: cabang yang di-assign ke karyawan (attendance_setting_id).
        // Fallback: cabang pertama perusahaan (untuk karyawan yang belum di-set cabangnya).
        $office = $user->office
            ?? AttendanceSetting::where('company_id', $user->company_id)
                ->orderBy('id')
                ->first();

        // Cek apakah assignment masih berlaku berdasarkan end_date:
        //   - end_date = null  → berlaku tanpa batas (perilaku lama)
        //   - end_date terisi  → shift hanya berlaku sampai end_date (inklusif).
        //     Jika tanggal yang diminta > end_date → shift sudah berakhir;
        //     karyawan otomatis kembali ke jam default kantor.
        $shiftStillActive = $userShift
            && ($userShift->end_date === null || $userShift->end_date->toDateString() >= $date);

        // Jika ada shift aktif dengan shift_id terisi DAN template masih aktif → gunakan jadwal shift
        if ($shiftStillActive && $userShift->shift_id && optional($userShift->shift)->is_active) {
            $shiftSchedule = ShiftSchedule::where('shift_id', $userShift->shift_id)
                ->where('day_of_week', $dayOfWeek)
                ->first();

            if ($shiftSchedule) {
                return [
                    'source'          => 'shift',
                    'shift_id'        => $userShift->shift_id,
                    'shift_name'      => optional($userShift->shift)->name,
                    'work_start_time' => $shiftSchedule->work_start_time,
                    'work_end_time'   => $shiftSchedule->work_end_time,
                    'is_off'          => $shiftSchedule->is_off,
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
    // 7. mySchedule() — jadwal shift karyawan yang sedang login
    //    GET /api/v1/employee/my-schedule
    //
    //    Return shift aktif + jadwal 7 hari (Senin–Minggu).
    //    Jika tidak ada shift khusus → fallback ke jam kantor default.
    // ═══════════════════════════════════════════════════════════
    public function mySchedule(Request $request): JsonResponse
    {
        $user = $request->user();
        $today = now('Asia/Jakarta')->toDateString();

        // Cari assignment shift aktif
        $userShift = UserShift::with('shift.schedules', 'shift.office:id,office_name')
            ->where('user_id', $user->id)
            ->where('start_date', '<=', $today)
            ->orderByDesc('start_date')
            ->first();

        $office = $user->office
            ?? AttendanceSetting::where('company_id', $user->company_id)
                ->orderBy('id')
                ->first();

        // Jika ada shift khusus dengan template
        if ($userShift && $userShift->shift_id && $userShift->shift) {
            $shift = $userShift->shift;
            $schedules = $shift->schedules->map(fn (ShiftSchedule $s) => [
                'day_of_week'     => $s->day_of_week,
                'day_name'        => $s->day_name,
                'work_start_time' => $s->is_off ? null : $s->work_start_time,
                'work_end_time'   => $s->is_off ? null : $s->work_end_time,
                'is_off'          => $s->is_off,
                'is_cross_day'    => (bool) $s->is_cross_day,
            ])->sortBy('day_of_week')->values();

            return response()->json([
                'source' => 'shift',
                'shift'  => [
                    'name'        => $shift->name,
                    'color'       => $shift->color ?? '#6366f1',
                    'start_date'  => $userShift->start_date->toDateString(),
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
                    'is_cross_day'    => false,
                ];
            });

            return response()->json([
                'source'    => 'office',
                'shift'     => [
                    'name'        => 'Jam Kantor Default',
                    'color'       => '#6366f1',
                    'start_date'  => null,
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
}
