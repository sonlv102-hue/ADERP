<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerBankAccount extends Model
{
    protected $fillable = [
        'customer_id', 'bank_name', 'account_number', 'account_name',
        'branch', 'is_primary', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean', 'is_active' => 'boolean'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
