<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InternalBankAccount extends Model
{
    protected $fillable = [
        'name', 'account_number', 'bank_name',
        'owner_name', 'description', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
