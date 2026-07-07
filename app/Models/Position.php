<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Position extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'code', 'name', 'notes', 'is_active', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public static function generateCode(): string
    {
        $last = static::withTrashed()->max('id') ?? 0;
        return 'CV-' . str_pad($last + 1, 4, '0', STR_PAD_LEFT);
    }

    protected static function booted()
    {
        static::updated(function ($position) {
            if ($position->wasChanged('name')) {
                $oldName = $position->getOriginal('name');
                Employee::where('position', $oldName)->update(['position' => $position->name]);
            }
        });
    }
}
