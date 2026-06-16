<?php

namespace Database\Seeders;

use App\Models\FixedAssetCategory;
use Illuminate\Database\Seeder;

class FixedAssetCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            [
                'code'                      => 'NV-VKT',
                'name'                      => 'Nhà cửa, vật kiến trúc',
                'asset_account_code'        => '2111',
                'depreciation_account_code' => '2141',
                'expense_account_code'      => '6421',
                'min_useful_life_months'    => 120,  // 10 năm
                'max_useful_life_months'    => 480,  // 40 năm
                'legal_basis'               => 'TT45/2013 Phụ lục 1 Nhóm 1',
                'description'               => 'Nhà xưởng, văn phòng, kho bãi, hàng rào, sân',
            ],
            [
                'code'                      => 'MMTB',
                'name'                      => 'Máy móc thiết bị',
                'asset_account_code'        => '2111',
                'depreciation_account_code' => '2141',
                'expense_account_code'      => '6421',
                'min_useful_life_months'    => 60,   // 5 năm
                'max_useful_life_months'    => 180,  // 15 năm
                'legal_basis'               => 'TT45/2013 Phụ lục 1 Nhóm 2',
                'description'               => 'Máy tính, máy in, thiết bị điện tử, máy móc sản xuất',
            ],
            [
                'code'                      => 'PTVT',
                'name'                      => 'Phương tiện vận tải',
                'asset_account_code'        => '2111',
                'depreciation_account_code' => '2141',
                'expense_account_code'      => '6421',
                'min_useful_life_months'    => 72,   // 6 năm
                'max_useful_life_months'    => 120,  // 10 năm
                'legal_basis'               => 'TT45/2013 Phụ lục 1 Nhóm 3',
                'description'               => 'Ô tô, xe máy, xe tải, xe đặc chủng',
            ],
            [
                'code'                      => 'TBDCQL',
                'name'                      => 'Thiết bị, dụng cụ quản lý',
                'asset_account_code'        => '2111',
                'depreciation_account_code' => '2141',
                'expense_account_code'      => '6421',
                'min_useful_life_months'    => 36,   // 3 năm
                'max_useful_life_months'    => 60,   // 5 năm
                'legal_basis'               => 'TT45/2013 Phụ lục 1 Nhóm 4',
                'description'               => 'Bàn ghế, tủ, điều hòa, thiết bị văn phòng',
            ],
            [
                'code'                      => 'TSCĐVH',
                'name'                      => 'TSCĐ vô hình',
                'asset_account_code'        => '2113',
                'depreciation_account_code' => '2143',
                'expense_account_code'      => '6421',
                'min_useful_life_months'    => 12,
                'max_useful_life_months'    => 240,  // 20 năm
                'legal_basis'               => 'TT45/2013 Nhóm 5 / TT30/2025 TSCĐVH',
                'description'               => 'Phần mềm, bản quyền, thương hiệu, giá trị lợi thế thương mại',
            ],
            [
                'code'                      => 'TSCĐTTC',
                'name'                      => 'TSCĐ thuê tài chính',
                'asset_account_code'        => '2112',
                'depreciation_account_code' => '2142',
                'expense_account_code'      => '6421',
                'min_useful_life_months'    => null,
                'max_useful_life_months'    => null,
                'legal_basis'               => 'TT45/2013 Nhóm 6',
                'description'               => 'Tài sản thuê tài chính theo hợp đồng leasing',
            ],
            [
                'code'                      => 'TSCĐKHAC',
                'name'                      => 'TSCĐ khác',
                'asset_account_code'        => '2111',
                'depreciation_account_code' => '2141',
                'expense_account_code'      => '6421',
                'min_useful_life_months'    => null,
                'max_useful_life_months'    => null,
                'legal_basis'               => 'TT45/2013',
                'description'               => 'Các tài sản cố định không thuộc các nhóm trên',
            ],
        ];

        foreach ($categories as $cat) {
            FixedAssetCategory::firstOrCreate(['code' => $cat['code']], $cat);
        }
    }
}
