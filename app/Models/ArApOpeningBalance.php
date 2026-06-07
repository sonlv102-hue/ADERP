<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArApOpeningBalance extends Model
{
    protected $fillable = [
        'type', 'period', 'customer_id', 'supplier_id',
        'invoice_ref', 'invoice_date', 'due_date',
        'amount', 'remaining_amount', 'note',
        'journal_entry_id', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount'           => 'decimal:2',
            'remaining_amount' => 'decimal:2',
            'invoice_date'     => 'date',
            'due_date'         => 'date',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
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
