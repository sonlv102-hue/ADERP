<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Shareholder extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'code', 'name', 'id_number', 'tax_number', 'phone', 'email',
        'address', 'share_percentage', 'is_active', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'share_percentage' => 'decimal:4',
            'is_active'        => 'boolean',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function personalLoans(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PersonalLoan::class);
    }

    public static function generateCode(): string
    {
        $last = static::withTrashed()->max('id') ?? 0;
        return 'TV-' . str_pad($last + 1, 4, '0', STR_PAD_LEFT);
    }
}
