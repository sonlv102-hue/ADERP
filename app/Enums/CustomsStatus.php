<?php

namespace App\Enums;

enum CustomsStatus: string
{
    case NotRequired = 'not_required';
    case Pending     = 'pending';
    case Declared    = 'declared';

    public function label(): string
    {
        return match($this) {
            self::NotRequired => 'Không yêu cầu',
            self::Pending     => 'Chờ khai báo HQ',
            self::Declared    => 'Đã khai báo HQ',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::NotRequired => 'gray',
            self::Pending     => 'red',
            self::Declared    => 'green',
        };
    }
}
