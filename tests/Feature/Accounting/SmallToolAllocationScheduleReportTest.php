<?php

namespace Tests\Feature\Accounting;

use App\Models\SmallTool;
use App\Models\SmallToolAllocation;
use App\Models\SmallToolCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Regression: SmallToolReportController::allocationSchedule() từng trả về props
 * (period/allocations/total) không khớp với AllocationSchedule.vue (schedule/currentPeriod/filters),
 * khiến `v-for="tool in schedule"` nhận undefined → Vue crash → màn hình trắng production.
 */
class SmallToolAllocationScheduleReportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['is_active' => true]);
        $this->actingAs($this->user);
        Gate::before(fn ($u, $a) => true);
    }

    public function test_page_renders_schedule_grouped_by_tool_with_expected_shape(): void
    {
        $category = SmallToolCategory::create(['code' => 'DC', 'name' => 'Dụng cụ']);
        $tool = SmallTool::create([
            'code'                  => SmallTool::generateCode(),
            'name'                  => 'Máy khoan test',
            'category_id'           => $category->id,
            'unit'                  => 'Cái',
            'quantity'              => 1,
            'original_cost'         => 1_200_000,
            'vat_amount'            => 0,
            'total_cost'            => 1_200_000,
            'acquisition_type'      => 'stock',
            'recognition_method'    => 'allocation',
            'allocation_periods'    => 3,
            'allocation_start_date' => '2026-05-01',
            'status'                => 'allocating',
            'expense_account_code'  => '6422',
            'stock_account_code'    => '1531',
            'payable_account_code'  => '3311',
            'created_by'            => $this->user->id,
        ]);

        SmallToolAllocation::create([
            'small_tool_id'      => $tool->id,
            'period'             => '2026-05',
            'period_start'       => '2026-05-01',
            'period_end'         => '2026-05-31',
            'amount'             => 400_000,
            'accumulated_before' => 0,
            'remaining_after'    => 800_000,
            'debit_account'      => '6422',
            'credit_account'     => '2422',
            'status'             => 'posted',
        ]);

        $res = $this->get(route('accounting.small-tools.reports.allocation-schedule'));

        $res->assertOk();
        $res->assertInertia(fn ($page) => $page
            ->component('Accounting/SmallTools/Reports/AllocationSchedule')
            ->has('schedule', 1)
            ->has('schedule.0.allocations', 1)
            ->where('schedule.0.code', $tool->code)
            ->where('schedule.0.allocations.0.period', '2026-05')
            ->where('schedule.0.allocations.0.amount', 400000)
            ->where('schedule.0.allocations.0.accumulated', 400000)
            ->has('currentPeriod')
            ->has('filters')
        );
    }

    public function test_empty_state_when_no_tools_have_allocations(): void
    {
        $res = $this->get(route('accounting.small-tools.reports.allocation-schedule'));

        $res->assertOk();
        $res->assertInertia(fn ($page) => $page
            ->component('Accounting/SmallTools/Reports/AllocationSchedule')
            ->has('schedule', 0)
        );
    }

    // Không test filter `tool` (dùng ilike) vì DB test là SQLite (:memory:),
    // không hỗ trợ ilike — hạn chế hạ tầng test có sẵn từ trước, không thuộc phạm vi fix này.
}
