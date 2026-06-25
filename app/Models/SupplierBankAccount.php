<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplierBankAccount extends Model
{
    protected $fillable = [
        'supplier_id', 'bank_name', 'account_number', 'normalized_account_number',
        'account_name', 'branch', 'is_primary', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'is_active'  => 'boolean',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function bankTransactions(): HasMany
    {
        return $this->hasMany(BankTransaction::class, 'supplier_bank_account_id');
    }
}
