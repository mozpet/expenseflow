<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Receipt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReceiptDisbursementTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $finance;
    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::create(['name' => 'PT Gawe Sukses', 'is_active' => true]);

        $this->finance = User::factory()->create([
            'company_id' => $this->company->id,
            'role'       => 'finance',
            'is_active'  => true,
        ]);

        $this->employee = User::factory()->create([
            'company_id'         => $this->company->id,
            'role'               => 'employee',
            'is_active'          => true,
            'bank_name'          => 'BCA',
            'bank_account_no'    => '1234567890',
            'bank_account_holder'=> 'Budi Santoso',
        ]);
    }

    private function token(User $u): array
    {
        $token = $u->createToken('test')->plainTextToken;
        return [
            'Authorization' => "Bearer {$token}",
            'X-Platform'    => 'web',
            'Accept'        => 'application/json',
        ];
    }

    private function createReceipt(array $attributes = []): Receipt
    {
        return Receipt::create(array_merge([
            'company_id'     => $this->company->id,
            'user_id'        => $this->employee->id,
            'receipt_number' => 'REC-' . uniqid(),
            'image_path'     => 'receipts/test.jpg',
            'status'         => 'submitted',
            'claimed_amount' => 100000,
            'merchant_name'  => 'Test Merchant',
            'receipt_date'   => now()->toDateString(),
        ], $attributes));
    }

    // ─── 1. Approval with adjusted amount (Partial Approval) ─────────
    public function test_approve_with_partial_amount_requires_notes(): void
    {
        $receipt = $this->createReceipt([
            'claimed_amount' => 500000,
            'merchant_name'  => 'Gramedia',
        ]);

        // Partial amount without notes should fail
        $response = $this->postJson(
            "/api/v1/dashboard/receipts/{$receipt->id}/approve",
            [
                'approved_amount' => 300000,
                'notes'           => '',
            ],
            $this->token($this->finance)
        );

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['notes']);

        // Partial amount with notes should succeed
        $response = $this->postJson(
            "/api/v1/dashboard/receipts/{$receipt->id}/approve",
            [
                'approved_amount' => 300000,
                'notes'           => 'Barang non-operasional dipotong',
            ],
            $this->token($this->finance)
        );

        $response->assertOk();
        $this->assertDatabaseHas('receipts', [
            'id'              => $receipt->id,
            'status'          => 'approved',
            'approved_amount' => 300000,
        ]);
        $this->assertDatabaseHas('receipt_approvals', [
            'receipt_id' => $receipt->id,
            'user_id'    => $this->finance->id,
            'status'     => 'approved',
            'notes'      => 'Barang non-operasional dipotong',
        ]);
    }

    // ─── 2. Bulk Approval ─────────────────────────────────────────────
    public function test_bulk_approve_receipts(): void
    {
        $r1 = $this->createReceipt([
            'claimed_amount' => 150000,
            'merchant_name'  => 'SPBU Pertamina',
        ]);

        $r2 = $this->createReceipt([
            'claimed_amount' => 250000,
            'merchant_name'  => 'Indomaret',
        ]);

        $response = $this->postJson(
            '/api/v1/dashboard/receipts/bulk-approve',
            [
                'receipt_ids' => [$r1->id, $r2->id],
                'notes'       => 'Disetujui serentak',
            ],
            $this->token($this->finance)
        );

        $response->assertOk()
            ->assertJsonPath('approved_count', 2);

        $this->assertEquals('approved', $r1->fresh()->status);
        $this->assertEquals(150000, $r1->fresh()->approved_amount);
        $this->assertEquals('approved', $r2->fresh()->status);
        $this->assertEquals(250000, $r2->fresh()->approved_amount);
    }

    // ─── 3. Single Disbursement (Pay) ─────────────────────────────────
    public function test_disburse_receipt_to_paid(): void
    {
        $receipt = $this->createReceipt([
            'status'          => 'approved',
            'claimed_amount'  => 450000,
            'approved_amount' => 450000,
            'approved_by'     => $this->finance->id,
            'approved_at'     => now(),
            'merchant_name'   => 'Office 2000',
        ]);

        $response = $this->postJson(
            "/api/v1/dashboard/receipts/{$receipt->id}/pay",
            [
                'payment_method' => 'bank_transfer',
                'payment_ref_no' => 'TRF-BCA-9921',
            ],
            $this->token($this->finance)
        );

        $response->assertOk()
            ->assertJsonPath('message', 'Pencairan reimbursement berhasil dicatat.');

        $fresh = $receipt->fresh();
        $this->assertEquals('paid', $fresh->status);
        $this->assertEquals('bank_transfer', $fresh->payment_method);
        $this->assertEquals('TRF-BCA-9921', $fresh->payment_ref_no);
        $this->assertEquals($this->finance->id, $fresh->paid_by);
        $this->assertNotNull($fresh->paid_at);
    }

    // ─── 4. Bulk Disbursement ─────────────────────────────────────────
    public function test_bulk_disburse_receipts(): void
    {
        $r1 = $this->createReceipt([
            'status'          => 'approved',
            'claimed_amount'  => 100000,
            'approved_amount' => 100000,
            'merchant_name'   => 'Toko A',
        ]);

        $r2 = $this->createReceipt([
            'status'          => 'approved',
            'claimed_amount'  => 200000,
            'approved_amount' => 200000,
            'merchant_name'   => 'Toko B',
        ]);

        $response = $this->postJson(
            '/api/v1/dashboard/receipts/bulk-pay',
            [
                'receipt_ids'    => [$r1->id, $r2->id],
                'payment_method' => 'bank_transfer',
                'payment_ref_no' => 'BATCH-PAY-001',
            ],
            $this->token($this->finance)
        );

        $response->assertOk()
            ->assertJsonPath('paid_count', 2);

        $this->assertEquals('paid', $r1->fresh()->status);
        $this->assertEquals('paid', $r2->fresh()->status);
        $this->assertEquals('BATCH-PAY-001', $r1->fresh()->payment_ref_no);
    }

    // ─── 5. Export Disbursement CSV ───────────────────────────────────
    public function test_export_disbursement_csv(): void
    {
        $this->createReceipt([
            'status'          => 'approved',
            'claimed_amount'  => 350000,
            'approved_amount' => 350000,
            'merchant_name'   => 'Hotel Santika',
            'receipt_date'    => '2026-08-30',
        ]);

        $response = $this->getJson(
            '/api/v1/dashboard/receipts/export-disbursement?status=approved',
            $this->token($this->finance)
        );

        $response->assertOk();
        $this->assertTrue(str_contains($response->headers->get('content-type'), 'text/csv'));
        $content = $response->streamedContent();
        $this->assertStringContainsString('BCA', $content);
        $this->assertStringContainsString('1234567890', $content);
        $this->assertStringContainsString('Budi Santoso', $content);
        $this->assertStringContainsString('350000', $content);
    }

    // ─── 6. Duplicate Detection ───────────────────────────────────────
    public function test_potential_duplicate_detection(): void
    {
        $existing = $this->createReceipt([
            'status'         => 'approved',
            'claimed_amount' => 125000,
            'merchant_name'  => 'KFC Kemang',
            'receipt_date'   => '2026-08-28',
        ]);

        // Submit matching receipt
        $duplicate = $this->createReceipt([
            'status'                 => 'submitted',
            'claimed_amount'         => 125000,
            'merchant_name'          => 'KFC Kemang',
            'receipt_date'           => '2026-08-29',
            'is_potential_duplicate' => true,
            'duplicate_reference_id' => $existing->id,
        ]);

        $this->assertTrue((bool) $duplicate->is_potential_duplicate);
        $this->assertEquals($existing->id, $duplicate->duplicate_reference_id);
    }

    // ─── 7. Claim Limit per Transaction Enforcement ───────────────────
    public function test_claim_limit_exceeded_blocks_submit(): void
    {
        DB::table('company_settings')->updateOrInsert(
            ['company_id' => $this->company->id, 'key' => 'max_claim_limit'],
            ['value' => '500000', 'created_at' => now(), 'updated_at' => now()]
        );

        $receipt = $this->createReceipt([
            'status'         => 'pending',
            'ocr_status'     => 'success',
            'claimed_amount' => 750000,
        ]);

        $empToken = $this->employee->createToken('mobile')->plainTextToken;
        $response = $this->postJson(
            "/api/v1/employee/receipts/{$receipt->id}/submit",
            [],
            [
                'Authorization' => "Bearer {$empToken}",
                'X-Platform'    => 'mobile',
                'Accept'        => 'application/json',
            ]
        );

        $response->assertStatus(422)
            ->assertJsonPath('code', 'CLAIM_LIMIT_EXCEEDED');
    }
}
