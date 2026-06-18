<?php

namespace Tests\Feature;

use App\Enums\SmallToolStatus;
use App\Models\AccountCode;
use App\Models\AccountingSetting;
use App\Models\SmallTool;
use App\Models\SmallToolAllocation;
use App\Models\SmallToolCategory;
use App\Models\User;
use App\Services\SmallToolAllocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SmallToolAllocationTest extends TestCase
{
    use RefreshDatabase;

    private User         $user;
    private SmallToolCategory $category;
    private SmallToolAllocationService $svc;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user     = User::factory()->create(['is_active' => true]);
        $this->actingAs($this->user);
        $this->category = SmallToolCategory::create(['code' => 'DC', 'name' => 'Dụng cụ']);
        $this->svc      = app(SmallToolAllocationService::class);

        $this->seedAccounts();
    }

    private function seedAccounts(): void
    {
        $accounts = [
            ['code' => '2422', 'name' => 'CCDC chờ phân bổ', 'type' => 'asset',   'normal_balance' => 'debit', 'is_detail' => true, 'parent_code' => null],
            ['code' => '6422', 'name' => 'Chi phí quản lý',  'type' => 'expense', 'normal_balance' => 'debit', 'is_detail' => true, 'parent_code' => null],
        ];
        foreach ($accounts as $a) {
            AccountCode::firstOrCreate(['code' => $a['code']], $a);
        }

        $settings = [
            ['key' => 'small_tool_expense_account', 'value' => '6422'],
            ['key' => 'small_tool_pending_account', 'value' => '2422'],
        ];
        foreach ($settings as $s) {
            AccountingSetting::firstOrCreate(['key' => $s['key']], array_merge($s, ['label' => $s['key']]));
        }
    }

    private function makeTool(int $periods, int $originalCost = 1_200_000, string $startPeriod = null): SmallTool
    {
        $start = $startPeriod ?? now()->format('Y-m');
        return SmallTool::create([
            'code'                  => SmallTool::generateCode(),
            'name'                  => 'CCDC test',
            'category_id'           => $this->category->id,
            'unit'                  => 'Cái',
            'quantity'              => 1,
            'original_cost'         => $originalCost,
            'vat_amount'            => 0,
            'total_cost'            => $originalCost,
            'acquisition_type'      => 'stock',
            'recognition_method'    => 'allocation',
            'allocation_periods'    => $periods,
            'allocation_start_date' => $start,
            'payment_type'          => 'credit',
            'stock_account_code'    => '1531',
            'pending_account_code'  => '2422',
            'expense_account_code'  => '6422',
            'payable_account_code'  => '3311',
            'periods_allocated'     => 0,
            'total_allocated'       => 0,
            'status'                => SmallToolStatus::Allocating,
            'created_by'            => $this->user->id,
        ]);
    }

    public function test_build_schedule_creates_correct_number_of_pending_rows(): void
    {
        $tool = $this->makeTool(6, 1_200_000);
        $this->svc->buildSchedule($tool);

        $allocs = $tool->allocations()->orderBy('period')->get();
        $this->assertCount(6, $allocs);
        $this->assertEquals('pending', $allocs->first()->status);
    }

    public function test_build_schedule_amounts_sum_to_original_cost(): void
    {
        $tool = $this->makeTool(6, 1_000_001); // Odd number to test rounding
        $this->svc->buildSchedule($tool);

        $total = (int) $tool->allocations()->sum('amount');
        $this->assertEquals((int) $tool->original_cost, $total);
    }

    public function test_build_schedule_last_period_handles_rounding(): void
    {
        $tool   = $this->makeTool(3, 1_000_000);
        $this->svc->buildSchedule($tool);

        $allocs  = $tool->allocations()->orderBy('period')->get();
        $regular = (int) floor(1_000_000 / 3);
        $last    = 1_000_000 - $regular * 2;

        $this->assertEquals($regular, (int) $allocs[0]->amount);
        $this->assertEquals($regular, (int) $allocs[1]->amount);
        $this->assertEquals($last,    (int) $allocs[2]->amount);
    }

    public function test_preview_period_returns_pending_allocs_for_period(): void
    {
        $period = now()->format('Y-m');
        $tool   = $this->makeTool(3, 900_000, $period);
        $this->svc->buildSchedule($tool);

        $preview = $this->svc->previewPeriod($period);
        $this->assertNotEmpty($preview);
        $this->assertEquals($tool->code, $preview[0]['tool_code']);
    }

    public function test_run_period_posts_allocations_and_creates_journal(): void
    {
        $period = now()->format('Y-m');
        $tool   = $this->makeTool(3, 900_000, $period);
        $this->svc->buildSchedule($tool);

        $result = $this->svc->runPeriod($period, false);

        $this->assertCount(1, $result['processed']);
        $this->assertEquals(0, $result['skipped']);

        $alloc = $tool->allocations()->where('period', $period)->first();
        $this->assertEquals('posted', $alloc->status);
        $this->assertNotNull($alloc->journal_entry_id);

        $tool->refresh();
        $this->assertEquals(1, $tool->periods_allocated);
        $this->assertEquals(300_000, $tool->total_allocated);
    }

    public function test_run_period_dry_run_makes_no_changes(): void
    {
        $period = now()->format('Y-m');
        $tool   = $this->makeTool(3, 900_000, $period);
        $this->svc->buildSchedule($tool);

        $this->svc->runPeriod($period, true);

        $alloc = $tool->allocations()->where('period', $period)->first();
        $this->assertEquals('pending', $alloc->status);
        $tool->refresh();
        $this->assertEquals(0, $tool->periods_allocated);
    }

    public function test_reverse_allocation_decrements_tool_counters(): void
    {
        $period = now()->format('Y-m');
        $tool   = $this->makeTool(3, 900_000, $period);
        $this->svc->buildSchedule($tool);
        $this->svc->runPeriod($period, false);

        $alloc = $tool->allocations()->where('period', $period)->first();
        $this->svc->reverseAllocation($alloc);

        $alloc->refresh();
        $this->assertEquals('reversed', $alloc->status);

        $tool->refresh();
        $this->assertEquals(0, $tool->periods_allocated);
        $this->assertEquals(0, $tool->total_allocated);
    }

    public function test_run_period_fully_allocated_updates_status(): void
    {
        $period = now()->format('Y-m');
        $tool   = $this->makeTool(1, 500_000, $period);
        $this->svc->buildSchedule($tool);
        $this->svc->runPeriod($period, false);

        $tool->refresh();
        $this->assertEquals(SmallToolStatus::FullyAllocated, $tool->status);
    }

    public function test_build_schedule_does_not_duplicate_on_second_call(): void
    {
        $period = now()->format('Y-m');
        $tool   = $this->makeTool(3, 900_000, $period);
        $this->svc->buildSchedule($tool);
        $this->svc->buildSchedule($tool); // second call

        $this->assertCount(3, $tool->allocations);
    }
}
