<?php

namespace App\Enums;

enum LeadStatus: string
{
    case New = 'new';
    case Contacted = 'contacted';
    case Qualified = 'qualified';
    case Proposal = 'proposal';
    case Negotiation = 'negotiation';
    case Won = 'won';
    case Lost = 'lost';

    public function label(): string
    {
        return match($this) {
            self::New => 'Mới',
            self::Contacted => 'Đã liên hệ',
            self::Qualified => 'Tiềm năng',
            self::Proposal => 'Báo giá',
            self::Negotiation => 'Đàm phán',
            self::Won => 'Chốt thành công',
            self::Lost => 'Thất bại',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::New => 'gray',
            self::Contacted => 'blue',
            self::Qualified => 'yellow',
            self::Proposal => 'purple',
            self::Negotiation => 'orange',
            self::Won => 'green',
            self::Lost => 'red',
        };
    }
}
