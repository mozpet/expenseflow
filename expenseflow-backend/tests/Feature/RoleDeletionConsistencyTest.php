<?php

namespace Tests\Feature;

use App\Models\AttendanceSetting;
use App\Models\Company;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleDeletionConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $admin;
    private AttendanceSetting $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->company = Company::create([
            'name'      => 'PT Sinergi Amanah',
            'is_active' => true,
        ]);

        $this->branch = AttendanceSetting::create([
            'company_id'             => $this->company->id,
            'office_name'            => 'Head Office',
            'office_latitude'        => -6.200000,
            'office_longitude'       => 106.816667,
            'radius_meters'          => 100,
            'work_start_time'        => '08:00:00',
            'work_end_time'          => '17:00:00',
            'late_tolerance_minutes' => 15,
        ]);

        $adminRole = Role::whereNull('company_id')->where('slug', 'admin')->first();

        $this->admin = User::create([
            'company_id'            => $this->company->id,
            'role_id'               => $adminRole->id,
            'name'                  => 'Admin HR',
            'email'                 => 'admin@sinergiamanah.com',
            'password'              => bcrypt('password'),
            'role'                  => 'admin',
            'is_active'             => true,
            'attendance_setting_id' => $this->branch->id,
        ]);
    }

    private function createCustomRole(string $name = 'Staf Khusus'): Role
    {
        $role = Role::create([
            'company_id'   => $this->company->id,
            'name'         => $name,
            'slug'         => strtolower(str_replace(' ', '_', $name)) . '_' . uniqid(),
            'platform'     => 'both',
            'branch_scope' => 'all',
            'is_builtin'   => false,
            'is_active'    => true,
        ]);

        RolePermission::create([
            'role_id'      => $role->id,
            'module'       => Role::MODULE_RECEIPT,
            'access_level' => 'read',
        ]);

        return $role;
    }

    /**
     * Fix #7: Role tidak dapat dihapus jika masih digunakan oleh user via role_id.
     */
    public function test_cannot_delete_role_if_user_has_matching_role_id(): void
    {
        $role = $this->createCustomRole('Staf Lapangan');

        $user = User::create([
            'company_id'            => $this->company->id,
            'role_id'               => $role->id,
            'name'                  => 'Budi Lapangan',
            'email'                 => 'budi.lapangan@sinergiamanah.com',
            'password'              => bcrypt('password'),
            'role'                  => $role->slug,
            'is_active'             => true,
            'attendance_setting_id' => $this->branch->id,
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/v1/admin/roles/{$role->id}");

        $response->assertStatus(422)
            ->assertJsonFragment([
                'message' => "Role '{$role->name}' tidak dapat dihapus karena masih digunakan oleh 1 karyawan. Alihkan karyawan ke role lain terlebih dahulu.",
            ]);

        $this->assertDatabaseHas('roles', ['id' => $role->id]);
    }

    /**
     * Fix #7: Role tidak dapat dihapus jika user masih memiliki string users.role = slug
     * meskipun role_id bernilai NULL (mencegah orphan deletion).
     */
    public function test_cannot_delete_role_if_user_has_matching_role_slug_with_null_role_id(): void
    {
        $role = $this->createCustomRole('Kurir Logistik');

        // Simulasikan user legacy/orphan yang tersimpan di database dengan role_id NULL tapi role slug terisi
        \Illuminate\Support\Facades\DB::table('users')->insert([
            'company_id'            => $this->company->id,
            'role_id'               => null,
            'name'                  => 'Doni Kurir',
            'email'                 => 'doni.kurir@sinergiamanah.com',
            'password'              => bcrypt('password'),
            'role'                  => $role->slug,
            'is_active'             => true,
            'attendance_setting_id' => $this->branch->id,
            'created_at'            => now(),
            'updated_at'            => now(),
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/v1/admin/roles/{$role->id}");

        $response->assertStatus(422)
            ->assertJsonFragment([
                'message' => "Role '{$role->name}' tidak dapat dihapus karena masih digunakan oleh 1 karyawan. Alihkan karyawan ke role lain terlebih dahulu.",
            ]);

        $this->assertDatabaseHas('roles', ['id' => $role->id]);
    }

    /**
     * Fix #7: Setelah user dialihkan ke role lain, role dapat dihapus dengan sukses
     * dan user tidak masuk ke zombie state (dapat mengakses employee endpoints).
     */
    public function test_can_delete_role_after_user_reassigned_and_user_is_not_zombie(): void
    {
        $role = $this->createCustomRole('Surveyor');
        $employeeRole = Role::whereNull('company_id')->where('slug', 'employee')->first();

        $user = User::create([
            'company_id'            => $this->company->id,
            'role_id'               => $role->id,
            'name'                  => 'Eko Surveyor',
            'email'                 => 'eko.surveyor@sinergiamanah.com',
            'password'              => bcrypt('password'),
            'role'                  => $role->slug,
            'is_active'             => true,
            'attendance_setting_id' => $this->branch->id,
        ]);

        // Alihkan user ke employee
        $user->update([
            'role_id' => $employeeRole->id,
        ]);

        // Pastikan users.role tersinkronisasi ke 'employee'
        $user->refresh();
        $this->assertEquals('employee', $user->role);
        $this->assertEquals($employeeRole->id, $user->role_id);

        // Hapus custom role yang sudah tidak digunakan
        $deleteRes = $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/v1/admin/roles/{$role->id}");

        $deleteRes->assertOk()
            ->assertJsonFragment(['message' => "Custom Role '{$role->name}' berhasil dihapus."]);

        $this->assertDatabaseMissing('roles', ['id' => $role->id]);

        // User tetap aktif dan memiliki izin employee
        $this->assertTrue($user->hasPermission(Role::MODULE_RECEIPT, 'read'));
        $this->assertTrue($user->hasPermission(Role::MODULE_ATTENDANCE, 'read'));
        $this->assertTrue($user->hasPermission(Role::MODULE_LEAVE, 'read'));
        $this->assertFalse($user->hasPermission(Role::MODULE_USER, 'manage'));
    }

    /**
     * Fix #7: Jika role dihapus langsung pada model Eloquent ($role->delete()),
     * event deleting otomatis mereassign user terkait ke built-in 'employee'
     * sehingga users.role dan users.role_id tidak tertinggal dalam zombie state.
     */
    public function test_deleting_role_model_reassigns_orphaned_users_to_employee(): void
    {
        $role = $this->createCustomRole('Petugas Gudang');
        $employeeRole = Role::whereNull('company_id')->where('slug', 'employee')->first();

        $user = User::create([
            'company_id'            => $this->company->id,
            'role_id'               => $role->id,
            'name'                  => 'Hendra Gudang',
            'email'                 => 'hendra.gudang@sinergiamanah.com',
            'password'              => bcrypt('password'),
            'role'                  => $role->slug,
            'is_active'             => true,
            'attendance_setting_id' => $this->branch->id,
        ]);

        // Panggil $role->delete() langsung (simulasi programmatic deletion)
        $role->delete();

        $user->refresh();

        // User tidak masuk ke zombie state: role_id dan role tersinkronkan ke employee
        $this->assertEquals($employeeRole->id, $user->role_id);
        $this->assertEquals('employee', $user->role);

        // User tetap dapat menggunakan hak akses employee
        $this->assertTrue($user->hasPermission(Role::MODULE_ATTENDANCE, 'read'));
    }

    /**
     * Fix #7: Verifikasi sinkronisasi otomatis antara role_id dan role pada User::saving.
     */
    public function test_user_saving_automatically_synchronizes_role_slug_with_role_id(): void
    {
        $role = $this->createCustomRole('Koordinator Lapangan');
        $financeRole = Role::whereNull('company_id')->where('slug', 'finance')->first();

        // 1. Set role_id -> users.role otomatis terisi slug role
        $user = User::create([
            'company_id'            => $this->company->id,
            'role_id'               => $role->id,
            'name'                  => 'Agus Koordinator',
            'email'                 => 'agus@sinergiamanah.com',
            'password'              => bcrypt('password'),
            'is_active'             => true,
            'attendance_setting_id' => $this->branch->id,
        ]);

        $this->assertEquals($role->slug, $user->role);

        // 2. Ubah role_id ke finance -> users.role otomatis menjadi 'finance'
        $user->update(['role_id' => $financeRole->id]);
        $user->refresh();

        $this->assertEquals('finance', $user->role);
        $this->assertEquals($financeRole->id, $user->role_id);

        // 3. Ubah string role ke 'employee' -> users.role_id otomatis disesuaikan
        $employeeRole = Role::whereNull('company_id')->where('slug', 'employee')->first();
        $user->update(['role' => 'employee']);
        $user->refresh();

        $this->assertEquals('employee', $user->role);
        $this->assertEquals($employeeRole->id, $user->role_id);
    }

    /**
     * Fix #7: Jika role_id di-set NULL dari sebuah custom role, users.role otomatis
     * di-reset ke 'employee' untuk mencegah zombie lockout 403.
     */
    public function test_setting_user_role_id_to_null_resets_custom_role_to_employee(): void
    {
        $role = $this->createCustomRole('Teknisi Jaringan');

        $user = User::create([
            'company_id'            => $this->company->id,
            'role_id'               => $role->id,
            'name'                  => 'Rian Teknisi',
            'email'                 => 'rian@sinergiamanah.com',
            'password'              => bcrypt('password'),
            'role'                  => $role->slug,
            'is_active'             => true,
            'attendance_setting_id' => $this->branch->id,
        ]);

        $employeeRole = Role::whereNull('company_id')->where('slug', 'employee')->first();
        $user->update(['role_id' => null]);
        $user->refresh();

        // users.role tidak boleh tetap 'teknisi_jaringan' saat role_id NULL
        $this->assertEquals('employee', $user->role);
        $this->assertEquals($employeeRole->id, $user->role_id);

        // Akses employee tetap berfungsi
        $this->assertTrue($user->hasPermission(Role::MODULE_ATTENDANCE, 'read'));
    }
}
