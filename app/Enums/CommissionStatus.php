<?php

namespace App\Enums;

enum CommissionStatus: string
{
    case Draft          = 'draft';
    case PendingL1      = 'pending_l1';
    case PendingL2      = 'pending_l2';
    case PendingPayment = 'pending_payment';
    case Paid           = 'paid';
    case Rejected       = 'rejected';
    case Cancelled      = 'cancelled';

    public function label(): string
    {
        return match($this) {
            self::Draft          => 'Nháp',
            self::PendingL1      => 'Chờ TP duyệt',
            self::PendingL2      => 'Chờ GĐ duyệt',
            self::PendingPayment => 'Chờ thanh toán',
            self::Paid           => 'Đã thanh toán',
            self::Rejected       => 'Từ chối',
            self::Cancelled      => 'Đã hủy',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::Draft          => 'gray',
            self::PendingL1      => 'yellow',
            self::PendingL2      => 'orange',
            self::PendingPayment => 'blue',
            self::Paid           => 'green',
            self::Rejected       => 'red',
            self::Cancelled      => 'red',
        };
    }
}
