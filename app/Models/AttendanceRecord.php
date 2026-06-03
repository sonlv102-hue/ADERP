<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceRecord extends Model
{
    protected $fillable = [
        'attendance_sheet_id', 'employee_id',
        'days',
        'cong', 'nghi_huong_luong', 'nghi_khong_luong', 'ot', 'tong',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'days' => 'array',
        ];
    }

    public function sheet(): BelongsTo
    {
        return $this->belongsTo(AttendanceSheet::class, 'attendance_sheet_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** Recalculate summary columns from days JSON */
    public function recalculate(): void
    {
        $days = $this->days ?? [];
        $cong = $nghi_huong = $nghi_khong = $ot = $tong = 0;

        foreach ($days as $symbol) {
            if ($symbol === null || $symbol === '') continue;
            $tong++;
            $s = strtoupper(trim($symbol));
            match ($s) {
                'X', 'CT' => $cong++,
                'P', 'Ô', 'O', 'NB', 'TS', 'L' => $nghi_huong++,
                'KP'      => $nghi_khong++,
                'OT'      => $ot++,
                default   => null,
            };
        }

        $this->update([
            'cong'             => $cong,
            'nghi_huong_luong' => $nghi_huong,
            'nghi_khong_luong' => $nghi_khong,
            'ot'               => $ot,
            'tong'             => $tong,
        ]);
    }
}
