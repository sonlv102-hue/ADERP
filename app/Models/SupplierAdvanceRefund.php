<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierAdvanceRefund extends Model
{
    protected $fillable = [
        'supplier_advance_id', 'supplier_id', 'refund_date', 'amount',
        'refund_method', 'fund_id', 'bank_account_id', 'journal_entry_id',
        'description', 'status', 'created_by',
        'cancelled_by', 'cancelled_at', 'cancel_reason',
    ];

    protected $casts = [
        'refund_date'  => 'date',
        'amount'       => 'decimal:2',
        'cancelled_at' => 'datetime',
    ];

    public function advance(): BelongsTo
    {
        return $this->belongsTo(SupplierOpeningAdvance::class, 'supplier_advance_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function fund(): BelongsTo
    {
        return $this->belongsTo(Fund::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
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
