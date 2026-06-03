<?php

namespace App\Models;

use App\Enums\ItemUsageType;
use App\Enums\StockExitStatus;
use App\Models\Concerns\GeneratesCode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class StockExit extends Model
{
    use GeneratesCode, LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['code', 'status', 'warehouse_id', 'exit_date', 'reason'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected static string $codePrefix = 'XK-';
    protected static int    $codePad    = 4;

    protected $fillable = [
        'code', 'warehouse_id', 'customer_id', 'order_id', 'created_by',
        'exit_date', 'reason', 'status', 'notes',
        'item_usage_type', 'project_id',
    ];

    protected function casts(): array
    {
        return [
            'status'          => StockExitStatus::class,
            'item_usage_type' => ItemUsageType::class,
            'exit_date'       => 'date',
        ];
    }

    /** @deprecated Dùng nextCode() thay thế — an toàn hơn với concurrent requests */
    public static function generateCode(): string
    {
        return static::nextCode();
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

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
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
