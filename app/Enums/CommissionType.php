<?php

namespace App\Enums;

enum CommissionType: string
{
    case Referral        = 'referral';
    case Brokerage       = 'brokerage';
    case CustomerCare    = 'customer_care';
    case Hospitality     = 'hospitality';
    case SalesSupport    = 'sales_support';
    case AfterSales      = 'after_sales';
    case TradeDiscount   = 'trade_discount';
    case PaymentDiscount = 'payment_discount';
    case PartnerCost     = 'partner_cost';
    case Collaborator    = 'collaborator';

    public function label(): string
    {
        return match($this) {
            self::Referral        => 'Hoa hồng giới thiệu KH',
            self::Brokerage       => 'Hoa hồng môi giới dự án',
            self::CustomerCare    => 'Chi phí chăm sóc KH',
            self::Hospitality     => 'Chi phí tiếp khách',
            self::SalesSupport    => 'Chi phí hỗ trợ bán hàng',
            self::AfterSales      => 'Chi phí sau bán hàng',
            self::TradeDiscount   => 'Chiết khấu thương mại',
            self::PaymentDiscount => 'Chiết khấu thanh toán',
            self::PartnerCost     => 'Chi phí đối tác triển khai',
            self::Collaborator    => 'Chi phí cộng tác viên',
        };
    }
}
