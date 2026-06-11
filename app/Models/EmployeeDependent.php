<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class EmployeeDependent extends Model
{
    protected $fillable = [
        'employee_id',
        'dependent_name',
        'relationship',
        'tax_id',
        'start_date',
        'end_date',
        'documentation_notes',
        'registration_status',
        'created_by',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date'   => 'date',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Đếm số người phụ thuộc hợp lệ của một nhân viên trong tháng lương.
     * Điều kiện:
     *   - registration_status = 'approved'
     *   - start_date <= ngày cuối tháng
     *   - end_date IS NULL hoặc end_date >= ngày đầu tháng
     */
    public static function countForPayrollMonth(int $employeeId, Carbon $payrollMonth): int
    {
        $monthStart = $payrollMonth->copy()->startOfMonth();
        $monthEnd   = $payrollMonth->copy()->endOfMonth();

        return static::where('employee_id', $employeeId)
            ->where('registration_status', 'approved')
            ->where('start_date', '<=', $monthEnd->format('Y-m-d'))
            ->where(function ($q) use ($monthStart) {
                $q->whereNull('end_date')
                  ->orWhere('end_date', '>=', $monthStart->format('Y-m-d'));
            })
            ->count();
    }
}
