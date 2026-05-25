<?php

namespace App\Models;

use App\Enums\InventoryCountStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryCount extends Model
{
    protected $fillable = [
        'code', 'warehouse_id', 'count_date', 'status', 'counted_by', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'status'     => InventoryCountStatus::class,
            'count_date' => 'date',
        ];
    }

    public static function generateCode(): string
    {
        $prefix = 'IK-' . now()->format('ymd');
        $last = self::where('code', 'like', $prefix . '%')->orderByDesc('id')->value('code');
        $seq = $last ? ((int) substr($last, -2)) + 1 : 1;
        return $prefix . str_pad($seq, 2, '0', STR_PAD_LEFT);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function countedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counted_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(InventoryCountItem::class);
    }
}
