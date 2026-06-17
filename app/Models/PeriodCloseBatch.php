<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PeriodCloseBatch extends Model
{
    protected $fillable = [
        'code', 'accounting_period_id', 'fiscal_period', 'batch_type', 'status',
        'total_revenue', 'total_expense', 'profit_or_loss', 'journal_entry_count',
        'notes', 'created_by', 'posted_by', 'posted_at',
        'reversed_by', 'reversed_at', 'reverse_reason',
    ];

    protected $casts = [
        'total_revenue'       => 'integer',
        'total_expense'       => 'integer',
        'profit_or_loss'      => 'integer',
        'journal_entry_count' => 'integer',
        'posted_at'           => 'datetime',
        'reversed_at'         => 'datetime',
    ];

    public function accountingPeriod(): BelongsTo
    {
        return $this->belongsTo(AccountingPeriod::class);
    }

    public function journalEntries(): HasMany
    {
        return $this->hasMany(JournalEntry::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function postedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function reversedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    public function statusLabel(): string
    {
        return match($this->status) {
            'draft'    => 'Nháp',
            'posted'   => 'Đã kết chuyển',
            'reversed' => 'Đã đảo',
            'voided'   => 'Đã hủy',
            default    => $this->status,
        };
    }

    public function statusColor(): string
    {
        return match($this->status) {
            'draft'    => 'gray',
            'posted'   => 'green',
            'reversed' => 'yellow',
            'voided'   => 'red',
            default    => 'gray',
        };
    }

    public static function generateCode(string $fiscalPeriod): string
    {
        $prefix = 'KC-' . str_replace('-', '', $fiscalPeriod) . '-';
        $last   = static::where('code', 'like', $prefix . '%')->count();
        return $prefix . str_pad($last + 1, 3, '0', STR_PAD_LEFT);
    }
}
