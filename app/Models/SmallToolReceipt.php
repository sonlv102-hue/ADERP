<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SmallToolReceipt extends Model
{
    protected $fillable = [
        'code', 'receipt_date', 'supplier_id', 'purchase_invoice_id',
        'warehouse_id', 'payment_type', 'fund_id',
        'total_cost', 'vat_amount', 'total_amount',
        'status', 'journal_entry_id', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'receipt_date' => 'date',
            'total_cost'   => 'decimal:2',
            'vat_amount'   => 'decimal:2',
            'total_amount' => 'decimal:2',
        ];
    }

    public function supplier(): BelongsTo   { return $this->belongsTo(Supplier::class); }
    public function purchaseInvoice(): BelongsTo { return $this->belongsTo(PurchaseInvoice::class); }
    public function warehouse(): BelongsTo  { return $this->belongsTo(Warehouse::class); }
    public function fund(): BelongsTo       { return $this->belongsTo(Fund::class); }
    public function journalEntry(): BelongsTo { return $this->belongsTo(JournalEntry::class); }
    public function createdByUser(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }

    public function items(): HasMany
    {
        return $this->hasMany(SmallToolReceiptItem::class);
    }

    public static function generateCode(): string
    {
        $last = static::orderByDesc('id')->value('code');
        $num  = $last ? ((int) substr($last, 5)) + 1 : 1;
        return 'CCNK-' . str_pad($num, 4, '0', STR_PAD_LEFT);
    }

    public function isConfirmed(): bool { return $this->status === 'confirmed'; }
    public function isDraft(): bool     { return $this->status === 'draft'; }
}
