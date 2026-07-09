<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Permission extends Model
{
    protected $fillable = ['module', 'menu_key', 'action', 'code', 'name', 'description', 'guard_name'];

    protected static function boot()
    {
        parent::boot();
        static::saving(function ($permission) {
            if (empty($permission->code)) {
                $permission->code = $permission->name;
            }
            if (empty($permission->module)) {
                $permission->module = 'old_compat';
            }
            if (empty($permission->menu_key)) {
                $permission->menu_key = 'compatibility';
            }
            if (empty($permission->action)) {
                $parts = explode('.', $permission->name);
                $permission->action = end($parts);
            }
        });
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_permissions', 'permission_id', 'role_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_permissions', 'permission_id', 'user_id')
            ->withPivot('effect')
            ->withTimestamps();
    }
}
