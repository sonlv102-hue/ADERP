<?php

namespace App\Enums;

enum DocumentStatus: string
{
    case Active    = 'active';
    case Expired   = 'expired';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match($this) {
            self::Active    => 'Có hiệu lực',
            self::Expired   => 'Hết hạn',
            self::Cancelled => 'Đã hủy',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::Active    => 'green',
            self::Expired   => 'yellow',
            self::Cancelled => 'red',
        };
    }
}
