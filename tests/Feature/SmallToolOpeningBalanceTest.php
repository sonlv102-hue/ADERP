<?php

namespace Tests\Feature;

use App\Enums\SmallToolStatus;
use App\Models\AccountCode;
use App\Models\AccountingPeriod;
use App\Models\SmallTool;
use App\Models\SmallToolCategory;
use App\Models\User;
use App\Services\SmallToolAllocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * TC1: Số dư đầu kỳ CCDC dương → chỉ sinh lịch cho các kỳ còn lại, JE ghi đúng giá trị còn lại
 * TC2: Làm tròn kỳ cuối vẫn đúng sau khi có số dư đầu kỳ (không tính lại từ đầu)
 * TC3: Tạm dừng → không sinh phân bổ kỳ đó, không đổi remaining/periods_allocated
 * TC4: Tiếp tục → phân bổ lại đúng từ kỳ chưa posted
 * TC5: Tạm dừng sau khi kỳ hiện tại đã posted → chỉ hiệu lực từ kỳ sau, không đụng JE đã posted
 * TC6: Regression — checkPeriodNotClosed dùng đúng cột year/month (không phải "period")
 * TC7: Đối soát GL cộng đúng dấu cho TK 2422 (không dùng abs)
 */
class SmallToolOpeningBalanceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private SmallToolCategory $category;
    private SmallToolAllocationService $svc;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['ccdc.view', 'ccdc.manage', 'ccdc.allocate', 'accounting.view', 'accounting.manage', 'reports.view'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
        $adminRole = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $adminRole->syncPermissions(Permission::all());

        $this->user = User::factory()->create(['is_active' => true]);
        $this->user->syncRoles([$adminRole]);
        $this->actingAs($this->user);

        $this->category = SmallToolCategory::create(['code' => 'DC', 'name' => 'Dụng cụ']);
        $this->svc       = app(SmallToolAllocationService::class);

        foreach ([
            ['code' => '2422', 'name' => 'CCDC chờ phân bổ', 'type' => 'asset',   'normal_balance' => 'debit',  'is_detail' => true],
            ['code' => '6422', 'name' => 'Chi phí quản lý',  'type' => 'expense', 'normal_balance' => 'debit',  'is_detail' => true],
            ['code' => '4111', 'name' => 'Vốn đầu tư CSH',   'type' => 'equity',  'normal_balance' => 'credit', 'is_detail' => true],
        ] as $a) {
            AccountCode::firstOrCreate(['code' => $a['code']], $a);
        }
    }

    private function storeOpeningBalance(array $overrides = []): SmallTool
    {
        $period = now()->format('Y-m');
        $data = array_merge([
            'name'                   => 'CCDC đầu kỳ',
            'category_id'            => $this->category->id,
            'unit'                   => 'cái',
            'quantity'               => 1,
            'original_cost'          => 1_200_000,
            'allocation_periods'     => 6,
            'periods_elapsed'        => 2,
            'remaining_amount'       => 800_000,
            'opening_balance_period' => $period,
            'pending_account_code'   => '2422',
            'expense_account_code'   => '6422',
        ], $overrides);

        $resp = $this->post(route('accounting.small-tools.opening-balance.store'), $data);
        $resp->assertSessionDoesntHaveErrors();

        return SmallTool::where('is_opening_balance', true)->latest('id')->firstOrFail();
    }

    // ── TC1 ─────────────────────────────────────────────────────────────

    public function test_positive_opening_balance_creates_remaining_periods_and_journal(): void
    {
        $tool = $this->storeOpeningBalance();

        $this->assertTrue($tool->is_opening_balance);
        $this->assertEquals(2, $tool->periods_allocated);
        $this->assertEquals(400_000, (float) $tool->total_allocated);
        $this->assertEquals(800_000, $tool->totalRemaining);

        $allocs = $tool->allocations()->orderBy('period')->get();
        $this->assertCount(4, $allocs); // 6 - 2 đã qua = 4 kỳ còn lại
        $this->assertEquals(800_000, (int) $allocs->sum('amount'));

        $this->assertNotNull($tool->acquisition_journal_entry_id);
        $je = $tool->acquisitionJournalEntry;
        $this->assertEquals('posted', $je->status);
        $this->assertTrue((bool) $je->exclude_from_period_movement);

        $line2422 = $je->lines->firstWhere('account_code', '2422');
        $this->assertEquals(800_000, (float) $line2422->debit);
        $line4111 = $je->lines->firstWhere('account_code', '4111');
        $this->assertEquals(800_000, (float) $line4111->credit);
    }

    // ── TC2 ─────────────────────────────────────────────────────────────

    public function test_rounding_after_opening_balance_sums_to_original_cost(): void
    {
        $tool = $this->storeOpeningBalance([
            'original_cost'    => 1_000_001,
            'allocation_periods' => 7,
            'periods_elapsed'  => 3,
            'remaining_amount' => 1_000_001 - 3 * (int) floor(1_000_001 / 7),
        ]);

        $sumRemaining = (int) $tool->allocations()->sum('amount');
        $this->assertEquals((int) $tool->total_allocated + $sumRemaining, (int) $tool->original_cost);
    }

    // ── TC3 ─────────────────────────────────────────────────────────────

    public function test_pause_blocks_allocation_for_current_period_without_losing_balance(): void
    {
        $tool = $this->storeOpeningBalance();
        $remainingBefore = $tool->totalRemaining;
        $periodsBefore   = $tool->periods_allocated;

        $this->post(route('accounting.small-tools.allocation.pause', $tool->id), ['reason' => 'CCDC không sử dụng'])
            ->assertSessionDoesntHaveErrors();

        $tool->refresh();
        $this->assertEquals('paused', $tool->allocation_status);
        $this->assertEquals(now()->format('Y-m'), $tool->pause_effective_period);

        $result = $this->svc->runPeriod(now()->format('Y-m'), false);
        $this->assertEmpty($result['processed']);

        $tool->refresh();
        $this->assertEquals($remainingBefore, $tool->totalRemaining);
        $this->assertEquals($periodsBefore, $tool->periods_allocated);

        $alloc = $tool->allocations()->where('period', now()->format('Y-m'))->first();
        $this->assertEquals('pending', $alloc->status);
    }

    // ── TC4 ─────────────────────────────────────────────────────────────

    public function test_resume_allocates_correctly_from_next_pending_period(): void
    {
        $tool = $this->storeOpeningBalance();
        $this->svc->pause($tool, 'Tạm dừng test');
        $tool->refresh();

        $this->post(route('accounting.small-tools.allocation.resume', $tool->id))
            ->assertSessionDoesntHaveErrors();

        $tool->refresh();
        $this->assertEquals('active', $tool->allocation_status);

        $result = $this->svc->runPeriod(now()->format('Y-m'), false);
        $this->assertCount(1, $result['processed']);

        $tool->refresh();
        $this->assertEquals(3, $tool->periods_allocated); // 2 elapsed + 1 kỳ vừa chạy
    }

    // ── TC5 ─────────────────────────────────────────────────────────────

    public function test_pause_after_current_period_posted_is_effective_next_period_only(): void
    {
        $tool = $this->storeOpeningBalance();
        $this->svc->runPeriod(now()->format('Y-m'), false); // post kỳ hiện tại trước
        $tool->refresh();
        $postedAlloc = $tool->allocations()->where('period', now()->format('Y-m'))->first();
        $this->assertEquals('posted', $postedAlloc->status);

        $this->svc->pause($tool, 'Tạm dừng sau khi đã post');
        $tool->refresh();

        $this->assertEquals(now()->addMonth()->format('Y-m'), $tool->pause_effective_period);

        // JE/alloc của kỳ hiện tại không bị đụng
        $postedAlloc->refresh();
        $this->assertEquals('posted', $postedAlloc->status);
        $this->assertNotNull($postedAlloc->journal_entry_id);
    }

    // ── TC6 (regression) ────────────────────────────────────────────────

    public function test_check_period_not_closed_uses_year_month_columns(): void
    {
        $period = now()->format('Y-m');
        AccountingPeriod::create(['year' => now()->year, 'month' => now()->month, 'status' => 'closed']);

        $tool = $this->storeOpeningBalance(['opening_balance_period' => now()->subMonth()->format('Y-m')]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/đã khóa/');
        $this->svc->runPeriod($period, false);
    }

    // ── TC7 ─────────────────────────────────────────────────────────────

    public function test_gl_reconcile_sums_signed_for_2422(): void
    {
        $tool = $this->storeOpeningBalance();

        $resp = $this->get(route('accounting.small-tools.reports.gl-reconcile'));
        $resp->assertOk();
        $reconcile = $resp->viewData('page')['props']['reconcile'];

        $this->assertEqualsWithDelta(800_000, $reconcile['gl_2422'], 0.01);
        $this->assertEqualsWithDelta(800_000, $reconcile['allocating_remaining'], 0.01);
        $this->assertEqualsWithDelta(0, $reconcile['diff_2422'], 0.01);
    }
}
