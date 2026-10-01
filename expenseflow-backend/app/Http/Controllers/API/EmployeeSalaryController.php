<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\CurrencyRate;
use App\Models\EmployeeBpjsProfile;
use App\Models\EmployeeSalary;
use App\Models\EmployeeSalaryComponent;
use App\Models\EmployeeTaxProfile;
use App\Models\Role;
use App\Models\SalaryComponent;
use App\Models\SalaryGrade;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Pengaturan gaji karyawan: gaji pokok efektif (riwayat), tunjangan/potongan
 * tetap, dan profil pajak (PTKP, NPWP terenkripsi). NPWP selalu dikirim
 * termasking ke response. Butuh izin modul Payroll ('read'/'manage').
 */
class EmployeeSalaryController extends Controller
{
    private const PTKP_STATUSES = ['TK/0', 'TK/1', 'TK/2', 'TK/3', 'K/0', 'K/1', 'K/2', 'K/3'];

    private function deny(): JsonResponse
    {
        return response()->json(['message' => 'Akses ditolak. Anda tidak memiliki wewenang mengelola gaji karyawan.'], 403);
    }

    /** Ambil user dalam perusahaan yang sama, atau null. */
    private function resolveUser(Request $request, int $userId): ?User
    {
        return User::where('id', $userId)
            ->where('company_id', $request->user()->company_id)
            ->first();
    }

