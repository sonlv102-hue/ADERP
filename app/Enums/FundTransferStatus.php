<?php

namespace App\Enums;

enum FundTransferStatus: string
{
    case Draft     = 'draft';
    case Posted    = 'posted';
    case Reversed  = 'reversed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft     => 'Nháp',
            self::Posted    => 'Đã ghi sổ',
            self::Reversed  => 'Đã đảo',
            self::Cancelled => 'Đã hủy',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft     => 'gray',
            self::Posted    => 'blue',
            self::Reversed  => 'orange',
            self::Cancelled => 'red',
        };
    }
}
