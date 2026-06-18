<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmallToolAllocation extends Model
{
    protected $fillable = [
        'small_tool_id', 'period', 'period_start', 'period_end',
        'amount', 'accumulated_before', 'remaining_after',
        'debit_account', 'credit_account',
        'status', 'journal_entry_id',
        'posted_at', 'posted_by', 'reversed_at', 'reversed_by', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'period_start'       => 'date',
            'period_end'         => 'date',
            'amount'             => 'decimal:2',
            'accumulated_before' => 'decimal:2',
            'remaining_after'    => 'decimal:2',
            'posted_at'          => 'datetime',
            'reversed_at'        => 'datetime',
        ];
    }

    public function tool(): BelongsTo          { return $this->belongsTo(SmallTool::class, 'small_tool_id'); }
    public function journalEntry(): BelongsTo  { return $this->belongsTo(JournalEntry::class); }
    public function postedByUser(): BelongsTo  { return $this->belongsTo(User::class, 'posted_by'); }
    public function reversedByUser(): BelongsTo { return $this->belongsTo(User::class, 'reversed_by'); }

    public function isPosted(): bool   { return $this->status === 'posted'; }
    public function isPending(): bool  { return $this->status === 'pending'; }
    public function isReversed(): bool { return $this->status === 'reversed'; }
}