    /**
     * GET /dashboard/payroll/salaries
     * Daftar karyawan + ringkasan gaji pokok aktif & status PTKP (untuk tabel UI).
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPermission(Role::MODULE_PAYROLL, 'read')) {
            return $this->deny();
        }

        $today = now()->toDateString();

        $employees = User::query()
            ->where('company_id', $user->company_id)
            ->where('is_active', true)
            ->with([
                'position:id,name',
                'taxProfile:id,user_id,ptkp_status,has_npwp,tax_method,tax_subject_type',
                'employeeSalaries' => fn ($q) => $q->where('is_active', true)
                    ->whereDate('effective_date', '<=', $today)
                    ->orderByDesc('effective_date'),
            ])
            ->orderBy('name')
            ->get(['id', 'name', 'employee_code', 'department', 'position_id', 'attendance_setting_id']);

        $data = $employees->map(function (User $emp) {
            $salary = $emp->employeeSalaries->first();

            return [
                'id'               => $emp->id,
                'name'             => $emp->name,
                'employee_code'    => $emp->employee_code,
                'department'       => $emp->department,
                'position'         => $emp->position?->name,
                'basic_salary'     => $salary?->basic_salary,
                'currency'         => $salary?->currency ?? 'IDR',
                'ptkp_status'      => $emp->taxProfile?->ptkp_status,
                'has_npwp'         => (bool) ($emp->taxProfile?->has_npwp ?? false),
                'tax_method'       => $emp->taxProfile?->tax_method ?? 'gross',
                'tax_subject_type' => $emp->taxProfile?->tax_subject_type ?? 'domestic',
            ];
        });

        return response()->json(['data' => $data]);
    }

    /**
     * GET /dashboard/payroll/salaries/{userId}
     * Detail gaji: gaji pokok aktif, riwayat, komponen tetap, profil pajak.
     */
    public function show(Request $request, int $userId): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPermission(Role::MODULE_PAYROLL, 'read')) {
            return $this->deny();
        }

        $employee = $this->resolveUser($request, $userId);
        if (! $employee) {
            return response()->json(['message' => 'Karyawan tidak ditemukan.'], 404);
        }

        $salaries = EmployeeSalary::where('user_id', $employee->id)
            ->with(['salaryGrade:id,name,code,min_salary,max_salary,currency', 'jobLevel:id,name,code'])
            ->orderByDesc('effective_date')
            ->get();

        $components = EmployeeSalaryComponent::with('component:id,name,code,type,is_taxable')
            ->where('user_id', $employee->id)
            ->orderByDesc('is_active')
            ->orderByDesc('effective_date')
            ->get();

        $profile = EmployeeTaxProfile::where('user_id', $employee->id)->first();
        $bpjs = EmployeeBpjsProfile::where('user_id', $employee->id)->first();

        return response()->json([
            'data' => [
                'employee' => [
                    'id'            => $employee->id,
                    'name'          => $employee->name,
                    'employee_code' => $employee->employee_code,
                    'department'    => $employee->department,
                    'bank_name'     => $employee->bank_name,
                    'bank_account_no' => $employee->bank_account_no,
                ],
                'active_salary' => $employee->activeSalaryOn(now()->toDateString()),
                'salaries'      => $salaries,
                'components'    => $components,
                'tax_profile'   => $profile ? [
                    'ptkp_status' => $profile->ptkp_status,
                    'has_npwp'    => $profile->has_npwp,
                    'npwp_masked' => $profile->maskedNpwp(),
                    'tax_method'  => $profile->tax_method,
                    // Subjek pajak (Fase 6): 'foreign' ⇒ dipotong PPh 26, bukan PPh 21.
                    'tax_subject_type' => $profile->tax_subject_type,
                    'treaty_country'   => $profile->treaty_country,
                    'treaty_rate'      => $profile->treaty_rate,
                    'foreign_tax_id'   => $profile->foreign_tax_id,
                ] : null,
                // Profil BPJS (nomor kepesertaan selalu termasking). null = belum diatur.
                'bpjs_profile'  => $bpjs ? [
                    'has_bpjs_kes'       => $bpjs->has_bpjs_kes,
                    'has_bpjs_tk'        => $bpjs->has_bpjs_tk,
                    'has_jkp'            => $bpjs->has_jkp,
                    'jkk_risk_class'     => $bpjs->jkkRiskClass(),
                    'bpjs_kes_no_masked' => $bpjs->maskedBpjsKesNo(),
                    'bpjs_tk_no_masked'  => $bpjs->maskedBpjsTkNo(),
                ] : null,
            ],
        ]);
    }

    /**
     * POST /dashboard/payroll/salaries/{userId}
     * Set gaji pokok baru (effective-dated). Menutup gaji aktif sebelumnya.
     */
    public function storeSalary(Request $request, int $userId): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPermission(Role::MODULE_PAYROLL, 'manage')) {
            return $this->deny();
        }

        $employee = $this->resolveUser($request, $userId);
        if (! $employee) {
            return response()->json(['message' => 'Karyawan tidak ditemukan.'], 404);
        }

        $companyId = (int) $employee->company_id;

        $validated = $request->validate([
            'basic_salary'   => 'required|numeric|min:0|max:999999999999',
            'effective_date' => 'required|date',
            'notes'          => 'nullable|string|max:500',
            // Struktur & Skala Upah (Fase 6) — opsional, tetapi bila diisi wajib
            // milik perusahaan yang sama dan nominalnya harus di dalam rentang.
            'salary_grade_id' => [
                'nullable', 'integer',
                Rule::exists('salary_grades', 'id')->where(fn ($q) => $q->where('company_id', $companyId)),
            ],
            'job_level_id' => [
                'nullable', 'integer',
                Rule::exists('job_levels', 'id')->where(fn ($q) => $q->where('company_id', $companyId)),
            ],
            // Mata uang kontrak (Fase 6). Non-IDR wajib punya kurs berlaku, agar
            // batch tidak gagal belakangan saat kalkulasi.
            'currency' => 'nullable|string|size:3|alpha',
        ]);

        $effectiveDate = $validated['effective_date'];
        $basic = (float) $validated['basic_salary'];
        $currency = strtoupper($validated['currency'] ?? CurrencyRate::BASE_CURRENCY);

        // Rambu rentang golongan (Permenaker 1/2017): nominal di luar min–maks ditolak.
        $gradeId = $validated['salary_grade_id'] ?? null;
        $jobLevelId = $validated['job_level_id'] ?? null;
        if ($gradeId !== null) {
            $grade = SalaryGrade::where('company_id', $companyId)->find((int) $gradeId);
            if ($grade && ! $grade->contains($basic)) {
                return response()->json([
                    'message' => sprintf(
                        'Gaji pokok di luar rentang golongan %s (%s – %s).',
                        $grade->name,
                        number_format((float) $grade->min_salary, 2, ',', '.'),
                        number_format((float) $grade->max_salary, 2, ',', '.'),
                    ),
                    'errors' => ['basic_salary' => ['Nominal di luar rentang golongan upah.']],
                ], 422);
            }
            // Jenjang mengikuti golongan bila tidak diisi eksplisit.
            $jobLevelId ??= $grade?->job_level_id;
        }

        // Kurs wajib tersedia untuk mata uang non-Rupiah — jangan pernah asumsi 1:1.
        if ($currency !== CurrencyRate::BASE_CURRENCY
            && CurrencyRate::resolve($currency, $effectiveDate, $companyId) === null) {
            return response()->json([
                'message' => "Kurs {$currency} → IDR pada {$effectiveDate} belum tersedia. Isi master kurs terlebih dahulu.",
                'errors'  => ['currency' => ['Kurs belum tersedia.']],
            ], 422);
        }

        $salary = DB::transaction(function () use ($employee, $user, $validated, $effectiveDate, $currency, $gradeId, $jobLevelId) {
            // Tutup gaji aktif sebelumnya sehari sebelum tanggal berlaku baru.
            EmployeeSalary::where('user_id', $employee->id)
                ->where('is_active', true)
                ->whereDate('effective_date', '<', $effectiveDate)
                ->update([
                    'is_active' => false,
                    'end_date'  => \Carbon\Carbon::parse($effectiveDate)->subDay()->toDateString(),
                ]);

            // Hindari duplikat tanggal berlaku sama.
            EmployeeSalary::where('user_id', $employee->id)
                ->whereDate('effective_date', $effectiveDate)
                ->update(['is_active' => false]);

            return EmployeeSalary::create([
                'company_id'      => $employee->company_id,
                'user_id'         => $employee->id,
                'salary_grade_id' => $gradeId !== null ? (int) $gradeId : null,
                'job_level_id'    => $jobLevelId !== null ? (int) $jobLevelId : null,
                'basic_salary'    => $validated['basic_salary'],
                'currency'        => $currency,
                'effective_date'  => $effectiveDate,
                'end_date'        => null,
                'is_active'       => true,
                'notes'           => $validated['notes'] ?? null,
                'created_by'      => $user->id,
            ]);
        });

        AuditLogger::log(
            action: 'SALARY_SET',
            description: "Menetapkan gaji pokok {$salary->basic_salary} untuk {$employee->name} (berlaku {$effectiveDate})",
            category: AuditLogger::CATEGORY_FINANCE,
            severity: AuditLogger::SEVERITY_INFO,
            entityType: 'EmployeeSalary',
            entityId: $salary->id,
            newValues: [
                'basic_salary'    => $salary->basic_salary,
                'currency'        => $salary->currency,
                'salary_grade_id' => $salary->salary_grade_id,
                'job_level_id'    => $salary->job_level_id,
                'effective_date'  => $effectiveDate,
            ],
        );

        return response()->json(['message' => 'Gaji pokok berhasil disimpan.', 'data' => $salary], 201);
    }

    /**
     * POST /dashboard/payroll/salaries/{userId}/components
     * Tambah tunjangan/potongan tetap ke karyawan.
     */
    public function storeComponent(Request $request, int $userId): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPermission(Role::MODULE_PAYROLL, 'manage')) {
            return $this->deny();
        }

        $employee = $this->resolveUser($request, $userId);
        if (! $employee) {
            return response()->json(['message' => 'Karyawan tidak ditemukan.'], 404);
        }

        $companyId = (int) $user->company_id;

        $validated = $request->validate([
            'salary_component_id' => [
                'required', 'integer',
                Rule::exists('salary_components', 'id')->where(fn ($q) => $q->where('company_id', $companyId)->where('is_active', true)),
            ],
            'amount'         => 'required|numeric|min:0|max:999999999999',
            'effective_date' => 'required|date',
        ]);

        $component = EmployeeSalaryComponent::create([
            'company_id'          => $companyId,
            'user_id'             => $employee->id,
            'salary_component_id' => (int) $validated['salary_component_id'],
            'amount'              => $validated['amount'],
            'effective_date'      => $validated['effective_date'],
            'end_date'            => null,
            'is_active'           => true,
        ]);

        AuditLogger::log(
            action: 'SALARY_COMPONENT_ASSIGNED',
            description: "Menambah komponen tetap #{$component->salary_component_id} ke {$employee->name}",
            category: AuditLogger::CATEGORY_FINANCE,
            severity: AuditLogger::SEVERITY_INFO,
            entityType: 'EmployeeSalaryComponent',
            entityId: $component->id,
            newValues: $component->toArray(),
        );

        return response()->json([
            'message' => 'Komponen tetap berhasil ditambahkan.',
            'data'    => $component->load('component:id,name,code,type,is_taxable'),
        ], 201);
    }

    /**
     * DELETE /dashboard/payroll/salary-components/{component}
     * Nonaktifkan komponen tetap karyawan (soft: is_active=false, set end_date).
     */
    public function destroyComponent(Request $request, EmployeeSalaryComponent $component): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPermission(Role::MODULE_PAYROLL, 'manage')) {
            return $this->deny();
        }
        if ((int) $component->company_id !== (int) $user->company_id) {
            return response()->json(['message' => 'Komponen tidak ditemukan.'], 404);
        }

        $component->update([
            'is_active' => false,
            'end_date'  => now()->toDateString(),
        ]);

        AuditLogger::log(
            action: 'SALARY_COMPONENT_REMOVED',
            description: "Menonaktifkan komponen tetap #{$component->id}",
            category: AuditLogger::CATEGORY_FINANCE,
            severity: AuditLogger::SEVERITY_INFO,
            entityType: 'EmployeeSalaryComponent',
            entityId: $component->id,
        );

        return response()->json(['message' => 'Komponen tetap dinonaktifkan.']);
    }

    /**
     * PUT /dashboard/payroll/salaries/{userId}/tax-profile
     * Upsert profil pajak. NPWP disimpan terenkripsi & dikirim balik termasking.
     */
    public function saveTaxProfile(Request $request, int $userId): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPermission(Role::MODULE_PAYROLL, 'manage')) {
            return $this->deny();
        }

        $employee = $this->resolveUser($request, $userId);
        if (! $employee) {
            return response()->json(['message' => 'Karyawan tidak ditemukan.'], 404);
        }

        $validated = $request->validate([
            'ptkp_status' => ['required', Rule::in(self::PTKP_STATUSES)],
            'tax_method'  => ['nullable', Rule::in(['gross', 'gross_up'])],
            // NPWP opsional. Kosongkan (null) untuk tidak mengubah; string kosong "" untuk menghapus.
            'npwp'        => 'nullable|string|max:30',
            // ── Subjek pajak & ekspatriat (Fase 6) ──
            // 'foreign' ⇒ pemotongan PPh 26 (20% bruto, atau tarif P3B bila negara mitra diisi).
            'tax_subject_type' => ['nullable', Rule::in(EmployeeTaxProfile::SUBJECT_TYPES)],
            'treaty_country'   => 'nullable|string|size:2|alpha',
            'treaty_rate'      => 'nullable|numeric|min:0|max:0.4',
            'foreign_tax_id'   => 'nullable|string|max:40',
        ]);

        $profile = EmployeeTaxProfile::firstOrNew(['user_id' => $employee->id]);
        $profile->company_id  = $employee->company_id;
        $profile->ptkp_status = $validated['ptkp_status'];
        $profile->tax_method  = $validated['tax_method'] ?? ($profile->tax_method ?? 'gross');

        // Subjek pajak & atribut P3B — hanya diubah bila field dikirim, agar penyimpanan
        // profil domestik lama tidak diam-diam menghapus data ekspatriat.
        if ($request->has('tax_subject_type')) {
            $profile->tax_subject_type = $validated['tax_subject_type'] ?? EmployeeTaxProfile::SUBJECT_DOMESTIC;
        }
        if ($request->has('treaty_country')) {
            $c = strtoupper(trim((string) ($validated['treaty_country'] ?? '')));
            $profile->treaty_country = $c !== '' ? $c : null;
        }
        if ($request->has('treaty_rate')) {
            $profile->treaty_rate = $validated['treaty_rate'] !== null ? round((float) $validated['treaty_rate'], 4) : null;
        }
        if ($request->has('foreign_tax_id')) {
            $tid = trim((string) ($validated['foreign_tax_id'] ?? ''));
            $profile->foreign_tax_id = $tid !== '' ? $tid : null;
        }

        // Tarif P3B TANPA negara mitra tidak dapat diverifikasi (SKD/DGT) — tolak di muka
        // agar tidak ada tarif istimewa yang "menggantung" tanpa dasar dokumen.
        if ($profile->treaty_rate !== null && ($profile->treaty_country === null || $profile->treaty_country === '')) {
            return response()->json([
                'message' => 'Tarif P3B wajib disertai negara mitra (dasar SKD/DGT).',
                'errors'  => ['treaty_country' => ['Negara mitra P3B wajib diisi bila tarif P3B diisi.']],
            ], 422);
        }

        // Update NPWP hanya jika field dikirim (present di request).
        if ($request->has('npwp')) {
            $raw = (string) ($validated['npwp'] ?? '');
            $digits = preg_replace('/\D/', '', $raw);

            if ($digits === '') {
                $profile->npwp = null;
                $profile->has_npwp = false;
            } else {
                if (strlen($digits) < 15 || strlen($digits) > 16) {
                    return response()->json([
                        'message' => 'NPWP tidak valid. Masukkan 15 digit (lama) atau 16 digit (baru).',
                        'errors'  => ['npwp' => ['Panjang NPWP harus 15 atau 16 digit.']],
                    ], 422);
                }
                $profile->npwp = $digits;
                $profile->has_npwp = true;
            }
        }

        $profile->save();

        AuditLogger::log(
            action: 'TAX_PROFILE_SAVED',
            description: "Menyimpan profil pajak {$employee->name} (PTKP {$profile->ptkp_status})",
            category: AuditLogger::CATEGORY_FINANCE,
            severity: AuditLogger::SEVERITY_INFO,
            entityType: 'EmployeeTaxProfile',
            entityId: $profile->id,
            newValues: [
                'ptkp_status'      => $profile->ptkp_status,
                'has_npwp'         => $profile->has_npwp,
                'tax_method'       => $profile->tax_method,
                'tax_subject_type' => $profile->tax_subject_type,
                'treaty_country'   => $profile->treaty_country,
                'treaty_rate'      => $profile->treaty_rate,
            ],
        );

        return response()->json([
            'message' => 'Profil pajak berhasil disimpan.',
            'data'    => [
                'ptkp_status' => $profile->ptkp_status,
                'has_npwp'    => $profile->has_npwp,
                'npwp_masked' => $profile->maskedNpwp(),
                'tax_method'  => $profile->tax_method,
                'tax_subject_type' => $profile->tax_subject_type,
                'treaty_country'   => $profile->treaty_country,
                'treaty_rate'      => $profile->treaty_rate,
                'foreign_tax_id'   => $profile->foreign_tax_id,
            ],
        ]);
    }

    /**
     * PUT /dashboard/payroll/salaries/{userId}/bpjs-profile
     * Upsert profil kepesertaan BPJS. Nomor kepesertaan disimpan terenkripsi &
     * dikirim balik termasking. Flag has_bpjs_* mengaktifkan perhitungan iuran
     * saat kalkulasi payroll (lihat BpjsCalculatorService). jkk_risk_class 1..5.
     */
    public function saveBpjsProfile(Request $request, int $userId): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPermission(Role::MODULE_PAYROLL, 'manage')) {
            return $this->deny();
        }

        $employee = $this->resolveUser($request, $userId);
        if (! $employee) {
            return response()->json(['message' => 'Karyawan tidak ditemukan.'], 404);
        }

        $validated = $request->validate([
            'has_bpjs_kes'   => 'required|boolean',
            'has_bpjs_tk'    => 'required|boolean',
            'has_jkp'        => 'nullable|boolean',
            'jkk_risk_class' => ['nullable', 'integer', Rule::in([1, 2, 3, 4, 5])],
            // Nomor kepesertaan opsional. Kosongkan (null/absen) = tidak mengubah;
            // string kosong "" = menghapus nomor tersimpan.
            'bpjs_kes_no'    => 'nullable|string|max:30',
            'bpjs_tk_no'     => 'nullable|string|max:30',
        ]);

        $profile = EmployeeBpjsProfile::firstOrNew(['user_id' => $employee->id]);
        $profile->company_id     = $employee->company_id;
        $profile->has_bpjs_kes   = (bool) $validated['has_bpjs_kes'];
        $profile->has_bpjs_tk    = (bool) $validated['has_bpjs_tk'];
        $profile->has_jkp        = (bool) ($validated['has_jkp'] ?? $profile->has_jkp ?? false);
        $profile->jkk_risk_class = (int) ($validated['jkk_risk_class'] ?? $profile->jkk_risk_class ?? 1);

        // Update nomor kepesertaan hanya bila field dikirim (present di request).
        foreach (['bpjs_kes_no', 'bpjs_tk_no'] as $field) {
            if ($request->has($field)) {
                $digits = preg_replace('/\D/', '', (string) ($validated[$field] ?? ''));
                $profile->{$field} = $digits === '' ? null : $digits;
            }
        }

        $profile->save();

        AuditLogger::log(
            action: 'BPJS_PROFILE_SAVED',
            description: "Menyimpan profil BPJS {$employee->name} (Kes: " . ($profile->has_bpjs_kes ? 'ya' : 'tidak') . ", TK: " . ($profile->has_bpjs_tk ? 'ya' : 'tidak') . ", kelas JKK {$profile->jkkRiskClass()})",
            category: AuditLogger::CATEGORY_FINANCE,
            severity: AuditLogger::SEVERITY_INFO,
            entityType: 'EmployeeBpjsProfile',
            entityId: $profile->id,
            newValues: [
                'has_bpjs_kes'   => $profile->has_bpjs_kes,
                'has_bpjs_tk'    => $profile->has_bpjs_tk,
                'has_jkp'        => $profile->has_jkp,
                'jkk_risk_class' => $profile->jkkRiskClass(),
            ],
        );

        return response()->json([
            'message' => 'Profil BPJS berhasil disimpan.',
            'data'    => [
                'has_bpjs_kes'       => $profile->has_bpjs_kes,
                'has_bpjs_tk'        => $profile->has_bpjs_tk,
                'has_jkp'            => $profile->has_jkp,
                'jkk_risk_class'     => $profile->jkkRiskClass(),
                'bpjs_kes_no_masked' => $profile->maskedBpjsKesNo(),
                'bpjs_tk_no_masked'  => $profile->maskedBpjsTkNo(),
            ],
        ]);
    }
}
