<?php

namespace Tests\Feature\Warehouse;

use App\Enums\StockExitStatus;
use App\Models\Customer;
use App\Models\Project;
use App\Models\ProjectWipEntry;
use App\Models\StockExit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * TC1: destroy() phải xóa WIP entries khi exit bị hard-delete
 * TC2: cancelExit() Draft path phải xóa WIP entries
 * TC3: cancelExit() Confirmed path phải xóa WIP entries (regression)
 * TC4: wip-audit command phát hiện orphan W1
 */
class StockExitWipCleanupTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Warehouse $warehouse;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::firstOrCreate(
            ['email' => 'admin@test.local'],
            ['name' => 'Admin', 'password' => bcrypt('pass'), 'is_active' => true]
        );
        $this->actingAs($this->user);
        Gate::before(fn ($u, $a) => true);

        $this->warehouse = Warehouse::firstOrCreate(
            ['code' => 'KHO-WIP'],
            ['name' => 'Kho WIP Test', 'address' => 'Test', 'manager_id' => null]
        );

        $customer = Customer::firstOrCreate(
            ['code' => 'KH-WIP'],
            ['name' => 'KH WIP Test', 'phone' => '0900000099']
        );

        $this->project = Project::create([
            'code' => 'DA-WIP', 'name' => 'Dự án WIP Test',
            'status' => 'in_progress', 'customer_id' => $customer->id,
            'created_by' => $this->user->id,
        ]);
    }

    private function makeExit(string $status = 'confirmed'): StockExit
    {
        return StockExit::create([
            'code'           => 'XK-WIP-' . uniqid(),
            'warehouse_id'   => $this->warehouse->id,
            'project_id'     => $this->project->id,
            'status'         => StockExitStatus::from($status),
            'exit_date'      => now()->toDateString(),
            'total_quantity' => 0,
            'created_by'     => $this->user->id,
        ]);
    }

    private function makeWipEntry(StockExit $exit): ProjectWipEntry
    {
        return ProjectWipEntry::create([
            'project_id'  => $this->project->id,
            'source_type' => StockExit::class,
            'source_id'   => $exit->id,
            'cost_type'   => 'material',
            'amount'      => 944_444,
            'description' => 'Test WIP from stock exit',
            'entry_date'  => now()->toDateString(),
            'status'      => 'active',
            'created_by'  => $this->user->id,
        ]);
    }

    // TC1: destroy() xóa WIP entries
    public function test_tc1_destroy_exit_cleans_up_wip_entries(): void
    {
        $exit  = $this->makeExit('cancelled'); // destroy chỉ cho phép Draft hoặc Cancelled
        $entry = $this->makeWipEntry($exit);

        $this->assertDatabaseHas('project_wip_entries', ['id' => $entry->id]);

        $this->delete(route('warehouse.stock-exits.destroy', $exit))
             ->assertRedirect();

        $this->assertDatabaseMissing('stock_exits', ['id' => $exit->id]);
        $this->assertDatabaseMissing('project_wip_entries', ['id' => $entry->id]);
    }

    // TC2: cancelExit() Draft path xóa WIP entries
    public function test_tc2_cancel_draft_exit_cleans_up_wip_entries(): void
    {
        $exit  = $this->makeExit('draft');
        $entry = $this->makeWipEntry($exit);

        $this->assertDatabaseHas('project_wip_entries', ['id' => $entry->id]);

        app(StockService::class)->cancelExit($exit);

        $this->assertDatabaseMissing('project_wip_entries', ['id' => $entry->id]);
        $exit->refresh();
        $this->assertEquals(StockExitStatus::Cancelled, $exit->status);
    }

    // TC3: cancelExit() Confirmed path xóa WIP entries (regression kiểm tra logic hiện có)
    public function test_tc3_cancel_confirmed_exit_cleans_up_wip_entries(): void
    {
        $exit  = $this->makeExit('confirmed');
        $entry = $this->makeWipEntry($exit);

        $this->assertDatabaseHas('project_wip_entries', ['id' => $entry->id]);

        // cancelExit Confirmed path (không cần movements/serials vì ta tạo exit đơn giản)
        app(StockService::class)->cancelExit($exit);

        $this->assertDatabaseMissing('project_wip_entries', ['id' => $entry->id]);
        $exit->refresh();
        $this->assertEquals(StockExitStatus::Cancelled, $exit->status);
    }

    // TC4: wip-audit command phát hiện orphan W1
    public function test_tc4_wip_audit_detects_orphan_with_deleted_source(): void
    {
        // Tạo WIP entry với source_id không tồn tại (orphan)
        ProjectWipEntry::create([
            'project_id'  => $this->project->id,
            'source_type' => StockExit::class,
            'source_id'   => 99999, // không tồn tại
            'cost_type'   => 'material',
            'amount'      => 500_000,
            'description' => 'Orphan entry',
            'entry_date'  => now()->toDateString(),
            'status'      => 'active',
            'created_by'  => $this->user->id,
        ]);

        $exitCode = $this->artisan('projects:wip-audit', [
            '--project' => $this->project->id,
        ]);

        $exitCode->assertExitCode(1); // Có issues → exit 1
        $exitCode->expectsOutputToContain('W1');
    }
}
