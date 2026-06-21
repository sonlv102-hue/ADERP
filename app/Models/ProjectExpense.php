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
        'status', 'journal_entry_id', 'project_wip_entry_id',
        'employee_id', 'fund_id', 'bank_account_id',
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

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function fund(): BelongsTo
    {
        return $this->belongsTo(Fund::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function wipEntry(): BelongsTo
    {
        return $this->belongsTo(ProjectWipEntry::class, 'project_wip_entry_id');
    }

    public function transfers(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ProjectExtraCostTransfer::class);
    }

    /** Tổng tiền bao gồm VAT */
    public function totalWithVat(): int
    {
        return (int) round((float) $this->amount) + ($this->vat_amount ?? 0);
    }

    public function isPosted(): bool
    {
        return $this->status === 'posted';
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }
}
