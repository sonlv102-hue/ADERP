<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PersonalExpenseLine extends Model
{
    protected $fillable = [
        'personal_expense_report_id', 'expense_account', 'description',
        'amount', 'vat_rate', 'vat_amount', 'net_amount', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'amount'     => 'decimal:0',
            'vat_rate'   => 'decimal:2',
            'vat_amount' => 'decimal:0',
            'net_amount' => 'decimal:0',
        ];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(PersonalExpenseReport::class, 'personal_expense_report_id');
    }
}
