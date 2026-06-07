<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function bankTransactions(): HasMany
    {
        return $this->hasMany(BankTransaction::class, 'internal_account_id');
    }
}
