<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryCountItem extends Model
{
    protected $fillable = [
        'inventory_count_id', 'product_id', 'system_quantity', 'counted_quantity', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'system_quantity'  => 'float',
            'counted_quantity' => 'float',
        ];
    }

    public function inventoryCount(): BelongsTo
    {
        return $this->belongsTo(InventoryCount::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function getDifferenceAttribute(): float
    {
        return ($this->counted_quantity ?? 0) - $this->system_quantity;
    }
}
