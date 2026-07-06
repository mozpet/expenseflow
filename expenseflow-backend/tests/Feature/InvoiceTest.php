<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceApproval;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Vendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::create(['name' => 'PT Test', 'is_active' => true]);
        $this->vendor  = Vendor::create([
            'company_id' => $this->company->id,
            'name'       => 'PT Vendor Tes',
            'is_active'  => true,
        ]);
    }

    private function user(string $role): User
    {
        return User::factory()->create([
            'company_id' => $this->company->id,
            'role'       => $role,
            'is_active'  => true,
        ]);
    }

    private function token(User $u): array
    {
        return ['Authorization' => 'Bearer ' . $u->createToken('t')->plainTextToken];
    }

    /**
     * Buat payload invoice dengan total amount mendekati target.
     * total = unit_price * 1.11 (karena PPN 11%)
     */
    private function invoicePayload(float $unitPrice, string $invoiceNo = 'INV-TEST-001'): array
    {
        return [
            'vendor_id'      => $this->vendor->id,
            'invoice_number' => $invoiceNo,
            'invoice_date'   => now()->toDateString(),
            'due_date'       => now()->addDays(30)->toDateString(),
            'category'       => 'Operasional',
            'items'          => [
                [
                    'description' => 'Item tes',
                    'quantity'    => 1,
                    'unit_price'  => $unitPrice,
                ],
            ],
        ];
    }

    /**
     * Buat invoice langsung di DB dengan total tertentu (bypass PPN).
     */
    private function invoice(User $actor, float $total, array $extra = []): Invoice
    {
        return Invoice::create(array_merge([
            'company_id'             => $this->company->id,
            'vendor_id'              => $this->vendor->id,
            'user_id'                => $actor->id,
            'invoice_number'         => 'INV-' . fake()->unique()->numerify('######'),
            'subtotal'               => $total,
            'tax_amount'             => 0,
            'discount_amount'        => 0,
            'total_amount'           => $total,
            'invoice_date'           => now()->toDateString(),
            'due_date'               => now()->addDays(30)->toDateString(),
            'currency'               => 'IDR',
            'status'                 => 'pending',
            'max_approval_level'     => Invoice::determineApprovalLevel($total),
            'current_approval_level' => 0,
            'source'                 => 'manual',
            'category'               => 'Operasional',
        ], $extra));
    }

    // ── 1. Invoice < 10jt → max_approval_level = 1 ──────────────────
    public function test_invoice_kurang_dari_10jt_max_level_1(): void
    {
        $finance = $this->user('finance');

        // unit_price = 5_000_000 → subtotal = 5M, ppn = 550K, total = 5.55M < 10M
        $res = $this->postJson('/api/v1/dashboard/invoices',
            $this->invoicePayload(5_000_000),
            $this->token($finance)
        )->assertCreated()
         ->assertJsonPath('invoice.max_approval_level', 1);

        $this->assertDatabaseHas('invoices', [
            'invoice_number'     => 'INV-TEST-001',
            'max_approval_level' => 1,
        ]);
    }

    // ── 2. Invoice 10-50jt → max_approval_level = 2 ─────────────────
    public function test_invoice_10_sampai_50jt_max_level_2(): void
    {
        $finance = $this->user('finance');

        // unit_price = 20_000_000 → subtotal = 20M, ppn = 2.2M, total = 22.2M (10M < x ≤ 50M)
        $this->postJson('/api/v1/dashboard/invoices',
            $this->invoicePayload(20_000_000),
            $this->token($finance)
        )->assertCreated()
         ->assertJsonPath('invoice.max_approval_level', 2);
    }

    // ── 3. Invoice > 50jt → max_approval_level = 3 ──────────────────
    public function test_invoice_lebih_dari_50jt_max_level_3(): void
    {
        $finance = $this->user('finance');

        // unit_price = 50_000_000 → subtotal = 50M, ppn = 5.5M, total = 55.5M > 50M
        $this->postJson('/api/v1/dashboard/invoices',
            $this->invoicePayload(50_000_000),
            $this->token($finance)
        )->assertCreated()
         ->assertJsonPath('invoice.max_approval_level', 3);
    }

    // ── 4. Finance approve level 1 → status approved (final) ────────
    public function test_finance_approve_level_1_langsung_approved(): void
    {
        $finance = $this->user('finance');
        $inv     = $this->invoice($finance, 5_000_000); // max_level = 1

        $this->postJson("/api/v1/dashboard/invoices/{$inv->id}/approve",
            ['notes' => 'Setuju'],
            $this->token($finance)
        )
        ->assertOk()
        ->assertJsonPath('invoice.status', 'approved')
        ->assertJsonPath('invoice.is_final', true);

        $this->assertDatabaseHas('invoices', [
            'id'                     => $inv->id,
            'status'                 => 'approved',
            'current_approval_level' => 1,
        ]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'invoice_approved']);
    }

    // ── 5a. Finance approve level 0 dari invoice 3-level → masih pending ─
    public function test_finance_approve_level_0_invoice_3_level_masih_pending(): void
    {
        $finance = $this->user('finance');
        $inv     = $this->invoice($finance, 60_000_000); // max_level = 3

        $this->postJson("/api/v1/dashboard/invoices/{$inv->id}/approve",
            [], $this->token($finance)
        )->assertOk()
         ->assertJsonPath('invoice.status', 'pending')
         ->assertJsonPath('invoice.is_final', false);

        $this->assertDatabaseHas('invoices', [
            'id'                     => $inv->id,
            'status'                 => 'pending',
            'current_approval_level' => 1,
        ]);
    }

    // ── 5b. Admin approve level 1 dari invoice 3-level → masih pending ─
    //     Buat invoice langsung di level 1 (sudah lewat level 0)
    //     agar tidak bergantung pada chained request dengan user berbeda.
    public function test_admin_approve_level_1_invoice_3_level_masih_pending(): void
    {
        $finance = $this->user('finance');
        $admin   = $this->user('admin');

        // Invoice sudah di level 1 (finance sudah approve level 0)
        $inv = $this->invoice($finance, 60_000_000, ['current_approval_level' => 1]);
        InvoiceApproval::create([
            'invoice_id'     => $inv->id,
            'user_id'        => $finance->id,
            'status'         => 'approved',
            'approval_level' => 1,
            'reviewed_at'    => now(),
        ]);

        // Admin approve level 1 → current menjadi 2, masih pending (max=3)
        $this->postJson("/api/v1/dashboard/invoices/{$inv->id}/approve",
            [], $this->token($admin)
        )->assertOk()
         ->assertJsonPath('invoice.status', 'pending')
         ->assertJsonPath('invoice.is_final', false);

        $this->assertDatabaseHas('invoices', [
            'id'                     => $inv->id,
            'status'                 => 'pending',
            'current_approval_level' => 2,
        ]);
    }

    // ── 6. Direktur tidak bisa approve dua level sendiri (separation of duties) ─
    public function test_satu_user_tidak_bisa_approve_dua_level_invoice_sama(): void
    {
        $finance     = $this->user('finance');
        $superAdmin  = $this->user('super_admin');
        $inv         = $this->invoice($finance, 60_000_000); // max_level = 3

        // super_admin approve level 0 → 1
        $this->postJson("/api/v1/dashboard/invoices/{$inv->id}/approve",
            [], $this->token($superAdmin)
        )->assertOk();

        // super_admin coba approve lagi → ditolak (separation of duties)
        $this->postJson("/api/v1/dashboard/invoices/{$inv->id}/approve",
            [], $this->token($superAdmin)
        )->assertStatus(403);
    }

    // ── 7. Karyawan tidak bisa akses invoice ────────────────────────
    public function test_karyawan_tidak_bisa_akses_invoice(): void
    {
        $emp = $this->user('employee');

        $this->getJson('/api/v1/dashboard/invoices', $this->token($emp))
            ->assertStatus(403);
    }

    // ── Extra: reject invoice → status rejected, wajib reason ────────
    public function test_reject_invoice_wajib_rejection_reason(): void
    {
        $finance = $this->user('finance');
        $inv     = $this->invoice($finance, 5_000_000);

        // Tanpa reason → 422
        $this->postJson("/api/v1/dashboard/invoices/{$inv->id}/reject",
            [],
            $this->token($finance)
        )->assertStatus(422);

        // Dengan reason → OK
        $this->postJson("/api/v1/dashboard/invoices/{$inv->id}/reject",
            ['rejection_reason' => 'Dokumen tidak lengkap'],
            $this->token($finance)
        )->assertOk()
         ->assertJsonPath('invoice.status', 'rejected');

        $this->assertDatabaseHas('invoices', [
            'id'     => $inv->id,
            'status' => 'rejected',
        ]);
    }

    // ── Extra: invoice perusahaan lain tidak bisa diakses ────────────
    public function test_invoice_perusahaan_lain_tidak_bisa_diakses(): void
    {
        $other        = Company::create(['name' => 'PT Lain', 'is_active' => true]);
        $otherVendor  = Vendor::create(['company_id' => $other->id, 'name' => 'V Lain', 'is_active' => true]);
        $otherUser    = User::factory()->create(['company_id' => $other->id, 'role' => 'finance', 'is_active' => true]);
        $otherInvoice = Invoice::create([
            'company_id'             => $other->id,
            'vendor_id'              => $otherVendor->id,
            'user_id'                => $otherUser->id,
            'invoice_number'         => 'INV-LAIN-001',
            'subtotal'               => 1_000_000,
            'tax_amount'             => 110_000,
            'discount_amount'        => 0,
            'total_amount'           => 1_110_000,
            'invoice_date'           => now()->toDateString(),
            'due_date'               => now()->addDays(30)->toDateString(),
            'currency'               => 'IDR',
            'status'                 => 'pending',
            'max_approval_level'     => 1,
            'current_approval_level' => 0,
            'source'                 => 'manual',
            'category'               => 'Tes',
        ]);

        $finance = $this->user('finance'); // milik $this->company

        $this->getJson("/api/v1/dashboard/invoices/{$otherInvoice->id}", $this->token($finance))
            ->assertStatus(403);
    }
}
