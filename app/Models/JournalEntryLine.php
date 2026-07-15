<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JournalEntryLine extends Model
{
    protected $fillable = [
        'journal_entry_id', 'account_code', 'description',
        'debit', 'credit', 'sort_order', 'project_id',
        'cost_group', 'project_cost_note',
        'partner_type', 'partner_id', 'fixed_asset_id',
    ];

    protected $casts = [
        'debit'  => 'decimal:0',
        'credit' => 'decimal:0',
    ];

    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(AccountCode::class, 'account_code', 'code');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function fixedAsset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class);
    }
}
