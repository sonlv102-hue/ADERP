<?php

namespace App\Enums;

enum AttendanceSheetStatus: string
{
    case Draft  = 'draft';
    case Locked = 'locked';

    public function label(): string
    {
        return match($this) {
            self::Draft  => 'Nháp',
            self::Locked => 'Đã khóa',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::Draft  => 'gray',
            self::Locked => 'green',
        };
    }
}
