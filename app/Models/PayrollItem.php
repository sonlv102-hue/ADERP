<?php

namespace App\Models;

use App\Enums\PayrollItemStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollItem extends Model
{
    protected $fillable = [
        'payroll_id',
        'user_id',
        'base_salary',
        'allowance',
        'bonus',
        'deductions',
        'net_salary',
        'status',
        'paid_at',
        'cash_voucher_id',
    ];

    protected function casts(): array
    {
        return [
            'status'          => PayrollItemStatus::class,
            'base_salary'     => 'decimal:2',
            'allowance'       => 'decimal:2',
            'bonus'           => 'decimal:2',
            'deductions'      => 'decimal:2',
            'net_salary'      => 'decimal:2',
            'paid_at'         => 'datetime',
            'cash_voucher_id' => 'integer',
        ];
    }

    public function payroll(): BelongsTo
    {
        return $this->belongsTo(Payroll::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function cashVoucher(): BelongsTo
    {
        return $this->belongsTo(CashVoucher::class);
    }
}
