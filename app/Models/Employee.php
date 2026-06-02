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
        'base_salary', 'allowance', 'dependents_count', 'pit_tax_code',
        'address', 'notes', 'created_by',
    ];

    protected $casts = [
        'birth_date'       => 'date',
        'hire_date'        => 'date',
        'status'           => EmployeeStatus::class,
        'employment_type'  => EmploymentType::class,
        'base_salary'      => 'decimal:0',
        'allowance'        => 'decimal:0',
        'dependents_count' => 'integer',
    ];

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
