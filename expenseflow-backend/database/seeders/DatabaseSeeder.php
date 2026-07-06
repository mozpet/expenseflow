<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\LoginAttempt;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database — 10 dataset dummy untuk testing.
     */
    public function run(): void
    {
        // ─── 1. Company ───────────────────────────────────────────
        $company = Company::create([
            'name'  => 'PT Maju Bersama',
            'email' => 'info@majubersama.co.id',
            'phone' => '021-5555-1234',
            'address' => 'Jl. Sudirman No. 10, Jakarta Selatan',
            'is_active' => true,
        ]);

        // ─── 2. Users (semua role) ───────────────────────────────
        $users = [
            // employee  — login via mobile
            ['name' => 'Budi Wicaksono',   'email' => 'budi@majubersama.co.id',    'role' => 'employee',    'password' => 'password'],
            ['name' => 'Siti Rahayu',       'email' => 'siti@majubersama.co.id',    'role' => 'employee',    'password' => 'password'],
            // finance   — login via web
            ['name' => 'Andi Pratama',      'email' => 'andi@majubersama.co.id',    'role' => 'finance',     'password' => 'password'],
            ['name' => 'Rina Susanti',      'email' => 'rina@majubersama.co.id',    'role' => 'finance',     'password' => 'password'],
            // hrd       — login via web
            ['name' => 'Dewi Lestari',      'email' => 'dewi@majubersama.co.id',    'role' => 'hrd',         'password' => 'password'],
            // admin     — login via web
            ['name' => 'Hendra Gunawan',    'email' => 'hendra@majubersama.co.id',  'role' => 'admin',       'password' => 'password'],
            // super_admin — login via web
            ['name' => 'Super Admin',       'email' => 'super@majubersama.co.id',   'role' => 'super_admin', 'password' => 'password'],
        ];

        $createdUsers = [];
        foreach ($users as $data) {
            $createdUsers[$data['email']] = User::create([
                'company_id' => $company->id,
                'name'       => $data['name'],
                'email'      => $data['email'],
                'password'   => $data['password'],
                'role'       => $data['role'],
                'is_active'  => true,
            ]);
        }

        // ─── 3. Vendors ──────────────────────────────────────────
        $vendors = [];
        $vendorData = [
            ['name' => 'PT Sumber Makmur',       'email' => 'sales@sumbermakmur.co.id',    'phone' => '021-1111-1001', 'contact_person' => 'Pak Herman', 'tax_id' => '01.234.567.8-001.000'],
            ['name' => 'CV Alat Kantor Jaya',    'email' => 'order@alatkantorjaya.co.id',  'phone' => '021-2222-2002', 'contact_person' => 'Bu Ratna',   'tax_id' => '02.345.678.9-002.000'],
            ['name' => 'PT Logistik Nusantara',  'email' => 'cs@logistiknusantara.co.id',   'phone' => '031-3333-3003', 'contact_person' => 'Pak Joko',   'tax_id' => '03.456.789.0-003.000'],
            ['name' => 'UD Berkah Sentosa',       'email' => 'info@berkahsentosa.co.id',    'phone' => '022-4444-4004', 'contact_person' => 'Bu Maya',    'tax_id' => null],
        ];
        foreach ($vendorData as $data) {
            $data['company_id'] = $company->id;
            $data['is_active'] = true;
            $vendors[] = DB::table('vendors')->insertGetId(array_merge($data, [
                'created_at' => now(), 'updated_at' => now(),
            ]));
        }

        // ─── 4. Receipts ─────────────────────────────────────────
        $budy = $createdUsers['budi@majubersama.co.id'];
        $siti = $createdUsers['siti@majubersama.co.id'];

        $receipts = [
            ['receipt_number' => 'RCP-2026-001', 'vendor_name' => 'Warung Makan Sederhana', 'total_amount' => 75000,  'receipt_date' => '2026-06-01', 'status' => 'approved', 'notes' => 'Makan siang meeting'],
            ['receipt_number' => 'RCP-2026-002', 'vendor_name' => 'Gojek',                   'total_amount' => 45000,  'receipt_date' => '2026-06-02', 'status' => 'pending',  'notes' => 'Transport client visit'],
            ['receipt_number' => 'RCP-2026-003', 'vendor_name' => 'Toko ATK Cemerlang',       'total_amount' => 125000, 'receipt_date' => '2026-06-03', 'status' => 'approved', 'notes' => 'Pembelian ATK'],
            ['receipt_number' => 'RCP-2026-004', 'vendor_name' => 'Kopi Kenangan',            'total_amount' => 56000,  'receipt_date' => '2026-06-04', 'status' => 'rejected', 'notes' => 'Kopi + snack'],
            ['receipt_number' => 'RCP-2026-005', 'vendor_name' => 'Blue Bird',                'total_amount' => 89000,  'receipt_date' => '2026-06-05', 'status' => 'pending',  'notes' => 'Transport ke bandara'],
        ];

        $receiptIds = [];
        foreach ($receipts as $i => $data) {
            $userId = $i < 3 ? $budy->id : $siti->id;
            $receiptIds[] = DB::table('receipts')->insertGetId([
                'company_id'     => $company->id,
                'user_id'        => $userId,
                'receipt_number' => $data['receipt_number'],
                'vendor_name'    => $data['vendor_name'],
                'total_amount'   => $data['total_amount'],
                'receipt_date'   => $data['receipt_date'],
                'currency'       => 'IDR',
                'status'         => $data['status'],
                'notes'          => $data['notes'],
                'created_at'     => now(), 'updated_at' => now(),
            ]);
        }

        // ─── 5. Receipt Approvals ────────────────────────────────
        $andi = $createdUsers['andi@majubersama.co.id'];
        DB::table('receipt_approvals')->insert([
            ['receipt_id' => $receiptIds[0], 'user_id' => $andi->id, 'status' => 'approved', 'notes' => 'Sesuai budget', 'created_at' => now(), 'updated_at' => now()],
            ['receipt_id' => $receiptIds[2], 'user_id' => $andi->id, 'status' => 'approved', 'notes' => 'OK', 'created_at' => now(), 'updated_at' => now()],
            ['receipt_id' => $receiptIds[3], 'user_id' => $andi->id, 'status' => 'rejected', 'notes' => 'Tidak ada nota asli', 'created_at' => now(), 'updated_at' => now()],
        ]);

        // ─── 6. Invoices ─────────────────────────────────────────
        $invoices = [
            ['invoice_number' => 'INV-2026-001', 'po_number' => 'PO-001', 'total_amount' => 5000000, 'tax_amount' => 550000, 'discount_amount' => 0,       'due_date' => '2026-07-01', 'invoice_date' => '2026-06-01', 'status' => 'approved', 'notes' => 'Pembelian laptop'],
            ['invoice_number' => 'INV-2026-002', 'po_number' => 'PO-002', 'total_amount' => 2500000, 'tax_amount' => 275000, 'discount_amount' => 100000,  'due_date' => '2026-07-15', 'invoice_date' => '2026-06-10', 'status' => 'pending',  'notes' => 'Langganan software'],
            ['invoice_number' => 'INV-2026-003', 'po_number' => null,     'total_amount' => 15000000, 'tax_amount' => 1650000,'discount_amount' => 500000, 'due_date' => '2026-06-30', 'invoice_date' => '2026-05-20', 'status' => 'approved', 'notes' => 'Renovasi kantor'],
        ];

        $invoiceIds = [];
        foreach ($invoices as $data) {
            $invoiceIds[] = DB::table('invoices')->insertGetId([
                'company_id'      => $company->id,
                'vendor_id'       => $vendors[array_rand($vendors)],
                'user_id'         => $andi->id,
                'invoice_number'  => $data['invoice_number'],
                'po_number'       => $data['po_number'],
                'total_amount'    => $data['total_amount'],
                'tax_amount'      => $data['tax_amount'],
                'discount_amount' => $data['discount_amount'],
                'due_date'        => $data['due_date'],
                'invoice_date'    => $data['invoice_date'],
                'currency'        => 'IDR',
                'status'          => $data['status'],
                'notes'           => $data['notes'],
                'created_at'      => now(), 'updated_at' => now(),
            ]);
        }

        // ─── 7. Invoice Items ────────────────────────────────────
        $itemsByInvoice = [
            // INV-001
            ['invoice_id' => $invoiceIds[0], 'description' => 'Laptop ASUS Vivobook 15"',  'quantity' => 2, 'unit_price' => 2500000, 'total_price' => 5000000],
            // INV-002
            ['invoice_id' => $invoiceIds[1], 'description' => 'Microsoft 365 Business',    'quantity' => 10, 'unit_price' => 240000,  'total_price' => 2400000],
            ['invoice_id' => $invoiceIds[1], 'description' => 'Biaya instalasi',            'quantity' => 1, 'unit_price' => 100000,  'total_price' => 100000],
            // INV-003
            ['invoice_id' => $invoiceIds[2], 'description' => 'Pengecatan ruangan',         'quantity' => 1, 'unit_price' => 8000000, 'total_price' => 8000000],
            ['invoice_id' => $invoiceIds[2], 'description' => 'Pemasangan partisi',         'quantity' => 1, 'unit_price' => 4500000, 'total_price' => 4500000],
            ['invoice_id' => $invoiceIds[2], 'description' => 'Pembelian material',          'quantity' => 1, 'unit_price' => 2500000, 'total_price' => 2500000],
        ];

        foreach ($itemsByInvoice as $item) {
            DB::table('invoice_items')->insert(array_merge($item, [
                'created_at' => now(), 'updated_at' => now(),
            ]));
        }

        // ─── 8. Invoice Approvals ────────────────────────────────
        DB::table('invoice_approvals')->insert([
            ['invoice_id' => $invoiceIds[0], 'user_id' => $andi->id, 'status' => 'approved', 'notes' => 'Sudah dicek, OK', 'created_at' => now(), 'updated_at' => now()],
            ['invoice_id' => $invoiceIds[2], 'user_id' => $andi->id, 'status' => 'approved', 'notes' => 'Approved level 1', 'created_at' => now(), 'updated_at' => now()],
        ]);

        // ─── 9. Activity Logs ────────────────────────────────────
        $activities = [
            ['user_id' => $budy->id,  'action' => 'login',     'description' => 'Login via mobile',                'created_at' => now()->subHours(2)],
            ['user_id' => $andi->id,  'action' => 'login',     'description' => 'Login via web dashboard',          'created_at' => now()->subHours(1)],
            ['user_id' => $andi->id,  'action' => 'approve',   'description' => 'Approve receipt RCP-2026-001',     'created_at' => now()->subMinutes(30)],
            ['user_id' => $budy->id,  'action' => 'create',    'description' => 'Submit receipt RCP-2026-002',      'created_at' => now()->subMinutes(20)],
            ['user_id' => $andi->id,  'action' => 'reject',    'description' => 'Reject receipt RCP-2026-004',      'created_at' => now()->subMinutes(10)],
        ];
        foreach ($activities as $data) {
            DB::table('activity_logs')->insert(array_merge($data, [
                'company_id' => $company->id,
                'updated_at' => $data['created_at'],
            ]));
        }

        // ─── 10. Company Settings ────────────────────────────────
        $settings = [
            ['key' => 'company_name',      'value' => 'PT Maju Bersama'],
            ['key' => 'currency',          'value' => 'IDR'],
            ['key' => 'timezone',          'value' => 'Asia/Jakarta'],
            ['key' => 'max_receipt_amount', 'value' => '5000000'],
            ['key' => 'approval_level_1',   'value' => '10000000'],
            ['key' => 'approval_level_2',   'value' => '50000000'],
        ];
        foreach ($settings as $data) {
            DB::table('company_settings')->insert(array_merge($data, [
                'company_id' => $company->id,
                'created_at' => now(), 'updated_at' => now(),
            ]));
        }

        // ─── 11. Attendance — kantor, enable employee, cuti, riwayat ──
        $dewi = $createdUsers['dewi@majubersama.co.id'];

        // Aktifkan fitur presensi untuk kedua employee
        User::whereIn('email', ['budi@majubersama.co.id', 'siti@majubersama.co.id'])
            ->update(['attendance_enabled' => true]);

        // Kantor (koordinat sekitar Sudirman, Jakarta)
        DB::table('attendance_settings')->insert([
            'company_id'             => $company->id,
            'office_name'            => 'Kantor Pusat Jakarta',
            'office_latitude'        => -6.20000000,
            'office_longitude'       => 106.81666700,
            'radius_meters'          => 100,
            'work_start_time'        => '08:00:00',
            'work_end_time'          => '17:00:00',
            'late_tolerance_minutes' => 15,
            'require_selfie'         => false,
            'allow_wfh'              => true,
            'created_at'             => now(), 'updated_at' => now(),
        ]);

        // Leave requests: 1 WFH approved (untuk tes check-in WFH), 1 cuti pending (untuk tes approve/reject)
        DB::table('leave_requests')->insert([
            [
                'user_id' => $budy->id, 'company_id' => $company->id,
                'leave_type' => 'wfh', 'start_date' => now()->toDateString(),
                'end_date' => now()->toDateString(), 'total_days' => 1,
                'reason' => 'WFH approved — siap untuk tes check-in WFH', 'status' => 'approved',
                'approved_by' => $dewi->id, 'approved_at' => now(),
                'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'user_id' => $siti->id, 'company_id' => $company->id,
                'leave_type' => 'cuti', 'start_date' => now()->addDays(3)->toDateString(),
                'end_date' => now()->addDays(5)->toDateString(), 'total_days' => 3,
                'reason' => 'Cuti pending — siap untuk tes approve/reject', 'status' => 'pending',
                'approved_by' => null, 'approved_at' => null,
                'created_at' => now(), 'updated_at' => now(),
            ],
        ]);

        // Riwayat presensi siti (agar /attendance/my & /report ada datanya)
        DB::table('attendances')->insert([
            [
                'user_id' => $siti->id, 'company_id' => $company->id,
                'date' => now()->subDay()->toDateString(),
                'check_in_time' => now()->subDay()->setTime(8, 5),
                'check_in_lat' => -6.2, 'check_in_lng' => 106.816667,
                'check_in_distance_meters' => 12, 'check_in_type' => 'onsite',
                'check_out_time' => now()->subDay()->setTime(17, 10),
                'check_out_lat' => -6.2, 'check_out_lng' => 106.816667,
                'check_out_type' => 'onsite', 'status' => 'present',
                'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'user_id' => $siti->id, 'company_id' => $company->id,
                'date' => now()->subDays(2)->toDateString(),
                'check_in_time' => now()->subDays(2)->setTime(8, 45),
                'check_in_lat' => -6.2, 'check_in_lng' => 106.816667,
                'check_in_distance_meters' => 30, 'check_in_type' => 'onsite',
                'check_out_time' => now()->subDays(2)->setTime(17, 0),
                'check_out_lat' => -6.2, 'check_out_lng' => 106.816667,
                'check_out_type' => 'onsite', 'status' => 'late',
                'created_at' => now(), 'updated_at' => now(),
            ],
        ]);

        // ─── 12. Libur nasional 2026 ─────────────────────────────
        $this->call(HolidaySeeder::class);

        $this->command?->info('✅ Dataset dummy siap (termasuk presensi & libur nasional). Password semua user: "password".');
        $this->command?->info('   Kantor: -6.200000, 106.816667 (radius 100m). Employee budi & siti: attendance_enabled = true.');
    }
}
