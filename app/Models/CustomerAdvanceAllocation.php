<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerAdvanceAllocation extends Model
{
    protected $fillable = [
        'customer_id', 'opening_advance_id', 'invoice_id',
        'ar_ap_opening_balance_id', 'journal_entry_id',
        'allocation_date', 'allocated_amount',
        'status', 'reason', 'created_by', 'reversed_by', 'reversed_at',
    ];

    protected function casts(): array
    {
        return [
            'allocation_date' => 'date',
            'allocated_amount' => 'decimal:2',
            'reversed_at'    => 'datetime',
        ];
    }

    public function advance(): BelongsTo
    {
        return $this->belongsTo(CustomerOpeningAdvance::class, 'opening_advance_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function openingBalance(): BelongsTo
    {
        return $this->belongsTo(ArApOpeningBalance::class, 'ar_ap_opening_balance_id');
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
