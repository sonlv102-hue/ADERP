<?php

namespace App\Models;

use App\Enums\PersonalExpenseStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PersonalExpenseReport extends Model
{
    protected $fillable = [
        'report_no', 'person_type', 'employee_id', 'shareholder_id', 'person_name',
        'expense_date', 'description', 'total_amount', 'vat_amount',
        'status', 'journal_entry_id', 'reimburse_journal_entry_id',
        'reimbursed_fund_id', 'reimbursed_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'expense_date'    => 'date',
            'total_amount'    => 'decimal:0',
            'vat_amount'      => 'decimal:0',
            'status'          => PersonalExpenseStatus::class,
            'reimbursed_at'   => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function shareholder(): BelongsTo
    {
        return $this->belongsTo(Shareholder::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function reimburseJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'reimburse_journal_entry_id');
    }

    public function reimbursedFund(): BelongsTo
    {
        return $this->belongsTo(Fund::class, 'reimbursed_fund_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PersonalExpenseLine::class)->orderBy('sort_order');
    }

    public function personName(): string
    {
        return match ($this->person_type) {
            'employee'    => $this->employee?->name ?? $this->person_name ?? '—',
            'shareholder' => $this->shareholder?->name ?? $this->person_name ?? '—',
            default       => $this->person_name ?? '—',
        };
    }

    public static function generateNo(): string
    {
        $last = static::max('id') ?? 0;
        return 'PCH-' . str_pad($last + 1, 4, '0', STR_PAD_LEFT);
    }
}
