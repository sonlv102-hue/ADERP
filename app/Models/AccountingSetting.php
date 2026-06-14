<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccountingSetting extends Model
{
    protected $fillable = ['key', 'value', 'label', 'description', 'group', 'sort_order'];
}
