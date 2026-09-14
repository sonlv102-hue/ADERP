<?php

namespace App\Models;

use App\Enums\CashFlowDirection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class CashFlowCategory extends Model
{
    /** Mã danh mục coi là "chuyển tiền nội bộ" (spec §6) — dùng để loại khỏi dòng
     *  tiền thuần TOÀN CÔNG TY ngay cả khi chưa cặp đôi (paired_transaction_id null). */
    public const INTERNAL_TRANSFER_CODES = ['in_internal_transfer', 'out_internal_transfer'];

    protected $fillable = ['code', 'name', 'direction', 'is_system', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return [
            'direction' => CashFlowDirection::class,
            'is_system' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeDirection(Builder $query, CashFlowDirection $direction): Builder
    {
        return $query->where('direction', $direction->value);
    }

    public function isInternalTransfer(): bool
    {
        return in_array($this->code, self::INTERNAL_TRANSFER_CODES, true);
    }
}
