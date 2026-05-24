<?php

namespace App\Enums;

enum TicketStatus: string
{
    case Open       = 'open';
    case InProgress = 'in_progress';
    case Resolved   = 'resolved';
    case Closed     = 'closed';

    public function label(): string
    {
        return match($this) {
            self::Open       => 'Mở',
            self::InProgress => 'Đang xử lý',
            self::Resolved   => 'Đã giải quyết',
            self::Closed     => 'Đã đóng',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::Open       => 'blue',
            self::InProgress => 'yellow',
            self::Resolved   => 'green',
            self::Closed     => 'gray',
        };
    }
}
