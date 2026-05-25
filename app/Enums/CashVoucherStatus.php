<?php

namespace App\Enums;

enum CashVoucherStatus: string
{
    case Draft     = 'draft';
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match($this) {
            self::Draft     => 'Nháp',
            self::Confirmed => 'Đã xác nhận',
            self::Cancelled => 'Đã hủy',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::Draft     => 'gray',
            self::Confirmed => 'green',
            self::Cancelled => 'red',
        };
    }
}
