<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SmallToolIssue extends Model
{
    protected $fillable = [
        'code', 'issue_date', 'department', 'responsible_employee_id',
        'project_id', 'recognition_method', 'allocation_periods', 'allocation_start_date',
        'expense_account_code', 'total_amount',
        'status', 'journal_entry_id', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'issue_date'            => 'date',
            'allocation_start_date' => 'date',
            'total_amount'          => 'decimal:2',
        ];
    }

    public function responsibleEmployee(): BelongsTo { return $this->belongsTo(Employee::class, 'responsible_employee_id'); }
    public function project(): BelongsTo             { return $this->belongsTo(Project::class); }
    public function journalEntry(): BelongsTo        { return $this->belongsTo(JournalEntry::class); }
    public function createdByUser(): BelongsTo       { return $this->belongsTo(User::class, 'created_by'); }

    public function items(): HasMany
    {
        return $this->hasMany(SmallToolIssueItem::class);
    }

    public static function generateCode(): string
    {
        $last = static::orderByDesc('id')->value('code');
        $num  = $last ? ((int) substr($last, 5)) + 1 : 1;
        return 'CCXD-' . str_pad($num, 4, '0', STR_PAD_LEFT);
    }

    public function isConfirmed(): bool { return $this->status === 'confirmed'; }
    public function isDraft(): bool     { return $this->status === 'draft'; }
}
