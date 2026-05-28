<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BankAccount extends Model
{
    protected $fillable = [
        'name', 'bank_name', 'account_number', 'account_code',
        'currency', 'opening_balance', 'is_active', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active'       => 'boolean',
            'opening_balance' => 'decimal:0',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(BankTransaction::class);
    }

    public function currentBalance(): float
    {
        $net = $this->transactions()->selectRaw('SUM(credit) - SUM(debit) as net')->value('net') ?? 0;
        return (float)$this->opening_balance + (float)$net;
    }
}
