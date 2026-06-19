<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierAdvanceAllocation extends Model
{
    protected $fillable = [
        'supplier_id', 'opening_advance_id', 'purchase_invoice_id',
        'ar_ap_opening_balance_id', 'journal_entry_id', 'reversal_entry_id',
        'allocation_date', 'allocated_amount', 'status', 'reason',
        'created_by', 'reversed_by', 'reversed_at', 'reverse_reason',
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

    public function openingBalance(): BelongsTo
    {
        return $this->belongsTo(ArApOpeningBalance::class, 'ar_ap_opening_balance_id');
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

    public function reversalEntry(): BelongsTo
    {
        return $this->belongsTo(\App\Models\JournalEntry::class, 'reversal_entry_id');
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
