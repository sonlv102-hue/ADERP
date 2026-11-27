<?php

namespace Database\Seeders;

use App\Models\MenuItem;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Seeder độc lập, idempotent cho "Kiểm soát sai lệch tồn kho và kế toán".
 * KHÔNG truncate, chỉ thêm quyền (syncWithoutDetaching) → an toàn chạy trên production.
 *
 *   php artisan db:seed --class=InventoryIntegrityPermissionSeeder
 */
class InventoryIntegrityPermissionSeeder extends Seeder
{
    public const PERMS = [
        'reports.inventory_integrity.view'   => ['view', 'Xem Kiểm soát sai lệch tồn kho và kế toán'],
        'reports.inventory_integrity.audit'  => ['audit', 'Chạy kiểm tra sai lệch tồn kho/kế toán'],
        'reports.inventory_integrity.assign' => ['assign', 'Giao việc / đổi trạng thái vấn đề sai lệch'],
        'reports.inventory_integrity.note'   => ['note', 'Ghi chú / kết quả kiểm tra vấn đề sai lệch'],
        'reports.inventory_integrity.accept' => ['accept', 'Xác nhận chênh lệch hợp lệ / quyết định chính sách'],
        'reports.inventory_integrity.export' => ['export', 'Xuất Excel Kiểm soát sai lệch tồn kho và kế toán'],
    ];

    /** Owner/Admin: xem, audit, giao việc; Kế toán trưởng: thêm xác nhận chênh lệch; Thủ kho: chỉ xem (lọc theo quyền). */
    public const ROLES = [
        'admin'      => ['view', 'audit', 'assign', 'note', 'accept', 'export'],
        'director'   => ['view', 'audit', 'assign', 'note', 'export'],
        'accounting' => ['view', 'audit', 'assign', 'note', 'accept', 'export'],
        'warehouse'  => ['view'],
    ];

    public function run(): void
    {
        foreach (self::PERMS as $code => [$action, $name]) {
            Permission::updateOrCreate(['code' => $code],
                ['module' => 'reports', 'menu_key' => 'reports.inventory_integrity', 'action' => $action, 'name' => $name]);
        }
        foreach (self::ROLES as $roleCode => $actions) {
            $ids = Permission::whereIn('code', array_map(fn ($a) => "reports.inventory_integrity.{$a}", $actions))->pluck('id');
            Role::where('code', $roleCode)->first()?->permissions()->syncWithoutDetaching($ids);
        }

        $warehouseGroup = MenuItem::where('key', 'warehouse')->whereNull('parent_id')->first();
        if ($warehouseGroup) {
            MenuItem::updateOrCreate(['key' => 'warehouse.report.integrity'], [
                'parent_id' => $warehouseGroup->id, 'label' => 'Kiểm soát sai lệch tồn kho và kế toán',
                'route_name' => 'reports.inventory_integrity.index', 'icon' => 'shield-check',
                'required_permission' => 'reports.inventory_integrity.view', 'order' => 12,
            ]);
        }
    }
}
