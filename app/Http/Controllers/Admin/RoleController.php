<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use App\Models\Permission;
use App\Models\Role;

class RoleController extends Controller
{
    public function index(Request $request): Response
    {
        $roles = Role::with('permissions')
            ->withCount('users')
            ->orderBy('id')
            ->get();

        $allPermissions = Permission::orderBy('module')
            ->orderBy('menu_key')
            ->orderBy('id')
            ->get();

        return Inertia::render('Admin/Roles/Index', [
            'roles' => $roles->map(fn ($r) => [
                'id'          => $r->id,
                'name'        => $r->name,
                'code'        => $r->code,
                'description' => $r->description,
                'is_system'   => $r->is_system,
                'users_count' => $r->users_count,
                'permissions' => $r->permissions->pluck('code')->values(),
            ]),
            'allPermissions' => $allPermissions,
            'selected'       => $request->query('selected'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'        => ['required', 'string', 'max:255'],
            'code'        => ['required', 'string', 'max:255', 'unique:roles,code'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $role = Role::create([
            'name'        => $data['name'],
            'code'        => $data['code'],
            'description' => $data['description'] ?? null,
            'is_system'   => false,
        ]);

        activity()
            ->causedBy(auth()->user())
            ->performedOn($role)
            ->log('Tạo vai trò mới: ' . $role->name);

        return redirect()
            ->route('admin.roles.index', ['selected' => $role->code])
            ->with('success', "Đã tạo vai trò \"{$role->name}\".");
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        abort_if($role->code === 'super_admin' || $role->code === 'admin', 403, 'Không thể thay đổi quyền của nhóm quản trị hệ thống.');

        $data = $request->validate([
            'permissions'   => ['present', 'array'],
            'permissions.*' => ['string', 'exists:permissions,code'],
        ]);

        $permissionIds = Permission::whereIn('code', $data['permissions'])->pluck('id');

        $oldPerms = $role->permissions()->pluck('code')->toArray();
        
        $role->permissions()->sync($permissionIds);

        activity()
            ->causedBy(auth()->user())
            ->performedOn($role)
            ->withProperties([
                'before' => $oldPerms,
                'after'  => $data['permissions'],
            ])
            ->log('Cập nhật quyền vai trò: ' . $role->name);

        return redirect()
            ->route('admin.roles.index', ['selected' => $role->code])
            ->with('success', "Đã cập nhật quyền cho nhóm \"{$role->name}\".");
    }

    public function destroy(Role $role): RedirectResponse
    {
        abort_if($role->is_system, 403, 'Không thể xóa vai trò hệ thống.');

        $name = $role->name;
        $role->delete();

        activity()
            ->causedBy(auth()->user())
            ->log('Xóa vai trò: ' . $name);

        return redirect()
            ->route('admin.roles.index')
            ->with('success', "Đã xóa vai trò \"{$name}\".");
    }
}
