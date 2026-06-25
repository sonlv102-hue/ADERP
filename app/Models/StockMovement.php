<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class StockMovement extends Model
{
    protected $fillable = [
        'product_id', 'warehouse_id', 'type', 'quantity',
        'source_type', 'source_id', 'source_item_id', 'created_by', 'notes',
        'project_id', 'unit_cost', 'amount',
        'status', 'cancelled_by', 'cancelled_at', 'cancel_reason',
    ];

    protected function casts(): array
    {
        return [
            'quantity'     => 'integer',
            'unit_cost'    => 'decimal:2',
            'amount'       => 'decimal:2',
            'cancelled_at' => 'datetime',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('status')->orWhere('status', 'active');
        });
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }
}
