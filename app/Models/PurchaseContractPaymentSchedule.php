<?php

namespace App\Models;

use App\Enums\PaymentScheduleStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseContractPaymentSchedule extends Model
{
    protected $fillable = [
        'purchase_contract_id',
        'name',
        'percentage',
        'amount',
        'due_date',
        'status',
        'paid_date',
        'payment_method',
        'payment_reference',
        'notes',
        'created_by',
    ];

    protected static function booted(): void
    {
        static::saved(function ($schedule) {
            app(\App\Services\SupplierAdvanceService::class)->syncPrepaymentForSchedule($schedule);
        });

        static::deleting(function ($schedule) {
            $prepayment = \App\Models\SupplierOpeningAdvance::where('payment_schedule_id', $schedule->id)->first();
            if ($prepayment) {
                $isPaidOrUsed = ($prepayment->remaining_amount < $prepayment->amount)
                    || ((float)$prepayment->refunded_amount > 0)
                    || $prepayment->activeAllocations()->exists()
                    || \App\Models\CashVoucher::where('reference_type', \App\Models\SupplierOpeningAdvance::class)
                        ->where('reference_id', $prepayment->id)
                        ->where('status', \App\Enums\CashVoucherStatus::Confirmed->value)
                        ->exists();

                if ($isPaidOrUsed) {
                    throw new \RuntimeException('Dòng lịch thanh toán này đã phát sinh bản ghi Tiền trả trước NCC. Vui lòng xử lý bản ghi trả trước trước khi xóa.');
                } else {
                    app(\App\Services\SupplierAdvanceService::class)->deleteSafely($prepayment, 'Xóa dòng lịch thanh toán của hợp đồng mua.');
                }
            }
        });
    }

    protected function casts(): array
    {
        return [
            'status'   => PaymentScheduleStatus::class,
            'due_date' => 'date',
            'paid_date'=> 'date',
            'percentage' => 'decimal:2',
            'amount'     => 'decimal:2',
        ];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(PurchaseContract::class, 'purchase_contract_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Tính overdue động: quá hạn và chưa thanh toán */
    public function getIsOverdueAttribute(): bool
    {
        return $this->status === PaymentScheduleStatus::Pending
            && $this->due_date
            && $this->due_date->isPast();
    }

    /** Trả về status thực tế (kể cả overdue động) */
    public function getEffectiveStatusAttribute(): PaymentScheduleStatus
    {
        if ($this->is_overdue) {
            return PaymentScheduleStatus::Overdue;
        }
        return $this->status;
    }
}
