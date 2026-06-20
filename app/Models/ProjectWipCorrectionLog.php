<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectWipCorrectionLog extends Model
{
    protected $table = 'project_wip_correction_logs';

    protected $fillable = [
        'wip_entry_id', 'action_type', 'from_project_id', 'to_project_id',
        'from_account', 'to_account', 'amount', 'reason',
        'performed_by', 'correction_je_id', 'new_wip_entry_id',
    ];

    public static array $actionLabels = [
        'cancel'   => 'Hủy chi phí',
        'transfer' => 'Chuyển dự án',
        'reclass'  => 'Điều chỉnh tài khoản',
    ];

    public function wipEntry(): BelongsTo
    {
        return $this->belongsTo(ProjectWipEntry::class, 'wip_entry_id');
    }

    public function correctionJe(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'correction_je_id');
    }

    public function performedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    public function actionLabel(): string
    {
        return self::$actionLabels[$this->action_type] ?? $this->action_type;
    }
}
