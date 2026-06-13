<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockExitItemLotAllocation extends Model
{
    protected $fillable = [
        'stock_exit_id',
        'stock_exit_item_id',
        'project_inventory_lot_id',
        'project_id',
        'product_id',
        'warehouse_id',
        'allocated_qty',
        'unit_cost',
        'amount',
        'voided_at',
    ];

    protected $casts = [
        'allocated_qty' => 'decimal:3',
        'unit_cost'     => 'decimal:2',
        'amount'        => 'decimal:2',
        'voided_at'     => 'datetime',
    ];

    // ─── Relationships ─────────────────────────────────────────────────────────

    public function stockExit(): BelongsTo
    {
        return $this->belongsTo(StockExit::class);
    }

    public function stockExitItem(): BelongsTo
    {
        return $this->belongsTo(StockExitItem::class);
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(ProjectInventoryLot::class, 'project_inventory_lot_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    // ─── Scopes ────────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->whereNull('voided_at');
    }

    public function scopeVoided($query)
    {
        return $query->whereNotNull('voided_at');
    }
}
