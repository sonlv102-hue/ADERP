<?php

namespace App\Enums;

enum SubcontractorType: string
{
    case Company    = 'company';
    case Team       = 'team';
    case Individual = 'individual';

    public function label(): string
    {
        return match($this) {
            self::Company    => 'Có hóa đơn (pháp nhân)',
            self::Team       => 'Đội nhóm không hóa đơn',
            self::Individual => 'Cá nhân không hóa đơn',
        };
    }

    public function requiresInvoice(): bool
    {
        return $this === self::Company;
    }
}
