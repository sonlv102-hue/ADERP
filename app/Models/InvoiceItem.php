<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceItem extends Model
{
    protected $fillable = [
        'invoice_id', 'sort_order', 'description',
        'quantity', 'unit_price', 'vat_rate', 'tax_amount',
    ];

    protected function casts(): array
    {
        return [
            'quantity'   => 'decimal:3',
            'unit_price' => 'decimal:2',
            'vat_rate'   => 'decimal:2',
            'tax_amount' => 'integer',
        ];
    }

    public function lineSubtotal(): float
    {
        return (float) $this->quantity * (float) $this->unit_price;
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
