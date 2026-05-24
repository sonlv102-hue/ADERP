<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Casts\Attribute;

class FixedAsset extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'code', 'name', 'category', 'acquisition_date',
        'acquisition_cost', 'useful_life_months', 'depreciation_method',
        'accumulated_depreciation', 'location', 'status', 'notes',
    ];

    protected $casts = [
        'acquisition_date'        => 'date',
        'acquisition_cost'        => 'float',
        'accumulated_depreciation'=> 'float',
    ];

    public function monthlyDepreciation(): Attribute
    {
        return Attribute::get(fn () => $this->useful_life_months > 0
            ? round($this->acquisition_cost / $this->useful_life_months, 2)
            : 0);
    }

    public function annualDepreciation(): Attribute
    {
        return Attribute::get(fn () => $this->useful_life_months > 0
            ? round($this->acquisition_cost / $this->useful_life_months * 12, 2)
            : 0);
    }

    public function netBookValue(): Attribute
    {
        return Attribute::get(fn () => max(0, $this->acquisition_cost - $this->accumulated_depreciation));
    }

    public function depreciationRate(): Attribute
    {
        return Attribute::get(fn () => $this->useful_life_months > 0
            ? round(12 / $this->useful_life_months * 100, 2)
            : 0);
    }
}
