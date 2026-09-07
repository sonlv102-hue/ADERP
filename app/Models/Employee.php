<?php

namespace App\Models;

use App\Enums\EmployeeStatus;
use App\Enums\EmploymentType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Employee extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'code', 'name', 'department', 'position',
        'phone', 'email', 'birth_date', 'gender',
        'national_id', 'national_id_issue_date', 'national_id_issue_place',
        'hire_date', 'status', 'employment_type',
        'contract_start_date', 'contract_end_date',
        'termination_date', 'termination_reason', 'termination_note',
        'termination_decision_no', 'termination_decision_date', 'terminated_by', 'terminated_at',
        'base_salary', 'allowance',
        'allowance_responsibility', 'allowance_lunch', 'allowance_phone', 'allowance_transport',
        'insurance_subject', 'standard_days',
        'dependents_count', 'pit_tax_code',
        'social_insurance_no', 'bank_account_no', 'bank_name',
        'address', 'notes', 'created_by',
    ];

    protected $casts = [
        'birth_date'               => 'date',
        'hire_date'                => 'date',
        'national_id_issue_date'   => 'date',
        'contract_start_date'      => 'date',
        'contract_end_date'        => 'date',
        'termination_date'         => 'date',
        'termination_decision_date' => 'date',
        'terminated_at'            => 'datetime',
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

    /** Dùng chung giữa Index filter và Excel export để đảm bảo luôn khớp danh sách đang xem */
    public function scopeFilter(Builder $query, array $filters): Builder
    {
        $q      = $filters['q'] ?? null;
        $status = $filters['status'] ?? null;

        return $query
            ->when($q, fn ($sub) => $sub->where(function ($sq) use ($q) {
                $sq->where('name', 'ilike', "%{$q}%")
                  ->orWhere('code', 'ilike', "%{$q}%")
                  ->orWhere('department', 'ilike', "%{$q}%")
                  ->orWhere('position', 'ilike', "%{$q}%");
            }))
            ->when($status, fn ($sub) => $sub->where('status', $status));
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable')->latest();
    }

    public function terminatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'terminated_by');
    }

    /** NV đang có quan hệ lao động (active|probation). */
    public function scopeWorking(Builder $query): Builder
    {
        return $query->whereIn('status', EmployeeStatus::workingValues());
    }

    /**
     * NV còn hiệu lực lao động tại ngày $date — dùng cho dropdown chọn NV trên chứng từ.
     * Nguồn sự thật là termination_date; loại thêm NV legacy resigned/terminated không có ngày.
     */
    public function scopeActiveOn(Builder $query, string|\DateTimeInterface $date): Builder
    {
        $date = $date instanceof \DateTimeInterface ? $date->format('Y-m-d') : $date;

        return $query
            ->where(fn ($w) => $w->whereNull('termination_date')->orWhereDate('termination_date', '>=', $date))
            ->where(fn ($w) => $w->whereNotIn('status', EmployeeStatus::endedValues())->orWhereNotNull('termination_date'))
            ->where(fn ($w) => $w->whereNull('hire_date')->orWhereDate('hire_date', '<=', $date));
    }

    /**
     * NV có quan hệ lao động vào bất kỳ thời điểm nào trong kỳ YYYY-MM —
     * dùng khi lập bảng lương / bảng chấm công (NV nghỉ giữa tháng vẫn được tính tháng đó).
     */
    public function scopeEmployedDuring(Builder $query, string $period): Builder
    {
        $first = \Carbon\Carbon::createFromFormat('Y-m', $period)->startOfMonth()->toDateString();
        $last  = \Carbon\Carbon::createFromFormat('Y-m', $period)->endOfMonth()->toDateString();

        return $query
            ->where(fn ($w) => $w->whereNull('termination_date')->orWhereDate('termination_date', '>=', $first))
            ->where(fn ($w) => $w->whereNull('hire_date')->orWhereDate('hire_date', '<=', $last))
            ->where(fn ($w) => $w->whereNotIn('status', EmployeeStatus::endedValues())->orWhereNotNull('termination_date'));
    }

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
