<?php

namespace App\Enums;

enum PayrollStatus: string
{
    case Draft     = 'draft';
    case Confirmed = 'confirmed';
    case Paid      = 'paid';

    public function label(): string
    {
        return match($this) {
            self::Draft     => 'Nháp',
            self::Confirmed => 'Đã xác nhận',
            self::Paid      => 'Đã chi lương',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::Draft     => 'gray',
            self::Confirmed => 'blue',
            self::Paid      => 'green',
        };
    }
}
