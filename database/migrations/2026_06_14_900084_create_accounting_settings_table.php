<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->string('value', 100)->nullable();
            $table->string('label', 255);
            $table->text('description')->nullable();
            $table->string('group', 100)->default('general');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        DB::table('accounting_settings')->insert([
            // --- Tiền mặt & Ngân hàng ---
            ['key' => 'cash_account',        'value' => '1111', 'label' => 'TK tiền mặt tại quỹ',         'description' => 'Dùng khi thanh toán / thu tiền mặt',                       'group' => 'cash',         'sort_order' => 10, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'bank_account',         'value' => '1121', 'label' => 'TK tiền gửi ngân hàng',        'description' => 'Dùng khi thanh toán / thu qua chuyển khoản',                'group' => 'cash',         'sort_order' => 20, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'default_ar_account',   'value' => '1311', 'label' => 'TK phải thu KH (mặc định)',    'description' => 'Dùng khi phiếu thu không gắn với khách hàng cụ thể',       'group' => 'cash',         'sort_order' => 30, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'default_ap_account',   'value' => '3311', 'label' => 'TK phải trả NCC (mặc định)',   'description' => 'Dùng khi phiếu chi không gắn với nhà cung cấp cụ thể',     'group' => 'cash',         'sort_order' => 40, 'created_at' => now(), 'updated_at' => now()],

            // --- Hàng tồn kho ---
            ['key' => 'vat_input_account',         'value' => '1331', 'label' => 'TK thuế GTGT đầu vào',          'description' => 'Hạch toán VAT khi nhập hàng',                              'group' => 'inventory',    'sort_order' => 10, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'default_inventory_account', 'value' => '1561', 'label' => 'TK hàng hóa nhập kho (mặc định)', 'description' => 'Dùng khi sản phẩm không cấu hình TK kho riêng',          'group' => 'inventory',    'sort_order' => 20, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'inventory_surplus_account', 'value' => '711',  'label' => 'TK thừa kho (kiểm kê)',        'description' => 'Ghi có khi kiểm kê phát hiện thừa hàng',                   'group' => 'inventory',    'sort_order' => 30, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'inventory_shortage_account','value' => '811',  'label' => 'TK thiếu kho (kiểm kê)',       'description' => 'Ghi nợ khi kiểm kê phát hiện thiếu hàng',                  'group' => 'inventory',    'sort_order' => 40, 'created_at' => now(), 'updated_at' => now()],

            // --- Doanh thu ---
            ['key' => 'product_revenue_account', 'value' => '5111', 'label' => 'TK doanh thu bán hàng hóa',   'description' => 'Mặc định khi sản phẩm không có TK doanh thu riêng',      'group' => 'revenue',      'sort_order' => 10, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'service_revenue_account', 'value' => '5113', 'label' => 'TK doanh thu cung cấp dịch vụ', 'description' => 'Mặc định cho dịch vụ và sản phẩm loại service',          'group' => 'revenue',      'sort_order' => 20, 'created_at' => now(), 'updated_at' => now()],

            // --- Giá vốn & Chi phí ---
            ['key' => 'default_cogs_account',   'value' => '632',  'label' => 'TK giá vốn hàng bán',         'description' => 'Dùng khi xuất kho giao đơn hàng bán',                      'group' => 'cogs',         'sort_order' => 10, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'project_wip_account',    'value' => '154',  'label' => 'TK chi phí SXKD dở dang (WIP)', 'description' => 'Dùng khi xuất kho cho dự án',                             'group' => 'cogs',         'sort_order' => 20, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'selling_expense_account','value' => '6421', 'label' => 'TK chi phí bán hàng',          'description' => 'Dùng cho xuất kho nội bộ bán hàng',                        'group' => 'cogs',         'sort_order' => 30, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'admin_expense_account',  'value' => '6422', 'label' => 'TK chi phí quản lý DN',        'description' => 'Mặc định cho mua dịch vụ, xuất kho nội bộ quản lý',       'group' => 'cogs',         'sort_order' => 40, 'created_at' => now(), 'updated_at' => now()],

            // --- Lương & Bảo hiểm ---
            ['key' => 'salary_payable_account',     'value' => '334',  'label' => 'TK phải trả người lao động',    'description' => 'Tổng hợp lương phải trả nhân viên',                    'group' => 'payroll',      'sort_order' => 10, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'pit_payable_account',        'value' => '3335', 'label' => 'TK thuế TNCN phải nộp',        'description' => 'Giữ lại từ lương nhân viên',                            'group' => 'payroll',      'sort_order' => 20, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'bhxh_payable_account',       'value' => '3383', 'label' => 'TK BHXH phải nộp',             'description' => 'BHXH nhân viên + công ty',                              'group' => 'payroll',      'sort_order' => 30, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'bhyt_payable_account',       'value' => '3384', 'label' => 'TK BHYT phải nộp',             'description' => 'BHYT nhân viên + công ty',                              'group' => 'payroll',      'sort_order' => 40, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'bhtn_payable_account',       'value' => '3385', 'label' => 'TK bảo hiểm thất nghiệp',     'description' => 'BHTN nhân viên + công ty',                              'group' => 'payroll',      'sort_order' => 50, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'union_fee_payable_account',  'value' => '3382', 'label' => 'TK kinh phí công đoàn',        'description' => 'KPCĐ phải nộp',                                         'group' => 'payroll',      'sort_order' => 60, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'payroll_sales_labor_account',      'value' => '6421', 'label' => 'Chi phí nhân công — Bán hàng',   'description' => 'Bộ phận có từ khóa SALES / BÁN HÀNG',          'group' => 'payroll',      'sort_order' => 70, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'payroll_production_labor_account', 'value' => '627',  'label' => 'Chi phí nhân công — Kỹ thuật',  'description' => 'Bộ phận TECHNICAL / KỸ THUẬT / SẢN XUẤT',       'group' => 'payroll',      'sort_order' => 80, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'payroll_admin_labor_account',      'value' => '6422', 'label' => 'Chi phí nhân công — Quản lý',   'description' => 'Mặc định cho bộ phận còn lại',                  'group' => 'payroll',      'sort_order' => 90, 'created_at' => now(), 'updated_at' => now()],

            // --- Kết chuyển cuối kỳ ---
            ['key' => 'period_close_pnl_account',                'value' => '911',  'label' => 'TK xác định kết quả kinh doanh',  'description' => 'Trung gian kết chuyển doanh thu - chi phí',    'group' => 'period_close', 'sort_order' => 10, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'period_close_retained_earnings_account',  'value' => '4212', 'label' => 'TK lợi nhuận chưa phân phối',     'description' => 'Nhận kết chuyển lãi/lỗ cuối kỳ',             'group' => 'period_close', 'sort_order' => 20, 'created_at' => now(), 'updated_at' => now()],

            // --- Dự án (WIP) ---
            ['key' => 'project_labor_account',    'value' => '6271', 'label' => 'TK nhân công trực tiếp dự án',    'description' => 'Chi phí lao động trực tiếp ghi vào dự án',         'group' => 'project',      'sort_order' => 10, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'project_material_account', 'value' => '6272', 'label' => 'TK nguyên vật liệu trực tiếp dự án', 'description' => 'Vật liệu xuất thẳng cho dự án',                'group' => 'project',      'sort_order' => 20, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'project_transport_account','value' => '6278', 'label' => 'TK dịch vụ mua ngoài dự án',     'description' => 'Vận chuyển, thuê ngoài cho dự án',                 'group' => 'project',      'sort_order' => 30, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'project_other_account',    'value' => '6279', 'label' => 'TK chi phí bằng tiền khác dự án', 'description' => 'Chi phí phát sinh bằng tiền khác cho dự án',      'group' => 'project',      'sort_order' => 40, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_settings');
    }
};
