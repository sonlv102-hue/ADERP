<?php

namespace App\Models;

use App\Enums\SerialStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class ProductSerial extends Model
{
    protected $fillable = [
        'product_id', 'warehouse_id', 'serial_number', 'status',
        'stock_entry_item_id', 'stock_exit_item_id', 'stock_transfer_item_id',
        'sales_return_item_id', 'purchase_return_item_id',
    ];

    protected function casts(): array
    {
        return ['status' => SerialStatus::class];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function stockEntryItem(): BelongsTo
    {
        return $this->belongsTo(StockEntryItem::class);
    }

    public function stockExitItem(): BelongsTo
    {
        return $this->belongsTo(StockExitItem::class);
    }

    public function stockTransferItem(): BelongsTo
    {
        return $this->belongsTo(StockTransferItem::class);
    }

    public function salesReturnItem(): BelongsTo
    {
        return $this->belongsTo(SalesReturnItem::class);
    }

    public function purchaseReturnItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseReturnItem::class);
    }

    public function transition(SerialStatus $newStatus): void
    {
        if (! in_array($newStatus, $this->status->allowedTransitions())) {
            throw new RuntimeException(
                "Cannot transition serial [{$this->serial_number}] from [{$this->status->value}] to [{$newStatus->value}]."
            );
        }
        $this->update(['status' => $newStatus]);
    }
}
