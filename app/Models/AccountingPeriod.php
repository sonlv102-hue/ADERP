<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountingPeriod extends Model
{
    protected $fillable = ['year', 'month', 'status', 'closed_at', 'closed_by', 'notes'];

    protected $casts = [
        'year'      => 'integer',
        'month'     => 'integer',
        'closed_at' => 'datetime',
    ];

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    public function label(): string
    {
        return sprintf('%02d/%d', $this->month, $this->year);
    }

    public function statusLabel(): string
    {
        return match($this->status) {
            'open'   => 'Đang mở',
            'closed' => 'Đã đóng',
            'locked' => 'Đã khóa',
            default  => $this->status,
        };
    }

    public function statusColor(): string
    {
        return match($this->status) {
            'open'   => 'green',
            'closed' => 'yellow',
            'locked' => 'red',
            default  => 'gray',
        };
    }

    /** Tìm hoặc tạo kỳ kế toán cho một ngày */
    public static function findOrCreateForDate(\Carbon\Carbon $date): self
    {
        return static::firstOrCreate(
            ['year' => $date->year, 'month' => $date->month],
            ['status' => 'open']
        );
    }
}
