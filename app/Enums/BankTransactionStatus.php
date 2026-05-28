<?php

namespace App\Enums;

enum BankTransactionStatus: string
{
    case Pending    = 'pending';
    case Reconciled = 'reconciled';

    public function label(): string
    {
        return match($this) {
            self::Pending    => 'Chờ đối chiếu',
            self::Reconciled => 'Đã đối chiếu',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::Pending    => 'yellow',
            self::Reconciled => 'green',
        };
    }
}
