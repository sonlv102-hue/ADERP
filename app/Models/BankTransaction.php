<?php

namespace App\Models;

use App\Enums\BankTransactionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankTransaction extends Model
{
    protected $fillable = [
        'bank_account_id', 'transaction_date', 'value_date',
        'description', 'reference', 'debit', 'credit', 'running_balance',
        'status', 'journal_entry_id', 'reconciled_at', 'reconciled_by',
        'import_batch', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status'           => BankTransactionStatus::class,
            'transaction_date' => 'date',
            'value_date'       => 'date',
            'reconciled_at'    => 'datetime',
            'debit'            => 'decimal:0',
            'credit'           => 'decimal:0',
            'running_balance'  => 'decimal:0',
        ];
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function reconciledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reconciled_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
