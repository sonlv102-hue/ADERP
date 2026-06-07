<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Invoice extends Model
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['code', 'status', 'issue_date', 'due_date', 'total', 'customer_id'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
    protected $fillable = [
        'code', 'customer_id', 'order_id', 'contract_id',
        'issue_date', 'due_date', 'subtotal', 'tax_amount', 'total',
        'status', 'notes', 'revenue_account_code', 'created_by',
        'e_inv_template', 'e_inv_series', 'e_inv_number',
        'e_inv_status', 'e_inv_issued_at', 'e_inv_cancel_reason',
    ];

    protected function casts(): array
    {
        return [
            'status'          => InvoiceStatus::class,
            'issue_date'      => 'date',
            'due_date'        => 'date',
            'subtotal'        => 'decimal:2',
            'tax_amount'      => 'decimal:2',
            'total'           => 'decimal:2',
            'e_inv_issued_at' => 'datetime',
        ];
    }

    public function nextEInvoiceNumber(): int
    {
        $max = static::where('e_inv_series', $this->e_inv_series)
            ->whereNotNull('e_inv_number')
            ->max('e_inv_number');
        return ($max ?? 0) + 1;
    }

    public static function generateCode(): string
    {
        $prefix = 'HĐ-';
        $last = self::orderByDesc('id')->value('code');
        $num = $last ? ((int) mb_substr($last, mb_strlen($prefix))) + 1 : 1;
        return $prefix . str_pad($num, 4, '0', STR_PAD_LEFT);
    }

    public function amountPaid(): float
    {
        return (float) $this->payments()->sum('amount');
    }

    public function amountDue(): float
    {
        return (float) $this->total - $this->amountPaid();
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable')->latest();
    }
}
