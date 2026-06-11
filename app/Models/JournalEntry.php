<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class JournalEntry extends Model
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['code', 'entry_date', 'description', 'status'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
    protected $fillable = [
        'code', 'entry_date', 'description', 'reference_type', 'reference_id',
        'status', 'is_auto', 'reversed_by_id', 'created_by', 'posted_at', 'notes',
        'source_type', 'fiscal_period', 'exclude_from_period_movement',
    ];

    protected $casts = [
        'entry_date'                   => 'date',
        'posted_at'                    => 'datetime',
        'is_auto'                      => 'boolean',
        'exclude_from_period_movement' => 'boolean',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class)->orderBy('sort_order');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'reversed_by_id');
    }

    public function totalDebit(): float
    {
        return (float) $this->lines->sum('debit');
    }

    public function totalCredit(): float
    {
        return (float) $this->lines->sum('credit');
    }

    public function isBalanced(): bool
    {
        return abs($this->totalDebit() - $this->totalCredit()) < 1;
    }

    public function statusLabel(): string
    {
        return match($this->status) {
            'draft'    => 'Nháp',
            'posted'   => 'Đã hạch toán',
            'reversed' => 'Đã đảo',
            default    => $this->status,
        };
    }

    public function statusColor(): string
    {
        return match($this->status) {
            'draft'    => 'gray',
            'posted'   => 'green',
            'reversed' => 'red',
            default    => 'gray',
        };
    }

    public static function generateCode(): string
    {
        $year = now()->year;
        $last = static::whereYear('created_at', $year)
            ->where('code', 'like', "BT-{$year}%")
            ->orderByDesc('code')
            ->value('code');

        $seq = $last ? ((int) substr($last, -4)) + 1 : 1;
        return sprintf('BT-%d%04d', $year, $seq);
    }
}
