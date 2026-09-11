<?php

namespace Tests\Feature;

use App\Models\AttendanceSetting;
use App\Models\Company;
use App\Models\Receipt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReceiptBranchFilterTest extends TestCase
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

        $this->officeJakarta = AttendanceSetting::create([
            'company_id'       => $this->company->id,
            'office_name'      => 'Cabang Jakarta',
            'office_latitude'  => -6.2088,
            'office_longitude' => 106.8456,
            'radius_meters'    => 100,
            'work_start_time'  => '08:00',
            'work_end_time'    => '17:00',
        ]);

        $this->officeSurabaya = AttendanceSetting::create([
            'company_id'       => $this->company->id,
            'office_name'      => 'Cabang Surabaya',
            'office_latitude'  => -7.2575,
            'office_longitude' => 112.7521,
            'radius_meters'    => 100,
            'work_start_time'  => '08:00',
            'work_end_time'    => '17:00',
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
            'bank_name'             => 'BCA',
            'bank_account_no'       => '1111222233',
            'bank_account_holder'   => 'Karyawan Jakarta',
        ]);

        $this->employeeSurabaya = User::factory()->create([
            'company_id'            => $this->company->id,
            'role'                  => 'employee',
            'is_active'             => true,
            'attendance_setting_id' => $this->officeSurabaya->id,
            'bank_name'             => 'Mandiri',
            'bank_account_no'       => '4444555566',
            'bank_account_holder'   => 'Karyawan Surabaya',
        ]);
    }

    private function token(User $u, string $platform = 'web'): array
    {
        $token = $u->createToken('test')->plainTextToken;
        return [
            'Authorization' => "Bearer {$token}",
            'X-Platform'    => $platform,
            'Accept'        => 'application/json',
        ];
    }

    private function createReceipt(array $attributes = []): Receipt
    {
        return Receipt::create(array_merge([
            'company_id'            => $this->company->id,
            'user_id'               => $this->employeeJakarta->id,
            'attendance_setting_id' => $this->officeJakarta->id,
            'receipt_number'        => 'REC-' . uniqid(),
            'image_path'            => 'receipts/test.jpg',
            'status'                => 'submitted',
            'claimed_amount'        => 150000,
            'category'              => 'Transport',
            'merchant_name'         => 'Test Merchant',
            'receipt_date'          => now()->toDateString(),
        ], $attributes));
    }

    public function test_finance_can_read_attendance_settings_list(): void
    {
        $response = $this->getJson('/api/v1/dashboard/attendance/settings', $this->token($this->finance));

        $response->assertStatus(200)
            ->assertJsonStructure(['settings'])
            ->assertJsonFragment(['office_name' => 'Cabang Jakarta'])
            ->assertJsonFragment(['office_name' => 'Cabang Surabaya']);
    }

    public function test_inbox_can_filter_by_branch(): void
    {
        $receiptJkt = $this->createReceipt([
            'user_id'               => $this->employeeJakarta->id,
            'attendance_setting_id' => $this->officeJakarta->id,
            'merchant_name'         => 'Toko Jakarta',
        ]);

        $receiptSby = $this->createReceipt([
            'user_id'               => $this->employeeSurabaya->id,
            'attendance_setting_id' => $this->officeSurabaya->id,
            'merchant_name'         => 'Toko Surabaya',
        ]);

        // 1. Tanpa filter cabang: menampilkan semua struk
        $resAll = $this->getJson('/api/v1/dashboard/receipts', $this->token($this->finance));
        $resAll->assertStatus(200);
        $idsAll = collect($resAll->json('data'))->pluck('id')->all();
        $this->assertContains($receiptJkt->id, $idsAll);
        $this->assertContains($receiptSby->id, $idsAll);

        // 2. Filter cabang Jakarta
        $resJkt = $this->getJson("/api/v1/dashboard/receipts?attendance_setting_id={$this->officeJakarta->id}", $this->token($this->finance));
        $resJkt->assertStatus(200);
        $idsJkt = collect($resJkt->json('data'))->pluck('id')->all();
        $this->assertContains($receiptJkt->id, $idsJkt);
        $this->assertNotContains($receiptSby->id, $idsJkt);

        // Pastikan relasi office dan nama cabang ter-eager load
        $firstItem = collect($resJkt->json('data'))->firstWhere('id', $receiptJkt->id);
        $this->assertEquals('Cabang Jakarta', $firstItem['office']['office_name'] ?? null);

        // 3. Filter cabang Surabaya
        $resSby = $this->getJson("/api/v1/dashboard/receipts?attendance_setting_id={$this->officeSurabaya->id}", $this->token($this->finance));
        $resSby->assertStatus(200);
        $idsSby = collect($resSby->json('data'))->pluck('id')->all();
        $this->assertNotContains($receiptJkt->id, $idsSby);
        $this->assertContains($receiptSby->id, $idsSby);
    }

    public function test_dashboard_receipts_can_filter_by_branch_and_updates_summary(): void
    {
        $this->createReceipt([
            'user_id'               => $this->employeeJakarta->id,
            'attendance_setting_id' => $this->officeJakarta->id,
            'status'                => 'approved',
        ]);

        $this->createReceipt([
            'user_id'               => $this->employeeSurabaya->id,
            'attendance_setting_id' => $this->officeSurabaya->id,
            'status'                => 'submitted',
        ]);

        // Filter Jakarta
        $res = $this->getJson("/api/v1/dashboard/receipts/all?attendance_setting_id={$this->officeJakarta->id}", $this->token($this->finance));
        $res->assertStatus(200);
        $this->assertEquals(1, $res->json('summary.approved'));
        $this->assertEquals(0, $res->json('summary.submitted'));

        // Filter Surabaya
        $resSby = $this->getJson("/api/v1/dashboard/receipts/all?attendance_setting_id={$this->officeSurabaya->id}", $this->token($this->finance));
        $resSby->assertStatus(200);
        $this->assertEquals(0, $resSby->json('summary.approved'));
        $this->assertEquals(1, $resSby->json('summary.submitted'));
    }

    public function test_export_disbursement_can_filter_by_branch_and_includes_branch_column(): void
    {
        $receiptJkt = $this->createReceipt([
            'user_id'               => $this->employeeJakarta->id,
            'attendance_setting_id' => $this->officeJakarta->id,
            'status'                => 'approved',
            'receipt_number'        => 'REC-JKT-001',
            'claimed_amount'        => 200000,
        ]);

        $receiptSby = $this->createReceipt([
            'user_id'               => $this->employeeSurabaya->id,
            'attendance_setting_id' => $this->officeSurabaya->id,
            'status'                => 'approved',
            'receipt_number'        => 'REC-SBY-002',
            'claimed_amount'        => 300000,
        ]);

        $response = $this->get(
            "/api/v1/dashboard/receipts/export-disbursement?status=approved&attendance_setting_id={$this->officeJakarta->id}",
            $this->token($this->finance)
        );

        $response->assertStatus(200);
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $csvContent = $response->streamedContent();
        $this->assertStringContainsString('Cabang Kantor', $csvContent);
        $this->assertStringContainsString('REC-JKT-001', $csvContent);
        $this->assertStringContainsString('Cabang Jakarta', $csvContent);
        $this->assertStringNotContainsString('REC-SBY-002', $csvContent);
        $this->assertStringNotContainsString('Cabang Surabaya', $csvContent);
    }

    public function test_store_receipt_snapshots_user_branch(): void
    {
        $file = UploadedFile::fake()->image('receipt.jpg');

        $response = $this->postJson('/api/v1/employee/receipts', [
            'image'          => $file,
            'claimed_amount' => 75000,
            'category'       => 'Transport',
            'merchant_name'  => 'Indomaret Jakarta',
            'receipt_date'   => now()->toDateString(),
        ], $this->token($this->employeeJakarta, 'mobile'));

        $response->assertStatus(201);
        $receiptId = $response->json('receipt.id');

        $this->assertDatabaseHas('receipts', [
            'id'                    => $receiptId,
            'user_id'               => $this->employeeJakarta->id,
            'attendance_setting_id' => $this->officeJakarta->id,
        ]);
    }
}
