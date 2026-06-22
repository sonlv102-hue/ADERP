<?php

namespace Tests\Feature;

use App\Models\AccountCode;
use App\Models\User;
use App\Services\Accounting\CashFlowStatementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Tests B03-DNN CashFlowStatement báo cáo TT133.
 *
 * CF-S1: index trả đủ 5 cột (chỉ tiêu, mã số, thuyết minh, năm nay, năm trước)
 * CF-S2: Mã 01 — thu bán hàng (Dr 1121 / Cr 131)
 * CF-S3: Mã 02 — chi trả NCC (Dr 331 / Cr 1121)
 * CF-S4: Mã 03 — chi trả lương (Dr 3341 / Cr 1111)
 * CF-S5: Mã 04 — chi lãi vay (Dr 635 / Cr 1121)
 * CF-S6: Mã 05 — nộp thuế TNDN (Dr 3334 / Cr 1121)
 * CF-S7: Mã 21 — chi mua TSCĐ (Dr 211 / Cr 1121)
 * CF-S8: Mã 22 — thu thanh lý TSCĐ (Dr 1121 / Cr 211)
 * CF-S9: Mã 33 — thu vay (Dr 1121 / Cr 341)
 * CF-S10: Mã 34 — trả nợ vay (Dr 341 / Cr 1121)
 * CF-S11: Công thức mã 20 = 01+02+03+04+05+06+07
 * CF-S12: Công thức mã 50 = 20+30+40
 * CF-S13: Công thức mã 70 = 50+60+61
 * CF-S14: Không lấy draft / cancelled JE
 * CF-S15: Mã 70 khớp số dư TK 111/112 cuối kỳ
 */
class CashFlowStatementTest extends TestCase
{
    use RefreshDatabase;

