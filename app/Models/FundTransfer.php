<?php

namespace App\Models;

use App\Enums\FundTransferStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FundTransfer extends Model
{
    protected $fillable = [
        'transfer_no', 'transfer_date', 'from_fund_id', 'to_fund_id',
        'amount', 'description', 'status',
        'journal_entry_id', 'created_by', 'posted_by', 'posted_at',
        'reversed_by',
    ];

    protected function casts(): array
    {
        return [
            'transfer_date' => 'date',
            'amount'        => 'decimal:2',
            'status'        => FundTransferStatus::class,
            'posted_at'     => 'datetime',
        ];
    }

    public function fromFund(): BelongsTo
    {
        return $this->belongsTo(Fund::class, 'from_fund_id');
    }

    public function toFund(): BelongsTo
    {
        return $this->belongsTo(Fund::class, 'to_fund_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function reverser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    public static function generateNo(): string
    {
        $last = static::max('id') ?? 0;
        return 'LCQ-' . str_pad($last + 1, 4, '0', STR_PAD_LEFT);
    }
}
