<?php

namespace App\Enums;

enum PurchaseOrderInvoiceType: string
{
    case Vat        = 'vat';
    case Retail     = 'retail';
    case NoInvoice  = 'no_invoice';

    public function label(): string
    {
        return match($this) {
            self::Vat       => 'Có hóa đơn GTGT',
            self::Retail    => 'Hóa đơn bán lẻ',
            self::NoInvoice => 'Không có hóa đơn',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::Vat       => 'green',
            self::Retail    => 'amber',
            self::NoInvoice => 'gray',
        };
    }
}
