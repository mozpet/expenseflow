<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Receipt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MultiImageReceiptTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $employee;
    private User $finance;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Queue::fake();

        $this->company = Company::create(['name' => 'PT Multi Photo', 'is_active' => true]);

        $this->employee = User::factory()->create([
            'company_id' => $this->company->id,
            'role'       => 'employee',
            'is_active'  => true,
        ]);

        $this->finance = User::factory()->create([
            'company_id' => $this->company->id,
            'role'       => 'finance',
            'is_active'  => true,
        ]);
    }

    public function test_can_upload_receipt_with_single_image(): void
    {
        $file = UploadedFile::fake()->image('nota.jpg', 600, 800);

        $response = $this->actingAs($this->employee)
            ->postJson('/api/v1/employee/receipts', [
                'image'    => $file,
                'category' => 'Konsumsi / Makan',
                'notes'    => 'Makan siang klien',
            ]);

        $response->assertStatus(201);
        $receiptId = $response->json('receipt.id');

        $this->assertDatabaseHas('receipts', [
            'id'       => $receiptId,
            'category' => 'Konsumsi / Makan',
        ]);

        $this->assertDatabaseCount('receipt_images', 1);
        $this->assertDatabaseHas('receipt_images', [
            'receipt_id' => $receiptId,
            'file_name'  => 'nota.jpg',
        ]);
    }

    public function test_can_upload_receipt_with_additional_images(): void
    {
        $primary = UploadedFile::fake()->image('nota_utama.jpg', 600, 800);
        $extra1  = UploadedFile::fake()->image('slip_edc.jpg', 400, 600);
        $extra2  = UploadedFile::fake()->image('rincian_bill.jpg', 500, 700);

        $response = $this->actingAs($this->employee)
            ->postJson('/api/v1/employee/receipts', [
                'image'             => $primary,
                'additional_images' => [$extra1, $extra2],
                'category'          => 'Hotel & Penginapan',
                'notes'             => 'Menginap dinas Surabaya',
            ]);

        $response->assertStatus(201);
        $receiptId = $response->json('receipt.id');

        $this->assertDatabaseHas('receipts', [
            'id'       => $receiptId,
            'category' => 'Hotel & Penginapan',
        ]);

        // Harus ada 3 gambar di receipt_images
        $this->assertDatabaseCount('receipt_images', 3);
        $this->assertDatabaseHas('receipt_images', ['receipt_id' => $receiptId, 'file_name' => 'nota_utama.jpg']);
        $this->assertDatabaseHas('receipt_images', ['receipt_id' => $receiptId, 'file_name' => 'slip_edc.jpg']);
        $this->assertDatabaseHas('receipt_images', ['receipt_id' => $receiptId, 'file_name' => 'rincian_bill.jpg']);

        $images = $response->json('receipt.images');
        $this->assertCount(3, $images);
    }

    public function test_can_upload_receipt_via_multipart_with_bracket_notation(): void
    {
        $primary = UploadedFile::fake()->image('nota.jpg', 600, 800);
        $extra   = UploadedFile::fake()->image('slip.jpg', 400, 600);

        $response = $this->actingAs($this->employee)
            ->post('/api/v1/employee/receipts', [
                'image'             => $primary,
                'additional_images' => [$extra],
                'category'          => 'Transportasi',
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseCount('receipt_images', 2);
    }


    public function test_can_fetch_additional_image_via_api(): void
    {
        $primary = UploadedFile::fake()->image('nota_utama.jpg', 600, 800);
        $extra   = UploadedFile::fake()->image('slip_edc.jpg', 400, 600);

        $res = $this->actingAs($this->employee)
            ->postJson('/api/v1/employee/receipts', [
                'image'             => $primary,
                'additional_images' => [$extra],
                'category'          => 'Transportasi',
            ]);

        $receiptId = $res->json('receipt.id');
        $receipt = Receipt::with('images')->find($receiptId);
        $additionalImage = $receipt->images->where('file_name', 'slip_edc.jpg')->first();

        $this->assertNotNull($additionalImage);

        // Akses oleh Finance via image_id query
        $imgRes = $this->actingAs($this->finance)
            ->get("/api/v1/dashboard/receipts/{$receiptId}/image?image_id={$additionalImage->id}");

        $imgRes->assertStatus(200);
    }

    public function test_uploaded_receipt_images_have_correct_image_types(): void
    {
        $primary = UploadedFile::fake()->image('nota_utama.jpg', 600, 800);
        $extra   = UploadedFile::fake()->image('slip_edc.jpg', 400, 600);

        $res = $this->actingAs($this->employee)
            ->postJson('/api/v1/employee/receipts', [
                'image'             => $primary,
                'additional_images' => [$extra],
                'category'          => 'Operasional',
            ]);

        $res->assertStatus(201);
        $receiptId = $res->json('receipt.id');

        $this->assertDatabaseHas('receipt_images', [
            'receipt_id' => $receiptId,
            'file_name'  => 'nota_utama.jpg',
            'image_type' => 'primary',
        ]);

        $this->assertDatabaseHas('receipt_images', [
            'receipt_id' => $receiptId,
            'file_name'  => 'slip_edc.jpg',
            'image_type' => 'additional',
        ]);
    }

    public function test_employee_can_access_own_receipt_image(): void
    {
        $primary = UploadedFile::fake()->image('nota.jpg', 600, 800);

        $res = $this->actingAs($this->employee)
            ->postJson('/api/v1/employee/receipts', [
                'image'    => $primary,
                'category' => 'Konsumsi / Makan',
            ]);

        $receiptId = $res->json('receipt.id');

        $imgRes = $this->actingAs($this->employee)
            ->get("/api/v1/employee/receipts/{$receiptId}/image");

        $imgRes->assertStatus(200);
    }

    public function test_other_employee_cannot_access_receipt_image(): void
    {
        $primary = UploadedFile::fake()->image('nota.jpg', 600, 800);

        $res = $this->actingAs($this->employee)
            ->postJson('/api/v1/employee/receipts', [
                'image'    => $primary,
                'category' => 'Konsumsi / Makan',
            ]);

        $receiptId = $res->json('receipt.id');

        $otherEmployee = User::factory()->create([
            'company_id' => $this->company->id,
            'role'       => 'employee',
            'is_active'  => true,
        ]);

        $imgRes = $this->actingAs($otherEmployee)
            ->get("/api/v1/employee/receipts/{$receiptId}/image");

        $imgRes->assertStatus(403);
    }
}

