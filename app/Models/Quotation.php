<?php

namespace App\Models;

use App\Enums\QuotationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Quotation extends Model
{
    protected $fillable = [
        'code', 'customer_id', 'assigned_to', 'valid_until',
        'status', 'discount_type', 'discount_value', 'notes', 'created_by',
        'file_path', 'file_name',
    ];

    protected function casts(): array
    {
        return [
            'status'      => QuotationStatus::class,
            'valid_until' => 'date',
            'discount_value' => 'decimal:2',
        ];
    }

    public static function generateCode(): string
    {
        $last = self::orderByDesc('id')->value('code');
        $num = $last ? ((int) substr($last, 3)) + 1 : 1;
        return 'BG-' . str_pad($num, 4, '0', STR_PAD_LEFT);
    }

    public function subtotal(): float
    {
        return (float) $this->items->sum(fn ($item) =>
            $item->quantity * $item->unit_price - $item->discount_amount
        );
    }

    public function discountAmount(): float
    {
        $sub = $this->subtotal();
        if ($this->discount_type === 'percent') {
            return $sub * ($this->discount_value / 100);
        }
        return (float) $this->discount_value;
    }

    public function discountPercent(): float
    {
        $sub = $this->subtotal();
        if ($sub <= 0) return 0;
        
        if ($this->discount_type === 'percent') {
            return (float) $this->discount_value;
        }
        
        // Tính % từ số tiền chiết khấu (discount_value là số tiền)
        return round(((float) $this->discount_value / $sub) * 100, 2);
    }

    public function vatTotal(): float
    {
        return (float) $this->items->sum(fn ($item) =>
            (int) round($item->lineTotal() * ($item->vat_rate ?? 0) / 100)
        );
    }

    /** Tổng sau CK, trước VAT — dùng nội bộ (docFactor khi convert sang Order) */
    public function netBeforeVat(): float
    {
        return $this->subtotal() - $this->discountAmount();
    }

    public function total(): float
    {
        return $this->netBeforeVat() + $this->vatTotal();
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuotationItem::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable')->latest();
    }
}
