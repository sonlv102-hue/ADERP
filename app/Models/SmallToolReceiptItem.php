<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmallToolReceiptItem extends Model
{
    protected $fillable = [
        'small_tool_receipt_id', 'small_tool_id',
        'quantity', 'unit_price', 'vat_rate', 'vat_amount', 'total_amount',
    ];

    protected function casts(): array
    {
        return [
            'unit_price'   => 'decimal:2',
            'vat_rate'     => 'decimal:2',
            'vat_amount'   => 'decimal:2',
            'total_amount' => 'decimal:2',
        ];
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(SmallToolReceipt::class, 'small_tool_receipt_id');
    }

    public function tool(): BelongsTo
    {
        return $this->belongsTo(SmallTool::class, 'small_tool_id');
    }
}
