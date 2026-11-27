<?php

namespace Tests\Feature\Warehouse;

use App\Enums\InventoryCountStatus;
use App\Enums\StockEntryStatus;
use App\Models\AccountCode;
use App\Models\Customer;
use App\Models\InventoryBalance;
use App\Models\InventoryCount;
use App\Models\InventoryCountItem;
use App\Models\JournalEntryLine;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProjectInventoryLot;
use App\Models\Role;
use App\Models\SalesReturn;
use App\Models\SalesReturnItem;
use App\Models\StockEntry;
use App\Models\StockEntryItem;
use App\Models\StockExit;
use App\Models\StockExitItem;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryCountService;
use App\Services\SalesReturnService;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** A4 (trả hàng bán, kiểm kê) + A6 (chặn sửa dòng hàng của phiếu có lịch sử lô) — V3. */
class InventoryReturnsAvcoTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Warehouse $wh;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->user = User::factory()->create();
        $this->user->roles()->sync([Role::firstOrCreate(['code' => 'admin'], ['name' => 'Admin', 'is_system' => true])->id]);
        $this->actingAs($this->user);
        foreach (['1331' => 'asset', '1561' => 'asset', '3311' => 'liability', '632' => 'expense', '811' => 'expense', '711' => 'revenue'] as $c => $t) {
            AccountCode::firstOrCreate(['code' => $c], ['name' => $c, 'type' => $t, 'normal_balance' => in_array($t, ['liability', 'revenue']) ? 'credit' : 'debit', 'is_detail' => true]);
        }
        $this->wh = Warehouse::create(['name' => 'Kho A4']);
        $cat = ProductCategory::create(['name' => 'Cat A4']);
        $this->product = Product::create(['code' => 'SP-A4', 'name' => 'SP A4', 'category_id' => $cat->id, 'cost_price' => 330000, 'is_active' => true]);
        $sup = Supplier::create(['code' => 'NCC-A4', 'name' => 'NCC', 'payable_account_code' => '3311']);
        foreach ([[10, 100000], [10, 200000]] as [$q, $u]) {   // avg = 150.000
            $e = StockEntry::create(['code' => 'NK-' . uniqid(), 'warehouse_id' => $this->wh->id, 'supplier_id' => $sup->id,
                'status' => StockEntryStatus::Draft, 'entry_date' => now()->toDateString(), 'created_by' => $this->user->id]);
            StockEntryItem::create(['stock_entry_id' => $e->id, 'product_id' => $this->product->id, 'quantity' => $q, 'unit_price' => $u, 'unit_cost' => $u, 'tax_rate' => 10]);
            app(StockService::class)->confirmEntry($e);
        }
    }

    private function balance(): InventoryBalance
    {
        return InventoryBalance::where('product_id', $this->product->id)->where('warehouse_id', $this->wh->id)->first();
    }

    /** Phiếu xuất bán đã xác nhận 4 cái + phiếu trả hàng bán nháp 1 cái. */
    private function salesReturnDraft(): SalesReturn
    {
        $customer = Customer::create(['code' => 'KH-A4', 'name' => 'KH']);
        $order = Order::create(['code' => 'DH-A4', 'customer_id' => $customer->id, 'order_date' => now(), 'status' => 'processing', 'created_by' => $this->user->id]);
        $oi = OrderItem::create(['order_id' => $order->id, 'product_id' => $this->product->id, 'name' => 'SP', 'quantity' => 4, 'delivered_quantity' => 0, 'unit_price' => 500000]);
        $x = StockExit::create(['code' => 'XK-A4', 'warehouse_id' => $this->wh->id, 'order_id' => $order->id, 'issue_purpose' => 'sale_delivery',
            'item_usage_type' => 'commercial', 'status' => 'draft', 'exit_date' => now()->toDateString(), 'created_by' => $this->user->id]);
        StockExitItem::create(['stock_exit_id' => $x->id, 'product_id' => $this->product->id, 'quantity' => 4, 'unit_price' => 500000, 'order_item_id' => $oi->id]);
        app(StockService::class)->confirmExit($x);              // COGS 4 × 150.000; còn 16 / 2.400.000
        $oi->update(['delivered_quantity' => 4]);

        $r = SalesReturn::create(['code' => 'TH-A4', 'order_id' => $order->id, 'warehouse_id' => $this->wh->id, 'return_date' => now(), 'status' => 'draft', 'created_by' => $this->user->id]);
        SalesReturnItem::create(['sales_return_id' => $r->id, 'order_item_id' => $oi->id, 'product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 500000]);
        return $r;
    }

    public function test_sales_return_restocks_at_original_exit_cost_and_cancel_is_symmetric(): void
    {
        config(['inventory_integrity.returns.allow_confirm_without_je' => true]); // chỉ kiểm thử logic tồn kho/AVCO
        $r = $this->salesReturnDraft();
        app(SalesReturnService::class)->confirmReturn($r);

        $mv = StockMovement::where('source_type', SalesReturn::class)->where('source_id', $r->id)->first();
        $this->assertEqualsWithDelta(150000.0, (float) $mv->amount, 0.01, 'Giá vốn của phiếu xuất gốc');
        $this->assertEquals(17.0, (float) $this->balance()->qty_on_hand);
        $this->assertEqualsWithDelta(2550000.0, (float) $this->balance()->value_on_hand, 0.01);

        app(SalesReturnService::class)->cancelReturn($r->fresh());
        $this->assertEquals(16.0, (float) $this->balance()->qty_on_hand);
        $this->assertEqualsWithDelta(2400000.0, (float) $this->balance()->value_on_hand, 0.01);
    }

    public function test_inventory_count_shortage_uses_avco_value_not_vat_inclusive_cost_price(): void
    {
        $count = InventoryCount::create(['code' => 'IK-A4', 'warehouse_id' => $this->wh->id, 'count_date' => now()->toDateString(),
            'status' => InventoryCountStatus::Draft, 'counted_by' => $this->user->id]);
        InventoryCountItem::create(['inventory_count_id' => $count->id, 'product_id' => $this->product->id, 'system_quantity' => 20, 'counted_quantity' => 18]);
        app(InventoryCountService::class)->confirm($count);

        $this->assertEquals(18.0, (float) $this->balance()->qty_on_hand, 'AVCO phải giảm theo kiểm kê');
        $credit = (float) JournalEntryLine::where('account_code', '1561')->where('credit', '>', 0)->sum('credit');
        $this->assertEquals(300000.0, $credit, '2 × avg 150.000 — không phải 2 × cost_price 330.000 (gồm VAT)');
    }

    public function test_editing_items_of_entry_with_lot_history_is_blocked_with_clear_message(): void
    {
        app(StockService::class)->recallEntry(StockEntry::latest('id')->first());   // phiếu sau cùng: không có phụ thuộc
        $draft = StockEntry::latest('id')->first();
        ProjectInventoryLot::create(['project_id' => \App\Models\Project::create(['code' => 'DA-A4', 'name' => 'DA', 'status' => 'in_progress',
            'customer_id' => Customer::create(['code' => 'KH-A6', 'name' => 'KH'])->id, 'created_by' => $this->user->id])->id,
            'product_id' => $this->product->id, 'warehouse_id' => $this->wh->id, 'stock_entry_id' => $draft->id,
            'stock_entry_item_id' => $draft->items()->first()->id, 'received_qty' => 1, 'issued_qty' => 0, 'unit_cost' => 1, 'status' => 'cancelled', 'received_at' => now()]);

        $this->assertTrue($draft->hasLotHistory());
        $this->assertStringContainsString('Lịch sử phân bổ FIFO', StockEntry::LOT_HISTORY_EDIT_BLOCKED);
    }

    /** Trạng thái kho + kế toán để chứng minh không thay đổi một phần. */
    private function snapshot(): array
    {
        return ['mv' => StockMovement::count(), 'mv_sum' => (float) StockMovement::sum('quantity'), 'qty' => (float) $this->balance()->qty_on_hand,
            'value' => (float) $this->balance()->value_on_hand, 'je' => JournalEntryLine::count()];
    }

    public function test_sales_return_confirm_blocked_without_approved_posting_rule_and_nothing_changes(): void
    {
        $r = $this->salesReturnDraft();
        $before = $this->snapshot();
        try {
            app(SalesReturnService::class)->confirmReturn($r->fresh());
            $this->fail('Phải chặn xác nhận khi chưa có quy tắc bút toán trả hàng');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('chưa có quy tắc bút toán trả hàng', $e->getMessage());
        }
        $this->assertSame($before, $this->snapshot());
        $this->assertSame('draft', $r->fresh()->status->value);

        $this->post(route('sales.sales-returns.confirm', $r))->assertSessionHas('error');
        $this->assertSame($before, $this->snapshot());
    }

    public function test_purchase_return_confirm_blocked_without_approved_posting_rule_and_nothing_changes(): void
    {
        $po = \App\Models\PurchaseOrder::create(['code' => 'MH-A4R', 'supplier_id' => \App\Models\Supplier::first()->id, 'warehouse_id' => $this->wh->id,
            'status' => \App\Enums\PurchaseOrderStatus::Sent, 'order_date' => now()->toDateString(), 'created_by' => $this->user->id, 'subtotal' => 0, 'tax' => 0, 'total' => 0]);
        $r = \App\Models\PurchaseReturn::create(['code' => 'THM-A4', 'purchase_order_id' => $po->id, 'supplier_id' => \App\Models\Supplier::first()->id, 'warehouse_id' => $this->wh->id,
            'return_date' => now()->toDateString(), 'status' => 'draft', 'created_by' => $this->user->id]);
        $poi = \App\Models\PurchaseOrderItem::create(['purchase_order_id' => $po->id, 'product_id' => $this->product->id, 'quantity' => 2,
            'received_quantity' => 2, 'unit_price' => 100000, 'vat_rate' => 10, 'subtotal' => 200000]);
        \App\Models\PurchaseReturnItem::create(['purchase_return_id' => $r->id, 'purchase_order_item_id' => $poi->id, 'product_id' => $this->product->id, 'quantity' => 2, 'unit_price' => 100000]);
        $before = $this->snapshot();
        $this->post(route('purchasing.purchase-returns.confirm', $r))->assertSessionHas('error');
        $this->assertSame($before, $this->snapshot());
        $this->assertSame('draft', $r->fresh()->status->value);
    }

    public function test_inventory_count_je_matches_movement_value_and_failure_is_atomic(): void
    {
        $count = InventoryCount::create(['code' => 'IK-A4B', 'warehouse_id' => $this->wh->id, 'count_date' => now()->toDateString(),
            'status' => InventoryCountStatus::Draft, 'counted_by' => $this->user->id]);
        InventoryCountItem::create(['inventory_count_id' => $count->id, 'product_id' => $this->product->id, 'system_quantity' => 20, 'counted_quantity' => 23]);

        AccountCode::where('code', '711')->delete();                  // TK đối ứng hàng thừa không tồn tại → JE lỗi
        $before = $this->snapshot();
        try {
            app(InventoryCountService::class)->confirm($count);
            $this->fail('JE lỗi phải làm hỏng cả giao dịch');
        } catch (\Throwable) {
        }
        $this->assertSame($before, $this->snapshot(), 'không ghi một phần movement/AVCO khi JE lỗi');
        $this->assertSame(InventoryCountStatus::Draft, $count->fresh()->status);

        AccountCode::create(['code' => '711', 'name' => '711', 'type' => 'revenue', 'normal_balance' => 'credit', 'is_detail' => true]);
        app(InventoryCountService::class)->confirm($count->fresh());
        $mv = (float) StockMovement::where('source_type', InventoryCount::class)->where('source_id', $count->id)->sum('amount');
        $countGl = (float) JournalEntryLine::whereHas('entry', fn ($q) => $q->where('reference_type', InventoryCount::class)->where('reference_id', $count->id))
            ->where('account_code', '1561')->sum(\DB::raw('debit - credit'));
        $this->assertEqualsWithDelta(450000.0, $mv, 0.01, '3 × avg 150.000');
        $this->assertEqualsWithDelta($mv, $countGl, 1, 'JE 1561 kiểm kê = giá trị movement (làm tròn ≤ 1đ)');
    }
}
