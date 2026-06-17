<?php

namespace Tests\Feature\Accounting;

use App\Models\AccountCode;
use App\Models\AccountingPeriod;
use App\Models\PeriodCloseBatch;
use App\Models\User;
use App\Services\Accounting\PeriodCloseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class PeriodCloseEnhancedTest extends TestCase
{
    use RefreshDatabase;

    private PeriodCloseService $service;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::firstOrCreate(
            ['email' => 'test@test.local'],
            ['name' => 'Test', 'password' => bcrypt('pass'), 'is_active' => true]
        );
        $this->actingAs($this->user);
        Gate::before(fn ($user, $ability) => true);

        $this->service = app(PeriodCloseService::class);

        $this->seedBaseAccounts();
    }

    private function seedBaseAccounts(): void
    {
        $accounts = [
            ['511',  'Doanh thu BH',                  'revenue', 'credit', null,  2, false],
            ['5111', 'Doanh thu BH hàng hóa',          'revenue', 'credit', '511', 3, true],
            ['5113', 'Doanh thu cung cấp DV',           'revenue', 'credit', '511', 3, true],
            ['515',  'DT hoạt động tài chính',          'revenue', 'credit', null,  2, true],
            ['711',  'Thu nhập khác',                   'revenue', 'credit', null,  2, true],
            ['632',  'Giá vốn HB',                     'expense', 'debit',  null,  2, true],
            ['635',  'Chi phí tài chính',               'expense', 'debit',  null,  2, true],
            ['642',  'Chi phí QLDN',                   'expense', 'debit',  null,  2, false],
            ['6421', 'Chi phí nhân viên QLDN',          'expense', 'debit',  '642', 3, true],
            ['6422', 'Chi phí khác QLDN',               'expense', 'debit',  '642', 3, true],
            ['811',  'Chi phí khác',                   'expense', 'debit',  null,  2, true],
            ['821',  'Chi phí thuế TNDN',              'expense', 'debit',  null,  2, true],
            ['8211', 'Chi phí thuế TNDN hiện hành',    'expense', 'debit',  '821', 3, true],
            ['91',   'TK trung gian',                   'equity',  'credit', null,  2, false],
            ['911',  'Xác định kết quả KD',             'equity',  'credit', '91',  3, true],
            ['421',  'LNST chưa phân phối',             'equity',  'credit', null,  2, false],
            ['4211', 'LNST năm trước',                  'equity',  'credit', '421', 3, true],
            ['4212', 'LNST năm nay',                    'equity',  'credit', '421', 3, true],
            ['111',  'Tiền mặt',                        'asset',   'debit',  null,  2, false],
            ['1111', 'Tiền mặt tại quỹ',                'asset',   'debit',  '111', 3, true],
            ['112',  'TG ngân hàng',                    'asset',   'debit',  null,  2, false],
            ['1121', 'TG NH VND',                       'asset',   'debit',  '112', 3, true],
            ['156',  'Hàng hóa',                        'asset',   'debit',  null,  2, false],
            ['1561', 'Hàng hóa kho',                    'asset',   'debit',  '156', 3, true],
            ['521',  'Giảm giá HB (TT133 hợp lệ)',      'revenue', 'debit',  '511', 3, true],
        ];
        foreach ($accounts as [$code, $name, $type, $nb, $parent, $level, $detail]) {
            AccountCode::updateOrCreate(['code' => $code], [
                'name' => $name, 'type' => $type, 'normal_balance' => $nb,
                'parent_code' => $parent, 'level' => $level,
                'is_detail' => $detail, 'is_active' => true,
            ]);
        }

        // accounting_settings
        \DB::table('accounting_settings')->insertOrIgnore([
            ['key' => 'cit_expense_account',            'value' => '821',  'label' => 'TK CIT', 'group' => 'period_close', 'sort_order' => 30, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'period_close_prior_year_account', 'value' => '4211', 'label' => 'TK 4211', 'group' => 'period_close', 'sort_order' => 40, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    private function makeOpenPeriod(string $fiscal): AccountingPeriod
    {
        [$year, $month] = array_map('intval', explode('-', $fiscal));
        return AccountingPeriod::firstOrCreate(
            ['year' => $year, 'month' => $month],
            ['status' => 'open']
        );
    }

    private function makeLockedPeriod(string $fiscal): AccountingPeriod
    {
        [$year, $month] = array_map('intval', explode('-', $fiscal));
        return AccountingPeriod::firstOrCreate(
            ['year' => $year, 'month' => $month],
            ['status' => 'locked']
        );
    }

    private function postJe(string $fiscal, array $lines, string $sourceType = null): int
    {
        $date = substr($fiscal, 0, 7) . '-15';

        \DB::table('journal_entries')->insert([
            'code'          => 'JE-' . uniqid(),
            'status'        => 'posted',
            'fiscal_period' => $fiscal,
            'entry_date'    => $date,
            'description'   => 'test',
            'source_type'   => $sourceType,
            'is_auto'       => false,
            'created_by'    => $this->user->id,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
        $jeId = \DB::getPdo()->lastInsertId();

        foreach ($lines as $i => $line) {
            \DB::table('journal_entry_lines')->insert([
                'journal_entry_id' => $jeId,
                'account_code'     => $line['account'],
                'debit'            => $line['debit'] ?? 0,
                'credit'           => $line['credit'] ?? 0,
                'description'      => $line['description'] ?? 'test',
                'sort_order'       => $i + 1,
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);
        }
        return $jeId;
    }

    // ─────────────────────────────────────────────────────────────────────
    // 1. Checklist
    // ─────────────────────────────────────────────────────────────────────

    public function test_checklist_returns_expected_keys(): void
    {
        $this->makeOpenPeriod('2026-06');
        $result = $this->service->buildChecklist('2026-06');

        $this->assertIsArray($result);
        $this->assertNotEmpty($result);

        $keys = array_column($result, 'key');
        foreach (['payroll', 'depreciation', 'prepaid', 'vat_close', 'cit', 'draft_jes', 'stock_entries', 'wip'] as $expected) {
            $this->assertContains($expected, $keys, "Checklist thiếu key: {$expected}");
        }

        foreach ($result as $item) {
            $this->assertArrayHasKey('key', $item);
            $this->assertArrayHasKey('label', $item);
            $this->assertArrayHasKey('status', $item);
            $this->assertArrayHasKey('message', $item);
            $this->assertContains($item['status'], ['ok', 'warning', 'missing', 'needs_review', 'info', 'skip']);
        }
    }

    public function test_checklist_payroll_missing_when_no_confirmed_payroll(): void
    {
        $this->makeOpenPeriod('2026-06');
        $result = $this->service->buildChecklist('2026-06');

        $payroll = collect($result)->firstWhere('key', 'payroll');
        $this->assertNotNull($payroll);
        $this->assertContains($payroll['status'], ['missing', 'skip', 'info', 'warning']);
    }

    public function test_checklist_draft_jes_warning_when_draft_exists(): void
    {
        $this->makeOpenPeriod('2026-06');

        \DB::table('journal_entries')->insert([
            'code'          => 'JE-DRAFT-001',
            'status'        => 'draft',
            'fiscal_period' => '2026-06',
            'entry_date'    => '2026-06-15',
            'description'   => 'Test draft',
            'is_auto'       => false,
            'created_by'    => $this->user->id,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        $result = $this->service->buildChecklist('2026-06');
        $item   = collect($result)->firstWhere('key', 'draft_jes');

        $this->assertNotNull($item);
        $this->assertEquals('warning', $item['status']);
    }

    // ─────────────────────────────────────────────────────────────────────
    // 2. Preview structure
    // ─────────────────────────────────────────────────────────────────────

    public function test_preview_has_required_structure(): void
    {
        $this->makeOpenPeriod('2026-06');
        $result = $this->service->preview('2026-06');

        foreach (['checklist', 'incomeSections', 'expenseSections', 'totalRevenue', 'totalExpense', 'profitOrLoss', 'warnings', 'hasCritical', 'canClose', 'result'] as $key) {
            $this->assertArrayHasKey($key, $result, "preview() thiếu key: {$key}");
        }
    }

    public function test_income_sections_include_tk_5111_when_has_revenue(): void
    {
        $this->makeOpenPeriod('2026-06');

        $this->postJe('2026-06', [
            ['account' => '1121', 'debit' => 10_000_000, 'credit' => 0],
            ['account' => '5111', 'debit' => 0, 'credit' => 10_000_000],
        ]);

        $result = $this->service->preview('2026-06');

        $this->assertGreaterThan(0, count($result['incomeSections']));
        // SQLite trả integer cho numeric codes — cast sang string để so sánh nhất quán
        $codes = array_map('strval', array_column($result['incomeSections'], 'code'));
        $this->assertContains('5111', $codes);
        $this->assertEquals(10_000_000, $result['totalRevenue']);
    }

    public function test_expense_sections_include_tk_632_when_has_cogs(): void
    {
        $this->makeOpenPeriod('2026-06');

        $this->postJe('2026-06', [
            ['account' => '632',  'debit' => 5_000_000, 'credit' => 0],
            ['account' => '1561', 'debit' => 0, 'credit' => 5_000_000],
        ]);

        $result = $this->service->preview('2026-06');

        $this->assertGreaterThan(0, count($result['expenseSections']));
        $codes = array_map('strval', array_column($result['expenseSections'], 'code'));
        $this->assertContains('632', $codes);
        $this->assertEquals(5_000_000, $result['totalExpense']);
    }

    // ─────────────────────────────────────────────────────────────────────
    // 3. TK 154 không được kết chuyển
    // ─────────────────────────────────────────────────────────────────────

    public function test_tk154_not_included_in_expense_sections(): void
    {
        $this->makeOpenPeriod('2026-06');

        AccountCode::updateOrCreate(['code' => '154'], [
            'name' => 'Chi phí SXKD DD', 'type' => 'asset', 'normal_balance' => 'debit',
            'level' => 2, 'is_detail' => true, 'is_active' => true,
        ]);

        $this->postJe('2026-06', [
            ['account' => '154',  'debit' => 1_000_000, 'credit' => 0],
            ['account' => '1561', 'debit' => 0, 'credit' => 1_000_000],
        ]);

        $result = $this->service->preview('2026-06');

        $this->assertNotContains('154', array_column($result['expenseSections'], 'code'),
            'TK 154 (WIP/asset) không được kết chuyển vào expenseSections');
    }

    // ─────────────────────────────────────────────────────────────────────
    // 4. Warnings
    // ─────────────────────────────────────────────────────────────────────

    public function test_warning_tk521_triggers_when_521_has_balance(): void
    {
        $this->makeOpenPeriod('2026-06');

        $this->postJe('2026-06', [
            ['account' => '521',  'debit' => 500_000, 'credit' => 0],
            ['account' => '1121', 'debit' => 0, 'credit' => 500_000],
        ]);

        $warnings = $this->service->getWarnings('2026-06');

        $this->assertContains('TK521_EXISTS', array_column($warnings, 'code'),
            'Phải có cảnh báo TK521_EXISTS');
    }

    public function test_warning_missing_tk911_is_critical(): void
    {
        $this->makeOpenPeriod('2026-06');

        AccountCode::where('code', '911')->forceDelete();

        $warnings = $this->service->getWarnings('2026-06');

        $critical = collect($warnings)->where('type', 'critical')->where('code', 'MISSING_ACCOUNT')->first();
        $this->assertNotNull($critical, 'Phải có CRITICAL MISSING_ACCOUNT khi TK 911 không tồn tại');
    }

    public function test_warning_missing_tk4212_is_critical(): void
    {
        $this->makeOpenPeriod('2026-06');

        AccountCode::where('code', '4212')->forceDelete();

        $warnings = $this->service->getWarnings('2026-06');

        $critical = collect($warnings)->where('type', 'critical')->where('code', 'MISSING_ACCOUNT')->first();
        $this->assertNotNull($critical, 'Phải có CRITICAL MISSING_ACCOUNT khi TK 4212 không tồn tại');
    }

    public function test_period_not_found_warning_when_period_missing(): void
    {
        // Không tạo period cho 2020-01
        $warnings = $this->service->getWarnings('2020-01');

        $this->assertContains('PERIOD_NOT_FOUND', array_column($warnings, 'code'));
    }

    public function test_period_locked_warning_critical(): void
    {
        $this->makeLockedPeriod('2026-07');

        $warnings = $this->service->getWarnings('2026-07');

        $this->assertContains('PERIOD_LOCKED', array_column($warnings, 'code'));
    }

    // ─────────────────────────────────────────────────────────────────────
    // 5. Year-end transfer
    // ─────────────────────────────────────────────────────────────────────

    public function test_year_end_transfer_profit_dr4212_cr4211(): void
    {
        $this->makeOpenPeriod('2026-12');

        // Simulate profit: 911 → 4212 (period_close JE)
        $this->postJe('2026-12', [
            ['account' => '911',  'debit' => 20_000_000, 'credit' => 0],
            ['account' => '4212', 'debit' => 0, 'credit' => 20_000_000],
        ], 'period_close');

        $plan = $this->service->buildYearEndTransfer(2026);

        $this->assertEquals(2026, $plan['year']);
        $this->assertEquals(20_000_000, $plan['balance']);

        $accounts = array_column($plan['lines'], 'account');
        $this->assertContains('4212', $accounts);
        $this->assertContains('4211', $accounts);

        $dr4212 = collect($plan['lines'])->firstWhere('account', '4212');
        $cr4211 = collect($plan['lines'])->firstWhere('account', '4211');
        $this->assertEquals(20_000_000, $dr4212['debit']);
        $this->assertEquals(20_000_000, $cr4211['credit']);
    }

    public function test_year_end_transfer_loss_dr4211_cr4212(): void
    {
        $this->makeOpenPeriod('2026-12');

        // Simulate loss: 4212 debit balance (Dr 4212 / Cr 911)
        $this->postJe('2026-12', [
            ['account' => '4212', 'debit' => 5_000_000, 'credit' => 0],
            ['account' => '911',  'debit' => 0, 'credit' => 5_000_000],
        ], 'period_close');

        $plan = $this->service->buildYearEndTransfer(2026);

        $this->assertEquals(-5_000_000, $plan['balance']);

        $dr4211 = collect($plan['lines'])->firstWhere('account', '4211');
        $cr4212 = collect($plan['lines'])->firstWhere('account', '4212');
        $this->assertNotNull($dr4211);
        $this->assertNotNull($cr4212);
        $this->assertEquals(5_000_000, $dr4211['debit']);
        $this->assertEquals(5_000_000, $cr4212['credit']);
    }

    public function test_year_end_transfer_zero_balance_returns_empty_lines(): void
    {
        $this->makeOpenPeriod('2026-12');

        // Không có JE nào với 4212 → balance = 0
        $plan = $this->service->buildYearEndTransfer(2026);

        $this->assertEquals(0.0, $plan['balance']);
        $this->assertEmpty($plan['lines'], 'Khi balance = 0 không tạo bút toán');
    }

    // ─────────────────────────────────────────────────────────────────────
    // 6. batch_type column
    // ─────────────────────────────────────────────────────────────────────

    public function test_close_with_batch_sets_monthly_type(): void
    {
        $this->makeOpenPeriod('2026-06');

        $this->postJe('2026-06', [
            ['account' => '1121', 'debit' => 1_000_000, 'credit' => 0],
            ['account' => '5111', 'debit' => 0, 'credit' => 1_000_000],
        ]);

        $batch = $this->service->closeWithBatch('2026-06', $this->user->id);

        $this->assertEquals('monthly', $batch->batch_type);
    }

    public function test_close_year_end_sets_year_end_type(): void
    {
        $this->makeOpenPeriod('2026-12');

        $this->postJe('2026-12', [
            ['account' => '911',  'debit' => 10_000_000, 'credit' => 0],
            ['account' => '4212', 'debit' => 0, 'credit' => 10_000_000],
        ], 'period_close');

        $batch = $this->service->closeYearEnd(2026, $this->user->id);

        $this->assertEquals('year_end', $batch->batch_type);
    }

    public function test_has_active_year_end_batch_blocks_second(): void
    {
        $this->makeOpenPeriod('2026-12');

        $this->postJe('2026-12', [
            ['account' => '911',  'debit' => 10_000_000, 'credit' => 0],
            ['account' => '4212', 'debit' => 0, 'credit' => 10_000_000],
        ], 'period_close');

        $this->service->closeYearEnd(2026, $this->user->id);

        // Plant balance again (service may clear after year-end)
        $this->postJe('2026-12', [
            ['account' => '911',  'debit' => 5_000_000, 'credit' => 0],
            ['account' => '4212', 'debit' => 0, 'credit' => 5_000_000],
        ], 'period_close');

        $this->expectException(\RuntimeException::class);
        $this->service->closeYearEnd(2026, $this->user->id);
    }

    // ─────────────────────────────────────────────────────────────────────
    // 7. API — preview trả warnings, store redirect
    // ─────────────────────────────────────────────────────────────────────

    public function test_api_preview_period_not_found_returns_warning(): void
    {
        // Không tạo period → preview trả 200 success:true nhưng warnings có PERIOD_NOT_FOUND
        $res = $this->postJson(
            route('accounting.period-close.preview'),
            ['period' => '2099-01']
        );

        $res->assertOk()->assertJsonPath('success', true);
        $warnings = collect($res->json('warnings'));
        $this->assertTrue(
            $warnings->contains('code', 'PERIOD_NOT_FOUND'),
            "Warnings phải chứa PERIOD_NOT_FOUND"
        );
        $this->assertTrue($res->json('hasCritical'), "hasCritical phải là true");
    }

    public function test_api_preview_period_locked_has_critical_warning(): void
    {
        $this->makeLockedPeriod('2026-05');

        $res = $this->postJson(
            route('accounting.period-close.preview'),
            ['period' => '2026-05']
        );

        $res->assertOk()->assertJsonPath('success', true);
        $warnings = collect($res->json('warnings'));
        $this->assertTrue(
            $warnings->contains('code', 'PERIOD_LOCKED'),
            "Warnings phải chứa PERIOD_LOCKED"
        );
        $this->assertTrue($res->json('hasCritical'));
    }

    public function test_service_existing_batch_throws_on_second_close(): void
    {
        $this->makeOpenPeriod('2026-04');

        $this->postJe('2026-04', [
            ['account' => '1121', 'debit' => 1_000_000, 'credit' => 0],
            ['account' => '5111', 'debit' => 0, 'credit' => 1_000_000],
        ]);

        $this->service->closeWithBatch('2026-04', $this->user->id);

        $this->expectException(\RuntimeException::class);
        $this->service->closeWithBatch('2026-04', $this->user->id);
    }

    // ─────────────────────────────────────────────────────────────────────
    // 8. Year-end API preview
    // ─────────────────────────────────────────────────────────────────────

    public function test_year_end_preview_api_returns_plan(): void
    {
        $this->makeOpenPeriod('2026-12');

        $this->postJe('2026-12', [
            ['account' => '911',  'debit' => 15_000_000, 'credit' => 0],
            ['account' => '4212', 'debit' => 0, 'credit' => 15_000_000],
        ], 'period_close');

        $res = $this->postJson(
            route('accounting.period-close.year-end-preview'),
            ['year' => 2026]
        );

        $res->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('year', 2026);

        $this->assertCount(2, $res->json('lines'));
    }
}
