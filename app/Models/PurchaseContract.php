<?php

namespace App\Models;

use App\Enums\PurchaseContractStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class PurchaseContract extends Model
{
    protected $fillable = [
        'code', 'supplier_id', 'purchase_order_id', 'title',
        'value', 'start_date', 'end_date', 'status',
        'file_path', 'file_name', 'notes', 'created_by',
    ];

    protected static function booted(): void
    {
        static::saved(function ($contract) {
            if ($contract->wasChanged(['purchase_order_id', 'supplier_id', 'value'])) {
                foreach ($contract->paymentSchedules as $schedule) {
                    if ($contract->wasChanged('value')) {
                        $schedule->update([
                            'amount' => round((float) $contract->value * (float) $schedule->percentage / 100, 0)
                        ]);
                    }
                    app(\App\Services\SupplierAdvanceService::class)->syncPrepaymentForSchedule($schedule);
                }
            }
        });
    }

    protected function casts(): array
    {
        return [
            'status'     => PurchaseContractStatus::class,
            'start_date' => 'date',
            'end_date'   => 'date',
            'value'      => 'decimal:2',
        ];
    }

    public static function generateCode(): string
    {
        $last = self::orderByDesc('id')->value('code');
        $num  = $last ? ((int) substr($last, 6)) + 1 : 1;
        return 'HD-MH-' . str_pad($num, 4, '0', STR_PAD_LEFT);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function paymentSchedules(): HasMany
    {
        return $this->hasMany(PurchaseContractPaymentSchedule::class)->orderBy('id');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable')->latest();
    }
}
