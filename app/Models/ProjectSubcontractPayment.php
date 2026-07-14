<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectSubcontractPayment extends Model
{
    protected $table = 'project_subcontract_payments';

    protected $fillable = [
        'subcontract_id', 'project_id', 'payment_date', 'amount', 'payment_method',
        'fund_id', 'bank_account_id', 'cash_voucher_id', 'bank_transaction_id', 'journal_entry_id',
        'pit_withholding_enabled', 'pit_rate', 'pit_amount',
        'status', 'cancel_reason', 'cancelled_by', 'cancelled_at', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'payment_date'            => 'date',
            'amount'                  => 'decimal:2',
            'pit_withholding_enabled' => 'boolean',
            'pit_rate'                => 'decimal:2',
            'pit_amount'              => 'decimal:2',
            'cancelled_at'            => 'datetime',
        ];
    }

    public function subcontract(): BelongsTo
    {
        return $this->belongsTo(ProjectSubcontract::class, 'subcontract_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
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
