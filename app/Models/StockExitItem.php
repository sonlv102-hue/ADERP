<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockExitItem extends Model
{
    protected $fillable = [
        'stock_exit_id', 'project_id',
        'product_id', 'order_item_id',
        'quantity', 'unit_price', 'source_cost', 'total_cost', 'cost_source',
    ];

    protected function casts(): array
    {
        return [
            'unit_price'  => 'decimal:2',
            'source_cost' => 'decimal:2',
            'total_cost'  => 'decimal:2',
            'quantity'    => 'decimal:3',
        ];
    }

    public function stockExit(): BelongsTo
    {
        return $this->belongsTo(StockExit::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(\App\Models\OrderItem::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function lotAllocations(): HasMany
    {
        return $this->hasMany(StockExitItemLotAllocation::class);
    }

    public function serials(): HasMany
    {
        return $this->hasMany(ProductSerial::class);
    }
}
