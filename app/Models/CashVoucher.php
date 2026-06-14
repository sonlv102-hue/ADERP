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
        'counterparty', 'supplier_id', 'partner_type', 'customer_id', 'employee_id',
        'description', 'reference_type', 'reference_id', 'created_by',
        'business_type', 'journal_mode', 'edited_by_user', 'edit_reason',
    ];

    protected function casts(): array
    {
        return [
            'type'            => CashVoucherType::class,
            'status'          => CashVoucherStatus::class,
            'amount'          => 'decimal:2',
            'voucher_date'    => 'date',
            'edited_by_user'  => 'boolean',
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

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Supplier::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Customer::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Employee::class);
    }

    public function journalLines(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CashVoucherLine::class)->orderBy('sort_order');
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
