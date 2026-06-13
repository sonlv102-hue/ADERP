<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'code', 'category_id', 'item_type', 'revenue_account_code', 'inventory_account',
        'name', 'unit', 'cost_price', 'business_cost', 'vat_percent', 'total_cost',
        'sell_price', 'has_serial', 'warranty_months', 'min_stock', 'description', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'cost_price'    => 'decimal:2',
            'business_cost' => 'decimal:2',
            'vat_percent'   => 'decimal:2',
            'total_cost'    => 'decimal:2',
            'sell_price'    => 'decimal:2',
            'has_serial'    => 'boolean',
            'is_active'     => 'boolean',
        ];
    }

    public static function generateCode(): string
    {
        $last = self::withTrashed()->orderByDesc('id')->value('code');
        $num = $last ? ((int) substr($last, 3)) + 1 : 1;
        return 'SP-' . str_pad($num, 4, '0', STR_PAD_LEFT);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class);
    }

    public function serials(): HasMany
    {
        return $this->hasMany(ProductSerial::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function stockEntryItems(): HasMany
    {
        return $this->hasMany(StockEntryItem::class);
    }

    public function stockExitItems(): HasMany
    {
        return $this->hasMany(StockExitItem::class);
    }

    public function stockQuantity(?int $warehouseId = null): int
    {
        $query = $this->stockMovements();
        if ($warehouseId) {
            $query->where('warehouse_id', $warehouseId);
        }
        return (int) $query->sum('quantity');
    }
}
