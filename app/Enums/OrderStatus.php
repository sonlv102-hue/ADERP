<?php

namespace App\Enums;

enum OrderStatus: string
{
    case Pending          = 'pending';
    case Processing       = 'processing';
    case PartialDelivered = 'partial_delivered';
    case Completed        = 'completed';
    case Cancelled        = 'cancelled';

    public function label(): string
    {
        return match($this) {
            self::Pending          => 'Chờ xử lý',
            self::Processing       => 'Đang xử lý',
            self::PartialDelivered => 'Giao một phần',
            self::Completed        => 'Hoàn thành',
            self::Cancelled        => 'Đã hủy',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::Pending          => 'gray',
            self::Processing       => 'blue',
            self::PartialDelivered => 'orange',
            self::Completed        => 'green',
            self::Cancelled        => 'red',
        };
    }
}
