<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use App\Models\Role;
use App\Models\Permission;
use Illuminate\Support\Facades\DB;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, SoftDeletes, LogsActivity;

    public function roles(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles', 'user_id', 'role_id');
    }

    public function permissions(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'user_permissions', 'user_id', 'permission_id')
            ->withPivot('effect');
    }

    public function isSuperAdmin(): bool
    {
        return $this->roles()->whereIn('code', ['super_admin', 'admin'])->exists();
    }

    public function hasRole(string $roleCode): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }
        return $this->roles()->where('code', $roleCode)->exists();
    }

    public function getRoleNames()
    {
        return $this->roles->pluck('code');
    }

    public function hasPermission(string $permissionCode): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        $codesToCheck = [$permissionCode];

        $legacyMappings = [
            'warehouse.stock_entries.view'    => 'warehouse.view',
            'warehouse.stock_entries.create'  => 'warehouse.create',
            'warehouse.stock_entries.update'  => 'warehouse.update',
            'warehouse.stock_entries.delete'  => 'warehouse.delete',
            'warehouse.stock_entries.confirm' => ['warehouse.confirm', 'warehouse.create'],
            'warehouse.stock_exits.view'      => 'warehouse.view',
            'warehouse.stock_exits.create'    => 'warehouse.create',
            'warehouse.stock_exits.update'    => 'warehouse.update',
            'warehouse.stock_exits.delete'    => 'warehouse.delete',
            'warehouse.stock_exits.confirm'   => ['warehouse.confirm', 'warehouse.create'],
            
            'sales.orders.view'    => ['sales.view', 'quotations.view'],
            'sales.orders.create'  => ['sales.create', 'quotations.view'],
            'sales.orders.update'  => ['sales.update', 'quotations.view'],
            'sales.orders.delete'  => ['sales.delete', 'quotations.view'],
            'sales.orders.approve' => ['sales.approve', 'quotations.view'],
            'sales.orders.export'  => ['sales.export', 'quotations.view'],
            
            'purchases.orders.view'    => 'purchasing.view',
            'purchases.orders.create'  => 'purchasing.create',
            'purchases.orders.update'  => 'purchasing.update',
            'purchases.orders.delete'  => 'purchasing.delete',
            'purchases.orders.approve' => 'purchasing.approve',
            
            'purchases.invoices.view'   => 'purchasing.view',
            'purchases.invoices.create' => 'purchasing.create',
            'purchases.invoices.update' => 'purchasing.update',
            'purchases.invoices.delete' => 'purchasing.delete',
            'purchases.invoices.post'   => 'purchasing.approve',
            
            'accounting.journals.view'    => 'accounting.view',
            'accounting.journals.create'  => 'accounting.manage',
            'accounting.journals.update'  => 'accounting.manage',
            'accounting.journals.delete'  => 'accounting.manage',
            'accounting.journals.post'    => 'accounting.manage',
            'accounting.journals.reverse' => 'accounting.manage',
            
            'hr.employees.view'   => 'hr.view',
            'hr.employees.create' => 'hr.manage',
            'hr.employees.update' => 'hr.manage',
            'hr.employees.delete' => 'hr.manage',
        ];

        if (isset($legacyMappings[$permissionCode])) {
            $mapped = $legacyMappings[$permissionCode];
            if (is_array($mapped)) {
                $codesToCheck = array_merge($codesToCheck, $mapped);
            } else {
                $codesToCheck[] = $mapped;
            }
        }

        // 1. Check user-specific deny override first (Deny overrides win)
        $denyOverride = $this->permissions()
            ->whereIn('code', $codesToCheck)
            ->where('user_permissions.effect', 'deny')
            ->exists();
        if ($denyOverride) {
            return false;
        }

        // 2. Check user-specific allow override
        $allowOverride = $this->permissions()
            ->whereIn('code', $codesToCheck)
            ->where('user_permissions.effect', 'allow')
            ->exists();
        if ($allowOverride) {
            return true;
        }

        // 3. Fallback to role permissions
        return Permission::whereIn('code', $codesToCheck)
            ->whereHas('roles', function ($q) {
                $q->whereIn('roles.id', $this->roles()->pluck('roles.id'));
            })->exists();
    }

    public function getAllPermissions(): \Illuminate\Support\Collection
    {
        if ($this->isSuperAdmin()) {
            return Permission::all();
        }

        // Get allowed overrides
        $allowedOverrides = $this->permissions()
            ->where('user_permissions.effect', 'allow')
            ->get();

        // Get role permissions
        $rolePerms = Permission::whereHas('roles', function ($q) {
            $q->whereIn('roles.id', $this->roles()->pluck('roles.id'));
        })->get();

        // Get denied override IDs
        $deniedIds = $this->permissions()
            ->where('user_permissions.effect', 'deny')
            ->pluck('permissions.id')
            ->toArray();

        return $rolePerms->merge($allowedOverrides)
            ->reject(fn ($p) => in_array($p->id, $deniedIds))
            ->unique('id');
    }

    public function syncRoles($roles)
    {
        $roleIds = [];
        $rolesArray = ($roles instanceof \Illuminate\Support\Collection) ? $roles->all() : (is_array($roles) ? $roles : [$roles]);
        foreach ($rolesArray as $role) {
            if (is_string($role)) {
                $roleModel = Role::where('code', $role)->orWhere('name', $role)->first();
                if (!$roleModel) {
                    $roleModel = Role::create([
                        'name' => $role,
                        'code' => $role,
                        'is_system' => false
                    ]);
                }
                $roleIds[] = $roleModel->id;
            } elseif (is_numeric($role)) {
                $roleIds[] = $role;
            } elseif ($role instanceof Role) {
                $roleIds[] = $role->id;
            }
        }
        $this->roles()->sync($roleIds);
        return $this;
    }

    public function assignRole($role)
    {
        if (is_array($role) || $role instanceof \Illuminate\Support\Collection) {
            foreach ($role as $r) {
                $this->assignRole($r);
            }
            return $this;
        }

        if (is_string($role)) {
            $roleModel = Role::where('code', $role)->orWhere('name', $role)->first();
            if (!$roleModel) {
                $roleModel = Role::create([
                    'name' => $role,
                    'code' => $role,
                    'is_system' => false
                ]);
            }
        } elseif (is_numeric($role)) {
            $roleModel = Role::find($role);
        } else {
            $roleModel = $role;
        }

        if ($roleModel) {
            $this->roles()->syncWithoutDetaching([$roleModel->id]);
        }
        return $this;
    }

    public function givePermissionTo($permission)
    {
        if (is_array($permission) || $permission instanceof \Illuminate\Support\Collection) {
            foreach ($permission as $p) {
                $this->givePermissionTo($p);
            }
            return $this;
        }

        if (is_string($permission)) {
            $permModel = Permission::where('code', $permission)->orWhere('name', $permission)->first();
            if (!$permModel) {
                $permModel = Permission::create([
                    'name' => $permission,
                    'code' => $permission,
                    'module' => 'old_compat',
                    'menu_key' => 'compatibility',
                    'action' => 'view'
                ]);
            }
        } elseif (is_numeric($permission)) {
            $permModel = Permission::find($permission);
        } else {
            $permModel = $permission;
        }

        if ($permModel) {
            $this->permissions()->syncWithoutDetaching([$permModel->id => ['effect' => 'allow']]);
        }
        return $this;
    }

    public function syncPermissions($permissions)
    {
        $permIds = [];
        $permsArray = ($permissions instanceof \Illuminate\Support\Collection) ? $permissions->all() : (is_array($permissions) ? $permissions : [$permissions]);
        foreach ($permsArray as $perm) {
            if (is_string($perm)) {
                $permModel = Permission::where('code', $perm)->orWhere('name', $perm)->first();
                if (!$permModel) {
                    $permModel = Permission::create([
                        'name' => $perm,
                        'code' => $perm,
                        'module' => 'old_compat',
                        'menu_key' => 'compatibility',
                        'action' => 'view'
                    ]);
                }
                $permIds[] = $permModel->id;
            } elseif (is_numeric($perm)) {
                $permIds[] = $perm;
            } elseif ($perm instanceof Permission) {
                $permIds[] = $perm->id;
            }
        }
        
        $syncData = [];
        foreach ($permIds as $id) {
            $syncData[$id] = ['effect' => 'allow'];
        }
        $this->permissions()->sync($syncData);
        return $this;
    }

    public function hasPermissionTo($permission)
    {
        if (is_array($permission) || $permission instanceof \Illuminate\Support\Collection) {
            foreach ($permission as $p) {
                if ($this->hasPermissionTo($p)) {
                    return true;
                }
            }
            return false;
        }

        $code = is_string($permission) ? $permission : $permission->code;
        return $this->hasPermission($code);
    }

    public function revokePermissionTo($permission)
    {
        if (is_array($permission) || $permission instanceof \Illuminate\Support\Collection) {
            foreach ($permission as $p) {
                $this->revokePermissionTo($p);
            }
            return $this;
        }

        if (is_string($permission)) {
            $permModel = Permission::where('code', $permission)->orWhere('name', $permission)->first();
        } elseif (is_numeric($permission)) {
            $permModel = Permission::find($permission);
        } else {
            $permModel = $permission;
        }

        if ($permModel) {
            $this->permissions()->detach($permModel->id);
        }
        return $this;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'email', 'phone', 'is_active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected $fillable = [
        'name', 'email', 'password', 'phone', 'avatar',
        'is_active', 'base_salary', 'allowance',
        'dependents_count', 'pit_tax_code',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at'  => 'datetime',
            'password'           => 'hashed',
            'is_active'          => 'boolean',
            'base_salary'        => 'decimal:2',
            'allowance'          => 'decimal:2',
            'dependents_count'   => 'integer',
        ];
    }
}
