<?php

namespace Tests\Feature\Warehouse;

use App\Enums\PurchaseOrderStatus;
use App\Enums\StockEntryStatus;
use App\Enums\StockExitStatus;
use App\Models\AccountCode;
use App\Models\Customer;
use App\Models\InventoryBalance;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Project;
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
use App\Services\AvcoService;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AvcoTest extends TestCase
{
    use RefreshDatabase;

    private StockService $svc;
    private AvcoService $avco;
    private User $user;
    private Warehouse $warehouse;
    private Supplier $supplier;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $this->svc  = app(StockService::class);
        $this->avco = app(AvcoService::class);
        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        foreach ([
            ['code' => '1331', 'name' => 'Thuế GTGT KT',  'type' => 'asset',     'normal_balance' => 'debit',  'is_detail' => true],
            ['code' => '1561', 'name' => 'Hàng hóa',      'type' => 'asset',     'normal_balance' => 'debit',  'is_detail' => true],
            ['code' => '3311', 'name' => 'Phải trả NCC',  'type' => 'liability', 'normal_balance' => 'credit', 'is_detail' => true],
            ['code' => '632',  'name' => 'Giá vốn HH',    'type' => 'expense',   'normal_balance' => 'debit',  'is_detail' => true],
            ['code' => '154',  'name' => 'CP SXKD DD',    'type' => 'asset',     'normal_balance' => 'debit',  'is_detail' => true],
        ] as $c) {
            AccountCode::firstOrCreate(['code' => $c['code']], $c);
        }

        $this->warehouse = Warehouse::create(['code' => 'WH-A', 'name' => 'Test WH']);

        $this->supplier = Supplier::create([
            'code' => 'NCC-A',
            'name' => 'Test Supplier',
            'payable_account_code' => '3311',
        ]);

        $cat = ProductCategory::create(['name' => 'Cat']);
        $this->product = Product::create([
            'code'        => 'SP-A',
            'name'        => 'Test Product',
            'category_id' => $cat->id,
            'cost_price'  => 220000, // incl VAT 10% → excl = 200000
            'vat_percent' => 10,
        ]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function makeNonProjectEntry(int $qty, float $unitPriceExcl): StockEntry
    {
        $po = PurchaseOrder::create([
            'code'        => 'MH-' . uniqid(),
            'supplier_id' => $this->supplier->id,
            'warehouse_id'=> $this->warehouse->id,
            'status'      => PurchaseOrderStatus::Sent,
            'order_date'  => now()->toDateString(),
            'created_by'  => $this->user->id,
            'subtotal' => 0, 'tax' => 0, 'total' => 0,
        ]);
        $poItem = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'product_id'        => $this->product->id,
            'quantity'          => $qty,
            'received_quantity' => 0,
            'unit_price'        => $unitPriceExcl,
            'vat_rate'          => 10,
            'subtotal'          => $qty * $unitPriceExcl,
        ]);

        $entry = StockEntry::create([
            'code'              => 'NK-' . uniqid(),
            'warehouse_id'      => $this->warehouse->id,
            'supplier_id'       => $this->supplier->id,
            'purchase_order_id' => $po->id,
            'status'            => StockEntryStatus::Draft,
            'entry_date'        => now()->toDateString(),
            'created_by'        => $this->user->id,
        ]);
        StockEntryItem::create([
            'stock_entry_id'         => $entry->id,
            'product_id'             => $this->product->id,
            'quantity'               => $qty,
            'unit_price'             => $unitPriceExcl,
            'tax_rate'               => 10,
            'purchase_order_item_id' => $poItem->id,
            'project_id'             => null,
            'unit_cost'              => $unitPriceExcl,
        ]);
        return $entry;
    }

    private function makeNonProjectExit(int $qty): StockExit
    {
        $exit = StockExit::create([
            'code'           => 'XK-' . uniqid(),
            'warehouse_id'   => $this->warehouse->id,
            'project_id'     => null,
            'issue_purpose'  => 'sale_delivery',
            'item_usage_type'=> 'commercial',
            'status'         => StockExitStatus::Draft,
            'exit_date'      => now()->toDateString(),
            'created_by'     => $this->user->id,
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

    // ─── Test cases ──────────────────────────────────────────────────────────

    /** T1: confirmEntry non-project → inventory_balances created */
    public function test_entry_creates_avco_balance(): void
    {
        $entry = $this->makeNonProjectEntry(10, 100000);
        $this->svc->confirmEntry($entry);

        $balance = InventoryBalance::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->first();

        $this->assertNotNull($balance);
        $this->assertEquals(10.0, (float) $balance->qty_on_hand);
        $this->assertEquals(1000000.0, (float) $balance->value_on_hand);
        $this->assertEquals(100000.0, (float) $balance->avg_cost);
        $this->assertNotNull($balance->last_movement_id);
    }

    /** T2: AVCO formula after two entries: (10×100k + 20×130k) / 30 = 120k */
    public function test_avco_formula_two_entries(): void
    {
        $e1 = $this->makeNonProjectEntry(10, 100000);
        $this->svc->confirmEntry($e1);

        $e2 = $this->makeNonProjectEntry(20, 130000);
        $this->svc->confirmEntry($e2);

        $balance = InventoryBalance::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->first();

        $this->assertEquals(30.0,     (float) $balance->qty_on_hand);
        $this->assertEquals(3600000.0,(float) $balance->value_on_hand);
        $this->assertEquals(120000.0, (float) $balance->avg_cost);
    }

    /** T3: confirmExit uses avg_cost from AVCO; JE Dr 632 / Cr 1561 correct */
    public function test_exit_uses_avco_cost_and_posts_je(): void
    {
        $entry = $this->makeNonProjectEntry(10, 100000);
        $this->svc->confirmEntry($entry);

        $exit = $this->makeNonProjectExit(5);
        $this->svc->confirmExit($exit);

        $exitItem = $exit->items()->first();
        $this->assertEquals('avco',    $exitItem->cost_source);
        $this->assertEquals(100000.0,  (float) $exitItem->source_cost);
        $this->assertEquals(500000.0,  (float) $exitItem->total_cost);

        // JE should have Dr 632 / Cr 1561 (tryPost creates as 'draft', reference_type = 'stock_exit')
        $je = JournalEntry::where('reference_type', 'stock_exit')
            ->where('reference_id', $exit->id)
            ->first();
        $this->assertNotNull($je, 'Journal entry should be created by postExitJournal');

        $lines = $je->lines()->get()->keyBy('account_code');
        $this->assertEquals(500000.0, (float) ($lines['632']->debit ?? 0));
        $this->assertEquals(500000.0, (float) ($lines['1561']->credit ?? 0));

        // AVCO balance: 5 units remaining
        $balance = InventoryBalance::where('product_id', $this->product->id)->first();
        $this->assertEquals(5.0,       (float) $balance->qty_on_hand);
        $this->assertEquals(500000.0,  (float) $balance->value_on_hand);
        $this->assertEquals(100000.0,  (float) $balance->avg_cost);
    }

    /** T4: changing products.cost_price does NOT affect AVCO exit */
    public function test_cost_price_change_does_not_affect_avco(): void
    {
        $entry = $this->makeNonProjectEntry(10, 100000);
        $this->svc->confirmEntry($entry);

        // cost_price incl VAT now = 330000 → excl = 300000
        $this->product->update(['cost_price' => 330000]);

        $exit = $this->makeNonProjectExit(3);
        $this->svc->confirmExit($exit);

        $exitItem = $exit->items()->first();
        $this->assertEquals('avco',   $exitItem->cost_source);
        $this->assertEquals(100000.0, (float) $exitItem->source_cost, 'Phải dùng AVCO 100k, không phải cost_price mới');
    }

    /** T5: no inventory_balances → confirmExit throws RuntimeException */
    public function test_exit_throws_when_no_avco_balance(): void
    {
        // Insert a stock movement directly (bypass confirmEntry → no AVCO balance created)
        StockMovement::create([
            'product_id'  => $this->product->id,
            'warehouse_id'=> $this->warehouse->id,
            'type'        => 'in',
            'quantity'    => 10,
            'unit_cost'   => 100000,
            'amount'      => 1000000,
            'source_type' => StockEntry::class,
            'source_id'   => 999,
            'created_by'  => $this->user->id,
        ]);

        $exit = $this->makeNonProjectExit(5);
        $this->expectException(\RuntimeException::class);
        $this->svc->confirmExit($exit);
    }

    /** T6: project exit still uses FIFO; AVCO balance NOT created for project entry */
    public function test_project_exit_uses_fifo_avco_unaffected(): void
    {
        $customer = Customer::create(['code' => 'KH-A', 'name' => 'Khách']);
        AccountCode::firstOrCreate(['code' => '154'], [
            'name' => 'CP SXKD DD', 'type' => 'asset', 'normal_balance' => 'debit', 'is_detail' => true,
        ]);
        $project = Project::create([
            'code'        => 'DA-A',
            'name'        => 'Test Project',
            'status'      => 'in_progress',
            'customer_id' => $customer->id,
            'created_by'  => $this->user->id,
        ]);

        $po = PurchaseOrder::create([
            'code'        => 'MH-' . uniqid(),
            'supplier_id' => $this->supplier->id,
            'warehouse_id'=> $this->warehouse->id,
            'project_id'  => $project->id,
            'status'      => PurchaseOrderStatus::Sent,
            'order_date'  => now()->toDateString(),
            'created_by'  => $this->user->id,
            'subtotal' => 0, 'tax' => 0, 'total' => 0,
        ]);
        $poItem = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'product_id'        => $this->product->id,
            'quantity'          => 10,
            'received_quantity' => 0,
            'unit_price'        => 100000,
            'vat_rate'          => 10,
            'subtotal'          => 1000000,
            'project_id'        => $project->id,
        ]);
        $entry = StockEntry::create([
            'code'              => 'NK-' . uniqid(),
            'warehouse_id'      => $this->warehouse->id,
            'supplier_id'       => $this->supplier->id,
            'purchase_order_id' => $po->id,
            'status'            => StockEntryStatus::Draft,
            'entry_date'        => now()->toDateString(),
            'created_by'        => $this->user->id,
        ]);
        StockEntryItem::create([
            'stock_entry_id'         => $entry->id,
            'product_id'             => $this->product->id,
            'quantity'               => 10,
            'unit_price'             => 100000,
            'tax_rate'               => 10,
            'purchase_order_item_id' => $poItem->id,
            'project_id'             => $project->id,
            'unit_cost'              => 100000,
        ]);
        $this->svc->confirmEntry($entry);

        // Project entry must NOT create AVCO balance
        $this->assertNull(
            InventoryBalance::where('product_id', $this->product->id)
                ->where('warehouse_id', $this->warehouse->id)
                ->first(),
            'Project entry không được tạo AVCO balance'
        );

        $exit = StockExit::create([
            'code'           => 'XK-' . uniqid(),
            'warehouse_id'   => $this->warehouse->id,
            'project_id'     => $project->id,
            'issue_purpose'  => 'project_cost',
            'item_usage_type'=> 'project',
            'status'         => StockExitStatus::Draft,
            'exit_date'      => now()->toDateString(),
            'created_by'     => $this->user->id,
        ]);
        StockExitItem::create([
            'stock_exit_id' => $exit->id,
            'product_id'    => $this->product->id,
            'quantity'      => 5,
            'unit_price'    => 0,
            'subtotal'      => 0,
        ]);
        $this->svc->confirmExit($exit);

        $exitItem = $exit->items()->first();
        $this->assertEquals('fifo',   $exitItem->cost_source);
        $this->assertEquals(100000.0, (float) $exitItem->source_cost);
        $this->assertEquals(500000.0, (float) $exitItem->total_cost);
    }

    /** T7: initializeFromOpeningBalance seeds inventory_balances correctly */
    public function test_initialize_from_opening_balance(): void
    {
        $this->avco->initializeFromOpeningBalance(
            $this->product->id,
            $this->warehouse->id,
            20.0,
            150000.0,
        );

        $balance = InventoryBalance::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->first();

        $this->assertNotNull($balance);
        $this->assertEquals(20.0,        (float) $balance->qty_on_hand);
        $this->assertEquals(3000000.0,   (float) $balance->value_on_hand);
        $this->assertEquals(150000.0,    (float) $balance->avg_cost);
        $this->assertEquals('opening_balance', $balance->initialized_from);
    }

    /** T8: re-submit opening balance updates AVCO (idempotent) */
    public function test_opening_balance_resubmit_updates_avco(): void
    {
        $this->avco->initializeFromOpeningBalance($this->product->id, $this->warehouse->id, 10.0, 100000.0);
        $this->avco->initializeFromOpeningBalance($this->product->id, $this->warehouse->id, 20.0, 150000.0);

        $balances = InventoryBalance::where('product_id', $this->product->id)->get();
        $this->assertCount(1, $balances, 'Chỉ được có 1 record cho mỗi product/warehouse');

        $balance = $balances->first();
        $this->assertEquals(20.0,      (float) $balance->qty_on_hand);
        $this->assertEquals(150000.0,  (float) $balance->avg_cost);
        $this->assertEquals(3000000.0, (float) $balance->value_on_hand);
    }
}
