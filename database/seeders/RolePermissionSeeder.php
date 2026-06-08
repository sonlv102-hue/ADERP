<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            // CRM
            'customers.view', 'customers.create', 'customers.edit', 'customers.delete',
            'leads.view', 'leads.create', 'leads.edit', 'leads.delete',
            // Products & Services
            'products.view', 'products.create', 'products.edit', 'products.delete',
            'services.view', 'services.create', 'services.edit', 'services.delete',
            // Warehouse
            'warehouse.view', 'warehouse.manage',
            // Sales
            'quotations.view', 'quotations.create', 'quotations.edit', 'quotations.approve',
            'orders.view', 'orders.create', 'orders.manage',
            // Projects
            'projects.view', 'projects.create', 'projects.manage', 'projects.delete',
            // Tickets
            'tickets.view', 'tickets.create', 'tickets.assign', 'tickets.close',
            // Purchasing
            'purchasing.view', 'purchasing.create', 'purchasing.approve',
            // Accounting
            'accounting.view', 'accounting.manage',
            // Reports
            'reports.view',
            // Documents
            'documents.view', 'documents.create', 'documents.manage',
            // Commissions
            'commissions.view', 'commissions.create', 'commissions.approve_l1', 'commissions.approve', 'commissions.pay',
            // Stock Transfers
            'stock-transfers.view', 'stock-transfers.create', 'stock-transfers.edit', 'stock-transfers.delete',
            // Sales Returns
            'sales-returns.view', 'sales-returns.create', 'sales-returns.edit', 'sales-returns.delete',
            // Purchase Returns
            'purchase-returns.view', 'purchase-returns.create', 'purchase-returns.edit', 'purchase-returns.delete',
            // Price Lists
            'price-lists.view', 'price-lists.create', 'price-lists.edit', 'price-lists.delete',
            // Admin
            'admin.users', 'admin.roles',
        ];

        foreach ($permissions as $perm) {
            Permission::firstOrCreate(['name' => $perm]);
        }

        $roles = [
            'admin' => $permissions, // tất cả
            'director' => [
                'customers.view', 'customers.create', 'customers.edit',
                'leads.view', 'leads.create', 'leads.edit', 'leads.delete',
                'products.view', 'services.view',
                'price-lists.view', 'price-lists.create', 'price-lists.edit', 'price-lists.delete',
                'warehouse.view',
                'stock-transfers.view',
                'quotations.view', 'quotations.approve',
                'orders.view', 'orders.manage',
                'sales-returns.view', 'sales-returns.create', 'sales-returns.edit', 'sales-returns.delete',
                'projects.view', 'projects.manage', 'projects.delete',
                'tickets.view',
                'purchasing.view', 'purchasing.approve',
                'purchase-returns.view', 'purchase-returns.create', 'purchase-returns.edit', 'purchase-returns.delete',
                'accounting.view',
                'commissions.view', 'commissions.create', 'commissions.approve_l1', 'commissions.approve',
                'documents.view', 'documents.create',
                'reports.view',
            ],
            'sales' => [
                'customers.view', 'customers.create', 'customers.edit',
                'leads.view', 'leads.create', 'leads.edit', 'leads.delete',
                'products.view', 'services.view',
                'price-lists.view', 'price-lists.create', 'price-lists.edit', 'price-lists.delete',
                'quotations.view', 'quotations.create', 'quotations.edit',
                'orders.view', 'orders.create',
                'sales-returns.view', 'sales-returns.create', 'sales-returns.edit', 'sales-returns.delete',
                'commissions.view', 'commissions.create',
                'documents.view', 'documents.create',
                'reports.view',
            ],
            'warehouse' => [
                'products.view', 'products.create', 'products.edit',
                'services.view',
                'warehouse.view', 'warehouse.manage',
                'purchasing.view',
                'stock-transfers.view', 'stock-transfers.create', 'stock-transfers.edit', 'stock-transfers.delete',
                'sales-returns.view', 'sales-returns.create', 'sales-returns.edit', 'sales-returns.delete',
                'purchase-returns.view', 'purchase-returns.create', 'purchase-returns.edit', 'purchase-returns.delete',
                'documents.view', 'documents.create',
                'reports.view',
            ],
            'technical' => [
                'customers.view',
                'products.view', 'services.view',
                'warehouse.view',
                'projects.view', 'projects.create', 'projects.manage',
                'tickets.view', 'tickets.create', 'tickets.assign', 'tickets.close',
                'documents.view', 'documents.create',
                'reports.view',
            ],
            'accounting' => [
                'customers.view',
                'products.view',
                'warehouse.view',
                'quotations.view',
                'orders.view',
                'projects.view',
                'purchasing.view',
                'purchase-returns.view',
                'accounting.view', 'accounting.manage',
                'commissions.view', 'commissions.pay',
                'documents.view', 'documents.create', 'documents.manage',
                'reports.view',
            ],
            'cskh' => [
                'customers.view', 'customers.create', 'customers.edit',
                'leads.view',
                'tickets.view', 'tickets.create',
                'orders.view',
                'documents.view',
            ],
        ];

        foreach ($roles as $roleName => $rolePerms) {
            $role = Role::firstOrCreate(['name' => $roleName]);
            $role->syncPermissions($rolePerms);
        }
    }
}
