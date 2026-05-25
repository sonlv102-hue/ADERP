<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Fund extends Model
{
    protected $fillable = [
        'code', 'name', 'type', 'bank_name', 'bank_account_no',
        'opening_balance', 'is_active', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'opening_balance' => 'decimal:2',
            'is_active'       => 'boolean',
        ];
    }

    public function cashVouchers(): HasMany
    {
        return $this->hasMany(CashVoucher::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function purchaseInvoicePayments(): HasMany
    {
        return $this->hasMany(PurchaseInvoicePayment::class);
    }

    public function balance(): float
    {
        $receipts = $this->cashVouchers()
            ->where('type', 'receipt')
            ->where('status', 'confirmed')
            ->sum('amount');

        $payments = $this->cashVouchers()
            ->where('type', 'payment')
            ->where('status', 'confirmed')
            ->sum('amount');

        $arReceived = $this->payments()->sum('amount');
        $apPaid     = $this->purchaseInvoicePayments()->sum('amount');

        return (float) $this->opening_balance + $receipts - $payments + $arReceived - $apPaid;
    }

    public static function generateCode(): string
    {
        $last = static::max('id') ?? 0;
        return 'QUY-' . str_pad($last + 1, 3, '0', STR_PAD_LEFT);
    }
}
