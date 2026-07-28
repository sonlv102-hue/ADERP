<?php

namespace Tests\Feature\Sales;

use App\Enums\QuotationStatus;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * TC1: Admin sửa product trên báo giá Cancelled → OK, name/product_id đổi
 * TC2: Draft/Sent/Approved → chặn (dùng form sửa thường)
 * TC3: Non-admin → 403
 * TC4: Thiếu reason → validation error
 * TC5: Báo giá đã chuyển thành Order → vẫn sửa được, Order không đổi theo (không có FK sống)
 */
class QuotationItemProductFixTest extends TestCase
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
            ['email' => 'admin-quoteitemfix@test.local'],
            ['name' => 'Admin', 'password' => bcrypt('pass'), 'is_active' => true]
        );
        $this->admin->syncRoles([$adminRole]);

        $this->nonAdmin = User::firstOrCreate(
            ['email' => 'sales-quoteitemfix@test.local'],
            ['name' => 'Sales Staff', 'password' => bcrypt('pass'), 'is_active' => true]
        );
        $this->nonAdmin->syncRoles([$salesRole]);

        $this->customer = Customer::firstOrCreate(
            ['code' => 'KH-QIFIX'],
            ['name' => 'KH QuotationItemFix Test', 'phone' => '0900000098']
        );

        $this->productA = Product::create([
            'code' => 'SP-QIFIX-A', 'name' => 'Sản phẩm A (sai)', 'unit' => 'cái',
            'cost_price' => 1_000_000, 'vat_percent' => 10, 'item_type' => 'goods',
        ]);

        $this->productB = Product::create([
            'code' => 'SP-QIFIX-B', 'name' => 'Sản phẩm B (đúng)', 'unit' => 'cái',
            'cost_price' => 2_000_000, 'vat_percent' => 10, 'item_type' => 'goods',
        ]);
    }

    private function makeQuotation(QuotationStatus $status): Quotation
    {
        $quotation = Quotation::create([
            'code' => 'BG-QIFIX-' . uniqid(),
            'customer_id' => $this->customer->id,
            'status' => $status,
            'discount_type' => 'percent',
            'discount_value' => 0,
            'created_by' => $this->admin->id,
        ]);

        $quotation->items()->create([
            'item_type' => 'product',
            'product_id' => $this->productA->id,
            'name' => $this->productA->name,
            'quantity' => 3,
            'unit_price' => 1_200_000,
        ]);

        return $quotation;
    }

    public function test_tc1_admin_fixes_product_on_cancelled_quotation(): void
    {
        $this->actingAs($this->admin);

        $quotation = $this->makeQuotation(QuotationStatus::Cancelled);
        $item = $quotation->items->first();

        $this->post(route('sales.quotations.items.fix-product', [$quotation->id, $item->id]), [
            'product_id' => $this->productB->id,
            'reason'     => 'Báo giá gắn nhầm sản phẩm khi tạo',
        ])->assertRedirect();

        $item->refresh();
        $this->assertEquals($this->productB->id, $item->product_id);
        $this->assertEquals($this->productB->name, $item->name);
        $this->assertEquals(3, (float) $item->quantity);

        $this->assertDatabaseHas('activity_log', [
            'subject_type' => Quotation::class,
            'subject_id'   => $quotation->id,
        ]);
    }

    public function test_tc2_blocked_when_quotation_draft(): void
    {
        $this->actingAs($this->admin);

        $quotation = $this->makeQuotation(QuotationStatus::Draft);
        $item = $quotation->items->first();

        $this->post(route('sales.quotations.items.fix-product', [$quotation->id, $item->id]), [
            'product_id' => $this->productB->id,
            'reason'     => 'Test',
        ])->assertRedirect();

        $item->refresh();
        $this->assertEquals($this->productA->id, $item->product_id);
    }

    public function test_tc3_non_admin_forbidden(): void
    {
        $this->actingAs($this->nonAdmin);

        $quotation = $this->makeQuotation(QuotationStatus::Cancelled);
        $item = $quotation->items->first();

        $this->post(route('sales.quotations.items.fix-product', [$quotation->id, $item->id]), [
            'product_id' => $this->productB->id,
            'reason'     => 'Test',
        ])->assertForbidden();

        $item->refresh();
        $this->assertEquals($this->productA->id, $item->product_id);
    }

    public function test_tc4_missing_reason_fails_validation(): void
    {
        $this->actingAs($this->admin);

        $quotation = $this->makeQuotation(QuotationStatus::Cancelled);
        $item = $quotation->items->first();

        $this->post(route('sales.quotations.items.fix-product', [$quotation->id, $item->id]), [
            'product_id' => $this->productB->id,
        ])->assertSessionHasErrors('reason');

        $item->refresh();
        $this->assertEquals($this->productA->id, $item->product_id);
    }

    public function test_tc5_converted_quotation_still_editable_order_unaffected(): void
    {
        $this->actingAs($this->admin);

        $quotation = $this->makeQuotation(QuotationStatus::Cancelled);
        $item = $quotation->items->first();

        $order = \App\Models\Order::create([
            'code' => 'DH-QIFIX-' . uniqid(),
            'customer_id' => $this->customer->id,
            'order_date' => '2026-07-01',
            'status' => 'pending',
            'quotation_id' => $quotation->id,
            'created_by' => $this->admin->id,
        ]);
        $orderItem = $order->items()->create([
            'product_id' => $this->productA->id,
            'name' => $this->productA->name,
            'quantity' => 3,
            'unit_price' => 1_200_000,
        ]);

        $this->post(route('sales.quotations.items.fix-product', [$quotation->id, $item->id]), [
            'product_id' => $this->productB->id,
            'reason'     => 'Test',
        ])->assertRedirect();

        $item->refresh();
        $orderItem->refresh();
        $this->assertEquals($this->productB->id, $item->product_id);
        $this->assertEquals($this->productA->id, $orderItem->product_id);
    }
}
