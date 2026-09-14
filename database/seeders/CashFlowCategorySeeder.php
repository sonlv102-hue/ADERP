<?php

namespace Database\Seeders;

use App\Models\CashFlowCategory;
use Illuminate\Database\Seeder;

/**
 * Danh mục Nguồn tiền vào / Mục đích tiền ra mặc định (spec §3/§4).
 * Idempotent — chạy lại không tạo trùng. is_system=true để không cho xóa cứng
 * (chỉ cho sửa tên/is_active), tránh mồ côi phân loại lịch sử.
 */
class CashFlowCategorySeeder extends Seeder
{
    public function run(): void
    {
        $inflow = [
            'in_customer_payment'  => 'Khách hàng thanh toán',
            'in_customer_advance'  => 'Khách hàng tạm ứng',
            'in_advance_return'    => 'Hoàn ứng',
            'in_refund'            => 'Hoàn tiền',
            'in_debt_collection'   => 'Thu hồi công nợ',
            'in_capital'           => 'Vốn góp',
            'in_bank_loan'         => 'Vay ngân hàng',
            'in_personal_loan'     => 'Vay cá nhân/tổ chức',
            'in_deposit_interest'  => 'Lãi tiền gửi',
            'in_financial_income'  => 'Thu nhập tài chính',
            'in_internal_transfer' => 'Chuyển tiền nội bộ',
            'in_other'             => 'Thu khác',
            'in_unclassified'      => 'Chưa xác định',
        ];

        $outflow = [
            'out_supplier_payment'    => 'Thanh toán nhà cung cấp',
            'out_employee_advance'    => 'Tạm ứng nhân viên',
            'out_customer_refund'     => 'Hoàn tiền khách hàng',
            'out_salary'              => 'Trả lương',
            'out_insurance'           => 'BHXH',
            'out_tax'                 => 'Thuế',
            'out_office_expense'      => 'Chi phí văn phòng',
            'out_project_expense'     => 'Chi phí dự án',
            'out_material_purchase'   => 'Mua vật tư',
            'out_asset_purchase'      => 'Mua tài sản',
            'out_loan_repayment'      => 'Trả nợ vay',
            'out_loan_interest'       => 'Trả lãi vay',
            'out_bank_fee'            => 'Phí ngân hàng',
            'out_internal_transfer'   => 'Chuyển tiền nội bộ',
            'out_other'               => 'Chi khác',
            'out_unclassified'        => 'Chưa xác định',
        ];

        $this->seedGroup($inflow, 'in');
        $this->seedGroup($outflow, 'out');
    }

    private function seedGroup(array $items, string $direction): void
    {
        $order = 0;
        foreach ($items as $code => $name) {
            CashFlowCategory::updateOrCreate(
                ['code' => $code],
                ['name' => $name, 'direction' => $direction, 'is_system' => true, 'sort_order' => $order++]
            );
        }
    }
}
