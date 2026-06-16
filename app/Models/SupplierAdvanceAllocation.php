<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierAdvanceAllocation extends Model
{
    protected $fillable = [
        'supplier_id', 'opening_advance_id', 'purchase_invoice_id',
        'allocation_date', 'allocated_amount', 'status', 'reason',
        'created_by', 'reversed_by', 'reversed_at',
    ];

    protected $casts = [
        'allocation_date'  => 'date',
        'allocated_amount' => 'decimal:2',
        'reversed_at'      => 'datetime',
    ];

    public function advance(): BelongsTo
    {
        return $this->belongsTo(SupplierOpeningAdvance::class, 'opening_advance_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoice::class, 'purchase_invoice_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    public function isReversed(): bool
    {
        return $this->status === 'reversed';
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
