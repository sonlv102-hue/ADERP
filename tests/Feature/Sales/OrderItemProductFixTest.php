<?php

namespace Tests\Feature\Sales;

use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\StockExit;
use App\Models\StockExitItem;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * TC1: Admin sửa product trên order Completed, chưa giao → OK, name/product_id đổi, quantity/unit_price giữ nguyên
 * TC2: Admin sửa product trên order Cancelled → OK
 * TC3: Order đang Processing → chặn (dùng form sửa thường)
 * TC4: delivered_quantity > 0 → chặn
 * TC5: có stock_exit_items.order_item_id liên kết → chặn dù delivered_quantity = 0
 * TC6: non-admin → 403
 * TC7: thiếu reason → validation error
 */
class OrderItemProductFixTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $nonAdmin;
    private Customer $customer;
    private Product $productA;
    private Product $productB;

    protected function setUp(): void
    {
        parent::setUp();

        $adminRole = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $salesRole = Role::firstOrCreate(['name' => 'sales', 'guard_name' => 'web']);

        $this->admin = User::firstOrCreate(
            ['email' => 'admin-orderitemfix@test.local'],
            ['name' => 'Admin', 'password' => bcrypt('pass'), 'is_active' => true]
        );
        $this->admin->syncRoles([$adminRole]);

        $this->nonAdmin = User::firstOrCreate(
            ['email' => 'sales-orderitemfix@test.local'],
            ['name' => 'Sales Staff', 'password' => bcrypt('pass'), 'is_active' => true]
        );
        $this->nonAdmin->syncRoles([$salesRole]);

        $this->customer = Customer::firstOrCreate(
            ['code' => 'KH-OIFIX'],
            ['name' => 'KH OrderItemFix Test', 'phone' => '0900000099']
        );

        $this->productA = Product::create([
            'code' => 'SP-OIFIX-A', 'name' => 'Sản phẩm A (sai)', 'unit' => 'cái',
            'cost_price' => 1_100_000, 'vat_percent' => 10, 'item_type' => 'goods',
        ]);

        $this->productB = Product::create([
            'code' => 'SP-OIFIX-B', 'name' => 'Sản phẩm B (đúng)', 'unit' => 'cái',
            'cost_price' => 2_200_000, 'vat_percent' => 10, 'item_type' => 'goods',
            'revenue_account_code' => '5111',
        ]);
    }

    private function makeOrder(OrderStatus $status, float $delivered = 0): Order
    {
        $order = Order::create([
            'code' => 'DH-OIFIX-' . uniqid(),
            'customer_id' => $this->customer->id,
            'order_date' => '2026-07-01',
            'status' => $status,
            'created_by' => $this->admin->id,
        ]);

        $order->items()->create([
            'product_id' => $this->productA->id,
            'name' => $this->productA->name,
            'quantity' => 5,
            'delivered_quantity' => $delivered,
            'unit_price' => 1_500_000,
        ]);

        return $order;
    }

    public function test_tc1_admin_fixes_product_on_completed_order(): void
    {
        $this->actingAs($this->admin);

        $order = $this->makeOrder(OrderStatus::Completed);
        $item = $order->items->first();

        $this->post(route('sales.orders.items.fix-product', [$order->id, $item->id]), [
            'product_id' => $this->productB->id,
            'reason'     => 'Đơn hàng gắn nhầm sản phẩm khi tạo',
        ])->assertRedirect();

        $item->refresh();
        $this->assertEquals($this->productB->id, $item->product_id);
        $this->assertEquals($this->productB->name, $item->name);
        $this->assertEquals(5, (float) $item->quantity);
        $this->assertEquals(1_500_000, (float) $item->unit_price);
        $this->assertEquals(round(2_200_000 / 1.10, 2), (float) $item->unit_cogs);
        $this->assertEquals('snapshot', $item->unit_cogs_source);
        $this->assertEquals('5111', $item->revenue_account_code);

        $this->assertDatabaseHas('activity_log', [
            'subject_type' => Order::class,
            'subject_id'   => $order->id,
        ]);
    }

    public function test_tc2_admin_fixes_product_on_cancelled_order(): void
    {
        $this->actingAs($this->admin);

        $order = $this->makeOrder(OrderStatus::Cancelled);
        $item = $order->items->first();

        $this->post(route('sales.orders.items.fix-product', [$order->id, $item->id]), [
            'product_id' => $this->productB->id,
            'reason'     => 'Sửa lại sản phẩm đúng',
        ])->assertRedirect();

        $item->refresh();
        $this->assertEquals($this->productB->id, $item->product_id);
    }

    public function test_tc3_blocked_when_order_processing(): void
    {
        $this->actingAs($this->admin);

        $order = $this->makeOrder(OrderStatus::Processing);
        $item = $order->items->first();

        $this->post(route('sales.orders.items.fix-product', [$order->id, $item->id]), [
            'product_id' => $this->productB->id,
            'reason'     => 'Test',
        ])->assertRedirect();

        $item->refresh();
        $this->assertEquals($this->productA->id, $item->product_id);
    }

    public function test_tc4_blocked_when_delivered_quantity_positive(): void
    {
        $this->actingAs($this->admin);

        $order = $this->makeOrder(OrderStatus::Completed, delivered: 2);
        $item = $order->items->first();

        $this->post(route('sales.orders.items.fix-product', [$order->id, $item->id]), [
            'product_id' => $this->productB->id,
            'reason'     => 'Test',
        ])->assertRedirect();

        $item->refresh();
        $this->assertEquals($this->productA->id, $item->product_id);
    }

    public function test_tc5_blocked_when_stock_exit_item_linked(): void
    {
        $this->actingAs($this->admin);

        $order = $this->makeOrder(OrderStatus::Completed);
        $item = $order->items->first();

        $warehouse = Warehouse::create(['code' => 'KHO-OIFIX', 'name' => 'Kho OIFIX Test', 'address' => 'Test']);
        $exit = StockExit::create([
            'code' => 'XK-OIFIX-' . uniqid(),
            'warehouse_id' => $warehouse->id,
            'order_id' => $order->id,
            'status' => 'confirmed',
            'exit_date' => '2026-07-02',
            'created_by' => $this->admin->id,
        ]);
        StockExitItem::create([
            'stock_exit_id' => $exit->id,
            'product_id'    => $this->productA->id,
            'order_item_id' => $item->id,
            'quantity'      => 1,
            'unit_price'    => 1_500_000,
        ]);

        $this->post(route('sales.orders.items.fix-product', [$order->id, $item->id]), [
            'product_id' => $this->productB->id,
            'reason'     => 'Test',
        ])->assertRedirect();

        $item->refresh();
        $this->assertEquals($this->productA->id, $item->product_id);
    }

    public function test_tc6_non_admin_forbidden(): void
    {
        $this->actingAs($this->nonAdmin);

        $order = $this->makeOrder(OrderStatus::Completed);
        $item = $order->items->first();

        $this->post(route('sales.orders.items.fix-product', [$order->id, $item->id]), [
            'product_id' => $this->productB->id,
            'reason'     => 'Test',
        ])->assertForbidden();

        $item->refresh();
        $this->assertEquals($this->productA->id, $item->product_id);
    }

    public function test_tc7_missing_reason_fails_validation(): void
    {
        $this->actingAs($this->admin);

        $order = $this->makeOrder(OrderStatus::Completed);
        $item = $order->items->first();

        $this->post(route('sales.orders.items.fix-product', [$order->id, $item->id]), [
            'product_id' => $this->productB->id,
        ])->assertSessionHasErrors('reason');

        $item->refresh();
        $this->assertEquals($this->productA->id, $item->product_id);
    }
}
