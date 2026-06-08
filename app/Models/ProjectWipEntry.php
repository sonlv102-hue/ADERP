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
        'entry_date', 'journal_entry_id', 'created_by',
    ];

    protected $casts = [
        'entry_date' => 'date',
        'amount'     => 'decimal:2',
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
}
