<?php

namespace App\Models;

use App\Enums\LeadStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Lead extends Model
{
    protected $fillable = [
        'code', 'full_name', 'company_name', 'phone', 'email',
        'source', 'assigned_to', 'status', 'next_follow_up',
        'expected_value', 'notes', 'created_by', 'converted_customer_id',
    ];

    protected function casts(): array
    {
        return [
            'status'          => LeadStatus::class,
            'next_follow_up'  => 'date',
            'expected_value'  => 'decimal:2',
        ];
    }

    public static function generateCode(): string
    {
        $last = static::orderByDesc('id')->value('code');
        $n = $last ? ((int) substr($last, 3)) + 1 : 1;
        return 'LD-' . str_pad($n, 4, '0', STR_PAD_LEFT);
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function convertedCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'converted_customer_id');
    }
}
