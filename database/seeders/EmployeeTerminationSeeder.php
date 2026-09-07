<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Seeder độc lập, idempotent cho nghiệp vụ "Nhân viên thôi việc".
 * KHÔNG truncate, KHÔNG đụng RolePermissionSeeder. Chạy lại nhiều lần an toàn.
 *
 *   php artisan db:seed --class=EmployeeTerminationSeeder
 */
class EmployeeTerminationSeeder extends Seeder
{
    public function run(): void
    {
        $perms = [
            'hr.employees.terminate'        => ['terminate',        'Xác nhận Nhân viên thôi việc'],
            'hr.employees.terminate_cancel' => ['terminate_cancel', 'Hủy xác nhận thôi việc'],
        ];

        foreach ($perms as $code => [$action, $name]) {
            Permission::updateOrCreate(
                ['code' => $code],
                ['module' => 'hr', 'menu_key' => 'hr.employees', 'action' => $action, 'name' => $name]
            );
        }

        $permIds = Permission::whereIn('code', array_keys($perms))->pluck('id');

        foreach (['admin', 'super_admin', 'hr'] as $roleCode) {
            Role::where('code', $roleCode)->first()?->permissions()->syncWithoutDetaching($permIds);
        }
    }
}
