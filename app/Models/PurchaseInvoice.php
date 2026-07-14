<?php

namespace App\Models;

use App\Enums\PurchaseInvoiceStatus;
use App\Enums\PurchaseInvoiceType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class PurchaseInvoice extends Model
{
    protected $fillable = [
        'code', 'purchase_order_id', 'project_id', 'supplier_id',
        'invoice_number', 'invoice_date', 'supplier_tax_code',
        'subtotal', 'tax_amount', 'total', 'paid_amount', 'advance_allocated_amount',
        'due_date', 'status', 'notes', 'expense_account_code', 'invoice_type', 'created_by',
        'file_path', 'file_name',
        'subcontract_id', 'subcontract_acceptance_id', 'cost_group',
    ];

    protected function casts(): array
    {
        return [
            'status'       => PurchaseInvoiceStatus::class,
            'invoice_type' => PurchaseInvoiceType::class,
            'invoice_date' => 'date',
            'due_date'     => 'date',
            'subtotal'     => 'decimal:2',
            'tax_amount'   => 'decimal:2',
            'total'        => 'decimal:2',
            'paid_amount'              => 'decimal:2',
            'advance_allocated_amount' => 'decimal:2',
        ];
    }

    public static function generateCode(): string
    {
        $last = self::orderByDesc('id')->value('code');
        $num  = $last ? ((int) substr($last, 7)) + 1 : 1;
        return 'HD-NCC-' . str_pad($num, 4, '0', STR_PAD_LEFT);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseInvoiceItem::class)->orderBy('sort_order');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function subcontract(): BelongsTo
    {
        return $this->belongsTo(ProjectSubcontract::class, 'subcontract_id');
    }

    public function subcontractAcceptance(): BelongsTo
    {
        return $this->belongsTo(ProjectSubcontractAcceptance::class, 'subcontract_acceptance_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PurchaseInvoicePayment::class);
    }

    public function amountPaid(): float
    {
        return (float) $this->payments()->active()->sum('amount');
    }

    public function activePayments(): HasMany
    {
        return $this->hasMany(PurchaseInvoicePayment::class)->where('status', 'active');
    }

    public function amountDue(): float
    {
        return max(0.0, (float) $this->total - $this->amountPaid() - (float) $this->advance_allocated_amount);
    }

    public function getRemainingAttribute(): float
    {
        return $this->amountDue();
    }

    public function advanceAllocations(): HasMany
    {
        return $this->hasMany(SupplierAdvanceAllocation::class);
    }

    public function activeAdvanceAllocations(): HasMany
    {
        return $this->hasMany(SupplierAdvanceAllocation::class)->where('status', 'active');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable')->latest();
    }
}
