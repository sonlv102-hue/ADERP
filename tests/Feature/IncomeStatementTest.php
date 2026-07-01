<?php

namespace Tests\Feature;

use App\Models\AccountCode;
use App\Models\User;
use App\Services\Accounting\IncomeStatementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Tests B02-DNN IncomeStatement báo cáo TT133.
 *
 * IS-S1:  Index trả đúng cấu trúc 5 cột B02-DNN
 * IS-S2:  Mã 01 từ TK 511 (Cr 511)
 * IS-S3:  Mã 11 từ TK 632 (Dr 632)
 * IS-S4:  Mã 21 từ TK 515 (Cr 515)
 * IS-S5:  Mã 22 từ TK 635 (Dr 635)
 * IS-S6:  Mã 24 từ TK 642 (Dr 642)
 * IS-S7:  Mã 31 từ TK 711 (Cr 711)
 * IS-S8:  Mã 32 từ TK 811 (Dr 811)
 * IS-S9:  Mã 51 từ TK 821 (Dr 821)
 * IS-S10: Công thức 10 = 01 - 02
 * IS-S11: Công thức 20 = 10 - 11
 * IS-S12: Công thức 30 = 20 + 21 - 22 - 24
 * IS-S13: Công thức 40 = 31 - 32
 * IS-S14: Công thức 50 = 30 + 40
 * IS-S15: Công thức 60 = 50 - 51
 * IS-S16: Không lấy draft / cancelled JE
 * IS-S17: Không double-count kết chuyển 911
 * IS-S18: Excel export HTTP 200
 * IS-S19: PDF export HTTP 200
 */
class IncomeStatementTest extends TestCase
{
    use RefreshDatabase;

    private const YEAR = 2026;
    private const DATE = '2026-06-15';

