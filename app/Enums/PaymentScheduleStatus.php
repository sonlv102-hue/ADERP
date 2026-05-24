<?php

namespace App\Enums;

enum PaymentScheduleStatus: string
{
    case Pending  = 'pending';
    case Paid     = 'paid';
    case Overdue  = 'overdue';

    public function label(): string
    {
        return match($this) {
            self::Pending => 'Chờ thanh toán',
            self::Paid    => 'Đã thanh toán',
            self::Overdue => 'Quá hạn',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::Pending => 'yellow',
            self::Paid    => 'green',
            self::Overdue => 'red',
        };
    }
}
