<?php

namespace Tests\Feature;

use App\Models\AccountCode;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\User;
use App\Services\Accounting\AccountBalanceService;
use App\Services\Accounting\FinancialPositionReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Smoke tests cho BalanceSheetController + FinancialPositionReportService.
 * Kiểm tra controller trả đúng props và service xử lý đúng các luồng cơ bản.
 * Chi tiết nghiệp vụ (131/331/421/133/TSCĐ) nằm trong FinancialPositionReportTest.
 */
class BalanceSheetTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private FinancialPositionReportService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
        $this->svc = new FinancialPositionReportService(new AccountBalanceService());

        // Grant permission for controller HTTP tests
        Permission::firstOrCreate(['name' => 'reports.view', 'guard_name' => 'web']);
        $this->user->givePermissionTo('reports.view');

        // Parent accounts must be seeded before children (FK constraint)
        $accounts = [
            ['code' => '111',  'name' => 'Tiền mặt',             'type' => 'asset',   'normal_balance' => 'debit',  'parent_code' => null,  'level' => 3, 'is_detail' => true],
            ['code' => '112',  'name' => 'TGNH',                  'type' => 'asset',   'normal_balance' => 'debit',  'parent_code' => null,  'level' => 3, 'is_detail' => false],
            ['code' => '411',  'name' => 'Vốn đầu tư CSH',        'type' => 'equity',  'normal_balance' => 'credit', 'parent_code' => null,  'level' => 3, 'is_detail' => true],
            ['code' => '421',  'name' => 'LNST chưa phân phối',   'type' => 'equity',  'normal_balance' => 'credit', 'parent_code' => null,  'level' => 3, 'is_detail' => false],
            ['code' => '511',  'name' => 'Doanh thu bán hàng',    'type' => 'revenue', 'normal_balance' => 'credit', 'parent_code' => null,  'level' => 3, 'is_detail' => true],
            ['code' => '632',  'name' => 'Giá vốn hàng bán',      'type' => 'expense', 'normal_balance' => 'debit',  'parent_code' => null,  'level' => 3, 'is_detail' => true],
            ['code' => '911',  'name' => 'Xác định KQKD',         'type' => 'expense', 'normal_balance' => 'debit',  'parent_code' => null,  'level' => 3, 'is_detail' => true],
            // Children after parents
            ['code' => '1121', 'name' => 'TGNH VND',              'type' => 'asset',   'normal_balance' => 'debit',  'parent_code' => '112', 'level' => 4, 'is_detail' => true],
            ['code' => '4212', 'name' => 'LNST năm nay',          'type' => 'equity',  'normal_balance' => 'credit', 'parent_code' => '421', 'level' => 4, 'is_detail' => true],
        ];
        foreach ($accounts as $ac) {
            AccountCode::firstOrCreate(['code' => $ac['code']], $ac);
        }
    }

    private function postEntry(string $date, array $lines): void
    {
        $entry = JournalEntry::create([
            'code'        => 'BT-' . uniqid(),
            'entry_date'  => $date,
            'description' => 'Test',
            'status'      => 'posted',
            'is_auto'     => false,
            'created_by'  => $this->user->id,
        ]);
        foreach ($lines as $i => [$account, $debit, $credit]) {
            JournalEntryLine::create([
                'journal_entry_id' => $entry->id,
                'account_code'     => $account,
                'debit'            => $debit,
                'credit'           => $credit,
                'sort_order'       => $i + 1,
            ]);
        }
    }

    private function build(string $asOf = '2026-06-30'): array
    {
        return $this->svc->build($asOf);
    }

    private function findRow(array $report, string $itemCode): ?array
    {
        foreach ($report['rows'] as $row) {
            if (($row['config_code'] ?? $row['item_code']) === $itemCode) {
                return $row;
            }
        }
        return null;
    }

    // ─── Controller HTTP tests ────────────────────────────────────────────────

    /**
     * TC1: Controller trả đúng props cho Inertia page.
     */
    public function test_controller_returns_expected_inertia_props(): void
    {
        $this->postEntry('2026-01-01', [['1121', 100_000_000, 0], ['411', 0, 100_000_000]]);

        $response = $this->get(route('reports.balance_sheet', ['as_of' => '2026-06-30']));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Reports/BalanceSheet/Index')
            ->has('balanceSheet')
            ->has('summary')
            ->has('warnings')
            ->has('trialBalance')
            ->has('reportMeta')
            ->has('filters')
        );
    }

    /**
     * TC2: summary chứa đúng 4 keys kế toán.
     */
    public function test_summary_has_required_keys(): void
    {
        $data = $this->build();

        $this->assertArrayHasKey('total_assets',             $data['summary']);
        $this->assertArrayHasKey('total_liabilities',        $data['summary']);
        $this->assertArrayHasKey('total_equity',             $data['summary']);
        $this->assertArrayHasKey('total_liabilities_equity', $data['summary']);
        $this->assertArrayHasKey('balanced',                 $data['summary']);
    }

    /**
     * TC3: rows có đủ keys chuẩn.
     */
    public function test_rows_have_required_keys(): void
    {
        $this->postEntry('2026-01-01', [['411', 0, 10_000_000], ['1121', 10_000_000, 0]]);

        $data = $this->build();

        $this->assertNotEmpty($data['rows']);
        $row = $data['rows'][0];
        $this->assertArrayHasKey('item_code',         $row);
        $this->assertArrayHasKey('item_name',         $row);
        $this->assertArrayHasKey('amount',            $row);
        $this->assertArrayHasKey('section',           $row);
        $this->assertArrayHasKey('is_total',          $row);
        $this->assertArrayHasKey('is_formula',        $row);
        $this->assertArrayHasKey('level',             $row);
    }

    /**
     * TC4: Báo cáo cân khi Dr = Cr — mã 200 = mã 500.
     */
    public function test_balanced_report_when_double_entry_correct(): void
    {
        $this->postEntry('2026-01-01', [['1121', 50_000_000, 0], ['411', 0, 50_000_000]]);
        $this->postEntry('2026-06-30', [['1121', 10_000_000, 0], ['4212', 0, 10_000_000]]);

        $data = $this->build();

        $this->assertTrue($data['summary']['balanced']);
        $this->assertEquals(0.0, $data['summary']['difference'], '', 1.0);
    }

    /**
     * TC5: reportMeta có đủ thông tin TT133.
     */
    public function test_report_meta_contains_tt133_info(): void
    {
        $data = $this->build();

        $this->assertEquals('B01a-DNN',              $data['report_code']);
        $this->assertStringContainsString('133',     $data['circular']);
        $this->assertNotEmpty($data['report_name']);
    }

    /**
     * TC6: TK 421/4212 dư Có → mã 417 dương.
     * (Không dùng công thức 5xx-6xx — lấy thẳng từ balance TK 421)
     */
    public function test_code417_comes_from_tk421_not_pnl_formula(): void
    {
        $this->postEntry('2026-01-01', [['1121', 100_000_000, 0], ['411', 0, 100_000_000]]);
        // Kết chuyển lãi 30M → 4212
        $this->postEntry('2026-06-30', [['1121', 30_000_000, 0], ['4212', 0, 30_000_000]]);

        $data    = $this->build();
        $row417  = $this->findRow($data, '417');

        $this->assertNotNull($row417);
        $this->assertEquals(30_000_000, $row417['amount']);
    }

    /**
     * TC7: TK 511 chưa kết chuyển → cảnh báo xuất hiện ở chế độ chính thức.
     */
    public function test_warning_emitted_when_511_not_closed(): void
    {
        $this->postEntry('2026-06-01', [['1121', 20_000_000, 0], ['511', 0, 20_000_000]]);

        $data = $this->svc->build('2026-06-30', 'official');

        $this->assertNotEmpty($data['warnings']);
        $this->assertTrue(
            collect($data['warnings'])->contains(fn($w) => str_contains($w, 'chưa kết chuyển')),
            'Phải có cảnh báo "chưa kết chuyển"'
        );
    }

    /**
     * TC8: Không có bút toán → báo cáo trả về đúng cấu trúc (không crash).
     */
    public function test_empty_journal_returns_valid_structure(): void
    {
        $data = $this->build();

        $this->assertIsArray($data['rows']);
        $this->assertIsArray($data['warnings']);
        $this->assertIsArray($data['summary']);
        $this->assertIsArray($data['trial_balance']);
        $this->assertEquals(0.0, $data['summary']['total_assets']);
        $this->assertEquals(0.0, $data['summary']['total_liabilities_equity']);
        $this->assertTrue($data['summary']['balanced']);
    }

    /**
     * TC9: trial_balance trả đúng totals và trạng thái cân.
     */
    public function test_trial_balance_totals_correct(): void
    {
        $this->postEntry('2026-01-01', [['1121', 100_000_000, 0], ['411', 0, 100_000_000]]);

        $data = $this->build();
        $tb   = $data['trial_balance'];

        $this->assertTrue($tb['balanced']);
        $this->assertEquals($tb['total_debit'], $tb['total_credit']);
        $this->assertEquals(100_000_000.0, $tb['total_debit'], '', 0.01);
    }

    // ─── Permission tests ────────────────────────────────────────────────────

    /**
     * TC10: User chỉ có reports.view — POST map-account phải trả 403.
     */
    public function test_map_account_requires_accounting_manage_permission(): void
    {
        // Đảm bảo permission tồn tại nhưng không grant cho user
        Permission::firstOrCreate(['name' => 'accounting.manage', 'guard_name' => 'web']);

        $response = $this->post(route('reports.balance_sheet.map_account'), [
            'account_code' => '1121',
            'item_code'    => '110',
        ]);

        $response->assertForbidden();
    }

    /**
     * TC11: canManageAccounting = false khi user chỉ có reports.view.
     */
    public function test_controller_passes_can_manage_false_without_accounting_manage(): void
    {
        Permission::firstOrCreate(['name' => 'accounting.manage', 'guard_name' => 'web']);
        // User trong setUp chỉ có reports.view, không có accounting.manage

        $response = $this->get(route('reports.balance_sheet', ['as_of' => '2026-06-30']));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Reports/BalanceSheet/Index')
            ->where('canManageAccounting', false)
        );
    }

    /**
     * TC12: canManageAccounting = true khi user có accounting.manage.
     */
    public function test_controller_passes_can_manage_true_with_accounting_manage(): void
    {
        Permission::firstOrCreate(['name' => 'accounting.manage', 'guard_name' => 'web']);
        $this->user->givePermissionTo('accounting.manage');

        $response = $this->get(route('reports.balance_sheet', ['as_of' => '2026-06-30']));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Reports/BalanceSheet/Index')
            ->where('canManageAccounting', true)
        );
    }
}
