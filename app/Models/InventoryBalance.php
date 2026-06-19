<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryBalance extends Model
{
    protected $fillable = [
        'product_id',
        'warehouse_id',
        'qty_on_hand',
        'value_on_hand',
        'avg_cost',
        'last_movement_id',
        'initialized_from',
        'initialized_at',
    ];

    protected function casts(): array
    {
        return [
            'initialized_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function lastMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'last_movement_id');
    }
}
