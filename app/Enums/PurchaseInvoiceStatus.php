<?php

namespace App\Enums;

enum PurchaseInvoiceStatus: string
{
    case Pending      = 'pending';
    case Received     = 'received';
    case Reviewing    = 'reviewing';
    case Valid        = 'valid';
    case NeedSupplement = 'need_supplement';
    case PartialPaid  = 'partial_paid';
    case Paid         = 'paid';
    case Cancelled    = 'cancelled';

    public function label(): string
    {
        return match($this) {
            self::Pending        => 'Chưa nhận HĐ',
            self::Received       => 'Đã nhận HĐ',
            self::Reviewing      => 'Đang kiểm tra',
            self::Valid          => 'Hợp lệ',
            self::NeedSupplement => 'Cần bổ sung',
            self::PartialPaid    => 'TT một phần',
            self::Paid           => 'Đã thanh toán',
            self::Cancelled      => 'Đã hủy',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::Pending        => 'gray',
            self::Received       => 'blue',
            self::Reviewing      => 'yellow',
            self::Valid          => 'indigo',
            self::NeedSupplement => 'orange',
            self::PartialPaid    => 'purple',
            self::Paid           => 'green',
            self::Cancelled      => 'red',
        };
    }
}
