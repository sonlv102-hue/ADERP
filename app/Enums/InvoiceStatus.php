<?php

namespace App\Enums;

enum InvoiceStatus: string
{
    case Draft   = 'draft';
    case Sent    = 'sent';
    case Paid    = 'paid';
    case Overdue = 'overdue';

    public function label(): string
    {
        return match($this) {
            self::Draft   => 'Nháp',
            self::Sent    => 'Đã gửi',
            self::Paid    => 'Đã thanh toán',
            self::Overdue => 'Quá hạn',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::Draft   => 'gray',
            self::Sent    => 'blue',
            self::Paid    => 'green',
            self::Overdue => 'red',
        };
    }
}
