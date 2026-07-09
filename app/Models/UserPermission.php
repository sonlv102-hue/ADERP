<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class UserPermission extends Pivot
{
    protected $table = 'user_permissions';

    public $incrementing = false;

    protected $fillable = ['user_id', 'permission_id', 'effect'];
}
