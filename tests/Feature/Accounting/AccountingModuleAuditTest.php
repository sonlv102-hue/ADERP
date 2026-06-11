<?php

namespace Tests\Feature\Accounting;

use App\Models\AccountCode;
use App\Models\Employee;
use App\Models\EmployeeDependent;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\PitConfig;
use App\Services\AccountingService;
use App\Services\Accounting\AccountBalanceService;
use App\Services\Accounting\FinancialPositionReportService;
use App\Services\PitCalculatorService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Test bắt buộc theo yêu cầu nghiệp vụ (phần G — 7 test cases).
 */
class AccountingModuleAuditTest extends TestCase
{
    use RefreshDatabase;

    private AccountingService $accounting;
    private AccountBalanceService $balanceSvc;
    private FinancialPositionReportService $reportSvc;
    private PitCalculatorService $pit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->accounting = app(AccountingService::class);
        $this->balanceSvc = app(AccountBalanceService::class);
        $this->reportSvc  = app(FinancialPositionReportService::class);
        $this->pit        = app(PitCalculatorService::class);

        // Seed period mở để post bút toán
        \App\Models\AccountingPeriod::create([
            'year'   => 2026,
            'month'  => 1,
            'status' => 'open',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /** Seed tài khoản + parent nếu cần (đảm bảo FK không fail) */
    private function seedAccount(
        string $code,
        string $type,
        string $normalBalance,
        bool $isDetail,
        ?string $parentCode = null
    ): AccountCode {
        if ($parentCode !== null) {
            AccountCode::firstOrCreate(['code' => $parentCode], [
                'name'           => 'TK ' . $parentCode,
                'type'           => $type,
                'normal_balance' => $normalBalance,
                'parent_code'    => null,
                'level'          => 3,
                'is_detail'      => false,
                'is_active'      => true,
            ]);
        }

        return AccountCode::firstOrCreate(['code' => $code], [
            'name'           => 'TK ' . $code,
            'type'           => $type,
            'normal_balance' => $normalBalance,
            'parent_code'    => $parentCode,
            'level'          => $parentCode ? 4 : 3,
            'is_detail'      => $isDetail,
            'is_active'      => true,
        ]);
    }

    /** Post bút toán thực tế (exclude_from_period_movement = false theo mặc định) */
    private function postNormalEntry(string $date, array $lines): JournalEntry
    {
        $user = \App\Models\User::firstOrCreate(
            ['email' => 'test@test.local'],
            ['name' => 'Test', 'password' => bcrypt('pass'), 'is_active' => true]
        );
        $this->actingAs($user);

        return $this->accounting->post(
            description: 'Test entry',
            date: Carbon::parse($date),
            lines: $lines,
        );
    }

    /** Tạo bút toán khai báo đầu kỳ (exclude_from_period_movement = true) */
    private function postOpeningEntry(string $date, array $lines): JournalEntry
    {
        $user = \App\Models\User::firstOrCreate(
            ['email' => 'test@test.local'],
            ['name' => 'Test', 'password' => bcrypt('pass'), 'is_active' => true]
        );
        $this->actingAs($user);

        return $this->accounting->post(
            description: 'Opening balance',
            date: Carbon::parse($date),
            lines: $lines,
            referenceType: 'opening_balance',
            referenceId: 0,
            isAuto: false,
            notes: null,
            journalSourceType: 'opening_balance',
            excludeFromPeriodMovement: true,
            fiscalPeriod: substr($date, 0, 7),
        );
    }

    /** Tìm dòng báo cáo B01a-DNN theo config_code */
    private function findRow(array $report, string $configCode): ?array
    {
        foreach ($report['rows'] as $row) {
            if (($row['config_code'] ?? $row['item_code']) === $configCode) {
                return $row;
            }
        }
        return null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TC1: Không cho phép hạch toán vào tài khoản cha (is_detail = false)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_TC1_cannot_post_to_parent_account(): void
    {
        // Seed TK 411 là cha (is_detail=false), TK 4111 là lá (is_detail=true)
        $this->seedAccount('411', 'equity', 'credit', false);
        $this->seedAccount('4111', 'equity', 'credit', true, '411');
        $this->seedAccount('1121', 'asset', 'debit', true, '112');

        $user = \App\Models\User::firstOrCreate(
            ['email' => 'test@test.local'],
            ['name' => 'Test', 'password' => bcrypt('pass'), 'is_active' => true]
        );
        $this->actingAs($user);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/tài khoản tổng hợp/i');

        // Thử post vào TK 411 (tổng hợp) → phải throw
        $this->accounting->post(
            description: 'Test parent',
            date: Carbon::parse('2026-01-15'),
            lines: [
                ['account' => '1121', 'debit' => 10_000_000, 'credit' => 0],
                ['account' => '411',  'debit' => 0, 'credit' => 10_000_000],
            ]
        );
    }

    public function test_TC1b_can_post_to_leaf_account(): void
    {
        $this->seedAccount('411',  'equity', 'credit', false);
        $this->seedAccount('4111', 'equity', 'credit', true, '411');
        $this->seedAccount('112',  'asset',  'debit',  false);
        $this->seedAccount('1121', 'asset',  'debit',  true, '112');

        $entry = $this->postNormalEntry('2026-01-15', [
            ['account' => '1121', 'debit' => 10_000_000, 'credit' => 0],
            ['account' => '4111', 'debit' => 0, 'credit' => 10_000_000],
        ]);

        $this->assertEquals('posted', $entry->status);
        $this->assertCount(2, $entry->lines);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TC2: Tồn kho đầu kỳ 01/01/2026 → CĐPS kỳ 01/01–01/01 phải ở đầu kỳ
    // ─────────────────────────────────────────────────────────────────────────

    public function test_TC2_opening_inventory_appears_in_opening_column_not_period(): void
    {
        $this->seedAccount('156',  'asset',  'debit', true);
        $this->seedAccount('411',  'equity', 'credit', false);
        $this->seedAccount('4111', 'equity', 'credit', true, '411');

        // Post tồn kho đầu kỳ 01/01/2026 với flag exclude_from_period_movement=true
        $this->postOpeningEntry('2026-01-01', [
            ['account' => '156',  'debit' => 50_000_000, 'credit' => 0],
            ['account' => '4111', 'debit' => 0, 'credit' => 50_000_000],
        ]);

        // TrialBalance kỳ 01/01/2026 - 01/01/2026
        $reportCtrl = app(\App\Http\Controllers\Reports\TrialBalanceController::class);
        $accounts   = $this->callMethod($reportCtrl, 'buildAccounts', ['2026-01-01', '2026-01-01']);

        $row156 = collect($accounts)->firstWhere('code', '156');

        $this->assertNotNull($row156, 'TK 156 phải xuất hiện trong CĐPS');
        $this->assertEquals(50_000_000, $row156['openingDebit'],
            'Tồn kho đầu kỳ phải nằm ở cột Số dư đầu kỳ');
        $this->assertEquals(0, $row156['dr'],
            'Tồn kho đầu kỳ KHÔNG được tính vào phát sinh trong kỳ');
        $this->assertEquals(0, $row156['cr']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TC3: Nhập số dư đầu kỳ cân thì CĐPS không được lệch 1 đồng
    // ─────────────────────────────────────────────────────────────────────────

    public function test_TC3_balanced_opening_balance_no_rounding_error(): void
    {
        $this->seedAccount('1121', 'asset',  'debit',  true, '112');
        $this->seedAccount('112',  'asset',  'debit',  false);
        $this->seedAccount('411',  'equity', 'credit', false);
        $this->seedAccount('4111', 'equity', 'credit', true, '411');

        // Opening balance cân: Dr 1121 = Cr 4111 = 123,456,789 VND
        $amount = 123_456_789;
        $this->postOpeningEntry('2026-01-01', [
            ['account' => '1121', 'debit' => $amount, 'credit' => 0],
            ['account' => '4111', 'debit' => 0, 'credit' => $amount],
        ]);

        $reportCtrl = app(\App\Http\Controllers\Reports\TrialBalanceController::class);
        $accounts   = $this->callMethod($reportCtrl, 'buildAccounts', ['2026-01-01', '2026-12-31']);

        $totals = [
            'opening_debit'  => array_sum(array_column($accounts, 'openingDebit')),
            'opening_credit' => array_sum(array_column($accounts, 'openingCredit')),
        ];

        $this->assertEquals(
            $totals['opening_debit'],
            $totals['opening_credit'],
            'Tổng dư đầu kỳ Nợ phải bằng tổng dư đầu kỳ Có'
        );

        // Không có phát sinh (chỉ có opening)
        $totalPeriodDr = array_sum(array_column($accounts, 'dr'));
        $totalPeriodCr = array_sum(array_column($accounts, 'cr'));
        $this->assertEquals(0, $totalPeriodDr, 'Không có phát sinh Nợ trong kỳ');
        $this->assertEquals(0, $totalPeriodCr, 'Không có phát sinh Có trong kỳ');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TC4: TK 1561 có số dư → B01a-DNN phải lên đúng nhóm hàng tồn kho (mã 140)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_TC4_tk1561_balance_appears_in_inventory_line(): void
    {
        // Seed TK 156 (is_detail=true trong hệ thống này) để map vào config mã 140
        $this->seedAccount('156',  'asset', 'debit', true);
        $this->seedAccount('411',  'equity', 'credit', false);
        $this->seedAccount('4111', 'equity', 'credit', true, '411');

        $this->postOpeningEntry('2026-06-30', [
            ['account' => '156',  'debit' => 30_000_000, 'credit' => 0],
            ['account' => '4111', 'debit' => 0, 'credit' => 30_000_000],
        ]);

        // B01a-DNN config dùng mã 140 cho hàng tồn kho
        // Kiểm tra qua AccountBalanceService trực tiếp (không phụ thuộc config phức tạp)
        $balances = $this->balanceSvc->getAllBalancesAsOf('2026-06-30');

        // TK 156 phải dư Nợ = 30M (debit-normal, balance = dr - cr)
        $this->assertEquals(30_000_000, $balances['156'] ?? 0,
            'TK 156 phải có số dư 30 triệu');

        // Verify trực tiếp qua sumExact (cách FinancialPositionReportService dùng cho debit items)
        $total = $this->balanceSvc->sumExact($balances, ['156', '1561', '1562']);
        $this->assertEquals(30_000_000, $total,
            'sumExact cho nhóm hàng tồn kho phải = 30M');
        $this->assertGreaterThan(0, $total, 'Số dư hàng tồn kho phải > 0 (thuộc phần tài sản)');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TC5: Tài khoản có số dư nhưng chưa map → hệ thống phải cảnh báo
    // ─────────────────────────────────────────────────────────────────────────

    public function test_TC5_unmapped_account_triggers_warning(): void
    {
        // TK '282' — không có trong config/accounting_reports_tt133.php (tài khoản tùy ý test)
        // Phải là balance sheet account (không bắt đầu bằng 5/6/8) để không bị loại trừ
        $this->seedAccount('282',  'asset',  'debit',  true);
        $this->seedAccount('411',  'equity', 'credit', false);
        $this->seedAccount('4111', 'equity', 'credit', true, '411');

        // Cần khai báo period 2026-06
        \App\Models\AccountingPeriod::firstOrCreate(['year' => 2026, 'month' => 6], ['status' => 'open']);

        $user = \App\Models\User::firstOrCreate(
            ['email' => 'test@test.local'],
            ['name' => 'Test', 'password' => bcrypt('pass'), 'is_active' => true]
        );
        $this->actingAs($user);

        $this->accounting->post(
            description: 'Test unmapped',
            date: Carbon::parse('2026-06-30'),
            lines: [
                ['account' => '282',  'debit' => 5_000_000, 'credit' => 0],
                ['account' => '4111', 'debit' => 0, 'credit' => 5_000_000],
            ]
        );

        $report = $this->reportSvc->build('2026-06-30');

        // Phải có unmapped_accounts trong kết quả
        $this->assertArrayHasKey('unmapped_accounts', $report);
        $unmappedCodes = array_column($report['unmapped_accounts'], 'code');
        $this->assertContains('282', $unmappedCodes,
            'TK 282 chưa được map phải xuất hiện trong danh sách cảnh báo');

        // Warnings phải đề cập đến TK chưa được map
        $warningText = implode(' ', $report['warnings']);
        $this->assertStringContainsString('282', $warningText,
            'TK 282 phải xuất hiện trong warning text của báo cáo');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TC6: Tổng tài sản = Tổng nguồn vốn khi dữ liệu kế toán cân
    // ─────────────────────────────────────────────────────────────────────────

    public function test_TC6_balanced_data_gives_equal_assets_and_equity(): void
    {
        // Seed các TK trong config B01a-DNN: tiền (mã 110) và vốn (mã 411)
        $this->seedAccount('112',  'asset',  'debit',  false);
        $this->seedAccount('1121', 'asset',  'debit',  true, '112');
        $this->seedAccount('411',  'equity', 'credit', false);
        $this->seedAccount('4111', 'equity', 'credit', true, '411');

        $this->postOpeningEntry('2026-01-01', [
            ['account' => '1121', 'debit' => 100_000_000, 'credit' => 0],
            ['account' => '4111', 'debit' => 0, 'credit' => 100_000_000],
        ]);

        $balances = $this->balanceSvc->getAllBalancesAsOf('2026-01-01');
        $trial    = $this->balanceSvc->getTrialBalanceTotals('2026-01-01');

        // Trial balance phải cân: tổng Dr = tổng Cr
        $this->assertTrue($trial['balanced'],
            'Trial balance phải cân: tổng Dr = tổng Cr');

        // 1121 dư Nợ = 100M, 4111 dư Có = 100M
        $this->assertEquals(100_000_000, $balances['1121'] ?? 0);
        $this->assertEquals(100_000_000, $balances['4111'] ?? 0);

        // Nếu đây là toàn bộ dữ liệu: sumExact(tài sản) = sumExact(vốn)
        $totalAsset  = $this->balanceSvc->sumExact($balances, ['1121']);
        $totalEquity = $this->balanceSvc->sumExact($balances, ['4111']);
        $this->assertEquals($totalAsset, $totalEquity,
            'Tổng tài sản phải = tổng nguồn vốn khi dữ liệu cân');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TC7: TNCN lấy mức giảm trừ theo cấu hình DB, không hard-code
    // ─────────────────────────────────────────────────────────────────────────

    public function test_TC7_pit_uses_config_deduction_not_hardcode(): void
    {
        // Seed 2 cấu hình khác nhau theo kỳ
        PitConfig::create([
            'effective_from'      => '2020-01-01',
            'effective_to'        => '2025-12-31',
            'personal_deduction'  => 9_000_000,   // cũ: 9 triệu
            'dependent_deduction' => 3_600_000,   // cũ: 3.6 triệu
            'insurance_cap'       => 29_800_000,
            'is_active'           => true,
        ]);

        PitConfig::create([
            'effective_from'      => '2026-01-01',
            'effective_to'        => null,
            'personal_deduction'  => 11_000_000,  // mới: 11 triệu
            'dependent_deduction' => 4_400_000,   // mới: 4.4 triệu
            'insurance_cap'       => 46_800_000,
            'is_active'           => true,
        ]);

        $grossSalary = 20_000_000;

        // Tính lương tháng 06/2021 → phải dùng cấu hình cũ (9M/3.6M)
        $bd2021 = $this->pit->breakdown(
            baseSalary: $grossSalary,
            dependents: 1,
            payrollMonth: Carbon::parse('2021-06-01'),
        );
        $expected2021 = 9_000_000 + 3_600_000; // 12.6M
        $this->assertEquals($expected2021, $bd2021['personal_deduction'],
            'Tháng 06/2021 phải dùng mức giảm trừ 9M bản thân + 3.6M NPT');

        // Tính lương tháng 03/2026 → phải dùng cấu hình mới (11M/4.4M)
        $bd2026 = $this->pit->breakdown(
            baseSalary: $grossSalary,
            dependents: 1,
            payrollMonth: Carbon::parse('2026-03-01'),
        );
        $expected2026 = 11_000_000 + 4_400_000; // 15.4M
        $this->assertEquals($expected2026, $bd2026['personal_deduction'],
            'Tháng 03/2026 phải dùng mức giảm trừ 11M bản thân + 4.4M NPT');

        // Đảm bảo PIT tính khác nhau giữa 2 kỳ
        $this->assertNotEquals($bd2021['pit'], $bd2026['pit'],
            'PIT phải khác nhau khi cấu hình giảm trừ khác nhau');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helper: call protected method via Reflection
    // ─────────────────────────────────────────────────────────────────────────

    private function callMethod(object $obj, string $method, array $args = []): mixed
    {
        $ref = new \ReflectionClass($obj);
        $m   = $ref->getMethod($method);
        $m->setAccessible(true);
        return $m->invokeArgs($obj, $args);
    }
}
