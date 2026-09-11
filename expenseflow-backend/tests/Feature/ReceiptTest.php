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

    private function user(string $role, bool $active = true): User
    {
        return User::factory()->create([
            'company_id' => $this->company->id,
            'role'       => $role,
            'is_active'  => $active,
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

    // ── 4b. Finance bisa approve jika selisih variance ≤ limit (peringatan kuning) ──
    public function test_finance_bisa_approve_jika_variance_dibawah_atau_sama_dengan_limit(): void
    {
        $emp     = $this->user('employee');
        $finance = $this->user('finance');
        $receipt = $this->receipt($emp, [
            'status'         => 'submitted',
            'ocr_raw_amount' => 100000,
            'claimed_amount' => 105000, // 5% selisih <= default limit 10%
        ]);

        $this->postJson("/api/v1/dashboard/receipts/{$receipt->id}/approve",
            ['notes' => 'Disetujui selisih wajar'],
            $this->token($finance)
        )
        ->assertOk()
        ->assertJsonPath('receipt.status', 'approved');
    }

    // ── 4c. Finance TIDAK bisa approve jika selisih variance > limit (peringatan merah) ──
    public function test_finance_tidak_bisa_approve_jika_variance_melebihi_limit(): void
    {
        $emp     = $this->user('employee');
        $finance = $this->user('finance');
        $receipt = $this->receipt($emp, [
            'status'         => 'submitted',
            'ocr_raw_amount' => 100000,
            'claimed_amount' => 130000, // 30% selisih > limit 10%
        ]);

        $this->postJson("/api/v1/dashboard/receipts/{$receipt->id}/approve",
            ['notes' => 'Coba approve'],
            $this->token($finance)
        )
        ->assertStatus(422)
        ->assertJsonPath('code', 'VARIANCE_LIMIT_EXCEEDED');

        // Pastikan status tetap submitted di database
        $this->assertEquals('submitted', $receipt->fresh()->status);
    }

    // ── 4d. Finance bisa approve jika nominal disesuaikan (partial approval) ke dalam batas limit ──
    public function test_finance_bisa_approve_struk_variance_jika_nominal_disesuaikan_ke_ocr(): void
    {
        $emp     = $this->user('employee');
        $finance = $this->user('finance');
        $receipt = $this->receipt($emp, [
            'status'         => 'submitted',
            'ocr_raw_amount' => 100000,
            'claimed_amount' => 130000, // Klaim 30% lebih tinggi
        ]);

        // Finance sesuaikan nominal yang disetujui menjadi 100.000 (sesuai OCR)
        $this->postJson("/api/v1/dashboard/receipts/{$receipt->id}/approve",
            [
                'approved_amount' => 100000,
                'notes'           => 'Disetujui sesuai nominal OCR',
            ],
            $this->token($finance)
        )
        ->assertOk()
        ->assertJsonPath('receipt.status', 'approved');

        $this->assertEquals(100000, (float) $receipt->fresh()->approved_amount);
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

    // ── 6. User non-aktif tidak bisa akses endpoint struk ──────────
    public function test_user_nonaktif_tidak_bisa_akses_endpoint_receipts(): void
    {
        $inactiveUser = $this->user('employee', false);

        $this->getJson('/api/v1/employee/receipts', $this->token($inactiveUser))
            ->assertStatus(403);
    }

    // ── 6b. User non-aktif tidak bisa upload struk ────────────────────
    public function test_user_nonaktif_tidak_bisa_upload_struk(): void
    {
        Queue::fake();
        $inactiveUser = $this->user('employee', false);

        $this->postJson('/api/v1/employee/receipts', [
            'image'    => UploadedFile::fake()->image('struk.jpg'),
            'category' => 'Makan',
        ], $this->token($inactiveUser))
        ->assertStatus(403);

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

    // ── 15. Foto ulang (retake) struk draf yang buram/gagal ───────────
    public function test_karyawan_bisa_foto_ulang_struk_draf(): void
    {
        Queue::fake();
        $emp     = $this->user('employee');
        $receipt = $this->receipt($emp, [
            'status'     => 'draft',
            'ocr_status' => 'failed',
            'ocr_error'  => 'Foto struk buram atau tidak terbaca jelas.',
        ]);

        $file = UploadedFile::fake()->image('nota_baru.jpg', 600, 800);

        $response = $this->postJson(
            "/api/v1/employee/receipts/{$receipt->id}/retake",
            ['image' => $file],
            $this->token($emp)
        );

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'Foto struk berhasil diperbarui. OCR sedang memproses ulang.');

        $receipt->refresh();
        $this->assertEquals('pending', $receipt->ocr_status);
        $this->assertNull($receipt->ocr_error);

        Queue::assertPushed(ProcessOcrJob::class, function ($job) use ($receipt) {
            return $job->receiptId === $receipt->id;
        });
    }

    public function test_struk_non_draf_tidak_bisa_difoto_ulang(): void
    {
        $emp     = $this->user('employee');
        $receipt = $this->receipt($emp, ['status' => 'submitted']);

        $file = UploadedFile::fake()->image('nota_baru.jpg', 600, 800);

        $this->postJson(
            "/api/v1/employee/receipts/{$receipt->id}/retake",
            ['image' => $file],
            $this->token($emp)
        )->assertStatus(422);
    }

    public function test_karyawan_lain_tidak_bisa_foto_ulang_struk_orang_lain(): void
    {
        $emp1    = $this->user('employee');
        $emp2    = $this->user('employee');
        $receipt = $this->receipt($emp1, ['status' => 'draft']);

        $file = UploadedFile::fake()->image('nota_baru.jpg', 600, 800);

        $this->postJson(
            "/api/v1/employee/receipts/{$receipt->id}/retake",
            ['image' => $file],
            $this->token($emp2)
        )->assertStatus(403);
    }
}
