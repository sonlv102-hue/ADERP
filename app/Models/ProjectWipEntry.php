<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ProjectWipEntry extends Model
{
    protected $table = 'project_wip_entries';

    protected $fillable = [
        'project_id', 'source_type', 'source_id',
        'cost_type', 'amount', 'description',
        'source_item_id', 'vat_amount',
        'entry_date', 'journal_entry_id', 'created_by',
        'product_id', 'quantity', 'unit_cost', 'stock_exit_item_id',
        'status', 'cancel_reason', 'cancelled_by', 'cancelled_at', 'correction_of_id',
    ];

    protected $casts = [
        'entry_date'   => 'date',
        'amount'       => 'decimal:2',
        'vat_amount'   => 'decimal:2',
        'quantity'     => 'decimal:3',
        'unit_cost'    => 'decimal:2',
        'cancelled_at' => 'datetime',
    ];

    public static array $costTypeLabels = [
        'material'    => 'Vật tư / Hàng hóa',
        'labor'       => 'Nhân công',
        'subcontract' => 'Nhà thầu phụ',
        'overhead'    => 'Chi phí chung',
        'other'       => 'Khác',
    ];

    public function costTypeLabel(): string
    {
        return self::$costTypeLabels[$this->cost_type] ?? $this->cost_type;
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function source(): MorphTo
    {
        return $this->morphTo('source');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function cancelledByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function correctionOf(): BelongsTo
    {
        return $this->belongsTo(ProjectWipEntry::class, 'correction_of_id');
    }

    public function correctionLogs(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ProjectWipCorrectionLog::class, 'wip_entry_id');
    }

    public function isActive(): bool
    {
        return ($this->status ?? 'active') === 'active';
    }

    public function statusLabel(): string
    {
        return match ($this->status ?? 'active') {
            'active'      => 'Đang hiệu lực',
            'cancelled'   => 'Đã hủy',
            'adjusted'    => 'Đã điều chỉnh TK',
            'transferred' => 'Đã chuyển dự án',
            'reversed'    => 'Đã đảo',
            default       => $this->status ?? 'active',
        };
    }

    public function statusColor(): string
    {
        return match ($this->status ?? 'active') {
            'active'      => 'green',
            'cancelled'   => 'red',
            'adjusted'    => 'yellow',
            'transferred' => 'blue',
            'reversed'    => 'gray',
            default       => 'gray',
        };
    }
}
