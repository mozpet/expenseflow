<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $modules = array_keys(Role::AVAILABLE_MODULES);

        // Definisi 5 Built-in Roles
        $builtinRoles = [
            [
                'slug'         => 'super_admin',
                'name'         => 'Super Admin',
                'description'  => 'Akses mutlak dan bypass seluruh batasan sistem perusahaan',
                'platform'     => 'both',
                'branch_scope' => 'all',
                'is_builtin'   => true,
                'permissions'  => array_fill_keys($modules, 'manage'),
            ],
            [
                'slug'         => 'admin',
                'name'         => 'Administrator',
                'description'  => 'Akses operasional penuh ke seluruh modul web dan mobile perusahaan',
                'platform'     => 'both',
                'branch_scope' => 'all',
                'is_builtin'   => true,
                'permissions'  => array_fill_keys($modules, 'manage'),
            ],
            [
                'slug'         => 'finance',
                'name'         => 'Finance & Keuangan',
                'description'  => 'Persetujuan reimbursement struk, invoice vendor, dan aturan keuangan',
                'platform'     => 'both',
                'branch_scope' => 'all',
                'is_builtin'   => true,
                'permissions'  => [
                    Role::MODULE_RECEIPT        => 'manage',
                    Role::MODULE_EXPENSE_REPORT => 'manage',
                    Role::MODULE_INVOICE        => 'manage',
                    Role::MODULE_VENDOR         => 'manage',
                    Role::MODULE_SETTINGS       => 'manage',
                    Role::MODULE_AUDIT_LOG      => 'read',
                    Role::MODULE_USER           => 'none',
                    Role::MODULE_ATTENDANCE     => 'none',
                    Role::MODULE_LEAVE          => 'none',
                    Role::MODULE_SHIFT           => 'none',
                    Role::MODULE_ROLE_MANAGEMENT => 'none',
                ],
            ],
            [
                'slug'         => 'hrd',
                'name'         => 'Human Resource (HRD)',
                'description'  => 'Pengelolaan data SDM, kehadiran presensi, cuti, lembur, dan shift kerja',
                'platform'     => 'both',
                'branch_scope' => 'all',
                'is_builtin'   => true,
                'permissions'  => [
                    Role::MODULE_USER            => 'manage',
                    Role::MODULE_ATTENDANCE      => 'manage',
                    Role::MODULE_LEAVE           => 'manage',
                    Role::MODULE_OVERTIME        => 'manage',
                    Role::MODULE_SHIFT           => 'manage',
                    Role::MODULE_AUDIT_LOG       => 'read',
                    Role::MODULE_RECEIPT         => 'none',
                    Role::MODULE_EXPENSE_REPORT  => 'none',
                    Role::MODULE_INVOICE         => 'none',
                    Role::MODULE_VENDOR          => 'none',
                    Role::MODULE_SETTINGS        => 'none',
                    Role::MODULE_ROLE_MANAGEMENT => 'none',
                ],
            ],
            [
                'slug'         => 'employee',
                'name'         => 'Karyawan (Staff)',
                'description'  => 'Akses khusus aplikasi mobile: scan struk sendiri & presensi kehadiran',
                'platform'     => 'mobile_only',
                'branch_scope' => 'self',
                'is_builtin'   => true,
                'permissions'  => [
                    Role::MODULE_RECEIPT         => 'read',
                    Role::MODULE_ATTENDANCE      => 'read',
                    Role::MODULE_LEAVE           => 'read',
                    Role::MODULE_EXPENSE_REPORT  => 'read',
                    Role::MODULE_INVOICE         => 'none',
                    Role::MODULE_VENDOR          => 'none',
                    Role::MODULE_USER            => 'none',
                    Role::MODULE_OVERTIME        => 'none',
                    Role::MODULE_SHIFT           => 'none',
                    Role::MODULE_AUDIT_LOG       => 'none',
                    Role::MODULE_SETTINGS        => 'none',
                    Role::MODULE_ROLE_MANAGEMENT => 'none',
                ],
            ],
        ];

        DB::transaction(function () use ($builtinRoles, $modules) {
            foreach ($builtinRoles as $data) {
                $permissions = $data['permissions'];
                unset($data['permissions']);

                /** @var Role $role */
                $role = Role::updateOrCreate(
                    [
                        'company_id' => null,
                        'slug'       => $data['slug'],
                    ],
                    $data
                );

                // Sinkronisasi permissions untuk role ini
                foreach ($modules as $mod) {
                    $level = $permissions[$mod] ?? 'none';
                    RolePermission::updateOrCreate(
                        [
                            'role_id' => $role->id,
                            'module'  => $mod,
                        ],
                        [
                            'access_level' => $level,
                        ]
                    );
                }

                // Hubungkan user yang memiliki string role sama ke role_id
                User::where('role', $role->slug)
                    ->where(function ($q) use ($role) {
                        $q->whereNull('role_id')->orWhere('role_id', '!=', $role->id);
                    })
                    ->update(['role_id' => $role->id]);
            }
        });
    }
}
