<?php

namespace App\Models;

use App\Enums\ExpenseCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectExpense extends Model
{
    protected $fillable = [
        'project_id', 'category', 'description', 'amount', 'expense_date', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'category'     => ExpenseCategory::class,
            'expense_date' => 'date',
            'amount'       => 'decimal:2',
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
}
