<?php

namespace App\Enums;

enum ItemUsageType: string
{
    case Commercial = 'commercial';
    case Project    = 'project';

    public function label(): string
    {
        return match($this) {
            self::Commercial => 'Bán thương mại',
            self::Project    => 'Xuất cho dự án',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::Commercial => 'blue',
            self::Project    => 'purple',
        };
    }
}
