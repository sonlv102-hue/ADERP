<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SupplierOpeningAdvance extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'supplier_id', 'advance_type', 'source_type', 'source_id',
        'purchase_contract_id', 'purchase_order_id', 'payment_schedule_id',
        'fiscal_year', 'opening_date', 'account_code',
        'amount', 'remaining_amount', 'refunded_amount', 'currency', 'reference_no',
        'bank_transaction_ref', 'original_payment_date', 'original_payment_note',
        'status', 'notes', 'created_by',
        'deleted_by', 'delete_reason',
    ];

    protected $casts = [
        'opening_date'          => 'date',
        'original_payment_date' => 'date',
        'amount'                => 'decimal:2',
        'remaining_amount'      => 'decimal:2',
        'refunded_amount'       => 'decimal:2',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function purchaseContract(): BelongsTo
    {
        return $this->belongsTo(PurchaseContract::class, 'purchase_contract_id');
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function paymentSchedule(): BelongsTo
    {
        return $this->belongsTo(PurchaseContractPaymentSchedule::class, 'payment_schedule_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(SupplierAdvanceAllocation::class, 'opening_advance_id');
    }

    public function activeAllocations(): HasMany
    {
        return $this->hasMany(SupplierAdvanceAllocation::class, 'opening_advance_id')
            ->where('status', 'active');
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(SupplierAdvanceRefund::class, 'supplier_advance_id')
            ->where('status', 'confirmed');
    }

    public function scopeAvailable($query)
    {
        return $query->whereIn('status', ['open', 'partially_applied'])
            ->where('remaining_amount', '>', 0);
    }

    public function isAvailable(): bool
    {
        return in_array($this->status, ['open', 'partially_applied'])
            && (float) $this->remaining_amount > 0;
    }

    public function isPrepayment(): bool
    {
        return $this->advance_type === 'prepayment';
    }

    public function typeLabel(): string
    {
        return match($this->advance_type) {
            'prepayment'      => 'Trả trước trong kỳ',
            'opening_balance' => 'Số dư đầu kỳ',
            default           => $this->advance_type,
        };
    }

    public function statusLabel(): string
    {
        return match($this->status) {
            'unpaid'            => 'Chờ thanh toán',
            'open'              => 'Đã ứng trước',
            'partially_applied' => 'Đối trừ một phần',
            'fully_applied'     => 'Đã đối trừ hết',
            'cancelled'         => 'Đã hủy',
            default             => $this->status,
        };
    }
}
