<?php

namespace App\Models;

use App\Enums\StockExitStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Order;

class StockExit extends Model
{
    protected $fillable = [
        'code', 'warehouse_id', 'customer_id', 'order_id', 'created_by', 'exit_date', 'reason', 'status', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => StockExitStatus::class,
            'exit_date' => 'date',
        ];
    }

    public static function generateCode(): string
    {
        $last = self::orderByDesc('id')->value('code');
        $num = $last ? ((int) substr($last, 3)) + 1 : 1;
        return 'XK-' . str_pad($num, 4, '0', STR_PAD_LEFT);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockExitItem::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'source_id')
            ->where('source_type', self::class);
    }
}
