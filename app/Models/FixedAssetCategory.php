<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FixedAssetCategory extends Model
{
    protected $fillable = [
        'code', 'name',
        'asset_account_code', 'depreciation_account_code', 'expense_account_code',
        'min_useful_life_months', 'max_useful_life_months',
        'legal_basis', 'description',
    ];

    public function fixedAssets(): HasMany
    {
        return $this->hasMany(FixedAsset::class, 'category_id');
    }
}
