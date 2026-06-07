<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryOpeningBalance extends Model
{
    protected $fillable = [
        'period', 'warehouse_id', 'product_id',
        'quantity', 'unit_cost', 'total_cost',
        'note', 'journal_entry_id', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'quantity'   => 'decimal:3',
            'unit_cost'  => 'decimal:2',
            'total_cost' => 'decimal:2',
        ];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
