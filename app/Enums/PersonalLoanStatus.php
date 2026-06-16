<?php

namespace App\Enums;

enum PersonalLoanStatus: string
{
    case Draft           = 'draft';
    case Active          = 'active';
    case PartiallyRepaid = 'partially_repaid';
    case Repaid          = 'repaid';
    case Cancelled       = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft           => 'Nháp',
            self::Active          => 'Đang vay',
            self::PartiallyRepaid => 'Trả một phần',
            self::Repaid          => 'Đã trả đủ',
            self::Cancelled       => 'Đã hủy',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft           => 'gray',
            self::Active          => 'blue',
            self::PartiallyRepaid => 'yellow',
            self::Repaid          => 'green',
            self::Cancelled       => 'red',
        };
    }
}
