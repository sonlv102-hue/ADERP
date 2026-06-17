<?php

namespace Tests\Feature;

use App\Enums\PurchaseOrderStatus;
use App\Enums\StockEntryStatus;
use App\Enums\StockExitStatus;
use App\Models\AccountCode;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProjectInventoryLot;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\StockEntry;
use App\Models\StockEntryItem;
use App\Models\StockExit;
use App\Models\StockExitItem;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class StockServiceTest extends TestCase
{
    use RefreshDatabase;

    private User      $user;
    private Supplier  $supplier;
    private Warehouse $warehouse;
    private Product   $product;
    private Customer  $customer;
    private StockService $svc;

    protected function setUp(): void
    {
        parent::setUp();

        // Prevent NotifyLowStockJob from running synchronously (it calls User::role('admin') which doesn't exist in test DB)
        Queue::fake();

        $this->user = User::factory()->create(['is_active' => true]);
        foreach (['warehouse.view', 'warehouse.create', 'warehouse.manage'] as $p) {
            \Spatie\Permission\Models\Permission::firstOrCreate(['name' => $p]);
        }
        $this->user->givePermissionTo(['warehouse.view', 'warehouse.create', 'warehouse.manage']);
        $this->actingAs($this->user);

        $this->warehouse = Warehouse::create([
            'name' => 'Kho Test', 'address' => 'HN', 'manager_id' => $this->user->id, 'is_active' => true,
        ]);

        // Account codes
        foreach ([
            ['code' => '156',  'name' => 'Hàng hoá',          'type' => 'asset',     'normal_balance' => 'debit',  'is_detail' => false, 'parent_code' => null],
            ['code' => '1561', 'name' => 'Hàng hoá kho',       'type' => 'asset',     'normal_balance' => 'debit',  'is_detail' => true,  'parent_code' => '156'],
            ['code' => '1331', 'name' => 'Thuế GTGT đầu vào', 'type' => 'asset',     'normal_balance' => 'debit',  'is_detail' => true,  'parent_code' => null],
            ['code' => '331',  'name' => 'Phải trả NCC',       'type' => 'liability', 'normal_balance' => 'credit', 'is_detail' => false, 'parent_code' => null],
            ['code' => '3311', 'name' => 'NCC trong nước',     'type' => 'liability', 'normal_balance' => 'credit', 'is_detail' => true,  'parent_code' => '331'],
            ['code' => '632',  'name' => 'Giá vốn hàng bán',   'type' => 'expense',   'normal_balance' => 'debit',  'is_detail' => true,  'parent_code' => null],
            ['code' => '154',  'name' => 'Chi phí dở dang',    'type' => 'asset',     'normal_balance' => 'debit',  'is_detail' => true,  'parent_code' => null],
        ] as $acc) {
            AccountCode::updateOrCreate(['code' => $acc['code']], array_merge($acc, ['level' => 3, 'is_active' => true]));
        }

        $this->supplier = Supplier::create([
            'code' => 'NCC-T01', 'name' => 'NCC Test', 'is_active' => true, 'payable_account_code' => '3311',
        ]);

        $this->product = Product::create([
            'code'        => 'SP-T01', 'name' => 'Sản phẩm Test', 'unit' => 'cái',
            'cost_price'  => 1100000,  // incl 10% VAT → excl = 1_000_000
            'vat_percent' => 10,
            'item_type'   => 'product', 'is_active' => true,
        ]);

        $this->customer = Customer::create([
            'code' => 'KH-T01', 'name' => 'Khách hàng Test', 'is_active' => true,
        ]);

        $this->svc = app(StockService::class);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function makePo(int $qty = 2, ?int $projectId = null): PurchaseOrder
    {
        $po = PurchaseOrder::create([
            'code'        => 'MH-T-' . rand(1000, 9999),
            'supplier_id' => $this->supplier->id,
            'warehouse_id'=> $this->warehouse->id,
            'order_date'  => now()->toDateString(),
            'status'      => PurchaseOrderStatus::Sent,
            'created_by'  => $this->user->id,
            'project_id'  => $projectId,
        ]);

        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'product_id'        => $this->product->id,
            'quantity'          => $qty,
            'received_quantity' => 0,
            'unit_price'        => 1000000,
            'vat_rate'          => 10,
        ]);

        return $po->fresh('items');
    }

    private function makeAndConfirmEntry(PurchaseOrder $po, int $qty = 2, ?int $projectId = null): StockEntry
    {
        $poItem = $po->items->first();
        $entry = StockEntry::create([
            'code'              => 'NK-T-' . rand(1000, 9999),
            'warehouse_id'      => $this->warehouse->id,
            'purchase_order_id' => $po->id,
            'supplier_id'       => $this->supplier->id,
            'entry_date'        => now()->toDateString(),
            'status'            => StockEntryStatus::Draft,
            'created_by'        => $this->user->id,
        ]);

        StockEntryItem::create([
            'stock_entry_id'         => $entry->id,
            'purchase_order_item_id' => $poItem->id,
            'product_id'             => $this->product->id,
            'quantity'               => $qty,
            'unit_price'             => 1000000,
            'unit_cost'              => 1000000,
            'tax_rate'               => 10,
            'project_id'             => $projectId,
        ]);

        $this->svc->confirmEntry($entry->fresh('items.product'));
        return $entry->fresh('items');
    }

    private function makeAndConfirmExit(int $qty = 2, ?int $projectId = null, string $purpose = 'sale_delivery'): StockExit
    {
        $exit = StockExit::create([
            'code'          => 'XK-T-' . rand(1000, 9999),
            'warehouse_id'  => $this->warehouse->id,
            'exit_date'     => now()->toDateString(),
            'status'        => StockExitStatus::Draft,
            'created_by'    => $this->user->id,
            'project_id'    => $projectId,
            'issue_purpose' => $purpose,
        ]);

        StockExitItem::create([
            'stock_exit_id' => $exit->id,
            'product_id'    => $this->product->id,
            'quantity'      => $qty,
            'unit_price'    => 1500000,
            'project_id'    => $projectId,
        ]);

        $this->svc->confirmExit($exit->fresh('items.product', 'items.serials'));
        return $exit->fresh('items');
    }

    // ─── cancelEntry tests ────────────────────────────────────────────────────

    public function test_cancel_confirmed_entry_decrements_po_received_quantity(): void
    {
        $po    = $this->makePo(2);
        $entry = $this->makeAndConfirmEntry($po, 2);

        $poItem = $po->items->first();
        $this->assertEquals(2, (int)$poItem->fresh()->received_quantity);

        $this->svc->cancelEntry($entry->fresh('items.serials', 'items.product'));

        $this->assertEquals(0, (int)$poItem->fresh()->received_quantity);
    }

    public function test_cancel_confirmed_entry_creates_reversal_movement_with_project_id(): void
    {
        $project = \App\Models\Project::create([
            'code' => 'DA-T01', 'name' => 'Dự án test', 'status' => 'in_progress',
            'customer_id' => $this->customer->id, 'created_by' => $this->user->id,
        ]);

        $po    = $this->makePo(2, $project->id);
        $entry = $this->makeAndConfirmEntry($po, 2, $project->id);

        $this->svc->cancelEntry($entry->fresh('items.serials', 'items.product'));

        // Net movement should be 0 after cancellation
        $netQty = StockMovement::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->sum('quantity');
        $this->assertEquals(0, $netQty);

        // Reversal movement must carry project_id
        $reversalMovement = StockMovement::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->where('quantity', '<', 0)
            ->latest()
            ->first();
        $this->assertNotNull($reversalMovement);
        $this->assertEquals($project->id, $reversalMovement->project_id);
    }

    public function test_cancel_confirmed_entry_reverses_journal_entry(): void
    {
        $po    = $this->makePo(2);
        $entry = $this->makeAndConfirmEntry($po, 2);

        // Auto JEs từ tryPost có status='draft' (cần kế toán duyệt). Không filter status.
        $je = \App\Models\JournalEntry::where('reference_type', 'stock_entry')
            ->where('reference_id', $entry->id)
            ->whereRaw("description NOT LIKE 'Đảo:%'")
            ->first();
        $this->assertNotNull($je, 'JE phải tồn tại sau confirm');
        $jeId = $je->id;

        $this->svc->cancelEntry($entry->fresh('items.serials', 'items.product'));

        // Draft JEs bị reverseOrDelete → hard delete; Posted JEs → reversed
        $jeAfter = \App\Models\JournalEntry::find($jeId);
        $isGone = $jeAfter === null
            || in_array($jeAfter->status, ['reversed', 'voided']);
        $this->assertTrue($isGone, 'JE phải bị xóa (nếu draft) hoặc reversed (nếu posted) sau khi hủy entry');
    }

    // ─── recallEntry tests ────────────────────────────────────────────────────

    public function test_recall_entry_decrements_po_received_quantity(): void
    {
        $po    = $this->makePo(4);
        $entry = $this->makeAndConfirmEntry($po, 4);

        $poItem = $po->items->first();
        $this->assertEquals(4, (int)$poItem->fresh()->received_quantity);

        $this->svc->recallEntry($entry->fresh('items.serials', 'items.product'));

        $this->assertEquals(0, (int)$poItem->fresh()->received_quantity);
        $this->assertEquals(StockEntryStatus::Draft, $entry->fresh()->status);
    }

    public function test_recall_entry_adds_project_id_to_reversal_movement(): void
    {
        $project = \App\Models\Project::create([
            'code' => 'DA-T02', 'name' => 'Dự án test 2', 'status' => 'in_progress',
            'customer_id' => $this->customer->id, 'created_by' => $this->user->id,
        ]);

        $po    = $this->makePo(2, $project->id);
        $entry = $this->makeAndConfirmEntry($po, 2, $project->id);

        $this->svc->recallEntry($entry->fresh('items.serials', 'items.product'));

        $netQty = StockMovement::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->sum('quantity');
        $this->assertEquals(0, $netQty);

        $reversalMovement = StockMovement::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->where('quantity', '<', 0)
            ->latest()
            ->first();
        $this->assertEquals($project->id, $reversalMovement->project_id);
    }

    // ─── confirmExit non-project tests ───────────────────────────────────────

    public function test_confirm_exit_non_project_stores_unit_cost_on_movement(): void
    {
        // Nhập 2 trước
        $po = $this->makePo(2);
        $this->makeAndConfirmEntry($po, 2);

        // Xuất 2 (non-project, sale_delivery)
        $exit = $this->makeAndConfirmExit(2, null, 'sale_delivery');

        // unit_cost phải được lưu vào movement
        $exitMovement = StockMovement::where('source_type', StockExit::class)
            ->where('source_id', $exit->id)
            ->where('quantity', '<', 0)
            ->first();

        $this->assertNotNull($exitMovement);
        $this->assertNotNull($exitMovement->unit_cost, 'unit_cost phải được lưu vào movement');
        $this->assertGreaterThan(0, (float)$exitMovement->unit_cost);

        // item cũng phải có source_cost / total_cost
        $exitItem = $exit->items->first();
        $this->assertNotNull($exitItem->source_cost);
        $this->assertNotNull($exitItem->total_cost);
        $this->assertGreaterThan(0, (float)$exitItem->total_cost);
    }

    public function test_confirm_exit_non_project_posts_cogs_journal(): void
    {
        $po = $this->makePo(2);
        $this->makeAndConfirmEntry($po, 2);

        $exit = $this->makeAndConfirmExit(2, null, 'sale_delivery');

        // Auto JEs có status='draft'; không filter status
        $je = \App\Models\JournalEntry::where('reference_type', 'stock_exit')
            ->where('reference_id', $exit->id)
            ->whereRaw("description NOT LIKE 'Đảo:%'")
            ->first();
        $this->assertNotNull($je, 'JE giá vốn phải tồn tại sau confirm exit');

        $lines  = $je->lines;
        $dr632  = $lines->where('account_code', '632')->sum('debit');
        $cr1561 = $lines->where('account_code', '1561')->sum('credit');

        $this->assertGreaterThan(0, $dr632,  'Dr 632 phải > 0');
        $this->assertGreaterThan(0, $cr1561, 'Cr 1561 phải > 0');
        $this->assertEquals($dr632, $cr1561, 'JE phải cân bằng');
    }

    // ─── cancelExit tests ─────────────────────────────────────────────────────

    public function test_cancel_confirmed_exit_restores_stock(): void
    {
        $po = $this->makePo(2);
        $this->makeAndConfirmEntry($po, 2);

        $stockBefore = StockMovement::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->sum('quantity');
        $this->assertEquals(2, $stockBefore);

        $exit = $this->makeAndConfirmExit(2, null, 'sale_delivery');

        $stockAfterExit = StockMovement::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->sum('quantity');
        $this->assertEquals(0, $stockAfterExit);

        $this->svc->cancelExit($exit->fresh('items.serials', 'items.product'));

        $stockAfterCancel = StockMovement::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->sum('quantity');
        $this->assertEquals(2, $stockAfterCancel);
    }

    public function test_cancel_confirmed_exit_reverses_cogs_journal(): void
    {
        $po = $this->makePo(2);
        $this->makeAndConfirmEntry($po, 2);

        $exit = $this->makeAndConfirmExit(2, null, 'sale_delivery');
        $je   = \App\Models\JournalEntry::where('reference_type', 'stock_exit')
            ->where('reference_id', $exit->id)
            ->whereRaw("description NOT LIKE 'Đảo:%'")
            ->first();
        $this->assertNotNull($je, 'JE giá vốn phải tồn tại trước khi hủy');
        $jeId = $je->id;

        $this->svc->cancelExit($exit->fresh('items.serials', 'items.product'));

        $jeAfter = \App\Models\JournalEntry::find($jeId);
        $isGone  = $jeAfter === null || in_array($jeAfter->status, ['reversed', 'voided']);
        $this->assertTrue($isGone, 'JE giá vốn phải bị xóa hoặc reversed sau khi hủy exit');
    }

    // ─── confirmExit project tests ────────────────────────────────────────────

    public function test_confirm_exit_project_creates_wip_entry(): void
    {
        $project = \App\Models\Project::create([
            'code' => 'DA-T03', 'name' => 'Dự án test 3', 'status' => 'in_progress',
            'customer_id' => $this->customer->id, 'created_by' => $this->user->id,
        ]);

        $po = $this->makePo(2, $project->id);
        $this->makeAndConfirmEntry($po, 2, $project->id);

        $exit = $this->makeAndConfirmExit(2, $project->id, 'project_cost');

        $wipCount = \App\Models\ProjectWipEntry::where('project_id', $project->id)
            ->where('source_type', StockExit::class)
            ->where('source_id', $exit->id)
            ->count();
        $this->assertGreaterThan(0, $wipCount, 'WIP entry phải được tạo khi xuất cho dự án');
    }

    public function test_cancel_exit_project_deletes_wip_entry(): void
    {
        $project = \App\Models\Project::create([
            'code' => 'DA-T04', 'name' => 'Dự án test 4', 'status' => 'in_progress',
            'customer_id' => $this->customer->id, 'created_by' => $this->user->id,
        ]);

        $po = $this->makePo(2, $project->id);
        $this->makeAndConfirmEntry($po, 2, $project->id);

        $exit = $this->makeAndConfirmExit(2, $project->id, 'project_cost');

        // Verify WIP exists
        $wipCount = \App\Models\ProjectWipEntry::where('source_type', StockExit::class)
            ->where('source_id', $exit->id)
            ->count();
        $this->assertGreaterThan(0, $wipCount);

        $this->svc->cancelExit($exit->fresh('items.serials', 'items.product'));

        // WIP must be deleted after cancel
        $wipAfter = \App\Models\ProjectWipEntry::where('source_type', StockExit::class)
            ->where('source_id', $exit->id)
            ->count();
        $this->assertEquals(0, $wipAfter, 'WIP entries phải bị xóa khi hủy exit dự án');
    }

    public function test_cancel_exit_project_restores_lot_issued_qty(): void
    {
        $project = \App\Models\Project::create([
            'code' => 'DA-T05', 'name' => 'Dự án test 5', 'status' => 'in_progress',
            'customer_id' => $this->customer->id, 'created_by' => $this->user->id,
        ]);

        $po = $this->makePo(2, $project->id);
        $this->makeAndConfirmEntry($po, 2, $project->id);

        $lot = ProjectInventoryLot::where('project_id', $project->id)
            ->where('product_id', $this->product->id)
            ->first();
        $this->assertNotNull($lot);
        $this->assertEquals(0, (float)$lot->issued_qty);

        $exit = $this->makeAndConfirmExit(2, $project->id, 'project_cost');

        $lot->refresh();
        $this->assertEquals(2, (float)$lot->issued_qty);
        $this->assertEquals('depleted', $lot->status);

        $this->svc->cancelExit($exit->fresh('items.serials', 'items.product'));

        $lot->refresh();
        $this->assertEquals(0, (float)$lot->issued_qty);
        $this->assertEquals('active', $lot->status);
    }

    // ─── Double-cancel guard ──────────────────────────────────────────────────

    public function test_cancel_confirmed_entry_twice_throws_exception(): void
    {
        $po    = $this->makePo(2);
        $entry = $this->makeAndConfirmEntry($po, 2);

        $this->svc->cancelEntry($entry->fresh('items.serials', 'items.product'));

        $this->expectException(\RuntimeException::class);
        $this->svc->cancelEntry($entry->fresh('items.serials', 'items.product'));
    }

    public function test_cancel_confirmed_exit_twice_throws_exception(): void
    {
        $po = $this->makePo(2);
        $this->makeAndConfirmEntry($po, 2);

        $exit = $this->makeAndConfirmExit(2, null, 'sale_delivery');
        $this->svc->cancelExit($exit->fresh('items.serials', 'items.product'));

        $this->expectException(\RuntimeException::class);
        $this->svc->cancelExit($exit->fresh('items.serials', 'items.product'));
    }
}
