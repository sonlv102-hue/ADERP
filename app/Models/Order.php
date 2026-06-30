<?php

namespace App\Models;

use App\Enums\CustomsStatus;
use App\Enums\OrderStatus;
use App\Models\Concerns\GeneratesCode;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Order extends Model
{
    use GeneratesCode, LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['code', 'status', 'order_date', 'total_amount', 'customer_id'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected static string $codePrefix = 'DH-';
    protected static int    $codePad    = 4;

    protected $fillable = [
        'code', 'customer_id', 'quotation_id', 'supplementary_for_order_id', 'order_date',
        'expected_delivery', 'status', 'notes', 'created_by',
        'file_path', 'file_name',
        'customs_status', 'customs_declared_at', 'customs_document_path', 'customs_document_name', 'customs_notes',
    ];

    protected function casts(): array
    {
        return [
            'status'               => OrderStatus::class,
            'customs_status'       => CustomsStatus::class,
            'order_date'           => 'date',
            'expected_delivery'    => 'date',
            'customs_declared_at'  => 'datetime',
        ];
    }

    /** @deprecated Dùng nextCode() thay thế — an toàn hơn với concurrent requests */
    public static function generateCode(): string
    {
        return static::nextCode();
    }

    public function total(): float
    {
        return (float) $this->items->sum(fn ($item) =>
            $item->lineTotal() + (int) round($item->lineTotal() * ($item->vat_rate ?? 0) / 100)
        );
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function supplementaryForOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'supplementary_for_order_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function stockExits(): HasMany
    {
        return $this->hasMany(StockExit::class);
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable')->latest();
    }

    public function isFullyDelivered(): bool
    {
        return $this->items->every(fn ($i) => $i->delivered_quantity >= $i->quantity);
    }

    public function hasAnyDelivery(): bool
    {
        return $this->items->some(fn ($i) => $i->delivered_quantity > 0);
    }
}
