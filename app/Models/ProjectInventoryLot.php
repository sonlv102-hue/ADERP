<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProjectInventoryLot extends Model
{
    protected $fillable = [
        'project_id',
        'product_id',
        'warehouse_id',
        'stock_entry_id',
        'stock_entry_item_id',
        'purchase_order_id',
        'purchase_order_item_id',
        'received_qty',
        'issued_qty',
        'unit_cost',
        'received_at',
        'status',
    ];

    protected $casts = [
        'received_qty' => 'decimal:3',
        'issued_qty'   => 'decimal:3',
        'unit_cost'    => 'decimal:2',
        'received_at'  => 'datetime',
    ];

    // ─── Relationships ─────────────────────────────────────────────────────────

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

    public function stockEntry(): BelongsTo
    {
        return $this->belongsTo(StockEntry::class);
    }

    public function stockEntryItem(): BelongsTo
    {
        return $this->belongsTo(StockEntryItem::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(StockExitItemLotAllocation::class);
    }

    // ─── Helpers ───────────────────────────────────────────────────────────────

    public function getAvailableQtyAttribute(): float
    {
        return (float) $this->received_qty - (float) $this->issued_qty;
    }

    public function isDepleted(): bool
    {
        return (float) $this->issued_qty >= (float) $this->received_qty;
    }
}
