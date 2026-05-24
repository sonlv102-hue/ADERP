<?php

namespace App\Enums;

enum QuotationStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Expired = 'expired';

    public function label(): string
    {
        return match($this) {
            self::Draft    => 'Nháp',
            self::Sent     => 'Đã gửi',
            self::Approved => 'Đã duyệt',
            self::Rejected => 'Từ chối',
            self::Expired  => 'Hết hạn',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::Draft    => 'gray',
            self::Sent     => 'blue',
            self::Approved => 'green',
            self::Rejected => 'red',
            self::Expired  => 'yellow',
        };
    }
}
