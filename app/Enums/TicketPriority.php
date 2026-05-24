<?php

namespace App\Enums;

enum TicketPriority: string
{
    case Low    = 'low';
    case Medium = 'medium';
    case High   = 'high';
    case Urgent = 'urgent';

    public function label(): string
    {
        return match($this) {
            self::Low    => 'Thấp',
            self::Medium => 'Trung bình',
            self::High   => 'Cao',
            self::Urgent => 'Khẩn cấp',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::Low    => 'gray',
            self::Medium => 'blue',
            self::High   => 'orange',
            self::Urgent => 'red',
        };
    }
}
