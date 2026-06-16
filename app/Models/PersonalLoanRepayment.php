<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PersonalLoanRepayment extends Model
{
    protected $fillable = [
        'personal_loan_id', 'fund_id', 'repayment_date', 'amount',
        'description', 'journal_entry_id', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount'         => 'decimal:0',
            'repayment_date' => 'date',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(PersonalLoan::class, 'personal_loan_id');
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
}
