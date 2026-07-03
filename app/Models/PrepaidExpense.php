<?php

namespace App\Models;

use App\Enums\PrepaidExpenseStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class PrepaidExpense extends Model
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['allocation_status', 'paused_at', 'pause_reason', 'resumed_at', 'resumed_by'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected $fillable = [
        'code', 'description', 'supplier_id', 'account_code', 'expense_account',
        'total_amount', 'start_date', 'months', 'monthly_amount',
        'amortized_amount', 'status', 'notes', 'created_by',
        'is_opening_balance', 'opening_balance_period', 'opening_balance_note',
        'opening_periods_elapsed', 'opening_journal_entry_id',
        'allocation_status', 'paused_at', 'paused_by', 'pause_effective_period', 'pause_reason',
        'resumed_at', 'resumed_by',
    ];

    protected function casts(): array
    {
        return [
            'status'             => PrepaidExpenseStatus::class,
            'start_date'         => 'date',
            'total_amount'       => 'decimal:0',
            'monthly_amount'     => 'decimal:0',
            'amortized_amount'   => 'decimal:0',
            'is_opening_balance' => 'boolean',
            'paused_at'          => 'datetime',
            'resumed_at'         => 'datetime',
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

    public function pausedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paused_by');
    }

    public function resumedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resumed_by');
    }

    public function openingJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'opening_journal_entry_id');
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
        return (float) $this->total_amount - (float) $this->amortized_amount;
    }

    public function allocatedMonths(): int
    {
        return $this->allocations()->count();
    }

    public function isPaused(): bool               { return $this->allocation_status === 'paused'; }
    public function isAllocationCompleted(): bool  { return $this->allocation_status === 'completed'; }
    public function canPauseAllocation(): bool     { return $this->status === PrepaidExpenseStatus::Active && $this->allocation_status === 'active'; }
    public function canResumeAllocation(): bool    { return $this->allocation_status === 'paused'; }
}
