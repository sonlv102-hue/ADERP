<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Supplier extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'code', 'name', 'tax_code', 'phone', 'email', 'address',
        'bank_name', 'bank_account', 'bank_account_name', 'bank_branch',
        'notes', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public static function generateCode(): string
    {
        $last = self::withTrashed()->orderByDesc('id')->value('code');
        $num = $last ? ((int) substr($last, 4)) + 1 : 1;
        return 'NCC-' . str_pad($num, 4, '0', STR_PAD_LEFT);
    }

    public function stockEntries(): HasMany
    {
        return $this->hasMany(StockEntry::class);
    }
}
