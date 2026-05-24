<?php

namespace App\Enums;

enum WarrantyStatus: string
{
    case Active  = 'active';
    case Expired = 'expired';
    case Void    = 'void';

    public function label(): string
    {
        return match($this) {
            self::Active  => 'Còn hiệu lực',
            self::Expired => 'Hết hạn',
            self::Void    => 'Đã hủy',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::Active  => 'green',
            self::Expired => 'gray',
            self::Void    => 'red',
        };
    }
}
