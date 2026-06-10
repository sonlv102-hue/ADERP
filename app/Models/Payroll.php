<?php

namespace App\Models;

use App\Enums\PayrollStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payroll extends Model
{
    protected $fillable = [
        'code', 'period', 'status',
        'total_base_salary', 'total_allowance', 'total_bonus',
        'total_gross', 'total_insurance_employee', 'total_insurance_employer',
        'total_trade_union_fee',
        'total_pit', 'total_deductions', 'total_net_salary',
        'created_by', 'notes',
        'is_locked', 'locked_by', 'locked_at',
        'union_fee_include', 'union_fee_confirmed_by', 'union_fee_confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'status'                   => PayrollStatus::class,
            'is_locked'                => 'boolean',
            'locked_at'                => 'datetime',
            'total_base_salary'        => 'decimal:0',
            'total_allowance'          => 'decimal:0',
            'total_bonus'              => 'decimal:0',
            'total_gross'              => 'decimal:0',
            'total_insurance_employee' => 'decimal:0',
            'total_insurance_employer' => 'decimal:0',
            'total_trade_union_fee'    => 'decimal:0',
            'total_pit'                => 'decimal:0',
            'total_deductions'         => 'decimal:0',
            'total_net_salary'         => 'decimal:0',
            'union_fee_include'        => 'boolean',
            'union_fee_confirmed_at'   => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(PayrollItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function locker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by');
    }

    public function unionFeeConfirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'union_fee_confirmed_by');
    }

    public static function generateCode(string $period): string
    {
        $cleanPeriod = str_replace('-', '', $period); // YYYY-MM -> YYYYMM
        return 'BL-' . $cleanPeriod;
    }
}
