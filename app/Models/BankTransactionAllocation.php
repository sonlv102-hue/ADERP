<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankTransactionAllocation extends Model
{
    protected $fillable = [
        'bank_transaction_id', 'party_type', 'party_id',
        'target_type', 'target_id', 'account_code',
        'allocated_amount', 'journal_entry_id', 'status',
        'cancel_reason', 'cancelled_by', 'cancelled_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'allocated_amount' => 'decimal:0',
            'cancelled_at'     => 'datetime',
        ];
    }

    public function bankTransaction(): BelongsTo
    {
        return $this->belongsTo(BankTransaction::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function targetDocument(): Model|null
    {
        return match($this->target_type) {
            'invoice'          => Invoice::find($this->target_id),
            'purchase_invoice' => PurchaseInvoice::find($this->target_id),
            default            => null,
        };
    }
}
