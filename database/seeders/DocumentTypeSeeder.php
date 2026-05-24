<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DocumentTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            ['code' => 'HDMB',  'name' => 'Hợp đồng mua bán',        'description' => 'Hợp đồng kinh tế giữa hai bên mua bán'],
            ['code' => 'PLHD',  'name' => 'Phụ lục hợp đồng',        'description' => 'Phụ lục điều chỉnh/bổ sung hợp đồng'],
            ['code' => 'BGBG',  'name' => 'Báo giá đã duyệt',         'description' => 'Báo giá được khách hàng chấp thuận'],
            ['code' => 'HDVR',  'name' => 'Hóa đơn đầu ra (VAT)',     'description' => 'Hóa đơn GTGT xuất cho khách hàng'],
            ['code' => 'HDVV',  'name' => 'Hóa đơn đầu vào (VAT)',    'description' => 'Hóa đơn GTGT từ nhà cung cấp'],
            ['code' => 'BBBD',  'name' => 'Biên bản bàn giao',        'description' => 'Biên bản bàn giao thiết bị/dự án'],
            ['code' => 'BBNT',  'name' => 'Biên bản nghiệm thu',      'description' => 'Biên bản nghiệm thu hoàn thành công trình'],
            ['code' => 'BBKS',  'name' => 'Biên bản khảo sát',        'description' => 'Biên bản khảo sát hiện trạng'],
            ['code' => 'PKXK',  'name' => 'Phiếu xuất kho',           'description' => 'Phiếu xuất kho thiết bị/vật tư'],
            ['code' => 'PKNK',  'name' => 'Phiếu nhập kho',           'description' => 'Phiếu nhập kho thiết bị/vật tư'],
            ['code' => 'CTBH',  'name' => 'Chứng từ bảo hành',        'description' => 'Giấy bảo hành, phiếu bảo hành sản phẩm'],
            ['code' => 'CTTT',  'name' => 'Chứng từ thanh toán',      'description' => 'Ủy nhiệm chi, biên lai, phiếu thu/chi'],
            ['code' => 'TKQTT', 'name' => 'Quyết toán thi công',      'description' => 'Bảng quyết toán chi phí dự án thi công'],
            ['code' => 'CTHH',  'name' => 'Chứng từ hoa hồng',        'description' => 'Chứng từ thanh toán hoa hồng/chiết khấu'],
            ['code' => 'KHAC',  'name' => 'Khác',                     'description' => 'Loại chứng từ khác'],
        ];

        foreach ($types as $type) {
            DB::table('document_types')->updateOrInsert(
                ['code' => $type['code']],
                [
                    'name'        => $type['name'],
                    'description' => $type['description'],
                    'is_active'   => true,
                    'updated_at'  => now(),
                    'created_at'  => now(),
                ]
            );
        }
    }
}
