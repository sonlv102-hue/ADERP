<?php

namespace App\Enums;

enum PrepaidExpenseStatus: string
{
    case Active         = 'active';
    case FullyAmortized = 'fully_amortized';
    case Cancelled      = 'cancelled';

    public function label(): string
    {
        return match($this) {
            self::Active         => 'Đang phân bổ',
            self::FullyAmortized => 'Đã phân bổ hết',
            self::Cancelled      => 'Đã hủy',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::Active         => 'blue',
            self::FullyAmortized => 'green',
            self::Cancelled      => 'gray',
        };
    }
}
