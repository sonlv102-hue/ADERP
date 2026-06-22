<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseInvoiceItem extends Model
{
    protected $fillable = [
        'purchase_invoice_id',
        'description',
        'account_code',
        'credit_account_code',
        'project_id',
        'amount',
        'vat_rate',
        'tax_amount',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'amount'     => 'decimal:2',
            'vat_rate'   => 'decimal:2',
            'tax_amount' => 'decimal:2',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoice::class, 'purchase_invoice_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function accountCode(): BelongsTo
    {
        return $this->belongsTo(AccountCode::class, 'account_code', 'code');
    }

    /**
     * TK 154 bắt buộc gắn project_id.
     */
    public function requiresProject(): bool
    {
        return str_starts_with((string) $this->account_code, '154');
    }
}
