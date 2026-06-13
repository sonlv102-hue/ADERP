<?php

namespace Tests\Feature\Warehouse;

use App\Enums\PurchaseOrderStatus;
use App\Enums\StockEntryStatus;
use App\Enums\StockExitStatus;
use Illuminate\Support\Facades\Queue;
use App\Models\AccountCode;
use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Project;
use App\Models\ProjectInventoryLot;
use App\Models\ProjectWipEntry;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\StockEntry;
use App\Models\StockEntryItem;
use App\Models\StockExit;
use App\Models\StockExitItem;
use App\Models\StockExitItemLotAllocation;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectInventoryTest extends TestCase
{
    use RefreshDatabase;

    private StockService $svc;
    private User $user;
    private Warehouse $warehouse;
    private Supplier $supplier;
    private Product $product;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->svc = app(StockService::class);
        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        // Seed account codes FIRST (suppliers FK to account_codes)
        foreach ([
            ['code' => '1331', 'name' => 'Thuế GTGT KT',   'type' => 'asset',     'normal_balance' => 'debit',  'is_detail' => true],
            ['code' => '1561', 'name' => 'Hàng hóa',       'type' => 'asset',     'normal_balance' => 'debit',  'is_detail' => true],
            ['code' => '3311', 'name' => 'Phải trả NCC',   'type' => 'liability', 'normal_balance' => 'credit', 'is_detail' => true],
            ['code' => '154',  'name' => 'CP SXKD DD',     'type' => 'asset',     'normal_balance' => 'debit',  'is_detail' => true],
            ['code' => '632',  'name' => 'Giá vốn HH',     'type' => 'expense',   'normal_balance' => 'debit',  'is_detail' => true],
            ['code' => '6421', 'name' => 'CP bán hàng',    'type' => 'expense',   'normal_balance' => 'debit',  'is_detail' => true],
            ['code' => '6422', 'name' => 'CP QLDN',        'type' => 'expense',   'normal_balance' => 'debit',  'is_detail' => true],
        ] as $c) {
            AccountCode::firstOrCreate(['code' => $c['code']], $c);
        }

        $this->warehouse = Warehouse::create(['code' => 'WH-T', 'name' => 'Test WH']);

        $this->supplier = Supplier::create([
            'code' => 'NCC-T',
            'name' => 'Test Supplier',
            'payable_account_code' => '3311',
        ]);

        $cat = ProductCategory::create(['name' => 'Cat']);
        $this->product = Product::create([
            'code'        => 'SP-T',
            'name'        => 'Test Product',
            'category_id' => $cat->id,
            'cost_price'  => 110000,
            'vat_percent' => 10,
        ]);

        $customer = Customer::create([
            'code' => 'KH-T',
            'name' => 'Test Customer',
        ]);

        $this->project = Project::create([
            'code'        => 'DA-T',
            'name'        => 'Test Project',
            'status'      => 'in_progress',
            'customer_id' => $customer->id,
            'created_by'  => $this->user->id,
        ]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function makePo(int $qty = 10, ?int $projectId = null): array
    {
        $pid = $projectId ?? $this->project->id;
        $po  = PurchaseOrder::create([
            'code'         => 'MH-' . uniqid(),
            'supplier_id'  => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'project_id'   => $pid,
            'status'       => PurchaseOrderStatus::Sent,
            'order_date'   => now()->toDateString(),
            'created_by'   => $this->user->id,
            'subtotal'     => 0, 'tax' => 0, 'total' => 0,
        ]);
        $poItem = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'product_id'        => $this->product->id,
            'quantity'          => $qty,
            'received_quantity' => 0,
            'unit_price'        => 100000,
            'vat_rate'          => 10,
            'subtotal'          => $qty * 100000,
            'project_id'        => $pid,
        ]);
        return [$po, $poItem];
    }

    private function makeEntryDraft(
        PurchaseOrder $po,
        PurchaseOrderItem $poItem,
        int $qty,
        string $entryDate
    ): StockEntry {
        $entry = StockEntry::create([
            'code'              => 'NK-' . uniqid(),
            'warehouse_id'      => $po->warehouse_id,
            'supplier_id'       => $po->supplier_id,
            'purchase_order_id' => $po->id,
            'status'            => StockEntryStatus::Draft,
            'entry_date'        => $entryDate,
            'created_by'        => $this->user->id,
        ]);
        // project_id = null so confirmEntry resolves it from PO item
        StockEntryItem::create([
            'stock_entry_id'         => $entry->id,
            'product_id'             => $this->product->id,
            'quantity'               => $qty,
            'unit_price'             => 100000,
            'tax_rate'               => 10,
            'purchase_order_item_id' => $poItem->id,
            'project_id'             => null,
            'unit_cost'              => 100000,
        ]);
        return $entry;
    }

    private function makeExitDraft(
        int $qty,
        string $issuePurpose = 'project_cost',
        ?int $projectId = null
    ): StockExit {
        $exit = StockExit::create([
            'code'            => 'XK-' . uniqid(),
            'warehouse_id'    => $this->warehouse->id,
            'project_id'      => $projectId ?? $this->project->id,
            'issue_purpose'   => $issuePurpose,
            'item_usage_type' => 'project',
            'status'          => StockExitStatus::Draft,
            'exit_date'       => now()->toDateString(),
            'created_by'      => $this->user->id,
        ]);
        StockExitItem::create([
            'stock_exit_id' => $exit->id,
            'product_id'    => $this->product->id,
            'quantity'      => $qty,
            'unit_price'    => 0,
            'subtotal'      => 0,
        ]);
        return $exit;
    }

    // ─── Tests ───────────────────────────────────────────────────────────────

    /** @test */
    public function confirm_entry_creates_project_inventory_lot(): void
    {
        [$po, $poItem] = $this->makePo(10);
        $entry = $this->makeEntryDraft($po, $poItem, 5, '2026-06-01');
        $this->svc->confirmEntry($entry);

        $lot = ProjectInventoryLot::where('project_id', $this->project->id)
            ->where('product_id', $this->product->id)
            ->first();

        $this->assertNotNull($lot);
        $this->assertEquals(5, (float) $lot->received_qty);
        $this->assertEquals(0, (float) $lot->issued_qty);
        $this->assertEquals('active', $lot->status);
        $this->assertEquals(100000, (float) $lot->unit_cost);
        $this->assertEquals($this->warehouse->id, $lot->warehouse_id);
    }

    /** @test */
    public function confirm_entry_increments_po_item_received_quantity(): void
    {
        [$po, $poItem] = $this->makePo(10);
        $entry = $this->makeEntryDraft($po, $poItem, 7, '2026-06-01');
        $this->svc->confirmEntry($entry);

        $this->assertEquals(7, (float) $poItem->fresh()->received_quantity);
    }

    /** @test */
    public function confirm_entry_prevents_over_receipt_per_po_line(): void
    {
        [$po, $poItem] = $this->makePo(5);
        $e1 = $this->makeEntryDraft($po, $poItem, 5, '2026-06-01');
        $this->svc->confirmEntry($e1);

        $e2 = StockEntry::create([
            'code'              => 'NK-OVR',
            'warehouse_id'      => $po->warehouse_id,
            'supplier_id'       => $po->supplier_id,
            'purchase_order_id' => $po->id,
            'status'            => StockEntryStatus::Draft,
            'entry_date'        => now()->toDateString(),
            'created_by'        => $this->user->id,
        ]);
        StockEntryItem::create([
            'stock_entry_id'         => $e2->id,
            'product_id'             => $this->product->id,
            'quantity'               => 1,
            'unit_price'             => 100000,
            'tax_rate'               => 10,
            'purchase_order_item_id' => $poItem->id,
            'project_id'             => null,
            'unit_cost'              => 100000,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->svc->confirmEntry($e2);
    }

    /** @test */
    public function confirm_exit_project_cost_creates_fifo_allocation(): void
    {
        [$po, $poItem] = $this->makePo(10);
        $entry = $this->makeEntryDraft($po, $poItem, 5, '2026-06-01');
        $this->svc->confirmEntry($entry);

        $exit = $this->makeExitDraft(3, 'project_cost');
        $this->svc->confirmExit($exit);

        $alloc = StockExitItemLotAllocation::where('stock_exit_id', $exit->id)->first();
        $this->assertNotNull($alloc);
        $this->assertEquals(3, (float) $alloc->allocated_qty);
        $this->assertEquals(300000, (float) $alloc->amount);
        $this->assertNull($alloc->voided_at);
    }

    /** @test */
    public function confirm_exit_depletes_lot_when_fully_issued(): void
    {
        [$po, $poItem] = $this->makePo(10);
        $entry = $this->makeEntryDraft($po, $poItem, 5, '2026-06-01');
        $this->svc->confirmEntry($entry);
        $lot = ProjectInventoryLot::where('project_id', $this->project->id)->first();

        $exit = $this->makeExitDraft(5, 'project_cost');
        $this->svc->confirmExit($exit);

        $this->assertEquals('depleted', $lot->fresh()->status);
        $this->assertEquals(5, (float) $lot->fresh()->issued_qty);
    }

    /** @test */
    public function confirm_exit_prevents_over_issue_from_project_lots(): void
    {
        [$po, $poItem] = $this->makePo(10);
        $entry = $this->makeEntryDraft($po, $poItem, 3, '2026-06-01');
        $this->svc->confirmEntry($entry);

        $exit = $this->makeExitDraft(5, 'project_cost'); // 5 > 3 available
        $this->expectException(\RuntimeException::class);
        $this->svc->confirmExit($exit);
    }

    /** @test */
    public function fifo_spans_multiple_lots_draining_oldest_first(): void
    {
        [$po, $poItem] = $this->makePo(10);

        // Lot A: 3 units (older date)
        $e1 = $this->makeEntryDraft($po, $poItem, 3, '2026-05-01');
        $this->svc->confirmEntry($e1);

        // Lot B: 5 units (newer date)
        $e2 = $this->makeEntryDraft($po, $poItem, 5, '2026-05-10');
        $this->svc->confirmEntry($e2);

        // Exit 4: should take 3 from lot A, 1 from lot B
        $exit = $this->makeExitDraft(4, 'project_cost');
        $this->svc->confirmExit($exit);

        $allocs = StockExitItemLotAllocation::where('stock_exit_id', $exit->id)
            ->orderBy('id')
            ->get();
        $this->assertCount(2, $allocs);
        $this->assertEquals(3, (float) $allocs[0]->allocated_qty);
        $this->assertEquals(1, (float) $allocs[1]->allocated_qty);

        $lotA = ProjectInventoryLot::where('received_qty', 3)->first();
        $lotB = ProjectInventoryLot::where('received_qty', 5)->first();
        $this->assertEquals('depleted', $lotA->fresh()->status);
        $this->assertEquals('active', $lotB->fresh()->status);
    }

    /** @test */
    public function cross_project_isolation_prevents_using_other_projects_lots(): void
    {
        $customer2 = Customer::create(['code' => 'KH-2', 'name' => 'Customer 2']);
        $project2 = Project::create([
            'code'        => 'DA-2',
            'name'        => 'Project 2',
            'status'      => 'in_progress',
            'customer_id' => $customer2->id,
            'created_by'  => $this->user->id,
        ]);

        // Only project2 has lots
        [$po, $poItem] = $this->makePo(10, $project2->id);
        $entry = $this->makeEntryDraft($po, $poItem, 5, '2026-06-01');
        $this->svc->confirmEntry($entry);

        // Exit for project1 (this->project) — no lots for it
        $exit = $this->makeExitDraft(1, 'project_cost');
        $this->expectException(\RuntimeException::class);
        $this->svc->confirmExit($exit);
    }

    /** @test */
    public function cancel_exit_voids_allocations_and_restores_lot_qty(): void
    {
        [$po, $poItem] = $this->makePo(10);
        $entry = $this->makeEntryDraft($po, $poItem, 5, '2026-06-01');
        $this->svc->confirmEntry($entry);
        $lot = ProjectInventoryLot::where('project_id', $this->project->id)->first();

        $exit = $this->makeExitDraft(3, 'project_cost');
        $this->svc->confirmExit($exit);
        $this->assertEquals(3, (float) $lot->fresh()->issued_qty);

        $this->svc->cancelExit($exit->fresh());

        $alloc = StockExitItemLotAllocation::where('stock_exit_id', $exit->id)->first();
        $this->assertNotNull($alloc->fresh()->voided_at, 'Allocation should be voided');
        $this->assertEquals(0, (float) $lot->fresh()->issued_qty);
        $this->assertEquals('active', $lot->fresh()->status);
    }

    /** @test */
    public function cancel_exit_reactivates_a_depleted_lot(): void
    {
        [$po, $poItem] = $this->makePo(10);
        $entry = $this->makeEntryDraft($po, $poItem, 5, '2026-06-01');
        $this->svc->confirmEntry($entry);
        $lot = ProjectInventoryLot::where('project_id', $this->project->id)->first();

        $exit = $this->makeExitDraft(5, 'project_cost');
        $this->svc->confirmExit($exit);
        $this->assertEquals('depleted', $lot->fresh()->status);

        $this->svc->cancelExit($exit->fresh());
        $this->assertEquals('active', $lot->fresh()->status);
        $this->assertEquals(0, (float) $lot->fresh()->issued_qty);
    }

    /** @test */
    public function exit_journal_project_cost_debits_account_154(): void
    {
        [$po, $poItem] = $this->makePo(10);
        $entry = $this->makeEntryDraft($po, $poItem, 5, '2026-06-01');
        $this->svc->confirmEntry($entry);

        $exit = $this->makeExitDraft(2, 'project_cost');
        $this->svc->confirmExit($exit);

        $je = JournalEntry::where('reference_type', 'stock_exit')
            ->where('reference_id', $exit->id)
            ->first();
        $this->assertNotNull($je, 'Journal entry should be created');

        $debitLine = $je->lines()->where('debit', '>', 0)->first();
        $this->assertEquals('154', $debitLine?->account_code);
    }

    /** @test */
    public function exit_journal_sale_delivery_debits_account_632(): void
    {
        // Create stock WITHOUT project so no FIFO lots
        $po = PurchaseOrder::create([
            'code'         => 'MH-SD',
            'supplier_id'  => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'project_id'   => null,
            'status'       => PurchaseOrderStatus::Sent,
            'order_date'   => now()->toDateString(),
            'created_by'   => $this->user->id,
            'subtotal'     => 0, 'tax' => 0, 'total' => 0,
        ]);
        $poItem = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'product_id'        => $this->product->id,
            'quantity'          => 10,
            'received_quantity' => 0,
            'unit_price'        => 100000,
            'vat_rate'          => 10,
            'subtotal'          => 1000000,
            'project_id'        => null,
        ]);
        $entry = StockEntry::create([
            'code'              => 'NK-SD',
            'warehouse_id'      => $this->warehouse->id,
            'supplier_id'       => $this->supplier->id,
            'purchase_order_id' => $po->id,
            'status'            => StockEntryStatus::Draft,
            'entry_date'        => '2026-06-01',
            'created_by'        => $this->user->id,
        ]);
        StockEntryItem::create([
            'stock_entry_id'         => $entry->id,
            'product_id'             => $this->product->id,
            'quantity'               => 5,
            'unit_price'             => 100000,
            'tax_rate'               => 10,
            'purchase_order_item_id' => $poItem->id,
            'project_id'             => null,
            'unit_cost'              => 100000,
        ]);
        $this->svc->confirmEntry($entry);

        $exit = StockExit::create([
            'code'            => 'XK-SD',
            'warehouse_id'    => $this->warehouse->id,
            'project_id'      => null,
            'issue_purpose'   => 'sale_delivery',
            'item_usage_type' => 'commercial',
            'status'          => StockExitStatus::Draft,
            'exit_date'       => now()->toDateString(),
            'created_by'      => $this->user->id,
        ]);
        StockExitItem::create([
            'stock_exit_id' => $exit->id,
            'product_id'    => $this->product->id,
            'quantity'      => 2,
            'unit_price'    => 0,
            'subtotal'      => 0,
        ]);
        $this->svc->confirmExit($exit);

        $je = JournalEntry::where('reference_type', 'stock_exit')
            ->where('reference_id', $exit->id)
            ->first();
        $this->assertNotNull($je, 'Journal entry should be created');

        $debitLine = $je->lines()->where('debit', '>', 0)->first();
        $this->assertEquals('632', $debitLine?->account_code);
    }

    /** @test */
    public function project_wip_entry_created_per_exit_item(): void
    {
        [$po, $poItem] = $this->makePo(10);
        $entry = $this->makeEntryDraft($po, $poItem, 5, '2026-06-01');
        $this->svc->confirmEntry($entry);

        $exit = $this->makeExitDraft(3, 'project_cost');
        $this->svc->confirmExit($exit);

        $wip = ProjectWipEntry::where('source_type', StockExit::class)
            ->where('source_id', $exit->id)
            ->first();

        $this->assertNotNull($wip, 'WIP entry should be created for project_cost exit');
        $this->assertEquals($this->project->id, $wip->project_id);
        $this->assertEquals('material', $wip->cost_type);
        $this->assertEquals($this->product->id, $wip->product_id);
        $this->assertEquals(3, (float) $wip->quantity);
        $this->assertEquals(300000, (float) $wip->amount);
    }

    /** @test */
    public function exit_item_total_cost_matches_fifo_allocation_amount(): void
    {
        [$po, $poItem] = $this->makePo(10);
        $entry = $this->makeEntryDraft($po, $poItem, 5, '2026-06-01');
        $this->svc->confirmEntry($entry);

        $exit = $this->makeExitDraft(3, 'project_cost');
        $this->svc->confirmExit($exit);

        $exitItem = StockExitItem::where('stock_exit_id', $exit->id)->first();
        $this->assertEquals(300000, (float) $exitItem->total_cost);
        $this->assertEquals(100000, (float) $exitItem->source_cost);
    }
}
