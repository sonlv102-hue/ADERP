<?php

namespace App\Enums;

enum PayrollItemStatus: string
{
    case Pending = 'pending';
    case Paid    = 'paid';

    public function label(): string
    {
        return match($this) {
            self::Pending => 'Chưa thanh toán',
            self::Paid    => 'Đã thanh toán',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::Pending => 'yellow',
            self::Paid    => 'green',
        };
    }
}
