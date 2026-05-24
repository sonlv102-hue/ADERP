<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Cash         = 'cash';
    case BankTransfer = 'bank_transfer';
    case Other        = 'other';

    public function label(): string
    {
        return match($this) {
            self::Cash         => 'Tiền mặt',
            self::BankTransfer => 'Chuyển khoản',
            self::Other        => 'Khác',
        };
    }
}
