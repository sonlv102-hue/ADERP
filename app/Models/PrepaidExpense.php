<?php

namespace App\Models;

use App\Enums\PrepaidExpenseStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PrepaidExpense extends Model
{
    protected $fillable = [
        'code', 'description', 'supplier_id', 'account_code', 'expense_account',
        'total_amount', 'start_date', 'months', 'monthly_amount',
        'amortized_amount', 'status', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status'           => PrepaidExpenseStatus::class,
            'start_date'       => 'date',
            'total_amount'     => 'decimal:0',
            'monthly_amount'   => 'decimal:0',
            'amortized_amount' => 'decimal:0',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PrepaidExpenseAllocation::class)->orderBy('period');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public static function generateCode(): string
    {
        $last = static::max('id') ?? 0;
        return 'CPT-' . str_pad($last + 1, 4, '0', STR_PAD_LEFT);
    }

    public function endDate(): \Carbon\Carbon
    {
        return $this->start_date->copy()->addMonths($this->months - 1)->endOfMonth();
    }

    public function remainingAmount(): float
    {
        return max(0.0, (float) $this->total_amount - (float) $this->amortized_amount);
    }

    public function allocatedMonths(): int
    {
        return $this->allocations()->count();
    }
}
