<?php

namespace Tests\Feature\Warehouse;

use App\Enums\StockEntryStatus;
use App\Models\AccountCode;
use App\Models\InventoryBalance;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\StockEntry;
use App\Models\StockEntryItem;
use App\Models\StockExit;
use App\Models\StockExitItem;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

/**
 * FULL_INVENTORY_INTEGRITY_LOCAL_REMEDIATION_V3 — A2 phụ thuộc, A3 đảo theo giá gốc,
 * JE khi xác nhận lại sau thu hồi, chặn hạch toán trùng với JE thủ công, A8 search.
 */
class InventoryIntegrityV3Test extends TestCase
{
    use RefreshDatabase;

    private StockService $svc;
    private User $user;
    private Warehouse $wh;
    private Supplier $supplier;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->svc = app(StockService::class);
        $this->user = User::factory()->create();
        $this->user->roles()->sync([Role::firstOrCreate(['code' => 'admin'], ['name' => 'Admin', 'is_system' => true])->id]);
        $this->actingAs($this->user);
        foreach (['1331' => 'asset', '1561' => 'asset', '3311' => 'liability', '632' => 'expense', '811' => 'expense', '711' => 'revenue'] as $c => $t) {
            AccountCode::firstOrCreate(['code' => $c], ['name' => $c, 'type' => $t, 'normal_balance' => in_array($t, ['liability', 'revenue']) ? 'credit' : 'debit', 'is_detail' => true]);
        }
        $this->wh = Warehouse::create(['name' => 'Kho V3']);
        $this->supplier = Supplier::create(['code' => 'NCC-V3', 'name' => 'NCC', 'payable_account_code' => '3311']);
        $cat = ProductCategory::create(['name' => 'Cat V3']);
        $this->product = Product::create(['code' => 'SP-V3', 'name' => 'SP V3', 'category_id' => $cat->id, 'cost_price' => 110000, 'is_active' => true]);
    }

    private function entry(int $qty, float $unit, ?Product $p = null): StockEntry
    {
        $e = StockEntry::create([
            'code' => 'NK-' . uniqid(), 'warehouse_id' => $this->wh->id, 'supplier_id' => $this->supplier->id,
            'status' => StockEntryStatus::Draft, 'entry_date' => now()->toDateString(), 'created_by' => $this->user->id,
        ]);
        StockEntryItem::create(['stock_entry_id' => $e->id, 'product_id' => ($p ?? $this->product)->id, 'quantity' => $qty,
            'unit_price' => $unit, 'unit_cost' => $unit, 'tax_rate' => 10]);
        $this->svc->confirmEntry($e);
        return $e->fresh();
    }

    private function sell(int $qty): StockExit
    {
        $x = StockExit::create(['code' => 'XK-' . uniqid(), 'warehouse_id' => $this->wh->id, 'issue_purpose' => 'sale_delivery',
            'item_usage_type' => 'commercial', 'status' => 'draft', 'exit_date' => now()->toDateString(), 'created_by' => $this->user->id]);
        StockExitItem::create(['stock_exit_id' => $x->id, 'product_id' => $this->product->id, 'quantity' => $qty, 'unit_price' => 0]);
        $this->svc->confirmExit($x);
        return $x->fresh();
    }

    private function balance(): InventoryBalance
    {
        return InventoryBalance::where('product_id', $this->product->id)->where('warehouse_id', $this->wh->id)->first();
    }

    /** A2: nhập 5 → xuất 5 → nhập 10 → thu hồi phiếu đầu: tồn hiện tại 10 ≥ 5 nhưng vẫn phải chặn. */
    public function test_recall_blocked_when_later_outflow_depends_on_entry_even_if_stock_suffices(): void
    {
        $first = $this->entry(5, 100000);
        $x = $this->sell(5);
        $this->entry(10, 200000);

        try {
            $this->svc->recallEntry($first);
            $this->fail('Phải chặn thu hồi');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString($x->code, $e->getMessage());
        }
        $this->assertEquals(10.0, (float) $this->balance()->qty_on_hand);
        $this->assertEquals(StockEntryStatus::Confirmed, $first->fresh()->status);

        // Hủy phiếu xuất phụ thuộc trước → thu hồi được, tồn và giá trị đúng phần còn lại của phiếu sau
        $this->svc->cancelExit($x);
        $this->svc->recallEntry($first->fresh());
        $this->assertEquals(10.0, (float) $this->balance()->qty_on_hand);
        $this->assertEqualsWithDelta(2000000.0, (float) $this->balance()->value_on_hand, 0.01);
    }

    /** A3: không có giao dịch phụ thuộc → đảo đúng giá trị nhập gốc, không theo giá bình quân. */
    public function test_cancel_entry_reverses_at_original_cost_not_average(): void
    {
        $this->entry(10, 100000);
        $second = $this->entry(5, 160000);   // avg = 120.000; đảo theo avg sẽ còn 1.200.000 thay vì 1.000.000

        $this->svc->cancelEntry($second);
        $b = $this->balance();
        $this->assertEquals(10.0, (float) $b->qty_on_hand);
        $this->assertEqualsWithDelta(1000000.0, (float) $b->value_on_hand, 0.01);
        $this->assertEqualsWithDelta(100000.0, (float) $b->avg_cost, 0.01);
    }

    /** Lỗi gốc NK-0054/NK-0065: xác nhận lại sau thu hồi phải sinh lại JE tự động (không bị posting job cũ chặn). */
    public function test_reconfirm_after_recall_posts_journal_again(): void
    {
        $e = $this->entry(3, 100000);
        $this->assertEquals(1, JournalEntry::where('reference_type', 'stock_entry')->where('reference_id', $e->id)->whereIn('status', ['posted', 'draft'])->count());

        $this->svc->recallEntry($e);
        $this->svc->confirmEntry($e->fresh());

        $live = JournalEntry::where('reference_type', 'stock_entry')->where('reference_id', $e->id)
            ->whereIn('status', ['posted', 'draft'])->where('description', 'not like', 'Đảo:%')->count();
        $this->assertEquals(1, $live, 'Sau thu hồi + xác nhận lại phải có đúng 1 JE nhập kho hiệu lực');
    }

    /** Phiếu được hạch toán bằng JE thủ công không liên kết → chặn thu hồi để không sinh JE trùng. */
    public function test_recall_blocked_when_entry_booked_by_unlinked_manual_journal(): void
    {
        $e = $this->entry(1, 130000);
        JournalEntry::where('reference_type', 'stock_entry')->where('reference_id', $e->id)->get()
            ->each(function ($j) { $j->lines()->delete(); $j->delete(); });
        JournalEntry::create(['code' => 'BT-MANUAL-1', 'entry_date' => now(), 'description' => "Nhập kho {$e->code} (tay)",
            'status' => 'posted', 'is_auto' => false, 'created_by' => $this->user->id]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('BT-MANUAL-1');
        $this->svc->recallEntry($e);
    }

    /** A8: kho đã có dòng AVCO của SP khác vẫn phải hiện SP chưa có AVCO (cùng điều kiện với backend). */
    public function test_warehouse_product_search_merges_products_without_avco_row(): void
    {
        $this->entry(2, 50000);
        $legacy = Product::create(['code' => 'SP-LEGACY', 'name' => 'Legacy', 'category_id' => $this->product->category_id, 'is_active' => true]);
        \App\Models\StockMovement::create(['product_id' => $legacy->id, 'warehouse_id' => $this->wh->id, 'type' => 'in', 'quantity' => 1,
            'source_type' => 'App\Models\InventoryOpeningBalance', 'source_id' => 1, 'created_by' => $this->user->id, 'status' => 'active']);

        $data = $this->getJson(route('search.warehouse-products', ['warehouse_id' => $this->wh->id]))->assertOk()->json('data');
        $ids = collect($data)->pluck('value')->all();
        $this->assertContains($this->product->id, $ids);
        $this->assertContains($legacy->id, $ids, 'SP chưa có AVCO nhưng backend cho xuất thì search cũng phải hiện');
    }
}
