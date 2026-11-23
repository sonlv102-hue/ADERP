<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockEntryItem extends Model
{
    protected $fillable = [
        'stock_entry_id', 'purchase_order_item_id', 'project_id',
        'product_id', 'quantity', 'unit_price', 'unit_cost', 'tax_rate', 'tax_amount',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'unit_cost'  => 'decimal:2',
            'tax_rate'   => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'quantity'   => 'decimal:3',
        ];
    }

    /** Tiền VAT của dòng: số kế toán tự nhập (nếu có), không thì tính theo % VAT. */
    public function lineTax(): float
    {
        return $this->tax_amount !== null
            ? (float) $this->tax_amount
            : (float) $this->quantity * (float) $this->unit_price * (float) $this->tax_rate / 100;
    }

    public function stockEntry(): BelongsTo
    {
        return $this->belongsTo(StockEntry::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function projectInventoryLot(): HasMany
    {
        return $this->hasMany(ProjectInventoryLot::class);
    }

    public function serials(): HasMany
    {
        return $this->hasMany(ProductSerial::class);
    }
}
