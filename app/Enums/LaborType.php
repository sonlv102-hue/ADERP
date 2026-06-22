<?php

namespace App\Enums;

enum LaborType: string
{
    case InternalEmployee     = 'internal_employee';
    case FreelanceContractor  = 'freelance_contractor';
    case SubcontractorInvoice = 'subcontractor_invoice';
    case InsuranceAllocation  = 'insurance_allocation';

    public function label(): string
    {
        return match($this) {
            self::InternalEmployee     => 'Nhân công nội bộ',
            self::FreelanceContractor  => 'Thuê khoán cá nhân/đội nhóm',
            self::SubcontractorInvoice => 'Nhà thầu phụ có hóa đơn',
            self::InsuranceAllocation  => 'Trích BHXH/KPCĐ',
        };
    }

    public function defaultPaymentMethod(): string
    {
        return match($this) {
            self::InternalEmployee     => 'salary',
            self::FreelanceContractor  => 'misc',
            self::SubcontractorInvoice => 'payable',
            self::InsuranceAllocation  => 'misc',
        };
    }
}
