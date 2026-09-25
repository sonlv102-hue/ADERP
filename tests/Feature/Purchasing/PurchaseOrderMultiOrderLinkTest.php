<?php

namespace Tests\Feature\Purchasing;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseOrderMultiOrderLinkTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Supplier $supplier;
    private Warehouse $warehouse;
    private Product $product;
    private Customer $customerA;
    private Customer $customerB;
    private Order $orderA;
    private Order $orderB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['is_active' => true]);
        \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'purchasing.view']);
        \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'purchasing.create']);
        \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'purchasing.update']);
        $this->user->givePermissionTo(['purchasing.view', 'purchasing.create', 'purchasing.update']);
        $this->actingAs($this->user);

        $this->supplier = Supplier::create(['code' => 'NCC-M001', 'name' => 'NCC Test']);
        $this->warehouse = Warehouse::create([
            'code' => 'K-M001', 'name' => 'Kho Test', 'address' => 'HN',
            'manager_id' => $this->user->id, 'is_active' => true,
        ]);
        $this->product = Product::create([
            'code' => 'SP-M001', 'name' => 'SP Test', 'unit' => 'cái',
            'cost_price' => 1000000, 'vat_percent' => 10, 'item_type' => 'product',
        ]);

        $this->customerA = Customer::create(['code' => 'KH-M001A', 'name' => 'Khách A', 'is_active' => true]);
        $this->customerB = Customer::create(['code' => 'KH-M001B', 'name' => 'Khách B', 'is_active' => true]);

        $this->orderA = Order::create([
            'code' => 'DH-M001A', 'customer_id' => $this->customerA->id,
            'created_by' => $this->user->id, 'status' => 'pending', 'order_date' => now()->toDateString(),
        ]);
        $this->orderB = Order::create([
            'code' => 'DH-M001B', 'customer_id' => $this->customerB->id,
            'created_by' => $this->user->id, 'status' => 'pending', 'order_date' => now()->toDateString(),
        ]);
    }

    public function test_store_links_purchase_order_to_multiple_sales_orders_of_different_customers(): void
    {
        $response = $this->post(route('purchasing.purchase-orders.store'), [
            'code' => 'MH-M001', 'supplier_id' => $this->supplier->id, 'warehouse_id' => $this->warehouse->id,
            'order_date' => now()->toDateString(),
            'order_ids' => [$this->orderA->id, $this->orderB->id],
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 1000000, 'vat_rate' => 10],
            ],
        ]);

        $response->assertRedirect();
        $po = PurchaseOrder::where('code', 'MH-M001')->firstOrFail();

        $this->assertCount(2, $po->orders);
        $this->assertEqualsCanonicalizing(
            [$this->orderA->id, $this->orderB->id],
            $po->orders->pluck('id')->toArray()
        );
    }

    public function test_store_rejects_duplicate_order_ids_with_validation_error_not_500(): void
    {
        $response = $this->post(route('purchasing.purchase-orders.store'), [
            'code' => 'MH-M004', 'supplier_id' => $this->supplier->id, 'warehouse_id' => $this->warehouse->id,
            'order_date' => now()->toDateString(),
            'order_ids' => [$this->orderA->id, $this->orderA->id],
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 1000000, 'vat_rate' => 10],
            ],
        ]);

        $response->assertSessionHasErrors('order_ids.1');
        $this->assertDatabaseMissing('purchase_orders', ['code' => 'MH-M004']);
    }

    public function test_update_syncs_linked_sales_orders_removing_unselected_ones(): void
    {
        $po = PurchaseOrder::create([
            'code' => 'MH-M002', 'supplier_id' => $this->supplier->id, 'warehouse_id' => $this->warehouse->id,
            'order_date' => now()->toDateString(), 'created_by' => $this->user->id,
        ]);
        $po->items()->create(['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 1000000, 'vat_rate' => 10]);
        $po->orders()->attach([$this->orderA->id, $this->orderB->id]);

        $response = $this->put(route('purchasing.purchase-orders.update', $po), [
            'supplier_id' => $this->supplier->id, 'warehouse_id' => $this->warehouse->id,
            'order_date' => now()->toDateString(),
            'order_ids' => [$this->orderB->id],
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 1000000, 'vat_rate' => 10],
            ],
        ]);

        $response->assertRedirect();
        $po->refresh();
        $this->assertEqualsCanonicalizing([$this->orderB->id], $po->orders->pluck('id')->toArray());
    }

    public function test_sales_order_show_lists_linked_purchase_orders(): void
    {
        $po = PurchaseOrder::create([
            'code' => 'MH-M003', 'supplier_id' => $this->supplier->id, 'warehouse_id' => $this->warehouse->id,
            'order_date' => now()->toDateString(), 'created_by' => $this->user->id,
        ]);
        $po->orders()->attach($this->orderA->id);

        \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'quotations.view']);
        $this->user->givePermissionTo('quotations.view');

        $response = $this->get(route('sales.orders.show', $this->orderA));
        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->where('order.purchase_orders.0.code', 'MH-M003')
        );
    }
}
