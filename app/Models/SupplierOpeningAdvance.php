<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplierOpeningAdvance extends Model
{
    protected $fillable = [
        'supplier_id', 'fiscal_year', 'opening_date', 'account_code',
        'amount', 'remaining_amount', 'currency', 'reference_no',
        'bank_transaction_ref', 'original_payment_date', 'original_payment_note',
        'status', 'notes', 'created_by',
    ];

    protected $casts = [
        'opening_date'          => 'date',
        'original_payment_date' => 'date',
        'amount'                => 'decimal:2',
        'remaining_amount'      => 'decimal:2',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(SupplierAdvanceAllocation::class, 'opening_advance_id');
    }

    public function activeAllocations(): HasMany
    {
        return $this->hasMany(SupplierAdvanceAllocation::class, 'opening_advance_id')
            ->where('status', 'active');
    }

    public function scopeAvailable($query)
    {
        return $query->whereIn('status', ['open', 'partially_applied'])
            ->where('remaining_amount', '>', 0);
    }

    public function isAvailable(): bool
    {
        return in_array($this->status, ['open', 'partially_applied'])
            && (float) $this->remaining_amount > 0;
    }

    public function statusLabel(): string
    {
        return match($this->status) {
            'open'              => 'Còn dư',
            'partially_applied' => 'Đối trừ một phần',
            'fully_applied'     => 'Đã đối trừ hết',
            'cancelled'         => 'Đã hủy',
            default             => $this->status,
        };
    }
}
