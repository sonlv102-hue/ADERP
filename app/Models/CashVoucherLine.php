<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashVoucherLine extends Model
{
    protected $fillable = [
        'cash_voucher_id',
        'debit_account',
        'credit_account',
        'amount',
        'description',
        'partner_type',
        'partner_id',
        'sort_order',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function cashVoucher(): BelongsTo
    {
        return $this->belongsTo(CashVoucher::class);
    }

    /** Tên đối tác để hiển thị (tra cứu qua partner_type + partner_id) */
    public function partnerName(): ?string
    {
        return match ($this->partner_type) {
            'customer' => Customer::find($this->partner_id)?->name,
            'supplier' => Supplier::find($this->partner_id)?->name,
            'employee' => Employee::find($this->partner_id)?->name,
            default    => null,
        };
    }
}
