<?php

namespace App\Models;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ticket extends Model
{
    protected $fillable = [
        'code', 'title', 'description',
        'customer_id', 'order_id', 'contract_id',
        'assigned_to', 'priority', 'status', 'category',
        'due_date', 'resolved_at', 'closed_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status'      => TicketStatus::class,
            'priority'    => TicketPriority::class,
            'due_date'    => 'date',
            'resolved_at' => 'datetime',
            'closed_at'   => 'datetime',
        ];
    }

    public static function generateCode(): string
    {
        $last = self::orderByDesc('id')->value('code');
        $num = $last ? ((int) substr($last, 3)) + 1 : 1;
        return 'TK-' . str_pad($num, 4, '0', STR_PAD_LEFT);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(TicketLog::class)->orderBy('id');
    }
}
