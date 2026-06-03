<?php

namespace App\Models;

use App\Enums\EmployeeStatus;
use App\Enums\EmploymentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Employee extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'code', 'name', 'department', 'position',
        'phone', 'email', 'birth_date', 'gender',
        'hire_date', 'status', 'employment_type',
        'base_salary', 'allowance',
        'allowance_responsibility', 'allowance_lunch', 'allowance_phone', 'allowance_transport',
        'insurance_subject', 'standard_days',
        'dependents_count', 'pit_tax_code',
        'address', 'notes', 'created_by',
    ];

    protected $casts = [
        'birth_date'               => 'date',
        'hire_date'                => 'date',
        'status'                   => EmployeeStatus::class,
        'employment_type'          => EmploymentType::class,
        'base_salary'              => 'decimal:0',
        'allowance'                => 'decimal:0',
        'allowance_responsibility' => 'decimal:0',
        'allowance_lunch'          => 'decimal:0',
        'allowance_phone'          => 'decimal:0',
        'allowance_transport'      => 'decimal:0',
        'insurance_subject'        => 'boolean',
        'standard_days'            => 'integer',
        'dependents_count'         => 'integer',
    ];

    /** Tổng phụ cấp không tính BHXH */
    public function totalAllowances(): float
    {
        return (float)($this->allowance_responsibility ?? 0)
            + (float)($this->allowance_lunch           ?? 0)
            + (float)($this->allowance_phone           ?? 0)
            + (float)($this->allowance_transport       ?? 0)
            + (float)($this->allowance                 ?? 0); // allowance cũ / khác
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public static function generateCode(): string
    {
        $last = self::withTrashed()->orderByDesc('id')->value('code');
        $num = $last ? ((int) substr($last, 3)) + 1 : 1;
        return 'NV-' . str_pad($num, 4, '0', STR_PAD_LEFT);
    }
}
