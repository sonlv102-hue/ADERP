<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountCode extends Model
{
    protected $primaryKey = 'code';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'code', 'name', 'type', 'normal_balance', 'balance_type',
        'parent_code', 'level', 'is_detail', 'is_active', 'notes',
    ];

    protected $casts = [
        'is_detail' => 'boolean',
        'is_active' => 'boolean',
        'level'     => 'integer',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(AccountCode::class, 'parent_code', 'code');
    }

    public function children(): HasMany
    {
        return $this->hasMany(AccountCode::class, 'parent_code', 'code');
    }

    public function journalLines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class, 'account_code', 'code');
    }

    /** Số dư tài khoản tính đến một ngày (tính từ journal entries đã post) */
    public function balance(?\Carbon\Carbon $asOf = null): float
    {
        $query = $this->journalLines()
            ->whereHas('entry', fn ($q) => $q->where('status', 'posted'));

        if ($asOf) {
            $query->whereHas('entry', fn ($q) => $q->where('entry_date', '<=', $asOf));
        }

        $debit  = (float) $query->sum('debit');
        $credit = (float) $query->sum('credit');

        return $this->normal_balance === 'debit' ? $debit - $credit : $credit - $debit;
    }

    public function typeLabel(): string
    {
        return match($this->type) {
            'asset'     => 'Tài sản',
            'liability' => 'Nợ phải trả',
            'equity'    => 'Vốn chủ sở hữu',
            'revenue'   => 'Doanh thu',
            'expense'   => 'Chi phí',
            'contra'    => 'Tài khoản điều chỉnh',
            default     => $this->type,
        };
    }
}
