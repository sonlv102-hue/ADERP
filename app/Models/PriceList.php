<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PriceList extends Model
{
    protected $fillable = [
        'code', 'name', 'valid_from', 'valid_to', 'is_default', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'valid_from'  => 'date',
            'valid_to'    => 'date',
            'is_default'  => 'boolean',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        static::saving(function (self $model) {
            if ($model->is_default) {
                static::where('id', '!=', $model->id)->update(['is_default' => false]);
            }
        });
    }

    public static function generateCode(): string
    {
        $last = static::orderByDesc('id')->value('code');
        $num  = $last ? ((int) substr($last, 3)) + 1 : 1;
        return 'BG-' . str_pad($num, 4, '0', STR_PAD_LEFT);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PriceListItem::class);
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'price_list_items')
            ->withPivot('unit_price')
            ->withTimestamps();
    }
}
