# Mini ERP — RBAC (Roles & Permissions)

Uses **Spatie laravel-permission**. Seed: `database/seeders/RolePermissionSeeder.php`.

## 7 Roles
| Role | Description |
|---|---|
| admin | Full access — all permissions |
| director | View all + approve quotations/purchasing, manage orders/projects |
| sales | CRM CRUD, quotations CRUD, orders view/create, reports |
| warehouse | Products CRUD, warehouse manage, purchasing view |
| technical | Customers/products view, projects manage, tickets CRUD |
| accounting | Most modules view, accounting manage |
| cskh | Customers create/edit, tickets view/create, orders view |

## Permission Groups
- **CRM:** customers.view/create/edit/delete, leads.view/create/manage
- **Catalog:** products.view/create/edit/delete, services.view/create/edit/delete, price-lists.view/manage
- **Warehouse:** warehouse.view, warehouse.manage, stock-transfers.view/manage, sales-returns.view/manage, purchase-returns.view/manage
- **Sales:** quotations.view/create/edit/approve, orders.view/create/manage, commissions.view/manage
- **Projects:** projects.view/create/manage
- **Tickets:** tickets.view/create/assign/close
- **Purchasing:** purchasing.view/create/approve
- **Accounting:** accounting.view, accounting.manage
- **Reports:** reports.view
- **Admin:** admin.users, admin.roles

## Adding New Permission
1. Add to `$permissions` array in `RolePermissionSeeder`
2. Assign to relevant roles in `$roles` array
3. Run: `php artisan db:seed --class=RolePermissionSeeder`
4. Add route middleware: `->middleware('can:permission.name')`
5. Add to Vue: `v-if="can('permission.name')"` using `usePermission` composable
