<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Seeder độc lập, idempotent cho quyền "Tạo phiếu nhập kho thủ công (không qua đơn mua)".
 * KHÔNG truncate, KHÔNG đụng RolePermissionSeeder. Chạy lại nhiều lần an toàn.
 *
 *   php artisan db:seed --class=ManualStockEntryPermissionSeeder
 */
class ManualStockEntryPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $perm = Permission::updateOrCreate(
            ['code' => 'warehouse.stock_entries.create_manual'],
            [
                'module' => 'warehouse', 'menu_key' => 'warehouse.stock_entries', 'action' => 'create_manual',
                'name' => 'Tạo Phiếu nhập kho thủ công',
                'description' => 'Tạo/sửa phiếu nhập kho không liên kết đơn mua hàng',
            ]
        );

        foreach (['admin', 'super_admin', 'accounting'] as $roleCode) {
            Role::where('code', $roleCode)->first()?->permissions()->syncWithoutDetaching([$perm->id]);
        }
    }
}
