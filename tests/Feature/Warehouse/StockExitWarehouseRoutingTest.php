<?php

namespace Tests\Feature\Warehouse;

use App\Enums\OrderStatus;
use App\Enums\StockExitStatus;
use App\Models\AccountCode;
use App\Models\AccountingPeriod;
use App\Models\Customer;
use App\Models\InventoryBalance;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Project;
use App\Models\StockExit;
use App\Models\StockExitItem;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Kiểm tra routing warehouse_id đúng trong luồng project_cost exit.
 *
 * TC1: product có AVCO rows toàn qty=0 → stock_by_warehouse dùng movement fallback (show đúng kho nguồn)
 * TC2: checkStockAvailability với kho nguồn có AVCO → pass, không lỗi
 * TC3: checkStockAvailability với kho dự án (không có tồn) → fail + gợi ý "Kho khác có tồn: kho_nguon"
 * TC4: store() với warehouse_id = kho nguồn (AVCO initialized) → exit được tạo đúng warehouse
 * TC5: store() với warehouse_id = kho dự án (no stock) → session errors với suggestion kho khác
 */
class StockExitWarehouseRoutingTest extends TestCase
{
    use RefreshDatabase;

    private User      $user;
    private Warehouse $srcWarehouse;   // Kho nguồn thực tế (có hàng)
    private Warehouse $projWarehouse;  // Kho dự án (không có hàng)
    private Product   $product;
    private Customer  $customer;
    private Project   $project;
    private Order     $order;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $this->user = User::firstOrCreate(
            ['email' => 'routing@test.local'],
            ['name' => 'Routing Test', 'password' => bcrypt('x'), 'is_active' => true]
        );
        $this->actingAs($this->user);
        Gate::before(fn ($u, $a) => true);

        AccountingPeriod::create(['year' => 2026, 'month' => 6, 'status' => 'open']);

        $this->srcWarehouse  = Warehouse::create(['name' => 'Kho Cty TNHH', 'code' => 'CTY', 'address' => 'HN']);
        $this->projWarehouse = Warehouse::create(['name' => 'Kho dự án Visicon', 'code' => 'VISI', 'address' => 'HN']);

        $this->customer = Customer::create([
            'code' => 'KH-VRT', 'name' => 'KH Visicon', 'is_active' => true,
        ]);

        $this->project = Project::create([
            'code'        => 'DA-VRT',
            'name'        => 'Dự án Visicon',
            'status'      => 'in_progress',
            'customer_id' => $this->customer->id,
            'created_by'  => $this->user->id,
        ]);

        $this->product = Product::create([
            'code'        => 'SP-VRT',
            'name'        => 'SP Routing Test',
            'unit'        => 'cái',
            'cost_price'  => 1100000,
            'vat_percent' => 10,
            'item_type'   => 'product',
            'is_active'   => true,
        ]);

        $this->order = Order::create([
            'code'        => 'DH-VRT',
            'customer_id' => $this->customer->id,
            'project_id'  => $this->project->id,
            'order_date'  => now()->toDateString(),
            'status'      => OrderStatus::Processing,
            'created_by'  => $this->user->id,
        ]);

        OrderItem::create([
            'order_id'   => $this->order->id,
            'product_id' => $this->product->id,
            'name'       => 'SP Routing Test',
            'quantity'   => 13,
            'unit_price' => 2000000,
            'subtotal'   => 26000000,
        ]);

