<?php

namespace Database\Seeders;

use App\Models\MenuItem;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Seeder độc lập, idempotent cho module "So sánh báo giá NCC".
 * KHÔNG truncate, KHÔNG đụng RolePermissionSeeder. Chạy lại nhiều lần an toàn.
 *
 *   php artisan db:seed --class=PurchaseQuoteComparisonSeeder
 */
class PurchaseQuoteComparisonSeeder extends Seeder
{
    public function run(): void
    {
        $perms = [
            'purchases.quote_comparisons.view'            => ['view',            'Xem So sánh báo giá NCC'],
            'purchases.quote_comparisons.create'          => ['create',          'Tạo đợt so sánh báo giá NCC'],
            'purchases.quote_comparisons.update'          => ['update',          'Sửa đợt so sánh báo giá NCC'],
            'purchases.quote_comparisons.import'          => ['import',          'Import báo giá NCC'],
            'purchases.quote_comparisons.select_supplier' => ['select_supplier', 'Chọn NCC cho mặt hàng so sánh'],
            'purchases.quote_comparisons.export'          => ['export',          'Xuất kết quả so sánh báo giá'],
        ];

        $permIds = [];
        foreach ($perms as $code => [$action, $name]) {
            $permIds[] = Permission::updateOrCreate(
                ['code' => $code],
                [
                    'module'   => 'purchases',
                    'menu_key' => 'purchases.quote_comparisons',
                    'action'   => $action,
                    'name'     => $name,
                ]
            )->id;
        }

        // Cấp toàn bộ quyền cho Admin + Super Admin (local). Các role khác: không tự cấp.
        foreach (['admin', 'super_admin'] as $roleCode) {
            if ($role = Role::where('code', $roleCode)->first()) {
                $role->permissions()->syncWithoutDetaching($permIds);
            }
        }

        // Menu con dưới nhóm "Mua hàng"
        $parent = MenuItem::where('key', 'purchasing')->first();
        MenuItem::updateOrCreate(
            ['key' => 'purchasing.quote-comparisons'],
            [
                'parent_id'           => $parent?->id,
                'label'               => 'So sánh báo giá NCC',
                'route_name'          => 'purchasing.quote-comparisons.index',
                'icon'                => 'switch-horizontal',
                'required_permission' => 'purchases.quote_comparisons.view',
                'order'               => 6,
                'is_active'           => true,
            ]
        );
    }
}
