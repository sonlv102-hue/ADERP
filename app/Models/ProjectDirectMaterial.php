<?php

namespace App\Models;

use App\Enums\DirectMaterialHandlingType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectDirectMaterial extends Model
{
    protected $table = 'project_direct_materials';

    protected $fillable = [
        'project_id', 'product_id', 'product_name',
        'quantity', 'unit_price', 'total_amount',
        'occurrence_date', 'handling_type',
        'supplier_id', 'credit_account_code',
        'purchase_invoice_item_id', 'journal_entry_id',
        'status', 'cancel_reason', 'cancelled_by', 'cancelled_at',
        'notes', 'source_document_ref', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'handling_type'    => DirectMaterialHandlingType::class,
            'occurrence_date'  => 'date',
            'quantity'         => 'decimal:3',
            'unit_price'       => 'decimal:2',
            'total_amount'     => 'decimal:2',
            'cancelled_at'     => 'datetime',
        ];
    }

    public function resolveDisplayName(): string
    {
        return $this->product?->name ?? $this->product_name ?? '—';
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function purchaseInvoiceItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoiceItem::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
