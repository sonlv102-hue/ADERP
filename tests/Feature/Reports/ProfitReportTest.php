<?php

namespace Tests\Feature\Reports;

use App\Models\AccountCode;
use App\Models\JournalEntry;
use App\Models\Permission;
use App\Models\User;
use App\Services\AccountingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * Test 1: Doanh thu 511 tháng 6 hiển thị đúng.
 * Test 2: Giá vốn 632 -> lợi nhuận gộp đúng.
 * Test 3: Chi phí 641/642 -> lợi nhuận thuần đúng.
 * Test 4: Filter quý Q2 gom đúng tháng 4,5,6.
 * Test 5: Filter custom từ ngày-đến ngày chỉ lấy trong khoảng.
 * Test 6: Bút toán draft/voided không được tính (hệ thống dùng 'voided', không có 'cancelled').
 * Test 7: Bút toán đảo (reverse) phải trừ đúng.
 * Test 8: Export Excel ra cùng số với UI.
 * Test 9: User không có reports.profit.view bị 403.
 */
class ProfitReportTest extends TestCase
{
    use RefreshDatabase;

    private int $userId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAccounts();
    }

    private function actingAsAdmin(): void
    {
        $user = User::firstOrCreate(
            ['email' => 'admin@test.local'],
            ['name' => 'Admin', 'password' => bcrypt('pass'), 'is_active' => true]
        );
        $this->actingAs($user);
        Gate::before(fn ($u, $a) => true);
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
            ['code' => '511',  'name' => 'Doanh thu BH',  'normal_balance' => 'credit', 'type' => 'revenue', 'is_detail' => true],
            ['code' => '521',  'name' => 'Giảm trừ DT',   'normal_balance' => 'debit',  'type' => 'revenue', 'is_detail' => true],
            ['code' => '632',  'name' => 'Giá vốn HB',    'normal_balance' => 'debit',  'type' => 'expense', 'is_detail' => true],
            ['code' => '641',  'name' => 'CP bán hàng',   'normal_balance' => 'debit',  'type' => 'expense', 'is_detail' => true],
            ['code' => '642',  'name' => 'CP QLDN',       'normal_balance' => 'debit',  'type' => 'expense', 'is_detail' => true],
            ['code' => '635',  'name' => 'CP tài chính',  'normal_balance' => 'debit',  'type' => 'expense', 'is_detail' => true],
            ['code' => '811',  'name' => 'CP khác',       'normal_balance' => 'debit',  'type' => 'expense', 'is_detail' => true],
            ['code' => '131',  'name' => 'Phải thu KH',   'normal_balance' => 'debit',  'type' => 'asset',   'is_detail' => true],
            ['code' => '156',  'name' => 'Hàng hóa',      'normal_balance' => 'debit',  'type' => 'asset',   'is_detail' => true],
            ['code' => '1121', 'name' => 'TGNH VND',      'normal_balance' => 'debit',  'type' => 'asset',   'is_detail' => true],
            ['code' => '331',  'name' => 'Phải trả NCC',  'normal_balance' => 'credit', 'type' => 'liability', 'is_detail' => true],
        ];
        foreach ($accounts as $a) {
            AccountCode::firstOrCreate(['code' => $a['code']], array_merge($a, ['is_active' => true]));
        }
    }

    private function makeJe(string $date, string $dr, string $cr, float $amount, string $status = 'posted'): int
    {
        $jeId = DB::table('journal_entries')->insertGetId([
            'code'        => 'JE-PL-' . uniqid(),
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
        return $jeId;
    }

    // ─── Test 1 ──────────────────────────────────────────────────────────────
    public function test_revenue_511_in_june_shows_correctly(): void
    {
        $this->actingAsAdmin();
        $this->makeJe('2026-06-15', '131', '511', 10_000_000);

        $this->get(route('reports.profit', ['period_type' => 'month', 'year' => 2026, 'month' => 6]))
            ->assertInertia(fn ($p) => $p
                ->component('Reports/Profit/Index')
                ->where('summary.revenue', 10_000_000)
                ->where('summary.net_revenue', 10_000_000)
            );
    }

    // ─── Test 2 ──────────────────────────────────────────────────────────────
    public function test_cogs_632_computes_gross_profit_correctly(): void
    {
        $this->actingAsAdmin();
        $this->makeJe('2026-06-15', '131', '511', 10_000_000);
        $this->makeJe('2026-06-15', '632', '156', 6_000_000);

        $this->get(route('reports.profit', ['period_type' => 'month', 'year' => 2026, 'month' => 6]))
            ->assertInertia(fn ($p) => $p
                ->where('summary.cogs', 6_000_000)
                ->where('summary.gross_profit', 4_000_000)
            );
    }

    // ─── Test 3 ──────────────────────────────────────────────────────────────
    public function test_expenses_641_642_compute_net_profit_correctly(): void
    {
        $this->actingAsAdmin();
        $this->makeJe('2026-06-15', '131', '511', 10_000_000);
        $this->makeJe('2026-06-15', '632', '156', 6_000_000);
        $this->makeJe('2026-06-15', '641', '1121', 1_000_000);
        $this->makeJe('2026-06-15', '642', '1121', 500_000);

        $this->get(route('reports.profit', ['period_type' => 'month', 'year' => 2026, 'month' => 6]))
            ->assertInertia(fn ($p) => $p
                ->where('summary.selling_expense', 1_000_000)
                ->where('summary.admin_expense', 500_000)
                ->where('summary.total_operating_expense', 1_500_000)
                ->where('summary.net_profit', 2_500_000)
            );
    }

    // ─── Test 4 ──────────────────────────────────────────────────────────────
    public function test_quarter_filter_groups_months_4_5_6(): void
    {
        $this->actingAsAdmin();
        $this->makeJe('2026-04-10', '131', '511', 1_000_000);
        $this->makeJe('2026-05-10', '131', '511', 2_000_000);
        $this->makeJe('2026-06-10', '131', '511', 3_000_000);
        $this->makeJe('2026-03-10', '131', '511', 9_999_999); // ngoài quý, không được tính
        $this->makeJe('2026-07-10', '131', '511', 9_999_999); // ngoài quý, không được tính

        $this->get(route('reports.profit', ['period_type' => 'quarter', 'year' => 2026, 'quarter' => 2]))
            ->assertInertia(fn ($p) => $p
                ->where('summary.revenue', 6_000_000)
                ->has('rows', 3)
            );
    }

    // ─── Test 5 ──────────────────────────────────────────────────────────────
    public function test_custom_range_filter_only_includes_dates_in_range(): void
    {
        $this->actingAsAdmin();
        $this->makeJe('2026-06-05', '131', '511', 1_000_000);
        $this->makeJe('2026-06-20', '131', '511', 2_000_000);
        $this->makeJe('2026-06-25', '131', '511', 5_000_000); // ngoài khoảng lọc

        $this->get(route('reports.profit', [
            'period_type' => 'custom', 'date_from' => '2026-06-01', 'date_to' => '2026-06-21',
        ]))->assertInertia(fn ($p) => $p->where('summary.revenue', 3_000_000));
    }

    // ─── Test 6 ──────────────────────────────────────────────────────────────
    public function test_draft_and_voided_entries_are_excluded(): void
    {
        $this->actingAsAdmin();
        $this->makeJe('2026-06-15', '131', '511', 10_000_000, 'posted');
        $this->makeJe('2026-06-15', '131', '511', 4_000_000, 'draft');
        $this->makeJe('2026-06-15', '131', '511', 7_000_000, 'voided');

        $this->get(route('reports.profit', ['period_type' => 'month', 'year' => 2026, 'month' => 6]))
            ->assertInertia(fn ($p) => $p->where('summary.revenue', 10_000_000));
    }

    // ─── Test 7 ──────────────────────────────────────────────────────────────
    public function test_reversed_entry_is_subtracted_correctly(): void
    {
        $this->actingAsAdmin();
        $jeId = $this->makeJe('2026-06-15', '632', '156', 6_000_000);

        app(AccountingService::class)->reverse(JournalEntry::find($jeId));

        // Bút toán gốc chuyển status 'reversed' (bị loại khỏi filter 'posted'),
        // bút toán đảo được post vào ngày hiện tại (now()) — ngoài kỳ tháng 6.
        $this->get(route('reports.profit', ['period_type' => 'month', 'year' => 2026, 'month' => 6]))
            ->assertInertia(fn ($p) => $p->where('summary.cogs', 0));
    }

    // ─── Test 8 ──────────────────────────────────────────────────────────────
    public function test_excel_export_matches_ui_numbers(): void
    {
        $this->actingAsAdmin();
        $this->makeJe('2026-06-15', '131', '511', 10_000_000);
        $this->makeJe('2026-06-15', '632', '156', 6_000_000);

        $filters = ['period_type' => 'month', 'year' => 2026, 'month' => 6];

        $uiResponse = $this->get(route('reports.profit', $filters));
        $uiResponse->assertInertia(fn ($p) => $p
            ->where('summary.net_profit', 4_000_000)
        );

        $exportResponse = $this->get(route('reports.profit.export', $filters));
        $exportResponse->assertOk();

        $path = $exportResponse->getFile()->getPathname();
        $sheet = IOFactory::load($path)->getActiveSheet();

        // Row 14 = "Lợi nhuận thuần" (xem thứ tự dòng cards trong ProfitReportExport::array()).
        $this->assertEquals('Lợi nhuận thuần', $sheet->getCell('A14')->getValue());
        $this->assertEqualsWithDelta(4_000_000, (float) $sheet->getCell('B14')->getValue(), 0.01);
    }

    // ─── Test 9 ──────────────────────────────────────────────────────────────
    public function test_user_without_permission_gets_403(): void
    {
        Permission::firstOrCreate(['code' => 'reports.profit.view'], ['name' => 'Xem Báo cáo lợi nhuận']);

        $user = User::factory()->create(['is_active' => true]);
        $this->actingAs($user);

        $this->get(route('reports.profit'))->assertForbidden();
    }
}
