<?php

namespace App\Enums;

enum FixedAssetStatus: string
{
    case PendingUse       = 'pending_use';
    case Active           = 'active';
    case Suspended        = 'suspended';
    case Disposed         = 'disposed';
    case FullyDepreciated = 'fully_depreciated';
    case WrittenOff       = 'written_off';

    public function label(): string
    {
        return match($this) {
            self::PendingUse       => 'Chờ sử dụng',
            self::Active           => 'Đang sử dụng',
            self::Suspended        => 'Tạm dừng khấu hao',
            self::Disposed         => 'Đã thanh lý',
            self::FullyDepreciated => 'Đã khấu hao hết',
            self::WrittenOff       => 'Đã xóa sổ',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::PendingUse       => 'yellow',
            self::Active           => 'green',
            self::Suspended        => 'orange',
            self::Disposed         => 'red',
            self::FullyDepreciated => 'gray',
            self::WrittenOff       => 'red',
        };
    }

    public function canDepreciate(): bool
    {
        return $this === self::Active;
    }
}
