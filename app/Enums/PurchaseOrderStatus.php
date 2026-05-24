<?php

namespace App\Enums;

enum PurchaseOrderStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case PartialReceived = 'partial_received';
    case Received = 'received';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match($this) {
            self::Draft => 'Nháp',
            self::Sent => 'Đã gửi NCC',
            self::PartialReceived => 'Nhận một phần',
            self::Received => 'Đã nhận hàng',
            self::Cancelled => 'Đã hủy',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::Draft => 'gray',
            self::Sent => 'blue',
            self::PartialReceived => 'orange',
            self::Received => 'green',
            self::Cancelled => 'red',
        };
    }
}