        $this->seedAccountCodes();
    }

    private function seedAccountCodes(): void
    {
        foreach ([
            ['code' => '156',  'name' => 'Hàng hoá',     'type' => 'asset',   'normal_balance' => 'debit',  'is_detail' => false],
            ['code' => '1561', 'name' => 'Hàng hoá kho', 'type' => 'asset',   'normal_balance' => 'debit',  'is_detail' => true],
            ['code' => '154',  'name' => 'CP SX dở dang','type' => 'asset',   'normal_balance' => 'debit',  'is_detail' => true],
            ['code' => '632',  'name' => 'Giá vốn',      'type' => 'expense', 'normal_balance' => 'debit',  'is_detail' => true],
        ] as $acc) {
            AccountCode::updateOrCreate(['code' => $acc['code']], array_merge($acc, ['level' => 3, 'is_active' => true]));
        }
    }

    // ── TC1: stock_by_warehouse dùng movement fallback khi AVCO chỉ có qty=0 ────

    public function test_tc1_order_show_uses_movement_fallback_when_avco_all_zero(): void
    {
        // AVCO chỉ có row tại kho dự án với qty=0 (đã xuất hết từ trước)
        InventoryBalance::create([
            'product_id'   => $this->product->id,
            'warehouse_id' => $this->projWarehouse->id,
            'qty_on_hand'  => 0,
            'value_on_hand'=> 0,
            'avg_cost'     => 1000000,
        ]);

        // Hàng thực tế tại kho nguồn (qua stock_movements, AVCO chưa init tại đây)
        StockMovement::create([
            'product_id'   => $this->product->id,
            'warehouse_id' => $this->srcWarehouse->id,
            'type'         => 'in',
            'quantity'     => 13,
            'created_by'   => $this->user->id,
        ]);

        $resp = $this->get(route('sales.orders.show', $this->order->id));
        $resp->assertOk();

        $items = $resp->viewData('page')['props']['order']['items'] ?? null;
        $this->assertNotNull($items, 'Props order.items phải tồn tại');

        $stockByWh = $items[0]['stock_by_warehouse'] ?? [];
        $this->assertNotEmpty($stockByWh, 'stock_by_warehouse phải dùng movement fallback, không được rỗng');

        $warehouseIds = collect($stockByWh)->pluck('warehouse_id')->all();
        $this->assertContains(
            $this->srcWarehouse->id,
            $warehouseIds,
            "stock_by_warehouse phải chứa kho nguồn ({$this->srcWarehouse->name}), không phải kho dự án"
        );
        $this->assertNotContains(
            $this->projWarehouse->id,
            $warehouseIds,
            "stock_by_warehouse không được chứa kho dự án (qty=0)"
        );
    }

    // ── TC2: checkStockAvailability với kho nguồn có AVCO → pass ────────────────

    public function test_tc2_check_stock_passes_when_source_warehouse_has_avco(): void
    {
        InventoryBalance::create([
            'product_id'   => $this->product->id,
            'warehouse_id' => $this->srcWarehouse->id,
            'qty_on_hand'  => 13,
            'value_on_hand'=> 13000000,
            'avg_cost'     => 1000000,
        ]);

        $resp = $this->post(route('warehouse.stock-exits.store'), [
            'code'          => 'XK-VRT-01',
            'warehouse_id'  => $this->srcWarehouse->id,
            'exit_date'     => now()->toDateString(),
            'issue_purpose' => 'project_cost',
            'item_usage_type' => 'project',
            'project_id'    => $this->project->id,
            'order_id'      => $this->order->id,
            'reason'        => 'Xuất dùng cho dự án Visicon',
            'items'         => [[
                'product_id'    => $this->product->id,
                'quantity'      => 13,
                'unit_price'    => 2000000,
                'serial_ids'    => [],
            ]],
        ]);

        $resp->assertSessionHasNoErrors();
        $resp->assertRedirect();

        $this->assertDatabaseHas('stock_exits', [
            'warehouse_id' => $this->srcWarehouse->id,
            'project_id'   => $this->project->id,
        ]);
    }

    // ── TC3: checkStockAvailability với kho dự án (no stock) → fail + gợi ý ─────

    public function test_tc3_check_stock_fails_with_suggestion_when_wrong_warehouse(): void
    {
        // AVCO tại kho nguồn (có hàng)
        InventoryBalance::create([
            'product_id'   => $this->product->id,
            'warehouse_id' => $this->srcWarehouse->id,
            'qty_on_hand'  => 13,
            'value_on_hand'=> 13000000,
            'avg_cost'     => 1000000,
        ]);

        // Không có tồn tại kho dự án
        InventoryBalance::create([
            'product_id'   => $this->product->id,
            'warehouse_id' => $this->projWarehouse->id,
            'qty_on_hand'  => 0,
            'value_on_hand'=> 0,
            'avg_cost'     => 1000000,
        ]);

        // User submit với kho dự án (sai)
        $resp = $this->post(route('warehouse.stock-exits.store'), [
            'code'          => 'XK-VRT-02',
            'warehouse_id'  => $this->projWarehouse->id,   // SAI: kho dự án
            'exit_date'     => now()->toDateString(),
            'issue_purpose' => 'project_cost',
            'item_usage_type' => 'project',
            'project_id'    => $this->project->id,
            'order_id'      => $this->order->id,
            'reason'        => 'Xuất dùng dự án Visicon',
            'items'         => [[
                'product_id'    => $this->product->id,
                'quantity'      => 13,
                'unit_price'    => 2000000,
                'serial_ids'    => [],
            ]],
        ]);

        // Phải trả về lỗi (stock errors flash vào errors.items)
        $resp->assertSessionHasErrors('items');
        $errorMsg = session('errors')->get('items')[0];

        $this->assertStringContainsString(
            $this->projWarehouse->name,
            $errorMsg,
            'Error message phải mention kho dự án đang chọn'
        );
        $this->assertStringContainsString(
            'Kho khác có tồn',
            $errorMsg,
            'Error message phải gợi ý kho khác đang có tồn'
        );
        $this->assertStringContainsString(
            $this->srcWarehouse->name,
            $errorMsg,
            'Error message phải mention kho nguồn thực tế'
        );
    }

    // ── TC4: Exit được tạo với đúng warehouse_id từ form ────────────────────────

    public function test_tc4_exit_stores_with_source_warehouse_id(): void
    {
        InventoryBalance::create([
            'product_id'   => $this->product->id,
            'warehouse_id' => $this->srcWarehouse->id,
            'qty_on_hand'  => 20,
            'value_on_hand'=> 20000000,
            'avg_cost'     => 1000000,
        ]);

        $resp = $this->post(route('warehouse.stock-exits.store'), [
            'code'          => 'XK-VRT-03',
            'warehouse_id'  => $this->srcWarehouse->id,
            'exit_date'     => now()->toDateString(),
            'issue_purpose' => 'project_cost',
            'item_usage_type' => 'project',
            'project_id'    => $this->project->id,
            'reason'        => 'Xuất dùng dự án',
            'items'         => [[
                'product_id' => $this->product->id,
                'quantity'   => 5,
                'unit_price' => 2000000,
                'serial_ids' => [],
            ]],
        ]);

        $resp->assertSessionHasNoErrors();

        $exit = StockExit::where('code', 'XK-VRT-03')->first();
        $this->assertNotNull($exit);
        $this->assertEquals(
            $this->srcWarehouse->id,
            $exit->warehouse_id,
            'Exit phải lưu warehouse_id của kho nguồn, không phải kho dự án'
        );
        $this->assertNotEquals(
            $this->projWarehouse->id,
            $exit->warehouse_id,
            'Exit không được lưu warehouse_id kho dự án'
        );
    }

    // ── TC5: stock_by_warehouse rỗng không xảy ra khi có movement tại kho nguồn ─

    public function test_tc5_stock_by_warehouse_nonempty_prevents_missing_warehouse_id_url(): void
    {
        // Tình huống: sản phẩm KHÔNG có AVCO rows ở bất kỳ kho nào
        // Nhưng có stock_movements tại kho nguồn
        StockMovement::create([
            'product_id'   => $this->product->id,
            'warehouse_id' => $this->srcWarehouse->id,
            'type'         => 'in',
            'quantity'     => 13,
            'created_by'   => $this->user->id,
        ]);

        // Không có inventory_balances rows (product chưa qua AVCO)
        $this->assertDatabaseMissing('inventory_balances', ['product_id' => $this->product->id]);

        $resp = $this->get(route('sales.orders.show', $this->order->id));
        $resp->assertOk();

        $items = $resp->viewData('page')['props']['order']['items'] ?? null;
        $stockByWh = $items[0]['stock_by_warehouse'] ?? [];

        $this->assertNotEmpty($stockByWh, 'Phải dùng movement fallback khi không có AVCO rows');
        $this->assertEquals(
            $this->srcWarehouse->id,
            $stockByWh[0]['warehouse_id'],
            'stock_by_warehouse[0].warehouse_id phải là kho nguồn (từ movements)'
        );
        $this->assertEquals(13, $stockByWh[0]['qty'], 'Qty phải là 13 từ movement');
    }
}
