<?php

namespace App\Enums;

enum PurchaseQuoteComparisonStatus: string
{
    case Draft = 'draft';
    case Quoted = 'quoted';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Nháp',
            self::Quoted => 'Đã nhập báo giá',
            self::Completed => 'Hoàn thành',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Quoted => 'blue',
            self::Completed => 'green',
        };
    }
}
