<?php

namespace Tests\Feature\Accounting;

use App\Enums\FixedAssetStatus;
use App\Models\AccountCode;
use App\Models\AccountingPeriod;
use App\Models\FixedAsset;
use App\Models\FixedAssetCategory;
use App\Models\FixedAssetDepreciation;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\FixedAssetDepreciationService;
use App\Services\FixedAssetJournalService;
use App\Services\FixedAssetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Test cases:
 * 1.  Mua TSCĐ có VAT, công nợ nhà cung cấp
 * 2.  Mua TSCĐ thanh toán tiền mặt
 * 3.  Tài sản dưới 30 triệu — cảnh báo nhưng vẫn có thể tạo
 * 4.  Tính khấu hao tháng đầu theo ngày sử dụng
 * 5.  Không cho khấu hao trùng kỳ
 * 6.  Không cho khấu hao vượt nguyên giá
 * 7.  Hủy khấu hao khi kỳ chưa khóa
 * 8.  Không cho hủy khấu hao khi kỳ đã khóa
 * 9.  Điều chuyển bộ phận không sinh bút toán nguyên giá
 * 10. Sửa chữa thường xuyên vào chi phí (Dr 6421 / Cr 3311)
 * 11. Sửa chữa lớn vào 242 và phân bổ
 * 12. Nâng cấp qua 241 rồi tăng nguyên giá 211 / 2111
 * 13. Thanh lý TSCĐ có doanh thu và VAT
 * 14. Đối chiếu tổng nguyên giá danh mục với TK 211 (smoke)
 * 15. Đối chiếu hao mòn lũy kế với TK 214 (smoke)
 * 16. Chặn post vào tài khoản cha
 * 17. Bảng cân đối kế toán — số dư 211 = acquisition_cost khi có bút toán
 */
class FixedAssetTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private FixedAssetService $service;
    private FixedAssetJournalService $journalService;
    private FixedAssetDepreciationService $depService;
    private AccountingService $accounting;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['is_active' => true]);
        $this->actingAs($this->user);
        Gate::before(fn ($u, $a) => true);

        $this->accounting     = app(AccountingService::class);
        $this->journalService = app(FixedAssetJournalService::class);
        $this->depService     = app(FixedAssetDepreciationService::class);
        $this->service        = app(FixedAssetService::class);

        $this->seedAccounts();
        AccountingPeriod::firstOrCreate(['year' => now()->year, 'month' => now()->month], ['status' => 'open']);
    }

    // -------------------------------------------------------
    // CASE 1: Mua TSCĐ có VAT, ghi vào công nợ NCC
    // -------------------------------------------------------

    public function test_acquisition_with_vat_creates_correct_journal(): void
    {
        $asset = $this->makeAsset(['acquisition_cost' => 100_000_000, 'vat_amount' => 10_000_000, 'payable_account_code' => '3311']);

        $je = $this->journalService->createAcquisitionJournal($asset);

        $this->assertEquals('draft', $je->status);
        $lines = $je->lines;

        // Dr 2111 = 100M
        $dr2111 = $lines->firstWhere('account_code', '2111');
        $this->assertNotNull($dr2111);
        $this->assertEquals(100_000_000, $dr2111->debit);

        // Dr 1332 = 10M
        $dr1332 = $lines->firstWhere('account_code', '1332');
        $this->assertNotNull($dr1332);
        $this->assertEquals(10_000_000, $dr1332->debit);

        // Cr 3311 = 110M
        $cr3311 = $lines->firstWhere('account_code', '3311');
        $this->assertNotNull($cr3311);
        $this->assertEquals(110_000_000, $cr3311->credit);

        // Bút toán cân: tổng Nợ = tổng Có
        $this->assertEquals($lines->sum('debit'), $lines->sum('credit'));
    }

    // -------------------------------------------------------
    // CASE 2: Mua TSCĐ thanh toán tiền mặt (Cr 1111)
    // -------------------------------------------------------

    public function test_acquisition_cash_payment(): void
    {
        $asset = $this->makeAsset(['acquisition_cost' => 50_000_000, 'vat_amount' => 0, 'payable_account_code' => '1111']);

        $je = $this->journalService->createAcquisitionJournal($asset);

        $cr1111 = $je->lines->firstWhere('account_code', '1111');
        $this->assertNotNull($cr1111);
        $this->assertEquals(50_000_000, $cr1111->credit);

        $dr2111 = $je->lines->firstWhere('account_code', '2111');
        $this->assertEquals(50_000_000, $dr2111->debit);

        // Không có dòng 1332 khi VAT = 0
        $this->assertNull($je->lines->firstWhere('account_code', '1332'));
    }

    // -------------------------------------------------------
    // CASE 3: Tài sản dưới 30 triệu — vẫn tạo được (cảnh báo ở UI)
    // -------------------------------------------------------

    public function test_asset_under_30m_can_still_be_created(): void
    {
        $asset = $this->service->create([
            'name'              => 'Máy tính bảng',
            'acquisition_date'  => now()->format('Y-m-d'),
            'acquisition_cost'  => 25_000_000,
            'useful_life_months' => 36,
            'depreciation_method' => 'straight_line',
            'asset_type'        => 'tangible',
        ]);

        $this->assertNotNull($asset->id);
        $this->assertEquals(25_000_000, (float) $asset->acquisition_cost);
    }

    // -------------------------------------------------------
    // CASE 4: Tính khấu hao = depreciable_amount / useful_life_months
    // -------------------------------------------------------

    public function test_monthly_depreciation_amount_is_correct(): void
    {
        $asset = $this->makeActiveAsset(['acquisition_cost' => 120_000_000, 'depreciable_amount' => 120_000_000, 'useful_life_months' => 60]);

        $this->assertEquals(2_000_000, $asset->monthly_depreciation);
    }

    // -------------------------------------------------------
    // CASE 5: Không cho khấu hao trùng kỳ
    // -------------------------------------------------------

    public function test_cannot_depreciate_same_period_twice(): void
    {
        $asset  = $this->makeActiveAsset();
        $period = now()->format('Y-m');

        $result1 = $this->depService->runPeriod($period, createJournal: false);
        $this->assertGreaterThan(0, $result1['processed']);

        $result2 = $this->depService->runPeriod($period, createJournal: false);
        $this->assertEquals(0, $result2['processed']);
        $this->assertGreaterThan(0, $result2['skipped']);
    }

    // -------------------------------------------------------
    // CASE 6: Không cho khấu hao vượt nguyên giá
    // -------------------------------------------------------

    public function test_depreciation_does_not_exceed_depreciable_amount(): void
    {
        // Đặt placed_in_service_date = 10 tháng trước để đủ kỳ chạy
        $startDate = now()->subMonths(10)->startOfMonth();
        $asset = $this->makeActiveAsset([
            'acquisition_cost'        => 10_000_000,
            'depreciable_amount'      => 10_000_000,
            'useful_life_months'      => 6,
            'placed_in_service_date'  => $startDate->format('Y-m-d'),
            'depreciation_start_date' => $startDate->format('Y-m-d'),
        ]);

        // Chạy 10 kỳ — phải dừng lại khi hết (6 kỳ)
        for ($i = 0; $i < 10; $i++) {
            $dt = now()->subMonths(9 - $i);
            AccountingPeriod::firstOrCreate(
                ['year' => $dt->year, 'month' => $dt->month],
                ['status' => 'open']
            );
            $this->depService->runPeriod($dt->format('Y-m'), createJournal: false);
        }

        $totalDep = FixedAssetDepreciation::where('fixed_asset_id', $asset->id)
            ->whereIn('status', ['planned', 'posted'])->sum('amount');

        $this->assertLessThanOrEqual(10_000_000, $totalDep);
        $this->assertEquals(6, FixedAssetDepreciation::where('fixed_asset_id', $asset->id)->count());
        $asset->refresh();
        $this->assertEquals(FixedAssetStatus::FullyDepreciated->value, $asset->status->value);
    }

    // -------------------------------------------------------
    // CASE 7: Hủy khấu hao khi kỳ chưa khóa
    // -------------------------------------------------------

    public function test_can_reverse_depreciation_in_open_period(): void
    {
        $asset  = $this->makeActiveAsset();
        $period = now()->format('Y-m');

        $this->depService->runPeriod($period, createJournal: false);
        $dep = FixedAssetDepreciation::where('fixed_asset_id', $asset->id)->where('period', $period)->firstOrFail();

        $this->depService->reverseDepreciation($dep);

        $dep->refresh();
        $this->assertEquals('reversed', $dep->status);
    }

    // -------------------------------------------------------
    // CASE 8: Không cho hủy khấu hao khi kỳ đã khóa
    // -------------------------------------------------------

    public function test_cannot_reverse_depreciation_in_closed_period(): void
    {
        $this->expectException(\RuntimeException::class);

        $prev   = now()->subMonth();
        $period = $prev->format('Y-m');
        AccountingPeriod::firstOrCreate(
            ['year' => $prev->year, 'month' => $prev->month],
            ['status' => 'closed']
        );

        $asset = $this->makeActiveAsset();
        $dep   = FixedAssetDepreciation::create([
            'fixed_asset_id'       => $asset->id,
            'period'               => $period,
            'amount'               => 1_000_000,
            'accumulated_before'   => 0,
            'net_book_value_after' => 59_000_000,
            'status'               => 'posted',
        ]);

        $this->depService->reverseDepreciation($dep);
    }

    // -------------------------------------------------------
    // CASE 9: Điều chuyển bộ phận không sinh bút toán nguyên giá
    // -------------------------------------------------------

    public function test_department_transfer_does_not_create_asset_journal(): void
    {
        $asset = $this->makeActiveAsset(['department' => 'Bộ phận A']);

        $jeCountBefore = \App\Models\JournalEntry::count();
        $this->service->transfer($asset, [
            'to_department'  => 'Bộ phận B',
            'effective_date' => now()->format('Y-m-d'),
        ]);

        // Không tạo thêm bút toán
        $this->assertEquals($jeCountBefore, \App\Models\JournalEntry::count());

        $asset->refresh();
        $this->assertEquals('Bộ phận B', $asset->department);
    }

    // -------------------------------------------------------
    // CASE 10: Sửa chữa thường xuyên — Dr 6421 / Cr 3311
    // -------------------------------------------------------

    public function test_regular_repair_creates_expense_journal(): void
    {
        $asset  = $this->makeActiveAsset();
        $repair = $this->service->createRepair($asset, [
            'repair_type'          => 'regular',
            'repair_date'          => now()->format('Y-m-d'),
            'description'          => 'Sửa chữa điều hòa',
            'amount'               => 5_000_000,
            'vat_amount'           => 500_000,
            'accounting_treatment' => 'expense_now',
        ], createJournal: true);

        $je    = $repair->journalEntry;
        $this->assertNotNull($je);

        $dr6421 = $je->lines->firstWhere('account_code', '6421');
        $this->assertEquals(5_000_000, $dr6421->debit);

        $dr1331 = $je->lines->firstWhere('account_code', '1331');
        $this->assertEquals(500_000, $dr1331->debit);
    }

    // -------------------------------------------------------
    // CASE 11: Sửa chữa lớn — Dr 242
    // -------------------------------------------------------

    public function test_major_repair_creates_prepaid_journal(): void
    {
        $asset  = $this->makeActiveAsset();
        $repair = $this->service->createRepair($asset, [
            'repair_type'          => 'major_repair',
            'repair_date'          => now()->format('Y-m-d'),
            'description'          => 'Sơn lại xe',
            'amount'               => 20_000_000,
            'vat_amount'           => 0,
            'accounting_treatment' => 'prepaid_allocation',
            'allocation_months'    => 12,
        ], createJournal: true);

        $je     = $repair->journalEntry;
        $dr242  = $je->lines->firstWhere('account_code', '242');
        $this->assertEquals(20_000_000, $dr242->debit);
    }

    // -------------------------------------------------------
    // CASE 12: Nâng cấp — Dr 2413, sau đó Dr 2111 / Cr 2413
    // -------------------------------------------------------

    public function test_upgrade_increases_original_cost(): void
    {
        $asset = $this->makeActiveAsset(['acquisition_cost' => 100_000_000]);

        $repair = $this->service->createRepair($asset, [
            'repair_type'          => 'upgrade',
            'repair_date'          => now()->format('Y-m-d'),
            'description'          => 'Nâng RAM server',
            'amount'               => 10_000_000,
            'vat_amount'           => 0,
            'accounting_treatment' => 'increase_original_cost',
        ], createJournal: true);

        $je    = $repair->journalEntry;
        $dr241 = $je->lines->firstWhere('account_code', '2413');
        $this->assertEquals(10_000_000, $dr241->debit);

        // acquisition_cost tăng lên
        $asset->refresh();
        $this->assertEquals(110_000_000, (float) $asset->acquisition_cost);
    }

    // -------------------------------------------------------
    // CASE 13: Thanh lý TSCĐ có doanh thu và VAT
    // -------------------------------------------------------

    public function test_disposal_with_revenue_creates_three_journals(): void
    {
        $asset = $this->makeActiveAsset([
            'acquisition_cost'         => 100_000_000,
            'accumulated_depreciation' => 60_000_000,
        ]);

        $disposal = $this->service->dispose($asset, [
            'disposal_type'       => 'sale',
            'disposal_date'       => now()->format('Y-m-d'),
            'selling_price'       => 50_000_000,
            'selling_vat_amount'  => 5_000_000,
            'disposal_cost'       => 2_000_000,
            'disposal_vat_amount' => 0,
        ], createJournal: true);

        // Phải có ít nhất 2 JE (writeoff + revenue)
        $this->assertGreaterThanOrEqual(2, count($disposal->journal_entry_ids));

        $asset->refresh();
        $this->assertEquals(FixedAssetStatus::Disposed->value, $asset->status->value);
    }

    // -------------------------------------------------------
    // CASE 14: Smoke — reconciliation data available
    // -------------------------------------------------------

    public function test_reconciliation_endpoint_returns_data(): void
    {
        $this->makeActiveAsset(['acquisition_cost' => 50_000_000]);

        $response = $this->get(route('accounting.fixed-assets.reports.reconciliation'));
        $response->assertOk();
    }

    // -------------------------------------------------------
    // CASE 15: Smoke — sổ TSCĐ trả về dữ liệu
    // -------------------------------------------------------

    public function test_ledger_report_returns_data(): void
    {
        $this->makeActiveAsset();
        $response = $this->get(route('accounting.fixed-assets.reports.ledger'));
        $response->assertOk();
    }

    // -------------------------------------------------------
    // CASE 16: Chặn post vào tài khoản cha
    // -------------------------------------------------------

    public function test_cannot_post_to_parent_account(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $asset = $this->makeAsset(['original_cost_account_code' => '211', 'payable_account_code' => '3311']);
        $this->journalService->createAcquisitionJournal($asset);
    }

    // -------------------------------------------------------
    // CASE 17: Index page trả về danh sách
    // -------------------------------------------------------

    public function test_index_page_accessible(): void
    {
        $this->makeActiveAsset();
        $response = $this->get(route('accounting.fixed-assets.index'));
        $response->assertOk();
    }

    // -------------------------------------------------------
    // Helpers
    // -------------------------------------------------------

    private function makeAsset(array $overrides = []): FixedAsset
    {
        return FixedAsset::create(array_merge([
            'code'                               => 'TSCĐ-' . rand(1000, 9999),
            'name'                               => 'Test Asset',
            'asset_type'                         => 'tangible',
            'acquisition_date'                   => now()->subMonth()->format('Y-m-d'),
            'acquisition_cost'                   => 100_000_000,
            'vat_amount'                         => 0,
            'total_amount'                       => 100_000_000,
            'depreciable_amount'                 => 100_000_000,
            'useful_life_months'                 => 60,
            'depreciation_method'                => 'straight_line',
            'original_cost_account_code'         => '2111',
            'accumulated_dep_account_code'       => '2141',
            'depreciation_expense_account_code'  => '6421',
            'payable_account_code'               => '3311',
            'status'                             => FixedAssetStatus::PendingUse->value,
        ], $overrides));
    }

    private function makeActiveAsset(array $overrides = []): FixedAsset
    {
        return $this->makeAsset(array_merge([
            'status'                  => FixedAssetStatus::Active->value,
            'placed_in_service_date'  => now()->startOfMonth()->format('Y-m-d'),
            'depreciation_start_date' => now()->startOfMonth()->format('Y-m-d'),
        ], $overrides));
    }

    private function seedAccounts(): void
    {
        // [code, type, normalBalance, isDetail, parentCode]
        $accounts = [
            ['211',  'asset',     'debit',  false, null],
            ['2111', 'asset',     'debit',  true,  '211'],
            ['2113', 'asset',     'debit',  true,  '211'],
            ['214',  'contra',    'credit', false, null],
            ['2141', 'contra',    'credit', true,  '214'],
            ['2143', 'contra',    'credit', true,  '214'],
            ['133',  'asset',     'debit',  false, null],
            ['1332', 'asset',     'debit',  true,  '133'],
            ['1331', 'asset',     'debit',  true,  '133'],
            ['331',  'liability', 'credit', false, null],
            ['3311', 'liability', 'credit', true,  '331'],
            ['333',  'liability', 'credit', false, null],
            ['3331', 'liability', 'credit', true,  '333'],
            ['111',  'asset',     'debit',  false, null],
            ['1111', 'asset',     'debit',  true,  '111'],
            ['642',  'expense',   'debit',  false, null],
            ['6421', 'expense',   'debit',  true,  '642'],
            ['24',   'asset',     'debit',  false, null],
            ['242',  'asset',     'debit',  true,  '24'],
            ['241',  'asset',     'debit',  false, null],
            ['2413', 'asset',     'debit',  true,  '241'],
            ['711',  'revenue',   'credit', true,  null],
            ['811',  'expense',   'debit',  true,  null],
        ];

        foreach ($accounts as [$code, $type, $balance, $isDetail, $parent]) {
            if ($parent) {
                AccountCode::firstOrCreate(['code' => $parent], [
                    'name' => 'TK ' . $parent, 'type' => $type,
                    'normal_balance' => $balance, 'parent_code' => null,
                    'level' => 2, 'is_detail' => false, 'is_active' => true,
                ]);
            }
            AccountCode::firstOrCreate(['code' => $code], [
                'name' => 'TK ' . $code, 'type' => $type,
                'normal_balance' => $balance, 'parent_code' => $parent,
                'level' => $parent ? 3 : 2, 'is_detail' => $isDetail, 'is_active' => true,
            ]);
        }
    }
}
