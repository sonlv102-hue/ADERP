<?php

namespace App\Models;

use App\Models\CashVoucher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseInvoicePayment extends Model
{
    protected $fillable = [
        'purchase_invoice_id', 'fund_id', 'cash_voucher_id', 'amount', 'payment_date',
        'method', 'reference', 'notes', 'created_by',
        'status', 'void_reason', 'voided_by', 'voided_at',
    ];

    protected function casts(): array
    {
        return [
            'payment_date' => 'date',
            'amount'       => 'decimal:2',
            'voided_at'    => 'datetime',
        ];
    }

    public function isVoided(): bool
    {
        return $this->status === 'voided';
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoice::class, 'purchase_invoice_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function cashVoucher(): BelongsTo
    {
        return $this->belongsTo(CashVoucher::class);
    }
}
