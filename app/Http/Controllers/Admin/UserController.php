<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Role;
use App\Models\Permission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Users/Index', [
            'users' => User::with('roles')
                ->orderBy('name')
                ->paginate(20)
                ->through(fn ($user) => [
                    'id'        => $user->id,
                    'name'      => $user->name,
                    'email'     => $user->email,
                    'phone'     => $user->phone,
                    'is_active' => $user->is_active,
                    'roles'     => $user->roles->pluck('name'),
                ]),
            'roles' => Role::orderBy('name')->get(['id', 'name', 'code']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Users/Form', [
            'roles' => Role::orderBy('name')->get(['id', 'name', 'code']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'             => ['required', 'string', 'max:255'],
            'email'            => ['required', 'email', 'unique:users'],
            'password'         => ['required', 'confirmed', Rules\Password::defaults()],
            'phone'            => ['nullable', 'string', 'max:20'],
            'role_ids'         => ['required', 'array'],
            'role_ids.*'       => ['integer', 'exists:roles,id'],
        ]);

        $user = User::create([
            'name'      => $data['name'],
            'email'     => $data['email'],
            'password'  => Hash::make($data['password']),
            'phone'     => $data['phone'] ?? null,
            'is_active' => true,
        ]);

        $user->roles()->sync($data['role_ids']);

        activity()
            ->causedBy(auth()->user())
            ->performedOn($user)
            ->log('Tạo người dùng mới: ' . $user->name);

        return redirect()->route('admin.users.index')
            ->with('success', 'Tạo tài khoản thành công.');
    }

    public function edit(User $user): Response
    {
        $user->load('roles', 'permissions');

        $allPermissions = Permission::orderBy('module')
            ->orderBy('menu_key')
            ->orderBy('id')
            ->get(['id', 'module', 'menu_key', 'action', 'code', 'name', 'description']);

        $userOverrides = $user->permissions->mapWithKeys(fn ($p) => [
            $p->id => $p->pivot->effect
        ]);

        $computedPermissions = $user->getAllPermissions()->pluck('code')->toArray();

        return Inertia::render('Admin/Users/Form', [
            'user' => [
                'id'               => $user->id,
                'name'             => $user->name,
                'email'            => $user->email,
                'phone'            => $user->phone,
                'is_active'        => $user->is_active,
                'role_ids'         => $user->roles->pluck('id')->toArray(),
                'base_salary'      => (float) ($user->base_salary ?? 0),
                'allowance'        => (float) ($user->allowance   ?? 0),
                'dependents_count' => (int)   ($user->dependents_count ?? 0),
                'pit_tax_code'     => $user->pit_tax_code,
            ],
            'roles'               => Role::orderBy('name')->get(['id', 'name', 'code', 'description']),
            'allPermissions'      => $allPermissions,
            'userOverrides'       => $userOverrides,
            'computedPermissions' => $computedPermissions,
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'name'             => ['required', 'string', 'max:255'],
            'email'            => ['required', 'email', 'unique:users,email,' . $user->id],
            'password'         => ['nullable', 'confirmed', Rules\Password::defaults()],
            'phone'            => ['nullable', 'string', 'max:20'],
            'is_active'        => ['boolean'],
            'role_ids'         => ['required', 'array'],
            'role_ids.*'       => ['integer', 'exists:roles,id'],
            'base_salary'      => ['nullable', 'numeric', 'min:0'],
            'allowance'        => ['nullable', 'numeric', 'min:0'],
            'dependents_count' => ['nullable', 'integer', 'min:0', 'max:10'],
            'pit_tax_code'     => ['nullable', 'string', 'max:20'],
            'overrides'        => ['nullable', 'array'], // key is permission_id, value is 'allow'|'deny'|'default'
        ]);

        $oldRoles = $user->roles()->pluck('name')->toArray();
        $oldOverrides = $user->permissions()->get()->map(fn ($p) => "{$p->code}:{$p->pivot->effect}")->toArray();

        $user->update([
            'name'             => $data['name'],
            'email'            => $data['email'],
            'phone'            => $data['phone']            ?? null,
            'is_active'        => $data['is_active']        ?? true,
            'base_salary'      => $data['base_salary']      ?? $user->base_salary,
            'allowance'        => $data['allowance']        ?? $user->allowance,
            'dependents_count' => $data['dependents_count'] ?? 0,
            'pit_tax_code'     => $data['pit_tax_code']     ?? null,
        ]);

        if (! empty($data['password'])) {
            $user->update(['password' => Hash::make($data['password'])]);
        }

        // Sync Roles
        $user->roles()->sync($data['role_ids']);

        // Process Overrides
        $syncData = [];
        if (isset($data['overrides']) && is_array($data['overrides'])) {
            foreach ($data['overrides'] as $permId => $effect) {
                if ($effect === 'allow' || $effect === 'deny') {
                    $syncData[$permId] = ['effect' => $effect];
                }
            }
        }
        $user->permissions()->sync($syncData);

        // Audit Logs details
        $newRoles = Role::whereIn('id', $data['role_ids'])->pluck('name')->toArray();
        $newOverrides = $user->permissions()->get()->map(fn ($p) => "{$p->code}:{$p->pivot->effect}")->toArray();

        activity()
            ->causedBy(auth()->user())
            ->performedOn($user)
            ->withProperties([
                'roles_before'     => $oldRoles,
                'roles_after'      => $newRoles,
                'overrides_before' => $oldOverrides,
                'overrides_after'  => $newOverrides,
            ])
            ->log('Cập nhật phân quyền người dùng: ' . $user->name);

        return redirect()->route('admin.users.index')
            ->with('success', 'Cập nhật tài khoản thành công.');
    }

    public function destroy(User $user): RedirectResponse
    {
        if ($user->id === auth()->id()) {
            return back()->with('error', 'Không thể xóa tài khoản đang đăng nhập.');
        }

        $name = $user->name;
        $user->delete();

        activity()
            ->causedBy(auth()->user())
            ->log('Xóa người dùng: ' . $name);

        return redirect()->route('admin.users.index')
            ->with('success', 'Đã xóa tài khoản.');
    }
}
