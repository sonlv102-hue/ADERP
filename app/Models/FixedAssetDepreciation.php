<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FixedAssetDepreciation extends Model
{
    protected $fillable = [
        'fixed_asset_id', 'period', 'amount',
        'accumulated_before', 'net_book_value_after', 'notes',
    ];

    protected $casts = [
        'amount'               => 'float',
        'accumulated_before'   => 'float',
        'net_book_value_after' => 'float',
    ];

    public function fixedAsset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class);
    }
}
