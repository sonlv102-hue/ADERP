<?php

namespace App\Models;

use App\Enums\AttendanceSheetStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttendanceSheet extends Model
{
    protected $fillable = [
        'code', 'period', 'status', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => AttendanceSheetStatus::class,
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function records(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    public static function generateCode(string $period): string
    {
        $suffix = str_replace('-', '', $period); // 2026-01 → 202601
        return 'CC-' . $suffix;
    }
}
