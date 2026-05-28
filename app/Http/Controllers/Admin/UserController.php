<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Users/Index', [
            'users' => User::with('roles')
                ->orderBy('name')
                ->paginate(20)
                ->through(fn ($user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'is_active' => $user->is_active,
                    'roles' => $user->roles->pluck('name'),
                ]),
            'roles' => Role::orderBy('name')->pluck('name'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Users/Form', [
            'roles' => Role::orderBy('name')->pluck('name'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'phone' => ['nullable', 'string', 'max:20'],
            'role' => ['required', 'string', 'exists:roles,name'],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'phone' => $data['phone'] ?? null,
            'is_active' => true,
        ]);

        $user->assignRole($data['role']);

        return redirect()->route('admin.users.index')
            ->with('success', 'Tạo tài khoản thành công.');
    }

    public function edit(User $user): Response
    {
        return Inertia::render('Admin/Users/Form', [
            'user' => [
                'id'               => $user->id,
                'name'             => $user->name,
                'email'            => $user->email,
                'phone'            => $user->phone,
                'is_active'        => $user->is_active,
                'role'             => $user->roles->first()?->name,
                'base_salary'      => (float) ($user->base_salary ?? 0),
                'allowance'        => (float) ($user->allowance   ?? 0),
                'dependents_count' => (int)   ($user->dependents_count ?? 0),
                'pit_tax_code'     => $user->pit_tax_code,
            ],
            'roles' => Role::orderBy('name')->pluck('name'),
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
            'role'             => ['required', 'string', 'exists:roles,name'],
            'base_salary'      => ['nullable', 'numeric', 'min:0'],
            'allowance'        => ['nullable', 'numeric', 'min:0'],
            'dependents_count' => ['nullable', 'integer', 'min:0', 'max:10'],
            'pit_tax_code'     => ['nullable', 'string', 'max:20'],
        ]);

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

        $user->syncRoles([$data['role']]);

        return redirect()->route('admin.users.index')
            ->with('success', 'Cập nhật tài khoản thành công.');
    }

    public function destroy(User $user): RedirectResponse
    {
        if ($user->id === auth()->id()) {
            return back()->with('error', 'Không thể xóa tài khoản đang đăng nhập.');
        }

        $user->delete();

        return redirect()->route('admin.users.index')
            ->with('success', 'Đã xóa tài khoản.');
    }
}
