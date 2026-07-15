<?php

namespace App\Enums;

enum SubcontractCostGroup: string
{
    case Material      = 'material';
    case Labor         = 'labor';
    case Subcontractor = 'subcontractor';
    case Equipment     = 'equipment';
    case Transport     = 'transport';
    case Overhead      = 'overhead';
    case Other         = 'other';

    public function label(): string
    {
        return match($this) {
            self::Material      => 'Vật tư / Hàng hóa',
            self::Labor         => 'Nhân công',
            self::Subcontractor => 'Nhà thầu phụ',
            self::Equipment     => 'Máy thi công / thiết bị',
            self::Transport     => 'Vận chuyển',
            self::Overhead      => 'Chi phí chung',
            self::Other         => 'Khác',
        };
    }

    /** cost_type dùng cho ProjectWipEntry — khớp giá trị có sẵn trong ProjectWipEntry::$costTypeLabels */
    public function wipCostType(): string
    {
        return match($this) {
            self::Subcontractor => 'subcontract',
            self::Material      => 'material',
            self::Labor         => 'labor',
            default             => 'overhead', // Equipment, Transport, Overhead, Other
        };
    }
}
