<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseSupplierQuoteLine extends Model
{
    protected $fillable = [
        'quote_id', 'comparison_item_id', 'product_id', 'unit_snapshot',
        'unit_price', 'discount_percent', 'net_unit_price', 'vat_percent',
        'delivery_time', 'warranty', 'note',
    ];

    protected function casts(): array
    {
        return [
            'unit_price'       => 'decimal:2',
            'discount_percent' => 'decimal:2',
            'net_unit_price'   => 'decimal:2',
            'vat_percent'      => 'decimal:2',
        ];
    }

    /** Giá sau chiết khấu, chưa VAT. */
    public static function computeNetUnitPrice(float $unitPrice, float $discountPercent): float
    {
        return round($unitPrice * (1 - $discountPercent / 100), 2);
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(PurchaseSupplierQuote::class, 'quote_id');
    }

    public function comparisonItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseQuoteComparisonItem::class, 'comparison_item_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
