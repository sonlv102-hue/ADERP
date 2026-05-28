<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrepaidExpenseAllocation extends Model
{
    protected $fillable = [
        'prepaid_expense_id', 'period', 'amount', 'journal_entry_id',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:0'];
    }

    public function prepaidExpense(): BelongsTo
    {
        return $this->belongsTo(PrepaidExpense::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }
}
