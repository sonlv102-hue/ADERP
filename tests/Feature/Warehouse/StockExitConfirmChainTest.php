<?php

namespace Tests\Feature\Warehouse;

use App\Enums\OrderStatus;
use App\Enums\StockExitStatus;
use App\Models\AccountCode;
use App\Models\AccountingPeriod;
use App\Models\Customer;
use App\Models\InventoryBalance;
use App\Models\JournalEntry;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Project;
use App\Models\ProjectWipEntry;
use App\Models\StockExit;
use App\Models\StockExitItem;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * TC1: project_cost confirm → inventory_balances trừ + movement OUT + JE Dr154/Cr1561 + WIP + order sync
 * TC2: Thiếu TK154 → confirm throw + rollback toàn bộ (không trừ kho, không tạo movement, không update order)
 * TC3: syncDelivery trong cùng transaction → delivered_quantity cập nhật đúng
 * TC4: sale_delivery không có order → tryPost, không throw (non-blocking)
 */
class StockExitConfirmChainTest extends TestCase
{
    use RefreshDatabase;

    private User      $user;
    private Warehouse $warehouse;
    private Product   $product;
    private Customer  $customer;
    private Project   $project;
    private StockService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $this->user = User::firstOrCreate(
            ['email' => 'chain@test.local'],
            ['name' => 'Chain Test', 'password' => bcrypt('x'), 'is_active' => true]
        );
        $this->actingAs($this->user);
        Gate::before(fn ($u, $a) => true);

        AccountingPeriod::create(['year' => 2026, 'month' => 6, 'status' => 'open']);

        $this->warehouse = Warehouse::create(['name' => 'Kho Chain', 'code' => 'KC', 'address' => 'HN']);

        $this->customer = Customer::create([
            'code' => 'KH-CHAIN', 'name' => 'KH Chain Test', 'is_active' => true,
        ]);

        $this->project = Project::create([
            'code'        => 'DA-CHAIN',
            'name'        => 'Dự án Chain Test',
            'status'      => 'in_progress',
            'customer_id' => $this->customer->id,
            'created_by'  => $this->user->id,
        ]);

        $this->product = Product::create([
            'code'        => 'SP-CHAIN',
            'name'        => 'SP Chain Test',
            'unit'        => 'cái',
            'cost_price'  => 1100000,
            'vat_percent' => 10,
            'item_type'   => 'product',
            'is_active'   => true,
        ]);

