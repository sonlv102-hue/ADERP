<?php

namespace App\Models;

use App\Enums\ContractStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Contract extends Model
{
    protected $fillable = [
        'code', 'customer_id', 'order_id', 'title', 'value',
        'start_date', 'end_date', 'status', 'file_path', 'file_name', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status'     => ContractStatus::class,
            'start_date' => 'date',
            'end_date'   => 'date',
            'value'      => 'decimal:2',
        ];
    }

    public static function generateCode(): string
    {
        $last = self::orderByDesc('id')->value('code');
        $num = $last ? ((int) substr($last, 3)) + 1 : 1;
        return 'HD-' . str_pad($num, 4, '0', STR_PAD_LEFT);
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
}
