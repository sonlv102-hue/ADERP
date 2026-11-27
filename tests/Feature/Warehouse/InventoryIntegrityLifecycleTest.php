<?php

namespace Tests\Feature\Warehouse;

use App\Enums\StockEntryStatus;
use App\Enums\StockExitStatus;
use App\Models\AccountCode;
use App\Models\Customer;
use App\Models\InventoryBalance;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Project;
use App\Models\ProjectInventoryLot;
use App\Models\Role;
use App\Models\StockEntry;
use App\Models\StockEntryItem;
use App\Models\StockExit;
use App\Models\StockExitItem;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AvcoService;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

/**
 * INVENTORY_INTEGRITY_REMEDIATION_V1 — vòng đời nhập/xuất/hủy/thu hồi phải đối xứng.
 * Bất biến kiểm tra sau mỗi kịch bản: AVCO qty = Σ movement active − Σ lot dự án còn lại.
 */
class InventoryIntegrityLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private StockService $svc;
    private User $user;
    private Warehouse $wh;
    private Warehouse $wh2;
    private Supplier $supplier;
    private Product $product;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $this->svc = app(StockService::class);
        $this->user = User::factory()->create();
        $role = Role::firstOrCreate(['code' => 'admin'], ['name' => 'Admin', 'is_system' => true]);
        $this->user->roles()->sync([$role->id]);
        $this->actingAs($this->user);

        foreach ([
            ['code' => '1331', 'name' => 'VAT', 'type' => 'asset', 'normal_balance' => 'debit'],
            ['code' => '1561', 'name' => 'HH', 'type' => 'asset', 'normal_balance' => 'debit'],
            ['code' => '3311', 'name' => 'NCC', 'type' => 'liability', 'normal_balance' => 'credit'],
            ['code' => '154', 'name' => 'CPDD', 'type' => 'asset', 'normal_balance' => 'debit'],
            ['code' => '632', 'name' => 'GV', 'type' => 'expense', 'normal_balance' => 'debit'],
            ['code' => '6421', 'name' => 'BH', 'type' => 'expense', 'normal_balance' => 'debit'],
            ['code' => '6422', 'name' => 'QL', 'type' => 'expense', 'normal_balance' => 'debit'],
        ] as $c) {
            AccountCode::firstOrCreate(['code' => $c['code']], $c + ['is_detail' => true]);
        }

        $this->wh = Warehouse::create(['code' => 'WH-1', 'name' => 'Kho chung']);
        $this->wh2 = Warehouse::create(['code' => 'WH-2', 'name' => 'Kho dự án']);
        $this->supplier = Supplier::create(['code' => 'NCC-T', 'name' => 'NCC', 'payable_account_code' => '3311']);
        $cat = ProductCategory::create(['name' => 'Cat']);
        $this->product = Product::create([
            'code' => 'SP-T', 'name' => 'SP', 'category_id' => $cat->id, 'cost_price' => 110000, 'vat_percent' => 10,
        ]);
        $customer = Customer::create(['code' => 'KH-T', 'name' => 'KH']);
        $this->project = Project::create([
            'code' => 'DA-T', 'name' => 'DA', 'status' => 'in_progress',
            'customer_id' => $customer->id, 'created_by' => $this->user->id,
        ]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function entry(int $qty, float $unit, ?int $projectId = null, ?int $whId = null): StockEntry
    {
        $e = StockEntry::create([
            'code' => 'NK-' . uniqid(), 'warehouse_id' => $whId ?? $this->wh->id, 'supplier_id' => $this->supplier->id,
            'status' => StockEntryStatus::Draft, 'entry_date' => now()->toDateString(), 'created_by' => $this->user->id,
        ]);
        StockEntryItem::create([
            'stock_entry_id' => $e->id, 'product_id' => $this->product->id, 'quantity' => $qty,
            'unit_price' => $unit, 'unit_cost' => $unit, 'tax_rate' => 10, 'project_id' => $projectId,
        ]);
        $this->svc->confirmEntry($e);
        return $e->fresh();
    }

    private function exitDraft(int $qty, string $purpose = 'sale_delivery', ?int $projectId = null, array $extra = []): StockExit
    {
        $x = StockExit::create(array_merge([
            'code' => 'XK-' . uniqid(), 'warehouse_id' => $this->wh->id, 'project_id' => $projectId,
            'issue_purpose' => $purpose, 'item_usage_type' => $projectId ? 'project' : 'commercial',
            'status' => StockExitStatus::Draft, 'exit_date' => now()->toDateString(), 'created_by' => $this->user->id,
        ], $extra));
        StockExitItem::create([
            'stock_exit_id' => $x->id, 'product_id' => $this->product->id, 'quantity' => $qty, 'unit_price' => 0,
        ]);
        return $x;
    }

    private function confirmedExit(int $qty, string $purpose = 'sale_delivery', ?int $projectId = null, array $extra = []): StockExit
    {
        $x = $this->exitDraft($qty, $purpose, $projectId, $extra);
        $this->svc->confirmExit($x);
        return $x->fresh();
    }

    private function balance(?int $whId = null): array
    {
        $b = InventoryBalance::where('product_id', $this->product->id)->where('warehouse_id', $whId ?? $this->wh->id)->first();
        return [(float) ($b?->qty_on_hand ?? 0), round((float) ($b?->value_on_hand ?? 0), 2)];
    }

    private function assertInvariant(?int $whId = null): void
    {
        $whId ??= $this->wh->id;
        $mv = (float) StockMovement::active()->where('product_id', $this->product->id)->where('warehouse_id', $whId)->sum('quantity');
        $lot = (float) ProjectInventoryLot::where('product_id', $this->product->id)->where('warehouse_id', $whId)
            ->where('status', '!=', 'cancelled')->get()->sum(fn ($l) => (float) $l->received_qty - (float) $l->issued_qty);
        $this->assertEquals($mv - $lot, $this->balance($whId)[0], "AVCO qty phải = movement active − lot còn lại (kho {$whId})");
    }

    // ─── 1. Xuất AVCO không dự án rồi hủy ────────────────────────────────────

    public function test_non_project_avco_exit_cancel_restores_qty_and_value(): void
    {
        $this->entry(10, 100000);
        $x = $this->confirmedExit(4);
        $this->assertEquals([6.0, 600000.0], $this->balance());

        $this->svc->cancelExit($x);
        $this->assertEquals([10.0, 1000000.0], $this->balance());
        $this->assertInvariant();
    }

    // ─── 2. Xuất dự án KHÔNG có lot (AVCO kho chung) rồi hủy — case XK-0042 ──

    public function test_project_exit_using_avco_cancel_restores_avco(): void
    {
        $this->entry(5, 200000);
        $x = $this->confirmedExit(5, 'project_cost', $this->project->id);
        $this->assertEquals([0.0, 0.0], $this->balance());
        $this->assertEquals(5.0, (float) $x->items->first()->avco_qty);

        $this->svc->cancelExit($x);
        $this->assertEquals([5.0, 1000000.0], $this->balance(), 'Hủy phiếu dự án phải hoàn AVCO (A1)');
        $this->assertInvariant();
    }

    // ─── 3. FIFO thuần rồi hủy ───────────────────────────────────────────────

    public function test_pure_fifo_exit_cancel_restores_lot_without_touching_avco(): void
    {
        $this->entry(3, 100000);                                // AVCO kho chung
        $this->entry(4, 150000, $this->project->id);            // lot dự án
        $x = $this->confirmedExit(4, 'project_cost', $this->project->id);
        $this->assertEquals(0.0, (float) $x->items->first()->avco_qty);
        $this->assertEquals([3.0, 300000.0], $this->balance());

        $this->svc->cancelExit($x);
        $this->assertEquals([3.0, 300000.0], $this->balance());
        $this->assertEquals(0.0, (float) ProjectInventoryLot::first()->issued_qty);
        $this->assertInvariant();
    }

    // ─── 4. FIFO + phần thiếu lấy AVCO rồi hủy ───────────────────────────────

    public function test_fifo_plus_avco_exit_cancel_restores_both_parts_exactly(): void
    {
        $this->entry(10, 100000);
        $this->entry(2, 150000, $this->project->id);
        $x = $this->confirmedExit(5, 'project_cost', $this->project->id); // 2 lot + 3 AVCO
        $item = $x->items->first();
        $this->assertEquals(3.0, (float) $item->avco_qty);
        $this->assertEquals(300000.0, (float) $item->avco_amount);
        $this->assertEquals([7.0, 700000.0], $this->balance());

        $this->svc->cancelExit($x);
        $this->assertEquals([10.0, 1000000.0], $this->balance());
        $this->assertInvariant();
    }

    public function test_legacy_fifo_item_without_avco_columns_derives_avco_part_from_allocations(): void
    {
        $this->entry(10, 100000);
        $this->entry(2, 150000, $this->project->id);
        $x = $this->confirmedExit(5, 'project_cost', $this->project->id);
        $x->items()->update(['avco_qty' => null, 'avco_amount' => null]); // giả lập phiếu xác nhận trước migration

        $this->svc->cancelExit($x->fresh());
        $this->assertEquals([10.0, 1000000.0], $this->balance());
        $this->assertInvariant();
    }

    // ─── 5. Hủy rồi xóa: không hoàn trùng ────────────────────────────────────

    public function test_cancel_then_delete_exit_does_not_double_restore(): void
    {
        $this->entry(5, 200000);
        $x = $this->confirmedExit(5, 'project_cost', $this->project->id);
        $this->svc->cancelExit($x);
        $this->delete(route('warehouse.stock-exits.destroy', $x->id))->assertSessionHasNoErrors();

        $this->assertNull(StockExit::find($x->id));
        $this->assertEquals([5.0, 1000000.0], $this->balance());
        $this->assertInvariant();
    }

    public function test_admin_force_delete_confirmed_exit_restores_once(): void
    {
        $this->entry(5, 200000);
        $x = $this->confirmedExit(3, 'project_cost', $this->project->id);
        $this->delete(route('warehouse.stock-exits.destroy', $x->id));

        $this->assertNull(StockExit::find($x->id));
        $this->assertEquals([5.0, 1000000.0], $this->balance());
        $this->assertInvariant();
    }

    // ─── 6. Hủy 2 lần / xác nhận 2 lần (bấm đúp, model cũ) ───────────────────

    public function test_double_cancel_with_stale_instance_is_blocked(): void
    {
        $this->entry(10, 100000);
        $x = $this->confirmedExit(4);
        $stale = StockExit::find($x->id); // vẫn đang ở trạng thái Confirmed trong bộ nhớ

        $this->svc->cancelExit($x);
        $movements = StockMovement::count();

        $this->expectException(RuntimeException::class);
        try {
            $this->svc->cancelExit($stale);
        } finally {
            $this->assertEquals($movements, StockMovement::count(), 'Không được sinh movement đảo lần 2');
            $this->assertEquals([10.0, 1000000.0], $this->balance());
        }
    }

    public function test_double_confirm_exit_with_stale_instance_is_blocked(): void
    {
        $this->entry(10, 100000);
        $x = $this->exitDraft(4);
        $stale = StockExit::find($x->id);
        $this->svc->confirmExit($x);

        $this->expectException(RuntimeException::class);
        try {
            $this->svc->confirmExit($stale);
        } finally {
            $this->assertEquals([6.0, 600000.0], $this->balance());
        }
    }

    public function test_double_confirm_entry_with_stale_instance_is_blocked(): void
    {
        $e = StockEntry::create([
            'code' => 'NK-DBL', 'warehouse_id' => $this->wh->id, 'supplier_id' => $this->supplier->id,
            'status' => StockEntryStatus::Draft, 'entry_date' => now()->toDateString(), 'created_by' => $this->user->id,
        ]);
        StockEntryItem::create(['stock_entry_id' => $e->id, 'product_id' => $this->product->id, 'quantity' => 2, 'unit_price' => 100000, 'unit_cost' => 100000]);
        $stale = StockEntry::find($e->id);
        $this->svc->confirmEntry($e);

        $this->expectException(RuntimeException::class);
        try {
            $this->svc->confirmEntry($stale);
        } finally {
            $this->assertEquals([2.0, 200000.0], $this->balance());
        }
    }

    // ─── 7. Thu hồi / hủy phiếu nhập sau khi hàng đã xuất — case NK-0100/SP58 ─

    public function test_recall_entry_after_goods_issued_is_blocked_and_no_phantom_stock(): void
    {
        $e = $this->entry(3, 100000);
        $this->confirmedExit(3);

        try {
            $this->svc->recallEntry($e);
            $this->fail('Thu hồi phải bị chặn khi hàng đã xuất');
        } catch (RuntimeException $ex) {
            $this->assertStringContainsString('đã được xuất', $ex->getMessage());
        }
        $this->assertEquals(StockEntryStatus::Confirmed, $e->fresh()->status);
        $this->assertEquals([0.0, 0.0], $this->balance());
        $this->assertInvariant();
    }

    public function test_cancel_entry_after_goods_issued_is_blocked(): void
    {
        $e = $this->entry(3, 100000);
        $this->confirmedExit(2);

        $this->expectException(RuntimeException::class);
        try {
            $this->svc->cancelEntry($e);
        } finally {
            $this->assertEquals([1.0, 100000.0], $this->balance());
        }
    }

    public function test_recall_and_reconfirm_entry_without_issues_is_symmetric(): void
    {
        $e = $this->entry(5, 100000);
        $this->svc->recallEntry($e);
        $this->assertEquals([0.0, 0.0], $this->balance());
        $this->svc->confirmEntry($e->fresh());
        $this->assertEquals([5.0, 500000.0], $this->balance());
        $this->assertInvariant();
    }

    public function test_avco_record_exit_no_longer_clamps_silently(): void
    {
        $this->entry(2, 100000);
        $this->expectException(RuntimeException::class);
        app(AvcoService::class)->recordExit($this->product->id, $this->wh->id, 3);
    }

    // ─── 8. Thu hồi phiếu nhập dự án: không sinh lot trùng (A6) ─────────────

    public function test_recall_project_entry_then_reconfirm_does_not_duplicate_lot(): void
    {
        $e = $this->entry(4, 150000, $this->project->id);
        $this->svc->recallEntry($e);
        $this->assertEquals(0, ProjectInventoryLot::count());
        $this->svc->confirmEntry($e->fresh());

        $this->assertEquals(1, ProjectInventoryLot::count());
        $this->assertEquals(4.0, (float) ProjectInventoryLot::first()->received_qty);
        $this->assertInvariant();
    }

    public function test_recall_project_entry_keeps_lot_with_allocation_history_as_cancelled(): void
    {
        $e = $this->entry(4, 150000, $this->project->id);
        $x = $this->confirmedExit(1, 'project_cost', $this->project->id);
        $this->svc->cancelExit($x);                          // allocation voided, lot issued_qty về 0
        $oldLot = ProjectInventoryLot::first();

        $this->svc->recallEntry($e->fresh());
        $this->assertEquals('cancelled', $oldLot->fresh()->status, 'Giữ lot có lịch sử phân bổ');
        $this->svc->confirmEntry($e->fresh());

        $this->assertEquals(1, ProjectInventoryLot::where('status', 'active')->count());
        $this->assertInvariant();
    }

    public function test_recall_project_entry_blocked_when_lot_issued(): void
    {
        $e = $this->entry(4, 150000, $this->project->id);
        $this->confirmedExit(1, 'project_cost', $this->project->id);

        $this->expectException(RuntimeException::class);
        $this->svc->recallEntry($e);
    }

    // ─── 9. Giao dịch phát sinh sau phiếu bị hủy ─────────────────────────────

    public function test_transactions_after_cancelled_exit_keep_correct_average(): void
    {
        $this->entry(10, 100000);
        $x = $this->confirmedExit(5, 'project_cost', $this->project->id);
        $this->entry(5, 200000);                     // avg = (500k + 1.000k)/10 = 150k
        $this->svc->cancelExit($x);                  // hoàn 5 × 100k → 15 / 2.000k
        $this->assertEquals([15.0, 2000000.0], $this->balance());

        $this->confirmedExit(3);
        $this->assertEquals(12.0, $this->balance()[0]);
        $this->assertEqualsWithDelta(1600000.0, $this->balance()[1], 1);
        $this->assertInvariant();
    }

    // ─── 10. Lỗi giữa chừng → rollback toàn bộ ───────────────────────────────

    public function test_failure_during_project_exit_journal_rolls_back_avco_and_movements(): void
    {
        $this->entry(5, 200000);
        AccountCode::where('code', '154')->delete(); // JE dự án (post fail-fast) sẽ lỗi
        $x = $this->exitDraft(2, 'project_cost', $this->project->id);
        $movements = StockMovement::count();

        try {
            $this->svc->confirmExit($x);
            $this->fail('Phải lỗi khi không post được JE');
        } catch (\Throwable) {
        }

        $this->assertEquals(StockExitStatus::Draft, $x->fresh()->status);
        $this->assertEquals($movements, StockMovement::count());
        $this->assertEquals([5.0, 1000000.0], $this->balance());
        $this->assertNull($x->items()->first()->avco_qty);
    }

    // ─── 11. Điều chuyển kho dự án (A5) ──────────────────────────────────────

    public function test_project_transfer_confirm_and_cancel_are_symmetric(): void
    {
        if (\Illuminate\Support\Facades\DB::getDriverName() === 'pgsql') {
            $this->markTestSkipped('PostgreSQL/production chặn issue_purpose=project_transfer bằng CHECK chk_stock_exits_issue_purpose.');
        }
        $this->entry(6, 100000);
        $x = $this->confirmedExit(4, 'project_transfer', $this->project->id, ['to_warehouse_id' => $this->wh2->id]);

        $lot = ProjectInventoryLot::where('source_stock_exit_id', $x->id)->first();
        $this->assertNotNull($lot);
        $this->assertNull($lot->stock_entry_id);
        $this->assertEquals([2.0, 200000.0], $this->balance());
        $this->assertEquals([0.0, 0.0], $this->balance($this->wh2->id), 'Kho đích chỉ ghi lot, không cộng AVCO');
        $this->assertInvariant();
        $this->assertInvariant($this->wh2->id);

        $this->svc->cancelExit($x);
        $this->assertEquals([6.0, 600000.0], $this->balance());
        $this->assertEquals('cancelled', $lot->fresh()->status);
        $this->assertInvariant();
        $this->assertInvariant($this->wh2->id);
    }

    public function test_non_project_exit_checks_avco_not_raw_movements(): void
    {
        $this->entry(2, 100000);
        $this->entry(5, 150000, $this->project->id); // hàng dự án, không thuộc kho chung

        $this->expectException(RuntimeException::class);
        $this->confirmedExit(4); // movement tổng = 7 nhưng kho chung chỉ có 2
    }

    // ─── 12. Xóa phiếu nhập giữ lịch sử movement (A9) ────────────────────────

    public function test_delete_cancelled_entry_voids_movements_instead_of_hard_delete(): void
    {
        $e = $this->entry(3, 100000);
        $this->svc->cancelEntry($e);
        $ids = StockMovement::where('source_type', StockEntry::class)->where('source_id', $e->id)->pluck('id');
        $this->assertCount(2, $ids);

        $this->delete(route('warehouse.stock-entries.destroy', $e->id));

        $this->assertNull(StockEntry::find($e->id));
        $this->assertEquals(2, StockMovement::whereIn('id', $ids)->where('status', 'voided')->count());
        $this->assertInvariant();
    }
}
