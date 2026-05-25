<?php

namespace App\Models;

use App\Enums\CashVoucherStatus;
use App\Enums\CashVoucherType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashVoucher extends Model
{
    protected $fillable = [
        'code', 'type', 'status', 'fund_id', 'amount', 'voucher_date',
        'counterparty', 'description', 'reference_type', 'reference_id', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'type'         => CashVoucherType::class,
            'status'       => CashVoucherStatus::class,
            'amount'       => 'decimal:2',
            'voucher_date' => 'date',
        ];
    }

    public function fund(): BelongsTo
    {
        return $this->belongsTo(Fund::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reference(): \Illuminate\Database\Eloquent\Relations\MorphTo
    {
        return $this->morphTo('reference');
    }

    public static function generateCode(CashVoucherType $type): string
    {
        $prefix = $type->codePrefix();
        $last   = static::where('type', $type->value)->max('id') ?? 0;
        return $prefix . '-' . str_pad($last + 1, 4, '0', STR_PAD_LEFT);
    }
}
