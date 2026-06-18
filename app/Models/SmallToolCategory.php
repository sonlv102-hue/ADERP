<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SmallToolCategory extends Model
{
    protected $fillable = ['name', 'code', 'description'];

    public function tools(): HasMany
    {
        return $this->hasMany(SmallTool::class, 'category_id');
    }
}
