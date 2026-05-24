<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuotationItem extends Model
{
    protected $fillable = [
        'quotation_id', 'item_type', 'product_id', 'service_id',
        'name', 'unit', 'quantity', 'unit_price', 'discount_percent',
    ];

    protected function casts(): array
    {
        return [
            'quantity'         => 'decimal:2',
            'unit_price'       => 'decimal:2',
            'discount_percent' => 'decimal:2',
        ];
    }

    public function lineTotal(): float
    {
        return (float) ($this->quantity * $this->unit_price * (1 - $this->discount_percent / 100));
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}
