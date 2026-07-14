<?php

namespace App\Enums;

enum SubcontractStatus: string
{
    case Draft             = 'draft';
    case Active            = 'active';
    case PartiallyAccepted = 'partially_accepted';
    case Completed         = 'completed';
    case Cancelled         = 'cancelled';

    public function label(): string
    {
        return match($this) {
            self::Draft             => 'Nháp',
            self::Active            => 'Đang hiệu lực',
            self::PartiallyAccepted => 'Đã nghiệm thu một phần',
            self::Completed         => 'Hoàn thành',
            self::Cancelled         => 'Đã hủy',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::Draft             => 'gray',
            self::Active            => 'blue',
            self::PartiallyAccepted => 'yellow',
            self::Completed         => 'green',
            self::Cancelled         => 'red',
        };
    }
}
