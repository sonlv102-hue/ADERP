<?php

namespace App\Enums;

enum DirectMaterialHandlingType: string
{
    case TrackingOnly  = 'tracking_only';
    case InvoiceLink   = 'invoice_link';
    case JournalEntry  = 'journal_entry';

    public function label(): string
    {
        return match($this) {
            self::TrackingOnly => 'Chỉ theo dõi nội bộ',
            self::InvoiceLink  => 'Liên kết hóa đơn mua',
            self::JournalEntry => 'Ghi nhận TK 154',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::TrackingOnly => 'gray',
            self::InvoiceLink  => 'blue',
            self::JournalEntry => 'purple',
        };
    }
}
