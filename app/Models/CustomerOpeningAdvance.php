<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerOpeningAdvance extends Model
{
    protected $fillable = [
        'customer_id', 'advance_type', 'source_type', 'source_id',
        'fiscal_year', 'advance_date', 'account_code',
        'amount', 'remaining_amount', 'currency',
        'reference_no', 'status', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'advance_date' => 'date',
            'amount'       => 'decimal:2',
            'remaining_amount' => 'decimal:2',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(CustomerAdvanceAllocation::class, 'opening_advance_id');
    }

    public function activeAllocations(): HasMany
    {
        return $this->allocations()->where('status', 'active');
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

    public function typeLabel(): string
    {
        return match ($this->advance_type) {
            'advance_receipt' => 'Nhận ứng trước trong kỳ',
            default           => 'Số dư đầu kỳ',
        };
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'open'              => 'Còn dư',
            'partially_applied' => 'Đối trừ một phần',
            'fully_applied'     => 'Đã đối trừ hết',
            'cancelled'         => 'Đã hủy',
            default             => $this->status,
        };
    }
}
