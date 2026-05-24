<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\StockExit;

class Order extends Model
{
    protected $fillable = [
        'code', 'customer_id', 'quotation_id', 'order_date',
        'expected_delivery', 'status', 'notes', 'created_by',
        'file_path', 'file_name',
    ];

    protected function casts(): array
    {
        return [
            'status'            => OrderStatus::class,
            'order_date'        => 'date',
            'expected_delivery' => 'date',
        ];
    }

    public static function generateCode(): string
    {
        $last = self::orderByDesc('id')->value('code');
        $num = $last ? ((int) substr($last, 3)) + 1 : 1;
        return 'DH-' . str_pad($num, 4, '0', STR_PAD_LEFT);
    }

    public function total(): float
    {
        return (float) $this->items->sum(fn ($item) => $item->quantity * $item->unit_price);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
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

    public function stockExits(): HasMany
    {
        return $this->hasMany(StockExit::class);
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