        $this->seedAccountCodes();
        $this->svc = app(StockService::class);
    }

    private function seedAccountCodes(bool $include154 = true): void
    {
        $codes = [
            ['code' => '156',  'name' => 'Hàng hoá',          'type' => 'asset',     'normal_balance' => 'debit',  'is_detail' => false],
            ['code' => '1561', 'name' => 'Hàng hoá kho',      'type' => 'asset',     'normal_balance' => 'debit',  'is_detail' => true],
            ['code' => '632',  'name' => 'Giá vốn hàng bán',  'type' => 'expense',   'normal_balance' => 'debit',  'is_detail' => true],
        ];

        if ($include154) {
            $codes[] = ['code' => '154', 'name' => 'Chi phí SX dở dang', 'type' => 'asset', 'normal_balance' => 'debit', 'is_detail' => true];
        }

        foreach ($codes as $acc) {
            AccountCode::updateOrCreate(['code' => $acc['code']], array_merge($acc, ['level' => 3, 'is_active' => true]));
        }
    }

    private function seedInventoryBalance(int $qty = 10): void
    {
        InventoryBalance::updateOrCreate(
            ['product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id],
            ['qty_on_hand' => $qty, 'value_on_hand' => $qty * 1000000, 'avg_cost' => 1000000]
        );
    }

    private function makeProjectCostExit(int $qty = 5): StockExit
    {
        $exit = StockExit::create([
            'code'          => 'XK-CHAIN-' . uniqid(),
            'warehouse_id'  => $this->warehouse->id,
            'project_id'    => $this->project->id,
            'exit_date'     => now()->toDateString(),
            'status'        => StockExitStatus::Draft,
            'created_by'    => $this->user->id,
            'issue_purpose' => 'project_cost',
        ]);

        StockExitItem::create([
            'stock_exit_id' => $exit->id,
            'product_id'    => $this->product->id,
            'quantity'      => $qty,
            'unit_price'    => 1500000,
            'project_id'    => $this->project->id,
        ]);

        return $exit->fresh('items.product', 'items.serials');
    }

    // ── TC1: Full chain thành công ─────────────────────────────────────────────

    public function test_tc1_full_chain_project_cost_confirm(): void
    {
        $this->seedInventoryBalance(10);
        $exit = $this->makeProjectCostExit(5);

        $warnings = $this->svc->confirmExit($exit);

        $this->assertIsArray($warnings);

        // Bước 1: inventory_balances giảm 5
        $balance = InventoryBalance::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouse->id)->first();
        $this->assertNotNull($balance);
        $this->assertEquals(5, (int) $balance->qty_on_hand, 'Tồn kho phải là 5 sau khi xuất 5.');

        // Bước 2: stock_movement OUT
        $movement = StockMovement::where('source_type', StockExit::class)
            ->where('source_id', $exit->id)
            ->where('type', 'out')
            ->first();
        $this->assertNotNull($movement, 'Phải có stock_movement OUT.');
        $this->assertEquals(-5, (int) $movement->quantity);
        $this->assertEquals($this->warehouse->id, $movement->warehouse_id);
        $this->assertEquals($this->project->id, $movement->project_id);

        // Bước 4: JE Dr154/Cr1561
        $je = JournalEntry::where('reference_type', 'stock_exit')
            ->where('reference_id', $exit->id)->first();
        $this->assertNotNull($je, 'Phải có JournalEntry.');
        $je->load('lines');
        $debitLine  = $je->lines->where('debit', '>', 0)->first();
        $creditLine = $je->lines->where('credit', '>', 0)->first();
        $this->assertNotNull($debitLine, 'Phải có dòng Nợ.');
        $this->assertStringStartsWith('154', $debitLine->account_code, 'Dòng Nợ phải là TK 154.');
        $this->assertNotNull($creditLine, 'Phải có dòng Có.');

        // Bước 3: project_wip_entries
        $wipCount = ProjectWipEntry::where('source_type', StockExit::class)
            ->where('source_id', $exit->id)->count();
        $this->assertGreaterThan(0, $wipCount, 'Phải có ProjectWipEntry.');

        // Bước 6: exit status = Confirmed
        $this->assertEquals(StockExitStatus::Confirmed, $exit->fresh()->status);
    }

    // ── TC2: Thiếu TK154 → rollback ───────────────────────────────────────────

    public function test_tc2_missing_tk154_rolls_back_entire_exit(): void
    {
        AccountCode::where('code', '154')->delete();
        $this->seedInventoryBalance(10);
        $exit = $this->makeProjectCostExit(5);

        $threw = false;
        try {
            $this->svc->confirmExit($exit);
        } catch (\Exception $e) {
            $threw = true;
        }

        $this->assertTrue($threw, 'confirmExit phải throw exception khi thiếu TK154.');

        // Không được có stock_movement (rollback)
        $movementCount = StockMovement::where('source_type', StockExit::class)
            ->where('source_id', $exit->id)->count();
        $this->assertEquals(0, $movementCount, 'Không được tạo stock_movement khi JE fail.');

        // Tồn kho không thay đổi (rollback)
        $balance = InventoryBalance::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouse->id)->value('qty_on_hand');
        $this->assertEquals(10, (int) $balance, 'Tồn kho không được thay đổi khi rollback.');

        // Không có JE (rollback)
        $jeCount = JournalEntry::where('reference_type', 'stock_exit')
            ->where('reference_id', $exit->id)->count();
        $this->assertEquals(0, $jeCount, 'Không được tạo JE khi rollback.');

        // Exit vẫn Draft (rollback)
        $this->assertEquals(StockExitStatus::Draft, $exit->fresh()->status, 'Exit phải vẫn là Draft khi rollback.');
    }

    // ── TC3: syncDelivery trong transaction → delivered_quantity cập nhật ─────

    public function test_tc3_sync_delivery_updates_order_item_inside_transaction(): void
    {
        $this->seedInventoryBalance(10);

        // Tạo order + order_item
        $supplier = Supplier::create(['code' => 'NCC-CH', 'name' => 'NCC Chain', 'is_active' => true, 'payable_account_code' => '3311']);
        AccountCode::updateOrCreate(['code' => '3311'], ['name' => 'NCC', 'type' => 'liability', 'normal_balance' => 'credit', 'is_detail' => true, 'level' => 3, 'is_active' => true]);

        $order = Order::create([
            'code'        => 'DH-CHAIN-001',
            'customer_id' => $this->customer->id,
            'status'      => OrderStatus::Processing,
            'created_by'  => $this->user->id,
            'order_date'  => now()->toDateString(),
        ]);

        $orderItem = OrderItem::create([
            'order_id'           => $order->id,
            'name'               => $this->product->name,
            'product_id'         => $this->product->id,
            'quantity'           => 5,
            'unit_price'         => 1500000,
            'delivered_quantity' => 0,
        ]);

        $exit = StockExit::create([
            'code'          => 'XK-CHAIN-SYN',
            'warehouse_id'  => $this->warehouse->id,
            'project_id'    => $this->project->id,
            'order_id'      => $order->id,
            'exit_date'     => now()->toDateString(),
            'status'        => StockExitStatus::Draft,
            'created_by'    => $this->user->id,
            'issue_purpose' => 'project_cost',
        ]);

        StockExitItem::create([
            'stock_exit_id'  => $exit->id,
            'product_id'     => $this->product->id,
            'quantity'       => 5,
            'unit_price'     => 1500000,
            'project_id'     => $this->project->id,
            'order_item_id'  => $orderItem->id,
        ]);

        $exit = $exit->fresh('items.product', 'items.serials');
        $warnings = $this->svc->confirmExit($exit);

        // delivered_quantity phải được cập nhật
        $this->assertEquals(5, (float) $orderItem->fresh()->delivered_quantity, 'delivered_quantity phải là 5 sau confirm.');

        // Order status phải là Completed
        $this->assertEquals(OrderStatus::Completed, $order->fresh()->status, 'Order phải là Completed khi xuất đủ.');
    }

    // ── TC4: sale_delivery không có order → tryPost, không throw ──────────────

    public function test_tc4_sale_delivery_without_order_uses_trypost_non_blocking(): void
    {
        // Non-project path dùng SUM(stock_movements) → cần seed movement thật
        StockMovement::create([
            'product_id'   => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'type'         => 'in',
            'quantity'     => 5,
            'created_by'   => $this->user->id,
        ]);
        $this->seedInventoryBalance(5);

        $exit = StockExit::create([
            'code'          => 'XK-CHAIN-SD',
            'warehouse_id'  => $this->warehouse->id,
            'exit_date'     => now()->toDateString(),
            'status'        => StockExitStatus::Draft,
            'created_by'    => $this->user->id,
            'issue_purpose' => 'sale_delivery',
        ]);

        StockExitItem::create([
            'stock_exit_id' => $exit->id,
            'product_id'    => $this->product->id,
            'quantity'      => 3,
            'unit_price'    => 1500000,
        ]);

        $exit = $exit->fresh('items.product', 'items.serials');

        // Xóa TK 632 → tryPost() nuốt FK violation, exit vẫn Confirmed
        AccountCode::where('code', '632')->delete();

        // Không được throw (non-blocking)
        $warnings = $this->svc->confirmExit($exit);
        $this->assertEquals(StockExitStatus::Confirmed, $exit->fresh()->status, 'sale_delivery phải Confirmed dù JE fail.');
    }

    // ── TC5: exit.order_id = NULL nhưng item có order_item_id → vẫn phải sync ──
    // Tái hiện đúng bug thật (XK-0015/DH-0011): header order_id thiếu, item vẫn
    // link order_item_id → OrderService::syncDelivery() phải tự suy ra order.

    public function test_tc5_sync_delivery_resolves_order_from_item_link_when_exit_order_id_null(): void
    {
        $this->seedInventoryBalance(10);

        $order = Order::create([
            'code' => 'DH-CHAIN-005', 'customer_id' => $this->customer->id,
            'status' => OrderStatus::Processing, 'created_by' => $this->user->id,
            'order_date' => now()->toDateString(),
        ]);

        $orderItem = OrderItem::create([
            'order_id' => $order->id, 'name' => $this->product->name, 'product_id' => $this->product->id,
            'quantity' => 5, 'unit_price' => 1500000, 'delivered_quantity' => 0,
        ]);

        $exit = StockExit::create([
            'code' => 'XK-CHAIN-NULLHDR', 'warehouse_id' => $this->warehouse->id,
            'project_id' => $this->project->id,
            'order_id' => null, // <- đúng trạng thái lỗi thật: header KHÔNG có order_id
            'exit_date' => now()->toDateString(), 'status' => StockExitStatus::Draft,
            'created_by' => $this->user->id, 'issue_purpose' => 'project_cost',
        ]);

        StockExitItem::create([
            'stock_exit_id' => $exit->id, 'product_id' => $this->product->id, 'quantity' => 5,
            'unit_price' => 1500000, 'project_id' => $this->project->id,
            'order_item_id' => $orderItem->id, // item vẫn có link
        ]);

        $exit = $exit->fresh('items.product', 'items.serials');
        $this->svc->confirmExit($exit);

        $this->assertEquals(5, (float) $orderItem->fresh()->delivered_quantity,
            'delivered_quantity phải sync dù exit.order_id header = NULL, miễn item có order_item_id.');
        $this->assertEquals(OrderStatus::Completed, $order->fresh()->status);
    }

    // ── TC6: item thiếu order_item_id nhưng product_id duy nhất trong đơn → fallback ──

    public function test_tc6_sync_delivery_fallback_by_product_id_when_order_item_id_null(): void
    {
        $this->seedInventoryBalance(10);

        $order = Order::create([
            'code' => 'DH-CHAIN-006', 'customer_id' => $this->customer->id,
            'status' => OrderStatus::Processing, 'created_by' => $this->user->id,
            'order_date' => now()->toDateString(),
        ]);

        $orderItem = OrderItem::create([
            'order_id' => $order->id, 'name' => $this->product->name, 'product_id' => $this->product->id,
            'quantity' => 5, 'unit_price' => 1500000, 'delivered_quantity' => 0,
        ]);

        $exit = StockExit::create([
            'code' => 'XK-CHAIN-FALLBACK', 'warehouse_id' => $this->warehouse->id,
            'project_id' => $this->project->id, 'order_id' => $order->id,
            'exit_date' => now()->toDateString(), 'status' => StockExitStatus::Draft,
            'created_by' => $this->user->id, 'issue_purpose' => 'project_cost',
        ]);

        StockExitItem::create([
            'stock_exit_id' => $exit->id, 'product_id' => $this->product->id, 'quantity' => 5,
            'unit_price' => 1500000, 'project_id' => $this->project->id,
            'order_item_id' => null, // legacy: thiếu link item-level
        ]);

        $exit = $exit->fresh('items.product', 'items.serials');
        $this->svc->confirmExit($exit);

        $this->assertEquals(5, (float) $orderItem->fresh()->delivered_quantity,
            'delivered_quantity phải fallback đúng qua product_id khi order_item_id null và product_id duy nhất trong đơn.');
    }

    // ── TC7: product_id trùng nhiều dòng trong đơn → không fallback mù, phải warning ──

    public function test_tc7_sync_delivery_warns_and_skips_when_product_id_duplicated_in_order(): void
    {
        $this->seedInventoryBalance(10);

        $order = Order::create([
            'code' => 'DH-CHAIN-007', 'customer_id' => $this->customer->id,
            'status' => OrderStatus::Processing, 'created_by' => $this->user->id,
            'order_date' => now()->toDateString(),
        ]);

        // Cùng product_id xuất hiện ở 2 dòng khác nhau trong đơn (vd 2 đợt giao khác giá)
        $orderItem1 = OrderItem::create([
            'order_id' => $order->id, 'name' => $this->product->name, 'product_id' => $this->product->id,
            'quantity' => 3, 'unit_price' => 1500000, 'delivered_quantity' => 0,
        ]);
        $orderItem2 = OrderItem::create([
            'order_id' => $order->id, 'name' => $this->product->name, 'product_id' => $this->product->id,
            'quantity' => 2, 'unit_price' => 1600000, 'delivered_quantity' => 0,
        ]);

        $exit = StockExit::create([
            'code' => 'XK-CHAIN-DUP', 'warehouse_id' => $this->warehouse->id,
            'project_id' => $this->project->id, 'order_id' => $order->id,
            'exit_date' => now()->toDateString(), 'status' => StockExitStatus::Draft,
            'created_by' => $this->user->id, 'issue_purpose' => 'project_cost',
        ]);

        StockExitItem::create([
            'stock_exit_id' => $exit->id, 'product_id' => $this->product->id, 'quantity' => 5,
            'unit_price' => 1500000, 'project_id' => $this->project->id,
            'order_item_id' => null, // không rõ dòng nào — product_id trùng 2 dòng
        ]);

        $exit = $exit->fresh('items.product', 'items.serials');
        $warnings = $this->svc->confirmExit($exit);

        $this->assertEquals(0, (float) $orderItem1->fresh()->delivered_quantity,
            'Không được đoán mù gán delivered_quantity cho order_item #1 khi product_id trùng nhiều dòng.');
        $this->assertEquals(0, (float) $orderItem2->fresh()->delivered_quantity,
            'Không được đoán mù gán delivered_quantity cho order_item #2 khi product_id trùng nhiều dòng.');
        $this->assertNotEmpty($warnings, 'Phải có warning khi không thể tự khớp product_id trùng nhiều dòng.');
        $this->assertStringContainsString('không thể tự khớp', $warnings[0]);
    }
}
