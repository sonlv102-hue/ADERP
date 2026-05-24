<?php

namespace App\Models;

use App\Enums\CommissionStatus;
use App\Enums\CommissionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Commission extends Model
{
    protected $fillable = [
        'code', 'type', 'customer_id', 'order_id', 'project_id',
        'recipient_name', 'recipient_info',
        'amount', 'rate', 'payment_method',
        'planned_date', 'paid_date',
        'status', 'reject_reason',
        'approver1_id', 'approved1_at',
        'approver2_id', 'approved2_at',
        'payer_id', 'paid_at',
        'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'type'          => CommissionType::class,
            'status'        => CommissionStatus::class,
            'amount'        => 'decimal:2',
            'rate'          => 'decimal:4',
            'planned_date'  => 'date',
            'paid_date'     => 'date',
            'approved1_at'  => 'datetime',
            'approved2_at'  => 'datetime',
            'paid_at'       => 'datetime',
        ];
    }

    public static function generateCode(): string
    {
        $last = self::orderByDesc('id')->value('code');
        $num  = $last ? ((int) substr($last, 4)) + 1 : 1;
        return 'HOA-' . str_pad($num, 4, '0', STR_PAD_LEFT);
    }

    public function creator(): BelongsTo   { return $this->belongsTo(User::class, 'created_by'); }
    public function customer(): BelongsTo  { return $this->belongsTo(Customer::class); }
    public function order(): BelongsTo     { return $this->belongsTo(Order::class); }
    public function project(): BelongsTo   { return $this->belongsTo(Project::class); }
    public function approver1(): BelongsTo { return $this->belongsTo(User::class, 'approver1_id'); }
    public function approver2(): BelongsTo { return $this->belongsTo(User::class, 'approver2_id'); }
    public function payer(): BelongsTo     { return $this->belongsTo(User::class, 'payer_id'); }
}
