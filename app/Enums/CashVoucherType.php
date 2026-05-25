<?php

namespace App\Enums;

enum CashVoucherType: string
{
    case Receipt = 'receipt';
    case Payment = 'payment';

    public function label(): string
    {
        return match($this) {
            self::Receipt => 'Phiếu thu',
            self::Payment => 'Phiếu chi',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::Receipt => 'green',
            self::Payment => 'red',
        };
    }

    public function codePrefix(): string
    {
        return match($this) {
            self::Receipt => 'PT',
            self::Payment => 'PC',
        };
    }
}
