<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Vấn đề sai lệch tồn kho/kế toán — định danh ổn định theo issue_key, không sinh trùng giữa các lần audit. */
class InventoryIntegrityIssue extends Model
{
    public const STATUSES = [
        'OPEN', 'ASSIGNED', 'IN_PROGRESS', 'PENDING_ACCOUNTING', 'PENDING_TECHNICAL', 'PENDING_POLICY',
        'PENDING_EVIDENCE', 'PENDING_VERIFICATION', 'RESOLVED', 'ACCEPTED_DIFFERENCE',
    ];
    /** Trạng thái người dùng được đặt tay. RESOLVED (rule tự động) và ACCEPTED_DIFFERENCE đi qua luồng riêng. */
    public const MANUAL_STATUSES = [
        'OPEN', 'ASSIGNED', 'IN_PROGRESS', 'PENDING_ACCOUNTING', 'PENDING_TECHNICAL', 'PENDING_POLICY',
        'PENDING_EVIDENCE', 'PENDING_VERIFICATION',
    ];
    public const CLOSED = ['RESOLVED', 'ACCEPTED_DIFFERENCE'];
    public const CATEGORIES = ['TECHNICAL', 'ACCOUNTING', 'BUSINESS', 'POLICY_DECISION', 'PENDING_EVIDENCE', 'EXPECTED'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'evidence' => 'array', 'case_info' => 'array', 'resolution' => 'array', 'accepted_snapshot' => 'array',
            'is_legacy' => 'boolean', 'due_date' => 'date', 'detected_at' => 'datetime', 'last_checked_at' => 'datetime',
            'resolved_at' => 'datetime', 'accepted_at' => 'datetime',
        ];
    }

    public function product()
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function logs()
    {
        return $this->hasMany(InventoryIntegrityIssueLog::class, 'issue_id')->orderByDesc('id');
    }

    public function isOpen(): bool
    {
        return ! in_array($this->status, self::CLOSED, true);
    }

    public function log(string $action, array $attrs = []): void
    {
        $this->logs()->create($attrs + ['action' => $action, 'user_id' => auth()->id()]);
    }
}
