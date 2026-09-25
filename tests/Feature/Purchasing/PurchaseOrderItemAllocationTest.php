<?php

namespace Tests\Feature\Purchasing;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\PurchaseOrderAllocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseOrderItemAllocationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Product $product;
    private Product $otherProduct;
    private PurchaseOrder $po;
    private PurchaseOrderItem $poItem;
    private Order $order;
    private OrderItem $orderItem;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['is_active' => true]);
        \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'purchasing.view']);
        \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'purchasing.update']);
        $this->user->givePermissionTo(['purchasing.view', 'purchasing.update']);
        $this->actingAs($this->user);

        $supplier  = Supplier::create(['code' => 'NCC-A001', 'name' => 'NCC Test']);
        $warehouse = Warehouse::create([
            'code' => 'K-A001', 'name' => 'Kho Test', 'address' => 'HN',
            'manager_id' => $this->user->id, 'is_active' => true,
        ]);
        $this->product = Product::create([
            'code' => 'SP-A001', 'name' => 'SP Test', 'unit' => 'cái',
            'cost_price' => 1000000, 'vat_percent' => 10, 'item_type' => 'product',
        ]);
        $this->otherProduct = Product::create([
            'code' => 'SP-A002', 'name' => 'SP Khac', 'unit' => 'cái',
            'cost_price' => 500000, 'vat_percent' => 10, 'item_type' => 'product',
        ]);

        $customer = Customer::create(['code' => 'KH-A001', 'name' => 'Khach A', 'is_active' => true]);
        $this->order = Order::create([
            'code' => 'DH-A001', 'customer_id' => $customer->id,
            'created_by' => $this->user->id, 'status' => 'pending', 'order_date' => now()->toDateString(),
        ]);
        $this->orderItem = $this->order->items()->create([
            'product_id' => $this->product->id, 'name' => 'SP Test', 'unit' => 'cái',
            'quantity' => 10, 'unit_price' => 1200000,
        ]);

        $this->po = PurchaseOrder::create([
            'code' => 'MH-A001', 'supplier_id' => $supplier->id, 'warehouse_id' => $warehouse->id,
            'order_date' => now()->toDateString(), 'created_by' => $this->user->id,
        ]);
        $this->poItem = $this->po->items()->create([
            'product_id' => $this->product->id, 'quantity' => 10, 'unit_price' => 1000000, 'vat_rate' => 10,
        ]);
        $this->po->orders()->attach($this->order->id);
    }

    public function test_allocate_succeeds_within_remaining_quantity(): void
    {
        $response = $this->post(
            route('purchasing.purchase-orders.items.allocations.store', [$this->po, $this->poItem]),
            ['order_item_id' => $this->orderItem->id, 'quantity' => 4]
        );

        $response->assertRedirect();
        $this->assertDatabaseHas('purchase_order_item_order_item_allocations', [
            'purchase_order_item_id' => $this->poItem->id,
            'order_item_id'          => $this->orderItem->id,
            'allocated_qty'          => '4.000',
        ]);
        $this->assertSame(4.0, $this->poItem->fresh()->allocatedQuantity());
    }

    public function test_allocate_rejects_when_purchase_order_not_linked_to_sales_order(): void
    {
        $this->po->orders()->detach($this->order->id);

        $response = $this->post(
            route('purchasing.purchase-orders.items.allocations.store', [$this->po, $this->poItem]),
            ['order_item_id' => $this->orderItem->id, 'quantity' => 1]
        );

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertDatabaseMissing('purchase_order_item_order_item_allocations', [
            'purchase_order_item_id' => $this->poItem->id,
        ]);
    }

    public function test_allocate_rejects_mismatched_product(): void
    {
        $otherOrderItem = $this->order->items()->create([
            'product_id' => $this->otherProduct->id, 'name' => 'SP Khac', 'unit' => 'cái',
            'quantity' => 5, 'unit_price' => 500000,
        ]);

        $response = $this->post(
            route('purchasing.purchase-orders.items.allocations.store', [$this->po, $this->poItem]),
            ['order_item_id' => $otherOrderItem->id, 'quantity' => 1]
        );

        $response->assertSessionHas('error');
        $this->assertDatabaseMissing('purchase_order_item_order_item_allocations', [
            'order_item_id' => $otherOrderItem->id,
        ]);
    }

    public function test_allocate_rejects_mismatched_unit(): void
    {
        $this->orderItem->update(['unit' => 'thùng']);

        $response = $this->post(
            route('purchasing.purchase-orders.items.allocations.store', [$this->po, $this->poItem]),
            ['order_item_id' => $this->orderItem->id, 'quantity' => 1]
        );

        $response->assertSessionHas('error');
        $this->assertDatabaseMissing('purchase_order_item_order_item_allocations', [
            'purchase_order_item_id' => $this->poItem->id,
        ]);
    }

    public function test_allocate_rejects_exceeding_purchase_order_item_quantity(): void
    {
        $response = $this->post(
            route('purchasing.purchase-orders.items.allocations.store', [$this->po, $this->poItem]),
            ['order_item_id' => $this->orderItem->id, 'quantity' => 11]
        );

        $response->assertSessionHas('error');
        $this->assertDatabaseMissing('purchase_order_item_order_item_allocations', [
            'purchase_order_item_id' => $this->poItem->id,
        ]);
    }

    public function test_allocate_rejects_exceeding_order_item_remaining_after_partial_allocation(): void
    {
        app(PurchaseOrderAllocationService::class)->allocate($this->poItem, $this->orderItem, 8);

        // remaining order item qty = 10 - 8 = 2, requesting 3 more should fail
        $response = $this->post(
            route('purchasing.purchase-orders.items.allocations.store', [$this->po, $this->poItem]),
            ['order_item_id' => $this->orderItem->id, 'quantity' => 3]
        );

        $response->assertSessionHas('error');
    }

    public function test_void_allocation_frees_up_quantity_without_hard_delete(): void
    {
        $allocation = app(PurchaseOrderAllocationService::class)->allocate($this->poItem, $this->orderItem, 5);

        $response = $this->delete(route('purchasing.purchase-orders.allocations.destroy', [$this->po, $allocation]));

        $response->assertRedirect();
        $this->assertDatabaseHas('purchase_order_item_order_item_allocations', ['id' => $allocation->id]);
        $this->assertNotNull($allocation->fresh()->voided_at);
        $this->assertSame(0.0, $this->poItem->fresh()->allocatedQuantity());
    }

    public function test_update_purchase_order_blocked_while_active_allocations_exist(): void
    {
        app(PurchaseOrderAllocationService::class)->allocate($this->poItem, $this->orderItem, 3);

        $response = $this->put(route('purchasing.purchase-orders.update', $this->po), [
            'supplier_id' => $this->po->supplier_id, 'warehouse_id' => $this->po->warehouse_id,
            'order_date' => now()->toDateString(),
            'order_ids' => [$this->order->id],
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 10, 'unit_price' => 1000000, 'vat_rate' => 10],
            ],
        ]);

        $response->assertSessionHas('error');
        $this->assertDatabaseHas('purchase_order_items', ['id' => $this->poItem->id]);
    }
}
