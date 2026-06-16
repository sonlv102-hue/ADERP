<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FixedAssetMovement extends Model
{
    protected $fillable = [
        'fixed_asset_id', 'movement_type', 'movement_date',
        'from_department', 'to_department',
        'from_expense_account_code', 'to_expense_account_code',
        'effective_from', 'journal_entry_id', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'movement_date' => 'date',
            'effective_from' => 'date',
        ];
    }

    public function fixedAsset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function movementTypeLabel(): string
    {
        return match($this->movement_type) {
            'placed_in_service'  => 'Đưa vào sử dụng',
            'department_transfer' => 'Điều chuyển bộ phận',
            'account_change'     => 'Thay đổi tài khoản chi phí',
            'suspended'          => 'Tạm dừng khấu hao',
            'resumed'            => 'Tiếp tục khấu hao',
            'revaluation'        => 'Đánh giá lại',
            default              => 'Khác',
        };
    }
}
