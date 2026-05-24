<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    public function index(Request $request): Response
    {
        $roles = Role::with('permissions')->orderBy('id')->get();

        $groupDefs = [
            'CRM / Khách hàng' => 'customers',
            'Sản phẩm'         => 'products',
            'Dịch vụ'          => 'services',
            'Kho hàng'         => 'warehouse',
            'Báo giá'          => 'quotations',
            'Đơn hàng'         => 'orders',
            'Dự án'            => 'projects',
            'Hỗ trợ kỹ thuật'  => 'tickets',
            'Mua hàng'         => 'purchasing',
            'Kế toán'          => 'accounting',
            'Báo cáo'          => 'reports',
            'Chứng từ'         => 'documents',
            'Hoa hồng'         => 'commissions',
            'Quản trị'         => 'admin',
        ];

        $allPerms = Permission::orderBy('name')->pluck('name');

        $permissionGroups = collect($groupDefs)
            ->map(fn ($prefix) => $allPerms->filter(fn ($p) => str_starts_with($p, $prefix . '.'))->values())
            ->filter(fn ($group) => $group->isNotEmpty());

        return Inertia::render('Admin/Roles/Index', [
            'roles' => $roles->map(fn ($r) => [
                'id'          => $r->id,
                'name'        => $r->name,
                'permissions' => $r->permissions->pluck('name')->values(),
            ]),
            'permissionGroups' => $permissionGroups,
            'selected'         => $request->query('selected'),
        ]);
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        abort_if($role->name === 'admin', 403, 'Không thể thay đổi quyền của nhóm admin.');

        $data = $request->validate([
            'permissions'   => ['present', 'array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ]);

        $role->syncPermissions($data['permissions']);
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        return redirect()
            ->route('admin.roles.index', ['selected' => $role->name])
            ->with('success', "Đã cập nhật quyền cho nhóm \"{$role->name}\".");
    }
}
