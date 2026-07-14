<?php

namespace App\Models;

use App\Enums\SubcontractCostGroup;
use App\Enums\SubcontractorType;
use App\Enums\SubcontractStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class ProjectSubcontract extends Model
{
    protected $fillable = [
        'project_id', 'contractor_id', 'contractor_name', 'contractor_type',
        'contract_no', 'contract_date', 'scope_of_work', 'cost_group',
        'amount_before_vat', 'vat_rate', 'vat_amount', 'total_amount',
        'advance_rate', 'advance_amount', 'retention_rate', 'retention_amount',
        'start_date', 'end_date', 'status', 'notes', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'contractor_type'   => SubcontractorType::class,
            'cost_group'        => SubcontractCostGroup::class,
            'status'            => SubcontractStatus::class,
            'contract_date'     => 'date',
            'start_date'        => 'date',
            'end_date'          => 'date',
            'amount_before_vat' => 'decimal:2',
            'vat_rate'          => 'decimal:2',
            'vat_amount'        => 'decimal:2',
            'total_amount'      => 'decimal:2',
            'advance_rate'      => 'decimal:2',
            'advance_amount'    => 'decimal:2',
            'retention_rate'    => 'decimal:2',
            'retention_amount'  => 'decimal:2',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function contractor(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'contractor_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function acceptances(): HasMany
    {
        return $this->hasMany(ProjectSubcontractAcceptance::class, 'subcontract_id');
    }

    public function advances(): HasMany
    {
        return $this->hasMany(ProjectSubcontractAdvance::class, 'subcontract_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(ProjectSubcontractPayment::class, 'subcontract_id');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable')->latest();
    }

    /** Đã ghi nhận chi phí (= tổng acceptance posted, trước VAT) */
    public function acceptedTotal(): float
    {
        return (float) $this->acceptances()->where('status', 'posted')->sum('amount_before_vat');
    }

    /** Đã thanh toán */
    public function paidTotal(): float
    {
        return (float) $this->payments()->where('status', 'posted')->sum('amount');
    }

    /** Còn phải trả = tổng nghiệm thu (gồm VAT) - tạm ứng - đã thanh toán */
    public function amountDue(): float
    {
        $acceptedWithVat = (float) $this->acceptances()->where('status', 'posted')->sum('total_amount');
        return max(0.0, $acceptedWithVat - (float) $this->advance_amount - $this->paidTotal());
    }

    /** TK công nợ chính của hợp đồng: company→3312, team/individual→3388 (hoặc 3341 nếu cost_group=labor) */
    public function payableAccount(): string
    {
        if ($this->contractor_type === SubcontractorType::Company) {
            return '3312';
        }

        return $this->cost_group === SubcontractCostGroup::Labor ? '3341' : '3388';
    }
}
