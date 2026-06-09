<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseOrderVatTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Supplier $supplier;
    private Warehouse $warehouse;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['is_active' => true]);
        \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'purchasing.view']);
        \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'purchasing.create']);
        $this->user->givePermissionTo(['purchasing.view', 'purchasing.create']);
        $this->actingAs($this->user);

        $this->supplier = Supplier::create([
            'code' => 'NCC-0001',
            'name' => 'Nhà Cung Cấp Test',
        ]);

        $this->warehouse = Warehouse::create([
            'code'       => 'K01',
            'name'       => 'Kho chính',
            'address'    => 'Hà Nội',
            'manager_id' => $this->user->id,
            'is_active'  => true,
        ]);

        $this->product = Product::create([
            'code'        => 'SP-0001',
            'name'        => 'Sản phẩm Test',
            'unit'        => 'cái',
            'cost_price'  => 10000000,
            'vat_percent' => 10,
            'item_type'   => 'product',
        ]);
    }

    private function makePo(array $items, string $code = 'MH-0001'): PurchaseOrder
    {
        $po = PurchaseOrder::create([
            'code'         => $code,
            'supplier_id'  => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'order_date'   => now()->toDateString(),
            'created_by'   => $this->user->id,
        ]);

        foreach ($items as $item) {
            $po->items()->create(array_merge(['product_id' => $this->product->id], $item));
        }

        return $po;
    }

    public function test_index_returns_subtotal_when_no_vat(): void
    {
        // 2 × 5,000,000, VAT 0% → total = 10,000,000
        $this->makePo([
            ['quantity' => 2, 'unit_price' => 5000000, 'vat_rate' => 0],
        ]);

        $response = $this->get(route('purchasing.purchase-orders.index'));
        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Purchasing/PurchaseOrders/Index')
            ->where('orders.data.0.total', 10000000)
        );
    }

    public function test_index_returns_total_with_vat_8_percent(): void
    {
        // 1 × 5,000,000 + 8% VAT = 5,400,000
        $this->makePo([
            ['quantity' => 1, 'unit_price' => 5000000, 'vat_rate' => 8],
        ]);

        $response = $this->get(route('purchasing.purchase-orders.index'));
        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->where('orders.data.0.total', 5400000)
        );
    }

    public function test_index_returns_total_with_vat_10_percent(): void
    {
        // 1 × 10,000,000 + 10% VAT = 11,000,000
        $this->makePo([
            ['quantity' => 1, 'unit_price' => 10000000, 'vat_rate' => 10],
        ]);

        $response = $this->get(route('purchasing.purchase-orders.index'));
        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->where('orders.data.0.total', 11000000)
        );
    }

    public function test_index_returns_correct_total_for_multi_line_mixed_vat(): void
    {
        // line 1: 1 × 10,000,000, VAT 10% → 11,000,000
        // line 2: 1 ×  5,000,000, VAT 8%  →  5,400,000
        // grand total = 16,400,000
        $this->makePo([
            ['quantity' => 1, 'unit_price' => 10000000, 'vat_rate' => 10],
            ['quantity' => 1, 'unit_price' =>  5000000, 'vat_rate' =>  8],
        ]);

        $response = $this->get(route('purchasing.purchase-orders.index'));
        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->where('orders.data.0.total', 16400000)
        );
    }

    public function test_index_returns_total_when_vat_rate_is_null(): void
    {
        // vat_rate = null treated as 0%: 3 × 2,000,000 = 6,000,000
        $this->makePo([
            ['quantity' => 3, 'unit_price' => 2000000, 'vat_rate' => null],
        ]);

        $response = $this->get(route('purchasing.purchase-orders.index'));
        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->where('orders.data.0.total', 6000000)
        );
    }

    public function test_store_creates_purchase_order_with_correct_vat_items(): void
    {
        $response = $this->post(route('purchasing.purchase-orders.store'), [
            'code'         => 'MH-TEST',
            'supplier_id'  => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'order_date'   => now()->toDateString(),
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 10000000, 'vat_rate' => 10],
            ],
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('purchase_order_items', [
            'quantity'   => 1,
            'unit_price' => '10000000.00',
            'vat_rate'   => '10.00',
        ]);
    }

    public function test_store_accepts_zero_vat(): void
    {
        $response = $this->post(route('purchasing.purchase-orders.store'), [
            'code'         => 'MH-NOVAT',
            'supplier_id'  => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'order_date'   => now()->toDateString(),
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 2, 'unit_price' => 5000000, 'vat_rate' => 0],
            ],
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('purchase_order_items', [
            'quantity'   => 2,
            'unit_price' => '5000000.00',
            'vat_rate'   => '0.00',
        ]);
    }
}
