<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    protected $fillable = [
        'order_id', 'product_id', 'service_id',
        'name', 'unit', 'quantity', 'delivered_quantity', 'unit_price',
    ];

    protected function casts(): array
    {
        return [
            'quantity'           => 'decimal:2',
            'delivered_quantity' => 'decimal:2',
            'unit_price'         => 'decimal:2',
        ];
    }

    public function lineTotal(): float
    {
        return (float) ($this->quantity * $this->unit_price);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}
