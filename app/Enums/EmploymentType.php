<?php

namespace App\Enums;

enum EmploymentType: string
{
    case FullTime  = 'full_time';
    case PartTime  = 'part_time';
    case Contract  = 'contract';
    case Seasonal  = 'seasonal';

    public function label(): string
    {
        return match($this) {
            self::FullTime => 'Toàn thời gian',
            self::PartTime => 'Bán thời gian',
            self::Contract => 'Hợp đồng',
            self::Seasonal => 'Thời vụ',
        };
    }
}
