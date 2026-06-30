<?php

namespace Tests\Feature\Warehouse;

use App\Enums\StockTransferStatus;
use App\Models\InventoryBalance;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\StockTransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use RuntimeException;
use Tests\TestCase;

/**
 * TC1: Chuyển toàn bộ — kho A qty=0, kho B qty=13, total_value không đổi
 * TC2: Chuyển một phần — kho A còn lại đúng, kho B tăng đúng
 * TC3: Không đủ tồn — chặn confirm, không tạo movement/balance
 * TC4: Hủy phiếu đã confirm — AVCO reverse đúng cả 2 kho
 * TC5: Không có JE/WIP/N154 cho chuyển kho
 * TC6: Sau chuyển kho, InventoryBalance kho B hiển thị đúng (Order Show gợi ý kho B)
 */
class StockTransferAvcoTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Warehouse $whA;
    private Warehouse $whB;
    private Product $product;
    private StockTransferService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
        Gate::before(fn ($u, $a) => true);

        $this->whA     = Warehouse::create(['code' => 'KHO-A', 'name' => 'Kho A']);
        $this->whB     = Warehouse::create(['code' => 'KHO-B', 'name' => 'Kho B']);
        $this->product = Product::create([
            'code'       => 'SP-CK01',
            'name'       => 'Sản phẩm chuyển kho',
            'unit'       => 'cái',
            'cost_price' => 110000,  // incl VAT — không dùng để tính AVCO
            'vat_percent' => 10,
            'item_type'  => 'product',
            'is_active'  => true,
        ]);

        $this->service = app(StockTransferService::class);
    }

    // Seed AVCO balance tại kho A
    private function seedSourceBalance(float $qty, float $avgCost): void
    {
        InventoryBalance::create([
            'product_id'       => $this->product->id,
            'warehouse_id'     => $this->whA->id,
            'qty_on_hand'      => $qty,
            'value_on_hand'    => $qty * $avgCost,
            'avg_cost'         => $avgCost,
            'initialized_from' => 'opening_balance',
            'initialized_at'   => now(),
        ]);

        // Cần có movement IN để active-filter-based stock check pass
        DB::table('stock_movements')->insert([
            'product_id'   => $this->product->id,
            'warehouse_id' => $this->whA->id,
            'type'         => 'in',
            'quantity'     => $qty,
            'unit_cost'    => $avgCost,
            'amount'       => $qty * $avgCost,
            'status'       => 'active',
            'created_by'   => $this->user->id,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }

    private function makeTransfer(float $qty): StockTransfer
    {
        $transfer = StockTransfer::create([
            'code'              => 'CK-0001',
            'from_warehouse_id' => $this->whA->id,
            'to_warehouse_id'   => $this->whB->id,
            'transfer_date'     => now()->toDateString(),
            'status'            => StockTransferStatus::Draft,
            'created_by'        => $this->user->id,
        ]);
        StockTransferItem::create([
            'stock_transfer_id' => $transfer->id,
            'product_id'        => $this->product->id,
            'quantity'          => $qty,
        ]);
        return $transfer;
    }

    // ── TC1: Chuyển toàn bộ 13 units, avg_cost 100,000 ──────────────────────

    public function test_tc1_full_transfer_updates_avco_both_warehouses(): void
    {
        $this->seedSourceBalance(qty: 13, avgCost: 100_000);

        $transfer = $this->makeTransfer(13);
        $this->service->confirmTransfer($transfer);

        // Kho A: qty = 0
        $srcBalance = InventoryBalance::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->whA->id)->first();
        $this->assertNotNull($srcBalance);
        $this->assertEquals(0.0, (float) $srcBalance->qty_on_hand, 'Kho A phải = 0 sau khi chuyển hết');
        $this->assertEquals(0.0, (float) $srcBalance->value_on_hand, 'Kho A value = 0');

        // Kho B: qty = 13, value = 1,300,000
        $destBalance = InventoryBalance::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->whB->id)->first();
        $this->assertNotNull($destBalance, 'AVCO kho B phải được tạo sau chuyển kho');
        $this->assertEquals(13.0,         (float) $destBalance->qty_on_hand);
        $this->assertEquals(1_300_000.0,  (float) $destBalance->value_on_hand, 'Kho B total_value = 1,300,000');
        $this->assertEquals(100_000.0,    (float) $destBalance->avg_cost, 'Kho B avg_cost = 100,000');

        // Tổng giá trị toàn hệ thống không đổi
        $totalValue = InventoryBalance::where('product_id', $this->product->id)->sum('value_on_hand');
        $this->assertEqualsWithDelta(1_300_000.0, $totalValue, 0.1, 'Tổng value không đổi');
    }

    // ── TC2: Chuyển một phần ────────────────────────────────────────────────

    public function test_tc2_partial_transfer_correct_remaining(): void
    {
        $this->seedSourceBalance(qty: 10, avgCost: 200_000);

        $transfer = $this->makeTransfer(4);
        $this->service->confirmTransfer($transfer);

        $srcBalance = InventoryBalance::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->whA->id)->first();
        $this->assertEquals(6.0,         (float) $srcBalance->qty_on_hand, 'Kho A còn 6');
        $this->assertEquals(1_200_000.0, (float) $srcBalance->value_on_hand, 'Kho A value còn 1,200,000');

        $destBalance = InventoryBalance::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->whB->id)->first();
        $this->assertEquals(4.0,         (float) $destBalance->qty_on_hand, 'Kho B nhận 4');
        $this->assertEquals(800_000.0,   (float) $destBalance->value_on_hand, 'Kho B value = 800,000');
        $this->assertEquals(200_000.0,   (float) $destBalance->avg_cost);
    }

    // ── TC3: Không đủ tồn — chặn confirm ────────────────────────────────────

    public function test_tc3_insufficient_stock_blocks_confirm(): void
    {
        $this->seedSourceBalance(qty: 5, avgCost: 100_000);

        $transfer = $this->makeTransfer(10); // nhiều hơn tồn

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/không đủ tồn kho/');

        $this->service->confirmTransfer($transfer);

        // Rollback: không có movement và không có balance kho B
        $this->assertEquals(0, StockMovement::where('source_id', $transfer->id)
            ->where('source_type', StockTransfer::class)->count());
        $this->assertNull(
            InventoryBalance::where('product_id', $this->product->id)
                ->where('warehouse_id', $this->whB->id)->first()
        );
    }

    // ── TC4: Hủy phiếu — AVCO reverse đúng ──────────────────────────────────

    public function test_tc4_cancel_reverses_avco_correctly(): void
    {
        $this->seedSourceBalance(qty: 8, avgCost: 150_000);
        $transfer = $this->makeTransfer(8);
        $this->service->confirmTransfer($transfer);

        // Sau confirm: A=0, B=8 @ 150,000
        $this->assertEquals(0.0,       (float) InventoryBalance::where('product_id', $this->product->id)->where('warehouse_id', $this->whA->id)->value('qty_on_hand'));
        $this->assertEquals(8.0,       (float) InventoryBalance::where('product_id', $this->product->id)->where('warehouse_id', $this->whB->id)->value('qty_on_hand'));

        $transfer->refresh();
        $this->service->cancelTransfer($transfer);

        // Sau cancel: A=8 @ 150,000 restored, B=0
        $srcBalance = InventoryBalance::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->whA->id)->first();
        $this->assertEquals(8.0,         (float) $srcBalance->qty_on_hand, 'Kho A phải restore về 8');
        $this->assertEquals(1_200_000.0, (float) $srcBalance->value_on_hand, 'Kho A value restore');

        $destBalance = InventoryBalance::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->whB->id)->first();
        $this->assertEquals(0.0, (float) $destBalance->qty_on_hand, 'Kho B phải về 0 sau cancel');
    }

    // ── TC5: Không có JE/WIP/N154 ──────────────────────────────────────────

    public function test_tc5_no_journal_entry_created_for_transfer(): void
    {
        $this->seedSourceBalance(qty: 3, avgCost: 100_000);
        $transfer = $this->makeTransfer(3);
        $this->service->confirmTransfer($transfer);

        $jeCount = \Illuminate\Support\Facades\DB::table('journal_entries')
            ->where('reference_type', 'App\\Models\\StockTransfer')
            ->where('reference_id', $transfer->id)
            ->count();

        $this->assertEquals(0, $jeCount, 'Chuyển kho không được tạo JE/WIP/N154');
    }

    // ── TC6: Sau chuyển kho, InventoryBalance kho B đúng cho Order Show ──────

    public function test_tc6_after_transfer_dest_warehouse_visible_to_order_show(): void
    {
        $this->seedSourceBalance(qty: 13, avgCost: 100_000);
        $transfer = $this->makeTransfer(13);
        $this->service->confirmTransfer($transfer);

        // InventoryBalance::stockForProducts dùng cho Order Show suggestion
        $stock = InventoryBalance::stockForProducts([$this->product->id]);

        // Tổng phải = 13 (B có 13, A có 0)
        $this->assertEquals(13.0, (float) $stock->get($this->product->id, 0),
            'stockForProducts phải trả về 13 từ kho B');

        // Kiểm tra kho B cụ thể
        $destBalance = InventoryBalance::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->whB->id)->first();
        $this->assertNotNull($destBalance);
        $this->assertEquals(13.0, (float) $destBalance->qty_on_hand,
            'Kho B phải có 13 units — Order Show sẽ gợi ý kho B thay vì kho A');
    }
}
