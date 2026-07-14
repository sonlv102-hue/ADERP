<?php

namespace App\Enums;

enum SubcontractCostGroup: string
{
    case Subcontractor = 'subcontractor';
    case Labor         = 'labor';
    case Equipment     = 'equipment';
    case Transport     = 'transport';
    case Other         = 'other';

    public function label(): string
    {
        return match($this) {
            self::Subcontractor => 'Nhà thầu phụ',
            self::Labor         => 'Nhân công',
            self::Equipment     => 'Máy thi công',
            self::Transport     => 'Vận chuyển',
            self::Other         => 'Khác',
        };
    }

    /** cost_type dùng cho ProjectWipEntry — khớp label có sẵn trong ProjectWipEntry::$costTypeLabels */
    public function wipCostType(): string
    {
        return match($this) {
            self::Subcontractor => 'subcontract',
            self::Labor         => 'labor',
            default             => 'overhead',
        };
    }
}
