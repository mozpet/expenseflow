<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\AttendanceSetting;
use App\Models\DeviceChangeRequest;
use App\Models\Holiday;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\OvertimeApproval;
use App\Models\ShiftSchedule;
use App\Models\User;
use App\Models\UserShift;
use App\Services\FcmService;
use App\Services\LocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttendanceController extends Controller
{
    // ─── Helper: catat aktivitas ──────────────────────────────
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

    // ─── Helper: kirim notifikasi ke user ─────────────────────
    private function notifyUser(int $userId, string $type, array $data, ?string $entityType = null, ?int $entityId = null): void
    {
        DB::table('notifications')->insert([
            'id'              => Str::uuid()->toString(),
            'type'            => $type,
            'notifiable_type' => 'App\\Models\\User',
            'notifiable_id'   => $userId,
            'user_id'         => $userId,
            'data'            => json_encode($data),
            'entity_type'     => $entityType,
            'entity_id'       => $entityId,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);
    }

    // ─── Helper: tanggal hari ini dalam zona waktu WIB (Asia/Jakarta) ────
    //     Presensi mengacu waktu lokal karyawan, bukan UTC.
    //     Tanpa ini, antara 00:00–06:59 WIB (= 17:00–23:59 UTC hari sebelumnya)
    //     `now()->toDateString()` mengembalikan tanggal kemarin sehingga sistem
    //     menemukan record check-in kemarin dan menolak check-in hari ini.
    private function todayDate(): string
    {
        return now('Asia/Jakarta')->toDateString();
    }

    // ─── Helper: ambil jadwal kerja efektif karyawan pada tanggal tertentu ────
    //     Mempertimbangkan shift khusus jika ada; fallback ke attendance_settings kantor.
    //     Delegasi ke ShiftController::resolveSchedule() agar logika terpusat.
    private function getWorkSchedule(User $user, string $date): array
    {
        return \App\Http\Controllers\API\ShiftController::resolveSchedule($user, $date);
    }

    // ─── Helper: tentukan status hadir/telat berdasarkan jam kerja ────
    //     Mempertimbangkan shift aktif karyawan; fallback ke kantor default.
    private function determineStatus(User $user, Carbon $checkInTime, string $date): string
    {
        $schedule = $this->getWorkSchedule($user, $date);

        $workStartTime = $schedule['work_start_time'];
        $office        = $schedule['office'];

        // Tanpa jam masuk sebagai acuan → anggap hadir
        if (! $workStartTime || ! $office) {
            return 'present';
        }

        $tanggalWib = $checkInTime->copy()->setTimezone('Asia/Jakarta')->toDateString();
        $batasTelat = Carbon::parse($tanggalWib . ' ' . $workStartTime, 'Asia/Jakarta')
            ->addMinutes((int) $office->late_tolerance_minutes);

        return $checkInTime->copy()->setTimezone('Asia/Jakarta')->greaterThan($batasTelat) ? 'late' : 'present';
    }

    // Hanya cuti yang punya kuota (12 hari/tahun). Izin & sakit tidak terbatas, hanya dihitung.
    private const DEFAULT_LEAVE_QUOTA = ['cuti' => 12];

    // ─── Helper: apakah tanggal bukan hari kerja (weekend atau libur) ────
    //     Dipakai untuk perhitungan lembur & total hari cuti.
    //     Libur cocok bila `company_id` sama dengan perusahaan ATAU libur nasional (NULL).
    private function isNonWorkingDay(string $date, ?int $companyId): bool
    {
        if (Carbon::parse($date)->isWeekend()) {
            return true;
        }

        return Holiday::whereDate('date', $date)
            ->where(function ($q) use ($companyId) {
                $q->whereNull('company_id')->orWhere('company_id', $companyId);
            })
            ->exists();
    }

    // ─── Helper: hitung jumlah HARI KERJA dalam rentang (inklusif) ────
    //     Lewati weekend & libur. Dipakai saat pengajuan cuti agar kuota adil.
    private function countWorkingDays(Carbon $start, Carbon $end, ?int $companyId): int
    {
        // Ambil daftar libur dalam rentang sekali query (hindari N+1).
        $holidays = Holiday::whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->where(function ($q) use ($companyId) {
                $q->whereNull('company_id')->orWhere('company_id', $companyId);
            })
            ->pluck('date')
            ->map(fn ($d) => Carbon::parse($d)->toDateString())
            ->flip();

        $count = 0;
        for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
            if ($day->isWeekend()) {
                continue;
            }
            if ($holidays->has($day->toDateString())) {
                continue;
            }
            $count++;
        }

        return $count;
    }

    // ─── Helper: format menit → "Xj Ym" ──────────────────────────
    private function formatMinutes(?int $minutes): string
    {
        $m = (int) $minutes;
        if ($m <= 0) {
            return '0j';
        }
        $jam = intdiv($m, 60);
        $sisa = $m % 60;

        return $sisa === 0 ? "{$jam}j" : "{$jam}j {$sisa}m";
    }

    // ═══════════════════════════════════════════════════════════
    // BAGIAN A — HRD / Admin / Super Admin (prefix dashboard)
    // ═══════════════════════════════════════════════════════════

    // 1. toggleAttendance() — aktif/nonaktifkan fitur presensi user
    public function toggleAttendance(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();

        $target = User::when(
            $actor->role !== 'super_admin',
            fn ($q) => $q->where('company_id', $actor->company_id)
        )->find($id);

        if (! $target) {
            return response()->json(['message' => 'User tidak ditemukan di perusahaan Anda.'], 404);
        }

        $target->attendance_enabled = ! $target->attendance_enabled;
        $target->save();

        $this->logActivity(
            $actor->id,
            $target->company_id,
            'attendance_toggled',
            ($target->attendance_enabled ? 'Mengaktifkan' : 'Menonaktifkan') . ' presensi untuk ' . $target->name,
            'user',
            $target->id
        );

        return response()->json([
            'message' => 'Status presensi user berhasil diperbarui.',
            'user'    => [
                'id'                 => $target->id,
                'name'               => $target->name,
                'attendance_enabled' => $target->attendance_enabled,
            ],
        ]);
    }

    // 1b. toggleWfh() — aktif/nonaktifkan mode WFH user (tombol di web HRD)
    //     true  → karyawan presensi dari rumah
    //     false → karyawan presensi dari kantor (cek lokasi)
    public function toggleWfh(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();

        $target = User::when(
            $actor->role !== 'super_admin',
            fn ($q) => $q->where('company_id', $actor->company_id)
        )->find($id);

        if (! $target) {
            return response()->json(['message' => 'User tidak ditemukan di perusahaan Anda.'], 404);
        }

        $target->wfh_enabled = ! $target->wfh_enabled;
        // Switch WFH = satu-satunya gerbang presensi mobile.
        // attendance_enabled disinkronkan: ON saat WFH, OFF saat bukan WFH (presensi kantor via hardware).
        $target->attendance_enabled = $target->wfh_enabled;
        $target->save();

        $this->logActivity(
            $actor->id,
            $target->company_id,
            'wfh_toggled',
            ($target->wfh_enabled ? 'Mengaktifkan' : 'Menonaktifkan') . ' mode WFH untuk ' . $target->name,
            'user',
            $target->id
        );

        return response()->json([
            'message' => $target->wfh_enabled
                ? 'Mode WFH diaktifkan — karyawan bisa presensi dari rumah lewat aplikasi.'
                : 'Mode WFH dinonaktifkan — presensi mobile dimatikan, presensi kantor lewat perangkat presensi.',
            'user' => [
                'id'                 => $target->id,
                'name'               => $target->name,
                'wfh_enabled'        => $target->wfh_enabled,
                'attendance_enabled' => $target->attendance_enabled,
            ],
        ]);
    }

    // 1c. toggleRadius() — aktif/nonaktifkan validasi radius untuk karyawan lapangan
    //     true  → presensi mobile wajib berada dalam radius lokasi kerja
    //     false → presensi mobile bebas (WFH dari rumah, tanpa cek lokasi)
    //     Catatan: radius hanya berlaku jika wfh_enabled = true (mobile aktif).
    public function toggleRadius(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();

        $target = User::when(
            $actor->role !== 'super_admin',
            fn ($q) => $q->where('company_id', $actor->company_id)
        )->find($id);

        if (! $target) {
            return response()->json(['message' => 'User tidak ditemukan di perusahaan Anda.'], 404);
        }

        $target->radius_enabled = ! $target->radius_enabled;
        $target->save();

        $this->logActivity(
            $actor->id,
            $target->company_id,
            'radius_toggled',
            ($target->radius_enabled ? 'Mengaktifkan' : 'Menonaktifkan') . ' validasi radius untuk ' . $target->name,
            'user',
            $target->id
        );

        return response()->json([
            'message' => $target->radius_enabled
                ? 'Validasi radius diaktifkan — karyawan harus presensi di sekitar area kerja.'
                : 'Validasi radius dinonaktifkan — karyawan bisa presensi dari mana saja (WFH).',
            'user' => [
                'id'             => $target->id,
                'name'           => $target->name,
                'wfh_enabled'    => $target->wfh_enabled,
                'radius_enabled' => $target->radius_enabled,
            ],
        ]);
    }

    // 2. listUsers() — daftar user + status attendance_enabled
    public function listUsers(Request $request): JsonResponse
    {
        $actor  = $request->user();
        $filter = $request->query('filter'); // enabled | disabled

        $query = User::query()
            ->when(
                $actor->role !== 'super_admin',
                fn ($q) => $q->where('company_id', $actor->company_id)
            )
            ->select(['id', 'name', 'email', 'role', 'department', 'attendance_enabled', 'wfh_enabled', 'radius_enabled', 'is_active']);

        if ($filter === 'enabled') {
            $query->where('attendance_enabled', true);
        } elseif ($filter === 'disabled') {
            $query->where('attendance_enabled', false);
        }

        return response()->json($query->orderBy('name')->paginate(20));
    }

    // 3. approveLeave() — setujui permintaan cuti/izin
    public function approveLeave(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();

        $leave = LeaveRequest::when(
            $actor->role !== 'super_admin',
            fn ($q) => $q->where('company_id', $actor->company_id)
        )->find($id);

        if (! $leave) {
            return response()->json(['message' => 'Permintaan tidak ditemukan.'], 404);
        }

        if ($leave->status !== 'pending') {
            return response()->json(['message' => 'Permintaan sudah diproses sebelumnya.'], 403);
        }

        $balance = null;
        $year    = Carbon::parse($leave->start_date)->year;

        if ($leave->leave_type === 'cuti') {
            // Cuti: cek & potong kuota (max 12 hari/tahun)
            $balance = LeaveBalance::firstOrCreate(
                ['user_id' => $leave->user_id, 'year' => $year, 'leave_type' => 'cuti'],
                ['company_id' => $leave->company_id, 'quota' => 12, 'used' => 0]
            );
            $remaining = $balance->quota - $balance->used;
            if ($leave->total_days > $remaining) {
                return response()->json([
                    'message' => "Saldo cuti tidak cukup. Sisa {$remaining} hari, diminta {$leave->total_days} hari.",
                ], 422);
            }
        } elseif (in_array($leave->leave_type, ['izin', 'sakit'])) {
            // Izin & sakit: tidak ada batas kuota, hanya dihitung di kolom 'izin'
            $balance = LeaveBalance::firstOrCreate(
                ['user_id' => $leave->user_id, 'year' => $year, 'leave_type' => 'izin'],
                ['company_id' => $leave->company_id, 'quota' => 0, 'used' => 0]
            );
        }

        $leave->update([
            'status'      => 'approved',
            'approved_by' => $actor->id,
            'approved_at' => now(),
        ]);

        if ($balance) {
            $balance->increment('used', $leave->total_days);
        }

        $this->logActivity(
            $actor->id,
            $leave->company_id,
            'leave_approved',
            "Approve {$leave->leave_type} #{$leave->id}",
            'leave_request',
            $leave->id
        );

        $this->notifyUser($leave->user_id, 'leave_approved', [
            'message'         => "Permintaan {$leave->leave_type} Anda telah disetujui.",
            'leave_id'        => $leave->id,
            'leave_type'      => $leave->leave_type,
            'status'          => 'approved',
        ], 'leave_request', $leave->id);

        return response()->json([
            'message' => 'Permintaan berhasil disetujui.',
            'leave'   => $leave->only(['id', 'leave_type', 'status', 'approved_by', 'approved_at']),
        ]);
    }

    // 4. rejectLeave() — tolak permintaan (wajib rejection_reason)
    public function rejectLeave(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'rejection_reason' => 'required|string|max:1000',
        ]);

        $actor = $request->user();

        $leave = LeaveRequest::when(
            $actor->role !== 'super_admin',
            fn ($q) => $q->where('company_id', $actor->company_id)
        )->find($id);

        if (! $leave) {
            return response()->json(['message' => 'Permintaan tidak ditemukan.'], 404);
        }

        if ($leave->status !== 'pending') {
            return response()->json(['message' => 'Permintaan sudah diproses sebelumnya.'], 403);
        }

        $leave->update([
            'status'           => 'rejected',
            'approved_by'      => $actor->id,
            'approved_at'      => now(),
            'rejection_reason' => $request->rejection_reason,
        ]);

        $this->logActivity(
            $actor->id,
            $leave->company_id,
            'leave_rejected',
            "Reject {$leave->leave_type} #{$leave->id}: {$request->rejection_reason}",
            'leave_request',
            $leave->id
        );

        $this->notifyUser($leave->user_id, 'leave_rejected', [
            'message'          => "Permintaan {$leave->leave_type} Anda ditolak.",
            'leave_id'         => $leave->id,
            'leave_type'       => $leave->leave_type,
            'status'           => 'rejected',
            'rejection_reason' => $request->rejection_reason,
        ], 'leave_request', $leave->id);

        return response()->json([
            'message' => 'Permintaan berhasil ditolak.',
            'leave'   => $leave->only(['id', 'leave_type', 'status', 'rejection_reason']),
        ]);
    }

    // 4b. listLeaves() — daftar pengajuan izin/cuti untuk HRD (filter status/tipe/user)
    public function listLeaves(Request $request): JsonResponse
    {
        $actor = $request->user();

        $validated = $request->validate([
            'status'     => 'nullable|in:pending,approved,rejected',
            'leave_type' => 'nullable|in:wfh,izin,sakit,cuti',
            'user_id'    => 'nullable|integer',
        ]);

        $leaves = LeaveRequest::query()
            ->join('users', 'leave_requests.user_id', '=', 'users.id')
            ->when(
                $actor->role !== 'super_admin',
                fn ($q) => $q->where('leave_requests.company_id', $actor->company_id)
            )
            ->when($validated['status'] ?? null, fn ($q, $s) => $q->where('leave_requests.status', $s))
            ->when($validated['leave_type'] ?? null, fn ($q, $t) => $q->where('leave_requests.leave_type', $t))
            ->when($validated['user_id'] ?? null, fn ($q, $u) => $q->where('leave_requests.user_id', $u))
            ->select([
                'leave_requests.id', 'leave_requests.user_id', 'users.name as user_name',
                'users.department', 'leave_requests.leave_type', 'leave_requests.start_date',
                'leave_requests.end_date', 'leave_requests.total_days', 'leave_requests.reason',
                'leave_requests.document_path',
                'leave_requests.status', 'leave_requests.rejection_reason',
                'leave_requests.approved_by', 'leave_requests.approved_at', 'leave_requests.created_at',
            ])
            ->orderByDesc('leave_requests.created_at')
            ->paginate(20);

        // Sertakan flag has_document agar web tahu kapan menampilkan tombol surat dokter
        $leaves->getCollection()->transform(function ($l) {
            $l->has_document = ! empty($l->document_path);
            unset($l->document_path); // path internal tidak perlu bocor ke client
            return $l;
        });

        return response()->json($leaves);
    }

    // 4b-2. leaveDocument() — sajikan surat dokter (privat).
    //       Boleh diakses HRD/admin/super_admin sekompanyi, atau pemilik pengajuan.
    public function leaveDocument(Request $request, LeaveRequest $leave)
    {
        $user = $request->user();

        // Pemilik pengajuan selalu boleh; selain itu harus sekompanyi (super_admin bebas).
        $isOwner    = $leave->user_id === $user->id;
        $sameCompany = $user->role === 'super_admin' || $leave->company_id === $user->company_id;

        if (! $isOwner && ! $sameCompany) {
            return response()->json(['message' => 'Anda tidak berhak mengakses dokumen ini.'], 403);
        }

        if (! $leave->document_path || ! Storage::disk('local')->exists($leave->document_path)) {
            return response()->json(['message' => 'Dokumen tidak ditemukan.'], 404);
        }

        $fullPath = Storage::disk('local')->path($leave->document_path);
        $mime     = str_ends_with(strtolower($leave->document_path), '.pdf')
            ? 'application/pdf'
            : (mime_content_type($fullPath) ?: 'application/octet-stream');

        return response()->file($fullPath, ['Content-Type' => $mime]);
    }

    // 4c. today() — dashboard presensi hari ini untuk HRD
    public function today(Request $request): JsonResponse
    {
        $actor = $request->user();
        $today = $this->todayDate();

        // Semua karyawan aktif (WFH maupun onsite)
        $employees = User::query()
            ->when($actor->role !== 'super_admin', fn ($q) => $q->where('company_id', $actor->company_id))
            ->where('is_active', true)
            ->whereIn('role', ['employee', 'finance', 'hrd', 'admin'])
            ->select(['id', 'name', 'department', 'wfh_enabled', 'radius_enabled', 'company_id'])
            ->orderBy('name')
            ->get();

        // Presensi hari ini, di-index per user_id
        // where() (bukan whereDate) agar index('date') terpakai — kolom sudah bertipe DATE
        $yesterday = Carbon::parse($today)->subDay()->toDateString();

        // Presensi hari ini
        $attendancesToday = Attendance::where('date', $today)
            ->when($actor->role !== 'super_admin', fn ($q) => $q->where('company_id', $actor->company_id))
            ->get()->keyBy('user_id');

        // Presensi shift malam kemarin yang belum checkout ATAU sudah checkout hari ini (cross-day)
        $attendancesYesterday = Attendance::where('date', $yesterday)
            ->when($actor->role !== 'super_admin', fn ($q) => $q->where('company_id', $actor->company_id))
            ->get()
            ->filter(function ($att) use ($today) {
                if (is_null($att->check_out_time)) return true;
                
                $checkoutCarbon = \Carbon\Carbon::parse($att->check_out_time)->timezone('Asia/Jakarta');
                $checkoutDateLocal = $checkoutCarbon->format('Y-m-d');
                
                if ($checkoutDateLocal !== $today) return false;

                // Threshold auto-update: jika sudah lewat 4 jam sejak check-out, 
                // data shift malam ini tidak lagi masuk ke "Sudah Check-In" 
                // agar karyawan bisa masuk ke status jadwal barunya di hari ini (Belum Check-In / Libur).
                $hoursSinceCheckout = $checkoutCarbon->diffInHours(\Carbon\Carbon::now('Asia/Jakarta'));
                return $hoursSinceCheckout < 4;
            })
            ->keyBy('user_id');

        // Gabungkan: record hari ini mengalahkan kemarin untuk user yang sama
        $attendances = $attendancesYesterday->replace($attendancesToday);

        // Izin/cuti disetujui yang mencakup hari ini, di-index per user_id
        $onLeave = LeaveRequest::where('status', 'approved')
            ->where('start_date', '<=', $today)
            ->where('end_date', '>=', $today)
            ->when($actor->role !== 'super_admin', fn ($q) => $q->where('company_id', $actor->company_id))
            ->get()->keyBy('user_id');

        $checkedIn = [];
        $notCheckedIn = [];
        $leaveList = [];

        // Cek apakah hari ini libur nasional/perusahaan
        $todayHoliday = \App\Models\Holiday::whereDate('date', $today)
            ->where(function ($q) use ($actor) {
                $q->whereNull('company_id')
                  ->orWhere('company_id', $actor->company_id);
            })->exists();

        foreach ($employees as $emp) {
            $att = $attendances[$emp->id] ?? null;

            if ($att && $att->check_in_time) {
                // Shift malam kemarin (cross-day)
                $isCrossDay = \Carbon\Carbon::parse($att->date)->format('Y-m-d') === $yesterday;
                $checkedIn[] = [
                    'user_id'        => $emp->id,
                    'name'           => $emp->name,
                    'department'     => $emp->department,
                    'check_in_time'  => $att->check_in_time,
                    'check_out_time' => $att->check_out_time,
                    'check_in_type'  => $att->check_in_type,
                    'status'         => $att->status,
                    'shift_date'     => Carbon::parse($att->date)->format('Y-m-d'),
                    'checkout_date'  => $isCrossDay ? $today : null,
                    'is_cross_day'   => $isCrossDay,
                ];
            } elseif (isset($onLeave[$emp->id])) {
                $leaveList[] = [
                    'user_id'    => $emp->id,
                    'name'       => $emp->name,
                    'department' => $emp->department,
                    'leave_type' => $onLeave[$emp->id]->leave_type,
                ];
            } else {
                // Cek apakah hari ini hari libur sesuai jadwal karyawan
                $empModel = $emp instanceof \App\Models\User
                    ? $emp
                    : \App\Models\User::find($emp->id);

                $isOff = false;
                if ($empModel) {
                    if ($todayHoliday) {
                        $isOff = true;
                    } else {
                        $schedule = \App\Http\Controllers\API\ShiftController::resolveSchedule($empModel, $today);
                        $isOff    = (bool) ($schedule['is_off'] ?? false);
                    }
                }

                $notCheckedIn[] = [
                    'user_id'    => $emp->id,
                    'name'       => $emp->name,
                    'department' => $emp->department,
                    'is_off'     => $isOff,
                ];
            }
        }

        return response()->json([
            'date'    => $today,
            'summary' => [
                'total_employees' => $employees->count(),
                'checked_in'      => count($checkedIn),
                'not_checked_in'  => count($notCheckedIn),
                'on_leave'        => count($leaveList),
            ],
            'checked_in'     => $checkedIn,
            'not_checked_in' => $notCheckedIn,
            'on_leave'       => $leaveList,
        ]);
    }

    // 4d. listLeaveBalances() — saldo cuti karyawan (HRD)
    public function listLeaveBalances(Request $request): JsonResponse
    {
        $actor = $request->user();

        $validated = $request->validate([
            'user_id' => 'nullable|integer',
            'year'    => 'nullable|integer',
        ]);
        $year = $validated['year'] ?? now()->year;

        $usersQuery = User::where('is_active', true)
            ->when(
                $actor->role !== 'super_admin',
                fn ($q) => $q->where('company_id', $actor->company_id)
            )
            ->when($validated['user_id'] ?? null, fn ($q, $u) => $q->where('id', $u))
            ->orderBy('name');

        $users = $usersQuery->get(['id', 'name', 'company_id']);

        $existingBalances = LeaveBalance::where('year', $year)
            ->whereIn('user_id', $users->pluck('id'))
            ->get()
            ->groupBy('user_id');

        $balances = collect();
        $leaveTypes = ['cuti', 'izin'];
        $defaultQuotas = ['cuti' => self::DEFAULT_LEAVE_QUOTA['cuti'] ?? 12, 'izin' => 0];

        foreach ($users as $user) {
            $userBalances = $existingBalances->get($user->id, collect());

            foreach ($leaveTypes as $type) {
                $existing = $userBalances->firstWhere('leave_type', $type);

                if ($existing) {
                    $balances->push([
                        'id'         => $existing->id,
                        'user_id'    => $user->id,
                        'user_name'  => $user->name,
                        'year'       => $year,
                        'leave_type' => $type,
                        'quota'      => $existing->quota,
                        'used'       => $existing->used,
                        'remaining'  => $existing->quota - $existing->used,
                    ]);
                } else {
                    $balances->push([
                        'id'         => null,
                        'user_id'    => $user->id,
                        'user_name'  => $user->name,
                        'year'       => $year,
                        'leave_type' => $type,
                        'quota'      => $defaultQuotas[$type],
                        'used'       => 0,
                        'remaining'  => $defaultQuotas[$type],
                    ]);
                }
            }
        }

        return response()->json(['year' => $year, 'balances' => $balances->values()]);
    }

    // 4e. setLeaveBalance() — atur kuota cuti/sakit karyawan (HRD)
    public function setLeaveBalance(Request $request): JsonResponse
    {
        $actor = $request->user();

        $validated = $request->validate([
            'user_id'    => 'required|integer',
            'leave_type' => 'required|in:cuti,sakit',
            'year'       => 'nullable|integer',
            'quota'      => 'required|integer|min:0',
        ]);
        $year = $validated['year'] ?? now()->year;

        $target = User::when(
            $actor->role !== 'super_admin',
            fn ($q) => $q->where('company_id', $actor->company_id)
        )->find($validated['user_id']);

        if (! $target) {
            return response()->json(['message' => 'User tidak ditemukan di perusahaan Anda.'], 404);
        }

        $balance = LeaveBalance::updateOrCreate(
            ['user_id' => $target->id, 'year' => $year, 'leave_type' => $validated['leave_type']],
            ['company_id' => $target->company_id, 'quota' => $validated['quota']]
        );

        $this->logActivity(
            $actor->id,
            $target->company_id,
            'leave_balance_set',
            "Set kuota {$validated['leave_type']} {$target->name} = {$validated['quota']} hari ({$year})",
            'leave_balance',
            $balance->id
        );

        return response()->json([
            'message' => 'Kuota cuti berhasil diperbarui.',
            'balance' => [
                'user_id'    => $balance->user_id,
                'year'       => $balance->year,
                'leave_type' => $balance->leave_type,
                'quota'      => $balance->quota,
                'used'       => $balance->used,
                'remaining'  => $balance->quota - $balance->used,
            ],
        ]);
    }

    // 5b. monthlySummary() — rekap bulanan satu karyawan (fondasi payroll)
    public function monthlySummary(Request $request): JsonResponse
    {
        $actor = $request->user();

        $validated = $request->validate([
            'user_id' => 'required|integer',
            'month'   => 'nullable|integer|between:1,12',
            'year'    => 'nullable|integer',
        ]);
        $month = $validated['month'] ?? now()->month;
        $year  = $validated['year'] ?? now()->year;

        $target = User::when(
            $actor->role !== 'super_admin',
            fn ($q) => $q->where('company_id', $actor->company_id)
        )->find($validated['user_id']);

        if (! $target) {
            return response()->json(['message' => 'User tidak ditemukan di perusahaan Anda.'], 404);
        }

        $start      = Carbon::create($year, $month, 1)->startOfMonth();
        $end        = (clone $start)->endOfMonth();
        $rangeStart = $start->toDateString();
        $rangeEnd   = $end->toDateString();

        // Hitung hanya sampai hari ini agar hari depan tidak dihitung absen
        $countUntil = Carbon::parse($rangeEnd)->greaterThan(now())
            ? now()->toDateString()
            : $rangeEnd;

        // Hari libur nasional + perusahaan dalam bulan ini
        $holidaySet = Holiday::where(function ($q) use ($target) {
                $q->whereNull('company_id')->orWhere('company_id', $target->company_id);
            })
            ->whereBetween('date', [$rangeStart, $rangeEnd])
            ->pluck('date')
            ->map(fn ($d) => Carbon::parse($d)->format('Y-m-d'))
            ->flip()
            ->all();

        // Hitung hari kerja (bukan weekend, bukan libur)
        $workingDays  = 0;
        $cur          = Carbon::parse($rangeStart);
        $until        = Carbon::parse($countUntil);
        while ($cur->lte($until)) {
            if (! $cur->isWeekend() && ! isset($holidaySet[$cur->format('Y-m-d')])) {
                $workingDays++;
            }
            $cur->addDay();
        }

        // Data attendance nyata (dari DB)
        $attCounts = Attendance::where('user_id', $target->id)
            ->whereBetween('date', [$rangeStart, $countUntil])
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')->pluck('total', 'status');

        $typeCounts = Attendance::where('user_id', $target->id)
            ->whereBetween('date', [$rangeStart, $rangeEnd])
            ->select('check_in_type', DB::raw('COUNT(*) as total'))
            ->groupBy('check_in_type')->pluck('total', 'check_in_type');

        $attendanceDays = (int) array_sum($attCounts->toArray());

        // Hari-hari leave yang disetujui (per tanggal unik agar tidak dobel)
        $leaveRequests = LeaveRequest::where('user_id', $target->id)
            ->where('status', 'approved')
            ->where('start_date', '<=', $countUntil)
            ->where('end_date', '>=', $rangeStart)
            ->get(['leave_type', 'start_date', 'end_date']);

        $leaveDatesByType = [];
        foreach ($leaveRequests as $lr) {
            $lCur = Carbon::parse($lr->start_date);
            $lEnd = Carbon::parse($lr->end_date);
            while ($lCur->lte($lEnd) && $lCur->lte($until)) {
                $ds = $lCur->format('Y-m-d');
                if (! $lCur->isWeekend() && ! isset($holidaySet[$ds])) {
                    $leaveDatesByType[$lr->leave_type][$ds] = true;
                }
                $lCur->addDay();
            }
        }
        $leaveDayCounts = array_map('count', $leaveDatesByType);
        $totalLeaveDays = array_sum($leaveDayCounts);

        // Absen = hari kerja − (hari dengan presensi) − (hari izin/cuti disetujui)
        $absentDays = max(0, $workingDays - $attendanceDays - $totalLeaveDays);

        // Total menit lembur bulan ini
        $overtimeMinutes = (int) Attendance::where('user_id', $target->id)
            ->whereBetween('date', [$rangeStart, $rangeEnd])
            ->sum('overtime_minutes');

        return response()->json([
            'user'   => ['id' => $target->id, 'name' => $target->name, 'department' => $target->department],
            'period' => ['month' => (int) $month, 'year' => (int) $year],
            'attendance' => [
                'present'        => (int) ($attCounts['present'] ?? 0),
                'late'           => (int) ($attCounts['late'] ?? 0),
                'early_leave'    => (int) ($attCounts['early_leave'] ?? 0),
                'absent'         => $absentDays,
                'total_check_in' => $attendanceDays,
                'working_days'   => $workingDays,
            ],
            'overtime' => [
                'minutes' => $overtimeMinutes,
                'hours'   => $this->formatMinutes($overtimeMinutes),
            ],
            'by_type' => [
                'onsite' => (int) ($typeCounts['onsite'] ?? 0),
                'wfh'    => (int) ($typeCounts['wfh'] ?? 0),
                'field'  => (int) ($typeCounts['field'] ?? 0),
            ],
            'leave' => [
                'izin'  => $leaveDayCounts['izin']  ?? 0,
                'sakit' => $leaveDayCounts['sakit']  ?? 0,
                'cuti'  => $leaveDayCounts['cuti']   ?? 0,
                'wfh'   => $leaveDayCounts['wfh']    ?? 0,
            ],
        ]);
    }

    // ─── Helper: bangun semua baris laporan (attendance nyata + virtual absent/izin/cuti)
    // Menggabungkan 4 sumber: attendance records, leave requests, holiday calendar, user list.
    // Karyawan yang tidak check-in di hari kerja → muncul sebagai 'absent' atau leave type-nya.
    private function buildFullRows(
        ?int $companyId,
        string $startDate,
        string $endDate,
        ?string $department,
        ?int $officeId = null
    ): array {
        // 1. Semua karyawan aktif (filtered by company & department & office)
        $users = User::where('is_active', true)
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->when($department, fn ($q, $d) => $q->where('department', $d))
            ->when($officeId, fn ($q, $o) => $q->where('attendance_setting_id', $o))
            ->select('id', 'name', 'department', 'attendance_setting_id')
            ->get();

        if ($users->isEmpty()) {
            return [];
        }

        // 2. Semua attendance dalam range, index by "userId_date"
        $attendances = Attendance::query()
            ->join('users', 'attendances.user_id', '=', 'users.id')
            ->when($companyId, fn ($q) => $q->where('attendances.company_id', $companyId))
            ->when($department, fn ($q, $d) => $q->where('users.department', $d))
            ->whereBetween('attendances.date', [$startDate, $endDate])
            ->select([
                'attendances.id', 'attendances.user_id',
                'users.name as user_name', 'users.department',
                'attendances.date', 'attendances.check_in_time', 'attendances.check_out_time',
                'attendances.check_in_type', 'attendances.status',
                'attendances.overtime_minutes', 'attendances.is_holiday',
                'attendances.check_in_lat', 'attendances.check_in_lng',
                DB::raw('TIMESTAMPDIFF(MINUTE, attendances.check_in_time, attendances.check_out_time) as working_minutes'),
            ])
            ->get()
            ->keyBy(fn ($a) => $a->user_id . '_' . Carbon::parse($a->date)->format('Y-m-d'));

        // 3. Approved leave dalam range → lookup [user_id][date] = leave_type
        $leaves = LeaveRequest::when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->where('status', 'approved')
            ->where('start_date', '<=', $endDate)
            ->where('end_date', '>=', $startDate)
            ->get(['user_id', 'leave_type', 'start_date', 'end_date']);

        $leaveLookup = [];
        foreach ($leaves as $lr) {
            $lCur = Carbon::parse($lr->start_date);
            $lEnd = Carbon::parse($lr->end_date);
            while ($lCur->lte($lEnd)) {
                $leaveLookup[$lr->user_id][$lCur->format('Y-m-d')] = $lr->leave_type;
                $lCur->addDay();
            }
        }

        // 4. Set tanggal libur (nasional + perusahaan) dalam range
        $holidaySet = Holiday::where(function ($q) use ($companyId) {
                $q->whereNull('company_id')->orWhere('company_id', $companyId);
            })
            ->whereBetween('date', [$startDate, $endDate])
            ->pluck('date')
            ->map(fn ($d) => Carbon::parse($d)->format('Y-m-d'))
            ->flip()
            ->all();

        // 5. Iterate setiap hari × setiap karyawan → hasilkan baris lengkap
        //    Menggunakan resolveSchedule() per-karyawan agar jadwal shift
        //    (termasuk shift weekend, shift malam/lintas-hari) diperhitungkan
        //    dengan benar — bukan hanya berdasarkan isWeekend() global.
        $rows  = [];
        $today = now()->toDateString();
        $cur   = Carbon::parse($startDate);
        $last  = Carbon::parse($endDate);

        // Pre-load UserShift untuk semua user dalam satu query agar tidak N+1
        $userIds   = $users->pluck('id')->all();
        $userShifts = \App\Models\UserShift::with('shift')
            ->whereIn('user_id', $userIds)
            ->where('start_date', '<=', $endDate)
            ->orderByDesc('start_date')
            ->get()
            ->groupBy('user_id');

        // Pre-load AttendanceSetting (office) tiap karyawan
        $userModels = User::with('office')
            ->whereIn('id', $userIds)
            ->get()
            ->keyBy('id');

        // Pre-load semua ShiftSchedule yang relevan (indexed by shift_id_day)
        $shiftIds = $userShifts->flatten()->pluck('shift_id')->filter()->unique()->values()->all();
        $shiftScheduleCache = \App\Models\ShiftSchedule::whereIn('shift_id', $shiftIds)
            ->get()
            ->groupBy(fn ($s) => $s->shift_id . '_' . $s->day_of_week);

        while ($cur->lte($last)) {
            $dateStr   = $cur->format('Y-m-d');
            $isHoliday = isset($holidaySet[$dateStr]);
            $isFuture  = $dateStr > $today;
            $cur->addDay();

            foreach ($users as $user) {
                $key      = $user->id . '_' . $dateStr;
                $fullUser = $userModels->get($user->id);

                if (isset($attendances[$key])) {
                    // Ada record presensi nyata — selalu tampilkan
                    $att          = $attendances[$key];
                    $checkoutDate = $att->check_out_time
                        ? Carbon::parse($att->check_out_time)->timezone('Asia/Jakarta')->format('Y-m-d')
                        : null;
                    $isCrossDay = $checkoutDate && $checkoutDate > $dateStr;
                    $lateMinutes = null;
                    if ($att->status === 'late' && $att->check_in_time) {
                        $schedule = $this->getWorkSchedule($fullUser, $dateStr);
                        if ($schedule['work_start_time']) {
                            $startSchedule = Carbon::parse($dateStr . ' ' . $schedule['work_start_time'], 'Asia/Jakarta');
                            $checkInWib = Carbon::parse($att->check_in_time)->timezone('Asia/Jakarta');
                            if ($checkInWib->greaterThan($startSchedule)) {
                                $lateMinutes = $startSchedule->diffInMinutes($checkInWib);
                            }
                        }
                    }

                    $rows[] = [
                        'id'               => $att->id,
                        'user_id'          => $att->user_id,
                        'user_name'        => $att->user_name,
                        'department'       => $att->department,
                        'date'             => $dateStr,
                        'checkout_date'    => $checkoutDate,
                        'is_cross_day'     => $isCrossDay,
                        'check_in_time'    => $att->check_in_time,
                        'check_out_time'   => $att->check_out_time,
                        'check_in_type'    => $att->check_in_type,
                        'check_in_lat'     => $att->check_in_lat,
                        'check_in_lng'     => $att->check_in_lng,
                        'status'           => $att->status,
                        'late_minutes'     => $lateMinutes,
                        'overtime_minutes' => (int) ($att->overtime_minutes ?? 0),
                        'is_holiday'       => (bool) $att->is_holiday,
                        'working_minutes'  => $att->working_minutes,
                    ];
                } elseif (! $isFuture) {
                    // Tidak ada presensi → tentukan apakah hari ini hari kerja
                    // menggunakan resolveSchedule() per-karyawan (shift-aware)
                    $schedule  = null;
                    $dayOfWeek = Carbon::parse($dateStr)->dayOfWeek;

                    // Cari shift aktif karyawan pada tanggal ini
                    $shiftAssignment = ($userShifts->get($user->id) ?? collect())
                        ->first(function ($us) use ($dateStr) {
                            $endOk = $us->end_date === null
                                || (is_string($us->end_date)
                                    ? $us->end_date >= $dateStr
                                    : $us->end_date->toDateString() >= $dateStr);
                            return $us->start_date <= $dateStr && $endOk;
                        });

                    $isOff = false;
                    if ($shiftAssignment && $shiftAssignment->shift_id && optional($shiftAssignment->shift)->is_active) {
                        // Karyawan punya jadwal shift → gunakan shift schedule (dari cache)
                        $cacheKey   = $shiftAssignment->shift_id . '_' . $dayOfWeek;
                        $shiftSched = ($shiftScheduleCache->get($cacheKey) ?? collect())->first();
                        $isOff = $shiftSched ? (bool) $shiftSched->is_off : false;
                    } else {
                        // Fallback ke jam kerja kantor
                        $office = $fullUser ? ($fullUser->office
                            ?? \App\Models\AttendanceSetting::where('company_id', $user->company_id ?? $fullUser->company_id)
                                ->orderBy('id')->first()) : null;

                        if ($office) {
                            $workDays = $office->work_days ?? [1, 2, 3, 4, 5];
                            $isOff    = ! in_array($dayOfWeek, array_map('intval', (array) $workDays));
                        } else {
                            // Tidak ada setting → gunakan Senin-Jumat sebagai default
                            $isOff = ! in_array($dayOfWeek, [1, 2, 3, 4, 5]);
                        }
                    }

                    // Libur nasional/perusahaan juga dianggap libur
                    if ($isHoliday) {
                        $isOff = true;
                    }

                    if ($isOff) {
                        // Hari libur → tetap tampilkan di laporan dengan status 'libur'
                        $rows[] = [
                            'id'               => null,
                            'user_id'          => $user->id,
                            'user_name'        => $user->name,
                            'department'       => $user->department,
                            'date'             => $dateStr,
                            'checkout_date'    => null,
                            'is_cross_day'     => false,
                            'check_in_time'    => null,
                            'check_out_time'   => null,
                            'check_in_type'    => null,
                            'check_in_lat'     => null,
                            'check_in_lng'     => null,
                            'status'           => 'libur',
                            'late_minutes'     => null,
                            'overtime_minutes' => 0,
                            'is_holiday'       => $isHoliday,
                            'working_minutes'  => null,
                        ];
                        continue;
                    }

                    // Hari kerja tapi tidak ada presensi → absent / cuti / izin
                    $leaveType = $leaveLookup[$user->id][$dateStr] ?? null;
                    $rows[] = [
                        'id'               => null,
                        'user_id'          => $user->id,
                        'user_name'        => $user->name,
                        'department'       => $user->department,
                        'date'             => $dateStr,
                        'checkout_date'    => null,
                        'is_cross_day'     => false,
                        'check_in_time'    => null,
                        'check_out_time'   => null,
                        'check_in_type'    => null,
                        'check_in_lat'     => null,
                        'check_in_lng'     => null,
                        'status'           => $leaveType ?? 'absent',
                        'late_minutes'     => null,
                        'overtime_minutes' => 0,
                        'is_holiday'       => false,
                        'working_minutes'  => null,
                    ];
                }

            }
        }

        // Sort: tanggal terbaru di atas, lalu nama
        usort($rows, fn ($a, $b) =>
            $b['date'] !== $a['date']
                ? strcmp($b['date'], $a['date'])
                : strcmp($a['user_name'], $b['user_name'])
        );

        return $rows;
    }

    // 5c. exportReport() — export laporan presensi ke CSV
    public function exportReport(Request $request): StreamedResponse
    {
        $actor = $request->user();

        $validated = $request->validate([
            'start_date' => 'nullable|date',
            'end_date'   => 'nullable|date|after_or_equal:start_date',
            'department' => 'nullable|string|max:100',
            'status'     => 'nullable|in:present,late,absent,early_leave,cuti,izin,sakit,wfh,libur',
            'type'       => 'nullable|in:onsite,wfh,field',
            'search'     => 'nullable|string|max:100',
            'office_id'  => 'nullable|integer',
        ]);

        $companyId  = $actor->role === 'super_admin' ? null : $actor->company_id;
        $startDate  = $validated['start_date'] ?? now()->startOfMonth()->toDateString();
        $endDate    = $validated['end_date']   ?? now()->toDateString();

        if (Carbon::parse($startDate)->diffInDays(Carbon::parse($endDate)) > 62) {
            return response()->streamDownload(function () {
                echo 'Rentang tanggal maksimal 62 hari (2 bulan) per export.';
            }, 'error.txt', ['Content-Type' => 'text/plain']);
        }

        $rows = $this->buildFullRows($companyId, $startDate, $endDate, $validated['department'] ?? null, $validated['office_id'] ?? null);

        if ($validated['status'] ?? null) {
            $rows = array_values(array_filter($rows, fn ($r) => $r['status'] === $validated['status']));
        }
        if ($validated['type'] ?? null) {
            $rows = array_values(array_filter($rows, fn ($r) => $r['check_in_type'] === $validated['type']));
        }
        if (!empty($validated['search'])) {
            $searchStr = strtolower($validated['search']);
            $rows = array_values(array_filter($rows, fn ($r) => str_contains(strtolower($r['user_name'] ?? ''), $searchStr)));
        }

        $filename = 'laporan-presensi-' . now()->format('Ymd-His') . '.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Nama', 'Departemen', 'Tanggal', 'Check In', 'Check Out', 'Tipe', 'Status', 'Telat (Menit)', 'Jam Kerja', 'Lembur', 'Hari Libur']);
            foreach ($rows as $r) {
                $mins     = $r['working_minutes'];
                $jamKerja = $mins !== null
                    ? floor($mins / 60) . 'j ' . ($mins % 60) . 'm'
                    : '-';
                $lembur = $this->formatMinutes((int) ($r['overtime_minutes'] ?? 0));
                $dateExport = $r['is_cross_day'] && $r['checkout_date']
                    ? Carbon::parse($r['date'])->format('d M') . ' - ' . Carbon::parse($r['checkout_date'])->format('d M Y')
                    : Carbon::parse($r['date'])->format('d M Y');

                fputcsv($out, [
                    $r['user_name'],
                    $r['department'] ?? '-',
                    $dateExport,
                    $r['check_in_time']  ? Carbon::parse($r['check_in_time'])->timezone('Asia/Jakarta')->format('H:i')  : '-',
                    $r['check_out_time'] ? Carbon::parse($r['check_out_time'])->timezone('Asia/Jakarta')->format('H:i') : '-',
                    $r['check_in_type'] ?? '-',
                    $r['status'],
                    $r['late_minutes'] !== null ? $r['late_minutes'] : '-',
                    $jamKerja,
                    $lembur,
                    $r['is_holiday'] ? 'Ya' : 'Tidak',
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    // 5. reportAttendance() — rekap presensi per periode (semua karyawan)
    public function reportAttendance(Request $request): JsonResponse
    {
        $actor = $request->user();

        $validated = $request->validate([
            'start_date' => 'nullable|date',
            'end_date'   => 'nullable|date|after_or_equal:start_date',
            'department' => 'nullable|string|max:100',
            'status'     => 'nullable|in:present,late,absent,early_leave,cuti,izin,sakit,wfh,libur',
            'type'       => 'nullable|in:onsite,wfh,field',
            'search'     => 'nullable|string|max:100',
            'office_id'  => 'nullable|integer',
        ]);

        $companyId = $actor->role === 'super_admin' ? null : $actor->company_id;
        $startDate = $validated['start_date'] ?? now()->startOfMonth()->toDateString();
        $endDate   = $validated['end_date']   ?? now()->toDateString();

        if (Carbon::parse($startDate)->diffInDays(Carbon::parse($endDate)) > 62) {
            return response()->json([
                'message' => 'Rentang tanggal maksimal 62 hari (2 bulan). Gunakan filter yang lebih sempit atau export CSV untuk data lebih lama.',
            ], 422);
        }

        // Bangun semua baris: presensi nyata + virtual absent/leave
        $rows = $this->buildFullRows($companyId, $startDate, $endDate, $validated['department'] ?? null, $validated['office_id'] ?? null);

        // Terapkan filter status, tipe lokasi, dan pencarian nama
        if ($validated['status'] ?? null) {
            $rows = array_values(array_filter($rows, fn ($r) => $r['status'] === $validated['status']));
        }
        if ($validated['type'] ?? null) {
            $rows = array_values(array_filter($rows, fn ($r) => $r['check_in_type'] === $validated['type']));
        }
        if (!empty($validated['search'])) {
            $searchStr = strtolower($validated['search']);
            $rows = array_values(array_filter($rows, fn ($r) => str_contains(strtolower($r['user_name'] ?? ''), $searchStr)));
        }

        // Hitung summary dari baris yang sudah difilter
        $statusCounts         = array_count_values(array_column($rows, 'status'));
        $typeCounts           = array_count_values(array_filter(array_column($rows, 'check_in_type')));
        $totalWorkingMinutes  = (int) array_sum(array_column($rows, 'working_minutes'));
        $totalOvertimeMinutes = (int) array_sum(array_column($rows, 'overtime_minutes'));

        // Paginasi manual
        $page    = max(1, (int) $request->query('page', 1));
        $perPage = 30;
        $total   = count($rows);
        $items   = array_slice($rows, ($page - 1) * $perPage, $perPage);

        return response()->json([
            'summary' => [
                'present'                => $statusCounts['present']     ?? 0,
                'late'                   => $statusCounts['late']        ?? 0,
                'absent'                 => $statusCounts['absent']      ?? 0,
                'early_leave'            => $statusCounts['early_leave'] ?? 0,
                'cuti'                   => $statusCounts['cuti']        ?? 0,
                'izin'                   => $statusCounts['izin']        ?? 0,
                'sakit'                  => $statusCounts['sakit']       ?? 0,
                'total_working_minutes'  => $totalWorkingMinutes,
                'total_overtime_minutes' => $totalOvertimeMinutes,
            ],
            'by_type' => [
                'onsite' => $typeCounts['onsite'] ?? 0,
                'wfh'    => $typeCounts['wfh']    ?? 0,
                'field'  => $typeCounts['field']  ?? 0,
            ],
            'report' => [
                'data'         => $items,
                'current_page' => $page,
                'per_page'     => $perPage,
                'total'        => $total,
                'last_page'    => (int) ceil($total / max(1, $perPage)),
            ],
        ]);
    }

    // ═══════════════════════════════════════════════════════════
    // BAGIAN A2 — CRUD pengaturan kantor (attendance_settings)
    //             HRD bisa punya >1 kantor per perusahaan
    // ═══════════════════════════════════════════════════════════

    // ─── Aturan validasi setting (dipakai store & update) ──────
    private function settingRules(bool $forUpdate = false): array
    {
        $req = $forUpdate ? 'sometimes' : 'required';

        return [
            'office_name'            => "{$req}|string|max:255",
            'office_latitude'        => "{$req}|numeric|between:-90,90",
            'office_longitude'       => "{$req}|numeric|between:-180,180",
            'radius_meters'          => 'sometimes|integer|min:1',
            'work_start_time'        => 'sometimes|date_format:H:i:s,H:i',
            'work_end_time'          => 'sometimes|date_format:H:i:s,H:i',
            // Maks 6 hari kerja — karyawan wajib mendapat min 1 hari libur/minggu (UU 13/2003 Pasal 79)
            'work_days'              => 'sometimes|array|min:1|max:6',
            'work_days.*'            => 'integer|between:0,6|distinct',
            'late_tolerance_minutes'     => 'sometimes|integer|min:0',
            'require_selfie'             => 'sometimes|boolean',
            'allow_wfh'                  => 'sometimes|boolean',
            'wfh_checkin_window_minutes' => 'sometimes|nullable|integer|min:0|max:720',
            'overtime_enabled'               => 'sometimes|boolean',
            'min_overtime_minutes'           => 'sometimes|integer|min:0|max:480',
            'early_leave_tolerance_minutes'  => 'sometimes|nullable|integer|min:0|max:480',
            'checkout_reminder_minutes'      => 'sometimes|integer|min:5|max:120',
            'auto_checkout_grace_minutes'    => 'sometimes|integer|min:30|max:240',
            // Validasi jam kerja mingguan (opsional, bisa di-toggle per kantor)
            'enforce_weekly_hours'           => 'sometimes|boolean',
            'max_weekly_hours'               => 'sometimes|nullable|integer|min:40|max:168',
            // Validasi custom_schedules (override per hari)
            'custom_schedules'               => 'sometimes|nullable|array',
            'custom_schedules.*.start'       => 'required_with:custom_schedules|date_format:H:i',
            'custom_schedules.*.end'         => 'required_with:custom_schedules|date_format:H:i',
        ];
    }

    // Pesan validasi kustom untuk settingRules()
    private function settingMessages(): array
    {
        return [
            'work_days.max'        => 'Hari kerja maksimal 6 hari per minggu. Karyawan wajib mendapat minimal 1 hari libur per minggu (UU No. 13/2003 Pasal 79).',
            'work_days.min'        => 'Hari kerja minimal 1 hari per minggu.',
            'work_days.*.distinct' => 'Setiap hari kerja hanya boleh dipilih satu kali.',
            'work_days.*.between'  => 'Nilai hari kerja tidak valid (0=Minggu hingga 6=Sabtu).',
            'custom_schedules.*.start.date_format' => 'Format jam masuk khusus harus HH:MM.',
            'custom_schedules.*.end.date_format'   => 'Format jam pulang khusus harus HH:MM.',
        ];
    }

    // 10. listSettings() — daftar kantor perusahaan
    public function listSettings(Request $request): JsonResponse
    {
        $actor = $request->user();

        $settings = AttendanceSetting::when(
            $actor->role !== 'super_admin',
            fn ($q) => $q->where('company_id', $actor->company_id)
        )->orderBy('office_name')->get();

        return response()->json(['settings' => $settings]);
    }

    // 11. storeSettings() — tambah kantor baru
    public function storeSettings(Request $request): JsonResponse
    {
        $validated = $request->validate($this->settingRules(), $this->settingMessages());

        $actor = $request->user();
        // super_admin boleh menentukan company_id; lainnya pakai milik sendiri
        $companyId = $actor->company_id;
        if ($actor->role === 'super_admin' && $request->filled('company_id')) {
            $request->validate(['company_id' => 'integer|exists:companies,id']);
            $companyId = (int) $request->company_id;
        }

        if (! $companyId) {
            return response()->json(['message' => 'company_id wajib ditentukan.'], 422);
        }

        // Buang nilai null agar default kolom tetap berlaku
        $data = array_filter($validated, fn ($v) => $v !== null);
        $data['company_id'] = $companyId;

        $setting = AttendanceSetting::create($data);

        $this->logActivity($actor->id, $companyId, 'attendance_setting_created', "Tambah kantor {$setting->office_name}", 'attendance_setting', $setting->id);

        return response()->json([
            'message' => 'Pengaturan kantor berhasil dibuat.',
            'setting' => $setting->fresh(), // muat default kolom (radius, jam kerja, dll.)
        ], 201);
    }

    // 12. showSettings() — detail satu kantor
    //     (CompanyMiddleware sudah memvalidasi company_id model binding)
    public function showSettings(AttendanceSetting $attendanceSetting): JsonResponse
    {
        return response()->json(['setting' => $attendanceSetting]);
    }

    // 13. updateSettings() — ubah kantor
    public function updateSettings(Request $request, AttendanceSetting $attendanceSetting): JsonResponse
    {
        $validated = $request->validate($this->settingRules(forUpdate: true), $this->settingMessages());

        $data = array_filter($validated, fn ($v) => $v !== null);
        $attendanceSetting->update($data);

        $this->logActivity(
            $request->user()->id,
            $attendanceSetting->company_id,
            'attendance_setting_updated',
            "Update kantor {$attendanceSetting->office_name}",
            'attendance_setting',
            $attendanceSetting->id
        );

        return response()->json([
            'message' => 'Pengaturan kantor berhasil diperbarui.',
            'setting' => $attendanceSetting->fresh(),
        ]);
    }

    // 14. destroySettings() — hapus kantor
    public function destroySettings(Request $request, AttendanceSetting $attendanceSetting): JsonResponse
    {
        $companyId = $attendanceSetting->company_id;
        $name      = $attendanceSetting->office_name;
        $id        = $attendanceSetting->id;

        $attendanceSetting->delete();

        $this->logActivity($request->user()->id, $companyId, 'attendance_setting_deleted', "Hapus kantor {$name}", 'attendance_setting', $id);

        return response()->json(['message' => 'Pengaturan kantor berhasil dihapus.']);
    }

    // ═══════════════════════════════════════════════════════════
    // BAGIAN A3 — Kalender libur nasional (holidays)
    // ═══════════════════════════════════════════════════════════

    // listHolidays() — daftar libur (nasional + milik perusahaan), filter tahun opsional
    public function listHolidays(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;
        $year      = $request->query('year', now('Asia/Jakarta')->year);

        $holidays = Holiday::where(function ($q) use ($companyId) {
                $q->whereNull('company_id')->orWhere('company_id', $companyId);
            })
            ->whereYear('date', $year)
            ->orderBy('date')
            ->get(['id', 'company_id', 'date', 'name', 'is_national'])
            ->map(fn ($h) => [
                'id'          => $h->id,
                'date'        => $h->date->toDateString(),
                'name'        => $h->name,
                'is_national' => $h->is_national,
                'scope'       => $h->company_id ? 'perusahaan' : 'nasional',
            ]);

        return response()->json(['year' => (int) $year, 'holidays' => $holidays]);
    }

    // storeHolidays() — tambah libur khusus perusahaan (mis. cuti bersama internal)
    public function storeHolidays(Request $request): JsonResponse
    {
        $user      = $request->user();
        $companyId = $user->company_id;

        $validated = $request->validate([
            'date' => 'required|date',
            'name' => 'required|string|max:255',
        ]);

        $date = Carbon::parse($validated['date'])->toDateString();

        // Cegah duplikat pada scope perusahaan yang sama.
        $exists = Holiday::whereDate('date', $date)
            ->where('company_id', $companyId)
            ->exists();

        if ($exists) {
            return response()->json(['message' => 'Tanggal libur tersebut sudah terdaftar.'], 422);
        }

        $holiday = Holiday::create([
            'company_id'  => $companyId,
            'date'        => $date,
            'name'        => $validated['name'],
            'is_national' => false,
        ]);

        $this->logActivity($user->id, $companyId, 'holiday_created', "Tambah libur {$holiday->name} ({$date})", 'holiday', $holiday->id);

        return response()->json([
            'message' => 'Hari libur berhasil ditambahkan.',
            'holiday' => [
                'id'          => $holiday->id,
                'date'        => $date,
                'name'        => $holiday->name,
                'is_national' => false,
                'scope'       => 'perusahaan',
            ],
        ], 201);
    }

    // updateHolidays() — ubah libur (nasional & perusahaan)
    public function updateHolidays(Request $request, Holiday $holiday): JsonResponse
    {
        $user = $request->user();

        // HRD boleh edit libur nasional (company_id NULL) DAN libur milik perusahaannya
        if ($holiday->company_id !== null && $holiday->company_id !== $user->company_id) {
            return response()->json(['message' => 'Libur tidak ditemukan.'], 403);
        }

        $validated = $request->validate([
            'date' => 'required|date',
            'name' => 'required|string|max:255',
        ]);

        $newDate = Carbon::parse($validated['date'])->toDateString();

        // Cegah duplikat: jika mengubah tanggal, pastikan tidak bentrok dengan libur lain di scope yang sama
        if ($newDate !== $holiday->date->toDateString()) {
            $exists = Holiday::whereDate('date', $newDate)
                ->where('company_id', $holiday->company_id)
                ->where('id', '!=', $holiday->id)
                ->exists();

            if ($exists) {
                return response()->json(['message' => 'Tanggal libur tersebut sudah terdaftar.'], 422);
            }
        }

        $oldName = $holiday->name;
        $oldDate = $holiday->date->toDateString();

        $holiday->update([
            'date' => $newDate,
            'name' => $validated['name'],
        ]);

        $scope = $holiday->company_id ? $user->company_id : null;
        $this->logActivity(
            $user->id,
            $scope,
            'holiday_updated',
            "Update libur dari {$oldName} ({$oldDate}) ke {$holiday->name} ({$newDate})",
            'holiday',
            $holiday->id
        );

        return response()->json([
            'message' => 'Hari libur berhasil diubah.',
            'holiday' => [
                'id'          => $holiday->id,
                'date'        => $newDate,
                'name'        => $holiday->name,
                'is_national' => $holiday->is_national,
                'scope'       => $holiday->company_id ? 'perusahaan' : 'nasional',
            ],
        ]);
    }

    // destroyHolidays() — hapus libur nasional & libur perusahaan.
    public function destroyHolidays(Request $request, Holiday $holiday): JsonResponse
    {
        $user = $request->user();

        // Libur perusahaan lain tidak boleh dihapus
        if ($holiday->company_id !== null && $holiday->company_id !== $user->company_id) {
            return response()->json(['message' => 'Libur tidak ditemukan di perusahaan Anda.'], 403);
        }

        $name = $holiday->name;
        $date = $holiday->date->toDateString();
        $id   = $holiday->id;

        $holiday->delete();

        $this->logActivity($user->id, $user->company_id, 'holiday_deleted', "Hapus libur {$name} ({$date})", 'holiday', $id);

        return response()->json(['message' => 'Hari libur berhasil dihapus.']);
    }

    // ═══════════════════════════════════════════════════════════
    // BAGIAN B — Semua user (prefix attendance)
    // ═══════════════════════════════════════════════════════════

    // 6. checkIn() — presensi masuk via mobile
    //    Tiga mode berdasarkan flag HRD di tabel users:
    //    a) wfh_enabled = false             → 403, harus pakai perangkat presensi kantor
    //    b) wfh_enabled = true, radius = false → WFH bebas, tanpa cek lokasi
    //    c) wfh_enabled = true, radius = true  → Lapangan, wajib dalam radius lokasi kerja
    public function checkIn(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'latitude'  => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
        ]);

        $user = $request->user();

        // Mode (a): mobile diblokir → gunakan perangkat presensi kantor
        if (! $user->canWfh()) {
            return response()->json([
                'message' => 'Presensi aplikasi hanya untuk karyawan WFH atau lapangan. Presensi di kantor dilakukan melalui perangkat presensi.',
            ], 403);
        }

        $today = $this->todayDate();

        // Cegah presensi jika user sedang cuti, sakit, atau izin hari ini
        // (leave_type 'wfh' dikecualikan — itu mode kerja dari rumah, bukan izin tidak masuk)
        $activeLeave = LeaveRequest::where('user_id', $user->id)
            ->where('status', 'approved')
            ->where('start_date', '<=', $today)
            ->where('end_date', '>=', $today)
            ->whereIn('leave_type', ['cuti', 'sakit', 'izin'])
            ->first();

        if ($activeLeave) {
            $leaveLabel = match ($activeLeave->leave_type) {
                'cuti'  => 'cuti',
                'sakit' => 'sakit',
                'izin'  => 'izin',
                default => $activeLeave->leave_type,
            };
            return response()->json([
                'message'    => "Anda tidak dapat melakukan presensi karena sedang dalam status {$leaveLabel} hari ini.",
                'leave_type' => $activeLeave->leave_type,
                'start_date' => $activeLeave->start_date,
                'end_date'   => $activeLeave->end_date,
            ], 403);
        }

        // Cegah double check-in
        $existing = Attendance::where('user_id', $user->id)->whereDate('date', $today)->first();
        if ($existing && $existing->check_in_time) {
            return response()->json(['message' => 'Anda sudah check-in hari ini.'], 409);
        }

        // Cegah tumpang tindih shift: bila ada shift LINTAS HARI dari KEMARIN yang belum
        // di-checkout, karyawan wajib menyelesaikan (check-out) shift malam itu dulu.
        // Menjamin aturan "satu shift per hari" untuk sistem shift 24 jam.
        $crossDayKemarin = \App\Http\Controllers\API\ShiftController::resolveYesterdayCrossDay($user, $today);
        if ($crossDayKemarin) {
            $yesterday   = Carbon::parse($today)->subDay()->toDateString();
            $shiftTerbuka = Attendance::where('user_id', $user->id)
                ->whereDate('date', $yesterday)
                ->whereNotNull('check_in_time')
                ->whereNull('check_out_time')
                ->first();

            if ($shiftTerbuka) {
                return response()->json([
                    'message' => 'Anda masih memiliki shift malam kemarin yang belum di-checkout. Silakan check-out terlebih dahulu sebelum memulai presensi baru.',
                    'pending_attendance_id' => $shiftTerbuka->id,
                    'pending_shift_date'    => $yesterday,
                ], 409);
            }
        }

        $distanceMeters = null;
        $checkInType    = 'wfh';

        // Mode WFH: validasi window waktu presensi
        // Cegah check-in terlalu dini (mis. subuh/malam setelah tengah malam reset).
        // Gunakan jam masuk dari shift aktif jika ada; fallback ke kantor.
        if (! $user->hasRadiusEnabled()) {
            $jadwalHariIni = $this->getWorkSchedule($user, $today);
            $officeRef     = $jadwalHariIni['office'];
            $jamMasuk      = $jadwalHariIni['work_start_time'];

            if ($officeRef
                && $officeRef->wfh_checkin_window_minutes !== null
                && $jamMasuk
            ) {
                $nowWib      = now('Asia/Jakarta');
                $tanggalWib  = $nowWib->toDateString();
                $workStart   = Carbon::parse("{$tanggalWib} {$jamMasuk}", 'Asia/Jakarta');
                $windowOpens = $workStart->copy()->subMinutes($officeRef->wfh_checkin_window_minutes);

                if ($nowWib->lt($windowOpens)) {
                    return response()->json([
                        'message'         => "Presensi WFH belum bisa dilakukan. Silakan presensi mulai jam {$windowOpens->format('H:i')} WIB.",
                        'window_open_at'  => $windowOpens->format('H:i'),
                        'work_start_time' => $workStart->format('H:i'),
                    ], 403);
                }
            }
        }

        // Mode (c): lapangan — validasi radius terhadap lokasi kantor terdekat
        if ($user->hasRadiusEnabled()) {
            $offices = AttendanceSetting::where('company_id', $user->company_id)->get();

            if ($offices->isEmpty()) {
                return response()->json([
                    'message' => 'Validasi radius tidak bisa dilakukan: belum ada pengaturan lokasi kantor. Hubungi HRD.',
                ], 422);
            }

            $locationService = app(LocationService::class);
            $lat             = (float) $validated['latitude'];
            $lng             = (float) $validated['longitude'];

            // Cari kantor terdekat dari posisi karyawan
            $nearest  = null;
            $minDist  = PHP_FLOAT_MAX;
            foreach ($offices as $office) {
                $dist = $locationService->calculateDistance($lat, $lng, (float) $office->office_latitude, (float) $office->office_longitude);
                if ($dist < $minDist) {
                    $minDist = $dist;
                    $nearest = $office;
                }
            }

            $distanceMeters = (int) round($minDist);

            if ($minDist > $nearest->radius_meters) {
                return response()->json([
                    'message'          => "Anda berada di luar area kerja. Jarak Anda {$distanceMeters} meter, batas radius {$nearest->radius_meters} meter dari {$nearest->office_name}.",
                    'distance_meters'  => $distanceMeters,
                    'radius_meters'    => $nearest->radius_meters,
                    'office_name'      => $nearest->office_name,
                ], 403);
            }

            $checkInType = 'field';
        }

        // Ambil jadwal efektif untuk menentukan status (hadir/telat) & reminder
        $jadwalHariIni = $this->getWorkSchedule($user, $today);
        $status        = $this->determineStatus($user, now(), $today);

        $attendance = Attendance::updateOrCreate(
            ['user_id' => $user->id, 'date' => $today],
            [
                'company_id'               => $user->company_id,
                'check_in_time'            => now(),
                'check_in_lat'             => $validated['latitude'],
                'check_in_lng'             => $validated['longitude'],
                'check_in_distance_meters' => $distanceMeters,
                'check_in_type'            => $checkInType,
                'status'                   => $status,
            ]
        );

        $this->logActivity($user->id, $user->company_id, 'attendance_check_in', "Check-in ({$checkInType}) status {$status}", 'attendance', $attendance->id);

        // Hitung jadwal reminder & auto-checkout untuk Flutter (scheduling notif lokal).
        // Gunakan jam pulang dari shift aktif jika ada; fallback ke kantor.
        $reminderAt        = null;
        $autoCheckoutAt    = null;
        $office            = $jadwalHariIni['office'];
        $jamPulang         = $jadwalHariIni['work_end_time'];

        if ($office && $jamPulang) {
            $graceMinutes    = (int) ($office->auto_checkout_grace_minutes ?? 60);
            $reminderMinutes = (int) ($office->checkout_reminder_minutes ?? 30);
            // Shift lintas hari (cross-day): jam pulang berada di hari BERIKUTNYA setelah tanggal check-in.
            // Tanpa ini, shift malam 22:00–06:00 akan menjadwalkan reminder/auto-checkout ke 06:00 hari ini (salah).
            $isCrossDay  = ! empty($jadwalHariIni['is_cross_day']);
            $workEndDate = $isCrossDay
                ? Carbon::parse($today, 'Asia/Jakarta')->addDay()->toDateString()
                : $today;
            $workEnd         = Carbon::parse($workEndDate . ' ' . $jamPulang, 'Asia/Jakarta');
            $reminderAt      = $workEnd->copy()->addMinutes($reminderMinutes)->toIso8601String();
            $autoCheckoutAt  = $workEnd->copy()->addMinutes($graceMinutes)->toIso8601String();
        }

        return response()->json([
            'message'    => 'Check-in berhasil.',
            'attendance' => $attendance->only([
                'id', 'date', 'check_in_time', 'check_in_type',
                'check_in_distance_meters', 'status',
            ]),
            // Jadwal shift aktif yang berlaku hari ini (untuk tampilan di Flutter)
            'active_shift' => $jadwalHariIni['source'] === 'shift' ? [
                'shift_id'        => $jadwalHariIni['shift_id'],
                'shift_name'      => $jadwalHariIni['shift_name'],
                'work_start_time' => $jadwalHariIni['work_start_time'],
                'work_end_time'   => $jadwalHariIni['work_end_time'],
            ] : null,
            // Info untuk Flutter menjadwalkan notifikasi lokal
            'reminder_at'      => $reminderAt,
            'auto_checkout_at' => $autoCheckoutAt,
        ], 201);
    }

    // 7. checkOut() — presensi pulang
    public function checkOut(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'latitude'  => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
        ]);

        $user  = $request->user();
        $today = $this->todayDate();

        // 1. Cari record hari ini yang belum di-checkout (shift normal)
        $attendance = Attendance::where('user_id', $user->id)
            ->whereDate('date', $today)
            ->whereNull('check_out_time')
            ->first();

        // 2. Jika tidak ada, cek shift lintas hari (cross-day) dari KEMARIN yang masih terbuka.
        //    Shift malam 22:00 (Jumat) -> check-out 06:00 (Sabtu): record ada di tanggal Jumat.
        //    scheduleDate menyimpan tanggal shift asli untuk perhitungan lembur.
        $scheduleDate = (string) $today;
        if (! $attendance) {
            $crossDay = \App\Http\Controllers\API\ShiftController::resolveYesterdayCrossDay($user, (string) $today);
            if ($crossDay) {
                $yesterday  = Carbon::parse($today)->subDay()->toDateString();
                $attendance = Attendance::where('user_id', $user->id)
                    ->whereDate('date', $yesterday)
                    ->whereNull('check_out_time')
                    ->first();
                if ($attendance) {
                    $scheduleDate = $yesterday;
                }
            }
        }

        // 3. Belum ada record terbuka -> tentukan pesan yang tepat
        if (! $attendance || ! $attendance->check_in_time) {
            $already = Attendance::where('user_id', $user->id)->whereDate('date', $today)->first();
            if ($already && $already->check_out_time) {
                return response()->json(['message' => 'Anda sudah check-out hari ini.'], 409);
            }
            return response()->json(['message' => 'Anda belum check-in hari ini.'], 403);
        }

        $checkOutTime = now();

        // ─── Hitung jam kerja & lembur otomatis ──────────────────
        $workMinutes = $attendance->check_in_time
            ? (int) $attendance->check_in_time->diffInMinutes($checkOutTime)
            : 0;

        // isNonWorkingDay: apakah tanggal shift libur nasional/weekend secara kalender
        $nonWorking      = $this->isNonWorkingDay($scheduleDate, $user->company_id);
        // calculateOvertime & checkEarlyLeave sudah mempertimbangkan shift aktif karyawan (cross-day aware)
        $overtimeMinutes = $this->calculateOvertime($user, $scheduleDate, $checkOutTime, $workMinutes, $nonWorking);
        $isEarlyLeave    = $this->checkEarlyLeave($user, $scheduleDate, $checkOutTime, $nonWorking);

        $updateData = [
            'check_out_time'   => $checkOutTime,
            'check_out_lat'    => $validated['latitude'],
            'check_out_lng'    => $validated['longitude'],
            'check_out_type'   => $attendance->check_in_type,
            'work_minutes'     => $workMinutes,
            'overtime_minutes' => $overtimeMinutes,
            'is_holiday'       => $nonWorking,
        ];

        // Tandai early leave — tidak berlaku di hari libur/weekend
        if ($isEarlyLeave) {
            $updateData['status'] = 'early_leave';
        }

        $attendance->update($updateData);
        $attendance->refresh();

        $this->logActivity($user->id, $user->company_id, 'attendance_check_out', 'Check-out', 'attendance', $attendance->id);

        // Jika ada lembur, buat record overtime_approval untuk persetujuan HRD
        if ($attendance->overtime_minutes > 0) {
            $this->createOvertimeApproval($attendance, false);
        }

        return response()->json([
            'message'    => 'Check-out berhasil.',
            'attendance' => $attendance->only([
                'id', 'date', 'check_in_time', 'check_out_time', 'status',
                'work_minutes', 'overtime_minutes', 'is_holiday',
            ]),
        ]);
    }

    // ─── Helper: hitung menit lembur saat check-out ──────────────
    //     Mempertimbangkan shift aktif karyawan:
    //     - Jika shift menandai hari ini is_off=true  → seluruh menit kerja jadi lembur.
    //     - Jika shift menandai hari ini is_off=false → lembur dihitung setelah jam pulang SHIFT
    //       (berlaku meski hari ini adalah weekend/libur nasional — karyawan memang dijadwalkan masuk).
    //     - Fallback ke perilaku lama jika tidak ada shift: hari libur/weekend → full lembur;
    //       hari kerja → selisih menit melewati work_end_time kantor.
    private function calculateOvertime(User $user, string $date, Carbon $checkOutTime, int $workMinutes, bool $isNationalNonWorking): int
    {
        $schedule = $this->getWorkSchedule($user, $date);
        $office   = $schedule['office'];

        // Tanpa setting kantor atau lembur dimatikan → tidak ada lembur.
        if (! $office || ! $office->overtime_enabled) {
            return 0;
        }

        // Kasus 1: jadwal shift menandai hari ini libur (is_off=true)
        //          → seluruh menit kerja dianggap lembur (bekerja di luar jadwal)
        if ($schedule['is_off']) {
            return max(0, $workMinutes);
        }

        // Kasus 2: tidak ada shift, dan hari ini libur nasional/weekend
        //          → seluruh menit kerja dianggap lembur
        if ($schedule['source'] === 'office' && $isNationalNonWorking) {
            return max(0, $workMinutes);
        }

        // Kasus 3: hari kerja efektif (dari shift atau default kantor)
        //          → hitung lembur setelah jam pulang yang berlaku
        $jamPulangStr = $schedule['work_end_time'];
        if (! $jamPulangStr) {
            return 0;
        }

        // Shift lintas hari (cross-day, mis. 22:00–06:00): jam pulang berada di HARI BERIKUTNYA.
        $jamPulangDate = ! empty($schedule['is_cross_day'])
            ? Carbon::parse($date, 'Asia/Jakarta')->addDay()->toDateString()
            : $date;

        $jamPulang   = Carbon::parse($jamPulangDate . ' ' . $jamPulangStr, 'Asia/Jakarta');
        $checkOutWib = $checkOutTime->copy()->setTimezone('Asia/Jakarta');

        $lewat = $checkOutWib->greaterThan($jamPulang)
            ? (int) $jamPulang->diffInMinutes($checkOutWib)
            : 0;

        return $lewat >= (int) $office->min_overtime_minutes ? $lewat : 0;
    }

    // ─── Helper: deteksi pulang lebih awal (early leave) ────────
    //     Mempertimbangkan shift aktif: pakai jam pulang shift jika ada.
    //     Tidak berlaku di hari libur (per jadwal shift atau kalender).
    private function checkEarlyLeave(User $user, string $date, Carbon $checkOutTime, bool $isNationalNonWorking): bool
    {
        $schedule = $this->getWorkSchedule($user, $date);
        $office   = $schedule['office'];

        // Hari libur per jadwal shift → tidak ada konsep pulang awal
        if ($schedule['is_off']) {
            return false;
        }

        // Hari libur nasional/weekend tanpa shift → tidak ada konsep pulang awal
        if ($schedule['source'] === 'office' && $isNationalNonWorking) {
            return false;
        }

        // Tanpa setting kantor atau fitur dimatikan (NULL) → tidak dianggap early leave
        if (! $office || is_null($office->early_leave_tolerance_minutes)) {
            return false;
        }

        $jamPulangStr = $schedule['work_end_time'];
        if (! $jamPulangStr) {
            return false;
        }

        // Shift lintas hari (cross-day): jam pulang berada di HARI BERIKUTNYA.
        $jamPulangDate = ! empty($schedule['is_cross_day'])
            ? Carbon::parse($date, 'Asia/Jakarta')->addDay()->toDateString()
            : $date;

        $toleransi   = (int) $office->early_leave_tolerance_minutes;
        $jamPulang   = Carbon::parse($jamPulangDate . ' ' . $jamPulangStr, 'Asia/Jakarta');
        $batasAwal   = $jamPulang->copy()->subMinutes($toleransi);
        $checkOutWib = $checkOutTime->copy()->setTimezone('Asia/Jakarta');

        return $checkOutWib->lessThan($batasAwal);
    }

    // ─── Helper: buat record OvertimeApproval & notifikasi HRD ──────────────
    //     Dipanggil saat checkout (manual/auto) jika ada overtime_minutes > 0.
    private function createOvertimeApproval(Attendance $attendance, bool $isAutoCheckout = false): void
    {
        // Jangan duplikat jika sudah ada
        if (OvertimeApproval::where('attendance_id', $attendance->id)->exists()) {
            return;
        }

        $overtimeReason = $this->resolveOvertimeReason($attendance, $isAutoCheckout);

        $approval = OvertimeApproval::create([
            'attendance_id'   => $attendance->id,
            'user_id'         => $attendance->user_id,
            'company_id'      => $attendance->company_id,
            'overtime_minutes'=> $attendance->overtime_minutes,
            'status'          => 'pending',
            'is_auto_checkout'=> $isAutoCheckout,
            'overtime_reason' => $overtimeReason,
        ]);

        // Cari info user karyawan
        $employee = User::find($attendance->user_id);
        $overtimeFormatted = $this->formatMinutes($attendance->overtime_minutes);
        $tanggal = Carbon::parse($attendance->date)->format('d/m/Y');

        // Notifikasi ke semua HRD/admin/super_admin perusahaan
        $approvers = DB::table('users')
            ->where('company_id', $attendance->company_id)
            ->whereIn('role', ['hrd', 'admin', 'super_admin'])
            ->where('is_active', true)
            ->pluck('id');

        foreach ($approvers as $approverId) {
            $this->notifyUser($approverId, 'overtime_pending', [
                'message'          => ($employee ? $employee->name : 'Karyawan') . " mengajukan lembur {$overtimeFormatted} ({$tanggal})." . ($isAutoCheckout ? ' [Auto-Checkout]' : ''),
                'overtime_id'      => $approval->id,
                'attendance_id'    => $attendance->id,
                'user_id'          => $attendance->user_id,
                'user_name'        => $employee ? $employee->name : null,
                'overtime_minutes' => $attendance->overtime_minutes,
                'is_auto_checkout' => $isAutoCheckout,
                'overtime_reason'  => $overtimeReason,
                'date'             => $tanggal,
            ], 'overtime_approval', $approval->id);
        }
    }

    // ─── Helper: tentukan alasan lembur otomatis untuk info HRD ─────────────
    private function resolveOvertimeReason(Attendance $attendance, bool $isAutoCheckout): ?string
    {
        if ($isAutoCheckout) {
            return 'Lupa checkout (auto-checkout oleh sistem)';
        }

        $user = User::find($attendance->user_id);
        $date = (string) $attendance->date;

        if ($user) {
            $schedule = $this->getWorkSchedule($user, $date);

            // Shift menandai hari ini libur (is_off)
            if ($schedule['is_off']) {
                return 'Masuk di hari libur (jadwal shift libur)';
            }
        }

        // Hari libur nasional/weekend
        if ($attendance->is_holiday) {
            return 'Masuk di hari libur';
        }

        return null;
    }

    // ─── Helper: kirim FCM push notification (fire-and-forget) ─────────────
    //     Jika FIREBASE_SERVER_KEY tidak dikonfigurasi, langkah ini dilewati.
    private function sendFcmPush(string $fcmToken, string $title, string $body, array $data = []): void
    {
        app(FcmService::class)->send($fcmToken, $title, $body, $data);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // BAGIAN B2 — Endpoint tambahan untuk sistem auto-checkout & overtime
    // ─────────────────────────────────────────────────────────────────────────

    // registerFcmToken() — simpan FCM token device karyawan (dipanggil saat login/buka app)
    public function registerFcmToken(Request $request): JsonResponse
    {
        $request->validate(['fcm_token' => 'required|string|max:512']);
        $request->user()->update(['fcm_token' => $request->fcm_token]);

        return response()->json(['message' => 'FCM token berhasil disimpan.']);
    }

    // checkStatus() — cek status presensi hari ini (dipakai Flutter untuk polling)
    //     Mengembalikan info checkout, overtime, dan jadwal auto-checkout.
    public function checkStatus(Request $request): JsonResponse
    {
        $user  = $request->user();
        $today = $this->todayDate();

        $attendance = Attendance::where('user_id', $user->id)
            ->whereDate('date', $today)
            ->first();

        // Jika tidak ada presensi hari ini, cek apakah ada presensi KEMARIN yang masih terbuka (belum checkout) 
        // khusus untuk shift lintas hari (cross-day).
        if (! $attendance) {
            $crossDay = \App\Http\Controllers\API\ShiftController::resolveYesterdayCrossDay($user, (string) $today);
            if ($crossDay) {
                $yesterday = Carbon::parse($today)->subDay()->toDateString();
                $attendance = Attendance::where('user_id', $user->id)
                    ->whereDate('date', $yesterday)
                    ->whereNull('check_out_time')
                    ->first();
            }
        }

        if (! $attendance || ! $attendance->check_in_time) {
            return response()->json([
                'checked_in'          => false,
                'checked_out'         => false,
                'attendance'          => null,
                'overtime_approval'   => null,
                'scheduled_auto_checkout_at' => null,
            ]);
        }

        // Ambil jadwal efektif saat karyawan check-in (shift aktif atau default kantor)
        $scheduleDate = $attendance->date;
        $jadwalHariIni = $this->getWorkSchedule($user, $scheduleDate);

        // Hitung waktu auto-checkout yang dijadwalkan (untuk Flutter scheduling notif lokal).
        // Gunakan jam pulang dari shift aktif jika ada; fallback ke kantor.
        $scheduledAutoCheckout = null;
        if (! $attendance->check_out_time) {
            $office    = $jadwalHariIni['office'];
            $jamPulang = $jadwalHariIni['work_end_time'];
            if ($office && $jamPulang) {
                $graceMinutes = (int) ($office->auto_checkout_grace_minutes ?? 60);
                // Shift lintas hari: jam pulang berada di hari BERIKUTNYA setelah tanggal check-in.
                $isCrossDay  = ! empty($jadwalHariIni['is_cross_day']);
                $workEndDate = $isCrossDay
                    ? Carbon::parse($scheduleDate, 'Asia/Jakarta')->addDay()->toDateString()
                    : $scheduleDate;
                $workEnd               = Carbon::parse($workEndDate . ' ' . $jamPulang, 'Asia/Jakarta');
                $scheduledAutoCheckout = $workEnd->copy()->addMinutes($graceMinutes)->toIso8601String();
            }
        }

        $overtimeApproval = OvertimeApproval::where('attendance_id', $attendance->id)->first();

        return response()->json([
            'checked_in'  => true,
            'checked_out' => (bool) $attendance->check_out_time,
            'attendance'  => $attendance->only([
                'id', 'date', 'check_in_time', 'check_out_time',
                'status', 'work_minutes', 'overtime_minutes',
                'is_auto_checkout', 'auto_checkout_at',
            ]),
            // Jadwal shift aktif hari ini (untuk tampilan di Flutter)
            'active_shift' => $jadwalHariIni['source'] === 'shift' ? [
                'shift_id'        => $jadwalHariIni['shift_id'],
                'shift_name'      => $jadwalHariIni['shift_name'],
                'work_start_time' => $jadwalHariIni['work_start_time'],
                'work_end_time'   => $jadwalHariIni['work_end_time'],
            ] : null,
            'scheduled_auto_checkout_at' => $scheduledAutoCheckout,
            'overtime_approval' => $overtimeApproval ? $overtimeApproval->only([
                'id', 'overtime_minutes', 'status', 'reviewed_at', 'notes',
            ]) : null,
        ]);
    }

    // ═══════════════════════════════════════════════════════════
    // BAGIAN C — HRD: manajemen approval lembur
    // ═══════════════════════════════════════════════════════════

    // listOvertimeApprovals() — daftar pengajuan lembur untuk HRD (filter status/user/tanggal)
    public function listOvertimeApprovals(Request $request): JsonResponse
    {
        $actor = $request->user();

        $validated = $request->validate([
            'status'     => 'nullable|in:pending,approved,rejected',
            'user_id'    => 'nullable|integer',
            'start_date' => 'nullable|date',
            'end_date'   => 'nullable|date|after_or_equal:start_date',
        ]);

        $approvals = OvertimeApproval::query()
            ->join('users', 'overtime_approvals.user_id', '=', 'users.id')
            ->join('attendances', 'overtime_approvals.attendance_id', '=', 'attendances.id')
            ->when(
                $actor->role !== 'super_admin',
                fn ($q) => $q->where('overtime_approvals.company_id', $actor->company_id)
            )
            ->when($validated['status'] ?? null, fn ($q, $s) => $q->where('overtime_approvals.status', $s))
            ->when($validated['user_id'] ?? null, fn ($q, $u) => $q->where('overtime_approvals.user_id', $u))
            ->when($validated['start_date'] ?? null, fn ($q, $d) => $q->where('attendances.date', '>=', $d))
            ->when($validated['end_date']   ?? null, fn ($q, $d) => $q->where('attendances.date', '<=', $d))
            ->select([
                'overtime_approvals.id',
                'overtime_approvals.attendance_id',
                'overtime_approvals.user_id',
                'users.name as user_name',
                'users.department',
                'attendances.date as attendance_date',
                'attendances.check_in_time',
                'attendances.check_out_time',
                'overtime_approvals.overtime_minutes',
                'overtime_approvals.status',
                'overtime_approvals.is_auto_checkout',
                'overtime_approvals.overtime_reason',
                'overtime_approvals.reviewed_at',
                'overtime_approvals.notes',
                'overtime_approvals.created_at',
            ])
            ->orderByDesc('attendances.date')
            ->paginate(20);

        // Tambahkan format jam untuk kemudahan tampilan
        $approvals->getCollection()->transform(function ($a) {
            $a->overtime_formatted = $this->formatMinutes((int) $a->overtime_minutes);

            if ($a->check_in_time) {
                $a->check_in_time = \Carbon\Carbon::parse($a->check_in_time)->toJSON();
            }
            if ($a->check_out_time) {
                $a->check_out_time = \Carbon\Carbon::parse($a->check_out_time)->toJSON();
            }

            return $a;
        });

        return response()->json($approvals);
    }

    // approveOvertime() — HRD setujui lembur (overtime_minutes dikonfirmasi)
    public function approveOvertime(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();

        $validated = $request->validate([
            'notes' => 'nullable|string|max:1000',
        ]);

        $approval = OvertimeApproval::when(
            $actor->role !== 'super_admin',
            fn ($q) => $q->where('company_id', $actor->company_id)
        )->find($id);

        if (! $approval) {
            return response()->json(['message' => 'Data lembur tidak ditemukan.'], 404);
        }

        if ($approval->status !== 'pending') {
            return response()->json(['message' => 'Pengajuan lembur sudah diproses sebelumnya.'], 403);
        }

        $approval->update([
            'status'      => 'approved',
            'reviewed_by' => $actor->id,
            'reviewed_at' => now(),
            'notes'       => $validated['notes'] ?? null,
        ]);

        // overtime_minutes di attendances TETAP sesuai hitungan (sudah disetujui)
        $this->logActivity(
            $actor->id,
            $approval->company_id,
            'overtime_approved',
            "Approve lembur #{$approval->id} ({$this->formatMinutes($approval->overtime_minutes)}) karyawan #{$approval->user_id}",
            'overtime_approval',
            $approval->id
        );

        // Notifikasi ke karyawan
        $employee = User::find($approval->user_id);
        $tanggal  = Carbon::parse($approval->attendance->date)->format('d/m/Y');
        $this->notifyUser($approval->user_id, 'overtime_approved', [
            'message'          => "Lembur Anda ({$this->formatMinutes($approval->overtime_minutes)}) pada {$tanggal} telah disetujui.",
            'overtime_id'      => $approval->id,
            'overtime_minutes' => $approval->overtime_minutes,
            'status'           => 'approved',
        ], 'overtime_approval', $approval->id);

        // Kirim push notification FCM ke karyawan
        if ($employee && $employee->fcm_token) {
            $this->sendFcmPush(
                $employee->fcm_token,
                '✅ Lembur Disetujui',
                "Lembur {$this->formatMinutes($approval->overtime_minutes)} pada {$tanggal} telah disetujui oleh HRD.",
                ['type' => 'overtime_approved', 'overtime_id' => (string) $approval->id]
            );
        }

        return response()->json([
            'message'  => 'Lembur berhasil disetujui.',
            'approval' => $approval->only(['id', 'status', 'overtime_minutes', 'reviewed_at', 'notes']),
        ]);
    }

    // rejectOvertime() — HRD tolak lembur (overtime_minutes di attendance di-set 0)
    public function rejectOvertime(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'notes' => 'required|string|max:1000',
        ]);

        $actor = $request->user();

        $approval = OvertimeApproval::when(
            $actor->role !== 'super_admin',
            fn ($q) => $q->where('company_id', $actor->company_id)
        )->find($id);

        if (! $approval) {
            return response()->json(['message' => 'Data lembur tidak ditemukan.'], 404);
        }

        if ($approval->status !== 'pending') {
            return response()->json(['message' => 'Pengajuan lembur sudah diproses sebelumnya.'], 403);
        }

        $approval->update([
            'status'      => 'rejected',
            'reviewed_by' => $actor->id,
            'reviewed_at' => now(),
            'notes'       => $request->notes,
        ]);

        // Jika ditolak → reset overtime_minutes ke 0 di tabel attendances
        Attendance::where('id', $approval->attendance_id)
            ->update(['overtime_minutes' => 0]);

        $this->logActivity(
            $actor->id,
            $approval->company_id,
            'overtime_rejected',
            "Reject lembur #{$approval->id} karyawan #{$approval->user_id}: {$request->notes}",
            'overtime_approval',
            $approval->id
        );

        // Notifikasi ke karyawan
        $employee = User::find($approval->user_id);
        $tanggal  = Carbon::parse($approval->attendance->date)->format('d/m/Y');
        $this->notifyUser($approval->user_id, 'overtime_rejected', [
            'message'          => "Lembur Anda pada {$tanggal} tidak disetujui.",
            'overtime_id'      => $approval->id,
            'status'           => 'rejected',
            'notes'            => $request->notes,
        ], 'overtime_approval', $approval->id);

        // Kirim push notification FCM ke karyawan
        if ($employee && $employee->fcm_token) {
            $this->sendFcmPush(
                $employee->fcm_token,
                '❌ Lembur Ditolak',
                "Lembur pada {$tanggal} tidak disetujui. Alasan: {$request->notes}",
                ['type' => 'overtime_rejected', 'overtime_id' => (string) $approval->id]
            );
        }

        return response()->json([
            'message'  => 'Lembur ditolak. Jam lembur karyawan di-reset ke 0.',
            'approval' => $approval->only(['id', 'status', 'reviewed_at', 'notes']),
        ]);
    }

    // ─── DEVICE BINDING — approval pindah perangkat (cegah titip absen) ────────

    // listDeviceChanges() — daftar permintaan pindah device untuk HR.
    public function listDeviceChanges(Request $request): JsonResponse
    {
        $actor = $request->user();

        $status = $request->query('status'); // pending|approved|rejected|null(semua)

        $query = DeviceChangeRequest::with([
                'user:id,name,email,employee_code,department',
                'reviewer:id,name',
            ])
            ->when(
                $actor->role !== 'super_admin',
                fn ($q) => $q->where('company_id', $actor->company_id)
            )
            ->when(
                in_array($status, ['pending', 'approved', 'rejected'], true),
                fn ($q) => $q->where('status', $status)
            )
            ->orderByRaw("FIELD(status, 'pending', 'approved', 'rejected')")
            ->orderByDesc('created_at');

        return response()->json($query->paginate(20));
    }

    // approveDeviceChange() — HR setujui pindah device: device baru GANTIKAN lama.
    public function approveDeviceChange(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();

        $validated = $request->validate([
            'notes' => 'nullable|string|max:1000',
        ]);

        $req = DeviceChangeRequest::when(
            $actor->role !== 'super_admin',
            fn ($q) => $q->where('company_id', $actor->company_id)
        )->find($id);

        if (! $req) {
            return response()->json(['message' => 'Permintaan pindah perangkat tidak ditemukan.'], 404);
        }

        if ($req->status !== 'pending') {
            return response()->json(['message' => 'Permintaan sudah diproses sebelumnya.'], 403);
        }

        // Ganti binding: device baru menggantikan device lama (1 akun = 1 device).
        $employee = User::find($req->user_id);
        if ($employee) {
            $employee->forceFill([
                'device_id'       => $req->new_device_id,
                'device_name'     => $req->new_device_name,
                'device_bound_at' => now(),
            ])->save();

            // Amankan: hapus token mobile lama agar device lama tak bisa dipakai lagi.
            $employee->tokens()->where('name', 'auth-token-mobile')->delete();
        }

        $req->update([
            'status'      => 'approved',
            'reviewed_by' => $actor->id,
            'reviewed_at' => now(),
            'notes'       => $validated['notes'] ?? null,
        ]);

        $this->logActivity(
            $actor->id,
            $req->company_id,
            'device_change_approved',
            "Approve pindah perangkat #{$req->id} karyawan #{$req->user_id} → {$req->new_device_name}",
            'device_change_request',
            $req->id
        );

        // Notifikasi ke karyawan.
        $this->notifyUser($req->user_id, 'device_change_approved', [
            'message'    => 'Permintaan pindah perangkat Anda telah disetujui. '
                . 'Silakan login kembali di perangkat baru.',
            'request_id' => $req->id,
            'status'     => 'approved',
        ], 'device_change_request', $req->id);

        return response()->json([
            'message' => 'Pindah perangkat disetujui. Perangkat lama tidak lagi bisa digunakan.',
            'request' => $req->only(['id', 'status', 'reviewed_at', 'notes']),
        ]);
    }

    // rejectDeviceChange() — HR tolak pindah device: binding lama tetap.
    public function rejectDeviceChange(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'notes' => 'required|string|max:1000',
        ]);

        $actor = $request->user();

        $req = DeviceChangeRequest::when(
            $actor->role !== 'super_admin',
            fn ($q) => $q->where('company_id', $actor->company_id)
        )->find($id);

        if (! $req) {
            return response()->json(['message' => 'Permintaan pindah perangkat tidak ditemukan.'], 404);
        }

        if ($req->status !== 'pending') {
            return response()->json(['message' => 'Permintaan sudah diproses sebelumnya.'], 403);
        }

        $req->update([
            'status'      => 'rejected',
            'reviewed_by' => $actor->id,
            'reviewed_at' => now(),
            'notes'       => $request->notes,
        ]);

        $this->logActivity(
            $actor->id,
            $req->company_id,
            'device_change_rejected',
            "Reject pindah perangkat #{$req->id} karyawan #{$req->user_id}: {$request->notes}",
            'device_change_request',
            $req->id
        );

        // Notifikasi ke karyawan.
        $this->notifyUser($req->user_id, 'device_change_rejected', [
            'message'    => 'Permintaan pindah perangkat Anda ditolak. '
                . 'Hubungi HR untuk informasi lebih lanjut.',
            'request_id' => $req->id,
            'status'     => 'rejected',
            'notes'      => $request->notes,
        ], 'device_change_request', $req->id);

        return response()->json([
            'message' => 'Permintaan pindah perangkat ditolak.',
            'request' => $req->only(['id', 'status', 'reviewed_at', 'notes']),
        ]);
    }

    // 8. myAttendance() — riwayat presensi user yang login
    public function myAttendance(Request $request): JsonResponse
    {
        $attendances = Attendance::where('user_id', $request->user()->id)
            ->with(['overtimeApproval:id,attendance_id,status,overtime_minutes,notes,reviewed_at,is_auto_checkout,overtime_reason'])
            ->select([
                'id', 'date', 'check_in_time', 'check_in_type', 'check_in_distance_meters',
                'check_out_time', 'check_out_type', 'status', 'notes',
                'work_minutes', 'overtime_minutes', 'is_holiday', 'is_auto_checkout',
            ])
            ->orderByDesc('date')
            ->paginate(30);

        // Tambahkan field overtime_approval ke setiap record:
        //   - null  : tidak ada lembur (overtime_minutes = 0 atau belum checkout)
        //   - object: status pending/approved/rejected beserta detailnya
        $attendances->getCollection()->transform(function ($att) {
            $oa = $att->overtimeApproval;
            $att->overtime_approval = $oa ? [
                'id'               => $oa->id,
                'status'           => $oa->status,           // pending | approved | rejected
                'overtime_minutes' => $oa->overtime_minutes,
                'notes'            => $oa->notes,
                'reviewed_at'      => $oa->reviewed_at,
                'is_auto_checkout' => $oa->is_auto_checkout,
                'overtime_reason'  => $oa->overtime_reason,
            ] : null;

            // Hapus relasi Eloquent dari payload (sudah dimap ke array di atas)
            unset($att->overtimeApproval);

            return $att;
        });

        return response()->json($attendances);
    }

    // myOvertimeApprovals() — riwayat pengajuan lembur milik karyawan yang login
    public function myOvertimeApprovals(Request $request): JsonResponse
    {
        $user = $request->user();

        $approvals = OvertimeApproval::where('user_id', $user->id)
            ->join('attendances', 'overtime_approvals.attendance_id', '=', 'attendances.id')
            ->select([
                'overtime_approvals.id',
                'overtime_approvals.attendance_id',
                'attendances.date as attendance_date',
                'attendances.check_in_time',
                'attendances.check_out_time',
                'overtime_approvals.overtime_minutes',
                'overtime_approvals.status',
                'overtime_approvals.is_auto_checkout',
                'overtime_approvals.overtime_reason',
                'overtime_approvals.reviewed_at',
                'overtime_approvals.notes',
                'overtime_approvals.created_at',
            ])
            ->orderByDesc('attendances.date')
            ->paginate(20);

        $approvals->getCollection()->transform(function ($a) {
            $a->overtime_formatted = $this->formatMinutes((int) $a->overtime_minutes);

            if ($a->check_in_time) {
                $a->check_in_time = \Carbon\Carbon::parse($a->check_in_time)->toJSON();
            }
            if ($a->check_out_time) {
                $a->check_out_time = \Carbon\Carbon::parse($a->check_out_time)->toJSON();
            }

            return $a;
        });

        return response()->json($approvals);
    }

    // 8b. myLeaveBalance() — saldo cuti milik karyawan yang login
    public function myLeaveBalance(Request $request): JsonResponse
    {
        $user = $request->user();
        $year = (int) $request->query('year', now()->year);

        // Pastikan baris saldo ada: cuti (kuota 12) dan izin (tanpa batas, quota=0)
        LeaveBalance::firstOrCreate(
            ['user_id' => $user->id, 'year' => $year, 'leave_type' => 'cuti'],
            ['company_id' => $user->company_id, 'quota' => 12, 'used' => 0]
        );
        LeaveBalance::firstOrCreate(
            ['user_id' => $user->id, 'year' => $year, 'leave_type' => 'izin'],
            ['company_id' => $user->company_id, 'quota' => 0, 'used' => 0]
        );

        $balances = LeaveBalance::where('user_id', $user->id)
            ->where('year', $year)
            ->get()
            ->map(fn ($b) => [
                'leave_type' => $b->leave_type,
                'quota'      => $b->quota,
                'used'       => $b->used,
                'remaining'  => $b->quota - $b->used,
            ]);

        return response()->json(['year' => $year, 'balances' => $balances]);
    }

    // 8c. myLeaves() — riwayat pengajuan izin/cuti milik karyawan yang login
    public function myLeaves(Request $request): JsonResponse
    {
        $user = $request->user();

        $leaves = LeaveRequest::where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($l) => [
                'id'               => $l->id,
                'leave_type'       => $l->leave_type,
                'start_date'       => $l->start_date,
                'end_date'         => $l->end_date,
                'total_days'       => $l->total_days,
                'reason'           => $l->reason,
                'status'           => $l->status,
                'rejection_reason' => $l->rejection_reason,
                'has_document'     => ! empty($l->document_path),
                'created_at'       => $l->created_at,
            ]);

        return response()->json(['leaves' => $leaves]);
    }

    // 9. requestLeave() — ajukan WFH/izin/sakit/cuti
    public function requestLeave(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'leave_type' => 'required|in:wfh,izin,sakit,cuti',
            'start_date' => 'required|date',
            'end_date'   => 'required|date|after_or_equal:start_date',
            'reason'     => 'required|string|max:1000',
            // Surat dokter WAJIB untuk jenis 'sakit' — foto/gambar atau PDF, maks 10 MB.
            'document'   => 'required_if:leave_type,sakit|file|mimes:jpeg,jpg,png,webp,gif,pdf|max:10240',
        ], [
            'document.required_if' => 'Surat dokter wajib dilampirkan untuk pengajuan sakit.',
            'document.mimes'       => 'Surat dokter harus berupa gambar (JPG/PNG/WEBP) atau PDF.',
            'document.max'         => 'Ukuran surat dokter maksimal 10 MB.',
        ]);

        $user = $request->user();

        // Hitung HARI KERJA saja (lewati weekend & libur nasional) agar kuota adil.
        $totalDays = $this->countWorkingDays(
            Carbon::parse($validated['start_date']),
            Carbon::parse($validated['end_date']),
            $user->company_id
        );

        if ($totalDays < 1) {
            return response()->json([
                'message' => 'Rentang tanggal tidak mengandung hari kerja (semua weekend/libur).',
            ], 422);
        }

        // Cek saldo cuti sebelum membuat pengajuan agar karyawan langsung tahu di awal
        if ($validated['leave_type'] === 'cuti') {
            $year    = Carbon::parse($validated['start_date'])->year;
            $balance = LeaveBalance::firstOrCreate(
                ['user_id' => $user->id, 'year' => $year, 'leave_type' => 'cuti'],
                ['company_id' => $user->company_id, 'quota' => self::DEFAULT_LEAVE_QUOTA['cuti'], 'used' => 0]
            );
            $remaining = $balance->quota - $balance->used;
            if ($remaining <= 0) {
                return response()->json([
                    'message'          => 'Saldo cuti Anda sudah habis. Tidak dapat mengajukan cuti.',
                    'remaining_quota'  => 0,
                ], 422);
            }
            if ($totalDays > $remaining) {
                return response()->json([
                    'message'          => "Saldo cuti tidak cukup. Sisa {$remaining} hari, diminta {$totalDays} hari.",
                    'remaining_quota'  => $remaining,
                ], 422);
            }
        }

        // Simpan surat dokter bila dilampirkan (disk privat 'local')
        $documentPath = null;
        if ($request->hasFile('document')) {
            $documentPath = $request->file('document')->store('leave_documents');
        }

        $leave = LeaveRequest::create([
            'user_id'       => $user->id,
            'company_id'    => $user->company_id,
            'leave_type'    => $validated['leave_type'],
            'start_date'    => $validated['start_date'],
            'end_date'      => $validated['end_date'],
            'total_days'    => $totalDays,
            'reason'        => $validated['reason'],
            'document_path' => $documentPath,
            'status'        => 'pending',
        ]);

        $this->logActivity($user->id, $user->company_id, 'leave_requested', "Ajukan {$leave->leave_type} ({$totalDays} hari)", 'leave_request', $leave->id);

        // Notifikasi ke HRD / admin perusahaan yang sama
        $approvers = DB::table('users')
            ->where('company_id', $user->company_id)
            ->whereIn('role', ['hrd', 'admin', 'super_admin'])
            ->where('is_active', true)
            ->pluck('id');

        foreach ($approvers as $approverId) {
            $this->notifyUser($approverId, 'leave_requested', [
                'message'    => "{$user->name} mengajukan {$leave->leave_type} ({$totalDays} hari).",
                'leave_id'   => $leave->id,
                'leave_type' => $leave->leave_type,
                'user_name'  => $user->name,
            ], 'leave_request', $leave->id);
        }

        return response()->json([
            'message' => 'Permintaan berhasil diajukan.',
            'leave'   => $leave->only([
                'id', 'leave_type', 'start_date', 'end_date', 'total_days', 'status',
            ]),
        ], 201);
    }
}
