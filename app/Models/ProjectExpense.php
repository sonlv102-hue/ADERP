<?php

namespace App\Models;

use App\Enums\ExpenseCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectExpense extends Model
{
    protected $fillable = [
        'project_id', 'category', 'description', 'amount', 'expense_date', 'created_by',
        'supplier_id', 'purchase_invoice_id', 'invoice_number',
        'payment_method', 'vat_rate', 'vat_amount',
        'debit_account', 'credit_account',
    ];

    protected function casts(): array
    {
        return [
            'category'     => ExpenseCategory::class,
            'expense_date' => 'date',
            'amount'       => 'decimal:2',
            'vat_rate'     => 'decimal:2',
            'vat_amount'   => 'integer',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** Tổng tiền bao gồm VAT */
    public function totalWithVat(): int
    {
        return (int) round((float) $this->amount) + ($this->vat_amount ?? 0);
    }
}
