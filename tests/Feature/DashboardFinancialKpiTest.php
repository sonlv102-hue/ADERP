<?php

namespace Tests\Feature;

use App\Models\AccountCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * KPI1: user có accounting.view → financialKpi có trong response
 * KPI2: user không có quyền → financialKpi = null
 * KPI3: Doanh thu = net credit của TK 511x (loại opening balance và reversed)
 * KPI4: Giá vốn = net debit của TK 632x
 * KPI5: Lợi nhuận gộp = doanh thu - giá vốn
 * KPI6: JE exclude_from_period_movement=true bị loại khỏi phép tính
 */
class DashboardFinancialKpiTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUser;
    private User $limitedUser;

    protected function setUp(): void
    {
        parent::setUp();

        \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'accounting.view']);

        $this->adminUser = User::factory()->create(['is_active' => true]);
        $this->adminUser->givePermissionTo('accounting.view');

        $this->limitedUser = User::factory()->create(['is_active' => true]);

        // Seed account codes cần thiết
        foreach ([
            ['code' => '5111', 'name' => 'Doanh thu bán hàng hóa',      'type' => 'revenue', 'normal_balance' => 'credit', 'level' => 4, 'is_detail' => true],
            ['code' => '5113', 'name' => 'Doanh thu cung cấp dịch vụ',   'type' => 'revenue', 'normal_balance' => 'credit', 'level' => 4, 'is_detail' => true],
            ['code' => '6321', 'name' => 'Giá vốn hàng hóa',             'type' => 'expense', 'normal_balance' => 'debit',  'level' => 4, 'is_detail' => true],
        ] as $ac) {
            AccountCode::firstOrCreate(['code' => $ac['code']], array_merge($ac, ['is_active' => true]));
        }
    }

    private static int $jeSeq = 0;

    private function insertJe(array $attrs, array $lines): void
    {
        self::$jeSeq++;
        $jeId = DB::table('journal_entries')->insertGetId(array_merge([
            'code'                        => 'TEST-' . str_pad(self::$jeSeq, 4, '0', STR_PAD_LEFT),
            'status'                      => 'posted',
            'exclude_from_period_movement' => false,
            'created_by'                  => $this->adminUser->id,
            'created_at'                  => now(),
            'updated_at'                  => now(),
        ], $attrs));

        foreach ($lines as $line) {
            DB::table('journal_entry_lines')->insert(array_merge([
                'journal_entry_id' => $jeId,
                'debit'            => 0,
                'credit'           => 0,
                'created_at'       => now(),
                'updated_at'       => now(),
            ], $line));
        }
    }

    // KPI1: user có quyền → financialKpi not null
    public function test_KPI1_admin_user_receives_financial_kpi(): void
    {
        $this->actingAs($this->adminUser);
        $response = $this->get(route('dashboard'));
        $response->assertOk();
        $response->assertInertia(fn ($page) =>
            $page->has('financialKpi')
                 ->where('financialKpi', fn ($v) => $v !== null)
        );
    }

    // KPI2: user không có quyền → financialKpi = null
    public function test_KPI2_limited_user_receives_null_financial_kpi(): void
    {
        $this->actingAs($this->limitedUser);
        $response = $this->get(route('dashboard'));
        $response->assertOk();
        $response->assertInertia(fn ($page) =>
            $page->where('financialKpi', null)
        );
    }

    // KPI3 + KPI4 + KPI5: doanh thu / giá vốn / lợi nhuận gộp tính đúng từ JE lines
    public function test_KPI3_revenue_cogs_gross_profit_computed_from_je_lines(): void
    {
        $this->actingAs($this->adminUser);

        $thisMonth = now()->startOfMonth()->toDateString();

        // JE doanh thu: Cr 5111 = 10,000,000
        $this->insertJe(
            ['description' => 'Doanh thu test', 'entry_date' => $thisMonth, 'reference_type' => 'invoice', 'reference_id' => 999],
            [['account_code' => '5111', 'debit' => 0, 'credit' => 10_000_000, 'description' => 'test']]
        );

        // JE giá vốn: Dr 6321 = 6,000,000
        $this->insertJe(
            ['description' => 'COGS test', 'entry_date' => $thisMonth, 'reference_type' => 'stock_exit', 'reference_id' => 999],
            [['account_code' => '6321', 'debit' => 6_000_000, 'credit' => 0, 'description' => 'test']]
        );

        $response = $this->get(route('dashboard'));
        $response->assertOk();

        $response->assertInertia(function ($page) {
            $kpi = $page->toArray()['props']['financialKpi'];
            $this->assertEquals(10_000_000, $kpi['current']['revenue'],      'Doanh thu phải = 10,000,000');
            $this->assertEquals(6_000_000,  $kpi['current']['cogs'],          'Giá vốn phải = 6,000,000');
            $this->assertEquals(4_000_000,  $kpi['current']['gross_profit'],  'Lợi nhuận gộp = 4,000,000');
            return true;
        });
    }

    // KPI6: JE với exclude_from_period_movement=true không được tính vào KPI
    public function test_KPI6_opening_balance_je_excluded_from_financial_kpi(): void
    {
        $this->actingAs($this->adminUser);

        $thisMonth = now()->startOfMonth()->toDateString();

        // JE doanh thu bình thường (phải tính)
        $this->insertJe(
            ['description' => 'Doanh thu thực', 'entry_date' => $thisMonth, 'exclude_from_period_movement' => false,
             'reference_type' => 'invoice', 'reference_id' => 1],
            [['account_code' => '5111', 'debit' => 0, 'credit' => 5_000_000, 'description' => 'normal']]
        );

        // JE đầu kỳ (KHÔNG được tính)
        $this->insertJe(
            ['description' => 'Số dư đầu kỳ', 'entry_date' => $thisMonth, 'exclude_from_period_movement' => true,
             'reference_type' => 'opening_balance', 'reference_id' => 0],
            [['account_code' => '5111', 'debit' => 0, 'credit' => 999_000_000, 'description' => 'opening']]
        );

        $response = $this->get(route('dashboard'));
        $response->assertOk();

        $response->assertInertia(function ($page) {
            $kpi = $page->toArray()['props']['financialKpi'];
            $this->assertEquals(5_000_000, $kpi['current']['revenue'],
                'JE đầu kỳ (exclude_from_period_movement=true) không được tính vào doanh thu');
            return true;
        });
    }
}
