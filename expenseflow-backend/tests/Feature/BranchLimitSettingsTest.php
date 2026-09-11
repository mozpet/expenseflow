<?php

namespace Tests\Feature;

use App\Models\AttendanceSetting;
use App\Models\Company;
use App\Models\Receipt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BranchLimitSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $finance;
    private AttendanceSetting $officeJakarta;
    private AttendanceSetting $officeSurabaya;
    private User $employeeJakarta;
    private User $employeeSurabaya;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        $this->company = Company::create(['name' => 'PT Gawe Sukses', 'is_active' => true]);

        // Default company settings: variance_limit = 10%, max_claim_limit = 2.000.000
        DB::table('company_settings')->insert([
            ['company_id' => $this->company->id, 'key' => 'variance_limit', 'value' => '10', 'created_at' => now(), 'updated_at' => now()],
            ['company_id' => $this->company->id, 'key' => 'max_claim_limit', 'value' => '2000000', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->officeJakarta = AttendanceSetting::create([
            'company_id'       => $this->company->id,
            'office_name'      => 'Cabang Jakarta',
            'office_latitude'  => -6.2088,
            'office_longitude' => 106.8456,
            'radius_meters'    => 100,
            'work_start_time'  => '08:00',
            'work_end_time'    => '17:00',
            // variance_limit & max_claim_limit = null (inherit company)
        ]);

        $this->officeSurabaya = AttendanceSetting::create([
            'company_id'       => $this->company->id,
            'office_name'      => 'Cabang Surabaya',
            'office_latitude'  => -7.2575,
            'office_longitude' => 112.7521,
            'radius_meters'    => 100,
            'work_start_time'  => '08:00',
            'work_end_time'    => '17:00',
            'variance_limit'   => 25,
            'max_claim_limit'  => 5000000,
        ]);

        $this->finance = User::factory()->create([
            'company_id' => $this->company->id,
            'role'       => 'finance',
            'is_active'  => true,
        ]);

        $this->employeeJakarta = User::factory()->create([
            'company_id'            => $this->company->id,
            'role'                  => 'employee',
            'is_active'             => true,
            'attendance_setting_id' => $this->officeJakarta->id,
        ]);

        $this->employeeSurabaya = User::factory()->create([
            'company_id'            => $this->company->id,
            'role'                  => 'employee',
            'is_active'             => true,
            'attendance_setting_id' => $this->officeSurabaya->id,
        ]);
    }

    public function test_settings_index_returns_branch_settings_list(): void
    {
        $response = $this->actingAs($this->finance)
            ->getJson('/api/v1/dashboard/settings');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'settings' => ['variance_limit', 'max_claim_limit'],
                'branch_settings' => [
                    '*' => ['id', 'office_name', 'variance_limit', 'max_claim_limit'],
                ],
            ]);

        $branches = $response->json('branch_settings');
        $this->assertCount(2, $branches);

        $jkt = collect($branches)->firstWhere('id', $this->officeJakarta->id);
        $this->assertNull($jkt['variance_limit']);
        $this->assertNull($jkt['max_claim_limit']);

        $sby = collect($branches)->firstWhere('id', $this->officeSurabaya->id);
        $this->assertEquals(25, $sby['variance_limit']);
        $this->assertEquals(5000000, $sby['max_claim_limit']);
    }

    public function test_finance_can_update_branch_limits(): void
    {
        $response = $this->actingAs($this->finance)
            ->putJson("/api/v1/dashboard/settings/branches/{$this->officeJakarta->id}", [
                'variance_limit'  => 15,
                'max_claim_limit' => 3500000,
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'branch' => [
                    'id'              => $this->officeJakarta->id,
                    'variance_limit'  => 15,
                    'max_claim_limit' => 3500000,
                ],
            ]);

        $this->officeJakarta->refresh();
        $this->assertEquals(15, $this->officeJakarta->variance_limit);
        $this->assertEquals(3500000, $this->officeJakarta->max_claim_limit);
    }

    public function test_finance_can_reset_branch_limits_to_null(): void
    {
        $response = $this->actingAs($this->finance)
            ->putJson("/api/v1/dashboard/settings/branches/{$this->officeSurabaya->id}", [
                'variance_limit'  => null,
                'max_claim_limit' => null,
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'branch' => [
                    'id'              => $this->officeSurabaya->id,
                    'variance_limit'  => null,
                    'max_claim_limit' => null,
                ],
            ]);

        $this->officeSurabaya->refresh();
        $this->assertNull($this->officeSurabaya->variance_limit);
        $this->assertNull($this->officeSurabaya->max_claim_limit);
    }

    public function test_branch_max_claim_limit_is_enforced_for_submitting_receipt(): void
    {
        // Surabaya limit = 5.000.000. Submit 4.000.000 (exceeds global 2.000.000, but within branch 5.000.000)
        $receiptSurabaya = Receipt::create([
            'company_id'            => $this->company->id,
            'user_id'               => $this->employeeSurabaya->id,
            'attendance_setting_id' => $this->officeSurabaya->id,
            'receipt_number'        => 'RCP-SBY-001',
            'image_path'            => 'receipts/test.jpg',
            'total_amount'          => 4000000,
            'claimed_amount'        => 4000000,
            'receipt_date'          => now()->toDateString(),
            'status'                => 'draft',
            'ocr_status'            => 'completed',
            'ocr_raw_amount'        => 4000000,
        ]);

        $responseSby = $this->actingAs($this->employeeSurabaya)
            ->postJson("/api/v1/employee/receipts/{$receiptSurabaya->id}/submit");

        $responseSby->assertStatus(200);

        // Jakarta limit = null (falls back to global 2.000.000). Submit 2.500.000 -> rejected 422
        $receiptJakarta = Receipt::create([
            'company_id'            => $this->company->id,
            'user_id'               => $this->employeeJakarta->id,
            'attendance_setting_id' => $this->officeJakarta->id,
            'receipt_number'        => 'RCP-JKT-001',
            'image_path'            => 'receipts/test.jpg',
            'total_amount'          => 2500000,
            'claimed_amount'        => 2500000,
            'receipt_date'          => now()->toDateString(),
            'status'                => 'draft',
            'ocr_status'            => 'completed',
            'ocr_raw_amount'        => 2500000,
        ]);

        $responseJkt = $this->actingAs($this->employeeJakarta)
            ->postJson("/api/v1/employee/receipts/{$receiptJakarta->id}/submit");

        $responseJkt->assertStatus(422)
            ->assertJson([
                'code' => 'CLAIM_LIMIT_EXCEEDED',
            ]);
    }

    public function test_branch_variance_limit_is_respected_on_approval(): void
    {
        // Surabaya variance limit = 25% (company default is 10%)
        // OCR = 1.000.000, claimed = 1.200.000 (diff 20%, exceeds 10% company, but within 25% Surabaya)
        $receiptSurabaya = Receipt::create([
            'company_id'            => $this->company->id,
            'user_id'               => $this->employeeSurabaya->id,
            'attendance_setting_id' => $this->officeSurabaya->id,
            'receipt_number'        => 'RCP-SBY-VAR-001',
            'image_path'            => 'receipts/test.jpg',
            'total_amount'          => 1200000,
            'claimed_amount'        => 1200000,
            'ocr_raw_amount'        => 1000000,
            'receipt_date'          => now()->toDateString(),
            'status'                => 'submitted',
            'ocr_status'            => 'completed',
        ]);
        $receiptSurabaya->recalculateVariance();

        // Struk Surabaya variance_flag should be false (20% <= 25%)
        $this->assertFalse($receiptSurabaya->variance_flag);

        // Finance approves receipt Surabaya -> should succeed
        $response = $this->actingAs($this->finance)
            ->postJson("/api/v1/dashboard/receipts/{$receiptSurabaya->id}/approve", [
                'approved_amount' => 1200000,
            ]);

        $response->assertStatus(200);
        $receiptSurabaya->refresh();
        $this->assertEquals('approved', $receiptSurabaya->status);

        // Jakarta variance limit = null (falls back to 10%)
        // OCR = 1.000.000, claimed = 1.200.000 (diff 20% > 10% company)
        $receiptJakarta = Receipt::create([
            'company_id'            => $this->company->id,
            'user_id'               => $this->employeeJakarta->id,
            'attendance_setting_id' => $this->officeJakarta->id,
            'receipt_number'        => 'RCP-JKT-VAR-001',
            'image_path'            => 'receipts/test.jpg',
            'total_amount'          => 1200000,
            'claimed_amount'        => 1200000,
            'ocr_raw_amount'        => 1000000,
            'receipt_date'          => now()->toDateString(),
            'status'                => 'submitted',
            'ocr_status'            => 'completed',
        ]);
        $receiptJakarta->recalculateVariance();

        // Struk Jakarta variance_flag should be true (20% > 10%)
        $this->assertTrue($receiptJakarta->variance_flag);

        // Approving Jakarta receipt with 20% variance should be rejected (exceeds 10%)
        $responseJkt = $this->actingAs($this->finance)
            ->postJson("/api/v1/dashboard/receipts/{$receiptJakarta->id}/approve", [
                'approved_amount' => 1200000,
            ]);

        $responseJkt->assertStatus(422)
            ->assertJson([
                'code' => 'VARIANCE_LIMIT_EXCEEDED',
            ]);
    }
}
