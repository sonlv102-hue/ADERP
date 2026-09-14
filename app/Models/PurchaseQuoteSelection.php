<?php

namespace App\Models;

use App\Enums\QuoteSelectionReason;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseQuoteSelection extends Model
{
    protected $fillable = [
        'comparison_item_id', 'quote_line_id', 'supplier_id', 'selected_unit_price',
        'is_lowest_price', 'selection_reason', 'selection_note', 'selected_by', 'selected_at',
    ];

    protected function casts(): array
    {
        return [
            'selected_unit_price' => 'decimal:2',
            'is_lowest_price'     => 'boolean',
            'selection_reason'    => QuoteSelectionReason::class,
            'selected_at'         => 'datetime',
        ];
    }

    public function comparisonItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseQuoteComparisonItem::class, 'comparison_item_id');
    }

    public function quoteLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseSupplierQuoteLine::class, 'quote_line_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function selector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'selected_by');
    }
}
