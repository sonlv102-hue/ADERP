<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PurchaseQuoteComparisonItem extends Model
{
    protected $fillable = [
        'comparison_id', 'product_id', 'product_code_snapshot', 'product_name_snapshot',
        'unit_snapshot', 'specification', 'requested_qty', 'note',
    ];

    protected function casts(): array
    {
        return ['requested_qty' => 'decimal:2'];
    }

    public function comparison(): BelongsTo
    {
        return $this->belongsTo(PurchaseQuoteComparison::class, 'comparison_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function quoteLines(): HasMany
    {
        return $this->hasMany(PurchaseSupplierQuoteLine::class, 'comparison_item_id');
    }

    public function selection(): HasOne
    {
        return $this->hasOne(PurchaseQuoteSelection::class, 'comparison_item_id');
    }
}
