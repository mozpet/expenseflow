<?php

namespace Tests\Feature;

use App\Models\AttendanceSetting;
use App\Models\Company;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceHistory;
use App\Models\LeaveRequest;
use App\Models\LeaveTypeSetting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LeaveTypeExpansionTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private AttendanceSetting $office;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->company = Company::create([
            'name'      => 'PT Nusantara Cuti Bersama',
            'email'     => 'admin@nusantara.com',
            'is_active' => true,
        ]);

        $this->office = AttendanceSetting::create([
            'company_id'                   => $this->company->id,
            'office_name'                  => 'Kantor Pusat Jakarta',
            'office_latitude'              => -6.2088,
            'office_longitude'             => 106.8456,
            'work_start_time'              => '08:00:00',
            'work_end_time'                => '17:00:00',
            'default_leave_quota'          => 12,
            'leave_multi_approval_enabled' => false, // direct HRD approval for faster tests
            'leave_reset_date'             => Carbon::today('Asia/Jakarta')->format('m-d'),
        ]);

        // Trigger default leave type settings seeding for this office
        $controller = new \App\Http\Controllers\API\AttendanceController(
            app(\App\Services\FcmService::class)
        );
        $controller->seedDefaultLeaveTypeSettings($this->office->id);
    }

    private function makeUser(string $role, string $gender = 'Laki-laki', array $extra = []): User
    {
        static $counter = 1;
        $counter++;

        return User::create(array_merge([
            'company_id'            => $this->company->id,
            'name'                  => "User {$counter}",
            'email'                 => "user{$counter}@nusantara.com",
            'password'              => bcrypt('secret123'),
            'role'                  => $role,
            'gender'                => $gender,
            'attendance_setting_id' => $this->office->id,
            'attendance_enabled'    => true,
            'is_active'             => true,
        ], $extra));
    }

    public function test_list_leave_type_settings_returns_catalog_merged_with_office_settings(): void
    {
        $hrd = $this->makeUser('hrd');

        $res = $this->actingAs($hrd, 'sanctum')->getJson("/api/v1/dashboard/attendance/leave-types?attendance_setting_id={$this->office->id}");

        $res->assertStatus(200);
        $res->assertJsonStructure([
            'attendance_setting_id',
            'office_name',
            'leave_types' => [
                '*' => [
                    'leave_type',
                    'label',
                    'is_enabled',
                    'quota_days',
                    'requires_document',
                    'gender_restriction',
                    'legal_basis',
                    'description',
                ],
            ],
        ]);

        $types = collect($res->json('leave_types'))->keyBy('leave_type');

        // Cuti hamil must be enabled by default with 90 days quota
        $this->assertTrue($types->has('cuti_hamil'));
        $this->assertTrue($types['cuti_hamil']['is_enabled']);
        $this->assertEquals(90, $types['cuti_hamil']['quota_days']);
        $this->assertEquals('Perempuan', $types['cuti_hamil']['gender_restriction']);

        // Cuti sakit must be in catalog, enabled by default with requires_document=true
        $this->assertTrue($types->has('sakit'));
        $this->assertTrue($types['sakit']['is_enabled']);
        $this->assertTrue($types['sakit']['requires_document']);
        $this->assertEquals(14, $types['sakit']['quota_days']);

        // Cuti setengah hari must be disabled by default (opt-in) with 10 units quota
        $this->assertTrue($types->has('cuti_setengah_hari'));
        $this->assertFalse($types['cuti_setengah_hari']['is_enabled']);
        $this->assertEquals(10, $types['cuti_setengah_hari']['quota_days']);
    }

    public function test_update_leave_type_settings_allows_toggling_and_custom_quota(): void
    {
        $hrd = $this->makeUser('hrd');

        $payload = [
            'attendance_setting_id' => $this->office->id,
            'settings' => [
                [
                    'leave_type'        => 'cuti_setengah_hari',
                    'is_enabled'        => true,
                    'quota_days'        => 15,
                    'requires_document' => false,
                ],
                [
                    'leave_type'        => 'cuti_hamil',
                    'is_enabled'        => true,
                    'quota_days'        => 98,
                    'requires_document' => true,
                ],
                [
                    'leave_type'        => 'sakit',
                    'is_enabled'        => true,
                    'quota_days'        => 30,
                    'requires_document' => false,
                    'notes'             => 'SOP Cabang: Sakit > 2 hari wajib surat dokter',
                ],
            ],
        ];

        $res = $this->actingAs($hrd, 'sanctum')->putJson('/api/v1/dashboard/attendance/leave-types', $payload);

        $res->assertStatus(200);

        $this->assertDatabaseHas('leave_type_settings', [
            'attendance_setting_id' => $this->office->id,
            'leave_type'            => 'cuti_setengah_hari',
            'is_enabled'            => true,
            'quota_days'            => 15,
        ]);

        $this->assertDatabaseHas('leave_type_settings', [
            'attendance_setting_id' => $this->office->id,
            'leave_type'            => 'cuti_hamil',
            'is_enabled'            => true,
            'quota_days'            => 98,
        ]);

        $this->assertDatabaseHas('leave_type_settings', [
            'attendance_setting_id' => $this->office->id,
            'leave_type'            => 'sakit',
            'is_enabled'            => true,
            'quota_days'            => 30,
            'requires_document'     => false,
            'notes'                 => 'SOP Cabang: Sakit > 2 hari wajib surat dokter',
        ]);
    }

    public function test_non_hrd_cannot_manage_leave_type_settings(): void
    {
        $employee = $this->makeUser('employee');

        $resGet = $this->actingAs($employee, 'sanctum')->getJson("/api/v1/dashboard/attendance/leave-types?attendance_setting_id={$this->office->id}");
        $resGet->assertStatus(403);

        $resPut = $this->actingAs($employee, 'sanctum')->putJson('/api/v1/dashboard/attendance/leave-types', [
            'attendance_setting_id' => $this->office->id,
            'settings' => [],
        ]);
        $resPut->assertStatus(403);
    }

    public function test_female_employee_can_request_cuti_hamil_with_document_and_autoprovisions_quota(): void
    {
        $femaleEmp = $this->makeUser('employee', 'Perempuan', [
            'marital_status' => 'married',
            'is_pregnant'    => true,
        ]);

        $start = Carbon::now('Asia/Jakarta')->addDays(2)->toDateString();
        $end   = Carbon::now('Asia/Jakarta')->addDays(10)->toDateString();

        $file = UploadedFile::fake()->create('surat_dokter.pdf', 300, 'application/pdf');

        $res = $this->actingAs($femaleEmp, 'sanctum')->postJson('/api/v1/attendance/leave-request', [
            'leave_type' => 'cuti_hamil',
            'start_date' => $start,
            'end_date'   => $end,
            'reason'     => 'Persiapan persalinan anak pertama',
            'document'   => $file,
        ]);

        $res->assertStatus(201);
        $res->assertJsonFragment(['leave_type' => 'cuti_hamil']);

        // Check balance auto-provisioned with office quota (90)
        $this->assertDatabaseHas('leave_balances', [
            'user_id'    => $femaleEmp->id,
            'leave_type' => 'cuti_hamil',
            'quota'      => 90,
            'used'       => 0,
        ]);
    }

    public function test_cuti_hamil_requires_document(): void
    {
        $femaleEmp = $this->makeUser('employee', 'Perempuan', [
            'marital_status' => 'married',
            'is_pregnant'    => true,
        ]);

        $start = Carbon::now('Asia/Jakarta')->addDays(2)->toDateString();
        $end   = Carbon::now('Asia/Jakarta')->addDays(5)->toDateString();

        $res = $this->actingAs($femaleEmp, 'sanctum')->postJson('/api/v1/attendance/leave-request', [
            'leave_type' => 'cuti_hamil',
            'start_date' => $start,
            'end_date'   => $end,
            'reason'     => 'Cuti hamil tanpa lampiran',
        ]);

        $res->assertStatus(422);
        $res->assertJsonValidationErrors(['document']);
    }

    public function test_unmarried_female_cannot_request_cuti_hamil(): void
    {
        $femaleEmp = $this->makeUser('employee', 'Perempuan', [
            'marital_status' => 'single',
            'is_pregnant'    => true,
        ]);

        $start = Carbon::now('Asia/Jakarta')->addDays(2)->toDateString();
        $end   = Carbon::now('Asia/Jakarta')->addDays(5)->toDateString();
        $file  = UploadedFile::fake()->create('surat_dokter.pdf', 200, 'application/pdf');

        $res = $this->actingAs($femaleEmp, 'sanctum')->postJson('/api/v1/attendance/leave-request', [
            'leave_type' => 'cuti_hamil',
            'start_date' => $start,
            'end_date'   => $end,
            'reason'     => 'Cuti hamil tapi status lajang',
            'document'   => $file,
        ]);

        $res->assertStatus(422);
        $this->assertStringContainsString('hanya diperuntukkan bagi karyawan yang sudah berstatus menikah', $res->json('message'));
    }

    public function test_non_pregnant_female_cannot_request_cuti_hamil(): void
    {
        $femaleEmp = $this->makeUser('employee', 'Perempuan', [
            'marital_status' => 'married',
            'is_pregnant'    => false,
        ]);

        $start = Carbon::now('Asia/Jakarta')->addDays(2)->toDateString();
        $end   = Carbon::now('Asia/Jakarta')->addDays(5)->toDateString();
        $file  = UploadedFile::fake()->create('surat_dokter.pdf', 200, 'application/pdf');

        $res = $this->actingAs($femaleEmp, 'sanctum')->postJson('/api/v1/attendance/leave-request', [
            'leave_type' => 'cuti_hamil',
            'start_date' => $start,
            'end_date'   => $end,
            'reason'     => 'Cuti hamil tapi tidak sedang hamil di data karyawan',
            'document'   => $file,
        ]);

        $res->assertStatus(422);
        $this->assertStringContainsString('hanya diperuntukkan bagi karyawati yang tercatat berstatus sedang hamil', $res->json('message'));
    }

    public function test_male_employee_cannot_request_cuti_hamil(): void
    {
        $maleEmp = $this->makeUser('employee', 'Laki-laki');

        $start = Carbon::now('Asia/Jakarta')->addDays(2)->toDateString();
        $end   = Carbon::now('Asia/Jakarta')->addDays(5)->toDateString();

        $file = UploadedFile::fake()->create('dokumen.pdf', 200, 'application/pdf');

        $res = $this->actingAs($maleEmp, 'sanctum')->postJson('/api/v1/attendance/leave-request', [
            'leave_type' => 'cuti_hamil',
            'start_date' => $start,
            'end_date'   => $end,
            'reason'     => 'Pengajuan cuti hamil oleh pria',
            'document'   => $file,
        ]);

        $res->assertStatus(422);
        $this->assertStringContainsString('hanya diperuntukkan bagi karyawan perempuan', $res->json('message'));
    }

    public function test_male_employee_cannot_request_cuti_haid(): void
    {
        $maleEmp = $this->makeUser('employee', 'Laki-laki');

        $start = Carbon::now('Asia/Jakarta')->addDays(1)->toDateString();

        $res = $this->actingAs($maleEmp, 'sanctum')->postJson('/api/v1/attendance/leave-request', [
            'leave_type' => 'cuti_haid',
            'start_date' => $start,
            'end_date'   => $start,
            'reason'     => 'Pengajuan cuti haid oleh pria',
        ]);

        $res->assertStatus(422);
        $this->assertStringContainsString('hanya diperuntukkan bagi karyawan perempuan', $res->json('message'));
    }

    public function test_female_employee_cannot_request_cuti_ayah(): void
    {
        $femaleEmp = $this->makeUser('employee', 'Perempuan');

        $start = Carbon::now('Asia/Jakarta')->addDays(1)->toDateString();

        $res = $this->actingAs($femaleEmp, 'sanctum')->postJson('/api/v1/attendance/leave-request', [
            'leave_type' => 'cuti_ayah',
            'start_date' => $start,
            'end_date'   => $start,
            'reason'     => 'Pengajuan cuti ayah oleh perempuan',
        ]);

        $res->assertStatus(422);
        $this->assertStringContainsString('hanya diperuntukkan bagi karyawan laki-laki', $res->json('message'));
    }

    public function test_unmarried_male_cannot_request_cuti_ayah(): void
    {
        $maleEmp = $this->makeUser('employee', 'Laki-laki', [
            'marital_status' => 'single',
        ]);

        $start = Carbon::now('Asia/Jakarta')->addDays(1)->toDateString();
        $end   = Carbon::now('Asia/Jakarta')->addDays(2)->toDateString();

        $res = $this->actingAs($maleEmp, 'sanctum')->postJson('/api/v1/attendance/leave-request', [
            'leave_type' => 'cuti_ayah',
            'start_date' => $start,
            'end_date'   => $end,
            'reason'     => 'Cuti ayah tapi belum menikah',
        ]);

        $res->assertStatus(422);
        $this->assertStringContainsString('hanya diperuntukkan bagi karyawan yang sudah berstatus menikah', $res->json('message'));
    }

    public function test_unmarried_employee_cannot_request_family_leave(): void
    {
        $singleEmp = $this->makeUser('employee', 'Laki-laki', [
            'marital_status' => 'single',
        ]);

        $start = Carbon::now('Asia/Jakarta')->addDays(1)->toDateString();
        $end   = Carbon::now('Asia/Jakarta')->addDays(2)->toDateString();

        $res = $this->actingAs($singleEmp, 'sanctum')->postJson('/api/v1/attendance/leave-request', [
            'leave_type' => 'cuti_menikahkan_anak',
            'start_date' => $start,
            'end_date'   => $end,
            'reason'     => 'Menikahkan anak padahal status lajang',
        ]);

        $res->assertStatus(422);
        $this->assertStringContainsString('hanya diperuntukkan bagi karyawan yang sudah berstatus menikah', $res->json('message'));
    }

    public function test_male_employee_can_request_cuti_ayah_without_document(): void
    {
        $maleEmp = $this->makeUser('employee', 'Laki-laki', [
            'marital_status' => 'married',
        ]);

        $start = Carbon::now('Asia/Jakarta')->addDays(1)->toDateString();
        $end   = Carbon::now('Asia/Jakarta')->addDays(2)->toDateString();

        $res = $this->actingAs($maleEmp, 'sanctum')->postJson('/api/v1/attendance/leave-request', [
            'leave_type' => 'cuti_ayah',
            'start_date' => $start,
            'end_date'   => $end,
            'reason'     => 'Mendampingi istri melahirkan',
        ]);

        $res->assertStatus(201);
        $res->assertJsonFragment(['leave_type' => 'cuti_ayah']);

        $this->assertDatabaseHas('leave_balances', [
            'user_id'    => $maleEmp->id,
            'leave_type' => 'cuti_ayah',
            'quota'      => 2,
            'used'       => 0,
        ]);
    }

    public function test_employee_cannot_request_disabled_leave_type(): void
    {
        $emp = $this->makeUser('employee');

        // cuti_setengah_hari is disabled by default
        $start = Carbon::now('Asia/Jakarta')->addDays(1)->toDateString();

        $res = $this->actingAs($emp, 'sanctum')->postJson('/api/v1/attendance/leave-request', [
            'leave_type' => 'cuti_setengah_hari',
            'start_date' => $start,
            'end_date'   => $start,
            'reason'     => 'Cuti setengah hari',
        ]);

        $res->assertStatus(422);
        $res->assertJsonValidationErrors(['leave_type']);
        $this->assertStringContainsString('belum diaktifkan untuk kantor Anda', $res->json('message') ?? json_encode($res->json()));
    }

    public function test_standalone_cuti_setengah_hari_approval_and_independent_balance_deduction(): void
    {
        // 1. Enable cuti_setengah_hari with quota 10
        LeaveTypeSetting::updateOrCreate(
            ['attendance_setting_id' => $this->office->id, 'leave_type' => 'cuti_setengah_hari'],
            ['is_enabled' => true, 'quota_days' => 10, 'requires_document' => false]
        );

        $hrd = $this->makeUser('hrd');
        $employee = $this->makeUser('employee');

        // Also setup annual leave balance with quota 12, used 0 to verify it stays untouched!
        $year = Carbon::now('Asia/Jakarta')->year;
        $annualBalance = LeaveBalance::create([
            'company_id' => $this->company->id,
            'user_id'    => $employee->id,
            'year'       => $year,
            'leave_type' => 'cuti',
            'quota'      => 12,
            'used'       => 0,
        ]);

        // 2. Request 2 days of cuti_setengah_hari (choose next Monday and Tuesday to guarantee 2 effective work days)
        $start = Carbon::now('Asia/Jakarta')->next(Carbon::MONDAY)->toDateString();
        $end   = Carbon::parse($start)->addDay()->toDateString();

        $resReq = $this->actingAs($employee, 'sanctum')->postJson('/api/v1/attendance/leave-request', [
            'leave_type' => 'cuti_setengah_hari',
            'start_date' => $start,
            'end_date'   => $end,
            'reason'     => 'Urusan pagi hari',
        ]);

        $resReq->assertStatus(201);
        $leaveId = $resReq->json('leave.id');

        // 3. HRD Approves the request
        $resApprove = $this->actingAs($hrd, 'sanctum')->postJson("/api/v1/dashboard/attendance/leaves/{$leaveId}/approve", [
            'notes' => 'Disetujui',
        ]);
        $resApprove->assertStatus(200);

        // 4. Verify cuti_setengah_hari balance: quota 10, used 2
        $halfDayBalance = LeaveBalance::where('user_id', $employee->id)
            ->where('leave_type', 'cuti_setengah_hari')
            ->where('year', $year)
            ->first();

        $this->assertNotNull($halfDayBalance);
        $this->assertEquals(10, $halfDayBalance->quota);
        $this->assertEquals(2, $halfDayBalance->used);

        // 5. Verify annual leave balance was NOT deducted!
        $annualBalance->refresh();
        $this->assertEquals(12, $annualBalance->quota);
        $this->assertEquals(0, $annualBalance->used);
    }

    public function test_leave_request_rejected_when_exceeding_office_quota(): void
    {
        $maleEmp = $this->makeUser('employee', 'Laki-laki', [
            'marital_status' => 'married',
        ]);

        // cuti_ayah quota is 2 days. Try requesting 5 days (start from next Monday).
        $start = Carbon::now('Asia/Jakarta')->next(Carbon::MONDAY)->toDateString();
        $end   = Carbon::parse($start)->addDays(4)->toDateString();

        $res = $this->actingAs($maleEmp, 'sanctum')->postJson('/api/v1/attendance/leave-request', [
            'leave_type' => 'cuti_ayah',
            'start_date' => $start,
            'end_date'   => $end,
            'reason'     => 'Cuti ayah melebihi kuota',
        ]);

        $res->assertStatus(422);
        $this->assertStringContainsString('tidak mencukupi', $res->json('message'));
    }

    public function test_my_leave_balance_filters_by_gender_and_office_settings(): void
    {
        $femaleEmp = $this->makeUser('employee', 'Perempuan');
        $maleEmp   = $this->makeUser('employee', 'Laki-laki');

        $resFemale = $this->actingAs($femaleEmp, 'sanctum')->getJson('/api/v1/attendance/leave-balance');
        $resFemale->assertStatus(200);
        $femaleTypes = collect($resFemale->json('balances'))->pluck('leave_type')->toArray();

        $this->assertContains('cuti_hamil', $femaleTypes);
        $this->assertContains('cuti_haid', $femaleTypes);
        $this->assertNotContains('cuti_ayah', $femaleTypes);

        $resMale = $this->actingAs($maleEmp, 'sanctum')->getJson('/api/v1/attendance/leave-balance');
        $resMale->assertStatus(200);
        $maleTypes = collect($resMale->json('balances'))->pluck('leave_type')->toArray();

        $this->assertContains('cuti_ayah', $maleTypes);
        $this->assertNotContains('cuti_hamil', $maleTypes);
        $this->assertNotContains('cuti_haid', $maleTypes);
    }

    public function test_annual_reset_command_snapshots_and_resets_dynamic_leave_balances(): void
    {
        // 1. Enable cuti_setengah_hari
        LeaveTypeSetting::updateOrCreate(
            ['attendance_setting_id' => $this->office->id, 'leave_type' => 'cuti_setengah_hari'],
            ['is_enabled' => true, 'quota_days' => 10, 'requires_document' => false]
        );

        $employee = $this->makeUser('employee');
        $year = Carbon::now('Asia/Jakarta')->year;

        // User has active annual leave and half day leave
        $cuti = LeaveBalance::create([
            'company_id' => $this->company->id,
            'user_id'    => $employee->id,
            'year'       => $year,
            'leave_type' => 'cuti',
            'quota'      => 12,
            'used'       => 4,
        ]);

        $halfDay = LeaveBalance::create([
            'company_id' => $this->company->id,
            'user_id'    => $employee->id,
            'year'       => $year,
            'leave_type' => 'cuti_setengah_hari',
            'quota'      => 10,
            'used'       => 3,
        ]);

        // Run the artisan command
        $this->artisan('attendance:reset-leave-balances')
            ->assertExitCode(0);

        // Verify history has snapshot
        $history = LeaveBalanceHistory::where('user_id', $employee->id)->latest('id')->first();
        $this->assertNotNull($history);
        $this->assertEquals(12, $history->cuti_quota);
        $this->assertEquals(4, $history->cuti_used);
        $this->assertNotNull($history->leave_types_snapshot);
        $this->assertEquals(3, $history->leave_types_snapshot['cuti_setengah_hari']['used']);
        $this->assertEquals(10, $history->leave_types_snapshot['cuti_setengah_hari']['quota']);
        $this->assertEquals(7, $history->leave_types_snapshot['cuti_setengah_hari']['remaining']);

        // Verify balances are reset for new period
        $cuti->refresh();
        $this->assertEquals(0, $cuti->used);

        $halfDay->refresh();
        $this->assertEquals(0, $halfDay->used);
        $this->assertEquals(10, $halfDay->quota);
    }

    public function test_izin_cannot_be_edited_via_set_leave_balance(): void
    {
        $hrd = $this->makeUser('hrd');
        $employee = $this->makeUser('employee');
        $year = Carbon::now('Asia/Jakarta')->year;

        // 1. Single update targeting 'izin' must return 422
        $resSingle = $this->actingAs($hrd, 'sanctum')->postJson('/api/v1/dashboard/attendance/leave-balances', [
            'user_id'    => $employee->id,
            'leave_type' => 'izin',
            'quota'      => 10,
            'year'       => $year,
        ]);

        $resSingle->assertStatus(422);
        $this->assertStringContainsString('Izin tidak memiliki batasan kuota', $resSingle->json('message'));

        // 2. Batch update containing 'izin' and 'cuti' must update 'cuti' but ignore 'izin'
        $resBatch = $this->actingAs($hrd, 'sanctum')->postJson('/api/v1/dashboard/attendance/leave-balances', [
            'user_id'  => $employee->id,
            'year'     => $year,
            'balances' => [
                ['leave_type' => 'cuti', 'quota' => 15],
                ['leave_type' => 'izin', 'quota' => 20],
            ],
        ]);

        $resBatch->assertStatus(200);

        // Check DB: cuti quota updated to 15, while izin quota remains 0 (unmetered)
        $cuti = LeaveBalance::where('user_id', $employee->id)->where('year', $year)->where('leave_type', 'cuti')->first();
        $this->assertNotNull($cuti);
        $this->assertEquals(15, $cuti->quota);

        $izin = LeaveBalance::where('user_id', $employee->id)->where('year', $year)->where('leave_type', 'izin')->first();
        $this->assertTrue($izin === null || $izin->quota === 0);
    }

    public function test_izin_approval_increments_used_without_quota_restrictions(): void
    {
        $hrd = $this->makeUser('hrd');
        $employee = $this->makeUser('employee');
        $year = Carbon::now('Asia/Jakarta')->year;

        // Submit leave request for izin (3 days) with current_step => 'hrd'
        $tomorrow = Carbon::tomorrow('Asia/Jakarta')->addDays(2);
        $endDate = $tomorrow->copy()->addDays(2);

        $req = LeaveRequest::create([
            'company_id'             => $this->company->id,
            'user_id'                => $employee->id,
            'leave_type'             => 'izin',
            'start_date'             => $tomorrow->toDateString(),
            'end_date'               => $endDate->toDateString(),
            'total_days'             => 3,
            'reason'                 => 'Keperluan keluarga mendesak',
            'status'                 => 'pending',
            'current_step'           => 'hrd',
            'requires_spv_approval'  => false,
        ]);

        // HRD approves the leave
        $resApprove = $this->actingAs($hrd, 'sanctum')->postJson("/api/v1/dashboard/attendance/leaves/{$req->id}/approve", [
            'notes' => 'Disetujui',
        ]);

        $resApprove->assertStatus(200);

        // Verify LeaveBalance for izin: quota = 0, used = 3
        $izin = LeaveBalance::where('user_id', $employee->id)->where('year', $year)->where('leave_type', 'izin')->first();
        $this->assertNotNull($izin);
        $this->assertEquals(0, $izin->quota);
        $this->assertEquals(3, $izin->used);

        // Submit another leave request for izin (2 days)
        $nextDate = $endDate->copy()->addDays(2);
        $nextEnd = $nextDate->copy()->addDay();

        $req2 = LeaveRequest::create([
            'company_id'             => $this->company->id,
            'user_id'                => $employee->id,
            'leave_type'             => 'izin',
            'start_date'             => $nextDate->toDateString(),
            'end_date'               => $nextEnd->toDateString(),
            'total_days'             => 2,
            'reason'                 => 'Urusan pribadi tambahan',
            'status'                 => 'pending',
            'current_step'           => 'hrd',
            'requires_spv_approval'  => false,
        ]);

        $resApprove2 = $this->actingAs($hrd, 'sanctum')->postJson("/api/v1/dashboard/attendance/leaves/{$req2->id}/approve", [
            'notes' => 'Disetujui kembali',
        ]);

        $resApprove2->assertStatus(200);

        // Verify used is accumulated (3 + 2 = 5)
        $izin->refresh();
        $this->assertEquals(0, $izin->quota);
        $this->assertEquals(5, $izin->used);
    }

    public function test_izin_used_is_reset_to_zero_on_office_and_annual_reset(): void
    {
        $hrd = $this->makeUser('hrd');
        $employee = $this->makeUser('employee');
        $year = Carbon::now('Asia/Jakarta')->year;

        // Initialize active cuti & accumulated izin
        $cuti = LeaveBalance::create([
            'company_id' => $this->company->id,
            'user_id'    => $employee->id,
            'year'       => $year,
            'leave_type' => 'cuti',
            'quota'      => 12,
            'used'       => 5,
        ]);

        $izin = LeaveBalance::create([
            'company_id' => $this->company->id,
            'user_id'    => $employee->id,
            'year'       => $year,
            'leave_type' => 'izin',
            'quota'      => 0,
            'used'       => 7,
        ]);

        // 1. Test manual office reset by HRD
        $resReset = $this->actingAs($hrd, 'sanctum')->postJson("/api/v1/dashboard/attendance/settings/{$this->office->id}/reset-leave-balances");
        $resReset->assertStatus(200);

        $izin->refresh();
        $this->assertEquals(0, $izin->used, 'Used days for izin must be reset to 0 after manual office reset');

        // Check history snapshot records izin used days
        $history = LeaveBalanceHistory::where('user_id', $employee->id)->latest('id')->first();
        $this->assertNotNull($history);
        $this->assertEquals(7, $history->izin_sakit_used);

        // Accumulate more used days for izin
        $izin->update(['used' => 4]);

        // 2. Test automated command reset (clear last_leave_reset_on to simulate new anniversary trigger)
        $this->office->update(['last_leave_reset_on' => null]);

        $this->artisan('attendance:reset-leave-balances')
            ->assertExitCode(0);

        $izin->refresh();
        $this->assertEquals(0, $izin->used, 'Used days for izin must be reset to 0 after automated annual reset');
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  TEST: Skenario semua leave type dimatikan, hanya izin yang menyala
    // ═══════════════════════════════════════════════════════════════════════

    public function test_when_all_leave_types_disabled_only_izin_remains_enabled(): void
    {
        $controller = new \App\Http\Controllers\API\AttendanceController(
            app(\App\Services\FcmService::class)
        );

        $employee = $this->makeUser('karyawan');

        // Matikan SEMUA LeaveTypeSetting untuk kantor ini
        LeaveTypeSetting::where('attendance_setting_id', $this->office->id)
            ->update(['is_enabled' => false]);

        $enabledTypes = $controller->enabledLeaveTypesFor($employee);

        // Izin harus SELALU ada
        $this->assertContains('izin', $enabledTypes, 'Izin must always be enabled regardless of settings');

        // Semua tipe lain harus MATI
        $this->assertNotContains('cuti', $enabledTypes, 'Cuti must be disabled when is_enabled=false');
        $this->assertNotContains('wfh', $enabledTypes, 'WFH must be disabled when is_enabled=false');
        $this->assertNotContains('sakit', $enabledTypes, 'Sakit must be disabled when is_enabled=false');

        // Hanya izin saja
        $this->assertCount(1, $enabledTypes, 'Only izin should be in enabled types');
        $this->assertEquals(['izin'], $enabledTypes);
    }

    public function test_disabled_leave_type_is_rejected_at_request_validation(): void
    {
        $employee = $this->makeUser('karyawan');

        // Matikan cuti di setting kantor
        LeaveTypeSetting::where('attendance_setting_id', $this->office->id)
            ->where('leave_type', 'cuti')
            ->update(['is_enabled' => false]);

        // Pastikan user punya saldo cuti (tapi seharusnya tidak bisa dipakai)
        LeaveBalance::updateOrCreate(
            ['user_id' => $employee->id, 'year' => now()->year, 'leave_type' => 'cuti'],
            ['company_id' => $this->company->id, 'quota' => 12, 'used' => 0]
        );

        $response = $this->actingAs($employee, 'sanctum')->postJson('/api/v1/attendance/leave-request', [
            'leave_type'  => 'cuti',
            'start_date'  => now()->addDays(1)->toDateString(),
            'end_date'    => now()->addDays(1)->toDateString(),
            'reason'      => 'Cuti liburan',
        ]);

        // Harus ditolak karena cuti sudah dimatikan
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['leave_type']);
    }

    public function test_izin_request_still_works_when_other_types_disabled(): void
    {
        $employee = $this->makeUser('karyawan');

        // Matikan SEMUA LeaveTypeSetting
        LeaveTypeSetting::where('attendance_setting_id', $this->office->id)
            ->update(['is_enabled' => false]);

        // Pastikan saldo izin ada
        LeaveBalance::updateOrCreate(
            ['user_id' => $employee->id, 'year' => now()->year, 'leave_type' => 'izin'],
            ['company_id' => $this->company->id, 'quota' => 0, 'used' => 0]
        );

        $response = $this->actingAs($employee, 'sanctum')->postJson('/api/v1/attendance/leave-request', [
            'leave_type'  => 'izin',
            'start_date'  => now()->addDays(1)->toDateString(),
            'end_date'    => now()->addDays(1)->toDateString(),
            'reason'      => 'Izin pribadi urusan penting',
        ]);

        // Izin harus tetap bisa diajukan (201 Created)
        $response->assertStatus(201);
        $response->assertJsonFragment(['leave_type' => 'izin']);
    }

    public function test_selectively_enabling_wfh_adds_it_back_to_enabled_list(): void
    {
        $controller = new \App\Http\Controllers\API\AttendanceController(
            app(\App\Services\FcmService::class)
        );

        $employee = $this->makeUser('karyawan');

        // Matikan semua dulu
        LeaveTypeSetting::where('attendance_setting_id', $this->office->id)
            ->update(['is_enabled' => false]);

        // Lalu nyalakan WFH saja
        LeaveTypeSetting::where('attendance_setting_id', $this->office->id)
            ->where('leave_type', 'wfh')
            ->update(['is_enabled' => true]);

        $enabledTypes = $controller->enabledLeaveTypesFor($employee);

        $this->assertContains('izin', $enabledTypes, 'Izin is always enabled');
        $this->assertContains('wfh', $enabledTypes, 'WFH should be enabled because is_enabled=true');
        $this->assertNotContains('cuti', $enabledTypes, 'Cuti should remain disabled');
        $this->assertCount(2, $enabledTypes);
    }

    public function test_my_leave_balance_returns_enhanced_contract(): void
    {
        $employee = $this->makeUser('employee', 'Laki-laki');

        // Matikan satu jenis cuti di kantor (misal cuti_ibadah_haji_umrah)
        LeaveTypeSetting::where('attendance_setting_id', $this->office->id)
            ->where('leave_type', 'cuti_ibadah_haji_umrah')
            ->update(['is_enabled' => false]);

        $res = $this->actingAs($employee, 'sanctum')->getJson('/api/v1/attendance/leave-balance');

        $res->assertStatus(200);
        $res->assertJsonStructure([
            'year',
            'balances' => [
                '*' => [
                    'leave_type',
                    'leave_type_label',
                    'quota',
                    'used',
                    'remaining',
                    'active',
                    'is_unlimited',
                    'is_disabled',
                ],
            ],
            'leave_reset_info',
            'reset_info',
        ]);

        $balances = collect($res->json('balances'))->keyBy('leave_type');

        // Izin: unlimited, active, not disabled, remaining null
        $this->assertTrue($balances->has('izin'));
        $this->assertTrue($balances['izin']['active']);
        $this->assertTrue($balances['izin']['is_unlimited']);
        $this->assertFalse($balances['izin']['is_disabled']);
        $this->assertNull($balances['izin']['remaining']);

        // Cuti: not unlimited, active=false if quota 0
        $this->assertTrue($balances->has('cuti'));
        $this->assertFalse($balances['cuti']['is_unlimited']);
        $this->assertFalse($balances['cuti']['is_disabled']);

        // Sakit: quota 14, active=true, not unlimited
        $this->assertTrue($balances->has('sakit'));
        $this->assertTrue($balances['sakit']['active']);
        $this->assertEquals(14, $balances['sakit']['quota']);
        $this->assertFalse($balances['sakit']['is_unlimited']);

        // Cuti ibadah haji umrah: disabled
        $this->assertTrue($balances->has('cuti_ibadah_haji_umrah'));
        $this->assertTrue($balances['cuti_ibadah_haji_umrah']['is_disabled']);
        $this->assertFalse($balances['cuti_ibadah_haji_umrah']['active']);
    }

    public function test_hrd_set_leave_balance_creates_adjustment_and_notifies_employee(): void
    {
        $hrd = $this->makeUser('hrd');
        $employee = $this->makeUser('employee', 'Laki-laki', [
            'fcm_token' => 'fake_fcm_token_123',
        ]);

        // Initial setup
        LeaveBalance::create([
            'company_id' => $this->company->id,
            'user_id'    => $employee->id,
            'year'       => 2026,
            'leave_type' => 'sakit',
            'quota'      => 14,
            'used'       => 0,
        ]);

        // HRD increases sick leave quota from 14 to 18
        $res = $this->actingAs($hrd, 'sanctum')->postJson('/api/v1/dashboard/attendance/leave-balances', [
            'user_id'    => $employee->id,
            'leave_type' => 'sakit',
            'quota'      => 18,
            'year'       => 2026,
            'reason'     => 'Bonus cuti sakit tahun berjalan',
        ]);

        $res->assertOk();
        $res->assertJsonStructure(['message', 'adjustments']);
        $this->assertCount(1, $res->json('adjustments'));
        $this->assertEquals(14, $res->json('adjustments.0.old_quota'));
        $this->assertEquals(18, $res->json('adjustments.0.new_quota'));
        $this->assertEquals(4, $res->json('adjustments.0.difference'));

        // Verify LeaveQuotaAdjustment in DB
        $this->assertDatabaseHas('leave_quota_adjustments', [
            'user_id'        => $employee->id,
            'adjusted_by_id' => $hrd->id,
            'leave_type'     => 'sakit',
            'year'           => 2026,
            'old_quota'      => 14,
            'new_quota'      => 18,
            'difference'     => 4,
            'reason'         => 'Bonus cuti sakit tahun berjalan',
        ]);

        // Verify Notification created for employee
        $this->assertDatabaseHas('notifications', [
            'user_id' => $employee->id,
            'type'    => 'leave_quota_adjusted',
        ]);

        // Verify myLeaves returns the adjustment
        $myLeavesRes = $this->actingAs($employee, 'sanctum')->getJson('/api/v1/attendance/my-leaves');
        $myLeavesRes->assertOk();
        $myLeavesRes->assertJsonStructure(['leaves', 'adjustments']);
        $this->assertCount(1, $myLeavesRes->json('adjustments'));
        $this->assertEquals('sakit', $myLeavesRes->json('adjustments.0.leave_type'));
        $this->assertEquals(14, $myLeavesRes->json('adjustments.0.old_quota'));
        $this->assertEquals(18, $myLeavesRes->json('adjustments.0.new_quota'));
        $this->assertEquals(4, $myLeavesRes->json('adjustments.0.difference'));
        $this->assertEquals($hrd->name, $myLeavesRes->json('adjustments.0.adjusted_by_name'));
    }

    public function test_when_user_allow_leave_is_false_all_cuti_types_are_disabled_except_izin_and_wfh(): void
    {
        $hrd = $this->makeUser('hrd');
        $employee = $this->makeUser('employee');
        $year = Carbon::now('Asia/Jakarta')->year;

        // 1. HRD nonaktifkan hak cuti employee via endpoint leave-balances
        $resToggleOff = $this->actingAs($hrd, 'sanctum')->postJson('/api/v1/dashboard/attendance/leave-balances', [
            'user_id'     => $employee->id,
            'leave_type'  => 'cuti',
            'quota'       => 0,
            'allow_leave' => false,
            'year'        => $year,
        ]);
        $resToggleOff->assertOk();
        $this->assertFalse($resToggleOff->json('allow_leave'));

        $employee->refresh();
        $this->assertFalse($employee->allow_leave);

        // 2. listLeaveBalances harus menandai cuti & jenis cuti lain dengan active=false & is_disabled=true, izin tetap active
        $listRes = $this->actingAs($hrd, 'sanctum')->getJson("/api/v1/dashboard/attendance/leave-balances?user_id={$employee->id}&year={$year}");
        $listRes->assertOk();
        $balances = collect($listRes->json('balances'))->where('user_id', $employee->id);
        
        $cutiBal = $balances->firstWhere('leave_type', 'cuti');
        $this->assertNotNull($cutiBal);
        $this->assertFalse($cutiBal['active']);
        $this->assertTrue($cutiBal['is_disabled']);
        $this->assertFalse($cutiBal['allow_leave']);

        $sakitBal = $balances->firstWhere('leave_type', 'sakit');
        if ($sakitBal) {
            $this->assertFalse($sakitBal['active']);
            $this->assertTrue($sakitBal['is_disabled']);
        }

        $izinBal = $balances->firstWhere('leave_type', 'izin');
        $this->assertNotNull($izinBal);
        $this->assertTrue($izinBal['active']);
        $this->assertFalse($izinBal['is_disabled']);

        // 3. myLeaveBalance karyawan juga menandai semua jenis cuti is_disabled=true kecuali izin
        $myBalRes = $this->actingAs($employee, 'sanctum')->getJson("/api/v1/attendance/leave-balance?year={$year}");
        $myBalRes->assertOk();
        $this->assertFalse($myBalRes->json('allow_leave'));
        $myBalances = collect($myBalRes->json('balances'));
        
        $myCuti = $myBalances->firstWhere('leave_type', 'cuti');
        $this->assertNotNull($myCuti);
        $this->assertFalse($myCuti['active']);
        $this->assertTrue($myCuti['is_disabled']);

        $mySakit = $myBalances->firstWhere('leave_type', 'sakit');
        $this->assertNotNull($mySakit);
        $this->assertFalse($mySakit['active']);
        $this->assertTrue($mySakit['is_disabled']);

        $myIzin = $myBalances->firstWhere('leave_type', 'izin');
        $this->assertNotNull($myIzin);
        $this->assertTrue($myIzin['active']);
        $this->assertFalse($myIzin['is_disabled']);

        // 4. leavePreview untuk cuti atau sakit harus ditolak 422
        $targetDate = Carbon::now('Asia/Jakarta')->addDays(1);
        while ($targetDate->isWeekend()) {
            $targetDate->addDay();
        }
        $validWorkday = $targetDate->toDateString();

        $previewRes = $this->actingAs($employee, 'sanctum')->getJson("/api/v1/attendance/leave-preview?leave_type=cuti&start_date={$validWorkday}&end_date={$validWorkday}");
        $previewRes->assertStatus(422);
        $this->assertStringContainsString('Hak cuti Anda sedang dinonaktifkan oleh HRD', $previewRes->json('message'));

        // 5. requestLeave untuk cuti tahunan harus ditolak 422
        $reqCutiRes = $this->actingAs($employee, 'sanctum')->postJson('/api/v1/attendance/leave-request', [
            'leave_type' => 'cuti',
            'start_date' => $validWorkday,
            'end_date'   => $validWorkday,
            'reason'     => 'Mau liburan',
        ]);
        $reqCutiRes->assertStatus(422);
        $this->assertStringContainsString('Hak cuti Anda sedang dinonaktifkan oleh HRD', $reqCutiRes->json('message'));

        // 6. requestLeave untuk sakit harus ditolak 422
        $reqSakitRes = $this->actingAs($employee, 'sanctum')->postJson('/api/v1/attendance/leave-request', [
            'leave_type' => 'sakit',
            'start_date' => $validWorkday,
            'end_date'   => $validWorkday,
            'reason'     => 'Sakit demam',
        ]);
        $reqSakitRes->assertStatus(422);
        $this->assertStringContainsString('Hak cuti Anda sedang dinonaktifkan oleh HRD', $reqSakitRes->json('message'));

        // 7. requestLeave untuk izin harus DITERIMA (201)
        $reqIzinRes = $this->actingAs($employee, 'sanctum')->postJson('/api/v1/attendance/leave-request', [
            'leave_type' => 'izin',
            'start_date' => $validWorkday,
            'end_date'   => $validWorkday,
            'reason'     => 'Urusan keluarga penting',
        ]);
        $reqIzinRes->assertStatus(201);
        $this->assertDatabaseHas('leave_requests', [
            'user_id'    => $employee->id,
            'leave_type' => 'izin',
            'status'     => 'pending',
        ]);

        // 8. HRD mengaktifkan kembali hak cuti employee
        $resToggleOn = $this->actingAs($hrd, 'sanctum')->postJson('/api/v1/dashboard/attendance/leave-balances', [
            'user_id'     => $employee->id,
            'leave_type'  => 'cuti',
            'quota'       => 12,
            'allow_leave' => true,
            'year'        => $year,
        ]);
        $resToggleOn->assertOk();
        $this->assertTrue($resToggleOn->json('allow_leave'));

        $employee->refresh();
        $this->assertTrue($employee->allow_leave);
    }
}


