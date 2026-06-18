<?php

namespace App\Enums;

enum SmallToolStatus: string
{
    case Draft         = 'draft';
    case InStock       = 'in_stock';
    case InUse         = 'in_use';
    case Allocating    = 'allocating';
    case FullyAllocated = 'fully_allocated';
    case Broken        = 'broken';
    case Lost          = 'lost';
    case Disposed      = 'disposed';
    case Cancelled     = 'cancelled';

    public function label(): string
    {
        return match($this) {
            self::Draft          => 'Nháp',
            self::InStock        => 'Trong kho',
            self::InUse          => 'Đang sử dụng',
            self::Allocating     => 'Đang phân bổ',
            self::FullyAllocated => 'Đã phân bổ hết',
            self::Broken         => 'Hỏng',
            self::Lost           => 'Mất',
            self::Disposed       => 'Thanh lý',
            self::Cancelled      => 'Đã hủy',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::Draft          => 'gray',
            self::InStock        => 'blue',
            self::InUse          => 'green',
            self::Allocating     => 'yellow',
            self::FullyAllocated => 'purple',
            self::Broken         => 'red',
            self::Lost           => 'red',
            self::Disposed       => 'gray',
            self::Cancelled      => 'gray',
        };
    }

    public function isActive(): bool
    {
        return in_array($this, [self::InStock, self::InUse, self::Allocating]);
    }
}
