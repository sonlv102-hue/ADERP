<?php

namespace Database\Seeders;

use App\Models\PitConfig;
use Illuminate\Database\Seeder;

class PitConfigSeeder extends Seeder
{
    /**
     * Seed cấu hình thuế TNCN theo kỳ hiệu lực.
     *
     * Căn cứ pháp lý:
     *   - Luật số 04/2007/QH12 + sửa đổi 2012, 2014: biểu thuế lũy tiến 7 bậc
     *   - Nghị quyết 954/2020/QĐ-UBTVQH14 (01/07/2020): giảm trừ bản thân 11M, NPT 4.4M
     *   - TT 111/2013/TT-BTC sửa đổi bởi TT 79/2022/TT-BTC: giảm trừ bản thân 15.5M, NPT 6.2M
     *   - Nghị định 158/2025/NĐ-CP (01/07/2025): trần BHXH ≈ 46.8M
     */
    public function run(): void
    {
        // Đóng kỳ cũ khi có kỳ mới
        PitConfig::where('effective_from', '2020-07-01')
            ->whereNull('effective_to')
            ->update(['effective_to' => '2025-12-31', 'is_active' => false]);

        // Kỳ cũ: NQ 954/2020 — giảm trừ 11M/4.4M (01/07/2020 – 31/12/2025)
        PitConfig::firstOrCreate(
            ['effective_from' => '2020-07-01'],
            [
                'effective_to'        => '2025-12-31',
                'personal_deduction'  => 11_000_000,
                'dependent_deduction' => 4_400_000,
                'insurance_cap'       => 46_800_000,
                'brackets'            => null,
                'legal_basis'         => 'NQ 954/2020/QĐ-UBTVQH14; NĐ 158/2025/NĐ-CP (trần BHXH)',
                'is_active'           => false,
            ]
        );

        // Kỳ mới: TT 79/2022 — giảm trừ 15.5M/6.2M (01/01/2026 – hiện tại)
        PitConfig::firstOrCreate(
            ['effective_from' => '2026-01-01'],
            [
                'effective_to'        => null,
                'personal_deduction'  => 15_500_000,
                'dependent_deduction' => 6_200_000,
                'insurance_cap'       => 46_800_000,
                'brackets'            => null,
                'legal_basis'         => 'TT 111/2013/TT-BTC sửa đổi bởi TT 79/2022/TT-BTC',
                'is_active'           => true,
            ]
        );
    }
}
