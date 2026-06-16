<?php

namespace App\Models;

use App\Enums\PersonalLoanStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PersonalLoan extends Model
{
    protected $fillable = [
        'loan_no', 'lender_type', 'employee_id', 'shareholder_id', 'lender_name',
        'amount', 'repaid_amount', 'interest_rate', 'loan_date', 'due_date',
        'purpose', 'fund_id', 'status', 'journal_entry_id', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount'        => 'decimal:0',
            'repaid_amount' => 'decimal:0',
            'interest_rate' => 'decimal:2',
            'loan_date'     => 'date',
            'due_date'      => 'date',
            'status'        => PersonalLoanStatus::class,
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function shareholder(): BelongsTo
    {
        return $this->belongsTo(Shareholder::class);
    }

    public function fund(): BelongsTo
    {
        return $this->belongsTo(Fund::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function repayments(): HasMany
    {
        return $this->hasMany(PersonalLoanRepayment::class)->orderByDesc('repayment_date');
    }

    public function remainingAmount(): float
    {
        return (float) $this->amount - (float) $this->repaid_amount;
    }

    public function lenderName(): string
    {
        return match ($this->lender_type) {
            'employee'    => $this->employee?->name ?? $this->lender_name ?? '—',
            'shareholder' => $this->shareholder?->name ?? $this->lender_name ?? '—',
            default       => $this->lender_name ?? '—',
        };
    }

    public static function generateNo(): string
    {
        $last = static::max('id') ?? 0;
        return 'PVay-' . str_pad($last + 1, 4, '0', STR_PAD_LEFT);
    }
}
