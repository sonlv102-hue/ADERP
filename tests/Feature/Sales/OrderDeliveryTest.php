<?php

namespace Tests\Feature\Sales;

use App\Enums\OrderStatus;
use App\Models\AccountCode;
use App\Models\AccountingPeriod;
use App\Models\Customer;
use App\Models\InventoryBalance;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StockExit;
use App\Models\StockExitItem;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * TC1: Xuất đủ 10 → gợi ý không còn sản phẩm này
 * TC2: Xuất 4/10 → remaining = 6
 * TC3: Kho chọn = 0, kho khác = 13 → không "Mua hàng", gợi ý đổi kho
 * TC4: Toàn hệ thống = 0, remaining > 0 → Mua hàng
 * TC5: Bấm Xuất kho → form tự nhận order_id, customer_id, order_item_id
 * TC6: syncDelivery dùng order_item_id (không nhầm khi cùng product 2 dòng)
 * TC7: reverseDelivery dùng order_item_id
 */
class OrderDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Customer $customer;
    private Warehouse $warehouseA;
    private Warehouse $warehouseB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::firstOrCreate(
            ['email' => 'dtest@test.local'],
            ['name' => 'DTest', 'password' => bcrypt('x'), 'is_active' => true]
        );
        $this->actingAs($this->user);
        Gate::before(fn ($u, $a) => true);
        AccountingPeriod::create(['year' => 2026, 'month' => 6, 'status' => 'open']);
        $this->customer   = Customer::create(['code' => 'KH-DLV', 'name' => 'KH Delivery Test', 'is_active' => true]);
        $this->warehouseA = Warehouse::create(['name' => 'Kho Chính', 'code' => 'KA']);
        $this->warehouseB = Warehouse::create(['name' => 'Kho Phụ', 'code' => 'KB']);
    }

    private function makeProduct(string $code): \App\Models\Product
    {
        return \App\Models\Product::create([
            'code' => $code, 'name' => $code, 'unit' => 'cái',
            'cost_price' => 100000, 'is_active' => true,
        ]);
    }

    private function makeOrder(array $itemQtys = [10]): Order
    {
        $order = Order::create([
            'code'        => 'DH-DLV-' . uniqid(),
            'customer_id' => $this->customer->id,
            'status'      => OrderStatus::Processing,
            'created_by'  => $this->user->id,
            'order_date'  => now()->toDateString(),
        ]);
        foreach ($itemQtys as $idx => $qty) {
            $product = $this->makeProduct('SP-DLV-' . uniqid());
            OrderItem::create([
                'order_id'          => $order->id,
                'name'              => 'SP Test ' . ($idx + 1),
                'product_id'        => $product->id,
                'quantity'          => $qty,
                'unit_price'        => 1000,
                'delivered_quantity' => 0,
            ]);
        }
        return $order->fresh('items');
    }

    // TC1: ordered=10, exit=10 có order_item_id → remaining=0 → item không xuất hiện trong gợi ý
    public function test_tc1_fully_delivered_item_not_in_suggestion(): void
    {
        $order = $this->makeOrder([10]);
        $item  = $order->items->first();

        $exit = StockExit::create([
            'code' => 'XK-DLV-TC1', 'status' => 'confirmed',
            'warehouse_id' => $this->warehouseA->id,
            'order_id'     => $order->id,
            'exit_date'    => now()->toDateString(),
            'created_by'   => $this->user->id,
            'issue_purpose' => 'sale_delivery',
            'item_usage_type' => 'commercial',
        ]);
        StockExitItem::create([
            'stock_exit_id' => $exit->id,
            'product_id'    => $item->product_id,
            'order_item_id' => $item->id,
            'quantity'      => 10,
            'unit_price'    => 0,
        ]);

        (new OrderService())->syncDelivery($exit);

        $item->refresh();
        $this->assertEquals(10, (float) $item->delivered_quantity);
        $this->assertEquals(0, max(0, $item->quantity - $item->delivered_quantity));
    }

    // TC2: ordered=10, exit=4 → remaining=6
    public function test_tc2_partial_delivery_remaining_correct(): void
    {
        $order = $this->makeOrder([10]);
        $item  = $order->items->first();

        $exit = StockExit::create([
            'code' => 'XK-DLV-TC2', 'status' => 'confirmed',
            'warehouse_id' => $this->warehouseA->id,
            'order_id'     => $order->id,
            'exit_date'    => now()->toDateString(),
            'created_by'   => $this->user->id,
            'issue_purpose' => 'sale_delivery',
            'item_usage_type' => 'commercial',
        ]);
        StockExitItem::create([
            'stock_exit_id' => $exit->id, 'product_id' => $item->product_id,
            'order_item_id' => $item->id, 'quantity' => 4, 'unit_price' => 0,
        ]);

        (new OrderService())->syncDelivery($exit);

        $item->refresh();
        $this->assertEquals(4, (float) $item->delivered_quantity);
        $this->assertEquals(6, max(0, (float) $item->quantity - (float) $item->delivered_quantity));
    }

    // TC3: kho A=0, kho B=13 → OrderController trả stock_by_warehouse với kho B, current_stock=13
    public function test_tc3_stock_by_warehouse_shows_other_warehouse(): void
    {
        $order = Order::create([
            'code' => 'DH-DLV-TC3', 'customer_id' => $this->customer->id,
            'status' => OrderStatus::Processing, 'created_by' => $this->user->id,
            'order_date' => now()->toDateString(),
        ]);
        $product = \App\Models\Product::create([
            'code' => 'SP-DLV3', 'name' => 'SP DLV3', 'unit' => 'cái',
            'cost_price' => 100000, 'is_active' => true,
        ]);
        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $product->id,
            'name' => 'SP DLV3', 'quantity' => 8, 'unit_price' => 1000,
        ]);

        // Kho A: 0, Kho B: 13
        InventoryBalance::create([
            'product_id' => $product->id, 'warehouse_id' => $this->warehouseA->id,
            'qty_on_hand' => 0, 'value_on_hand' => 0, 'avg_cost' => 0,
        ]);
        InventoryBalance::create([
            'product_id' => $product->id, 'warehouse_id' => $this->warehouseB->id,
            'qty_on_hand' => 13, 'value_on_hand' => 1300000, 'avg_cost' => 100000,
        ]);

        $res = $this->get(route('sales.orders.show', $order->id));
        $res->assertOk();

        $items = $res->original->getData()['page']['props']['order']['items'];
        $i = collect($items)->first();
        // Kho B phải xuất hiện trong stock_by_warehouse (qty > 0)
        $this->assertNotEmpty($i['stock_by_warehouse']);
        $wbEntry = collect($i['stock_by_warehouse'])->firstWhere('warehouse_id', $this->warehouseB->id);
        $this->assertNotNull($wbEntry);
        $this->assertEquals(13.0, $wbEntry['qty']);
        // Kho A (qty=0) không xuất hiện
        $waEntry = collect($i['stock_by_warehouse'])->firstWhere('warehouse_id', $this->warehouseA->id);
        $this->assertNull($waEntry);
    }

    // TC4: tổng tồn = 0, remaining > 0 → stock_by_warehouse rỗng → UI nên hiện Mua hàng
    public function test_tc4_zero_stock_shows_empty_warehouse_list(): void
    {
        $order = Order::create([
            'code' => 'DH-DLV-TC4', 'customer_id' => $this->customer->id,
            'status' => OrderStatus::Processing, 'created_by' => $this->user->id,
            'order_date' => now()->toDateString(),
        ]);
        $product = \App\Models\Product::create([
            'code' => 'SP-DLV4', 'name' => 'SP DLV4', 'unit' => 'cái',
            'cost_price' => 100000, 'is_active' => true,
        ]);
        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $product->id,
            'name' => 'SP DLV4', 'quantity' => 5, 'unit_price' => 1000,
        ]);
        // Không tạo InventoryBalance → stock = 0

        $res = $this->get(route('sales.orders.show', $order->id));
        $res->assertOk();

        $items = $res->original->getData()['page']['props']['order']['items'];
        $i = collect($items)->first();
        $this->assertEquals(0, $i['current_stock']);
        $this->assertEmpty($i['stock_by_warehouse']);
    }

    // TC5: GET /warehouse/stock-exits/create?order_id=X → form nhận prefillOrderId
    public function test_tc5_create_form_receives_prefill_order_id(): void
    {
        $order = Order::create([
            'code' => 'DH-DLV-TC5', 'customer_id' => $this->customer->id,
            'status' => OrderStatus::Processing, 'created_by' => $this->user->id,
            'order_date' => now()->toDateString(),
        ]);

        $res = $this->get(route('warehouse.stock-exits.create') . '?order_id=' . $order->id);
        $res->assertOk();

        $props = $res->original->getData()['page']['props'];
        $this->assertEquals($order->id, $props['prefillOrderId']);
    }

    // TC6: syncDelivery dùng order_item_id — cùng product 2 dòng, cập nhật đúng dòng
    public function test_tc6_sync_delivery_uses_order_item_id_not_product_id(): void
    {
        $order = $this->makeOrder([5, 3]); // 2 dòng (giả sử cùng product, khác id)
        [$item1, $item2] = $order->items->values()->all();

        $exit = StockExit::create([
            'code' => 'XK-DLV-TC6', 'status' => 'confirmed',
            'warehouse_id' => $this->warehouseA->id,
            'order_id'     => $order->id,
            'exit_date'    => now()->toDateString(),
            'created_by'   => $this->user->id,
            'issue_purpose' => 'sale_delivery',
            'item_usage_type' => 'commercial',
        ]);
        // Link vào dòng 2 (item2) theo order_item_id
        StockExitItem::create([
            'stock_exit_id' => $exit->id, 'product_id' => $item2->product_id,
            'order_item_id' => $item2->id, 'quantity' => 2, 'unit_price' => 0,
        ]);

        (new OrderService())->syncDelivery($exit);

        $item1->refresh();
        $item2->refresh();
        // item1 không được cập nhật
        $this->assertEquals(0, (float) $item1->delivered_quantity);
        // item2 được cập nhật đúng
        $this->assertEquals(2, (float) $item2->delivered_quantity);
    }

    // TC7: reverseDelivery dùng order_item_id
    public function test_tc7_reverse_delivery_uses_order_item_id(): void
    {
        $order = $this->makeOrder([10]);
        $item  = $order->items->first();
        $item->update(['delivered_quantity' => 6]);

        $exit = StockExit::create([
            'code' => 'XK-DLV-TC7', 'status' => 'confirmed',
            'warehouse_id' => $this->warehouseA->id,
            'order_id'     => $order->id,
            'exit_date'    => now()->toDateString(),
            'created_by'   => $this->user->id,
            'issue_purpose' => 'sale_delivery',
            'item_usage_type' => 'commercial',
        ]);
        StockExitItem::create([
            'stock_exit_id' => $exit->id, 'product_id' => $item->product_id,
            'order_item_id' => $item->id, 'quantity' => 6, 'unit_price' => 0,
        ]);

        (new OrderService())->reverseDelivery($exit);

        $item->refresh();
        $this->assertEquals(0, (float) $item->delivered_quantity);
    }

    // TC8: product không có inventory_balance → stock_by_warehouse fallback từ stock_movements
    // Đây là root cause của bug "Tồn HT=190 nhưng vẫn hiện Mua hàng"
    public function test_tc8_stock_by_warehouse_fallback_from_movements(): void
    {
        $order = Order::create([
            'code' => 'DH-DLV-TC8', 'customer_id' => $this->customer->id,
            'status' => OrderStatus::Processing, 'created_by' => $this->user->id,
            'order_date' => now()->toDateString(),
        ]);
        $product = \App\Models\Product::create([
            'code' => 'SP-DLV8', 'name' => 'SP DLV8', 'unit' => 'cái',
            'cost_price' => 100000, 'is_active' => true,
        ]);
        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $product->id,
            'name' => 'SP DLV8', 'quantity' => 190, 'unit_price' => 1000,
        ]);

        // Không tạo inventory_balance (AVCO chưa init)
        // Tạo stock movements trực tiếp (nhập 200, xuất 10 = còn 190 tại kho A)
        \App\Models\StockMovement::create([
            'product_id'   => $product->id, 'warehouse_id' => $this->warehouseA->id,
            'type'         => 'in', 'quantity' => 200,
            'source_type'  => 'stock_entry', 'source_id' => 1,
            'created_by'   => $this->user->id,
        ]);
        \App\Models\StockMovement::create([
            'product_id'   => $product->id, 'warehouse_id' => $this->warehouseA->id,
            'type'         => 'out', 'quantity' => -10,
            'source_type'  => 'stock_exit', 'source_id' => 1,
            'created_by'   => $this->user->id,
        ]);

        $res = $this->get(route('sales.orders.show', $order->id));
        $res->assertOk();

        $items = $res->original->getData()['page']['props']['order']['items'];
        $i = collect($items)->first();

        // Kho A phải xuất hiện trong stock_by_warehouse (fallback từ movements)
        $this->assertNotEmpty($i['stock_by_warehouse'], 'stock_by_warehouse rỗng — fallback movement chưa hoạt động');
        $whEntry = collect($i['stock_by_warehouse'])->firstWhere('warehouse_id', $this->warehouseA->id);
        $this->assertNotNull($whEntry);
        $this->assertEquals(190.0, $whEntry['qty']);
    }

    // TC9: có phiếu xuất nháp → pending_exit_qty > 0 → không hiện Mua hàng
    public function test_tc9_draft_exit_shows_pending_not_purchase(): void
    {
        $order = Order::create([
            'code' => 'DH-DLV-TC9', 'customer_id' => $this->customer->id,
            'status' => OrderStatus::Processing, 'created_by' => $this->user->id,
            'order_date' => now()->toDateString(),
        ]);
        $product = \App\Models\Product::create([
            'code' => 'SP-DLV9', 'name' => 'SP DLV9', 'unit' => 'cái',
            'cost_price' => 100000, 'is_active' => true,
        ]);
        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $product->id,
            'name' => 'SP DLV9', 'quantity' => 50, 'unit_price' => 1000,
        ]);

        // Tạo phiếu xuất nháp cho đơn hàng
        $exit = StockExit::create([
            'code' => 'XK-DLV-TC9', 'status' => 'draft',
            'warehouse_id' => $this->warehouseA->id,
            'order_id'     => $order->id,
            'exit_date'    => now()->toDateString(),
            'created_by'   => $this->user->id,
            'issue_purpose' => 'sale_delivery',
            'item_usage_type' => 'commercial',
        ]);
        StockExitItem::create([
            'stock_exit_id' => $exit->id,
            'product_id'    => $product->id,
            'quantity'      => 50,
            'unit_price'    => 0,
        ]);

        $res = $this->get(route('sales.orders.show', $order->id));
        $res->assertOk();

        $items = $res->original->getData()['page']['props']['order']['items'];
        $i = collect($items)->first();
        $this->assertEquals(50.0, $i['pending_exit_qty'], 'pending_exit_qty phải = 50');
    }
}