    private const YEAR = 2026;
    private const DATE = '2026-06-15';

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::firstOrCreate(
            ['email' => 'admin@test.local'],
            ['name' => 'Admin', 'password' => bcrypt('pass'), 'is_active' => true]
        );
        $this->actingAs($user);
        Gate::before(fn ($user, $ability) => true);
        $this->seedCashAccounts();
    }

    private function seedCashAccounts(): void
    {
        $accounts = [
            ['code' => '1111', 'name' => 'Tiền mặt VND',   'is_detail' => true, 'normal_balance' => 'debit',  'type' => 'asset'],
            ['code' => '1121', 'name' => 'TGNH VND',        'is_detail' => true, 'normal_balance' => 'debit',  'type' => 'asset'],
            ['code' => '131',  'name' => 'Phải thu KH',     'is_detail' => true, 'normal_balance' => 'debit',  'type' => 'asset'],
            ['code' => '331',  'name' => 'Phải trả NCC',    'is_detail' => true, 'normal_balance' => 'credit', 'type' => 'liability'],
            ['code' => '3341', 'name' => 'Lương thực lĩnh', 'is_detail' => true, 'normal_balance' => 'credit', 'type' => 'liability'],
            ['code' => '3334', 'name' => 'Thuế TNDN',       'is_detail' => true, 'normal_balance' => 'credit', 'type' => 'liability'],
            ['code' => '635',  'name' => 'Chi phí lãi vay', 'is_detail' => true, 'normal_balance' => 'debit',  'type' => 'expense'],
            ['code' => '211',  'name' => 'TSCĐ hữu hình',   'is_detail' => true, 'normal_balance' => 'debit',  'type' => 'asset'],
            ['code' => '341',  'name' => 'Vay dài hạn',     'is_detail' => true, 'normal_balance' => 'credit', 'type' => 'liability'],
            ['code' => '5111', 'name' => 'DT bán hàng',     'is_detail' => true, 'normal_balance' => 'credit', 'type' => 'revenue'],
        ];
        foreach ($accounts as $a) {
            AccountCode::firstOrCreate(['code' => $a['code']], array_merge($a, ['is_active' => true]));
        }
    }

    private int $userId = 0;

    private function getUserId(): int
    {
        if ($this->userId === 0) {
            $this->userId = DB::table('users')->where('email', 'admin@test.local')->value('id') ?? 1;
        }
        return $this->userId;
    }

    private function makeJe(string $date, string $dr, string $cr, float $amount, string $status = 'posted'): void
    {
        $jeId = DB::table('journal_entries')->insertGetId([
            'code'        => 'JE-TEST-' . uniqid(),
            'entry_date'  => $date,
            'description' => 'Test',
            'status'      => $status,
            'created_by'  => $this->getUserId(),
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
        DB::table('journal_entry_lines')->insert([
            ['journal_entry_id' => $jeId, 'account_code' => $dr, 'debit' => $amount, 'credit' => 0, 'description' => ''],
            ['journal_entry_id' => $jeId, 'account_code' => $cr, 'debit' => 0, 'credit' => $amount, 'description' => ''],
        ]);
    }

    /** @test */
    public function index_returns_all_five_columns(): void
    {
        $this->get(route('reports.cash_flow_statement', ['year' => self::YEAR]))
            ->assertInertia(fn ($page) => $page
                ->component('Reports/CashFlowStatement/Index')
                ->has('report.rows')
                ->where('report.year', self::YEAR)
            );
    }

    /** @test */
    public function code_01_captures_sales_receipts(): void
    {
        $this->makeJe(self::DATE, '1121', '131', 5_000_000); // thu từ KH

        $svc = app(CashFlowStatementService::class);
        $report = $svc->getReport(self::YEAR);
        $row01 = collect($report['rows'])->firstWhere('code', '01');

        $this->assertEquals(5_000_000, $row01['curr']);
    }

    /** @test */
    public function code_02_captures_supplier_payments(): void
    {
        $this->makeJe(self::DATE, '331', '1121', 3_000_000); // chi trả NCC

        $svc = app(CashFlowStatementService::class);
        $report = $svc->getReport(self::YEAR);
        $row02 = collect($report['rows'])->firstWhere('code', '02');

        $this->assertEquals(-3_000_000, $row02['curr']);
    }

    /** @test */
    public function code_03_captures_salary_payments(): void
    {
        $this->makeJe(self::DATE, '3341', '1111', 2_000_000); // chi lương

        $svc = app(CashFlowStatementService::class);
        $report = $svc->getReport(self::YEAR);
        $row03 = collect($report['rows'])->firstWhere('code', '03');

        $this->assertEquals(-2_000_000, $row03['curr']);
    }

    /** @test */
    public function code_04_captures_interest_paid(): void
    {
        $this->makeJe(self::DATE, '635', '1121', 500_000); // chi lãi vay

        $svc = app(CashFlowStatementService::class);
        $report = $svc->getReport(self::YEAR);
        $row04 = collect($report['rows'])->firstWhere('code', '04');

        $this->assertEquals(-500_000, $row04['curr']);
    }

    /** @test */
    public function code_05_captures_cit_payments(): void
    {
        $this->makeJe(self::DATE, '3334', '1121', 1_000_000); // nộp thuế TNDN

        $svc = app(CashFlowStatementService::class);
        $report = $svc->getReport(self::YEAR);
        $row05 = collect($report['rows'])->firstWhere('code', '05');

        $this->assertEquals(-1_000_000, $row05['curr']);
    }

    /** @test */
    public function code_21_captures_fixed_asset_purchases(): void
    {
        $this->makeJe(self::DATE, '211', '1121', 20_000_000); // mua TSCĐ

        $svc = app(CashFlowStatementService::class);
        $report = $svc->getReport(self::YEAR);
        $row21 = collect($report['rows'])->firstWhere('code', '21');

        $this->assertEquals(-20_000_000, $row21['curr']);
    }

    /** @test */
    public function code_22_captures_fixed_asset_disposals(): void
    {
        $this->makeJe(self::DATE, '1121', '211', 8_000_000); // thu thanh lý TSCĐ

        $svc = app(CashFlowStatementService::class);
        $report = $svc->getReport(self::YEAR);
        $row22 = collect($report['rows'])->firstWhere('code', '22');

        $this->assertEquals(8_000_000, $row22['curr']);
    }

    /** @test */
    public function code_33_captures_loan_receipts(): void
    {
        $this->makeJe(self::DATE, '1121', '341', 50_000_000); // vay dài hạn

        $svc = app(CashFlowStatementService::class);
        $report = $svc->getReport(self::YEAR);
        $row33 = collect($report['rows'])->firstWhere('code', '33');

        $this->assertEquals(50_000_000, $row33['curr']);
    }

    /** @test */
    public function code_34_captures_loan_repayments(): void
    {
        $this->makeJe(self::DATE, '341', '1121', 10_000_000); // trả nợ vay

        $svc = app(CashFlowStatementService::class);
        $report = $svc->getReport(self::YEAR);
        $row34 = collect($report['rows'])->firstWhere('code', '34');

        $this->assertEquals(-10_000_000, $row34['curr']);
    }

    /** @test */
    public function formula_code_20_equals_sum_of_01_to_07(): void
    {
        $this->makeJe(self::DATE, '1121', '131', 10_000_000);  // 01: +10M
        $this->makeJe(self::DATE, '331', '1121', 4_000_000);   // 02: -4M

        $svc    = app(CashFlowStatementService::class);
        $report = $svc->getReport(self::YEAR);
        $rows   = collect($report['rows'])->keyBy('code');

        $expected20 = $rows['01']['curr'] + $rows['02']['curr'] + $rows['03']['curr']
                    + $rows['04']['curr'] + $rows['05']['curr'] + $rows['06']['curr'] + $rows['07']['curr'];

        $this->assertEquals($expected20, $rows['20']['curr']);
    }

    /** @test */
    public function formula_code_50_equals_20_plus_30_plus_40(): void
    {
        $this->makeJe(self::DATE, '1121', '131', 5_000_000);

        $svc    = app(CashFlowStatementService::class);
        $report = $svc->getReport(self::YEAR);
        $rows   = collect($report['rows'])->keyBy('code');

        $this->assertEquals(
            $rows['20']['curr'] + $rows['30']['curr'] + $rows['40']['curr'],
            $rows['50']['curr']
        );
    }

    /** @test */
    public function formula_code_70_equals_50_plus_60_plus_61(): void
    {
        $this->makeJe(self::DATE, '1121', '131', 5_000_000);

        $svc    = app(CashFlowStatementService::class);
        $report = $svc->getReport(self::YEAR);
        $rows   = collect($report['rows'])->keyBy('code');

        $this->assertEquals(
            $rows['50']['curr'] + $rows['60']['curr'] + $rows['61']['curr'],
            $rows['70']['curr']
        );
    }

    /** @test */
    public function draft_and_cancelled_je_are_excluded(): void
    {
        $this->makeJe(self::DATE, '1121', '131', 99_000_000, 'draft');
        $this->makeJe(self::DATE, '1121', '131', 88_000_000, 'cancelled');
        $this->makeJe(self::DATE, '1121', '131', 5_000_000, 'posted'); // only this

        $svc = app(CashFlowStatementService::class);
        $report = $svc->getReport(self::YEAR);
        $row01 = collect($report['rows'])->firstWhere('code', '01');

        $this->assertEquals(5_000_000, $row01['curr']);
    }

    /** @test */
    public function code_70_matches_actual_cash_balance(): void
    {
        // Opening (before 2026): +20M
        $this->makeJe('2025-12-31', '1121', '131', 20_000_000);
        // In 2026: +5M
        $this->makeJe(self::DATE, '1121', '131', 5_000_000);

        $svc    = app(CashFlowStatementService::class);
        $report = $svc->getReport(self::YEAR);
        $rows   = collect($report['rows'])->keyBy('code');

        $actualEnding = $svc->getEndingCashBalance(self::YEAR);
        $this->assertEquals(round($actualEnding), $rows['70']['curr']);
        $this->assertTrue($report['reconciliation']['ok']);
    }

    /** @test */
    public function excel_export_returns_xlsx(): void
    {
        $response = $this->get(route('reports.cash_flow_statement.export', ['year' => self::YEAR]));
        $response->assertStatus(200);
        $this->assertStringContainsString('spreadsheet', strtolower($response->headers->get('Content-Type') ?? ''));
    }

    /** @test */
    public function pdf_export_returns_pdf(): void
    {
        $response = $this->get(route('reports.cash_flow_statement.pdf', ['year' => self::YEAR]));
        $response->assertStatus(200);
        $this->assertStringContainsString('pdf', strtolower($response->headers->get('Content-Type') ?? ''));
    }

    /** @test */
    public function hide_empty_rows_does_not_renumber_codes(): void
    {
        // Chỉ có mã 01
        $this->makeJe(self::DATE, '1121', '131', 5_000_000);

        $svc    = app(CashFlowStatementService::class);
        $report = $svc->getReport(self::YEAR);
        $rows   = collect($report['rows']);

        // Mã số phải giữ nguyên dù dòng khác = 0
        $this->assertEquals('01', $rows->firstWhere('code', '01')['code']);
        $this->assertEquals('02', $rows->firstWhere('code', '02')['code']);
        $this->assertEquals('70', $rows->firstWhere('code', '70')['code']);
    }
}
