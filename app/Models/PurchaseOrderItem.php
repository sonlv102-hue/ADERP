<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrderItem extends Model
{
    protected $fillable = [
        'purchase_order_id', 'project_id',
        'product_id', 'line_type', 'quantity', 'received_quantity', 'unit_price', 'vat_rate',
    ];

    protected function casts(): array
    {
        return [
            'unit_price'        => 'decimal:2',
            'vat_rate'          => 'decimal:2',
            'quantity'          => 'integer',
            'received_quantity' => 'decimal:3',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function stockEntryItems(): HasMany
    {
        return $this->hasMany(StockEntryItem::class);
    }

    public function projectInventoryLots(): HasMany
    {
        return $this->hasMany(ProjectInventoryLot::class);
    }
}
