<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AttendanceSetting;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FieldVisitDinasLuarAttendanceTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private AttendanceSetting $branchJakarta;
    private AttendanceSetting $branchSurabaya;
    private User $hrd;
    private User $salesUser;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-16 08:30:00');
        Storage::fake('local');

        $this->company = Company::create([
            'name'      => 'PT Kunjungan Mandiri',
            'is_active' => true,
        ]);

        // Kantor Cabang Jakarta
        $this->branchJakarta = AttendanceSetting::create([
            'company_id'       => $this->company->id,
            'office_name'      => 'Cabang Jakarta Pusat',
            'office_latitude'  => -6.2000000,
            'office_longitude' => 106.8166670,
            'radius_meters'    => 150,
            'work_start_time'  => '08:00:00',
            'work_end_time'    => '17:00:00',
        ]);

        // Kantor Cabang Surabaya (Multi-geofence roaming)
        $this->branchSurabaya = AttendanceSetting::create([
            'company_id'       => $this->company->id,
            'office_name'      => 'Cabang Surabaya',
            'office_latitude'  => -7.2575000,
            'office_longitude' => 112.7521000,
            'radius_meters'    => 200,
            'work_start_time'  => '08:00:00',
            'work_end_time'    => '17:00:00',
        ]);

        $this->hrd = User::factory()->create([
            'company_id'            => $this->company->id,
            'role'                  => 'hrd',
            'name'                  => 'HRD Manager',
            'attendance_enabled'    => true,
            'attendance_setting_id' => $this->branchJakarta->id,
        ]);

        $this->salesUser = User::factory()->create([
            'company_id'            => $this->company->id,
            'role'                  => 'employee',
            'name'                  => 'Budi Sales Lapangan',
            'attendance_enabled'    => true,
            'wfh_enabled'           => false,
            'radius_enabled'        => true,
            'dinas_luar_enabled'    => false,
            'attendance_setting_id' => $this->branchJakarta->id,
        ]);
    }

    public function test_hrd_can_toggle_dinas_luar_permission_for_employee(): void
    {
        $response = $this->actingAs($this->hrd)
            ->postJson("/api/v1/dashboard/attendance/users/{$this->salesUser->id}/toggle-dinas-luar");

        $response->assertStatus(200)
            ->assertJsonPath('user.dinas_luar_enabled', true)
            ->assertJsonPath('user.wfh_enabled', true)
            ->assertJsonPath('user.radius_enabled', false);

        $fresh = $this->salesUser->fresh();
        $this->assertTrue($fresh->canDinasLuar());
        $this->assertTrue((bool) $fresh->wfh_enabled);
        $this->assertFalse((bool) $fresh->radius_enabled);

        // Toggle kembali menjadi nonaktif
        $response2 = $this->actingAs($this->hrd)
            ->postJson("/api/v1/dashboard/attendance/users/{$this->salesUser->id}/toggle-dinas-luar");

        $response2->assertStatus(200)
            ->assertJsonPath('user.dinas_luar_enabled', false)
            ->assertJsonPath('user.wfh_enabled', false);

        $fresh2 = $this->salesUser->fresh();
        $this->assertFalse($fresh2->canDinasLuar());
        $this->assertFalse((bool) $fresh2->wfh_enabled);
    }

    public function test_dinas_luar_check_in_is_blocked_if_permission_not_granted(): void
    {
        $photo = UploadedFile::fake()->image('visit.jpg');

        $response = $this->actingAs($this->salesUser)
            ->postJson('/api/v1/attendance/check-in', [
                'latitude'       => -6.2100000,
                'longitude'      => 106.8200000,
                'check_in_type'  => 'dinas_luar',
                'client_name'    => 'PT Mega Solusindo',
                'client_address' => 'Jl. Sudirman No 45, Jakarta Selatan',
                'photo'          => $photo,
            ]);

        $response->assertStatus(403)
            ->assertJson([
                'message' => 'Anda tidak memiliki izin presensi dinas luar / kunjungan klien. Hubungi HRD.',
            ]);
    }

    public function test_dinas_luar_check_in_requires_client_name_and_allows_without_photo(): void
    {
        $this->salesUser->update(['dinas_luar_enabled' => true]);

        // Missing client_name -> ditolak 422
        $responseNoClient = $this->actingAs($this->salesUser)
            ->postJson('/api/v1/attendance/check-in', [
                'latitude'      => -6.2100000,
                'longitude'     => 106.8200000,
                'check_in_type' => 'dinas_luar',
            ]);
        $responseNoClient->assertStatus(422)
            ->assertJson(['message' => 'Nama klien / instansi wajib diisi untuk presensi dinas luar.']);

        // Check-in dinas luar TANPA foto -> sukses 201
        $responseNoPhoto = $this->actingAs($this->salesUser)
            ->postJson('/api/v1/attendance/check-in', [
                'latitude'      => -6.2100000,
                'longitude'     => 106.8200000,
                'check_in_type' => 'dinas_luar',
                'client_name'   => 'PT Mega Solusindo',
            ]);
        $responseNoPhoto->assertStatus(201)
            ->assertJsonPath('attendance.check_in_type', 'dinas_luar')
            ->assertJsonPath('attendance.client_name', 'PT Mega Solusindo');
    }

    public function test_dinas_luar_check_in_succeeds_and_saves_client_visit_details(): void
    {
        $this->salesUser->update(['dinas_luar_enabled' => true]);
        $photo = UploadedFile::fake()->image('selfie_klien.jpg');

        $response = $this->actingAs($this->salesUser)
            ->postJson('/api/v1/attendance/check-in', [
                'latitude'       => -6.3000000, // Lokasi klien jauh dari kantor
                'longitude'      => 106.9000000,
                'check_in_type'  => 'dinas_luar',
                'client_name'    => 'PT Mitra Abadi Jaya',
                'client_address' => 'Gedung Cyber 2 Lantai 15, Kuningan',
                'visit_notes'    => 'Presentasi implementasi POS di kantor klien',
                'photo'          => $photo,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('attendance.check_in_type', 'dinas_luar')
            ->assertJsonPath('attendance.client_name', 'PT Mitra Abadi Jaya')
            ->assertJsonPath('attendance.client_address', 'Gedung Cyber 2 Lantai 15, Kuningan')
            ->assertJsonPath('attendance.visit_notes', 'Presentasi implementasi POS di kantor klien');

        $att = Attendance::where('user_id', $this->salesUser->id)->first();
        $this->assertNotNull($att);
        $this->assertEquals('dinas_luar', $att->check_in_type);
        $this->assertEquals('PT Mitra Abadi Jaya', $att->client_name);
        $this->assertNotNull($att->check_in_photo);

        // Test Photo Streaming Endpoint
        $photoResponse = $this->actingAs($this->salesUser)
            ->get("/api/v1/attendance/{$att->id}/photo");
        $photoResponse->assertStatus(200);

        // HRD dapat melihat foto
        $hrdPhotoResponse = $this->actingAs($this->hrd)
            ->get("/api/v1/dashboard/attendance/attendances/{$att->id}/photo");
        $hrdPhotoResponse->assertStatus(200);
    }

    public function test_multi_geofence_roaming_allows_check_in_at_any_company_branch(): void
    {
        // Karyawan ditempatkan di Jakarta, tetapi sedang berkunjung/dinas di Cabang Surabaya
        $this->salesUser->update([
            'wfh_enabled'    => true,
            'radius_enabled' => true,
        ]);

        // Koordinat tepat di Cabang Surabaya (-7.2575000, 112.7521000)
        $response = $this->actingAs($this->salesUser)
            ->postJson('/api/v1/attendance/check-in', [
                'latitude'      => -7.2575010,
                'longitude'     => 112.7521010,
                'check_in_type' => 'field',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('attendance.check_in_type', 'field');

        $att = Attendance::where('user_id', $this->salesUser->id)->first();
        $this->assertNotNull($att);
        $this->assertLessThan(20, $att->check_in_distance_meters);
    }

    public function test_check_status_returns_all_branches_and_dinas_luar_enabled(): void
    {
        $this->salesUser->update(['dinas_luar_enabled' => true]);

        $response = $this->actingAs($this->salesUser)
            ->getJson('/api/v1/attendance/status');

        $response->assertStatus(200)
            ->assertJsonPath('dinas_luar_enabled', true)
            ->assertJsonCount(2, 'offices'); // Jakarta & Surabaya
    }

    public function test_hrd_today_and_report_endpoint_include_client_visit_details(): void
    {
        $this->salesUser->update(['dinas_luar_enabled' => true]);
        $photo = UploadedFile::fake()->image('klien_visit.jpg');

        $this->actingAs($this->salesUser)
            ->postJson('/api/v1/attendance/check-in', [
                'latitude'       => -6.3000000,
                'longitude'      => 106.9000000,
                'check_in_type'  => 'dinas_luar',
                'client_name'    => 'PT Semesta Data',
                'client_address' => 'Gedung Graha Tower 3',
                'visit_notes'    => 'Diskusi instalasi server',
                'photo'          => $photo,
            ]);

        // HRD Today Attendance
        $todayResponse = $this->actingAs($this->hrd)
            ->getJson('/api/v1/dashboard/attendance/today');

        $todayResponse->assertStatus(200);
        $checkedInList = collect($todayResponse->json('checked_in'));
        $budiData = $checkedInList->firstWhere('user_id', $this->salesUser->id);
        $this->assertNotNull($budiData);
        $this->assertEquals('dinas_luar', $budiData['check_in_type']);
        $this->assertEquals('PT Semesta Data', $budiData['client_name']);
        $this->assertEquals('Gedung Graha Tower 3', $budiData['client_address']);

        // HRD Report
        $reportResponse = $this->actingAs($this->hrd)
            ->getJson('/api/v1/dashboard/attendance/report?start_date=2026-09-16&end_date=2026-09-16');

        $reportResponse->assertStatus(200);
        $reportData = collect($reportResponse->json('report.data'));
        $budiReport = $reportData->firstWhere('user_id', $this->salesUser->id);
        $this->assertNotNull($budiReport);
        $this->assertEquals('dinas_luar', $budiReport['check_in_type']);
        $this->assertEquals('PT Semesta Data', $budiReport['client_name']);
    }
}
