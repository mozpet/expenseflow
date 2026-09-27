<?php

namespace Tests\Feature;

use App\Models\AttendanceSetting;
use App\Models\Company;
use App\Models\ExpenseReport;
use App\Models\Receipt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReceiptClaimLimitAndDisabledAccessTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $employee;
    private User $admin;
    private AttendanceSetting $office;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->company = Company::create(['name' => 'PT Klaim Struk Test', 'is_active' => true]);

        $this->office = AttendanceSetting::create([
            'company_id'       => $this->company->id,
            'office_name'      => 'Kantor Pusat Jakarta',
            'office_latitude'  => -6.2088,
            'office_longitude' => 106.8456,
            'radius_meters'    => 100,
            'work_start_time'  => '08:00',
            'work_end_time'    => '17:00',
        ]);

        $this->employee = User::factory()->create([
            'company_id'            => $this->company->id,
            'attendance_setting_id' => $this->office->id,
            'role'                  => 'employee',
            'is_active'             => true,
            'allow_receipt_claim'   => true,
            'monthly_claim_limit'   => 2000000,
        ]);

        $this->admin = User::factory()->create([
            'company_id' => $this->company->id,
            'role'       => 'admin',
            'is_active'  => true,
        ]);
    }

    public function test_user_with_allow_receipt_claim_false_cannot_store_receipt(): void
    {
        $this->employee->update(['allow_receipt_claim' => false]);
        Sanctum::actingAs($this->employee, ['*']);

        $file = UploadedFile::fake()->image('struk.jpg', 600, 800);

        $response = $this->postJson('/api/v1/employee/receipts', [
            'image' => $file,
        ], [
            'X-Platform' => 'mobile',
        ]);

        $response->assertStatus(403);
        $response->assertJsonFragment([
            'message' => 'Akses klaim struk dinonaktifkan untuk akun Anda. Silakan hubungi admin/HRD.',
        ]);
    }

    public function test_user_with_allow_receipt_claim_false_cannot_submit_receipt(): void
    {
        $receipt = Receipt::create([
            'company_id'     => $this->company->id,
            'user_id'        => $this->employee->id,
            'receipt_number' => 'RCP-TEST-001',
            'sha256_hash'    => 'fakehash123',
            'image_path'     => 'receipts/fake.jpg',
            'vendor_name'    => 'Toko ATK',
            'total_amount'   => 50000,
            'claimed_amount' => 50000,
            'status'         => 'draft',
            'ocr_status'     => 'done',
        ]);

        $this->employee->update(['allow_receipt_claim' => false]);
        Sanctum::actingAs($this->employee, ['*']);

        $response = $this->postJson("/api/v1/employee/receipts/{$receipt->id}/submit", [], [
            'X-Platform' => 'mobile',
        ]);

        $response->assertStatus(403);
        $response->assertJsonFragment([
            'message' => 'Akses klaim struk dinonaktifkan untuk akun Anda. Silakan hubungi admin/HRD.',
        ]);
    }

    public function test_user_with_allow_receipt_claim_false_cannot_store_expense_report(): void
    {
        $this->employee->update(['allow_receipt_claim' => false]);
        Sanctum::actingAs($this->employee, ['*']);

        $response = $this->postJson('/api/v1/employee/expense-reports', [
            'title'   => 'Laporan Dinas Lapangan',
            'purpose' => 'Kunjungan Klien',
        ], [
            'X-Platform' => 'mobile',
        ]);

        $response->assertStatus(403);
        $response->assertJsonFragment([
            'message' => 'Akses klaim struk dinonaktifkan untuk akun Anda. Silakan hubungi admin/HRD.',
        ]);
    }

    public function test_admin_can_update_employee_allow_receipt_claim_and_limit(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        // 1. Nonaktifkan klaim struk
        $res1 = $this->putJson("/api/v1/admin/users/{$this->employee->id}", [
            'allow_receipt_claim' => false,
            'monthly_claim_limit' => null,
        ]);

        $res1->assertStatus(200);
        $this->employee->refresh();
        $this->assertFalse((bool) $this->employee->allow_receipt_claim);
        $this->assertNull($this->employee->monthly_claim_limit);

        // 2. Set ke Unlimited
        $res2 = $this->putJson("/api/v1/admin/users/{$this->employee->id}", [
            'allow_receipt_claim' => true,
            'monthly_claim_limit' => null,
        ]);

        $res2->assertStatus(200);
        $this->employee->refresh();
        $this->assertTrue((bool) $this->employee->allow_receipt_claim);
        $this->assertNull($this->employee->monthly_claim_limit);

        // 3. Set ke Batas Nominal Tertentu
        $res3 = $this->putJson("/api/v1/admin/users/{$this->employee->id}", [
            'allow_receipt_claim' => true,
            'monthly_claim_limit' => 3500000,
        ]);

        $res3->assertStatus(200);
        $this->employee->refresh();
        $this->assertTrue((bool) $this->employee->allow_receipt_claim);
        $this->assertEquals('3500000.00', (string) $this->employee->monthly_claim_limit);
    }

    public function test_auth_me_returns_allow_receipt_claim_status(): void
    {
        $this->employee->update(['allow_receipt_claim' => false]);
        Sanctum::actingAs($this->employee, ['*']);

        $response = $this->getJson('/api/v1/me');

        $response->assertStatus(200);
        $response->assertJsonPath('user.allow_receipt_claim', false);
    }
}
