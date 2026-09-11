<?php

namespace Tests\Feature;

use App\Models\AttendanceSetting;
use App\Models\Company;
use App\Models\ExpenseReport;
use App\Models\Receipt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ExpenseReportTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $employee;
    private User $finance;
    private AttendanceSetting $office;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->company = Company::create(['name' => 'PT Bundling Expense', 'is_active' => true]);

        $this->office = AttendanceSetting::create([
            'company_id'       => $this->company->id,
            'office_name'      => 'Kantor Cabang Bandung',
            'office_latitude'  => -6.9175,
            'office_longitude' => 107.6191,
            'radius_meters'    => 100,
            'work_start_time'  => '08:00',
            'work_end_time'    => '17:00',
        ]);

        $this->employee = User::factory()->create([
            'company_id'            => $this->company->id,
            'attendance_setting_id' => $this->office->id,
            'role'                  => 'employee',
            'is_active'             => true,
        ]);

        $this->finance = User::factory()->create([
            'company_id'            => $this->company->id,
            'attendance_setting_id' => $this->office->id,
            'role'                  => 'finance',
            'is_active'             => true,
        ]);
    }

    private function createReceipt(string $number, float $amount, string $status = 'draft', string $ocrStatus = 'done'): Receipt
    {
        return Receipt::create([
            'company_id'            => $this->company->id,
            'user_id'               => $this->employee->id,
            'attendance_setting_id' => $this->office->id,
            'receipt_number'        => $number,
            'sha256_hash'           => hash('sha256', $number . microtime()),
            'image_path'            => 'receipts/test.jpg',
            'currency'              => 'IDR',
            'status'                => $status,
            'ocr_status'            => $ocrStatus,
            'vendor_name'           => 'Merchant ' . $number,
            'total_amount'          => $amount,
            'claimed_amount'        => $amount,
            'category'              => 'Transportasi',
        ]);
    }

    public function test_employee_can_create_expense_report_draft(): void
    {
        $r1 = $this->createReceipt('RCP-001', 150000);
        $r2 = $this->createReceipt('RCP-002', 350000);

        $response = $this->actingAs($this->employee)
            ->postJson('/api/v1/employee/expense-reports', [
                'title'       => 'Dinas Luar Kota Bandung 3 Hari',
                'description' => 'Instalasi jaringan klien',
                'start_date'  => '2026-09-15',
                'end_date'    => '2026-09-17',
                'receipt_ids' => [$r1->id, $r2->id],
            ]);

        $response->assertStatus(201);
        $reportId = $response->json('report.id');

        $this->assertDatabaseHas('expense_reports', [
            'id'                   => $reportId,
            'user_id'              => $this->employee->id,
            'title'                => 'Dinas Luar Kota Bandung 3 Hari',
            'status'               => 'draft',
            'total_claimed_amount' => 500000,
        ]);

        $this->assertDatabaseHas('receipts', [
            'id'                => $r1->id,
            'expense_report_id' => $reportId,
        ]);
    }

    public function test_employee_can_add_and_remove_receipts_from_report(): void
    {
        $report = ExpenseReport::create([
            'company_id'    => $this->company->id,
            'user_id'       => $this->employee->id,
            'report_number' => 'EXP-20260911-0001',
            'title'         => 'Laporan Dinas',
            'status'        => 'draft',
        ]);

        $r1 = $this->createReceipt('RCP-001', 200000);
        $r2 = $this->createReceipt('RCP-002', 300000);

        // Tambah struk
        $addRes = $this->actingAs($this->employee)
            ->postJson("/api/v1/employee/expense-reports/{$report->id}/receipts", [
                'receipt_ids' => [$r1->id, $r2->id],
            ]);

        $addRes->assertStatus(200);
        $this->assertEquals(500000, $report->fresh()->total_claimed_amount);

        // Lepas r1
        $removeRes = $this->actingAs($this->employee)
            ->deleteJson("/api/v1/employee/expense-reports/{$report->id}/receipts/{$r1->id}");

        $removeRes->assertStatus(200);
        $this->assertNull($r1->fresh()->expense_report_id);
        $this->assertEquals(300000, $report->fresh()->total_claimed_amount);
    }

    public function test_employee_can_submit_expense_report(): void
    {
        $r1 = $this->createReceipt('RCP-001', 200000, 'draft', 'done');
        $r2 = $this->createReceipt('RCP-002', 300000, 'draft', 'done');

        $report = ExpenseReport::create([
            'company_id'            => $this->company->id,
            'user_id'               => $this->employee->id,
            'attendance_setting_id' => $this->office->id,
            'report_number'         => 'EXP-20260911-0002',
            'title'                 => 'Kunjungan Lapangan',
            'status'                => 'draft',
        ]);

        $r1->update(['expense_report_id' => $report->id]);
        $r2->update(['expense_report_id' => $report->id]);
        $report->recalculateTotals();

        $response = $this->actingAs($this->employee)
            ->postJson("/api/v1/employee/expense-reports/{$report->id}/submit");

        $response->assertStatus(200);

        $this->assertEquals('submitted', $report->fresh()->status);
        $this->assertEquals('submitted', $r1->fresh()->status);
        $this->assertEquals('submitted', $r2->fresh()->status);
    }

    public function test_finance_can_view_and_approve_expense_report_bundle(): void
    {
        $r1 = $this->createReceipt('RCP-001', 200000, 'submitted', 'done');
        $r2 = $this->createReceipt('RCP-002', 300000, 'submitted', 'done');

        $report = ExpenseReport::create([
            'company_id'            => $this->company->id,
            'user_id'               => $this->employee->id,
            'attendance_setting_id' => $this->office->id,
            'report_number'         => 'EXP-20260911-0003',
            'title'                 => 'Dinas Training Bandung',
            'status'                => 'submitted',
            'total_claimed_amount'  => 500000,
            'submitted_at'          => now(),
        ]);

        $r1->update(['expense_report_id' => $report->id]);
        $r2->update(['expense_report_id' => $report->id]);

        // Finance list reports
        $listRes = $this->actingAs($this->finance)
            ->getJson('/api/v1/dashboard/expense-reports');

        $listRes->assertStatus(200);
        $this->assertEquals(1, $listRes->json('total'));

        // Finance approve bundle
        $approveRes = $this->actingAs($this->finance)
            ->postJson("/api/v1/dashboard/expense-reports/{$report->id}/approve");

        $approveRes->assertStatus(200);

        $this->assertEquals('approved', $report->fresh()->status);
        $this->assertEquals(500000, $report->fresh()->total_approved_amount);
        $this->assertEquals('approved', $r1->fresh()->status);
        $this->assertEquals('approved', $r2->fresh()->status);
    }

    public function test_finance_can_reject_expense_report_bundle(): void
    {
        $r1 = $this->createReceipt('RCP-001', 200000, 'submitted', 'done');

        $report = ExpenseReport::create([
            'company_id'            => $this->company->id,
            'user_id'               => $this->employee->id,
            'attendance_setting_id' => $this->office->id,
            'report_number'         => 'EXP-20260911-0004',
            'title'                 => 'Dinas Tanpa Surat Tugas',
            'status'                => 'submitted',
            'total_claimed_amount'  => 200000,
            'submitted_at'          => now(),
        ]);

        $r1->update(['expense_report_id' => $report->id]);

        $rejectRes = $this->actingAs($this->finance)
            ->postJson("/api/v1/dashboard/expense-reports/{$report->id}/reject", [
                'notes' => 'Surat tugas dinas belum dilampirkan.',
            ]);

        $rejectRes->assertStatus(200);

        $this->assertEquals('rejected', $report->fresh()->status);
        $this->assertEquals('rejected', $r1->fresh()->status);
        $this->assertEquals('Surat tugas dinas belum dilampirkan.', $report->fresh()->rejection_reason);
    }

    public function test_submit_blocks_report_with_blurry_or_failed_ocr_receipt(): void
    {
        $r1 = Receipt::create([
            'company_id'            => $this->company->id,
            'user_id'               => $this->employee->id,
            'attendance_setting_id' => $this->office->id,
            'receipt_number'        => 'RCP-BLUR-01',
            'sha256_hash'           => hash('sha256', 'blur' . microtime()),
            'image_path'            => 'receipts/test.jpg',
            'currency'              => 'IDR',
            'status'                => 'draft',
            'ocr_status'            => 'failed',
            'ocr_error'             => 'Foto struk buram dan tidak terbaca jelas',
            'vendor_name'           => 'Merchant Buram',
            'total_amount'          => null,
            'claimed_amount'        => null,
            'category'              => 'Transportasi',
        ]);

        $report = ExpenseReport::create([
            'company_id'            => $this->company->id,
            'user_id'               => $this->employee->id,
            'attendance_setting_id' => $this->office->id,
            'report_number'         => 'EXP-BLUR-001',
            'title'                 => 'Laporan Foto Buram',
            'status'                => 'draft',
        ]);

        $r1->update(['expense_report_id' => $report->id]);
        $report->recalculateTotals();

        $submitRes = $this->actingAs($this->employee)
            ->postJson("/api/v1/employee/expense-reports/{$report->id}/submit");

        $submitRes->assertStatus(422);
        $submitRes->assertJsonFragment(['code' => 'RECEIPT_IMAGE_BLURRY']);
    }

    public function test_submit_blocks_report_exceeding_branch_max_claim_limit(): void
    {
        // Pasang batas cabang Rp 400.000
        $this->office->update(['max_claim_limit' => 400000]);

        $r1 = $this->createReceipt('RCP-EXP-01', 500000, 'draft', 'done');

        $report = ExpenseReport::create([
            'company_id'            => $this->company->id,
            'user_id'               => $this->employee->id,
            'attendance_setting_id' => $this->office->id,
            'report_number'         => 'EXP-BRANCH-LIMIT-001',
            'title'                 => 'Laporan Melebihi Batas Cabang',
            'status'                => 'draft',
        ]);

        $r1->update(['expense_report_id' => $report->id]);
        $report->recalculateTotals();

        $submitRes = $this->actingAs($this->employee)
            ->postJson("/api/v1/employee/expense-reports/{$report->id}/submit");

        $submitRes->assertStatus(422);
        $submitRes->assertJsonFragment(['code' => 'CLAIM_LIMIT_EXCEEDED']);
    }

    public function test_submit_blocks_report_exceeding_monthly_limit(): void
    {
        // Pasang batas bulanan user Rp 1.000.000
        $this->employee->update(['monthly_claim_limit' => 1000000]);

        // Pengeluaran bulan ini yang sudah disetujui: Rp 800.000
        $this->createReceipt('RCP-EXISTING-01', 800000, 'approved', 'done');

        // Pengajuan baru Rp 300.000 (total = 1.100.000 > 1.000.000)
        $r1 = $this->createReceipt('RCP-OVER-01', 300000, 'draft', 'done');

        $report = ExpenseReport::create([
            'company_id'            => $this->company->id,
            'user_id'               => $this->employee->id,
            'attendance_setting_id' => $this->office->id,
            'report_number'         => 'EXP-MONTHLY-LIMIT-001',
            'title'                 => 'Laporan Melebihi Plafon Bulanan',
            'status'                => 'draft',
        ]);

        $r1->update(['expense_report_id' => $report->id]);
        $report->recalculateTotals();

        $submitRes = $this->actingAs($this->employee)
            ->postJson("/api/v1/employee/expense-reports/{$report->id}/submit");

        $submitRes->assertStatus(422);
        $submitRes->assertJsonFragment(['code' => 'MONTHLY_LIMIT_EXCEEDED']);
    }

    public function test_individual_receipt_approval_syncs_parent_expense_report_totals(): void
    {
        $r1 = $this->createReceipt('RCP-SYNC-01', 200000, 'submitted', 'done');
        $r2 = $this->createReceipt('RCP-SYNC-02', 300000, 'submitted', 'done');

        $report = ExpenseReport::create([
            'company_id'            => $this->company->id,
            'user_id'               => $this->employee->id,
            'attendance_setting_id' => $this->office->id,
            'report_number'         => 'EXP-SYNC-001',
            'title'                 => 'Laporan Sinkronisasi Total',
            'status'                => 'submitted',
            'total_claimed_amount'  => 500000,
            'submitted_at'          => now(),
        ]);

        $r1->update(['expense_report_id' => $report->id]);
        $r2->update(['expense_report_id' => $report->id]);

        // Finance menyetujui struk 1 via receipt controller
        $res1 = $this->actingAs($this->finance)
            ->postJson("/api/v1/dashboard/receipts/{$r1->id}/approve", [
                'catatan' => 'Disetujui struk 1',
            ]);
        $res1->assertStatus(200);

        // Parent report total_approved_amount harus otomatis tersinkronisasi menjadi 200.000
        $report->refresh();
        $this->assertEquals(200000, $report->total_approved_amount);
        $this->assertEquals('submitted', $report->status);

        // Finance menyetujui struk 2
        $res2 = $this->actingAs($this->finance)
            ->postJson("/api/v1/dashboard/receipts/{$r2->id}/approve", [
                'catatan' => 'Disetujui struk 2',
            ]);
        $res2->assertStatus(200);

        // Parent report total_approved_amount harus 500.000 dan status otomatis approved!
        $report->refresh();
        $this->assertEquals(500000, $report->total_approved_amount);
        $this->assertEquals('approved', $report->status);
    }

    public function test_reassigning_receipt_between_draft_reports_recalculates_both_totals(): void
    {
        $r1 = $this->createReceipt('RCP-MOV-01', 200000);
        $r2 = $this->createReceipt('RCP-MOV-02', 300000);

        $reportA = ExpenseReport::create([
            'company_id'    => $this->company->id,
            'user_id'       => $this->employee->id,
            'report_number' => 'EXP-MOV-A',
            'title'         => 'Laporan A',
            'status'        => 'draft',
        ]);

        $reportB = ExpenseReport::create([
            'company_id'    => $this->company->id,
            'user_id'       => $this->employee->id,
            'report_number' => 'EXP-MOV-B',
            'title'         => 'Laporan B',
            'status'        => 'draft',
        ]);

        // Awal: r1 di report A, r2 di report B
        $r1->update(['expense_report_id' => $reportA->id]);
        $reportA->recalculateTotals();

        $r2->update(['expense_report_id' => $reportB->id]);
        $reportB->recalculateTotals();

        $this->assertEquals(200000, $reportA->fresh()->total_claimed_amount);
        $this->assertEquals(300000, $reportB->fresh()->total_claimed_amount);

        // Pindahkan r1 ke report B lewat endpoint addReceipts
        $res = $this->actingAs($this->employee)
            ->postJson("/api/v1/employee/expense-reports/{$reportB->id}/receipts", [
                'receipt_ids' => [$r1->id],
            ]);

        $res->assertStatus(200);

        // Report A harus berkurang jadi 0, Report B bertambah jadi 500.000
        $this->assertEquals(0, $reportA->fresh()->total_claimed_amount);
        $this->assertEquals(500000, $reportB->fresh()->total_claimed_amount);
        $this->assertEquals($reportB->id, $r1->fresh()->expense_report_id);
    }

    public function test_finance_can_approve_partial_receipts_in_bundle(): void
    {
        $r1 = $this->createReceipt('RCP-PARTIAL-01', 100000, 'submitted', 'done');
        $r2 = $this->createReceipt('RCP-PARTIAL-02', 200000, 'submitted', 'done');
        $r3 = $this->createReceipt('RCP-PARTIAL-03', 300000, 'submitted', 'done');

        $report = ExpenseReport::create([
            'company_id'            => $this->company->id,
            'user_id'               => $this->employee->id,
            'attendance_setting_id' => $this->office->id,
            'report_number'         => 'EXP-PARTIAL-001',
            'title'                 => 'Laporan Dinas Uji Parsial',
            'status'                => 'submitted',
            'total_claimed_amount'  => 600000,
            'submitted_at'          => now(),
        ]);

        $r1->update(['expense_report_id' => $report->id]);
        $r2->update(['expense_report_id' => $report->id]);
        $r3->update(['expense_report_id' => $report->id]);

        // Finance hanya memilih menyetujui r1 dan r2 (r3 ditolak karena tidak dicentang)
        $res = $this->actingAs($this->finance)
            ->postJson("/api/v1/dashboard/expense-reports/{$report->id}/approve", [
                'notes'                => 'Disetujui 2 struk, 1 struk ditolak',
                'approved_receipt_ids' => [$r1->id, $r2->id],
            ]);

        $res->assertStatus(200);

        // Periksa status struk individual
        $this->assertEquals('approved', $r1->fresh()->status);
        $this->assertEquals(100000, $r1->fresh()->approved_amount);

        $this->assertEquals('approved', $r2->fresh()->status);
        $this->assertEquals(200000, $r2->fresh()->approved_amount);

        $this->assertEquals('rejected', $r3->fresh()->status);
        $this->assertEquals(0, $r3->fresh()->approved_amount);

        // Periksa status parent ExpenseReport
        $report->refresh();
        $this->assertEquals('approved', $report->status);
        $this->assertEquals(300000, $report->total_approved_amount);
        $this->assertEquals(600000, $report->total_claimed_amount);
    }

    public function test_dashboard_index_includes_variance_and_duplicate_fields(): void
    {
        $refReceipt = $this->createReceipt('RCP-REF-01', 50000, 'approved', 'done');

        $dupReceipt = $this->createReceipt('RCP-DUP-01', 55000, 'submitted', 'done');
        $dupReceipt->update([
            'ocr_raw_amount'         => 50000,
            'claimed_amount'         => 55000,
            'variance_flag'          => true,
            'variance_pct'           => 10.0,
            'is_potential_duplicate' => true,
            'duplicate_reference_id' => $refReceipt->id,
            'duplicate_reason'       => 'Duplikat dengan struk ref',
        ]);

        $report = ExpenseReport::create([
            'company_id'            => $this->company->id,
            'user_id'               => $this->employee->id,
            'attendance_setting_id' => $this->office->id,
            'report_number'         => 'EXP-DUP-001',
            'title'                 => 'Laporan Dinas Uji Duplicate & Variance',
            'status'                => 'submitted',
            'total_claimed_amount'  => 55000,
            'submitted_at'          => now(),
        ]);

        $dupReceipt->update(['expense_report_id' => $report->id]);

        $res = $this->actingAs($this->finance)
            ->getJson('/api/v1/dashboard/expense-reports');

        $res->assertStatus(200);
        $data = $res->json('data.0.receipts.0');

        $this->assertNotNull($data);
        $this->assertTrue($data['is_potential_duplicate']);
        $this->assertTrue((bool) $data['variance_flag']);
        $this->assertEquals(10.0, (float) $data['variance_pct']);
        $this->assertEquals($refReceipt->id, $data['duplicate_reference_id']);
        $this->assertEquals('RCP-REF-01', $data['duplicate_reference']['receipt_number']);
    }
}

