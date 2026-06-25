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

    /**
     * Lấy tồn kho cho danh sách sản phẩm.
     * Ưu tiên inventory_balances (AVCO); fallback SUM(active movements) cho sản phẩm chưa có AVCO.
     *
     * @return \Illuminate\Support\Collection  keyed by product_id, value = qty (float)
     */
    public static function stockForProducts(array|\Illuminate\Support\Collection $productIds): \Illuminate\Support\Collection
    {
        $ids = collect($productIds)->filter()->unique()->values();
        if ($ids->isEmpty()) return collect();

        $balances = static::whereIn('product_id', $ids)
            ->selectRaw('product_id, SUM(qty_on_hand) as total')
            ->groupBy('product_id')
            ->pluck('total', 'product_id');

        $noBalance = $ids->diff($balances->keys());
        if ($noBalance->isNotEmpty()) {
            $fallback = StockMovement::whereIn('product_id', $noBalance)
                ->active()
                ->selectRaw('product_id, SUM(quantity) as total')
                ->groupBy('product_id')
                ->pluck('total', 'product_id');
            return $balances->union($fallback);
        }

        return $balances;
    }

    public static function stockForProduct(int $productId): float
    {
        return (float) static::stockForProducts([$productId])->get($productId, 0);
    }
}
