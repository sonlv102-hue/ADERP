<?php

namespace Tests\Feature\Warehouse;

use App\Enums\StockExitStatus;
use App\Models\AccountingPeriod;
use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\Project;
use App\Models\ProjectWipEntry;
use App\Models\StockExit;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * TC1: Admin sửa ngày phiếu confirmed (không JE/WIP) → OK
 * TC2: Admin sửa ngày phiếu confirmed có JE posted + WIP dự án → đồng bộ cả 3 bảng
 * TC3: User không phải admin bị chặn (403)
 * TC4: Phiếu đã hủy không cho sửa ngày
 * TC5: Kỳ kế toán đã khóa (ngày mới) → chặn, không có gì bị thay đổi
 * TC6: JE đã voided → chặn, yêu cầu kiểm tra thủ công
 */
class StockExitEditDateTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $nonAdmin;
    private Warehouse $warehouse;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $adminRole     = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $warehouseRole = Role::firstOrCreate(['name' => 'warehouse', 'guard_name' => 'web']);
        $viewPerm      = Permission::firstOrCreate(['name' => 'warehouse.view', 'guard_name' => 'web']);
        $warehouseRole->givePermissionTo($viewPerm);

        $this->admin = User::firstOrCreate(
            ['email' => 'admin-editdate@test.local'],
            ['name' => 'Admin', 'password' => bcrypt('pass'), 'is_active' => true]
        );
        $this->admin->syncRoles([$adminRole]);

        // Có quyền warehouse.view (qua middleware group) nhưng KHÔNG phải admin —
        // dùng để xác nhận middleware role:admin chặn riêng route edit-date.
        $this->nonAdmin = User::firstOrCreate(
            ['email' => 'warehouse-editdate@test.local'],
            ['name' => 'Warehouse Staff', 'password' => bcrypt('pass'), 'is_active' => true]
        );
        $this->nonAdmin->syncRoles([$warehouseRole]);

        $this->warehouse = Warehouse::firstOrCreate(
            ['code' => 'KHO-EDITDATE'],
            ['name' => 'Kho EditDate Test', 'address' => 'Test', 'manager_id' => null]
        );

        $customer = Customer::firstOrCreate(
            ['code' => 'KH-EDITDATE'],
            ['name' => 'KH EditDate Test', 'phone' => '0900000098']
        );

        $this->project = Project::create([
            'code' => 'DA-EDITDATE', 'name' => 'Dự án EditDate Test',
            'status' => 'in_progress', 'customer_id' => $customer->id,
            'created_by' => $this->admin->id,
        ]);
    }

    private function makeExit(array $overrides = []): StockExit
    {
        return StockExit::create(array_merge([
            'code'         => 'XK-ED-' . uniqid(),
            'warehouse_id' => $this->warehouse->id,
            'status'       => StockExitStatus::Confirmed,
            'exit_date'    => '2026-06-01',
            'created_by'   => $this->admin->id,
        ], $overrides));
    }

    public function test_tc1_admin_edits_date_of_simple_confirmed_exit(): void
    {
        $this->actingAs($this->admin);
        Gate::before(fn ($u, $a) => true);

        $exit = $this->makeExit();

        $this->post(route('warehouse.stock-exits.edit-date', $exit), [
            'new_date' => '2026-06-15',
            'reason'   => 'Sửa lại đúng ngày giao hàng thực tế',
        ])->assertRedirect();

        $exit->refresh();
        $this->assertEquals('2026-06-15', $exit->exit_date->format('Y-m-d'));
    }

    public function test_tc2_admin_edits_date_syncs_journal_and_wip(): void
    {
        $this->actingAs($this->admin);
        Gate::before(fn ($u, $a) => true);

        $exit = $this->makeExit(['project_id' => $this->project->id]);

        $je = JournalEntry::create([
            'code'           => 'BT-ED-' . uniqid(),
            'entry_date'     => '2026-06-01',
            'description'    => 'Test JE',
            'status'         => 'posted',
            'is_auto'        => true,
            'reference_type' => 'stock_exit',
            'reference_id'   => $exit->id,
            'created_by'     => $this->admin->id,
            'posted_at'      => now(),
        ]);

        $wip = ProjectWipEntry::create([
            'project_id'  => $this->project->id,
            'source_type' => StockExit::class,
            'source_id'   => $exit->id,
            'cost_type'   => 'material',
            'amount'      => 500_000,
            'description' => 'Test WIP',
            'entry_date'  => '2026-06-01',
            'status'      => 'active',
            'journal_entry_id' => $je->id,
            'created_by'  => $this->admin->id,
        ]);

        $this->post(route('warehouse.stock-exits.edit-date', $exit), [
            'new_date' => '2026-07-05',
            'reason'   => 'Đồng bộ ngày kế toán',
        ])->assertRedirect();

        $exit->refresh();
        $je->refresh();
        $wip->refresh();

        $this->assertEquals('2026-07-05', $exit->exit_date->format('Y-m-d'));
        $this->assertEquals('2026-07-05', $je->entry_date->format('Y-m-d'));
        $this->assertEquals('2026-07', $je->fiscal_period);
        $this->assertTrue($je->edited_by_user);
        $this->assertNotEmpty($je->edit_reason);
        $this->assertEquals('2026-07-05', $wip->entry_date->format('Y-m-d'));
    }

    public function test_tc3_non_admin_forbidden(): void
    {
        $this->actingAs($this->nonAdmin);

        $exit = $this->makeExit();

        $this->post(route('warehouse.stock-exits.edit-date', $exit), [
            'new_date' => '2026-06-15',
            'reason'   => 'Test',
        ])->assertForbidden();

        $exit->refresh();
        $this->assertEquals('2026-06-01', $exit->exit_date->format('Y-m-d'));
    }

    public function test_tc4_cancelled_exit_cannot_be_edited(): void
    {
        $this->actingAs($this->admin);
        Gate::before(fn ($u, $a) => true);

        $exit = $this->makeExit(['status' => StockExitStatus::Cancelled]);

        $this->post(route('warehouse.stock-exits.edit-date', $exit), [
            'new_date' => '2026-06-15',
            'reason'   => 'Test',
        ])->assertRedirect();

        $exit->refresh();
        $this->assertEquals('2026-06-01', $exit->exit_date->format('Y-m-d'));
    }

    public function test_tc5_locked_period_blocks_edit(): void
    {
        $this->actingAs($this->admin);
        Gate::before(fn ($u, $a) => true);

        $exit = $this->makeExit();
        AccountingPeriod::create(['year' => 2026, 'month' => 7, 'status' => 'locked']);

        $this->post(route('warehouse.stock-exits.edit-date', $exit), [
            'new_date' => '2026-07-10',
            'reason'   => 'Test',
        ])->assertRedirect();

        $exit->refresh();
        $this->assertEquals('2026-06-01', $exit->exit_date->format('Y-m-d'));
    }

    public function test_tc6_voided_journal_blocks_edit(): void
    {
        $this->actingAs($this->admin);
        Gate::before(fn ($u, $a) => true);

        $exit = $this->makeExit();

        JournalEntry::create([
            'code'           => 'BT-ED-' . uniqid(),
            'entry_date'     => '2026-06-01',
            'description'    => 'Test JE voided',
            'status'         => 'voided',
            'is_auto'        => true,
            'reference_type' => 'stock_exit',
            'reference_id'   => $exit->id,
            'created_by'     => $this->admin->id,
        ]);

        $this->post(route('warehouse.stock-exits.edit-date', $exit), [
            'new_date' => '2026-06-15',
            'reason'   => 'Test',
        ])->assertRedirect();

        $exit->refresh();
        $this->assertEquals('2026-06-01', $exit->exit_date->format('Y-m-d'));
    }
}
