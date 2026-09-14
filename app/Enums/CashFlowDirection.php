<?php

namespace App\Enums;

enum CashFlowDirection: string
{
    case In = 'in';
    case Out = 'out';

    public function label(): string
    {
        return match ($this) {
            self::In => 'Tiền vào',
            self::Out => 'Tiền ra',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::In => 'green',
            self::Out => 'red',
        };
    }
}
