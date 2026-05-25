<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderOverDelivery extends Model
{
    protected $fillable = [
        'order_id',
        'product_id',
        'product_name',
        'over_quantity',
        'resolved_by_order_id',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function resolvedByOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'resolved_by_order_id');
    }

    public function scopeUnresolved($query)
    {
        return $query->whereNull('resolved_at');
    }
}
