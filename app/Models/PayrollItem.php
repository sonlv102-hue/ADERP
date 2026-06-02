<?php

namespace App\Models;

use App\Enums\PayrollItemStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollItem extends Model
{
    protected $fillable = [
        'payroll_id', 'employee_id', 'user_id',
        'base_salary', 'allowance', 'bonus',
        'gross_salary', 'insurance_base',
        'bhxh_employee', 'bhyt_employee', 'bhtn_employee',
        'bhxh_employer', 'bhyt_employer', 'bhtn_employer',
        'pit', 'dependents_count',
        'deductions', 'net_salary',
        'status', 'paid_at', 'cash_voucher_id',
    ];

    protected function casts(): array
    {
        return [
            'status'           => PayrollItemStatus::class,
            'base_salary'      => 'decimal:0',
            'allowance'        => 'decimal:0',
            'bonus'            => 'decimal:0',
            'gross_salary'     => 'decimal:0',
            'insurance_base'   => 'decimal:0',
            'bhxh_employee'    => 'decimal:0',
            'bhyt_employee'    => 'decimal:0',
            'bhtn_employee'    => 'decimal:0',
            'bhxh_employer'    => 'decimal:0',
            'bhyt_employer'    => 'decimal:0',
            'bhtn_employer'    => 'decimal:0',
            'pit'              => 'decimal:0',
            'dependents_count' => 'integer',
            'deductions'       => 'decimal:0',
            'net_salary'       => 'decimal:0',
            'paid_at'          => 'datetime',
            'cash_voucher_id'  => 'integer',
        ];
    }

    public function payroll(): BelongsTo
    {
        return $this->belongsTo(Payroll::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Employee::class);
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
