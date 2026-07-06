<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\AttendanceSetting;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\User;
use App\Models\UserShift;
use App\Services\FcmService;
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
                // Saat hari libur shift, jam kerja tidak relevan → simpan null
                'work_start_time' => $sch['is_off'] ? null : ($sch['work_start_time'] ?? null),
                'work_end_time'   => $sch['is_off'] ? null : ($sch['work_end_time'] ?? null),
                'is_off'          => $sch['is_off'],
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
            'is_active'                    => 'boolean',
            // Cabang pemilik shift (wajib). Null hanya boleh untuk super_admin (company-wide).
            'attendance_setting_id'        => 'required|integer',
            'schedules'                    => 'required|array|size:7',
            'schedules.*.day_of_week'      => 'required|integer|between:0,6',
            'schedules.*.work_start_time'  => 'nullable|date_format:H:i',
            'schedules.*.work_end_time'    => 'nullable|date_format:H:i',
            'schedules.*.is_off'           => 'required|boolean',
        ]);

        // Validasi 7 hari unik + jam kerja terisi untuk hari non-libur
        if ($err = $this->validateSchedules($validated['schedules'])) {
            return response()->json(['message' => $err], 422);
        }

        // Pastikan cabang milik perusahaan aktor
        $branch = $this->resolveBranch($actor, $validated['attendance_setting_id']);
        if (! $branch) {
            return response()->json(['message' => 'Cabang tidak ditemukan di perusahaan Anda.'], 404);
        }

        $shift = DB::transaction(function () use ($validated, $actor, $branch) {
            $shift = Shift::create([
                'company_id'            => $actor->company_id,
                'attendance_setting_id' => $branch->id,
                'name'                  => $validated['name'],
                'description'           => $validated['description'] ?? null,
                'is_active'             => $validated['is_active'] ?? true,
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
            'message' => 'Shift berhasil dibuat.',
            'data'    => $shift->load(['schedules', 'office:id,office_name']),
        ], 201);
    }

    // ─── Helper: validasi array 7 jadwal harian ──────────────────────────
    //     Return string error bila tidak valid; null bila valid.
    private function validateSchedules(array $schedules): ?string
    {
        // Harus tepat 7 hari unik (0–6)
        $hari = collect($schedules)->pluck('day_of_week')->sort()->values()->toArray();
        if ($hari !== [0, 1, 2, 3, 4, 5, 6]) {
            return 'Jadwal harus mencakup tepat 7 hari unik (0=Minggu hingga 6=Sabtu).';
        }

        foreach ($schedules as $sch) {
            if (! empty($sch['is_off'])) {
                continue; // hari libur shift → jam boleh kosong
            }

            // Hari kerja wajib punya jam masuk & pulang
            if (empty($sch['work_start_time']) || empty($sch['work_end_time'])) {
                return "Hari kerja (day_of_week {$sch['day_of_week']}) wajib mengisi jam masuk & jam pulang.";
            }

            // Cegah shift lintas tengah malam (jam pulang <= jam masuk) — perhitungan
            // lembur belum mendukungnya, jadi ditolak agar data tidak salah.
            if ($sch['work_end_time'] <= $sch['work_start_time']) {
                return "Jam pulang harus lebih besar dari jam masuk (day_of_week {$sch['day_of_week']}). Shift lintas tengah malam belum didukung.";
            }
        }

        return null;
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
            'is_active'                    => 'sometimes|boolean',
            'attendance_setting_id'        => 'sometimes|required|integer',
            'schedules'                    => 'sometimes|required|array|size:7',
            'schedules.*.day_of_week'      => 'required_with:schedules|integer|between:0,6',
            'schedules.*.work_start_time'  => 'nullable|date_format:H:i',
            'schedules.*.work_end_time'    => 'nullable|date_format:H:i',
            'schedules.*.is_off'           => 'required_with:schedules|boolean',
        ]);

        // Validasi jadwal jika dikirim
        if (isset($validated['schedules']) && ($err = $this->validateSchedules($validated['schedules']))) {
            return response()->json(['message' => $err], 422);
        }

        // Validasi pindah cabang jika dikirim
        if (array_key_exists('attendance_setting_id', $validated)) {
            $branch = $this->resolveBranch($actor, $validated['attendance_setting_id']);
            if (! $branch) {
                return response()->json(['message' => 'Cabang tidak ditemukan di perusahaan Anda.'], 404);
            }
            $shift->attendance_setting_id = $branch->id;
        }

        DB::transaction(function () use ($shift, $validated) {
            $shift->fill(collect($validated)->only(['name', 'description', 'is_active'])->toArray());
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
            'message' => 'Shift berhasil diperbarui.',
            'data'    => $shift->fresh()->load(['schedules', 'office:id,office_name']),
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

        $userShift = UserShift::create([
            'user_id'    => $validated['user_id'],
            'shift_id'   => $validated['shift_id'],
            'start_date' => $validated['start_date'],
            'notes'      => $validated['notes'] ?? null,
        ]);

        // Catat aktivitas ke log
        $action = $validated['shift_id'] ? 'shift_assigned' : 'shift_removed';
        $shiftName = $shift ? $shift->name : 'Default Kantor';
        $description = $validated['shift_id']
            ? "Assign shift '{$shiftName}' ke {$targetUser->name} mulai {$validated['start_date']}"
            : "Hapus shift khusus {$targetUser->name} mulai {$validated['start_date']} (kembali ke default kantor)";

        $this->logActivity($actor->id, $actor->company_id, $action, $description, 'user', $targetUser->id);

        // Beri tahu karyawan bahwa jadwalnya berubah
        $tglMulai = Carbon::parse($validated['start_date'])->translatedFormat('d F Y');
        $this->notifyEmployee(
            $targetUser,
            'shift_assigned',
            $validated['shift_id']
                ? "Jadwal kerja Anda diubah ke '{$shiftName}' mulai {$tglMulai}."
                : "Jadwal kerja Anda dikembalikan ke jam kantor default mulai {$tglMulai}.",
            $userShift->id
        );

        return response()->json([
            'message' => $validated['shift_id']
                ? "Shift '{$shiftName}' berhasil di-assign ke {$targetUser->name}."
                : "Shift karyawan dikembalikan ke default kantor mulai {$validated['start_date']}.",
            'data'    => $userShift->load('shift.schedules'),
        ], 201);
    }

    // ─── Helper: pastikan cabang shift cocok dengan cabang karyawan ──────────
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
            'notes'      => 'nullable|string|max:500',
        ]);

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

        $userShift->fill(collect($validated)->only(['shift_id', 'start_date', 'notes'])->toArray());
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
            'message' => 'Assignment berhasil diperbarui.',
            'data'    => $userShift->fresh()->load('shift.schedules'),
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

                $userShift = UserShift::create([
                    'user_id'    => $uid,
                    'shift_id'   => $validated['shift_id'],
                    'start_date' => $validated['start_date'],
                    'notes'      => $validated['notes'] ?? null,
                ]);

                $this->notifyEmployee(
                    $user,
                    $validated['shift_id'] ? 'shift_assigned' : 'shift_removed',
                    $validated['shift_id']
                        ? "Jadwal kerja Anda diubah ke '{$shiftName}' mulai {$tglMulai}."
                        : "Jadwal kerja Anda dikembalikan ke jam kantor default mulai {$tglMulai}.",
                    $userShift->id
                );

                $berhasil[] = ['user_id' => $uid, 'name' => $user->name, 'assignment_id' => $userShift->id];
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
            ->where('attendance_enabled', true) // hanya karyawan yang pakai presensi mobile
            ->with('office:id,office_name')
            ->orderBy('name')
            ->get();

        // Susun baris roster: identitas karyawan + jadwal efektif tanggal tsb
        $roster = $users->map(function (User $user) use ($date) {
            $schedule = self::resolveSchedule($user, $date);

            return [
                'user_id'         => $user->id,
                'name'            => $user->name,
                'department'      => $user->department,
                'branch'          => optional($user->office)->office_name,
                'source'          => $schedule['source'],   // 'shift' | 'office' | 'none'
                'shift_name'      => $schedule['shift_name'],
                'work_start_time' => $schedule['work_start_time'],
                'work_end_time'   => $schedule['work_end_time'],
                'is_off'          => $schedule['is_off'],
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

        // Jika ada shift aktif dengan shift_id terisi → gunakan jadwal shift
        if ($userShift && $userShift->shift_id) {
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
                    'office'          => $office,
                ];
            }
        }

        // Fallback ke jadwal default kantor
        if ($office) {
            return [
                'source'          => 'office',
                'shift_id'        => null,
                'shift_name'      => null,
                'work_start_time' => $office->work_start_time,
                'work_end_time'   => $office->work_end_time,
                'is_off'          => false,
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
            'office'          => null,
        ];
    }
}
