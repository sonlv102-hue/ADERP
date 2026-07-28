<?php

namespace Tests\Feature\Purchasing;

use App\Enums\PurchaseOrderStatus;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\StockEntry;
use App\Models\StockEntryItem;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * TC1: Admin sửa product trên PO Sent, chưa có stock_entry_items → OK
 * TC2: PO Draft → chặn (dùng form sửa thường)
 * TC3: đã có stock_entry_items xác nhận (received_quantity > 0) → chặn
 * TC4: PO Received (đã nhận hàng) → cùng nhánh chặn với TC3
 * TC5 (quan trọng): stock_entry_items tồn tại nhưng received_quantity đã về 0 (giả lập trạng
 *      thái sau khi hủy phiếu nhập) → vẫn chặn vì row lịch sử còn tồn tại
 * TC6: non-admin → 403
 * TC7: thiếu reason → validation error
 */
class PurchaseOrderItemProductFixTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $nonAdmin;
    private Supplier $supplier;
    private Warehouse $warehouse;
    private Product $productA;
    private Product $productB;

    protected function setUp(): void
    {
        parent::setUp();

        $adminRole = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $warehouseRole = Role::firstOrCreate(['name' => 'warehouse', 'guard_name' => 'web']);

        $this->admin = User::firstOrCreate(
            ['email' => 'admin-poitemfix@test.local'],
            ['name' => 'Admin', 'password' => bcrypt('pass'), 'is_active' => true]
        );
        $this->admin->syncRoles([$adminRole]);

        $this->nonAdmin = User::firstOrCreate(
            ['email' => 'warehouse-poitemfix@test.local'],
            ['name' => 'Warehouse Staff', 'password' => bcrypt('pass'), 'is_active' => true]
        );
        $this->nonAdmin->syncRoles([$warehouseRole]);

        $this->supplier = Supplier::firstOrCreate(
            ['code' => 'NCC-POIFIX'],
            ['name' => 'NCC PoItemFix Test', 'phone' => '0900000097']
        );

        $this->warehouse = Warehouse::firstOrCreate(
            ['code' => 'KHO-POIFIX'],
            ['name' => 'Kho PoItemFix Test', 'address' => 'Test']
        );

        $this->productA = Product::create([
            'code' => 'SP-POIFIX-A', 'name' => 'Sản phẩm A (sai)', 'unit' => 'cái',
            'cost_price' => 1_000_000, 'vat_percent' => 10, 'item_type' => 'goods',
        ]);

        $this->productB = Product::create([
            'code' => 'SP-POIFIX-B', 'name' => 'Sản phẩm B (đúng)', 'unit' => 'cái',
            'cost_price' => 2_000_000, 'vat_percent' => 10, 'item_type' => 'goods',
        ]);
    }

    private function makePo(PurchaseOrderStatus $status): PurchaseOrder
    {
        $po = PurchaseOrder::create([
            'code' => 'MH-POIFIX-' . uniqid(),
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'order_date' => '2026-07-01',
            'status' => $status,
            'created_by' => $this->admin->id,
        ]);

        $po->items()->create([
            'product_id' => $this->productA->id,
            'quantity' => 10,
            'received_quantity' => 0,
            'unit_price' => 1_200_000,
        ]);

        return $po;
    }

    public function test_tc1_admin_fixes_product_on_sent_po_no_stock_entries(): void
    {
        $this->actingAs($this->admin);

        $po = $this->makePo(PurchaseOrderStatus::Sent);
        $item = $po->items->first();

        $this->post(route('purchasing.purchase-orders.items.fix-product', [$po->id, $item->id]), [
            'product_id' => $this->productB->id,
            'reason'     => 'Đơn mua gắn nhầm sản phẩm khi tạo',
        ])->assertRedirect();

        $item->refresh();
        $this->assertEquals($this->productB->id, $item->product_id);
        $this->assertEquals(10, (int) $item->quantity);
        $this->assertEquals(0, (float) $item->received_quantity);

        $this->assertDatabaseHas('activity_log', [
            'subject_type' => PurchaseOrder::class,
            'subject_id'   => $po->id,
        ]);
    }

    public function test_tc2_blocked_when_po_draft(): void
    {
        $this->actingAs($this->admin);

        $po = $this->makePo(PurchaseOrderStatus::Draft);
        $item = $po->items->first();

        $this->post(route('purchasing.purchase-orders.items.fix-product', [$po->id, $item->id]), [
            'product_id' => $this->productB->id,
            'reason'     => 'Test',
        ])->assertRedirect();

        $item->refresh();
        $this->assertEquals($this->productA->id, $item->product_id);
    }

    public function test_tc3_blocked_when_stock_entry_item_confirmed(): void
    {
        $this->actingAs($this->admin);

        $po = $this->makePo(PurchaseOrderStatus::PartialReceived);
        $item = $po->items->first();
        $item->update(['received_quantity' => 4]);

        $entry = StockEntry::create([
            'code' => 'NK-POIFIX-' . uniqid(),
            'warehouse_id' => $this->warehouse->id,
            'purchase_order_id' => $po->id,
            'status' => 'confirmed',
            'entry_date' => '2026-07-05',
            'created_by' => $this->admin->id,
        ]);
        StockEntryItem::create([
            'stock_entry_id' => $entry->id,
            'purchase_order_item_id' => $item->id,
            'product_id' => $this->productA->id,
            'quantity' => 4,
            'unit_price' => 1_200_000,
        ]);

        $this->post(route('purchasing.purchase-orders.items.fix-product', [$po->id, $item->id]), [
            'product_id' => $this->productB->id,
            'reason'     => 'Test',
        ])->assertRedirect();

        $item->refresh();
        $this->assertEquals($this->productA->id, $item->product_id);
    }

    public function test_tc4_blocked_when_po_received(): void
    {
        $this->actingAs($this->admin);

        $po = $this->makePo(PurchaseOrderStatus::Received);
        $item = $po->items->first();
        $item->update(['received_quantity' => 10]);

        $entry = StockEntry::create([
            'code' => 'NK-POIFIX-' . uniqid(),
            'warehouse_id' => $this->warehouse->id,
            'purchase_order_id' => $po->id,
            'status' => 'confirmed',
            'entry_date' => '2026-07-05',
            'created_by' => $this->admin->id,
        ]);
        StockEntryItem::create([
            'stock_entry_id' => $entry->id,
            'purchase_order_item_id' => $item->id,
            'product_id' => $this->productA->id,
            'quantity' => 10,
            'unit_price' => 1_200_000,
        ]);

        $this->post(route('purchasing.purchase-orders.items.fix-product', [$po->id, $item->id]), [
            'product_id' => $this->productB->id,
            'reason'     => 'Test',
        ])->assertRedirect();

        $item->refresh();
        $this->assertEquals($this->productA->id, $item->product_id);
    }

    public function test_tc5_blocked_even_after_received_quantity_reversed_to_zero(): void
    {
        $this->actingAs($this->admin);

        $po = $this->makePo(PurchaseOrderStatus::PartialReceived);
        $item = $po->items->first();

        // Giả lập trạng thái SAU khi StockService::cancelEntry() đã chạy: received_quantity
        // được trả về 0, nhưng row stock_entry_items KHÔNG bị xóa (theo thiết kế thật của
        // cancelEntry() — chỉ tạo movement đảo ngược, giữ nguyên lịch sử).
        $entry = StockEntry::create([
            'code' => 'NK-POIFIX-' . uniqid(),
            'warehouse_id' => $this->warehouse->id,
            'purchase_order_id' => $po->id,
            'status' => 'cancelled',
            'entry_date' => '2026-07-05',
            'created_by' => $this->admin->id,
        ]);
        StockEntryItem::create([
            'stock_entry_id' => $entry->id,
            'purchase_order_item_id' => $item->id,
            'product_id' => $this->productA->id,
            'quantity' => 4,
            'unit_price' => 1_200_000,
        ]);
        $item->update(['received_quantity' => 0]);

        $this->post(route('purchasing.purchase-orders.items.fix-product', [$po->id, $item->id]), [
            'product_id' => $this->productB->id,
            'reason'     => 'Test',
        ])->assertRedirect();

        $item->refresh();
        $this->assertEquals($this->productA->id, $item->product_id, 'Guard phải chặn dù received_quantity=0 vì stock_entry_items lịch sử còn tồn tại.');
    }

    public function test_tc6_non_admin_forbidden(): void
    {
        $this->actingAs($this->nonAdmin);

        $po = $this->makePo(PurchaseOrderStatus::Sent);
        $item = $po->items->first();

        $this->post(route('purchasing.purchase-orders.items.fix-product', [$po->id, $item->id]), [
            'product_id' => $this->productB->id,
            'reason'     => 'Test',
        ])->assertForbidden();

        $item->refresh();
        $this->assertEquals($this->productA->id, $item->product_id);
    }

    public function test_tc7_missing_reason_fails_validation(): void
    {
        $this->actingAs($this->admin);

        $po = $this->makePo(PurchaseOrderStatus::Sent);
        $item = $po->items->first();

        $this->post(route('purchasing.purchase-orders.items.fix-product', [$po->id, $item->id]), [
            'product_id' => $this->productB->id,
        ])->assertSessionHasErrors('reason');

        $item->refresh();
        $this->assertEquals($this->productA->id, $item->product_id);
    }
}
