<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmallToolDisposal extends Model
{
    protected $fillable = [
        'code', 'small_tool_id', 'disposal_type', 'disposal_date', 'reason',
        'net_value_snapshot', 'expense_account_code',
        'recovery_amount', 'recovery_account_code', 'recovery_vat_amount', 'disposal_cost',
        'status', 'journal_entry_ids',
        'approved_by', 'approved_at', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'disposal_date'        => 'date',
            'approved_at'          => 'datetime',
            'net_value_snapshot'   => 'decimal:2',
            'recovery_amount'      => 'decimal:2',
            'recovery_vat_amount'  => 'decimal:2',
            'disposal_cost'        => 'decimal:2',
            'journal_entry_ids'    => 'array',
        ];
    }

    public function tool(): BelongsTo       { return $this->belongsTo(SmallTool::class, 'small_tool_id'); }
    public function approvedByUser(): BelongsTo { return $this->belongsTo(User::class, 'approved_by'); }
    public function createdByUser(): BelongsTo  { return $this->belongsTo(User::class, 'created_by'); }

    public function disposalTypeLabel(): string
    {
        return match($this->disposal_type) {
            'broken'     => 'Báo hỏng',
            'lost'       => 'Báo mất',
            'liquidated' => 'Thanh lý',
            default      => $this->disposal_type,
        };
    }

    public static function generateCode(): string
    {
        $last = static::orderByDesc('id')->value('code');
        $num  = $last ? ((int) substr($last, 5)) + 1 : 1;
        return 'CCXL-' . str_pad($num, 4, '0', STR_PAD_LEFT);
    }
}
