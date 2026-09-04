<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\AttendanceSetting;
use App\Models\LeaveBalance;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    /**
     * Hanya super_admin yang boleh mengelola akun super_admin.
     * Cegah admin menonaktifkan/reset/ubah akun setara/di atasnya.
     */
    private function denyIfProtectedTarget(User $actor, User $target): ?JsonResponse
    {
        if ($target->role === 'super_admin' && $actor->role !== 'super_admin') {
            return response()->json([
                'message' => 'Anda tidak berwenang mengelola akun super admin.',
            ], 403);
        }

        return null;
    }

    /**
     * List semua karyawan dalam satu perusahaan.
     * GET /api/v1/admin/users
     */
    public function index(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;

        $query = User::where('company_id', $companyId);

        if ($request->has('status')) {
            if ($request->status === 'active') {
                $query->where('is_active', true);
            } elseif ($request->status === 'inactive') {
                $query->where('is_active', false);
            }
        }

        $limit = $request->query('per_page') ? (int) $request->query('per_page') : 2000;

        $users = $query->with('office:id,office_name')
            ->select([
                'id', 'company_id', 'employee_code', 'name', 'email', 'phone',
                'gender', 'birth_place', 'birth_date', 'is_pregnant',
                'role', 'department', 'attendance_setting_id', 'monthly_claim_limit',
                'is_active', 'employment_type', 'joined_date', 'identity_number',
                'contract_start_date', 'contract_end_date', 'bank_name',
                'bank_account_no', 'bank_account_holder', 'created_at', 'updated_at',
            ])
            ->latest()
            ->paginate($limit);

        return response()->json($users);
    }

    /**
     * Tambah karyawan baru dengan password hash.
     * POST /api/v1/admin/users
     */
    public function store(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;

        $validated = $request->validate([
            'name'                  => 'required|string|max:255',
            'email'                 => 'required|email|unique:users,email',
            'password'              => 'required|string|min:8',
            'role'                  => ['required', Rule::in(['employee', 'finance', 'hrd', 'admin', 'super_admin'])],
            'employee_code'         => 'nullable|string|max:50|unique:users,employee_code',
            'identity_number'       => 'nullable|string|size:16|unique:users,identity_number',
            'department'            => 'nullable|string|max:100',
            'phone'                 => 'nullable|string|max:13',
            'gender'                => ['nullable', 'string', Rule::in(['Laki-laki', 'Perempuan', 'male', 'female', 'L', 'P'])],
            'birth_place'           => 'nullable|string|max:100',
            'birth_date'            => 'nullable|date|before:today',
            'is_pregnant'           => 'nullable|boolean',
            // Kantor penempatan — harus milik perusahaan yang sama.
            'attendance_setting_id' => [
                'nullable',
                Rule::exists('attendance_settings', 'id')->where('company_id', $companyId),
            ],
            'monthly_claim_limit'   => 'nullable|numeric|min:0',
            // Tipe hubungan kerja
            'employment_type'       => ['nullable', Rule::in(['PKWTT', 'PKWT', 'Probation', 'Internship'])],
            'joined_date'           => 'nullable|date',
            'contract_start_date'   => 'nullable|date',
            'contract_end_date'     => 'nullable|date|after_or_equal:contract_start_date',
            // Data Rekening Bank
            'bank_name'             => 'nullable|string|max:50',
            'bank_account_no'       => 'nullable|string|max:50',
            'bank_account_holder'   => 'nullable|string|max:150',
        ]);

        $gender = null;
        if (! empty($validated['gender'])) {
            $g = strtolower(trim($validated['gender']));
            $gender = in_array($g, ['perempuan', 'female', 'p']) ? 'Perempuan' : 'Laki-laki';
        }

        $user = User::create([
            'company_id'            => $companyId,
            'employee_code'         => $validated['employee_code'] ?? null,
            'name'                  => $validated['name'],
            'email'                 => $validated['email'],
            'password'              => Hash::make($validated['password']),
            'role'                  => $validated['role'],
            'department'            => $validated['department'] ?? null,
            'identity_number'       => $validated['identity_number'] ?? null,
            'phone'                 => $validated['phone'] ?? null,
            'gender'                => $gender,
            'birth_place'           => $validated['birth_place'] ?? null,
            'birth_date'            => $validated['birth_date'] ?? null,
            'is_pregnant'           => $gender === 'Perempuan' ? (bool) ($validated['is_pregnant'] ?? false) : false,
            'attendance_setting_id' => $validated['attendance_setting_id'] ?? null,
            'monthly_claim_limit'   => $validated['monthly_claim_limit'] ?? null,
            'is_active'             => true,
            'employment_type'       => $validated['employment_type'] ?? null,
            'joined_date'           => $validated['joined_date'] ?? null,
            'contract_start_date'   => $validated['contract_start_date'] ?? null,
            'contract_end_date'     => $validated['contract_end_date'] ?? null,
            'bank_name'             => $validated['bank_name'] ?? null,
            'bank_account_no'       => $validated['bank_account_no'] ?? null,
            'bank_account_holder'   => $validated['bank_account_holder'] ?? null,
        ]);

        AuditLogger::log(
            action: 'EMPLOYEE_CREATED',
            description: "Menambahkan karyawan baru: {$user->name} ({$user->email}) - Role: {$user->role}",
            category: AuditLogger::CATEGORY_HR,
            severity: AuditLogger::SEVERITY_INFO,
            entityType: 'User',
            entityId: $user->id,
            newValues: $user->only([
                'employee_code', 'name', 'email', 'role', 'department', 'phone',
                'gender', 'birth_place', 'birth_date', 'is_pregnant',
                'identity_number', 'monthly_claim_limit', 'employment_type',
                'joined_date', 'contract_start_date', 'contract_end_date',
                'bank_name', 'bank_account_no', 'bank_account_holder',
            ])
        );

        return response()->json([
            'message' => 'Karyawan berhasil ditambahkan.',
            'user'    => $user->only([
                'id', 'employee_code', 'name', 'email', 'phone', 'role', 'department',
                'gender', 'birth_place', 'birth_date', 'is_pregnant',
                'attendance_setting_id', 'monthly_claim_limit', 'is_active', 'company_id',
                'employment_type', 'joined_date', 'contract_start_date', 'contract_end_date',
                'identity_number', 'bank_name', 'bank_account_no', 'bank_account_holder'
            ]),
        ], 201);
    }

    /**
     * Edit data karyawan.
     * PUT /api/v1/admin/users/{user}
     */
    public function update(Request $request, User $user): JsonResponse
    {
        $actor = $request->user();

        // Cegah admin mengubah akun super_admin.
        if ($deny = $this->denyIfProtectedTarget($actor, $user)) {
            return $deny;
        }

        $validated = $request->validate([
            'name'                  => 'sometimes|required|string|max:255',
            'email'                 => ['sometimes', 'required', 'email', Rule::unique('users')->ignore($user->id)],
            'role'                  => ['sometimes', 'required', Rule::in(['employee', 'finance', 'hrd', 'admin', 'super_admin'])],
            'employee_code'         => ['nullable', 'string', 'max:50', Rule::unique('users')->ignore($user->id)],
            'identity_number'       => ['nullable', 'string', 'size:16', Rule::unique('users')->ignore($user->id)],
            'department'            => 'nullable|string|max:100',
            'phone'                 => 'nullable|string|max:13',
            'gender'                => ['sometimes', 'nullable', 'string', Rule::in(['Laki-laki', 'Perempuan', 'male', 'female', 'L', 'P'])],
            'birth_place'           => 'sometimes|nullable|string|max:100',
            'birth_date'            => 'sometimes|nullable|date|before:today',
            'is_pregnant'           => 'sometimes|nullable|boolean',
            // Kantor penempatan — harus milik perusahaan karyawan tsb.
            'attendance_setting_id' => [
                'sometimes',
                'nullable',
                Rule::exists('attendance_settings', 'id')->where('company_id', $user->company_id),
            ],
            'monthly_claim_limit'   => 'nullable|numeric|min:0',
            // Tipe hubungan kerja
            'employment_type'       => ['sometimes', 'nullable', Rule::in(['PKWTT', 'PKWT', 'Probation', 'Internship'])],
            'joined_date'           => 'sometimes|nullable|date',
            'contract_start_date'   => 'sometimes|nullable|date',
            'contract_end_date'     => 'sometimes|nullable|date|after_or_equal:contract_start_date',
            // Data Rekening Bank
            'bank_name'             => 'sometimes|nullable|string|max:50',
            'bank_account_no'       => 'sometimes|nullable|string|max:50',
            'bank_account_holder'   => 'sometimes|nullable|string|max:150',
        ]);

        // Hanya super_admin yang boleh menetapkan role super_admin (cegah escalation).
        if (isset($validated['role']) && $validated['role'] === 'super_admin' && $actor->role !== 'super_admin') {
            return response()->json([
                'message' => 'Hanya super admin yang bisa menetapkan role super admin.',
            ], 403);
        }

        if (array_key_exists('gender', $validated)) {
            if (! empty($validated['gender'])) {
                $g = strtolower(trim($validated['gender']));
                $validated['gender'] = in_array($g, ['perempuan', 'female', 'p']) ? 'Perempuan' : 'Laki-laki';
            } else {
                $validated['gender'] = null;
            }
        }

        // Jika gender bukan Perempuan, pastikan is_pregnant = false
        $targetGender = $validated['gender'] ?? $user->gender;
        if ($targetGender !== 'Perempuan') {
            $validated['is_pregnant'] = false;
        }

        $original = $user->only([
            'name', 'email', 'phone', 'gender', 'birth_place', 'birth_date', 'is_pregnant',
            'role', 'department', 'employee_code',
            'identity_number', 'attendance_setting_id', 'monthly_claim_limit',
            'employment_type', 'joined_date', 'contract_start_date',
            'contract_end_date', 'bank_name', 'bank_account_no', 'bank_account_holder',
        ]);

        $user->update($validated);

        $updated = $user->only(array_keys($original));

        // Tentukan tingkat severity: jika rekening bank, role, NIK, atau limit klaim berubah -> CRITICAL
        $isCriticalChange = (isset($original['bank_account_no']) && $original['bank_account_no'] != ($updated['bank_account_no'] ?? null))
            || (isset($original['role']) && $original['role'] != ($updated['role'] ?? null))
            || (isset($original['identity_number']) && $original['identity_number'] != ($updated['identity_number'] ?? null))
            || (isset($original['monthly_claim_limit']) && $original['monthly_claim_limit'] != ($updated['monthly_claim_limit'] ?? null));

        $severity = $isCriticalChange ? AuditLogger::SEVERITY_CRITICAL : AuditLogger::SEVERITY_INFO;
        $category = (isset($original['bank_account_no']) && $original['bank_account_no'] != ($updated['bank_account_no'] ?? null))
            ? AuditLogger::CATEGORY_FINANCE
            : AuditLogger::CATEGORY_HR;

        AuditLogger::logModelDiff(
            action: 'EMPLOYEE_UPDATED',
            description: "Memperbarui data profil karyawan {$user->name}" . ($isCriticalChange ? " (Data Sensitif Berubah)" : ""),
            category: $category,
            severity: $severity,
            entityType: 'User',
            entityId: $user->id,
            original: $original,
            updated: $updated
        );

        return response()->json([
            'message' => 'Data karyawan berhasil diperbarui.',
            'user'    => $user->only([
                'id', 'employee_code', 'name', 'email', 'phone', 'role', 'department',
                'gender', 'birth_place', 'birth_date', 'is_pregnant',
                'attendance_setting_id', 'monthly_claim_limit', 'is_active', 'company_id',
                'employment_type', 'joined_date', 'contract_start_date', 'contract_end_date',
                'identity_number', 'bank_name', 'bank_account_no', 'bank_account_holder'
            ]),
        ]);
    }

    /**
     * Nonaktifkan akun karyawan — set is_active = false + revoke token.
     * PATCH /api/v1/admin/users/{user}/deactivate
     */
    public function deactivate(Request $request, User $user): JsonResponse
    {
        $actor = $request->user();

        // Cegah admin menonaktifkan akun super_admin.
        if ($deny = $this->denyIfProtectedTarget($actor, $user)) {
            return $deny;
        }

        // Cegah menonaktifkan akun sendiri (footgun: bisa terkunci keluar).
        if ($actor->id === $user->id) {
            return response()->json([
                'message' => 'Anda tidak bisa menonaktifkan akun Anda sendiri.',
            ], 403);
        }

        $user->update(['is_active' => false]);

        // Cabut semua token yang aktif
        $user->tokens()->delete();

        AuditLogger::log(
            action: 'EMPLOYEE_DEACTIVATED',
            description: "Menonaktifkan akun karyawan {$user->name} ({$user->email})",
            category: AuditLogger::CATEGORY_HR,
            severity: AuditLogger::SEVERITY_WARNING,
            entityType: 'User',
            entityId: $user->id,
            oldValues: ['is_active' => true],
            newValues: ['is_active' => false]
        );

        return response()->json([
            'message' => 'Akun karyawan berhasil dinonaktifkan.',
        ]);
    }

    /**
     * Aktifkan kembali akun karyawan — set is_active = true.
     * PATCH /api/v1/admin/users/{user}/activate
     */
    public function activate(Request $request, User $user): JsonResponse
    {
        // Cegah admin mengaktifkan/mengelola akun super_admin.
        if ($deny = $this->denyIfProtectedTarget($request->user(), $user)) {
            return $deny;
        }

        $user->update(['is_active' => true]);

        AuditLogger::log(
            action: 'EMPLOYEE_ACTIVATED',
            description: "Mengaktifkan kembali akun karyawan {$user->name} ({$user->email})",
            category: AuditLogger::CATEGORY_HR,
            severity: AuditLogger::SEVERITY_INFO,
            entityType: 'User',
            entityId: $user->id,
            oldValues: ['is_active' => false],
            newValues: ['is_active' => true]
        );

        return response()->json([
            'message' => 'Akun karyawan berhasil diaktifkan kembali.',
        ]);
    }

    /**
     * Hapus akun karyawan (Soft Delete).
     * DELETE /api/v1/admin/users/{user}
     */
    public function destroy(Request $request, User $user): JsonResponse
    {
        $actor = $request->user();

        // Cegah admin menghapus akun super_admin.
        if ($deny = $this->denyIfProtectedTarget($actor, $user)) {
            return $deny;
        }

        // Cegah menghapus akun sendiri
        if ($actor->id === $user->id) {
            return response()->json([
                'message' => 'Anda tidak bisa menghapus akun Anda sendiri.',
            ], 403);
        }

        // Cegah menghapus user yang masih aktif — harus dinonaktifkan terlebih dahulu
        if ($user->is_active) {
            return response()->json([
                'message' => 'Akun karyawan masih aktif. Silakan nonaktifkan akun terlebih dahulu sebelum menghapus.',
            ], 422);
        }

        // Cabut semua token sesi user
        $user->tokens()->delete();

        AuditLogger::log(
            action: 'EMPLOYEE_DELETED',
            description: "Menghapus akun karyawan {$user->name} ({$user->email}) secara soft delete",
            category: AuditLogger::CATEGORY_HR,
            severity: AuditLogger::SEVERITY_CRITICAL,
            entityType: 'User',
            entityId: $user->id,
            oldValues: $user->only(['name', 'email', 'role', 'department', 'employee_code'])
        );

        // Lakukan soft delete Eloquent (mengisi kolom deleted_at)
        $user->delete();

        return response()->json([
            'message' => 'Akun karyawan berhasil dihapus (soft delete).',
        ]);
    }

    /**
     * Impor massal data karyawan dari file Excel/CSV dengan dynamic mapping.
     * POST /api/v1/admin/users/bulk-import
     */
    public function bulkImport(Request $request): JsonResponse
    {
        $actor = $request->user();
        $companyId = $actor->company_id;

        $validated = $request->validate([
            'users'                          => 'required|array|min:1|max:1000',
            'users.*.name'                   => 'required|string|max:255',
            'users.*.email'                  => 'required|string|max:255',
            'users.*.employee_code'          => 'nullable|string|max:50',
            'users.*.identity_number'        => 'nullable|string|max:20',
            'users.*.phone'                  => 'nullable|string|max:20',
            'users.*.department'             => 'nullable|string|max:100',
            'users.*.role'                   => 'nullable|string',
            'users.*.gender'                 => 'nullable|string',
            'users.*.birth_place'            => 'nullable|string|max:100',
            'users.*.birth_date'             => 'nullable|string',
            'users.*.attendance_setting_id'  => 'nullable|integer',
            'users.*.monthly_claim_limit'    => 'nullable|numeric|min:0',
            'users.*.employment_type'        => 'nullable|string',
            'users.*.joined_date'            => 'nullable|string',
            'users.*.contract_start_date'    => 'nullable|string',
            'users.*.contract_end_date'      => 'nullable|string',
            'users.*.bank_name'              => 'nullable|string|max:50',
            'users.*.bank_account_no'        => 'nullable|string|max:50',
            'users.*.bank_account_holder'    => 'nullable|string|max:150',
            'users.*.leave_balance'          => 'nullable|numeric|min:0',
            'default_password'               => 'nullable|string|min:6',
            'default_role'                   => 'nullable|string',
            'default_attendance_setting_id'  => 'nullable|integer',
            'default_employment_type'        => 'nullable|string',
            'default_wfh_enabled'            => 'nullable|boolean',
            'default_attendance_enabled'     => 'nullable|boolean',
            'default_radius_enabled'         => 'nullable|boolean',
        ]);

        $defaultPassword = $request->input('default_password') ?: 'Karyawan123!';
        $defaultPasswordHash = Hash::make($defaultPassword);
        $defaultRole = in_array($request->input('default_role'), ['employee', 'finance', 'hrd', 'admin'])
            ? $request->input('default_role')
            : 'employee';

        $defaultOfficeId = $request->input('default_attendance_setting_id');
        $defaultEmploymentType = in_array($request->input('default_employment_type'), ['PKWTT', 'PKWT', 'Probation', 'Internship'])
            ? $request->input('default_employment_type')
            : 'PKWTT';
        $defaultWfhEnabled = $request->boolean('default_wfh_enabled', false);
        $defaultAttendanceEnabled = $request->boolean('default_attendance_enabled', true);
        $defaultRadiusEnabled = $request->boolean('default_radius_enabled', false);

        // Ambil data referensi kantor yang valid untuk perusahaan ini
        $validOfficeIds = AttendanceSetting::where('company_id', $companyId)->pluck('id')->flip()->toArray();

        // Validasi defaultOfficeId
        if ($defaultOfficeId && !isset($validOfficeIds[$defaultOfficeId])) {
            $defaultOfficeId = null;
        }

        // Ambil seluruh email dan NIK yang sudah ada di DB untuk pengecekan cepat (O(1) hash lookup)
        $existingEmails = User::withTrashed()
            ->pluck('email')
            ->map(fn($e) => strtolower(trim($e)))
            ->flip()
            ->toArray();

        $existingCodes = User::withTrashed()
            ->where('company_id', $companyId)
            ->whereNotNull('employee_code')
            ->pluck('employee_code')
            ->map(fn($c) => strtolower(trim($c)))
            ->flip()
            ->toArray();

        $imported = 0;
        $skipped = 0;
        $errors = [];
        $importedUsers = [];

        $seenEmailsInBatch = [];
        $seenCodesInBatch = [];

        DB::beginTransaction();
        try {
            foreach ($validated['users'] as $index => $row) {
                $rowNum = $index + 1;
                $name = trim($row['name'] ?? '');
                $rawEmail = strtolower(trim($row['email'] ?? ''));

                if (empty($name)) {
                    $skipped++;
                    $errors[] = [
                        'row'    => $rowNum,
                        'name'   => '-',
                        'email'  => $rawEmail,
                        'reason' => 'Nama karyawan tidak boleh kosong.',
                    ];
                    continue;
                }

                if (empty($rawEmail) || !filter_var($rawEmail, FILTER_VALIDATE_EMAIL)) {
                    $skipped++;
                    $errors[] = [
                        'row'    => $rowNum,
                        'name'   => $name,
                        'email'  => $rawEmail,
                        'reason' => 'Format email tidak valid atau kosong.',
                    ];
                    continue;
                }

                if (isset($existingEmails[$rawEmail])) {
                    $skipped++;
                    $errors[] = [
                        'row'    => $rowNum,
                        'name'   => $name,
                        'email'  => $rawEmail,
                        'reason' => 'Email sudah terdaftar di sistem.',
                    ];
                    continue;
                }

                if (isset($seenEmailsInBatch[$rawEmail])) {
                    $skipped++;
                    $errors[] = [
                        'row'    => $rowNum,
                        'name'   => $name,
                        'email'  => $rawEmail,
                        'reason' => 'Email duplikat di dalam file yang diunggah.',
                    ];
                    continue;
                }

                $code = !empty($row['employee_code']) ? trim($row['employee_code']) : null;
                if ($code !== null) {
                    $lowerCode = strtolower($code);
                    if (isset($existingCodes[$lowerCode])) {
                        $skipped++;
                        $errors[] = [
                            'row'    => $rowNum,
                            'name'   => $name,
                            'email'  => $rawEmail,
                            'reason' => "NIK / Kode Karyawan '{$code}' sudah digunakan.",
                        ];
                        continue;
                    }
                    if (isset($seenCodesInBatch[$lowerCode])) {
                        $skipped++;
                        $errors[] = [
                            'row'    => $rowNum,
                            'name'   => $name,
                            'email'  => $rawEmail,
                            'reason' => "NIK / Kode Karyawan '{$code}' duplikat di dalam file.",
                        ];
                        continue;
                    }
                    $seenCodesInBatch[$lowerCode] = true;
                }

                // Normalisasi role
                $role = !empty($row['role']) ? strtolower(trim($row['role'])) : $defaultRole;
                if (!in_array($role, ['employee', 'finance', 'hrd', 'admin', 'super_admin'])) {
                    $role = $defaultRole;
                }
                if ($role === 'super_admin' && $actor->role !== 'super_admin') {
                    $role = 'employee';
                }

                // Normalisasi kantor
                $officeId = !empty($row['attendance_setting_id']) && isset($validOfficeIds[$row['attendance_setting_id']])
                    ? (int) $row['attendance_setting_id']
                    : $defaultOfficeId;

                // Normalisasi employment type
                $employmentType = !empty($row['employment_type']) && in_array(trim($row['employment_type']), ['PKWTT', 'PKWT', 'Probation', 'Internship'])
                    ? trim($row['employment_type'])
                    : $defaultEmploymentType;

                // Normalisasi nomor telepon (hanya digit dan +)
                $phone = !empty($row['phone']) ? preg_replace('/[^0-9+]/', '', trim($row['phone'])) : null;
                if ($phone && strlen($phone) > 15) {
                    $phone = substr($phone, 0, 15);
                }

                // Normalisasi NIK KTP (identity_number)
                $identityNumber = !empty($row['identity_number']) ? preg_replace('/[^0-9]/', '', trim($row['identity_number'])) : null;
                if ($identityNumber && strlen($identityNumber) !== 16) {
                    $identityNumber = null;
                }

                // Normalisasi tanggal
                $parseDate = function (?string $d) {
                    if (empty($d)) return null;
                    $ts = strtotime($d);
                    return $ts ? date('Y-m-d', $ts) : null;
                };

                $joinedDate = $parseDate($row['joined_date'] ?? null);
                $contractStart = $parseDate($row['contract_start_date'] ?? null);
                $contractEnd = $parseDate($row['contract_end_date'] ?? null);
                $birthDate = $parseDate($row['birth_date'] ?? null);

                $rawGender = !empty($row['gender']) ? strtolower(trim($row['gender'])) : null;
                $gender = null;
                if ($rawGender) {
                    $gender = in_array($rawGender, ['perempuan', 'female', 'p', 'wanita']) ? 'Perempuan' : 'Laki-laki';
                }
                $birthPlace = !empty($row['birth_place']) ? trim($row['birth_place']) : null;

                // Buat user baru
                $user = User::create([
                    'company_id'            => $companyId,
                    'employee_code'         => $code,
                    'name'                  => $name,
                    'email'                 => $rawEmail,
                    'password'              => $defaultPasswordHash,
                    'role'                  => $role,
                    'department'            => !empty($row['department']) ? trim($row['department']) : null,
                    'identity_number'       => $identityNumber,
                    'phone'                 => $phone,
                    'gender'                => $gender,
                    'birth_place'           => $birthPlace,
                    'birth_date'            => $birthDate,
                    'is_pregnant'           => false,
                    'attendance_setting_id' => $officeId,
                    'monthly_claim_limit'   => isset($row['monthly_claim_limit']) && is_numeric($row['monthly_claim_limit']) ? (float) $row['monthly_claim_limit'] : null,
                    'is_active'             => true,
                    'attendance_enabled'    => $defaultAttendanceEnabled,
                    'wfh_enabled'           => $defaultWfhEnabled,
                    'radius_enabled'        => $defaultRadiusEnabled,
                    'employment_type'       => $employmentType,
                    'joined_date'           => $joinedDate,
                    'contract_start_date'   => $contractStart,
                    'contract_end_date'     => $contractEnd,
                    'bank_name'             => !empty($row['bank_name']) ? trim($row['bank_name']) : null,
                    'bank_account_no'       => !empty($row['bank_account_no']) ? trim($row['bank_account_no']) : null,
                    'bank_account_holder'   => !empty($row['bank_account_holder']) ? trim($row['bank_account_holder']) : $name,
                ]);

                // Inisialisasi saldo cuti jika disediakan
                $initialLeave = isset($row['leave_balance']) && is_numeric($row['leave_balance'])
                    ? (int) $row['leave_balance']
                    : 12;

                $currentYear = (int) date('Y');
                LeaveBalance::firstOrCreate(
                    ['user_id' => $user->id, 'leave_type' => 'cuti', 'year' => $currentYear],
                    ['quota' => $initialLeave, 'used' => 0]
                );
                LeaveBalance::firstOrCreate(
                    ['user_id' => $user->id, 'leave_type' => 'izin', 'year' => $currentYear],
                    ['quota' => 12, 'used' => 0]
                );

                $seenEmailsInBatch[$rawEmail] = true;
                $existingEmails[$rawEmail] = true;
                if ($code !== null) {
                    $existingCodes[strtolower($code)] = true;
                }

                $imported++;
                if (count($importedUsers) < 10) {
                    $importedUsers[] = [
                        'id'            => $user->id,
                        'name'          => $user->name,
                        'email'         => $user->email,
                        'employee_code' => $user->employee_code,
                        'role'          => $user->role,
                        'department'    => $user->department,
                    ];
                }
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Gagal memproses impor massal: ' . $e->getMessage(),
            ], 500);
        }

        AuditLogger::log(
            action: 'BULK_EMPLOYEE_IMPORT',
            description: "Impor massal karyawan oleh {$actor->name}: {$imported} berhasil, {$skipped} dilewati dari total " . count($validated['users']) . ' baris.',
            category: AuditLogger::CATEGORY_HR,
            severity: AuditLogger::SEVERITY_WARNING,
            entityType: 'User',
            entityId: $actor->id,
            newValues: [
                'total_rows' => count($validated['users']),
                'imported'   => $imported,
                'skipped'    => $skipped,
            ]
        );

        return response()->json([
            'message'        => "Impor data selesai: {$imported} karyawan berhasil ditambahkan, {$skipped} dilewati.",
            'total'          => count($validated['users']),
            'imported'       => $imported,
            'skipped'        => $skipped,
            'errors'         => $errors,
            'imported_users' => $importedUsers,
        ], 200);
    }
}
