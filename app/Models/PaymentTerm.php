<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentTerm extends Model
{
    protected $fillable = ['code', 'name', 'days', 'description', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'days' => 'integer'];
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function suppliers(): HasMany
    {
        return $this->hasMany(Supplier::class);
    }
}