    private int $userId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::firstOrCreate(
            ['email' => 'admin@test.local'],
            ['name' => 'Admin', 'password' => bcrypt('pass'), 'is_active' => true]
        );
        $this->actingAs($user);
        Gate::before(fn ($u, $a) => true);
        $this->seedAccounts();
    }

    private function getUserId(): int
    {
        if ($this->userId === 0) {
            $this->userId = DB::table('users')->where('email', 'admin@test.local')->value('id') ?? 1;
        }
        return $this->userId;
    }

    private function seedAccounts(): void
    {
        $accounts = [
            ['code' => '511',  'name' => 'Doanh thu BH',       'normal_balance' => 'credit', 'type' => 'revenue',   'is_detail' => true],
            ['code' => '5111', 'name' => 'DT thương mại',      'normal_balance' => 'credit', 'type' => 'revenue',   'is_detail' => true],
            ['code' => '521',  'name' => 'Giảm trừ DT',        'normal_balance' => 'debit',  'type' => 'revenue',   'is_detail' => true],
            ['code' => '632',  'name' => 'Giá vốn HB',         'normal_balance' => 'debit',  'type' => 'expense',   'is_detail' => true],
            ['code' => '515',  'name' => 'DT tài chính',       'normal_balance' => 'credit', 'type' => 'revenue',   'is_detail' => true],
            ['code' => '635',  'name' => 'CP tài chính',       'normal_balance' => 'debit',  'type' => 'expense',   'is_detail' => true],
            ['code' => '642',  'name' => 'CP QLDN',            'normal_balance' => 'debit',  'type' => 'expense',   'is_detail' => true],
            ['code' => '711',  'name' => 'Thu nhập khác',      'normal_balance' => 'credit', 'type' => 'revenue',   'is_detail' => true],
            ['code' => '811',  'name' => 'CP khác',            'normal_balance' => 'debit',  'type' => 'expense',   'is_detail' => true],
            ['code' => '821',  'name' => 'Thuế TNDN',          'normal_balance' => 'debit',  'type' => 'expense',   'is_detail' => true],
            ['code' => '131',  'name' => 'Phải thu KH',        'normal_balance' => 'debit',  'type' => 'asset',     'is_detail' => true],
            ['code' => '1121', 'name' => 'TGNH VND',           'normal_balance' => 'debit',  'type' => 'asset',     'is_detail' => true],
            ['code' => '156',  'name' => 'Hàng hóa',           'normal_balance' => 'debit',  'type' => 'asset',     'is_detail' => true],
            ['code' => '341',  'name' => 'Vay dài hạn',        'normal_balance' => 'credit', 'type' => 'liability', 'is_detail' => true],
            ['code' => '3334', 'name' => 'Thuế TNDN phải nộp', 'normal_balance' => 'credit', 'type' => 'liability', 'is_detail' => true],
            ['code' => '911',  'name' => 'Xác định KQKD',      'normal_balance' => 'credit', 'type' => 'equity',    'is_detail' => true],
        ];
        foreach ($accounts as $a) {
            AccountCode::firstOrCreate(['code' => $a['code']], array_merge($a, ['is_active' => true]));
        }
    }

    private function makeJe(string $date, string $dr, string $cr, float $amount, string $status = 'posted'): void
    {
        $jeId = DB::table('journal_entries')->insertGetId([
            'code'        => 'JE-IS-' . uniqid(),
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

    // ─── IS-S1 ───────────────────────────────────────────────────────────────
    /** @test */
    public function index_returns_b02_dnn_structure(): void
    {
        $this->get(route('reports.income_statement', ['year' => self::YEAR]))
            ->assertInertia(fn ($p) => $p
                ->component('Reports/IncomeStatement/Index')
                ->has('report.rows')
                ->has('report.year')
                ->has('report.unit')
                ->has('company')
            );
    }

    // ─── IS-S2: Mã 01 ────────────────────────────────────────────────────────
    /** @test */
    public function code_01_captures_revenue_from_511(): void
    {
        $this->makeJe(self::DATE, '131', '511', 10_000_000);

        $svc  = app(IncomeStatementService::class);
        $rows = collect($svc->buildRows(self::YEAR));

        $this->assertEquals(10_000_000, $rows['01']);
    }

    // ─── IS-S3: Mã 11 ────────────────────────────────────────────────────────
    /** @test */
    public function code_11_captures_cogs_from_632(): void
    {
        $this->makeJe(self::DATE, '632', '156', 6_000_000);

        $svc  = app(IncomeStatementService::class);
        $rows = collect($svc->buildRows(self::YEAR));

        $this->assertEquals(6_000_000, $rows['11']);
    }

    // ─── IS-S4: Mã 21 ────────────────────────────────────────────────────────
    /** @test */
    public function code_21_captures_financial_income_from_515(): void
    {
        $this->makeJe(self::DATE, '1121', '515', 500_000);

        $svc  = app(IncomeStatementService::class);
        $rows = collect($svc->buildRows(self::YEAR));

        $this->assertEquals(500_000, $rows['21']);
    }

    // ─── IS-S5: Mã 22 ────────────────────────────────────────────────────────
    /** @test */
    public function code_22_captures_financial_expense_from_635(): void
    {
        $this->makeJe(self::DATE, '635', '1121', 300_000);

        $svc  = app(IncomeStatementService::class);
        $rows = collect($svc->buildRows(self::YEAR));

        $this->assertEquals(300_000, $rows['22']);
    }

    // ─── IS-S6: Mã 24 ────────────────────────────────────────────────────────
    /** @test */
    public function code_24_captures_admin_expense_from_642(): void
    {
        $this->makeJe(self::DATE, '642', '1121', 2_000_000);

        $svc  = app(IncomeStatementService::class);
        $rows = collect($svc->buildRows(self::YEAR));

        $this->assertEquals(2_000_000, $rows['24']);
    }

    // ─── IS-S7: Mã 31 ────────────────────────────────────────────────────────
    /** @test */
    public function code_31_captures_other_income_from_711(): void
    {
        $this->makeJe(self::DATE, '1121', '711', 1_000_000);

        $svc  = app(IncomeStatementService::class);
        $rows = collect($svc->buildRows(self::YEAR));

        $this->assertEquals(1_000_000, $rows['31']);
    }

    // ─── IS-S8: Mã 32 ────────────────────────────────────────────────────────
    /** @test */
    public function code_32_captures_other_expense_from_811(): void
    {
        $this->makeJe(self::DATE, '811', '1121', 400_000);

        $svc  = app(IncomeStatementService::class);
        $rows = collect($svc->buildRows(self::YEAR));

        $this->assertEquals(400_000, $rows['32']);
    }

    // ─── IS-S9: Mã 51 ────────────────────────────────────────────────────────
    /** @test */
    public function code_51_captures_cit_from_821(): void
    {
        $this->makeJe(self::DATE, '821', '3334', 1_500_000);

        $svc  = app(IncomeStatementService::class);
        $rows = collect($svc->buildRows(self::YEAR));

        $this->assertEquals(1_500_000, $rows['51']);
    }

    // ─── IS-S10: Formula 10 ──────────────────────────────────────────────────
    /** @test */
    public function formula_10_equals_01_minus_02(): void
    {
        $this->makeJe(self::DATE, '131', '511', 10_000_000);   // mã 01 = 10M
        $this->makeJe(self::DATE, '521', '131', 500_000);       // mã 02 = 500K

        $svc  = app(IncomeStatementService::class);
        $rows = $svc->buildRows(self::YEAR);

        $this->assertEquals($rows['01'] - $rows['02'], $rows['10']);
        $this->assertEquals(9_500_000, $rows['10']);
    }

    // ─── IS-S11: Formula 20 ──────────────────────────────────────────────────
    /** @test */
    public function formula_20_equals_10_minus_11(): void
    {
        $this->makeJe(self::DATE, '131', '511', 10_000_000);
        $this->makeJe(self::DATE, '632', '156', 6_000_000);

        $svc  = app(IncomeStatementService::class);
        $rows = $svc->buildRows(self::YEAR);

        $this->assertEquals($rows['10'] - $rows['11'], $rows['20']);
        $this->assertEquals(4_000_000, $rows['20']);
    }

    // ─── IS-S12: Formula 30 ──────────────────────────────────────────────────
    /** @test */
    public function formula_30_equals_20_plus_21_minus_22_minus_24(): void
    {
        $this->makeJe(self::DATE, '131', '511', 10_000_000);  // 01
        $this->makeJe(self::DATE, '632', '156', 4_000_000);   // 11
        $this->makeJe(self::DATE, '1121', '515', 200_000);    // 21
        $this->makeJe(self::DATE, '635', '1121', 100_000);    // 22
        $this->makeJe(self::DATE, '642', '1121', 800_000);    // 24

        $svc  = app(IncomeStatementService::class);
        $rows = $svc->buildRows(self::YEAR);

        $expected = $rows['20'] + $rows['21'] - $rows['22'] - $rows['24'];
        $this->assertEquals($expected, $rows['30']);
    }

    // ─── IS-S13: Formula 40 ──────────────────────────────────────────────────
    /** @test */
    public function formula_40_equals_31_minus_32(): void
    {
        $this->makeJe(self::DATE, '1121', '711', 1_000_000);   // 31
        $this->makeJe(self::DATE, '811', '1121', 300_000);     // 32

        $svc  = app(IncomeStatementService::class);
        $rows = $svc->buildRows(self::YEAR);

        $this->assertEquals($rows['31'] - $rows['32'], $rows['40']);
        $this->assertEquals(700_000, $rows['40']);
    }

    // ─── IS-S14: Formula 50 ──────────────────────────────────────────────────
    /** @test */
    public function formula_50_equals_30_plus_40(): void
    {
        $this->makeJe(self::DATE, '131', '511', 5_000_000);

        $svc  = app(IncomeStatementService::class);
        $rows = $svc->buildRows(self::YEAR);

        $this->assertEquals($rows['30'] + $rows['40'], $rows['50']);
    }

    // ─── IS-S15: Formula 60 ──────────────────────────────────────────────────
    /** @test */
    public function formula_60_equals_50_minus_51(): void
    {
        $this->makeJe(self::DATE, '131', '511', 5_000_000);
        $this->makeJe(self::DATE, '821', '3334', 500_000);

        $svc  = app(IncomeStatementService::class);
        $rows = $svc->buildRows(self::YEAR);

        $this->assertEquals($rows['50'] - $rows['51'], $rows['60']);
    }

    // ─── IS-S16: Exclude draft/cancelled ─────────────────────────────────────
    /** @test */
    public function draft_and_cancelled_je_are_excluded(): void
    {
        $this->makeJe(self::DATE, '131', '511', 99_000_000, 'draft');
        $this->makeJe(self::DATE, '131', '511', 88_000_000, 'cancelled');
        $this->makeJe(self::DATE, '131', '511', 5_000_000, 'posted');

        $svc  = app(IncomeStatementService::class);
        $rows = $svc->buildRows(self::YEAR);

        $this->assertEquals(5_000_000, $rows['01']);
    }

    // ─── IS-S17: No double-count from 911 kết chuyển ─────────────────────────
    /** @test */
    public function ketchuyen_911_does_not_double_count(): void
    {
        // Revenue JE: Dr 131 / Cr 511
        $this->makeJe(self::DATE, '131', '511', 10_000_000);
        // Kết chuyển: Dr 511 / Cr 911
        $this->makeJe('2026-12-31', '511', '911', 10_000_000);

        $svc  = app(IncomeStatementService::class);
        $rows = $svc->buildRows(self::YEAR);

        // Mã 01 phải = 10M (không bị cộng thêm từ kết chuyển)
        $this->assertEquals(10_000_000, $rows['01']);
    }

    // ─── IS-S18: Excel export ────────────────────────────────────────────────
    /** @test */
    public function excel_export_returns_xlsx(): void
    {
        $response = $this->get(route('reports.income_statement.export', ['year' => self::YEAR]));
        $response->assertStatus(200);
        $this->assertStringContainsString('spreadsheet', strtolower($response->headers->get('Content-Type') ?? ''));
    }

    // ─── IS-S19: PDF export ──────────────────────────────────────────────────
    /** @test */
    public function pdf_export_returns_pdf(): void
    {
        $response = $this->get(route('reports.income_statement.pdf', ['year' => self::YEAR]));
        $response->assertStatus(200);
        $this->assertStringContainsString('pdf', strtolower($response->headers->get('Content-Type') ?? ''));
    }

    // ─── IS-S20: period_type=month tính đúng khoảng ngày + label ─────────────
    /** @test */
    public function period_type_month_computes_correct_range_and_label(): void
    {
        $this->makeJe('2026-01-15', '131', '511', 3_000_000);
        $this->makeJe('2026-02-15', '131', '511', 9_000_000); // ngoài tháng 1, không được tính

        $this->get(route('reports.income_statement', ['period_type' => 'month', 'year' => 2026, 'month' => 1]))
            ->assertInertia(fn ($p) => $p
                ->where('report.period.type', 'month')
                ->where('report.period.date_from', '2026-01-01')
                ->where('report.period.date_to', '2026-01-31')
                ->where('report.period.label', 'Tháng 01/2026')
                ->where('report.rows.0.curr', 3_000_000)
            );
    }

    // ─── IS-S21: period_type=quarter tính đúng khoảng ngày ────────────────────
    /** @test */
    public function period_type_quarter_computes_correct_range(): void
    {
        $this->get(route('reports.income_statement', ['period_type' => 'quarter', 'year' => 2026, 'quarter' => 2]))
            ->assertInertia(fn ($p) => $p
                ->where('report.period.type', 'quarter')
                ->where('report.period.date_from', '2026-04-01')
                ->where('report.period.date_to', '2026-06-30')
                ->where('report.period.label', 'Quý II/2026')
            );
    }

    // ─── IS-S22: period_type=custom nhận date_from/date_to trực tiếp ──────────
    /** @test */
    public function period_type_custom_uses_given_date_range(): void
    {
        $this->get(route('reports.income_statement', [
            'period_type' => 'custom', 'date_from' => '2026-02-10', 'date_to' => '2026-03-05',
        ]))->assertInertia(fn ($p) => $p
            ->where('report.period.type', 'custom')
            ->where('report.period.date_from', '2026-02-10')
            ->where('report.period.date_to', '2026-03-05')
        );
    }

    // ─── IS-S23: compare_type=none không có comparison_period ─────────────────
    /** @test */
    public function compare_type_none_returns_null_comparison_period(): void
    {
        $this->get(route('reports.income_statement', ['year' => self::YEAR, 'compare_type' => 'none']))
            ->assertInertia(fn ($p) => $p->where('report.comparison_period', null));
    }

    // ─── IS-S24: Regression — getReport(year) khớp 100% getReportForRange tương đương ──
    /** @test */
    public function legacy_get_report_matches_explicit_range_for_full_year(): void
    {
        $this->makeJe(self::DATE, '131', '511', 7_000_000);
        $this->makeJe(self::DATE, '632', '156', 4_000_000);
        $this->makeJe(self::DATE, '642', '1121', 1_200_000);

        $svc    = app(IncomeStatementService::class);
        $legacy = $svc->getReport(self::YEAR);

        $from = \Carbon\Carbon::create(self::YEAR, 1, 1)->startOfDay();
        $to   = \Carbon\Carbon::create(self::YEAR, 12, 31)->endOfDay();
        $explicit = $svc->getReportForRange($from, $to, 'dong', [
            'type'      => 'year',
            'date_from' => $from->toDateString(),
            'date_to'   => $to->toDateString(),
            'label'     => 'Năm ' . self::YEAR,
        ], [
            'date_from' => \Carbon\Carbon::create(self::YEAR - 1, 1, 1)->toDateString(),
            'date_to'   => \Carbon\Carbon::create(self::YEAR - 1, 12, 31)->toDateString(),
            'label'     => 'Cùng kỳ năm trước',
        ]);

        $this->assertEquals(
            collect($legacy['rows'])->pluck('curr', 'code')->toArray(),
            collect($explicit['rows'])->pluck('curr', 'code')->toArray()
        );
        $this->assertEquals(
            collect($legacy['rows'])->pluck('prev', 'code')->toArray(),
            collect($explicit['rows'])->pluck('prev', 'code')->toArray()
        );
    }

    // ─── IS-S25: Cảnh báo unposted tính theo kỳ đang xem, không phải cả năm ───
    /** @test */
    public function unposted_warning_scopes_to_selected_period_not_whole_year(): void
    {
        // Draft JE trong tháng 2 — ngoài kỳ tháng 1 đang xem
        $this->makeJe('2026-02-10', '131', '511', 1_000_000, 'draft');

        $svc  = app(IncomeStatementService::class);
        $from = \Carbon\Carbon::create(2026, 1, 1)->startOfDay();
        $to   = \Carbon\Carbon::create(2026, 1, 31)->endOfDay();

        $warnings = $svc->validateDataQualityForRange($from, $to);
        $this->assertFalse(collect($warnings)->contains(fn ($w) => str_contains($w['message'], 'chưa posted')));

        // Draft JE trong đúng tháng 1 → phải cảnh báo, ghi rõ khoảng ngày của kỳ
        $this->makeJe('2026-01-20', '131', '511', 2_000_000, 'draft');
        $warnings2 = $svc->validateDataQualityForRange($from, $to);
        $this->assertTrue(collect($warnings2)->contains(fn ($w) => str_contains($w['message'], '01/01/2026 - 31/01/2026')));
    }

    // ─── IS-S26: compare_type=previous_period không được lệch ngày ────────────
    /** @test */
    public function compare_type_previous_period_has_no_off_by_one_day(): void
    {
        $this->get(route('reports.income_statement', [
            'year' => 2026, 'compare_type' => 'previous_period',
        ]))->assertInertia(fn ($p) => $p
            ->where('report.comparison_period.date_from', '2025-01-01')
            ->where('report.comparison_period.date_to', '2025-12-31')
        );

        $this->get(route('reports.income_statement', [
            'period_type' => 'quarter', 'year' => 2026, 'quarter' => 1, 'compare_type' => 'previous_period',
        ]))->assertInertia(fn ($p) => $p
            ->where('report.comparison_period.date_from', '2025-10-01')
            ->where('report.comparison_period.date_to', '2025-12-31')
        );
    }

    // ─── IS-S27: compare_type=same_period_last_year với tháng 2 năm nhuận ─────
    /** @test */
    public function compare_type_same_period_last_year_handles_leap_february(): void
    {
        $this->get(route('reports.income_statement', [
            'period_type' => 'month', 'year' => 2028, 'month' => 2,
        ]))->assertInertia(fn ($p) => $p
            ->where('report.period.date_from', '2028-02-01')
            ->where('report.period.date_to', '2028-02-29')
            ->where('report.comparison_period.date_from', '2027-02-01')
            ->where('report.comparison_period.date_to', '2027-02-28')
        );
    }
}
