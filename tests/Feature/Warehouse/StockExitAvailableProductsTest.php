<?php

namespace Tests\Feature\Warehouse;

use App\Enums\PurchaseInvoiceStatus;
use App\Models\AccountCode;
use App\Models\AccountingPeriod;
use App\Models\Customer;
use App\Models\InventoryBalance;
use App\Models\Product;
use App\Models\Project;
use App\Models\ProjectInventoryLot;
use App\Models\PurchaseOrder;
use App\Models\StockEntry;
use App\Models\StockEntryItem;
use App\Models\StockExit;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * TC1: search.warehouse-products — chỉ trả SP có tồn ở kho A, không trả SP kho B
 * TC2: search.warehouse-products — product ở kho A qty=0 không hiển thị
 * TC3: search.warehouse-products project mode — lấy từ project_inventory_lots
 * TC4: store() backend validation — chặn khi qty > tồn kho (non-project)
 * TC5: store() backend validation — chặn khi qty > tồn project lots
 * TC6: store() pass — tồn đủ (non-project)
 */
class StockExitAvailableProductsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Warehouse $warehouseA;
    private Warehouse $warehouseB;
    private Product $productA; // chỉ có tồn ở kho A
    private Product $productB; // chỉ có tồn ở kho B
    private Product $productBoth; // có tồn ở cả hai kho

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::firstOrCreate(
            ['email' => 'admin@test.local'],
            ['name' => 'Admin', 'password' => bcrypt('pass'), 'is_active' => true]
        );
        $this->actingAs($this->user);
        Gate::before(fn ($u, $a) => true);

        AccountingPeriod::create(['year' => 2026, 'month' => 6, 'status' => 'open']);

        $this->warehouseA = Warehouse::create(['name' => 'Kho A', 'code' => 'KHO-A']);
        $this->warehouseB = Warehouse::create(['name' => 'Kho B', 'code' => 'KHO-B']);

        $this->productA    = Product::create(['code' => 'SP-A001', 'name' => 'Sản phẩm A', 'unit' => 'cái', 'cost_price' => 100000, 'is_active' => true]);
        $this->productB    = Product::create(['code' => 'SP-B001', 'name' => 'Sản phẩm B', 'unit' => 'hộp', 'cost_price' => 200000, 'is_active' => true]);
        $this->productBoth = Product::create(['code' => 'SP-AB01', 'name' => 'Sản phẩm AB', 'unit' => 'bộ',  'cost_price' => 300000, 'is_active' => true]);

        // Seed inventory_balances
        InventoryBalance::create([
            'product_id'   => $this->productA->id,
            'warehouse_id' => $this->warehouseA->id,
            'qty_on_hand'  => 50,
            'value_on_hand' => 5000000,
            'avg_cost'     => 100000,
        ]);
        InventoryBalance::create([
            'product_id'   => $this->productB->id,
            'warehouse_id' => $this->warehouseB->id,
            'qty_on_hand'  => 30,
            'value_on_hand' => 6000000,
            'avg_cost'     => 200000,
        ]);
        InventoryBalance::create([
            'product_id'   => $this->productBoth->id,
            'warehouse_id' => $this->warehouseA->id,
            'qty_on_hand'  => 20,
            'value_on_hand' => 6000000,
            'avg_cost'     => 300000,
        ]);
        InventoryBalance::create([
            'product_id'   => $this->productBoth->id,
            'warehouse_id' => $this->warehouseB->id,
            'qty_on_hand'  => 15,
            'value_on_hand' => 4500000,
            'avg_cost'     => 300000,
        ]);
    }

    // ─── TC1: Chỉ trả SP có tồn ở kho A, không trả SP chỉ ở kho B ──────────

    public function test_tc1_warehouse_products_returns_only_products_in_requested_warehouse(): void
    {
        $res = $this->getJson(route('search.warehouse-products', ['warehouse_id' => $this->warehouseA->id]));

        $res->assertStatus(200);
        $data = collect($res->json('data'));

        $ids = $data->pluck('value')->all();
        $this->assertContains($this->productA->id, $ids, 'productA phải có trong kho A');
        $this->assertContains($this->productBoth->id, $ids, 'productBoth phải có trong kho A');
        $this->assertNotContains($this->productB->id, $ids, 'productB chỉ có ở kho B — không hiển thị kho A');
    }

    // ─── TC2: SP có tồn = 0 không được trả về ────────────────────────────────

    public function test_tc2_product_with_zero_stock_not_returned(): void
    {
        $productZero = Product::create(['code' => 'SP-Z001', 'name' => 'Hàng hết tồn', 'cost_price' => 50000, 'is_active' => true]);
        InventoryBalance::create([
            'product_id'   => $productZero->id,
            'warehouse_id' => $this->warehouseA->id,
            'qty_on_hand'  => 0,
            'value_on_hand' => 0,
            'avg_cost'     => 0,
        ]);

        $res = $this->getJson(route('search.warehouse-products', ['warehouse_id' => $this->warehouseA->id]));
        $ids = collect($res->json('data'))->pluck('value')->all();

        $this->assertNotContains($productZero->id, $ids, 'SP tồn = 0 không được hiển thị');
    }

    // ─── TC3: Project mode — lấy từ project_inventory_lots ───────────────────

    public function test_tc3_project_mode_returns_from_project_lots(): void
    {
        $customer = \App\Models\Customer::create(['code' => 'KH-TEST', 'name' => 'KH test', 'phone' => '0900000009']);
        $project = Project::create([
            'code'        => 'DA-TEST',
            'name'        => 'Dự án test',
            'status'      => 'in_progress',
            'customer_id' => $customer->id,
            'created_by'  => $this->user->id,
        ]);

        $po = PurchaseOrder::create([
            'code' => 'MH-TEST-LOT',
            'supplier_id' => Supplier::create(['code' => 'NCC-L', 'name' => 'NCC Lot', 'phone' => '0900000000'])->id,
            'warehouse_id' => $this->warehouseA->id,
            'order_date' => '2026-06-01',
            'status' => 'received',
            'total' => 5000000,
            'created_by' => $this->user->id,
        ]);

        $entry = StockEntry::create([
            'code'              => 'NK-LOT-TEST',
            'warehouse_id'      => $this->warehouseA->id,
            'purchase_order_id' => $po->id,
            'status'            => 'confirmed',
            'entry_date'        => '2026-06-01',
            'created_by'        => $this->user->id,
        ]);

        $entryItem = StockEntryItem::create([
            'stock_entry_id' => $entry->id,
            'product_id'     => $this->productA->id,
            'quantity'       => 10,
            'unit_price'     => 100000,
            'unit_cost'      => 100000,
            'tax_rate'       => 10,
        ]);

        // Tạo lot cho productA ở dự án
        ProjectInventoryLot::create([
            'project_id'          => $project->id,
            'product_id'          => $this->productA->id,
            'warehouse_id'        => $this->warehouseA->id,
            'stock_entry_id'      => $entry->id,
            'stock_entry_item_id' => $entryItem->id,
            'purchase_order_id'   => $po->id,
            'received_qty'        => 10,
            'issued_qty'          => 3,
            'unit_cost'           => 100000,
            'received_at'         => now(),
            'status'              => 'active',
        ]);

        $res = $this->getJson(route('search.warehouse-products', [
            'warehouse_id' => $this->warehouseA->id,
            'project_id'   => $project->id,
        ]));

        $res->assertStatus(200);
        $data = collect($res->json('data'));

        // productA: lot=7 + AVCO=50 (setUp seed) → tổng 57
        $productAData = $data->firstWhere('value', $this->productA->id);
        $this->assertNotNull($productAData, 'productA phải xuất hiện (lot DA + AVCO)');
        $this->assertEquals(57, $productAData['qty'], 'Qty = lot(7) + AVCO(50) = 57');
        $this->assertStringContainsString('lô DA', $productAData['meta']);

        // productBoth không có lot dự án, nhưng có AVCO=20 → vẫn hiển thị từ kho chung
        $productBothData = $data->firstWhere('value', $this->productBoth->id);
        $this->assertNotNull($productBothData, 'productBoth phải xuất hiện từ AVCO kho chung');
        $this->assertEquals(20, $productBothData['qty'], 'Qty = AVCO(20), không có lot dự án');
    }

    // ─── TC4: Backend block khi qty > tồn kho non-project ────────────────────

    public function test_tc4_store_blocks_when_quantity_exceeds_non_project_stock(): void
    {
        foreach (['1561', '1331', '3311', '331', '6321'] as $code) {
            AccountCode::firstOrCreate(['code' => $code], [
                'name' => "TK {$code}", 'type' => 'asset', 'normal_balance' => 'debit',
                'level' => 2, 'is_detail' => true, 'is_active' => true,
            ]);
        }

        $res = $this->post(route('warehouse.stock-exits.store'), [
            'code'           => 'XK-TEST-BLOCK',
            'exit_date'      => '2026-06-15',
            'warehouse_id'   => $this->warehouseA->id,
            'item_usage_type' => 'commercial',
            'issue_purpose'  => 'sale_delivery',
            'items'          => [
                [
                    'product_id' => $this->productA->id,
                    'quantity'   => 999, // tồn chỉ có 50
                    'unit_price' => 150000,
                    'serial_ids' => [],
                ],
            ],
        ]);

        $res->assertRedirect();
        $res->assertSessionHasErrors('items');
        $errMsg = session('errors')->first('items');
        $this->assertStringContainsString('Không đủ tồn kho', $errMsg);
        $this->assertStringContainsString('SP-A001', $errMsg);
        $this->assertStringContainsString('Kho A', $errMsg);
    }

    // ─── TC5: Backend block khi qty > project lots ────────────────────────────

    public function test_tc5_store_blocks_when_quantity_exceeds_project_lots(): void
    {
        $customer = \App\Models\Customer::create(['code' => 'KH-BLK', 'name' => 'KH block', 'phone' => '0900000002']);
        $project = Project::create([
            'code'        => 'DA-BLOCK',
            'name'        => 'Dự án block test',
            'status'      => 'in_progress',
            'customer_id' => $customer->id,
            'created_by'  => $this->user->id,
        ]);

        $po = PurchaseOrder::create([
            'code' => 'MH-BLOCK',
            'supplier_id' => Supplier::create(['code' => 'NCC-BLK', 'name' => 'NCC Block', 'phone' => '0900000001'])->id,
            'warehouse_id' => $this->warehouseA->id,
            'order_date' => '2026-06-01',
            'status' => 'received',
            'total' => 3000000,
            'created_by' => $this->user->id,
        ]);

        $entry = StockEntry::create([
            'code'              => 'NK-BLK',
            'warehouse_id'      => $this->warehouseA->id,
            'purchase_order_id' => $po->id,
            'status'            => 'confirmed',
            'entry_date'        => '2026-06-01',
            'created_by'        => $this->user->id,
        ]);

        $entryItem = StockEntryItem::create([
            'stock_entry_id' => $entry->id,
            'product_id'     => $this->productBoth->id,
            'quantity'       => 5,
            'unit_price'     => 300000,
            'unit_cost'      => 300000,
            'tax_rate'       => 10,
        ]);

        ProjectInventoryLot::create([
            'project_id'          => $project->id,
            'product_id'          => $this->productBoth->id,
            'warehouse_id'        => $this->warehouseA->id,
            'stock_entry_id'      => $entry->id,
            'stock_entry_item_id' => $entryItem->id,
            'purchase_order_id'   => $po->id,
            'received_qty'        => 5,
            'issued_qty'          => 0,
            'unit_cost'           => 300000,
            'received_at'         => now(),
            'status'              => 'active',
        ]);

        // lot dự án = 5, AVCO kho chung (setUp) = 20 → tổng = 25
        // Yêu cầu 30 → vượt cả lot + AVCO → phải bị chặn
        $res = $this->post(route('warehouse.stock-exits.store'), [
            'code'           => 'XK-TEST-PROJECT-BLOCK',
            'exit_date'      => '2026-06-15',
            'warehouse_id'   => $this->warehouseA->id,
            'item_usage_type' => 'project',
            'issue_purpose'  => 'project_cost',
            'project_id'     => $project->id,
            'items'          => [
                [
                    'product_id' => $this->productBoth->id,
                    'quantity'   => 30, // lot(5) + AVCO(20) = 25 < 30 → chặn
                    'unit_price' => 0,
                    'serial_ids' => [],
                ],
            ],
        ]);

        $res->assertRedirect();
        $res->assertSessionHasErrors('items');
        $this->assertStringContainsString('Không đủ tồn kho', session('errors')->first('items'));
    }

    // ─── TC6: store() pass khi tồn đủ (non-project) ──────────────────────────

    public function test_tc6_store_passes_when_stock_sufficient(): void
    {
        foreach (['1561', '1331', '3311', '331', '6321', '5111', '131', '1311'] as $code) {
            AccountCode::firstOrCreate(['code' => $code], [
                'name' => "TK {$code}", 'type' => 'asset', 'normal_balance' => 'debit',
                'level' => 2, 'is_detail' => true, 'is_active' => true,
            ]);
        }

        $res = $this->post(route('warehouse.stock-exits.store'), [
            'code'           => 'XK-TEST-PASS',
            'exit_date'      => '2026-06-15',
            'warehouse_id'   => $this->warehouseA->id,
            'item_usage_type' => 'commercial',
            'issue_purpose'  => 'sale_delivery',
            'items'          => [
                [
                    'product_id' => $this->productA->id,
                    'quantity'   => 10, // tồn = 50 → đủ
                    'unit_price' => 150000,
                    'serial_ids' => [],
                ],
            ],
        ]);

        // Phải redirect đến show page (không có errors)
        $res->assertSessionHasNoErrors();
        $this->assertDatabaseHas('stock_exits', ['code' => 'XK-TEST-PASS']);
    }
}
