<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectMaterial extends Model
{
    protected $fillable = [
        'project_id', 'product_id', 'quantity', 'unit_price', 'stock_exit_id', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'quantity'   => 'decimal:2',
            'unit_price' => 'decimal:2',
        ];
    }

    public function lineTotal(): float
    {
        return (float) ($this->quantity * $this->unit_price);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function stockExit(): BelongsTo
    {
        return $this->belongsTo(StockExit::class);
    }
}
