<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmallToolTransfer extends Model
{
    protected $fillable = [
        'code', 'small_tool_id', 'transfer_date',
        'from_department', 'to_department',
        'from_employee_id', 'to_employee_id',
        'from_project_id', 'to_project_id',
        'from_warehouse_id', 'to_warehouse_id',
        'new_expense_account_code', 'affects_future_allocation',
        'reason', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'transfer_date'            => 'date',
            'affects_future_allocation' => 'boolean',
        ];
    }

    public function tool(): BelongsTo           { return $this->belongsTo(SmallTool::class, 'small_tool_id'); }
    public function fromEmployee(): BelongsTo   { return $this->belongsTo(Employee::class, 'from_employee_id'); }
    public function toEmployee(): BelongsTo     { return $this->belongsTo(Employee::class, 'to_employee_id'); }
    public function fromProject(): BelongsTo    { return $this->belongsTo(Project::class, 'from_project_id'); }
    public function toProject(): BelongsTo      { return $this->belongsTo(Project::class, 'to_project_id'); }
    public function fromWarehouse(): BelongsTo  { return $this->belongsTo(Warehouse::class, 'from_warehouse_id'); }
    public function toWarehouse(): BelongsTo    { return $this->belongsTo(Warehouse::class, 'to_warehouse_id'); }
    public function createdByUser(): BelongsTo  { return $this->belongsTo(User::class, 'created_by'); }

    public static function generateCode(): string
    {
        $last = static::orderByDesc('id')->value('code');
        $num  = $last ? ((int) substr($last, 5)) + 1 : 1;
        return 'CCCT-' . str_pad($num, 4, '0', STR_PAD_LEFT);
    }
}
