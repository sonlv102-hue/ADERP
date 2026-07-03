<?php

namespace Tests\Feature;

use App\Models\JournalEntry;
use App\Models\SmallTool;
use App\Models\SmallToolAllocation;
use App\Models\SmallToolCategory;
use App\Models\SmallToolDisposal;
use App\Models\SmallToolIssue;
use App\Models\SmallToolIssueItem;
use App\Models\SmallToolReceipt;
use App\Models\SmallToolReceiptItem;
use App\Models\SmallToolTransfer;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * TC1: Xóa thành công khi CCDC sạch (không receipt/issue/transfer/disposal, allocation toàn pending)
 * TC2: Chặn khi có phiếu nhập kho (receipt item) liên kết
 * TC3: Chặn khi có lịch sử điều chuyển
 * TC4: Chặn khi có phiếu ghi giảm/thanh lý
 * TC5: Chặn khi có phiếu xuất dùng (issue item) liên kết
 * TC6: Chặn khi có kỳ phân bổ đã posted (và reversed)
 * TC7: Chặn khi bút toán tăng CCDC tồn tại (bất kỳ status nào — kể cả đã reversed/voided)
 * TC8: Không có quyền ccdc.delete -> 403
 * TC9: Activity log ghi đúng mã, lý do, người xóa
 * TC10: reason là bắt buộc (validation)
 */
class SmallToolDestroyTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private SmallToolCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['ccdc.view', 'ccdc.manage', 'ccdc.delete', 'accounting.view'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
        $adminRole = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $adminRole->syncPermissions(Permission::all());

        $this->user = User::factory()->create(['is_active' => true]);
        $this->user->syncRoles([$adminRole]);
        $this->actingAs($this->user);

        $this->category = SmallToolCategory::create(['code' => 'DC', 'name' => 'Dụng cụ']);
    }

    private function makeTool(array $overrides = []): SmallTool
    {
        return SmallTool::create(array_merge([
            'code'           => SmallTool::generateCode(),
            'name'           => 'Máy đếm tiền test',
            'category_id'    => $this->category->id,
            'original_cost'  => 5453704,
            'total_cost'     => 5453704,
            'status'         => 'allocating',
            'allocation_status' => 'paused',
            'created_by'     => $this->user->id,
        ], $overrides));
    }

    public function test_destroy_succeeds_when_clean(): void
    {
        $tool = $this->makeTool();
        SmallToolAllocation::create([
            'small_tool_id' => $tool->id,
            'period'        => '2026-07',
            'period_start'  => '2026-07-01',
            'period_end'    => '2026-07-31',
            'amount'        => 100000,
            'status'        => 'pending',
        ]);

        $response = $this->delete(route('accounting.small-tools.destroy', $tool->id), [
            'reason' => 'Xóa CCDC tạo trùng',
        ]);

        $response->assertRedirect(route('accounting.small-tools.index'));
        $this->assertDatabaseMissing('small_tools', ['id' => $tool->id]);
        $this->assertDatabaseMissing('small_tool_allocations', ['small_tool_id' => $tool->id]);
    }

    public function test_destroy_blocked_by_receipt_item(): void
    {
        $tool = $this->makeTool();
        $warehouse = Warehouse::create(['name' => 'Kho test']);
        $receipt = SmallToolReceipt::create([
            'code'         => 'CCNK-0001',
            'receipt_date' => '2026-07-01',
            'warehouse_id' => $warehouse->id,
            'status'       => 'draft',
        ]);
        SmallToolReceiptItem::create([
            'small_tool_receipt_id' => $receipt->id,
            'small_tool_id'         => $tool->id,
            'quantity'              => 1,
        ]);

        $response = $this->from(route('accounting.small-tools.show', $tool->id))
            ->delete(route('accounting.small-tools.destroy', $tool->id), ['reason' => 'test']);

        $response->assertRedirect(route('accounting.small-tools.show', $tool->id));
        $this->assertDatabaseHas('small_tools', ['id' => $tool->id]);
    }

    public function test_destroy_blocked_by_transfer(): void
    {
        $tool = $this->makeTool();
        SmallToolTransfer::create([
            'code'          => 'CCCT-0001',
            'small_tool_id' => $tool->id,
            'transfer_date' => '2026-07-01',
        ]);

        $response = $this->delete(route('accounting.small-tools.destroy', $tool->id), ['reason' => 'test']);

        $response->assertSessionHas('error');
        $this->assertDatabaseHas('small_tools', ['id' => $tool->id]);
    }

    public function test_destroy_blocked_by_disposal(): void
    {
        $tool = $this->makeTool();
        SmallToolDisposal::create([
            'code'          => 'CCXL-0001',
            'small_tool_id' => $tool->id,
            'disposal_type' => 'broken',
            'disposal_date' => '2026-07-01',
            'reason'        => 'hỏng',
        ]);

        $response = $this->delete(route('accounting.small-tools.destroy', $tool->id), ['reason' => 'test']);

        $response->assertSessionHas('error');
        $this->assertDatabaseHas('small_tools', ['id' => $tool->id]);
    }

    public function test_destroy_blocked_by_issue_item(): void
    {
        $tool = $this->makeTool();
        $issue = SmallToolIssue::create([
            'code'       => 'CCXD-0001',
            'issue_date' => '2026-07-01',
            'status'     => 'draft',
        ]);
        SmallToolIssueItem::create([
            'small_tool_issue_id' => $issue->id,
            'small_tool_id'       => $tool->id,
            'quantity'            => 1,
        ]);

        $response = $this->delete(route('accounting.small-tools.destroy', $tool->id), ['reason' => 'test']);

        $response->assertSessionHas('error');
        $this->assertDatabaseHas('small_tools', ['id' => $tool->id]);
    }

    public function test_destroy_blocked_by_posted_allocation(): void
    {
        $tool = $this->makeTool();
        SmallToolAllocation::create([
            'small_tool_id' => $tool->id,
            'period'        => '2026-07',
            'period_start'  => '2026-07-01',
            'period_end'    => '2026-07-31',
            'amount'        => 100000,
            'status'        => 'posted',
        ]);

        $response = $this->delete(route('accounting.small-tools.destroy', $tool->id), ['reason' => 'test']);

        $response->assertSessionHas('error');
        $this->assertDatabaseHas('small_tools', ['id' => $tool->id]);
    }

    public function test_destroy_blocked_by_reversed_allocation(): void
    {
        $tool = $this->makeTool();
        SmallToolAllocation::create([
            'small_tool_id' => $tool->id,
            'period'        => '2026-07',
            'period_start'  => '2026-07-01',
            'period_end'    => '2026-07-31',
            'amount'        => 100000,
            'status'        => 'reversed',
        ]);

        $response = $this->delete(route('accounting.small-tools.destroy', $tool->id), ['reason' => 'test']);

        $response->assertSessionHas('error');
        $this->assertDatabaseHas('small_tools', ['id' => $tool->id]);
    }

    public function test_destroy_blocked_by_acquisition_journal_entry_regardless_of_status(): void
    {
        $je = JournalEntry::create([
            'code'       => 'BT-TEST-0001',
            'entry_date' => '2026-07-01',
            'description' => 'test',
            'status'     => 'reversed',
            'created_by' => $this->user->id,
        ]);
        $tool = $this->makeTool(['acquisition_journal_entry_id' => $je->id]);

        $response = $this->delete(route('accounting.small-tools.destroy', $tool->id), ['reason' => 'test']);

        $response->assertSessionHas('error');
        $this->assertDatabaseHas('small_tools', ['id' => $tool->id]);
    }

    public function test_destroy_blocked_by_issue_journal_entry(): void
    {
        $je = JournalEntry::create([
            'code'       => 'BT-TEST-0002',
            'entry_date' => '2026-07-01',
            'description' => 'test',
            'status'     => 'posted',
            'created_by' => $this->user->id,
        ]);
        $tool = $this->makeTool(['issue_journal_entry_id' => $je->id]);

        $response = $this->delete(route('accounting.small-tools.destroy', $tool->id), ['reason' => 'test']);

        $response->assertSessionHas('error');
        $this->assertDatabaseHas('small_tools', ['id' => $tool->id]);
    }

    public function test_destroy_requires_reason(): void
    {
        $tool = $this->makeTool();

        $response = $this->delete(route('accounting.small-tools.destroy', $tool->id), []);

        $response->assertSessionHasErrors('reason');
        $this->assertDatabaseHas('small_tools', ['id' => $tool->id]);
    }

    public function test_destroy_forbidden_without_permission(): void
    {
        $tool = $this->makeTool();
        $this->user->syncRoles([]);
        $this->user->syncPermissions(['ccdc.view', 'ccdc.manage', 'accounting.view']);

        $response = $this->delete(route('accounting.small-tools.destroy', $tool->id), ['reason' => 'test']);

        $response->assertForbidden();
        $this->assertDatabaseHas('small_tools', ['id' => $tool->id]);
    }

    public function test_destroy_logs_activity(): void
    {
        $tool = $this->makeTool(['code' => 'CCDC-9999']);

        $this->delete(route('accounting.small-tools.destroy', $tool->id), [
            'reason' => 'Xóa CCDC tạo trùng',
        ]);

        $log = Activity::where('log_name', 'small_tool')->latest()->first();
        $this->assertNotNull($log);
        $this->assertSame('CCDC-9999', $log->properties['code']);
        $this->assertSame('Xóa CCDC tạo trùng', $log->properties['reason']);
        $this->assertSame($this->user->id, $log->causer_id);
    }
}
