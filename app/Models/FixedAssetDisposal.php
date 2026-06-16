<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FixedAssetDisposal extends Model
{
    protected $fillable = [
        'fixed_asset_id', 'disposal_type', 'disposal_date',
        'original_cost_snapshot', 'accumulated_depreciation_snapshot', 'net_book_value_snapshot',
        'selling_price', 'selling_vat_amount', 'disposal_cost', 'disposal_vat_amount', 'gain_loss',
        'buyer_name', 'disposal_account_code', 'income_account_code',
        'status', 'journal_entry_ids', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'disposal_date'                     => 'date',
            'original_cost_snapshot'            => 'decimal:2',
            'accumulated_depreciation_snapshot' => 'decimal:2',
            'net_book_value_snapshot'           => 'decimal:2',
            'selling_price'                     => 'decimal:2',
            'selling_vat_amount'                => 'decimal:2',
            'disposal_cost'                     => 'decimal:2',
            'disposal_vat_amount'               => 'decimal:2',
            'gain_loss'                         => 'decimal:2',
            'journal_entry_ids'                 => 'array',
        ];
    }

    public function fixedAsset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class);
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function disposalTypeLabel(): string
    {
        return match($this->disposal_type) {
            'liquidation' => 'Thanh lý',
            'sale'        => 'Nhượng bán',
            'damage'      => 'Mất mát/Hư hỏng',
            default       => 'Khác',
        };
    }
}
