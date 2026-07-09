<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    protected $fillable = ['name', 'code', 'description', 'is_system', 'guard_name'];

    protected $casts = [
        'is_system' => 'boolean',
    ];

    protected static function boot()
    {
        parent::boot();
        static::saving(function ($role) {
            if (empty($role->code)) {
                $role->code = $role->name;
            }
        });
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
            $this->permissions()->syncWithoutDetaching([$permModel->id]);
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
        $this->permissions()->sync($permIds);
        return $this;
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions', 'role_id', 'permission_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_roles', 'role_id', 'user_id');
    }
}
