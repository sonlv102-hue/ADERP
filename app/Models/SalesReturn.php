<?php

namespace App\Models;

use App\Enums\SalesReturnStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesReturn extends Model
{
    protected $fillable = [
        'code', 'order_id', 'warehouse_id', 'return_date',
        'status', 'reason', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status'      => SalesReturnStatus::class,
            'return_date' => 'date',
        ];
    }

    public static function generateCode(): string
    {
        $last = self::orderByDesc('id')->value('code');
        $num  = $last ? ((int) substr($last, 3)) + 1 : 1;
        return 'TH-' . str_pad($num, 4, '0', STR_PAD_LEFT);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SalesReturnItem::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'source_id')
            ->where('source_type', self::class);
    }
}
