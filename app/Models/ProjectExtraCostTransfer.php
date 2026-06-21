<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectExtraCostTransfer extends Model
{
    protected $fillable = [
        'project_id', 'project_expense_id',
        'transfer_date', 'debit_account', 'credit_account',
        'amount', 'description', 'status',
        'journal_entry_id', 'reversal_journal_entry_id',
        'project_wip_entry_id',
        'created_by', 'cancelled_by', 'cancelled_at', 'cancel_reason',
    ];

    protected function casts(): array
    {
        return [
            'transfer_date' => 'date',
            'amount'        => 'integer',
            'cancelled_at'  => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(ProjectExpense::class, 'project_expense_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function reversalJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'reversal_journal_entry_id');
    }

    public function wipEntry(): BelongsTo
    {
        return $this->belongsTo(ProjectWipEntry::class, 'project_wip_entry_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function cancelledByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
