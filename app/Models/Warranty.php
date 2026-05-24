<?php

namespace App\Models;

use App\Enums\WarrantyStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Warranty extends Model
{
    protected $fillable = [
        'code', 'customer_id', 'order_id', 'product_serial_id',
        'product_id', 'product_name', 'serial_number',
        'start_date', 'end_date', 'status', 'duration_months',
        'terms', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status'     => WarrantyStatus::class,
            'start_date' => 'date',
            'end_date'   => 'date',
        ];
    }

    public static function generateCode(): string
    {
        $last = self::orderByDesc('id')->value('code');
        $num = $last ? ((int) substr($last, 3)) + 1 : 1;
        return 'BH-' . str_pad($num, 4, '0', STR_PAD_LEFT);
    }

    public function isExpired(): bool
    {
        return $this->status === WarrantyStatus::Active
            && $this->end_date->isPast();
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function productSerial(): BelongsTo
    {
        return $this->belongsTo(ProductSerial::class, 'product_serial_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
