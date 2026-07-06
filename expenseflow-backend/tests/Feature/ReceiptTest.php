<?php

namespace Tests\Feature;

use App\Jobs\ProcessOcrJob;
use App\Models\Company;
use App\Models\Receipt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReceiptTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::create(['name' => 'PT Test', 'is_active' => true]);
        Storage::fake('local');
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

    private function receipt(User $emp, array $extra = []): Receipt
    {
        $r = Receipt::create(array_merge([
            'company_id'     => $this->company->id,
            'user_id'        => $emp->id,
            'receipt_number' => 'RCP-' . fake()->unique()->numerify('########'),
            'sha256_hash'    => hash('sha256', 'test'),
            'image_path'     => 'receipts/test.jpg',
            'currency'       => 'IDR',
            'status'         => 'draft',
            'ocr_status'     => 'pending',
            'category'       => 'Makan',
        ], $extra));

        return $r;
    }

    // ── 1. Upload foto → OCR job terdispatch ────────────────────────
    public function test_upload_foto_mendispatch_ocr_job(): void
    {
        Queue::fake();
        $emp = $this->user('employee');

        $this->postJson('/api/v1/employee/receipts', [
            'image'    => UploadedFile::fake()->image('struk.jpg'),
            'category' => 'Makan',
            'notes'    => 'Makan siang tim',
        ], $this->token($emp))
        ->assertCreated()
        ->assertJsonPath('receipt.status', 'draft')
        ->assertJsonPath('receipt.ocr_status', 'pending');

        Queue::assertPushed(ProcessOcrJob::class);
        $this->assertDatabaseHas('activity_logs', ['action' => 'receipt_uploaded']);
    }

    // ── 2. OCR raw tidak bisa diubah via updateClaim ─────────────────
    public function test_ocr_raw_amount_immutable_lewat_update_claim(): void
    {
        $emp     = $this->user('employee');
        $receipt = $this->receipt($emp);

        // Simulasi OCR sudah mengisi raw data — set langsung (bukan via endpoint)
        $receipt->ocr_raw_amount = 100000;
        $receipt->saveQuietly();

        // Karyawan coba kirim ocr_raw_amount berbeda via updateClaim
        $this->patchJson("/api/v1/employee/receipts/{$receipt->id}/claim", [
            'category'       => 'Transport',
            'ocr_raw_amount' => 999999, // field ini harus diabaikan
        ], $this->token($emp))
        ->assertOk();

        // DB harus tetap menyimpan nilai OCR asli
        $this->assertDatabaseHas('receipts', [
            'id'             => $receipt->id,
            'ocr_raw_amount' => 100000,
            'category'       => 'Transport',
        ]);
    }

    // ── 3. Variance flag otomatis jika selisih > 10% ─────────────────
    public function test_variance_flag_aktif_jika_selisih_lebih_dari_10_persen(): void
    {
        $emp     = $this->user('employee');
        $receipt = $this->receipt($emp);

        // Claimed 20% lebih besar dari OCR raw → harus flag
        $receipt->ocr_raw_amount = 100000;
        $receipt->claimed_amount = 120000;
        $receipt->saveQuietly();

        $receipt->recalculateVariance();
        $receipt->refresh();

        $this->assertTrue($receipt->variance_flag);
        $this->assertEquals(20.00, (float) $receipt->variance_pct);
    }

    // ── 3b. Variance flag tidak aktif jika selisih ≤ 10% ─────────────
    public function test_variance_flag_tidak_aktif_jika_selisih_kecil(): void
    {
        $emp     = $this->user('employee');
        $receipt = $this->receipt($emp);

        // Claimed 5% lebih kecil → tidak flag
        $receipt->ocr_raw_amount = 100000;
        $receipt->claimed_amount = 95000;
        $receipt->saveQuietly();

        $receipt->recalculateVariance();
        $receipt->refresh();

        $this->assertFalse($receipt->variance_flag);
        $this->assertEquals(5.00, (float) $receipt->variance_pct);
    }

    // ── 4. Finance approve → status berubah menjadi approved ─────────
    public function test_finance_approve_mengubah_status_jadi_approved(): void
    {
        $emp     = $this->user('employee');
        $finance = $this->user('finance');
        $receipt = $this->receipt($emp, ['status' => 'submitted']);

        $this->postJson("/api/v1/dashboard/receipts/{$receipt->id}/approve",
            ['notes' => 'Disetujui finance'],
            $this->token($finance)
        )
        ->assertOk()
        ->assertJsonPath('receipt.status', 'approved');

        $this->assertDatabaseHas('receipts', [
            'id'     => $receipt->id,
            'status' => 'approved',
        ]);
        $this->assertDatabaseHas('receipt_approvals', [
            'receipt_id' => $receipt->id,
            'user_id'    => $finance->id,
            'status'     => 'approved',
        ]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'receipt_approved']);
    }

    // ── 5. Karyawan tidak bisa akses struk milik orang lain ──────────
    public function test_karyawan_tidak_bisa_akses_struk_orang_lain(): void
    {
        $emp1    = $this->user('employee');
        $emp2    = $this->user('employee');
        $receipt = $this->receipt($emp1);

        // emp2 coba updateClaim struk milik emp1 → 403
        $this->patchJson("/api/v1/employee/receipts/{$receipt->id}/claim",
            ['category' => 'Curi'],
            $this->token($emp2)
        )->assertStatus(403)
         ->assertJsonPath('message', 'Anda bukan pemilik struk ini.');
    }

    // ── 5b. Karyawan tidak bisa akses detail struk orang lain ─────────
    public function test_karyawan_tidak_bisa_lihat_struk_orang_lain(): void
    {
        $emp1    = $this->user('employee');
        $emp2    = $this->user('employee');
        $receipt = $this->receipt($emp1);

        $this->getJson("/api/v1/employee/receipts/{$receipt->id}",
            $this->token($emp2)
        )->assertStatus(403);
    }

    // ── 6. Finance tidak bisa akses endpoint struk karyawan ──────────
    public function test_finance_tidak_bisa_akses_endpoint_employee_receipts(): void
    {
        $finance = $this->user('finance');

        // receipt_access middleware memblokir role selain employee
        // role:employee middleware fires first sebelum receipt_access
        $this->getJson('/api/v1/employee/receipts', $this->token($finance))
            ->assertStatus(403)
            ->assertJsonPath('message', 'Akses ditolak. Role yang diizinkan: employee.');
    }

    // ── 6b. Finance tidak bisa upload struk ───────────────────────────
    public function test_finance_tidak_bisa_upload_struk(): void
    {
        Queue::fake();
        $finance = $this->user('finance');

        // role:employee middleware fires first (403) sebelum upload diproses
        $this->postJson('/api/v1/employee/receipts', [
            'image'    => UploadedFile::fake()->image('struk.jpg'),
            'category' => 'Makan',
        ], $this->token($finance))
        ->assertStatus(403)
        ->assertJsonPath('message', 'Akses ditolak. Role yang diizinkan: employee.');

        Queue::assertNothingPushed();
    }

    // ── Extra: submit gagal jika OCR masih pending ────────────────────
    public function test_submit_gagal_jika_ocr_masih_pending(): void
    {
        $emp     = $this->user('employee');
        $receipt = $this->receipt($emp, ['ocr_status' => 'pending']);

        $this->postJson("/api/v1/employee/receipts/{$receipt->id}/submit",
            [],
            $this->token($emp)
        )->assertStatus(400)
         ->assertJsonPath('message', 'OCR masih diproses, tunggu sebentar.');
    }

    // ── Extra: submit gagal jika OCR gagal ────────────────────────────
    public function test_submit_gagal_jika_ocr_failed(): void
    {
        $emp     = $this->user('employee');
        $receipt = $this->receipt($emp, ['ocr_status' => 'failed']);

        $this->postJson("/api/v1/employee/receipts/{$receipt->id}/submit",
            [],
            $this->token($emp)
        )->assertStatus(400)
         ->assertJsonPath('message', 'OCR gagal, isi data manual dulu.');
    }
}
