<?php

namespace Database\Seeders;

use App\Models\MenuItem;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Seeder độc lập, idempotent cho "Báo cáo dòng tiền tài khoản công ty".
 * KHÔNG truncate, KHÔNG đụng RolePermissionSeeder. Chạy lại nhiều lần an toàn.
 *
 *   php artisan db:seed --class=CashFlowReportPermissionSeeder
 */
class CashFlowReportPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $perms = [
            'reports.bank_cashflow.view'              => ['view', 'Xem Dòng tiền tài khoản công ty'],
            'reports.bank_cashflow.transactions.view'  => ['transactions_view', 'Xem chi tiết giao dịch ngân hàng'],
            'reports.bank_cashflow.reconcile'          => ['reconcile', 'Đối soát/phân loại giao dịch dòng tiền'],
            'reports.bank_cashflow.edit'                => ['edit', 'Sửa thông tin bổ sung giao dịch dòng tiền'],
            'reports.bank_cashflow.balance.view'        => ['balance_view', 'Xem số dư tài khoản ngân hàng'],
        ];

        foreach ($perms as $code => [$action, $name]) {
            Permission::updateOrCreate(
                ['code' => $code],
                ['module' => 'reports', 'menu_key' => 'reports.bank_cashflow', 'action' => $action, 'name' => $name]
            );
        }

        $permIds = Permission::whereIn('code', array_keys($perms))->pluck('id');

        foreach (['admin', 'super_admin', 'accounting'] as $roleCode) {
            Role::where('code', $roleCode)->first()?->permissions()->syncWithoutDetaching($permIds);
        }

        $reportsSub = MenuItem::where('key', 'accounting.reports')->first();
        if ($reportsSub) {
            MenuItem::updateOrCreate(
                ['key' => 'accounting.reports.bank_cashflow'],
                [
                    'parent_id'           => $reportsSub->id,
                    'label'               => 'Dòng tiền tài khoản công ty',
                    'route_name'          => 'reports.company-cashflow.index',
                    'icon'                => 'banknotes',
                    'required_permission' => 'reports.bank_cashflow.view',
                    'order'               => 8,
                ]
            );
        }
    }
}
