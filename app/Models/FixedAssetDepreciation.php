<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FixedAssetDepreciation extends Model
{
    protected $fillable = [
        'fixed_asset_id', 'period', 'period_start', 'period_end',
        'amount', 'non_deductible_amount', 'accumulated_before', 'net_book_value_after',
        'status', 'journal_entry_id',
        'posted_at', 'reversed_at', 'posted_by', 'reversed_by', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'period_start'          => 'date',
            'period_end'            => 'date',
            'amount'                => 'decimal:2',
            'non_deductible_amount' => 'decimal:2',
            'accumulated_before'    => 'decimal:2',
            'net_book_value_after'  => 'decimal:2',
            'posted_at'             => 'datetime',
            'reversed_at'           => 'datetime',
        ];
    }

    public function fixedAsset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function postedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function reversedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    public function isPosted(): bool  { return $this->status === 'posted'; }
    public function isReversed(): bool { return $this->status === 'reversed'; }
}
